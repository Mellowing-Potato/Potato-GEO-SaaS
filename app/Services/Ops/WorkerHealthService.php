<?php

namespace App\Services\Ops;

use App\Models\WorkerHeartbeat;
use Illuminate\Support\Facades\DB;

/**
 * 单 9 · 队列健康判定服务。
 *
 * 产出三块（执行书 §3.3）：
 *   ① worker 列表（谁还活着）
 *   ② 无消费者检测（本单最重要的一条：有任务在排队但无 worker 消费）
 *   ③ 队列深度（pending / 最老等待时长 / 近 24h 失败数）
 *
 * 全部查询只读 jobs / failed_jobs / worker_heartbeats，不改任何表结构（执行书 §四）。
 *
 * 2026-09-01 评审后补的第二类告警（老板拍板的行为变更）：
 *   原来「无消费者」只看 pending，而 pending 的定义是 reserved_at IS NULL。
 *   worker 把任务领走（reserved_at 落值）之后进程才死掉的话，这行任务 pending 算 0 →
 *   整块看板一声不吭，正好漏掉它最该抓的场景（任务卡住且不报错）。
 *   现在补 stuck_reserved：被领走超过 retry_after 秒、且没有活心跳覆盖该队列 → 告警。
 */
class WorkerHealthService
{
    /**
     * 看板快照。
     *
     * @return array{workers:list<array>, queues:list<array>, alerts:list<array>, stale_seconds:int}
     */
    public function snapshot(): array
    {
        $staleSeconds = max(30, (int) config('geo.worker_heartbeat.stale_seconds', 120));
        $now = now();

        // ① worker 列表
        $heartbeats = WorkerHeartbeat::query()->get();
        $workers = $heartbeats->map(function (WorkerHeartbeat $hb) use ($staleSeconds, $now) {
            $isStale = $hb->last_seen_at === null || $hb->last_seen_at->lt($now->copy()->subSeconds($staleSeconds));

            return [
                'worker_id' => $hb->worker_id,
                'status' => $hb->status,
                'last_seen_at' => $hb->last_seen_at?->toIso8601String(),
                'stale' => $isStale,
                'queues' => $hb->meta['queues'] ?? [],
                'connection' => $hb->meta['connection'] ?? null,
            ];
        })->values()->all();

        // ② 无消费者检测
        //   活着的队列 = 所有「非 stale」心跳的 meta.queues 并集
        //   已知的队列 = 所有心跳（含 stale）的 meta.queues 并集
        //     —— 只用来分辨「这个队列压根没人配」和「配了但心跳断了」，给告警定严重级别，不参与是否告警的判断。
        $aliveQueues = [];
        $knownQueues = [];
        foreach ($heartbeats as $hb) {
            $isStale = $hb->last_seen_at === null || $hb->last_seen_at->lt($now->copy()->subSeconds($staleSeconds));
            foreach (($hb->meta['queues'] ?? []) as $q) {
                if ($q === '') {
                    continue;
                }
                $knownQueues[$q] = true;
                if (! $isStale) {
                    $aliveQueues[$q] = true;
                }
            }
        }
        $aliveQueues = array_keys($aliveQueues);
        $knownQueues = array_keys($knownQueues);

        // ③ 队列深度 + 无消费者告警
        //   有活的队列 = jobs 表里 distinct queue
        $activeQueues = DB::table('jobs')->distinct()->orderBy('queue')->pluck('queue')->all();

        // 「卡住」的判据必须和数据库队列驱动自己的重派阈值同一个数字：
        // Illuminate\Queue\DatabaseQueue::isReservedButExpired() 用 reserved_at <= now-retry_after 决定
        // 一行任务是否可以被重新领走。所以这里直接读配置，不写死 600。
        $retryAfter = max(60, (int) config('queue.connections.'.config('queue.default', 'database').'.retry_after', 600));
        $stuckBefore = $now->timestamp - $retryAfter;

        $queues = [];
        $alerts = [];
        foreach ($activeQueues as $q) {
            $pending = DB::table('jobs')->where('queue', $q)->whereNull('reserved_at')->count();
            $oldestCreatedAt = DB::table('jobs')->where('queue', $q)->whereNull('reserved_at')->min('created_at');
            // jobs.created_at 是 unsignedInteger（Unix 秒），不是 Carbon 列；用 now()->timestamp 求差。
            $oldestWaitSeconds = $oldestCreatedAt !== null ? (now()->timestamp - (int) $oldestCreatedAt) : null;
            // jobs.reserved_at 和 created_at 一样是 unsignedInteger（Unix 秒），比较只能用时间戳，不能塞 Carbon。
            $stuckReserved = DB::table('jobs')
                ->where('queue', $q)
                ->whereNotNull('reserved_at')
                ->where('reserved_at', '<', $stuckBefore)
                ->count();
            $oldestReservedAt = DB::table('jobs')->where('queue', $q)->whereNotNull('reserved_at')->min('reserved_at');
            $oldestReservedSeconds = $oldestReservedAt !== null ? ($now->timestamp - (int) $oldestReservedAt) : null;
            $failed24h = DB::table('failed_jobs')
                ->where('queue', $q)
                ->where('failed_at', '>=', $now->copy()->subHours(24))
                ->count();

            $queues[] = [
                'queue' => $q,
                'pending' => $pending,
                'oldest_wait_seconds' => $oldestWaitSeconds,
                'stuck_reserved' => $stuckReserved,
                'oldest_reserved_seconds' => $oldestReservedSeconds,
                'failed_24h' => $failed24h,
            ];

            $hasConsumer = in_array($q, $aliveQueues, true);

            // 告警一 · 有任务在排队、没人消费。
            // ⚠️ 空队列不报警：必须是「有任务（pending>0）」且「无消费者」两个条件都满足（执行书 §3.3）。
            if ($pending > 0 && ! $hasConsumer) {
                $alerts[] = [
                    'type' => 'no_consumer',
                    'queue' => $q,
                    'pending' => $pending,
                    'severity' => 'critical',
                    'message' => "队列 `{$q}` 有 {$pending} 个任务在排队，但没有任何 worker 在消费它。"
                        .'这些任务不会执行，也不会报错。请检查 compose.yaml 里 worker 的 --queue= 列表。',
                ];
            }

            // 告警二 · 任务被 worker 领走后卡住（2026-09-01 加）。
            // 「没人消费」是必要条件，不是凑数的：只要还有活着的 worker，数据库队列驱动会在 retry_after
            // 之后把这些行重新派出去，能自愈；没有 worker 时它们才会永远卡着。
            if ($stuckReserved > 0 && ! $hasConsumer) {
                // 有心跳行覆盖这个队列（只是过期了）→ 可能是 worker 死了，也可能是它正忙着跑长任务
                //   （worker 在跑任务期间不发 Looping，心跳本来就会过期）→ 只能报 warning，话要说两头。
                // 完全没有心跳覆盖 → 没有任何进程会碰这些任务 → critical。
                $configured = in_array($q, $knownQueues, true);
                $alerts[] = [
                    'type' => 'stuck_reserved',
                    'queue' => $q,
                    'stuck_reserved' => $stuckReserved,
                    'retry_after_seconds' => $retryAfter,
                    'severity' => $configured ? 'warning' : 'critical',
                    'message' => $configured
                        ? "队列 `{$q}` 有 {$stuckReserved} 个任务被 worker 领走后超过 {$retryAfter} 秒还没跑完，"
                            .'而配了这个队列的 worker 心跳已经断了。两种可能：worker 进程死了（任务会一直卡着，不报错），'
                            .'或者它正忙着跑一个比这还久的长任务（worker 跑任务期间不发心跳）。请对照上面 worker 列表的最后心跳时间。'
                        : "队列 `{$q}` 有 {$stuckReserved} 个任务被 worker 领走后超过 {$retryAfter} 秒还没跑完，"
                            .'且没有任何 worker 心跳覆盖这个队列。这些任务不会执行，也不会报错。请检查 compose.yaml 里 worker 的 --queue= 列表。',
                ];
            }
        }

        return [
            'workers' => $workers,
            'queues' => $queues,
            'alerts' => $alerts,
            'stale_seconds' => $staleSeconds,
        ];
    }
}
