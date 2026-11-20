<?php

namespace App\Services\Knowledge;

use App\Models\KnowledgeDocument;
use App\Services\Contracts\AiProvider;
use App\Services\DTOs\AiProviderCallResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * B4：把 needs_vision 的 PDF 页渲染成图，交给多模态模型逐字誊抄，再做双向校验。
 *
 * 一页一次调用，绝不合并（§1.4 结论三：合并整本会让模型在长上下文里丢内容）。
 * 模型固定 geo-plus（qwen3.7-plus，thinking 已在网关侧关掉；geo-max 单价 4.5 倍
 * 且思考开着，誊抄用不上 —— 见执行书 B4-3）。
 *
 * 失败语义（B5 按 status 合并正文时依赖）：
 *   ok        誊抄成功且（若可校验）双向校验通过 → B5 用誊抄内容
 *   truncated 两次调用都撞 max_tokens → 半截内容绝不当成功入库，退回文字层
 *   failed    调用失败 / 正向校验查漏（数字金额对不上）→ 退回文字层
 *   skipped   渲染失败或降档后仍超 4MB → 退回文字层
 */
class VisionPageExtractor
{
    /** 誊抄模型：geo-plus（百炼 qwen3.7-plus，多模态，thinking 关）。 */
    public const MODEL = 'vision';

    /** 首次调用的出字上限（单页 600 字正文约 1,000 token，4096 余量充分）。 */
    public const MAX_TOKENS = 4096;

    /** 撞上限后的重试上限（只重试一次，仍截断就判 truncated）。 */
    public const RETRY_MAX_TOKENS = 8192;

    /** 渲染出图超过这个体积（字节）就降档重渲。base64 后约 ×1.37，4MB 是网关侧的安全线。 */
    public const MAX_IMAGE_BYTES = 4 * 1024 * 1024;

    /** B4-2 system 提示词（逐字，勿改）。 */
    public const SYSTEM_PROMPT = '你是文档转录工具。你的唯一职责是把图片里的文字一字不差地转成 Markdown。';

    /** B4-2 user 提示词（逐字，勿改）。第 5 条专治「陈小聪乍得」那类把图标认成国旗的幻觉。 */
    public const USER_PROMPT = <<<'PROMPT'
    把这一页的全部内容逐字转录成 Markdown，不是解析、不是总结、不是分析。

    规则：
    1. 只转录。不归纳、不改写、不补充、不评价、不提建议。
    2. 页面上有什么就写什么，一个字都不能省。价格、数字、人名、日期、
       型号、配置、联系方式，必须原样照抄。
    3. 表格转成 Markdown 表格，保持行列对应关系。
    4. 不许自创章节分组或小标题，页面上没有的层级不要造。
    5. 图标、装饰图形、二维码不要转成文字，也不要猜它是什么，
       直接写 [图标] / [二维码]。
    6. 看不清的地方写 [无法识别]，禁止猜测或补全。
    7. 输出只包含转录内容本身，不要开场白，不要结尾总结。
    PROMPT;

    /** 反向校验：单页 unverified_terms 上限，防 metadata 被整段幻觉撑爆。 */
    private const MAX_UNVERIFIED_TERMS_PER_PAGE = 20;

    /** 反向校验：单个中文连续串参与比对的最大长度（更长的串拆给正向覆盖逻辑天然处理）。 */
    private const MAX_RUN_LENGTH = 50;

    public function __construct(
        private readonly AiProvider $aiProvider,
    ) {}

