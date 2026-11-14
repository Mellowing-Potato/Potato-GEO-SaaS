<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Api\ApiTokenService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * 阶段 4 缺口补齐验收测试：
 *  - 缺口①：微信富文本 HTML 导出（复刻 GEOFlow WeChatArticleHtmlExporter）
 *  - 缺口②：作用域 API Token（复刻 GEOFlow ApiTokenService + EnsureApiScope）
 *
 * 复刻目标是 GEOFlow；本文件只验证 geo-saas 侧的实现正确性，不再重复 GEOFlow 自身的测试。
 */
class Stage4GapWeChatExportAndApiTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.tenant_domain' => 'geo.example.test']);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();

        parent::tearDown();
    }

    // ───────────────────────── 缺口①：微信导出 ─────────────────────────

    public function test_wechat_export_renders_paste_ready_html(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('wechat-tenant');
        $project = $this->createProjectFor($tenant, $admin);
        $article = $this->createArticleFor($tenant, $project, $admin, [
            'body_markdown' => "# 微信导出测试\n\n这是一段 **加粗** 与 [链接](https://example.com) 的内容。\n\n- 列表项一\n- 列表项二",
        ]);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->forTenant($tenant)
            ->getJson("/api/v1/articles/{$article->id}/export/wechat");

        $response->assertOk();
        $response->assertJsonStructure(['object', 'article_id', 'html', 'plain_text']);

        $html = $response->json('html');
        // 微信粘贴标记
        $this->assertStringContainsString('data-geo-export="wechat-article"', $html);
        // 容器内联基础样式（微信编辑器兼容）
        $this->assertStringContainsString('max-width:677px', $html);
        // Markdown 已被渲染为富文本标签
        $this->assertStringContainsString('<h1', $html);
        $this->assertStringContainsString('<strong', $html);
        $this->assertStringContainsString('<a ', $html);
        // 外链被强制新窗口打开（微信安全规范）
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);

        // 纯文本视图非空且保留正文
        $this->assertNotEmpty($response->json('plain_text'));
        $this->assertStringContainsString('微信导出测试', $response->json('plain_text'));
    }

    public function test_wechat_export_enforces_tenant_isolation(): void
    {
        [$tenantA, $adminA] = $this->createTenantAdmin('wx-tenant-a');
        [$tenantB, $adminB] = $this->createTenantAdmin('wx-tenant-b');
        $projectA = $this->createProjectFor($tenantA, $adminA);
        $articleA = $this->createArticleFor($tenantA, $projectA, $adminA);

        // 租户 B 的管理员尝试导出租户 A 的文章 → 跨租户隔离（404）
        Sanctum::actingAs($adminB, ['*']);

        $this->forTenant($tenantB)
            ->getJson("/api/v1/articles/{$articleA->id}/export/wechat")
            ->assertNotFound();
    }

    public function test_wechat_export_requires_articles_read_scope(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('wx-scope-tenant');
        $project = $this->createProjectFor($tenant, $admin);
        $article = $this->createArticleFor($tenant, $project, $admin);

        // 持有 analytics:read 的 Token（无 articles:read）→ 作用域中间件拒绝
        Sanctum::actingAs($admin, ['analytics:read']);

        $this->forTenant($tenant)
            ->getJson("/api/v1/articles/{$article->id}/export/wechat")
            ->assertForbidden();
    }

    // ───────────────────── 缺口②：作用域 API Token ─────────────────────

    public function test_api_token_store_creates_scoped_token(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('token-tenant');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->forTenant($tenant)
            ->postJson('/api/v1/api-tokens', [
                'name' => 'CI 集成',
                'scopes' => ['articles:read', 'articles:write'],
                'expires_at' => null,
            ]);

        $response->assertCreated();
        $response->assertJsonStructure(['object', 'token', 'record' => ['id', 'name', 'scopes', 'status']]);
        // 明文 Token 仅在此一次性回显
        $this->assertNotEmpty($response->json('token'));
        $this->assertSame('CI 集成', $response->json('record.name'));
        $this->assertSame(['articles:read', 'articles:write'], $response->json('record.scopes'));
    }

    public function test_api_token_index_lists_own_tokens_and_available_scopes(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('token-index-tenant');
        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->postJson('/api/v1/api-tokens', [
                'name' => 'list-test',
                'scopes' => ['articles:read'],
                'expires_at' => null,
            ])
            ->assertCreated();

        $response = $this->forTenant($tenant)->getJson('/api/v1/api-tokens');

        $response->assertOk();
        $response->assertJsonStructure(['object', 'data', 'available_scopes', 'default_expires_at']);
        $this->assertIsArray($response->json('data'));
        $this->assertNotEmpty($response->json('data'));
        $this->assertContains('articles:read', $response->json('available_scopes'));
        $this->assertContains('articles:write', $response->json('available_scopes'));
    }

    public function test_api_token_revoke_removes_token(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('token-revoke-tenant');
        Sanctum::actingAs($admin, ['*']);

        $created = $this->forTenant($tenant)
            ->postJson('/api/v1/api-tokens', [
                'name' => 'revoke-test',
                'scopes' => ['articles:read'],
                'expires_at' => null,
            ])
            ->assertCreated();

        $tokenId = $created->json('record.id');

        $this->forTenant($tenant)
            ->deleteJson("/api/v1/api-tokens/{$tokenId}")
            ->assertOk()
            ->assertJson(['message' => 'Token 已吊销。']);

        $after = $this->forTenant($tenant)->getJson('/api/v1/api-tokens');
        $ids = array_column($after->json('data') ?? [], 'id');
        $this->assertNotContains($tokenId, $ids);
    }

    public function test_api_token_revoke_is_self_isolated_across_admins(): void
    {
        [$tenant] = $this->createTenantAdmin('token-iso-tenant');
        $adminA = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'admin',
        ]);
        $adminB = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'admin',
        ]);

        // adminA 创建自己的 Token
        Sanctum::actingAs($adminA, ['*']);
        $created = $this->forTenant($tenant)
            ->postJson('/api/v1/api-tokens', [
                'name' => 'adminA-token',
                'scopes' => ['articles:read'],
                'expires_at' => null,
            ])
            ->assertCreated();
        $tokenId = $created->json('record.id');

        // adminB（同为 admin，可通过 can:team:manage）尝试吊销 adminA 的 Token → 不属于自己 → 404
        Sanctum::actingAs($adminB, ['*']);
        $this->forTenant($tenant)
            ->deleteJson("/api/v1/api-tokens/{$tokenId}")
            ->assertNotFound();
    }

    public function test_external_read_token_can_read_but_not_write_articles(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('ext-read-tenant');
        $project = $this->createProjectFor($tenant, $admin);
        $article = $this->createArticleFor($tenant, $project, $admin);

        // 直接签发仅有 articles:read 的真实作用域 Token（不走 actingAs，避免测试态泄漏导致 scope 被绕过）
        $service = $this->app->make(ApiTokenService::class);
        $created = $service->createToken($admin, 'read-only', ['articles:read'], null);
        $plain = $created['token'];

        // 只读 Token 可读取文章列表
        $this->withToken($plain)
            ->forTenant($tenant)
            ->getJson('/api/v1/articles')
            ->assertOk();

        // 只读 Token 不可写文章（缺少 articles:write scope）→ 403
        $this->withToken($plain)
            ->forTenant($tenant)
            ->postJson('/api/v1/articles', ['title' => 'x'])
            ->assertForbidden();
    }

    public function test_external_write_token_passes_scope_on_write_endpoint(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('ext-write-tenant');
        $project = $this->createProjectFor($tenant, $admin);
        $article = $this->createArticleFor($tenant, $project, $admin);

        // 直接签发仅有 articles:write 的真实作用域 Token
        $service = $this->app->make(ApiTokenService::class);
        $created = $service->createToken($admin, 'write-only', ['articles:write'], null);
        $plain = $created['token'];

        // 持有 articles:write 的 Token 调用写接口（PATCH）应通过作用域中间件（非 403）。
        // 业务校验可能返回 422，但作用域本身已放行。
        $writeResponse = $this->withToken($plain)
            ->forTenant($tenant)
            ->patchJson("/api/v1/articles/{$article->id}", ['title' => '改个标题']);
        $this->assertNotSame(403, $writeResponse->status());

        // 反向校验：同一 Token 调用读接口（需要 articles:read）应被拒绝
        $this->withToken($plain)
            ->forTenant($tenant)
            ->getJson('/api/v1/articles')
            ->assertForbidden();
    }

    public function test_wildcard_token_bypasses_scope_for_backwards_compat(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('wildcard-tenant');
        $project = $this->createProjectFor($tenant, $admin);

        // SPA 全权限 Token（通配 ability）调用带 scope 的写接口不应被作用域中间件拦截
        Sanctum::actingAs($admin, ['*']);
        $wildcardResponse = $this->forTenant($tenant)
            ->postJson('/api/v1/articles', ['title' => 'x']);
        $this->assertNotSame(403, $wildcardResponse->status());
    }

    public function test_expired_api_token_is_rejected(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('expired-tenant');
        $project = $this->createProjectFor($tenant, $admin);

        // 直接签发一个已过期的真实 Token
        $tokenResult = $admin->createToken('expired', ['articles:read'], now()->subDay());
        $plain = $tokenResult->plainTextToken;

        // Sanctum 守卫应拒绝过期 Token → 401
        $this->withToken($plain)
            ->forTenant($tenant)
            ->getJson('/api/v1/articles')
            ->assertUnauthorized();
    }

    public function test_service_revoke_throws_when_token_belongs_to_another_user(): void
    {
        [$tenant] = $this->createTenantAdmin('svc-iso-tenant');
        $userA = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        $userB = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);

        $tokenResult = $userA->createToken('only-a', ['articles:read']);
        $tokenId = $tokenResult->accessToken->id;

        $service = $this->app->make(ApiTokenService::class);

        $this->expectException(NotFoundHttpException::class);
        $service->revokeTokenForUser($userB, $tokenId);
    }

    // ───────────────────────────── helpers ─────────────────────────────

    private function createTenantAdmin(string $subdomain): array
    {
        $tenant = Tenant::query()->create([
            'subdomain' => $subdomain,
            'name' => strtoupper($subdomain),
            'plan' => 'basic',
            'status' => 'active',
        ]);

        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain),
            'role' => 'admin',
        ]);

        return [$tenant, $admin];
    }

    private function createProjectFor(Tenant $tenant, User $owner, ?string $name = null): Project
    {
        TenantContext::set($tenant);

        try {
            return Project::query()->create([
                'owner_id' => $owner->id,
                'name' => $name ?? $tenant->name.' Project',
                'brand_name' => $tenant->name,
                'brand_url' => 'https://'.$tenant->subdomain.'.example.test',
                'settings' => [],
            ]);
        } finally {
            TenantContext::forget();
        }
    }

    private function createArticleFor(Tenant $tenant, Project $project, User $author, array $overrides = []): Article
    {
        TenantContext::set($tenant);

        try {
            return Article::query()->create(array_merge([
                'project_id' => $project->id,
                'author_id' => $author->id,
                'title' => 'Stage4 gap article',
                'body_markdown' => '# Stage4',
                'summary' => 'stage4',
                'content_type' => 'general',
                'status' => 'approved',
                'source_type' => 'geo_analysis',
                'source_skill' => Project::GEO_ANALYSIS_SKILL_ID,
                'version' => 1,
                'word_count' => 8,
                'metadata' => ['structured' => ['summary' => 'stage4']],
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
