<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticlePublication;
use App\Models\Project;
use App\Models\PublishingChannel;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ArticlePublishingFlowTest extends TestCase
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

    public function test_tenant_can_create_publishing_channel_without_leaking_secret(): void
    {
        [$tenant, $admin] = $this->tenantAdmin('publish-channel');
        Sanctum::actingAs($admin, ['*']);

        $response = $this
            ->forTenant($tenant)
            ->postJson('/api/v1/publishing/channels', [
                'name' => 'Primary Webhook',
                'type' => 'http_webhook',
                'endpoint_url' => 'https://hooks.example.test/geo',
                'auth_type' => 'bearer',
                'secret' => 'test-fake-fa7c7186',
                'headers' => ['X-Source' => 'geo-saas'],
            ])
            ->assertCreated()
            ->assertJsonPath('object', 'publishing_channel')
            ->assertJsonPath('channel.secret_present', true);

        $this->assertStringNotContainsString('test-fake-fa7c7186', $response->getContent());

        $channel = PublishingChannel::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($tenant->id, $channel->tenant_id);
        $this->assertSame('test-fake-fa7c7186', $channel->secret());
    }

    public function test_approved_article_publishes_to_http_webhook_and_records_status(): void
    {
        [$tenant, $admin, $project] = $this->tenantProject('publish-webhook');
        $article = $this->article($tenant, $project, $admin, ['status' => 'approved']);
        $channel = $this->channel($tenant, [
            'name' => 'Webhook',
            'type' => PublishingChannel::TYPE_HTTP_WEBHOOK,
            'endpoint_url' => 'https://hooks.example.test/publish',
            'auth_type' => 'bearer',
            'encrypted_secret' => 'test-fake-758099ef',
        ]);
        Sanctum::actingAs($admin, ['*']);

        Http::fake([
            'https://hooks.example.test/publish' => Http::response([
                'remote_id' => 'remote-article-001',
                'remote_url' => 'https://target.example.test/articles/remote-article-001',
                'status' => 'accepted',
            ], 202),
        ]);

        $response = $this
            ->forTenant($tenant)
            ->postJson("/api/v1/articles/{$article->id}/publish", [
                'channel_id' => $channel->id,
            ])
            ->assertCreated()
            ->assertJsonPath('publication.status', 'accepted')
            ->assertJsonPath('publication.attempt_count', 1)
            ->assertJsonPath('publication.remote_id', 'remote-article-001');

        $this->assertStringNotContainsString('test-fake-758099ef', $response->getContent());
        $this->assertDatabaseHas('article_publications', [
            'tenant_id' => $tenant->id,
            'article_id' => $article->id,
            'publishing_channel_id' => $channel->id,
            'status' => 'accepted',
            'attempt_count' => 1,
        ]);

        Http::assertSent(function ($request) use ($article) {
            return $request->url() === 'https://hooks.example.test/publish'
                && $request->header('Authorization')[0] === 'Bearer test-fake-758099ef'
                && $request['object'] === 'geo_article_publication'
                && $request['article']['id'] === $article->id
                && $request['article']['title'] === $article->title;
        });
    }

    public function test_hosted_page_channel_is_rejected_even_for_existing_records(): void
    {
        [$tenant, $admin, $project] = $this->tenantProject('hosted-publish');
        $article = $this->article($tenant, $project, $admin, [
            'status' => Article::STATUS_APPROVED,
            'title' => '示例李白文化体验指南',
            'body_markdown' => "# 示例李白文化体验指南\n\n这是一篇站内托管测试文章。",
        ]);
        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)->postJson('/api/v1/publishing/channels', [
                'name' => '站内演示发布',
                'type' => PublishingChannel::TYPE_HOSTED_PAGE,
            ])
            ->assertUnprocessable();

        $channel = $this->channel($tenant, [
            'name' => '历史站内渠道',
            'type' => PublishingChannel::TYPE_HOSTED_PAGE,
            'endpoint_url' => 'https://app.example.test/read',
        ]);

        $this
            ->forTenant($tenant)
            ->getJson("/api/v1/publishing/channels/{$channel->id}/health")
            ->assertStatus(410)
            ->assertJsonPath('healthy', false);

        $this
            ->forTenant($tenant)
            ->postJson("/api/v1/articles/{$article->id}/publish-async", [
                'channel_id' => $channel->id,
            ])
            ->assertUnprocessable();

        $this->forTenant($tenant)
            ->postJson("/api/v1/articles/{$article->id}/publish", ['channel_id' => $channel->id])
            ->assertUnprocessable();
        $this->assertSame(Article::STATUS_APPROVED, $article->fresh()->status);
        $this->assertDatabaseCount('article_publications', 0);
        $this->get("/read/{$tenant->subdomain}/article-{$article->id}")->assertStatus(410);

        Http::assertNothingSent();
    }

    public function test_platform_article_site_files_and_reading_pages_are_retired(): void
    {
        [$tenant, $admin, $project] = $this->tenantProject('site-files');
        config(['app.url' => 'https://app.example.test']);
        Http::fake(['https://app.example.test/*' => Http::response('', 404)]);

        $this->article($tenant, $project, $admin, [
            'status' => Article::STATUS_PUBLISHED,
            'slug' => 'public-guide',
            'published_at' => now(),
        ]);
        $this->article($tenant, $project, $admin, [
            'status' => Article::STATUS_PUBLISHED,
            'slug' => 'private-guide',
            'published_at' => now(),
            'metadata' => ['visibility' => 'private'],
        ]);
        $this->article($tenant, $project, $admin, [
            'status' => Article::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->getJson('/api/v1/publishing/robots-txt')
            ->assertOk()
            ->assertJsonPath('content', file_get_contents(public_path('robots.txt')))
            ->assertJsonPath('live_check.state', 'mismatch');

        $this->forTenant($tenant)->postJson('/api/v1/publishing/sitemap')->assertStatus(410);
        $this->get('/sitemap.xml')->assertStatus(410)->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get("/sitemaps/{$tenant->subdomain}.xml")->assertStatus(410);
        $this->get("/read/{$tenant->subdomain}/public-guide")
            ->assertStatus(410)
            ->assertDontSee('public-guide');
    }

    public function test_wordpress_channel_posts_to_rest_endpoint_with_application_password(): void
    {
        [$tenant, $admin, $project] = $this->tenantProject('publish-wordpress');
        $article = $this->article($tenant, $project, $admin, ['status' => 'approved']);
        $channel = $this->channel($tenant, [
            'name' => 'WordPress',
            'type' => PublishingChannel::TYPE_WORDPRESS_REST,
            'endpoint_url' => 'https://wp.example.test',
            'auth_type' => 'wordpress_application_password',
            'username' => 'wp-editor',
            'encrypted_secret' => 'test-fake-c63e1f8d',
            'settings' => ['wordpress_status' => 'draft'],
        ]);
        Sanctum::actingAs($admin, ['*']);

        Http::fake([
            'https://wp.example.test/wp-json/wp/v2/posts' => Http::response([
                'id' => 42,
                'link' => 'https://wp.example.test/?p=42',
                'status' => 'draft',
            ], 201),
        ]);

        $this
            ->forTenant($tenant)
            ->postJson("/api/v1/articles/{$article->id}/publish", [
                'channel_id' => $channel->id,
            ])
            ->assertCreated()
            ->assertJsonPath('publication.status', 'accepted')
            ->assertJsonPath('publication.remote_id', '42')
            ->assertJsonPath('publication.remote_url', 'https://wp.example.test/?p=42');

        Http::assertSent(function ($request) use ($article) {
            return $request->url() === 'https://wp.example.test/wp-json/wp/v2/posts'
                && $request->header('Authorization')[0] === 'Basic '.base64_encode('wp-editor:test-fake-c63e1f8d')
                && $request['title'] === $article->title
                && $request['status'] === 'draft';
        });
    }

    public function test_retired_sitemaps_never_return_article_links(): void
    {
        [$tenant, $admin, $projectA] = $this->tenantProject('maps-a');
        TenantContext::set($tenant);
        $projectB = Project::query()->create([
            'owner_id' => $admin->id, 'name' => 'Second project', 'brand_name' => 'Second',
            'brand_url' => 'https://second.example.test', 'settings' => [],
        ]);
        TenantContext::forget();
        $first = $this->article($tenant, $projectA, $admin, ['status' => 'published', 'slug' => 'first']);
        $second = $this->article($tenant, $projectB, $admin, ['status' => 'published', 'slug' => 'second']);
        $this->article($tenant, $projectA, $admin, [
            'status' => 'published', 'slug' => 'external-copy',
            'canonical_url' => 'https://example.test/geo/articles/yanji-geo-workflow/',
        ]);
        Sanctum::actingAs($admin, ['*']);
        config(['app.url' => 'https://app.example.test']);
        Http::fake(['https://app.example.test/*' => Http::response('', 404)]);

        $this->forTenant($tenant)->postJson('/api/v1/publishing/sitemap?project_id='.$projectA->id)->assertStatus(410);
        $this->get("/sitemaps/{$tenant->subdomain}.xml")->assertStatus(410)->assertDontSee('/first');
        $this->get("/sitemaps/{$tenant->subdomain}/projects/{$projectA->id}.xml")
            ->assertStatus(410)->assertDontSee('/first');
        $this->get('/sitemap.xml')->assertStatus(410)->assertDontSee($tenant->subdomain.'.xml');
        $this->get("/read/{$tenant->subdomain}/external-copy")->assertStatus(410);
    }

    public function test_failed_publication_can_retry_on_same_status_record(): void
    {
        [$tenant, $admin, $project] = $this->tenantProject('publish-retry');
        $article = $this->article($tenant, $project, $admin, ['status' => 'approved']);
        $channel = $this->channel($tenant, [
            'name' => 'Retry Webhook',
            'type' => PublishingChannel::TYPE_HTTP_WEBHOOK,
            'endpoint_url' => 'https://hooks.example.test/retry',
            'auth_type' => 'bearer',
            'encrypted_secret' => 'test-fake-683bf46e7',
        ]);
        Sanctum::actingAs($admin, ['*']);

        Http::fakeSequence('https://hooks.example.test/retry')
            ->push([
                'error' => ['message' => 'publish token=test-fake-683bf46e7 failed'],
            ], 500)
            ->push([
                'remote_id' => 'retry-remote-001',
                'remote_url' => 'https://target.example.test/retry-remote-001',
            ], 200);

        $first = $this
            ->forTenant($tenant)
            ->postJson("/api/v1/articles/{$article->id}/publish", [
                'channel_id' => $channel->id,
            ])
            ->assertStatus(502)
            ->assertJsonPath('publication.status', 'failed');

        $this->assertStringNotContainsString('test-fake-683bf46e7', $first->getContent());
        $publication = ArticlePublication::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('failed', $publication->status);
        $this->assertSame(1, $publication->attempt_count);
        $this->assertStringContainsString('[redacted_secret]', $publication->last_error);

        $this
            ->forTenant($tenant)
            ->postJson("/api/v1/articles/{$article->id}/publications/{$publication->id}/retry")
            ->assertOk()
            ->assertJsonPath('publication.status', 'accepted')
            ->assertJsonPath('publication.attempt_count', 2)
            ->assertJsonPath('publication.remote_id', 'retry-remote-001');

        $this->assertSame(1, ArticlePublication::withoutGlobalScopes()->count());
    }

    public function test_cross_tenant_channel_cannot_publish_article(): void
    {
        [$tenantA, $adminA, $projectA] = $this->tenantProject('publish-a');
        [$tenantB] = $this->tenantProject('publish-b');
        $article = $this->article($tenantA, $projectA, $adminA, ['status' => 'approved']);
        $foreignChannel = $this->channel($tenantB, [
            'name' => 'Other Tenant Webhook',
            'type' => PublishingChannel::TYPE_HTTP_WEBHOOK,
            'endpoint_url' => 'https://hooks.example.test/other',
        ]);
        Sanctum::actingAs($adminA, ['*']);
        Http::fake();

        $this
            ->forTenant($tenantA)
            ->postJson("/api/v1/articles/{$article->id}/publish", [
                'channel_id' => $foreignChannel->id,
            ])
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_editor_cannot_manage_channels_or_mint_secrets(): void
    {
        [$tenant] = $this->tenantAdmin('editor-guard');
        $editor = User::factory()->create([
            'tenant_id' => $tenant->id,

            'email' => 'fixture-user-11@example.com',
            'phone' => '13800000021',
            'role' => 'editor',
        ]);
        Sanctum::actingAs($editor, ['*']);

        // 编辑不得新增外发渠道（channel:manage 仅 admin，防止用低权限账号加外泄渠道）
        $this
            ->forTenant($tenant)
            ->postJson('/api/v1/publishing/channels', [
                'name' => 'Editor Channel',
                'type' => 'http_webhook',
                'endpoint_url' => 'https://hooks.example.test/editor',
            ])
            ->assertForbidden();

        $this->assertSame(0, PublishingChannel::withoutGlobalScopes()->count());

        // admin 建的渠道，编辑也不得铸造密钥
        $channel = $this->channel($tenant, ['type' => PublishingChannel::TYPE_GEOFLOW_AGENT]);
        $this
            ->forTenant($tenant)
            ->postJson("/api/v1/publishing/channels/{$channel->id}/secrets")
            ->assertForbidden();
    }

    public function test_channel_endpoint_cannot_target_internal_address(): void
    {
        [$tenant, $admin] = $this->tenantAdmin('ssrf-guard');
        Sanctum::actingAs($admin, ['*']);

        foreach ([
            'http://169.254.169.254/latest/meta-data',
            'http://127.0.0.1/admin',
            'http://10.0.0.5/internal',
            'https://localhost/hook',
        ] as $blocked) {
            $this
                ->forTenant($tenant)
                ->postJson('/api/v1/publishing/channels', [
                    'name' => 'SSRF Attempt',
                    'type' => 'http_webhook',
                    'endpoint_url' => $blocked,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('endpoint_url');
        }

        $this->assertSame(0, PublishingChannel::withoutGlobalScopes()->count());
    }

    public function test_manual_publish_rejects_unapproved_inactive_and_unbound_targets(): void
    {
        [$tenant, $admin, $project] = $this->tenantProject('target-guard');
        $article = $this->article($tenant, $project, $admin, ['status' => 'editing']);
        $channel = $this->channel($tenant);
        Sanctum::actingAs($admin, ['*']);
        Http::fake();

        $path = "/api/v1/articles/{$article->id}/publish";
        $this->forTenant($tenant)->postJson($path, ['channel_id' => $channel->id])
            ->assertUnprocessable()->assertJsonValidationErrors('article_id');

        $article->forceFill(['status' => 'approved'])->saveQuietly();
        $channel->forceFill(['is_active' => false])->save();
        $this->forTenant($tenant)->postJson($path, ['channel_id' => $channel->id])
            ->assertUnprocessable()->assertJsonValidationErrors('channel_id');

        $channel->forceFill(['is_active' => true])->save();
        $bound = $this->channel($tenant, ['name' => 'Bound only']);
        DB::table('project_publishing_channels')->insert([
            'tenant_id' => $tenant->id, 'project_id' => $project->id,
            'publishing_channel_id' => $bound->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->forTenant($tenant)->postJson($path, ['channel_id' => $channel->id])
            ->assertUnprocessable()->assertJsonValidationErrors('channel_id');
        Http::assertNothingSent();
    }

    public function test_accepted_webhook_is_not_public_and_double_click_does_not_resend(): void
    {
        [$tenant, $admin, $project] = $this->tenantProject('webhook-accepted');
        $article = $this->article($tenant, $project, $admin);
        $channel = $this->channel($tenant);
        Sanctum::actingAs($admin, ['*']);
        Http::fake(['https://hooks.example.test/default' => Http::response([
            'id' => 'task-1', 'status' => 'accepted', 'url' => 'https://target.example.test/task-1',
        ], 202)]);

        for ($click = 0; $click < 2; $click++) {
            $this->forTenant($tenant)->postJson("/api/v1/articles/{$article->id}/publish", ['channel_id' => $channel->id])
                ->assertCreated()
                ->assertJsonPath('publication.status', ArticlePublication::STATUS_ACCEPTED)
                ->assertJsonPath('publication.target_state', 'accepted')
                ->assertJsonPath('publication.article_version', $article->fresh()->version);
        }

        $publicationId = ArticlePublication::query()->firstOrFail()->id;
        $this->forTenant($tenant)->postJson("/api/v1/articles/{$article->id}/publications/{$publicationId}/retry")
            ->assertOk()->assertJsonPath('publication.status', ArticlePublication::STATUS_ACCEPTED);

        Http::assertSentCount(1);
        $this->assertSame('approved', $article->fresh()->status);
        $this->assertNull(ArticlePublication::query()->firstOrFail()->published_at);
    }

    public function test_wordpress_draft_retry_updates_same_remote_post_before_public_success(): void
    {
        [$tenant, $admin, $project] = $this->tenantProject('wordpress-draft');
        $article = $this->article($tenant, $project, $admin);
        $channel = $this->channel($tenant, [
            'type' => PublishingChannel::TYPE_WORDPRESS_REST,
            'endpoint_url' => 'https://wp.example.test',
            'auth_type' => 'wordpress_application_password',
            'username' => 'editor', 'encrypted_secret' => 'test-fake-c0',
            'settings' => ['wordpress_status' => 'draft'],
        ]);
        Sanctum::actingAs($admin, ['*']);
        Http::fake([
            'https://wp.example.test/wp-json/wp/v2/posts' => Http::response([
                'id' => 73, 'link' => 'https://wp.example.test/?p=73', 'status' => 'draft',
            ], 201),
            'https://wp.example.test/wp-json/wp/v2/posts/73' => Http::response([
                'id' => 73, 'link' => 'https://wp.example.test/?p=73', 'status' => 'publish',
            ], 200),
        ]);

        $first = $this->forTenant($tenant)->postJson("/api/v1/articles/{$article->id}/publish", ['channel_id' => $channel->id])
            ->assertCreated()->assertJsonPath('publication.status', 'accepted');
        $publicationId = $first->json('publication.id');
        $this->assertSame('approved', $article->fresh()->status);
        $channel->forceFill(['settings' => ['wordpress_status' => 'publish']])->save();

        $this->forTenant($tenant)->postJson("/api/v1/articles/{$article->id}/publications/{$publicationId}/retry")
            ->assertOk()->assertJsonPath('publication.status', 'published')
            ->assertJsonPath('publication.remote_id', '73');
        $this->assertSame('published', $article->fresh()->status);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && $request->url() === 'https://wp.example.test/wp-json/wp/v2/posts/73');
    }

    public function test_queued_publication_rejects_a_different_reviewed_version(): void
    {
        [$tenant, $admin, $project] = $this->tenantProject('queued-version');
        $article = $this->article($tenant, $project, $admin, ['version' => 2]);
        $channel = $this->channel($tenant);
        $publication = ArticlePublication::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'article_id' => $article->id,
            'publishing_channel_id' => $channel->id, 'status' => 'queued',
            'metadata' => ['article_version' => 1],
        ]);
        Http::fake();
        TenantContext::set($tenant);
        $result = app(\App\Services\Publishing\ArticlePublicationService::class)->executePublish($publication);
        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString('版本已变化', $result->last_error);
        Http::assertNothingSent();
    }

    public function test_publication_completion_does_not_approve_a_concurrently_edited_draft(): void
    {
        [$tenant, $admin, $project] = $this->tenantProject('sending-version');
        $article = $this->article($tenant, $project, $admin, ['version' => 1]);
        $channel = $this->channel($tenant);
        Http::fake(function () use ($article) {
            Article::withoutGlobalScopes()->whereKey($article->id)->update(['version' => 2, 'status' => 'draft']);
            return Http::response(['status' => 'published', 'remote_url' => 'https://target.example.test/v1'], 200);
        });
        TenantContext::set($tenant);
        $result = app(\App\Services\Publishing\ArticlePublicationService::class)->publish($article, $channel, $admin);
        $this->assertSame('published', $result->status);
        $this->assertSame(1, $result->metadata['article_version']);
        $this->assertSame('draft', $article->fresh()->status);
        $this->assertSame(2, $article->fresh()->version);
    }

    private function tenantProject(string $subdomain): array
    {
        [$tenant, $admin] = $this->tenantAdmin($subdomain);

        TenantContext::set($tenant);
        try {
            $project = Project::query()->create([
                'owner_id' => $admin->id,
                'name' => strtoupper($subdomain).' Project',
                'brand_name' => strtoupper($subdomain),
                'brand_url' => 'https://'.$subdomain.'.example.test',
                'settings' => [],
            ]);
        } finally {
            TenantContext::forget();
        }

        return [$tenant, $admin, $project];
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

            'email' => $subdomain.'@example.com',
            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain),
            'role' => 'admin',
        ]);

        return [$tenant, $admin];
    }

    private function article(Tenant $tenant, Project $project, User $author, array $overrides = []): Article
    {
        TenantContext::set($tenant);

        try {
            return Article::query()->create(array_merge([
                'project_id' => $project->id,
                'author_id' => $author->id,
                'title' => 'GEO Distribution Article',
                'body_markdown' => '# GEO Distribution Article',
                'summary' => 'Distribution summary',
                'content_type' => 'general',
                'status' => 'approved',
                'source_type' => 'manual',
                'metadata' => [],
            ], $overrides));
        } finally {
            TenantContext::forget();
        }
    }

    private function channel(Tenant $tenant, array $overrides = []): PublishingChannel
    {
        return PublishingChannel::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $tenant->id,
            'name' => 'Default Webhook',
            'type' => PublishingChannel::TYPE_HTTP_WEBHOOK,
            'endpoint_url' => 'https://hooks.example.test/default',
            'auth_type' => 'none',
            'is_active' => true,
        ], $overrides));
    }

    private function forTenant(Tenant $tenant): self
    {
        return $this
            ->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }
}
