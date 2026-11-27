<?php

namespace Tests\Feature;

use App\Models\ApiIdempotencyKey;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Api\IdempotencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiTokenIdempotencySecurityTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTE = 'api/v1/api-tokens';
    private const PAYLOAD = ['name' => 'Test integration', 'scopes' => ['articles:read']];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function account(string $slug): array
    {
        $tenant = Tenant::create(['name' => $slug, 'subdomain' => $slug, 'plan' => 'basic', 'status' => 'active']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        Sanctum::actingAs($user, ['*']);
        $this->withHeader('X-Tenant', $slug);

        return [$tenant, $user];
    }

    private function issue(string $key = 'test-token-issue')
    {
        return $this->postJson('/'.self::ROUTE, self::PAYLOAD, ['X-Idempotency-Key' => $key]);
    }

    public function test_http_issuance_caches_only_a_receipt_and_replays_without_minting_another_token(): void
    {
        $this->account('token-receipt');
        $first = $this->issue()->assertCreated();
        $secret = $first->json('token');
        $this->assertIsString($secret);
        $this->assertNotSame('', $secret);
        $raw = (string) DB::table('api_idempotency_keys')->value('response_body');
        $this->assertStringNotContainsString($secret, $raw, 'The raw database must not contain the issued credential.');
        $this->assertArrayNotHasKey('token', json_decode(Crypt::decryptString($raw), true));
        $this->issue()->assertCreated()->assertJsonMissingPath('token')
            ->assertJsonPath('record.id', $first->json('record.id'))
            ->assertJsonPath('token_available', false)
            ->assertJsonPath('action', 'api_token_already_issued');
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('api_idempotency_keys', 1);
        Http::assertNothingSent();
    }

    public function test_direct_insert_and_reserved_update_never_store_the_plaintext_credential(): void
    {
        [$tenant, $user] = $this->account('token-store');
        $payload = ['object' => 'api_token', 'token' => 'sk-test-idempotency-secret',
            'record' => ['id' => 42, 'name' => 'Test integration', 'status' => 'active']];
        $hash = IdempotencyService::requestHash(self::PAYLOAD);
        foreach (['insert', 'reserved'] as $key) {
            if ($key === 'reserved') {
                $this->assertTrue(IdempotencyService::reserve($key, self::ROUTE, $hash, $tenant->id, $user->id));
            }
            IdempotencyService::store($key, self::ROUTE, $hash, $payload, 201, $tenant->id, $user->id);
            $raw = (string) DB::table('api_idempotency_keys')->where('idempotency_key', $key)->value('response_body');
            $this->assertStringNotContainsString($payload['token'], $raw, 'Credential persisted by '.$key.' branch');
            $this->assertArrayNotHasKey('token', json_decode(Crypt::decryptString($raw), true));
        }
    }

    public function test_a_legacy_cached_token_is_neither_returned_nor_left_in_the_database(): void
    {
        $this->account('token-legacy');
        $first = $this->issue()->assertCreated();
        // Simulate a response cached before this fix, without changing its owner/key/hash.
        DB::table('api_idempotency_keys')->update(['response_body' => json_encode($first->json(), JSON_THROW_ON_ERROR)]);
        $this->issue()->assertCreated()->assertJsonMissingPath('token')
            ->assertJsonPath('record.id', $first->json('record.id'));
        $this->assertStringNotContainsString($first->json('token'), (string) DB::table('api_idempotency_keys')->value('response_body'));
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_replaying_after_revocation_cannot_claim_the_token_is_active_or_reissue_it(): void
    {
        $this->account('token-revoked');
        $first = $this->issue()->assertCreated();
        $this->deleteJson('/'.self::ROUTE.'/'.$first->json('record.id'))->assertOk();
        $this->issue()->assertStatus(410)->assertJsonMissingPath('token')
            ->assertJsonPath('action', 'api_token_unavailable')
            ->assertJsonPath('record.status', 'unavailable');
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('api_idempotency_keys', 1);
    }

    public function test_replaying_an_expired_token_is_not_a_new_issuance(): void
    {
        $this->account('token-expired');
        $first = $this->issue()->assertCreated();
        DB::table('personal_access_tokens')->where('id', $first->json('record.id'))->update(['expires_at' => now()->subMinute()]);
        $this->issue()->assertStatus(410)->assertJsonMissingPath('token')->assertJsonPath('action', 'api_token_unavailable');
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_key_reuse_remains_scoped_to_the_account_and_workspace_and_rejects_changed_payloads(): void
    {
        [$tenantA, $userA] = $this->account('token-owner-a');
        $first = $this->issue('shared-test-key')->assertCreated();
        [$tenantB, $userB] = $this->account('token-owner-b');
        $second = $this->issue('shared-test-key')->assertCreated();
        $this->assertNotSame($first->json('record.id'), $second->json('record.id'));
        $this->assertNotSame($first->json('token'), $second->json('token'));
        $this->withHeader('X-Tenant', $tenantA->subdomain)->issue('shared-test-key')->assertForbidden();
        $this->withHeader('X-Tenant', $tenantB->subdomain)
            ->postJson('/'.self::ROUTE, ['name' => 'Changed', 'scopes' => ['articles:read']], ['X-Idempotency-Key' => 'shared-test-key'])
            ->assertConflict();
        // The same tenant with another user cannot load A's receipt via the common service.
        $this->assertNull(IdempotencyService::loadReplay('shared-test-key', self::ROUTE,
            IdempotencyService::requestHash(self::PAYLOAD), $tenantA->id, $userB->id));
        $this->assertDatabaseCount('personal_access_tokens', 2);
        $this->assertDatabaseCount('api_idempotency_keys', 2);
    }

    public function test_a_pending_token_request_is_retained_and_does_not_mint_again(): void
    {
        [$tenant, $user] = $this->account('token-pending');
        IdempotencyService::reserve('test-token-issue', self::ROUTE,
            IdempotencyService::requestHash(self::PAYLOAD), $tenant->id, $user->id);
        $this->issue()->assertConflict()->assertJsonPath('action', 'idempotency_result_pending');
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('api_idempotency_keys', 1);
    }
}
