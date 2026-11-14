<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ArticlePublication;
use App\Models\ModelCallLog;
use App\Models\Project;
use App\Models\PublishingChannel;
use App\Models\Tenant;
use App\Support\OperationalTextRedactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformOperationsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:366'],
            'recent' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $days = (int) ($validated['days'] ?? 30);
        $recent = (int) ($validated['recent'] ?? 10);
        $since = now()->subDays($days);

        $tenants = Tenant::query()->orderBy('id')->get();
        $tenantSummaries = $tenants
            ->map(fn (Tenant $tenant): array => $this->tenantSummary($tenant, $since))
            ->values();

        return response()->json([
            'object' => 'geo_platform_operations_dashboard',
            'generated_at' => now()->toISOString(),
            'window' => [
                'days' => $days,
                'since' => $since->toISOString(),
                'recent' => $recent,
            ],
            'metrics' => [
                'tenants_total' => $tenants->count(),
                'tenants_active' => $tenants->where('status', 'active')->count(),
                'tenants_with_risks' => $tenantSummaries->filter(fn (array $tenant): bool => $tenant['summary']['risk_flags'] !== [])->count(),
                'projects_total' => Project::withoutGlobalScopes()->count(),
                'articles_total' => Article::withoutGlobalScopes()->count(),
                'publishing_channels_total' => PublishingChannel::withoutGlobalScopes()->count(),
                'publishing_channels_active' => PublishingChannel::withoutGlobalScopes()->where('is_active', true)->count(),
                'publications_total' => ArticlePublication::withoutGlobalScopes()->where('created_at', '>=', $since)->count(),
                'publications_published' => ArticlePublication::withoutGlobalScopes()->where('created_at', '>=', $since)->where('status', ArticlePublication::STATUS_PUBLISHED)->count(),
                'publications_failed' => ArticlePublication::withoutGlobalScopes()->where('created_at', '>=', $since)->where('status', ArticlePublication::STATUS_FAILED)->count(),
                'model_calls_total' => ModelCallLog::withoutGlobalScopes()->where('created_at', '>=', $since)->count(),
                'model_calls_failed' => ModelCallLog::withoutGlobalScopes()->where('created_at', '>=', $since)->where('status', 'failed')->count(),
            ],
            'tenants' => $tenantSummaries
                ->sortByDesc(fn (array $tenant): int => count($tenant['summary']['risk_flags']))
                ->values()
                ->take($recent)
                ->all(),
            'recent_publications' => $this->recentPublications($since, $recent),
        ]);
    }

    private function tenantSummary(Tenant $tenant, \DateTimeInterface $since): array
    {
        $publications = ArticlePublication::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('created_at', '>=', $since)
            ->get();
        $modelLogs = ModelCallLog::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('created_at', '>=', $since)
            ->get();

        $riskFlags = collect([
            'failed_publications' => $publications->where('status', ArticlePublication::STATUS_FAILED)->isNotEmpty(),
        ])->filter()->keys()->values()->all();

        return [
            'tenant' => [
                'id' => $tenant->id,
                'subdomain' => $tenant->subdomain,
                'status' => $tenant->status,
            ],
            'summary' => [
                'risk_flags' => $riskFlags,
            ],
            'counts' => [
                'projects' => Project::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(),
                'articles' => Article::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(),
                'channels' => PublishingChannel::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(),
                'publications' => $publications->count(),
                'published_publications' => $publications->where('status', ArticlePublication::STATUS_PUBLISHED)->count(),
                'failed_publications' => $publications->where('status', ArticlePublication::STATUS_FAILED)->count(),
                'model_calls' => $modelLogs->count(),
            ],
        ];
    }

    private function recentPublications(\DateTimeInterface $since, int $recent): array
    {
        return ArticlePublication::withoutGlobalScopes()
            ->with([
                'article' => fn ($query) => $query->withoutGlobalScopes()->select('id', 'tenant_id', 'project_id', 'title', 'slug', 'status'),
                'article.project' => fn ($query) => $query->withoutGlobalScopes()->select('id', 'tenant_id', 'name'),
                'channel' => fn ($query) => $query->withoutGlobalScopes()->select('id', 'tenant_id', 'name', 'type'),
                'tenant:id,subdomain',
            ])
            ->where('created_at', '>=', $since)
            ->latest('id')
            ->limit($recent)
            ->get()
            ->map(fn (ArticlePublication $publication): array => [
                'id' => $publication->id,
                'tenant_subdomain' => $publication->tenant?->subdomain,
                'status' => $publication->status,
                'attempt_count' => $publication->attempt_count,
                'article_id' => $publication->article_id,
                'article_title' => $publication->article?->title,
                'article_slug' => $publication->article?->slug,
                'project_id' => $publication->article?->project_id,
                'project_name' => $publication->article?->project?->name,
                'channel_id' => $publication->publishing_channel_id,
                'channel_name' => $publication->channel?->name,
                'channel_type' => $publication->channel?->type,
                'remote_url' => $publication->remote_url,
                'last_error' => OperationalTextRedactor::redact($publication->last_error),
                'published_at' => $publication->published_at?->toISOString(),
                'created_at' => $publication->created_at?->toISOString(),
            ])
            ->values()
            ->all();
    }

}
