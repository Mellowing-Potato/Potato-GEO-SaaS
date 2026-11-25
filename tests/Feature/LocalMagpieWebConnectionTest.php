<?php

namespace Tests\Feature;

use App\Models\ModelSlotBinding;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AI\MagpieException;
use App\Services\AI\MagpieTargets;
use App\Services\AI\ModelSources;
use App\Services\Auth\LocalAccounts;
use App\Services\Outbound\MagpieRequestScope;
use App\Services\Outbound\SecureHttpFactory;
use App\Support\InstanceEnvironment;
use App\Support\InstanceKey;
use App\Support\LocalSession;
use App\Support\TenantContext;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Yanji\Portable\Platform;

final class LocalMagpieWebConnectionTest extends TestCase
{
    use RefreshDatabase;

    private string $root;
    private string $path;
    private string $origin = 'http://127.0.0.1:8000';
    private User $user;
    private array $hello = ['name' => 'magpie', 'version' => 'test-desktop'];
    private ?\Closure $onHello = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/magpie-web-'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($this->root, 0700);
        require_once base_path('portable/src/Platform.php');
        Platform::protectPrivate($this->root, true);
        file_put_contents($this->root.'/.portable-layout', "yanji-portable-v1\n");
        $this->path = $this->root.'/.env';
        file_put_contents($this->path, 'APP_KEY='.config('app.key')."\nYANJI_INSTANCE_ID=".str_repeat('a', 32)
            ."\nGEO_MAGPIE_TARGETS='{}'\n# retained fixture comment\nUNRELATED=retained\n");
        Platform::protectPrivate($this->path, false);
        $this->app->useEnvironmentPath($this->root)->loadEnvironmentFrom('.env');
        Tenant::create(['name' => 'Other fixture workspace', 'subdomain' => 'other-magpie-web', 'status' => 'active']);
        $this->user = app(LocalAccounts::class)->create('desktop@example.com', 'test-desktop-password', '本机用户', 'platform_admin');
        InstanceKey::bind($this->path);
        config(['app.url' => $this->origin, 'instance.portable' => true, 'instance.desktop_mode' => true,
            'instance.portable_root' => $this->root, 'geo.magpie_targets' => []]);
        $session = LocalSession::issue($this->user, Request::create($this->origin));
        $cookie = collect($session->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === LocalSession::cookieName());
        app('auth')->forgetGuards();
        $this->withToken($session->getData(true)['token'])->withHeader('Cookie', LocalSession::cookieName().'='.$cookie->getValue())
            ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        Http::swap(app(SecureHttpFactory::class));
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/api/hello') {
                if ($this->onHello !== null) {
                    ($this->onHello)();
                }

