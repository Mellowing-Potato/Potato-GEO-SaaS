<?php

namespace App\Services\Billing;

use App\Models\SkillExecution;
use App\Services\Enums\ExecutionStatus;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** The original vector request may have incurred cost, with no usable result yet. */
final class UnresolvedEmbeddingAttempt extends RuntimeException
{
    public function __construct(private readonly array $receipt = [])
    {
        parent::__construct('结果未知，可手动重新执行');
    }

    public function hold(SkillExecution $execution, array $briefData = []): void
    {
        DB::transaction(function () use ($execution, $briefData): void {
            $current = SkillExecution::withoutGlobalScopes()
                ->whereKey($execution->id)->where('tenant_id', $execution->tenant_id)
                ->lockForUpdate()->first();
            if (! $current || $current->status === ExecutionStatus::Completed->value) {
                return;
            }
            $prepared = $current->billing_pending_result;
            if (! in_array(data_get($prepared, 'kind'),
                ['embedding_attempt_prepared', 'embedding_attempt_unknown', 'embedding_result_recorded'], true)) {
                throw new RuntimeException('The original embedding checkpoint is missing.');
            }
            $expected = data_get($prepared, 'gateway.attribution.client_request_id');
            if ($expected === null || $expected !== data_get($this->receipt, 'attribution.client_request_id')) {
                throw new RuntimeException('Embedding receipt does not match the prepared attempt.');
            }
            if (data_get($prepared, 'kind') !== 'embedding_result_recorded') {
                app(ModelUsageRecorder::class)->recordUnknown($current, $this->receipt);
            }

            $current->update([
                'status' => $current->status === ExecutionStatus::Cancelled->value
                    ? ExecutionStatus::Cancelled->value : ExecutionStatus::BillingPending->value,
                'billing_pending_result' => array_merge($prepared, [
                    'kind' => data_get($prepared, 'kind') === 'embedding_result_recorded'
                        ? 'embedding_result_recorded' : 'embedding_attempt_unknown',
                    'gateway' => array_replace_recursive($prepared['gateway'] ?? [], $this->receipt),
                    'brief_data' => $briefData ?: ($prepared['brief_data'] ?? []),
                ]),
                'output_data' => [], 'article_id' => null,
                'error_message' => $this->getMessage(),
            ]);
        });
    }
}
