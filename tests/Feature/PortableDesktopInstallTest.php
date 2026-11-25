<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PDO;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class PortableDesktopInstallTest extends TestCase
{
    private string $directory;
    private string $database;
    private string $envFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/yanji-desktop-install-'.bin2hex(random_bytes(12));
        foreach (['', '/database', '/run', '/storage/app', '/storage/framework/cache/data', '/storage/framework/views'] as $path) {
            File::makeDirectory($this->directory.$path, 0700, true, true);
        }
        $this->database = $this->directory.'/database/database.sqlite';
        $this->envFile = $this->directory.'/.env';
        touch($this->database);
        file_put_contents($this->envFile, "APP_URL=http://127.0.0.1:8123\nAPP_KEY=\nYANJI_DESKTOP_MODE=true\n");
        file_put_contents($this->directory.'/.portable-layout', "yanji-portable-v1\n");
        chmod($this->envFile, 0600);
        chmod($this->database, 0600);
        chmod($this->directory.'/.portable-layout', 0600);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function install(array $arguments = ['--portable', '--desktop'], array $overrides = []): Process
    {
        $environment = [
            'APP_ENV' => 'production', 'APP_URL' => 'http://127.0.0.1:8123', 'APP_KEY' => false,
            'YANJI_ENV_PATH' => $this->directory, 'YANJI_PORTABLE_INSTANCE' => 'true', 'YANJI_DESKTOP_MODE' => 'true',
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->database, 'DB_URL' => '',
            'QUEUE_CONNECTION' => 'sync', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'LARAVEL_STORAGE_PATH' => $this->directory.'/storage', 'LOG_CHANNEL' => 'stderr',
            'BCRYPT_ROUNDS' => '4',
        ];
        foreach (['SERVICES', 'PACKAGES', 'CONFIG', 'EVENTS', 'ROUTES'] as $kind) {
            $environment['APP_'.$kind.'_CACHE'] = $this->directory.'/'.strtolower($kind).'.php';
        }
        // Closed stdin is deliberate: the reader must not need to provide an email or password.
        $process = new Process([PHP_BINARY, 'artisan', 'yanji:install', ...$arguments, '--no-interaction'],
            base_path(), $overrides + $environment, '', 60);
        $process->run();
        return $process;
    }

    public function test_desktop_install_without_input_creates_one_private_workspace_and_is_ready(): void
    {
        $install = $this->install();
        $this->assertSame(0, $install->getExitCode(), 'Desktop initialization must work with closed stdin.');
        $db = new PDO('sqlite:'.$this->database);
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM tenants')->fetchColumn());
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM users u JOIN tenants t ON t.id = u.tenant_id WHERE u.role = \'platform_admin\' AND t.status = \'active\'')->fetchColumn());
        $this->assertSame(0, (int) $db->query('SELECT registration_enabled FROM instance_settings WHERE id = 1')->fetchColumn());
        $this->assertTrue((bool) preg_match('/^APP_KEY=base64:.+$/m', file_get_contents($this->envFile)), 'The local instance encryption key must be saved.');
        $output = $install->getOutput().$install->getErrorOutput();
        $this->assertStringNotContainsString('管理员邮箱', $output);
        $this->assertStringNotContainsString('管理员密码', $output);
        $this->assertStringNotContainsString('请在本实例登录页', $output);
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame(0600, fileperms($this->envFile) & 0777);
        }
    }

    public function test_repeated_desktop_initialization_preserves_key_account_and_workspace_data(): void
    {
        $this->assertSame(0, $this->install()->getExitCode(), 'The first desktop initialization must succeed.');
        $before = file_get_contents($this->envFile);
        $db = new PDO('sqlite:'.$this->database);
        $user = $db->query('SELECT id, tenant_id, email, role, password FROM users')->fetch(PDO::FETCH_ASSOC);
        $db->exec("UPDATE tenants SET name = 'Preserved desktop workspace'");
        $this->assertNotSame(0, $this->install()->getExitCode(), 'Reinitialization must not create a second account.');
        $this->assertTrue($before === file_get_contents($this->envFile), 'Reinitialization changed the saved environment or encryption key.');
        $this->assertTrue($user === $db->query('SELECT id, tenant_id, email, role, password FROM users')->fetch(PDO::FETCH_ASSOC), 'Reinitialization changed the existing local identity.');
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM tenants')->fetchColumn());
        $this->assertSame('Preserved desktop workspace', $db->query('SELECT name FROM tenants')->fetchColumn());
    }

    public function test_install_without_desktop_option_still_requires_account_credentials(): void
    {
        $before = file_get_contents($this->envFile);
        $install = $this->install([], ['YANJI_PORTABLE_INSTANCE' => 'false', 'YANJI_DESKTOP_MODE' => 'false']);
        $this->assertNotSame(0, $install->getExitCode(), 'Server initialization without credentials must fail.');
        $this->assertTrue($before === file_get_contents($this->envFile), 'A failed server initialization must not generate a key.');
        clearstatcache(true, $this->database);
        $this->assertSame(0, filesize($this->database));
    }

    public function test_desktop_option_cannot_enable_passwordless_initialization_on_a_server(): void
    {
        $before = file_get_contents($this->envFile);
        foreach ([
            ['YANJI_PORTABLE_INSTANCE' => 'false'],
            ['YANJI_DESKTOP_MODE' => 'false'],
            ['APP_URL' => 'https://example.com'],
        ] as $overrides) {
            $this->assertNotSame(0, $this->install(['--portable', '--desktop'], $overrides)->getExitCode(), 'Desktop initialization requires an explicit local portable instance.');
            $this->assertTrue($before === file_get_contents($this->envFile), 'Rejected desktop initialization changed the saved environment.');
            clearstatcache(true, $this->database);
            $this->assertSame(0, filesize($this->database));
        }
        unlink($this->directory.'/.portable-layout');
        $this->assertNotSame(0, $this->install()->getExitCode(), 'An arbitrary local web deployment must not be treated as a managed portable instance.');
        $this->assertTrue($before === file_get_contents($this->envFile), 'An unmanaged instance must not receive an encryption key.');
    }
}
