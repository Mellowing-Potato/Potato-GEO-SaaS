<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\LocalAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NativeProductAccountTest extends TestCase
{
    use RefreshDatabase;

    private function login(): \Illuminate\Testing\TestResponse
    {
        Http::preventStrayRequests();
        if (!User::withoutGlobalScopes()->where('email', 'native@example.com')->exists()) {
            app(LocalAccounts::class)->create('native@example.com', 'test-password-1', '初始姓名');
        }
        app('auth')->forgetGuards();
        return $this->withHeaders(['Authorization' => '', 'Cookie' => ''])
            ->postJson('/api/v1/account/login', ['email' => 'native@example.com', 'password' => 'test-password-1'])->assertOk();
    }

    private function authenticated($response): void
    {
        app('auth')->forgetGuards();
        $cookie = collect($response->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === 'geo_session');
        $this->withToken($response->json('token'))->withHeader('Cookie', 'geo_session='.$cookie->getValue())
            ->withHeader('X-Tenant', $response->json('tenant.subdomain'));
    }

    public function test_native_login_cookie_profile_and_session_binding(): void
    {
        app('cookie')->setDefaultPathAndDomain('/', '.example.test', true);
        $login = $this->login()->assertCookie('geo_session')->assertCookieMissing('gateway_session');
        $cookie = collect($login->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === 'geo_session');
        $this->assertNull($cookie->getDomain());
        $this->assertTrue($cookie->isHttpOnly());
        $this->authenticated($login);
        $this->patchJson('/api/v1/account/profile', ['name' => '独立昵称', 'email' => 'native@example.com'])->assertOk();
        $this->patchJson('/api/v1/account/profile', ['name' => ' ', 'email' => 'native@example.com'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/session-login')->assertOk()->assertJsonPath('user.name', '独立昵称')->assertJsonPath('account_user.name', '独立昵称');
        $this->getJson('/api/v1/account/sessions')->assertOk()->assertJsonPath('data.0.current', true);
        Http::assertNothingSent();
    }

    public function test_password_change_rejects_wrong_password_and_stale_setup_cannot_overwrite_it(): void
    {
        $this->authenticated($this->login());
        $payload = ['mode' => 'setup', 'password' => 'test-password-2', 'password_confirmation' => 'wrong-confirmation'];
        $this->patchJson('/api/v1/account/password', $payload)->assertUnprocessable();
        $payload['password_confirmation'] = $payload['password'];
        $this->patchJson('/api/v1/account/password', $payload)->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $payload['current_password'] = 'incorrect';
        $this->patchJson('/api/v1/account/password', $payload)->assertUnprocessable();
        $this->assertTrue(Hash::check('test-password-1', User::first()->password));
        $payload['current_password'] = 'test-password-1';
        $updated = $this->patchJson('/api/v1/account/password', $payload)->assertOk()->assertCookie('geo_session');
        $this->authenticated($updated);
        $this->getJson('/api/v1/account/profile')->assertOk()->assertJsonPath('has_password', true);
        $this->patchJson('/api/v1/account/password', $payload)->assertUnprocessable();
        $this->assertTrue(Hash::check('test-password-2', User::first()->password));
        Http::assertNothingSent();
    }

    public function test_shared_cookie_and_cross_origin_login_are_rejected(): void
    {
        Http::preventStrayRequests();
        $this->withHeader('Cookie', 'gateway_session=test-fake')->postJson('/api/v1/auth/session-login')->assertUnauthorized();
        $this->withHeader('Origin', 'https://unrelated.test')->postJson('/api/v1/account/login', ['email' => 'native@example.com', 'password' => 'test-password-1'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_email_change_requires_password_and_preserves_workspace_and_identity(): void
    {
        $login = $this->login();
        $this->authenticated($login);
        $payload = ['name' => '新名字', 'email' => 'changed@example.com'];
        $this->patchJson('/api/v1/account/profile', $payload)->assertUnprocessable();
        $this->patchJson('/api/v1/account/profile', [...$payload, 'current_password' => 'incorrect'])->assertUnprocessable();
        $this->patchJson('/api/v1/account/profile', [...$payload, 'current_password' => 'test-password-1'])
            ->assertOk()->assertJsonPath('user.id', $login->json('user.id'))->assertJsonPath('user.tenant_id', $login->json('tenant.id'));
        $this->assertDatabaseHas('users', ['id' => $login->json('user.id'), 'email' => 'changed@example.com']);
        Http::assertNothingSent();
    }

    public function test_old_reset_link_cannot_take_over_a_new_owner_of_a_released_email(): void
    {
        $login = $this->login();
        $user = User::findOrFail($login->json('user.id'));
        $oldLink = \Illuminate\Support\Facades\Password::createToken($user);
        $this->authenticated($login);
        $this->patchJson('/api/v1/account/profile', ['name' => '新名字', 'email' => 'changed@example.com',
            'current_password' => 'test-password-1'])->assertOk();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'native@example.com']);
        $newOwner = app(LocalAccounts::class)->create('native@example.com', 'test-new-owner', '新用户');
        app('auth')->forgetGuards();
        $this->withHeaders(['Authorization' => '', 'Cookie' => '', 'X-Tenant' => ''])
            ->postJson('/api/v1/account/reset-password', ['email' => 'native@example.com', 'token' => $oldLink,
                'password' => 'test-attacker-password', 'password_confirmation' => 'test-attacker-password'])
            ->assertUnprocessable();
        $this->assertTrue(Hash::check('test-new-owner', $newOwner->fresh()->password));
        Http::assertNothingSent();
    }

}
