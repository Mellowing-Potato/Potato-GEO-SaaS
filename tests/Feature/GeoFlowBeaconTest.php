<?php

namespace Tests\Feature;

use App\Models\AnalyticsCrawlerHit;
use App\Models\AnalyticsVisitLog;
use App\Models\Article;
use App\Models\ArticlePublication;
use App\Models\DistributionChannelSecret;
use App\Models\Project;
use App\Models\PublishingChannel;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * X3 采集二期：外站分发内容回传信标端点验收。
 *
 * 覆盖执行书 X3.3：合法签名批量、错签名 401、nonce 重放 401、超批 422、GPTBot 爬虫命中。
 */
class GeoFlowBeaconTest extends TestCase
{
    use RefreshDatabase;

    private string $keyId;
    private string $plainSecret;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::query()->create([
            'subdomain' => 'beacon-tenant',
            'name' => 'BEACON',
            'plan' => 'basic',
            'status' => 'active',
        ]);

        TenantContext::set($tenant);
        $owner = User::factory()->create();
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'name' => 'B 项目',
            'brand_name' => 'B',
            'owner_id' => $owner->id,
            'current_stage' => 2,
            'status' => 'active',
        ]);
        $article = Article::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'title' => '被爬文章',
            'slug' => 'geo-abc',
            'status' => 'published',
            'body_markdown' => '# hi',
        ]);
        $channel = PublishingChannel::create([
            'tenant_id' => $tenant->id,
            'name' => '客户站',
            'type' => PublishingChannel::TYPE_HTTP_WEBHOOK,
            'endpoint_url' => 'https://site.test/hook',
            'is_active' => true,
        ]);
        ArticlePublication::create([
            'article_id' => $article->id,
            'publishing_channel_id' => $channel->id,
            'remote_id' => 'geo-abc',
            'remote_url' => 'https://site.test/geo-abc',
            'status' => 'published',
        ]);
        TenantContext::forget();

        $this->keyId = 'gfk_beacon_' . uniqid();
        $this->plainSecret = 'gfsec_beacon_' . bin2hex(random_bytes(8));
        DistributionChannelSecret::create([
            'tenant_id' => $tenant->id,
            'publishing_channel_id' => $channel->id,
            'key_id' => $this->keyId,
            'secret_ciphertext' => encrypt($this->plainSecret),
            'status' => 'active',
        ]);
    }

    private function signedPost(array $visits, array $opts = []): \Illuminate\Testing\TestResponse
    {
        $body = json_encode(['visits' => $visits]);
        $url = url('/api/geoflow-beacon/v1/visits');
        $path = parse_url($url, PHP_URL_PATH);
        $timestamp = $opts['timestamp'] ?? (string) time();
        $nonce = $opts['nonce'] ?? bin2hex(random_bytes(16));
        $bodyHash = hash('sha256', $body);
        $secret = $opts['secret'] ?? $this->plainSecret;
        $canonical = implode("\n", ['POST', $path, $timestamp, $nonce, $bodyHash]);
        $signature = hash_hmac('sha256', $canonical, $secret);

        return $this->call('POST', $url, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GEOFLOW_KEY_ID' => $opts['keyId'] ?? $this->keyId,
            'HTTP_X_GEOFLOW_TIMESTAMP' => $timestamp,
            'HTTP_X_GEOFLOW_NONCE' => $nonce,
            'HTTP_X_GEOFLOW_BODY_SHA256' => $bodyHash,
            'HTTP_X_GEOFLOW_SIGNATURE' => $signature,
        ], $body);
    }

    public function test_valid_batch_accepted_and_dropped(): void
    {
        $response = $this->signedPost([
            ['path' => '/geo-abc', 'user_agent' => 'Mozilla/5.0', 'status_code' => 200],
            ['path' => '/some-unknown', 'user_agent' => 'Mozilla/5.0', 'status_code' => 200],
            ['path' => '/geo-abc', 'user_agent' => 'Mozilla/5.0', 'occurred_at' => now()->subDays(8)->toISOString()],
        ]);

        $response->assertOk();
        $response->assertJson(['accepted' => 2, 'dropped' => 1]);

        // 2 行入库，tenant/channel 正确；一条有 article_id，一条 null
        $this->assertDatabaseCount('analytics_visit_logs', 2);
        $this->assertDatabaseHas('analytics_visit_logs', [
            'path' => '/geo-abc',
            'article_id' => Article::where('slug', 'geo-abc')->first()->id,
        ]);
        $this->assertDatabaseHas('analytics_visit_logs', [
            'path' => '/some-unknown',
            'article_id' => null,
        ]);
    }

    public function test_wrong_signature_returns_401(): void
    {
        $this->signedPost(
            [['path' => '/geo-abc', 'user_agent' => 'Mozilla/5.0']],
            ['secret' => 'test-fake-53']
        )->assertStatus(401)->assertJson(['error' => 'signature_error']);
    }

    public function test_nonce_replay_returns_401(): void
    {
        $nonce = bin2hex(random_bytes(16));
        $this->signedPost([['path' => '/geo-abc']], ['nonce' => $nonce])->assertOk();
        // 同 nonce 重放 → 401
        $this->signedPost([['path' => '/geo-abc']], ['nonce' => $nonce])
            ->assertStatus(401)
            ->assertJson(['error' => 'signature_error']);
    }

    public function test_batch_too_large_returns_422(): void
    {
        $visits = array_fill(0, 51, ['path' => '/x']);
        $this->signedPost($visits)->assertStatus(422);
    }

    public function test_gptbot_produces_crawler_hit(): void
    {
        $this->signedPost([
            ['path' => '/geo-abc', 'user_agent' => 'GPTBot/1.0', 'status_code' => 200],
        ])->assertOk();

        $articleId = Article::where('slug', 'geo-abc')->first()->id;
        $this->assertDatabaseHas('analytics_crawler_hits', [
            'bot_name' => 'gptbot',
            'article_id' => $articleId,
        ]);
    }
}
