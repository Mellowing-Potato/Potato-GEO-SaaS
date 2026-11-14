<?php

namespace App\Console\Commands;

use App\Services\Backup\DatabaseBackupService;
use Illuminate\Console\Command;

/**
 * 备份恢复演练（计划 §9.2）。
 *
 * 为什么单独一条命令：**没恢复过的备份等于没有备份**。§9.2 原本要的是
 * "PostgreSQL 独立备份、异机备份和空白机恢复演练" —— 用户 2026-09-21 定的是本机目的地，
 * 所以"空白机"落地成"本机的一次性 scratch 库/临时副本"：真的把备份恢复起来、真的数行数、
 * 真的核对校验和，然后自己清干净，绝不碰生产库与备份本体。
 */
class GeoRestoreDrillCommand extends Command
{
    protected $signature = 'geo:restore-drill
        {--from= : 指定备份文件，默认取 storage/app/backups 下最新的一份}
        {--json : 机读输出}';

    protected $description = '恢复演练：把最新备份真的恢复起来，核对完整性/校验和/关键表行数';

    public function handle(DatabaseBackupService $service): int
    {
        $result = $service->drill($this->option('from') ?: null);

        if ($this->option('json')) {
            $this->line(json_encode(['object' => 'geo_restore_drill', 'pass' => $result['ok']] + $result, JSON_UNESCAPED_UNICODE));

            return $result['ok'] ? self::SUCCESS : self::FAILURE;
        }

        $this->line('恢复演练（只读备份；pgsql 用一次性 scratch 库，演练完删掉）');
        $this->line('  备份文件：'.$result['source']);
        $this->line('  驱动：'.$result['driver']);
        foreach ($result['checks'] as $name => $value) {
            $this->line('  检查 ' . $name . '：' . (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value));
        }
        if ($result['tables'] !== []) {
            $this->line('  关键表行数：');
            foreach ($result['tables'] as $table => $count) {
                $this->line(sprintf('    %-12s %d', $table, $count));
            }
        }
        foreach ($result['problems'] as $problem) {
            $this->error('  ✗ '.$problem);
        }
        $this->line($result['ok'] ? '✓ 演练通过：这份备份真的能恢复' : '✗ 演练失败：这份备份现在不能用来恢复');
        $this->line('本命令只读备份：没有覆盖生产库、没有改动备份文件本身。');

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
