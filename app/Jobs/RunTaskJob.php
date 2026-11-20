<?php

namespace App\Jobs;

use App\Models\Task;
use App\Models\TaskRun;
use App\Models\Tenant;
use App\Services\AI\ModelConnectionResolver;
use App\Services\DTOs\SkillBrief;
use App\Services\Workflow\TaskOrchestrationService;
use App\Support\TenantContext;
use App\Support\TenantScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 任务运行队列任务（阶段 1.4，队列 tasks）
 *
 * 由 tasks:tick 或手动「立即运行」派发。还原租户上下文后调用
 * TaskOrchestrationService::run()，把执行细节交给编排服务处理。
 */
class RunTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 超时（秒）：与 SkillAgentRunnerService::execute() 的 set_time_limit(420) 对齐，留余量。
     */
    public int $tries = 1;

    public int $timeout = 480;

    public bool $failOnTimeout = true;

    /** Stable across serialization/redelivery; old payloads may have no token. */
    public ?string $runToken = null;

    /** Historical jobs without a snapshot can complete using their original recovery protocol. */
    public ?array $modelConnectionSnapshot = null;

    /**
     * @param  int  $tenantId  租户 ID（队列 Worker 还原 TenantContext）
     * @param  int  $taskId  任务 ID
     * @param  string  $trigger  触发来源 manual | schedule
     */
    public function __construct(
        public int $tenantId,
        public int $taskId,
        public string $trigger = TaskRun::TRIGGER_SCHEDULE,
        ?array $modelConnectionSnapshot = null,
    ) {
        $this->runToken = (string) Str::uuid();
        $this->modelConnectionSnapshot = $modelConnectionSnapshot;
        $this->onQueue('tasks');
    }

    public static function dispatchFor(Task $task, string $trigger): void
    {
        $previous = TenantContext::get();
        $tenant = $task->tenant;
        if ($tenant === null) {
            return;
        }

        TenantContext::set($tenant);
        try {
            $slot = SkillBrief::imageInputs($task->input_data ?? []) === [] ? 'text' : 'vision';
            $snapshot = app(ModelConnectionResolver::class)->snapshot($slot);
            self::dispatch($task->tenant_id, $task->id, $trigger, $snapshot);
        } finally {
            $previous ? TenantContext::set($previous) : TenantContext::forget();
        }
    }

    public function handle(TaskOrchestrationService $service): void
    {
        TenantContext::forget();
        try {
            $this->handleWithinWorker($service);
        } finally {
            TenantContext::forget();
        }
    }

    private function handleWithinWorker(TaskOrchestrationService $service): void
    {
        $tenant = Tenant::find($this->tenantId);

        if (! $tenant) {
            Log::error('RunTaskJob: tenant not found', [
                'tenant_id' => $this->tenantId,
                'task_id' => $this->taskId,
            ]);

            return;
        }

        if (! $tenant->isActive()) {
            Log::info('RunTaskJob: tenant not active, skipping', [
                'tenant_id' => $this->tenantId,
                'task_id' => $this->taskId,
            ]);

            return;
        }

        // 只绕过租户作用域，保留软删除过滤，避免重新执行已删除任务。
        $task = Task::withoutGlobalScope(TenantScope::class)
            ->where('id', $this->taskId)
            ->where('tenant_id', $this->tenantId)
            ->first();

        if (! $task) {
            Log::warning('RunTaskJob: task not found', [
                'task_id' => $this->taskId,
                'tenant_id' => $this->tenantId,
            ]);

            return;
        }

        if ($task->status !== Task::STATUS_ACTIVE) {
            Log::info('RunTaskJob: task not active, skipping', [
                'task_id' => $this->taskId,
                'status' => $task->status,
            ]);

            return;
        }

        $service->run($task, $this->trigger, $this->queueRunToken(), modelConnectionSnapshot: $this->modelConnectionSnapshot);
    }

    /** The callback is invoked on a fresh copy of the serialized job. */
    public function failed(?\Throwable $exception): void
    {
        TenantContext::forget();
        try {
            $token = $this->queueRunToken();
            if ($token !== null) {
                app(TaskOrchestrationService::class)->failQueuedRun($this->tenantId, $this->taskId, $token, $exception);
            }
        } catch (\Throwable $e) {
            Log::error('RunTaskJob: failed to close interrupted run', ['task_id' => $this->taskId, 'error' => $e->getMessage()]);
        } finally {
            TenantContext::forget();
        }
    }

    private function queueRunToken(): ?string
    {
        // Laravel's payload UUID also identifies pre-deployment queued jobs. Never
        // invent a fresh UUID while handling them: the failed callback must match.
        return $this->runToken ?? $this->job?->uuid();
    }
}
