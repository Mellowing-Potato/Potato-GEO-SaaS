<?php

namespace App\Services\Content;

use App\Models\Article;
use App\Models\UrlImport;
use App\Models\UrlImportLog;
use App\Services\Outbound\OutboundRequestBlockedException;
use App\Services\Outbound\OutboundRequestFailedException;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Services\Outbound\SecureHttpFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * URL 文章导入加工服务（阶段 1.3，对标 GEOFlow UrlImportProcessingService）
 *
 * 流程：抓取 HTML（走出站安全链）→ 抽取标题 → 正文转 Markdown → 生成 Article 草稿（status=draft）。
 * 失败不阻塞：记录日志、将导入任务标记为 failed，供前端追溯。
 *
 * 出站安全由 SafeOutboundHttpClient 统一裁决，本服务不再自建 SSRF 判定。
 * 原自建实现有三处真实缺口，已随本次改造消除：
 *   1. 校验完 IP 后用 Http::get($url) 再解析一次 DNS —— 两次解析之间 IP 可被换成
 *      内网地址（DNS rebinding / TOCTOU）。安全链把请求 pin 在已校验的 IP 上。
 *   2. 手写内网段漏了 CGNAT 100.64.0.0/10、192.0.0.0/24、224.0.0.0/4、240.0.0.0/4
 *      等，云环境内部服务常落在 CGNAT 段。
 *   3. 没有响应体上限，抓到超大页面会打爆 worker 内存。
 */
class UrlImportProcessingService
{
    public function __construct(
        private readonly HtmlToMarkdownConverter $converter,
        private readonly SafeOutboundHttpClient $outbound,
        private readonly SecureHttpFactory $http,
        private readonly WebExtractionQuality $quality,
    ) {}

    public function process(UrlImport $import): void
    {
        $import->update(['status' => 'processing']);

        try {
            $html = $this->fetchHtml($import->url);
            $title = $this->extractTitle($html) ?? $import->title ?? $import->url;
            $markdown = trim($this->converter->convert($html, $import->url));
            $quality = $this->quality->assess($html, $markdown);
            if ($quality['chars'] === 0) {
                throw new \RuntimeException('没有提取到网页正文，请重抓或人工补全。');
            }

            $article = Article::create([
                'project_id' => $import->project_id,
                'title' => Str::limit($title, 500),
                'body_markdown' => $markdown !== '' ? $markdown : '# ' . $title,
                'status' => 'draft',
                'source_type' => 'url_import',
                'word_count' => mb_strlen(strip_tags($markdown)),
                'metadata' => [
                    'source_url' => $import->url,
                    'imported_at' => now()->toDateTimeString(),
                    'extraction_quality' => $quality,
                ],
            ]);

            $import->update([
                'status' => 'completed',
                'title' => $title,
                'article_id' => $article->id,
                'metadata' => array_merge((array) $import->metadata, [
                    'word_count' => $article->word_count,
                    'extraction_quality' => $quality,
                ]),
            ]);

            $this->log($import, 'info', 'URL 导入完成，已生成草稿文章 #' . $article->id);
        } catch (\Throwable $e) {
            $import->update([
                'status' => 'failed',
                'error_message' => Str::limit($e->getMessage(), 1000),
            ]);

            $this->log($import, 'error', 'URL 导入失败：' . $e->getMessage(), [
                'url' => $import->url,
                'trace' => mb_substr($e->getTraceAsString(), 0, 2000),
            ]);
        }
    }

