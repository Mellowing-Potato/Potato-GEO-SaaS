<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Yanji\Portable\Instance;
use Yanji\Portable\Supervisor;

require_once __DIR__.'/../../portable/src/Instance.php';
require_once __DIR__.'/../../portable/src/JobClock.php';
require_once __DIR__.'/../../portable/src/Supervisor.php';

class PortableControlSocketTest extends TestCase
{
    public function test_another_instance_cannot_stop_a_supervisor_through_a_wrong_socket(): void
    {
        $root = sys_get_temp_dir().'/yanji-control-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $second = new Instance($root.'/second');
        $first = new Instance($root.'/first');
        $second->prepare();
        $first->prepare();
        $release = $root.'/release';
        mkdir($release.'/app/public', 0700, true);
        mkdir($release.'/runtime/bin', 0700, true);
        symlink(PHP_BINARY, $release.'/runtime/bin/php');
        file_put_contents($release.'/runtime/bin/frankenphp', "#!/bin/sh\nexec sleep 20\n");
        chmod($release.'/runtime/bin/frankenphp', 0700);
        file_put_contents($release.'/app/artisan', '<?php sleep(20);');
        $second->activate($release);
        Instance::atomicFile($second->root.'/instance.json', '{"port":1}');
        $db = new \SQLite3($second->root.'/database/database.sqlite');
        $db->exec('CREATE TABLE worker_heartbeats(worker_id TEXT, status TEXT, meta TEXT, last_seen_at TEXT)');
        $db->close();
        $supervisor = new Supervisor($second);
        $pid = pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            umask(0077);
            $supervisor->serve();
            exit(0);
        }
        try {
            $until = microtime(true) + 5;
            do {
                $known = $supervisor->request('status');
                if ($known !== null) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $until);
            self::assertSame($pid, $known['manager_pid']);
            // An incorrectly addressed frame reaches the real server. It must check identity before acting.
            $client = stream_socket_client('unix://'.$second->socketPath());
            fwrite($client, json_encode(['action'=>'stop','instance'=>hash('sha256',$first->root)])."\n");
            $response = json_decode(fgets($client), true);
            fclose($client);
            self::assertSame('identity', $response['error']);
            self::assertSame($pid, $supervisor->request('status')['manager_pid']);
            // A second socket name pointing to the first is rejected without transmitting any frame.
            symlink($second->socketPath(), $first->socketPath());
            try {
                (new Supervisor($first))->stop();
                self::fail('Followed a control socket symlink');
            } catch (RuntimeException $e) {
                self::assertStringContainsString('管理套接字', $e->getMessage());
            }
            self::assertSame($pid, $supervisor->request('status')['manager_pid']);
        } finally {
            if (is_link($first->socketPath())) {
                unlink($first->socketPath());
            }
            posix_kill($pid, SIGTERM);
            pcntl_waitpid($pid, $result);
            (new Filesystem)->deleteDirectory($root);
        }
    }
}
