<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\ModelCallLog;
use App\Services\Ops\PrivacyPurgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 保留期限执行器（计划 §9.5；口径由用户 2026-09-21 拍板：「可以，做吧」）。
 *
 * 三件事各守一条：
 *  1. 内容类残留按**删除事件**计时（不是按行自己的 created_at）—— 三年前建、昨天删的项目不许被当场清掉；
 *  2. 磁盘产物只在"行已不在 + 目录超期"时删，窗口内的不碰；
 *  3. 钱相关表默认只统计：不给 --include-money 一行都不许少（对账/审计要用，删了不可再生）。
 */
class PrivacyPurgeCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 报表磁盘是**真目录**（RefreshDatabase 只回滚库，不回滚盘），不收拾干净会污染别的测试：
     * 实测过一次 —— 本测试留下的 `executions/9002` 被 PrivacyLifecycleAuditCommandTest
     * 数成"孤儿目录"，那边断言 1 个、实际 2 个，红在别人身上。
     */
    protected function tearDown(): void
    {
        $disk = Storage::disk('reports');
        foreach (['executions/9001', 'executions/9002'] as $directory) {
            if ($disk->exists($directory)) {
                $disk->deleteDirectory($directory);
            }
        }

        parent::tearDown();
    }

    public function test_it_clears_content_rows_only_after_the_deletion_window(): void
    {
        // 一个"三年前建、昨天刚删"的项目：行龄很老，但删除事件在窗口内 → 绝不能被清
        $recentPurge = $this->deletedProjectFixture(daysAgo: 1);
        // 一个"很久以前就删了"的项目：过了内容保留期 → 该清
        $oldPurge = $this->deletedProjectFixture(daysAgo: 120);

        $this->artisan('geo:privacy-purge --json')
            ->expectsOutputToContain('"dry_run":true')
            ->assertSuccessful();

        // 演练一行不动
        $this->assertSame(1, DB::table('analytics_visit_logs')->where('project_id', $recentPurge)->count());
        $this->assertSame(1, DB::table('analytics_visit_logs')->where('project_id', $oldPurge)->count());

        $this->artisan('geo:privacy-purge --apply --json')->assertSuccessful();

        $this->assertSame(1, DB::table('analytics_visit_logs')->where('project_id', $recentPurge)->count(), '窗口内的项目内容被清掉了 —— 客户删完还想找回的那段窗口没了');
        $this->assertSame(0, DB::table('analytics_visit_logs')->where('project_id', $oldPurge)->count(), '过了保留期的内容行没有清掉');
    }

    public function test_it_deletes_orphan_report_directories_only_after_the_window(): void
    {
        $disk = Storage::disk('reports');
        $disk->put('executions/9001/report.md', '# 孤儿报告：行已不在且超期');
        $disk->put('executions/9002/report.md', '# 孤儿报告：行刚没，还在窗口内');

        // 9001 的目录时间拨到 120 天前（行不存在 → 孤儿）；9002 保持"刚产生"
        $this->backdateDirectory('reports', 'executions/9001', 120);

        $report = app(PrivacyPurgeService::class)->run(apply: false);
        $this->assertSame(1, $report['sections']['disk_artifacts']['count']);
        $this->assertSame('executions/9001', $report['sections']['disk_artifacts']['items'][0]['path']);

        app(PrivacyPurgeService::class)->run(apply: true);

        $this->assertFalse($disk->exists('executions/9001/report.md'), '超期的孤儿目录没删');
        $this->assertTrue($disk->exists('executions/9002/report.md'), '窗口内的目录被删了 —— 那时客户还没过期');
    }

    public function test_money_tables_are_untouched_without_the_explicit_flag(): void
    {
        $old = $this->moneyLogFixture(daysAgo: 800);   // 超过 24 个月
        $recent = $this->moneyLogFixture(daysAgo: 30);

        // 第一道闸：默认（不加 --include-money）即使 --apply 也不动钱相关表
        $this->artisan('geo:privacy-purge --apply --json')
            ->expectsOutputToContain('"include_money":false')
            ->assertSuccessful();
        $this->assertNotNull(ModelCallLog::withoutGlobalScopes()->find($old), '默认清理动了钱相关表');

        // 第二道闸：给了 --include-money 才清，而且只清超过保留月的
        $report = app(PrivacyPurgeService::class)->run(apply: true, includeMoney: true);
        $moneyRows = collect($report['sections']['money_rows']['items'])->firstWhere('table', 'model_call_logs');
        $this->assertNotNull($moneyRows, '钱相关表没有被统计到');
        $this->assertNull(ModelCallLog::withoutGlobalScopes()->find($old), '超过 24 个月的流水没有清掉');
        $this->assertNotNull(ModelCallLog::withoutGlobalScopes()->find($recent), '24 个月以内的流水被清掉了 —— 那是不能碰的');

        // 清理本身也留痕
        $this->assertTrue(
            AdminActivityLog::query()->where('action', 'privacy.purge_run')->exists(),
            '清理动作没有写审计',
        );
    }

    /** 造一个"已真删的项目"：项目行已不在，残留一行 tasks，并把删除事件按 daysAgo 写进审计。 */
    private function deletedProjectFixture(int $daysAgo): int
    {
        $projectId = random_int(900_000, 999_999);

        DB::table('admin_activity_logs')->insert([
            'action' => 'project.purged',
            'request_method' => 'GET',
            'actor_type' => 'system',
            'actor_name' => 'geo:projects-purge',
            'target_type' => 'project',
            'target_id' => $projectId,
            'details' => json_encode(['name' => '测试项目'], JSON_UNESCAPED_UNICODE),
            'created_at' => now()->subDays($daysAgo),
        ]);

        // 用 analytics_visit_logs 当残留载体：它的 project_id 是**没有外键**的裸 bigint
        // （生产库里也确实存在这种孤儿行）—— 这正是这条清理要处理的那一类；
        // 而 tasks/articles 这些有 cascade 外键的表在测试库（开了 FK）里造不出孤儿来。
        DB::table('analytics_visit_logs')->insert([
            'tenant_id' => $this->anyTenantId(),
            'project_id' => $projectId,
            'path' => '/articles/old',
            'created_at' => now()->subYears(3),
        ]);

        return $projectId;
    }

    private function moneyLogFixture(int $daysAgo): int
    {
        // model_call_logs.project_id 是 NOT NULL（外键在 2026-09-04 的迁移里被拆掉，
        // 但列本身还在），所以这里要有一行真项目；用 DB 直插，避免动到 Eloquent 的租户作用域。
        $tenantId = $this->anyTenantId();
        $ownerId = $this->anyUserId($tenantId);
        $projectId = (int) DB::table('projects')->insertGetId([
            'tenant_id' => $tenantId,
            'owner_id' => $ownerId,
            'name' => '流水夹具项目',
            'current_stage' => 0,
            'progress' => 0,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = ModelCallLog::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'user_id' => $ownerId,
            'provider' => 'dashscope',
            'model' => 'vision',
            'status' => 'completed',
            'prompt_tokens' => 1,
            'completion_tokens' => 1,
            'total_tokens' => 2,
            'duration_seconds' => 0.1,
            'metadata' => ['points_deducted' => 5],
        ]);

        DB::table('model_call_logs')->where('id', $row->id)->update(['created_at' => now()->subDays($daysAgo)]);

        return (int) $row->id;
    }

    private function anyTenantId(): int
    {
        $existing = DB::table('tenants')->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('tenants')->insertGetId([
            'subdomain' => 'purge-fixture',
            'name' => 'PURGE',
            'plan' => 'basic',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function anyUserId(int $tenantId): int
    {
        $existing = DB::table('users')->where('tenant_id', $tenantId)->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('users')->insertGetId([
            'tenant_id' => $tenantId,

            'name' => '流水夹具用户',
            'role' => 'admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** 把某个磁盘目录的 mtime 拨到 N 天前（判定按目录时间，不按文件内容）。 */
    private function backdateDirectory(string $diskName, string $directory, int $daysAgo): void
    {
        $path = Storage::disk($diskName)->path($directory);
        $target = now()->subDays($daysAgo)->timestamp;
        touch($path, $target);
        // 目录本身也要拨：Storage::lastModified 读的是目录 mtime
        if (function_exists('chmod')) {
            @chmod($path, 0775);
        }
        @touch(dirname($path), $target);
        $this->assertTrue(Schema::hasTable('tasks'), 'tasks 表应当存在（测试自身的前置检查）');
    }
}
