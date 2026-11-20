<?php

namespace App\Services\Workflow;

use App\Models\Article;
use App\Models\ModelCallLog;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\Task;
use App\Models\TaskRun;
use App\Services\Billing\UnresolvedEmbeddingAttempt;
use App\Models\Tenant;
use App\Services\Contracts\SkillAgentRunner;
use App\Services\Contracts\SkillRegistry;
use App\Services\DTOs\SkillBrief;
use App\Services\DTOs\SkillDefinition;
use App\Services\Enums\ExecutionStatus;
use App\Services\Reports\ReportAssetService;
use App\Support\OperationalTextRedactor;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 任务自动化编排服务（阶段 1.4，对标 GEOFlow TaskLifecycleService）
 *
 * 将「技能执行 → 生成草稿 →（可选）发布 →（可选）分发」串成一次可调度运行：
 *  1. 还原租户上下文（队列 Worker 无请求生命周期）
 *  2. 写一条 running 的 TaskRun
 *  3. 组装 SkillBrief（技能 + 项目 + 任务输入）并同步执行 SkillAgentRunner
 *  4. 从 intermediateData['article_id'] 取生成文章
 *  5. auto_publish → 将草稿置为 published（发布观察者会自动分发）
 *     auto_distribute=false → 临时关闭文章自动分发开关，发布但不过站
 *  6. 写回 TaskRun 终态、推进任务的 last_run_at / next_run_at
 *
 * 幂等性：同一队列消息按持久化 token 只建立一次运行；独立手动派发仍各自运行。
 * 分发由 DistributionOrchestrator 自身保证（已发布渠道不再入队）；
 * 调度侧在派发时即推进 next_run_at，避免同一周期内重复派发。
 */
class TaskOrchestrationService
{
    public function __construct(
        private readonly SkillAgentRunner $runner,
        private readonly ProgressBroadcastService $broadcaster,
        private readonly SkillRegistry $registry,
    ) {}

