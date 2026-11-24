<?php

namespace Tests\Feature;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Yanji\Portable\Backup;
use Yanji\Portable\Instance;
use ZipArchive;

final class PortableBackupTest extends TestCase
{
    private string $dir;
    private string $release;
    private Instance $instance;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['Instance', 'Installation', 'Backup'] as $class) {
            $entry = base_path('portable/src/'.$class.'.php');
            if (is_file($entry)) {
                require_once $entry;
            }
        }
        $this->dir = sys_get_temp_dir().'/yanji-instance-backup-'.bin2hex(random_bytes(8));
        mkdir($this->dir, 0700);
        $this->release = $this->dir.'/release-1';
        foreach (['app/public', 'app/portable', 'runtime/bin', 'runtime/ca'] as $name) {
            mkdir($this->release.'/'.$name, 0700, true);
        }
        symlink(PHP_BINARY, $this->release.'/runtime/bin/php');
        file_put_contents($this->release.'/app/artisan', '<?php require '.var_export(base_path('artisan'), true).';');
        copy(base_path('portable/yanji'), $this->release.'/app/portable/yanji');
        copy(base_path('tests/fixtures/portable-test-ca.pem'), $this->release.'/runtime/ca/cacert.pem');
        $this->instance = new Instance($this->dir.'/数据实例');
        $this->instance->prepare();
        symlink($this->release, $this->instance->root.'/current');
        file_put_contents($this->instance->root.'/instance.json', '{"port":8182}');
        $key = random_bytes(32);
        $id = bin2hex(random_bytes(16));
        file_put_contents($this->instance->root.'/.env', 'APP_KEY=base64:'.base64_encode($key)."\nYANJI_INSTANCE_ID={$id}\n");
        $db = new \PDO('sqlite:'.$this->instance->root.'/database/database.sqlite');
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('CREATE TABLE facts (id INTEGER PRIMARY KEY)');
        $db->exec('INSERT INTO facts VALUES (19)');
        $db->exec('CREATE TABLE users (role TEXT)');
        $db->exec("INSERT INTO users (role) VALUES ('platform_admin')");
        $db->exec('CREATE TABLE instance_settings (id INTEGER PRIMARY KEY, key_verification TEXT)');
        $db->prepare('INSERT INTO instance_settings VALUES (1, ?)')->execute([
            (new Encrypter($key, 'AES-256-CBC'))->encryptString('yanji-instance-v1:'.$id),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_backup_contains_persistent_instance_data_only_and_restores_privately(): void
    {
        $source = $this->instance->root;
        $files = ['storage/app/private/reports/报告.html', 'storage/app/private/uploads/text.txt',
            'storage/app/public/themes/template.html', 'storage/app/private/material/.embedding-receipts/batch.receipt'];
        foreach ($files as $name) {
            Instance::privateDir(dirname($source.'/'.$name));
            file_put_contents($source.'/'.$name, "合成数据 {$name}");
        }
        file_put_contents($source.'/bootstrap-cache/config.php', 'old absolute paths');
        file_put_contents($source.'/storage/framework/views/compiled.php', 'compiled fixture');
        file_put_contents($source.'/storage/framework/sessions/test-session', 'expired fixture');
        file_put_contents($source.'/run/worker.pid', '999');
        file_put_contents($source.'/storage/app/yanji-install.lock', '');
        $archive = $this->dir.'/全量备份.zip';
        Backup::create($this->instance, $archive);
        $this->assertSame(0600, fileperms($archive) & 0777);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($archive));
        $manifest = json_decode($zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('release-1', $manifest['release']);
        $this->assertSame(8182, $manifest['port']);
        $this->assertEqualsCanonicalizing(['.env', 'database/database.sqlite', ...$files], array_keys($manifest['files']));
        $zip->close();

        $target = $this->dir.'/恢复实例';
        mkdir($target, 0700);
        Backup::extractToEmpty($archive, $target);
        $this->assertSame(0700, fileperms($target) & 0777);
        $this->assertSame(file_get_contents($source.'/.env'), file_get_contents($target.'/.env'));
        foreach ($files as $name) {
            $this->assertSame("合成数据 {$name}", file_get_contents($target.'/'.$name));
            $this->assertSame(0600, fileperms($target.'/'.$name) & 0777);
        }
        $db = new \PDO('sqlite:'.$target.'/database/database.sqlite');
        $this->assertSame(19, $db->query('SELECT id FROM facts')->fetchColumn());
        $this->assertDirectoryDoesNotExist($target.'/bootstrap-cache');
        $this->assertDirectoryDoesNotExist($target.'/storage/framework');
        $this->assertDirectoryDoesNotExist($target.'/run');
    }

    public function test_a_bad_archive_hash_and_failed_key_probe_leave_a_fresh_target_unpublished(): void
    {
        $archive = $this->dir.'/backup.zip';
        Backup::create($this->instance, $archive);
        $target = new Instance($this->dir.'/empty');
        mkdir($target->root, 0700);
        try {
            Backup::restore($target, $archive, $this->dir.'/other-release', 8183);
            $this->fail('An untested schema version must not be served by another release.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('备份不匹配当前程序版本', $error->getMessage());
        }
        $this->assertSame(['.', '..'], scandir($target->root));
        file_put_contents($this->release.'/app/artisan', '<?php exit(7);');
        try {
            Backup::restore($target, $archive, $this->release, 8183);
            $this->fail('A release that fails its portable readiness check must not publish the backup.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('就绪', $error->getMessage());
        }
        $this->assertSame(['.', '..'], scandir($target->root));
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($archive));
        $zip->addFromString('.env', 'damaged fixture');
        $zip->close();
        try {
            Backup::extractToEmpty($archive, $target->root);
            $this->fail('Changed backup bytes must not restore.');
        } catch (RuntimeException $error) {
            $this->assertNotSame('', $error->getMessage());
        }
        $this->assertSame(['.', '..'], scandir($target->root));
    }

    public static function mismatchedBindings(): array
    {
        return ['missing key' => ['APP_KEY', null], 'different key' => ['APP_KEY', 'key'], 'different instance' => ['YANJI_INSTANCE_ID', 'id']];
    }

    #[DataProvider('mismatchedBindings')]
    public function test_unrestorable_source_never_publishes_a_successful_backup(string $variable, ?string $replacement): void
    {
        $env = $this->instance->root.'/.env';
        $db = $this->instance->root.'/database/database.sqlite';
        $value = match ($replacement) {
            'key' => 'base64:'.base64_encode(random_bytes(32)), 'id' => bin2hex(random_bytes(16)), default => '',
        };
        $contents = preg_replace('/^'.$variable.'=.*$/m', $variable.'='.$value, file_get_contents($env));
        file_put_contents($env, $contents);
        $originalDb = hash_file('sha256', $db);
        $archive = $this->dir.'/unrestorable.zip';
        try {
            Backup::create($this->instance, $archive);
            $this->fail('A backup from incompatible instance keys must not be published.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('就绪', $error->getMessage());
        }
        $this->assertFileDoesNotExist($archive);
        $this->assertSame($contents, file_get_contents($env));
        $this->assertSame($originalDb, hash_file('sha256', $db));
    }

    public function test_refuses_existing_files_recursive_outputs_and_linked_private_data(): void
    {
        $nestedOutput = $this->instance->root.'/storage/app/backup.zip';
        try {
            Backup::create($this->instance, $nestedOutput);
            $this->fail('A backup must not be created under its own source.');
        } catch (RuntimeException $error) {
            $this->assertFileDoesNotExist($nestedOutput);
        }
        $archive = $this->dir.'/backup.zip';
        Backup::create($this->instance, $archive);
        $before = hash_file('sha256', $archive);
        try {
            Backup::create($this->instance, $archive);
            $this->fail('An existing backup must not be replaced.');
        } catch (RuntimeException $error) {
            $this->assertSame($before, hash_file('sha256', $archive));
        }
        try {
            Backup::extractToEmpty($archive, $this->instance->root);
            $this->fail('A populated instance must not be replaced.');
        } catch (RuntimeException $error) {
            $this->assertFileExists($this->instance->root.'/instance.json');
        }
        symlink($this->instance->root.'/.env', $this->instance->root.'/storage/app/private/alias');
        $other = $this->dir.'/linked.zip';
        try {
            Backup::create($this->instance, $other);
            $this->fail('A storage alias must never be followed into another file.');
        } catch (RuntimeException $error) {
            $this->assertFileDoesNotExist($other);
        }
    }

    public function test_archive_and_restore_refuse_another_instances_public_directory_and_installed_program_tree(): void
    {
        $other = new Instance($this->dir.'/other-instance');
        $other->prepare();
        Instance::atomicFile($other->root.'/.portable-layout', "yanji-portable-v1\n");
        $program = $this->dir.'/other-program';
        Instance::privateDir($program.'/releases');
        Instance::atomicFile($program.'/.yanji-program', "test fixture\n");
        $link = $this->dir.'/linked-public';
        symlink($other->root.'/storage/app/public', $link);
        $valid = $this->dir.'/valid.zip';
        Backup::create($this->instance, $valid);
        foreach ([$other->root.'/storage/app/public', $link, $program.'/releases'] as $parent) {
            $zip = $parent.'/export.zip';
            try {
                Backup::create($this->instance, $zip);
                $this->fail('Private environment archives must stay outside every managed instance and program.');
            } catch (RuntimeException $error) {
                $this->assertFileDoesNotExist($zip);
            }
            $target = $parent.'/new-instance';
            try {
                Backup::extractToEmpty($valid, $target);
                $this->fail('Restored private data must stay outside every managed instance and program.');
            } catch (RuntimeException $error) {
                $this->assertDirectoryDoesNotExist($target);
            }
        }
    }

    public function test_restore_accepts_only_declared_regular_members_inside_the_data_tree(): void
    {
        $archive = $this->dir.'/invalid-member.zip';
        Backup::create($this->instance, $archive);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($archive));
        $manifest = json_decode($zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $name = 'storage/app/../unlisted.txt';
        $manifest['files'][$name] = ['bytes' => 7, 'sha256' => hash('sha256', 'fixture')];
        $zip->addFromString($name, 'fixture');
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $zip->close();
        $target = $this->dir.'/fresh';
        try {
            Backup::extractToEmpty($archive, $target);
            $this->fail('Invalid relative members must not restore.');
        } catch (RuntimeException $error) {
            $this->assertDirectoryDoesNotExist($target);
            $this->assertFileDoesNotExist($this->dir.'/unlisted.txt');
        }
    }
}
