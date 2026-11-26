<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\LocalAccounts;
use App\Support\LocalSession;
use App\Support\PortableDesktopAccess;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Yanji\Portable\Platform;

final class PortableDesktopAccessTest extends TestCase
{
    use RefreshDatabase;

    private string $root;
    private User $owner;
    private string $origin = 'http://127.0.0.1:8000';

    protected function setUp(): void
    {
        parent::setUp();
        // Ticket creation and exact TTL assertions must share the same clock.
        $this->freezeTime();
        Http::preventStrayRequests();
        $this->root = storage_path('framework/testing/desktop-'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($this->root.'/run', 0700, true);
        require_once base_path('portable/src/Platform.php');
        Platform::protectPrivate($this->root, true);
        Platform::protectPrivate($this->root.'/run', true);
        file_put_contents($this->root.'/.portable-layout', "yanji-portable-v1\n");
        config(['app.url' => $this->origin, 'instance.portable' => true,
            'instance.portable_root' => $this->root, 'instance.desktop_mode' => true,
            'instance.browser_cookie_name' => 'geo_session_desktop_fixture']);
        $this->owner = app(LocalAccounts::class)->create('desktop@example.com', 'test-desktop-password', '本机用户', 'platform_admin');
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_private_one_time_ticket_creates_a_regular_workspace_bound_browser_session(): void
    {
        $ticket = $this->launch();
        $onDisk = file_get_contents($this->root.'/run/desktop-ticket.json');
        $this->assertStringNotContainsString($ticket, $onDisk);
        $this->assertStringContainsString(hash('sha256', $ticket), $onDisk);
        $response = $this->exchange($ticket)->assertOk()->assertCookie('geo_session_desktop_fixture');
        $response->assertJsonPath('user.id', $this->owner->id)->assertJsonPath('tenant.id', $this->owner->tenant_id);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === LocalSession::cookieName());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertDatabaseCount('local_browser_sessions', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        app('auth')->forgetGuards();
        $this->withToken($response->json('token'))->withHeader('Cookie', LocalSession::cookieName().'='.$cookie->getValue());
        $this->getJson($this->origin.'/api/v1/me')->assertOk()->assertJsonPath('id', $this->owner->id);
        $this->exchange($ticket)->assertForbidden();
        $this->assertDatabaseCount('local_browser_sessions', 1);
        Http::assertNothingSent();
    }

    public function test_missing_credentials_cannot_access_business_endpoints_or_exchange_a_ticket(): void
    {
        $this->getJson($this->origin.'/api/v1/projects')->assertUnauthorized();
        $this->exchange(str_repeat('a', 64))->assertForbidden();
        $this->assertDatabaseCount('local_browser_sessions', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_desktop_session_cannot_access_a_project_in_a_different_workspace(): void
    {
        $other = Tenant::create(['name' => 'Other fixture workspace', 'subdomain' => 'other-project-fixture', 'status' => 'active', 'settings' => []]);
        TenantContext::set($other);
        $project = Project::create(['name' => 'Private fixture project', 'owner_id' => $this->owner->id]);
        TenantContext::forget();
        $response = $this->exchange($this->launch())->assertOk();
        $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === LocalSession::cookieName());
        app('auth')->forgetGuards();
        $this->withToken($response->json('token'))->withHeader('Cookie', LocalSession::cookieName().'='.$cookie->getValue());
        $this->getJson($this->origin.'/api/v1/projects/'.$project->id)->assertNotFound();
    }

    public function test_access_token_without_its_bound_browser_cookie_cannot_access_private_data(): void
    {
        $response = $this->exchange($this->launch())->assertOk();
        app('auth')->forgetGuards();
        $this->withToken($response->json('token'))->withHeader('Cookie', '');
        $this->getJson($this->origin.'/api/v1/projects')->assertUnauthorized();
    }

    public function test_expired_and_replaced_tickets_cannot_create_a_session(): void
    {
        $issuedAt = now();
        $expired = $this->launch();
        $this->travel(121)->seconds();
        $this->exchange($expired)->assertForbidden();
        $this->travelTo($issuedAt);
        $replaced = $this->launch();
        $current = $this->launch();
        $this->exchange($replaced)->assertForbidden();
        $this->exchange($current)->assertOk();
        $this->assertDatabaseCount('local_browser_sessions', 1);
    }

    #[DataProvider('invalidRequestProvider')]
    public function test_local_network_address_alone_does_not_authorize_a_browser(array $server, array $headers, ?string $url = null): void
    {
        $ticket = $this->launch();
        $this->withServerVariables($server)->postJson(($url ?? $this->origin).'/api/v1/auth/local-start', ['ticket' => $ticket],
            [...['Origin' => $this->origin], ...$headers])->assertForbidden();
        $this->assertDatabaseCount('local_browser_sessions', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public static function invalidRequestProvider(): array
    {
        return [
            'remote peer' => [['REMOTE_ADDR' => '192.0.2.10'], []],
            'cross origin' => [[], ['Origin' => 'https://example.com']],
            'other loopback port' => [[], ['Origin' => 'http://127.0.0.1:8001']],
            'missing origin' => [[], ['Origin' => '']],
            'proxy header' => [[], ['X-Forwarded-For' => '127.0.0.1']],
            'forwarded header' => [[], ['Forwarded' => 'for=127.0.0.1']],
            'host mismatch' => [[], [], 'http://localhost:8000'],
        ];
    }

    public function test_ticket_exchange_requires_json_and_keeps_ticket_out_of_error_responses(): void
    {
        $ticket = $this->launch();
        $response = $this->post($this->origin.'/api/v1/auth/local-start', ['ticket' => $ticket],
            ['Origin' => $this->origin, 'Accept' => 'application/json']);
        $response->assertForbidden();
        $this->assertStringNotContainsString($ticket, $response->getContent());
        $this->assertDatabaseCount('local_browser_sessions', 0);
    }

    public function test_server_mode_cannot_mint_or_exchange_even_when_a_valid_ticket_exists(): void
    {
        $ticket = $this->launch();
        config(['instance.portable' => false]);
        $this->exchange($ticket)->assertNotFound();
        $output = $this->root.'/run/browser-launch-server-fixture.json';
        $this->assertSame(1, Artisan::call('yanji:desktop-launch', ['--output' => $output]));
        $this->assertFileDoesNotExist($output);
        $this->assertDatabaseCount('local_browser_sessions', 0);
    }

    public function test_account_creation_and_password_login_remain_closed_in_desktop_mode(): void
    {
        $data = ['email' => 'desktop@example.com', 'password' => 'test-desktop-password'];
        $this->postJson($this->origin.'/api/v1/account/login', $data)->assertNotFound();
        $this->postJson($this->origin.'/api/v1/account/register', $data)->assertNotFound();
        $this->postJson($this->origin.'/api/v1/account/forgot-password', $data)->assertNotFound();
        $this->postJson($this->origin.'/api/v1/account/reset-password', $data)->assertNotFound();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_desktop_account_identity_cannot_be_changed_or_deleted_through_legacy_forms(): void
    {
        $response = $this->exchange($this->launch())->assertOk();
        $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === LocalSession::cookieName());
        app('auth')->forgetGuards();
        $this->withToken($response->json('token'))->withHeader('Cookie', LocalSession::cookieName().'='.$cookie->getValue());
        $this->patchJson($this->origin.'/api/v1/account/profile', ['email' => 'changed@example.com', 'name' => 'Changed fixture'])->assertNotFound();
        $this->patchJson($this->origin.'/api/v1/account/password', ['current_password' => 'test-desktop-password', 'password' => 'test-new-password', 'password_confirmation' => 'test-new-password'])->assertNotFound();
        $this->postJson($this->origin.'/api/v1/account/deletion', ['confirm_text' => '注销账户', 'password' => 'test-desktop-password'])->assertNotFound();
        $this->assertSame('desktop@example.com', $this->owner->fresh()->email);
        $this->assertNull($this->owner->fresh()->deletion_requested_at);
    }

    public function test_desktop_pages_hide_account_forms_and_login_redirects_to_local_start(): void
    {
        $this->get($this->origin.'/login')->assertRedirect('/local-start');
        $this->get($this->origin.'/local-start')->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get($this->origin.'/')->assertOk()->assertDontSee('id="personalProfileForm"', false)
            ->assertDontSee('id="geoPasswordForm"', false)->assertDontSee('id="accountLogoutButton"', false)
            ->assertDontSee('id="accountMenuPhone"', false);
        config(['instance.desktop_mode' => false]);
        $this->get($this->origin.'/login')->assertOk();
        $this->get($this->origin.'/local-start')->assertNotFound();
        $this->get($this->origin.'/')->assertOk()->assertSee('id="personalProfileForm"', false)
            ->assertSee('id="geoPasswordForm"', false)->assertSee('id="accountLogoutButton"', false);
    }

    public function test_second_account_added_after_launch_cannot_receive_the_first_workspaces_session(): void
    {
        $ticket = $this->launch();
        app(LocalAccounts::class)->create('second@example.com', 'test-other-password', '另一个工作区', 'platform_admin');
        $this->exchange($ticket)->assertForbidden();
        $this->assertDatabaseCount('local_browser_sessions', 0);
    }

    public function test_owner_workspace_changed_after_launch_invalidates_the_ticket(): void
    {
        $ticket = $this->launch();
        $other = Tenant::create(['name' => 'Other fixture workspace', 'subdomain' => 'other-desktop-fixture', 'status' => 'active', 'settings' => []]);
        User::withoutGlobalScopes()->whereKey($this->owner->id)->update(['tenant_id' => $other->id]);
        $this->exchange($ticket)->assertForbidden();
        $this->assertDatabaseCount('local_browser_sessions', 0);
    }

    public function test_instance_key_change_invalidates_ticket_without_issuing_a_session(): void
    {
        $ticket = $this->launch();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('b', 32))]);
        $this->exchange($ticket)->assertForbidden();
        $this->assertDatabaseCount('local_browser_sessions', 0);
    }

    public function test_launch_file_must_be_new_and_inside_private_run_directory(): void
    {
        $outside = $this->root.'/browser-launch-outside-fixture.json';
        $this->assertSame(1, Artisan::call('yanji:desktop-launch', ['--output' => $outside]));
        $this->assertFileDoesNotExist($outside);
        $existing = $this->root.'/run/browser-launch-existing-fixture.json';
        file_put_contents($existing, 'preserved');
        $this->assertSame(1, Artisan::call('yanji:desktop-launch', ['--output' => $existing]));
        $this->assertSame('preserved', file_get_contents($existing));
    }

    public function test_non_regular_ticket_file_and_unix_symlink_are_rejected_without_modifying_existing_data(): void
    {
        $ticketFile = $this->root.'/run/desktop-ticket.json';
        mkdir($ticketFile, 0700);
        file_put_contents($ticketFile.'/preserved.json', 'preserved');
        $this->assertSame(1, Artisan::call('yanji:desktop-launch', ['--output' => $this->root.'/run/browser-launch-directory-fixture.json']));
        $this->assertSame('preserved', file_get_contents($ticketFile.'/preserved.json'));
        File::deleteDirectory($ticketFile);
        if (PHP_OS_FAMILY === 'Windows') {
            // The non-regular-file boundary above also runs without Windows symlink privileges.
            return;
        }
        $outside = $this->root.'/fixture-preserved.json';
        file_put_contents($outside, 'preserved');
        chmod($outside, 0600);
        symlink($outside, $ticketFile);
        $this->assertSame(1, Artisan::call('yanji:desktop-launch', ['--output' => $this->root.'/run/browser-launch-symlink-fixture.json']));
        $this->assertSame('preserved', file_get_contents($outside));
        $this->assertDatabaseCount('local_browser_sessions', 0);
    }

    public function test_missing_portable_marker_or_public_url_never_enables_desktop_access(): void
    {
        unlink($this->root.'/.portable-layout');
        $this->assertFalse(PortableDesktopAccess::enabled());
        file_put_contents($this->root.'/.portable-layout', "yanji-portable-v1\n");
        config(['app.url' => 'https://example.com']);
        $this->assertFalse(PortableDesktopAccess::enabled());
        config(['app.url' => 'http://user@127.0.0.1:8000']);
        $this->assertFalse(PortableDesktopAccess::enabled());
    }

    private function launch(): string
    {
        $output = $this->root.'/run/browser-launch-'.bin2hex(random_bytes(8)).'.json';
        $this->assertSame(0, Artisan::call('yanji:desktop-launch', ['--output' => $output]), Artisan::output());
        $this->assertSame('', Artisan::output());
        $launch = json_decode(file_get_contents($output), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsInt($launch['expires_at']);
        $this->assertSame(now()->getTimestamp() + 120, $launch['expires_at']);
        $this->assertStringStartsWith($this->origin.'/local-start#ticket=', $launch['url']);
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame(0, fileperms($output) & 0077);
        }

        return substr($launch['url'], strlen($this->origin.'/local-start#ticket='));
    }

    private function exchange(string $ticket): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($this->origin.'/api/v1/auth/local-start', ['ticket' => $ticket], ['Origin' => $this->origin]);
    }
}
