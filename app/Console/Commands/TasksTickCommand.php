<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * 由 scripts/migrate-console-command.mjs 从 routes/console.php 迁出（计划 §7 第 4 条：
 * 路由文件只留调度声明，命令实现进 app/Console/Commands）。
 *
 * 搬迁口径：只搬位置。签名字符串与 purpose 逐字复制，闭包体原样进 handle()，
 * 输出格式、退出码与用户可见文案没有任何改动。
 */
class TasksTickCommand extends Command
{
    protected $signature = 'tasks:tick';

    protected $description = '派发所有到点的定时任务到 tasks 队列';

    public function handle(): int
    {
        $due = \App\Models\Task::withoutGlobalScopes()
            ->where('status', \App\Models\Task::STATUS_ACTIVE)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->orderBy('next_run_at')
            ->get();

        $dispatched = 0;
        foreach ($due as $task) {
            // 立即推进 next_run_at，避免同一周期内重复派发（幂等）
            $task->update([
                'next_run_at' => \App\Models\Task::computeNextRunAtFor($task->cadence, $task->timezone),
            ]);

            \App\Jobs\RunTaskJob::dispatch($task->tenant_id, $task->id, \App\Models\TaskRun::TRIGGER_SCHEDULE);
            $dispatched++;
        }

        $this->info("tasks:tick dispatched {$dispatched} due task(s).");

        // 闭包版跑完不 return（退出码 0）；handle(): int 必须显式给值，否则 TypeError。
        return Command::SUCCESS;
    }
}
