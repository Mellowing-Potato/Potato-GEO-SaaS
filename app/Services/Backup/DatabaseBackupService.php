<?php

namespace App\Services\Backup;

use Closure;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Process\Process;

/**
 * 数据库备份与"恢复演练"（计划 §9.2，2026-09-21）。
 *
 * 背景：这一条原本标着"需要外部目的地"（异机备份）。2026-09-21 用户口径明确：
 * **备份存本机，本机足够大** —— 所以这里实现的是"本机独立备份 + 本机恢复演练"，
 * 异机/对象存储仍然留着（README 里写了怎么接），但不再是阻塞项。
 *
 * 两个动作：
 *   backup()  —— 产出一致副本 + sha256 校验和（校验和是给恢复演练与将来的异地传输用的）；
 *   drill()   —— **真的把备份恢复起来**再核对行数，而不是只看"文件存在"。
 *                这条是 §9.2 的关键：没演练过的备份等于没有备份。
 *
 * 驱动差异：
 *   sqlite —— 用 SQLite VACUUM INTO 做一致副本，演练 = 打开副本跑 integrity_check + 行数；
 *   pgsql  —— pg_dump -Fc 产出 custom 格式；演练 = 建一个临时库 → pg_restore 进去 → 数行数 → 删库。
 *
 * 为什么把"外部命令执行器"做成可注入：pg_dump / pg_restore / createdb 在本机（开发环境）没有，
 * 但这一整条逻辑必须能被测试 —— 测试里注入一个假的执行器记录命令序列，就能把参数与顺序钉住。
 */
class DatabaseBackupService
{
    /** 演练时要核对行数的关键表（钱、内容、账号各一张，能反映"库真的是那个库"）。 */
    public const DRILL_TABLES = ['tenants', 'users', 'projects', 'articles'];

    private Closure $runner;

    public function __construct(?Closure $runner = null)
    {
        $this->runner = $runner ?? function (array $command): array {
            $process = new Process($command, null, null, null, 600);
            $process->run();

            return [
                'exit' => $process->getExitCode(),
                'output' => trim($process->getOutput()."\n".$process->getErrorOutput()),
            ];
        };
    }

    public function driver(): string
    {
        return (string) Config::get('database.default', 'sqlite');
    }

    /** 默认备份目录（用户口径：本机；`geo.backup.dir` 可覆盖）。 */
    public function directory(): string
    {
        return (string) (Config::get('geo.backup.dir') ?: storage_path('app/backups'));
    }

    /**
     * 做一次备份。
     *
     * @return array{path: string, driver: string, bytes: int, sha256: string, pruned: list<string>}
     */
    public function backup(?string $to = null, int $keep = 0): array
    {
        $dir = $this->directory();
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new \RuntimeException("备份目录不可用：{$dir}");
        }

        $driver = $this->driver();
        $to ??= $dir.'/geo-saas-'.$driver.'-'.date('Ymd-His').($driver === 'pgsql' ? '.dump' : '-'.bin2hex(random_bytes(3)).'.sqlite');

        if ($driver === 'pgsql') {
            $this->backupPostgres($to);
            if (! is_file($to) || filesize($to) === 0) {
                throw new \RuntimeException("备份失败（文件不存在或为空）：{$to}");
            }
            $sha = hash_file('sha256', $to);
            file_put_contents($to.'.sha256', $sha.'  '.basename($to)."\n");
        } else {
            $snapshot = SqliteSnapshot::create((string) Config::get('database.connections.sqlite.database'), $to, withChecksum: true);
            $sha = $snapshot['sha256'];
        }

