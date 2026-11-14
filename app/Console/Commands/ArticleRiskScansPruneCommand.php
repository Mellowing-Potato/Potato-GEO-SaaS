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
class ArticleRiskScansPruneCommand extends Command
{
    protected $signature = 'article-risk-scans:prune {--days=90} {--chunk=1000}';

    protected $description = '删除 90 天前的文章风险扫描记录，防止出站扫描落库表无限增长';

    public function handle(): int
    {
        // 与 sensitive-word-hits:prune 同一处修正：显式 0 不再被当成"没传"。
        $daysRaw = $this->option('days');
        $days = ($daysRaw === null || $daysRaw === '') ? 90 : (int) $daysRaw;
        $chunk = max(100, (int) $this->option('chunk'));

        if ($days < 1) {
            $this->error('--days 必须 >= 1，拒绝执行（防止一条命令清空整表）。');

            return 1;
        }

        $cutoff = now()->subDays($days);
        $deleted = 0;

        // 分批删：与 sensitive-word-hits:prune 同一理由，避免长持锁影响出站主链路。
        do {
            $batch = \App\Models\ArticleRiskScan::query()
                ->where('scanned_at', '<', $cutoff)
                ->limit($chunk)
                ->pluck('id');

            if ($batch->isEmpty()) {
                break;
            }

            $deleted += \App\Models\ArticleRiskScan::query()->whereIn('id', $batch)->delete();
        } while (true);

        $this->info("Pruned {$deleted} article risk scan(s) older than {$days} day(s) (cutoff {$cutoff->toDateTimeString()}).");

        return 0;
    }
}
