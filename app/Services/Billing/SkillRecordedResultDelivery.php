<?php

namespace App\Services\Billing;

use App\Models\SkillExecution;
use App\Models\TaskRun;
use App\Models\Tenant;
use App\Services\Content\MaterialLibraryService;
use App\Services\DTOs\SkillExecutionResult;
use App\Services\Enums\ExecutionStatus;
use App\Services\Reports\ReportAssetService;
use App\Services\Workflow\TaskOrchestrationService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/** Deliver a saved model result with tenant and checkpoint validation. */
class SkillRecordedResultDelivery
{
    public function __construct(
        private readonly ReportAssetService $reportAssets,
        private readonly MaterialLibraryService $materials,
    ) {}

    /** Stage a recovered model result before any article or material usage is published. */
    public function stageRecordedResult(
        SkillExecution $execution,
        int $modelCallLogId,
        SkillExecutionResult $result,
        array $materialRefs,
    ): bool {
        $output = $result->outputPaths;
        if (isset($output['md']) && ! isset($output['markdown'])) {
            $output['markdown'] = $output['md'];
        }
        unset($output['md']);
        if (is_string($result->intermediateData['raw_output'] ?? null)) {
            $output['result_text'] = $result->intermediateData['raw_output'];
        }
        if (is_array($result->intermediateData['structured'] ?? null)) {
            $output['structured'] = $result->intermediateData['structured'];
        }
        if (is_string($result->intermediateData['pipeline_mode'] ?? null)) {
            $output['pipeline_mode'] = $result->intermediateData['pipeline_mode'];
        }
        if (is_array($result->intermediateData['rendered_formats'] ?? null)) {
            $output['rendered_formats'] = array_values(array_intersect(
                ['markdown', 'html', 'docx', 'pdf'],
                array_unique(array_map(
                    fn (string $format): string => $format === 'md' ? 'markdown' : $format,
                    array_filter($result->intermediateData['rendered_formats'], 'is_string'),
                )),
            ));
        }

        return DB::transaction(function () use ($execution, $modelCallLogId, $result, $output, $materialRefs): bool {
            $current = SkillExecution::query()->whereKey($execution->id)->lockForUpdate()->first();
            if (! $current || $current->status !== ExecutionStatus::BillingPending->value
                || data_get($current->billing_pending_result, 'kind') !== 'model_result_recorded'
                || data_get($current->billing_pending_result, 'model_call_log_id') !== $modelCallLogId) {
                return false;
            }
            $current->update([
                'billing_pending_result' => [
                    'version' => 1,
                    'kind' => 'skill_execution',
                    'success' => $result->success,
                    'error_message' => $result->errorMessage,
                    'output_data' => $output,
                    'quality_report' => $result->qualityReport,
                    'duration_seconds' => (int) $result->durationSeconds,
                    'token_usage' => $result->tokenUsage,
                    'delivery' => [
                        'article_content' => $result->intermediateData['billing_pending_article_content'] ?? null,
                        'material_refs' => $materialRefs,
                    ],
                    'recovered_model_call_log_id' => $modelCallLogId,
                ],
                'output_data' => [],
                'article_id' => null,
                'error_message' => '原模型结果已恢复，正在完成交付。',
            ]);

            return true;
        });
    }

    /** Finish one saved result without invoking the model again. */
    public function release(int $executionId): bool
    {
        $execution = SkillExecution::withoutGlobalScopes()->find($executionId);
        if (! $execution || $execution->status !== ExecutionStatus::BillingPending->value) {
            return false;
        }
        $tenant = Tenant::find($execution->tenant_id);
        if (! $tenant) {
            return false;
        }

        $previousTenant = TenantContext::get();
        TenantContext::set($tenant);
        try {
            return DB::transaction(function () use ($execution): bool {
                $current = SkillExecution::query()->whereKey($execution->id)->lockForUpdate()->first();
                if (! $current || $current->status !== ExecutionStatus::BillingPending->value) {
                    return false;
                }
                $snapshot = $current->billing_pending_result;
                if (! is_array($snapshot) || ($snapshot['kind'] ?? null) !== 'skill_execution'
                    || ! is_array($snapshot['output_data'] ?? null)) {
                    return false;
                }

                $output = $snapshot['output_data'];
                $delivery = is_array($snapshot['delivery'] ?? null) ? $snapshot['delivery'] : [];
                if (($snapshot['success'] ?? true) === false) {
                    $current->update([
                        'status' => ExecutionStatus::Failed->value,
                        'output_data' => [],
                        'quality_report' => $snapshot['quality_report'] ?? null,
                        'duration_seconds' => $snapshot['duration_seconds'] ?? 0,
                        'token_usage' => $snapshot['token_usage'] ?? 0,
                        'error_message' => $snapshot['error_message'] ?? '技能结果未通过质量检查。',
                    ]);
                    if (is_numeric($delivery['task_run_id'] ?? null)) {
                        $run = TaskRun::query()->whereKey((int) $delivery['task_run_id'])
                            ->where('tenant_id', $current->tenant_id)->lockForUpdate()->first();
                        if ($run && $run->status === TaskRun::STATUS_BILLING_PENDING) {
                            app(TaskOrchestrationService::class)->failBillingPendingRun(
                                $run, $current, (string) $current->error_message,
                            );
                        }
                    }

                    return true;
                }
                $articleContent = $delivery['article_content'] ?? null;
                $body = is_string($articleContent) && $articleContent !== ''
                    ? $articleContent : $this->resultText($output);
                $article = $this->reportAssets->createOrLinkForSkillExecution(
                    $current, $body, null,
                    is_array($output['structured'] ?? null) ? $output['structured'] : null,
                    is_array($snapshot['quality_report'] ?? null) ? $snapshot['quality_report'] : null,
                );
                $output = $this->reportAssets->outputDataWithArticle($output, $article);
                if (is_array($delivery['material_refs'] ?? null) && $delivery['material_refs'] !== []) {
                    $this->materials->recordUsage($delivery['material_refs']);
                }

                $current->update([
                    'status' => ExecutionStatus::Completed->value,
                    'billing_pending_result' => null,
                    'output_data' => $output,
                    'quality_report' => $snapshot['quality_report'] ?? null,
                    'article_id' => $article->id,
                    'duration_seconds' => $snapshot['duration_seconds'] ?? 0,
                    'token_usage' => $snapshot['token_usage'] ?? 0,
                    'error_message' => null,
                ]);
                if (is_numeric($delivery['task_run_id'] ?? null)) {
                    $run = TaskRun::query()->whereKey((int) $delivery['task_run_id'])
                        ->where('tenant_id', $current->tenant_id)->lockForUpdate()->first();
                    if ($run && $run->status === TaskRun::STATUS_BILLING_PENDING) {
                        app(TaskOrchestrationService::class)->releaseBillingPendingRun($run, $current, $article, $delivery);
                    }
                }

                return true;
            });
        } finally {
            $previousTenant ? TenantContext::set($previousTenant) : TenantContext::forget();
        }
    }

    private function resultText(array $output): string
    {
        $text = $output['result_text'] ?? $output['markdown_content'] ?? null;
        if (is_string($text)) {
            return $text;
        }

        return is_array($output['structured'] ?? null)
            ? (json_encode($output['structured'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '')
            : '';
    }
}
