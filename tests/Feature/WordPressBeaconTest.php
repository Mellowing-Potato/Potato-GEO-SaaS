<?php

namespace Tests\Feature;

use App\Models\AnalyticsVisitLog;
use App\Models\DistributionChannelSecret;
use App\Models\PublishingChannel;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GEO 三期 W1+W2 验收：
 *  W1：WP 渠道开放密钥管理（scopes=['beacon']）、http_webhook 仍 422、WP 新密钥通过信标端点验签入库。
 *  W2：WP 信标插件生成 200 且文件含插件头+key_id+信标 URL、不含明文 secret；http_webhook/geoflow_agent 渠道 422。
 */
class WordPressBeaconTest extends TestCase
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

    // ─── W1 ───

    public function test_wp_channel_secret_generation_returns_beacon_scope(): void
    {
        [$tenant, $admin] = $this->tenantAdmin('wp-beacon');
        Sanctum::actingAs($admin, ['*']);

        $channel = $this->channel($tenant, [
            'type' => PublishingChannel::TYPE_WORDPRESS_REST,
            'endpoint_url' => 'https://wp.example.test/wp-json',
            'is_active' => true,
        ]);

        $this->forTenant($tenant)
            ->postJson("/api/v1/publishing/channels/{$channel->id}/secrets")
            ->assertStatus(201)
            ->assertJsonPath('object', 'distribution_channel_secret')
            ->assertJsonPath('scopes', ['beacon'])
            ->assertJsonStructure(['key_id', 'secret']);
    }

    public function test_http_webhook_channel_secret_still_422(): void
    {
        [$tenant, $admin] = $this->tenantAdmin('wp-beacon-hw');
        Sanctum::actingAs($admin, ['*']);

        $channel = $this->channel($tenant, [
            'type' => PublishingChannel::TYPE_HTTP_WEBHOOK,
            'endpoint_url' => 'https://hooks.example.test/geo',
            'is_active' => true,
        ]);

        $this->forTenant($tenant)
            ->postJson("/api/v1/publishing/channels/{$channel->id}/secrets")
            ->assertStatus(422)
            ->assertJson(['error' => '仅 geoflow_agent / wordpress_rest 类型渠道支持密钥管理。']);
    }

    public function test_wp_channel_secret_passes_beacon_endpoint(): void
    {
        [$tenant, $admin] = $this->tenantAdmin('wp-beacon-e2e');
        Sanctum::actingAs($admin, ['*']);

        $channel = $this->channel($tenant, [
            'type' => PublishingChannel::TYPE_WORDPRESS_REST,
            'endpoint_url' => 'https://wp.example.test/wp-json',
            'is_active' => true,
        ]);

        $secretResp = $this->forTenant($tenant)
            ->postJson("/api/v1/publishing/channels/{$channel->id}/secrets")
            ->assertStatus(201)
            ->json();

        $keyId = $secretResp['key_id'];
        $plainSecret = $secretResp['secret'];

        // WP 新密钥（scopes=['beacon']）通过信标端点验签入库
        $response = $this->signedPost($keyId, $plainSecret, [
            ['path' => '/hello-world', 'user_agent' => 'Mozilla/5.0', 'status_code' => 200],
        ]);

        $response->assertOk();
        $response->assertJson(['accepted' => 1, 'dropped' => 0]);

        $this->assertDatabaseHas('analytics_visit_logs', [
            'path' => '/hello-world',
            'publishing_channel_id' => $channel->id,
            'tenant_id' => $tenant->id,
        ]);
    }

    // ─── W2 ───

    public function test_beacon_plugin_generated_for_wp_channel(): void
    {
        [$tenant, $admin] = $this->tenantAdmin('wp-plugin');
        Sanctum::actingAs($admin, ['*']);

        $channel = $this->channel($tenant, [
            'type' => PublishingChannel::TYPE_WORDPRESS_REST,
            'endpoint_url' => 'https://wp.example.test/wp-json',
            'is_active' => true,
        ]);

        // 先生成 active 密钥
        $secretResp = $this->forTenant($tenant)
            ->postJson("/api/v1/publishing/channels/{$channel->id}/secrets")
            ->assertStatus(201)
            ->json();
        $keyId = $secretResp['key_id'];

        $response = $this->forTenant($tenant)
            ->getJson("/api/v1/publishing/channels/{$channel->id}/beacon-plugin");

        $response->assertOk();
        $response->assertJsonPath('object', 'wordpress_beacon_plugin');
        $response->assertJsonStructure(['files' => ['geoflow-beacon.php'], 'instructions']);

        $files = $response->json('files');
        $plugin = $files['geoflow-beacon.php'];

        // 插件头 + 烤入 key_id + 信标 URL
        $this->assertStringContainsString('Plugin Name: GEOFlow Beacon', $plugin);
        $this->assertStringContainsString($keyId, $plugin);
        $this->assertStringContainsString('/api/geoflow-beacon/v1/visits', $plugin);

        // 明文 secret 绝不进文件：不存在任何“真正执行”的 define('GEOFLOW_BEACON_SECRET'
        $this->assertSame(0, preg_match("/^define\('GEOFLOW_BEACON_SECRET'/m", $plugin), 'plaintext secret must not be baked');

        // 生成的插件必须是合法 PHP（php -l 通过）
        $tmp = tempnam(sys_get_temp_dir(), 'wpplugin') . '.php';
        file_put_contents($tmp, $plugin);
        exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $lintOut, $lintCode);
        unlink($tmp);
        $this->assertSame(0, $lintCode, 'generated plugin must be valid PHP: ' . implode("\n", $lintOut));
    }

    public function test_beacon_plugin_422_for_http_webhook_channel(): void
    {
        [$tenant, $admin] = $this->tenantAdmin('wp-plugin-hw');
        Sanctum::actingAs($admin, ['*']);

        $channel = $this->channel($tenant, [
            'type' => PublishingChannel::TYPE_HTTP_WEBHOOK,
            'endpoint_url' => 'https://hooks.example.test/geo',
            'is_active' => true,
        ]);

        $this->forTenant($tenant)
            ->getJson("/api/v1/publishing/channels/{$channel->id}/beacon-plugin")
            ->assertStatus(422)
            ->assertJson(['error' => '仅 wordpress_rest 类型渠道支持 WP 信标插件生成。']);
    }

    public function test_beacon_plugin_422_for_agent_channel(): void
    {
        [$tenant, $admin] = $this->tenantAdmin('wp-plugin-agent');
        Sanctum::actingAs($admin, ['*']);

        $channel = $this->channel($tenant, [
            'type' => PublishingChannel::TYPE_GEOFLOW_AGENT,
            'endpoint_url' => 'https://agent.example.test',
            'is_active' => true,
        ]);

        $this->forTenant($tenant)
            ->getJson("/api/v1/publishing/channels/{$channel->id}/beacon-plugin")
            ->assertStatus(422)
            ->assertJson(['error' => '仅 wordpress_rest 类型渠道支持 WP 信标插件生成。']);
    }

    // ─── helper：签 GEOFlow 信标批量（与 X3 端点逐字节一致）───

    private function signedPost(string $keyId, string $secret, array $visits): \Illuminate\Testing\TestResponse
    {
        $body = json_encode(['visits' => $visits]);
        $url = url('/api/geoflow-beacon/v1/visits');
        $path = parse_url($url, PHP_URL_PATH);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $bodyHash = hash('sha256', $body);
        $canonical = implode("\n", ['POST', $path, $timestamp, $nonce, $bodyHash]);
        $signature = hash_hmac('sha256', $canonical, $secret);

        return $this->call('POST', $url, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GEOFLOW_KEY_ID' => $keyId,
            'HTTP_X_GEOFLOW_TIMESTAMP' => $timestamp,
            'HTTP_X_GEOFLOW_NONCE' => $nonce,
            'HTTP_X_GEOFLOW_BODY_SHA256' => $bodyHash,
            'HTTP_X_GEOFLOW_SIGNATURE' => $signature,
        ], $body);
    }

    private function tenantAdmin(string $subdomain): array
    {
        $tenant = Tenant::query()->create([
            'subdomain' => $subdomain,
            'name' => strtoupper($subdomain),
            'plan' => 'points',
            'status' => 'active',
        ]);

        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,

            'email' => $subdomain . '@example.com',
            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain),
            'role' => 'admin',
        ]);

        return [$tenant, $admin];
    }

    private function channel(Tenant $tenant, array $overrides = []): PublishingChannel
    {
        return PublishingChannel::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $tenant->id,
            'name' => 'Default Channel',
            'type' => PublishingChannel::TYPE_HTTP_WEBHOOK,
            'endpoint_url' => 'https://hooks.example.test/default',
            'auth_type' => 'none',
            'is_active' => true,
        ], $overrides));
    }

    private function forTenant(Tenant $tenant): self
    {
        return $this
            ->withServerVariables(['HTTP_HOST' => $tenant->subdomain . '.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }
}
