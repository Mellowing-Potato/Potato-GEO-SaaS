<?php

namespace Tests\Feature;

use App\Contracts\Outbound\HostResolver;
use App\Contracts\Outbound\OutboundTransport;
use App\Models\ModelConnectionProfile;
use App\Models\ModelSlotBinding;
use App\Models\Tenant;
use App\Models\TenantAiKey;
use App\Models\User;
use App\Services\AI\ModelConfiguration;
use App\Services\AI\ModelConnectionResolver;
use App\Services\AI\ModelConnectionUnavailable;
use App\Services\Outbound\ResolvedOutboundTarget;
use App\Support\TenantContext;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class MagpieModelSourcesTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/v1/ai-keys/magpie';

    private const MODEL_ID = 'group/团队写作 · model';

    private Tenant $tenant;

    private object $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->workspace('magpie-workspace');
        config(['app.tenant_domain' => 'geo.example.test', 'geo.magpie_targets' => [
            'magpie-main' => $this->target('http://127.0.0.1:3425'),
            'magpie-other-port' => $this->target('http://127.0.0.1:3426'),
            'magpie-loopback' => $this->target('http://127.0.0.1:3427', 'loopback'),
        ]]);
        $this->transport = new class implements OutboundTransport
        {
            public array $requests = [];

            public ?\Closure $onCatalog = null;

            public function send(PendingRequest $request, string $method, ResolvedOutboundTarget $target,
                array $data, int $maxBytes, bool $crossOrigin = false): Response
            {
                $path = parse_url($target->url, PHP_URL_PATH);
                $this->requests[] = [$method, $path];
                if ($path === '/v1/models' && $this->onCatalog) {
                    ($this->onCatalog)();
                }
                $body = match ($path) {
                    '/api/hello' => ['name' => 'magpie', 'version' => 'test-version'],
                    '/v1/models' => ['data' => [
                        ['id' => 'group/团队写作 · model', 'modalities' => ['input' => ['text', 'image']]],
                        ['id' => 'text-only', 'modalities' => ['input' => ['text']]],
                        ['id' => 'unknown-capability'],
                    ]],
                    default => throw new \LogicException('Unexpected inference in configuration test.'),
                };

                return new Response(new PsrResponse(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR)));
            }
        };
        $this->app->instance(OutboundTransport::class, $this->transport);
        $this->app->bind(HostResolver::class, fn () => new class implements HostResolver
        {
            public function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
        $this->login($this->tenant);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    public function test_upgrade_keeps_all_four_legacy_slots_and_unassigned_history_byte_for_byte(): void
    {
        TenantContext::set($this->tenant);
        $migration = require database_path('migrations/2026_11_20_000001_create_model_connection_profiles_and_bindings.php');
        $migration->down();

        foreach (['text', 'vision', 'embedding', 'visibility', null] as $slot) {
            $this->legacy($slot);
        }
        $rows = DB::table('tenant_ai_keys')->orderBy('id')->get()->toArray();
        $migration->up();

        $this->assertEquals($rows, DB::table('tenant_ai_keys')->orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('model_connection_profiles', 0);
        $this->assertDatabaseCount('model_slot_bindings', 0);
        $config = app(ModelConfiguration::class);
        foreach (['text', 'vision', 'embedding', 'visibility'] as $slot) {
            $this->assertSame('sk-test-'.$slot, $config->active($slot)->getDecryptedKey());
        }
        TenantAiKey::query()->whereNotNull('slot')->delete();
        $this->assertNull($config->active('text'));
    }

    public function test_saving_drafts_and_switching_both_slots_preserves_legacy_rows_and_credentials(): void
    {
        TenantContext::set($this->tenant);
        $text = $this->legacy('text');
        $vision = $this->legacy('vision');
        $before = DB::table('tenant_ai_keys')->orderBy('id')->get()->toArray();

        foreach ([$text, $vision] as $legacy) {
            $draft = $this->postJson(self::API.'/profiles', $this->profileInput($legacy->slot, ['model' => null]))
                ->assertCreated()->assertJsonPath('model', null);
            $id = $draft->json('id');
            $preview = $draft->json('key_preview');
            $this->assertStringNotContainsString('sk-test-magpie', $draft->getContent());
            $this->assertNotSame('sk-test-magpie', DB::table('model_connection_profiles')->where('id', $id)->value('credential'));

            $this->getJson(self::API)->assertOk()->assertJsonPath('bindings.'.$legacy->slot.'.source_type', 'legacy');
            $this->select($legacy->slot, 'profile', $id)->assertUnprocessable()->assertJsonPath('code', 'model_required');
            $this->postJson(self::API."/profiles/{$id}/directory")->assertOk()
                ->assertJsonPath('models.0.id', self::MODEL_ID)->assertJsonPath('models.0.image_input', true);
            $this->patchJson(self::API."/profiles/{$id}", ['model' => self::MODEL_ID, 'api_key' => $preview])
                ->assertUnprocessable()->assertJsonValidationErrors('api_key');
            $this->patchJson(self::API."/profiles/{$id}", ['model' => self::MODEL_ID, 'api_key' => '', 'revision' => 1])
                ->assertOk()->assertJsonPath('revision', 2);
            $this->patchJson(self::API."/profiles/{$id}", ['label' => 'stale review', 'revision' => 1])->assertConflict();
            $this->assertSame('sk-test-magpie', ModelConnectionProfile::withoutGlobalScopes()->findOrFail($id)->credential);
            $this->select($legacy->slot, 'profile', $id)->assertOk()->assertJsonPath('source_type', 'profile');
            $this->getJson(self::API)->assertOk()->assertJsonPath('bindings.'.$legacy->slot.'.profile_id', $id);

            $this->deleteJson(self::API."/profiles/{$id}")->assertConflict();
            $this->select($legacy->slot, 'legacy')->assertOk()->assertJsonPath('profile_id', null);
            $this->deleteJson(self::API."/profiles/{$id}")->assertOk();
        }

        $this->assertEquals($before, DB::table('tenant_ai_keys')->orderBy('id')->get()->toArray());
        $this->getJson('/api/v1/ai-keys')->assertOk()->assertJsonCount(2, 'data');
        $this->assertDatabaseCount('model_connection_profiles', 0);
    }

    public function test_same_profile_cannot_reuse_gateway_key_for_changed_endpoint_or_target(): void
    {
        $profileId = $this->postJson(self::API.'/profiles', $this->profileInput('text'))->assertCreated()->json('id');
        $this->postJson(self::API.'/profiles', $this->profileInput('vision', ['api_key' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors('api_key');
        $this->patchJson(self::API."/profiles/{$profileId}", ['target_alias' => 'magpie-other-port'])
            ->assertUnprocessable()->assertJsonValidationErrors('api_key');
        $this->patchJson(self::API."/profiles/{$profileId}", ['target_alias' => 'magpie-other-port', 'api_key' => 'magpie-geo'])
            ->assertUnprocessable()->assertJsonValidationErrors('api_key');
        config(['geo.magpie_targets.magpie-main.origin' => 'http://127.0.0.1:3428']);
        $this->patchJson(self::API."/profiles/{$profileId}", ['label' => 'same alias'])
            ->assertUnprocessable()->assertJsonValidationErrors('api_key');
        $this->patchJson(self::API."/profiles/{$profileId}", ['target_alias' => 'magpie-other-port', 'api_key' => 'sk-test-new-gateway'])
            ->assertOk()->assertJsonPath('base_url', 'http://127.0.0.1:3426/v1')->assertJsonPath('model', null);
        $this->patchJson(self::API."/profiles/{$profileId}", ['target_alias' => 'magpie-loopback'])
            ->assertOk()->assertJsonPath('auth_mode', 'loopback')->assertJsonPath('key_preview', null);
        $this->assertNull(ModelConnectionProfile::withoutGlobalScopes()->findOrFail($profileId)->credential);
    }

    public function test_cross_workspace_ids_are_rejected_before_any_transport_and_composite_fk_rejects_binding(): void
    {
        $profileId = $this->postJson(self::API.'/profiles', $this->profileInput('text'))->assertCreated()->json('id');
        $other = $this->workspace('magpie-other-workspace');
        $this->login($other);
        $this->getJson(self::API)->assertOk()->assertJsonCount(0, 'targets')->assertJsonCount(0, 'profiles');
        $this->postJson(self::API.'/profiles', $this->profileInput('text', ['tenant_id' => $this->tenant->id, 'authorized' => true]))
            ->assertForbidden();
        $this->patchJson(self::API."/profiles/{$profileId}", ['enabled' => false])->assertNotFound();
        $this->postJson(self::API."/profiles/{$profileId}/directory")->assertNotFound();
        $this->postJson(self::API."/profiles/{$profileId}/test")->assertNotFound();
        $this->select('text', 'profile', $profileId)->assertNotFound();
        $this->deleteJson(self::API."/profiles/{$profileId}")->assertNotFound();
        $this->assertSame([], $this->transport->requests);

        $this->expectException(QueryException::class);
        DB::table('model_slot_bindings')->insert([
            'tenant_id' => $other->id, 'slot' => 'text', 'source_type' => 'profile', 'profile_id' => $profileId,
        ]);
    }

    public function test_active_profile_disabled_and_invalid_bindings_fail_without_legacy_fallback(): void
    {
        $id = $this->postJson(self::API.'/profiles', $this->profileInput('text'))->assertCreated()->json('id');
        $this->select('text', 'profile', $id)->assertOk();
        $this->patchJson(self::API."/profiles/{$id}", ['model' => null])->assertOk();
        $this->postJson(self::API."/profiles/{$id}/test")->assertUnprocessable()->assertJsonPath('status', 'failed');
        TenantContext::set($this->tenant);
        $this->legacy('text');
        $this->assertFalse(app(ModelConnectionResolver::class)->availability('text')['available']);
        $this->patchJson(self::API."/profiles/{$id}", ['model' => self::MODEL_ID, 'enabled' => false])->assertOk();
        $this->getJson(self::API)->assertOk()->assertJsonPath('bindings.text.profile_id', $id);
        TenantContext::set($this->tenant);
        $resolver = app(ModelConnectionResolver::class);
        $this->assertUnavailable($resolver);
        $binding = ModelSlotBinding::query()->where('slot', 'text')->firstOrFail();
        $binding->update(['profile_id' => null]);
        $this->assertUnavailable($resolver);
        $binding->update(['source_type' => 'disabled']);
        $this->assertUnavailable($resolver);
        $binding->delete();
        $this->assertTrue($resolver->availability('text')['available']);
    }

    public function test_late_directory_response_cannot_activate_a_changed_profile(): void
    {
        $id = $this->postJson(self::API.'/profiles', $this->profileInput('text'))->assertCreated()->json('id');
        $this->transport->onCatalog = fn () => DB::table('model_connection_profiles')->where('id', $id)->increment('revision');
        $response = $this->select('text', 'profile', $id);
        $this->assertContains($response->status(), [409, 422]);
        $this->assertDatabaseCount('model_slot_bindings', 0);
    }

    private function target(string $origin, string $authMode = 'gateway_key'): array
    {
        return ['origin' => $origin, 'tenant_ids' => [$this->tenant->id],
            'auth_mode' => $authMode, 'enabled' => true, 'revision' => 1];
    }

    private function profileInput(string $slot, array $overrides = []): array
    {
        return array_replace(['slot' => $slot, 'target_alias' => 'magpie-main',
            'api_key' => 'sk-test-magpie', 'model' => self::MODEL_ID], $overrides);
    }

    private function workspace(string $subdomain): Tenant
    {
        return Tenant::create(['name' => $subdomain, 'subdomain' => $subdomain, 'status' => 'active']);
    }

    private function login(Tenant $tenant): void
    {
        TenantContext::forget();
        Sanctum::actingAs(User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']), ['*']);
        $this->withServerVariables(['HTTP_HOST' => 'geo.example.test'])->withHeader('X-Tenant', $tenant->subdomain);
    }

    private function legacy(?string $slot): TenantAiKey
    {
        return TenantAiKey::create(['tenant_id' => $this->tenant->id, 'provider' => 'custom',
            'slot' => $slot, 'encrypted_key' => 'sk-test-'.($slot ?? 'unassigned'), 'is_active' => true,
            'metadata' => ['base_url' => 'https://example.com/v1', 'model' => 'legacy-model', 'json_mode' => false]]);
    }

    private function select(string $slot, string $source, ?int $id = null): \Illuminate\Testing\TestResponse
    {
        return $this->putJson(self::API.'/bindings/'.$slot, ['source_type' => $source] + ($id === null ? [] : ['profile_id' => $id]));
    }

    private function assertUnavailable(ModelConnectionResolver $resolver): void
    {
        try {
            $resolver->resolve('text');
            $this->fail('Expected this binding to be unavailable.');
        } catch (ModelConnectionUnavailable) {
            $this->assertFalse($resolver->availability('text')['available']);
        }
    }
}
