<?php

namespace App\Services\Knowledge;

use App\Exceptions\IngestionFailure;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Billing\AssistUsageRecorder;
use App\Services\Content\HtmlVisibility;
use App\Services\Contracts\AiProvider;
use App\Services\DTOs\AiProviderCallResult;
use App\Services\Enums\SkillCategory;
use App\Services\Outbound\OutboundRequestBlockedException;
use App\Services\Outbound\OutboundRequestFailedException;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Services\Outbound\SecureHttpFactory;
use App\Support\BillingContext;
use App\Support\TenantContext;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DocumentIngestionService
{
    /**
     * 单篇文档进入切块/向量化前的正文字数上限。
     *
     * ⚠️ 先说清楚这道闸门**不是**什么，免得再被误读一次（2026-08-07 更正）：
     *
     * 它不是 2026-08-01 那次卡死事故的修复。那次的真凶是 chunkText() 的**死循环** ——
     * 剩余文本短于 overlapChars 时 $offset 不前进，块无限累积直到 OOM。生产日志三条
     * 报错都落在同一行（当时的 :435，mb_substr），"tried to allocate 20480 bytes"
     * 这种小额分配失败正是失控累积的特征，与文档大小无关：那三篇卡住的 PDF 各 1.28MB、
     * 正文仅 2,199 字符，而同一天 1.22MB 的另一篇入库成功。把 memory_limit 从 128M
     * 提到 512M 只是让它多转一会儿再死（11:50 死在 128M，12:05 与 12:06 死在 512M）。
     * 该死循环已由 `3fd8d41` 当晚修掉并随 7d05979 上线。
     *
     * 这道闸门守的是**另一种失效**：ingestUrl / ingestText 这两条路径此前完全没有任何
     * 体积上限（ingestFile 的 50MB 检查是死的 —— php.ini 的 upload_max_filesize=10M
     * 先一步拒掉大文件）。超大正文一样能把内存撑爆，而内存耗尽是 FatalError、不是异常，
     * 下面 ingest*() 的 catch(\Throwable) 接不住 —— markFailed() 不执行，文档就永远
     * 卡在 pending。所以这里不是「优化内存」，是**把不可捕获的致命错误换成可捕获的异常**：
     * 超限直接抛 RuntimeException，让文档正常落 failed 并带上用户看得懂的原因。
     *
     * 阈值 1000 万字符（老板 2026-08-06 拍板「按字符数卡、10m 以内」）。实测切块峰值内存：
     *
     *   100 万字符 → 4.9MB    500 万字符 → 34.3MB    1000 万字符 → 70.6MB（512M 的 14%）
     *
     * 也就是说**内存不是这道闸门的瓶颈**，它守的是那个不可捕获的致命错误。
     *
     * 真正吃紧的是时间和钱，这两条闸门管不了，别指望它挡：1000 万字符切出约 5,500 条、
     * 按 20 条一批要串行打约 278 次向量化请求。这道闸门守的是 worker 里解析/向量化阶段的
     * 致命错误（FatalError 类），不是网页请求 —— 入库现在跑在队列里（IngestKnowledgeDocumentJob）。
     *
     * 另外这道闸门管不到 PDF 解析本身：ingestFile 里 extractText() 先跑、闸门后跑，
     * PDF 解析库自己撑爆内存的话轮不到这里拦。那条路目前靠 php.ini 的
     * upload_max_filesize=10M 兜着（2026-08-07 由 5M 提上来）。
     * 但那条上限**挡不住结构特殊的 PDF**：实测解析内存与文件大小不成正比 ——
     * 1.22MB/17页 峰值 4MB、1.28MB/9页 却要 24.3MB、7.8MB/10页 要 36.4MB。
     * 这部分仍由队列 worker 在后台承受，卡住的文档由 knowledge:reap-stuck 清道夫兜底。
     */
    private const MAX_TEXT_CHARS = 10_000_000;

    /**
     * 单个上传文件的兜底体积上限（50MB）。
     *
     * 网页路径上它是死的：php.ini 的 upload_max_filesize=10M 和
     * KnowledgeBaseController::MAX_UPLOAD_KB 都排在它前面。它守的是
     * tinker / 命令行 / 测试直接调 ingestFile 的那条路——那里一道闸门都没有。
     */
    private const MAX_FILE_BYTES = 50 * 1024 * 1024;

    /**
     * B7/§7：assistRecorder 可空带默认值 —— 既有测试和 tinker 里大量
     * `new DocumentIngestionService(new MockAiProvider())` 直接构造，容器解析时
     * 才会注入真实的 AssistUsageRecorder。为 null 时跳过扣费（测试环境语义）。
     *
     * outbound / http 同理可空：全仓有 6 处手动 new（GeoServiceProvider 与 4 个测试文件），
     * 加成必填参数会全部炸。为 null 时在 fetchUrlText 里从容器取 ——
     * 顺带让测试能用 app()->instance() 打桩出站层，不必改这 6 处构造。
     * ⚠️ 不是「可选安全」：取不到就抛，出站永远走安全链。
     */
    public function __construct(
        private readonly AiProvider $aiProvider,
        private readonly ?AssistUsageRecorder $assistRecorder = null,
        private readonly ?SafeOutboundHttpClient $outbound = null,
        private readonly ?SecureHttpFactory $http = null,
    ) {}

    /**
     * 正文规模闸门。三条入库路径（文件 / 网页 / 纯文本）共用。
     *
     * 注意 ingestFile 的 50MB 检查挡不住这里：php.ini 的 upload_max_filesize=10M
     * 先一步拒掉大文件，那条检查实际上是死的；而 ingestUrl / ingestText 这两条
     * 路径压根没有任何体积上限，正是它们能把内存撑爆。
     */
    private function guardTextSize(string $text): void
    {
        $length = mb_strlen($text);
        if ($length <= self::MAX_TEXT_CHARS) {
            return;
        }

        throw new IngestionFailure(sprintf(
            '文档正文 %s 字符，超过单篇 %s 字符上限，请拆分后再上传。',
            number_format($length),
            number_format(self::MAX_TEXT_CHARS),
        ));
    }

    /**
     * 登记一份上传文件：建行、落盘，不解析。
     *
     * 解析（提取正文 / 切块 / 向量化）全部交给 process()，由队列 worker 跑。
     * 这里必须快 —— 它跑在网页请求里，一份 50 万字符的白皮书同步解析要 26 秒
     * （2026-08-20 实测：每 4 万字符正文约 2 秒，串行打网关）。
     */
    /**
     * @param  int|null  $projectId  B7/§7 扣费归属：这笔费用记到哪个项目名下
     * @param  int|null  $uploadedBy B7/§7 扣谁的钱包（网关钱包按人开）
     */
    public function stageFile(int $knowledgeBaseId, UploadedFile $file, ?string $title = null, ?int $projectId = null, ?int $uploadedBy = null): KnowledgeDocument
    {
        // 兜底体积闸门。走网页上传时它确实是死的（php.ini 的 upload_max_filesize=10M
        // 和 MAX_UPLOAD_KB 都在前面先拒了），但 ingestFile 还能被 tinker / 测试 / 命令行
        // 直接调用，那条路上没有任何上限。留着它，成本是三行。
        if ((int) $file->getSize() > self::MAX_FILE_BYTES) {
            throw new IngestionFailure(sprintf(
                '文件 %s MB，超过 %d MB 上限，请压缩或拆分后再上传。',
                number_format($file->getSize() / 1048576, 1),
                intdiv(self::MAX_FILE_BYTES, 1048576),
            ));
        }

        $tenantId = TenantContext::id();

        $kb = KnowledgeBase::where('id', $knowledgeBaseId)
            ->where('tenant_id', $tenantId)
            ->first();
        if (! $kb) {
            throw new \RuntimeException('知识库不存在或不属于当前租户');
        }

        $document = app(\App\Services\AI\ModelConfiguration::class)->createDocument([
            'tenant_id' => $tenantId,
            'knowledge_base_id' => $knowledgeBaseId,
            'project_id' => $projectId,
            'uploaded_by' => $uploadedBy,
            'title' => $title ?? $file->getClientOriginalName(),
            'source_type' => 'upload',
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'status' => 'pending',
        ]);

        $storagePath = "knowledge/{$tenantId}/{$knowledgeBaseId}/{$document->id}/" . $file->getClientOriginalName();
        Storage::disk('local')->put($storagePath, file_get_contents($file->getRealPath()));
        $document->update(['file_path' => $storagePath]);

        return $document->fresh();
    }

    /**
     * 同步入库（登记 + 立即处理）。保留给：测试、tinker、以及回退开关关闭时的网页请求。
     */
    public function ingestFile(int $knowledgeBaseId, UploadedFile $file, ?string $title = null, ?int $projectId = null, ?int $uploadedBy = null): KnowledgeDocument
    {
        return $this->process($this->stageFile($knowledgeBaseId, $file, $title, $projectId, $uploadedBy));
    }

    /**
     * 登记一条网页摄入：仅做 SSRF 校验 + 建行，不抓取。
     *
     * 抓取（慢活，30 秒超时 + retry 2）在 process() 里现抓，绝不放在网页请求里。
     * SSRF 校验在登记时先跑一遍，让用户当场拿 422；process() 里再跑一遍，
     * 防止两次解析之间的 DNS rebinding。
     */
    /**
     * @param  int|null  $projectId  B7/§7 扣费归属（url 摄入暂不产生多模态费用，但归属照记）
     * @param  int|null  $uploadedBy B7/§7 扣谁的钱包
     */
    public function stageUrl(int $knowledgeBaseId, string $url, ?string $title = null, ?int $projectId = null, ?int $uploadedBy = null): KnowledgeDocument
    {
        // fragment 从不发给服务器，剥离后再校验、再入库，避免误杀带锚点的合法网页。
        $url = $this->stripFragment($url);
        $this->assertUrlIsSafe($url);

        $tenantId = TenantContext::id();

        $kb = KnowledgeBase::where('id', $knowledgeBaseId)
            ->where('tenant_id', $tenantId)
            ->first();
        if (! $kb) {
            throw new \RuntimeException('知识库不存在或不属于当前租户');
        }

        return app(\App\Services\AI\ModelConfiguration::class)->createDocument([
            'tenant_id' => $tenantId,
            'knowledge_base_id' => $knowledgeBaseId,
            'project_id' => $projectId,
            'uploaded_by' => $uploadedBy,
            'title' => $title ?? $url,
            'source_type' => 'url',
            'source_url' => $url,
            'status' => 'pending',
        ])->fresh();
    }

    /**
     * 同步摄入网页（登记 + 立即抓取解析）。保留给：测试、tinker、回退开关关闭时。
     */
    public function ingestUrl(int $knowledgeBaseId, string $url, ?string $title = null, ?int $projectId = null, ?int $uploadedBy = null): KnowledgeDocument
    {
        return $this->process($this->stageUrl($knowledgeBaseId, $url, $title, $projectId, $uploadedBy));
    }

    /**
     * 校验 URL 安全性（SSRF 防护），通过则返回用于绑定 IP 的 curlResolve 列表。
     *
     * 不安全的地址直接抛 IngestionFailure —— 调用方（登记阶段）据此给 422 并把原话带给用户。
     * 这些话里只会出现用户自己填的主机名，不含任何我们这边的地址。
     */
    private function safeOutbound(): SafeOutboundHttpClient
    {
        return $this->outbound ?? app(SafeOutboundHttpClient::class);
    }

    private function secureHttp(): SecureHttpFactory
    {
        return $this->http ?? app(SecureHttpFactory::class);
    }

    /**
     * 剥掉 URL 末尾的 fragment（#...）。fragment 是纯浏览器端定位符，从不发给服务器，
     * 抓网页时带不带它服务端都拿不到。但安全链对「内部 API 调用」保留「带 fragment 即拒」
     * 的严格策略（fragment 在内部调用里属于异常输入），所以在这里先剥掉，避免误杀用户
     * 从浏览器地址栏直接复制来的、常带 #top / #section 锚点的合法网页地址。
     */
    private function stripFragment(string $url): string
    {
        $pos = strpos($url, '#');

        return $pos === false ? $url : substr($url, 0, $pos);
    }

    /** 把内部 reasonCode 翻成用户看得懂的话，不把安全细节漏给前端。 */
    private function outboundFailureMessage(string $reasonCode): string
    {
        return match ($reasonCode) {
            // 文案必须带「内网地址」四个字：KnowledgeIngestReviewFixesTest 以此断言
            // 「拦下时要说清为什么、不许回请重试」。措辞上兼顾保留段/CGNAT 等非严格内网的情况。
            //
            // ⚠️ 这句只留给真的地址判定（unsafe_address / mapped_address / ambiguous_ip），
            // 不能放在 default 上当兜底 —— 那会让跳转、压缩、内部装配错误统统被说成
            // 「内网地址」，用户拿着这句话根本查不到真问题。
            'unsafe_address', 'mapped_address', 'ambiguous_ip' => '这个网址指向的是内网地址或其他非公网可达地址，出于安全考虑不能抓取，请换一个公网可以打开的网址。',
            'response_too_large' => '这个网页太大了（超过抓取上限），请换一个网址或改用文件上传。',
            'dns_resolution_failed' => '域名解析不到，请确认这个网址在公网上可以打开。',
            'invalid_scheme' => '只支持 http / https 开头的网址，请检查后重填。',
            'redirect_limit_exceeded', 'invalid_redirect' => '这个网址跳转次数过多（或跳转到了不能抓取的地址），请直接填跳转后的最终网址。',
            'encoded_response_forbidden' => '对方站点返回了我们无法解开的压缩内容，请换一个网址或改用文件上传。',
            'invalid_url', 'control_character', 'invalid_host', 'invalid_port',
            'invalid_address', 'userinfo_forbidden', 'fragment_forbidden' => '网址格式不对，请检查后重填。',
            // 剩下的是本系统内部的装配/环境问题（pinning_unavailable、
            // secure_pending_request_required 等），跟用户填的网址无关，不许赖到用户头上。
            default => '抓取这个网页失败：这次请求没能通过出站安全检查，请稍后再试；如持续出现请联系客服。',
        };
    }

    private function assertUrlIsSafe(string $url): void
    {
        // 登记阶段的早失败：让用户当场看到「这网址不行」，而不是排队半天才 failed。
        // 真正生效的校验在 fetchUrlText 里紧挨着发包的那一次（见下方说明）。
        try {
            $this->safeOutbound()->resolveTarget($url);
        } catch (OutboundRequestBlockedException $e) {
            throw new IngestionFailure($this->outboundFailureMessage($e->reasonCode));
        } catch (OutboundRequestFailedException) {
            throw new IngestionFailure('域名解析不到，请确认这个网址在公网上可以打开。');
        }
    }

    /**
     * 抓取并提取网页正文（仅 process() 调用）。
     *
     * 出站交给 SafeOutboundHttpClient：它在同一次调用里完成「解析 → 校验 → 把请求
     * pin 在已校验的 IP 上发出」，中间没有第二次 DNS 解析，DNS rebinding 无窗口可钻。
     * 原实现用 CURLOPT_RESOLVE 手工 pin，方向是对的，但仍有两处缺口：
     *   - 手写内网判定漏 CGNAT 100.64.0.0/10（云环境内部服务常落这段，实测被放行）；
     *   - 没有响应体上限，抓到超大页面直接把 worker 内存撑爆 —— 而内存耗尽是
     *     FatalError 不是异常，本类 catch(\Throwable) 接不住，文档会永远卡在 pending
     *     （正是 MAX_TEXT_CHARS 那道闸门要防的同一类失效）。
     */
    private function fetchUrlText(KnowledgeDocument $document): string
    {
        // 防御性剥离：即使文档是绕过 stageUrl 外部创建的，抓取时也不带 fragment 发出去。
        $url = $this->stripFragment($document->source_url);

        try {
            $response = $this->safeOutbound()->get(
                // retry 保留原行为：偶发抖动重试 2 次。重试是安全的 ——
                // 目标 IP 已由 CURLOPT_RESOLVE 钉死在 curl 选项里，重试不会重新解析 DNS。
                //
                // ⚠️ 但必须显式限定「只对连不上重试」并关掉 retryThrow，否则 Laravel 的
                // retry() 会把任何非 2xx 响应（404 / 403 / 500）当成失败：先 $response->throw()
                // 触发重试，重试完再抛 RequestException 出去。后果有两个：
                //   1. 下面那句「对方返回 HTTP xxx」永远走不到，用户看到的是「连不上这个网址」——
                //      一句假话（网站明明连得上，只是 404 或需要登录）；
                //   2. 每个 404 白等 500ms 再打一次，纯浪费。
                // 参见 vendor/laravel/framework/.../PendingRequest.php 的 retry 闭包：
                // $shouldRetry=false 只挡住第一次 throw，第二次 throw 由 retryThrow 控制。
                $this->secureHttp()->timeout(30)->retry(
                    2,
                    500,
                    // 判据要同时认 OutboundRequestFailedException：安全链的策略中间件会把
                    // 底层的连接异常统一归一化掉（FinalOutboundSecurityPolicy::throwNormalizedFailure，
                    // 为的是不把内部地址/密钥随异常原文漏出去），所以 ConnectionException
                    // 根本活不到这里 —— 只认它等于把「抖动重试」悄悄关掉了。
                    // 参数必须可空：3xx 走的也是这条判据，而 Response::toException()
                    // 只对 4xx/5xx 造异常，对 301/302 返回 **null**。写成不可空的
                    // \Throwable 会当场 TypeError，再被上层吞成「连不上这个网址」——
                    // 跳转就又挂了（真发包实测到的，假 transport 测不出来）。
                    when: static fn (?\Throwable $e): bool => $e instanceof ConnectionException
                        || $e instanceof OutboundRequestFailedException,
                    throw: false,
                ),
                $url,
                (int) config('geo.outbound_import_max_bytes', 5 * 1024 * 1024),
                // 必须跟跳转：http→https、裸域→www 在真实网页里是常态。跟跳转不放松防护，
                // 安全链对每一跳都重新解析 + 重新校验（见 SafeOutboundHttpClient::send）。
                maxRedirects: 3,
            );
        } catch (OutboundRequestBlockedException $e) {
            throw new IngestionFailure($this->outboundFailureMessage($e->reasonCode));
        } catch (OutboundRequestFailedException) {
            throw new IngestionFailure('抓取这个网页失败：连不上这个网址，请确认它在公网上可以正常打开。');
        }

        if (! $response->successful()) {
            throw new IngestionFailure("抓取这个网页失败（对方返回 HTTP {$response->status()}），请确认网址可以正常打开、且不需要登录。");
        }

        $html = $response->body();

        // 尝试提取 <title> 作为文档标题（仅当登记时没给自定义标题时）
        if ($document->title === $url
            && preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $tm)) {
            $document->update(['title' => trim(html_entity_decode($tm[1], ENT_QUOTES, 'UTF-8'))]);
        }

        // 可读性提取：优先从 <main>、[role=main] 或 <article> 中取内容，
        // 再回退到 <body>，最终回退到全文 strip_tags
        $text = $this->extractReadableText($html);
        $text = preg_replace('/\s+/', ' ', $text); // Normalize whitespace

        if (trim($text) === '') {
            throw new IngestionFailure('这个网页几乎没有可提取的正文（可能内容由脚本动态生成），请换一个网址或改用文件上传。');
        }

        $metadata = $document->metadata ?? [];
        $metadata['extraction_quality'] = app(\App\Services\Content\WebExtractionQuality::class)->assess($html, $text);
        $document->update(['metadata' => $metadata]);

        return $text;
    }

    /**
     * 登记一段纯文本：把正文写成 content.md 落盘 + 建行，不解析。
     *
     * 企业知识库「发布」走这条路 —— 发布看着像异步（草稿生成在队列里），
     * 但发布这一步原本是同步的，现在也改成登记 + 入队。
     *
     * @param  array<string, mixed>  $metadata
     */
    public function stageText(int $knowledgeBaseId, string $title, string $content, array $metadata = []): KnowledgeDocument
    {
        $tenantId = TenantContext::id();

        $kb = KnowledgeBase::where('id', $knowledgeBaseId)
            ->where('tenant_id', $tenantId)
            ->first();
        if (! $kb) {
            throw new \RuntimeException('知识库不存在或不属于当前租户');
        }

        $content = trim($content);
        if ($content === '') {
            throw new IngestionFailure('待入库内容为空。');
        }

        $document = app(\App\Services\AI\ModelConfiguration::class)->createDocument([
            'tenant_id' => $tenantId,
            'knowledge_base_id' => $knowledgeBaseId,
            'title' => $title,
            'project_id' => $kb->project_id,
            'source_type' => $metadata['source_type'] ?? 'enterprise_knowledge',
            'mime_type' => 'text/markdown',
            'file_size' => mb_strlen($content, '8bit'),
            'status' => 'pending',
            'metadata' => $metadata,
        ]);

        $storagePath = "knowledge/{$tenantId}/{$knowledgeBaseId}/{$document->id}/content.md";
        Storage::disk('local')->put($storagePath, $content);
        $document->update(['file_path' => $storagePath]);

        return $document->fresh();
    }

    /**
     * 同步入库纯文本（登记 + 立即处理）。保留给：测试、tinker、回退开关关闭时。
     *
     * @param  array<string, mixed>  $metadata
     */
    public function ingestText(int $knowledgeBaseId, string $title, string $content, array $metadata = []): KnowledgeDocument
    {
        return $this->process($this->stageText($knowledgeBaseId, $title, $content, $metadata));
    }

    /**
     * 解析一份已登记的文档：提取正文 → 闸门 → 切块 → 向量化 → 落状态。
     *
     * 跑在队列 worker 里。任何异常都先 markFailed 再抛出去，让 job 能记账。
     * 重入时复用已完成的批次；未确认的请求停下，不重复发送。
     */
    public function process(KnowledgeDocument $document): KnowledgeDocument
    {
        // 认领计费主体：入库走队列（IngestKnowledgeDocumentJob），那里 Auth 是空的。
        // uploaded_by 就是下面 assistRecorder->charge() 扣的那个人，两处必须同一个。
        return BillingContext::scoped(
            $document->uploaded_by !== null ? (int) $document->uploaded_by : null,
            'knowledge:ingest',
            fn (): KnowledgeDocument => $this->processWithinBillingContext($document),
        );
    }

    private function processWithinBillingContext(KnowledgeDocument $document): KnowledgeDocument
    {
        try {
            app(\App\Services\AI\ModelConfiguration::class)->assertDocumentModel($document);
            $document->refresh();
            if ($document->status === 'ready') {
                return $document;
            }
            $document->markProcessing();

            $extracted = $document->source_type === 'url'
                ? ['text' => $this->fetchUrlText($document), 'pages' => null]
                : $this->extractText($document->file_path, $document->mime_type);

            $text = $extracted['text'];
            $pageCount = $extracted['pages'] ?? null;

            // ── B5：视觉誊抄（PDF 且 GEO_KNOWLEDGE_VISION=true 时）──
            // 任何一步出问题都降级走原文字层路，绝不因为多模态挂了把整篇入库拖死。
            $vision = null;
            $visionMissing = ($document->mime_type === 'application/pdf' || str_starts_with((string) $document->mime_type, 'image/'))
                && app(\App\Services\AI\ModelConfiguration::class)->active('vision', $document->tenant_id) === null;
            if ($visionMissing) {
                $metadata = $document->metadata ?? [];
                $metadata['vision_notice'] = '图片未识别：未配置视觉模型';
                $document->update(['metadata' => $metadata]);
            }
            if ($this->visionApplicable($document)) {
                try {
                    $vision = $this->extractWithVision($document);
                } catch (\Throwable $e) {
                    Log::warning('Vision extraction degraded to text layer', [
                        'document_id' => $document->id,
                        'error' => mb_substr($e->getMessage(), 0, 300),
                    ]);
                    $vision = null;
                }
            }

            if ($vision !== null) {
                $text = $vision['text'];
                $pageCount = $vision['pages'];
            }

            // PDF parsers and uploaded text can contain broken byte sequences.
            // Chunks and JSON metadata must always contain valid UTF-8.
            if (! mb_check_encoding($text, 'UTF-8')) {
                $text = mb_scrub($text, 'UTF-8');
            }

            if (trim((string) $text) === '') {
                if ($visionMissing) {
                    throw new IngestionFailure('图片未识别：未配置视觉模型');
                }
                throw new IngestionFailure('没能从这份文件里提取到正文（可能是扫描件、纯图片或加密文件），请换一份能复制出文字的文件。');
            }
            $this->guardTextSize($text);

            $chunks = $this->chunkText($text);
            if ($chunks === []) {
                throw new IngestionFailure('正文太短，切不出可检索的内容。');
            }

            // B6-1：切片→页号映射（仅多模态文档有页概念）。定位不到就 null，宁缺毋猜。
            $chunkPages = null;
            if ($vision !== null) {
                $chunkPages = $this->mapChunksToPages($chunks, $text, $vision['page_starts']);
            }

            $chunkCount = $this->embedAndStoreChunks($document, $chunks);

            // A2：把提取情况写进 metadata，让前端能暴露「假成功」（§1.2）。
            // 取不到页数就写 null，绝不编一个数字（§2.A2）。
            $extractionMeta = [
                'method' => $vision['method'] ?? 'text_layer',
                'chars' => mb_strlen($text),
                'pages' => $pageCount,
                'extracted_at' => now()->toIso8601String(),
            ];
            if ($vision !== null) {
                $extractionMeta['vision_pages'] = $vision['vision_pages'];
                $extractionMeta['failed_pages'] = $vision['failed_pages'];
                $extractionMeta['unverified_terms'] = $vision['unverified_terms'];
                $extractionMeta['chunk_pages'] = $chunkPages;
                $extractionMeta['gateway_requests'] = $vision['gateway_requests'] ?? [];
                $extractionMeta['vision_tokens'] = [
                    'prompt' => $vision['prompt_tokens'],
                    'completion' => $vision['completion_tokens'],
                ];

            }

            $document->update(['metadata' => array_merge($document->metadata ?? [], [
                'extraction' => $extractionMeta,
            ])]);

            $document->markReady($chunkCount);

            $supersededId = (int) (($document->metadata ?? [])['supersedes_document_id'] ?? 0);
            if ($supersededId > 0) {
                KnowledgeDocument::query()->whereKey($supersededId)
                    ->where('tenant_id', $document->tenant_id)
                    ->where('knowledge_base_id', $document->knowledge_base_id)
                    ->where('project_id', $document->project_id)
                    ->update(['review_status' => 'archived']);
            }

            Log::info('Document ingested successfully', [
                'document_id' => $document->id,
                'chunks' => $chunkCount,
            ]);

            return $document->fresh();
        } catch (\Throwable $e) {
            // 存进库的这句话会显示在客户的浏览器里，所以只有 IngestionFailure 的原话能过去；
            // 其余异常（数据库报错、网关连不上、解析库炸了）一律换成通用提示，原文只进日志。
            $document->markFailed(IngestionFailure::messageFor($e));
            Log::error('Document ingestion failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * B5：视觉誊抄是否适用于这份文档。
     * 只有「上传的 PDF + 开关开了 + 文件还在盘上」才走；url/text/docx 一律原路。
     *
     * B7/§7 追加一道归属闸门：project_id / uploaded_by 缺任何一个都不许多模态 ——
     * 多模态是真实模型调用，归属不全时 charge() 会整个跳过，等于免费跑。
     * **绝不允许「读不到归属就免费跑一次多模态」**（执行书 §7.3），宁可降级纯文字层。
     */
    private function visionApplicable(KnowledgeDocument $document): bool
    {
        if (! app(\App\Services\AI\ModelConfiguration::class)->active('vision', $document->tenant_id)) {
            return false;
        }
        if (! $document->project_id || ! $document->uploaded_by) {
            if (config('geo.knowledge.vision_enabled', false)) {
                Log::warning('Vision extraction skipped: document has no charge attribution (project_id/uploaded_by)', [
                    'document_id' => $document->id,
                    'project_id' => $document->project_id,
                    'uploaded_by' => $document->uploaded_by,
                ]);
            }

            return false;
        }

        return $document->source_type !== 'url'
            && ($document->mime_type ?? '') === 'application/pdf'
            && ! empty($document->file_path)
            && config('geo.knowledge.vision_enabled', false);
    }

    /**
     * B5：对 needs_vision 的页跑视觉誊抄并按页合并正文。
     *
     * 返回 null = 一页都不用送（或分析失败），调用方走原 text_layer 路。
     * 合并规则（执行书 B5-2d）：
     *   · 送了且校验通过 → 用誊抄结果
     *   · 送了但失败/截断 → 用文字层结果，页号进 failed_pages
     *   · 没送 → 用文字层结果（analyzer 的 pdftotext 逐页文本）
     *
     * @return null|array{text:string, pages:int, method:string, vision_pages:list<int>, failed_pages:list<int>, unverified_terms:list<string>, page_starts:array<int,int>, prompt_tokens:int, completion_tokens:int, duration_seconds:float, provider:?string, model:?string}
     */
    private function extractWithVision(KnowledgeDocument $document): ?array
    {
        $absPath = Storage::disk('local')->path($document->file_path);
        if (! is_file($absPath)) {
            Log::warning('Vision extraction: source file missing', [
                'document_id' => $document->id,
                'path' => $document->file_path,
            ]);
            return null;
        }

        $analysis = (new PdfPageAnalyzer())->analyze($absPath);
        if ($analysis === []) {
            // 分析失败已在 analyzer 里写 warning（= 一页都不送），原路。
            return null;
        }

        $visionPages = array_values(array_filter(
            $analysis,
            fn (array $p): bool => $p['needs_vision'],
        ));
        if ($visionPages === []) {
            return null;
        }

        $extraction = (new VisionPageExtractor($this->aiProvider))->extract($document, $absPath, $analysis);

        // 按页合并：页与页之间用空行隔开，同时记下每页在合并正文里的起始偏移，
        // 供 B6 的切片→页号映射使用。
        $parts = [];
        $pageStarts = [];
        $pos = 0;
        $failedPages = [];
        foreach ($analysis as $page => $info) {
            $entry = $extraction['pages'][$page] ?? null;

            $pageText = null;
            if ($entry !== null) {
                if ($entry['status'] === 'ok') {
                    $pageText = $entry['content'];
                } else {
                    $failedPages[] = $page;
                }
            }
            $pageText = trim((string) ($pageText ?? $info['text'] ?? ''));
            if ($pageText === '') {
                continue;
            }

            $pageStarts[$page] = $pos;
            $parts[] = $pageText;
            $pos += mb_strlen($pageText) + 2; // +2 = "\n\n" 连接符
        }

        $totalPages = count($analysis);
        $sentPages = array_map(fn (array $p): int => $p['page'], $visionPages);

        // unverified_terms 拍平去重（执行书 B5 示例是平铺数组）。
        $unverifiedTerms = [];
        foreach ($extraction['unverified_terms'] as $terms) {
            foreach ($terms as $term) {
                if (! in_array($term, $unverifiedTerms, true)) {
                    $unverifiedTerms[] = $term;
                }
            }
        }

        return [
            'text' => implode("\n\n", $parts),
            'pages' => $totalPages,
            'method' => count($sentPages) === $totalPages ? 'vision' : 'hybrid',
            'vision_pages' => $sentPages,
            'failed_pages' => $failedPages,
            'unverified_terms' => $unverifiedTerms,
            'page_starts' => $pageStarts,
            'gateway_requests' => $extraction['gateway_requests'] ?? [],
            'billable_request_ids' => $extraction['billable_request_ids'] ?? [],
            'prompt_tokens' => $extraction['prompt_tokens'],
            'completion_tokens' => $extraction['completion_tokens'],
            'duration_seconds' => $extraction['duration_seconds'],
            'provider' => $extraction['provider'],
            'model' => $extraction['model'],
        ];
    }

    /**
     * B6-1：把切片映射到页号。
     *
     * chunkText 的每个切片都是合并正文的连续子串（含 overlap，起点单调递增），
     * 所以从上一个切片位置往后找第一次出现即可。找不到就 null ——
     * 映射不准宁可返回 null 也不要猜（执行书 B6-1）。
     *
     * @param  list<string>  $chunks
     * @param  array<int, int>  $page Starts page => 合并正文中的起始偏移（升序）
     * @return array<int, int|null> 切片下标 => 页号（或 null）
     */
    private function mapChunksToPages(array $chunks, string $text, array $pageStarts): array
    {
        if ($pageStarts === []) {
            return [];
        }

        $map = [];
        $searchFrom = 0;
        foreach ($chunks as $i => $chunk) {
            $pos = mb_strpos($text, $chunk, $searchFrom);
            if ($pos === false) {
                $map[$i] = null;
                continue;
            }
            $searchFrom = $pos + 1;

            $page = null;
            foreach ($pageStarts as $p => $start) {
                if ($start <= $pos) {
                    $page = $p;
                } else {
                    break;
                }
            }
            $map[$i] = $page;
        }

        return $map;
    }

    /**
     * 检查 IP 是否为私有/回环/链路本地/保留地址
     */

    /**
     * Extract text from a stored file based on mime type
     *
     * 支持格式：
     *   - text/plain, text/markdown: 直接读取
     *   - text/html, application/xhtml+xml: strip_tags 提取
     *   - application/pdf: smalot/pdfextractor 解析
     *   - application/vnd.openxmlformats-officedocument.wordprocessingml.document: phpoffice/phpword 解析
     */
    private function extractText(string $storagePath, ?string $mimeType): array
    {
        $fullPath = Storage::disk('local')->path($storagePath);

        return match ($mimeType) {
            'text/plain', 'text/markdown' => ['text' => file_get_contents($fullPath), 'pages' => null],
            'text/html', 'application/xhtml+xml' => ['text' => strip_tags(file_get_contents($fullPath)), 'pages' => null],
            'application/pdf' => $this->extractPdfText($fullPath),
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['text' => $this->extractDocxText($fullPath), 'pages' => null],
            default => ['text' => '', 'pages' => null], // 不支持的 MIME 类型不读取原始二进制，避免产生垃圾向量
        };
    }

    /**
     * 从 PDF 文件中提取文本
     *
     * 返回 ['text' => ..., 'pages' => ...]：pages 取自 smalot 已解析的对象，
     * 不让 A2 为拿页数新引依赖；解析库不在时 pages 自然为 null。
     */
    private function extractPdfText(string $fullPath): array
    {
        if (! class_exists(\Smalot\PdfParser\Parser::class)) {
            Log::warning('PDF parser not installed. Run: composer require smalot/pdfextractor');
            throw new \RuntimeException('PDF 解析库未安装，请联系管理员');
        }

        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($fullPath);
        $text = $pdf->getText();

        // 清理 PDF 提取的多余空白
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);

        $pageCount = null;
        try {
            $pageCount = count($pdf->getPages());
        } catch (\Throwable $e) {
            // 某些损坏/特殊 PDF 取页数会抛，取不到就写 null —— 绝不编。
            Log::warning('Failed to count PDF pages', ['error' => $e->getMessage()]);
        }

        return ['text' => $text, 'pages' => $pageCount];
    }

    /**
     * 从 DOCX 文件中提取文本
     */
    private function extractDocxText(string $fullPath): string
    {
        if (! class_exists(\PhpOffice\PhpWord\IOFactory::class)) {
            Log::warning('DOCX parser not installed. Run: composer require phpoffice/phpword');
            throw new \RuntimeException('DOCX 解析库未安装，请联系管理员');
        }

        $phpWord = \PhpOffice\PhpWord\IOFactory::load($fullPath);
        $textParts = [];

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $textParts[] = $this->extractDocxElementText($element);
            }
        }

        return trim(implode("\n", $textParts));
    }

    /**
     * 递归提取 DOCX 元素的文本内容
     */
    private function extractDocxElementText(object $element): string
    {
        $text = '';

        if (method_exists($element, 'getText')) {
            $raw = $element->getText();
            if (is_string($raw)) {
                $text .= $raw;
            } elseif (is_object($raw) && method_exists($raw, 'getText')) {
                $text .= $raw->getText();
            }
        }

        if (method_exists($element, 'getElements')) {
            foreach ($element->getElements() as $child) {
                $text .= $this->extractDocxElementText($child);
            }
        }

        return $text;
    }

    /** Extract only text a reader could see in the fetched HTML. */
    private function extractReadableText(string $html): string
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        $root = $xpath->query('//main')->item(0)
            ?? $xpath->query('//*[@role="main"]')->item(0)
            ?? $xpath->query('//article')->item(0)
            ?? $xpath->query('//body')->item(0)
            ?? $document->documentElement;

        return $root instanceof DOMNode ? $this->readableHtmlNode($root) : '';
    }

    private function readableHtmlNode(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return $node->nodeValue ?? '';
        }
        if ($node instanceof DOMElement) {
            $tag = strtolower($node->tagName);
            if (HtmlVisibility::isHidden($node)
                || in_array($tag, ['script', 'style', 'noscript', 'template', 'nav', 'header', 'footer', 'aside', 'svg', 'iframe', 'head'], true)) {
                return '';
            }
        }

        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= $this->readableHtmlNode($child);
        }

        if ($node instanceof DOMElement
            && preg_match('/^(br|p|div|section|article|main|h[1-6]|li|ul|ol|table|tr|td|th)$/i', $node->tagName)) {
            $text .= ' ';
        }

        return $text;
    }

    /**
     * Split text into chunks with overlap
     *
     * Strategy: ~500 tokens per chunk (approx 2000 chars for Chinese, 3000 for English)
     * with 100-char overlap between chunks.
     */
    private function chunkText(string $text, int $maxChunkChars = 2000, int $overlapChars = 200): array
    {
        if ($maxChunkChars <= $overlapChars) {
            throw new \InvalidArgumentException('maxChunkChars must exceed overlapChars');
        }

        $text = trim($text);
        if (mb_strlen($text) <= $maxChunkChars) {
            return [$text];
        }

        $chunks = [];
        $offset = 0;
        $textLen = mb_strlen($text);

        while ($offset < $textLen) {
            $end = min($offset + $maxChunkChars, $textLen);

            // Try to break at a sentence boundary
            if ($end < $textLen) {
                $segment = mb_substr($text, $offset, $maxChunkChars);
                // Look for sentence boundaries: ... ! ? .!? followed by space/newline
                $lastBreak = max(
                    mb_strrpos($segment, '。'),
                    mb_strrpos($segment, '！'),
                    mb_strrpos($segment, '？'),
                    mb_strrpos($segment, ".\n"),
                    mb_strrpos($segment, ". "),
                    mb_strrpos($segment, "\n\n"),
                );
                if ($lastBreak !== false && $lastBreak > $maxChunkChars * 0.3) {
                    $end = $offset + $lastBreak + 1;
                }
            }

            $chunk = mb_substr($text, $offset, $end - $offset);
            $chunks[] = trim($chunk);

            // 本段已经吃到正文结尾，后面没有内容了，直接收工。
            // 不加这一条会再绕一圈：$offset 退回 $textLen - $overlapChars，
            // 切出最后 200 个字符 —— 与上一段结尾逐字重复。
            // 2026-08-20 实测生产 4 篇文档 min(length(content)) 全是 200。
            if ($end >= $textLen) {
                break;
            }

            $nextOffset = $end - $overlapChars;
            // 当剩余文本短于 overlap 时，$nextOffset 可能不会前进，导致死循环。
            // 此时直接跳到 $end，让循环自然结束。
            if ($nextOffset <= $offset) {
                $nextOffset = $end;
            }
            $offset = $nextOffset;
            if ($offset >= $textLen) break;
        }

        return array_values(array_filter($chunks, fn($c) => trim($c) !== ''));
    }

    /**
     * Generate embeddings for chunks and store as KnowledgeChunk records
     */
    private function embedAndStoreChunks(KnowledgeDocument $document, array $chunks): int
    {
        $tenantId = $document->tenant_id;
        $count = 0;
        // Reprocessing identical content reuses each batch attempt. A changed
        // document is a new business operation even when its row ID is stable.
        $contentVersion = substr(hash('sha256', json_encode($chunks, JSON_THROW_ON_ERROR)), 0, 32);

        // Batch embed (up to 20 at a time)
        // Note: array_values() is required because array_chunk() preserves original
        // keys, but the API returns embeddings with 0-based indices. Without re-indexing,
        // $embeddings[$i] would misalign for any batch after the first.
        $embeddingKey = app(\App\Services\AI\ModelConfiguration::class)->active('embedding', $tenantId);
        $batchSize = $embeddingKey && config("ai-providers.{$embeddingKey->provider}.embedding_protocol") === 'ark_multimodal' ? 1 : 10;
        foreach (array_chunk($chunks, $batchSize) as $batchIndex => $batch) {
            $batch = array_values($batch);
            $attemptKey = $contentVersion.':'.$batchIndex;
            $reuse = DB::transaction(function () use ($document, $attemptKey, $batch): bool {
                $document->refresh();
                $current = KnowledgeDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
                app(\App\Services\AI\ModelConfiguration::class)->assertDocumentModel($current);
                $metadata = $current->metadata ?? [];
                $prior = $metadata['embedding_batches'][$attemptKey] ?? null;
                if (($prior['status'] ?? null) === 'completed') {
                    return true;
                }
                if ($prior !== null) {
                    throw new IngestionFailure('结果未知，可手动重新执行；如需重新入库，请删除这份材料后重新导入。');
                }
                $metadata['embedding_batches'][$attemptKey] = ['status' => 'prepared', 'count' => count($batch)];
                $current->update(['metadata' => $metadata]);
                return false;
            });
            if ($reuse) {
                $stored = KnowledgeChunk::query()->where('document_id', $document->id)
                    ->whereBetween('chunk_index', [$count, $count + count($batch) - 1])->orderBy('chunk_index')->get();
                if ($stored->count() !== count($batch)) {
                    throw new IngestionFailure('已保存的材料切片不完整，请删除这份材料后重新导入。');
                }
                foreach ($stored as $i => $chunk) {
                    if ($chunk->content !== $batch[$i] || ! \App\Services\AI\EmbeddingModelPolicy::validVector($chunk->embedding)) {
                        throw new IngestionFailure('已保存的材料切片与正文不一致，请删除这份材料后重新导入。');
                    }
                }
                $count += count($batch);
                continue;
            }
            $result = $this->aiProvider->embedBatch($batch, 'embedding', ['feature_id' => 'knowledge-ingestion', 'task_id' => 'document:'.$document->id.':'.$contentVersion, 'step_id' => (string) $batchIndex]);
            $embeddings = $result['embeddings'] ?? [];
            if ($this->assistRecorder && $document->uploaded_by && isset($result['provider'], $result['model'])) {
                $usage = $result['gateway']['usage'] ?? [];
                $this->assistRecorder->record($document->project_id, $document->uploaded_by, new AiProviderCallResult(
                    success: $embeddings !== [], provider: $result['provider'], model: $result['model'],
                    promptTokens: $usage['prompt_tokens'] ?? 0, completionTokens: $usage['completion_tokens'] ?? 0,
                    totalTokens: $usage['total_tokens'] ?? 0, gateway: $result['gateway'] ?? [],
                ), 'ai-assist-kb-embedding', '知识库向量化', metadata: ['document_id' => $document->id, 'batch' => $batchIndex]);
            }

            // 整批空 = 向量化真的失败了，不是这批文本没内容。继续往下走会把文档
            // 标成「已解析、0 切片」——检索一条都召不回，而用户看到的是绿色成功。
            // 2026-08-20 在生产实打一次 embedBatch，402 余额不足就走的这条路。
            if ($embeddings === []) {
                throw new IngestionFailure(sprintf(
                    '向量化失败（第 %d 批，共 %d 批）：%s',
                    $batchIndex + 1,
                    (int) ceil(count($chunks) / $batchSize),
                    $result['error'] ?? '未知原因',
                ));
            }

            // One transaction commits both vectors and their receipt. A crash before
            // this commit leaves the prepared marker and must never trigger a resend.
            DB::transaction(function () use ($document, $attemptKey, $embeddings, $result, $batch, $tenantId, &$count): void {
                $current = KnowledgeDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
                app(\App\Services\AI\ModelConfiguration::class)->assertDocumentModel($current);
                foreach ($batch as $i => $chunkText) {
                $embedding = $embeddings[$i] ?? null;
                if (! \App\Services\AI\EmbeddingModelPolicy::validVector($embedding)) {
                    throw new IngestionFailure('向量维度不匹配，知识库只接受 1024 维向量。');
                }

                // Try to extract section title from the chunk
                $sectionTitle = null;
                if (preg_match('/^#+\s+(.+)$/m', $chunkText, $m)) {
                    $sectionTitle = trim($m[1]);
                }

                KnowledgeChunk::create([
                    'tenant_id' => $tenantId,
                    'document_id' => $document->id,
                    'chunk_index' => $count,
                    'content' => $chunkText,
                    'section_title' => $sectionTitle,
                    'token_count' => (int) ceil(mb_strlen($chunkText) / 4), // rough estimate
                    'embedding' => $embedding,
                ]);
                $count++;
            }
                $metadata = $current->metadata ?? [];
                $metadata['embedding_batches'][$attemptKey] = ['status' => 'completed', 'count' => count($batch)];
                if (! empty($result['gateway'])) {
                    $metadata['embedding_requests'][] = $result['gateway'];
                }
                $current->update(['metadata' => $metadata]);
            });
            $document->refresh();
        }

        // 收尾兜底：每批都返回了数组、但里面每一条都是空向量 —— 半死不活的返回，
        // 也会让文档变成「已解析、0 切片」。有正文却 0 切片，必须当失败处理。
        if ($chunks !== [] && $count === 0) {
            throw new IngestionFailure('向量化没有产出任何切片，请稍后重试；如反复出现请联系我们。');
        }

        return $count;
    }
}
