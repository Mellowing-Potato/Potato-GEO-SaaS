<?php

namespace App\Services\Billing;

use App\Models\SkillExecution;
use App\Models\Tenant;
use App\Models\Task;
use App\Models\TaskRun;
use App\Services\AI\AiProviderServiceImpl;
use App\Services\Enums\ExecutionStatus;
use App\Support\TenantContext;
use App\Jobs\ExecuteSkillJob;
use App\Jobs\ResumeTaskEmbeddingJob;
use Illuminate\Support\Facades\DB;

/** Inspect or explicitly resume the original locally saved vector. */
final class PendingEmbeddingAttemptInspector
{
    public function __construct(private readonly AiProviderServiceImpl $provider) {}

    /** @return array{status: string, execution_id: int} */
    public function inspect(int $executionId): array
    {
        $execution = SkillExecution::withoutGlobalScopes()->findOrFail($executionId);
        $tenant = Tenant::findOrFail($execution->tenant_id);
        $previousTenant = TenantContext::get();
        TenantContext::set($tenant);
        try {
            return $this->inspectForTenant($execution);
        } finally {
            $previousTenant ? TenantContext::set($previousTenant) : TenantContext::forget();
        }
    }

    /** Explicitly resume only the original execution; never send another embedding request. */
    public function recover(int $executionId): array
    {
        $execution = SkillExecution::withoutGlobalScopes()->findOrFail($executionId);
        $tenant = Tenant::findOrFail($execution->tenant_id);
        $previousTenant = TenantContext::get();
        TenantContext::set($tenant);
        try {
            return $this->recoverForTenant($execution);
        } finally {
            $previousTenant ? TenantContext::set($previousTenant) : TenantContext::forget();
        }
    }

    private function recoverForTenant(SkillExecution $execution): array
    {
        $result = static fn (string $status): array => ['status' => $status, 'execution_id' => $execution->id];
        $queueConnection = config('queue.connections.database.connection');
        if (config('queue.default') !== 'database'
            || config('queue.connections.database.after_commit') !== false
            || ($queueConnection !== null && $queueConnection !== ''
                && $queueConnection !== DB::getDefaultConnection())) {
            return $result('queue_unavailable');
        }
        $taskRun = null;
        if ($execution->execution_mode === 'task') {
            $runs = TaskRun::query()->where('metadata->execution_id', $execution->id)->limit(2)->get();
            if ($runs->count() !== 1 || $runs[0]->status !== TaskRun::STATUS_BILLING_PENDING) {
                return $result('task_run_unavailable');
            }
            $taskRun = $runs[0];
            $task = Task::query()->whereKey($taskRun->task_id)->first();
            if (! $task || $task->trashed() || $task->status !== Task::STATUS_ACTIVE
                || $task->skill_id !== $execution->skill_id
                || $task->project_id !== $execution->project_id
                || $task->user_id !== $execution->user_id) {
                return $result('task_run_unavailable');
            }
        }
        $snapshot = $execution->billing_pending_result;
        $brief = $snapshot['brief_data'] ?? null;
        if (! is_array($brief) || ($brief['executionId'] ?? null) !== $execution->id
            || ($brief['projectId'] ?? null) !== $execution->project_id
            || ($brief['userId'] ?? null) !== $execution->user_id
            || ($brief['skillId'] ?? null) !== $execution->skill_id
            || ($brief['query'] ?? null) !== ($snapshot['query'] ?? null)) {
            return $result('brief_context_unavailable');
        }
        $checked = $this->inspectForTenant($execution, true);
        if ($checked['status'] !== 'ready_for_result_recovery') {
            return $result($checked['status']);
        }
        try {
            return DB::transaction(function () use ($execution, $snapshot, $brief, $checked, $result, $taskRun): array {
                $current = SkillExecution::query()->whereKey($execution->id)->lockForUpdate()->first();
                if (! $current || $current->status !== ExecutionStatus::BillingPending->value
                    || $current->billing_pending_result !== $snapshot) {
                    return $result('state_conflict');
                }
                if ($taskRun !== null) {
                    $lockedRun = TaskRun::query()->whereKey($taskRun->id)->lockForUpdate()->first();
                    if (! $lockedRun || $lockedRun->status !== TaskRun::STATUS_BILLING_PENDING
                        || (int) data_get($lockedRun->metadata, 'execution_id') !== $current->id) {
                        return $result('state_conflict');
                    }
                }
                $receipt = $checked['gateway'];
                app(EmbeddingUsageRecorder::class)->record($current, $receipt,
                    (int) data_get($receipt, 'usage.total_tokens', 0));
                $checkpoint = $snapshot;
                $checkpoint['kind'] = 'embedding_result_recorded';
                $checkpoint['vector'] = $checked['vector'];
                $checkpoint['gateway'] = array_replace_recursive($checkpoint['gateway'] ?? [], $receipt);
                $receipts = $current->gateway_requests ?? [];
                $updated = false;
                foreach ($receipts as &$existing) {
                    if (data_get($existing, 'request_id') === $receipt['request_id']) {
                        $existing = array_replace_recursive($existing, $receipt);
                        $updated = true;
                    }
                }
                unset($existing);
                if (! $updated) {
                    $receipts[] = $receipt;
                }
                $current->update([
                    'status' => ExecutionStatus::Pending->value,
                    'billing_pending_result' => $checkpoint,
                    'gateway_requests' => $receipts,
                    'error_message' => null,
                ]);
                if ($taskRun !== null) {
                    ResumeTaskEmbeddingJob::dispatch($execution->tenant_id, $taskRun->task_id,
                        $taskRun->id, $execution->id, $brief);
                    return $result('original_task_run_queued');
                }
                ExecuteSkillJob::dispatch($execution->id, $brief, $execution->tenant_id);
                return $result('original_execution_queued');
            });
        } catch (\Throwable) {
            return $result('queue_unavailable');
        }
    }

