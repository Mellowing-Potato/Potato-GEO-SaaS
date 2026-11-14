<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * SQLite 并发压测（放量前收尾 T3 评估工具，自包含、不碰业务库）。
 *
 * 在临时 SQLite 文件上模拟"业务写 + 队列写 + 缓存写"同压一个文件的单写者争用，
 * 扫不同并发度，统计 SQLITE_BUSY（database is locked）出现次数与时延，
 * 对比 WAL + busy_timeout 的兜底效果，从而定位并发锁阈值。
 *
 * 用法：
 *   # 编排模式：扫并发度并对比两种配置
 *   php artisan geo:loadtest-sqlite --sweep --iterations=100
 *   # 单点：指定并发 + 是否开 WAL/busy
 *   php artisan geo:loadtest-sqlite --concurrency=16 --wal --busy=5000
 *   # 子进程（内部使用）：
 *   php artisan geo:loadtest-sqlite --worker --db=/tmp/x.sqlite --iterations=100 --busy=0
 */
class LoadTestSqlite extends Command
{
    protected $signature = 'geo:loadtest-sqlite
        {--concurrency=8 : 并发 worker 数}
        {--iterations=100 : 每个 worker 的事务数}
        {--wal : 启用 WAL}
        {--busy=0 : busy_timeout(ms)，0=立即报错}
        {--db= : 临时库路径（worker 用）}
        {--worker : 子进程模式}
        {--sweep : 扫描并发度 [1,4,8,16,32]}';

    protected $description = 'SQLite 单写者并发压测：定位 database is locked 阈值';

    public function handle(): int
    {
        if ($this->option('worker')) {
            return $this->runWorker();
        }

        $levels = $this->option('sweep')
            ? [1, 4, 8, 16, 32]
            : [(int) $this->option('concurrency')];

        $iterations = (int) $this->option('iterations');
        $wal = (bool) $this->option('wal');
        $busy = (int) $this->option('busy');

        $this->info("SQLite 并发压测  |  iterations/worker={$iterations}  |  WAL=".($wal ? 'ON' : 'OFF')."  busy_timeout={$busy}ms");
        $this->line('（注意：本机 SSD 极快，阈值仅供相对参考；生产前请在类生产机复核）');

        $rows = [];
        foreach ($levels as $c) {
            $rows[] = $this->runLevel($c, $iterations, $wal, $busy);
        }

        $this->table(
            ['concurrency', 'total_ops', 'SQLITE_BUSY', 'busy_rate', 'p50(ms)', 'p99(ms)'],
            $rows
        );

        return self::SUCCESS;
    }

    private function runLevel(int $concurrency, int $iterations, bool $wal, int $busy): array
    {
        $db = tempnam(sys_get_temp_dir(), 'geolt-').'.sqlite';
        $this->initDb($db, $wal);

        $procs = [];
        for ($i = 0; $i < $concurrency; $i++) {
            $args = [
                PHP_BINARY,
                base_path('artisan'),
                'geo:loadtest-sqlite',
                '--worker',
                '--db='.$db,
                '--iterations='.$iterations,
                '--busy='.$busy,
            ];
            if ($wal) {
                $args[] = '--wal';
            }
            $p = new Process($args);
            $p->setTimeout(300);
            $p->start();
            $procs[] = $p;
        }

        $totalBusy = 0;
        $latencies = [];
        $totalOps = 0;
        foreach ($procs as $p) {
            $p->wait();
            $line = trim($p->getOutput());
            if (! $line) {
                continue;
            }
            $res = json_decode($line, true);
            if (! $res) {
                continue;
            }
            $totalBusy += $res['busy'];
            $totalOps += $res['ops'];
            foreach ($res['latencies'] as $l) {
                $latencies[] = $l;
            }
        }

        @unlink($db);
        @unlink($db.'-wal');
        @unlink($db.'-shm');

        $rate = $totalOps > 0 ? round($totalBusy / $totalOps * 100, 2) : 0;
        sort($latencies);
        $p50 = $this->percentile($latencies, 50);
        $p99 = $this->percentile($latencies, 99);

        return [$concurrency, $totalOps, $totalBusy, $rate.'%', $p50, $p99];
    }

    private function initDb(string $db, bool $wal): void
    {
        $pdo = new \PDO('sqlite:'.$db);
        $pdo->exec('PRAGMA journal_mode='.($wal ? 'WAL' : 'DELETE'));
        $pdo->exec('CREATE TABLE business (id INTEGER PRIMARY KEY AUTOINCREMENT, val INTEGER)');
        $pdo->exec('CREATE TABLE queue_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, payload TEXT)');
        $pdo->exec('CREATE TABLE cache (k TEXT PRIMARY KEY, v TEXT)');
        $pdo->exec("INSERT INTO cache (k,v) VALUES ('hb','0')");
        $pdo = null;
    }

    private function runWorker(): int
    {
        $db = $this->option('db');
        $iterations = (int) $this->option('iterations');
        $busy = (int) $this->option('busy');
        $wal = (bool) $this->option('wal');

        $pdo = new \PDO('sqlite:'.$db);
        if ($wal) {
            $pdo->exec('PRAGMA journal_mode=WAL');
        }
        if ($busy > 0) {
            $pdo->exec("PRAGMA busy_timeout={$busy}");
        }

        $busyCount = 0;
        $ops = 0;
        $latencies = [];

        for ($i = 0; $i < $iterations; $i++) {
            $t0 = microtime(true);
            $ok = false;
            try {
                // BEGIN IMMEDIATE：事务开始即抢写锁，放大单写者争用以暴露阈值
                $pdo->exec('BEGIN IMMEDIATE');
                for ($b = 0; $b < 10; $b++) {
                    $pdo->exec('INSERT INTO business (val) VALUES ('.mt_rand(1, 1000000).')');
                }
                $pdo->exec("INSERT INTO queue_jobs (payload) VALUES ('x')");
                $pdo->exec("UPDATE cache SET v='".mt_rand(1, 1000000)."' WHERE k='hb'");
                $pdo->exec('COMMIT');
                $ok = true;
            } catch (\PDOException $e) {
                try {
                    $pdo->exec('ROLLBACK');
                } catch (\Throwable $ign) {
                    // 忽略
                }
                $code = $e->getCode();
                $msg = $e->getMessage();
                // SQLITE_BUSY = 5；部分驱动报 "database is locked"
                if ($code === 5 || stripos($msg, 'database is locked') !== false) {
                    $busyCount++;
                } else {
                    fwrite(STDERR, "worker err: {$msg}\n");
                }
            }
            $latencies[] = round((microtime(true) - $t0) * 1000, 3);
            if ($ok) {
                $ops++;
            }
        }

        fwrite(STDOUT, json_encode(['ops' => $ops, 'busy' => $busyCount, 'latencies' => $latencies])."\n");

        return self::SUCCESS;
    }

    private function percentile(array $sorted, int $p)
    {
        if (empty($sorted)) {
            return 0;
        }
        $idx = max(0, min(count($sorted) - 1, (int) ceil($p / 100 * count($sorted)) - 1));

        return $sorted[$idx];
    }
}
