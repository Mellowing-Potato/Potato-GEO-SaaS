<?php

namespace Tests\Feature;

use App\Services\Backup\DatabaseBackupService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PDO;
use Tests\TestCase;
use Throwable;

final class SqliteSnapshotTest extends TestCase
{
    private string $dir;
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/yanji-snapshot-'.bin2hex(random_bytes(8));
        mkdir($this->dir, 0700);
        $this->source = $this->dir.'/source.sqlite';
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $this->source, 'geo.backup.dir' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_artisan_and_backup_service_refuse_preexisting_outputs(): void
    {
        $source = new PDO('sqlite:'.$this->source);
        $source->exec('CREATE TABLE evidence (id INTEGER PRIMARY KEY)');
        $to = $this->dir.'/existing.sqlite';
        file_put_contents($to, '已有文件');
        $this->assertSame(1, Artisan::call('geo:backup-sqlite', ['--to' => $to]));
        $this->assertSame('已有文件', file_get_contents($to));

        $error = null;
        try {
            (new DatabaseBackupService)->backup($to);
        } catch (Throwable $exception) {
            $error = $exception;
        }
        $this->assertNotNull($error, 'An occupied target must survive both entry points.');
        $this->assertSame('已有文件', file_get_contents($to));
    }

    public function test_committed_wal_pages_are_in_one_standalone_file_with_spaces_and_quotes_in_its_name(): void
    {
        $writer = new PDO('sqlite:'.$this->source);
        $writer->exec('PRAGMA journal_mode=WAL');
        $writer->exec('PRAGMA wal_autocheckpoint=0');
        $writer->exec('CREATE TABLE evidence (id INTEGER PRIMARY KEY)');
        $reader = new PDO('sqlite:'.$this->source);
        $reader->beginTransaction();
        $reader->query('SELECT count(*) FROM evidence')->fetchColumn();
        $writer->exec('INSERT INTO evidence VALUES (17)');
        $this->assertGreaterThan(0, filesize($this->source.'-wal'));

        $to = $this->dir."/it's 测试.sqlite";
        $snapshot = (new DatabaseBackupService)->backup($to);
        $reader->rollBack();
        $this->assertSame(hash_file('sha256', $to), $snapshot['sha256']);
        $this->assertGreaterThan(0, $snapshot['bytes']);
        $this->assertFileDoesNotExist($to.'-wal');
        $this->assertFileDoesNotExist($to.'-shm');
        $copy = new PDO('sqlite:'.$to);
        $this->assertSame('ok', $copy->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertSame(17, $copy->query('SELECT id FROM evidence')->fetchColumn());
    }

    public function test_an_occupied_checksum_path_does_not_publish_or_overwrite(): void
    {
        $source = new PDO('sqlite:'.$this->source);
        $source->exec('CREATE TABLE evidence (id INTEGER PRIMARY KEY)');
        $to = $this->dir.'/checksum.sqlite';
        file_put_contents($to.'.sha256', '保留已有校验和');
        try {
            (new DatabaseBackupService)->backup($to);
        } catch (Throwable $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
        $this->assertFileDoesNotExist($to);
        $this->assertSame('保留已有校验和', file_get_contents($to.'.sha256'));
    }

    public function test_corrupt_source_never_publishes_a_snapshot_or_sidecar(): void
    {
        file_put_contents($this->source, str_repeat('corrupt SQLite fixture', 32));
        $to = $this->dir.'/partial.sqlite';
        try {
            (new DatabaseBackupService)->backup($to);
        } catch (Throwable $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
        $this->assertFileDoesNotExist($to);
        $this->assertFileDoesNotExist($to.'.sha256');
    }
}
