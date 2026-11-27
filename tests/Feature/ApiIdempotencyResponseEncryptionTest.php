<?php

namespace Tests\Feature;

use App\Models\ApiIdempotencyKey;
use App\Models\PublishingChannel;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Api\IdempotencyService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ApiIdempotencyResponseEncryptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_real_channel_secret_response_is_encrypted_and_replay_does_not_rotate_it(): void
    {
        $tenant = Tenant::create(['name' => 'Channel test', 'subdomain' => 'channel-cache', 'status' => 'active']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        TenantContext::set($tenant);
        $channel = PublishingChannel::create(['name' => 'Test channel', 'type' => PublishingChannel::TYPE_WORDPRESS_REST,
            'endpoint_url' => 'https://example.com/wp-json', 'is_active' => true]);
        TenantContext::forget();
        Sanctum::actingAs($user, ['*']);
        $this->withHeader('X-Tenant', $tenant->subdomain);
        $url = '/api/v1/publishing/channels/'.$channel->id.'/secrets';
        $headers = ['X-Idempotency-Key' => 'test-channel-secret'];
        $first = $this->postJson($url, [], $headers)->assertCreated();
        $secret = $first->json('secret');
        $this->assertNotEmpty($secret);
        $raw = (string) DB::table('api_idempotency_keys')->value('response_body');
        $this->assertStringNotContainsString($secret, $raw);
        $this->assertSame($first->json(), json_decode(Crypt::decryptString($raw), true));
        $this->postJson($url, [], $headers)->assertCreated()->assertExactJson($first->json());
        $this->assertDatabaseCount('distribution_channel_secrets', 1);
        Http::assertNothingSent();
    }

    public function test_nested_draft_credentials_and_non_success_payloads_replay_exactly_without_plaintext_storage(): void
    {
        foreach ([201, 422, 503] as $status) {
            foreach ([false, true] as $reserved) {
                $key = 'test-response-'.$status.'-'.(int) $reserved;
                $payload = ['request_token' => 'sk-test-draft-request', 'payload' => ['token' => 'sk-test-draft-payload'], 'action' => 'test'];
                if ($reserved) {
                    IdempotencyService::reserve($key, 'api/v1/test-draft/start', 'hash', 1, 2);
                }
                IdempotencyService::store($key, 'api/v1/test-draft/start', 'hash', $payload, $status, 1, 2);
                $row = ApiIdempotencyKey::query()->where('idempotency_key', $key)->firstOrFail();
                $raw = (string) DB::table('api_idempotency_keys')->where('id', $row->id)->value('response_body');
                $this->assertStringNotContainsString('sk-test-', $raw);
                $this->assertSame($payload, json_decode($row->response_body, true));
                $this->assertArrayNotHasKey('response_body', $row->toArray());
                $this->assertSame(['state' => 'completed', 'status' => $status, 'payload' => $payload],
                    IdempotencyService::loadReplay($key, 'api/v1/test-draft/start', 'hash', 1, 2));
            }
        }
    }

    public function test_title_recovery_marker_remains_comparable_and_reservation_is_not_recreated(): void
    {
        $this->assertTrue(IdempotencyService::reserve('test-title-marker', 'api/v1/materials/titles/generate', 'hash', 1, 2, true));
        $raw = (string) DB::table('api_idempotency_keys')->value('response_body');
        $this->assertStringNotContainsString('title_generation_v2', $raw);
        $this->assertSame('{"_recoverable":"title_generation_v2"}', ApiIdempotencyKey::first()->response_body);
        $this->assertFalse(IdempotencyService::reserve('test-title-marker', 'api/v1/materials/titles/generate', 'hash', 1, 2, true));
        $this->assertSame(['state' => 'pending'], IdempotencyService::loadReplay('test-title-marker', 'api/v1/materials/titles/generate', 'hash', 1, 2));
        $this->assertDatabaseCount('api_idempotency_keys', 1);
    }

    public function test_tampered_ciphertext_fails_closed_without_discarding_the_original_reservation(): void
    {
        IdempotencyService::store('test-broken', 'api/v1/test-draft/start', 'hash', ['token' => 'sk-test-sensitive'], 201, 1, 2);
        DB::table('api_idempotency_keys')->update(['response_body' => 'invalid-encrypted-response']);
        try {
            IdempotencyService::loadReplay('test-broken', 'api/v1/test-draft/start', 'hash', 1, 2);
            $this->fail('Corrupt ciphertext must not be treated as an absent request.');
        } catch (ConflictHttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertStringNotContainsString('sk-test-sensitive', $e->getMessage());
        }
        $this->assertDatabaseCount('api_idempotency_keys', 1);
        $this->assertFalse(IdempotencyService::reserve('test-broken', 'api/v1/test-draft/start', 'hash', 1, 2));
    }

    public function test_cleanup_migration_removes_old_token_copies_and_encrypts_other_receipts_without_deleting_claims(): void
    {
        $bodies = [
            ['api/v1/api-tokens', 201, json_encode(['object' => 'api_token', 'token' => 'sk-test-old-api', 'record' => ['id' => 3, 'status' => 'active']])],
            ['api/v1/api-tokens/', null, '{broken sk-test-broken-secret'],
            ['api/v1/materials/titles/generate', null, '{"_recoverable":"title_generation_v2"}'],
            ['api/v1/test-draft/start', 201, json_encode(['payload' => ['token' => 'sk-test-old-draft']])],
            ['api/v1/api-tokens', 422, json_encode(['message' => 'Invalid name', 'errors' => ['name' => ['Required']]])],
        ];
        foreach ($bodies as $i => [$route, $status, $body]) {
            DB::table('api_idempotency_keys')->insert(['idempotency_key' => 'migration-'.$i, 'route_key' => $route,
                'request_hash' => 'hash-'.$i, 'tenant_id' => 1, 'user_id' => 2,
                'response_status' => $status, 'response_body' => $body]);
        }
        $migration = require database_path('migrations/2026_11_27_000002_remove_credentials_from_api_token_replay_cache.php');
        $migration->up();
        $migration->up(); // Safe to rerun after a partially completed upgrade.
        $migration->down(); // Rollback must not reconstruct plaintext secrets.
        $this->assertDatabaseCount('api_idempotency_keys', 5);
        foreach (DB::table('api_idempotency_keys')->orderBy('id')->get() as $row) {
            $this->assertStringNotContainsString('sk-test-', (string) $row->response_body);
            $this->assertSame(1, (int) $row->tenant_id);
            $this->assertSame(2, (int) $row->user_id);
        }
        $token = json_decode(ApiIdempotencyKey::where('idempotency_key', 'migration-0')->first()->response_body, true);
        $this->assertArrayNotHasKey('token', $token);
        $this->assertSame(3, $token['record']['id']);
        $this->assertNull(ApiIdempotencyKey::where('idempotency_key', 'migration-1')->first()->response_body);
        $this->assertSame(['state' => 'pending'], IdempotencyService::loadReplay('migration-1', 'api/v1/api-tokens/', 'hash-1', 1, 2));
        $this->assertSame('{"_recoverable":"title_generation_v2"}', ApiIdempotencyKey::where('idempotency_key', 'migration-2')->first()->response_body);
        $this->assertSame(['payload' => ['token' => 'sk-test-old-draft']], IdempotencyService::loadReplay('migration-3', 'api/v1/test-draft/start', 'hash-3', 1, 2)['payload']);
        $this->assertSame(['state' => 'completed', 'status' => 422, 'payload' => json_decode($bodies[4][2], true)],
            IdempotencyService::loadReplay('migration-4', 'api/v1/api-tokens', 'hash-4', 1, 2));
    }

    public function test_migration_refuses_an_unreadable_ciphertext_without_destroying_the_receipt(): void
    {
        $otherCipher = new \Illuminate\Encryption\Encrypter(random_bytes(32), 'AES-256-CBC');
        $body = $otherCipher->encryptString('{"token":"sk-test-other-instance"}');
        DB::table('api_idempotency_keys')->insert(['idempotency_key' => 'wrong-key',
            'route_key' => 'api/v1/api-tokens', 'request_hash' => 'hash', 'response_body' => $body, 'response_status' => 201]);
        $migration = require database_path('migrations/2026_11_27_000002_remove_credentials_from_api_token_replay_cache.php');
        try {
            $migration->up();
            $this->fail('A wrong key must stop migration rather than erase the original receipt.');
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            $this->assertSame($body, DB::table('api_idempotency_keys')->value('response_body'));
            $this->assertDatabaseCount('api_idempotency_keys', 1);
        }
    }

    public function test_migration_rechecks_a_response_completed_after_its_initial_page_read(): void
    {
        $id = DB::table('api_idempotency_keys')->insertGetId(['idempotency_key' => 'migration-race',
            'route_key' => 'api/v1/materials/titles/generate', 'request_hash' => 'hash',
            'response_body' => '{"_recoverable":"title_generation_v2"}', 'response_status' => null]);
        $completed = ['object' => 'title_generation', 'data' => [['id' => 42, 'title' => 'Test title']]];
        $interleaved = false;
        DB::listen(function ($query) use (&$interleaved, $id, $completed): void {
            if (! $interleaved && str_starts_with(strtolower($query->sql), 'select')
                && str_contains($query->sql, 'api_idempotency_keys')) {
                $interleaved = true;
                DB::table('api_idempotency_keys')->where('id', $id)->update([
                    'response_body' => Crypt::encryptString(json_encode($completed, JSON_THROW_ON_ERROR)),
                    'response_status' => 201,
                ]);
            }
        });
        $migration = require database_path('migrations/2026_11_27_000002_remove_credentials_from_api_token_replay_cache.php');
        $migration->up();
        $this->assertTrue($interleaved);
        $row = ApiIdempotencyKey::findOrFail($id);
        $this->assertSame(201, $row->response_status);
        $this->assertSame($completed, json_decode($row->response_body, true));
    }
}
