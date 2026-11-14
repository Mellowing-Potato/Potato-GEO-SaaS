<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiVisibilityRun;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Services\Geo\AiVisibility\AiVisibilityConfigurationResolver;
use App\Services\Geo\AiVisibility\AiVisibilityService;
use App\Services\Reports\AiVisibilityAnalyticsFilter;
use App\Services\Reports\AiVisibilityAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AI 可见性监测 API（批次二 · 单 4）。
 *
 * POST 花真钱（豆包直连）→ 202 异步（单次 50 秒起步，前端轮询 show）。
 * 全部端点受 geo.visibility_panel_enabled 门控（默认 false）：
 * 这是本模块第一道回滚开关（§13.1），关掉后 404、不派发新任务。
 */
class AiVisibilityController extends Controller
{
    private const INTENT_MINER_SKILL_ID = 'yao-geo-intent-miner';

    public function __construct(
        private readonly AiVisibilityService $service,
        private readonly AiVisibilityAnalyticsService $analytics,
        private readonly AiVisibilityAnalyticsFilter $filter,
        private readonly AiVisibilityConfigurationResolver $config,
    ) {}

    /**
     * POST /api/v1/visibility/runs — 发起一轮监测（一个问题一个 Job，N 问题同批）。
     */
    public function store(Request $request): JsonResponse
    {
        $this->assertEnabled();

        if (! $this->config->connection()) {
            throw ValidationException::withMessages(['configuration' => '未配置豆包监测，请先在设置中填写模型配置。']);
        }

        $maxQuestions = $this->config->maxQuestions();

        $validated = $request->validate([
            'project_id' => ['required', 'integer', 'min:1'],
            'questions' => ['required', 'array', 'min:1', 'max:'.$maxQuestions],
            'questions.*' => ['required', 'string', 'max:500'],
        ]);

        $project = Project::query()->whereKey($validated['project_id'])->first();
        if (! $project) {
            // BelongsToTenant 作用域下跨租户/不存在都查不到 → 统一 422（与 skills execute 同口径）。
            throw ValidationException::withMessages([
                'project_id' => 'The selected project_id is invalid.',
            ]);
        }

        $questions = array_values(array_map(
            static fn (string $question): string => trim($question),
            $validated['questions'],
        ));
        $questions = array_values(array_filter($questions, static fn (string $q): bool => $q !== ''));
        // 同一批里完全相同的问题只执行一次，避免一次误粘贴产生重复模型调用和重复扣点。
        $questions = array_values(array_unique($questions));
        if ($questions === []) {
            throw ValidationException::withMessages([
                'questions' => '问题不能为空。',
            ]);
        }

        $created = $this->service->createRuns(
            userId: (int) $request->user()->id,
            projectId: (int) $project->id,
            questions: $questions,
        );

        Log::info('AiVisibility: batch created.', [
            'batch_id' => $created['batch_id'],
            'runs' => count($created['runs']),
            'project_id' => $project->id,
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => '已提交，监测在后台执行（单问题约 1 分钟）。请轮询 runs 接口获取进度。',
            'batch_id' => $created['batch_id'],
            'question_count' => count($created['runs']),
            'runs' => array_map(
                fn (AiVisibilityRun $run): array => $this->runSummary($run),
                $created['runs'],
            ),
        ], 202);
    }