    /**
     * 运行一个任务（手动或定时触发）。
     *
     * 方法内部临时设置并恢复租户上下文，调用方无需预先设置。
     * 任何异常都会被记录为 failed 的 TaskRun，不会向上抛出（避免污染队列 Worker）。
     */
    public function run(Task $task, string $trigger = TaskRun::TRIGGER_MANUAL,
        ?string $queueToken = null, ?array $resume = null, ?array $modelConnectionSnapshot = null): TaskRun
    {
        $tenant = $task->tenant;

        if (! $tenant instanceof Tenant) {
            throw new \RuntimeException("Task #{$task->id} 缺少租户上下文，无法运行。");
        }

        $previousTenant = TenantContext::get();
        TenantContext::set($tenant);

        $run = null;
        $execution = null;

        try {
            if ($resume !== null) {
                [$run, $claimed, $execution] = $this->claimResumedRun($task, $resume);
            } else {
                [$run, $claimed] = $this->claimRun($task, $trigger, $queueToken);
            }
            if (! $claimed) {
                return $run;
            }
            if ($resume !== null) {
                $trigger = $run->trigger;
            }

            $this->broadcaster->taskProgress($task->id, $run->id, TaskRun::STATUS_RUNNING, 5, [
                'trigger' => $trigger,
            ]);

            $brief = $resume !== null
                ? SkillBrief::fromArray($resume['brief']) : $this->buildBrief($task);

            // X1: 自动化任务补建 SkillExecution 并回写 executionId，恢复记账扣费。
            // runner 仅在 $brief->executionId 非空时才走 ModelUsageRecorder 扣费；
            // 此前任务编排路径漏传 executionId，导致定时/自动化任务跑 AI 零记录零扣积分，
            // 与「全量真实扣费」拍板冲突。模式照抄 executeAsync。
            // 真实任务的 skill_id 必在 registry 中；查不到（如测试 mock）则降级保持原行为，不阻断任务。
            $skill = $this->resolveSkillDefinition($task->skill_id);
            $executionBrief = $brief;
            if ($skill && $resume === null) {
                $execution = DB::transaction(function () use ($brief, $skill, $run, $modelConnectionSnapshot) {
                    $execution = SkillExecution::create([
                        'project_id' => $brief->projectId,
                        'user_id' => $brief->userId,
                        'skill_id' => $skill->id,
                        'skill_name' => $skill->name,
                        'category' => $skill->category->value,
                        'stage_index' => $skill->stageIndex,
                        'status' => ExecutionStatus::Running->value,
                        'execution_mode' => 'task',
                        'input_data' => $brief->auditableInputData(),
                        'model_connection_snapshot' => $modelConnectionSnapshot
                            ?? app(\App\Services\AI\ModelConnectionResolver::class)->snapshot($brief->modelSlot()),
                    ]);
                    // Persist before the model call, so timeout/failed callbacks can
                    // recover the exact execution and already-settled call logs.
                    $run->update(['metadata' => array_merge($run->metadata ?? [], ['execution_id' => $execution->id])]);

                    return $execution;
                });

                $executionBrief = new SkillBrief(
                    skillId: $brief->skillId,
                    projectId: $brief->projectId,
                    userId: $brief->userId,
                    fields: $brief->fields,
                    query: $brief->query,
                    contextFromPreviousStage: $brief->contextFromPreviousStage,
                    executionId: $execution->id,
                );
            }

            $result = $this->runner->execute($executionBrief);

            return DB::transaction(function () use ($task, $run, $execution, $result, $trigger, $resume) {
                $run = TaskRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
                if ($run->isTerminal()) {
                    return $run;
                }
                $autoPublish = $resume === null ? (bool) $task->auto_publish
                    : (bool) data_get($run->metadata, 'auto_publish', false);
                $autoDistribute = $resume === null ? (bool) $task->auto_distribute
                    : (bool) data_get($run->metadata, 'auto_distribute', false);
                // Serialize finalization with cancellation's conditional status update.
                // This also prevents a stale successful result from publishing after cancel.
                $execution = $execution ? SkillExecution::whereKey($execution->id)->lockForUpdate()->firstOrFail() : null;
                $terminalExecution = $execution && ExecutionStatus::tryFrom($execution->status)?->isTerminal();
                $success = $result->success && ! $terminalExecution;
                $error = $terminalExecution
                    ? ($execution->error_message ?: 'Task execution reached a terminal state before result finalization.')
                    : $result->errorMessage;

                $articleId = $result->intermediateData['article_id'] ?? null;
                if ($success && $execution && ! $articleId
                    && is_string($result->intermediateData['billing_pending_article_content'] ?? null)) {
                    $articleId = app(ReportAssetService::class)->createOrLinkForSkillExecution(
                        $execution,
                        $result->intermediateData['billing_pending_article_content'],
                        null,
                        is_array($result->intermediateData['structured'] ?? null)
                            ? $result->intermediateData['structured'] : null,
                        $result->qualityReport,
                    )->id;
                }
                $published = false;

                if ($success && $articleId && $autoPublish) {
                    $published = $this->publishArticle((int) $articleId, $autoDistribute);
                }

                $run->update([
                    'status' => $success ? TaskRun::STATUS_SUCCEEDED : TaskRun::STATUS_FAILED,
                    'article_id' => $articleId,
                    'finished_at' => now(),
                    'error_message' => $success ? null : $this->friendlyError($error),
                    'metadata' => array_merge($run->metadata ?? [], [
                        'duration_seconds' => $result->durationSeconds,
                        'token_usage' => $result->tokenUsage,
                        'auto_published' => $published,
                        'auto_distributed' => $published && $autoDistribute,
                        'trigger' => $trigger,
                        // X1: 绑定本次运行的 SkillExecution（查不到 skill 时无，诚实留空）
                        'execution_id' => $execution?->id,
                    ]),
                ]);

                // 收尾 SkillExecution 状态，与 run 终态一致
                if ($execution && ! $terminalExecution) {
                    $outputData = array_merge($execution->output_data ?? [], $result->outputPaths);
                    foreach (['structured', 'pipeline_mode', 'rendered_formats', 'chunk_format'] as $key) {
                        if (isset($result->intermediateData[$key])) {
                            $outputData[$key] = $result->intermediateData[$key];
                        }
                    }
                    if (isset($result->intermediateData['raw_output'])) {
                        $outputData['result_text'] = $result->intermediateData['raw_output'];
                    }
                    $execution->update([
                        'status' => $success ? ExecutionStatus::Completed->value : ExecutionStatus::Failed->value,
                        'article_id' => $articleId,
                        'output_data' => $outputData,
                        'quality_report' => $result->qualityReport,
                        'duration_seconds' => $result->durationSeconds,
                        'token_usage' => $result->tokenUsage,
                        'error_message' => $success ? null : $this->friendlyError($error),
                        'billing_pending_result' => null,
                    ]);
                }

                // 推进任务调度时间（无论成功失败，都进入下一个周期）
                if ($resume === null) {
                    $this->advanceSchedule($task);
                }

                $this->broadcaster->taskProgress(
                    $task->id,
                    $run->id,
                    $success ? TaskRun::STATUS_SUCCEEDED : TaskRun::STATUS_FAILED,
                    100,
                    [
                        'article_id' => $articleId,
                        'auto_published' => $published,
                        'auto_distributed' => $published && $autoDistribute,
                    ],
                );

                return $run->fresh();
            });
        } catch (UnresolvedEmbeddingAttempt $e) {
            if ($run && $execution) {
                return $this->holdPendingEmbeddingRun($task, $run, $execution, $e, $resume === null);
            }
            throw $e;
        } catch (\Throwable $e) {
            $prepared = $execution?->fresh()?->billing_pending_result;
            if ($run && $execution && in_array(data_get($prepared, 'kind'),
                ['embedding_attempt_prepared', 'embedding_result_recorded'], true)) {
                return $this->holdPendingEmbeddingRun($task, $run, $execution,
                    new UnresolvedEmbeddingAttempt($prepared['gateway']), $resume === null);
            }
            Log::error('Task orchestration failed', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);

            if ($run) {
                $this->closeFailedRun($run, $execution, $e->getMessage());
                if ($resume === null) {
                    $this->advanceSchedule($task);
                }

                return $run->fresh();
            }

            // 连运行记录都未能创建（极端情况），向上抛以便 Worker 标记
            throw $e;
        } finally {
            $previousTenant ? TenantContext::set($previousTenant) : TenantContext::forget();
        }
    }

