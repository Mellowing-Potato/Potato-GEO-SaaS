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