    /**
     * 抓取 URL 内容，全程走出站安全链。
     *
     * 不再有「测试环境跳过防护」的分支 —— 那个开关的代价是测试跑的根本不是生产路径，
     * 线上出事时测试全绿。测试改用假 DNS + 假传输打桩（见 Tests\Concerns\FakesSafeOutbound），
     * 地址判定仍是真的在跑。
     */
    private function fetchHtml(string $url): string
    {
        // fragment（#...）是纯浏览器端定位符，从不发给服务器。安全链对内部 API 调用保留
        // 「带 fragment 即拒」的严格策略，这里先剥掉，避免误杀带锚点的合法网页。
        $url = $this->stripFragment($url);

        try {
            $response = $this->outbound->get(
                $this->http->timeout(30),
                $url,
                (int) config('geo.outbound_import_max_bytes', 5 * 1024 * 1024),
                // 必须跟跳转：http→https、裸域→www、旧链接→新链接在真实网页里是常态，
                // 不跟等于把大半个互联网判成「抓不了」。跟跳转不放松防护 —— 安全链对
                // 每一跳都重新解析 + 重新校验地址（见 SafeOutboundHttpClient::send 的循环），
                // 跳到内网仍然拦得住。3 是安全链允许的上限。
                maxRedirects: 3,
            );
        } catch (OutboundRequestBlockedException $e) {
            // 给用户看的是人话，不把内部 reasonCode 直接抛到前端。
            throw new \RuntimeException($this->blockedMessage($e->reasonCode), 0, $e);
        } catch (OutboundRequestFailedException $e) {
            throw new \RuntimeException('抓取失败：无法连接这个网址，请确认它在公网上可以打开。', 0, $e);
        }

        if (! $response->successful()) {
            throw new \RuntimeException('抓取失败，HTTP 状态 ' . $response->status());
        }

        return (string) $response->body();
    }

    /**
     * 剥掉 URL 末尾的 fragment（#...）。fragment 是纯浏览器端定位符，从不发给服务器。
     * 安全链对「内部 API 调用」保留「带 fragment 即拒」的严格策略，这里先剥掉，
     * 避免误杀用户从浏览器地址栏复制来的、常带 #top / #section 锚点的合法网页地址。
     */
    private function stripFragment(string $url): string
    {
        $pos = strpos($url, '#');

        return $pos === false ? $url : substr($url, 0, $pos);
    }

    private function blockedMessage(string $reasonCode): string
    {
        return match ($reasonCode) {
            // 「内网地址」这句只留给真的地址判定，不能当兜底 —— 兜底会让跳转、压缩、
            // 装配错误统统被说成「内网地址」，用户照着这句话查一整天也查不出问题。
            'unsafe_address', 'mapped_address', 'ambiguous_ip' => '这个网址指向的是内网地址或其他非公网可达地址，出于安全考虑不能抓取。',
            'response_too_large' => '这个网页太大了（超过导入上限），请换一个网址或改用文件上传。',
            'dns_resolution_failed' => '域名解析不到，请确认这个网址在公网上可以打开。',
            'redirect_limit_exceeded', 'invalid_redirect' => '这个网址跳转次数过多（或跳转到了不能抓取的地址），请直接填跳转后的最终网址。',
            'encoded_response_forbidden' => '对方站点返回了我们无法解开的压缩内容，请换一个网址或改用文件上传。',
            'invalid_url', 'invalid_scheme', 'control_character', 'invalid_host',
            'invalid_port', 'invalid_address', 'userinfo_forbidden',
            'fragment_forbidden' => '网址格式不对，请检查后重填。',
            // 剩下的是本系统内部的装配/环境问题（如 pinning_unavailable、
            // secure_pending_request_required），跟用户填的网址无关，不许赖到用户头上。
            default => '抓取失败：这次请求没能通过出站安全检查，请稍后再试；如持续出现请联系客服。',
        };
    }

    private function extractTitle(string $html): ?string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            return $this->cleanText($m[1]);
        }

        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m)) {
            return $this->cleanText($m[1]);
        }

        return null;
    }

    private function cleanText(string $text): string
    {
        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function log(UrlImport $import, string $level, string $message, array $context = []): void
    {
        UrlImportLog::create([
            'tenant_id' => $import->tenant_id,
            'url_import_id' => $import->id,
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ]);

        Log::info('[url-import] ' . $message, ['url_import_id' => $import->id] + $context);
    }
}
