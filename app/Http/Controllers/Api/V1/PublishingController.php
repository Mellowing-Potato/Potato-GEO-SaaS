<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ArticlePublication;
use App\Models\DistributionChannelSecret;
use App\Models\DistributionLog;
use App\Models\PublishingChannel;
use App\Models\Project;
use App\Rules\SafeChannelEndpoint;
use App\Services\Distribution\DistributionOrchestrator;
use App\Services\Distribution\RewriteRuleGenerator;
use App\Services\Distribution\TargetSitePackageBuilder;
use App\Services\Distribution\TaskDistributionChannelSelector;
use App\Services\Distribution\WordPressBeaconPluginBuilder;
use App\Services\Distribution\WordPressTaxonomySyncService;
use App\Services\Publishing\ArticlePublicationService;
use App\Services\Publishing\ProjectPublicationAccounts;
use App\Services\Publishing\SitemapGeneratorService;
use App\Support\OperationalTextRedactor;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use App\Support\Concerns\RecordsSensitiveAction;
class PublishingController extends Controller
{
    use RecordsSensitiveAction;

    public function __construct(
        private readonly ArticlePublicationService $publications,
        private readonly SitemapGeneratorService $sitemap,
        private readonly DistributionOrchestrator $distribution,
        private readonly TaskDistributionChannelSelector $channelSelector,
        private readonly TargetSitePackageBuilder $packageBuilder,
        private readonly WordPressBeaconPluginBuilder $beaconPluginBuilder,
        private readonly RewriteRuleGenerator $rewriteRules,
        private readonly WordPressTaxonomySyncService $taxonomy,
        private readonly ProjectPublicationAccounts $projectAccounts,
    ) {}

    public function channels(): JsonResponse
    {
        $channels = PublishingChannel::query()
            ->latest('id')
            ->get()
            ->map(fn (PublishingChannel $channel): array => $this->channelPayload($channel))
            ->values();

        return response()->json([
            'object' => 'publishing_channel_list',
            'data' => $channels,
        ]);
    }

