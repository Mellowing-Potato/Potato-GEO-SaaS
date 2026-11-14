<?php

namespace App\Jobs;

use App\Http\Controllers\Api\V1\GeoAnalysisController;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\Tenant;
use App\Services\Enums\ExecutionStatus;
use App\Support\OperationalTextRedactor;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 异步 GEO 全景分析队列任务
 *
 * 与 ExecuteSkillJob 同构：从数据库队列消费，还原租户上下文后 CAS 抢占
 * pending→running，再复用 GeoAnalysisController::runAnalysisPipeline 落库
 * (分析结构化 output_data + 报告资产)。前端轮询 show 端点获取终态。
 *
 * 为什么不复用 ExecuteSkillJob：ExecuteSkillJob 组装的是 Skill 通用 output_data，
 * 缺少分析视图所需的 analysis/summary/report 资产结构，故单列本 Job 复用控制器持久化。
 */
class RunGeoAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 超时(秒)：与 storeFullPipelineAnalysis 的上游预算对齐，留足前后处理。 */
    public int $timeout = 480;

    /** LLM 调用开销大，不做自动重试(失败即落库 failed，用户可重新发起)。 */
    public int $tries = 1;

    /**
     * @param  int  $executionId  skill_executions 记录 ID
     * @param  int  $projectId  项目 ID
     * @param  int  $userId  发起用户 ID
     * @param  array  $input  analysisInput() 结果(含 notes/fields)
     * @param  int  $tenantId  租户 ID(用于 Worker 还原 TenantContext)
     */
    public function __construct(
        public readonly int $executionId,
        public readonly int $projectId,
        public readonly int $userId,
        public readonly array $input,
        public readonly int $tenantId,
    ) {
        $this->onQueue('skills');
    }

    public function handle(GeoAnalysisController $controller): void
    {
        // ─── 1. 还原租户上下文(队列 Worker 无请求生命周期) ───
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            Log::error('RunGeoAnalysisJob: tenant not found', [
                'execution_id' => $this->executionId,
                'tenant_id' => $this->tenantId,
            ]);
            $this->failExecutionWithoutScope('租户不存在');

            return;
        }

        if (! $tenant->isActive()) {
            Log::warning('RunGeoAnalysisJob: tenant inactive, skipping', [
                'execution_id' => $this->executionId,
                'tenant_id' => $this->tenantId,
                'tenant_status' => $tenant->status,
            ]);
            $this->failExecutionWithoutScope('Tenant is paused; queued analysis was not started.');

            return;
        }

        TenantContext::set($tenant);

        try {
            // ─── 2. 加载记录 ───
            $execution = SkillExecution::find($this->executionId);
            if (! $execution) {
                Log::error('RunGeoAnalysisJob: execution not found', ['execution_id' => $this->executionId]);

                return;
            }

            if ($execution->status !== ExecutionStatus::Pending->value) {
                Log::info('RunGeoAnalysisJob: no longer pending, skipping', ['execution_id' => $this->executionId]);

                return;
            }

            $project = Project::find($this->projectId);
            if (! $project) {
                SkillExecution::where('id', $this->executionId)
                    ->where('status', ExecutionStatus::Pending->value)
                    ->update([
                        'status' => ExecutionStatus::Failed->value,
                        'error_message' => 'Project no longer exists.',
                    ]);

                return;
            }

            // ─── 3. CAS 抢占 pending→running(防取消竞态/重复消费) ───
            $rows = SkillExecution::where('id', $this->executionId)
                ->where('status', ExecutionStatus::Pending->value)
                ->update(['status' => ExecutionStatus::Running->value]);

            if ($rows === 0) {
                Log::info('RunGeoAnalysisJob: no longer pending, aborting', ['execution_id' => $this->executionId]);

                return;
            }

            // ─── 4. 复用控制器持久化管线(落库 completed/failed + 报告资产) ───
            $controller->runAnalysisPipeline($execution->fresh(), $project, $this->input, $this->userId);

            Log::info('RunGeoAnalysisJob: completed', [
                'execution_id' => $this->executionId,
                'status' => $execution->fresh()->status,
            ]);
        } catch (\Throwable $e) {
            $this->failClaimedExecution($e->getMessage());

            Log::error('RunGeoAnalysisJob: unhandled exception', [
                'execution_id' => $this->executionId,
                'error' => $e->getMessage(),
            ]);
        } finally {
            TenantContext::forget();
        }
    }

    /**
     * 队列任务彻底失败时的回调(超过重试或不可恢复异常)。
     */
    public function failed(?\Throwable $exception): void
    {
        $tenant = Tenant::find($this->tenantId);
        if ($tenant) {
            TenantContext::set($tenant);
            try {
                $this->failClaimedExecution($exception?->getMessage() ?? '队列任务异常终止。');
            } catch (\Throwable) {
                // 静默——避免循环异常
            }
            TenantContext::forget();
        }
    }

    /** A saved pre-send attempt may already have reached the gateway when the worker dies. */
    private function failClaimedExecution(string $message): void
    {
        DB::transaction(function () use ($message): void {
            $execution = SkillExecution::query()->whereKey($this->executionId)->lockForUpdate()->first();
            if (! $execution || ! in_array($execution->status,
                [ExecutionStatus::Pending->value, ExecutionStatus::Running->value], true)) {
                return;
            }

            $kind = data_get($execution->billing_pending_result, 'kind');
            $prepared = in_array($kind,
                ['model_attempt_prepared', 'embedding_attempt_prepared', 'embedding_result_recorded'], true);
            $execution->update([
                'status' => $prepared ? ExecutionStatus::BillingPending->value : ExecutionStatus::Failed->value,
                'error_message' => $prepared
                    ? (str_starts_with((string) $kind, 'embedding_')
                        ? '原向量请求待核对，执行已暂缓；请勿重新提交'
                        : '原模型请求待核对，执行已暂缓；请勿重新提交')
                    : (OperationalTextRedactor::redact($message) ?: 'GEO analysis failed.'),
            ]);
        });
    }

    /**
     * 无 TenantContext 时绕过租户作用域直接落库失败(租户已不存在)。
     */
    private function failExecutionWithoutScope(string $message): void
    {
        try {
            SkillExecution::withoutGlobalScopes()
                ->where('id', $this->executionId)
                ->where('tenant_id', $this->tenantId)
                ->whereIn('status', [ExecutionStatus::Pending->value, ExecutionStatus::Running->value])
                ->update([
                    'status' => ExecutionStatus::Failed->value,
                    'error_message' => OperationalTextRedactor::redact($message) ?: 'GEO analysis failed.',
                ]);
        } catch (\Throwable) {
            // 静默
        }
    }
}
