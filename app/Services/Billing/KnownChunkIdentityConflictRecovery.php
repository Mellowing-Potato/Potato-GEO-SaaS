<?php

namespace App\Services\Billing;

use App\Jobs\ExecuteSkillJob;
use App\Models\ModelCallLog;
use App\Models\SkillExecution;
use App\Models\SkillExecutionRelay;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Resume the one legacy explainer repair whose step ID collided with its recorded parent. */
final class KnownChunkIdentityConflictRecovery
{
    public static function isLegacyRepairConflict(SkillExecution $execution): bool
    {
        $snapshot = $execution->billing_pending_result;

        return $execution->skill_id === 'yao-geo-explainer-builder'
            && $execution->status === 'billing_pending'
            && data_get($snapshot, 'kind') === 'model_attempt_unknown'
            && data_get($snapshot, 'gateway.http_status') === 409
            && data_get($snapshot, 'record_metadata.recovery') === true
            // Before 45b204f9 the repair was recorded under the parent chunk.
            && data_get($snapshot, 'record_metadata.chunk') === 'article_core'
            && data_get($snapshot, 'delivery_context.path') === 'chunk'
            && data_get($snapshot, 'delivery_context.step_id') === 'model:article_core:attempt:1';
    }

    public function inspect(int $executionId, ?int $expectedTenantId = null, bool $apply = false): array
    {
        $execution = SkillExecution::withoutGlobalScopes()->find($executionId);
        if (! $execution || ($expectedTenantId !== null && (int) $execution->tenant_id !== $expectedTenantId)) {
            return ['status' => 'not_found', 'execution_id' => $executionId];
        }
        if ($apply && $expectedTenantId === null) {
            return ['status' => 'tenant_required', 'execution_id' => $executionId];
        }

        $previousTenant = TenantContext::get();
        TenantContext::set(Tenant::findOrFail($execution->tenant_id));
        try {
            return DB::transaction(function () use ($executionId, $expectedTenantId, $apply): array {
                $execution = SkillExecution::withoutGlobalScopes()->whereKey($executionId)
                    ->when($apply, fn ($query) => $query->lockForUpdate())->firstOrFail();
                $base = ['execution_id' => $executionId, 'tenant_id' => (int) $execution->tenant_id,
                    'project_id' => (int) $execution->project_id,
                    'skill_id' => $execution->skill_id, 'updated_at' => $execution->updated_at?->toISOString()];
                if ($expectedTenantId !== null && (int) $execution->tenant_id !== $expectedTenantId) {
                    return $base + ['status' => 'not_found'];
                }
                if (! self::isLegacyRepairConflict($execution)) {
                    return $base + ['status' => 'not_applicable'];
                }
                $snapshot = $execution->billing_pending_result;
                $expectedClientId = 'text:'.hash('sha256', json_encode([
                    $execution->skill_id, 'skill:'.$executionId, 'model:article_core:attempt:1', 'direct',
                ], JSON_THROW_ON_ERROR));
                if (data_get($snapshot, 'gateway.attribution.client_request_id') !== $expectedClientId
                    || data_get($snapshot, 'gateway.attribution.task_id') !== 'skill:'.$executionId
                    || data_get($snapshot, 'gateway.attribution.feature_id') !== $execution->skill_id
                    || data_get($snapshot, 'gateway.attribution.step_id') !== 'model:article_core:attempt:1') {
                    return $base + ['status' => 'gateway_identity_conflict'];
                }
                $brief = $snapshot['brief_data'] ?? null;
                if (! is_array($brief) || ($brief['executionId'] ?? null) !== $executionId
                    || ($brief['projectId'] ?? null) !== $execution->project_id
                    || ($brief['userId'] ?? null) !== $execution->user_id
                    || ($brief['skillId'] ?? null) !== $execution->skill_id) {
                    return $base + ['status' => 'brief_identity_conflict'];
                }
                $relay = SkillExecutionRelay::where('skill_execution_id', $executionId)
                    ->when($apply, fn ($query) => $query->lockForUpdate())->first();
                $state = $relay?->state;
                $task = $state['pending'][0] ?? null;
                if (! $relay || (int) $relay->tenant_id !== (int) $execution->tenant_id
                    || ! is_array($state) || ! is_array($task)
                    || $relay->completed_steps !== data_get($snapshot, 'delivery_context.relay_completed_steps')
                    || ! hash_equals((string) data_get($snapshot, 'delivery_context.relay_task_fingerprint', ''),
                        hash('sha256', json_encode($task, JSON_THROW_ON_ERROR)))
                    || ($task['recovery'] ?? null) !== true
                    || data_get($task, 'parent.key') !== 'article_core'
                    || data_get($task, 'spec.key') !== 'article_core.core_answer'
                    || data_get($task, 'spec.fields') !== ['core_answer']
                    || ! is_array(data_get($state, 'chunk_outputs.article_core'))
                    || count($state['chunk_outputs']['article_core']) < 2) {
                    return $base + ['status' => 'relay_state_conflict'];
                }
                $logs = ModelCallLog::withoutGlobalScopes()->where('skill_execution_id', $executionId)->get();
                $settled = $logs->filter(fn (ModelCallLog $log) => $log->status === 'completed'
                    && (int) $log->tenant_id === (int) $execution->tenant_id
                    && (int) $log->project_id === (int) $execution->project_id
                    && (int) $log->user_id === (int) $execution->user_id);
                $parent = $settled->first(fn (ModelCallLog $log) => data_get($log->metadata, 'chunk') === 'article_core'
                    && (int) data_get($log->metadata, 'attempt') === 1);
                if (! $parent || $logs->count() !== $settled->count()) {
                    return $base + ['status' => 'usage_unconfirmed'];
                }
                $plan = $base + [
                    'status' => 'ready',
                    'checkpoint_kind' => $snapshot['kind'],
                    'completed_steps' => $relay->completed_steps,
                    'settled_log_ids' => $settled->pluck('id')->all(),
                    'reusable_result' => ['article_core' => array_keys($state['chunk_outputs']['article_core'])],
                    'pending_steps' => array_map(fn ($pending) => data_get($pending, 'spec.key'), $state['pending']),
                    'next_request_step_id' => 'model:article_core.core_answer:attempt:1',
                    'new_model_call_possible' => true,
                    'preconditions' => ['same tenant and project', 'unchanged relay fingerprint',
                        'all existing model logs completed', 'database queue on the same connection'],
                ];
                if (! $apply) return $plan;

                $queueConnection = config('queue.connections.database.connection');
                if (config('queue.default') !== 'database'
                    || config('queue.connections.database.after_commit') !== false
                    || ($queueConnection !== null && $queueConnection !== ''
                        && $queueConnection !== DB::getDefaultConnection())) {
                    return array_replace($plan, ['status' => 'queue_unavailable']);
                }
                DB::table('skill_execution_recovery_audits')->insert([
                    'tenant_id' => $execution->tenant_id, 'skill_execution_id' => $executionId,
                    'action' => 'resume_known_chunk_identity_conflict',
                    'details' => json_encode(['completed_steps' => $relay->completed_steps,
                        'settled_log_ids' => $plan['settled_log_ids'],
                        'next_request_step_id' => $plan['next_request_step_id']], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
                $execution->update(['status' => 'running', 'billing_pending_result' => null,
                    'error_message' => null]);
                ExecuteSkillJob::dispatch($executionId, $brief, $execution->tenant_id, $relay->completed_steps + 1);
                Log::notice('Known chunk identity conflict resumed', ['execution_id' => $executionId,
                    'tenant_id' => $execution->tenant_id, 'settled_log_ids' => $plan['settled_log_ids']]);

                return array_replace($plan, ['status' => 'resumed']);
            });
        } finally {
            $previousTenant ? TenantContext::set($previousTenant) : TenantContext::forget();
        }
    }
}
