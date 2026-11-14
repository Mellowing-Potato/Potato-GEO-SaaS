<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LocalInstallTest extends TestCase
{
    public function test_fresh_install_creates_admin_and_repeat_preserves_key_account_and_data(): void
    {
        $directory = storage_path('framework/testing/install-'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($directory);
        touch($directory.'/database.sqlite');
        file_put_contents($directory.'/.env', "APP_URL=http://localhost\nAPP_KEY=\n");
        $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->useEnvironmentPath($argv[1]);
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$input = new Symfony\Component\Console\Input\ArrayInput([
    'command' => 'yanji:install', '--email' => 'install@example.com', '--password' => 'test-install-password',
]);
exit($kernel->handle($input, new Symfony\Component\Console\Output\NullOutput));
PHP;
        $env = ['APP_ENV' => 'production', 'APP_URL' => 'http://localhost', 'APP_KEY' => false,
            'APP_RUNNING_IN_CONSOLE' => 'true', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $directory.'/database.sqlite',
            'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'];
        try {
            $first = new Process([PHP_BINARY, '-r', $script, $directory], base_path(), $env);
            $first->setTimeout(120)->run();
            $this->assertSame(0, $first->getExitCode(), 'Fresh installer failed.');
            $before = file_get_contents($directory.'/.env');
            $this->assertTrue((bool) preg_match('/^APP_KEY=base64:.+$/m', $before), 'APP_KEY was not saved.');
            $db = new \PDO('sqlite:'.$directory.'/database.sqlite');
            $user = $db->query('SELECT id, tenant_id, role, password FROM users')->fetch(\PDO::FETCH_ASSOC);
            $this->assertSame('platform_admin', $user['role']);
            $this->assertTrue(password_verify('test-install-password', $user['password']));
            $db->exec("UPDATE tenants SET name = 'Preserved workspace'");
            $second = new Process([PHP_BINARY, '-r', $script, $directory], base_path(), $env);
            $second->setTimeout(120)->run();
            $this->assertSame(1, $second->getExitCode(), 'Repeated installer must refuse another admin.');
            $this->assertTrue($before === file_get_contents($directory.'/.env'), 'Existing APP_KEY changed.');
            $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn());
            $this->assertSame('Preserved workspace', $db->query('SELECT name FROM tenants')->fetchColumn());
            $this->assertTrue($user === $db->query('SELECT id, tenant_id, role, password FROM users')->fetch(\PDO::FETCH_ASSOC), 'Existing account changed.');
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_required_url_and_optional_agreements_are_explicit(): void
    {
        config(['app.url' => '']);
        try {
            \App\Support\InstanceConfiguration::validate();
            $this->fail('Missing APP_URL must fail.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('APP_URL', $error->getMessage());
        }
        config(['app.url' => 'https://instance.example.test', 'instance.terms_url' => '', 'instance.privacy_url' => '']);
        $this->assertFalse(\App\Support\InstanceConfiguration::agreementsRequired());
        $this->assertSame('YanjiBot/1.0 (+https://instance.example.test)', \App\Support\InstanceConfiguration::userAgent());
        config(['instance.terms_url' => 'https://example.com/terms']);
        $this->assertTrue(\App\Support\InstanceConfiguration::agreementsRequired());
    }
}