    /**
     * 对 analysis 里标了 needs_vision 的页逐页誊抄。
     *
     * @param  array<int, array{page:int,image_coverage:float,chars:int,needs_vision:bool,text:string}>  $analysis
     *         PdfPageAnalyzer::analyze() 的输出
     * @return array{
     *     pages: array<int, array{status:string, content:?string, reason:?string, prompt_tokens:int, completion_tokens:int}>,
     *     unverified_terms: array<int, list<string>>,
     *     prompt_tokens: int,
     *     completion_tokens: int,
     *     duration_seconds: float,
     *     provider: ?string,
     *     model: ?string,
     * }
     *         token 合计只含 ok 页 —— B7 扣费时失败页的 token 不计入（执行书 §7）。
     *         provider/model/duration 同样只统计 ok 页，供 B7 聚合成一次扣费调用。
     */
    public function extract(KnowledgeDocument $document, string $pdfAbsPath, array $analysis): array
    {
        $pages = [];
        $gatewayRequests = [];
        $billableRequests = [];
        $unverifiedTerms = [];
        $sumPrompt = 0;
        $sumCompletion = 0;
        $sumDuration = 0.0;
        $provider = null;
        $model = null;

        foreach ($analysis as $page => $info) {
            if (! ($info['needs_vision'] ?? false)) {
                continue;
            }

            // ── B4-1 渲染 ──
            $jpg = $this->renderPage($document, $pdfAbsPath, (int) $page);
            if ($jpg === null) {
                $pages[$page] = $this->pageEntry('skipped', null, 'render_failed_or_oversize', 0, 0);
                continue;
            }

            // ── B4-3/B4-4 调用 + 截断重试 ──
            $result = $this->transcribeOnce($document, $jpg, self::MAX_TOKENS);
            if ($result->gateway) $gatewayRequests[] = $result->gateway;

            if ($result->success && $result->finishReason === 'length') {
                Log::warning('VisionPageExtractor: page hit max_tokens, retrying once at 8192', [
                    'document_id' => $document->id,
                    'page' => $page,
                ]);
                $result = $this->transcribeOnce($document, $jpg, self::RETRY_MAX_TOKENS);
                if ($result->gateway) $gatewayRequests[] = $result->gateway;
            }

            if (! $result->success) {
                Log::warning('VisionPageExtractor: transcription failed, falling back to text layer', [
                    'document_id' => $document->id,
                    'page' => $page,
                    'error' => mb_substr((string) $result->errorMessage, 0, 200),
                ]);
                $pages[$page] = $this->pageEntry('failed', null, 'call_failed', 0, 0);
                if (($result->gateway['request_sent'] ?? true) === false || ($result->gateway['status'] ?? null) === 'unknown') {
                    break;
                }
                continue;
            }

            if ($result->finishReason === 'length') {
                // 半截内容绝不当成功入库（B4-4 阻断项）。
                Log::warning('VisionPageExtractor: page still truncated after retry, marked truncated', [
                    'document_id' => $document->id,
                    'page' => $page,
                ]);
                $pages[$page] = $this->pageEntry('truncated', null, 'max_tokens_exceeded', 0, 0);
                continue;
            }

            // ── B4-5 双向校验（仅对同时有文字层和誊抄结果的页） ──
            $textLayer = (string) ($info['text'] ?? '');
            $vision = (string) $result->content;
            $failure = null;

            if (trim($textLayer) !== '') {
                $failure = $this->forwardValidate($textLayer, $vision);
            }

            if ($failure !== null) {
                // 正向查漏是阻断项：数字/金额/百分比对不上，这一页判失败退回文字层。
                Log::warning('VisionPageExtractor: forward validation failed, falling back to text layer', [
                    'document_id' => $document->id,
                    'page' => $page,
                    'missing' => $failure,
                ]);
                $pages[$page] = $this->pageEntry('failed', null, 'forward_validation:' . $failure, 0, 0);
                continue;
            }

            if (trim($textLayer) !== '') {
                $terms = $this->reverseValidate($textLayer, $vision);
                if ($terms !== []) {
                    $unverifiedTerms[$page] = $terms;
                }
            }

            $pages[$page] = $this->pageEntry(
                'ok',
                $vision,
                null,
                $result->promptTokens,
                $result->completionTokens,
            );
            if (!empty($result->gateway['request_id'])) $billableRequests[] = $result->gateway['request_id'];
            $sumPrompt += $result->promptTokens;
            $sumCompletion += $result->completionTokens;
            $sumDuration += (float) $result->durationSeconds;
            // B7：聚合扣费结果的 provider/model 取任意 ok 页的（同一文档都一样）
            $provider ??= $result->provider;
            $model ??= $result->model;
        }

        return [
            'pages' => $pages,
            'gateway_requests' => $gatewayRequests,
            'billable_request_ids' => $billableRequests,
            'unverified_terms' => $unverifiedTerms,
            'prompt_tokens' => $sumPrompt,
            'completion_tokens' => $sumCompletion,
            'duration_seconds' => $sumDuration,
            'provider' => $provider,
            'model' => $model,
        ];
    }

