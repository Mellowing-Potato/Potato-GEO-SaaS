<?php

namespace App\Services\SkillAgent;

use App\Models\Project;
use App\Services\DTOs\SkillBrief;
use App\Services\DTOs\SkillExecutionResult;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\Log;

/**
 * 执行前页面预检（计划 §7 第 3 步：把 `SkillAgentRunnerService` 按职责拆小，「准备」里的页面预检）。
 *
 * 页面审计这类技能不能凭客户填的一串字符就写报告 —— 必须真把页面抓下来，报告只许写抓到的。
 * 这一桶是三件事：
 *   1. observe()          —— 该抓的技能抓一次（白名单 + 逃生阀），抓成功顺手回填项目官网；
 *   2. failureResult()    —— 抓失败的收场：判执行失败、不调模型、**不扣费**（0 次调用 / 0 词元）；
 *   3. rememberBrandUrl() —— 抓成功后把地址记回项目，只在字段为空时写。
 *
 * 判据：`tests/Feature/SkillPagePreflightTest.php` 冻结白名单行为与三条判据；端到端由
 * `PageAuditRunnerTest`（一次执行只抓一遍、逃生阀、官网回填、不相关技能一次请求都不发）与
 * `PageAuditSectionRelayTest`（接力路径）兜底。
 */
class SkillPagePreflight
{
    /**
     * 页面技术审计的抓取字段：skill id => 存放 URL 的简报字段名。
     *
     * 为什么是白名单而不是照 SkillDefinition::requiresWeb 判 —— requiresWeb 在注册表里
     * 有 13 个技能是 true，但它一直只是个**展示标签**（全仓只有两处消费方，都是原样吐给
     * 前端），从来没有人按它发过请求。拿它当开关，等于一次给 13 个技能改行为，
     * 其中多数根本没有 URL 字段。这里只放本单验收过的那个。
     */
    private const PAGE_FETCH_SKILLS = [
        'yao-geo-page-audit' => 'url',
    ];

    public function __construct(private readonly ?PageFetchService $pageFetch = null) {}

    /**
     * 需要抓页面的技能就抓一次；不需要的返回 null。
     *
     * 对页面审计和执行路线图生效。开关 GEO_PAGE_FETCH_ENABLED 是逃生阀，
     * ⚠️ 关掉它等于**退回 2026-09-06 之前那个会编报告的行为**，只在抓取本身出系统性
     * 故障、且宁可要一份未经实测的报告也不能全停的时候才关。
     * 执行路线图另走首页和下载页双页核验；没有官网地址时如实标为未核验。
     */
    public function observe(SkillBrief $brief): ?PageObservation
    {
        if (! (bool) config('geo.page_fetch_enabled', true)) {
            return null;
        }

        // 路线图必须以本次官网事实校准前置报告。它不做页面技术审计，只读首页
        // 以及首页实际链接到的下载页，避免把历史版本状态写成当前状态。
        if ($brief->skillId === 'yao-geo-execution-roadmap') {
            return $this->observeRoadmapSite($brief);
        }

        $field = self::PAGE_FETCH_SKILLS[$brief->skillId] ?? null;
        if ($field === null) {
            return null;
        }

        $url = trim((string) $brief->field($field, ''));
        if ($url === '') {
            return PageObservation::failure('', 'missing_url', __('page_audit.fetch.invalid_url'));
        }

        $observation = ($this->pageFetch ?? app(PageFetchService::class))->fetch($url);

        Log::info('Page fetched for skill', [
            'skill_id' => $brief->skillId,
            'ok' => $observation->ok,
            'status_code' => $observation->statusCode,
            'failure_code' => $observation->failureCode,
            // ⚠️ 只记 host，不记完整 URL：客户填的地址可能带 query 参数（预览 token 之类）。
            'host' => parse_url($observation->requestedUrl, PHP_URL_HOST),
        ]);

        if ($observation->ok) {
            $this->rememberBrandUrl($brief, $observation);
        }

        return $observation;
    }