                return Http::response($this->hello);
            }
            if ($path === '/v1/models') {
                return Http::response(['data' => [['id' => 'fixture-chat', 'modalities' => ['input' => ['text']]]]]);
            }
            throw new \LogicException('No generation or other network request is allowed in this fixture.');
        });
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_one_click_authorizes_the_authenticated_workspace_and_is_immediately_visible_without_restart(): void
    {
        $this->assertGreaterThan(1, $this->user->tenant_id);
        $this->getJson($this->origin.'/api/v1/ai-keys/magpie')->assertOk()->assertJsonPath('local_connect_available', true)
            ->assertJsonPath('targets', []);
        Http::assertNothingSent();
        $response = $this->connect()->assertOk()->assertJsonPath('local_target_alias', 'local')
            ->assertJsonPath('local_target.auth_mode', 'loopback')->assertJsonPath('service.version', 'test-desktop');
        $this->assertCount(1, $response->json('targets'));
        $this->assertSame([$this->user->tenant_id], $this->targets()['local']['tenant_ids']);
        $this->assertSame([], config('geo.magpie_targets'), 'The long-running process retains its old cached config.');
        $this->assertDatabaseCount('model_slot_bindings', 0);
        $this->assertDatabaseCount('model_connection_profiles', 0);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === 'http://127.0.0.1:3425/api/hello'
            && ! $request->hasHeader('Authorization') && ! $request->hasHeader('Cookie'));
        $this->getJson($this->origin.'/api/v1/ai-keys/magpie')->assertOk()->assertJsonPath('targets.0.alias', 'local');
        Http::assertSentCount(1);
    }

    public function test_new_connection_can_read_the_directory_without_a_restart_and_never_activates_or_generates(): void
    {
        $this->connect()->assertOk();
        $saved = $this->postJson($this->origin.'/api/v1/ai-keys/magpie/profiles', ['slot' => 'text', 'target_alias' => 'local', 'model' => null])
            ->assertCreated();
        $this->postJson($this->origin.'/api/v1/ai-keys/magpie/profiles/'.$saved->json('id').'/directory')
            ->assertOk()->assertJsonPath('models.0.id', 'fixture-chat');
        Http::assertSentCount(3);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $this->assertDatabaseCount('model_slot_bindings', 0);
    }

    public function test_desktop_displays_the_connection_button_for_both_supported_slots_and_server_does_not(): void
    {
        $desktop = $this->get($this->origin.'/')->assertOk();
        $this->assertSame(2, substr_count($desktop->getContent(), 'data-magpie-local-connect'));
        config(['instance.desktop_mode' => false]);
        $this->get($this->origin.'/')->assertOk()->assertDontSee('data-magpie-local-connect', false);
        Http::assertNothingSent();
    }

    public function test_existing_alias_other_authorizations_profiles_and_model_bindings_are_preserved(): void
    {
        $existing = ['desktop' => ['origin' => 'http://127.0.0.1:3425', 'tenant_ids' => [71], 'auth_mode' => 'loopback', 'enabled' => true, 'revision' => 9],
            'other' => ['origin' => 'https://gateway.example.com', 'tenant_ids' => [$this->user->tenant_id],
                'auth_mode' => 'gateway_key', 'enabled' => true, 'revision' => 13, 'addresses' => ['192.0.2.17']]];
        $this->writeTargets($existing);
        TenantContext::set($this->user->tenant);
        app(ModelSources::class)->save(['slot' => 'text', 'target_alias' => 'other', 'model' => 'fixture-existing', 'api_key' => 'sk-test-preserved']);
        ModelSlotBinding::create(['tenant_id' => $this->user->tenant_id, 'slot' => 'text', 'source_type' => 'legacy']);
        $profiles = DB::table('model_connection_profiles')->get()->toJson();
        $bindings = DB::table('model_slot_bindings')->get()->toJson();
        TenantContext::forget();
        $this->connect()->assertOk()->assertJsonPath('local_target_alias', 'desktop');
        $existing['desktop']['tenant_ids'][] = $this->user->tenant_id;
        $this->assertSame($existing, $this->targets());
        $this->assertSame($profiles, DB::table('model_connection_profiles')->get()->toJson());
        $this->assertSame($bindings, DB::table('model_slot_bindings')->get()->toJson());
        $contents = file_get_contents($this->path);
        $this->assertStringContainsString('# retained fixture comment', $contents);
        $this->assertSame('retained', Dotenv::parse($contents)['UNRELATED']);
        Http::assertSentCount(1);
    }

    #[DataProvider('invalidConsentProvider')]
    public function test_no_explicit_consent_or_client_selected_targets_never_probe_or_modify_configuration(array $input): void
    {
        $before = file_get_contents($this->path);
        $this->connect($input)->assertUnprocessable();
        Http::assertNothingSent();
        $this->assertSame($before, file_get_contents($this->path));
    }

    public static function invalidConsentProvider(): array
    {
        return ['absent' => [[]], 'false' => [['consent' => false]], 'string' => [['consent' => 'true']],
            'other workspace' => [['consent' => true, 'tenant_id' => 1]],
            'other URL' => [['consent' => true, 'url' => 'https://example.com']],
            'credential injection' => [['consent' => true, 'api_key' => 'sk-test-never-send']]];
    }

    #[DataProvider('invalidLocalRequestProvider')]
    public function test_request_must_be_direct_same_origin_loopback_before_any_probe(array $server, array $headers, ?string $url = null): void
    {
        $before = file_get_contents($this->path);
        if ($url !== null) {
            // Resolve the caller's own workspace so the local-origin guard, rather than tenant routing, rejects this host.
            $this->withHeader('X-Tenant', $this->user->tenant->subdomain);
        }
        $this->withServerVariables($server)->postJson(($url ?? $this->origin).'/api/v1/ai-keys/magpie/local-connect', ['consent' => true],
            ['Origin' => $this->origin, ...$headers])->assertForbidden();
        Http::assertNothingSent();
        $this->assertSame($before, file_get_contents($this->path));
    }

    public static function invalidLocalRequestProvider(): array
    {
        return ['remote peer' => [['REMOTE_ADDR' => '192.0.2.10'], []],
            'cross origin' => [[], ['Origin' => 'https://example.com']],
            'missing origin' => [[], ['Origin' => '']],
            'proxy' => [[], ['X-Forwarded-For' => '127.0.0.1']],
            'forwarded' => [[], ['Forwarded' => 'for=127.0.0.1']],
            'wrong host' => [[], [], 'http://localhost:8000']];
    }

    public function test_non_json_server_mode_and_unauthenticated_calls_cannot_probe(): void
    {
        $this->post($this->origin.'/api/v1/ai-keys/magpie/local-connect', ['consent' => true],
            ['Origin' => $this->origin, 'Accept' => 'application/json'])->assertForbidden();
        config(['instance.portable' => false]);
        $this->connect()->assertNotFound();
        config(['instance.portable' => true]);
        app('auth')->forgetGuards();
        $this->withToken('')->withHeader('Cookie', '');
        $this->connect()->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_an_unreachable_or_non_magpie_service_cannot_be_authorized(): void
    {
        $before = file_get_contents($this->path);
        $this->hello = ['name' => 'different-service', 'version' => 'test'];
        $this->connect()->assertUnprocessable()->assertJsonPath('code', 'invalid_identity');
        $this->assertSame($before, file_get_contents($this->path));
        $this->onHello = fn () => throw new ConnectionException('Fixture connection unavailable');
        $this->connect()->assertUnprocessable()->assertJsonPath('code', 'unreachable');
        $this->assertSame($before, file_get_contents($this->path));
        $this->assertDatabaseCount('model_connection_profiles', 0);
        $this->assertDatabaseCount('model_slot_bindings', 0);
    }

    public function test_disabled_target_is_not_silently_enabled_by_a_web_request(): void
    {
        $this->writeTargets(['local' => ['origin' => 'http://127.0.0.1:3425', 'tenant_ids' => [71],
            'auth_mode' => 'loopback', 'enabled' => false, 'revision' => 7]]);
        $before = file_get_contents($this->path);
        $this->connect()->assertUnprocessable()->assertJsonPath('code', 'local_authorization_failed');
        $this->assertSame($before, file_get_contents($this->path));
        Http::assertSentCount(1);
    }

    public function test_environment_replaced_during_probe_never_receives_the_old_workspace_authorization(): void
    {
        $changed = null;
        $this->onHello = function () use (&$changed): void {
            $contents = str_replace(str_repeat('a', 32), str_repeat('b', 32), file_get_contents($this->path));
            file_put_contents($this->path, $contents);
            $changed = $contents;
        };
        $this->connect()->assertUnprocessable()->assertJsonPath('code', 'local_authorization_failed');
        $this->assertSame($changed, file_get_contents($this->path));
        $this->assertSame([], $this->targets());
    }

    public function test_workspace_changed_during_probe_never_receives_authorization(): void
    {
        $before = file_get_contents($this->path);
        $this->onHello = fn () => User::withoutGlobalScopes()->whereKey($this->user->id)->update(['tenant_id' => 1]);
        $this->connect()->assertForbidden();
        $this->assertSame($before, file_get_contents($this->path));
    }

    public function test_changes_saved_during_the_identity_probe_are_preserved_when_granting_access(): void
    {
        $concurrent = ['other' => ['origin' => 'https://gateway.example.com', 'tenant_ids' => [71],
            'auth_mode' => 'gateway_key', 'enabled' => false, 'revision' => 17]];
        $this->onHello = function () use ($concurrent): void {
            $this->writeTargets($concurrent);
            InstanceEnvironment::update($this->path, fn ($contents) => str_replace('UNRELATED=retained', 'UNRELATED=concurrent-fixture', $contents));
        };
        $this->connect()->assertOk();
        $after = $this->targets();
        $this->assertSame($concurrent['other'], $after['other']);
        $this->assertSame([$this->user->tenant_id], $after['local']['tenant_ids']);
        $this->assertSame('concurrent-fixture', Dotenv::parse(file_get_contents($this->path))['UNRELATED']);
        Http::assertSentCount(1);
    }

    public function test_long_lived_target_object_observes_grants_and_revocation_instead_of_cached_permissions(): void
    {
        $targets = app(MagpieTargets::class);
        TenantContext::set($this->user->tenant);
        $this->assertSame([], $targets->available());
        TenantContext::forget();
        $this->connect()->assertOk();
        TenantContext::set($this->user->tenant);
        $this->assertSame(['local'], array_column($targets->available(), 'alias'));
        $profile = app(ModelSources::class)->save(['slot' => 'text', 'target_alias' => 'local', 'model' => 'fixture-chat']);
        $scope = MagpieRequestScope::forProfile($targets, $profile, '/v1/chat/completions');
        $scope->currentProfile();
        config(['geo.magpie_targets' => $this->targets()]);
        $current = $this->targets();
        $current['local']['enabled'] = false;
        $this->writeTargets($current);
        $this->assertSame([], $targets->available());
        try {
            $scope->currentProfile();
            $this->fail('Revoked permissions must not survive in a long-running worker.');
        } catch (MagpieException $error) {
            $this->assertSame('target_unauthorized', $error->reason);
        }
        $this->assertArrayHasKey('127.0.0.1', $targets->reservedHosts(3425), 'Disabled targets remain reserved to general outbound requests.');
        Http::assertSentCount(1);
    }

    public function test_malformed_or_rebound_environment_fails_closed_instead_of_using_stale_permissions(): void
    {
        $this->connect()->assertOk();
        config(['geo.magpie_targets' => $this->targets()]);
        TenantContext::set($this->user->tenant);
        $before = file_get_contents($this->path);
        foreach ([$before."GEO_MAGPIE_TARGETS='{}'\n", str_replace(str_repeat('a', 32), str_repeat('b', 32), $before)] as $contents) {
            file_put_contents($this->path, $contents);
            try {
                app(MagpieTargets::class)->available();
                $this->fail('Invalid live authorization must not use the cached allow-list.');
            } catch (MagpieException $error) {
                $this->assertSame('target_unauthorized', $error->reason);
            }
        }
        Http::assertSentCount(1);
    }

    private function connect(array $input = ['consent' => true]): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($this->origin.'/api/v1/ai-keys/magpie/local-connect', $input, ['Origin' => $this->origin]);
    }

    private function targets(): array
    {
        return json_decode(Dotenv::parse(file_get_contents($this->path))['GEO_MAGPIE_TARGETS'], true, flags: JSON_THROW_ON_ERROR);
    }

    private function writeTargets(array $targets): void
    {
        InstanceEnvironment::update($this->path, fn ($contents) => preg_replace('/^GEO_MAGPIE_TARGETS=.*$/m',
            "GEO_MAGPIE_TARGETS='".json_encode($targets, JSON_THROW_ON_ERROR)."'", $contents));
    }
}