    /**
     * 渲染单页为 JPEG，落盘到 storage/app/knowledge/{t}/{kb}/{doc}/pages/p{n}.jpg。
     * B6 图文对照复用同一批文件，渲染完不删。
     *
     * 超过 4MB 降到 -scale-to 1000 重渲一次；还超就跳过该页（返回 null）。
     */
    private function renderPage(KnowledgeDocument $document, string $pdfAbsPath, int $page): ?string
    {
        $dir = $this->pagesDirectory($document);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            Log::warning('VisionPageExtractor: cannot create pages directory', ['dir' => $dir]);
            return null;
        }

        $jpgPath = $dir . '/p' . $page . '.jpg';
        $ok = $this->runPdftoppm($pdfAbsPath, $page, $dir . "/tmp_p{$page}_a", 1400, $jpgPath);

        if ($ok && @filesize($jpgPath) > self::MAX_IMAGE_BYTES) {
            Log::info('VisionPageExtractor: image over 4MB, re-rendering at scale 1000', [
                'document_id' => $document->id,
                'page' => $page,
                'bytes' => @filesize($jpgPath),
            ]);
            $ok = $this->runPdftoppm($pdfAbsPath, $page, $dir . "/tmp_p{$page}_b", 1000, $jpgPath);
        }

        if (! $ok) {
            Log::warning('VisionPageExtractor: pdftoppm failed, page skipped', [
                'document_id' => $document->id,
                'page' => $page,
            ]);
            return null;
        }

        if (@filesize($jpgPath) > self::MAX_IMAGE_BYTES) {
            // 降档后仍超 4MB：跳过该页（退回文字层），不把超大图删掉留给 B6 排查也行，
            // 但 4MB 以上的图没法安全送网关，删掉避免图文对照误挂。
            $oversize = @filesize($jpgPath) ?: 0;
            @unlink($jpgPath);
            Log::warning('VisionPageExtractor: page still over 4MB after re-render, skipped', [
                'document_id' => $document->id,
                'page' => $page,
                'bytes' => $oversize,
            ]);
            return null;
        }

