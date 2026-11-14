<?php

namespace Tests\Feature;

use App\Models\AnalyticsCrawlerHit;
use App\Models\AnalyticsVisitLog;
use App\Models\Article;
use App\Models\PublishingChannel;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Reports\AnalyticsIngestionService;
use App\Support\Analytics\TrafficClassifier;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnalyticsVisitLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=',
            'app.tenant_domain' => 'geo.example.test',
        ]);
        $this->app->forgetInstance('encrypter');
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    public function test_classifier_identifies_ai_bots(): void
    {
        $this->assertSame(TrafficClassifier::AI_BOT, TrafficClassifier::classify('Mozilla/5.0 GPTBot/1.0'));
        $this->assertSame('gptbot', TrafficClassifier::botName('Mozilla/5.0 GPTBot/1.0'));
        $this->assertSame(TrafficClassifier::AI_BOT, TrafficClassifier::classify('Bytespider/2.0'));
        $this->assertSame(TrafficClassifier::SEARCH_BOT, TrafficClassifier::classify('Googlebot/2.1'));
        $this->assertSame(TrafficClassifier::HUMAN, TrafficClassifier::classify('Mozilla/5.0 (Macintosh) Chrome/120'));
        $this->assertSame(TrafficClassifier::UNKNOWN, TrafficClassifier::classify(''));
    }

    /**
     * Phase 1 §1.2：补齐的 AI 爬虫 token 必须归 ai_bot。
     */
    public function test_phase1_added_ai_bot_tokens_classify_as_ai_bot(): void
    {
        $tokens = [
            'Amazonbot/0.1',
            'PetalBot/1.0',
            'DuckAssistBot/1.0',
            'Meta-ExternalFetcher/1.0',
            'GoogleOther/1.0',
            'VelenPublicCrawler/1.0',
            'DataForSeoBot/1.0',
            'SeznamBot/1.0',
        ];

        foreach ($tokens as $ua) {
            $this->assertSame(
                TrafficClassifier::AI_BOT,
                TrafficClassifier::classify($ua),
                "UA [{$ua}] 应被分类为 ai_bot",
            );
        }
    }

    public function test_record_visit_writes_log_and_crawler_hit_for_ai_bot(): void
    {
        [$tenant, $admin] = $this->tenantAndUser('vlog-a', 'admin');
        $svc = $this->app->make(AnalyticsIngestionService::class);

        $log = $svc->recordVisit([
            'tenant_id' => $tenant->id,
            'user_agent' => 'Mozilla/5.0 GPTBot/1.0',
            'ip_address' => '203.0.113.9',
            'path' => '/article/hello',
        ]);

        $this->assertSame(TrafficClassifier::AI_BOT, $log->traffic_type);
        $this->assertSame('gptbot', $log->bot_name);
        $this->assertSame(1, AnalyticsVisitLog::query()->count());
        $this->assertSame(1, AnalyticsCrawlerHit::query()->where('bot_name', 'gptbot')->count());
    }

    public function test_human_visit_does_not_write_crawler_hit(): void
    {
        [$tenant] = $this->tenantAndUser('vlog-h', 'admin');
        $svc = $this->app->make(AnalyticsIngestionService::class);

        $svc->recordVisit([
            'tenant_id' => $tenant->id,
            'user_agent' => 'Mozilla/5.0 (Macintosh) Chrome/120',
            'ip_address' => '198.51.100.7',
        ]);

        $this->assertSame(1, AnalyticsVisitLog::query()->count());
        $this->assertSame(0, AnalyticsCrawlerHit::query()->count());
    }

    public function test_crawler_breakdown_endpoint_reports_ai_bot_pv(): void
    {
        [$tenant, $admin] = $this->tenantAndUser('vlog-c', 'admin');
        $svc = $this->app->make(AnalyticsIngestionService::class);
        $article = $this->article($tenant, $admin);

        $svc->recordVisit(['tenant_id' => $tenant->id, 'user_agent' => 'Mozilla/5.0 GPTBot/1.0', 'article_id' => $article->id]);
        $svc->recordVisit(['tenant_id' => $tenant->id, 'user_agent' => 'Mozilla/5.0 (Mac) Chrome/120']);
        $svc->recordVisit(['tenant_id' => $tenant->id, 'user_agent' => 'Bingbot/2.0', 'article_id' => $article->id]);

        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->getJson('/api/v1/analytics/crawler-breakdown')
            ->assertOk()
            ->assertJsonPath('kpis.pv', 3)
            ->assertJsonPath('kpis.ai_bot_pv', 1)
            ->assertJsonPath('kpis.search_bot_pv', 1)
            ->assertJsonPath('kpis.human_pv', 1)
            ->assertJsonPath('traffic_breakdown.2.count', 1); // ai_bot 在第三位

        // top AI bots 命中文章
        $this->forTenant($tenant)
            ->getJson('/api/v1/analytics/crawler-breakdown')
            ->assertOk()
            ->assertJsonPath('top_ai_bots.0.bot_name', 'gptbot')
            ->assertJsonPath('top_ai_bots.0.articles_reached', 1);
    }

    public function test_visit_logs_filter_by_traffic_type_and_are_tenant_isolated(): void
    {
        [$tenantA, $adminA] = $this->tenantAndUser('vlog-ta', 'admin');
        [$tenantB, $adminB] = $this->tenantAndUser('vlog-tb', 'admin');
        $svc = $this->app->make(AnalyticsIngestionService::class);

        $svc->recordVisit(['tenant_id' => $tenantA->id, 'user_agent' => 'Mozilla/5.0 GPTBot/1.0']);
        $svc->recordVisit(['tenant_id' => $tenantA->id, 'user_agent' => 'Mozilla/5.0 (Mac) Chrome']);
        $svc->recordVisit(['tenant_id' => $tenantB->id, 'user_agent' => 'Mozilla/5.0 GPTBot/1.0']);

        Sanctum::actingAs($adminA, ['*']);

        // A 只能看到自己的 2 条
        $this->forTenant($tenantA)
            ->getJson('/api/v1/analytics/visit-logs')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        // 按 ai_bot 过滤，A 只有 1 条
        $this->forTenant($tenantA)
            ->getJson('/api/v1/analytics/visit-logs?traffic_type=ai_bot')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_traffic_trend_and_top_articles(): void
    {
        [$tenant, $admin] = $this->tenantAndUser('vlog-t', 'admin');
        $svc = $this->app->make(AnalyticsIngestionService::class);
        $article = $this->article($tenant, $admin);

        $svc->recordVisit(['tenant_id' => $tenant->id, 'user_agent' => 'Mozilla/5.0 GPTBot/1.0', 'article_id' => $article->id]);
        $svc->recordVisit(['tenant_id' => $tenant->id, 'user_agent' => 'Mozilla/5.0 GPTBot/1.0', 'article_id' => $article->id]);

        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->getJson('/api/v1/analytics/traffic-trend')
            ->assertOk()
            ->assertJsonPath('trend.0.ai_bot_pv', 2);

        $this->forTenant($tenant)
            ->getJson('/api/v1/analytics/top-articles')
            ->assertOk()
            ->assertJsonPath('data.0.article_id', $article->id)
            ->assertJsonPath('data.0.views', 2);
    }

    public function test_channel_beacon_health_reports_last_beacon_and_beaconing_flag(): void
    {
        [$tenant, $admin] = $this->tenantAndUser('vlog-beacon', 'admin');

        TenantContext::set($tenant);
        $channel = PublishingChannel::create([
            'tenant_id' => $tenant->id,
            'name' => 'WP 外站 A',
            'type' => 'wordpress',
            'endpoint_url' => 'https://wp-a.test/xmlrpc.php',
            'is_active' => true,
        ]);

        // 近 7 日内两条回传（beaconing=true，hits_7d=2）
        AnalyticsVisitLog::create([
            'tenant_id' => $tenant->id,
            'publishing_channel_id' => $channel->id,
            'ip_address' => '203.0.113.5',
            'user_agent' => 'Mozilla/5.0 GPTBot/1.0',
            'traffic_type' => TrafficClassifier::AI_BOT,
            'bot_name' => 'gptbot',
            'created_at' => now()->subDays(2),
        ]);
        AnalyticsVisitLog::create([
            'tenant_id' => $tenant->id,
            'publishing_channel_id' => $channel->id,
            'ip_address' => '203.0.113.6',
            'user_agent' => 'Mozilla/5.0 GPTBot/1.0',
            'traffic_type' => TrafficClassifier::AI_BOT,
            'bot_name' => 'gptbot',
            'created_at' => now()->subDays(1),
        ]);
        TenantContext::forget();

        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->getJson('/api/v1/analytics/channel-beacon-health')
            ->assertOk()
            ->assertJsonStructure([
                'period',
                'beacon_threshold_days',
                'data' => [['channel_id', 'channel_name', 'last_beacon_at', 'beaconing', 'hits_7d']],
            ])
            ->assertJsonPath('data.0.channel_id', $channel->id)
            ->assertJsonPath('data.0.channel_name', 'WP 外站 A')
            ->assertJsonPath('data.0.beaconing', true)
            ->assertJsonPath('data.0.hits_7d', 2);
    }

    public function test_channel_beacon_health_stale_channel_is_not_beaconing(): void
    {
        [$tenant, $admin] = $this->tenantAndUser('vlog-beacon-stale', 'admin');

        TenantContext::set($tenant);
        $channel = PublishingChannel::create([
            'tenant_id' => $tenant->id,
            'name' => 'WP 外站 B',
            'type' => 'wordpress',
            'endpoint_url' => 'https://wp-b.test/xmlrpc.php',
            'is_active' => true,
        ]);
        // 远早于阈值（默认 7 天）的回传 → beaconing=false
        AnalyticsVisitLog::create([
            'tenant_id' => $tenant->id,
            'publishing_channel_id' => $channel->id,
            'ip_address' => '203.0.113.7',
            'user_agent' => 'Mozilla/5.0 GPTBot/1.0',
            'traffic_type' => TrafficClassifier::AI_BOT,
            'bot_name' => 'gptbot',
            'created_at' => now()->subDays(20),
        ]);
        TenantContext::forget();

        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->getJson('/api/v1/analytics/channel-beacon-health')
            ->assertOk()
            ->assertJsonPath('data.0.channel_id', $channel->id)
            ->assertJsonPath('data.0.beaconing', false)
            ->assertJsonPath('data.0.hits_7d', 0);
    }

    // ─── helpers ───

    private function tenantAndUser(string $subdomain, string $role): array
    {
        $tenant = Tenant::create([
            'subdomain' => $subdomain,
            'name' => $subdomain,
            'plan' => 'points',
            'status' => 'active',
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,

            'email' => $subdomain.'@example.com',
            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain),
            'role' => $role,
        ]);

        return [$tenant, $user];
    }

    private function article(Tenant $tenant, User $author, array $overrides = []): Article
    {
        TenantContext::set($tenant);
        try {
            $project = Project::query()->create([
                'owner_id' => $author->id,
                'name' => 'Proj '.$tenant->subdomain,
                'brand_name' => $tenant->subdomain,
                'brand_url' => 'https://'.$tenant->subdomain.'.example.test',
                'settings' => [],
            ]);

            return Article::query()->create(array_merge([
                'project_id' => $project->id,
                'author_id' => $author->id,
                'title' => 'Tracked Article',
                'body_markdown' => '# Tracked',
                'content_type' => 'general',
                'status' => 'published',
                'source_type' => 'manual',
                'metadata' => [],
            ], $overrides));
        } finally {
            TenantContext::forget();
        }
    }

    private function forTenant(Tenant $tenant): self
    {
        return $this
            ->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }
}
