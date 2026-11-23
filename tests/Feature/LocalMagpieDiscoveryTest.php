<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\AI\MagpieException;
use App\Services\Outbound\LocalMagpieDiscovery;
use App\Services\Outbound\MagpieRequestScope;
use App\Services\Outbound\OutboundRequestBlockedException;
use App\Services\Outbound\SecureHttpFactory;
use App\Support\LocalMagpieAuthorization;
use App\Support\TenantContext;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class LocalMagpieDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'http://127.0.0.1:3425/api/hello';
    private string $directory;
    private Tenant $tenant;
    private mixed $response;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::create(['name' => 'Other fixture', 'subdomain' => 'other-local-probe', 'status' => 'active']);
        $this->tenant = Tenant::create(['name' => 'Local fixture', 'subdomain' => 'local-probe', 'status' => 'active']);
        TenantContext::set($this->tenant);
        $this->directory = sys_get_temp_dir().'/yanji-magpie-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $this->app->useEnvironmentPath($this->directory)->loadEnvironmentFrom('.env');
        \App\Models\User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'platform_admin']);
        file_put_contents($this->directory.'/.env', 'APP_KEY='.config('app.key')."\nYANJI_INSTANCE_ID=".str_repeat('a', 32)."\n# retained comment\nUNRELATED=retained\n");
        \App\Support\InstanceKey::bind($this->directory.'/.env');
        Http::swap(app(SecureHttpFactory::class));
        Http::preventStrayRequests();
        $this->response = Http::response(['name' => 'magpie', 'version' => '1.0.0']);
        Http::fake(fn () => $this->response);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        foreach (glob($this->directory.'/{*,.*}', GLOB_BRACE) ?: [] as $path) {
            if (is_file($path)) { unlink($path); }
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_no_consent_never_sends_a_request_or_updates_configuration(): void
    {
        $before = file_get_contents($this->directory.'/.env');
        $this->artisan('yanji:magpie', ['--tenant' => $this->tenant->id, '--no-interaction' => true])->assertExitCode(0);
        Http::assertNothingSent();
        $this->assertSame($before, file_get_contents($this->directory.'/.env'));
        try {
            app(LocalMagpieDiscovery::class)->probe();
            $this->fail('Discovery requires explicit consent.');
        } catch (MagpieException $e) {
            $this->assertSame('target_unauthorized', $e->reason);
        }
        Http::assertNothingSent();
    }

    public function test_scope_allows_only_the_exact_get_identity_endpoint_in_the_original_tenant(): void
    {
        $scope = MagpieRequestScope::forLocalDiscovery(true);
        $scope->assertAuthorized('GET', self::URL);
        foreach ([['POST', self::URL], ['GET', self::URL.'?x=1'], ['GET', 'http://127.0.0.1:3425/v1/models'],
            ['GET', 'http://localhost:3425/api/hello'], ['GET', 'http://127.0.0.2:3425/api/hello'],
            ['GET', 'https://127.0.0.1:3425/api/hello'], ['GET', 'http://127.0.0.1:3426/api/hello']] as [$method, $url]) {
            try {
                $scope->assertAuthorized($method, $url);
                $this->fail('The discovery scope accepted a wider operation.');
            } catch (OutboundRequestBlockedException) {
                $this->addToAssertionCount(1);
            }
        }
        TenantContext::forget();
        $this->expectException(OutboundRequestBlockedException::class);
        $scope->assertAuthorized('GET', self::URL);
    }

    public function test_terminal_discovery_discards_injected_credentials_proxies_and_redirects(): void
    {
        $scope = MagpieRequestScope::forLocalDiscovery(true);
        $target = new \App\Services\Outbound\ResolvedOutboundTarget(self::URL, 'http', '127.0.0.1', 3425,
            ['127.0.0.1'], '127.0.0.1', $scope);
        $seen = null;
        $handler = function ($request, $options) use (&$seen) {
            $seen = [$request, $options];
            return new \GuzzleHttp\Psr7\Response(200, [], '{"name":"magpie","version":"1"}');
        };
        $policy = new \App\Services\Outbound\FinalOutboundSecurityPolicy(fn () => true);
        $middleware = $policy->fixedTargetMiddleware(['timeout' => 2, 'connect_timeout' => 1], $target,
            'GET', false, 16384, false);
        $middleware($handler)(new \GuzzleHttp\Psr7\Request('GET', self::URL, [
            'Authorization' => 'Bearer sk-test-never-send', 'Cookie' => 'fixture=private',
            'X-Api-Key' => 'sk-test-never-send',
        ]), ['proxy' => 'https://proxy.example.test', 'allow_redirects' => true]);
        [$request, $options] = $seen;
        foreach (['Authorization', 'Cookie', 'X-Api-Key'] as $name) {
            $this->assertFalse($request->hasHeader($name));
        }
        $this->assertSame(self::URL, (string) $request->getUri());
        $this->assertSame('', $options['proxy']);
        $this->assertFalse($options['allow_redirects']);
        $this->assertSame(2, $options['timeout']);
        $this->assertSame(1, $options['connect_timeout']);
    }

    public function test_probe_is_one_bounded_identity_request_and_does_not_activate_models(): void
    {
        $before = file_get_contents($this->directory.'/.env');
        $this->artisan('yanji:magpie', ['--tenant' => $this->tenant->id, '--probe' => true, '--no-interaction' => true])->assertExitCode(0);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === self::URL
            && ! $request->hasHeader('Authorization') && ! $request->hasHeader('Cookie'));
        $this->assertSame($before, file_get_contents($this->directory.'/.env'));
        $this->assertDatabaseCount('model_slot_bindings', 0);
        $this->assertDatabaseCount('model_connection_profiles', 0);
    }

    public function test_authorization_merges_actual_tenant_without_erasing_other_targets_or_revisions(): void
    {
        $targets = ['local' => ['origin' => 'http://127.0.0.1:3425', 'tenant_ids' => [71], 'auth_mode' => 'loopback', 'enabled' => true, 'revision' => 9],
            'other' => ['origin' => 'https://gateway.example.test', 'tenant_ids' => [72], 'auth_mode' => 'gateway_key', 'enabled' => false, 'revision' => 13]];
        $path = $this->directory.'/.env';
        file_put_contents($path, "GEO_MAGPIE_TARGETS='".json_encode($targets)."'\n", FILE_APPEND);
        $this->artisan('yanji:magpie', ['--tenant' => $this->tenant->id, '--probe' => true, '--authorize' => true, '--no-interaction' => true])->assertExitCode(0);
        $parsed = Dotenv::parse(file_get_contents($path));
        $after = json_decode($parsed['GEO_MAGPIE_TARGETS'], true, flags: JSON_THROW_ON_ERROR);
        $targets['local']['tenant_ids'][] = $this->tenant->id;
        $this->assertSame($targets, $after);
        $this->assertSame(config('app.key'), $parsed['APP_KEY']);
        $this->assertSame('retained', $parsed['UNRELATED']);
        $this->assertStringContainsString('# retained comment', file_get_contents($path));
        $before = file_get_contents($path);
        app(LocalMagpieAuthorization::class)->grant($path, $this->tenant->id);
        $this->assertSame($before, file_get_contents($path), 'Reauthorization must be idempotent.');
        $this->assertDatabaseCount('model_slot_bindings', 0);
    }

    public function test_disabled_local_target_and_invalid_identity_cannot_write_authorization(): void
    {
        $path = $this->directory.'/.env';
        $targets = ['local' => ['origin' => 'http://127.0.0.1:3425', 'tenant_ids' => [71], 'auth_mode' => 'loopback', 'enabled' => false, 'revision' => 12]];
        file_put_contents($path, "GEO_MAGPIE_TARGETS='".json_encode($targets)."'\n", FILE_APPEND);
        $before = file_get_contents($path);
        $this->artisan('yanji:magpie', ['--tenant' => $this->tenant->id, '--probe' => true, '--authorize' => true, '--no-interaction' => true])->assertExitCode(1);
        $this->assertSame($before, file_get_contents($path));
        $this->response = Http::response(['name' => 'different', 'version' => '1.0.0']);
        $this->artisan('yanji:magpie', ['--tenant' => $this->tenant->id, '--probe' => true, '--authorize' => true, '--no-interaction' => true])->assertExitCode(1);
        $this->assertSame($before, file_get_contents($path));
    }

    public function test_multiline_authorization_preserves_unrelated_literals_and_refuses_duplicate_definitions(): void
    {
        $path = $this->directory.'/.env';
        $target = ['other' => ['origin' => 'https://gateway.example.test', 'tenant_ids' => [71],
            'auth_mode' => 'gateway_key', 'enabled' => false, 'revision' => 17, 'note' => "quote' dollar$1 back\\slash"]];
        $json = json_encode($target, JSON_PRETTY_PRINT | JSON_HEX_APOS);
        $escaped = str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $json);
        file_put_contents($path, '  export GEO_MAGPIE_TARGETS="'.$escaped."\"\n", FILE_APPEND);
        app(LocalMagpieAuthorization::class)->grant($path, $this->tenant->id);
        $parsed = Dotenv::parse(file_get_contents($path));
        $after = json_decode($parsed['GEO_MAGPIE_TARGETS'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($target['other'], $after['other']);
        $this->assertSame([$this->tenant->id], $after['local']['tenant_ids']);
        $this->assertSame(config('app.key'), $parsed['APP_KEY']);
        file_put_contents($path, "GEO_MAGPIE_TARGETS='{}'\n", FILE_APPEND);
        $before = file_get_contents($path);
        try {
            app(LocalMagpieAuthorization::class)->grant($path, $this->tenant->id);
            $this->fail('Duplicate definitions must be reviewed before editing.');
        } catch (\RuntimeException) {
            $this->assertSame($before, file_get_contents($path));
        }
    }

    public function test_authorization_invalidates_only_this_instances_cached_configuration(): void
    {
        $cache = $this->directory.'/config.php';
        $other = $this->directory.'/other-config.php';
        file_put_contents($other, 'unrelated-instance-fixture');
        $env = ['APP_ENV' => 'testing', 'YANJI_ENV_PATH' => $this->directory, 'APP_KEY' => false,
            'APP_CONFIG_CACHE' => $cache, 'APP_PACKAGES_CACHE' => $this->directory.'/packages.php',
            'APP_SERVICES_CACHE' => $this->directory.'/services.php', 'APP_EVENTS_CACHE' => $this->directory.'/events.php',
            'APP_ROUTES_CACHE' => $this->directory.'/routes.php', 'LARAVEL_STORAGE_PATH' => $this->directory.'/storage',
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
            'GEO_MAGPIE_TARGETS' => false, 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array'];
        $build = new \Symfony\Component\Process\Process([PHP_BINARY, 'artisan', 'config:cache'], base_path(), $env);
        $build->mustRun();
        $this->assertFileExists($cache);
        $inspect = <<<'CODE'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
App\Support\TenantContext::set((new App\Models\Tenant)->forceFill(['id' => (int) $argv[1]]));
echo json_encode(app(App\Services\AI\MagpieTargets::class)->available(), JSON_THROW_ON_ERROR);
CODE;
        $read = fn () => (new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $inspect, (string) $this->tenant->id], base_path(), $env))->mustRun()->getOutput();
        $this->assertSame([], json_decode($read(), true, flags: JSON_THROW_ON_ERROR));
        $previous = [getenv('APP_CONFIG_CACHE'), $_ENV['APP_CONFIG_CACHE'] ?? null, $_SERVER['APP_CONFIG_CACHE'] ?? null];
        putenv('APP_CONFIG_CACHE='.$cache);
        $_ENV['APP_CONFIG_CACHE'] = $_SERVER['APP_CONFIG_CACHE'] = $cache;
        try {
            $this->artisan('yanji:magpie', ['--tenant' => $this->tenant->id, '--probe' => true,
                '--authorize' => true, '--no-interaction' => true])->assertExitCode(0);
            $this->assertFileDoesNotExist($cache);
            $this->assertSame('unrelated-instance-fixture', file_get_contents($other));
            $this->assertSame(['local'], array_column(json_decode($read(), true, flags: JSON_THROW_ON_ERROR), 'alias'));
        } finally {
            $previous[0] === false ? putenv('APP_CONFIG_CACHE') : putenv('APP_CONFIG_CACHE='.$previous[0]);
            if ($previous[1] === null) { unset($_ENV['APP_CONFIG_CACHE']); } else { $_ENV['APP_CONFIG_CACHE'] = $previous[1]; }
            if ($previous[2] === null) { unset($_SERVER['APP_CONFIG_CACHE']); } else { $_SERVER['APP_CONFIG_CACHE'] = $previous[2]; }
            \Illuminate\Support\Facades\File::deleteDirectory($this->directory.'/storage');
        }
    }

    public function test_redirect_and_oversized_identity_are_rejected_without_following_or_writing(): void
    {
        $path = $this->directory.'/.env';
        $before = file_get_contents($path);
        foreach ([Http::response('', 302, ['Location' => 'https://example.com/']),
            Http::response(str_repeat('x', 16385))] as $response) {
            $this->response = $response;
            $this->artisan('yanji:magpie', ['--tenant' => $this->tenant->id, '--probe' => true, '--authorize' => true, '--no-interaction' => true])->assertExitCode(1);
            $this->assertSame($before, file_get_contents($path));
        }
        Http::assertSentCount(2);
    }
}
