<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\SecuritySetting;
use App\Models\User;
use App\Services\Admin\ActivityLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformActivityLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.tenant_domain' => 'geo.example.test']);
    }

    private function platformAdmin(): User
    {
        return User::factory()->create([
            'tenant_id' => null,
            'role' => 'platform_admin',
        ]);
    }

    public function test_security_settings_returns_defaults_when_unset(): void
    {
        $admin = $this->platformAdmin();
        Sanctum::actingAs($admin, ['*']);

        $this->putJson('/api/v1/platform/security-settings') // 空 body 也会校验通过
            ->assertOk();

        $this->getJson('/api/v1/platform/security-settings')
            ->assertOk()
            ->assertJsonPath('settings.password_min_length', 8)
            ->assertJsonPath('settings.api_rate_limit_per_min', 60);
    }

    public function test_update_security_settings_persists_and_writes_audit(): void
    {
        $admin = $this->platformAdmin();
        Sanctum::actingAs($admin, ['*']);

        $this->putJson('/api/v1/platform/security-settings', [
            'password_min_length' => 12,
            'api_rate_limit_per_min' => 120,
            'sensitive_words' => ['免费', '内部'],
        ])->assertOk()
            ->assertJsonPath('settings.password_min_length', 12)
            ->assertJsonPath('settings.api_rate_limit_per_min', 120)
            ->assertJsonPath('settings.sensitive_words.0', '免费');

        // 写操作由 log-admin-activity 中间件落审计，动作取路由名
        $this->getJson('/api/v1/platform/audit')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.action', 'put platform.security-settings.update');

        // 平台级设置行唯一（tenant_id 为 null）
        $this->assertSame(1, SecuritySetting::query()->whereNull('tenant_id')->count());
    }

    public function test_mutating_platform_request_is_logged_by_middleware(): void
    {
        $admin = $this->platformAdmin();
        Sanctum::actingAs($admin, ['*']);

        // 触发一次 PUT（写操作），中间件应落审计
        $this->putJson('/api/v1/platform/security-settings', ['api_rate_limit_per_min' => 30])
            ->assertOk();

        $this->getJson('/api/v1/platform/audit')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.request_method', 'PUT');
    }

    public function test_activity_log_service_redacts_sensitive_fields(): void
    {
        $svc = $this->app->make(ActivityLogService::class);
        $svc->record('create tenant', [
            'actor_id' => 1,
            'details' => [
                'password' => 'super-secret',
                'name' => str_repeat('x', 300),
                'title' => 'hello world',
            ],
        ]);

        $log = AdminActivityLog::query()->latest('id')->first();
        $details = json_decode((string) $log->details, true);

        $this->assertSame('[redacted]', $details['password']);
        $this->assertStringEndsWith('...', $details['name']);
        $this->assertSame('hello world', $details['title']);
    }
}
