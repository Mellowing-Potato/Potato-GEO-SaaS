<?php

namespace App\Services\Billing;

use App\Jobs\ExecuteSkillJob;
use App\Models\ModelCallLog;
use App\Models\SkillExecution;
use App\Models\SkillExecutionRelay;
use App\Services\DTOs\AiProviderCallResult;
use App\Services\DTOs\SkillBrief;
use App\Services\Enums\ExecutionStatus;
use App\Services\SkillAgent\SkillChunkAssembly;
use App\Services\SkillAgent\SkillChunkPlan;
use App\Services\SkillAgent\SkillChunkRunner;
use App\Services\SkillAgent\SkillPromptBuilder;
use App\Services\SkillAgent\SkillRunPreparer;
use Illuminate\Support\Facades\DB;

/** Merge one already recorded chunk into its original relay without another model call. */
final class ChunkRecordedDeliveryRecovery
{
    public function __construct(
        private readonly SkillRunPreparer $preparer,
        private readonly SkillChunkRunner $chunkRunner,
        private readonly SkillChunkAssembly $assembly,
    ) {}

    public function resume(
        SkillExecution $execution, SkillBrief $brief, AiProviderCallResult $result,
        array $context, ModelCallLog $log,
    ): string {
        if (($context['path'] ?? null) !== 'chunk'
            || ($context['attempt'] ?? null) !== 1
            || ! is_string($context['asset_fingerprint'] ?? null)
            || ! is_string($context['relay_task_fingerprint'] ?? null)
            || ! is_int($context['relay_completed_steps'] ?? null)
            || ! is_string($context['step_id'] ?? null)) {
            return 'delivery_context_unavailable';
        }
        $queueConnection = config('queue.connections.database.connection');
        if (config('queue.default') !== 'database'
            || config('queue.connections.database.after_commit') !== false
            || ($queueConnection !== null && $queueConnection !== ''
                && $queueConnection !== DB::getDefaultConnection())) {
            return 'queue_unavailable';
        }
        if (! $result->success || $result->finishReason !== 'stop') {
            return 'delivery_context_unavailable';
        }
        try {
            $inputs = $this->preparer->deliveryInputs($brief);
        } catch (\Throwable) {
            return 'delivery_context_unavailable';
        }
        if (! hash_equals($context['asset_fingerprint'], $inputs['fingerprint'])) {
            return 'delivery_context_unavailable';
        }

        try {
            return DB::transaction(function () use ($execution, $brief, $result, $context, $log, $inputs): string {
                $current = SkillExecution::query()->whereKey($execution->id)->lockForUpdate()->first();
                $relay = SkillExecutionRelay::query()
                    ->where('skill_execution_id', $execution->id)->lockForUpdate()->first();
                if (! $current || $current->status !== ExecutionStatus::BillingPending->value
                    || data_get($current->billing_pending_result, 'kind') !== 'model_result_recorded'
                    || data_get($current->billing_pending_result, 'model_call_log_id') !== $log->id
                    || ! $relay || $relay->completed_steps !== $context['relay_completed_steps']) {
                    return 'state_conflict';
                }
                $state = $relay->state;
                $task = $state['pending'][0] ?? null;
                if (! is_array($task)
                    || ! hash_equals($context['relay_task_fingerprint'], hash('sha256', json_encode($task, JSON_THROW_ON_ERROR)))) {
                    return 'delivery_context_unavailable';
                }
                $key = data_get($task, 'spec.key');
                if (! is_string($key)
                    || $context['step_id'] !== 'model:'.$key.':attempt:1') {
                    return 'delivery_context_unavailable';
                }
                $markdown = SkillPromptBuilder::isMarkdownSpec($task['spec']);
                if ($markdown
                    ? mb_strlen(trim($result->content)) < SkillChunkPlan::MIN_USABLE_OUTPUT_CHARS
                    : $this->assembly->extractJson($result->content, true) === null) {
                    return 'delivery_context_unavailable';
                }

                $merged = $this->chunkRunner->runTask(
                    $state, $inputs['skill'], $inputs['assets'], $brief, '',
                    enforceRecoveryDeadline: false, recordedResult: $result,
                );
                if ($merged === null) {
                    return 'state_conflict';
                }
                $completed = $relay->completed_steps + 1;
                $relay->update([
                    'state' => $state,
                    'completed_steps' => $completed,
                    'total_steps' => max($relay->total_steps, $completed + count($state['pending'])),
                ]);
                $current->update([
                    'status' => ExecutionStatus::Running->value,
                    'billing_pending_result' => null,
                    'error_message' => null,
                ]);
                // Database queue on the same connection inserts this successor
                // in the same transaction as the relay cursor and status.
                ExecuteSkillJob::dispatch($execution->id, $brief->toArray(), $execution->tenant_id, $completed + 1);

                return 'chunk_relay_resumed';
            });
        } catch (\Throwable) {
            return 'delivery_context_unavailable';
        }
    }
}
