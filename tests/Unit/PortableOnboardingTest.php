<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Yanji\Portable\Instance;
use Yanji\Portable\Onboarding;

require_once __DIR__.'/../../portable/src/Instance.php';
require_once __DIR__.'/../../portable/src/Installation.php';
require_once __DIR__.'/../../portable/src/DesktopEntry.php';
require_once __DIR__.'/../../portable/src/Onboarding.php';

final class PortableOnboardingTest extends TestCase
{
    private string $directory;
    private Instance $instance;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/yanji-onboarding-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->instance = new Instance($this->directory.'/instance');
        $this->instance->prepare();
        Instance::atomicFile($this->instance->root.'/instance.json', '{"port":8137}');
        Instance::atomicFile($this->instance->root.'/'.(PHP_OS_FAMILY === 'Windows' ? 'yanji.cmd' : 'yanji'),
            PHP_OS_FAMILY === 'Windows' ? "@exit /b 0\r\n" : "#!/bin/sh\nexit 0\n", 0700);
        Instance::atomicFile($this->instance->root.'/.env', "YANJI_DESKTOP_MODE=true\nAPP_KEY=sk-test-private-fixture\n");
        $release = $this->directory.'/release';
        mkdir($release.'/runtime/bin', 0700, true);
        mkdir($release.'/app/public', 0700, true);
        touch($release.'/app/artisan');
        $php = $release.'/runtime/bin/'.(PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');
        touch($php);
        chmod($php, 0700);
        Instance::atomicFile($release.'/app/VERSION', "0.1.1\n");
        $this->instance->activate($release);
        Instance::atomicFile($this->instance->root.'/.portable-initialized', "verified\n");
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
    }

    private static function readyStatus(): array
    {
        return ['ready' => true, 'writes_enabled' => true, 'services' => [
            'web' => ['healthy' => true], 'worker' => ['healthy' => true], 'scheduler' => ['healthy' => true],
        ]];
    }

    public static function unavailableServices(): array
    {
        $ready = self::readyStatus();
        $noWorker = $ready;
        unset($noWorker['services']['worker']);
        $stringHealthy = $ready;
        $stringHealthy['services']['scheduler']['healthy'] = 'true';
        $stopped = $ready;
        $stopped['services']['web']['healthy'] = false;

        return [
            'no manager' => [null],
            'missing roles' => [['ready' => true, 'writes_enabled' => true]],
            'string readiness' => [array_replace($ready, ['ready' => 'true'])],
            'upgrade hold' => [array_replace($ready, ['writes_enabled' => false])],
            'missing worker' => [$noWorker],
            'string heartbeat' => [$stringHealthy],
            'stopped web' => [$stopped],
        ];
    }

    #[DataProvider('unavailableServices')]
    public function test_readiness_requires_initialized_instance_and_every_healthy_role(?array $status): void
    {
        $record = Onboarding::report($this->instance, $status);
        self::assertFalse($record['services_ready']);
        self::assertSame('not_ready', $record['state']);
        self::assertTrue($record['browser_confirmation_required']);
    }

    public function test_even_running_services_do_not_replace_initialization_or_browser_confirmation(): void
    {
        unlink($this->instance->root.'/.portable-initialized');
        $record = Onboarding::report($this->instance, self::readyStatus());
        self::assertFalse($record['initialized']);
        self::assertFalse($record['services_ready']);
        self::assertSame('not_ready', $record['state']);
        self::assertTrue($record['browser_confirmation_required']);
    }

    public function test_missing_local_entry_requires_explicit_repair_without_granting_a_browser_session(): void
    {
        $before = scandir($this->instance->root);
        $record = Onboarding::report($this->instance, self::readyStatus());
        self::assertSame(1, $record['schema_version']);
        self::assertSame('desktop', $record['mode']);
        self::assertSame('0.1.1', $record['version']);
        self::assertTrue($record['services_ready']);
        self::assertSame('needs_entry_repair', $record['state']);
        self::assertNull($record['entry']['file']);
        self::assertSame('entry', $record['next_action']['command'][1]);
        self::assertTrue($record['browser_confirmation_required']);
        self::assertSame($before, scandir($this->instance->root), 'Status inspection must not create desktop files.');
        self::assertSame([], glob($this->instance->root.'/run/browser-*'));
    }

    public function test_account_mode_keeps_its_login_and_never_creates_a_passwordless_entry(): void
    {
        Instance::atomicFile($this->instance->root.'/.env', "APP_KEY=sk-test-private-fixture\n");
        $record = Onboarding::report($this->instance, self::readyStatus());
        self::assertSame('account', $record['mode']);
        self::assertNull($record['entry']);
        self::assertNull($record['next_action']['human_entry']);
        self::assertSame('open', $record['next_action']['command'][1]);
        self::assertSame('awaiting_browser_confirmation', $record['state']);
        self::assertTrue($record['browser_confirmation_required']);
    }

    public function test_a_valid_local_entry_and_ready_services_still_require_browser_confirmation(): void
    {
        $entry = \Yanji\Portable\DesktopEntry::install($this->instance, false);
        self::assertNotNull($entry['file']);
        $record = Onboarding::report($this->instance, self::readyStatus());
        self::assertTrue($record['services_ready']);
        self::assertSame('awaiting_browser_confirmation', $record['state']);
        self::assertSame('open', $record['next_action']['action']);
        self::assertSame('open', $record['next_action']['command'][1]);
        self::assertSame($entry['file'], $record['next_action']['human_entry']);
        self::assertTrue($record['browser_confirmation_required']);
        self::assertSame([], glob($this->instance->root.'/run/browser-*'));
    }

    public function test_missing_management_launcher_is_not_reported_as_an_actionable_open_command(): void
    {
        unlink($this->instance->root.'/'.(PHP_OS_FAMILY === 'Windows' ? 'yanji.cmd' : 'yanji'));
        $record = Onboarding::report($this->instance, self::readyStatus());
        self::assertTrue($record['services_ready']);
        self::assertSame('needs_installation_repair', $record['state']);
        self::assertSame('repair_installation', $record['next_action']['action']);
        self::assertSame([], $record['next_action']['command']);
        self::assertNull($record['entry']['file']);
    }

    public function test_credentials_and_arbitrary_manager_fields_never_enter_the_agent_contract(): void
    {
        $status = self::readyStatus() + ['token' => 'sk-test-manager-secret', 'ticket' => 'sk-test-launch-secret',
            'browser_url' => 'http://127.0.0.1:8137/local-start#ticket=sk-test-launch-secret'];
        $record = Onboarding::report($this->instance, $status);
        $json = json_encode($record, JSON_THROW_ON_ERROR);
        foreach (['sk-test-manager-secret', 'sk-test-launch-secret', 'sk-test-private-fixture',
            '#ticket=', 'browser_url', 'APP_KEY'] as $private) {
            self::assertStringNotContainsString($private, $json);
        }
        self::assertArrayNotHasKey('gui_verified', $record);
        self::assertArrayNotHasKey('workspace_opened', $record);
    }

    public function test_any_local_command_agent_can_read_stopped_installation_status_without_a_tty(): void
    {
        $before = scandir($this->instance->root);
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/portable/yanji.php', 'onboarding',
            '--instance', $this->instance->root], null, null, '', 30);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('', $process->getErrorOutput());
        $record = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $record['schema_version']);
        self::assertFalse($record['services_ready']);
        self::assertSame('not_ready', $record['state']);
        self::assertTrue($record['browser_confirmation_required']);
        self::assertSame($before, scandir($this->instance->root));
        self::assertSame([], glob($this->instance->root.'/run/browser-*'));
    }
}
