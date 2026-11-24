<?php

namespace App\Console\Commands;

use App\Services\Backup\SqliteSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * SQLite 定时备份（放量前收尾 T3 兜底）。
 *
 * 用 SQLite VACUUM INTO 生成一致副本（会自动把 WAL 内容并入目标文件）。
 * 目标目录默认 storage/app/backups/，文件名带时间戳。
 *
 * 建议由 geo-scheduler 容器按 cron 调用，例如每 6 小时一次（0/6/12/18 点）：
 *   0 0,6,12,18 * * * cd /var/www && php artisan geo:backup-sqlite >> storage/logs/backup.log 2>&1
 * （cron 接线属 ops/部署范畴，需经理拍板后由 ops 落到 compose/scheduler。）
 */
class BackupSqlite extends Command
{
    protected $signature = 'geo:backup-sqlite
        {--to= : 目标文件路径，默认 storage/app/backups/geo-saas-YYYYmmdd-HHMMSS.sqlite}
        {--keep= : 仅保留最近 N 个备份，超出则删最旧，默认 0（不自动清理）}';

    protected $description = '对 geo-saas 的 SQLite 库做一致快照备份';

    public function handle(): int
    {
        $db = Config::get('database.connections.sqlite.database');
        $dir = dirname($this->option('to') ?: storage_path('app/backups/'.basename($db)));
        $dst = $this->option('to') ?: $dir.'/geo-saas-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)).'.sqlite';
        try {
            if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
                throw new \RuntimeException('无法创建备份目录。');
            }
            $snapshot = SqliteSnapshot::create($db, $dst);
        } catch (\Throwable $error) {
            $this->error('备份未完成：'.$error->getMessage());

            return self::FAILURE;
        }
        $this->info("✓ 备份完成：{$dst}（".round($snapshot['bytes'] / 1024, 1)." KB）");

        if ($keep = (int) $this->option('keep')) {
            $this->prune($dir, $keep);
        }

        return self::SUCCESS;
    }

    private function prune(string $dir, int $keep): void
    {
        $files = glob($dir.'/geo-saas-*.sqlite');
        if (! $files || count($files) <= $keep) {
            return;
        }
        usort($files, fn ($a, $b) => filemtime($a) <=> filemtime($b));
        $old = array_slice($files, 0, count($files) - $keep);
        foreach ($old as $f) {
            @unlink($f);
            @unlink($f.'-wal');
            @unlink($f.'-shm');
            $this->line("  清理旧备份：{$f}");
        }
    }
}
