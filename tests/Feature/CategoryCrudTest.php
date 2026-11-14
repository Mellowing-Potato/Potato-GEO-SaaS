<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CategoryCrudTest extends TestCase
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

    public function test_admin_can_create_and_list_categories(): void
    {
        [$tenant, $admin] = $this->tenantAndUser('cat-a', 'admin');
        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->postJson('/api/v1/categories', [
                'name' => '产品动态',
                'slug' => 'Product-News',
                'description' => '产品相关动态',
            ])
            ->assertCreated()
            ->assertJsonPath('name', '产品动态')
            ->assertJsonPath('slug', 'product-news');

        $this->forTenant($tenant)
            ->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', '产品动态');
    }

    public function test_member_cannot_manage_categories(): void
    {
        [$tenant, $member] = $this->tenantAndUser('cat-m', 'member');
        Sanctum::actingAs($member, ['*']);

        $this->forTenant($tenant)
            ->postJson('/api/v1/categories', ['name' => 'x'])
            ->assertForbidden();
    }

    public function test_cross_tenant_category_returns_404(): void
    {
        [$tenantA] = $this->tenantAndUser('cat-a2', 'admin');
        [$tenantB, $adminB] = $this->tenantAndUser('cat-b2', 'admin');

        $cat = Category::create(['tenant_id' => $tenantA->id, 'name' => 'A-only']);

        Sanctum::actingAs($adminB, ['*']);
        $this->forTenant($tenantB)
            ->getJson("/api/v1/categories/{$cat->id}")
            ->assertNotFound();
    }

    public function test_admin_can_update_and_delete_category(): void
    {
        [$tenant, $admin] = $this->tenantAndUser('cat-u', 'admin');
        $cat = Category::create(['tenant_id' => $tenant->id, 'name' => '旧名']);
        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->patchJson("/api/v1/categories/{$cat->id}", ['name' => '新名'])
            ->assertOk()
            ->assertJsonPath('name', '新名');

        $this->forTenant($tenant)
            ->deleteJson("/api/v1/categories/{$cat->id}")
            ->assertOk();

        $this->assertDatabaseMissing('categories', ['id' => $cat->id]);
    }

    public function test_duplicate_slug_per_tenant_is_rejected(): void
    {
        [$tenant, $admin] = $this->tenantAndUser('cat-s', 'admin');
        Category::create(['tenant_id' => $tenant->id, 'name' => 'A', 'slug' => 'dup']);
        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->postJson('/api/v1/categories', ['name' => 'B', 'slug' => 'dup'])
            ->assertJsonValidationErrors(['slug']);
    }

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

    private function forTenant(Tenant $tenant): self
    {
        return $this
            ->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }
}
