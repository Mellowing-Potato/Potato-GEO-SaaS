<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Illuminate\Filesystem\Filesystem;
use Yanji\Portable\Instance;
use Yanji\Portable\Installation;
use Yanji\Portable\Supervisor;

require_once __DIR__.'/../../portable/src/Instance.php';
require_once __DIR__.'/../../portable/src/Installation.php';
require_once __DIR__.'/../../portable/src/Supervisor.php';

class PortableRuntimeTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/yanji-portable-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->directory = realpath($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
    }

    private function program(string $name): string
    {
        $release = $this->directory.'/'.$name;
        mkdir($release.'/app/public', 0700, true);
        mkdir($release.'/runtime/bin', 0700, true);
        touch($release.'/app/artisan');
        touch($release.'/app/public/index.php');
        touch($release.'/runtime/bin/php');
        chmod($release.'/runtime/bin/php', 0700);
        return $release;
    }

    public function test_missing_prerequisites_leave_installation_retryable_with_another_release(): void
    {
        $i = new Instance($this->directory."/it's 中文");
        $release = $this->program('first');
        mkdir($release.'/app/portable');
        file_put_contents($release.'/app/portable/yanji', "#!/bin/sh\n");
        file_put_contents($release.'/app/portable/instance.env.example', "DB_DATABASE=\n");
        $setup = new Installation($i);
        try {
            $setup->configure($release, 8030);
            self::fail('Missing CA accepted');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('CA', $e->getMessage());
            self::assertFalse(is_link($i->root.'/current'));
            self::assertFileDoesNotExist($i->root.'/instance.json');
        }

        $second = $this->program('second');
        mkdir($second.'/runtime/ca');
        mkdir($second.'/app/portable');
        touch($second.'/runtime/ca/cacert.pem');
        copy($release.'/app/portable/yanji', $second.'/app/portable/yanji');
        copy($release.'/app/portable/instance.env.example', $second.'/app/portable/instance.env.example');
        $password = $this->directory.'/password';
        file_put_contents($password, "test-install-only\n");
        chmod($password, 0600);
        try {
            $setup->install($second, 8031, 'example@example.com', $password, '/usr/bin/false');
            self::fail('Invalid base renderer accepted');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('--python-bin', $e->getMessage());
            self::assertFalse(is_link($i->root.'/current'));
            self::assertFileDoesNotExist($i->root.'/python-path');
        }
        $setup->configure($second, 8031);
        self::assertSame($second, $i->release());
        self::assertSame(8031, $i->port());
    }

    public function test_empty_interrupted_install_can_select_a_new_release_and_retain_the_database_path(): void
    {
        $instance = new Instance($this->directory."/it's 中文");
        $instance->prepare();
        $instance->activate($this->program('old'));
        Instance::atomicFile($instance->root.'/instance.json', '{"port":8030}');
        $release = $this->program('new');
        mkdir($release.'/runtime/ca');
        mkdir($release.'/app/portable');
        touch($release.'/runtime/ca/cacert.pem');
        file_put_contents($release.'/app/portable/yanji', "#!/bin/sh\n");
        file_put_contents($release.'/app/portable/instance.env.example', "DB_DATABASE=\n");
        file_put_contents($release.'/runtime/bin/php', "#!/bin/sh\nexit 0\n");
        $python = $this->directory.'/python';
        file_put_contents($python, "#!/bin/sh\nprintf '%s' '{\"basic\":true}'\n");
        chmod($python, 0700);
        $password = $this->directory.'/password';
        Instance::atomicFile($password, "test-retry-only\n");
        (new Installation($instance))->install($release, 8030, 'admin@example.com', $password, $python);
        self::assertSame($release, $instance->release());
        $env = \Dotenv\Dotenv::parse(file_get_contents($instance->root.'/.env'));
        self::assertSame($instance->root.'/database/database.sqlite', $env['DB_DATABASE']);
    }

    public function test_public_conflict_keeps_the_served_version_and_all_managed_links(): void
    {
        $i = new Instance($this->directory.'/中文 instance');
        $i->prepare();
        $old = $this->program('old');
        $new = $this->program('new');
        touch($old.'/app/public/old.png');
        touch($new.'/app/public/new.png');
        $i->activate($old);
        file_put_contents($i->root.'/public/user.txt', 'owned');
        try {
            $i->activate($new);
            self::fail('Unexpectedly accepted an unmanaged public file');
        } catch (\RuntimeException) {
            self::assertSame($old, $i->release());
            self::assertFileExists($i->root.'/public/old.png');
            self::assertFalse(is_link($i->root.'/public/new.png'));
            self::assertSame('owned', file_get_contents($i->root.'/public/user.txt'));
        }
    }

    public function test_two_ports_on_the_same_host_have_different_cookies_caches_and_storage(): void
    {
        $release = $this->program('shared');
        $environments = [];
        foreach ([8000,8001] as $port) {
            $i = new Instance($this->directory.'/instance-'.$port);
            $i->prepare();
            $i->activate($release);
            Instance::atomicFile($i->root.'/instance.json', json_encode(['port'=>$port]));
            $env = $i->environment();
            self::assertSame($i->root.'/public', $env['YANJI_PUBLIC_PATH']);
            self::assertSame($i->root.'/database/database.sqlite', $env['DB_DATABASE']);
            $environments[] = $env;
        }
        foreach (['YANJI_BROWSER_COOKIE_NAME','SESSION_COOKIE','APP_CONFIG_CACHE','LARAVEL_STORAGE_PATH','CACHE_PREFIX'] as $key) {
            self::assertNotSame($environments[0][$key], $environments[1][$key]);
        }
    }

    public function test_physical_destinations_cannot_overlap_a_private_instance_or_replace_a_source_checkout(): void
    {
        $existing = new Instance($this->directory.'/first');
        $existing->prepare();
        Instance::atomicFile($existing->root.'/.portable-layout', "yanji-portable-v1\n");
        symlink($existing->root.'/storage/app/public', $this->directory.'/alias');
        foreach ([$existing->root.'/storage/app/public/new', $this->directory.'/alias/new'] as $path) {
            try {
                (new Instance($path))->validateDestination();
                self::fail('Managed descendant accepted');
            } catch (\RuntimeException) {
                self::assertDirectoryDoesNotExist($path);
            }
        }
        $source = $this->directory.'/source';
        mkdir($source.'/database', 0755, true);
        file_put_contents($source.'/.env', 'APP_ENV=local');
        $before = fileperms($source);
        try {
            (new Instance($source))->validateDestination();
            self::fail('Unrelated Laravel source directory accepted');
        } catch (\RuntimeException) {
            self::assertSame('APP_ENV=local', file_get_contents($source.'/.env'));
            self::assertSame($before, fileperms($source));
            self::assertDirectoryDoesNotExist($source.'/run');
        }
        self::assertSame($this->directory.'/program', Instance::programPath($this->directory.'/program', new Instance($this->directory.'/second')));
    }

    public function test_legacy_database_url_cannot_redirect_a_restored_process_to_another_database(): void
    {
        $release = $this->program('release');
        $i = new Instance($this->directory.'/restored');
        $i->prepare();
        $i->activate($release);
        Instance::atomicFile($i->root.'/instance.json', '{"port":8100}');
        $old = $this->directory.'/original.sqlite';
        $target = $i->root.'/database/database.sqlite';
        foreach ([$old=>'old',$target=>'restored'] as $file=>$label) {
            $db = new \SQLite3($file);
            $db->exec("CREATE TABLE marker (value TEXT)");
            $db->exec("INSERT INTO marker VALUES ('$label')");
            $db->close();
        }
        Instance::atomicFile($i->root.'/.env', 'DB_URL=sqlite://localhost/'.$old."\n");
        $script = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo $app['db']->connection()->table('marker')->value('value');
PHP;
        $proc = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 2)], env: $i->environment() + ['DB_URL'=>false]);
        $proc->mustRun();
        self::assertSame('restored', $proc->getOutput());
    }

    public function test_stale_cached_configuration_is_bypassed_for_diagnostics_and_removed_before_start(): void
    {
        $i = new Instance($this->directory.'/instance');
        $i->prepare();
        $i->activate($this->program('release'));
        Instance::atomicFile($i->root.'/instance.json', '{"port":8100}');
        $cache = $i->environment()['APP_CONFIG_CACHE'];
        Instance::atomicFile($cache, "<?php return ['database'=>['default'=>'old']];");
        self::assertNotSame($cache, $i->environment(true)['APP_CONFIG_CACHE']);
        self::assertFileExists($cache);
        $i->discardConfigurationCache();
        self::assertFileDoesNotExist($cache);
    }

    public function test_world_writable_program_ancestor_is_rejected_without_changing_it(): void
    {
        $shared = $this->directory.'/shared';
        mkdir($shared, 0700);
        chmod($shared, 0777);
        try {
            Instance::programPath($shared.'/program', new Instance($this->directory.'/instance'));
            self::fail('Writable ancestor accepted');
        } catch (\RuntimeException) {
            self::assertSame(0777, fileperms($shared) & 0777);
            self::assertDirectoryDoesNotExist($shared.'/program');
        }
    }

    public function test_readiness_requires_current_generation_running_status_and_nonfuture_time(): void
    {
        $i = new Instance($this->directory.'/instance');
        $i->prepare();
        $db = new \SQLite3($i->root.'/database/database.sqlite');
        $db->exec('CREATE TABLE worker_heartbeats(worker_id TEXT, status TEXT, meta TEXT, last_seen_at TEXT)');
        $db->exec("INSERT INTO worker_heartbeats VALUES('portable-worker','running','{}','')");
        $now = time();
        foreach ([['old', 42, 'running', $now], ['current', 41, 'running', $now],
            ['current', 42, 'stopping', $now], ['current',42,'running',$now + 300], ['current',42,'running',$now]] as [$gen,$pid,$status,$ts]) {
            $update = $db->prepare('UPDATE worker_heartbeats SET status=?, meta=?, last_seen_at=?');
            $update->bindValue(1,$status);
            $update->bindValue(2,json_encode(['generation'=>$gen,'pid'=>$pid]));
            $update->bindValue(3,gmdate('Y-m-d H:i:s',$ts));
            $update->execute();
            $healthy = $i->heartbeat('worker',$now - 2,'current',42) !== null;
            self::assertSame($gen === 'current' && $pid === 42 && $status === 'running' && $ts === $now,$healthy);
        }
        $db->close();
    }

    public function test_framework_public_path_is_the_selected_instance_public_root(): void
    {
        $public = $this->directory.'/public';
        $script = '$app = require $argv[1]; echo $app->publicPath();';
        $process = new \Symfony\Component\Process\Process([PHP_BINARY,'-r',
            'require $argv[2]; '.$script, dirname(__DIR__, 2).'/bootstrap/app.php', dirname(__DIR__, 2).'/vendor/autoload.php'],
            env: ['YANJI_PUBLIC_PATH'=>$public]);
        $process->mustRun();
        self::assertSame($public, $process->getOutput());
    }

    public function test_unrelated_nonempty_directory_is_never_initialized(): void
    {
        $path = $this->directory.'/unrelated';
        mkdir($path, 0700);
        file_put_contents($path.'/keep.txt', 'keep');
        $this->expectException(\RuntimeException::class);
        (new Instance($path))->validateDestination();
    }

    public function test_lost_supervisor_socket_does_not_authorize_backups_with_a_live_service_lease(): void
    {
        $i = new Instance($this->directory.'/instance');
        $i->prepare();
        $lease = fopen($i->root.'/run/services.lock', 'c');
        flock($lease, LOCK_SH);
        try {
            $this->expectExceptionMessage('仍有服务持有实例锁');
            (new Supervisor($i))->stop();
        } finally {
            fclose($lease);
        }
    }

    public function test_boolean_arguments_with_values_do_not_probe_or_write_authorization(): void
    {
        $release = $this->program('release');
        unlink($release.'/runtime/bin/php');
        symlink(PHP_BINARY, $release.'/runtime/bin/php');
        $i = new Instance($this->directory.'/instance');
        $i->prepare();
        $i->activate($release);
        Instance::atomicFile($i->root.'/instance.json', '{"port":8100}');
        $endpoint = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($endpoint, false);
        // This local stand-in records exactly what reached Artisan, without accessing a host service.
        $artisan = '<?php $socket=stream_socket_client('.var_export('tcp://'.$address, true).');'
            .'fwrite($socket,"fixture"); fclose($socket); file_put_contents(getenv("YANJI_ENV_PATH")."/run/authorized","yes");';
        file_put_contents($release.'/app/artisan', $artisan);
        try {
            foreach ([['--probe=false'], ['--probe','--authorize=false'], ['--probe=true']] as $flags) {
                $proc = new \Symfony\Component\Process\Process([PHP_BINARY, dirname(__DIR__, 2).'/portable/yanji.php',
                    'magpie', '--instance', $i->root, '--tenant=1', ...$flags]);
                $proc->run();
                self::assertSame(1, $proc->getExitCode());
                self::assertStringContainsString('开关仅接受不带值的形式', $proc->getErrorOutput());
                self::assertFalse(@stream_socket_accept($endpoint, 0));
                self::assertFileDoesNotExist($i->root.'/run/authorized');
            }
        } finally {
            fclose($endpoint);
        }
    }

    public function test_password_file_must_be_private_and_is_consumed_using_stdin(): void
    {
        $path = $this->directory.'/password';
        file_put_contents($path, 'fixture-value');
        chmod($path, 0600);
        [$args, $input] = Installation::passwordInput(['yanji:install'], $path);
        self::assertSame(['yanji:install','--password-stdin','--no-interaction'], $args);
        self::assertSame("fixture-value\n", $input);
        chmod($path, 0644);
        $this->expectException(\RuntimeException::class);
        Installation::passwordInput(['yanji:install'], $path);
    }
}
