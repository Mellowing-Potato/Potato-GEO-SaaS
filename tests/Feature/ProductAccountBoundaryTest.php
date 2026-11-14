<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantAiKey;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductAccountBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    private function login(): array
    {
        $tenant = Tenant::create(['subdomain' => 'boundary', 'name' => '原工作区', 'plan' => 'basic', 'status' => 'active']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin', 'name' => '个人昵称']);
        Sanctum::actingAs($user, ['*']);
        config(['app.tenant_domain' => 'geo.example.test']);
        $this->withServerVariables(['HTTP_HOST' => 'geo.example.test'])->withHeader('X-Tenant', $tenant->subdomain);

        return [$tenant, $user];
    }

    public function test_unassigned_legacy_key_is_not_an_editable_model_slot(): void
    {
        [$tenant] = $this->login();
        $key = TenantAiKey::create(['tenant_id' => $tenant->id, 'provider' => 'gateway', 'encrypted_key' => 'sk-test-fake-77f', 'is_active' => true]);
        $this->getJson('/api/v1/ai-keys')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson('/api/v1/ai-keys/'.$key->id)->assertNotFound();
        $this->patchJson('/api/v1/ai-keys/'.$key->id.'/toggle')->assertNotFound();
        $this->postJson('/api/v1/ai-keys', ['provider' => 'gateway', 'api_key' => 'sk-test-fake'])->assertUnprocessable();
        $this->assertTrue($key->fresh()->is_active);
        $this->assertSame('sk-test-fake-77f', $key->fresh()->getDecryptedKey());
    }

    public function test_workspace_rename_does_not_change_identity_or_another_workspace(): void
    {
        [$tenant, $user] = $this->login();
        $other = Tenant::create(['subdomain' => 'another', 'name' => '另一个工作区', 'plan' => 'basic', 'status' => 'active']);
        $this->patchJson('/api/v1/workspace', ['name' => '示例文旅', 'tenant_id' => $other->id])->assertOk()->assertJsonPath('tenant.name', '示例文旅');
        $this->assertSame('示例文旅', $tenant->fresh()->name);
        $this->assertSame('个人昵称', $user->fresh()->name);
        $this->assertSame('另一个工作区', $other->fresh()->name);
        $this->patchJson('/api/v1/workspace', ['name' => ' '])->assertUnprocessable();
    }


}
