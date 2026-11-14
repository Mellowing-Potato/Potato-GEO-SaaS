<?php

namespace Tests\Feature;

use App\Models\ApiIdempotencyKey;
use App\Models\Tenant;
use App\Models\TitleLibrary;
use App\Models\User;
use App\Services\Api\IdempotencyService;
use App\Support\TenantContext;
use Illuminate\Contracts\Cache\Lock as CacheLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ApiIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.tenant_domain' => 'geo.example.test']);
    }

    public function test_duplicate_request_with_same_key_replays_cached_response(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');
        Sanctum::actingAs($admin, ['*']);

        $payload = ['title' => '幂等测试标题', 'category' => 'listicle'];

        $first = $this->forTenant($tenant)
            ->postJson('/api/v1/materials/titles', $payload, ['X-Idempotency-Key' => 'key-123']);

        $first->assertStatus(201);

        $second = $this->forTenant($tenant)
            ->postJson('/api/v1/materials/titles', $payload, ['X-Idempotency-Key' => 'key-123']);

        $second->assertStatus(201)
            ->assertJsonPath('data.title', '幂等测试标题');

        // 第二次请求被回放，数据库只落一条
        TenantContext::set($tenant);
        $this->assertSame(1, TitleLibrary::query()->where('tenant_id', $tenant->id)->count());
        TenantContext::forget();
    }

    public function test_same_key_different_body_returns_conflict(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');
        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->postJson('/api/v1/materials/titles', ['title' => 'A 标题'], ['X-Idempotency-Key' => 'key-conflict'])
            ->assertStatus(201);

        $this->forTenant($tenant)
            ->postJson('/api/v1/materials/titles', ['title' => 'B 标题'], ['X-Idempotency-Key' => 'key-conflict'])
            ->assertStatus(409);

        // 冲突请求不落库
        TenantContext::set($tenant);
        $this->assertSame(1, TitleLibrary::query()->where('tenant_id', $tenant->id)->count());
        TenantContext::forget();
    }

    public function test_requests_without_key_are_not_idempotent(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');
        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)->postJson('/api/v1/materials/titles', ['title' => '无键 1'])->assertStatus(201);
        $this->forTenant($tenant)->postJson('/api/v1/materials/titles', ['title' => '无键 2'])->assertStatus(201);

        TenantContext::set($tenant);
        $this->assertSame(2, TitleLibrary::query()->where('tenant_id', $tenant->id)->count());
        TenantContext::forget();
    }

    public function test_idempotency_is_scoped_per_tenant(): void
    {
        [$tenantA, $adminA] = $this->makeTenantUser('tenant-a', 'admin');
        [$tenantB, $adminB] = $this->makeTenantUser('tenant-b', 'admin');

        $payload = ['title' => '跨租户同键'];

        Sanctum::actingAs($adminA, ['*']);
        $this->forTenant($tenantA)
            ->postJson('/api/v1/materials/titles', $payload, ['X-Idempotency-Key' => 'shared-key'])
            ->assertStatus(201);

        // B 租户使用相同幂等键 → 不受影响，正常创建
        Sanctum::actingAs($adminB, ['*']);
        $this->forTenant($tenantB)
            ->postJson('/api/v1/materials/titles', $payload, ['X-Idempotency-Key' => 'shared-key'])
            ->assertStatus(201);

        TenantContext::set($tenantB);
        $this->assertSame(1, TitleLibrary::query()->where('tenant_id', $tenantB->id)->count());
        TenantContext::forget();
    }

    public function test_concurrent_request_cannot_enter_business_code_while_same_scope_is_locked(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-locked', 'admin');
        Sanctum::actingAs($admin, ['*']);
        config([
            'cache.default' => 'database',
            'geo.api_idempotency.wait_seconds' => 0,
        ]);

        $routeKey = 'api/v1/materials/titles';
        $idempotencyKey = 'same-paid-request';
        $lock = Cache::lock(
            IdempotencyService::lockName($idempotencyKey, $routeKey, $tenant->id, $admin->id),
            600,
        );
        $this->assertTrue($lock->get());

        try {
            $response = $this->forTenant($tenant)->postJson(
                '/api/v1/materials/titles',
                ['title' => '不应创建的并发标题'],
                ['X-Idempotency-Key' => $idempotencyKey],
            );

            $response->assertStatus(409)
                ->assertHeader('Retry-After', '2')
                ->assertJsonPath('action', 'idempotency_request_in_progress')
                ->assertJsonPath('retryable', true);

            TenantContext::set($tenant);
            $this->assertSame(0, TitleLibrary::query()->where('tenant_id', $tenant->id)->count());
            TenantContext::forget();
        } finally {
            $lock->release();
        }
    }

    public function test_response_completed_during_lock_acquisition_is_replayed_after_inner_recheck(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-race', 'admin');
        Sanctum::actingAs($admin, ['*']);

        $payload = ['title' => '不应重复执行的标题'];
        $routeKey = 'api/v1/materials/titles';
        $idempotencyKey = 'race-key';
        $cachedPayload = ['data' => ['title' => '首请求已经创建的标题']];
        $lockName = IdempotencyService::lockName($idempotencyKey, $routeKey, $tenant->id, $admin->id);

        $lock = Mockery::mock(CacheLock::class);
        Cache::shouldReceive('lock')
            ->once()
            ->with($lockName, 600)
            ->andReturn($lock);
        $lock->shouldReceive('block')
            ->once()
            ->with(5)
            ->andReturnUsing(function () use ($idempotencyKey, $routeKey, $payload, $cachedPayload, $tenant, $admin): bool {
                // 模拟：本请求第一次查库未命中；等待锁时，首请求完成并保存了响应。
                IdempotencyService::store(
                    $idempotencyKey,
                    $routeKey,
                    IdempotencyService::requestHash($payload),
                    $cachedPayload,
                    201,
                    $tenant->id,
                    $admin->id,
                );

                return true;
            });
        $lock->shouldReceive('release')->once()->andReturn(true);

        $response = $this->forTenant($tenant)->postJson(
            '/api/v1/materials/titles',
            $payload,
            ['X-Idempotency-Key' => $idempotencyKey],
        );

        $response->assertStatus(201)
            ->assertJsonPath('data.title', '首请求已经创建的标题');

        // 若锁内没有二次查询，请求会进入控制器并创建这一条。
        TenantContext::set($tenant);
        $this->assertSame(0, TitleLibrary::query()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(1, ApiIdempotencyKey::query()->where('idempotency_key', $idempotencyKey)->count());
        TenantContext::forget();
    }

    public function test_pending_reservation_blocks_duplicate_when_final_response_cannot_be_saved(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-pending', 'admin');
        Sanctum::actingAs($admin, ['*']);

        $idempotencyKey = 'response-write-failed';
        $payload = ['title' => '只允许创建一次'];

        // 模拟业务已成功、最终响应写入幂等记录时数据库异常。
        // remember() 会记录异常，并保留业务前写下的 pending 预留。
        ApiIdempotencyKey::updating(function (): never {
            throw new RuntimeException('simulated idempotency response write failure');
        });

        $this->forTenant($tenant)
            ->postJson('/api/v1/materials/titles', $payload, ['X-Idempotency-Key' => $idempotencyKey])
            ->assertStatus(201);

        $pending = ApiIdempotencyKey::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
        $this->assertNull($pending->response_status);
        $this->assertNull($pending->response_body);

        $this->forTenant($tenant)
            ->postJson('/api/v1/materials/titles', $payload, ['X-Idempotency-Key' => $idempotencyKey])
            ->assertStatus(409)
            ->assertHeader('Retry-After', '2')
            ->assertJsonPath('action', 'idempotency_result_pending');

        TenantContext::set($tenant);
        $this->assertSame(1, TitleLibrary::query()->where('tenant_id', $tenant->id)->count());
        TenantContext::forget();
    }

    public function test_same_key_and_body_on_different_route_resources_do_not_cross_replay(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-resource-scope', 'admin');
        Sanctum::actingAs($admin, ['*']);

        $calls = [];
        Route::post('/api/_test/idempotency-resources/{resource}', function (string $resource) use (&$calls) {
            $calls[] = $resource;

            return response()->json(['resource' => $resource]);
        })->middleware(['auth:sanctum', 'tenant', 'idempotency']);

        $headers = ['X-Idempotency-Key' => 'shared-resource-key'];
        $payload = ['operation' => 'same-body'];

        $this->forTenant($tenant)
            ->postJson('/api/_test/idempotency-resources/41', $payload, $headers)
            ->assertOk()
            ->assertJsonPath('resource', '41');

        $this->forTenant($tenant)
            ->postJson('/api/_test/idempotency-resources/42', $payload, $headers)
            ->assertOk()
            ->assertJsonPath('resource', '42');

        $this->assertSame(['41', '42'], $calls);
        $this->assertDatabaseCount('api_idempotency_keys', 2);
        $this->assertDatabaseHas('api_idempotency_keys', [
            'idempotency_key' => 'shared-resource-key',
            'route_key' => 'api/_test/idempotency-resources/41',
        ]);
        $this->assertDatabaseHas('api_idempotency_keys', [
            'idempotency_key' => 'shared-resource-key',
            'route_key' => 'api/_test/idempotency-resources/42',
        ]);
    }

    public function test_rendered_downstream_exception_is_saved_and_replayed_without_second_execution(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-exception', 'admin');
        Sanctum::actingAs($admin, ['*']);

        $calls = 0;
        Route::post('/api/_test/idempotency-throws', function () use (&$calls): never {
            $calls++;

            throw new RuntimeException('simulated failure after an unknown side effect');
        })->middleware(['auth:sanctum', 'tenant', 'idempotency']);

        $headers = ['X-Idempotency-Key' => 'exception-after-side-effect'];
        $payload = ['value' => 'same-request'];

        $this->forTenant($tenant)
            ->postJson('/api/_test/idempotency-throws', $payload, $headers)
            ->assertStatus(500);

        $this->assertDatabaseHas('api_idempotency_keys', [
            'idempotency_key' => 'exception-after-side-effect',
            'response_status' => 500,
        ]);

        $this->forTenant($tenant)
            ->postJson('/api/_test/idempotency-throws', $payload, $headers)
            ->assertStatus(500);

        $this->assertSame(1, $calls, '框架渲染出的异常响应也必须回放，同 key 不得再次进入下游业务');
        $this->assertDatabaseHas('api_idempotency_keys', [
            'idempotency_key' => 'exception-after-side-effect',
            'route_key' => 'api/_test/idempotency-throws',
            'tenant_id' => $tenant->id,
            'user_id' => $admin->id,
            'response_status' => 500,
        ]);
    }

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

            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain.$role),
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
