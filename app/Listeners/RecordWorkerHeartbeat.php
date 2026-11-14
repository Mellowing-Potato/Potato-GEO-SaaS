<?php

namespace App\Listeners;

use App\Models\WorkerHeartbeat;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Log;

/**
 * 单 9 · 队列 worker 心跳记录器（挂在 Queue::looping 上）。
 *
 * 三条致命红线（执行书 §一），任一条写错都会让全平台队列停摆：
 *
 *  🔴 红线 1：handleLooping / handleStopping 签名必须 : void，方法体禁止任何 return。
 *     Queue::looping 是可中断派发（Worker.php:348 `events->until(new Looping(...)) === false`），
 *     监听器返回 false → 整台 worker 永久停摆、不报错不退出、容器健康。
 *     本类所有 handler 一律 : void，且方法体里没有任何 return 语句（节流用 `return;` 空返回，非 `return false`）。
 *
 *  🔴 红线 2：心跳写库失败必须静默吞掉，不许抛异常。
 *     我们跑在每一轮循环里，DB 抖一下→抛异常→worker 进程崩→队列停摆。
 *     catch 后只 Log::debug（不许 Log::error，DB 抖动会刷爆日志）。心跳是观测手段，自己不能成为故障源。
 *
 *  🔴 红线 3：心跳写入频率必须节流（进程内 static 计时），否则每 worker 每 --sleep(默认3s) 一次 UPDATE 打到 Postgres。
 */
class RecordWorkerHeartbeat
{
    /**
     * 进程内节流计时（跨多次 Looping 调用累计）。static 跨请求存活于常驻 worker 进程。
     */
    private static ?float $lastBeatAt = null;

    /**
     * 🔴 红线 1：: void，无 return。
     * Looping 每轮循环都发（空闲时也发），正是「我还活着」的语义（执行书 §3.2 选型表）。
     */
    public function handleLooping(Looping $event): void
    {
        if (! config('geo.worker_heartbeat.enabled')) {
            return; // 空返回，红线 1 仍满足（不是 return false）
        }

        $interval = max(1, (int) config('geo.worker_heartbeat.interval_seconds', 15));
        $now = microtime(true);
        if (self::$lastBeatAt !== null && ($now - self::$lastBeatAt) < $interval) {
            return; // 节流：空返回，非 return false
        }
        self::$lastBeatAt = $now;

        $this->write($event->connectionName, $event->queue, 'running');
    }

    /**
     * 监听 WorkerStopping：--max-time 正常回收时把 status 写成 stopping，
     * 看板显示「回收中」而非和真崩溃长一个样（执行书 §3.2）。
     * 不传 meta（updateOrCreate 只动给定列），meta.queues 保留最后存活心跳的值。
     */
    public function handleStopping(WorkerStopping $event): void
    {
        if (! config('geo.worker_heartbeat.enabled')) {
            return;
        }

        try {
            WorkerHeartbeat::query()->updateOrCreate(
                ['worker_id' => $this->workerId()],
                ['status' => 'stopping', 'last_seen_at' => now()]
            );
        } catch (\Throwable $e) {
            // 🔴 红线 2：静默吞
            Log::debug('[queue-health] worker stopping 心跳写入失败（已静默跳过）: '.$e->getMessage());
        }
    }

    private function write(string $connection, string $queue, string $status): void
    {
        $workerId = $this->workerId();
        $queues = array_values(array_filter(array_map('trim', explode(',', (string) $queue)), fn ($q) => $q !== ''));

        $meta = [
            'queues' => $queues,
            'connection' => $connection,
            'pid' => getmypid(),
            'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 1),
            'max_time_started_at' => env('WORKER_MAX_TIME_STARTED_AT'),
        ];

        try {
            WorkerHeartbeat::query()->updateOrCreate(
                ['worker_id' => $workerId],
                [
                    'status' => $status,
                    'last_seen_at' => now(),
                    'meta' => $meta,
                ]
            );
        } catch (\Throwable $e) {
            // 🔴 红线 2：静默吞（不许抛、不许 Log::error）
            Log::debug('[queue-health] worker 心跳写入失败（已静默跳过）: '.$e->getMessage());
        }
    }

    private function workerId(): string
    {
        // WORKER_ID 优先（compose 注入，见 部署配置 改动）；取不到退回主机名（容器内=容器ID，能跑但不好认）。
        return (string) (env('WORKER_ID') ?: gethostname());
    }
}
