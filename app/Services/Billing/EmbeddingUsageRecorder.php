<?php

namespace App\Services\Billing;

use App\Models\ModelCallLog;
use App\Models\SkillExecution;
use App\Services\DTOs\AiProviderCallResult;

/** Record a locally saved embedding result without calling the model again. */
final class EmbeddingUsageRecorder
{
    public function record(SkillExecution $execution, array $gateway, int $tokens): ?ModelCallLog
    {
        if ($gateway === []) {
            return null;
        }
        if (($gateway['status'] ?? null) !== 'completed' || ! is_string($gateway['provider'] ?? null)
            || ! is_string($gateway['model'] ?? null) || ! is_string($gateway['request_id'] ?? null) || $tokens < 0) {
            throw new \LogicException('Embedding result identity is not confirmed.');
        }

        return app(ModelUsageRecorder::class)->record($execution, new AiProviderCallResult(
            success: true, provider: $gateway['provider'], model: $gateway['model'],
            promptTokens: $tokens, totalTokens: $tokens, gateway: $gateway,
        ), ['usage_kind' => 'embedding']);
    }
}
