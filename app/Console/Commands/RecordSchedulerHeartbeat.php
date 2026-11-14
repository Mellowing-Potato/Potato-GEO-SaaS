<?php

namespace App\Console\Commands;

use App\Models\WorkerHeartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 单 9 · 调度器心跳刷新命令。
 *
 * geo-scheduler 跑 schedule:work，不消费队列，Queue::looping 永不响（执行书 §3.2(b)）。
 * 但它挂了同样是事故（tasks:tick / 敏感词清理 / 知识库清道夫全靠它），
 * 故用每分钟调度保活：写一条 worker_id=scheduler 的心跳，看板照常判定 stale。
 */
class RecordSchedulerHeartbeat extends Command
{
    protected $signature = 'ops:heartbeat {worker_id? : 该心跳的 worker 标识，默认 scheduler}';

    protected $description = '刷新调度器心跳（geo-scheduler 不消费队列，靠每分钟调度保活）';

    public function handle(): int
    {
        if (! config('geo.worker_heartbeat.enabled')) {
            return self::SUCCESS;
        }

        $workerId = $this->argument('worker_id') ?: 'scheduler';

        $meta = [
            'queues' => [], // 调度器不消费队列
            'connection' => 'schedule',
            'pid' => getmypid(),
            'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 1),
        ];

        try {
            WorkerHeartbeat::query()->updateOrCreate(
                ['worker_id' => $workerId],
                [
                    'status' => 'running',
                    'last_seen_at' => now(),
                    'meta' => $meta,
                ]
            );
        } catch (\Throwable $e) {
            // 静默吞：心跳写入失败不能让调度命令崩
            Log::debug('[queue-health] scheduler 心跳写入失败（已静默跳过）: '.$e->getMessage());

            return self::SUCCESS;
        }

        return self::SUCCESS;
    }
}
