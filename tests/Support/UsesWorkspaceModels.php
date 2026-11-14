<?php

namespace Tests\Support;

use App\Contracts\Outbound\HostResolver;
use App\Models\Tenant;
use App\Models\TenantAiKey;
use App\Services\Outbound\SecureHttpFactory;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;

/** Explicit test-only model configuration; fakes never contact a supplier. */
trait UsesWorkspaceModels
{
    protected function useWorkspaceModels(?Tenant $tenant = null, string $provider = 'custom', string $url = 'https://models.example.test/v1'): Tenant
    {
        $tenant ??= Tenant::create(['name' => '模型测试', 'subdomain' => 'model-'.bin2hex(random_bytes(4)), 'status' => 'active']);
        TenantContext::set($tenant);
        $this->configureWorkspaceModelSlots($tenant, $provider, $url);
        $this->app->instance(HostResolver::class, new class implements HostResolver {
            public function resolve(string $host): array { return ['93.184.216.34']; }
        });
        // Use the real secure factory's fake hooks; production bindings are untouched.
        Http::swap($this->app->make(SecureHttpFactory::class));
        Http::preventStrayRequests();
        return $tenant;
    }
    protected function configureWorkspaceModelSlots(Tenant $tenant, string $provider = 'custom', string $url = 'https://models.example.test/v1'): void
    {
        foreach (['text', 'vision', 'embedding'] as $slot) {
            TenantAiKey::updateOrCreate(['tenant_id' => $tenant->id, 'slot' => $slot], [
                'provider' => $provider, 'encrypted_key' => 'sk-test-'.$slot, 'is_active' => true,
                'metadata' => ['base_url' => $url, 'model' => 'test-'.$slot, 'json_mode' => true, 'dimensions' => $slot === 'embedding' ? 1024 : null]]);
        }
    }

}