    public function storeChannel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', 'in:wordpress_rest,http_webhook,geoflow_agent'],
            'endpoint_url' => ['required', 'url', 'max:2000', new SafeChannelEndpoint()],
            'auth_type' => ['nullable', 'string', 'in:none,bearer,basic,wordpress_application_password,hmac'],
            'username' => ['nullable', 'string', 'max:255'],
            'secret' => ['nullable', 'string', 'max:2000'],
            'headers' => ['nullable', 'array'],
            'settings' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
            // 分发引擎扩展字段
            'domain' => ['nullable', 'string', 'max:255'],
            'front_mode' => ['nullable', 'string', 'in:proxy,static,hybrid'],
            'template_key' => ['nullable', 'string', 'max:120'],
            'site_settings' => ['nullable', 'array'],
            'channel_config' => ['nullable', 'array'],
        ]);

        $channel = PublishingChannel::query()->create([
            'tenant_id' => TenantContext::id(),
            'name' => $validated['name'],
            'type' => $validated['type'],
            'endpoint_url' => $validated['endpoint_url'],
            'auth_type' => $validated['auth_type'] ?? 'none',
            'username' => $validated['username'] ?? null,
            'encrypted_secret' => $validated['secret'] ?? null,
            'headers' => $validated['headers'] ?? null,
            'settings' => $validated['settings'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'domain' => $validated['domain'] ?? null,
            'front_mode' => $validated['front_mode'] ?? null,
            'template_key' => $validated['template_key'] ?? null,
            'site_settings' => $validated['site_settings'] ?? null,
            'channel_config' => $validated['channel_config'] ?? null,
        ]);

        return response()->json([
            'object' => 'publishing_channel',
            'channel' => $this->channelPayload($channel),
        ], 201);
    }

    public function updateChannel(Request $request, PublishingChannel $channel): JsonResponse
    {
        $this->ensureCurrentTenantChannel($channel);
        if ($channel->type === PublishingChannel::TYPE_HOSTED_PAGE) {
            return response()->json(['message' => '站内托管渠道已退役，不可重新启用。'], 410);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'endpoint_url' => ['sometimes', 'url', 'max:2000', new SafeChannelEndpoint()],
            'auth_type' => ['sometimes', 'string', 'in:none,bearer,basic,wordpress_application_password,hmac'],
            'username' => ['sometimes', 'nullable', 'string', 'max:255'],
            'secret' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'headers' => ['sometimes', 'nullable', 'array'],
            'settings' => ['sometimes', 'nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
            'domain' => ['sometimes', 'nullable', 'string', 'max:255'],
            'front_mode' => ['sometimes', 'nullable', 'string', 'in:proxy,static,hybrid'],
            'template_key' => ['sometimes', 'nullable', 'string', 'max:120'],
            'site_settings' => ['sometimes', 'nullable', 'array'],
            'channel_config' => ['sometimes', 'nullable', 'array'],
        ]);

        if (array_key_exists('secret', $validated)) {
            $validated['encrypted_secret'] = $validated['secret'];
            unset($validated['secret']);
        }

        $channel->update($validated);

        return response()->json([
            'object' => 'publishing_channel',
            'channel' => $this->channelPayload($channel->fresh()),
        ]);
    }

    public function deleteChannel(PublishingChannel $channel): JsonResponse
    {
        $this->ensureCurrentTenantChannel($channel);
        $channel->delete();

        return response()->json(['message' => '发布渠道已删除。']);
    }

    public function publish(Request $request, Article $article): JsonResponse
    {
        $this->ensureCurrentTenantArticle($article);

        $validated = $request->validate([
            'channel_id' => ['required', 'integer'],
        ]);

        $channel = PublishingChannel::query()->whereKey((int) $validated['channel_id'])->firstOrFail();
        $this->ensureCurrentTenantChannel($channel);

        $publication = $this->publications->publish($article, $channel, $request->user());

        return response()->json([
            'object' => 'article_publication',
            'publication' => $this->publicationPayload($publication),
        ], $publication->status === ArticlePublication::STATUS_FAILED ? 502 : 201);
    }

    public function publishAsync(Request $request, Article $article): JsonResponse
    {
        $this->ensureCurrentTenantArticle($article);

        $validated = $request->validate([
            'channel_id' => ['required', 'integer'],
        ]);

        $channel = PublishingChannel::query()->whereKey((int) $validated['channel_id'])->firstOrFail();
        $this->ensureCurrentTenantChannel($channel);

        $publication = $this->publications->dispatchPublish($article, $channel, $request->user());

        return response()->json([
            'object' => 'article_publication',
            'message' => '发布任务已推入队列。',
            'publication' => $this->publicationPayload($publication),
        ], 202);
    }

    public function publishBatch(Request $request, Article $article): JsonResponse
    {
        $this->ensureCurrentTenantArticle($article);

        $validated = $request->validate([
            'channel_ids' => ['required', 'array', 'min:1', 'max:10'],
            'channel_ids.*' => ['integer'],
            'async' => ['nullable', 'boolean'],
        ]);

        $channelIds = $validated['channel_ids'];
        $async = $validated['async'] ?? true;

        $channels = PublishingChannel::query()
            ->whereIn('id', $channelIds)
            ->get()
            ->keyBy('id');

        $publications = [];
        $errors = [];

        foreach ($channelIds as $channelId) {
            $channel = $channels->get($channelId);
            if (! $channel) {
                $errors[] = ['channel_id' => $channelId, 'error' => '渠道不存在。'];
                continue;
            }

            try {
                $this->ensureCurrentTenantChannel($channel);
            } catch (\Throwable) {
                $errors[] = ['channel_id' => $channelId, 'error' => '渠道不属于当前租户。'];
                continue;
            }

            try {
                $publication = $async
                    ? $this->publications->dispatchPublish($article, $channel, $request->user())
                    : $this->publications->publish($article, $channel, $request->user());
                $publications[] = $this->publicationPayload($publication);
            } catch (\Throwable $e) {
                $errors[] = ['channel_id' => $channelId, 'error' => $e->getMessage()];
            }
        }

        return response()->json([
            'object' => 'article_publication_batch',
            'message' => $async ? '批量发布任务已推入队列。' : '批量发布已完成。',
            'publications' => $publications,
            'errors' => $errors,
        ], $errors ? ($publications ? 207 : 422) : ($async ? 202 : 201));
    }

    public function articlePublications(Article $article): JsonResponse
    {
        $this->ensureCurrentTenantArticle($article);

        $publications = ArticlePublication::query()
            ->where('article_id', $article->id)
            ->with('channel:id,tenant_id,name,type')
            ->latest('id')
            ->get()
            ->map(fn (ArticlePublication $publication): array => $this->publicationPayload($publication))
            ->values();

        return response()->json([
            'object' => 'article_publication_list',
            'data' => $publications,
        ]);
    }

    public function retry(Request $request, Article $article, ArticlePublication $publication): JsonResponse
    {
        $this->ensureCurrentTenantArticle($article);
        if ((int) $publication->article_id !== (int) $article->id || (int) $publication->tenant_id !== (int) TenantContext::id()) {
            abort(404);
        }

        $publication->loadMissing('channel');
        $publication = $this->publications->publish($article, $publication->channel, $request->user(), true);

        return response()->json([
            'object' => 'article_publication',
            'publication' => $this->publicationPayload($publication),
        ], $publication->status === ArticlePublication::STATUS_FAILED ? 502 : 200);
    }

    public function generateSitemap(Request $request): JsonResponse
    {
        return response()->json(['message' => '站内托管已退役，不再生成平台文章 Sitemap。'], 410);
    }

    public function robotsTxt(Request $request): JsonResponse
    {
        $projectId = $request->query('project_id');
        $content = $this->sitemap->robotsTxt($projectId ? (int) $projectId : null);

        return response()->json([
            'object' => 'robots_txt',
            'content' => $content,
            'source_url' => rtrim((string) config('app.url'), '/').'/robots.txt',
            'live_check' => $this->sitemap->robotsStatus(),
            'public_page_check' => $this->sitemap->publicPageStatus(),
        ]);
    }

    // ─── 分发引擎管理端点 ───

    public function channelHealth(PublishingChannel $channel): JsonResponse
    {
        $this->ensureCurrentTenantChannel($channel);
        if ($channel->type === PublishingChannel::TYPE_HOSTED_PAGE) {
            return response()->json([
                'object' => 'channel_health',
                'channel_id' => $channel->id,
                'healthy' => false,
                'details' => '站内托管渠道已退役。',
            ], 410);
        }

        try {
            $result = $this->distribution->checkHealth($channel);
            $healthy = $result['ok'] ?? false;

            // 只补充 consecutive_failures 计数（orchestrator 已更新其他健康字段）
            $channel->update([
                'consecutive_failures' => $healthy ? 0 : ($channel->consecutive_failures + 1),
            ]);

            return response()->json([
                'object' => 'channel_health',
                'channel_id' => $channel->id,
                'healthy' => $healthy,
                'latency_ms' => $result['latency_ms'] ?? null,
                'details' => OperationalTextRedactor::redact($result['details'] ?? $result['message'] ?? null),
                'checked_at' => now()->toISOString(),
            ]);
        } catch (\Throwable $e) {
            $channel->update([
                'last_health_checked_at' => now(),
                'last_health_status' => 'error',
                'consecutive_failures' => $channel->consecutive_failures + 1,
            ]);

            return response()->json([
                'object' => 'channel_health',
                'channel_id' => $channel->id,
                'healthy' => false,
                'error' => OperationalTextRedactor::redact($e->getMessage()),
                'checked_at' => now()->toISOString(),
            ], 502);
        }
    }

    public function distributionLogs(Request $request, PublishingChannel $channel): JsonResponse
    {
        $this->ensureCurrentTenantChannel($channel);

        $perPage = min((int) ($request->query('per_page', 50)), 200);

        $logs = DistributionLog::query()
            ->where('publishing_channel_id', $channel->id)
            ->latest('id')
            ->paginate($perPage);

        return response()->json([
            'object' => 'distribution_log_list',
            'channel_id' => $channel->id,
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    public function generateSecret(PublishingChannel $channel): JsonResponse
    {
        $this->ensureCurrentTenantChannel($channel);

        // W1：开放 WP 渠道密钥管理（仅用于信标）；http_webhook 仍 422。
        if (! in_array($channel->type, [
            PublishingChannel::TYPE_GEOFLOW_AGENT,
            PublishingChannel::TYPE_WORDPRESS_REST,
        ], true)) {
            return response()->json([
                'error' => '仅 geoflow_agent / wordpress_rest 类型渠道支持密钥管理。',
            ], 422);
        }

        // 撤销旧密钥（同一渠道只保留一把 active）
        $channel->activeSecret?->revoke();

        $secret = DistributionChannelSecret::generateFor($channel);

        // 敏感操作审计（计划 §9.7）：渠道密钥=凭据，生成必须留痕。
        // 明细只记 key_id 与渠道名 —— **绝不记明文 secret**。
        $this->auditSensitive('channel_secret.created', $channel, [
            'channel' => (string) $channel->name,
            'key_id' => (string) $secret->key_id,
        ]);

        DistributionLog::write(
            'info',
            '渠道密钥已生成。',
            channel: $channel,
            event: 'secret.generated',
            context: ['key_id' => $secret->key_id],
        );

        return response()->json([
            'object' => 'distribution_channel_secret',
            'key_id' => $secret->key_id,
            'secret' => $secret->decryptSecret(), // 仅在创建时返回明文一次
            'scopes' => $secret->scopes,
            'created_at' => $secret->created_at->toISOString(),
        ], 201);
    }

    public function revokeSecret(PublishingChannel $channel, DistributionChannelSecret $secret): JsonResponse
    {
        $this->ensureCurrentTenantChannel($channel);

        if ((int) $secret->publishing_channel_id !== (int) $channel->id) {
            abort(404);
        }

        $secret->revoke();

        $this->auditSensitive('channel_secret.revoked', $channel, [
            'channel' => (string) $channel->name,
            'key_id' => (string) $secret->key_id,
        ]);

        DistributionLog::write(
            'info',
            '渠道密钥已撤销。',
            channel: $channel,
            event: 'secret.revoked',
            context: ['key_id' => $secret->key_id],
        );

        return response()->json([
            'object' => 'distribution_channel_secret',
            'key_id' => $secret->key_id,
            'status' => 'revoked',
        ]);
    }

    // ─── B6.4 项目绑定分发渠道 ───

    public function projectChannels(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'integer'],
        ]);

        $projectId = (int) $validated['project_id'];
        Project::query()->whereKey($projectId)->firstOrFail();
        $boundIds = $this->channelSelector->boundChannelIds($projectId);
        $hasBindings = $boundIds->isNotEmpty();

        $channels = PublishingChannel::query()->whereIn('id', $boundIds)->get();
        $manualEnabled = (bool) config('geo.manual_publish_enabled', false);
        $manualIds = $manualEnabled ? $this->projectAccounts->boundIds($projectId) : collect();

        return response()->json([
            'object' => 'project_channel_bindings',
            'project_id' => $projectId,
            'has_explicit_bindings' => $hasBindings || $manualIds->isNotEmpty(),
            'mode' => $hasBindings || $manualIds->isNotEmpty() ? 'bound' : 'none',
            'bound_channel_ids' => $boundIds->values(),
            'channels' => $channels->map(fn (PublishingChannel $ch) => $this->channelPayload($ch))->values(),
            'manual_publish_enabled' => $manualEnabled,
            'bound_manual_account_ids' => $manualIds->values(),
            'manual_accounts' => $manualEnabled ? $this->projectAccounts->forProject($projectId) : [],
            'available_manual_accounts' => $manualEnabled
                ? $this->projectAccounts->accounts()->map(fn ($account) => $this->projectAccounts->payload($account))->values() : [],
        ]);
    }

    public function bindProjectChannels(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'integer'],
            'channel_ids' => ['present', 'array', 'max:100'],
            'channel_ids.*' => ['integer'],
            'manual_account_ids' => ['sometimes', 'array', 'max:100'],
            'manual_account_ids.*' => ['integer'],
        ]);

        Project::query()->whereKey((int) $validated['project_id'])->firstOrFail();
        $ids = array_values(array_unique(array_map('intval', $validated['channel_ids'])));
        if (PublishingChannel::query()->whereIn('id', $ids)
            ->where('type', '!=', PublishingChannel::TYPE_HOSTED_PAGE)
            ->count() !== count($ids)) {
            return response()->json(['message' => '所选渠道不存在、已退役或不属于当前租户。'], 422);
        }

        if (! config('geo.manual_publish_enabled', false) && ! empty($validated['manual_account_ids'])) {
            return response()->json(['message' => '当前未启用第三方平台人工发布。'], 422);
        }
        $count = DB::transaction(function () use ($validated, $ids): int {
            $projectId = (int) $validated['project_id'];
            Project::query()->lockForUpdate()->findOrFail($projectId);
            if (array_key_exists('manual_account_ids', $validated)) {
                $this->projectAccounts->bind($projectId, $validated['manual_account_ids']);
            }

            return $this->channelSelector->bindChannels($projectId, $ids);
        });

        return response()->json([
            'object' => 'project_channel_bindings',
            'project_id' => (int) $validated['project_id'],
            'bound_count' => $count,
            'channel_ids' => $ids,
            'manual_account_ids' => $this->projectAccounts->boundIds((int) $validated['project_id'])->values(),
        ]);
    }

    public function unbindProjectChannels(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'integer'],
        ]);
        Project::query()->whereKey((int) $validated['project_id'])->firstOrFail();

        $deleted = DB::transaction(function () use ($validated): int {
            $projectId = (int) $validated['project_id'];
            Project::query()->lockForUpdate()->findOrFail($projectId);
            $this->projectAccounts->bind($projectId, []);

            return $this->channelSelector->unbindAll($projectId);
        });

        return response()->json([
            'object' => 'project_channel_bindings',
            'project_id' => (int) $validated['project_id'],
            'mode' => 'none',
            'removed_count' => $deleted,
        ]);
    }

    // ─── B4.2 Agent 包生成 ───

    public function generateAgentPackage(PublishingChannel $channel): JsonResponse
    {
        $this->ensureCurrentTenantChannel($channel);

        if ($channel->type !== PublishingChannel::TYPE_GEOFLOW_AGENT) {
            return response()->json([
                'error' => '仅 geoflow_agent 类型渠道支持 Agent 包生成。',
            ], 422);
        }

        $secret = $channel->activeSecret;
        if (! $secret) {
            return response()->json([
                'error' => '渠道尚未生成密钥，请先调用密钥生成接口。',
            ], 422);
        }

        $package = $this->packageBuilder->build(
            $channel->name,
            $secret->key_id,
            $secret->decryptSecret(),
        );

        return response()->json([
            'object' => 'geoflow_agent_package',
            'channel_id' => $channel->id,
            'files' => $package['files'],
            'instructions' => $package['instructions'],
        ]);
    }

    // ─── W2 WordPress 信标插件生成 ───

    public function generateBeaconPlugin(PublishingChannel $channel): JsonResponse
    {
        $this->ensureCurrentTenantChannel($channel);

        if ($channel->type !== PublishingChannel::TYPE_WORDPRESS_REST) {
            return response()->json([
                'error' => '仅 wordpress_rest 类型渠道支持 WP 信标插件生成。',
            ], 422);
        }

        $secret = $channel->activeSecret;
        if (! $secret) {
            return response()->json([
                'error' => '渠道尚未生成密钥，请先调用密钥生成接口。',
            ], 422);
        }

        $plugin = $this->beaconPluginBuilder->build(
            $channel->name,
            $secret->key_id,
        );

        return response()->json([
            'object' => 'wordpress_beacon_plugin',
            'channel_id' => $channel->id,
            'files' => $plugin['files'],
            'instructions' => $plugin['instructions'],
        ]);
    }

    // ─── B4.3 伪静态规则生成 ───

    public function generateRewriteRules(Request $request, PublishingChannel $channel): JsonResponse
    {
        $this->ensureCurrentTenantChannel($channel);

        $serverType = $request->query('server_type', 'nginx');
        $frontMode = $channel->front_mode ?? 'proxy';
        $domain = $channel->domain ?? parse_url($channel->endpoint_url, PHP_URL_HOST) ?? 'localhost';

        $result = $this->rewriteRules->generate($domain, $serverType, $frontMode);

        return response()->json([
            'object' => 'rewrite_rules',
            'channel_id' => $channel->id,
            'domain' => $result['domain'],
            'server_type' => $result['server_type'],
            'front_mode' => $result['front_mode'],
            'rules' => $result['rules'],
            'instructions' => $result['instructions'],
        ]);
    }

    /**
     * 批量预同步分类 / 标签到目标 WordPress 站点（阶段 4 缺口补齐：
     * 复刻 GEOFlow WordPressTaxonomySyncService 的预同步能力）。
     */
    public function syncTaxonomy(Request $request, PublishingChannel $channel): JsonResponse
    {
        $this->ensureCurrentTenantChannel($channel);

        $validated = $request->validate([
            'categories' => 'nullable|array',
            'categories.*' => 'required|string|max:120',
            'tags' => 'nullable|array',
            'tags.*' => 'required|string|max:120',
        ]);

        $categories = $this->taxonomy->preSync($channel, 'categories', $validated['categories'] ?? []);
        $tags = $this->taxonomy->preSync($channel, 'tags', $validated['tags'] ?? []);

        return response()->json([
            'object' => 'taxonomy_sync',
            'channel_id' => $channel->id,
            'categories' => $categories,
            'tags' => $tags,
            'synced_categories' => count($categories),
            'synced_tags' => count($tags),
        ]);
    }

    private function channelPayload(PublishingChannel $channel): array
    {
        $activeSecret = $channel->activeSecret;

        return [
            'id' => $channel->id,
            'name' => $channel->name,
            'type' => $channel->type,
            'endpoint_url' => $channel->endpoint_url,
            'auth_type' => $channel->auth_type,
            'username' => $channel->username,
            'secret_present' => $channel->secret() !== null,
            'headers' => $channel->headers,
            'settings' => $channel->settings,
            'is_active' => $channel->is_active && $channel->type !== PublishingChannel::TYPE_HOSTED_PAGE,
            'publish_ready' => $this->publications->channelIssue($channel) === null,
            'publish_unavailable_reason' => $this->publications->channelIssue($channel),
            // 分发引擎扩展
            'domain' => $channel->domain,
            'front_mode' => $channel->front_mode,
            'template_key' => $channel->template_key,
            'site_settings' => $channel->site_settings,
            'channel_config' => $channel->channel_config,
            'last_health_checked_at' => $channel->last_health_checked_at?->toISOString(),
            'last_health_status' => $channel->last_health_status,
            'consecutive_failures' => $channel->consecutive_failures,
            'active_key_id' => $activeSecret?->key_id,
            'last_verified_at' => $channel->last_verified_at?->toISOString(),
            'created_at' => $channel->created_at?->toISOString(),
            'updated_at' => $channel->updated_at?->toISOString(),
        ];
    }

    private function publicationPayload(ArticlePublication $publication): array
    {
        $publication->loadMissing('channel:id,tenant_id,name,type');

        return [
            'id' => $publication->id,
            'article_id' => $publication->article_id,
            'channel_id' => $publication->publishing_channel_id,
            'channel' => $publication->channel ? [
                'id' => $publication->channel->id,
                'name' => $publication->channel->name,
                'type' => $publication->channel->type,
            ] : null,
            'status' => $publication->status,
            'target_state' => $publication->status === ArticlePublication::STATUS_PUBLISHED ? 'public' : ($publication->status === ArticlePublication::STATUS_ACCEPTED ? 'accepted' : $publication->status),
            'article_version' => data_get($publication->metadata, 'article_version'),
            'attempt_count' => $publication->attempt_count,
            'remote_id' => $publication->remote_id,
            'remote_url' => $publication->remote_url,
            'last_error' => OperationalTextRedactor::redact($publication->last_error),
            'last_attempted_at' => $publication->last_attempted_at?->toISOString(),
            'published_at' => $publication->published_at?->toISOString(),
            'metadata' => OperationalTextRedactor::redactOperationalFields($publication->metadata ?? []),
            'created_at' => $publication->created_at?->toISOString(),
            'updated_at' => $publication->updated_at?->toISOString(),
        ];
    }

    private function ensureCurrentTenantArticle(Article $article): void
    {
        if ((int) $article->tenant_id !== (int) TenantContext::id()) {
            abort(404);
        }
    }

    private function ensureCurrentTenantChannel(PublishingChannel $channel): void
    {
        if ((int) $channel->tenant_id !== (int) TenantContext::id()) {
            abort(404);
        }
    }
}
