<?php

namespace App\Services\Billing;

use App\Models\SkillExecution;
use App\Services\Enums\ExecutionStatus;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** A model request may have been processed, but its outcome is unknown. */
final class UnresolvedModelAttempt extends RuntimeException
{
    private array $deliveryContext = [];

    public function __construct(
        private readonly array $receipt,
        private readonly array $recordMetadata = [],
        private readonly ?string $requestedModel = null,
    )
    {
        parent::__construct('结果未知，可手动重新执行');
    }

    public function withDeliveryContext(array $context): self
    {
        $this->deliveryContext = $context;

        return $this;
    }

    public static function fromFailure(\App\Services\DTOs\AiProviderCallResult $result, array $recordMetadata = []): ?self
    {
        if ($result->success) {
            return null;
        }

        if (isset($result->gateway['status'])) {
            return $result->gateway['status'] === 'unknown' ? new self($result->gateway, $recordMetadata, $result->model) : null;
        }
        if (! empty($result->gateway['pre_send_rejection'])) {
            return null;
        }

        $status = data_get($result->gateway, 'http_status');
        $hasAttempt = data_get($result->gateway, 'attribution.client_request_id') !== null;
        if ($status === 202 || (is_int($status) && $status >= 500)
            || ($hasAttempt && $status === null)) {
            return new self($result->gateway, $recordMetadata, $result->model);
        }

        return null;
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

            app(ModelUsageRecorder::class)->recordUnknown($current, $this->receipt);

            $current->update([
                'status' => $current->status === ExecutionStatus::Cancelled->value
                    ? ExecutionStatus::Cancelled->value : ExecutionStatus::BillingPending->value,
                'billing_pending_result' => [
                    'version' => 1,
                    'kind' => 'model_attempt_unknown',
                    'gateway' => $this->receipt,
                    'record_metadata' => $this->recordMetadata,
                    'requested_model' => $this->requestedModel,
                    'brief_data' => $briefData,
                    'delivery_context' => $this->deliveryContext,
                ],
                'output_data' => [],
                'article_id' => null,
                'error_message' => $current->status === ExecutionStatus::Cancelled->value
                    ? $current->error_message : $this->getMessage(),
            ]);
        });
    }
}
