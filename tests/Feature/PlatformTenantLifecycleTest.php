<?php
namespace Tests\Feature;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Non-billing account controls retained from the former operations endpoint suite.
class PlatformTenantLifecycleTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.tenant_domain' => 'geo.example.test']);
    }
    public function test_pause_requires_platform_operations(): void
    {
        [$tenant, $admin] = $this->tenantAdmin('pause-denied', 'basic');
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/v1/platform/tenants/'.$tenant->subdomain.'/pause', ['reason' => 'incident'])
            ->assertForbidden();
    }

    public function test_pause_already_paused_returns_409(): void
    {
        [$tenant, $admin] = $this->tenantAdmin('pause-dup', 'basic');
        $platformAdmin = $this->platformAdmin();
        Sanctum::actingAs($platformAdmin, ['*']);

        $this->postJson('/api/v1/platform/tenants/'.$tenant->subdomain.'/pause', ['reason' => 'incident'])
            ->assertOk();
        $dup = $this->postJson('/api/v1/platform/tenants/'.$tenant->subdomain.'/pause', ['reason' => 'incident'])
            ->assertStatus(409);
        $this->assertNotNull($dup->json('audit_id'));
    }

    public function test_pause_and_recover_flow(): void
    {
        [$tenant, $admin] = $this->tenantAdmin('pause-ok', 'basic');
        $platformAdmin = $this->platformAdmin();
        Sanctum::actingAs($platformAdmin, ['*']);

        $pause = $this->postJson('/api/v1/platform/tenants/'.$tenant->subdomain.'/pause', ['reason' => 'incident', 'note' => 'card stuck'])
            ->assertOk()
            ->assertJsonPath('status', 'paused');
        $this->assertNotNull($pause->json('audit_id'));
        $this->assertSame('paused', $tenant->fresh()->status);

        // 恢复：未暂停 -> 409。
        // （此处 tenant 已暂停，走正常恢复路径。）
        $recover = $this->postJson('/api/v1/platform/tenants/'.$tenant->subdomain.'/recover', ['reason' => 'incident_resolved'])
            ->assertOk()
            ->assertJsonPath('status', 'recovered');
        $this->assertNotNull($recover->json('audit_id'));
        $this->assertSame('active', $tenant->fresh()->status);
    }

    public function test_recover_not_paused_returns_409(): void
    {
        [$tenant, $admin] = $this->tenantAdmin('recover-denied', 'basic');
        $platformAdmin = $this->platformAdmin();
        Sanctum::actingAs($platformAdmin, ['*']);

        $notPaused = $this->postJson('/api/v1/platform/tenants/'.$tenant->subdomain.'/recover', ['reason' => 'incident_resolved'])
            ->assertStatus(409);
        $this->assertNotNull($notPaused->json('audit_id'));
    }

    private function tenantAdmin(string $subdomain, string $plan): array
    {
        $phone = \Tests\Support\FixtureIdentity::phone($subdomain);
        $tenant = Tenant::query()->create([
            'subdomain' => $subdomain,
            'name' => strtoupper($subdomain),
            'plan' => $plan,
            'status' => 'active',
        ]);
        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,

            'email' => $subdomain.'@example.com',
            'phone' => $phone,
            'phone_verified_at' => now(),
            'role' => 'admin',
        ]);

        return [$tenant, $admin];
    }

    private function platformAdmin(): User
    {
        return User::factory()->create([
            'tenant_id' => null,
            'role' => 'platform_admin',
        ]);
    }

    private function forTenant(Tenant $tenant): self
    {
        return $this
            ->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();

        parent::tearDown();
    }
    public function test_a_tenant_with_platform_operator_cannot_be_paused(): void
    {
        [$tenant] = $this->tenantAdmin('operator-workspace', 'basic');
        $operator = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'platform_admin']);
        Sanctum::actingAs($operator, ['*']);
        $this->postJson('/api/v1/platform/tenants/'.$tenant->subdomain.'/pause', ['reason' => 'incident'])
            ->assertStatus(409);
        $this->assertSame('active', $tenant->fresh()->status);
    }
}
