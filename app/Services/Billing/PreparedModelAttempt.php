<?php

namespace App\Services\Billing;

use App\Models\SkillExecution;
use App\Services\DTOs\SkillBrief;
use App\Services\Enums\ExecutionStatus;
use Illuminate\Support\Facades\DB;

/** Persist the exact original gateway attempt before a paid model request leaves GEO. */
final class PreparedModelAttempt
{
    public static function save(
        SkillBrief $brief, string $stepId, string $model, array $metadata, array $deliveryContext = [],
        ?string $gatewayFeatureId = null,
    ): ?string {
        if (! $brief->executionId) {
            throw new \RuntimeException('A paid model attempt requires a durable skill execution before send.');
        }

        $taskId = 'skill:'.$brief->executionId;
        // The legacy analysis route keeps its historical gateway feature label,
        // while the execution itself belongs to the panorama skill.
        $featureId = $gatewayFeatureId ?? $brief->skillId;
        $clientId = 'text:'.hash('sha256', json_encode([
            $featureId, $taskId, $stepId, 'direct',
        ], JSON_THROW_ON_ERROR));

        DB::transaction(function () use ($brief, $stepId, $model, $metadata, $deliveryContext, $taskId, $clientId, $featureId): void {
            $execution = SkillExecution::query()->whereKey($brief->executionId)->lockForUpdate()->first();
            if (! $execution || $execution->status !== ExecutionStatus::Running->value
                || $execution->project_id !== $brief->projectId
                || $execution->user_id !== $brief->userId
                || $execution->skill_id !== $brief->skillId) {
                throw new \RuntimeException('Model attempt cannot be prepared for this execution.');
            }
            if (in_array(data_get($execution->billing_pending_result, 'kind'),
                ['embedding_attempt_prepared', 'embedding_attempt_unknown'], true)) {
                throw new \RuntimeException('Original embedding attempt is unresolved.');
            }
            $prior = $execution->billing_pending_result;
            if (in_array(data_get($prior, 'kind'), ['model_attempt_prepared', 'model_attempt_unknown'], true)) {
                throw (new UnresolvedModelAttempt($prior['gateway'] ?? [], $prior['record_metadata'] ?? [], $prior['requested_model'] ?? null))
                    ->withDeliveryContext($prior['delivery_context'] ?? []);
            }
            if (data_get($prior, 'gateway.attribution.client_request_id') === $clientId
                && data_get($prior, 'kind') === 'model_result_unrecorded') {
                $saved = $prior['known_model_result'];
                throw (new UnrecordedModelResult(new \App\Services\DTOs\AiProviderCallResult(
                    success: true, provider: $saved['provider'], model: $saved['model'], content: $saved['content'],
                    promptTokens: $saved['prompt_tokens'], completionTokens: $saved['completion_tokens'],
                    totalTokens: $saved['total_tokens'], durationSeconds: (float) ($saved['duration_seconds'] ?? 0.0),
                    finishReason: $saved['finish_reason'], gateway: $prior['gateway'],
                ), $prior['record_metadata'] ?? [], new \RuntimeException('A saved result must be delivered without resending.')))
                    ->withDeliveryContext($prior['delivery_context'] ?? []);
            }
            $selection = $execution->model_connection_snapshot ?? [];
            $identity = array_intersect_key($selection, array_flip(['provider', 'model', 'credential_fingerprint']));
            $execution->update(['billing_pending_result' => [
                'version' => 1, 'kind' => 'model_attempt_prepared',
                'gateway' => $identity + ['attribution' => [
                    'client_request_id' => $clientId, 'task_id' => $taskId,
                    'feature_id' => $featureId, 'step_id' => $stepId,
                ]],
                'record_metadata' => $metadata,
                'requested_model' => $selection['model'] ?? $model,
                'brief_data' => $brief->toArray(),
                'delivery_context' => $deliveryContext,
            ]]);
        });

        return $clientId;
    }
    /** Save returned content before recording usage or rendering it locally. */
    public static function resolved(int $executionId, string $clientId, \App\Services\DTOs\AiProviderCallResult $result): void
    {
        DB::transaction(function () use ($executionId, $clientId, $result): void {
            $execution = SkillExecution::query()->whereKey($executionId)->lockForUpdate()->first();
            $prior = $execution?->billing_pending_result;
            if (! $execution || data_get($prior, 'kind') !== 'model_attempt_prepared'
                || data_get($prior, 'gateway.attribution.client_request_id') !== $clientId) {
                throw new \RuntimeException('Original model checkpoint is unavailable.');
            }
            $prior['kind'] = $result->success ? 'model_result_unrecorded' : 'model_attempt_resolved';
            $prior['gateway'] = array_replace_recursive($prior['gateway'], $result->gateway);
            $prior['delivery_context'] = self::resultDeliveryContext($prior['delivery_context'] ?? [], $result);
            $prior['known_model_result'] = [
                'provider' => $result->provider, 'model' => $result->model, 'content' => $result->content,
                'prompt_tokens' => $result->promptTokens, 'completion_tokens' => $result->completionTokens,
                'total_tokens' => $result->totalTokens, 'finish_reason' => $result->finishReason,
                'duration_seconds' => $result->durationSeconds,
            ];
            $execution->update(['billing_pending_result' => $prior]);
        });
    }

    /** Persist the actual provider model, not the internal slot used before send. */
    public static function resultDeliveryContext(array $context, \App\Services\DTOs\AiProviderCallResult $result): array
    {
        if (($context['path'] ?? null) === 'single') {
            $context[($context['attempt'] ?? 1) === 2 ? 'fallback_model' : 'primary_model'] = $result->model;
        }
        return $context;
    }

}
