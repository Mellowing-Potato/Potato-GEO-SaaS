<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\LocalAccounts;
use App\Support\LocalSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class LocalAccountFlowTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $email = 'admin@example.com', string $role = 'platform_admin'): User
    {
        Http::preventStrayRequests();
        return app(LocalAccounts::class)->create($email, 'test-password-1', '本地用户', $role);
    }

    private function login(string $email = 'admin@example.com', string $password = 'test-password-1')
    {
        app('auth')->forgetGuards();
        return $this->withHeaders(['Authorization' => '', 'Cookie' => ''])
            ->postJson('/api/v1/account/login', compact('email', 'password'));
    }

    private function authenticate($response): void
    {
        app('auth')->forgetGuards();
        $cookie = collect($response->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === 'geo_session');
        $this->assertNull($cookie->getDomain());
        $this->assertTrue($cookie->isHttpOnly());
        $this->withToken($response->json('token'))->withHeader('Cookie', 'geo_session='.$cookie->getValue());
    }

    public function test_offline_login_password_change_revoke_logout_and_relogin(): void
    {
        $user = $this->account();
        $first = $this->login()->assertOk();
        $second = $this->login()->assertOk();
        $this->authenticate($first);
        $this->getJson('/api/v1/account/sessions')->assertOk()->assertJsonCount(2, 'data');
        $changed = $this->patchJson('/api/v1/account/password', ['current_password' => 'test-password-1',
            'password' => 'test-password-2', 'password_confirmation' => 'test-password-2'])->assertOk();
        $this->assertTrue(Hash::check('test-password-2', $user->fresh()->password));
        $this->authenticate($second);
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $third = $this->login(password: 'test-password-2')->assertOk();
        $this->authenticate($changed);
        $this->postJson('/api/v1/account/sessions/revoke')->assertOk()->assertJsonPath('revoked_count', 1);
        $this->authenticate($third);
        $this->postJson('/api/v1/auth/session-login')->assertUnauthorized();
        $this->authenticate($changed);
        $this->postJson('/api/v1/auth/logout')->assertOk();
        app('auth')->forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->login(password: 'test-password-2')->assertOk();
        Http::assertNothingSent();
    }

    public function test_registration_is_closed_by_default_and_admin_can_open_it(): void
    {
        $user = $this->account();
        $data = ['email' => 'customer@example.com', 'name' => '客户', 'password' => 'test-password-1', 'password_confirmation' => 'test-password-1'];
        $this->postJson('/api/v1/account/register', $data)->assertForbidden();
        $this->authenticate($this->login()->assertOk());
        $this->putJson('/api/v1/platform/registration', ['registration_enabled' => true])->assertOk();
        $this->withHeaders(['Authorization' => '', 'Cookie' => '']);
        app('auth')->forgetGuards();
        $registered = $this->postJson('/api/v1/account/register', $data)->assertOk();
        $this->assertNotSame($user->tenant_id, $registered->json('user.tenant_id'));
        $this->assertSame('admin', $registered->json('user.role'));
        $this->authenticate($registered);
        $this->putJson('/api/v1/platform/registration', ['registration_enabled' => false])->assertForbidden();
        $this->assertSame(1, User::withoutGlobalScopes()->where('tenant_id', $registered->json('user.tenant_id'))->count());
        Http::assertNothingSent();
    }

    public function test_platform_admin_has_own_workspace_permissions_but_cannot_select_another_tenant(): void
    {
        $user = $this->account();
        $other = $this->account('other@example.com', 'admin');
        $this->authenticate($this->login()->assertOk());
        $permissions = $this->getJson('/api/v1/me/permissions')->assertOk()->json('permissions');
        $this->assertContains('project:create', $permissions);
        $this->assertContains('platform:operations', $permissions);
        $this->postJson('/api/v1/projects', ['name' => '自己的项目'])->assertCreated();
        $personaId = $this->postJson('/api/v1/manual-publications/settings/personas', ['name' => '验收人设'])
            ->assertCreated()->json('data.id');
        $this->postJson('/api/v1/manual-publications/settings/accounts', [
            'persona_id' => $personaId, 'platform' => 'zhihu', 'account_name' => '验收账号',
            'profile_url' => 'https://www.zhihu.com/people/test-account',
        ])->assertCreated();
        $this->withHeader('X-Tenant', $other->tenant->subdomain)->getJson('/api/v1/projects')->assertForbidden();
        $this->getJson('/api/v1/manual-publications/settings/accounts')->assertForbidden();
        $this->assertTrue($user->hasPermission('project:create'));
        $this->assertFalse($other->hasPermission('platform:operations'));
    }

    public function test_paused_user_and_cross_user_cookie_are_rejected(): void
    {
        $user = $this->account();
        $first = $this->login()->assertOk();
        $other = $this->account('other@example.com', 'admin');
        $second = $this->login('other@example.com')->assertOk();
        $this->authenticate($second);
        $this->withToken($first->json('token'))->getJson('/api/v1/auth/me')->assertUnauthorized();
        $user->tenant->update(['status' => 'paused']);
        $this->login()->assertForbidden();
    }

    public function test_expired_reset_links_fail_and_valid_links_work_once_and_revoke_sessions(): void
    {
        $user = $this->account();
        $session = $this->login()->assertOk();
        $token = Password::createToken($user);
        $data = ['email' => $user->email, 'token' => $token, 'password' => 'test-password-2', 'password_confirmation' => 'test-password-2'];
        $this->travel(61)->minutes();
        $this->postJson('/api/v1/account/reset-password', $data)->assertUnprocessable();
        $this->assertTrue(Hash::check('test-password-1', $user->fresh()->password));
        $this->travelBack();
        $data['token'] = Password::createToken($user);
        $this->postJson('/api/v1/account/reset-password', $data)->assertOk();
        $this->postJson('/api/v1/account/reset-password', $data)->assertUnprocessable();
        $this->authenticate($session);
        $this->postJson('/api/v1/auth/session-login')->assertUnauthorized();
        $this->login(password: 'test-password-2')->assertOk();
    }

    public function test_mail_reset_uses_configured_instance_and_no_mail_directs_to_admin(): void
    {
        $user = $this->account();
        $this->postJson('/api/v1/account/forgot-password', ['email' => $user->email])->assertStatus(503);
        config(['mail.default' => 'smtp', 'app.url' => 'https://instance.example.test']);
        Notification::fake();
        $this->postJson('/api/v1/account/forgot-password', ['email' => $user->email])->assertOk();
        Notification::assertSentTo($user, \Illuminate\Auth\Notifications\ResetPassword::class, function ($notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;
            $this->assertStringStartsWith('https://instance.example.test/login?', $url);
            $this->assertStringContainsString('token=', $url);
            return true;
        });
    }

    public function test_admin_reset_command_replaces_password_and_revokes_sessions(): void
    {
        $user = $this->account();
        $session = $this->login()->assertOk();
        $this->artisan('yanji:reset-password', ['email' => $user->email, '--password' => 'test-password-2'])->assertSuccessful();
        $this->authenticate($session);
        $this->postJson('/api/v1/auth/session-login')->assertUnauthorized();
        $this->login(password: 'test-password-2')->assertOk();
    }

    public function test_missing_or_cross_origin_and_wrong_password_fail_without_outbound_requests(): void
    {
        $this->account();
        $this->login(password: 'wrong-password')->assertUnprocessable();
        $this->withHeader('Origin', 'https://foreign.example.test');
        $this->login()->assertForbidden();
        Http::assertNothingSent();
    }
}
