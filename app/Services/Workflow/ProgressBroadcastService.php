<?php

namespace App\Services\Workflow;

use App\Events\JobProgressUpdated;
use Throwable;

/**
 * 实时进度广播服务（阶段 3.2，对标 GEOFlow TaskRealtimeBroadcastService）。
 *
 * 统一进度事件出口：技能执行 / 任务运行 / 分发进度变化时调用 emit()，
 * 由 ProgressBroadcastService 派发 JobProgressUpdated 事件。
 * 无广播驱动时（如测试、本地）安全降级为 no-op，不影响主流程。
 */
final class ProgressBroadcastService
{
    /**
     * 派发一条进度事件。
     *
     * @param  array<string, mixed>  $payload
     */
    public function emit(string $topic, array $payload = []): void
    {
        try {
            event(new JobProgressUpdated($topic, $payload));
        } catch (Throwable) {
            // 广播失败绝不影响主业务流程（轮询兜底）。
        }
    }

    /**
     * 技能执行进度（供前端订阅 `skill.{executionId}`）。
     *
     * @param  array<string, mixed>  $extra
     */
    public function skillProgress(int $executionId, string $status, int $percent, array $extra = []): void
    {
        $this->emit("skill.{$executionId}", array_merge([
            'execution_id' => $executionId,
            'status' => $status,
            'percent' => $percent,
        ], $extra));
    }

    /**
     * 任务运行进度（供前端订阅 `task.{taskId}`）。
     *
     * @param  array<string, mixed>  $extra
     */
    public function taskProgress(int $taskId, int $runId, string $status, int $percent, array $extra = []): void
    {
        $this->emit("task.{$taskId}", array_merge([
            'task_id' => $taskId,
            'run_id' => $runId,
            'status' => $status,
            'percent' => $percent,
        ], $extra));
    }

    /**
     * 分发进度（供前端订阅 `distribution.{publicationId}`）。
     *
     * @param  array<string, mixed>  $extra
     */
    public function distributionProgress(int $publicationId, string $status, int $percent, array $extra = []): void
    {
        $this->emit("distribution.{$publicationId}", array_merge([
            'publication_id' => $publicationId,
            'status' => $status,
            'percent' => $percent,
        ], $extra));
    }
}