        return $jpgPath;
    }

    /** 渲染到 tmp 前缀再把产物挪成 p{n}.jpg（pdftoppm 会自动在前缀后追加 -页号）。 */
    private function runPdftoppm(string $pdfAbsPath, int $page, string $tmpPrefix, int $scaleTo, string $finalPath): bool
    {
        // 上一次的残留（同页重渲时 tmp_a 已挪走，双保险清一遍）。
        foreach (glob($tmpPrefix . '-*.jpg') ?: [] as $stale) {
            @unlink($stale);
        }

        $process = new Process([
            'pdftoppm', '-jpeg', '-r', '150', '-scale-to', (string) $scaleTo,
            '-f', (string) $page, '-l', (string) $page,
            $pdfAbsPath, $tmpPrefix,
        ]);
        $process->setTimeout(60)->run();

        if (! $process->isSuccessful()) {
            Log::warning('VisionPageExtractor: pdftoppm exited non-zero', [
                'page' => $page,
                'error' => mb_substr($process->getErrorOutput(), 0, 200),
            ]);
            return false;
        }

        $produced = glob($tmpPrefix . '-*.jpg') ?: [];
        if ($produced === []) {
            return false;
        }

        $moved = @rename($produced[0], $finalPath);
        if (! $moved) {
            return false;
        }

        // 挪走后清理可能多出的产物（正常只有一个）。
        foreach (glob($tmpPrefix . '-*.jpg') ?: [] as $rest) {
            @unlink($rest);
        }

        return true;
    }

    /** Durable per-page checkpoint prevents duplicate calls after a worker restart. */
    private function transcribeOnce(KnowledgeDocument $document, string $jpgPath, int $maxTokens): AiProviderCallResult
    {
        $attempt = basename($jpgPath).':'.$maxTokens.':'.hash_file('sha256', $jpgPath);
        $saved = DB::transaction(function () use ($document, $attempt): ?array {
            $current = KnowledgeDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $metadata = $current->metadata ?? [];
            $prior = $metadata['vision_attempts'][$attempt] ?? null;
            if ($prior !== null) {
                if (isset($prior['result'])) { return $prior['result']; }
                throw new \App\Exceptions\IngestionFailure('图片识别结果未知，可手动重新执行；重新入库前请删除这份材料。');
            }
            $metadata['vision_attempts'][$attempt] = ['status' => 'prepared'];
            $current->update(['metadata' => $metadata]);
            return null;
        });
        if ($saved !== null) {
            return new AiProviderCallResult(...$saved);
        }
        $result = $this->transcribe(
            $jpgPath, $maxTokens, 'document:'.$document->id, $document->metadata['vision_connection_snapshot'] ?? null,
        );
        DB::transaction(function () use ($document, $attempt, $result): void {
            $current = KnowledgeDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $metadata = $current->metadata ?? [];
            $metadata['vision_attempts'][$attempt] = ['status' => 'recorded', 'result' => get_object_vars($result)];
            $current->update(['metadata' => $metadata]);
        });
        $document->refresh();
        if ($document->project_id && $document->uploaded_by && ($result->gateway['request_sent'] ?? true) !== false) {
            app(\App\Services\Billing\AssistUsageRecorder::class)->record(
                $document->project_id, $document->uploaded_by, $result,
                'ai-assist-kb-vision', '知识库图像识别', metadata: ['document_id' => $document->id, 'page' => basename($jpgPath)],
            );
        }
        return $result;
    }

    /** 一页一次调用（B4-3）。不传 enable_thinking —— 网关按路由已配好。 */
    private function transcribe(
        string $jpgPath,
        int $maxTokens,
        string $taskId,
        ?array $snapshot = null,
    ): AiProviderCallResult
    {
        $dataUrl = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($jpgPath));

        return $this->aiProvider->generate(self::USER_PROMPT, self::SYSTEM_PROMPT, [
            'model' => self::MODEL,
            'feature_id' => 'pdf-transcription',
            'task_id' => $taskId,
            'connection_snapshot' => $snapshot,
            'step_id' => basename($jpgPath),
            'temperature' => 0,
            'max_tokens' => $maxTokens,
            'images' => [$dataUrl],
        ]);
    }

    /**
     * B4-5 正向校验（查漏，阻断项）：文字层里的每个数字/金额/百分比都必须能在
     * 誊抄输出里找到。归一化：全角转半角、去所有空白、，→,。
     *
     * @return string|null 缺失的 token（用于失败原因），全部找到返回 null。
     */
    private function forwardValidate(string $textLayer, string $vision): ?string
    {
        $textNorm = $this->normalize($textLayer);
        $visionNorm = $this->normalize($vision);

        // 数字/金额/百分比：连续数字，可含千分位逗号和小数点，可带 %。
        if (! preg_match_all('/[0-9]+(?:[,.][0-9]+)*%?/', $textNorm, $m)) {
            return null;
        }

        foreach (array_unique($m[0]) as $token) {
            // 单字符数字（如「第8条」的 8）在誊抄里几乎必然以别的形式出现，放过以降误报。
            if (strlen($token) < 2 && ! str_ends_with($token, '%')) {
                continue;
            }
            if (! str_contains($visionNorm, $token)) {
                return $token;
            }
        }

        return null;
    }

    /**
     * B4-5 反向校验（查凭空增加，不阻断）：誊抄输出里出现、文字层完全没有的
     * 2 字以上连续中文 → unverified_terms。
     *
     * 「陈小聪」「乍得」这类词落进名单是预期行为 —— 名单的作用是让人一眼看到
     * 「这几个词只有模型说有」，不是替人做判断（执行书 B4-5）。
     *
     * @return list<string>
     */
    private function reverseValidate(string $textLayer, string $vision): array
    {
        $textNorm = $this->normalize($textLayer);
        $visionNorm = $this->normalize($vision);

        if (! preg_match_all('/[\x{4e00}-\x{9fff}]{2,}/u', $visionNorm, $m)) {
            return [];
        }

        $terms = [];
        foreach (array_unique($m[0]) as $run) {
            if (mb_strlen($run) > self::MAX_RUN_LENGTH) {
                continue;
            }

            foreach ($this->uncoveredChineseSegments($run, $textNorm) as $segment) {
                if (count($terms) >= self::MAX_UNVERIFIED_TERMS_PER_PAGE) {
                    return $terms;
                }
                if (! in_array($segment, $terms, true)) {
                    $terms[] = $segment;
                }
            }
        }

        return $terms;
    }

    /**
     * 把一个中文连续串里「文字层确实存在」的部分剥掉，剩下的裸段即凭空出现的词。
     * 例：串=陈小聪乍得、文字层含「陈小聪」→ 剥掉后剩「乍得」。
     *
     * @return list<string>
     */
    private function uncoveredChineseSegments(string $run, string $textNorm): array
    {
        $len = mb_strlen($run);
        $covered = array_fill(0, $len, false);

        for ($l = min(12, $len); $l >= 2; $l--) {
            for ($i = 0; $i + $l <= $len; $i++) {
                $sub = mb_substr($run, $i, $l);
                if (mb_strpos($textNorm, $sub) !== false) {
                    for ($k = $i; $k < $i + $l; $k++) {
                        $covered[$k] = true;
                    }
                }
            }
        }

        $segments = [];
        $buffer = '';
        for ($i = 0; $i < $len; $i++) {
            if ($covered[$i]) {
                if (mb_strlen($buffer) >= 2) {
                    $segments[] = $buffer;
                }
                $buffer = '';
            } else {
                $buffer .= mb_substr($run, $i, 1);
            }
        }
        if (mb_strlen($buffer) >= 2) {
            $segments[] = $buffer;
        }

        return $segments;
    }

    /** B4-5 归一化：全角转半角、，→,、去掉所有空白。 */
    private function normalize(string $s): string
    {
        $s = mb_convert_kana($s, 'as');
        $s = str_replace('，', ',', $s);

        return preg_replace('/\s+/u', '', $s) ?? '';
    }

    /** @return array{status:string, content:?string, reason:?string, prompt_tokens:int, completion_tokens:int} */
    private function pageEntry(string $status, ?string $content, ?string $reason, int $promptTokens, int $completionTokens): array
    {
        return [
            'status' => $status,
            'content' => $content,
            'reason' => $reason,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
        ];
    }

    private function pagesDirectory(KnowledgeDocument $document): string
    {
        // 必须从 Storage 盘取路径：local 盘的 root 是 storage/app/private（Laravel 11+ 默认），
        // 不是 storage/app。与 DocumentIngestionService 落盘 file_path 的盘和目录约定保持一致，
        // B6 图文对照接口也从同一张盘读。
        return Storage::disk('local')->path(
            $document->storageDirectory() . '/pages'
        );
    }
}
