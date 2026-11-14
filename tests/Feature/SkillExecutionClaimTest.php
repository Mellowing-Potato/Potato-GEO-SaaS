<?php

namespace Tests\Feature;

use App\Models\ApiIdempotencyKey;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Api\IdempotencyService;
use App\Services\Contracts\SkillAgentRunner;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SkillExecutionClaimTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = '/api/v1/skills/execute-async';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.tenant_domain' => 'geo.example.test',
            'geo.skill_execution_claim_required' => true]);
    }

    public function test_unclaimed_or_wrong_owner_and_body_cannot_queue(): void
    {
        [$tenant, $user, $project] = $this->owner();
        $other = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        $this->app->instance(SkillAgentRunner::class, \Mockery::mock(SkillAgentRunner::class,
            static fn ($mock) => $mock->shouldNotReceive('executeAsync')));
        $body = $this->body($project);
        Sanctum::actingAs($user, ['*']);
        $this->forTenant($tenant)->postJson(self::PATH, $body,
            ['X-Idempotency-Key' => 'unclaimed'])->assertStatus(409);
        $this->forTenant($tenant)->postJson(self::PATH, $body)->assertStatus(409);
        $this->forTenant($tenant)->postJson(self::PATH.'/claim', [
            'payload' => $body, 'request_key' => 'skill-first',
        ])->assertOk()->assertJsonPath('request_key', 'skill-first');
        $this->forTenant($tenant)->postJson(self::PATH, [
            ...$body, 'query' => 'other',
        ], ['X-Idempotency-Key' => 'skill-first'])->assertStatus(409);

        Sanctum::actingAs($other, ['*']);
        $this->forTenant($tenant)->postJson(self::PATH, $body,
            ['X-Idempotency-Key' => 'skill-first'])->assertStatus(409);
        $this->forTenant($tenant)->postJson(self::PATH.'/claim', [
            'payload' => $body, 'request_key' => 'skill-second',
        ])->assertOk()->assertJsonPath('request_key', 'skill-second');
        $this->assertDatabaseCount('skill_executions', 0);
    }

    public function test_lost_browser_key_recovers_one_task_and_seen_releases_claim(): void
    {
        [$tenant, $user, $project] = $this->owner();
        $body = $this->body($project);
        Sanctum::actingAs($user, ['*']);
        $execution = $this->execution($tenant, $user, $project);
        $this->resultRow($tenant, $user, $body, 'old-skill-key', $execution);
        $this->app->instance(SkillAgentRunner::class, \Mockery::mock(SkillAgentRunner::class,
            static fn ($mock) => $mock->shouldNotReceive('executeAsync')));

        $this->forTenant($tenant)->postJson(self::PATH.'/claim', [
            'payload' => $body, 'request_key' => 'new-browser-key',
        ])->assertOk()->assertJsonPath('request_key', 'old-skill-key');
        $this->forTenant($tenant)->postJson(self::PATH, $body,
            ['X-Idempotency-Key' => 'old-skill-key'])
            ->assertStatus(202)->assertJsonPath('execution.id', $execution->id);
        $this->forTenant($tenant)->postJson(self::PATH.'/seen', [
            'request_key' => 'old-skill-key',
        ])->assertOk();
        $this->assertNotNull(ApiIdempotencyKey::query()->first()->client_seen_at);
        $this->forTenant($tenant)->postJson(self::PATH.'/claim', [
            'payload' => $body, 'request_key' => 'next-skill-task',
        ])->assertOk()->assertJsonPath('request_key', 'next-skill-task');
    }

    public function test_ambiguous_old_tasks_and_unverified_response_stay_unseen(): void
    {
        [$tenant, $user, $project] = $this->owner();
        $body = $this->body($project);
        Sanctum::actingAs($user, ['*']);
        $execution = $this->execution($tenant, $user, $project);
        $this->resultRow($tenant, $user, $body, 'old-one', $execution);
        $this->resultRow($tenant, $user, $body, 'old-two', $execution);
        $this->forTenant($tenant)->postJson(self::PATH.'/claim', [
            'payload' => $body, 'request_key' => 'third',
        ])->assertStatus(409);
        $this->forTenant($tenant)->postJson(self::PATH.'/seen', [
            'request_key' => 'unknown',
        ])->assertStatus(409);
        $this->forTenant($tenant)->postJson(self::PATH.'/seen', [
            'request_key' => 'old-one',
        ])->assertOk();
        $this->forTenant($tenant)->postJson(self::PATH.'/claim', [
            'payload' => $body, 'request_key' => 'third',
        ])->assertOk()->assertJsonPath('request_key', 'old-two');
    }

    public function test_started_execution_recovers_missing_idempotency_response_without_queuing_again(): void
    {
        [$tenant, $user, $project] = $this->owner();
        $body = $this->body($project);
        Sanctum::actingAs($user, ['*']);
        $this->forTenant($tenant)->postJson(self::PATH.'/claim', [
            'payload' => $body, 'request_key' => 'started-skill',
        ])->assertOk();
        ApiIdempotencyKey::query()->create([
            'idempotency_key' => 'started-skill', 'route_key' => 'api/v1/skills/execute-async',
            'request_hash' => IdempotencyService::requestHash($body),
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
        ]);
        $execution = $this->execution($tenant, $user, $project);
        DB::table('skill_executions')->where('id', $execution->id)->update([
            'client_request_key' => 'started-skill', 'status' => 'running',
        ]);
        $this->app->instance(SkillAgentRunner::class, \Mockery::mock(SkillAgentRunner::class,
            static fn ($mock) => $mock->shouldNotReceive('executeAsync')));

        $this->forTenant($tenant)->postJson(self::PATH.'/claim', [
            'payload' => $body, 'request_key' => 'new-browser-key',
        ])->assertOk()->assertJsonPath('request_key', 'started-skill')
            ->assertJsonPath('recovered_execution', true);
        $this->forTenant($tenant)->postJson(self::PATH, $body,
            ['X-Idempotency-Key' => 'started-skill'])
            ->assertStatus(202)->assertJsonPath('execution.id', $execution->id);
        $this->forTenant($tenant)->postJson(self::PATH.'/seen', [
            'request_key' => 'started-skill',
        ])->assertOk();
        $this->assertDatabaseCount('skill_executions', 1);
    }

    public function test_pending_execution_does_not_claim_that_queue_dispatch_succeeded(): void
    {
        [$tenant, $user, $project] = $this->owner();
        $body = $this->body($project);
        Sanctum::actingAs($user, ['*']);
        $this->forTenant($tenant)->postJson(self::PATH.'/claim', [
            'payload' => $body, 'request_key' => 'pending-skill',
        ])->assertOk();
        ApiIdempotencyKey::query()->create([
            'idempotency_key' => 'pending-skill', 'route_key' => 'api/v1/skills/execute-async',
            'request_hash' => IdempotencyService::requestHash($body),
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
        ]);
        $execution = $this->execution($tenant, $user, $project);
        DB::table('skill_executions')->where('id', $execution->id)
            ->update(['client_request_key' => 'pending-skill']);
        $this->app->instance(SkillAgentRunner::class, \Mockery::mock(SkillAgentRunner::class,
            static fn ($mock) => $mock->shouldNotReceive('executeAsync')));

        $this->forTenant($tenant)->postJson(self::PATH.'/claim', [
            'payload' => $body, 'request_key' => 'new-browser-key',
        ])->assertOk()->assertJsonPath('request_key', 'pending-skill')
            ->assertJsonPath('recovered_execution', false);
        $this->forTenant($tenant)->postJson(self::PATH, $body,
            ['X-Idempotency-Key' => 'pending-skill'])->assertStatus(409);
        $this->assertNull(ApiIdempotencyKey::query()->first()->response_status);
    }

    private function owner(): array
    {
        $tenant = Tenant::query()->create([
            'subdomain' => 'skill-claim', 'name' => 'Skill Claim',
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
        return ['skill_id' => 'yao-geo-panorama-audit', 'project_id' => $project->id,
            'fields' => [], 'query' => null, 'material_refs' => []];
    }

    private function execution(Tenant $tenant, User $user, Project $project): SkillExecution
    {
        TenantContext::set($tenant);
        try {
            return SkillExecution::query()->create([
                'project_id' => $project->id, 'user_id' => $user->id,
                'skill_id' => 'yao-geo-panorama-audit', 'skill_name' => 'Panorama',
                'category' => 'geo-strategy', 'stage_index' => 0,
                'status' => 'pending', 'execution_mode' => 'async', 'input_data' => [],
            ]);
        } finally {
            TenantContext::forget();
        }
    }

    private function resultRow(Tenant $tenant, User $user, array $body, string $key, SkillExecution $execution): void
    {
        ApiIdempotencyKey::query()->create([
            'idempotency_key' => $key, 'route_key' => 'api/v1/skills/execute-async',
            'request_hash' => IdempotencyService::requestHash($body),
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'response_status' => 202,
            'response_body' => json_encode(['execution' => [
                'id' => $execution->id, 'project_id' => $execution->project_id,
                'user_id' => $execution->user_id, 'skill_id' => $execution->skill_id,
            ]]),
        ]);
    }

    private function forTenant(Tenant $tenant): self
    {
        return $this->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }
}
