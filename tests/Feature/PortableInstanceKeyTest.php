<?php

namespace Tests\Feature;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\File;
use PDO;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PortableInstanceKeyTest extends TestCase
{
    private string $directory;
    private string $database;
    private string $envFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/yanji-instance-test-'.bin2hex(random_bytes(12));
        File::makeDirectory($this->directory.'/storage/app', 0700, true);
        $this->database = $this->directory.'/database.sqlite';
        $this->envFile = $this->directory.'/.env';
        touch($this->database);
        file_put_contents($this->envFile, "APP_URL=http://localhost\nAPP_KEY=\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function command(array $arguments, ?string $input = null, array $overrides = []): Process
    {
        return $this->execute(['artisan', ...$arguments, '--no-interaction'], $input, $overrides);
    }

    private function execute(array $arguments, ?string $input = null, array $overrides = []): Process
    {
        $env = ['APP_ENV' => 'production', 'YANJI_ENV_PATH' => $this->directory, 'APP_URL' => 'http://localhost',
            'APP_KEY' => false, 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->database, 'DB_URL' => '',
            'QUEUE_CONNECTION' => 'sync', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'LARAVEL_STORAGE_PATH' => $this->directory.'/storage', 'LOG_CHANNEL' => 'stderr'];
        foreach (['SERVICES', 'PACKAGES', 'CONFIG', 'EVENTS', 'ROUTES'] as $kind) {
            $env['APP_'.$kind.'_CACHE'] = $this->directory.'/'.strtolower($kind).'.php';
        }
        $process = new Process([PHP_BINARY, ...$arguments], base_path(), $overrides + $env, $input, 60);
        $process->run();
        return $process;
    }

    private function install(bool $strict = true): Process
    {
        return $this->command(['yanji:install', '--email=admin@example.com', '--password-stdin', ...($strict ? ['--portable'] : [])],
            "test-first-password\n");
    }

    public function test_bound_instance_rejects_missing_changed_or_overridden_key_without_modifying_accounts_or_env(): void
    {
        $this->assertSame(0, $this->install()->getExitCode());
        $saved = file_get_contents($this->envFile);
        $db = new PDO('sqlite:'.$this->database);
        $password = $db->query('select password from users')->fetchColumn();
        $this->assertTrue(password_verify('test-first-password', $password));
        $this->assertSame(0, $this->command(['yanji:ready', '--portable'])->getExitCode());
        $otherKey = 'base64:'.base64_encode(random_bytes(32));
        $this->assertNotSame(0, $this->command(['yanji:ready', '--portable'], null, ['APP_KEY' => $otherKey])->getExitCode());
        foreach (['', $otherKey] as $value) {
            $altered = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY='.$value, $saved);
            file_put_contents($this->envFile, $altered);
            foreach ([['yanji:ready'], ['yanji:ready', '--portable'], ['yanji:reset-password', 'admin@example.com', '--portable', '--password-stdin']] as $args) {
                $this->assertNotSame(0, $this->command($args, "test-new-password\n")->getExitCode());
            }
            $this->assertNotSame(0, $this->install()->getExitCode());
            $this->assertSame($altered, file_get_contents($this->envFile));
            $this->assertSame($password, $db->query('select password from users')->fetchColumn());
        }
        file_put_contents($this->envFile, $saved);
        $reset = $this->command(['yanji:reset-password', 'admin@example.com', '--portable', '--password-stdin'], "test-new-password\r\n");
        $this->assertSame(0, $reset->getExitCode());
        $this->assertStringNotContainsString('test-new-password', $reset->getOutput().$reset->getErrorOutput());
        $this->assertTrue(password_verify('test-new-password', $db->query('select password from users')->fetchColumn()));
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame(0600, fileperms($this->envFile) & 0777);
        }
    }

    public function test_legacy_docker_readiness_and_strict_portable_proof_have_distinct_requirements(): void
    {
        $this->assertSame(0, $this->install(false)->getExitCode());
        $db = new PDO('sqlite:'.$this->database);
        $db->exec('update instance_settings set key_verification = null');
        file_put_contents($this->envFile, preg_replace('/^YANJI_INSTANCE_ID=.*\n/m', '', file_get_contents($this->envFile)));
        $this->assertSame(0, $this->command(['yanji:ready'])->getExitCode());
        $this->assertNotSame(0, $this->command(['yanji:ready', '--portable'])->getExitCode());
        $matched = preg_match('/^APP_KEY=base64:(.+)$/m', file_get_contents($this->envFile), $key);
        $this->assertSame(1, $matched);
        $cipher = new Encrypter(base64_decode($key[1], true), 'AES-256-CBC');
        $db->prepare("insert into tenant_ai_keys (tenant_id, provider, slot, encrypted_key) values ((select tenant_id from users), 'test', 'llm', ?)")->execute([$cipher->encryptString('sk-test-legacy')]);
        $this->assertSame(0, $this->command(['yanji:ready', '--portable'])->getExitCode());
        file_put_contents($this->envFile, 'APP_KEY=base64:'.base64_encode(random_bytes(32))."\n");
        foreach ([['yanji:ready'], ['yanji:ready', '--portable']] as $args) {
            $this->assertNotSame(0, $this->command($args)->getExitCode());
        }
        $this->assertNull($db->query('select key_verification from instance_settings')->fetchColumn());
    }

    public function test_invalid_password_input_does_not_initialize_even_a_new_instance(): void
    {
        $original = file_get_contents($this->envFile);
        foreach (["short\n", "test-first-password\nsecond-line\n", ''] as $input) {
            $cmd = $this->command(['yanji:install', '--email=admin@example.com', '--password-stdin', '--portable'], $input);
            $this->assertNotSame(0, $cmd->getExitCode());
            $this->assertSame($original, file_get_contents($this->envFile));
        }
        $this->assertSame(0, filesize($this->database));
    }

    public function test_encrypted_replay_can_prove_a_legacy_key_but_plain_json_cannot(): void
    {
        $this->assertSame(0, $this->install(false)->getExitCode());
        $db = new PDO('sqlite:'.$this->database);
        $db->exec('update instance_settings set key_verification = null');
        $legacyEnv = preg_replace('/^YANJI_INSTANCE_ID=.*\n/m', '', file_get_contents($this->envFile));
        file_put_contents($this->envFile, $legacyEnv);
        $this->assertSame(1, preg_match('/^APP_KEY=base64:(.+)$/m', $legacyEnv, $key));
        $cipher = new Encrypter(base64_decode($key[1], true), 'AES-256-CBC');
        $db->prepare('insert into api_idempotency_keys (idempotency_key, route_key, request_hash, response_body) values (?, ?, ?, ?)')
            ->execute(['test-legacy-replay', 'api/v1/test-draft/start', 'hash', '{"message":"legacy"}']);
        $this->assertNotSame(0, $this->command(['yanji:ready', '--portable'])->getExitCode());
        $db->prepare('update api_idempotency_keys set response_body = ?')
            ->execute([$cipher->encryptString('{"token":"sk-test-replay"}')]);
        $this->assertSame(0, $this->command(['yanji:ready', '--portable'])->getExitCode());
        file_put_contents($this->envFile, 'APP_KEY=base64:'.base64_encode(random_bytes(32))."\n");
        foreach ([['yanji:ready'], ['yanji:ready', '--portable']] as $args) {
            $this->assertNotSame(0, $this->command($args)->getExitCode());
        }
        $this->assertNull($db->query('select key_verification from instance_settings')->fetchColumn());
    }

    public function test_legacy_install_with_an_existing_account_and_missing_key_preserves_the_environment(): void
    {
        $db = new PDO('sqlite:'.$this->database);
        $db->exec("create table users (id integer, role text)");
        $db->exec("insert into users values (1, 'admin')");
        $original = file_get_contents($this->envFile);
        $cmd = $this->command(['yanji:install', '--email=admin@example.com', '--password=test-existing-password']);
        $this->assertNotSame(0, $cmd->getExitCode());
        $this->assertSame($original, file_get_contents($this->envFile));
        $this->assertSame(['users'], $db->query("select name from sqlite_master where type='table'")->fetchAll(PDO::FETCH_COLUMN));
    }

    public function test_same_encryption_key_does_not_accept_another_instance_id_and_duplicates_are_rejected(): void
    {
        $this->assertSame(0, $this->install()->getExitCode());
        $saved = file_get_contents($this->envFile);
        foreach ([preg_replace('/^YANJI_INSTANCE_ID=.*$/m', 'YANJI_INSTANCE_ID='.bin2hex(random_bytes(16)), $saved),
            $saved."APP_KEY=\n", preg_replace('/^YANJI_INSTANCE_ID=.*$/m', '', $saved)] as $candidate) {
            file_put_contents($this->envFile, $candidate);
            $this->assertNotSame(0, $this->command(['yanji:ready'])->getExitCode());
            $this->assertSame($candidate, file_get_contents($this->envFile));
        }
    }

    public function test_cold_sqlite_file_is_created_privately_but_a_missing_bound_database_is_never_replaced(): void
    {
        unlink($this->database);
        $this->assertSame(0, $this->install()->getExitCode());
        $this->assertSame(0, $this->command(['yanji:ready', '--portable'])->getExitCode());
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame(0600, fileperms($this->database) & 0777);
        }
        $this->database = $this->directory.'/missing.sqlite';
        $this->assertNotSame(0, $this->install()->getExitCode());
        $this->assertFileDoesNotExist($this->database);
    }

    public function test_bound_environment_cannot_initialize_a_replacement_empty_database(): void
    {
        $this->assertSame(0, $this->install()->getExitCode());
        $saved = file_get_contents($this->envFile);
        $this->database = $this->directory.'/wrong.sqlite';
        touch($this->database);
        $this->assertNotSame(0, $this->install()->getExitCode());
        $this->assertSame($saved, file_get_contents($this->envFile));
        $this->assertSame(0, filesize($this->database));
    }

    public function test_interrupted_first_install_can_resume_only_with_its_original_database(): void
    {
        $setup = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
App\Support\InstanceKey::prepareInstall($app->environmentFilePath());
PHP;
        $this->assertSame(0, $this->execute(['-r', $setup])->getExitCode());
        $pending = file_get_contents($this->envFile);
        $this->assertStringContainsString('YANJI_INSTALL_PENDING=', $pending);
        $db = new PDO('sqlite:'.$this->database);
        $db->exec('create table migrations (id integer primary key, migration text, batch integer)');
        $db = null;
        $original = $this->database;
        $this->database = $this->directory.'/swapped.sqlite';
        copy($original, $this->database);
        $this->assertNotSame(0, $this->install()->getExitCode());
        $this->assertSame($pending, file_get_contents($this->envFile));
        $this->database = $original;
        $this->assertSame(0, $this->install()->getExitCode());
        $this->assertStringNotContainsString('YANJI_INSTALL_PENDING=', file_get_contents($this->envFile));
        $this->assertSame(0, $this->command(['yanji:ready', '--portable'])->getExitCode());
    }

    public function test_another_laravel_database_with_a_migrations_table_is_not_an_interrupted_install(): void
    {
        file_put_contents($this->envFile, 'APP_KEY=base64:'.base64_encode(random_bytes(32))."\n");
        $original = file_get_contents($this->envFile);
        $db = new PDO('sqlite:'.$this->database);
        $db->exec('create table migrations (id integer primary key, migration text, batch integer)');
        $db->exec('create table preserved (value text)');
        $before = hash_file('sha256', $this->database);
        $this->assertNotSame(0, $this->install()->getExitCode());
        $this->assertSame($original, file_get_contents($this->envFile));
        $this->assertSame($before, hash_file('sha256', $this->database));
    }

    public function test_unrecognized_existing_database_is_never_initialized(): void
    {
        $db = new PDO('sqlite:'.$this->database);
        $db->exec('create table preserved (value text)');
        $original = file_get_contents($this->envFile);
        $before = hash_file('sha256', $this->database);
        $this->assertNotSame(0, $this->install()->getExitCode());
        $this->assertSame($before, hash_file('sha256', $this->database), 'Checking a rejected database changed its SQLite file.');
        $this->assertSame($original, file_get_contents($this->envFile));
        $this->assertSame(['preserved'], $db->query("select name from sqlite_master where type = 'table'")->fetchAll(PDO::FETCH_COLUMN));
    }
}
