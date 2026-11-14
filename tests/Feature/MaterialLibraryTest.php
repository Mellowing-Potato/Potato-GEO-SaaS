<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\ImageLibrary;
use App\Models\KeywordLibrary;
use App\Models\Tenant;
use App\Models\TitleLibrary;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MaterialLibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.tenant_domain' => 'geo.example.test']);
    }

    // ─── 标题库 CRUD ───
    public function test_admin_can_create_and_list_titles(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');

        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->postJson('/api/v1/materials/titles', [
                'title' => '10 个提升 GEO 可见度的技巧',
                'category' => 'listicle',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.title', '10 个提升 GEO 可见度的技巧');

        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->getJson('/api/v1/materials/titles')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_editor_can_manage_materials_but_member_cannot(): void
    {
        [$tenant, $editor] = $this->makeTenantUser('tenant-a', 'editor');

        // 同一租户内的 member（仅做权限验证，subdomain 不可重复）
        $member = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => \Tests\Support\FixtureIdentity::phone('tenant-a_member'),
            'role' => 'member',
        ]);

        Sanctum::actingAs($editor, ['*']);

        $this->forTenant($tenant)
            ->postJson('/api/v1/materials/titles', ['title' => 'E 标题'])
            ->assertStatus(201);

        Sanctum::actingAs($member, ['*']);

        $this->forTenant($tenant)
            ->postJson('/api/v1/materials/titles', ['title' => 'M 标题'])
            ->assertStatus(403);
    }

    // ─── 租户隔离 ───
    public function test_materials_are_tenant_isolated(): void
    {
        [$tenantA, $adminA] = $this->makeTenantUser('tenant-a', 'admin');
        [$tenantB, $adminB] = $this->makeTenantUser('tenant-b', 'admin');

        TenantContext::set($tenantA);
        $titleA = TitleLibrary::query()->create([
            'tenant_id' => $tenantA->id,
            'title' => '仅 A 可见',
        ]);
        TenantContext::forget();

        // B 看不到 A 的标题
        Sanctum::actingAs($adminB, ['*']);

        $this->forTenant($tenantB)
            ->getJson('/api/v1/materials/titles')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        // B 直接访问 A 的标题 → 404
        Sanctum::actingAs($adminB, ['*']);

        $this->forTenant($tenantB)
            ->getJson('/api/v1/materials/titles/' . $titleA->id)
            ->assertStatus(404);
    }

    // ─── 四库全可用 ───
    public function test_all_four_libraries_are_crudable(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');

        $call = function (string $type, array $payload) use ($tenant, $admin): int {
            Sanctum::actingAs($admin, ['*']);

            return (int) $this->forTenant($tenant)
                ->postJson("/api/v1/materials/{$type}", $payload)
                ->assertStatus(201)
                ->json('data.id');
        };

        $titleId = $call('titles', ['title' => 'T']);
        $keywordId = $call('keywords', ['keyword' => 'GEO']);
        $imageId = $call('images', ['url' => 'https://cdn.example.com/x.png']);
        $authorId = $call('authors', ['name' => '张三']);

        $this->assertNotNull($titleId);
        $this->assertNotNull($keywordId);
        $this->assertNotNull($imageId);
        $this->assertNotNull($authorId);

        // 更新
        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->patchJson("/api/v1/materials/keywords/{$keywordId}", ['search_volume' => 1200])
            ->assertStatus(200)
            ->assertJsonPath('data.search_volume', 1200);

        // 删除
        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->deleteJson("/api/v1/materials/authors/{$authorId}")
            ->assertStatus(200);

        $this->assertSame(0, Author::query()->count());
        $this->assertSame(1, TitleLibrary::query()->count());
        $this->assertSame(1, KeywordLibrary::query()->count());
        $this->assertSame(1, ImageLibrary::query()->count());
    }

    // ─── pick + recordUsage（技能 brief 接入）───
    public function test_pick_returns_materials_and_record_usage_bumps_count(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');

        TenantContext::set($tenant);
        $t1 = TitleLibrary::query()->create(['tenant_id' => $tenant->id, 'title' => 'A']);
        $t2 = TitleLibrary::query()->create(['tenant_id' => $tenant->id, 'title' => 'B']);
        $k1 = KeywordLibrary::query()->create(['tenant_id' => $tenant->id, 'keyword' => 'GEO']);
        TenantContext::forget();

        Sanctum::actingAs($admin, ['*']);

        $pick = $this->forTenant($tenant)
            ->postJson('/api/v1/materials/pick', ['titles' => 2, 'keywords' => 1])
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(2, $pick['titles']);
        $this->assertCount(1, $pick['keywords']);

        // 记录使用
        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->postJson('/api/v1/materials/record-usage', [
                'refs' => [
                    ['type' => 'title', 'id' => $t1->id],
                    ['type' => 'keyword', 'id' => $k1->id],
                ],
            ])
            ->assertStatus(200);

        $this->assertSame(1, (int) $t1->fresh()->usage_count);
        $this->assertSame(1, (int) $k1->fresh()->usage_count);
        $this->assertSame(0, (int) $t2->fresh()->usage_count);
    }

    // ─── 内部辅助 ───
    private function makeTenantUser(string $subdomain, string $role): array
    {
        $tenant = Tenant::query()->create([
            'subdomain' => $subdomain,
            'name' => strtoupper($subdomain),
            'plan' => 'basic',
            'status' => 'active',
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain . $role),
            'role' => $role,
        ]);

        return [$tenant, $user];
    }

    private function forTenant(Tenant $tenant): self
    {
        return $this
            ->withServerVariables(['HTTP_HOST' => $tenant->subdomain . '.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }
}