    private function inspectForTenant(SkillExecution $execution, bool $includeDelivery = false): array
    {
        $id = $execution->id;
        $result = static fn (string $status): array => ['status' => $status, 'execution_id' => $id];
        $snapshot = $execution->billing_pending_result;
        if (! in_array(data_get($snapshot, 'kind'),
            ['embedding_attempt_prepared', 'embedding_attempt_unknown', 'embedding_result_recorded'], true)) {
            return $result('not_applicable');
        }
        if ($execution->status === ExecutionStatus::Cancelled->value) {
            return $result('cancelled_manual_review');
        }
        if ($execution->status !== ExecutionStatus::BillingPending->value) {
            return $result('state_conflict');
        }
        $attribution = data_get($snapshot, 'gateway.attribution');
        $query = $snapshot['query'] ?? null;
        $clientId = is_array($attribution) ? ($attribution['client_request_id'] ?? null) : null;
        $taskId = 'skill:'.$id;
        $expectedClientId = 'embed:'.hash('sha256', json_encode([
            $execution->skill_id, $taskId, 'knowledge-query',
        ], JSON_THROW_ON_ERROR));
        if (! is_string($query) || $query === ''
            || ($snapshot['query_hash'] ?? null) !== hash('sha256', $query)
            || ! is_string($clientId) || $clientId !== $expectedClientId
            || ($attribution['task_id'] ?? null) !== $taskId
            || ($attribution['feature_id'] ?? null) !== $execution->skill_id
            || ($attribution['step_id'] ?? null) !== 'knowledge-query') {
            return $result('identity_unconfirmed');
        }
        $brief = $snapshot['brief_data'] ?? [];
        if ($brief !== [] && (! is_array($brief)
            || ($brief['executionId'] ?? null) !== $id
            || ($brief['projectId'] ?? null) !== $execution->project_id
            || ($brief['userId'] ?? null) !== $execution->user_id
            || ($brief['skillId'] ?? null) !== $execution->skill_id
            || ($brief['query'] ?? null) !== $query)) {
            return $result('brief_identity_conflict');
        }
        if (data_get($snapshot, 'kind') !== 'embedding_result_recorded') {
            return $result('unknown_manual_review');
        }
        $vector = $snapshot['vector'] ?? null;
        $receipt = $snapshot['gateway'] ?? [];
        if (! \App\Services\AI\EmbeddingModelPolicy::validVector($vector)
            || ! is_array($receipt) || ($receipt['status'] ?? null) !== 'completed'
            || ! is_string($receipt['provider'] ?? null) || ! is_string($receipt['model'] ?? null)
            || ! is_string($receipt['request_id'] ?? null)) {
            return $result('receipt_unconfirmed');
        }
        $key = app(\App\Services\AI\ModelConfiguration::class)->active('embedding', $execution->tenant_id);
        if (! $key || $key->provider !== $receipt['provider'] || ($key->metadata['model'] ?? null) !== $receipt['model']) {
            return $result('embedding_model_changed');
        }
        return $result('ready_for_result_recovery') + ($includeDelivery ? ['vector' => $vector, 'gateway' => $receipt] : []);
    }
}
