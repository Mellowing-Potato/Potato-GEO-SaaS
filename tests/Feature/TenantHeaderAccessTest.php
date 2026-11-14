<?php
namespace Tests\Feature;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
class TenantHeaderAccessTest extends TestCase
{
    use RefreshDatabase;
    public function test_geo_admin_can_use_base_domain_workspace_with_tenant_header(): void
    {

        $tenant = Tenant::query()->create([
            'subdomain' => 'geo-base',
            'name' => 'GEO Base Workspace',
            'plan' => 'basic',
            'status' => 'active',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => '13800000012',
            'role' => 'admin',
        ]);

        Sanctum::actingAs($user, ['*']);

        $this
            ->withServerVariables(['HTTP_HOST' => 'geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain)
            ->getJson('/api/v1/dashboard/usage')
            ->assertOk();
    }

    public function test_geo_user_cannot_access_another_tenant_with_header_override(): void
    {
        $ownTenant = Tenant::query()->create([
            'subdomain' => 'geo-own',
            'name' => 'GEO Own Tenant',
            'plan' => 'basic',
            'status' => 'active',
        ]);
        $otherTenant = Tenant::query()->create([
            'subdomain' => 'geo-other',
            'name' => 'GEO Other Tenant',
            'plan' => 'basic',
            'status' => 'active',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $ownTenant->id,

            'phone' => '13800000012',
            'role' => 'admin',
        ]);

        Sanctum::actingAs($user, ['*']);

        $this
            ->withServerVariables(['HTTP_HOST' => 'geo.example.test'])
            ->withHeader('X-Tenant', $otherTenant->subdomain)
            ->getJson('/api/v1/dashboard/usage')
            ->assertStatus(403);
    }

}
