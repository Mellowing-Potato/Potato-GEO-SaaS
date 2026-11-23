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
        if ($old === $candidate) {
            $services->start();
            return;
        }
        $port = $i->port();
        $online = $services->request('status') !== null;
        $setup = new Installation($i);
        $hold = $i->root.'/run/upgrade-hold';
        if (file_exists($hold)) {
            throw new RuntimeException('发现未完成的升级。请先检查原配套备份与实例状态。');
        }
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
        Instance::atomicFile($hold, "blocked\n");
        try {
            $setup->configure($candidate, $port);
            foreach ([['migrate', '--force'], ['yanji:ready'], ['yanji:worker','--check'], ['schedule:list']] as $args) {
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
            // Both web traffic and business jobs are gated. Keep the failed tree for inspection.
            $staging = $recovery.'/restore';
            Backup::extractToEmpty($backup, $staging);
            $failed = $recovery.'/candidate';
            Instance::privateDir($failed);
            foreach (['.env','database','storage','bootstrap-cache'] as $name) {
                if (file_exists($i->root.'/'.$name) && !rename($i->root.'/'.$name, $failed.'/'.$name)) {
                    throw new RuntimeException('回滚文件无法移动，保留维护门和原备份 '.$backup);
                }
                if (file_exists($staging.'/'.$name) && !rename($staging.'/'.$name, $i->root.'/'.$name)) {
                    throw new RuntimeException('回滚文件无法恢复，保留维护门和原备份 '.$backup);
                }
            }
            $setup->configure($old, $port);
            if ($i->artisan(['yanji:ready'], quiet:true) !== 0) {
                throw new RuntimeException('恢复后就绪检查失败，保留维护门和原备份 '.$backup);
            }
            unlink($hold);
            if ($online) {
                $services->start();
            }
            throw new RuntimeException('升级失败，旧版本及匹配数据已恢复。备份 '.$backup, previous: $error);
        }
        echo "升级完成，恢复备份 ".$backup."\n";
    }
}
