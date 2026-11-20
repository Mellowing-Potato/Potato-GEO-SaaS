<?php

namespace Tests\Feature;

use App\Contracts\Outbound\HostResolver;
use App\Contracts\Outbound\OutboundTransport;
use App\Models\ModelConnectionProfile;
use App\Models\Tenant;
use App\Services\AI\MagpieException;
use App\Services\AI\MagpieGateway;
use App\Services\AI\MagpieTargets;
use App\Services\AI\ModelConfiguration;
use App\Services\Outbound\FinalOutboundSecurityPolicy;
use App\Services\Outbound\MagpieRequestScope;
use App\Services\Outbound\OutboundRequestBlockedException;
use App\Services\Outbound\ResolvedOutboundTarget;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Services\Outbound\SecureHttpFactory;
use App\Support\TenantContext;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class MagpieOutboundTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'http://host.docker.internal:3425';

    private const KEY = 'sk-test-magpie';

    private const ROUTE = '团队/长路由组:Stable';

    private array $requests = [];

    private array $responses = [];

    private array $ips = [];

    private Tenant $tenant;

    private MagpieGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Magpie', 'subdomain' => 'magpie-test', 'status' => 'active']);
        TenantContext::set($this->tenant);
        config(['geo.magpie_targets.local' => [
            'origin' => self::ORIGIN,
            'tenant_ids' => [$this->tenant->id],
            'auth_mode' => 'gateway_key',
            'enabled' => true,
            'revision' => 1,
        ]]);
        $this->ips = [
            'host.docker.internal' => ['192.168.65.254'],
            'alternate.example.test' => ['192.168.65.254'],
            'public.example.test' => ['93.184.216.34'],
        ];
        $resolver = new class(fn (string $host): array => $this->ips[$host] ?? []) implements HostResolver
        {
            public function __construct(private readonly \Closure $resolve) {}

            public function resolve(string $host): array
            {
                return ($this->resolve)($host);
            }
        };
        $transport = new class(function (string $method, ResolvedOutboundTarget $target, array $data, array $headers): Response {
            $this->requests[] = ['method' => $method, 'url' => $target->url,
                'selected_ip' => $target->selectedIp, 'data' => $data, 'headers' => $headers];
            $response = array_shift($this->responses);
            $this->assertNotNull($response, 'Unexpected outbound request.');

            return $response instanceof \Closure ? $response() : $response;
        }) implements OutboundTransport
        {

            public function __construct(private readonly \Closure $send) {}

            public function send(PendingRequest $request, string $method, ResolvedOutboundTarget $target, array $data,
                int $maxBytes, bool $crossOrigin = false): Response
            {
                return ($this->send)($method, $target, $data, $request->getOptions()['headers'] ?? []);
            }
        };
        $this->app->instance(SafeOutboundHttpClient::class, new SafeOutboundHttpClient($resolver, $transport));
        $this->gateway = $this->app->make(MagpieGateway::class);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    public function test_g05_scoped_identity_and_catalog_then_the_selected_completion_without_cross_tenant_access(): void
    {
        $profile = $this->profile();
        $this->responses = [
            $this->hello(),
            $this->response(['data' => [
                ['id' => self::ROUTE, 'modalities' => ['input' => ['text', 'image']]],
                ['id' => 'example/illustration', 'kind' => 'image'],
            ]]),
            $this->response(['choices' => [['message' => ['content' => 'example']]]]),
        ];
        $this->assertSame([
            'id' => self::ROUTE,
            'display_name' => self::ROUTE,
            'image_input' => true,
            'json_object' => null,
            'json_schema' => null,
        ], $this->gateway->model($profile));

        $other = Tenant::create(['name' => 'Other', 'subdomain' => 'other-magpie-test', 'status' => 'active']);
        TenantContext::set($other);
        $this->assertSame([], app(MagpieTargets::class)->available());
        $this->assertRefused('target_unauthorized', fn () => $this->gateway->directory($profile));
        $this->assertCount(2, $this->requests);
        TenantContext::set($this->tenant);

        $this->assertTrue($this->gateway->post($profile, ['model' => self::ROUTE, 'stream' => true], 10)->ok());
        $this->assertSame([
            ['GET', self::ORIGIN.'/api/hello'],
            ['GET', self::ORIGIN.'/v1/models'],
            ['POST', self::ORIGIN.'/v1/chat/completions'],
        ], array_map(fn (array $request): array => [$request['method'], $request['url']], $this->requests));
        $this->assertArrayNotHasKey('Authorization', $this->requests[0]['headers']);
        $this->assertSame('Bearer '.self::KEY, $this->requests[1]['headers']['Authorization']);
        $this->assertSame('Bearer '.self::KEY, $this->requests[2]['headers']['Authorization']);
        $this->assertSame(false, $this->requests[2]['data']['stream']);
        $this->assertSame(self::ROUTE, $this->requests[2]['data']['model']);
        $this->assertSame(['192.168.65.254'], array_unique(array_column($this->requests, 'selected_ip')));

        config(['geo.magpie_targets.local.enabled' => false]);
        $this->assertRefused('target_unauthorized', fn () => $this->gateway->post($profile, ['model' => self::ROUTE], 10));
        $this->assertCount(3, $this->requests);
    }

    public function test_g06_reservation_blocks_custom_models_aliases_and_forged_credentials_even_with_global_allowlist(): void
    {
        config([
            'geo.outbound_enforce' => false,
            'geo.magpie_targets.local.enabled' => false,
            'geo.outbound_private_targets' => [
                'host.docker.internal:3425', '127.0.0.1:3425', 'localhost:3425', 'alternate.example.test:3425',
            ],
        ]);
        $outbound = app(SafeOutboundHttpClient::class);
        $http = app(SecureHttpFactory::class);
        $this->responses = [$this->response(['data' => []])];
        foreach ([self::ORIGIN, 'http://127.0.0.1:3425', 'http://localhost:3425', 'http://alternate.example.test:3425',
            'http://192.168.65.254:3425'] as $origin) {
            $this->assertRefused('magpie_target_reserved', fn () => $outbound->get(
                $http->withHeaders(['Authorization' => 'Bearer sk-test-fake']), $origin.'/v1/models', 256,
            ));
        }

        try {
            app(ModelConfiguration::class)->validate([
                'slot' => 'text', 'provider' => 'custom', 'base_url' => 'http://alternate.example.test:3425/v1',
                'model' => 'demo', 'api_key' => self::KEY,
            ], $this->tenant->id);
            $this->fail('The legacy custom-model validator accepted a reserved destination.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('base_url', $e->errors());
        }

        config(['geo.magpie_targets.local.origin' => 'http://127.0.0.1:3425']);
        $this->assertRefused('magpie_target_reserved', fn () => $outbound->resolveTarget(self::ORIGIN.'/v1/models'));
        $this->assertCount(0, $this->requests);
        $this->responses = [$this->response(['data' => []])];
        $this->assertTrue($outbound->get($http->timeout(5), 'https://public.example.test:3425/v1/models', 256)->ok());
        $this->assertCount(1, $this->requests);
    }

    public function test_g07_scope_checks_path_query_method_and_current_context_at_transport(): void
    {
        $scope = MagpieRequestScope::forProfile(app(MagpieTargets::class), $this->profile(), '/v1/models');
        $outbound = app(SafeOutboundHttpClient::class);
        $request = app(SecureHttpFactory::class)->timeout(3);
        foreach ([
            ['GET', self::ORIGIN.'/v1/models?kind=all', []],
            ['GET', self::ORIGIN.'/api/hello', []],
            ['HEAD', $scope->url(), []],
            ['GET', $scope->url(), ['kind' => 'all']],
            ['GET', 'http://alternate.example.test:3425/v1/models', []],
        ] as [$method, $url, $query]) {
            $this->assertRefused('magpie_operation_forbidden',
                fn () => $outbound->send($request, $method, $url, $query, 1024, magpie: $scope));
        }

        $target = $outbound->resolveTarget($scope->url(), $scope);
        $final = app(FinalOutboundSecurityPolicy::class)->fixedTargetMiddleware([], $target, 'GET', false, 1024, false)(
            fn () => $this->fail('The terminal handler ran after the workspace changed.'),
        );
        TenantContext::forget();
        $this->assertRefused('magpie_target_unauthorized', fn () => $final(new Request('GET', $scope->url()), []));
        $this->assertCount(0, $this->requests);
    }

    public function test_g07_denies_redirects_and_ip_changes_between_identity_and_catalog(): void
    {
        $profile = $this->profile();
        $this->responses = [new Response(new PsrResponse(307, ['Location' => self::ORIGIN.'/v1/models']))];
        $this->assertRefused('invalid_catalog', fn () => $this->gateway->directory($profile, refresh: true));
        $this->assertCount(1, $this->requests);

        $this->responses = [function (): Response {
            $this->ips['host.docker.internal'] = ['192.168.65.253'];

            return $this->hello();
        }];
        $this->assertRefused('connection_changed', fn () => $this->gateway->directory($profile, refresh: true));
        $this->assertCount(2, $this->requests);

        $this->ips['host.docker.internal'] = ['192.168.65.254', '93.184.216.34'];
        $this->assertRefused('target_unauthorized', fn () => $this->gateway->directory($profile, refresh: true));
        $this->assertCount(2, $this->requests);
    }

    public function test_g07_uses_deployers_static_pins_when_no_dns_records_exist_and_never_uses_a_conflicting_answer(): void
    {
        $this->ips['host.docker.internal'] = [];
        config(['geo.magpie_targets.local.addresses' => ['192.168.65.254']]);
        $profile = $this->profile();
        $this->responses = [$this->hello(), $this->response(['data' => [['id' => self::ROUTE]]])];
        $this->assertSame(self::ROUTE, $this->gateway->model($profile)['id']);
        $this->assertSame(['192.168.65.254'], array_unique(array_column($this->requests, 'selected_ip')));

        $this->assertRefused('magpie_target_reserved', fn () => app(SafeOutboundHttpClient::class)->resolveTarget(
            'http://192.168.65.254:3425/v1/models',
        ));
        $this->ips['host.docker.internal'] = ['192.168.65.253'];
        $this->assertRefused('target_unauthorized', fn () => $this->gateway->directory($profile, refresh: true));
        $this->assertCount(2, $this->requests);
    }

    public function test_g09_public_hello_does_not_confirm_gateway_key_and_failed_checks_prevent_completion(): void
    {
        $profile = $this->profile();
        $this->responses = [$this->hello(), $this->response(['error' => ['message' => self::KEY]], 401)];
        $this->assertRefused('authentication_failed', fn () => $this->gateway->post($profile, ['model' => self::ROUTE], 10));
        $this->assertCount(2, $this->requests);

        $profile->update(['credential' => 'magpie-yanji', 'revision' => 2]);
        $this->assertRefused('invalid_credential', fn () => $this->gateway->directory($profile, refresh: true));
        $this->assertCount(2, $this->requests);

        $profile->update(['credential' => self::KEY, 'revision' => 3]);
        $this->responses = [$this->response(['name' => 'example', 'version' => '1'])];
        $this->assertRefused('invalid_identity', fn () => $this->gateway->post($profile, ['model' => self::ROUTE], 10));
        $this->assertCount(3, $this->requests);
    }

    public function test_g09_loopback_identity_requires_a_deployers_loopback_authorization(): void
    {
        config(['geo.magpie_targets.local.auth_mode' => 'loopback']);
        $this->assertRefused('target_unauthorized', fn () => app(MagpieTargets::class)->forTenant('local'));

        config(['geo.magpie_targets.local.origin' => 'http://127.0.0.1:3425']);
        $profile = $this->profile();
        $profile->update(['credential' => null, 'revision' => 2]);
        $this->responses = [$this->hello(), $this->response(['data' => [['id' => self::ROUTE]]])];
        $this->gateway->directory($profile, refresh: true);
        $this->assertArrayNotHasKey('Authorization', $this->requests[0]['headers']);
        $this->assertSame('Bearer magpie-yanji', $this->requests[1]['headers']['Authorization']);
        $this->assertSame(['127.0.0.1'], array_unique(array_column($this->requests, 'selected_ip')));
    }

    public function test_g10_profile_revision_change_during_probe_does_not_publish_stale_directory(): void
    {
        $profile = $this->profile();
        $this->responses = [$this->hello(), function () use ($profile): Response {
            ModelConnectionProfile::query()->whereKey($profile->id)->update(['revision' => 2]);

            return $this->response(['data' => [['id' => self::ROUTE]]]);
        }];
        $this->assertRefused('connection_changed', fn () => $this->gateway->directory($profile));
        $this->assertCount(2, $this->requests);
        $this->assertRefused('connection_changed', fn () => $this->gateway->directory($profile));
        $this->assertCount(2, $this->requests);

        $this->responses = [$this->hello(), $this->response(['data' => [['id' => 'new/available']]])];
        $this->assertSame('new/available', $this->gateway->directory($profile->fresh())['models'][0]['id']);
        $this->assertCount(4, $this->requests);
    }

    public function test_g10_refresh_preserves_ids_and_does_not_replace_a_removed_model(): void
    {
        $profile = $this->profile();
        $this->responses = [
            $this->hello(), $this->response(['data' => [['id' => self::ROUTE]]]),
            $this->hello(), $this->response(['data' => [['id' => 'new/available']]]),
            $this->hello(), $this->response(['data' => []]),
        ];
        $this->assertSame(self::ROUTE, $this->gateway->model($profile)['id']);
        $this->assertNull($this->gateway->directory($profile)['models'][0]['image_input']);
        $this->assertCount(2, $this->requests);
        $this->assertRefused('model_unavailable', fn () => $this->gateway->model($profile));
        $this->assertSame('new/available', $this->gateway->directory($profile)['models'][0]['id']);
        $this->assertRefused('empty_catalog', fn () => $this->gateway->model($profile));
        $this->assertSame(self::ROUTE, $profile->fresh()->model);
        $this->assertSame(['GET'], array_unique(array_column($this->requests, 'method')));
    }

    private function profile(): ModelConnectionProfile
    {
        $target = app(MagpieTargets::class)->forTenant('local');

        return ModelConnectionProfile::query()->create([
            'tenant_id' => $this->tenant->id, 'slot' => 'text', 'provider' => 'magpie',
            'target_alias' => $target->alias, 'target_revision' => $target->revision, 'base_url' => $target->baseUrl,
            'model' => self::ROUTE, 'auth_mode' => $target->authMode, 'credential' => self::KEY, 'enabled' => true,
        ])->refresh();
    }

    private function assertRefused(string $reason, callable $callback): void
    {
        try {
            $callback();
            $this->fail('The expected refusal did not happen.');
        } catch (MagpieException|OutboundRequestBlockedException $e) {
            $this->assertSame($reason, $e instanceof MagpieException ? $e->reason : $e->reasonCode);
        }
    }

    private function hello(): Response
    {
        return $this->response(['name' => 'magpie', 'version' => '0.1.0']);
    }

    private function response(array $body, int $status = 200): Response
    {
        return new Response(new PsrResponse($status, [], json_encode($body, JSON_THROW_ON_ERROR)));
    }
}
