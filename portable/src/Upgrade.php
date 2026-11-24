<?php

declare(strict_types=1);

namespace Yanji\Portable;

use RuntimeException;
use Throwable;

final class Upgrade
{
    public static function run(Instance $i, string $candidate, Supervisor $services): void
    {
        $old = $i->release();
        $port = $i->port();
        $hold = $i->root.'/run/upgrade-hold';
        if (file_exists($hold)) {
            $record = json_decode(file_get_contents($hold), true, flags: JSON_THROW_ON_ERROR);
            if (($record['instance'] ?? null) !== $i->root || !is_string($record['backup'] ?? null)
                || !is_string($record['old'] ?? null)) {
                throw new RuntimeException('升级记录无效，保留维护门；请核对实例外的完整备份。');
            }
            self::rollback($i, $services, $record['old'], $record['backup'], $port, $record['online']);
            throw new RuntimeException('已恢复上次中断升级前的程序与数据。请核对后重新升级。');
        }
        if ($old === $candidate) {
            $services->start();
            return;
        }
        if ($i->artisan(['yanji:ready','--portable'], quiet:true) !== 0) {
            throw new RuntimeException('升级前的原密钥与数据库未通过验证，请先核对配套备份。');
        }
        $online = $services->request('status') !== null;
        $setup = new Installation($i);
        $services->stop();
        $recovery = dirname($i->root).'/.yanji-recovery-'.bin2hex(random_bytes(12));
        Instance::privateDir($recovery);
        $backup = $recovery.'/instance.zip';
        try {
            Backup::create($i, $backup);
        } catch (Throwable $error) {
            if ($online) {
                $services->start();
            }
            throw $error;
        }
        Instance::atomicFile($hold, json_encode(['instance'=>$i->root,'old'=>$old,'backup'=>$backup,'online'=>$online], JSON_THROW_ON_ERROR));
        try {
            $setup->configure($candidate, $port);
            foreach ([['migrate', '--force'], ['yanji:ready','--portable'], ['yanji:worker','--check'], ['schedule:list']] as $args) {
                if ($i->artisan($args, quiet: true) !== 0) {
                    throw new RuntimeException('候选版本迁移或就绪检查失败。');
                }
            }
            $services->start();
            if (!unlink($hold)) {
                throw new RuntimeException('服务未开放写入。');
            }
        } catch (Throwable $error) {
            $services->stop();
            if (!is_file($hold)) {
                throw new RuntimeException('新版本已可能写入，请核对备份和当前实例后人工恢复。备份 '.$backup, previous: $error);
            }
            self::rollback($i, $services, $old, $backup, $port, $online);
            throw new RuntimeException('升级失败，旧版本及匹配数据已恢复。备份 '.$backup, previous: $error);
        }
        echo "升级完成，恢复备份 ".$backup."\n";
    }

    private static function rollback(Instance $i, Supervisor $services, string $old, string $backup, int $port, bool $online): void
    {
        $services->stop();
        // Both web traffic and business jobs are gated. Keep the failed tree for inspection.
        $work = dirname($backup).'/rollback-'.bin2hex(random_bytes(8));
        Instance::privateDir($work);
        $staging = $work.'/restore';
        Backup::extractToEmpty($backup, $staging);
        $failed = $work.'/candidate';
        Instance::privateDir($failed);
        foreach (['.env','database','storage','bootstrap-cache'] as $name) {
            if (file_exists($i->root.'/'.$name) && !rename($i->root.'/'.$name, $failed.'/'.$name)) {
                throw new RuntimeException('回滚文件无法移动，保留维护门和原备份 '.$backup);
            }
            if (file_exists($staging.'/'.$name) && !rename($staging.'/'.$name, $i->root.'/'.$name)) {
                throw new RuntimeException('回滚文件无法恢复，保留维护门和原备份 '.$backup);
            }
        }
        $setup = new Installation($i);
        $setup->configure($old, $port);
        $setup->protectInstancePaths();
        if ($i->artisan(['yanji:ready','--portable'], quiet:true) !== 0) {
            throw new RuntimeException('恢复后就绪检查失败，保留维护门和原备份 '.$backup);
        }
        unlink($i->root.'/run/upgrade-hold');
        if ($online) {
            $services->start();
        }
    }
}
