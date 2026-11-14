<?php

namespace App\Console\Commands;

use App\Services\Backup\DatabaseBackupService;
use Illuminate\Console\Command;

/**
 * 数据库备份（计划 §9.2）。
 *
 * 与 geo:backup-sqlite 的关系：那个命令是 SQLite 专用兜底，这个是**驱动无关**的入口
 * （生产是 PostgreSQL，本机开发是 SQLite，两边都可以用同一条命令 + 同一个恢复演练）。
 * 用户口径（2026-09-21）：备份存本机，本机足够大 —— 因此默认目的地是 storage/app/backups。
 */
class GeoBackupDatabaseCommand extends Command
{
    protected $signature = 'geo:backup-database
        {--to= : 目标文件路径，默认 storage/app/backups/geo-saas-<driver>-<时间戳>}
        {--keep= : 仅保留最近 N 个备份（超出删最旧），默认 0 = 不清理}
        {--json : 机读输出}';

    protected $description = '备份当前数据库（SQLite 一致副本 / PostgreSQL pg_dump -Fc），并写 sha256 校验和';

    public function handle(DatabaseBackupService $service): int
    {
        try {
            $result = $service->backup($this->option('to') ?: null, (int) ($this->option('keep') ?: 0));
        } catch (\Throwable $e) {
            if ($this->option('json')) {
                $this->line(json_encode(['object' => 'geo_database_backup', 'ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE));
            } else {
                $this->error('备份失败：'.$e->getMessage());
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode(['object' => 'geo_database_backup', 'ok' => true] + $result, JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '✓ 备份完成（%s）：%s（%.1f KB，sha256 %s…）',
            $result['driver'],
            $result['path'],
            $result['bytes'] / 1024,
            substr($result['sha256'], 0, 12),
        ));
        foreach ($result['pruned'] as $old) {
            $this->line('  清理旧备份：'.$old);
        }
        $this->line('  校验和：'.$result['path'].'.sha256');
        $this->line('  恢复演练：php artisan geo:restore-drill');

        return self::SUCCESS;
    }
}
