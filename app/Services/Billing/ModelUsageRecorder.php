<?php

namespace App\Services\Billing;

use App\Models\ModelCallLog;
use App\Models\SkillExecution;
use App\Services\DTOs\AiProviderCallResult;

class ModelUsageRecorder
{
    /** Record a received unknown outcome without inventing provider usage. */
    public function recordUnknown(SkillExecution $execution, array $receipt, array $metadata = []): void
    {
        if (! is_string($receipt['provider'] ?? null) || $receipt['provider'] === ''
            || ! is_string($receipt['model'] ?? null) || $receipt['model'] === ''
            || ! is_string($receipt['request_id'] ?? null)) {
            return; // A worker may have stopped before receiving any attributable response.
        }
        $this->record($execution, new AiProviderCallResult(
            success: false, provider: $receipt['provider'], model: $receipt['model'],
            errorMessage: '结果未知，可手动重新执行',
            gateway: array_replace($receipt, ['status' => 'unknown', 'usage_available' => false]),
        ), $metadata);
    }

    public function record(SkillExecution $execution, AiProviderCallResult $result, array $metadata = []): ModelCallLog
    {
        $status = ($result->gateway['status'] ?? '') === 'unknown' ? 'unknown' : ($result->success ? 'completed' : 'failed');
        $usageAvailable = ($result->gateway['usage_available'] ?? true) !== false;
        $gatewayRequestId = data_get($result->gateway, 'request_id');
        $gatewayRequestId = is_string($gatewayRequestId) && preg_match('/^[A-Za-z0-9_.:-]{1,160}$/', $gatewayRequestId)
            ? $gatewayRequestId : null;
        if ($gatewayRequestId !== null) {
            $existing = ModelCallLog::withoutGlobalScopes()->where('gateway_request_id', $gatewayRequestId)->first();
            if ($existing) {
                if ((int) $existing->tenant_id !== (int) $execution->tenant_id
                    || (int) $existing->project_id !== (int) $execution->project_id
                    || (int) $existing->user_id !== (int) $execution->user_id
                    || (int) $existing->skill_execution_id !== (int) $execution->id) {
                    throw new \LogicException('Gateway request already belongs to another usage owner.');
                }
                if ($existing->provider !== $result->provider
                    || $existing->model !== $result->model
                    || $existing->status !== $status
                    || (int) $existing->prompt_tokens !== $result->promptTokens
                    || (int) $existing->completion_tokens !== $result->completionTokens
                    || (int) $existing->total_tokens !== $result->totalTokens) {
                    throw new \LogicException('Gateway request receipt conflicts with the saved model call.');
                }

                return $existing;
            }
        }
        $log = ModelCallLog::query()->create([
            'tenant_id' => $execution->tenant_id,
            'project_id' => $execution->project_id,
            'user_id' => $execution->user_id,
            'skill_execution_id' => $execution->id,
            'provider' => $result->provider,
            'model' => $result->model,
            'status' => $status,
            'prompt_tokens' => $usageAvailable ? $result->promptTokens : null,
            'completion_tokens' => $usageAvailable ? $result->completionTokens : null,
            'total_tokens' => $usageAvailable ? $result->totalTokens : null,
            'duration_seconds' => $result->durationSeconds,
            'metadata' => array_merge($metadata, ['gateway' => $result->gateway]),
            'gateway_request_id' => $gatewayRequestId,
        ]);

        return $log->fresh();
    }

}