    private function holdPendingEmbeddingRun(Task $task, TaskRun $run, SkillExecution $execution,
        UnresolvedEmbeddingAttempt $pending, bool $advanceSchedule = true): TaskRun
    {
        $pending->hold($execution);
        $run->update([
            'status' => TaskRun::STATUS_BILLING_PENDING,
            'error_message' => $pending->getMessage(),
            'metadata' => array_merge($run->metadata ?? [], [
                'execution_id' => $execution->id,
            ]),
        ]);
        if ($advanceSchedule) {
            $this->advanceSchedule($task);
        }
        $this->broadcaster->taskProgress($task->id, $run->id,
            TaskRun::STATUS_BILLING_PENDING, 100, ['execution_id' => $execution->id]);

        return $run->fresh();
    }

    /** A short task-row lock protects one claim per serialized queue message. */
    private function claimRun(Task $task, string $trigger, ?string $queueToken): array
    {
        return DB::transaction(function () use ($task, $trigger, $queueToken) {
            Task::whereKey($task->id)->where('tenant_id', $task->tenant_id)->lockForUpdate()->firstOrFail();
            if ($queueToken !== null) {
                $existing = TaskRun::where('tenant_id', $task->tenant_id)->where('task_id', $task->id)
                    ->where('metadata->queue_token', $queueToken)->first();
                if ($existing) {
                    return [$existing, false];
                }
            }

            return [TaskRun::create([
                'tenant_id' => $task->tenant_id, 'task_id' => $task->id, 'skill_id' => $task->skill_id,
                'trigger' => $trigger, 'status' => TaskRun::STATUS_RUNNING, 'started_at' => now(),
                'metadata' => ['queue_token' => $queueToken, 'trigger' => $trigger,
                    'execution_id' => null,
                    'auto_publish' => (bool) $task->auto_publish,
                    'auto_distribute' => (bool) $task->auto_distribute],
            ]), true];
        });
    }

    /** Claim the same run and execution; a duplicate queue delivery cannot start a second model path. */
    private function claimResumedRun(Task $task, array $resume): array
    {
        return DB::transaction(function () use ($task, $resume): array {
            $run = TaskRun::query()->whereKey($resume['run_id'])->lockForUpdate()->first();
            $execution = SkillExecution::query()->whereKey($resume['execution_id'])->lockForUpdate()->first();
            if (! $run || ! $execution) {
                throw new \RuntimeException('Original task run is unavailable.');
            }
            if ($run->task_id !== $task->id
                || $run->tenant_id !== $task->tenant_id
                || $task->project_id !== $execution->project_id
                || $task->user_id !== $execution->user_id
                || $task->skill_id !== $execution->skill_id
                || (int) data_get($run->metadata, 'execution_id') !== $execution->id
                || $run->status !== TaskRun::STATUS_BILLING_PENDING
                || $execution->status !== ExecutionStatus::Pending->value
                || data_get($execution->billing_pending_result, 'kind') !== 'embedding_result_recorded'
                || data_get($resume, 'brief.executionId') !== $execution->id
                || data_get($resume, 'brief.projectId') !== $execution->project_id
                || data_get($resume, 'brief.userId') !== $execution->user_id
                || data_get($resume, 'brief.skillId') !== $execution->skill_id
                || data_get($resume, 'brief.query') !== data_get($execution->billing_pending_result, 'query')) {
                return [$run, false, null];
            }
            $execution->update(['status' => ExecutionStatus::Running->value]);
            $run->update(['status' => TaskRun::STATUS_RUNNING, 'error_message' => null]);
            return [$run, true, $execution];
        });
    }

