<?php

namespace Tests\Feature;

use App\Listeners\RecordWorkerHeartbeat;
use App\Models\WorkerHeartbeat;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Schema;
use ReflectionProperty;
use Tests\TestCase;

/**
 * 单 9 · 心跳监听器与 stale 判定（执行书 §六 W6 / W7 / W1·W2）。
 *
 * 每条关键断言都做变异验证：改坏源码 → 对应测试必须变红 → 改回 → 确认无残留。
 * 变异步骤在交付报告里逐条记录。
 */
class WorkerHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 重置监听器进程内节流计时（private static），保证每个测试的首发都真正写库。
        $ref = new ReflectionProperty(RecordWorkerHeartbeat::class, 'lastBeatAt');
        $ref->setAccessible(true);
        $ref->setValue(null);

        config(['geo.worker_heartbeat.enabled' => true]);
    }

    /**
     * 🔴 红线 1（W6）：监听器 : void，不返回 false。
     * Queue::looping 是可中断派发——返回 false 会让整台 worker 永久停摆。
     */
    public function test_looping_listener_never_returns_false(): void
    {
        $result = $this->app['events']->until(new Looping('database', 'default', new WorkerOptions()));

        // 监听器 void → until 返回 null（不是 false）
        $this->assertNull($result, '监听器应返回 null（void），而非 false');
        $this->assertNotSame(false, $result, '🔴 红线 1 失守：监听器返回了 false，会让 worker 停摆');

        // 副作用：心跳应已写入，status=running
        $this->assertDatabaseHas('worker_heartbeats', [
            'worker_id' => (string) (env('WORKER_ID') ?: gethostname()),
            'status' => 'running',
        ]);
    }

    /**
     * 🔴 红线 2（W7）：心跳写库失败时异常必须静默吞掉，不能冒泡（否则 worker 崩、队列停摆）。
     */
    public function test_write_failure_is_silently_swallowed(): void
    {
        // 强制写库失败：临时移除表（事务回滚后会恢复，不影响后续测试）
        Schema::drop('worker_heartbeats');
        // 重置节流，确保本次确实尝试写库
        $ref = new ReflectionProperty(RecordWorkerHeartbeat::class, 'lastBeatAt');
        $ref->setAccessible(true);
        $ref->setValue(null);

        $threw = null;
        try {
            event(new Looping('database', 'default', new WorkerOptions()));
        } catch (\Throwable $e) {
            $threw = $e;
        }

        $this->assertNull($threw, '🔴 红线 2 失守：心跳写库失败时异常冒泡了: '.($threw ? $threw->getMessage() : ''));
    }

    /**
     * W2 · WorkerStopping 把 status 写成 stopping（--max-time 正常回收显示「回收中」）。
     */
    public function test_worker_stopping_marks_status_stopping(): void
    {
        // 监听器按 env('WORKER_ID') ?: gethostname() 算 worker_id，这里用同一表达式造行，保证对上。
        $workerId = (string) (env('WORKER_ID') ?: gethostname());
        WorkerHeartbeat::query()->create([
            'worker_id' => $workerId,
            'status' => 'running',
            'last_seen_at' => now(),
            'meta' => ['queues' => ['default'], 'connection' => 'database'],
        ]);

        event(new WorkerStopping(0));

        $this->assertDatabaseHas('worker_heartbeats', [
            'worker_id' => $workerId,
            'status' => 'stopping',
        ]);
    }

    /**
     * W1 / W2 · stale 判定（执行书 §六 第 4 条，用 Carbon::setTestNow 拨时间，不用 sleep）。
     * 119 秒前的心跳算活着；121 秒前算 stale（阈值默认 120，下限 30）。
     */
    public function test_stale_judgement_boundary(): void
    {
        Carbon::setTestNow(now());

        WorkerHeartbeat::query()->create([
            'worker_id' => 'alive-worker',
            'status' => 'running',
            'last_seen_at' => now()->subSeconds(119),
            'meta' => ['queues' => ['default']],
        ]);
        WorkerHeartbeat::query()->create([
            'worker_id' => 'stale-worker',
            'status' => 'running',
            'last_seen_at' => now()->subSeconds(121),
            'meta' => ['queues' => ['default']],
        ]);

        $service = app(\App\Services\Ops\WorkerHealthService::class);
        $snapshot = $service->snapshot();

        $byId = collect($snapshot['workers'])->keyBy('worker_id');
        $this->assertFalse($byId['alive-worker']['stale'], '119 秒前心跳应判为存活');
        $this->assertTrue($byId['stale-worker']['stale'], '121 秒前心跳应判为 stale');

        Carbon::setTestNow();
    }
}