    /**
     * GET /api/v1/visibility/runs — 运行历史（?batch_id= 轮询单批进度，含批次摘要）。
     */
    public function index(Request $request): JsonResponse
    {
        $this->assertEnabled();

        $batchId = trim((string) $request->query('batch_id', ''));

        if ($batchId !== '') {
            $runs = AiVisibilityRun::query()
                ->where('batch_id', $batchId)
                ->orderBy('question_index')
                ->get();

            return response()->json([
                'configured' => $this->config->connection() !== null,
                'data' => $runs->map(fn (AiVisibilityRun $run) => $this->runSummary($run))->values(),
                'batches' => $this->analytics->batchSummaries($runs),
            ]);
        }

        $page = $this->filter->apply($request)->paginate(min(50, max(10, (int) $request->query('per_page', 20))));

        return response()->json([
            'configured' => $this->config->connection() !== null,
            'data' => collect($page->items())
                ->map(fn (AiVisibilityRun $run) => $this->runSummary($run))
                ->values()
                ->all(),
            'batches' => $this->analytics->batchSummaries(collect($page->items())),
            'overview' => $this->analytics->overview($this->filter->apply($request)),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * GET /api/v1/visibility/question-suggestions — 复用项目已有意图问题库。
     *
     * 这是纯数据库读取；不会调用模型，也不会产生扣点。若项目还没有完整的意图
     * 报告，则只根据项目资料给出少量规则化示例，避免为了填表再跑一次模型。
     */
    public function questionSuggestions(Request $request): JsonResponse
    {
        $this->assertEnabled();

        $validated = $request->validate([
            'project_id' => ['required', 'integer', 'min:1'],
        ]);

        $project = Project::query()->whereKey($validated['project_id'])->first();
        if (! $project) {
            throw ValidationException::withMessages([
                'project_id' => 'The selected project_id is invalid.',
            ]);
        }

        $suggestions = [];

        // 最新一份“标记完成”的报告仍可能缺 question_bank，因此逐份向前找，
        // 找到第一份实际含问题的完整产物才停止。
        $executions = SkillExecution::query()
            ->where('project_id', $project->id)
            ->where('skill_id', self::INTENT_MINER_SKILL_ID)
            ->where('status', 'completed')
            ->latest('id')
            ->limit(20)
            ->get(['output_data']);

        foreach ($executions as $execution) {
            $suggestions = $this->normalizeQuestionBank(
                data_get($execution->output_data, 'structured.question_bank', []),
            );
            if ($suggestions !== []) {
                break;
            }
        }

        $source = 'intent_report';
        if ($suggestions === []) {
            $suggestions = $this->projectFallbackQuestions($project);
            $source = 'project_profile';
        }

        return response()->json([
            'data' => array_slice($suggestions, 0, $this->config->maxQuestions()),
            'source' => $source,
            'incurs_charge' => false,
        ]);
    }

    /**
     * GET /api/v1/visibility/runs/{runId} — 结果详情（前端轮询直到终态）。
     *
     * 显式按 ID 查（不用隐式绑定）：绑定发生在 IdentifyTenant 之前、TenantScope
     * fail-open，实测会跨租户泄漏；控制器查询时租户上下文已就位、作用域生效。
     */
    public function show(int $runId): JsonResponse
    {
        $this->assertEnabled();

        $run = AiVisibilityRun::query()->findOrFail($runId);

        return response()->json([
            'data' => $this->runDetail($run->fresh(['sources'])),
        ]);
    }

    /**
     * GET /api/v1/visibility/runs/{runId}/download/{format} — markdown|html 报告下载。
     */
    public function download(int $runId, string $format = 'markdown'): Response|JsonResponse|StreamedResponse
    {
        $this->assertEnabled();

        $format = strtolower(trim($format));
        if (! in_array($format, ['markdown', 'html'], true)) {
            return response()->json(['message' => 'Unsupported format: '.$format], 404);
        }

        $run = AiVisibilityRun::query()->findOrFail($runId);

        if (! $run->isTerminal()) {
            return response()->json(['message' => '监测尚未结束，暂无可下载报告。'], 409);
        }

        $content = $format === 'html'
            ? $this->service->buildHtmlReport($run)
            : $this->service->buildMarkdownReport($run);

        $filename = 'ai-visibility-report-'.$run->id.'.'.$format;
        $contentType = $format === 'html' ? 'text/html; charset=utf-8' : 'text/markdown; charset=utf-8';

        return response()->stream(
            function () use ($content): void {
                echo $content;
            },
            200,
            [
                'Content-Type' => $contentType,
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ],
        );
    }

    // ── 私有 ─────────────────────────────────────────────────────────────

    private function assertEnabled(): void
    {
        if (! $this->config->enabled()) {
            // 功能闸默认关：对外不暴露路由语义（404），也不给「这里有个隐藏功能」的提示。
            abort(404);
        }
    }

    /**
     * @return list<array{question: string, cluster: ?string, priority: ?string}>
     */
    private function normalizeQuestionBank(mixed $questionBank): array
    {
        if (! is_array($questionBank)) {
            return [];
        }

        $result = [];
        $seen = [];
        foreach ($questionBank as $item) {
            $question = is_string($item)
                ? trim($item)
                : trim((string) data_get($item, 'question', data_get($item, 'standalone_rewrite', '')));

            if ($question === '' || isset($seen[$question])) {
                continue;
            }

            $seen[$question] = true;
            $result[] = [
                'question' => $question,
                'cluster' => is_array($item) ? ($item['cluster'] ?? null) : null,
                'priority' => is_array($item) ? ($item['priority'] ?? null) : null,
            ];
        }

        return $result;
    }

    /**
     * @return list<array{question: string, cluster: string, priority: string}>
     */
    private function projectFallbackQuestions(Project $project): array
    {
        $brand = trim((string) ($project->brand_name ?: $project->name));
        $market = trim((string) data_get($project->settings, 'target_market', ''));
        $subject = $market !== '' ? $market : '相关行业';

        return [
            ['question' => $brand.'是做什么的？', 'cluster' => '品牌基础认知', 'priority' => 'P0'],
            ['question' => $brand.'适合哪些客户？', 'cluster' => '适用场景', 'priority' => 'P0'],
            ['question' => $subject.'有哪些值得关注的服务商？', 'cluster' => '品类推荐', 'priority' => 'P1'],
            ['question' => $brand.'和同类方案相比有什么特点？', 'cluster' => '品牌对比', 'priority' => 'P1'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runSummary(AiVisibilityRun $run): array
    {
        return [
            'id' => $run->id,
            'batch_id' => $run->batch_id,
            'question_index' => $run->question_index,
            'question' => $run->question,
            'status' => $run->status,
            'is_terminal' => $run->isTerminal(),
            'project_id' => $run->project_id,
            'created_at' => optional($run->created_at)->toIso8601String(),
            'completed_at' => optional($run->completed_at)?->toIso8601String(),
            'duration_seconds' => (float) $run->duration_seconds,
            'total_tokens' => data_get($run->metadata, 'usage_available') === true
                ? (int) $run->prompt_tokens + (int) $run->completion_tokens : null,
            'search_call_count' => (int) $run->search_call_count,
            'search_count_estimated' => $run->searchCountEstimated(),
            'error_message' => $run->error_message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runDetail(AiVisibilityRun $run): array
    {
        $answerText = (string) ($run->answer_text ?? '');

        return [
            ...$this->runSummary($run),
            'answer_text' => $run->answer_text,
            // annotations 是本次联网检索返回的参考结果；只有正文自己带 [n] 时，
            // 才能把它们理解成正文引用。该标记让界面对两种情况说清楚。
            'answer_citations_present' => preg_match('/\[\d+\]/u', $answerText) === 1,
            'search_queries' => $run->searchQueries(),
            'sources' => $run->sources
                ->sortBy('rank')
                // 兼容修复前的历史运行：接口展示时也按 URL 去重，不改历史账目。
                ->unique(fn ($source) => trim((string) $source->url))
                ->values()
                ->map(fn ($source) => [
                    'id' => $source->id,
                    'citation_key' => $source->citation_key,
                    'title' => $source->title,
                    'url' => $source->url,
                    'domain' => $source->domain,
                    'site_name' => $source->site_name,
                    'snippet' => $source->snippet,
                    'published_at' => optional($source->published_at)?->toIso8601String(),
                    'rank' => $source->rank,
                ])
                ->all(),
            // 用量仅展示 tokens 与搜索次数。
            'usage' => [
                'prompt_tokens' => data_get($run->metadata, 'usage_available') === true ? (int) $run->prompt_tokens : null,
                'completion_tokens' => data_get($run->metadata, 'usage_available') === true ? (int) $run->completion_tokens : null,
                'cached_tokens' => data_get($run->metadata, 'usage_available') === true ? (int) $run->cached_tokens : null,
                'search_call_count' => (int) $run->search_call_count,
            ],
        ];
    }
}