    private function observeRoadmapSite(SkillBrief $brief): ?PageObservation
    {
        $url = trim((string) $brief->field('brand_url', ''));
        if ($url === '' || $url === '(未提供)') {
            return null;
        }

        $fetch = $this->pageFetch ?? app(PageFetchService::class);
        $homepage = $fetch->fetch($url);
        if (! $homepage->ok) {
            return $homepage;
        }

        $facts = $homepage->facts;
        $downloadUrl = $this->linkedDownloadUrl($homepage);
        $facts['related_download_page'] = ['status' => 'not_found'];
        if ($downloadUrl !== null) {
            $download = $fetch->fetch($downloadUrl);
            if (! $download->ok) {
                return PageObservation::failure(
                    $url,
                    'related_page_failed',
                    '官网首页可访问，但下载页抓取失败；无法核验当前版本状态，请稍后重试。',
                    $download->statusCode,
                );
            }

            $facts['related_download_page'] = [
                'status' => 'fetched',
                'url' => $download->finalUrl,
                'status_code' => $download->statusCode,
                'title' => $download->facts['head']['title'] ?? null,
                'headings' => $download->facts['headings'] ?? [],
                'visible_text' => $download->facts['prose']['first_paint_sample'] ?? '',
                'fetched_at' => $download->facts['fetched_at'] ?? null,
            ];
        }

        Log::info('Official site fetched for roadmap', [
            'skill_id' => $brief->skillId,
            'host' => parse_url($homepage->finalUrl ?? $url, PHP_URL_HOST),
            'download_page_status' => $facts['related_download_page']['status'],
        ]);

        return PageObservation::success(
            $homepage->requestedUrl,
            $homepage->finalUrl ?? $homepage->requestedUrl,
            $homepage->statusCode ?? 200,
            $facts,
        );
    }

    private function linkedDownloadUrl(PageObservation $homepage): ?string
    {
        $base = $homepage->finalUrl ?? $homepage->requestedUrl;
        $host = strtolower((string) parse_url($base, PHP_URL_HOST));
        foreach ($homepage->facts['links']['download_candidates'] ?? [] as $candidate) {
            $href = trim((string) ($candidate['href'] ?? ''));
            if ($href === '' || str_starts_with($href, '#')) {
                continue;
            }
            try {
                $resolved = (string) UriResolver::resolve(new Uri($base), new Uri($href));
            } catch (\Throwable) {
                continue;
            }
            $scheme = strtolower((string) parse_url($resolved, PHP_URL_SCHEME));
            $baseScheme = strtolower((string) parse_url($base, PHP_URL_SCHEME));
            if (in_array($scheme, ['http', 'https'], true)
                && ($scheme === 'https' || $scheme === $baseScheme)
                && strtolower((string) parse_url($resolved, PHP_URL_HOST)) === $host
                && parse_url($resolved, PHP_URL_PATH) !== parse_url($base, PHP_URL_PATH)) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * 抓取失败的收场：判执行失败，不调模型，不扣费。
     *
     * errorMessage 由两句拼成 —— 先说清「为什么没有报告」，再说清「是什么挡住了」。
     * 客户看完要能自己判断下一步做什么，而不是只知道"失败了"。
     */
    public function failureResult(
        SkillBrief $brief,
        PageObservation $observation,
        ?int $tenantId,
        float $startTime,
    ): SkillExecutionResult {
        Log::warning('Skill aborted: page fetch failed', [
            'skill_id' => $brief->skillId,
            'project_id' => $brief->projectId,
            'failure_code' => $observation->failureCode,
            'status_code' => $observation->statusCode,
        ]);

        return new SkillExecutionResult(
            executionId: 0,
            skillId: $brief->skillId,
            success: false,
            outputPaths: [],
            intermediateData: [
                'skill_id' => $brief->skillId,
                'tenant_id' => $tenantId,
                'project_id' => $brief->projectId,
                'generated_at' => now()->toISOString(),
                'page_fetch_failed' => true,
                'page_observation' => $observation->toArray(),
            ],
            qualityReport: null,
            durationSeconds: round(microtime(true) - $startTime, 2),
            // 0 次调用、0 词元：这一单没有花过钱，账面上也要是 0。
            llmCallCount: 0,
            tokenUsage: 0,
            errorMessage: __('page_audit.execution_failed').' '.$observation->failureMessage,
        );
    }

    /**
     * 抓成功了就把这个地址记回项目。
     *
     * 起因是生产 execution 52：项目的 brand_url 是空的 → 全景审计据此断言「官网缺失」→
     * 页面审计明明拿到了客户当场填的、能打开的地址，报告却照抄了那句「官网缺失」。
     * 客户已经把地址告诉我们了，不该只活在这一次执行里。
     *
     * 只在字段为空时写：已经填过的值是客户自己设的，不拿单次技能输入去覆盖它。
     */
    public function rememberBrandUrl(SkillBrief $brief, PageObservation $observation): void
    {
        try {
            $project = Project::find($brief->projectId);
            if ($project === null || trim((string) $project->brand_url) !== '') {
                return;
            }

            $project->update(['brand_url' => $observation->finalUrl ?? $observation->requestedUrl]);

            Log::info('Project brand_url backfilled from skill input', [
                'project_id' => $project->id,
                'skill_id' => $brief->skillId,
            ]);
        } catch (\Throwable $exception) {
            // 回填失败不该拖垮这次执行 —— 报告该出还是要出。
            Log::warning('Failed to backfill project brand_url', [
                'project_id' => $brief->projectId,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
