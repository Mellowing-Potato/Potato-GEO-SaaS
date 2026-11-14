<?php

namespace App\Jobs;

use App\Models\Task;
use App\Models\Tenant;
use App\Services\Workflow\TaskOrchestrationService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Continue one original automation run using its recorded embedding, never a new run. */
final class ResumeTaskEmbeddingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 480;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $taskId,
        public readonly int $runId,
        public readonly int $executionId,
        public readonly array $brief,
    ) {
        $this->onQueue('tasks');
    }

    public function handle(TaskOrchestrationService $service): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant || ! $tenant->isActive()) {
            $service->failResumedRun($this->tenantId, $this->runId, $this->executionId, null);
            return;
        }
        $task = Task::withoutGlobalScopes()->where('tenant_id', $this->tenantId)
            ->whereKey($this->taskId)->first();
        if (! $task || $task->trashed() || $task->status !== Task::STATUS_ACTIVE) {
            $service->failResumedRun($this->tenantId, $this->runId, $this->executionId, null);
            return;
        }
        $service->run($task, resume: [
            'run_id' => $this->runId,
            'execution_id' => $this->executionId,
            'brief' => $this->brief,
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        TenantContext::forget();
        try {
            app(TaskOrchestrationService::class)->failResumedRun(
                $this->tenantId, $this->runId, $this->executionId, $exception);
        } finally {
            TenantContext::forget();
        }
    }
}
