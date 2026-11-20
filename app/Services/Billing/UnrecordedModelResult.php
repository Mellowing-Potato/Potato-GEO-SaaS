<?php

namespace App\Services\Billing;

use App\Models\SkillExecution;
use App\Services\DTOs\AiProviderCallResult;
use App\Services\Enums\ExecutionStatus;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** A successful paid model response whose local usage record is not confirmed. */
final class UnrecordedModelResult extends RuntimeException
{
    private array $deliveryContext = [];

    public function __construct(
        private readonly AiProviderCallResult $result,
        private readonly array $recordMetadata,
        Throwable $cause,
    ) {
        parent::__construct('模型用量记录未确认，结果暂缓交付；请勿重新提交，等待原任务核对。', 0, $cause);
    }

    public function withDeliveryContext(array $context): self
    {
        $this->deliveryContext = $context;

        return $this;
    }

    public function hold(SkillExecution $execution, array $briefData = []): void
    {
        DB::transaction(function () use ($execution, $briefData): void {
            $current = SkillExecution::withoutGlobalScopes()
                ->whereKey($execution->id)
                ->where('tenant_id', $execution->tenant_id)
                ->lockForUpdate()->first();
            if (! $current || $current->status === ExecutionStatus::Completed->value) {
                return;
            }
            $current->update([
                'status' => $current->status === ExecutionStatus::Cancelled->value
                    ? ExecutionStatus::Cancelled->value : ExecutionStatus::BillingPending->value,
                'billing_pending_result' => [
                    'version' => 1,
                    'kind' => 'model_result_unrecorded',
                    'gateway' => $this->result->gateway,
                    'requested_model' => $this->result->model,
                    'record_metadata' => $this->recordMetadata,
                    'brief_data' => $briefData,
                    'delivery_context' => PreparedModelAttempt::resultDeliveryContext($this->deliveryContext, $this->result),
                    'known_model_result' => [
                        'provider' => $this->result->provider,
                        'model' => $this->result->model,
                        'finish_reason' => $this->result->finishReason,
                        'content' => $this->result->content,
                        'prompt_tokens' => $this->result->promptTokens,
                        'completion_tokens' => $this->result->completionTokens,
                        'total_tokens' => $this->result->totalTokens,
                        'duration_seconds' => $this->result->durationSeconds,
                    ],
                ],
                'output_data' => [],
                'article_id' => null,
                'error_message' => $current->status === ExecutionStatus::Cancelled->value
                    ? $current->error_message : $this->getMessage(),
            ]);
        });
    }
}
