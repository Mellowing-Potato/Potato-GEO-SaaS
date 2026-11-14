<?php

namespace Tests\Feature;

use App\Jobs\ExecuteSkillJob;
use App\Models\ModelCallLog;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\SkillExecutionRelay;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\KnownChunkIdentityConflictRecovery;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class KnownChunkIdentityConflictRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    public function test_preflight_and_apply_resume_only_missing_repair_once(): void
    {
        [$execution, $relay, $tenant] = $this->fixture();
        $service = app(KnownChunkIdentityConflictRecovery::class);
        $otherTenant = Tenant::create(['subdomain' => 'other-conflict', 'name' => 'Other',
            'plan' => 'basic', 'status' => 'active']);

        $this->assertSame('not_found', $service->inspect($execution->id, $otherTenant->id, true)['status']);
        $this->assertSame('tenant_required', $service->inspect($execution->id, null, true)['status']);
        $preview = $service->inspect($execution->id, $tenant->id);
        $this->assertSame('ready', $preview['status']);
        $this->assertSame(2, $preview['completed_steps']);
        $this->assertSame('model:article_core.core_answer:attempt:1', $preview['next_request_step_id']);
        $this->assertSame(['article_core.core_answer'], $preview['pending_steps']);
        $this->assertCount(2, $preview['settled_log_ids']);
        $this->assertSame(0, DB::table('skill_execution_recovery_audits')->count());
        Queue::assertNothingPushed();

        $this->assertSame('resumed', $service->inspect($execution->id, $tenant->id, true)['status']);
        $this->assertSame('running', $execution->fresh()->status);
        $this->assertNull($execution->fresh()->billing_pending_result);
        $this->assertSame(2, $relay->fresh()->completed_steps);
        $this->assertSame(2, ModelCallLog::withoutGlobalScopes()->where('skill_execution_id', $execution->id)->count());
        $this->assertSame(1, DB::table('skill_execution_recovery_audits')->count());
        Queue::assertPushed(ExecuteSkillJob::class,
            fn (ExecuteSkillJob $job): bool => $job->executionId === $execution->id && $job->step === 3);

        $this->assertSame('not_applicable', $service->inspect($execution->id, $tenant->id, true)['status']);
        Queue::assertPushed(ExecuteSkillJob::class, 1);
    }

    public function test_missing_reusable_result_and_incomplete_parent_are_not_restarted(): void
    {
        [$execution, $relay, $tenant] = $this->fixture();
        $service = app(KnownChunkIdentityConflictRecovery::class);
        $state = $relay->state;
        $state['chunk_outputs']['article_core'] = ['core_answer' => '短'];
        $relay->update(['state' => $state]);
        $this->assertSame('relay_state_conflict', $service->inspect($execution->id, $tenant->id, true)['status']);
        $state['chunk_outputs']['article_core']['title'] = '已保存标题';
        $relay->update(['state' => $state]);
        $parent = ModelCallLog::withoutGlobalScopes()->where('skill_execution_id', $execution->id)
            ->get()->first(fn ($log) => data_get($log->metadata, 'chunk') === 'article_core');
        $parent->update(['status' => 'failed', 'metadata' => ['chunk' => 'article_core', 'attempt' => 1,
            'points_deduction_status' => 'unknown']]);
        $this->assertSame('usage_unconfirmed', $service->inspect($execution->id, $tenant->id, true)['status']);
        $this->assertSame('billing_pending', $execution->fresh()->status);
        Queue::assertNothingPushed();
    }

    private function fixture(): array
    {
        if (! DB::getSchemaBuilder()->hasTable('skill_execution_recovery_audits')) {
            throw new \RuntimeException('Migration missing from '.app()->databasePath());
        }
        Queue::fake();
        config(['queue.default' => 'database', 'queue.connections.database.after_commit' => false,
            'queue.connections.database.connection' => null]);
        $tenant = Tenant::create(['subdomain' => 'known-conflict', 'name' => 'Known Conflict',
            'plan' => 'basic', 'status' => 'active']);
        TenantContext::set($tenant);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $project = Project::create(['owner_id' => $user->id, 'name' => 'Report Writing']);
        $execution = SkillExecution::create(['tenant_id' => $tenant->id,
            'project_id' => $project->id, 'user_id' => $user->id,
            'skill_id' => 'yao-geo-explainer-builder', 'skill_name' => '解释型文章',
            'category' => 'geo-content-production', 'status' => 'billing_pending',
            'execution_mode' => 'async', 'input_data' => [], 'article_id' => null]);
        $task = ['parent' => ['key' => 'article_core', 'fields' => ['core_answer']],
            'spec' => ['key' => 'article_core.core_answer', 'fields' => ['core_answer']],
            'recovery' => true];
        $relay = SkillExecutionRelay::create(['tenant_id' => $tenant->id,
            'skill_execution_id' => $execution->id, 'total_steps' => 6, 'completed_steps' => 2,
            'state' => ['pending' => [$task], 'chunk_outputs' => [
                'article_core' => ['title' => '已保存标题', 'core_answer' => '太短'],
                'research_evidence' => ['sources' => ['source']]],
            ]]);
        $step = 'model:article_core:attempt:1';
        $clientId = 'text:'.hash('sha256', json_encode([
            $execution->skill_id, 'skill:'.$execution->id, $step, 'direct',
        ], JSON_THROW_ON_ERROR));
        $execution->update(['billing_pending_result' => ['version' => 1,
            'kind' => 'model_attempt_unknown', 'gateway' => ['http_status' => 409,
                'attribution' => ['client_request_id' => $clientId, 'task_id' => 'skill:'.$execution->id,
                    'feature_id' => $execution->skill_id, 'step_id' => $step]],
            'record_metadata' => ['chunk' => 'article_core', 'recovery' => true, 'attempt' => 1],
            'requested_model' => 'text',
            'brief_data' => ['skillId' => $execution->skill_id, 'projectId' => $project->id,
                'userId' => $user->id, 'executionId' => $execution->id, 'fields' => []],
            'delivery_context' => ['path' => 'chunk', 'step_id' => $step,
                'relay_completed_steps' => 2,
                'relay_task_fingerprint' => hash('sha256', json_encode($task, JSON_THROW_ON_ERROR))]]]);
        foreach (['research_evidence', 'article_core'] as $chunk) {
            ModelCallLog::create(['tenant_id' => $tenant->id, 'project_id' => $project->id,
                'user_id' => $user->id, 'skill_execution_id' => $execution->id,
                'provider' => 'gateway', 'model' => 'text', 'status' => 'completed',
                'gateway_request_id' => 'gateway-'.$chunk,
                'prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15,
                'metadata' => ['chunk' => $chunk, 'attempt' => 1,
                    'points_deduction_status' => 'deducted', 'points_deducted' => 5]]);
        }

        return [$execution->fresh(), $relay, $tenant];
    }
}
