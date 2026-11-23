<?php

namespace Tests\Feature;

use App\Services\Backup\DatabaseBackupService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 数据库备份与恢复演练（计划 §9.2，2026-09-21）。
 *
 * 这一条判据要守的是"备份真的能用来恢复"，不是"命令退出码是 0"：
 *   1. sqlite：**真做一次**——造一个有数据的临时库 → 备份 → 演练 → 行数对得上；
 *   2. 坏备份必须被发现：把备份文件改坏，演练要因为 sha256 不匹配而失败
 *      （否则"备份成功"只是一句口号）；
 *   3. pgsql：本机没有 pg_dump/pg_restore，用注入的假执行器把**命令序列**钉住
 *      （pg_dump → 备份；createdb → pg_restore → psql 数行数 → dropdb）；
 *   4. 保留份数：--keep 只留最近 N 份（含删掉 .sha256 边车文件）。
 */
class DatabaseBackupTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('app/backup-test-'.uniqid());
        File::ensureDirectoryExists($this->dir);
        config(['geo.backup.dir' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** 造一个最小 sqlite 库：表结构与 DRILL_TABLES 对齐，各塞几行。 */
    private function makeSourceDatabase(int $tenants = 2, int $users = 3): string
    {
        $path = $this->dir.'/source.sqlite';
        $pdo = new \PDO('sqlite:'.$path);
        $pdo->exec('CREATE TABLE tenants (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT)');
        $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, tenant_id INTEGER)');
        $pdo->exec('CREATE TABLE articles (id INTEGER PRIMARY KEY, project_id INTEGER)');
        for ($i = 1; $i <= $tenants; $i++) {
            $pdo->exec("INSERT INTO tenants (id, name) VALUES ({$i}, 'tenant {$i}')");
        }
        for ($i = 1; $i <= $users; $i++) {
            $pdo->exec("INSERT INTO users (id, email) VALUES ({$i}, 'u{$i}@example.com')");
        }

        return $path;
    }

    private function useSqliteSource(string $path): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $path,
        ]);
    }

    public function test_sqlite_backup_then_drill_reports_real_row_counts(): void
    {
        $source = $this->makeSourceDatabase(tenants: 2, users: 3);
        $this->useSqliteSource($source);

        $service = new DatabaseBackupService;
        $backup = $service->backup($this->dir.'/geo-saas-sqlite-test.sqlite');

        $this->assertFileExists($backup['path']);
        $this->assertGreaterThan(0, $backup['bytes']);
        $this->assertFileExists($backup['path'].'.sha256');
        $this->assertSame(hash_file('sha256', $backup['path']), $backup['sha256']);

        $drill = $service->drill($backup['path']);
        $this->assertTrue($drill['ok'], '演练必须通过：'.implode('；', $drill['problems']));
        $this->assertSame(2, $drill['tables']['tenants']);
        $this->assertSame(3, $drill['tables']['users']);
        $this->assertTrue($drill['checks']['sha256_verified']);
    }

    public function test_corrupted_backup_fails_the_drill(): void
    {
        $source = $this->makeSourceDatabase();
        $this->useSqliteSource($source);

        $service = new DatabaseBackupService;
        $backup = $service->backup($this->dir.'/geo-saas-sqlite-corrupt.sqlite');

        // 模拟"备份文件被截断/改坏"：追加几个字节，校验和立刻对不上。
        file_put_contents($backup['path'], 'CORRUPTED', FILE_APPEND);

        $drill = $service->drill($backup['path']);
        $this->assertFalse($drill['ok'], '改坏的备份绝不能被当成可恢复');
        $this->assertFalse($drill['checks']['sha256_verified']);
        $this->assertStringContainsString('sha256', implode(' ', $drill['problems']));
    }

    public function test_unreadable_sqlite_drill_never_reports_integrity_ok(): void
    {
        $this->useSqliteSource($this->makeSourceDatabase());
        $file = $this->dir.'/geo-saas-invalid.sqlite';
        file_put_contents($file, str_repeat('invalid SQLite data', 40));
        $drill = (new DatabaseBackupService)->drill($file);
        $this->assertFalse($drill['ok']);
        $this->assertSame('unavailable', $drill['checks']['integrity_check']);
    }

    public function test_a_newer_legacy_sidecar_is_not_selected_as_the_backup(): void
    {
        $this->useSqliteSource($this->makeSourceDatabase());
        $service = new DatabaseBackupService;
        $backup = $service->backup($this->dir.'/geo-saas-old.sqlite');
        file_put_contents($backup['path'].'-shm', 'legacy shared-memory fixture');
        touch($backup['path'].'-shm', time() + 2);
        $drill = $service->drill();
        $this->assertSame($backup['path'], $drill['source']);
        $this->assertTrue($drill['ok']);
    }

    public function test_drill_without_any_backup_says_what_to_do(): void
    {
        $this->useSqliteSource($this->makeSourceDatabase());

        $drill = (new DatabaseBackupService)->drill();
        $this->assertFalse($drill['ok']);
        $this->assertStringContainsString('geo:backup-database', implode(' ', $drill['problems']));
    }

    public function test_keep_retention_removes_oldest_backups_and_sidecars(): void
    {
        $source = $this->makeSourceDatabase();
        $this->useSqliteSource($source);

        $service = new DatabaseBackupService;
        foreach (['a', 'b', 'c'] as $name) {
            $service->backup($this->dir.'/geo-saas-sqlite-'.$name.'.sqlite');
            touch($this->dir.'/geo-saas-sqlite-'.$name.'.sqlite', time() + (int) ord($name));
        }
        $result = $service->backup($this->dir.'/geo-saas-sqlite-latest.sqlite', keep: 2);

        $remaining = array_values(array_filter(
            scandir($this->dir) ?: [],
            fn (string $f) => str_starts_with($f, 'geo-saas-sqlite-') && ! str_ends_with($f, '.sha256'),
        ));
        $this->assertCount(2, $remaining, '保留 2 份：'.implode(',', $remaining));
        $this->assertNotEmpty($result['pruned']);
        foreach ($result['pruned'] as $pruned) {
            $this->assertFileDoesNotExist($pruned.'.sha256', '被清理的备份不能留下孤儿 .sha256 边车');
        }
    }

    public function test_postgres_backup_and_drill_use_the_expected_command_sequence(): void
    {
        $commands = [];
        $runner = function (array $command) use (&$commands): array {
            $commands[] = $command;
            // 假装 pg_dump 真的产出了文件，让 backup() 的后续校验能通过。
            foreach ($command as $arg) {
                if (str_starts_with((string) $arg, '--file=')) {
                    file_put_contents(substr($arg, 7), 'PGDMP-fake');
                }
            }
            $output = 'COUNT(*)';

            return ['exit' => 0, 'output' => in_array('SELECT COUNT(*) FROM tenants', $command, true) ? "7\n" : $output];
        };

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql' => [
                'host' => 'db.internal', 'port' => 5432, 'username' => 'geo', 'database' => 'geo_prod',
            ],
            'geo.backup.pg_dump_binary' => 'pg_dump',
        ]);

        $service = new DatabaseBackupService($runner);
        $backup = $service->backup($this->dir.'/geo-saas-pgsql-test.dump');

        $this->assertSame('pgsql', $backup['driver']);
        $dump = $commands[0];
        $this->assertSame('pg_dump', $dump[0]);
        $this->assertContains('--format=custom', $dump);
        $this->assertContains('--no-owner', $dump);
        $this->assertContains('--host=db.internal', $dump);
        $this->assertContains('--username=geo', $dump);
        $this->assertContains('geo_prod', $dump);

        $commands = [];
        $drill = $service->drill($backup['path']);
        $this->assertTrue($drill['ok'], '演练必须通过：'.implode('；', $drill['problems']));
        $this->assertSame(7, $drill['tables']['tenants']);

        $sequence = array_map(fn (array $c) => basename((string) $c[0]), $commands);
        $this->assertSame(['createdb', 'pg_restore', 'psql', 'psql', 'psql', 'psql', 'dropdb'], $sequence,
            '演练顺序必须是：建临时库 → 恢复 → 逐表数行数 → 删临时库');
        $this->assertStringContainsString('--if-exists', implode(' ', $commands[6]));
        $this->assertStringContainsString('geo_drill_', implode(' ', $commands[0]));
    }

    public function test_postgres_drill_cleans_up_scratch_db_even_when_restore_fails(): void
    {
        $commands = [];
        $runner = function (array $command) use (&$commands): array {
            $commands[] = $command;
            $binary = basename((string) $command[0]);

            return ['exit' => $binary === 'pg_restore' ? 1 : 0, 'output' => $binary === 'pg_restore' ? 'restore blew up' : ''];
        };

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql' => ['host' => 'h', 'port' => 5432, 'username' => 'u', 'database' => 'd'],
        ]);
        file_put_contents($this->dir.'/geo-saas-pgsql-broken.dump', 'PGDMP');

        $drill = (new DatabaseBackupService($runner))->drill($this->dir.'/geo-saas-pgsql-broken.dump');

        $this->assertFalse($drill['ok']);
        $this->assertStringContainsString('pg_restore 失败', implode(' ', $drill['problems']));
        $this->assertSame('dropdb', basename((string) end($commands)[0]), '恢复失败也必须删掉临时库');
    }
}
