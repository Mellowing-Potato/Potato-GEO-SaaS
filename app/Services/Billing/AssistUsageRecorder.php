<?php

namespace App\Services\Billing;

use App\Models\ModelCallLog;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Services\DTOs\AiProviderCallResult;
use App\Services\Enums\SkillCategory;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;


class AssistUsageRecorder
{
    public function __construct(
        private readonly ModelUsageRecorder $usageRecorder,
    ) {}


    public function record(
        ?int $projectId,
        int $userId,
        AiProviderCallResult $result,
        string $kind,
        string $label,
        SkillCategory $category = SkillCategory::GeoResearch,
        array $metadata = [],
    ): ?ModelCallLog {
        try {
            if (! $projectId) {
                Log::warning('Assist usage recording skipped: missing project_id', [
                    'kind' => $kind,
                    'user_id' => $userId,
                ]);

                return null;
            }

            $project = $this->projectForCurrentTenant($projectId);

            // Gateway may return the same completed result after a lost response.
            // Reuse its existing usage record and avoid an orphan assist execution.
            $gatewayRequestId = data_get($result->gateway, 'request_id');
            if (is_string($gatewayRequestId) && preg_match('/^[A-Za-z0-9_.:-]{1,160}$/', $gatewayRequestId)) {
                $existing = ModelCallLog::withoutGlobalScopes()
                    ->where('gateway_request_id', $gatewayRequestId)->first();
                if ($existing) {
                    if ((int) $existing->tenant_id !== (int) $project->tenant_id
                        || (int) $existing->project_id !== (int) $project->id
                        || (int) $existing->user_id !== $userId) {
                        throw new \LogicException('Gateway request already belongs to another usage owner.');
                    }
                    $existingExecution = SkillExecution::withoutGlobalScopes()
                        ->where('tenant_id', $existing->tenant_id)
                        ->find($existing->skill_execution_id);
                    if ($existingExecution?->skill_id !== $kind
                        || $existing->provider !== $result->provider
                        || $existing->model !== $result->model
                        || (int) $existing->prompt_tokens !== $result->promptTokens
                        || (int) $existing->completion_tokens !== $result->completionTokens
                        || (int) $existing->total_tokens !== $result->totalTokens) {
                        throw new \LogicException('Gateway request receipt conflicts with the saved assist call.');
                    }

                    return $existing;
                }
            }

            $execution = SkillExecution::create([
                'project_id' => $project->id,
                'user_id' => $userId,
                'skill_id' => $kind,
                'skill_name' => $label,
                'category' => $category->value,
                'stage_index' => 0,
                'status' => ($result->gateway['status'] ?? '') === 'unknown'
                    ? \App\Services\Enums\ExecutionStatus::BillingPending->value : ($result->success ? 'completed' : 'failed'),
                'error_message' => ($result->gateway['status'] ?? '') === 'unknown' ? '结果未知，可手动重新执行' : $result->errorMessage,
                'execution_mode' => 'assist',
                'input_data' => [],
            ]);

            return $this->usageRecorder->record(
                $execution,
                $result,
                array_merge(['assist' => true, 'assist_kind' => $kind], $metadata),
            );
        } catch (\Throwable $e) {
            Log::warning('Assist usage recording failed (non-blocking)', [
                'kind' => $kind,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function projectForCurrentTenant(int $projectId): Project
    {
        $project = Project::query()->whereKey($projectId)->first();

        if (! $project || (int) $project->tenant_id !== (int) TenantContext::id()) {
            throw ValidationException::withMessages([
                'project_id' => 'The selected project_id is invalid.',
            ]);
        }

        return $project;
    }
}
