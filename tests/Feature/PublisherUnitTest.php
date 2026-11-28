<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticlePublication;
use App\Models\DistributionChannelSecret;
use App\Models\Project;
use App\Models\PublishingChannel;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Distribution\Publishers\GenericHttpPublisher;
use App\Services\Distribution\Publishers\WordPressRestPublisher;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublisherUnitTest extends TestCase
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

    // ─── WordPressRestPublisher ───

    public function test_wp_publish_success_returns_remote_id_and_url(): void
    {
        [$tenant, $admin, $project] = $this->setupTenant('wp-pub-ok');
        $channel = $this->channel($tenant, [
            'type' => PublishingChannel::TYPE_WORDPRESS_REST,
            'endpoint_url' => 'https://wp.example.test',
            'auth_type' => 'wordpress_application_password',
            'username' => 'editor',
            'encrypted_secret' => 'test-fake-a34332a',
        ]);
        $article = $this->article($tenant, $project, $admin);
        TenantContext::set($tenant);
        $publication = $this->publication($tenant, $article, $channel);

        Http::fake(function ($request) {
            // 文章发布 POST
            if (str_contains($request->url(), '/wp-json/wp/v2/posts') && $request->method() === 'POST') {
                return Http::response([
                    'id' => 42,
                    'link' => 'https://wp.example.test/hello-world/',
                    'status' => 'publish',
                ], 201);
            }
            // 分类搜索（默认 disabled，不会触发，但兜底）
            if (str_contains($request->url(), '/wp-json/wp/v2/categories')) {
                return Http::response([], 200);
            }
            // 标签搜索（默认 disabled）
            if (str_contains($request->url(), '/wp-json/wp/v2/tags')) {
                return Http::response([], 200);
            }
            return Http::response(['ok' => true], 200);
        });

        $publisher = app(WordPressRestPublisher::class);
        $result = $publisher->publish($publication);

        $this->assertTrue($result['ok']);
        $this->assertSame('42', $result['remote_id']);
        $this->assertSame('https://wp.example.test/hello-world/', $result['remote_url']);
        $this->assertSame('publish', $result['wp_status']);

        TenantContext::forget();
    }

    public function test_wp_publish_failure_redacts_error_message(): void
    {
        [$tenant, $admin, $project] = $this->setupTenant('wp-pub-fail');
        $channel = $this->channel($tenant, [
            'type' => PublishingChannel::TYPE_WORDPRESS_REST,
            'endpoint_url' => 'https://wp.example.test',
            'auth_type' => 'bearer',
            'encrypted_secret' => 'sk-test-39db767a08961a',
        ]);
        $article = $this->article($tenant, $project, $admin);
        TenantContext::set($tenant);
        $publication = $this->publication($tenant, $article, $channel);

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/wp-json/wp/v2/posts') && $request->method() === 'POST') {
                return Http::response([
                    'code' => 'rest_forbidden',
                    'message' => 'token=sk-test-39db767a08961a is invalid, email fixture-user-4@example.com',
                ], 403);
            }
            return Http::response(['ok' => true], 200);
        });

        $publisher = app(WordPressRestPublisher::class);
        $result = $publisher->publish($publication);

        $this->assertFalse($result['ok']);
        $this->assertSame(403, $result['http_status']);
        // 报错脱敏：secret 和 email 不应明文出现
        $this->assertStringNotContainsString('sk-test-39db767a08961a', $result['message']);
        $this->assertStringNotContainsString('fixture-user-4@example.com', $result['message']);

        TenantContext::forget();
    }

    /**
     * 2xx 不等于"发出去了"。
     *
     * 2026-09-21 用一次性 WordPress 靶站跑真实 E2E 时踩到：靶站没配固定链接（或 REST 路由没生效）时，
     * `/wp-json/wp/v2/posts` 会被 WordPres 当成普通页面，返回 **200 + HTML**。旧代码只看
     * `$response->successful()`，于是把 publication 记成 `published`，而 `remote_id` / `remote_url`
     * 都是空、`last_error` 也是空 —— 客户看到"已发布"，实际什么都没发出去，还没有任何线索可查。
     *
     * 判据：2xx 但响应体里没有 id/link → 失败，并且错误文案要能指路（固定链接/REST 路由）。
     */
    public function test_wp_publish_2xx_with_html_body_is_not_treated_as_published(): void
    {
        [$tenant, $admin, $project] = $this->setupTenant('wp-pub-html');
        $channel = $this->channel($tenant, [
            'type' => PublishingChannel::TYPE_WORDPRESS_REST,
            'endpoint_url' => 'https://wp.example.test',
            'auth_type' => 'wordpress_application_password',
            'username' => 'editor',
            'encrypted_secret' => 'test-fake-a34332a',
        ]);
        $article = $this->article($tenant, $project, $admin);
        TenantContext::set($tenant);
        $publication = $this->publication($tenant, $article, $channel);

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/wp-json/wp/v2/posts')) {
                // 真靶站实测：固定链接没设时就是这个形状 —— 200 + 站点首页的 HTML。
                return Http::response('<!doctype html><html><body>首页</body></html>', 200);
            }

            return Http::response(['ok' => true], 200);
        });

        $result = app(WordPressRestPublisher::class)->publish($publication);

        $this->assertFalse($result['ok'], '2xx + HTML 不能当成发布成功');
        $this->assertSame(200, $result['http_status']);
        $this->assertArrayNotHasKey('remote_url', $result);
        $this->assertStringContainsString('JSON', $result['message']);
    }

    public function test_wp_publish_with_category_find_or_create_finds_existing(): void
    {
        [$tenant, $admin, $project] = $this->setupTenant('wp-cat');
        $channel = $this->channel($tenant, [
            'type' => PublishingChannel::TYPE_WORDPRESS_REST,
            'endpoint_url' => 'https://wp.example.test',
            'auth_type' => 'bearer',
            'encrypted_secret' => 'test-fake',
            'channel_config' => [
                'category_strategy' => 'match_or_create',
            ],
        ]);
        $article = $this->article($tenant, $project, $admin, [
            'content_type' => 'explainer',
        ]);
        TenantContext::set($tenant);
        $publication = $this->publication($tenant, $article, $channel);

        $capturedPayload = null;
        Http::fake(function ($request) use (&$capturedPayload) {
            // 分类搜索 — 返回已有
            if (str_contains($request->url(), '/wp-json/wp/v2/categories') && $request->method() === 'GET') {
                return Http::response([
                    ['id' => 7, 'name' => 'explainer'],
                ], 200);
            }
            // 文章发布
            if (str_contains($request->url(), '/wp-json/wp/v2/posts') && $request->method() === 'POST') {
                $capturedPayload = json_decode($request->body(), true);
                return Http::response([
                    'id' => 100,
                    'link' => 'https://wp.example.test/post-100/',
                    'status' => 'publish',
                ], 201);
            }
            return Http::response(['ok' => true], 200);
        });

        $publisher = app(WordPressRestPublisher::class);
        $result = $publisher->publish($publication);

        $this->assertTrue($result['ok']);
        $this->assertNotNull($capturedPayload);
        $this->assertArrayHasKey('categories', $capturedPayload);
        $this->assertSame([7], $capturedPayload['categories']);

        TenantContext::forget();
    }

    public function test_wp_publish_with_category_find_or_create_creates_new(): void
    {
        [$tenant, $admin, $project] = $this->setupTenant('wp-cat-new');
        $channel = $this->channel($tenant, [
            'type' => PublishingChannel::TYPE_WORDPRESS_REST,
            'endpoint_url' => 'https://wp.example.test',
            'auth_type' => 'bearer',
            'encrypted_secret' => 'test-fake',
            'channel_config' => [
                'category_strategy' => 'match_or_create',
            ],
        ]);
        $article = $this->article($tenant, $project, $admin, [
            'content_type' => 'case_study',
        ]);
        TenantContext::set($tenant);
        $publication = $this->publication($tenant, $article, $channel);

        $capturedPayload = null;
        Http::fake(function ($request) use (&$capturedPayload) {
            // 分类搜索 — 无匹配
            if (str_contains($request->url(), '/wp-json/wp/v2/categories') && $request->method() === 'GET') {
                return Http::response([
                    ['id' => 7, 'name' => 'explainer'], // 名称不匹配
                ], 200);
            }
            // 创建分类
            if (str_contains($request->url(), '/wp-json/wp/v2/categories') && $request->method() === 'POST') {
                return Http::response([
                    'id' => 99,
                    'name' => 'case_study',
                ], 201);
            }
            // 文章发布
            if (str_contains($request->url(), '/wp-json/wp/v2/posts') && $request->method() === 'POST') {
                $capturedPayload = json_decode($request->body(), true);
                return Http::response([
                    'id' => 200,
                    'link' => 'https://wp.example.test/post-200/',
                    'status' => 'publish',
                ], 201);
            }
            return Http::response(['ok' => true], 200);
        });

        $publisher = app(WordPressRestPublisher::class);
        $result = $publisher->publish($publication);

        $this->assertTrue($result['ok']);
        $this->assertNotNull($capturedPayload);
        $this->assertArrayHasKey('categories', $capturedPayload);
        $this->assertSame([99], $capturedPayload['categories']);

        TenantContext::forget();
    }

    public function test_wp_update_without_remote_id_returns_failure(): void
    {
        [$tenant, $admin, $project] = $this->setupTenant('wp-upd');
        $channel = $this->channel($tenant, [
            'type' => PublishingChannel::TYPE_WORDPRESS_REST,
            'endpoint_url' => 'https://wp.example.test',
        ]);
        $article = $this->article($tenant, $project, $admin);
        TenantContext::set($tenant);
        $publication = $this->publication($tenant, $article, $channel, [
            'remote_id' => null,
        ]);

        $publisher = app(WordPressRestPublisher::class);
        $result = $publisher->update($publication);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('remote_id', $result['message']);

        TenantContext::forget();
    }

    public function test_wp_delete_success(): void
    {
        [$tenant, $admin, $project] = $this->setupTenant('wp-del');
        $channel = $this->channel($tenant, [
            'type' => PublishingChannel::TYPE_WORDPRESS_REST,
            'endpoint_url' => 'https://wp.example.test',
            'auth_type' => 'bearer',
            'encrypted_secret' => 'token',
        ]);
        $article = $this->article($tenant, $project, $admin);
        TenantContext::set($tenant);
        $publication = $this->publication($tenant, $article, $channel, [
            'remote_id' => '42',
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/wp-json/wp/v2/posts/42') && $request->method() === 'DELETE') {
                return Http::response(['id' => 42, 'status' => 'trash'], 200);
            }
            return Http::response(['ok' => true], 200);
        });

        $publisher = app(WordPressRestPublisher::class);
        $result = $publisher->delete($publication);

        $this->assertTrue($result['ok']);

        TenantContext::forget();
    }

    // ─── GenericHttpPublisher ───

    public function test_generic_publish_success_returns_remote_id_and_url(): void
    {
        [$tenant, $admin, $project] = $this->setupTenant('gen-ok');
        $channel = $this->channel($tenant, [
            'type' => PublishingChannel::TYPE_HTTP_WEBHOOK,
            'endpoint_url' => 'https://api.example.test',
            'auth_type' => 'bearer',
            'encrypted_secret' => 'test-fake-dc842',
            'channel_config' => [
                'publish_path' => '/api/articles',
                'remote_id_path' => 'data.id',
                'remote_url_path' => 'data.url',
            ],
        ]);
        $article = $this->article($tenant, $project, $admin);
        TenantContext::set($tenant);
        $publication = $this->publication($tenant, $article, $channel);

        $capturedRequest = null;
        Http::fake(function ($request) use (&$capturedRequest) {
            if (str_contains($request->url(), 'api.example.test/api/articles')) {
                $capturedRequest = $request;
                return Http::response([
                    'data' => [
                        'id' => 'gen-123',
                        'url' => 'https://site.example.test/articles/gen-123',
                    ],
                ], 200);
            }
            return Http::response(['ok' => true], 200);
        });

        $publisher = app(GenericHttpPublisher::class);
        $result = $publisher->publish($publication);

        $this->assertTrue($result['ok']);
        $this->assertSame('gen-123', $result['remote_id']);
        $this->assertSame('https://site.example.test/articles/gen-123', $result['remote_url']);

        // 验证请求头含 bearer token
        $this->assertNotNull($capturedRequest);
        $this->assertStringContainsString('test-fake-dc842', $capturedRequest->header('Authorization')[0] ?? '');

        TenantContext::forget();
    }

    public function test_generic_publish_failure_redacts_secret(): void
    {
        [$tenant, $admin, $project] = $this->setupTenant('gen-fail');
        $channel = $this->channel($tenant, [
            'type' => PublishingChannel::TYPE_HTTP_WEBHOOK,
            'endpoint_url' => 'https://api.example.test',
            'auth_type' => 'header_key',
            'encrypted_secret' => 'sk-test-b8a6f82269c2150',
            'channel_config' => [
                'publish_path' => '/api/articles',
                'auth_header_name' => 'X-Api-Key',
            ],
        ]);
        $article = $this->article($tenant, $project, $admin);
        TenantContext::set($tenant);
        $publication = $this->publication($tenant, $article, $channel);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'api.example.test/api/articles')) {
                return Http::response([
                    'error' => 'Unauthorized: api_key=sk-test-b8a6f82269c2150 expired',
                    'message' => 'token=sk-test-b8a6f82269c2150 invalid, contact fixture-user-4@example.com',
                ], 401);
            }
            return Http::response(['ok' => true], 200);
        });

        $publisher = app(GenericHttpPublisher::class);
        $result = $publisher->publish($publication);

        $this->assertFalse($result['ok']);
        $this->assertSame(401, $result['http_status']);
        // 报错消息中不得泄露 secret 和 email
        $this->assertStringNotContainsString('sk-test-b8a6f82269c2150', $result['message']);
        $this->assertStringNotContainsString('fixture-user-4@example.com', $result['message']);

        TenantContext::forget();
    }

    public function test_generic_update_without_remote_id_returns_failure(): void
    {
        [$tenant, $admin, $project] = $this->setupTenant('gen-upd');
        $channel = $this->channel($tenant, [
            'type' => PublishingChannel::TYPE_HTTP_WEBHOOK,
            'endpoint_url' => 'https://api.example.test',
            'channel_config' => ['update_path' => '/api/articles/{remote_id}'],
        ]);
        $article = $this->article($tenant, $project, $admin);
        TenantContext::set($tenant);
        $publication = $this->publication($tenant, $article, $channel, [
            'remote_id' => '',
        ]);

        $publisher = app(GenericHttpPublisher::class);
        $result = $publisher->update($publication);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('remote_id', $result['message']);

        TenantContext::forget();
    }

    public function test_generic_delete_without_remote_id_returns_failure(): void
    {
        [$tenant, $admin, $project] = $this->setupTenant('gen-del');
        $channel = $this->channel($tenant, [
            'type' => PublishingChannel::TYPE_HTTP_WEBHOOK,
            'endpoint_url' => 'https://api.example.test',
        ]);
        $article = $this->article($tenant, $project, $admin);
        TenantContext::set($tenant);
        $publication = $this->publication($tenant, $article, $channel, [
            'remote_id' => null,
        ]);

        $publisher = app(GenericHttpPublisher::class);
        $result = $publisher->delete($publication);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('remote_id', $result['message']);

        TenantContext::forget();
    }

    public function test_generic_publish_with_payload_wrapper(): void
    {
        [$tenant, $admin, $project] = $this->setupTenant('gen-wrap');
        $channel = $this->channel($tenant, [
            'type' => PublishingChannel::TYPE_HTTP_WEBHOOK,
            'endpoint_url' => 'https://api.example.test',
            'auth_type' => 'none',
            'channel_config' => [
                'publish_path' => '/webhook/receive',
                'payload_wrapper' => 'payload',
            ],
        ]);
        $article = $this->article($tenant, $project, $admin);
        TenantContext::set($tenant);
        $publication = $this->publication($tenant, $article, $channel);

        $capturedBody = null;
        Http::fake(function ($request) use (&$capturedBody) {
            if (str_contains($request->url(), 'webhook/receive')) {
                $capturedBody = json_decode($request->body(), true);
                return Http::response(['id' => 'w-1', 'url' => 'https://x.test/w-1'], 200);
            }
            return Http::response(['ok' => true], 200);
        });

        $publisher = app(GenericHttpPublisher::class);
        $result = $publisher->publish($publication);

        $this->assertTrue($result['ok']);
        $this->assertNotNull($capturedBody);
        // payload_wrapper = 'payload' → 外层 key 是 payload
        $this->assertArrayHasKey('payload', $capturedBody);
        $this->assertArrayHasKey('article', $capturedBody['payload']);

        TenantContext::forget();
    }

    // ─── Helpers ───

    private function setupTenant(string $subdomain = 'pub-test'): array
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

        TenantContext::set($tenant);
        try {
            $project = Project::query()->create([
                'name' => $subdomain . ' Project',
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
                'title' => 'Publisher Test Article',
                'body_markdown' => '# Hello World',
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
            'status' => ArticlePublication::STATUS_QUEUED,
            'attempt_count' => 0,
            'metadata' => [],
        ], $overrides));
    }
}
