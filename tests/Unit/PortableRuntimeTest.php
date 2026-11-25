<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Illuminate\Filesystem\Filesystem;
use Yanji\Portable\Instance;
use Yanji\Portable\Installation;
use Yanji\Portable\Supervisor;
use Symfony\Component\Process\Process;

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

    public function test_fresh_default_install_uses_desktop_initialization_with_closed_stdin(): void
    {
        $root = $this->directory.'/desktop instance';
        $release = $this->program('desktop-release');
        mkdir($release.'/runtime/ca');
        mkdir($release.'/app/portable');
        touch($release.'/runtime/ca/cacert.pem');
        file_put_contents($release.'/app/portable/yanji', "#!/bin/sh\n");
        file_put_contents($release.'/app/portable/instance.env.example', "APP_URL=http://127.0.0.1:8000\nAPP_KEY=\nDB_DATABASE=\nSESSION_COOKIE=\n");
        $fakePhp = <<<'PHP'
<?php
$root = getenv('YANJI_ENV_PATH');
file_put_contents($root.'/run/commands', json_encode(array_slice($argv, 1))."\n", FILE_APPEND);
if (in_array('yanji:install', $argv, true)) {
    file_put_contents($root.'/run/installed', 'verified');
    exit(0);
}
if (in_array('yanji:ready', $argv, true)) {
    exit(is_file($root.'/run/installed') ? 0 : 1);
}
exit(2);
PHP;
        file_put_contents($release.'/runtime/bin/php', '#!'.PHP_BINARY."\n".$fakePhp);
        chmod($release.'/runtime/bin/php', 0700);
        $python = $this->directory.'/desktop-python';
        file_put_contents($python, "#!/bin/sh\nprintf '%s' '{\"basic\":true}'\n");
        chmod($python, 0700);
        $driver = <<<'PHP'
require 'vendor/autoload.php';
require 'portable/src/Installation.php';
require 'portable/src/Instance.php';
$instance = new Yanji\Portable\Instance($argv[1]);
(new Yanji\Portable\Installation($instance))->install($argv[2], 8132, null, null, $argv[3]);
PHP;
        $process = new Process([PHP_BINARY, '-r', $driver, $root, $release, $python], dirname(__DIR__, 2), null, '', 30);
        $process->run();
        self::assertSame(0, $process->getExitCode(), 'Fresh default installation must not require a terminal or credentials.');
        $env = \Dotenv\Dotenv::parse(file_get_contents($root.'/.env'));
        self::assertSame('true', $env['YANJI_DESKTOP_MODE']);
        $calls = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($root.'/run/commands', FILE_IGNORE_NEW_LINES));
        $install = array_values(array_filter($calls, static fn (array $args): bool => in_array('yanji:install', $args, true)));
        self::assertCount(1, $install);
        self::assertContains('--portable', $install[0]);
        self::assertContains('--desktop', $install[0]);
        self::assertContains('--no-interaction', $install[0]);
        self::assertFalse((bool) array_filter($install[0], static fn (string $arg): bool => str_starts_with($arg, '--email') || str_starts_with($arg, '--password')));
        self::assertFileExists($root.'/.portable-initialized');
    }

    public function test_no_browser_flag_is_accepted_by_the_native_cli_without_changing_the_stopped_state(): void
    {
        $instance = new Instance($this->directory.'/headless instance');
        $instance->prepare();
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/portable/yanji.php', 'status',
            '--instance', $instance->root, '--no-browser']);
        $process->mustRun();
        $status = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($status['ready']);
        self::assertSame('stopped', $status['state']);
        self::assertFileDoesNotExist($instance->root.'/.env');
    }

    public function test_no_browser_flag_survives_the_upgrade_installer_handoff(): void
    {
        $instance = new Instance($this->directory.'/headless upgrade');
        $instance->prepare();
        $release = $this->program('handoff-release');
        mkdir($release.'/app/scripts', 0700, true);
        $record = $this->directory.'/forwarded-arguments';
        if (PHP_OS_FAMILY === 'Windows') {
            file_put_contents($release.'/app/scripts/install-local.ps1', <<<'PS'
param([switch]$Upgrade,[string]$ProgramHome,[string]$Instance,[string]$Manifest,[string]$ManifestSha256,[switch]$NoBrowser)
[IO.File]::WriteAllText($env:YANJI_TEST_FORWARD, (@{no_browser=$NoBrowser.IsPresent;upgrade=$Upgrade.IsPresent;instance=$Instance} | ConvertTo-Json -Compress))
PS);
        } else {
            file_put_contents($release.'/app/scripts/install-local.sh', <<<'SH'
#!/bin/sh
printf '%s\n' "$@" > "$YANJI_TEST_FORWARD"
SH);
        }
        $instance->activate($release);
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/portable/yanji.php', 'upgrade',
            '--instance', $instance->root, '--manifest', $this->directory.'/fixture-sums',
            '--manifest-sha256', str_repeat('a', 64), '--no-browser'], null, ['YANJI_TEST_FORWARD' => $record]);
        $process->mustRun();
        if (PHP_OS_FAMILY === 'Windows') {
            $forwarded = json_decode(file_get_contents($record), true, flags: JSON_THROW_ON_ERROR);
            self::assertTrue($forwarded['no_browser']);
            self::assertTrue($forwarded['upgrade']);
            self::assertSame($instance->root, str_replace('\\', '/', $forwarded['instance']));
        } else {
            $forwarded = file($record, FILE_IGNORE_NEW_LINES);
            self::assertContains('--no-browser', $forwarded);
            self::assertContains('--upgrade', $forwarded);
            self::assertSame($instance->root, $forwarded[array_search('--instance', $forwarded, true) + 1]);
        }
    }

    public function test_verified_restore_rebuilds_portable_markers_without_changing_restored_data(): void
    {
        $instance = new Instance($this->directory.'/restored desktop');
        $instance->prepare();
        $release = $this->program('restored-release');
        file_put_contents($release.'/runtime/bin/php', '#!'.PHP_BINARY."\n". <<<'PHP'
<?php
file_put_contents(getenv('YANJI_ENV_PATH').'/run/readiness-arguments', json_encode($argv));
exit(in_array('yanji:ready', $argv, true) && in_array('--portable', $argv, true) ? 0 : 2);
PHP);
        chmod($release.'/runtime/bin/php', 0700);
        $instance->activate($release);
        Instance::atomicFile($instance->root.'/instance.json', '{"port":8133}');
        $environment = "YANJI_DESKTOP_MODE=true\nAPP_KEY=sk-test-restored-fixture\n";
        Instance::atomicFile($instance->root.'/.env', $environment);
        Instance::atomicFile($instance->root.'/database/database.sqlite', 'restored database fixture');
        self::assertFileDoesNotExist($instance->root.'/.portable-layout');

        (new Installation($instance))->finishRestore();

        self::assertFileExists($instance->root.'/.portable-layout', 'A verified restore must regain the marker required by local browser authorization.');
        self::assertFileExists($instance->root.'/.portable-initialized');
        self::assertSame("yanji-portable-v1\n", file_get_contents($instance->root.'/.portable-layout'));
        self::assertSame("verified\n", file_get_contents($instance->root.'/.portable-initialized'));
        self::assertTrue(Installation::desktopMode($instance));
        self::assertSame($environment, file_get_contents($instance->root.'/.env'));
        self::assertSame('restored database fixture', file_get_contents($instance->root.'/database/database.sqlite'));
        self::assertFileExists($instance->root.'/run/readiness-arguments');
    }

    public function test_failed_restore_readiness_never_enables_a_portable_desktop_instance(): void
    {
        $instance = new Instance($this->directory.'/invalid restored desktop');
        $instance->prepare();
        $release = $this->program('invalid-restored-release');
        file_put_contents($release.'/runtime/bin/php', "#!/bin/sh\nexit 7\n");
        $instance->activate($release);
        Instance::atomicFile($instance->root.'/instance.json', '{"port":8134}');
        $environment = "YANJI_DESKTOP_MODE=true\nAPP_KEY=sk-test-restored-fixture\n";
        Instance::atomicFile($instance->root.'/.env', $environment);
        try {
            (new Installation($instance))->finishRestore();
            self::fail('An unverified restored instance must not acquire portable markers.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('就绪检查', $error->getMessage());
        }
        self::assertFileDoesNotExist($instance->root.'/.portable-layout');
        self::assertFileDoesNotExist($instance->root.'/.portable-initialized');
        self::assertSame($environment, file_get_contents($instance->root.'/.env'));
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

    public function test_physical_destinations_cannot_overlap_a_private_instance_or_replace_a_source_tree(): void
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

    public function test_launcher_without_subcommand_prints_help_and_exits_zero(): void
    {
        $proc = new \Symfony\Component\Process\Process([
            PHP_BINARY,
            dirname(__DIR__, 2).'/portable/yanji.php',
            '--instance-default',
            $this->directory.'/instance',
        ]);
        $proc->run();
        self::assertSame(0, $proc->getExitCode());
        self::assertStringContainsString('yanji start | stop', $proc->getOutput());

        $unknown = new \Symfony\Component\Process\Process([
            PHP_BINARY,
            dirname(__DIR__, 2).'/portable/yanji.php',
            'unknown-cmd',
            '--instance-default',
            $this->directory.'/instance',
        ]);
        $unknown->run();
        self::assertSame(1, $unknown->getExitCode());
    }

    public function test_activating_a_release_clears_stale_package_and_service_bootstrap_caches(): void
    {
        $i = new Instance($this->directory.'/upgrade-cache');
        $i->prepare();
        $old = $this->program('old');
        $new = $this->program('new');
        $i->activate($old);
        foreach (['packages.php', 'services.php', 'config.php'] as $cache) {
            Instance::atomicFile($i->root.'/bootstrap-cache/'.$cache, "<?php return ['stale' => true];\n");
        }
        $i->activate($new);
        foreach (['packages.php', 'services.php', 'config.php'] as $cache) {
            self::assertFileDoesNotExist($i->root.'/bootstrap-cache/'.$cache);
        }
    }

    public function test_publish_moves_readonly_staging_directory_and_preserves_readonly_mode(): void
    {
        $releases = $this->directory.'/releases';
        mkdir($releases, 0700, true);
        $stage = $releases.'/.incoming.first';
        $secondStage = $releases.'/.incoming.second';
        $target = $releases.'/published-digest';
        mkdir($stage.'/app', 0700, true);
        file_put_contents($stage.'/app/VERSION', "0.1.0\n");
        mkdir($secondStage.'/app', 0700, true);
        file_put_contents($secondStage.'/app/VERSION', "different\n");

        try {
            chmod($stage.'/app/VERSION', 0400);
            chmod($stage.'/app', 0500);
            chmod($stage, 0500);

            $proc = new \Symfony\Component\Process\Process([
                PHP_BINARY,
                '-n',
                dirname(__DIR__, 2).'/portable/publish.php',
                $stage,
                $target,
            ]);
            $proc->mustRun();

            self::assertDirectoryDoesNotExist($stage);
            self::assertDirectoryExists($target);
            self::assertSame(0500, fileperms($target) & 0777);
            self::assertSame(0400, fileperms($target.'/app/VERSION') & 0777);
            self::assertSame("0.1.0\n", file_get_contents($target.'/app/VERSION'));

            // Second publish for an existing target must succeed without replacing the published bytes.
            chmod($secondStage.'/app/VERSION', 0400);
            chmod($secondStage.'/app', 0500);
            chmod($secondStage, 0500);
            $second = new \Symfony\Component\Process\Process([
                PHP_BINARY,
                '-n',
                dirname(__DIR__, 2).'/portable/publish.php',
                $secondStage,
                $target,
            ]);
            $second->mustRun();
            self::assertSame("0.1.0\n", file_get_contents($target.'/app/VERSION'));
            self::assertDirectoryExists($secondStage);

            // Missing staging directory must fail closed with diagnostic detail.
            $missing = new \Symfony\Component\Process\Process([
                PHP_BINARY,
                '-n',
                dirname(__DIR__, 2).'/portable/publish.php',
                $releases.'/.incoming.nonexistent',
                $releases.'/never-created',
            ]);
            $missing->run();
            self::assertSame(1, $missing->getExitCode());
            self::assertStringContainsString('无法发布已核验的程序版本', $missing->getErrorOutput());
            self::assertStringContainsString('rename(', $missing->getErrorOutput());
            self::assertDirectoryDoesNotExist($releases.'/never-created');

            // Unix installer must invoke publish.php from preflight, never from inside the stage being renamed.
            $installer = file_get_contents(dirname(__DIR__, 2).'/scripts/install-local.sh');
            self::assertStringContainsString('"$scratch/preflight/runtime/bin/php" -n "$scratch/preflight/app/portable/publish.php" "$stage" "$release"', $installer);
            self::assertStringNotContainsString('"$stage/runtime/bin/php" -n "$stage/app/portable/publish.php"', $installer);
        } finally {
            foreach ([$stage, $secondStage, $target] as $dir) {
                if (is_dir($dir)) {
                    @chmod($dir, 0700);
                    if (is_dir($dir.'/app')) {
                        @chmod($dir.'/app', 0700);
                    }
                    if (is_file($dir.'/app/VERSION')) {
                        @chmod($dir.'/app/VERSION', 0600);
                    }
                }
            }
        }
    }
}
