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
use App\Support\TenantScope;
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

        // Platform permissions never grant access to another account's workspace.
        $tenantId = (int) $request->user()->tenant_id;
        abort_unless($tenantId > 0, 403, '当前账号没有有效工作区。');
        $tenant = Tenant::query()->whereKey($tenantId)->where('status', 'active')->firstOrFail();
        $tenants = collect([$tenant]);
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
                'projects_total' => Project::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->count(),
                'articles_total' => Article::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->count(),
                'publishing_channels_total' => PublishingChannel::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->count(),
                'publishing_channels_active' => PublishingChannel::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->where('is_active', true)->count(),
                'publications_total' => ArticlePublication::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->where('created_at', '>=', $since)->count(),
                'publications_published' => ArticlePublication::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->where('created_at', '>=', $since)->where('status', ArticlePublication::STATUS_PUBLISHED)->count(),
                'publications_failed' => ArticlePublication::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->where('created_at', '>=', $since)->where('status', ArticlePublication::STATUS_FAILED)->count(),
                'model_calls_total' => ModelCallLog::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->where('created_at', '>=', $since)->count(),
                'model_calls_failed' => ModelCallLog::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->where('created_at', '>=', $since)->where('status', 'failed')->count(),
            ],
            'tenants' => $tenantSummaries
                ->sortByDesc(fn (array $tenant): int => count($tenant['summary']['risk_flags']))
                ->values()
                ->take($recent)
                ->all(),
            'recent_publications' => $this->recentPublications($tenantId, $since, $recent),
        ]);
    }

    private function tenantSummary(Tenant $tenant, \DateTimeInterface $since): array
    {
        $publications = ArticlePublication::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->where('created_at', '>=', $since)
            ->get();
        $modelLogs = ModelCallLog::withoutGlobalScope(TenantScope::class)
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
                'projects' => Project::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->count(),
                'articles' => Article::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->count(),
                'channels' => PublishingChannel::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->count(),
                'publications' => $publications->count(),
                'published_publications' => $publications->where('status', ArticlePublication::STATUS_PUBLISHED)->count(),
                'failed_publications' => $publications->where('status', ArticlePublication::STATUS_FAILED)->count(),
                'model_calls' => $modelLogs->count(),
            ],
        ];
    }

    private function recentPublications(int $tenantId, \DateTimeInterface $since, int $recent): array
    {
        return ArticlePublication::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->with([
                'article' => fn ($query) => $query->withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->select('id', 'tenant_id', 'project_id', 'title', 'slug', 'status'),
                'article.project' => fn ($query) => $query->withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->select('id', 'tenant_id', 'name'),
                'channel' => fn ($query) => $query->withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->select('id', 'tenant_id', 'name', 'type'),
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
