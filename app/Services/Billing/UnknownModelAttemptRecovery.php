<?php

namespace App\Services\Billing;

use App\Http\Controllers\Api\V1\GeoAnalysisController;
use App\Models\ModelCallLog;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\Tenant;
use App\Services\AI\AiProviderServiceImpl;
use App\Services\DTOs\AiProviderCallResult;
use App\Services\DTOs\SkillBrief;
use App\Services\Enums\ExecutionStatus;
use App\Services\SkillAgent\SkillSingleCallRunner;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/** Resume locally saved output only. Unknown provider results are never resubmitted. */
final class UnknownModelAttemptRecovery
{
    public function __construct(
        private readonly AiProviderServiceImpl $provider,
        private readonly ModelUsageRecorder $usageRecorder,
        private readonly SkillSingleCallRunner $singleCallRunner,
        private readonly SkillRecordedResultDelivery $deliveryGate,
        private readonly ChunkRecordedDeliveryRecovery $chunkRecovery,
    ) {}

    /** @return array{status: string, execution_id: int, model_call_log_id?: int} */
    public function recover(int $executionId, bool $apply = false): array
    {
        $execution = SkillExecution::withoutGlobalScopes()->findOrFail($executionId);
        $tenant = Tenant::findOrFail($execution->tenant_id);
        $previousTenant = TenantContext::get();
        TenantContext::set($tenant);
        try {
            $snapshot = $execution->billing_pending_result;
            $snapshotKind = is_array($snapshot) ? ($snapshot['kind'] ?? null) : null;
            if ($snapshotKind === 'model_result_recorded') {
                if ($execution->status === ExecutionStatus::Cancelled->value) {
                    return ['status' => 'cancelled_manual_review', 'execution_id' => $executionId];
                }
                if ($execution->status !== ExecutionStatus::BillingPending->value) {
                    return ['status' => 'state_conflict', 'execution_id' => $executionId];
                }
                $logId = $snapshot['model_call_log_id'] ?? null;
                if (! is_int($logId) || $logId < 1) {
                    return ['status' => 'receipt_unconfirmed', 'execution_id' => $executionId];
                }
                $log = ModelCallLog::withoutGlobalScopes()->find($logId);
                if (! $log || (int) $log->tenant_id !== (int) $execution->tenant_id
                    || (int) $log->project_id !== (int) $execution->project_id
                    || (int) $log->user_id !== (int) $execution->user_id
                    || (int) $log->skill_execution_id !== $executionId
                    || $log->status !== 'completed'
                    || $log->gateway_request_id !== data_get($snapshot, 'gateway.request_id')
                    || $log->model !== data_get($snapshot, 'model_result.model')
                    || (int) $log->prompt_tokens !== data_get($snapshot, 'model_result.prompt_tokens')
                    || (int) $log->completion_tokens !== data_get($snapshot, 'model_result.completion_tokens')
                    || (int) $log->total_tokens !== data_get($snapshot, 'model_result.total_tokens')) {
                    return ['status' => 'receipt_unconfirmed', 'execution_id' => $executionId];
                }
                $pending = ['status' => 'usage_recorded_delivery_pending',
                    'execution_id' => $executionId, 'model_call_log_id' => $logId];
                if (! $apply || ! is_array($snapshot['delivery_context'] ?? null)
                    || ! in_array($snapshot['delivery_context']['path'] ?? null,
                        ['single', 'chunk', 'legacy_geo_analysis'], true)) {
                    return $pending;
                }
                $briefData = $snapshot['brief_data'] ?? null;
                if (! is_array($briefData)
                    || ($briefData['executionId'] ?? null) !== $execution->id
                    || ($briefData['projectId'] ?? null) !== $execution->project_id
                    || ($briefData['userId'] ?? null) !== $execution->user_id
                    || ($briefData['skillId'] ?? null) !== $execution->skill_id) {
                    return ['status' => 'brief_identity_conflict', 'execution_id' => $executionId];
                }
                $savedResult = $snapshot['model_result'] ?? null;
                if (! is_array($savedResult) || ! is_string($savedResult['content'] ?? null)) {
                    return ['status' => 'receipt_unconfirmed', 'execution_id' => $executionId];
                }
                try {
                    $brief = SkillBrief::fromArray($briefData);
                    $result = new AiProviderCallResult(
                        success: true, provider: $log->provider, model: $savedResult['model'],
                        content: $savedResult['content'],
                        promptTokens: $savedResult['prompt_tokens'],
                        completionTokens: $savedResult['completion_tokens'],
                        totalTokens: $savedResult['total_tokens'],
                        finishReason: $savedResult['finish_reason'] ?? null,
                        durationSeconds: (float) ($savedResult['duration_seconds'] ?? 0.0),
                        gateway: is_array($snapshot['gateway'] ?? null) ? $snapshot['gateway'] : [],
                    );
                    if ($snapshot['delivery_context']['path'] === 'legacy_geo_analysis') {
                        $analysis = app(GeoAnalysisController::class);
                        if (! $analysis->stageRecordedLegacyAnalysis($execution, $log, $result)) {
                            return ['status' => 'delivery_context_unavailable',
                                'execution_id' => $executionId, 'model_call_log_id' => $logId];
                        }
                        if (! $analysis->finalizePendingAnalysis($executionId)) {
                            return ['status' => 'delivery_state_conflict',
                                'execution_id' => $executionId, 'model_call_log_id' => $logId];
                        }

                        return ['status' => 'delivery_completed',
                            'execution_id' => $executionId, 'model_call_log_id' => $logId];
                    }
                    if ($snapshot['delivery_context']['path'] === 'chunk') {
                        return ['status' => $this->chunkRecovery->resume(
                            $execution, $brief, $result, $snapshot['delivery_context'], $log,
                        ), 'execution_id' => $executionId, 'model_call_log_id' => $logId];
                    }
                    $deliveryResult = $this->singleCallRunner->resumeRecorded(
                        $brief, $result, $snapshot['delivery_context'],
                    );
                } catch (\RuntimeException $e) {
                    return ['status' => 'delivery_context_unavailable', 'execution_id' => $executionId];
                }
                if (! $this->deliveryGate->stageRecordedResult(
                    $execution, $logId, $deliveryResult, $brief->materialRefs,
                )) {
                    return ['status' => 'state_conflict', 'execution_id' => $executionId];
                }
                if (! $this->deliveryGate->release($executionId)) {
                    return ['status' => 'delivery_state_conflict',
                        'execution_id' => $executionId, 'model_call_log_id' => $logId];
                }

                return ['status' => $deliveryResult->success ? 'delivery_completed' : 'delivery_failed',
                    'execution_id' => $executionId, 'model_call_log_id' => $logId];
            }
            if (! in_array($snapshotKind, ['model_attempt_prepared', 'model_attempt_unknown', 'model_result_unrecorded'], true)) {
                return ['status' => 'not_applicable', 'execution_id' => $executionId];
            }
            if ($execution->status === ExecutionStatus::Cancelled->value) {
                return ['status' => 'cancelled_manual_review', 'execution_id' => $executionId];
            }
            if ($execution->status !== ExecutionStatus::BillingPending->value) {
                return ['status' => 'state_conflict', 'execution_id' => $executionId];
            }
            if ($snapshotKind !== 'model_result_unrecorded') {
                return ['status' => 'unknown_manual_review', 'execution_id' => $executionId];
            }
            $brief = $snapshot['brief_data'] ?? [];
            if (! is_array($brief) || ($brief['executionId'] ?? null) !== $executionId
                || ($brief['projectId'] ?? null) !== $execution->project_id
                || ($brief['userId'] ?? null) !== $execution->user_id
                || ($brief['skillId'] ?? null) !== $execution->skill_id) {
                return ['status' => 'brief_identity_conflict', 'execution_id' => $executionId];
            }
            if (! is_array($snapshot['record_metadata'] ?? null)) {
                return ['status' => 'metadata_unconfirmed', 'execution_id' => $executionId];
            }
            $attribution = data_get($snapshot, 'gateway.attribution', []);
            $feature = data_get($snapshot, 'delivery_context.path') === 'legacy_geo_analysis' ? 'geo-analysis' : $execution->skill_id;
            if (($attribution['task_id'] ?? null) !== 'skill:'.$executionId
                || ($attribution['feature_id'] ?? null) !== $feature
                || ! is_string($attribution['client_request_id'] ?? null)) {
                return ['status' => 'identity_unconfirmed', 'execution_id' => $executionId];
            }
            $saved = $snapshot['known_model_result'] ?? [];
            if (! is_array($saved) || ! is_string($saved['content'] ?? null)
                || ! is_string($saved['provider'] ?? null) || ! is_string($saved['model'] ?? null)) {
                return ['status' => 'receipt_unconfirmed', 'execution_id' => $executionId];
            }
            foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $field) {
                if (! is_int($saved[$field] ?? null) || $saved[$field] < 0) {
                    return ['status' => 'receipt_unconfirmed', 'execution_id' => $executionId];
                }
            }
            if (! $apply) {
                return ['status' => 'ready_for_usage_recovery', 'execution_id' => $executionId];
            }
            $result = new AiProviderCallResult(success: true, provider: $saved['provider'], model: $saved['model'],
                content: $saved['content'], promptTokens: $saved['prompt_tokens'], completionTokens: $saved['completion_tokens'],
                totalTokens: $saved['total_tokens'], finishReason: $saved['finish_reason'] ?? null,
                durationSeconds: (float) ($saved['duration_seconds'] ?? 0.0),
                gateway: is_array($snapshot['gateway'] ?? null) ? $snapshot['gateway'] : []);
            $log = DB::transaction(function () use ($execution, $snapshot, $result) {
                $current = SkillExecution::query()->whereKey($execution->id)->lockForUpdate()->first();
                if (! $current || $current->status !== ExecutionStatus::BillingPending->value
                    || $current->billing_pending_result !== $snapshot) {
                    return null;
                }
                $log = $this->usageRecorder->record($current, $result, $snapshot['record_metadata'] ?? []);
                $current->update(['billing_pending_result' => [
                    'version' => 1, 'kind' => 'model_result_recorded', 'model_call_log_id' => $log->id,
                    'gateway' => $result->gateway, 'brief_data' => $snapshot['brief_data'],
                    'delivery_context' => $snapshot['delivery_context'] ?? [], 'model_result' => $snapshot['known_model_result'],
                ]]);
                return $log;
            });
            if (! $log) {
                return ['status' => 'state_conflict', 'execution_id' => $executionId];
            }
            return $this->recover($executionId, true);
        } finally {
            $previousTenant ? TenantContext::set($previousTenant) : TenantContext::forget();
        }
    }
}
