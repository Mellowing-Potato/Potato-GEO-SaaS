<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\UrlImport;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 工作台新入口的权限与租户隔离（计划 §5 验收标准第二条：
 * "权限和租户隔离与后端路由一致"）。
 *
 * 2026-09-21 把 URL 导入、文章分类、API 令牌、平台审计/安全设置补进正式界面。
 * 前端补入口时最容易出的问题是"界面能打开、接口按另一套口径放行"：
 * 例如把 category:manage 的能力做成 member 可见可点，或者列表接口漏了租户作用域。
 * 这个测试直接打真实 API，把两侧口径钉在一起：
 *
 *   1. API 令牌：只认 team:manage（member 全禁），且列表/吊销按用户隔离
 *      —— 看不到、也吊销不了别人的令牌；
 *   2. 文章分类：读要 project:view（member 可读），写要 category:manage（member 禁）；
 *   3. URL 导入：读要 article:view（member 可读），提交要 article:create（member 禁）；
 *   4. 跨租户：拿别的租户的 url-import / category id 一律 404（不是 403，不泄露存在性）；
 *   5. 平台审计与安全设置：只有 platform_admin，租户 admin 也是 403。
 */
class WorkbenchFeaturePermissionParityTest extends TestCase
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

    // ── 1. API 令牌：只有 team:manage，且按用户隔离 ──

    public function test_api_tokens_require_team_manage_and_are_scoped_to_the_current_user(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('perm-tokens', 'admin');
        $member = $this->makeUser($tenant, 'member');

        // member：列表 / 创建 / 吊销全禁
        Sanctum::actingAs($member, ['*']);
        $this->forTenant($tenant)->getJson('/api/v1/api-tokens')->assertStatus(403);
        $this->forTenant($tenant)->postJson('/api/v1/api-tokens', [
            'name' => 'member-token',
            'scopes' => ['articles:read'],
        ])->assertStatus(403);
        $this->forTenant($tenant)->deleteJson('/api/v1/api-tokens/1')->assertStatus(403);

        // admin：能签，明文只回一次
        Sanctum::actingAs($admin, ['*']);
        $created = $this->forTenant($tenant)->postJson('/api/v1/api-tokens', [
            'name' => 'admin-token',
            'scopes' => ['articles:read', 'categories:write'],
        ])->assertStatus(201);

        $tokenId = $created->json('record.id');
        $this->assertIsInt($tokenId);
        $this->assertIsString($created->json('token'));
        $this->assertNotSame('', (string) $created->json('token'));

        // 另一个租户的 admin 看不到这条令牌（列表按 tokenable 隔离）
        [$otherTenant, $otherAdmin] = $this->makeTenantUser('perm-tokens-other', 'admin');
        Sanctum::actingAs($otherAdmin, ['*']);
        $otherList = $this->forTenant($otherTenant)->getJson('/api/v1/api-tokens')->assertStatus(200);
        $this->assertSame([], $otherList->json('data'));

        // 也吊销不了别人的令牌（404，不是 200）
        $this->forTenant($otherTenant)
            ->deleteJson("/api/v1/api-tokens/{$tokenId}")
            ->assertStatus(404);
    }

    // ── 2. 文章分类：读 project:view，写 category:manage ──

    public function test_categories_split_read_and_write_permissions(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('perm-categories', 'admin');
        $member = $this->makeUser($tenant, 'member');
        $editor = $this->makeUser($tenant, 'editor');
        $this->makeProject($tenant, $admin);

        Sanctum::actingAs($admin, ['*']);
        $this->forTenant($tenant)->postJson('/api/v1/categories', ['name' => '行业解读'])
            ->assertStatus(201);

        // member：能读（归类展示需要），不能写
        Sanctum::actingAs($member, ['*']);
        $this->forTenant($tenant)->getJson('/api/v1/categories')->assertStatus(200);
        $this->forTenant($tenant)->postJson('/api/v1/categories', ['name' => 'member-分类'])
            ->assertStatus(403);

        // editor：能写（与后端 category:manage 矩阵一致）
        Sanctum::actingAs($editor, ['*']);
        $this->forTenant($tenant)->postJson('/api/v1/categories', ['name' => '编辑-分类'])
            ->assertStatus(201);
    }

    // ── 3. URL 导入：读 article:view，提交 article:create ──

    public function test_url_imports_split_read_and_create_permissions(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('perm-url', 'admin');
        $member = $this->makeUser($tenant, 'member');
        $editor = $this->makeUser($tenant, 'editor');
        $project = $this->makeProject($tenant, $admin);

        Sanctum::actingAs($member, ['*']);
        $this->forTenant($tenant)->getJson('/api/v1/url-imports')->assertStatus(200);
        $this->forTenant($tenant)->postJson('/api/v1/url-imports', [
            'url' => 'https://example.test/member',
            'project_id' => $project->id,
        ])->assertStatus(403);

        Sanctum::actingAs($editor, ['*']);
        $this->forTenant($tenant)->postJson('/api/v1/url-imports', [
            'url' => 'https://example.test/editor',
            'project_id' => $project->id,
        ])->assertStatus(201);
    }

    // ── 4. 跨租户：别人的 id 一律 404 ──

    public function test_cross_tenant_ids_are_not_reachable(): void
    {
        [$tenantA, $adminA] = $this->makeTenantUser('perm-iso-a', 'admin');
        $projectA = $this->makeProject($tenantA, $adminA);

        [$tenantB, $adminB] = $this->makeTenantUser('perm-iso-b', 'admin');
        $projectB = $this->makeProject($tenantB, $adminB);

        // B 租户的数据
        $urlImportB = UrlImport::query()->create([
            'tenant_id' => $tenantB->id,
            'project_id' => $projectB->id,
            'url' => 'https://example.test/b',
            'status' => 'pending',
        ]);
        $categoryB = Category::query()->create([
            'tenant_id' => $tenantB->id,
            'name' => 'B 租户分类',
            'sort_order' => 0,
        ]);

        // A 租户的 admin 拿 B 的 id
        Sanctum::actingAs($adminA, ['*']);
        $this->forTenant($tenantA)
            ->getJson("/api/v1/url-imports/{$urlImportB->id}")
            ->assertStatus(404);
        $this->forTenant($tenantA)
            ->getJson("/api/v1/categories/{$categoryB->id}")
            ->assertStatus(404);
        $this->forTenant($tenantA)
            ->deleteJson("/api/v1/categories/{$categoryB->id}")
            ->assertStatus(404);

        // 而且 A 的列表里看不到 B 的数据
        $this->assertSame([], $this->forTenant($tenantA)->getJson('/api/v1/url-imports')->json('data'));
        $this->assertSame([], $this->forTenant($tenantA)->getJson('/api/v1/categories')->json('data'));

        // B 的数据仍在（隔离 ≠ 隐式删除）
        $this->assertDatabaseHas('url_imports', ['id' => $urlImportB->id, 'tenant_id' => $tenantB->id]);
        $this->assertDatabaseHas('categories', ['id' => $categoryB->id, 'tenant_id' => $tenantB->id]);

        // project_id 也不能跨租户使用（UrlImportController 显式校验）
        Sanctum::actingAs($adminA, ['*']);
        $this->forTenant($tenantA)->postJson('/api/v1/url-imports', [
            'url' => 'https://example.test/cross-project',
            'project_id' => $projectB->id,
        ])->assertStatus(404);
    }

    // ── 5. 平台审计/安全设置：租户 admin 也要 403 ──

    public function test_platform_audit_and_security_settings_are_platform_admin_only(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('perm-platform', 'admin');

        Sanctum::actingAs($admin, ['*']);
        $this->getJson('/api/v1/platform/audit')->assertStatus(403);
        $this->getJson('/api/v1/platform/security-settings')->assertStatus(403);
        $this->putJson('/api/v1/platform/security-settings', ['password_min_length' => 10])
            ->assertStatus(403);

        // platform_admin 才放行（这里只验读，写入本身会落审计日志）
        $platformAdmin = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'platform_admin',

            'phone' => '13800000022',
        ]);
        Sanctum::actingAs($platformAdmin, ['*']);
        $this->getJson('/api/v1/platform/audit')->assertStatus(200);
        $this->getJson('/api/v1/platform/security-settings')->assertStatus(200);
    }

    // ── Helpers ──

    private function makeTenantUser(string $subdomain, string $role): array
    {
        $tenant = Tenant::query()->create([
            'subdomain' => $subdomain,
            'name' => strtoupper($subdomain),
            'plan' => 'basic',
            'status' => 'active',
        ]);

        return [$tenant, $this->makeUser($tenant, $role)];
    }

    private function makeUser(Tenant $tenant, string $role): User
    {
        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => $role,

            'phone' => \Tests\Support\FixtureIdentity::phone($tenant->subdomain.$role),
        ]);
    }

    private function makeProject(Tenant $tenant, User $owner): Project
    {
        TenantContext::set($tenant);
        $project = Project::create([
            'name' => '权限测试项目',
            'owner_id' => $owner->id,
            'status' => 'active',
        ]);
        TenantContext::forget();

        return $project;
    }

    private function forTenant(Tenant $tenant): self
    {
        return $this
            ->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }
}
