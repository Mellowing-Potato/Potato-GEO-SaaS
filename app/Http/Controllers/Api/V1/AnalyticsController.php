<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ModelTierLabel;
use App\Models\AnalyticsCrawlerHit;
use App\Models\AnalyticsVisitLog;
use App\Models\Article;
use App\Models\AiVisibilityRun;
use App\Models\ArticlePublication;
use App\Models\ModelCallLog;
use App\Models\Project;
use App\Models\PublishingChannel;
use App\Models\SkillExecution;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Analytics\TrafficClassifier;
use App\Support\OperationalTextRedactor;
use App\Support\ArticleAssetClassifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    public function __construct(
    ) {}

    /**
     * GET /api/v1/analytics/summary
     * Return KPI summary for the dashboard/reports page.
     */
    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate(['project_id' => ['nullable', 'integer'], 'days' => ['nullable', 'integer', 'min:1', 'max:90']]);
        $projectId = isset($validated['project_id']) ? (int) $validated['project_id'] : null;
        if ($projectId !== null) {
            Project::query()->whereKey($projectId)->firstOrFail();
        }
        $days = max(1, min((int) ($request->query('days', 30)), 90));
        $since = now()->subDays($days);

        // The content library is all-time, current-project, non-recycled and excludes report assets.
        $allAssets = Article::query()->whereHas('project')->where('status', '!=', Article::STATUS_RECYCLED)
            ->when($projectId, fn ($query) => $query->where('project_id', $projectId))
            ->with('skillExecution:id,skill_id')
            ->get();
        $articleRows = $allAssets->filter(fn (Article $article) => ArticleAssetClassifier::isArticle($article));
        $periodArticles = $articleRows->filter(fn (Article $article) => $article->created_at >= $since);
        $totalArticles = $articleRows->count();
        $publishedArticles = $articleRows->where('status', 'published')->count();
        $approvedArticles = $articleRows->where('status', 'approved')->count();
        $pendingArticles = $articleRows->where('status', 'pending')->count();
        $reportAssets = $allAssets->count() - $totalArticles;

        $publicationQuery = $this->publicationQuery($projectId ? (int) $projectId : null, null, $since);
        $totalPublications = (clone $publicationQuery)->count();
        $publishedPublications = (clone $publicationQuery)->where('status', ArticlePublication::STATUS_PUBLISHED)->count();
        $failedPublications = (clone $publicationQuery)->where('status', ArticlePublication::STATUS_FAILED)->count();

        // Skill execution stats
        $execQuery = SkillExecution::query()->where('created_at', '>=', $since);
        if ($projectId) {
            $execQuery->where('project_id', $projectId);
        }

        $totalExecutions = $execQuery->count();
        $successfulExecutions = (clone $execQuery)->where('status', 'completed')->count();
        $totalTokens = $execQuery->sum('token_usage');

        // Project stats
        $projectQuery = Project::query()->whereNull('deleted_at');
        if ($projectId) {
            $projectQuery->where('id', $projectId);
        }
        $activeProjects = (clone $projectQuery)->where('status', 'active')->count();
        $totalProjects = $projectQuery->count();

        // Content by type
        $contentByType = $periodArticles->countBy('content_type');

        // Content by status
        $contentByStatus = $periodArticles->countBy('status');

        $publicationsByStatus = $this->publicationQuery($projectId ? (int) $projectId : null, null, $since)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        // Execution by skill
        $execBySkill = SkillExecution::query()
            ->when($projectId, fn($q) => $q->where('project_id', $projectId))
            ->where('created_at', '>=', $since)
            ->select('skill_name', DB::raw('count(*) as count'))
            ->groupBy('skill_name')
            ->orderByDesc('count')
            ->get()
            ->map(fn($r) => ['skill' => $r->skill_name, 'count' => $r->count]);

        return response()->json([
            'period' => ['from' => $since->toDateString(), 'to' => now()->toDateString(), 'days' => $days],
            'metrics' => [
                'articles_total' => $totalArticles,
                'articles_period' => $periodArticles->count(),
                'report_assets_total' => $reportAssets,
                'articles_published' => $publishedArticles,
                'articles_approved' => $approvedArticles,
                'articles_pending' => $pendingArticles,
                'publications_total' => $totalPublications,
                'publications_published' => $publishedPublications,
                'publications_failed' => $failedPublications,
                'executions_total' => $totalExecutions,
                'executions_success' => $successfulExecutions,
                'tokens_used' => $totalTokens,
                'projects_active' => $activeProjects,
                'projects_total' => $totalProjects,
            ],
            'breakdowns' => [
                'content_by_type' => $contentByType,
                'content_by_status' => $contentByStatus,
                'publications_by_status' => $publicationsByStatus,
                'executions_by_skill' => $execBySkill,
            ],
            'article_scope' => [
                'project_id' => $projectId,
                'time' => 'all_time',
                'asset' => 'content_article',
                'recycled' => 'excluded',
            ],
            'ai_visibility' => $this->visibilityEvidence($projectId, $since),
        ]);
    }

    /**
     * GET /api/v1/analytics/trends
     * Return daily trend data for charts.
     */
    public function trends(Request $request): JsonResponse
    {
        $projectId = $request->query('project_id');
        $days = max(1, min((int) ($request->query('days', 30)), 90));
        $since = now()->subDays($days);

        // Daily article creation trend
        $articleTrend = Article::query()
            ->whereHas('project')
            ->where('status', '!=', Article::STATUS_RECYCLED)
            ->when($projectId, fn($q) => $q->where('project_id', $projectId))
            ->where('created_at', '>=', $since)
            ->with('skillExecution:id,skill_id')
            ->get()->filter(fn (Article $article) => ArticleAssetClassifier::isArticle($article))
            ->groupBy(fn (Article $article) => $article->created_at->toDateString())
            ->map(fn ($articles, $date) => ['date' => $date, 'count' => $articles->count()])
            ->sortKeys()->values();

        // Daily execution trend
        $execTrend = SkillExecution::query()
            ->when($projectId, fn($q) => $q->where('project_id', $projectId))
            ->where('created_at', '>=', $since)
            ->select(DB::raw("DATE(created_at) as date"), DB::raw('count(*) as count'), DB::raw("sum(case when status = 'completed' then 1 else 0 end) as success_count"))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Daily token usage trend
        $tokenTrend = SkillExecution::query()
            ->when($projectId, fn($q) => $q->where('project_id', $projectId))
            ->where('created_at', '>=', $since)
            ->select(DB::raw("DATE(created_at) as date"), DB::raw('sum(token_usage) as tokens'))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $publicationTrend = $this->publicationQuery($projectId ? (int) $projectId : null, null, $since)
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('count(*) as count'),
                DB::raw("sum(case when status = 'published' then 1 else 0 end) as published_count"),
                DB::raw("sum(case when status = 'failed' then 1 else 0 end) as failed_count")
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Stage output breakdown
        $stageOutput = SkillExecution::query()
            ->when($projectId, fn($q) => $q->where('project_id', $projectId))
            ->where('created_at', '>=', $since)
            ->select('stage_index', DB::raw('count(*) as count'))
            ->groupBy('stage_index')
            ->orderBy('stage_index')
            ->get();

        return response()->json([
            'period' => ['from' => $since->toDateString(), 'to' => now()->toDateString(), 'days' => $days],
            'trends' => [
                'articles' => $articleTrend,
                'executions' => $execTrend,
                'tokens' => $tokenTrend,
                'publications' => $publicationTrend,
            ],
            'stage_output' => $stageOutput,
        ]);
    }

    /**
     * GET /api/v1/analytics/distribution
     * Publishing effectiveness across projects and channels.
     */
    public function distribution(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['nullable', 'integer'],
            'channel_id' => ['nullable', 'integer'],
            'days' => ['nullable', 'integer', 'min:1', 'max:366'],
            'recent' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $projectId = isset($validated['project_id']) ? (int) $validated['project_id'] : null;
        $channelId = isset($validated['channel_id']) ? (int) $validated['channel_id'] : null;
        $days = (int) ($validated['days'] ?? 30);
        $recentLimit = (int) ($validated['recent'] ?? 10);
        $since = now()->subDays($days);

        if ($projectId !== null) {
            $project = Project::query()->whereKey($projectId)->first();
            abort_unless($project, 404, '项目不存在。');
        }

        if ($channelId !== null) {
            $channel = PublishingChannel::query()->whereKey($channelId)->first();
            abort_unless($channel, 404, '发布渠道不存在。');
        }

        $base = $this->publicationQuery($projectId, $channelId, $since);
        $total = (clone $base)->count();
        $published = (clone $base)->where('status', ArticlePublication::STATUS_PUBLISHED)->count();
        $failed = (clone $base)->where('status', ArticlePublication::STATUS_FAILED)->count();
        $publishing = (clone $base)->where('status', ArticlePublication::STATUS_PUBLISHING)->count();
        $queued = (clone $base)->where('status', ArticlePublication::STATUS_QUEUED)->count();
        $accepted = (clone $base)->where('status', ArticlePublication::STATUS_ACCEPTED)->count();
        $attempts = (int) (clone $base)->sum('attempt_count');
        $retryAttempts = max(0, $attempts - $total);

        $statusBreakdown = $this->publicationQuery($projectId, $channelId, $since)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $channelBreakdown = $this->publicationQuery($projectId, $channelId, $since)
            ->with('channel:id,tenant_id,name,type,is_active')
            ->get()
            ->groupBy('publishing_channel_id')
            ->map(function ($publications): array {
                $first = $publications->first();

                return [
                    'channel_id' => $first->publishing_channel_id,
                    'channel_name' => $first->channel?->name,
                    'channel_type' => $first->channel?->type,
                    'is_active' => (bool) ($first->channel?->is_active ?? false),
                    'total' => $publications->count(),
                    'published' => $publications->where('status', ArticlePublication::STATUS_PUBLISHED)->count(),
                    'failed' => $publications->where('status', ArticlePublication::STATUS_FAILED)->count(),
                    'retry_attempts' => max(0, (int) $publications->sum('attempt_count') - $publications->count()),
                ];
            })
            ->sortByDesc('total')
            ->values();

        $projectBreakdown = $this->publicationQuery($projectId, $channelId, $since)
            ->with('article.project:id,name')
            ->get()
            ->groupBy(fn (ArticlePublication $publication): int => (int) $publication->article?->project_id)
            ->map(function ($publications): array {
                $first = $publications->first();

                return [
                    'project_id' => $first->article?->project_id,
                    'project_name' => $first->article?->project?->name,
                    'total' => $publications->count(),
                    'published' => $publications->where('status', ArticlePublication::STATUS_PUBLISHED)->count(),
                    'failed' => $publications->where('status', ArticlePublication::STATUS_FAILED)->count(),
                ];
            })
            ->filter(fn (array $row): bool => $row['project_id'] !== null)
            ->sortByDesc('total')
            ->values();

        $dailyTrend = $this->publicationQuery($projectId, $channelId, $since)
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('count(*) as count'),
                DB::raw("sum(case when status = 'published' then 1 else 0 end) as published_count"),
                DB::raw("sum(case when status = 'failed' then 1 else 0 end) as failed_count")
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $recent = $this->publicationQuery($projectId, $channelId, $since)
            ->with(['article:id,tenant_id,project_id,title,slug,status', 'article.project:id,name', 'channel:id,tenant_id,name,type'])
            ->latest('id')
            ->limit($recentLimit)
            ->get()
            ->map(fn (ArticlePublication $publication): array => [
                'id' => $publication->id,
                'status' => $publication->status,
                'attempt_count' => $publication->attempt_count,
                'article_id' => $publication->article_id,
                'article_title' => $publication->article?->title,
                'article_slug' => $publication->article?->slug,
                'article_status' => $publication->article?->status,
                'project_id' => $publication->article?->project_id,
                'project_name' => $publication->article?->project?->name,
                'channel_id' => $publication->publishing_channel_id,
                'channel_name' => $publication->channel?->name,
                'channel_type' => $publication->channel?->type,
                'remote_url' => $publication->remote_url,
                'remote_status' => data_get($publication->metadata, 'remote_status'),
                'article_version' => data_get($publication->metadata, 'article_version'),
                'last_error' => OperationalTextRedactor::redact($publication->last_error),
                'last_attempted_at' => $publication->last_attempted_at?->toISOString(),
                'published_at' => $publication->published_at?->toISOString(),
                'created_at' => $publication->created_at?->toISOString(),
            ])
            ->values();

        return response()->json([
            'object' => 'geo_distribution_analytics',
            'period' => ['from' => $since->toDateString(), 'to' => now()->toDateString(), 'days' => $days],
            'filters' => [
                'project_id' => $projectId,
                'channel_id' => $channelId,
            ],
            'metrics' => [
                'channels_total' => PublishingChannel::query()->count(),
                'channels_active' => PublishingChannel::query()->where('is_active', true)->count(),
                'publications_total' => $total,
                'publications_published' => $published,
                'publications_failed' => $failed,
                'publications_publishing' => $publishing,
                'publications_queued' => $queued,
                'publications_accepted' => $accepted,
                'success_rate' => $total > 0 ? round($published / $total, 4) : null,
                'attempts_total' => $attempts,
                'retry_attempts' => $retryAttempts,
            ],
            'breakdowns' => [
                'by_status' => $statusBreakdown,
                'by_channel' => $channelBreakdown,
                'by_project' => $projectBreakdown,
            ],
            'trends' => [
                'daily_publications' => $dailyTrend,
            ],
            'recent_publications' => $recent,
        ]);
    }

    /**
     * GET /api/v1/dashboard/activity
     * 最近活动列表（Dashboard 任务流）
     */
    public function activity(Request $request): JsonResponse
    {
        $validated = $request->validate(['project_id' => ['sometimes', 'required', 'integer', 'min:1']]);
        $projectId = isset($validated['project_id']) ? (int) $validated['project_id'] : null;
        if ($projectId !== null) {
            Project::query()->findOrFail($projectId);
        }
        $limit = min((int) $request->query('limit', 10), 30);

        // 最近 Skill 执行
        $recentExecutions = SkillExecution::with(['project:id,name'])
            ->when($projectId !== null, fn ($query) => $query->where('project_id', $projectId))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'type' => 'execution',
                'skill_name' => $e->skill_name,
                'status' => $e->status,
                'project_name' => $e->project?->name,
                'project_id' => $e->project_id,
                'duration_seconds' => $e->duration_seconds,
                'created_at' => $e->created_at->toIso8601String(),
            ]);

        // 待处理文章
        $pendingArticles = Article::with(['project:id,name'])
            ->when($projectId !== null, fn ($query) => $query->where('project_id', $projectId))
            ->whereIn('status', ['editing', 'pending'])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'type' => $a->status === 'pending' ? 'review' : 'editing',
                'title' => $a->title,
                'status' => $a->status,
                'project_name' => $a->project?->name,
                'project_id' => $a->project_id,
                'updated_at' => $a->updated_at->toIso8601String(),
            ]);

        // 合并排序
        $activity = $recentExecutions->concat($pendingArticles)
            ->sortByDesc(fn ($item) => $item['updated_at'] ?? $item['created_at'])
            ->values()
            ->take($limit);

        return response()->json(['data' => $activity]);
    }

    /**
     * GET /api/v1/dashboard/usage
     * 用量统计（Settings 订阅管理页）
     */
    public function usage(Request $request): JsonResponse
    {
        return $this->monthlyUsage($request);
    }

    private function monthlyUsage(Request $request): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        $tenant = Tenant::query()->find($tenantId);
        $periodStart = now()->startOfMonth();
        $resetAt = now()->copy()->addMonthNoOverflow()->startOfMonth();

        $projectCount = Project::query()->where('tenant_id', $tenantId)->count();
        $articleCount = Article::query()->where('tenant_id', $tenantId)->count();
        $memberCount = User::query()->where('tenant_id', $tenantId)->count();
        $analysisCount = SkillExecution::query()
            ->where('tenant_id', $tenantId)
            ->where('skill_id', Project::GEO_ANALYSIS_SKILL_ID)
            ->where('created_at', '>=', $periodStart)
            ->count();
        $skillExecutionCount = SkillExecution::query()
            ->where('tenant_id', $tenantId)
            ->where('skill_id', '!=', Project::GEO_ANALYSIS_SKILL_ID)
            ->where('created_at', '>=', $periodStart)
            ->count();
        $skillExecutionTokens = (int) SkillExecution::query()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $periodStart)
            ->sum('token_usage');

        $modelCallQuery = ModelCallLog::query()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $periodStart);
        $monthlyModelLogs = (clone $modelCallQuery)->get();
        $modelCallCount = $monthlyModelLogs->count();
        $modelTokens = (int) $monthlyModelLogs->sum('total_tokens');
        $monthlyTokens = max($modelTokens, $skillExecutionTokens);
        $storageMb = $this->storageUsageMb($tenantId);

        // B7.2 分发用量统计
        $distributionTotal = ArticlePublication::query()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $periodStart)
            ->count();
        $distributionPublished = ArticlePublication::query()
            ->where('tenant_id', $tenantId)
            ->where('status', ArticlePublication::STATUS_PUBLISHED)
            ->where('created_at', '>=', $periodStart)
            ->count();
        $distributionFailed = ArticlePublication::query()
            ->where('tenant_id', $tenantId)
            ->where('status', ArticlePublication::STATUS_FAILED)
            ->where('created_at', '>=', $periodStart)
            ->count();
        $distributionQueued = ArticlePublication::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [ArticlePublication::STATUS_QUEUED, ArticlePublication::STATUS_PUBLISHING])
            ->where('created_at', '>=', $periodStart)
            ->count();
        $activeChannelCount = PublishingChannel::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->count();

        $recentModelCalls = (clone $modelCallQuery)
            ->with(['project:id,name', 'skillExecution:id,skill_id,skill_name,status'])
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn (ModelCallLog $log) => [
                'id' => $log->id,
                'project_id' => $log->project_id,
                'project_name' => $log->project?->name ?? data_get($log->metadata, 'project_name_snapshot'),
                'skill_execution_id' => $log->skill_execution_id,
                'skill_id' => $log->skillExecution?->skill_id,
                // 同 billing/usage：不回 provider / 内部档位 slug，只回对客品牌名
                'model_tier' => ModelTierLabel::for($log->model),
                'status' => $log->status,
                'total_tokens' => $log->total_tokens,
                'created_at' => $log->created_at?->toISOString(),
            ])
            ->values();

        $recentFailedExecutions = SkillExecution::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'failed')
            ->with('project:id,name')
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn (SkillExecution $execution) => [
                'id' => $execution->id,
                'project_id' => $execution->project_id,
                'project_name' => $execution->project?->name,
                'skill_id' => $execution->skill_id,
                'skill_name' => $execution->skill_name,
                'error_message' => OperationalTextRedactor::redact($execution->error_message),
                'created_at' => $execution->created_at?->toISOString(),
            ])
            ->values();

        return response()->json([
            'period' => [
                // 计费周期是整个自然月：月初到月末。此前 to 取"今天"，导致每月 1 号
                // 起止同日（2026-08-01 ~ 2026-08-01），看着像零长度周期。
                'from' => $periodStart->toDateString(),
                'to' => $periodStart->copy()->endOfMonth()->toDateString(),
                'as_of' => now()->toDateString(),
                'reset_at' => $resetAt->toISOString(),
            ],
            'quotas' => [
                'geo_analysis' => $this->usageMetric($analysisCount, null),
                'skill_executions' => $this->usageMetric($skillExecutionCount, null),
                'projects' => $this->usageMetric($projectCount, null),
                'articles' => $this->usageMetric($articleCount, null),
                'members' => $this->usageMetric($memberCount, null),
                'tokens' => $this->usageMetric($monthlyTokens, null),
                'storage_mb' => $this->usageMetric($storageMb, null),
                'distribution_channels' => $this->usageMetric($activeChannelCount, null),
            ],
            'model_usage' => [
                'calls' => $modelCallCount,
                'completed_calls' => $monthlyModelLogs->where('status', 'completed')->count(),
                'failed_calls' => $monthlyModelLogs->where('status', 'failed')->count(),
                'total_tokens' => $modelTokens,
            ],
            'distribution' => [
                'total_publications' => $distributionTotal,
                'published' => $distributionPublished,
                'failed' => $distributionFailed,
                'queued' => $distributionQueued,
                'active_channels' => $activeChannelCount,
            ],
            'operations' => [
                'recent_model_calls' => $recentModelCalls,
                'recent_failed_executions' => $recentFailedExecutions,
            ],
            'usage' => [
                ['label' => 'Projects', 'current' => $projectCount, 'limit' => $this->effectiveLimit(null)],
                ['label' => 'Articles', 'current' => $articleCount, 'limit' => null],
                ['label' => 'Members', 'current' => $memberCount, 'limit' => $this->effectiveLimit(null)],
                ['label' => 'Monthly GEO analysis', 'current' => $analysisCount, 'limit' => $this->effectiveLimit(null)],
                ['label' => 'Monthly Skill executions', 'current' => $skillExecutionCount, 'limit' => $this->effectiveLimit(null)],
                ['label' => 'Monthly model calls', 'current' => $modelCallCount, 'limit' => $this->effectiveLimit(null)],
                ['label' => 'Monthly tokens', 'current' => $monthlyTokens, 'limit' => $this->effectiveLimit(null)],
                ['label' => 'Storage (MB)', 'current' => $storageMb, 'limit' => $this->effectiveLimit(null)],
                ['label' => 'Distribution channels', 'current' => $activeChannelCount, 'limit' => $this->effectiveLimit(null)],
                ['label' => 'Distribution publications', 'current' => $distributionTotal, 'limit' => null],
            ],
        ]);
    }

    /**
     * GET /api/v1/analytics/model-usage
     * 按模型档位维度的用量与成本分析（P1-3 数据分析落地）
     */
    public function modelUsage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['nullable', 'integer'],
            'days' => ['nullable', 'integer', 'min:1', 'max:366'],
        ]);

        $projectId = isset($validated['project_id']) ? (int) $validated['project_id'] : null;
        $days = (int) ($validated['days'] ?? 30);
        $since = now()->subDays($days);

        $query = ModelCallLog::query()->where('created_at', '>=', $since);
        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        $logs = $query->get();

        // 按档位分组聚合。分组键用对客品牌名而不是内部 slug——原来回的
        $byModel = $logs->groupBy(fn ($log): string => ModelTierLabel::for($log->model))
            ->map(function ($group, string $tier) {
                $completed = $group->where('status', 'completed');
                $totalPromptTokens = (int) $completed->sum('prompt_tokens');
                $totalCompletionTokens = (int) $completed->sum('completion_tokens');
                $totalTokens = (int) $completed->sum('total_tokens');

                return [
                    'model' => $tier,
                    'total_calls' => $group->count(),
                    'completed_calls' => $completed->count(),
                    'failed_calls' => $group->where('status', 'failed')->count(),
                    'prompt_tokens' => $totalPromptTokens,
                    'completion_tokens' => $totalCompletionTokens,
                    'total_tokens' => $totalTokens,
                    'avg_duration_seconds' => round((float) $completed->avg('duration_seconds'), 3),
                ];
            })->sortByDesc('total_calls')->values();

        // 日趋势（按模型）
        $dailyByModel = ModelCallLog::query()
            ->where('created_at', '>=', $since)
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->select(
                DB::raw('DATE(created_at) as date'),
                'model',
                DB::raw('count(*) as calls'),
                DB::raw('sum(total_tokens) as tokens'),
            )
            ->groupBy('date', 'model')
            ->orderBy('date')
            ->get()
            // 同样只回对客品牌名，不回内部档位 slug
            ->map(fn ($row) => [
                'date' => $row->date,
                'model' => ModelTierLabel::for($row->model),
                'calls' => (int) $row->calls,
                'tokens' => (int) $row->tokens,
            ])
            ->values();

        return response()->json([
            'period' => ['from' => $since->toDateString(), 'to' => now()->toDateString(), 'days' => $days],
            'by_model' => $byModel,
            'daily_trend' => $dailyByModel,
            'summary' => [
                'total_calls' => $logs->count(),
                'total_tokens' => (int) $logs->sum('total_tokens'),
            ],
        ]);
    }

    /**
     * GET /api/v1/analytics/by-project
     * 多项目维度对比分析（P1-3 数据分析落地）
     */
    public function byProject(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:366'],
        ]);

        $days = (int) ($validated['days'] ?? 30);
        $since = now()->subDays($days);

        $projects = Project::query()
            ->whereNull('deleted_at')
            ->withCount(['skillExecutions as total_executions'])
            ->get();

        $projectStats = $projects->map(function (Project $project) use ($since) {
            $articles = Article::query()->where('project_id', $project->id)
                ->where('status', '!=', Article::STATUS_RECYCLED)
                ->with('skillExecution:id,skill_id')->get()
                ->filter(fn (Article $article) => ArticleAssetClassifier::isArticle($article));
            $articlesSince = $articles->filter(fn (Article $article) => $article->created_at >= $since);

            $execsSince = SkillExecution::query()
                ->where('project_id', $project->id)
                ->where('created_at', '>=', $since);

            $modelCallsSince = ModelCallLog::query()
                ->where('project_id', $project->id)
                ->where('created_at', '>=', $since);

            $publicationsSince = ArticlePublication::query()
                ->whereHas('article', fn ($q) => $q->where('project_id', $project->id))
                ->where('created_at', '>=', $since);

            return [
                'project_id' => $project->id,
                'project_name' => $project->name,
                'brand_name' => $project->brand_name,
                'current_stage' => $project->current_stage_name,
                'articles' => [
                    'total' => $articles->count(),
                    'period' => $articlesSince->count(),
                    'published' => $articles->where('status', 'published')->count(),
                ],
                'executions' => [
                    'total' => $project->total_executions,
                    'period' => (clone $execsSince)->count(),
                    'success_rate' => $execsSince->count() > 0
                        ? round(((clone $execsSince)->where('status', 'completed')->count() / max((clone $execsSince)->count(), 1)) * 100, 1)
                        : 0,
                    'tokens' => (int) (clone $execsSince)->sum('token_usage'),
                ],
                'model_calls' => [
                    'period' => (clone $modelCallsSince)->count(),
                    'tokens' => (int) (clone $modelCallsSince)->sum('total_tokens'),
                ],
                'publications' => [
                    'period' => (clone $publicationsSince)->count(),
                    'published' => (clone $publicationsSince)->where('status', ArticlePublication::STATUS_PUBLISHED)->count(),
                    'failed' => (clone $publicationsSince)->where('status', ArticlePublication::STATUS_FAILED)->count(),
                ],
            ];
        })->sortByDesc(fn ($p) => $p['executions']['period'])->values();

        return response()->json([
            'period' => ['from' => $since->toDateString(), 'to' => now()->toDateString(), 'days' => $days],
            'projects' => $projectStats,
        ]);
    }

    /**
     * GET /api/v1/analytics/visit-logs
     * 访问日志列表（阶段 3.1）：按流量类型 / 机器人 / 文章 / 项目 / 渠道 / 日期过滤，分页返回。
     */
    public function visitLogs(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'traffic_type' => ['nullable', 'string', 'in:human,search_bot,ai_bot,other_bot,unknown,all'],
            'bot_name' => ['nullable', 'string', 'max:80'],
            'article_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'channel_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'path' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = AnalyticsVisitLog::query();

        if (($validated['traffic_type'] ?? 'all') !== 'all') {
            $query->where('traffic_type', $validated['traffic_type']);
        }
        if (! empty($validated['bot_name'])) {
            $query->where('bot_name', $validated['bot_name']);
        }
        if (! empty($validated['article_id'])) {
            $query->where('article_id', $validated['article_id']);
        }
        if (! empty($validated['project_id'])) {
            $query->where('project_id', $validated['project_id']);
        }
        if (! empty($validated['channel_id'])) {
            $query->where('publishing_channel_id', $validated['channel_id']);
        }
        if (! empty($validated['path'])) {
            $query->where('path', 'like', '%'.$validated['path'].'%');
        }
        if (! empty($validated['from'])) {
            $query->where('created_at', '>=', $validated['from']);
        }
        if (! empty($validated['to'])) {
            $query->where('created_at', '<=', $validated['to'].' 23:59:59');
        }

        $perPage = (int) ($validated['per_page'] ?? 25);
        $logs = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/v1/analytics/crawler-breakdown
     * AI 爬虫命中报表（阶段 3.1）：KPI + 流量类型分布 + Top AI 爬虫。
     */
    public function crawlerBreakdown(Request $request): JsonResponse
    {
        $days = max(1, min((int) ($request->query('days', 30)), 366));
        $since = now()->subDays($days);

        $base = AnalyticsVisitLog::query()->where('created_at', '>=', $since);

        $aiBotPv = (int) (clone $base)->where('traffic_type', TrafficClassifier::AI_BOT)->count();
        $searchBotPv = (int) (clone $base)->where('traffic_type', TrafficClassifier::SEARCH_BOT)->count();
        $otherBotPv = (int) (clone $base)->where('traffic_type', TrafficClassifier::OTHER_BOT)->count();
        $humanPv = (int) (clone $base)->where('traffic_type', TrafficClassifier::HUMAN)->count();
        $unknownPv = (int) (clone $base)->where('traffic_type', TrafficClassifier::UNKNOWN)->count();
        $pv = $aiBotPv + $searchBotPv + $otherBotPv + $humanPv + $unknownPv;
        $uniqueIp = (int) (clone $base)->where('ip_address', '!=', '')->distinct()->count('ip_address');

        $topAiBots = AnalyticsCrawlerHit::query()
            ->where('bot_family', TrafficClassifier::AI_BOT)
            ->where('hit_at', '>=', $since->toDateString())
            ->whereNotNull('article_id')
            ->select('bot_name', DB::raw('count(*) as hits'), DB::raw('count(distinct article_id) as articles'))
            ->groupBy('bot_name')
            ->orderByDesc('hits')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'bot_name' => $row->bot_name,
                'hits' => (int) $row->hits,
                'articles_reached' => (int) $row->articles,
            ])
            ->values();

        return response()->json([
            'period' => ['from' => $since->toDateString(), 'to' => now()->toDateString(), 'days' => $days],
            'kpis' => [
                'pv' => $pv,
                'unique_ip' => $uniqueIp,
                'ai_bot_pv' => $aiBotPv,
                'search_bot_pv' => $searchBotPv,
                'other_bot_pv' => $otherBotPv,
                'human_pv' => $humanPv,
                'unknown_pv' => $unknownPv,
            ],
            'traffic_breakdown' => [
                ['key' => 'human', 'count' => $humanPv],
                ['key' => 'search_bot', 'count' => $searchBotPv],
                ['key' => 'ai_bot', 'count' => $aiBotPv],
                ['key' => 'other_bot', 'count' => $otherBotPv],
                ['key' => 'unknown', 'count' => $unknownPv],
            ],
            'top_ai_bots' => $topAiBots,
        ]);
    }

    /**
     * GET /api/v1/analytics/traffic-trend
     * 每日 PV 与 AI 爬虫 PV 趋势（阶段 3.1）。
     */
    public function trafficTrend(Request $request): JsonResponse
    {
        $days = max(1, min((int) ($request->query('days', 30)), 366));
        $since = now()->subDays($days);

        $trend = AnalyticsVisitLog::query()
            ->where('created_at', '>=', $since)
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('count(*) as pv'),
                DB::raw("sum(case when traffic_type = 'ai_bot' then 1 else 0 end) as ai_bot_pv"),
                DB::raw("sum(case when traffic_type = 'search_bot' then 1 else 0 end) as search_bot_pv"),
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return response()->json([
            'period' => ['from' => $since->toDateString(), 'to' => now()->toDateString(), 'days' => $days],
            'trend' => $trend,
        ]);
    }

    /**
     * GET /api/v1/analytics/top-articles
     * 按访问量排序的 Top 文章（阶段 3.1，对标 GEOFlow topArticles）。
     */
    public function topArticlesByVisits(Request $request): JsonResponse
    {
        $days = max(1, min((int) ($request->query('days', 30)), 366));
        $since = now()->subDays($days);
        $limit = min((int) ($request->query('limit', 10)), 20);

        $rows = AnalyticsVisitLog::query()
            ->whereNotNull('article_id')
            ->where('created_at', '>=', $since)
            ->select('article_id', DB::raw('count(*) as views'), DB::raw('count(distinct ip_address) as unique_ip'))
            ->groupBy('article_id')
            ->orderByDesc('views')
            ->limit($limit)
            ->get();

        $articleIds = $rows->pluck('article_id')->all();
        $articles = Article::query()->whereIn('id', $articleIds)->get()->keyBy('id');

        $data = $rows->map(fn ($row) => [
            'article_id' => (int) $row->article_id,
            'title' => $articles->get($row->article_id)?->title,
            'slug' => $articles->get($row->article_id)?->slug,
            'views' => (int) $row->views,
            'unique_ip' => (int) $row->unique_ip,
        ])->values();

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/v1/analytics/channel-beacon-health
     * 各分发渠道最后一次外站信标回传时间 / 是否在发信标（Phase 1 §1.1）。
     *
     * 数据面：analytics_visit_logs 中 publishing_channel_id IS NOT NULL 的行
     * （信标插件在外站回传时带该渠道 id）。按渠道聚合最近回传时间与近 7 日命中数。
     * 租户隔离由 BelongsToTenant 全局作用域自动处理（与同文件其余 analytics 端点一致）。
     */
    public function channelBeaconHealth(Request $request): JsonResponse
    {
        $days = max(1, min((int) $request->query('days', 30), 366));
        $since = now()->subDays($days);
        $thresholdDays = max(1, min((int) $request->query('beacon_threshold_days', 7), 90));
        $beaconSince = now()->subDays($thresholdDays);
        $hitsSince = now()->subDays(7); // 契约字段 hits_7d 固定近 7 日

        $channelIds = AnalyticsVisitLog::query()
            ->whereNotNull('publishing_channel_id')
            ->where('created_at', '>=', $since)
            ->distinct()
            ->pluck('publishing_channel_id');

        $data = $channelIds
            ->map(function (int $channelId) use ($since, $beaconSince, $hitsSince): array {
                $latest = AnalyticsVisitLog::query()
                    ->where('publishing_channel_id', $channelId)
                    ->where('created_at', '>=', $since)
                    ->with('channel:id,name')
                    ->latest('created_at')
                    ->first();

                $hits7d = AnalyticsVisitLog::query()
                    ->where('publishing_channel_id', $channelId)
                    ->where('created_at', '>=', $hitsSince)
                    ->count();

                $lastAt = $latest?->created_at; // Carbon|null
                $beaconing = $lastAt !== null && $lastAt >= $beaconSince;

                return [
                    'channel_id' => $channelId,
                    'channel_name' => $latest?->channel?->name,
                    'last_beacon_at' => $lastAt?->toISOString(),
                    'beaconing' => $beaconing,
                    'hits_7d' => (int) $hits7d,
                ];
            })
            ->sortByDesc(fn (array $row): string => $row['last_beacon_at'] ?? '')
            ->values();

        return response()->json([
            'period' => ['from' => $since->toDateString(), 'to' => now()->toDateString(), 'days' => $days],
            'beacon_threshold_days' => $thresholdDays,
            'data' => $data,
        ]);
    }

    /**
     * 用量指标。limit 语义只有两种：正整数 = 真实上限；null = 不限。
     * 套餐版权限下线后 GeoPlanService 各 *Limit() 一律返回 0（= 无上限），
     * 这里归一成 null，避免前端把 0 当分母渲染成 "x / 0"。
     */
    private function effectiveLimit(?int $limit): ?int
    {
        return $limit !== null && $limit > 0 ? $limit : null;
    }

    private function usageMetric(int|float $current, ?int $limit): array
    {
        $effectiveLimit = $limit !== null && $limit > 0 ? $limit : null;

        return [
            'current' => $current,
            'limit' => $effectiveLimit,
            'unlimited' => $effectiveLimit === null,
            'remaining' => $effectiveLimit !== null ? max($effectiveLimit - $current, 0) : null,
            'percent' => $effectiveLimit !== null ? min(round(($current / $effectiveLimit) * 100, 1), 100) : null,
        ];
    }

    private function publicationQuery(?int $projectId, ?int $channelId, \DateTimeInterface $since)
    {
        return ArticlePublication::query()
            ->when($channelId, fn ($query) => $query->where('publishing_channel_id', $channelId))
            ->when($projectId, fn ($query) => $query->whereHas(
                'article',
                fn ($articleQuery) => $articleQuery->where('project_id', $projectId),
            ))
            ->where('created_at', '>=', $since);
    }

    private function visibilityEvidence(?int $projectId, \DateTimeInterface $since): array
    {
        $runs = AiVisibilityRun::query()->with('project:id,tenant_id,name,brand_name')
            ->when($projectId, fn ($query) => $query->where('project_id', $projectId))
            ->where('created_at', '>=', $since)->orderBy('id')->get();
        $completed = $runs->where('status', AiVisibilityRun::STATUS_COMPLETED);
        $usable = $completed->filter(fn (AiVisibilityRun $run) => trim((string) $run->answer_text) !== '');
        $failed = $runs->whereIn('status', [AiVisibilityRun::STATUS_FAILED, AiVisibilityRun::STATUS_RECONCILIATION_REQUIRED])->count();
        $pending = $runs->whereIn('status', [AiVisibilityRun::STATUS_PENDING, AiVisibilityRun::STATUS_RUNNING])->count();

        $state = match (true) {
            $runs->isEmpty() => 'not_monitored',
            $usable->isNotEmpty() => 'sampled',
            $pending > 0 => 'running',
            $failed > 0 => 'failed',
            default => 'unavailable',
        };

        $mentions = $usable->filter(function (AiVisibilityRun $run): bool {
            $brand = trim((string) $run->project?->brand_name);
            // The brand field can list the Chinese and English names together,
            // e.g. "清禾可研 / ScholarClue". Either name is a valid mention.
            $aliases = $brand !== ''
                ? preg_split('~\s+/\s+|[／｜|、]~u', $brand)
                : [(string) $run->project?->name];
            foreach ($aliases ?: [] as $alias) {
                $alias = trim($alias);
                if ($alias !== '' && mb_stripos((string) $run->answer_text, $alias) !== false) {
                    return true;
                }
            }

            return false;
        })->count();
        $citations = $usable->filter(fn (AiVisibilityRun $run): bool => preg_match('/\[\d+\]/u', (string) $run->answer_text) === 1)->count();

        return [
            'state' => $state,
            'questions_total' => $runs->count(),
            'sampled_answers' => $usable->count(),
            'failed_questions' => $failed,
            'pending_questions' => $pending,
            'batch_count' => $runs->pluck('batch_id')->filter()->unique()->count(),
            'platforms' => $usable->pluck('provider')->filter()->unique()->values(),
            'mentions' => $mentions,
            'citations' => $citations,
            'mention_basis' => '已完成回答正文包含任一项目品牌名的次数',
            'citation_basis' => '已完成回答正文出现 [数字] 引用标记的次数；不代表引用了本品牌',
            'sampled_from' => $usable->first()?->completed_at?->toISOString(),
            'sampled_to' => $usable->last()?->completed_at?->toISOString(),
            'evidence_run_ids' => $usable->sortByDesc('id')->take(5)->pluck('id')->values(),
            'monitoring_url' => config('geo.visibility_panel_enabled', false) ? '/visibility' : null,
        ];
    }

    private function storageUsageMb(int $tenantId): float
    {
        try {
            return round(
                \App\Models\KnowledgeDocument::query()->where('tenant_id', $tenantId)->sum('file_size') / 1024 / 1024,
                2,
            );
        } catch (\Throwable) {
            return 0.0;
        }
    }
}
