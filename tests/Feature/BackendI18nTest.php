<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BackendI18nTest extends TestCase
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

    public function test_accept_language_en_returns_english_message(): void
    {
        [$tenant, $admin] = $this->tenantAndUser('i18n-en', 'admin');
        $cat = Category::create(['tenant_id' => $tenant->id, 'name' => 'X']);

        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->withHeader('Accept-Language', 'en-US,en;q=0.9')
            ->deleteJson("/api/v1/categories/{$cat->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Category deleted');
    }

    public function test_accept_language_zh_returns_chinese_message(): void
    {
        [$tenant, $admin] = $this->tenantAndUser('i18n-zh', 'admin');
        $cat = Category::create(['tenant_id' => $tenant->id, 'name' => 'X']);

        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->withHeader('Accept-Language', 'zh-CN,zh;q=0.9')
            ->deleteJson("/api/v1/categories/{$cat->id}")
            ->assertOk()
            ->assertJsonPath('message', '分类已删除');
    }

    public function test_x_locale_header_overrides_accept_language(): void
    {
        [$tenant, $admin] = $this->tenantAndUser('i18n-x', 'admin');
        $cat = Category::create(['tenant_id' => $tenant->id, 'name' => 'X']);

        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->withHeader('Accept-Language', 'zh-CN')
            ->withHeader('X-Locale', 'en')
            ->deleteJson("/api/v1/categories/{$cat->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Category deleted');
    }

    public function test_not_found_message_is_localized(): void
    {
        [$tenantA] = $this->tenantAndUser('i18n-ta', 'admin');
        [$tenantB, $adminB] = $this->tenantAndUser('i18n-tb', 'admin');
        $cat = Category::create(['tenant_id' => $tenantA->id, 'name' => 'A-only']);

        Sanctum::actingAs($adminB, ['*']);

        $this->forTenant($tenantB)
            ->withHeader('Accept-Language', 'en')
            ->getJson("/api/v1/categories/{$cat->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Category not found');
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

    private function forTenant(Tenant $tenant): self
    {
        return $this
            ->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }
}
