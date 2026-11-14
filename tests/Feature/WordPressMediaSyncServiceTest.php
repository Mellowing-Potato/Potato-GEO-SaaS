<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticlePublication;
use App\Models\Project;
use App\Models\PublishingChannel;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Distribution\DistributionOrchestrator;
use App\Services\Distribution\WordPressMediaSyncService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WordPressMediaSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://geo.example.test',
            'app.tenant_domain' => 'geo.example.test']);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    // ─── D1 验收：2 张本站图 → media 端点被调 2 次、post content 全指向 WP ───

    public function test_sync_uploads_local_images_and_replaces_urls_in_content(): void
    {
        $channel = $this->makeChannel();

        $markdown = "![图1](https://geo.example.test/storage/img1.jpg)\n\n"
            . "正文内容\n\n"
            . "![图2](https://geo.example.test/storage/img2.jpg)";

        Http::fake(function ($request) {
            $url = $request->url();
            // WP media upload endpoint
            if (str_contains($url, '/wp-json/wp/v2/media')) {
                if ($request->method() === 'PUT') {
                    return Http::response(['id' => 101, 'alt_text' => 'ok'], 200);
                }
                // POST sequence
                static $mediaIndex = 0;
                $mediaIndex++;
                if ($mediaIndex === 1) {
                    return Http::response(['id' => 101, 'source_url' => 'https://wp.example.test/wp-content/uploads/img1.jpg'], 201);
                }
                return Http::response(['id' => 102, 'source_url' => 'https://wp.example.test/wp-content/uploads/img2.jpg'], 201);
            }
            // Image download
            if (str_contains($url, '/storage/')) {
                return Http::response('fake-image-binary-content', 200, ['Content-Type' => 'image/jpeg']);
            }
            return Http::response('{}', 200);
        });

        $service = app(WordPressMediaSyncService::class);
        $result = $service->syncContentImages($channel, $markdown);

        $this->assertSame(2, $result['uploaded']);
        $this->assertSame(0, $result['failed']);
        $this->assertStringContainsString('https://wp.example.test/wp-content/uploads/img1.jpg', $result['content']);
        $this->assertStringContainsString('https://wp.example.test/wp-content/uploads/img2.jpg', $result['content']);
        $this->assertStringNotContainsString('https://geo.example.test/storage/img1.jpg', $result['content']);
        $this->assertStringNotContainsString('https://geo.example.test/storage/img2.jpg', $result['content']);
    }

    // ─── D1 验收：第三方外链图不被搬运 ───

    public function test_sync_skips_third_party_images(): void
    {
        $channel = $this->makeChannel();

        $markdown = "![本地](https://geo.example.test/storage/local.jpg)\n\n"
            . "![外链](https://cdn.third-party.com/image.jpg)";

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/wp-json/wp/v2/media')) {
                return Http::response(
                    ['id' => 201, 'source_url' => 'https://wp.example.test/wp-content/uploads/local.jpg'],
                    201,
                );
            }
            if (str_contains($request->url(), '/storage/')) {
                return Http::response('binary', 200, ['Content-Type' => 'image/jpeg']);
            }
            return Http::response('{}', 200);
        });

        $service = app(WordPressMediaSyncService::class);
        $result = $service->syncContentImages($channel, $markdown);

        $this->assertSame(1, $result['uploaded']);
        $this->assertSame(0, $result['failed']);
        // 外链图保留原样
        $this->assertStringContainsString('https://cdn.third-party.com/image.jpg', $result['content']);
        // 本地图已替换
        $this->assertStringNotContainsString('https://geo.example.test/storage/local.jpg', $result['content']);
    }

    // ─── D1.4 验收：单张失败 → 整篇仍成功、该图保留原链 ───

    public function test_sync_non_blocking_degradation_on_single_image_failure(): void
    {
        $channel = $this->makeChannel();

        $markdown = "![正常](https://geo.example.test/storage/ok.jpg)\n\n"
            . "![失败](https://geo.example.test/storage/fail.jpg)";

        static $mediaCallIndex = 0;
        Http::fake(function ($request) use (&$mediaCallIndex) {
            if (str_contains($request->url(), '/wp-json/wp/v2/media')) {
                if ($request->method() === 'PUT') {
                    return Http::response(['id' => 301], 200);
                }
                $mediaCallIndex++;
                if ($mediaCallIndex === 1) {
                    return Http::response(
                        ['id' => 301, 'source_url' => 'https://wp.example.test/wp-content/uploads/ok.jpg'],
                        201,
                    );
                }
                return Http::response(['code' => 'rest_upload_error', 'message' => 'Upload failed'], 500);
            }
            if (str_contains($request->url(), '/storage/')) {
                return Http::response('binary', 200, ['Content-Type' => 'image/jpeg']);
            }
            return Http::response('{}', 200);
        });

        $service = app(WordPressMediaSyncService::class);
        $result = $service->syncContentImages($channel, $markdown);

        $this->assertSame(1, $result['uploaded']);
        $this->assertSame(1, $result['failed']);
        // 正常的图替换了
        $this->assertStringContainsString('https://wp.example.test/wp-content/uploads/ok.jpg', $result['content']);
        // 失败的图保留原链
        $this->assertStringContainsString('https://geo.example.test/storage/fail.jpg', $result['content']);
    }

    // ─── 无图内容短路返回 ───

    public function test_sync_returns_unchanged_content_when_no_images(): void
    {
        $channel = $this->makeChannel();
        $markdown = '# 纯文本文章\n\n没有任何图片。';

        $service = app(WordPressMediaSyncService::class);
        $result = $service->syncContentImages($channel, $markdown);

        $this->assertSame(0, $result['uploaded']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame($markdown, $result['content']);
    }

    // ─── HTML img 标签也能提取和替换 ───

    public function test_sync_handles_html_img_tags(): void
    {
        $channel = $this->makeChannel();

        $content = '<p>段落文字</p><img src="https://geo.example.test/storage/photo.png" alt="照片">';

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/wp-json/wp/v2/media')) {
                return Http::response(
                    ['id' => 401, 'source_url' => 'https://wp.example.test/wp-content/uploads/photo.png'],
                    201,
                );
            }
            if (str_contains($request->url(), '/storage/')) {
                return Http::response('binary', 200, ['Content-Type' => 'image/png']);
            }
            return Http::response('{}', 200);
        });

        $service = app(WordPressMediaSyncService::class);
        $result = $service->syncContentImages($channel, $content);

        $this->assertSame(1, $result['uploaded']);
        $this->assertStringContainsString('src="https://wp.example.test/wp-content/uploads/photo.png"', $result['content']);
        $this->assertStringNotContainsString('https://geo.example.test/storage/photo.png', $result['content']);
    }

    // ─── 相对路径图片：filterLocalImages 放行 /storage/...，uploadMediaCore 拼 app.url ───

    public function test_sync_handles_relative_path_images(): void
    {
        $channel = $this->makeChannel();

        $markdown = "![相对路径](/storage/images/relative.jpg)\n\n正文段落";

        $fetchedUrls = [];
        Http::fake(function ($request) use (&$fetchedUrls) {
            $url = $request->url();
            // 记录所有图片抓取 URL（用于验证相对路径被拼接为绝对 URL）
            if (str_contains($url, '/storage/images/')) {
                $fetchedUrls[] = $url;
                return Http::response('binary-image-data', 200, ['Content-Type' => 'image/jpeg']);
            }
            if (str_contains($url, '/wp-json/wp/v2/media')) {
                if ($request->method() === 'PUT') {
                    return Http::response(['id' => 701], 200);
                }
                return Http::response(
                    ['id' => 701, 'source_url' => 'https://wp.example.test/wp-content/uploads/relative.jpg'],
                    201,
                );
            }
            return Http::response('{}', 200);
        });

        $service = app(WordPressMediaSyncService::class);
        $result = $service->syncContentImages($channel, $markdown);

        $this->assertSame(1, $result['uploaded']);
        $this->assertSame(0, $result['failed']);
        // 验证抓取时用了绝对 URL（app.url + 相对路径）
        $this->assertNotEmpty($fetchedUrls);
        $this->assertStringStartsWith('https://geo.example.test/storage/images/relative.jpg', $fetchedUrls[0]);
        // 正文中的相对路径已被替换为 WP 媒体 URL
        $this->assertStringContainsString('https://wp.example.test/wp-content/uploads/relative.jpg', $result['content']);
        $this->assertStringNotContainsString('/storage/images/relative.jpg', $result['content']);
    }

    // ─── 重复 URL 去重 ───

    public function test_sync_deduplicates_same_image_url(): void
    {
        $channel = $this->makeChannel();

        $markdown = "![图1](https://geo.example.test/storage/same.jpg)\n\n"
            . "![同样的图](https://geo.example.test/storage/same.jpg)";

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/wp-json/wp/v2/media')) {
                return Http::response(
                    ['id' => 501, 'source_url' => 'https://wp.example.test/wp-content/uploads/same.jpg'],
                    201,
                );
            }
            if (str_contains($request->url(), '/storage/')) {
                return Http::response('binary', 200, ['Content-Type' => 'image/jpeg']);
            }
            return Http::response('{}', 200);
        });

        $service = app(WordPressMediaSyncService::class);
        $result = $service->syncContentImages($channel, $markdown);

        // 只上传 1 次
        $this->assertSame(1, $result['uploaded']);
        // 两处都替换了
        $this->assertSame(0, substr_count($result['content'], 'https://geo.example.test/storage/same.jpg'));
        $this->assertSame(2, substr_count($result['content'], 'https://wp.example.test/wp-content/uploads/same.jpg'));
    }

    // ─── 端到端：通过 Orchestrator 分发 WP 渠道，验证 post content 无本站 URL ───

    public function test_end_to_end_wp_distribution_rewrites_inline_images(): void
    {
        [$tenant, $admin, $project] = $this->setupTenant();

        $channel = $this->channel($tenant, [
            'type' => PublishingChannel::TYPE_WORDPRESS_REST,
            'endpoint_url' => 'https://wp.example.test',
            'auth_type' => 'wordpress_application_password',
            'username' => 'admin',
            'encrypted_secret' => 'test-fake-a228eb',
        ]);

        $article = $this->article($tenant, $project, $admin, [
            'status' => 'draft',
            'body_markdown' => "![配图](https://geo.example.test/storage/hero.jpg)\n\n正文段落",
        ]);
        // 绕过 model events 设置 published
        Article::withoutEvents(function () use ($article) {
            $article->forceFill(['status' => 'published', 'published_at' => now()])->save();
        });

        TenantContext::set($tenant);
        $publication = $this->publication($tenant, $article, $channel, [
            'status' => ArticlePublication::STATUS_QUEUED,
            'attempt_count' => 0,
        ]);

        $postsRequestBody = null;
        Http::fake(function ($request) use (&$postsRequestBody) {
            $url = $request->url();
            if (str_contains($url, '/wp-json/wp/v2/posts') && $request->method() === 'POST') {
                $postsRequestBody = $request->body();
                return Http::response(
                    ['id' => 99, 'link' => 'https://wp.example.test/article-99', 'status' => 'publish'],
                    201,
                );
            }
            if (str_contains($url, '/wp-json/wp/v2/media')) {
                return Http::response(
                    ['id' => 601, 'source_url' => 'https://wp.example.test/wp-content/uploads/hero.jpg'],
                    201,
                );
            }
            if (str_contains($url, '/storage/')) {
                return Http::response('binary', 200, ['Content-Type' => 'image/jpeg']);
            }
            return Http::response('{}', 200);
        });

        $result = app(DistributionOrchestrator::class)->process($publication->fresh());

        $this->assertTrue($result['ok'] ?? false);
        $this->assertSame(ArticlePublication::STATUS_PUBLISHED, $publication->fresh()->status);

        // 验证发到 WP 的 post content 不含本站 URL
        $this->assertNotNull($postsRequestBody, 'WP posts endpoint should have been called');
        $decodedBody = json_decode($postsRequestBody, true) ?? [];
        $postContent = data_get($decodedBody, 'content', '');
        $this->assertStringNotContainsString('geo.example.test/storage/hero.jpg', $postContent);
        $this->assertStringContainsString('wp.example.test/wp-content/uploads/hero.jpg', $postContent);

        TenantContext::forget();
    }

    // ─── D2 验收：WordPress 健康检查验发布/上传权限 ───

    public function test_health_check_unhealthy_when_credentials_invalid(): void
    {
        $channel = $this->makeChannel();

        Http::fake(function ($request) {
            $url = $request->url();
            // 可达性检查通过
            if ($url === 'https://wp.example.test/wp-json') {
                return Http::response([
                    'name' => 'Test WP Site',
                    'namespaces' => ['wp/v2', 'oembed/1.0'],
                    'generator' => 'WordPress 6.5',
                ], 200);
            }
            // 认证探测 → 401
            if (str_contains($url, '/wp-json/wp/v2/users/me')) {
                return Http::response(['code' => 'rest_not_logged_in', 'message' => 'Invalid credentials'], 401);
            }
            return Http::response('{}', 200);
        });

        $publisher = app(\App\Services\Distribution\Publishers\WordPressRestPublisher::class);
        $result = $publisher->health($channel);

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['can_publish']);
        $this->assertFalse($result['can_upload']);
        $this->assertStringContainsString('401', $result['message']);
    }

    public function test_health_check_unhealthy_when_no_publish_permission(): void
    {
        $channel = $this->makeChannel();

        Http::fake(function ($request) {
            $url = $request->url();
            if ($url === 'https://wp.example.test/wp-json') {
                return Http::response([
                    'name' => 'Test WP Site',
                    'namespaces' => ['wp/v2'],
                ], 200);
            }
            // 认证有效，但 capabilities 缺 publish_posts
            if (str_contains($url, '/wp-json/wp/v2/users/me')) {
                return Http::response([
                    'id' => 42,
                    'name' => 'subscriber',
                    'capabilities' => [
                        'read' => true,
                        'edit_posts' => false,
                        'publish_posts' => false,
                        'upload_files' => false,
                    ],
                ], 200);
            }
            return Http::response('{}', 200);
        });

        $publisher = app(\App\Services\Distribution\Publishers\WordPressRestPublisher::class);
        $result = $publisher->health($channel);

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['can_publish']);
        $this->assertFalse($result['can_upload']);
        $this->assertStringContainsString('发布权限', $result['message']);
    }

    public function test_health_check_healthy_with_full_permissions(): void
    {
        $channel = $this->makeChannel();

        Http::fake(function ($request) {
            $url = $request->url();
            if ($url === 'https://wp.example.test/wp-json') {
                return Http::response([
                    'name' => 'My WP Site',
                    'namespaces' => ['wp/v2', 'oembed/1.0'],
                    'generator' => 'WordPress 6.5',
                ], 200);
            }
            // 完整权限
            if (str_contains($url, '/wp-json/wp/v2/users/me')) {
                return Http::response([
                    'id' => 1,
                    'name' => 'admin',
                    'capabilities' => [
                        'edit_posts' => true,
                        'publish_posts' => true,
                        'upload_files' => true,
                        'manage_options' => true,
                    ],
                ], 200);
            }
            return Http::response('{}', 200);
        });

        $publisher = app(\App\Services\Distribution\Publishers\WordPressRestPublisher::class);
        $result = $publisher->health($channel);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['can_publish']);
        $this->assertTrue($result['can_upload']);
        $this->assertSame('My WP Site', $result['site_name']);
    }

    // ─── Helpers ───

    private function makeChannel(): PublishingChannel
    {
        $tenant = Tenant::query()->create([
            'subdomain' => 'wp-sync-test',
            'name' => 'WP Sync Test',
            'plan' => 'points',
            'status' => 'active',
        ]);

        return PublishingChannel::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'WP Test Channel',
            'type' => PublishingChannel::TYPE_WORDPRESS_REST,
            'endpoint_url' => 'https://wp.example.test',
            'auth_type' => 'wordpress_application_password',
            'username' => 'admin',
            'encrypted_secret' => 'test-password',
            'is_active' => true,
        ]);
    }

    private function setupTenant(string $subdomain = 'wp-e2e'): array
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
                'title' => 'WP Media Sync Test',
                'body_markdown' => '# Test',
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
