<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * SQLite 定时备份（放量前收尾 T3 兜底）。
 *
 * 用 sqlite3 的 ".backup" 生成一致副本（会自动把 WAL 内容并入目标文件）。
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

    protected $description = '对 geo-saas 的 SQLite 库做一致快照备份（.backup）';

    public function handle(): int
    {
        $db = Config::get('database.connections.sqlite.database');
        if (! file_exists($db)) {
            $this->error("源库不存在：{$db}");

            return self::FAILURE;
        }

        $dir = dirname($this->option('to') ?: storage_path('app/backups/'.basename($db)));
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $dst = $this->option('to') ?: $dir.'/geo-saas-'.date('Ymd-His').'.sqlite';

        // 优先用 sqlite3 CLI 的 .backup（对 WAL 最稳）
        $cli = trim((string) shell_exec('command -v sqlite3'));
        if ($cli) {
            $cmd = sprintf('%s %s ".backup \'%s\'"', escapeshellarg($cli), escapeshellarg($db), escapeshellarg($dst));
            exec($cmd, $out, $code);
            if ($code !== 0 || ! file_exists($dst)) {
                $this->warn("sqlite3 .backup 失败，回退文件拷贝");
            }
        }
        if (! file_exists($dst)) {
            // 回退：先 checkpoint 再拷贝（含 -wal/-shm）
            try {
                $pdo = new \PDO('sqlite:'.$db);
                $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            } catch (\Throwable $e) {
                // 忽略
            }
            copy($db, $dst);
            foreach (['-wal', '-shm'] as $suf) {
                if (file_exists($db.$suf)) {
                    copy($db.$suf, $dst.$suf);
                }
            }
        }

        if (! file_exists($dst)) {
            $this->error("备份失败：{$dst}");

            return self::FAILURE;
        }

        $size = filesize($dst);
        $this->info("✓ 备份完成：{$dst}（".round($size / 1024, 1)." KB）");

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
