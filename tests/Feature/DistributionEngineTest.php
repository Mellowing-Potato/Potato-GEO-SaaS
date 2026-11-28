<?php

namespace Tests\Feature;

use App\Services\Distribution\TaskDistributionChannelSelector;

use App\Jobs\ProcessArticleDistributionJob;
use App\Models\Article;
use App\Models\ArticlePublication;
use App\Models\DistributionChannelSecret;
use App\Models\DistributionLog;
use App\Models\Project;
use App\Models\PublishingChannel;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Distribution\DistributionOrchestrator;
use App\Services\Distribution\DistributionRetryPolicy;
use App\Services\Distribution\DistributionSigningService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DistributionEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.tenant_domain' => 'geo.example.test']);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    // ─── DistributionRetryPolicy (unit) ───

    public function test_retry_policy_allows_retry_within_max_attempts(): void
    {
        $policy = new DistributionRetryPolicy;

        $this->assertTrue($policy->isRetryable(1, 500));
        $this->assertTrue($policy->isRetryable(2, 502));
        $this->assertFalse($policy->isRetryable(DistributionRetryPolicy::MAX_ATTEMPTS + 1, 500));
    }

    public function test_retry_policy_never_retries_auth_or_validation_errors(): void
    {
        $policy = new DistributionRetryPolicy;

        $this->assertFalse($policy->isRetryable(1, 401));
        $this->assertFalse($policy->isRetryable(1, 403));
        $this->assertFalse($policy->isRetryable(1, 422));
        $this->assertFalse($policy->isRetryable(1, null, 'signature_error'));
    }

    public function test_retry_policy_backoff_increases_exponentially(): void
    {
        $policy = new DistributionRetryPolicy;

        $first = $policy->backoffSeconds(1);   // 60 * 2^0 = 60
        $second = $policy->backoffSeconds(2);   // 60 * 2^1 = 120
        $third = $policy->backoffSeconds(3);    // 60 * 2^2 = 240

        $this->assertSame(60, $first);
        $this->assertSame(120, $second);
        $this->assertSame(240, $third);
        // cap at 3600
        $this->assertLessThanOrEqual(3600, $policy->backoffSeconds(20));
    }

    // ─── DistributionSigningService (unit) ───

    public function test_signing_service_produces_valid_signature_and_verifies_round_trip(): void
    {
        $signer = new DistributionSigningService;
        $secret = 'test_secret_key_for_signing';
        $keyId = 'gfk_test123';
        $body = '{"article_id":1}';

        $headers = $signer->signRequest('POST', 'https://agent.example.test/api/articles', $body, $keyId, $secret);

        $this->assertArrayHasKey('X-GEOFlow-Signature', $headers);
        $this->assertArrayHasKey('X-GEOFlow-Key-Id', $headers);
        $this->assertArrayHasKey('X-GEOFlow-Timestamp', $headers);
        $this->assertArrayHasKey('X-GEOFlow-Nonce', $headers);
        $this->assertSame($keyId, $headers['X-GEOFlow-Key-Id']);

        // 验证签名通过
        $verified = $signer->verifySignature(
            'POST',
            '/api/articles',
            $body,
            $headers['X-GEOFlow-Timestamp'],
            $headers['X-GEOFlow-Nonce'],
            $headers['X-GEOFlow-Body-SHA256'],
            $headers['X-GEOFlow-Signature'],
            $secret,
        );

        $this->assertTrue($verified);
    }

    public function test_signing_service_rejects_tampered_body(): void
    {
        $signer = new DistributionSigningService;
        $secret = 'test_secret_key_for_signing';

        $headers = $signer->signRequest('POST', 'https://agent.example.test/api/articles', '{"ok":true}', 'gfk_k', $secret);

        $verified = $signer->verifySignature(
            'POST',
            '/api/articles',
            '{"ok":false}', // 篡改的 body
            $headers['X-GEOFlow-Timestamp'],
            $headers['X-GEOFlow-Nonce'],
            $headers['X-GEOFlow-Body-SHA256'],
            $headers['X-GEOFlow-Signature'],
            $secret,
        );

        $this->assertFalse($verified);
    }

    // ─── DistributionOrchestrator::enqueueForArticle (integration) ───

    public function test_enqueue_for_article_creates_queued_publications_for_active_channels(): void
    {
        [$tenant, $admin, $project] = $this->setupTenant();

        $activeChannel = $this->channel($tenant, ['name' => 'Active WP', 'is_active' => true]);
        $this->channel($tenant, ['name' => 'Inactive', 'is_active' => false]);

        $article = $this->article($tenant, $project, $admin, ['status' => 'approved']);

        TenantContext::set($tenant);
        app(TaskDistributionChannelSelector::class)->bindChannels($project->id, [$activeChannel->id]);
        $orchestrator = app(DistributionOrchestrator::class);
        $result = $orchestrator->enqueueForArticle($article);

        // 返回结构变了（执行书 T6）：从「入队的 publication 数组」变成
        // {blocked, reason, publications}。改的理由是 Article::triggerAutoDistribution
        // 用 try/catch 静默包裹这次调用，抛异常表达「被合规拦下」会和
        // 「网络故障」混成同一条日志，运营分不清是拦的还是挂的。
        $this->assertFalse($result['blocked']);
        $this->assertNull($result['reason']);

        // 只有 active channel 被入队
        $enqueued = $result['publications'];
        $this->assertCount(1, $enqueued);
        $this->assertSame($activeChannel->id, $enqueued[0]->publishing_channel_id);
        $this->assertSame(ArticlePublication::STATUS_QUEUED, $enqueued[0]->status);

        TenantContext::forget();
    }

    public function test_enqueue_skips_channels_already_published(): void
    {
        [$tenant, $admin, $project] = $this->setupTenant();

        $channel1 = $this->channel($tenant, ['name' => 'Already Published']);
        $channel2 = $this->channel($tenant, ['name' => 'New Channel']);

        $article = $this->article($tenant, $project, $admin, ['status' => 'draft']);

        // channel1 已有 published 记录
        TenantContext::set($tenant);
        app(TaskDistributionChannelSelector::class)->bindChannels($project->id, [$channel1->id, $channel2->id]);
        $this->publication($tenant, $article, $channel1, [
            'status' => ArticlePublication::STATUS_PUBLISHED,
        ]);

        // 标记为 published（绕过 created 钩子），Queue::fake 阻止自动分发 Job
        Queue::fake();
        $article->update(['status' => 'published']);
        // 清理自动分发产生的 publication（如有），只保留手动建的 channel1 published
        ArticlePublication::query()->where('article_id', $article->id)
            ->where('publishing_channel_id', $channel2->id)->delete();

        $orchestrator = app(DistributionOrchestrator::class);
        $result = $orchestrator->enqueueForArticle($article);

        // 只入队 channel2
        $this->assertFalse($result['blocked']);
        $this->assertNull($result['reason']);
        $enqueued = $result['publications'];
        $this->assertCount(1, $enqueued);
        $this->assertSame($channel2->id, $enqueued[0]->publishing_channel_id);

        TenantContext::forget();
    }

    // ─── Article 模型事件：自动分发 ───

    public function test_article_status_change_to_published_triggers_auto_distribution(): void
    {
        [$tenant, $admin, $project] = $this->setupTenant();

        $autoChannel = $this->channel($tenant, ['name' => 'Auto Dist Channel', 'is_active' => true]);
        $article = $this->article($tenant, $project, $admin, ['status' => 'draft']);

        TenantContext::set($tenant);
        app(TaskDistributionChannelSelector::class)->bindChannels($project->id, [$autoChannel->id]);
        $this->assertSame(0, ArticlePublication::query()->where('article_id', $article->id)->count());

        // 阻止 Job 同步执行，仅验证入队逻辑
        Queue::fake();

        // 状态变为 published → 触发自动分发
        $article->update(['status' => 'published']);

        $publications = ArticlePublication::query()
            ->where('article_id', $article->id)
            ->get();

        $this->assertCount(1, $publications);
        $this->assertSame(ArticlePublication::STATUS_QUEUED, $publications->first()->status);

        TenantContext::forget();
    }

    public function test_article_status_change_to_non_published_does_not_trigger_distribution(): void
    {
        [$tenant, $admin, $project] = $this->setupTenant();

        $this->channel($tenant, ['name' => 'No Dist', 'is_active' => true]);
        $article = $this->article($tenant, $project, $admin, ['status' => 'draft']);

        TenantContext::set($tenant);
        $article->update(['status' => 'pending']);

        $this->assertSame(0, ArticlePublication::query()->where('article_id', $article->id)->count());

        TenantContext::forget();
    }

    // D3: 直接以 published 创建的文章也触发自动分发分发
    public function test_article_created_as_published_triggers_auto_distribution(): void
    {
        [$tenant, $admin, $project] = $this->setupTenant();

        $autoChannel = $this->channel($tenant, ['name' => 'Auto Create Dist', 'is_active' => true]);

        // Queue::fake 阻止 Job 同步执行
        Queue::fake();

        TenantContext::set($tenant);
        app(TaskDistributionChannelSelector::class)->bindChannels($project->id, [$autoChannel->id]);
        $article = Article::query()->create([
            'project_id' => $project->id,
            'author_id' => $admin->id,
            'title' => 'Created As Published',
            'body_markdown' => '# Hello',
            'summary' => 'test',
            'content_type' => 'general',
            'status' => 'published',
            'source_type' => 'manual',
            'metadata' => [],
        ]);

        $publications = ArticlePublication::query()
            ->where('article_id', $article->id)
            ->get();

        $this->assertCount(1, $publications);
        $this->assertSame(ArticlePublication::STATUS_QUEUED, $publications->first()->status);

        TenantContext::forget();
    }

    // ─── 租户隔离 ───

    public function test_distribution_channels_are_tenant_scoped(): void
    {
        [$tenantA] = $this->setupTenant('dist-a');
        [$tenantB] = $this->setupTenant('dist-b');

        TenantContext::set($tenantA);
        $this->channel($tenantA, ['name' => 'A Channel']);
        TenantContext::forget();

        TenantContext::set($tenantB);
        $this->channel($tenantB, ['name' => 'B Channel']);
        $this->channel($tenantB, ['name' => 'B Channel 2']);
        TenantContext::forget();

        TenantContext::set($tenantA);
        $aChannels = PublishingChannel::query()->get();
        $this->assertCount(1, $aChannels);
        $this->assertSame('A Channel', $aChannels->first()->name);
        TenantContext::forget();

        TenantContext::set($tenantB);
        $bChannels = PublishingChannel::query()->get();
        $this->assertCount(2, $bChannels);
        TenantContext::forget();
    }

    // ─── DistributionChannelSecret ───

    public function test_generate_secret_creates_active_key_with_correct_prefix(): void
    {
        [$tenant] = $this->setupTenant();
        TenantContext::set($tenant);

        $channel = $this->channel($tenant, [
            'type' => PublishingChannel::TYPE_WORDPRESS_REST,
        ]);

        $secret = DistributionChannelSecret::generateFor($channel);

        $this->assertTrue($secret->isActive());
        $this->assertStringStartsWith('gfk_', $secret->key_id);
        $this->assertStringStartsWith('gfsec_', $secret->decryptSecret());
        $this->assertSame($channel->id, $secret->publishing_channel_id);
        $this->assertSame(['beacon'], $secret->scopes);

        TenantContext::forget();
    }

    public function test_revoke_secret_changes_status_to_revoked(): void
    {
        [$tenant] = $this->setupTenant();
        TenantContext::set($tenant);

        $channel = $this->channel($tenant, [
            'type' => PublishingChannel::TYPE_WORDPRESS_REST,
        ]);

        $secret = DistributionChannelSecret::generateFor($channel);
        $this->assertTrue($secret->isActive());

        $secret->revoke();
        $this->assertFalse($secret->fresh()->isActive());

        TenantContext::forget();
    }

    // ─── DistributionLog ───

    public function test_distribution_log_write_creates_record(): void
    {
        [$tenant] = $this->setupTenant();
        TenantContext::set($tenant);

        $channel = $this->channel($tenant);
        $log = DistributionLog::write(
            'info',
            'Test distribution log',
            channel: $channel,
            event: 'test.event',
            context: ['key' => 'value'],
        );

        $this->assertNotNull($log->id);
        $this->assertSame('info', $log->level);
        $this->assertSame('test.event', $log->event);
        $this->assertSame('Test distribution log', $log->message);
        $this->assertSame(['key' => 'value'], $log->context);
        $this->assertSame($channel->id, $log->publishing_channel_id);

        TenantContext::forget();
    }

    // ─── 非阻塞铁律 + process() 端到端（B5 验收）───

    public function test_remote_failure_does_not_roll_back_local_article_publish(): void
    {
        [$tenant, $admin, $project] = $this->setupTenant('nonblock');
        $channel = $this->channel($tenant, [
            'type' => PublishingChannel::TYPE_HTTP_WEBHOOK,
            'endpoint_url' => 'https://hooks.example.test/nonblock',
        ]);
        // D3: 以 draft 创建避免自动分发，再手动设 published 状态
        $article = $this->article($tenant, $project, $admin, [
            'status' => 'draft',
        ]);
        Article::withoutEvents(function () use ($article) {
            $article->forceFill(['status' => 'published', 'published_at' => now()])->save();
        });

        TenantContext::set($tenant);
        $publication = $this->publication($tenant, $article, $channel, [
            'status' => ArticlePublication::STATUS_QUEUED,
            'attempt_count' => 0,
        ]);

        // 远端返回 403（不可重试）→ 分发终态 failed
        Http::fake(['*' => Http::response(['message' => 'forbidden'], 403)]);

        $result = app(DistributionOrchestrator::class)->process($publication->fresh());

        $this->assertFalse($result['ok'] ?? true);
        $this->assertSame(ArticlePublication::STATUS_FAILED, $publication->fresh()->status);

        // 铁律：远端失败绝不回滚本地文章
        $freshArticle = $article->fresh();
        $this->assertSame('published', $freshArticle->status);
        $this->assertNotNull($freshArticle->published_at);

        TenantContext::forget();
    }

    public function test_process_publishes_to_remote_and_records_remote_url(): void
    {
        [$tenant, $admin, $project] = $this->setupTenant('dist-ok');
        $channel = $this->channel($tenant, [
            'type' => PublishingChannel::TYPE_HTTP_WEBHOOK,
            'endpoint_url' => 'https://hooks.example.test/ok',
        ]);
        // D3: 以 draft 创建避免自动分发，再手动设 published 状态
        $article = $this->article($tenant, $project, $admin, [
            'status' => 'draft',
        ]);
        Article::withoutEvents(function () use ($article) {
            $article->forceFill(['status' => 'published', 'published_at' => now()])->save();
        });

        TenantContext::set($tenant);
        $publication = $this->publication($tenant, $article, $channel, [
            'status' => ArticlePublication::STATUS_QUEUED,
            'attempt_count' => 0,
        ]);

        Http::fake(['*' => Http::response(['id' => 'remote-777', 'url' => 'https://site.example.test/post/777'], 201)]);

        $result = app(DistributionOrchestrator::class)->process($publication->fresh());

        $this->assertTrue($result['ok'] ?? false);
        $fresh = $publication->fresh();
        $this->assertSame(ArticlePublication::STATUS_PUBLISHED, $fresh->status);
        $this->assertSame('remote-777', $fresh->remote_id);
        $this->assertSame('https://site.example.test/post/777', $fresh->remote_url);
        $this->assertNotNull($fresh->published_at);

        TenantContext::forget();
    }

    // ─── 队列 Job：还原租户上下文 + 归属校验（跨租户安全）───

    public function test_distribution_job_reestablishes_tenant_context_and_enforces_ownership(): void
    {
        [$tenantA, $adminA, $projectA] = $this->setupTenant('job-a');
        [$tenantB] = $this->setupTenant('job-b');

        $channelA = $this->channel($tenantA, [
            'type' => PublishingChannel::TYPE_HTTP_WEBHOOK,
            'endpoint_url' => 'https://hooks.example.test/job-a',
        ]);
        // D3: 以 draft 创建避免自动分发，再手动设 published 状态
        $articleA = $this->article($tenantA, $projectA, $adminA, [
            'status' => 'draft',
        ]);
        Article::withoutEvents(function () use ($articleA) {
            $articleA->forceFill(['status' => 'published', 'published_at' => now()])->save();
        });

        TenantContext::set($tenantA);
        $publicationA = $this->publication($tenantA, $articleA, $channelA, [
            'status' => ArticlePublication::STATUS_QUEUED,
            'attempt_count' => 0,
        ]);
        TenantContext::forget();

        Http::fake(['*' => Http::response(['id' => 'r-a', 'url' => 'https://a.example.test/1'], 201)]);

        // 模拟队列 worker：无环境租户上下文
        $this->assertFalse(TenantContext::has());

        // 错配租户（B 的 id + A 的 publication）→ 租户作用域找不到 → no-op，不得处理
        (new ProcessArticleDistributionJob($publicationA->id, $tenantB->id))
            ->handle(app(DistributionOrchestrator::class));

        $this->assertFalse(TenantContext::has(), 'Job 结束后必须清理租户上下文');
        TenantContext::set($tenantA);
        $this->assertSame(ArticlePublication::STATUS_QUEUED, $publicationA->fresh()->status, '错配租户不应处理该分发');
        TenantContext::forget();

        // 正确租户 → 正常处理并写回远端 URL
        (new ProcessArticleDistributionJob($publicationA->id, $tenantA->id))
            ->handle(app(DistributionOrchestrator::class));

        $this->assertFalse(TenantContext::has(), 'Job 结束后必须清理租户上下文');
        TenantContext::set($tenantA);
        $fresh = $publicationA->fresh();
        $this->assertSame(ArticlePublication::STATUS_PUBLISHED, $fresh->status);
        $this->assertSame('r-a', $fresh->remote_id);
        TenantContext::forget();
    }

    // ─── E1: consecutive_failures 阈值自动禁用 ───

    public function test_health_check_increments_consecutive_failures_but_keeps_active_below_threshold(): void
    {
        [$tenant] = $this->setupTenant('e1-below');

        $channel = $this->channel($tenant, [
            'type' => PublishingChannel::TYPE_HTTP_WEBHOOK,
            'endpoint_url' => 'https://hooks.example.test/e1',
            'consecutive_failures' => 0,
        ]);

        // 阈值设低方便测试
        config(['geo.distribution.channel_failure_threshold' => 3]);

        // 模拟不健康
        Http::fake(['*' => Http::response(['error' => 'down'], 500)]);

        TenantContext::set($tenant);
        $orchestrator = app(DistributionOrchestrator::class);

        // 第 1 次失败
        $orchestrator->checkHealth($channel);
        $this->assertSame(1, $channel->fresh()->consecutive_failures);
        $this->assertTrue($channel->fresh()->is_active);

        // 第 2 次失败（未达阈值 3）
        $orchestrator->checkHealth($channel);
        $this->assertSame(2, $channel->fresh()->consecutive_failures);
        $this->assertTrue($channel->fresh()->is_active);

        TenantContext::forget();
    }

    public function test_health_check_disables_channel_at_threshold(): void
    {
        [$tenant] = $this->setupTenant('e1-at');

        $channel = $this->channel($tenant, [
            'type' => PublishingChannel::TYPE_HTTP_WEBHOOK,
            'endpoint_url' => 'https://hooks.example.test/e1-at',
            'consecutive_failures' => 2,
        ]);

        config(['geo.distribution.channel_failure_threshold' => 3]);

        Http::fake(['*' => Http::response(['error' => 'down'], 500)]);

        TenantContext::set($tenant);
        $orchestrator = app(DistributionOrchestrator::class);

        // 第 3 次 → 达阈值 → 禁用
        $orchestrator->checkHealth($channel);
        $this->assertSame(3, $channel->fresh()->consecutive_failures);
        $this->assertFalse($channel->fresh()->is_active);

        TenantContext::forget();
    }

    public function test_health_check_resets_consecutive_failures_on_success(): void
    {
        [$tenant] = $this->setupTenant('e1-reset');

        $channel = $this->channel($tenant, [
            'type' => PublishingChannel::TYPE_HTTP_WEBHOOK,
            'endpoint_url' => 'https://hooks.example.test/e1-reset',
            'consecutive_failures' => 4,
            'is_active' => false, // 已被禁用
        ]);

        config(['geo.distribution.channel_failure_threshold' => 5]);

        // 模拟健康
        Http::fake([
            '*/hooks.example.test/*' => Http::response([
                'ok' => true,
                'message' => 'webhook reachable',
            ], 200),
        ]);

        TenantContext::set($tenant);
        $orchestrator = app(DistributionOrchestrator::class);

        // 健康检查成功 → 计数归零
        $orchestrator->checkHealth($channel);
        $this->assertSame(0, $channel->fresh()->consecutive_failures);

        TenantContext::forget();
    }

    // ─── Helpers ───

    private function setupTenant(string $subdomain = 'dist-test'): array
    {
        $tenant = Tenant::query()->create([
            'subdomain' => $subdomain,
            'name' => strtoupper($subdomain),
            'plan' => 'points',
            'status' => 'active',
        ]);
        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,

            'email' => $subdomain.'@example.com',
            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain),
            'role' => 'admin',
        ]);

        TenantContext::set($tenant);
        try {
            $project = Project::query()->create([
                'name' => $subdomain.' Project',
                'industry' => 'technology',
                'status' => 'active',
                'owner_id' => $admin->id,
            ]);
        } finally {
            TenantContext::forget();
        }

        return [$tenant, $admin, $project];
    }

    private function channel(Tenant $tenant, array $overrides = []): PublishingChannel
    {
        return PublishingChannel::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $tenant->id,
            'name' => 'Test Channel',
            'type' => PublishingChannel::TYPE_HTTP_WEBHOOK,
            'endpoint_url' => 'https://hooks.example.test/test',
            'auth_type' => 'none',
            'is_active' => true,
        ], $overrides));
    }

    private function article(Tenant $tenant, Project $project, User $author, array $overrides = []): Article
    {
        TenantContext::set($tenant);
        try {
            return Article::query()->create(array_merge([
                'project_id' => $project->id,
                'author_id' => $author->id,
                'title' => 'Distribution Test Article',
                'body_markdown' => '# Distribution Test',
                'summary' => 'Test summary',
                'content_type' => 'general',
                'status' => 'draft',
                'source_type' => 'manual',
                'metadata' => [],
            ], $overrides));
        } finally {
            TenantContext::forget();
        }
    }

    private function publication(Tenant $tenant, Article $article, PublishingChannel $channel, array $overrides = []): ArticlePublication
    {
        return ArticlePublication::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $tenant->id,
            'article_id' => $article->id,
            'publishing_channel_id' => $channel->id,
            'status' => ArticlePublication::STATUS_PUBLISHED,
            'attempt_count' => 1,
            'last_attempted_at' => now(),
            'metadata' => [],
        ], $overrides));
    }
}
