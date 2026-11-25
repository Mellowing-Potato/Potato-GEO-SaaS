<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PDO;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class LocalInstallPromptTest extends TestCase
{
    private string $directory;
    private string $database;
    private string $envFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/yanji-install-prompts-'.bin2hex(random_bytes(12));
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

    private function prompt(string $answers, array $options = [], bool $interactive = true): Process
    {
        $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$input = new Symfony\Component\Console\Input\ArrayInput(['command' => 'yanji:install'] + json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR));
$input->setInteractive($argv[2] === 'interactive');
$input->setStream(fopen('php://stdin', 'r'));
exit($kernel->handle($input, new Symfony\Component\Console\Output\NullOutput));
PHP;
        $environment = [
            'APP_ENV' => 'production', 'APP_URL' => 'http://localhost', 'APP_KEY' => false,
            'YANJI_ENV_PATH' => $this->directory, 'YANJI_PORTABLE_INSTANCE' => 'false', 'YANJI_DESKTOP_MODE' => 'false',
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->database, 'DB_URL' => '',
            'QUEUE_CONNECTION' => 'sync', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'LARAVEL_STORAGE_PATH' => $this->directory.'/storage', 'LOG_CHANNEL' => 'stderr', 'BCRYPT_ROUNDS' => '4',
        ];
        foreach (['SERVICES', 'PACKAGES', 'CONFIG', 'EVENTS', 'ROUTES'] as $kind) {
            $environment['APP_'.$kind.'_CACHE'] = $this->directory.'/'.strtolower($kind).'.php';
        }
        $process = new Process([PHP_BINARY, '-r', $script, json_encode($options, JSON_THROW_ON_ERROR), $interactive ? 'interactive' : 'noninteractive'],
            base_path(), $environment, $answers, 20);
        $process->run();
        return $process;
    }

    private function assertAccountCreated(string $password): void
    {
        $db = new PDO('sqlite:'.$this->database);
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $user = $db->query('SELECT email, password, role FROM users')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('admin@example.com', $user['email']);
        $this->assertSame('platform_admin', $user['role']);
        $this->assertTrue(password_verify($password, $user['password']), 'The final valid password must be saved as a hash.');
        $this->assertTrue((bool) preg_match('/^APP_KEY=base64:.+$/m', file_get_contents($this->envFile)), 'Successful installation must save its local key.');
    }

    public function test_invalid_email_can_be_corrected_without_restarting_installation(): void
    {
        $password = 'test-prompt-email-password';
        $install = $this->prompt("invalid-email\nadmin@example.com\n".$password."\n");
        $this->assertSame(0, $install->getExitCode(), 'A corrected email must complete the same initialization.');
        $this->assertAccountCreated($password);
        $this->assertStringNotContainsString($password, $install->getOutput().$install->getErrorOutput());
    }

    public function test_a_short_password_can_be_corrected_without_restarting_installation(): void
    {
        $password = 'test-prompt-valid-password';
        $install = $this->prompt("test\n".$password."\n", ['--email' => 'admin@example.com']);
        $this->assertSame(0, $install->getExitCode(), 'A corrected password must complete the same initialization.');
        $this->assertAccountCreated($password);
        $this->assertStringNotContainsString($password, $install->getOutput().$install->getErrorOutput());
    }

    public function test_exhausted_input_and_five_invalid_attempts_do_not_initialize_an_instance(): void
    {
        $before = file_get_contents($this->envFile);
        foreach (["invalid-email\n", str_repeat("invalid-email\n", 5)."test-exhausted-password\n",
            "admin@example.com\n".str_repeat("test\n", 5)] as $answers) {
            $this->assertNotSame(0, $this->prompt($answers)->getExitCode(), 'Invalid or exhausted answers must fail without creating an account.');
            $this->assertTrue($before === file_get_contents($this->envFile), 'Failed prompts must not generate an instance key.');
            $db = new PDO('sqlite:'.$this->database);
            $this->assertSame(0, (int) $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn());
        }
    }

    public function test_noninteractive_invalid_credentials_fail_without_prompting_or_saving_a_key(): void
    {
        $before = file_get_contents($this->envFile);
        $install = $this->prompt('', ['--email' => 'admin@example.com', '--password' => 'test', '--no-interaction' => true], false);
        $this->assertNotSame(0, $install->getExitCode());
        $this->assertTrue($before === file_get_contents($this->envFile), 'A noninteractive validation failure must not save a key.');
        clearstatcache(true, $this->database);
        $this->assertSame(0, filesize($this->database));
    }
}
