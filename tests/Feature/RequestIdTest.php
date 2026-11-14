<?php

namespace Tests\Feature;

use App\Http\Middleware\AttachRequestId;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * 请求编号（2026-08-17）
 *
 * 界面上的「错误编号」以前是前端 Math.random() 现编的，服务端查无此号。
 * 这组测试盯住三件事：编号必须由服务端发、必须覆盖异常渲染出来的 500、
 * 必须能在日志里查得到。任何一条断了，「联系支持并附上编号」就又变成空话。
 */
class RequestIdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.tenant_domain' => 'geo.example.test']);
    }

    public function test_successful_api_response_carries_a_request_id(): void
    {
        [$tenant, $admin] = $this->makeTenantAdmin('req-ok');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->forTenant($tenant)->getJson('/api/v1/tasks');

        $response->assertOk();
        $this->assertMatchesRequestIdFormat($response->headers->get(AttachRequestId::HEADER));
    }

    public function test_permission_denied_response_carries_a_request_id(): void
    {
        // An account without task-management permission receives an attributable 403.
        [$tenant, $operator] = $this->makeTenantAdmin('req-403', 'member');
        Sanctum::actingAs($operator, ['*']);

        $response = $this->forTenant($tenant)->getJson('/api/v1/tasks');

        $response->assertForbidden();
        $this->assertMatchesRequestIdFormat($response->headers->get(AttachRequestId::HEADER));
    }

    public function test_unauthenticated_response_carries_a_request_id(): void
    {
        $response = $this->getJson('/api/v1/tasks');

        $response->assertUnauthorized();
        $this->assertMatchesRequestIdFormat($response->headers->get(AttachRequestId::HEADER));
    }

    public function test_uncaught_exception_still_carries_a_request_id(): void
    {
        // 这条是关键：500 是在中间件管道之外渲染的，所以编号必须挂在
        // RequestHandled 事件上而不是中间件里，否则最该有编号的响应反而没有。
        Route::middleware('api')->get('/api/__boom', function (): void {
            throw new RuntimeException('boom');
        });

        $response = $this->getJson('/api/__boom');

        $response->assertStatus(500);
        $this->assertMatchesRequestIdFormat($response->headers->get(AttachRequestId::HEADER));
    }

    public function test_failed_request_is_logged_under_its_request_id(): void
    {
        $logged = [];
        Log::listen(function ($message) use (&$logged): void {
            $logged[] = $message;
        });

        $response = $this->getJson('/api/v1/tasks');
        $requestId = $response->headers->get(AttachRequestId::HEADER);

        $matching = array_values(array_filter(
            $logged,
            fn ($message): bool => ($message->context['request_id'] ?? null) === $requestId,
        ));

        $this->assertNotEmpty($matching, '失败响应没有留下带该编号的日志，支持侧就查不到。');

        $index = array_values(array_filter(
            $matching,
            fn ($message): bool => $message->message === 'api 请求失败',
        ));

        $this->assertNotEmpty($index, '缺少可 grep 的索引行。');
        $this->assertSame(401, $index[0]->context['status']);
        $this->assertSame('api/v1/tasks', $index[0]->context['path']);
    }

    public function test_each_request_gets_its_own_id(): void
    {
        $first = $this->getJson('/api/v1/tasks')->headers->get(AttachRequestId::HEADER);
        $second = $this->getJson('/api/v1/tasks')->headers->get(AttachRequestId::HEADER);

        $this->assertNotSame($first, $second);
    }

    public function test_non_api_routes_are_left_alone(): void
    {
        $response = $this->get('/up');

        $this->assertNull($response->headers->get(AttachRequestId::HEADER));
    }

    private function assertMatchesRequestIdFormat(?string $id): void
    {
        $this->assertNotNull($id, '响应里没有请求编号。');
        $this->assertMatchesRegularExpression('/^GEO-\d{6}-[0-9A-F]{6}$/', $id);
    }

    private function makeTenantAdmin(string $subdomain, string $role = 'admin'): array
    {
        $tenant = Tenant::query()->create([
            'subdomain' => $subdomain,
            'name' => strtoupper($subdomain),
            'plan' => 'basic',
            'status' => 'active',
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,

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
