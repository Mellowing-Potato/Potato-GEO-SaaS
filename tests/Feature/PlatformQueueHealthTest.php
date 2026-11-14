<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkerHeartbeat;
use App\Services\Ops\WorkerHealthService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 单 9 · 队列健康端点与判定服务（执行书 §六 W3 / W4 / W5 / W8 / W9）。
 *
 * W3/W4/W5 直接驱动 WorkerHealthService（绕过 HTTP，纯逻辑断言）。
 * W8/W9 走真实端点 /api/v1/platform/queue-health（鉴权 + 面板开关门控）。
 * 每条关键断言都对应变异验证，记录在交付报告。
 */
class PlatformQueueHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.tenant_domain' => 'geo.example.test']);
        config(['geo.queue_health_panel_enabled' => true]);
        config(['geo.worker_heartbeat.enabled' => true]);
        // phpunit.xml 把 QUEUE_CONNECTION 强制成 sync；这里改回 database，
        // 好让「卡住阈值读 queue.connections.database.retry_after」这条生产路径在测试里真的被走到。
        config(['queue.default' => 'database']);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();

        parent::tearDown();
    }

    private function platformAdmin(): User
    {
        return User::factory()->create([
            'tenant_id' => null,
            'role' => 'platform_admin',
        ]);
    }

    private function insertJob(string $queue, ?int $createdAt = null): void
    {
        DB::table('jobs')->insert([
            'queue' => $queue,
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => $createdAt ?? now()->timestamp,
        ]);
    }

    /** 造一行「已被 worker 领走」的任务（reserved_at 落值 → 不算 pending）。 */
    private function insertReservedJob(string $queue, int $reservedSecondsAgo): void
    {
        DB::table('jobs')->insert([
            'queue' => $queue,
            'payload' => '{}',
            'attempts' => 1,
            'reserved_at' => now()->timestamp - $reservedSecondsAgo,
            'available_at' => now()->timestamp - $reservedSecondsAgo,
            'created_at' => now()->timestamp - $reservedSecondsAgo,
        ]);
    }

    /**
     * 🔴 W3 · 无消费者检测（本单最重要的一条）。
     * 两个心跳分别消费 visibility / publishing；三个队列都有任务：
     * 只有 nobody 没人消费 → 必须报警；visibility / publishing 有人消费 → 不报警。
     */
    public function test_no_consumer_detected(): void
    {
        WorkerHeartbeat::query()->create([
            'worker_id' => 'geo-visibility-worker',
            'status' => 'running',
            'last_seen_at' => now(),
            'meta' => ['queues' => ['visibility'], 'connection' => 'database'],
        ]);
        WorkerHeartbeat::query()->create([
            'worker_id' => 'geo-worker',
            'status' => 'running',
            'last_seen_at' => now(),
            'meta' => ['queues' => ['publishing'], 'connection' => 'database'],
        ]);

        $this->insertJob('visibility');
        $this->insertJob('publishing');
        $this->insertJob('nobody');

        $snapshot = app(WorkerHealthService::class)->snapshot();
        $alertQueues = collect($snapshot['alerts'])->pluck('queue')->all();

        $this->assertContains('nobody', $alertQueues, '无人消费的队列必须报警');
        $this->assertNotContains('visibility', $alertQueues, '有人消费的队列不应报警');
        $this->assertNotContains('publishing', $alertQueues, '有人消费的队列不应报警');
    }

    /**
     * W4 · 空的无人队列不报警（只有队列名、没有任务时不报）。
     *
     * ⚠️ 这条只是把意图写下来，它守不住 `$pending > 0` 那道闸：
     *    一行 jobs 都没有 → 服务里那层 foreach 根本不进（队列名是从 jobs 表 distinct 出来的），
     *    删掉 `$pending > 0` 它照样绿。真正守那道闸的是下面的
     *    test_recently_reserved_job_does_not_alert（2026-09-01 评审补）。
     */
    public function test_empty_unconsumed_queue_does_not_alert(): void
    {
        WorkerHeartbeat::query()->create([
            'worker_id' => 'geo-worker',
            'status' => 'running',
            'last_seen_at' => now(),
            'meta' => ['queues' => ['visibility'], 'connection' => 'database'],
        ]);
        // 没有任何 jobs 行

        $snapshot = app(WorkerHealthService::class)->snapshot();
        $this->assertEmpty($snapshot['alerts'], '空队列不应产生告警');
    }

    /**
     * W5 · 队列深度（pending / 近24h失败）与直接查库一致。
     */
    public function test_queue_depth_matches_db(): void
    {
        $this->insertJob('visibility');
        $this->insertJob('visibility');
        $this->insertJob('publishing');

        DB::table('failed_jobs')->insert([
            'uuid' => 'test-fail-'.uniqid(),
            'connection' => 'database',
            'queue' => 'visibility',
            'payload' => '{}',
            'exception' => 'boom',
            'failed_at' => now(),
        ]);

        $snapshot = app(WorkerHealthService::class)->snapshot();
        $queues = collect($snapshot['queues'])->keyBy('queue');

        $this->assertEquals(2, $queues['visibility']['pending']);
        $this->assertEquals(1, $queues['publishing']['pending']);
        $this->assertEquals(1, $queues['visibility']['failed_24h']);
        $this->assertNotNull($queues['visibility']['oldest_wait_seconds']);
        $this->assertSame(0, $queues['visibility']['stuck_reserved'], '没有被领走的任务时卡住数应为 0');
        $this->assertNull($queues['visibility']['oldest_reserved_seconds']);
        $this->assertGreaterThanOrEqual(0, $queues['visibility']['oldest_wait_seconds']);

        // 直接查库交叉核对
        $this->assertEquals(
            DB::table('jobs')->where('queue', 'visibility')->whereNull('reserved_at')->count(),
            $queues['visibility']['pending']
        );
    }

    /* ==================================================================
       2026-09-01 评审补：任务被 worker 领走后卡住的检测（老板拍板的行为变更）
       pending 的定义是 reserved_at IS NULL，所以 worker 领走任务后才死掉的话，
       这行任务 pending 算 0 —— 原来整块看板一声不吭，正好漏掉最该抓的场景。
       ================================================================== */

    /**
     * 🔴 刚被领走、还没到 retry_after 的任务不报警。
     * 这条同时守住 `$pending > 0` 那道闸：队列在 jobs 表里有行（foreach 会进），但 pending=0。
     * 删掉 `$pending > 0` → 本条必红。
     */
    public function test_recently_reserved_job_does_not_alert(): void
    {
        $this->insertReservedJob('nobody', 60); // 领走才 60 秒，retry_after 是 600

        $snapshot = app(WorkerHealthService::class)->snapshot();
        $row = collect($snapshot['queues'])->firstWhere('queue', 'nobody');

        $this->assertSame(0, $row['pending'], '被领走的任务不算 pending');
        $this->assertSame(0, $row['stuck_reserved'], '没超过 retry_after 不算卡住');
        $this->assertEmpty($snapshot['alerts'], '刚被领走的任务不应报警');
    }

    /**
     * 🔴 本次行为变更的主判据：任务被领走超过 retry_after、且没有任何心跳覆盖该队列 → critical。
     * 这就是 worker 进程死在任务里的样子：任务永远卡着，且不报错。
     */
    public function test_stuck_reserved_alerts_critical_when_no_heartbeat_covers_queue(): void
    {
        $this->insertReservedJob('ghost', 900); // 领走 900 秒 > retry_after 600

        $snapshot = app(WorkerHealthService::class)->snapshot();
        $alert = collect($snapshot['alerts'])->firstWhere('queue', 'ghost');

        $this->assertNotNull($alert, '被领走后卡住且无人消费必须报警');
        $this->assertSame('stuck_reserved', $alert['type']);
        $this->assertSame('critical', $alert['severity'], '压根没心跳覆盖 → critical');
        $this->assertSame(1, $alert['stuck_reserved']);
        $this->assertSame(0, collect($snapshot['queues'])->firstWhere('queue', 'ghost')['pending'],
            '证明这条告警不是靠 pending 报出来的');
    }

    /**
     * 心跳存在但已过期 → 只报 warning：可能是 worker 死了，也可能是它正忙着跑长任务
     * （worker 跑任务期间不发 Looping，心跳本来就会过期），话要说两头。
     */
    public function test_stuck_reserved_warns_when_heartbeat_is_stale(): void
    {
        WorkerHeartbeat::query()->create([
            'worker_id' => 'geo-visibility-worker',
            'status' => 'running',
            'last_seen_at' => now()->subSeconds(300), // stale 阈值 120
            'meta' => ['queues' => ['visibility'], 'connection' => 'database'],
        ]);
        $this->insertReservedJob('visibility', 900);

        $snapshot = app(WorkerHealthService::class)->snapshot();
        $alert = collect($snapshot['alerts'])->firstWhere('queue', 'visibility');

        $this->assertNotNull($alert);
        $this->assertSame('stuck_reserved', $alert['type']);
        $this->assertSame('warning', $alert['severity'], '心跳行存在（只是过期）→ warning，不是 critical');
    }

    /**
     * 🔴 有活着的 worker 就不报：数据库队列驱动会在 retry_after 之后把这些行重新派出去（能自愈）。
     * 删掉告警二里的 `! $hasConsumer` → 本条必红。
     */
    public function test_stuck_reserved_not_alerted_when_worker_alive(): void
    {
        WorkerHeartbeat::query()->create([
            'worker_id' => 'geo-visibility-worker',
            'status' => 'running',
            'last_seen_at' => now(),
            'meta' => ['queues' => ['visibility'], 'connection' => 'database'],
        ]);
        $this->insertReservedJob('visibility', 900);

        $snapshot = app(WorkerHealthService::class)->snapshot();

        $this->assertEmpty($snapshot['alerts'], '有活 worker 时队列驱动会自己重派，不该报警');
    }

    /**
     * 卡住阈值必须跟着 retry_after 配置走，不许写死 600。
     * 把 retry_after 调成 300，一个领走 400 秒的任务就该报（600 的话不报）。
     */
    public function test_stuck_threshold_follows_retry_after_config(): void
    {
        config(['queue.connections.database.retry_after' => 300]);
        $this->insertReservedJob('ghost', 400);

        $snapshot = app(WorkerHealthService::class)->snapshot();
        $alert = collect($snapshot['alerts'])->firstWhere('queue', 'ghost');

        $this->assertNotNull($alert, '400 秒 > retry_after 300，应报警');
        $this->assertSame(300, $alert['retry_after_seconds'], '阈值应取自配置');
    }

    /**
     * W8 · 面板开关：关 → 404；开 → 200。
     */
    public function test_panel_disabled_returns_404(): void
    {
        config(['geo.queue_health_panel_enabled' => false]);
        Sanctum::actingAs($this->platformAdmin(), ['*']);

        $this->getJson('/api/v1/platform/queue-health')->assertNotFound();
    }

    public function test_panel_enabled_returns_200(): void
    {
        config(['geo.queue_health_panel_enabled' => true]);
        Sanctum::actingAs($this->platformAdmin(), ['*']);

        $this->getJson('/api/v1/platform/queue-health')->assertOk();
    }

    /**
     * W9 · 非平台管理员 → 403。
     */
    public function test_non_admin_forbidden(): void
    {
        $member = User::factory()->create(['role' => 'member']);
        Sanctum::actingAs($member, ['*']);

        $this->getJson('/api/v1/platform/queue-health')
            ->assertForbidden()
            ->assertJsonPath('required_permission', 'platform:operations');
    }
}
