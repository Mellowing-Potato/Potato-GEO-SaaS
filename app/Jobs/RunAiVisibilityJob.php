<?php

namespace App\Jobs;

use App\Models\AiVisibilityRun;
use App\Models\Tenant;
use App\Services\Geo\AiVisibility\AiVisibilityService;
use App\Support\OperationalTextRedactor;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * AI 可见性监测队列任务（批次二 · 单 4）。
 *
 * 红线（派单单 4，一条都不能破）：
 *  - 一个问题一个 Job：一行 run = 一次提问，Job 只跑这一行；
 *  - 不能重试：$tries = 1（重试 = 同一次监测重复扣钱）；
 *  - 必须还原租户上下文：队列进程没有 tenant 中间件，TenantScope fail-open，
 *    六步模式照抄 RunGeoAnalysisJob（含最容易漏的 failed() 分支）；
 *  - 必须显式扣点：在 AiVisibilityService::chargePoints（成功之后才扣）。
 *
 * 梯度约束：Job $timeout(280) < worker --timeout(300) < queue retry_after(600)，
 * 超时被杀的任务不会被重新派发＝不会重复花钱。
 */
class RunAiVisibilityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 280;

    public int $tries = 1;

    public function __construct(
        public readonly int $runId,
        public readonly int $tenantId,
    ) {
        $this->onQueue('visibility');
    }

    public function handle(AiVisibilityService $service): void
    {
        // ─── 1. 还原租户上下文 ───
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            Log::error('RunAiVisibilityJob: tenant not found.', [
                'run_id' => $this->runId,
                'tenant_id' => $this->tenantId,
            ]);
            $service->markFailedWithoutScope($this->runId, $this->tenantId, '租户不存在，任务未执行。');

            return;
        }

        if (! $tenant->isActive()) {
            Log::warning('RunAiVisibilityJob: tenant inactive, skipping.', [
                'run_id' => $this->runId,
                'tenant_id' => $this->tenantId,
                'tenant_status' => $tenant->status,
            ]);
            $service->markFailedWithoutScope($this->runId, $this->tenantId, '租户已暂停，排队中的监测未执行。');

            return;
        }

        TenantContext::set($tenant);

        try {
            // ─── 2. 业务执行（内部含 CAS 抢占、扣点、终态落库，不抛业务异常） ───
            $service->executeQuestion($this->runId);
        } catch (\Throwable $e) {
            // 双保险：service 已自吞异常，这里兜住任何残余抛出。
            $attemptPersisted = (bool) data_get(AiVisibilityRun::query()->find($this->runId)?->metadata, 'model_client_request_id');
            AiVisibilityRun::query()
                ->where('id', $this->runId)
                ->whereNotIn('status', AiVisibilityRun::TERMINAL_STATUSES)
                ->update([
                    'status' => $attemptPersisted ? AiVisibilityRun::STATUS_RECONCILIATION_REQUIRED : AiVisibilityRun::STATUS_FAILED,
                    'error_message' => $attemptPersisted ? '监测供应商尝试状态待核对。'
                        : (OperationalTextRedactor::redact($e->getMessage()) ?: 'AI 可见性任务异常终止。'),
                ]);

            Log::error('RunAiVisibilityJob: unhandled exception.', [
                'run_id' => $this->runId,
                'error' => $e->getMessage(),
            ]);
        } finally {
            TenantContext::forget();
        }
    }

    /**
     * 队列任务彻底失败（进程被杀/超时）时的回调——六步模式里最容易漏的一步。
     */
    public function failed(?\Throwable $exception): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            // 租户没了也必须落终态：显式 where tenant_id 绕过 fail-open 作用域。
            try {
                $run = AiVisibilityRun::query()->withoutGlobalScopes()
                    ->where('id', $this->runId)->where('tenant_id', $this->tenantId)->first();
                $attemptPersisted = (bool) data_get($run?->metadata, 'model_client_request_id');
                AiVisibilityRun::query()
                    ->withoutGlobalScopes()
                    ->where('id', $this->runId)
                    ->where('tenant_id', $this->tenantId)
                    ->whereNotIn('status', AiVisibilityRun::TERMINAL_STATUSES)
                    ->update([
                        'status' => $attemptPersisted ? AiVisibilityRun::STATUS_RECONCILIATION_REQUIRED : AiVisibilityRun::STATUS_FAILED,
                        'error_message' => $attemptPersisted ? '监测供应商尝试状态待核对。' : '队列任务异常终止（worker 崩溃或超时）。',
                    ]);
            } catch (\Throwable) {
                // 静默——避免循环异常
            }

            return;
        }

        TenantContext::set($tenant);
        try {
            $attemptPersisted = (bool) data_get(AiVisibilityRun::query()->find($this->runId)?->metadata, 'model_client_request_id');
            AiVisibilityRun::query()
                ->where('id', $this->runId)
                ->whereNotIn('status', AiVisibilityRun::TERMINAL_STATUSES)
                ->update([
                    'status' => $attemptPersisted ? AiVisibilityRun::STATUS_RECONCILIATION_REQUIRED : AiVisibilityRun::STATUS_FAILED,
                    'error_message' => $attemptPersisted ? '监测供应商尝试状态待核对。'
                        : (OperationalTextRedactor::redact($exception?->getMessage()) ?: '队列任务异常终止（worker 崩溃或超时）。'),
                ]);
        } catch (\Throwable) {
            // 静默
        } finally {
            TenantContext::forget();
        }
    }
}
