<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Yanji\Portable\JobClock;

require_once __DIR__.'/../../portable/src/Instance.php';
require_once __DIR__.'/../../portable/src/JobClock.php';

class PortableJobClockTest extends TestCase
{
    public function test_only_the_owned_worker_generation_is_stopped_after_a_job_deadline(): void
    {
        $run = sys_get_temp_dir().'/yanji-clock-'.bin2hex(random_bytes(8));
        mkdir($run, 0700);
        $child = pcntl_fork();
        self::assertGreaterThanOrEqual(0, $child);
        if ($child === 0) {
            posix_setpgid(0, 0);
            sleep(10);
            exit(0);
        }
        try {
            posix_setpgid($child, $child);
            self::assertSame($child, posix_getpgid($child));
            $state = ['pid'=>$child,'generation'=>'current','deadline'=>hrtime(true)-1_000_000_000];
            JobClock::record($run, $state);
            JobClock::enforce($run, $child, 'previous');
            self::assertTrue(posix_kill($child, 0));
            self::assertFileDoesNotExist($run.'/expired-job.json');
            JobClock::enforce($run, $child, 'current');
            pcntl_waitpid($child, $status);
            $child = 0;
            self::assertTrue(pcntl_wifsignaled($status));
            self::assertSame(SIGKILL, pcntl_wtermsig($status));
            self::assertSame($state, json_decode(file_get_contents($run.'/expired-job.json'), true));
        } finally {
            if ($child > 0) {
                posix_kill($child, SIGTERM);
                pcntl_waitpid($child, $status);
            }
            (new Filesystem)->deleteDirectory($run);
        }
    }
}
