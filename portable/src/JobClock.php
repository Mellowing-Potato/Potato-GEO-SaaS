<?php

declare(strict_types=1);

namespace Yanji\Portable;

use RuntimeException;

require_once __DIR__.'/Platform.php';

final class JobClock
{
    private const GRACE_NS = 300_000_000;

    public static function record(string $run, ?array $job): void
    {
        self::locked($run, static function () use ($run, $job): void {
            $path = $run.'/current-job.json';
            if ($job === null) {
                if (is_file($path)) {
                    unlink($path);
                }
            } else {
                Instance::atomicFile($path, json_encode($job, JSON_THROW_ON_ERROR));
            }
        });
    }

    public static function enforce(string $run, int $pid, string $generation): void
    {
        self::locked($run, static function () use ($run, $pid, $generation): void {
            $path = $run.'/current-job.json';
            if (!is_file($path)) {
                return;
            }
            $job = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            if ($job['pid'] !== $pid || $job['generation'] !== $generation || $job['deadline'] + self::GRACE_NS > hrtime(true)) {
                return;
            }
            // The supervisor owns the unreaped child and the transition lock prevents a later job from starting.
            Instance::atomicFile($run.'/expired-job.json', json_encode($job, JSON_THROW_ON_ERROR));
            if (!Platform::killProcessTree($pid)) {
                throw new RuntimeException('无法终止到期的受监护作业。');
            }
        });
    }

    private static function locked(string $run, callable $action): void
    {
        $lock = fopen($run.'/job-clock.lock', Platform::lockMode());
        if (!$lock || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('无法保护当前作业状态。');
        }
        try {
            $action();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
