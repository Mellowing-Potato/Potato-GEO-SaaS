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
class SensitiveWordHitsPruneCommand extends Command
{
    protected $signature = 'sensitive-word-hits:prune {--days=} {--chunk=1000}';

    protected $description = '删除 90 天前的敏感词命中日志，防止这张高频写入表无限增长';

    public function handle(): int
    {
        // 2026-09-21 修：`?:` 会把显式的 `--days=0` 当成"没传"而回落默认值，
        // 于是下面那条"必须 >= 1"的闸门对 0 永远不触发（只有负数才拦得住）。
        // 运维敲 `--days=0` 要么是想清空、要么是打错了 —— 两种都该被拒绝。
        // 证据：tests/Feature/OperationalCommandsCoverageTest.php 的 prune 用例。
        $daysRaw = $this->option('days');
        $days = ($daysRaw === null || $daysRaw === '')
            ? \App\Models\SensitiveWordHit::RETENTION_DAYS
            : (int) $daysRaw;
        $chunk = max(100, (int) $this->option('chunk'));

        if ($days < 1) {
            $this->error('--days 必须 >= 1，拒绝执行（防止一条命令清空整表）。');

            return 1;
        }

        $cutoff = now()->subDays($days);
        $deleted = 0;

        // 分批删：一次性 DELETE 上百万行会长时间持锁，把文章保存那条路一起卡住。
        do {
            $batch = \App\Models\SensitiveWordHit::query()
                ->where('created_at', '<', $cutoff)
                ->limit($chunk)
                ->pluck('id');

            if ($batch->isEmpty()) {
                break;
            }

            $deleted += \App\Models\SensitiveWordHit::query()->whereIn('id', $batch)->delete();
        } while (true);

        $this->info("Pruned {$deleted} sensitive word hit(s) older than {$days} day(s) (cutoff {$cutoff->toDateTimeString()}).");

        return 0;
    }
}
