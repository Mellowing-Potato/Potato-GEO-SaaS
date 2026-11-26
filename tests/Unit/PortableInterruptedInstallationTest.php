<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Yanji\Portable\Instance;

require_once __DIR__.'/../../portable/src/Instance.php';
require_once __DIR__.'/../../portable/src/Installation.php';

class PortableInterruptedInstallationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/yanji-interrupted-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->directory = realpath($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
    }

    private function databaseEnvironment(string $environment): string
    {
        return $environment.'DB_CONNECTION=sqlite'."\n".'DB_DATABASE="'
            .$this->directory.'/interrupted instance/database/database.sqlite'.'"'."\n";
    }

    private function fixture(string $environment, ?string $database = ''): array
    {
        $instance = new Instance($this->directory.'/interrupted instance');
        $instance->prepare();
        Instance::atomicFile($instance->root.'/.env', $environment);
        Instance::atomicFile($instance->root.'/instance.json', '{"port":8132}');
        if ($database !== null) {
            Instance::atomicFile($instance->root.'/database/database.sqlite', $database);
        }
        $release = $this->directory.'/new-release';
        foreach (['app/public', 'app/portable', 'runtime/bin', 'runtime/ca'] as $directory) {
            mkdir($release.'/'.$directory, 0700, true);
        }
        touch($release.'/app/artisan');
        touch($release.'/app/public/index.php');
        touch($release.'/runtime/ca/cacert.pem');
        file_put_contents($release.'/app/portable/yanji', "#!/bin/sh\n");
        // Recovery must preserve the original environment even if the new template is absent.
        $fakePhp = <<<'PHP'
<?php
$root = getenv('YANJI_ENV_PATH');
file_put_contents($root.'/run/commands', json_encode(array_slice($argv, 1))."\n", FILE_APPEND);
if (in_array('yanji:install', $argv, true)) {
    file_put_contents($root.'/run/installed', 'verified');
    exit(0);
}
exit(in_array('yanji:ready', $argv, true) && is_file($root.'/run/installed') ? 0 : 1);
PHP;
        file_put_contents($release.'/runtime/bin/php', '#!'.PHP_BINARY."\n".$fakePhp);
        chmod($release.'/runtime/bin/php', 0700);
        $python = $this->directory.'/python';
        file_put_contents($python, "#!/bin/sh\nprintf '%s' '{\"basic\":true}'\n");
        chmod($python, 0700);
        return [$instance, $release, $python];
    }

    private function install(array $fixture, ?string $email = null, ?string $passwordFile = null): Process
    {
        [$instance, $release, $python] = $fixture;
        $driver = <<<'PHP'
require $argv[1].'/portable/src/Instance.php';
require $argv[1].'/portable/src/Installation.php';
$options = json_decode($argv[5], true, flags: JSON_THROW_ON_ERROR);
try {
    (new Yanji\Portable\Installation(new Yanji\Portable\Instance($argv[2])))
        ->install($argv[3], 8132, $options[0], $options[1], $argv[4]);
} catch (RuntimeException $error) {
    fwrite(STDERR, $error->getMessage());
    exit(9);
}
PHP;
        $process = new Process([PHP_BINARY, '-r', $driver, dirname(__DIR__, 2), $instance->root,
            $release, $python, json_encode([$email, $passwordFile], JSON_THROW_ON_ERROR)], null, null, '', 30);
        $process->run();
        return $process;
    }

    private function installCalls(Instance $instance): array
    {
        $path = $instance->root.'/run/commands';
        $calls = is_file($path) ? array_map(static fn (string $line): array => json_decode($line, true,
            flags: JSON_THROW_ON_ERROR), file($path, FILE_IGNORE_NEW_LINES)) : [];
        return array_values(array_filter($calls, static fn (array $args): bool => in_array('yanji:install', $args, true)));
    }

    public static function unusedInstances(): array
    {
        return [
            'empty database' => ['', "\n", true],
            'missing database' => [null, "\n", true],
            'CRLF environment' => ['', "\r\n", true],
            'no final newline' => ['', "\n", false],
            'single quoted database path' => ['', "\n", true, "'"],
            'empty database URL' => ['', "\n", true, '"', ''],
            'quoted empty database URL' => ['', "\n", true, '"', '""'],
        ];
    }

    #[DataProvider('unusedInstances')]
    public function test_empty_interrupted_install_resumes_the_desktop_default_without_account_input(
        ?string $database, string $newline, bool $finalNewline, string $quote = '"', ?string $databaseUrl = null
    ): void {
        $environment = implode($newline, ['# Retain the original settings.', 'APP_KEY=',
            'APP_URL=http://127.0.0.1:8132', 'DB_CONNECTION=sqlite',
            'DB_DATABASE='.$quote.$this->directory.'/interrupted instance/database/database.sqlite'.$quote,
            'GEO_VISIBILITY_PANEL_ENABLED=false', 'CUSTOM_LABEL="中文 keep value"']);
        if ($databaseUrl !== null) {
            $environment .= $newline.'DB_URL='.$databaseUrl;
        }
        $environment .= $finalNewline ? $newline : '';
        $fixture = $this->fixture($environment, $database);
        [$instance] = $fixture;

        $process = $this->install($fixture);

        self::assertSame(0, $process->getExitCode(), 'An untouched interrupted instance must resume without credentials or a terminal. '
            .$process->getErrorOutput());
        self::assertSame('', $process->getOutput().$process->getErrorOutput());
        self::assertSame($environment.($finalNewline ? '' : $newline).'YANJI_DESKTOP_MODE=true'.$newline,
            file_get_contents($instance->root.'/.env'));
        $calls = $this->installCalls($instance);
        self::assertCount(1, $calls);
        self::assertContains('--portable', $calls[0]);
        self::assertContains('--desktop', $calls[0]);
        self::assertContains('--no-interaction', $calls[0]);
        self::assertFalse((bool) array_filter($calls[0], static fn (string $argument): bool =>
            str_starts_with($argument, '--email') || str_starts_with($argument, '--password')));
        self::assertSame('', file_get_contents($instance->root.'/database/database.sqlite'));
        self::assertFileExists($instance->root.'/.portable-initialized');
    }

    public static function nonRecoverableInstances(): array
    {
        return [
            'existing key' => ["APP_KEY=sk-test-existing-fixture\n", '', null],
            'key without database' => ["APP_KEY=sk-test-existing-fixture\n", null, null],
            'database data' => ["APP_KEY=\n", 'existing database fixture', null],
            'initialized empty database' => ["APP_KEY=\n", '', '.portable-initialized'],
            'initialized missing database' => ["APP_KEY=\n", null, '.portable-initialized'],
            'initialized populated database' => ["APP_KEY=\n", 'existing database fixture', '.portable-initialized'],
            'missing key assignment' => ["CUSTOM_SETTING=keep\n", '', null],
            'duplicate empty keys' => ["APP_KEY=\nAPP_KEY=\n", '', null],
            'duplicate keyed and empty assignment' => ["APP_KEY=sk-test-existing-fixture\nAPP_KEY=\n", '', null],
            'duplicate unrelated setting' => ["APP_KEY=\nCUSTOM_SETTING=one\nCUSTOM_SETTING=two\n", '', null],
            'quoted empty key' => ["APP_KEY=\"\"\n", '', null],
            'key interpolation' => ["APP_KEY=\${OTHER_KEY}\n", '', null],
            'unrelated interpolation' => ["APP_KEY=\nCUSTOM_SETTING=\${OTHER_SETTING}\n", '', null],
            'multiline quoted setting' => ["APP_KEY=\nCUSTOM_SETTING=\"first\nsecond\"\n", '', null],
            'exported key' => ["export APP_KEY=\n", '', null],
            'explicit legacy mode' => ["APP_KEY=\nYANJI_DESKTOP_MODE=false\n", '', null],
            'instance identity' => ["APP_KEY=\nYANJI_INSTANCE_ID=sk-test-instance-fixture\n", '', null],
            'pending install identity' => ["APP_KEY=\nYANJI_INSTALL_PENDING=sk-test-pending-fixture\n", '', null],
            'database WAL data' => ["APP_KEY=\n", '', 'database/database.sqlite-wal'],
            'database SHM data' => ["APP_KEY=\n", '', 'database/database.sqlite-shm'],
            'database journal data' => ["APP_KEY=\n", '', 'database/database.sqlite-journal'],
            'blank instance identity' => ["APP_KEY=\nYANJI_INSTANCE_ID=\n", '', null],
            'blank pending identity' => ["APP_KEY=\nYANJI_INSTALL_PENDING=\n", '', null],
        ];
    }

    #[DataProvider('nonRecoverableInstances')]
    public function test_existing_or_ambiguous_instance_is_never_switched_to_the_desktop_default(
        string $environment, ?string $database, ?string $marker
    ): void {
        $environment = $this->databaseEnvironment($environment);
        $fixture = $this->fixture($environment, $database);
        [$instance] = $fixture;
        if ($marker !== null) {
            Instance::atomicFile($instance->root.'/'.$marker, 'existing fixture data');
        }

        $process = $this->install($fixture);

        self::assertSame(9, $process->getExitCode());
        self::assertSame($environment, file_get_contents($instance->root.'/.env'));
        self::assertSame([], $this->installCalls($instance));
        if ($database === null) {
            self::assertFileDoesNotExist($instance->root.'/database/database.sqlite');
        } else {
            self::assertSame($database, file_get_contents($instance->root.'/database/database.sqlite'));
        }
        if ($marker !== null) {
            self::assertSame('existing fixture data', file_get_contents($instance->root.'/'.$marker));
        }
        if ($marker === '.portable-initialized' && ($database === '' || $database === null)) {
            self::assertStringContainsString('已初始化实例缺数据库或环境文件', $process->getErrorOutput());
        }
        if ($database === null && str_contains($environment, 'sk-test-existing-fixture')) {
            self::assertStringContainsString('原环境密钥仍在，但数据库缺失', $process->getErrorOutput());
        }
    }

    public static function nonDefaultDatabaseConfigurations(): array
    {
        return [
            'external database' => ['DB_CONNECTION=sqlite'."\n".'DB_DATABASE="@other@"'."\n"],
            'non sqlite connection' => ['DB_CONNECTION=pgsql'."\n".'DB_DATABASE="@default@"'."\n"],
            'missing connection' => ['DB_DATABASE="@default@"'."\n"],
            'missing database path' => ["DB_CONNECTION=sqlite\n"],
            'empty database path' => ["DB_CONNECTION=sqlite\nDB_DATABASE=\n"],
            'relative database path' => ["DB_CONNECTION=sqlite\nDB_DATABASE=database/database.sqlite\n"],
            'memory database' => ["DB_CONNECTION=sqlite\nDB_DATABASE=:memory:\n"],
            'database URL' => ['DB_CONNECTION=sqlite'."\n".'DB_DATABASE="@default@"'."\nDB_URL=sqlite:example.sqlite\n"],
            'quoted database URL' => ['DB_CONNECTION=sqlite'."\n".'DB_DATABASE="@default@"'."\nDB_URL=\"sqlite:example.sqlite\"\n"],
        ];
    }

    #[DataProvider('nonDefaultDatabaseConfigurations')]
    public function test_only_the_original_default_sqlite_database_configuration_can_resume(string $configuration): void
    {
        $other = $this->directory.'/other.sqlite';
        Instance::atomicFile($other, 'other database fixture');
        $environment = "APP_KEY=\n".strtr($configuration, [
            '@default@' => $this->directory.'/interrupted instance/database/database.sqlite', '@other@' => $other,
        ]);
        $fixture = $this->fixture($environment);
        [$instance] = $fixture;

        $process = $this->install($fixture);

        self::assertSame(9, $process->getExitCode());
        self::assertSame($environment, file_get_contents($instance->root.'/.env'));
        self::assertSame('', file_get_contents($instance->root.'/database/database.sqlite'));
        self::assertSame('other database fixture', file_get_contents($other));
        self::assertSame([], $this->installCalls($instance));
        self::assertFileDoesNotExist($instance->root.'/.portable-initialized');
    }

    public static function explicitAccounts(): array
    {
        return ['email and password' => ['admin@example.com'], 'password file only' => [null]];
    }

    #[DataProvider('explicitAccounts')]
    public function test_explicit_account_parameters_keep_the_existing_legacy_installation_path(?string $email): void
    {
        $environment = $this->databaseEnvironment("APP_KEY=\nCUSTOM_SETTING=keep\n");
        $fixture = $this->fixture($environment);
        [$instance] = $fixture;
        $password = $this->directory.'/password';
        Instance::atomicFile($password, "test-retry-password\n");

        $process = $this->install($fixture, $email, $password);

        self::assertSame(0, $process->getExitCode());
        self::assertSame($environment, file_get_contents($instance->root.'/.env'));
        $calls = $this->installCalls($instance);
        self::assertCount(1, $calls);
        if ($email !== null) {
            self::assertContains('--email=admin@example.com', $calls[0]);
        } else {
            self::assertFalse((bool) array_filter($calls[0], static fn (string $argument): bool => str_starts_with($argument, '--email')));
        }
        self::assertContains('--password-stdin', $calls[0]);
        self::assertNotContains('--desktop', $calls[0]);
        self::assertContains('--no-interaction', $calls[0]);
    }

    public function test_explicit_email_alone_does_not_silently_change_the_authentication_mode(): void
    {
        $environment = $this->databaseEnvironment("APP_KEY=\n");
        $fixture = $this->fixture($environment);
        [$instance] = $fixture;

        $process = $this->install($fixture, 'admin@example.com');

        self::assertSame(9, $process->getExitCode());
        self::assertStringContainsString('交互终端', $process->getErrorOutput());
        self::assertSame($environment, file_get_contents($instance->root.'/.env'));
        self::assertSame([], $this->installCalls($instance));
    }

    public function test_ready_existing_account_instance_remains_unchanged_without_credentials(): void
    {
        $environment = $this->databaseEnvironment("APP_KEY=sk-test-existing-fixture\nCUSTOM_SETTING=keep\n");
        $fixture = $this->fixture($environment, 'existing database fixture');
        [$instance] = $fixture;
        Instance::atomicFile($instance->root.'/.portable-initialized', "verified\n");
        Instance::atomicFile($instance->root.'/run/installed', 'verified');

        $process = $this->install($fixture);

        self::assertSame(0, $process->getExitCode());
        self::assertSame($environment, file_get_contents($instance->root.'/.env'));
        self::assertSame('existing database fixture', file_get_contents($instance->root.'/database/database.sqlite'));
        self::assertSame([], $this->installCalls($instance));
    }

    public function test_state_is_checked_again_before_appending_the_desktop_default(): void
    {
        $fixture = $this->fixture($this->databaseEnvironment("APP_KEY=\n"));
        [$instance, , $python] = $fixture;
        $changed = $this->databaseEnvironment("APP_KEY=sk-test-concurrent-fixture\n");
        file_put_contents($python, '#!'.PHP_BINARY."\n<?php\nfile_put_contents("
            .var_export($instance->root.'/.env', true).', '.var_export($changed, true).");\n"
            ."echo '{\"basic\":true}';\n");

        $process = $this->install($fixture);

        self::assertSame(9, $process->getExitCode());
        self::assertStringContainsString('实例状态已改变', $process->getErrorOutput());
        self::assertSame($changed, file_get_contents($instance->root.'/.env'));
        self::assertSame('', file_get_contents($instance->root.'/database/database.sqlite'));
        self::assertSame([], $this->installCalls($instance));
        self::assertFileDoesNotExist($instance->root.'/.portable-initialized');
    }
}