        return [
            'path' => $to,
            'driver' => $driver,
            'bytes' => (int) filesize($to),
            'sha256' => (string) $sha,
            'pruned' => $keep > 0 ? $this->prune($dir, $keep) : [],
        ];
    }

    /** PostgreSQL：pg_dump -Fc（custom 格式，pg_restore 可直接吃）。 */
    private function backupPostgres(string $to): void
    {
        $connection = (string) Config::get('database.default');
        $config = (array) Config::get("database.connections.{$connection}", []);
        $binary = (string) (Config::get('geo.backup.pg_dump_binary') ?: 'pg_dump');

        $command = [
            $binary,
            '--format=custom',
            '--no-owner',
            '--no-privileges',
            '--file='.$to,
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.(string) ($config['port'] ?? 5432),
            '--username='.($config['username'] ?? 'postgres'),
            (string) ($config['database'] ?? ''),
        ];

        $result = ($this->runner)($command);
        if ($result['exit'] !== 0) {
            throw new \RuntimeException('pg_dump 失败：'.$result['output']);
        }
    }

    /**
     * 恢复演练：把备份真的恢复起来，核对关键表行数。
     *
     * @return array{ok: bool, driver: string, source: string, checks: array<string, mixed>, problems: list<string>, tables: array<string, int>}
     */
    public function drill(?string $from = null): array
    {
        $source = $from ?: $this->latestBackup();
        if ($source === null || ! is_file($source)) {
            return [
                'ok' => false,
                'driver' => $this->driver(),
                'source' => (string) $source,
                'checks' => [],
                'problems' => ['找不到可恢复的备份文件（先跑 geo:backup-database）'],
                'tables' => [],
            ];
        }

        // 校验和：备份文件被截断/改坏要在这里就发现，而不是等到真出事。
        $checksumFile = $source.'.sha256';
        $checksumOk = null;
        if (is_file($checksumFile)) {
            $expected = preg_split('/\s+/', trim((string) file_get_contents($checksumFile)))[0] ?? '';
            $checksumOk = hash_equals($expected, (string) hash_file('sha256', $source));
        }

        $driver = str_contains($source, '.dump') || $this->driver() === 'pgsql' && ! str_ends_with($source, '.sqlite')
            ? 'pgsql'
            : 'sqlite';

        return $driver === 'pgsql' ? $this->drillPostgres($source, $checksumOk) : $this->drillSqlite($source, $checksumOk);
    }

    private function latestBackup(): ?string
    {
        $files = $this->backupFiles($this->directory());
        if ($files === []) {
            return null;
        }
        usort($files, fn (string $a, string $b) => filemtime($b) <=> filemtime($a));

        return $files[0];
    }

    private function backupFiles(string $dir): array
    {
        return array_filter(glob($dir.'/geo-saas-*') ?: [], static fn (string $path): bool =>
            is_file($path) && ! preg_match('/(?:\.sha256|-wal|-shm|-journal)$/D', $path));
    }

    private function drillSqlite(string $source, ?bool $checksumOk): array
    {
        $problems = [];
        $tables = [];

        // 拷到临时文件再打开：演练过程绝不碰备份本体，也绝不碰生产库。
        $tmp = tempnam(sys_get_temp_dir(), 'geo-drill-');
        $integrity = 'unavailable';
        try {
            if ($tmp === false || ! copy($source, $tmp)
                || (is_file($source.'-wal') && ! copy($source.'-wal', $tmp.'-wal'))) {
                throw new \RuntimeException('无法完整读取待演练的备份文件。');
            }
            $pdo = new \PDO('sqlite:'.$tmp);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $integrity = (string) $pdo->query('PRAGMA integrity_check')->fetchColumn();
            if (strtolower($integrity) !== 'ok') {
                $problems[] = 'integrity_check 未通过：'.$integrity;
            }
            foreach (self::DRILL_TABLES as $table) {
                try {
                    $tables[$table] = (int) $pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
                } catch (\Throwable $e) {
                    $problems[] = "表 {$table} 读不到：".$e->getMessage();
                }
            }
        } catch (\Throwable $e) {
            $problems[] = '打开备份失败：'.$e->getMessage();
        } finally {
            unset($pdo);
            if ($tmp !== false) {
                foreach (['', '-wal', '-shm'] as $suffix) {
                    if (is_file($tmp.$suffix)) {
                        unlink($tmp.$suffix);
                    }
                }
            }
        }

        if ($checksumOk === false) {
            $problems[] = 'sha256 校验和不匹配（备份文件可能被截断或改坏）';
        }

        return [
            'ok' => $problems === [],
            'driver' => 'sqlite',
            'source' => $source,
            'checks' => ['integrity_check' => $integrity, 'sha256_verified' => $checksumOk],
            'problems' => $problems,
            'tables' => $tables,
        ];
    }

    /**
     * PostgreSQL 演练：临时库 → pg_restore → 数行数 → 删库。
     *
     * 库名带随机后缀，避免与任何真实库撞名；无论成败都 dropdb（失败只记 warning）。
     */
    private function drillPostgres(string $source, ?bool $checksumOk): array
    {
        $connection = (string) Config::get('database.default');
        $config = (array) Config::get("database.connections.{$connection}", []);
        $host = '--host='.($config['host'] ?? '127.0.0.1');
        $port = '--port='.(string) ($config['port'] ?? 5432);
        $user = '--username='.($config['username'] ?? 'postgres');

        $scratch = 'geo_drill_'.date('YmdHis').'_'.random_int(1000, 9999);
        $problems = [];
        $tables = [];

        $created = ($this->runner)([Config::get('geo.backup.createdb_binary') ?: 'createdb', $host, $port, $user, $scratch]);
        if ($created['exit'] !== 0) {
            return [
                'ok' => false,
                'driver' => 'pgsql',
                'source' => $source,
                'checks' => ['sha256_verified' => $checksumOk],
                'problems' => ['createdb 失败：'.$created['output']],
                'tables' => [],
            ];
        }

        try {
            $restore = ($this->runner)([Config::get('geo.backup.pg_restore_binary') ?: 'pg_restore', $host, $port, $user, '--dbname='.$scratch, '--no-owner', $source]);
            if ($restore['exit'] !== 0) {
                $problems[] = 'pg_restore 失败：'.$restore['output'];
            } else {
                foreach (self::DRILL_TABLES as $table) {
                    $count = ($this->runner)([Config::get('geo.backup.psql_binary') ?: 'psql', $host, $port, $user, '--tuples-only', '--no-align', '--dbname='.$scratch, '-c', 'SELECT COUNT(*) FROM '.$table]);
                    if ($count['exit'] !== 0) {
                        $problems[] = "表 {$table} 读不到：".$count['output'];

                        continue;
                    }
                    $tables[$table] = (int) trim($count['output']);
                }
            }
        } finally {
            ($this->runner)([Config::get('geo.backup.dropdb_binary') ?: 'dropdb', $host, $port, $user, '--if-exists', $scratch]);
        }

        if ($checksumOk === false) {
            $problems[] = 'sha256 校验和不匹配（备份文件可能被截断或改坏）';
        }

        return [
            'ok' => $problems === [],
            'driver' => 'pgsql',
            'source' => $source,
            'checks' => ['scratch_db' => $scratch, 'sha256_verified' => $checksumOk],
            'problems' => $problems,
            'tables' => $tables,
        ];
    }

    /** @return list<string> 被删掉的旧备份 */
    private function prune(string $dir, int $keep): array
    {
        $files = $this->backupFiles($dir);
        if (count($files) <= $keep) {
            return [];
        }
        usort($files, fn (string $a, string $b) => filemtime($a) <=> filemtime($b));
        $removed = [];
        foreach (array_slice($files, 0, count($files) - $keep) as $old) {
            @unlink($old);
            @unlink($old.'.sha256');
            @unlink($old.'-wal');
            @unlink($old.'-shm');
            $removed[] = $old;
        }

        return $removed;
    }
}
