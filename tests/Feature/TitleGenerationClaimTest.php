<?php

namespace Tests\Feature;

use App\Models\ApiIdempotencyKey;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TitleLibrary;
use App\Models\User;
use App\Services\Api\IdempotencyService;
use App\Services\Contracts\AiProvider;
use App\Services\DTOs\AiProviderCallResult;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TitleGenerationClaimTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = '/api/v1/materials/titles/generate';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.tenant_domain' => 'geo.example.test',
            'geo.title_generation_claim_required' => true]);
    }

    public function test_unclaimed_or_wrong_owner_and_body_never_reach_model(): void
    {
        [$tenant, $user, $project] = $this->owner();
        $other = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        $this->app->instance(AiProvider::class, \Mockery::mock(AiProvider::class,
            static fn ($mock) => $mock->shouldNotReceive('generate')));
        $body = $this->body($project);
        Sanctum::actingAs($user, ['*']);

        $this->forTenant($tenant)->postJson(self::PATH, $body,
            ['X-Idempotency-Key' => 'unclaimed'])->assertStatus(409);
        $this->forTenant($tenant)->postJson(self::PATH, $body)->assertStatus(409);
        $this->forTenant($tenant)->postJson(self::PATH.'/claim', [
            'payload' => $body, 'request_key' => 'title-first',
        ])->assertOk()->assertJsonPath('request_key', 'title-first');
        $this->forTenant($tenant)->postJson(self::PATH, [
            ...$body, 'keywords' => ['other'],
        ], ['X-Idempotency-Key' => 'title-first'])->assertStatus(409);

        Sanctum::actingAs($other, ['*']);
        $this->forTenant($tenant)->postJson(self::PATH, $body,
            ['X-Idempotency-Key' => 'title-first'])->assertStatus(409);
        $this->forTenant($tenant)->postJson(self::PATH.'/claim', [
            'payload' => $body, 'request_key' => 'title-second',
        ])->assertOk()->assertJsonPath('request_key', 'title-second');
        $this->assertDatabaseCount('api_idempotency_keys', 0);
    }

    public function test_lost_browser_key_recovers_single_original_result_then_seen_allows_new_task(): void
    {
        [$tenant, $user, $project] = $this->owner();
        $body = $this->body($project);
        Sanctum::actingAs($user, ['*']);
        $title = $this->savedTitle($tenant);
        $this->resultRow($tenant, $user, $body, 'original-title-key', $title);
        $this->app->instance(AiProvider::class, \Mockery::mock(AiProvider::class,
            static fn ($mock) => $mock->shouldNotReceive('generate')));

        $this->forTenant($tenant)->postJson(self::PATH.'/claim', [
            'payload' => $body, 'request_key' => 'new-browser-key',
        ])->assertOk()->assertJsonPath('request_key', 'original-title-key');
        $this->forTenant($tenant)->postJson(self::PATH, $body,
            ['X-Idempotency-Key' => 'original-title-key'])
            ->assertCreated()->assertJsonPath('data.0.id', $title->id);
        $this->forTenant($tenant)->postJson(self::PATH.'/seen', [
            'request_key' => 'original-title-key',
        ])->assertOk();
        $this->assertNotNull(ApiIdempotencyKey::query()->first()->client_seen_at);
        $this->forTenant($tenant)->postJson(self::PATH.'/claim', [
            'payload' => $body, 'request_key' => 'another-business-task',
        ])->assertOk()->assertJsonPath('request_key', 'another-business-task');
    }

    public function test_claimed_new_task_generates_once_and_releases_after_seen(): void
    {
        [$tenant, $user, $project] = $this->owner();
        $body = $this->body($project);
        Sanctum::actingAs($user, ['*']);
        $provider = \Mockery::mock(AiProvider::class);
        $provider->shouldReceive('generate')->once()->andReturn(new AiProviderCallResult(
            success: true, provider: 'fake', model: 'text', content: '新标题',
        ));
        $this->app->instance(AiProvider::class, $provider);

        $this->forTenant($tenant)->postJson(self::PATH.'/claim', [
            'payload' => $body, 'request_key' => 'claimed-new-title',
        ])->assertOk()->assertJsonPath('request_key', 'claimed-new-title');
        $this->forTenant($tenant)->postJson(self::PATH, $body,
            ['X-Idempotency-Key' => 'claimed-new-title'])
            ->assertCreated()->assertJsonPath('count', 1);
        $this->forTenant($tenant)->postJson(self::PATH.'/seen', [
            'request_key' => 'claimed-new-title',
        ])->assertOk();
        $this->forTenant($tenant)->postJson(self::PATH.'/claim', [
            'payload' => $body, 'request_key' => 'next-title',
        ])->assertOk()->assertJsonPath('request_key', 'next-title');
        TenantContext::set($tenant);
        try {
            $this->assertSame(1, TitleLibrary::query()->count());
        } finally {
            TenantContext::forget();
        }
    }

    public function test_ambiguous_legacy_attempts_and_unverified_result_fail_closed(): void
    {
        [$tenant, $user, $project] = $this->owner();
        $body = $this->body($project);
        Sanctum::actingAs($user, ['*']);
        $title = $this->savedTitle($tenant);
        $this->resultRow($tenant, $user, $body, 'legacy-one', $title);
        $this->resultRow($tenant, $user, $body, 'legacy-two', $title);
        $this->forTenant($tenant)->postJson(self::PATH.'/claim', [
            'payload' => $body, 'request_key' => 'third',
        ])->assertStatus(409);
        $this->forTenant($tenant)->postJson(self::PATH.'/seen', [
            'request_key' => 'unknown',
        ])->assertStatus(409);
        $this->forTenant($tenant)->postJson(self::PATH.'/seen', [
            'request_key' => 'legacy-one',
        ])->assertOk();
        $this->forTenant($tenant)->postJson(self::PATH.'/claim', [
            'payload' => $body, 'request_key' => 'third',
        ])->assertOk()->assertJsonPath('request_key', 'legacy-two');
    }

    private function owner(): array
    {
        $tenant = Tenant::query()->create([
            'subdomain' => 'title-claim', 'name' => 'Title Claim',
            'plan' => 'basic', 'status' => 'active',
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        TenantContext::set($tenant);
        try {
            $project = Project::query()->create([
                'owner_id' => $user->id, 'name' => 'Claim Project', 'settings' => [],
            ]);
        } finally {
            TenantContext::forget();
        }
        return [$tenant, $user, $project];
    }

    private function body(Project $project): array
    {
        return ['project_id' => $project->id, 'keywords' => ['GEO'],
            'count' => 1, 'style' => 'professional', 'category' => null];
    }

    private function savedTitle(Tenant $tenant): TitleLibrary
    {
        TenantContext::set($tenant);
        try {
            return TitleLibrary::query()->create(['tenant_id' => $tenant->id, 'title' => '已保存标题']);
        } finally {
            TenantContext::forget();
        }
    }

    private function resultRow(Tenant $tenant, User $user, array $body, string $key, TitleLibrary $title): void
    {
        ApiIdempotencyKey::query()->create([
            'idempotency_key' => $key, 'route_key' => 'api/v1/materials/titles/generate',
            'request_hash' => IdempotencyService::requestHash($body),
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'response_status' => 201,
            'response_body' => json_encode(['object' => 'title_generation',
                'data' => [['id' => $title->id, 'title' => $title->title]], 'count' => 1]),
        ]);
    }

    private function forTenant(Tenant $tenant): self
    {
        return $this->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }
}