    /** Queue failure after claim returns the original paid attempt to visible review. */
    public function failResumedRun(int $tenantId, int $runId, int $executionId, ?\Throwable $exception): void
    {
        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            return;
        }
        $previousTenant = TenantContext::get();
        TenantContext::set($tenant);
        try {
            $run = TaskRun::query()->whereKey($runId)->first();
            $execution = SkillExecution::query()->whereKey($executionId)->first();
            if ($run && $execution && $run->status === TaskRun::STATUS_BILLING_PENDING
                && $execution->status === ExecutionStatus::Pending->value
                && data_get($execution->billing_pending_result, 'kind') === 'embedding_result_recorded') {
                SkillExecution::query()->whereKey($executionId)
                    ->where('status', ExecutionStatus::Pending->value)
                    ->update(['status' => ExecutionStatus::BillingPending->value,
                        'error_message' => '原向量结果已记录，自动任务待核验后续办。']);
                return;
            }
            if ($run && $execution && $run->status === TaskRun::STATUS_RUNNING
                && (int) data_get($run->metadata, 'execution_id') === $execution->id) {
                $this->closeFailedRun($run, $execution,
                    $exception?->getMessage() ?: 'Recovered task stopped before completion.');
            }
        } finally {
            $previousTenant ? TenantContext::set($previousTenant) : TenantContext::forget();
        }
    }

    /** Mark only this queue delivery's unfinished run after timeout/worker failure. */
    public function failQueuedRun(int $tenantId, int $taskId, string $queueToken, ?\Throwable $exception): void
    {
        DB::transaction(function () use ($tenantId, $taskId, $queueToken, $exception) {
            $run = TaskRun::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('task_id', $taskId)
                ->where('metadata->queue_token', $queueToken)->lockForUpdate()->first();
            if (! $run || $run->isTerminal() || $run->status === TaskRun::STATUS_BILLING_PENDING) {
                return;
            }
            $executionId = $run->metadata['execution_id'] ?? null;
            $execution = $executionId ? SkillExecution::withoutGlobalScopes()->where('tenant_id', $tenantId)
                ->whereKey($executionId)->lockForUpdate()->first() : null;
            $this->closeFailedRun($run, $execution, $exception?->getMessage() ?: 'Queued task stopped before completion.');
            $task = Task::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($taskId)->first();
            if ($task && ! $task->trashed()) {
                $this->advanceSchedule($task);
            }
        });
    }

    private function closeFailedRun(TaskRun $run, ?SkillExecution $execution, string $message): void
    {
        DB::transaction(function () use ($run, $execution, $message) {
            $run = TaskRun::withoutGlobalScopes()->where('tenant_id', $run->tenant_id)->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($run->isTerminal() || $run->status === TaskRun::STATUS_BILLING_PENDING) {
                return;
            }
            if ($execution) {
                $execution = SkillExecution::withoutGlobalScopes()->where('tenant_id', $run->tenant_id)->whereKey($execution->id)->lockForUpdate()->first();
            }
            if ($execution && in_array(data_get($execution->billing_pending_result, 'kind'),
                ['embedding_attempt_prepared', 'embedding_attempt_unknown', 'embedding_result_recorded',
                    'model_attempt_prepared', 'model_attempt_unknown',
                    'model_result_unrecorded', 'model_result_recorded'], true)) {
                $execution->update([
                    'status' => ExecutionStatus::BillingPending->value,
                    'error_message' => '原付费请求或结果待核对，执行已暂缓；请勿重新提交',
                ]);
                $run->update([
                    'status' => TaskRun::STATUS_BILLING_PENDING,
                    'error_message' => $execution->error_message,
                    'metadata' => array_merge($run->metadata ?? [], [
                        'execution_id' => $execution->id,
                    ]),
                ]);
                $this->broadcaster->taskProgress($run->task_id, $run->id,
                    TaskRun::STATUS_BILLING_PENDING, 100, ['execution_id' => $execution->id]);
                return;
            }
            if ($execution) {
                SkillExecution::withoutGlobalScopes()->where('tenant_id', $run->tenant_id)->whereKey($execution->id)
                    ->whereIn('status', [ExecutionStatus::Pending->value, ExecutionStatus::Running->value])
                    ->update(['status' => ExecutionStatus::Failed->value, 'error_message' => $this->friendlyError($message)]);
            }
            $run->update([
                'status' => TaskRun::STATUS_FAILED, 'finished_at' => now(), 'error_message' => $this->friendlyError($message),
                'metadata' => array_merge($run->metadata ?? [], ['execution_id' => $execution?->id]),
            ]);
            $this->broadcaster->taskProgress($run->task_id, $run->id, TaskRun::STATUS_FAILED, 100, ['error' => $this->friendlyError($message)]);
        });
    }

    private function advanceSchedule(Task $task): void
    {
        $task->update(['last_run_at' => now(), 'next_run_at' => Task::computeNextRunAtFor($task->cadence, $task->timezone)]);
    }

    /**
     * 组装 SkillBrief。
     *
     * 复用任务保存的 input_data 作为简报字段；userId 回退到项目 owner。
     * 不自动注入前置阶段上下文（任务面向单技能，行为可预期）。
     */
    private function buildBrief(Task $task): SkillBrief
    {
        /** @var Project $project */
        $project = $task->project;

        $userId = $task->user_id ?? ($project->owner_id ?? $task->user_id);

        return new SkillBrief(
            skillId: $task->skill_id,
            projectId: $task->project_id,
            userId: (int) $userId,
            fields: $task->input_data ?? [],
            query: $task->query,
            contextFromPreviousStage: [],
        );
    }

    /**
     * 发布文章（草稿 → 已发布）。
     *
     * 返回是否成功发布。auto_distribute=false 时临时关闭文章自动分发开关，
     * 使文章进入 published 状态但不过站外发，调用后务必还原开关。
     */
    private function publishArticle(int $articleId, bool $autoDistribute): bool
    {
        $article = Article::find($articleId);

        if (! $article) {
            return false;
        }

        $previous = Article::$autoDistributionEnabled;
        Article::$autoDistributionEnabled = $autoDistribute;

        try {
            $article->update([
                'status' => 'published',
                'published_at' => now(),
            ]);
        } finally {
            Article::$autoDistributionEnabled = $previous;
        }

        return $article->fresh()->status === 'published';
    }

    /** Resume the original automation run after its skill result is released. */
    public function releaseBillingPendingRun(TaskRun $run, SkillExecution $execution, Article $article, array $delivery): void
    {
        if ($run->status !== TaskRun::STATUS_BILLING_PENDING) {
            return;
        }
        $task = Task::query()->whereKey($run->task_id)->first();
        $autoPublish = (bool) ($delivery['auto_publish'] ?? false);
        $autoDistribute = (bool) ($delivery['auto_distribute'] ?? false);
        $published = $task && $autoPublish
            ? $this->publishArticle($article->id, $autoDistribute) : false;
        $run->update([
            'status' => TaskRun::STATUS_SUCCEEDED,
            'article_id' => $article->id,
            'finished_at' => now(),
            'error_message' => null,
            'metadata' => array_merge($run->metadata ?? [], [
                'auto_published' => $published,
                'auto_distributed' => $published && $autoDistribute,
            ]),
        ]);
        if ($task) {
            $this->broadcaster->taskProgress($task->id, $run->id, TaskRun::STATUS_SUCCEEDED, 100, [
                'article_id' => $article->id,
                'auto_published' => $published,
                'auto_distributed' => $published && $autoDistribute,
            ]);
        }
    }

    public function failBillingPendingRun(TaskRun $run, SkillExecution $execution, string $reason): void
    {
        if ($run->status !== TaskRun::STATUS_BILLING_PENDING) {
            return;
        }
        $run->update([
            'status' => TaskRun::STATUS_FAILED,
            'finished_at' => now(),
            'error_message' => $this->friendlyError($reason),
            'metadata' => array_merge($run->metadata ?? [], [
            ]),
        ]);
        $this->broadcaster->taskProgress($run->task_id, $run->id, TaskRun::STATUS_FAILED, 100);
    }

    /**
     * 解析任务 skill 定义；查不到（如测试 mock 的假 skill_id）则降级返回 null，
     * 不阻断任务执行（此时 X1 的记账 execution 不建，行为回退到修复前）。
     */
    private function resolveSkillDefinition(string $skillId): ?SkillDefinition
    {
        try {
            return $this->registry->find($skillId);
        } catch (\Throwable $e) {
            Log::warning('Task orchestration: skill definition not found, billing execution skipped', [
                'skill_id' => $skillId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function friendlyError(?string $message): ?string
    {
        if (! $message) {
            return null;
        }

        if (str_contains($message, "\n") || str_contains($message, '\\') || str_contains($message, '/')) {
            return 'Task execution failed. Please review the task configuration and try again.';
        }

        return OperationalTextRedactor::redact($message);
    }
}
