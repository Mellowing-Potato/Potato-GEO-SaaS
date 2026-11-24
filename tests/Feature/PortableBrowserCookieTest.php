<?php

namespace Tests\Feature;

use App\Services\Auth\LocalAccounts;
use App\Support\LocalSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class PortableBrowserCookieTest extends TestCase
{
    use RefreshDatabase;

    public function test_instance_cookie_name_is_used_for_login_binding_and_logout(): void
    {
        Http::preventStrayRequests();
        config(['instance.browser_cookie_name' => 'geo_session_alpha']);
        app(LocalAccounts::class)->create('admin@example.com', 'test-password-1', 'Fixture admin', 'platform_admin');
        $login = $this->postJson('/api/v1/account/login', ['email' => 'admin@example.com', 'password' => 'test-password-1'])
            ->assertOk()->assertCookie('geo_session_alpha')->assertCookieMissing('geo_session');
        $cookie = collect($login->headers->getCookies())->first(fn ($c) => $c->getName() === 'geo_session_alpha');
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertNull($cookie->getDomain());
        app('auth')->forgetGuards();
        $this->withToken($login->json('token'))->withHeader('Cookie', 'geo_session_beta=other-fixture; geo_session_alpha='.$cookie->getValue());
        $this->getJson('/api/v1/auth/me')->assertOk();
        $logout = $this->postJson('/api/v1/auth/logout')->assertOk();
        $cleared = collect($logout->headers->getCookies())->first(fn ($c) => $c->getName() === 'geo_session_alpha');
        $this->assertNotNull($cleared);
        $this->assertLessThan(time(), $cleared->getExpiresTime());
        Http::assertNothingSent();
    }

    public function test_same_cookie_jar_selects_only_the_configured_instance_cookie(): void
    {
        $request = Request::create('http://localhost/');
        $request->headers->set('Cookie', 'geo_session_beta=beta-fixture; geo_session_alpha=alpha-fixture; geo_session=legacy-fixture');
        config(['instance.browser_cookie_name' => 'geo_session_alpha']);
        $this->assertSame('alpha-fixture', LocalSession::valueFromRequest($request));
        config(['instance.browser_cookie_name' => 'geo_session_beta']);
        $this->assertSame('beta-fixture', LocalSession::valueFromRequest($request));
        config(['instance.browser_cookie_name' => 'geo_session_gamma']);
        $this->assertSame('', LocalSession::valueFromRequest($request));
    }

    public function test_invalid_cookie_name_fails_before_creating_a_browser_session(): void
    {
        $user = app(LocalAccounts::class)->create('fixture@example.com', 'test-password-1', 'Fixture admin', 'platform_admin');
        config(['instance.browser_cookie_name' => "invalid; cookie"]);
        try {
            LocalSession::issue($user, Request::create('/'));
            $this->fail('An invalid configured cookie name must fail before issuing credentials.');
        } catch (\InvalidArgumentException) {
            $this->assertDatabaseCount('local_browser_sessions', 0);
            $this->assertDatabaseCount('personal_access_tokens', 0);
        }
    }
}
