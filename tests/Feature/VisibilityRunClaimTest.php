<?php

namespace Tests\Feature;

use App\Models\AiVisibilityRun;
use App\Models\ApiIdempotencyKey;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Api\IdempotencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VisibilityRunClaimTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = '/api/v1/visibility/runs';

    private const BODY = ['project_id' => 7, 'questions' => ['一个监测问题']];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.tenant_domain' => 'geo.example.test',
            'geo.visibility_panel_enabled' => true,
            'geo.visibility_run_claim_required' => true]);
    }

    public function test_claim_survives_lost_browser_storage_and_blocks_unclaimed_paid_post(): void
    {
        [$tenant, $user] = $this->owner();
        Sanctum::actingAs($user, ['*']);

        $this->forTenant($tenant)->postJson(self::PATH, self::BODY,
            ['X-Idempotency-Key' => 'unclaimed-key'])->assertStatus(409);
        $this->assertDatabaseCount('api_idempotency_keys', 0);

        $first = $this->forTenant($tenant)->postJson(self::PATH.'/claim',
            ['payload' => self::BODY, 'request_key' => 'first-browser-key'])
            ->assertOk()->assertJsonPath('request_key', 'first-browser-key');
        $this->forTenant($tenant)->postJson(self::PATH.'/claim', ['payload' => self::BODY])
            ->assertOk()->assertJsonPath('request_key', $first->json('request_key'));
        $this->forTenant($tenant)->postJson(self::PATH,
            ['project_id' => 8, 'questions' => self::BODY['questions']],
            ['X-Idempotency-Key' => 'first-browser-key'])->assertStatus(409);
        $this->forTenant($tenant)->postJson(self::PATH, self::BODY)
            ->assertStatus(409);
        $this->assertDatabaseCount('visibility_run_claims', 1);
        $this->assertDatabaseCount('api_idempotency_keys', 0);
    }

    public function test_claim_and_recovery_are_scoped_to_exact_tenant_user_and_body(): void
    {
        [$tenant, $user] = $this->owner();
        $other = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        Sanctum::actingAs($user, ['*']);
        $this->forTenant($tenant)->postJson(self::PATH.'/claim',
            ['payload' => self::BODY, 'request_key' => 'user-one-key'])
            ->assertOk()->assertJsonPath('request_key', 'user-one-key');

        Sanctum::actingAs($other, ['*']);
        $this->forTenant($tenant)->postJson(self::PATH, self::BODY,
            ['X-Idempotency-Key' => 'user-one-key'])->assertStatus(409);
        $this->forTenant($tenant)->postJson(self::PATH.'/claim',
            ['payload' => self::BODY, 'request_key' => 'user-two-key'])
            ->assertOk()->assertJsonPath('request_key', 'user-two-key');
        $this->forTenant($tenant)->postJson(self::PATH.'/claim', ['payload' => [
            'project_id' => 7, 'questions' => ['另一个问题'],
        ]])->assertOk()->assertJsonMissing(['request_key' => 'user-two-key']);
    }

    public function test_unseen_legacy_response_is_recovered_and_confirmed_before_new_claim(): void
    {
        [$tenant, $user] = $this->owner();
        Sanctum::actingAs($user, ['*']);
        $hash = IdempotencyService::requestHash(self::BODY);
        ApiIdempotencyKey::query()->create([
            'idempotency_key' => 'old-browser-key',
            'route_key' => 'api/v1/visibility/runs', 'request_hash' => $hash,
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'response_status' => 202,
            'response_body' => json_encode(['batch_id' => 'old-batch', 'runs' => [['id' => 1]]]),
        ]);

        $this->forTenant($tenant)->postJson(self::PATH.'/claim',
            ['payload' => self::BODY, 'request_key' => 'new-browser-key'])
            ->assertOk()->assertJsonPath('request_key', 'old-browser-key')
            ->assertJsonPath('accepted', true);
        $this->forTenant($tenant)->postJson(self::PATH.'/seen',
            ['request_key' => 'old-browser-key'])->assertStatus(409);

        AiVisibilityRun::query()->create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'project_id' => 7, 'batch_id' => 'old-batch',
            'question_index' => 0, 'question' => '一个监测问题',
            'status' => 'pending',
        ]);
        $this->forTenant($tenant)->postJson(self::PATH.'/seen',
            ['request_key' => 'old-browser-key'])->assertOk();
        $this->assertNotNull(ApiIdempotencyKey::query()
            ->where('idempotency_key', 'old-browser-key')->firstOrFail()->client_seen_at);
        $this->forTenant($tenant)->postJson(self::PATH.'/claim',
            ['payload' => self::BODY, 'request_key' => 'truly-new-key'])
            ->assertOk()->assertJsonPath('request_key', 'truly-new-key');
    }

    public function test_ambiguous_legacy_rows_fail_closed(): void
    {
        [$tenant, $user] = $this->owner();
        Sanctum::actingAs($user, ['*']);
        foreach (['old-one', 'old-two'] as $key) {
            ApiIdempotencyKey::query()->create([
                'idempotency_key' => $key,
                'route_key' => 'api/v1/visibility/runs',
                'request_hash' => IdempotencyService::requestHash(self::BODY),
                'tenant_id' => $tenant->id, 'user_id' => $user->id,
            ]);
        }
        $this->forTenant($tenant)->postJson(self::PATH.'/claim',
            ['payload' => self::BODY])->assertStatus(409);
    }

    public function test_known_pre_dispatch_balance_rejection_can_receive_new_claim(): void
    {
        [$tenant, $user] = $this->owner();
        Sanctum::actingAs($user, ['*']);
        $this->forTenant($tenant)->postJson(self::PATH.'/claim', [
            'payload' => self::BODY, 'request_key' => 'balance-denied',
        ])->assertOk();
        ApiIdempotencyKey::query()->create([
            'idempotency_key' => 'balance-denied',
            'route_key' => 'api/v1/visibility/runs',
            'request_hash' => IdempotencyService::requestHash(self::BODY),
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'response_status' => 402,
            'response_body' => json_encode(['message' => 'insufficient balance']),
        ]);
        $this->forTenant($tenant)->postJson(self::PATH.'/claim', [
            'payload' => self::BODY, 'request_key' => 'after-rejection',
        ])->assertOk()->assertJsonPath('request_key', 'after-rejection');
    }

    private function owner(): array
    {
        $tenant = Tenant::query()->create([
            'subdomain' => 'claim-test', 'name' => 'Claim Test',
            'plan' => 'basic', 'status' => 'active',
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);

        return [$tenant, $user];
    }

    private function forTenant(Tenant $tenant): self
    {
        return $this->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }
}
