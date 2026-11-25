<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Yanji\Portable\DesktopEntry;
use Yanji\Portable\Instance;

require_once __DIR__.'/../../portable/src/DesktopEntry.php';

final class DesktopEntryTest extends TestCase
{
    private string $directory;
    private array $powerShellCalls = [];

    protected function setUp(): void
    {
        $directory = sys_get_temp_dir().'/yanji-entry-test-'.bin2hex(random_bytes(10));
        mkdir($directory, 0700);
        $this->directory = realpath($directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
    }

    private function instance(string $suffix = 'instance', int $port = 8123): Instance
    {
        $instance = new Instance($this->directory.'/'.$suffix);
        $instance->prepare();
        Instance::atomicFile($instance->root.'/instance.json', json_encode(['port' => $port]));
        Instance::atomicFile($instance->root.'/yanji', "#!/bin/sh\nexit 0\n", 0700);
        Instance::atomicFile($instance->root.'/yanji.cmd', "@echo off\r\nexit /b 0\r\n", 0700);
        return $instance;
    }

    private function folder(string $kind): string
    {
        return $this->directory.'/known folders/'.$kind;
    }

    private function fakePowerShell(string $script, array $variables): array
    {
        $this->powerShellCalls[] = [$script, $variables];
        if (str_contains($script, 'GetFolderPath')) {
            return ['code' => 0, 'stdout' => $this->folder(str_contains($script, 'DesktopDirectory') ? 'desktop' : 'start_menu')];
        }
        self::assertStringNotContainsString($this->directory, $script, 'Paths must be passed as data, never interpolated PowerShell code.');
        self::assertStringContainsString('CreateShortcut($env:YANJI_ENTRY_SHORTCUT)', $script);
        self::assertStringNotContainsString('Start-Process', $script);
        $path = PHP_OS_FAMILY === 'Windows' ? $variables['YANJI_ENTRY_SHORTCUT'] : str_replace('\\', '/', $variables['YANJI_ENTRY_SHORTCUT']);
        $record = ['target' => $variables['YANJI_ENTRY_TARGET'] ?? $variables['YANJI_ENTRY_FILE'],
            'arguments' => $variables['YANJI_ENTRY_ARGUMENTS'] ?? '',
            'directory' => $variables['YANJI_ENTRY_WORKING_DIRECTORY'] ?? $variables['YANJI_ENTRY_DIRECTORY'],
            'marker' => $variables['YANJI_ENTRY_MARKER']];
        // Native WSH persisted only 1023 argument characters, confirmed through the full property store.
        $readArguments = static fn (string $value): string => str_contains($script, "ExtendedProperty('System.Link.Arguments')")
            ? $value : substr($value, 0, 1023);
        if ($variables['YANJI_ENTRY_INSPECT'] === '1') {
            $stored = @json_decode((string) @file_get_contents($path), true);
            if (is_array($stored) && is_string($stored['arguments'] ?? null)) {
                $stored['arguments'] = $readArguments($stored['arguments']);
            }
            $legacy = ['target' => $variables['YANJI_ENTRY_FILE'], 'arguments' => '',
                'directory' => $variables['YANJI_ENTRY_DIRECTORY'], 'marker' => $variables['YANJI_ENTRY_MARKER']];
            return ['code' => $stored === $record || $stored === $legacy ? 0 : 3, 'stdout' => ''];
        }
        self::assertFileDoesNotExist($path);
        $saved = $record;
        $saved['arguments'] = substr($saved['arguments'], 0, 1023);
        file_put_contents($path, json_encode($saved, JSON_THROW_ON_ERROR));
        return ['code' => $readArguments($saved['arguments']) === $record['arguments'] ? 0 : 3, 'stdout' => ''];
    }

    private function install(Instance $instance, string $platform, bool $shortcuts = true): array
    {
        return DesktopEntry::install($instance, $shortcuts, $this->folder(...), $this->fakePowerShell(...), $platform);
    }

    public static function platforms(): array
    {
        return ['Mac' => ['Darwin', '.command', ['desktop']],
            'Windows' => ['Windows', '.cmd', ['desktop', 'start_menu']],
            'Linux' => ['Linux', '.desktop', ['applications']]];
    }

    #[DataProvider('platforms')]
    public function test_each_platform_has_a_stable_per_instance_entry_and_real_registered_files(
        string $platform, string $extension, array $kinds
    ): void {
        $instance = $this->instance("中文 it's %value% !name! \$cash & [workspace]");
        ob_start();
        try {
            $result = $this->install($instance, $platform);
        } finally {
            self::assertSame('', ob_get_clean());
        }
        self::assertSame(['name', 'file', 'shortcuts', 'warnings'], array_keys($result));
        self::assertSame([], $result['warnings']);
        self::assertSame('言及工作台-8123-'.substr(hash('sha256', $instance->root), 0, 8), $result['name']);
        self::assertSame($instance->root.'/'.$result['name'].$extension, $result['file']);
        self::assertFileExists($result['file']);
        self::assertSame($kinds, array_column($result['shortcuts'], 'kind'));
        foreach ($result['shortcuts'] as $shortcut) {
            self::assertFileExists($shortcut['path']);
            self::assertStringStartsWith($this->directory.'/', $shortcut['path']);
        }
        $contents = file_get_contents($result['file']);
        self::assertStringContainsString('open', $contents);
        foreach (['http://', 'https://', 'ticket=', 'APP_KEY', 'password', 'current/', 'current-release', '%*', '$@'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $contents);
        }
        self::assertSame($result, DesktopEntry::describe($instance, $platform));
        self::assertSame($result, $this->install($instance, $platform), 'Repeated registration must retain the same files without conflict.');
        self::assertSame([], glob($this->directory.'/known folders/*/.yanji-entry-*'));
    }

    #[DataProvider('platforms')]
    public function test_release_changes_keep_the_entry_and_other_instances_have_separate_names(
        string $platform, string $extension, array $kinds
    ): void {
        $first = $this->instance('first');
        $second = $this->instance('second');
        $original = $this->install($first, $platform);
        $contents = file_get_contents($original['file']);
        Instance::atomicFile($first->root.'/current-release.txt', 'new release fixture');
        self::assertSame($original, $this->install($first, $platform));
        $other = $this->install($second, $platform);
        self::assertNotSame($original['name'], $other['name']);
        self::assertNotSame($original['file'], $other['file']);
        self::assertSame($contents, file_get_contents($original['file']));
        self::assertCount(count($kinds), $other['shortcuts']);
    }

    #[DataProvider('platforms')]
    public function test_existing_foreign_canonical_and_shortcut_files_are_never_overwritten(
        string $platform, string $extension, array $kinds
    ): void {
        $instance = $this->instance();
        $canonical = $this->install($instance, $platform, false);
        $directory = $this->folder($kinds[0]);
        mkdir($directory, 0700, true);
        $shortcut = $directory.'/'.$canonical['name'].($platform === 'Windows' ? '.lnk' : $extension);
        Instance::atomicFile($shortcut, 'foreign shortcut fixture');
        $result = $this->install($instance, $platform);
        self::assertContains('shortcut_conflict', $result['warnings']);
        self::assertSame('foreign shortcut fixture', file_get_contents($shortcut));
        self::assertSame($canonical['file'], $result['file']);
        self::assertNotContains($shortcut, array_column($result['shortcuts'], 'path'));

        Instance::atomicFile($canonical['file'], 'foreign canonical fixture');
        $result = $this->install($instance, $platform);
        self::assertNull($result['file']);
        self::assertSame(['entry_conflict'], $result['warnings']);
        self::assertSame('foreign canonical fixture', file_get_contents($canonical['file']));
        self::assertNull(DesktopEntry::describe($instance, $platform)['file']);
    }

    public function test_registration_failure_preserves_the_canonical_entry_and_only_reports_controlled_codes(): void
    {
        $instance = $this->instance();
        $result = DesktopEntry::install($instance, true, static function (): never {
            throw new \RuntimeException('sk-test-do-not-echo-an-exception');
        }, platform: 'Darwin');
        self::assertFileExists($result['file']);
        self::assertSame([], $result['shortcuts']);
        self::assertSame(['shortcut_failed'], $result['warnings']);
        self::assertSame($result, DesktopEntry::describe($instance, 'Darwin'));
        self::assertStringNotContainsString('sk-test-', json_encode($result));
    }

    public function test_windows_shortcut_failure_does_not_claim_registration_or_run_the_browser(): void
    {
        $instance = $this->instance();
        $result = DesktopEntry::install($instance, true, $this->folder(...),
            static fn (): array => ['code' => 70, 'stdout' => 'sk-test-ignore-process-output'], 'Windows');
        self::assertFileExists($result['file']);
        self::assertSame([], $result['shortcuts']);
        self::assertSame(['shortcut_failed'], $result['warnings']);
        self::assertStringNotContainsString('sk-test-', json_encode($result));
        self::assertStringNotContainsString('call ', strtolower(file_get_contents($result['file'])));
    }

    public function test_windows_uses_actual_known_folders_instead_of_guessing_profile_subdirectories(): void
    {
        $instance = $this->instance();
        $result = DesktopEntry::install($instance, powerShell: $this->fakePowerShell(...), platform: 'Windows');
        self::assertSame([], $result['warnings']);
        self::assertCount(2, $result['shortcuts']);
        self::assertSame($this->folder('desktop'), dirname($result['shortcuts'][0]['path']));
        self::assertSame($this->folder('start_menu'), dirname($result['shortcuts'][1]['path']));
        self::assertCount(4, $this->powerShellCalls);
    }

    public function test_windows_shortcuts_use_a_system_target_with_a_bound_private_file_bridge(): void
    {
        $instance = $this->instance("中文 it's %value% !name! \$cash & [workspace]");
        $result = $this->install($instance, 'Windows');
        self::assertSame([], $result['warnings']);
        foreach ($this->powerShellCalls as [$script, $variables]) {
            self::assertStringContainsString('$link.TargetPath = $env:YANJI_ENTRY_TARGET', $script);
            self::assertStringNotContainsString('$link.TargetPath = $env:YANJI_ENTRY_FILE', $script);
            self::assertLessThanOrEqual(1023, intdiv(strlen(mb_convert_encoding($variables['YANJI_ENTRY_ARGUMENTS'], 'UTF-16LE', 'UTF-8')), 2));
            self::assertStringContainsString("ExtendedProperty('System.Link.Arguments')", $script);
            self::assertStringContainsString('$arguments -isnot [string]', $script);
            self::assertStringContainsString('$null -eq $folder', $script);
            self::assertStringContainsString('$null -eq $item', $script);
            self::assertStringContainsString('$fullArguments -cne $env:YANJI_ENTRY_ARGUMENTS', $script);
            self::assertStringNotContainsString('$link.Arguments -c', $script);
            self::assertMatchesRegularExpression('/\A[A-Za-z]:\\\\.+\\\\System32\\\\WindowsPowerShell\\\\v1\.0\\\\powershell\.exe\z/', $variables['YANJI_ENTRY_TARGET']);
            self::assertSame(substr($variables['YANJI_ENTRY_TARGET'], 0, -strlen('\\powershell.exe')), $variables['YANJI_ENTRY_WORKING_DIRECTORY']);
            self::assertSame(1, preg_match('/\A-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "([^"\r\n]+)"\z/', $variables['YANJI_ENTRY_ARGUMENTS'], $fileArgument));
            [$bridgePath, $expected] = (new \ReflectionMethod(DesktopEntry::class, 'windowsShortcutBridge'))->invoke(null, $result['file']);
            self::assertSame(str_replace('/', '\\', $bridgePath), $fileArgument[1]);
            self::assertSame($instance->root, dirname($bridgePath));
            self::assertFileExists($bridgePath);
            self::assertFalse(is_link($bridgePath));
            $command = file_get_contents($bridgePath);
            self::assertSame($expected, $command);
            self::assertDoesNotMatchRegularExpression('/[\x80-\xff]/', $command);
            self::assertSame(1, preg_match("/FromBase64String\\('([A-Za-z0-9+\\/=]+)'\\)/", $command, $match));
            self::assertSame(str_replace('/', '\\', $result['file']), base64_decode($match[1], true));
            self::assertStringContainsString('/d /v:off /s /c ""%YANJI_ENTRY_CANONICAL%""', $command);
            foreach (['http://', 'https://', 'ticket=', '$args', '%*'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $command);
            }
        }
    }

    public function test_windows_private_bridge_is_bound_unchanged_and_foreign_files_or_links_are_never_overwritten(): void
    {
        $instance = $this->instance();
        $result = $this->install($instance, 'Windows');
        [$bridgePath, $expected] = (new \ReflectionMethod(DesktopEntry::class, 'windowsShortcutBridge'))->invoke(null, $result['file']);
        $before = count($this->powerShellCalls);
        Instance::atomicFile($bridgePath, 'foreign bridge fixture');
        $description = DesktopEntry::describe($instance, 'Windows');
        self::assertSame($result['file'], $description['file'], 'The original canonical entry remains the independent fallback.');
        self::assertSame([], $description['shortcuts']);
        self::assertContains('shortcut_conflict', $description['warnings']);
        self::assertContains('shortcut_conflict', $this->install($instance, 'Windows')['warnings']);
        self::assertSame('foreign bridge fixture', file_get_contents($bridgePath));
        self::assertCount($before, $this->powerShellCalls);

        unlink($bridgePath);
        self::assertSame([], DesktopEntry::describe($instance, 'Windows')['shortcuts']);
        self::assertSame([], $this->install($instance, 'Windows')['warnings']);
        self::assertSame($expected, file_get_contents($bridgePath));
        if (PHP_OS_FAMILY !== 'Windows') {
            $foreign = $this->directory.'/foreign-bridge';
            Instance::atomicFile($foreign, 'other file fixture');
            unlink($bridgePath);
            symlink($foreign, $bridgePath);
            self::assertContains('shortcut_conflict', $this->install($instance, 'Windows')['warnings']);
            self::assertTrue(is_link($bridgePath));
            self::assertSame('other file fixture', file_get_contents($foreign));
        }
    }

    public function test_windows_foreign_shortcut_properties_are_rejected_and_owned_legacy_shortcuts_are_preserved(): void
    {
        $instance = $this->instance();
        $result = $this->install($instance, 'Windows');
        $shortcut = $result['shortcuts'][0]['path'];
        $record = json_decode(file_get_contents($shortcut), true);
        foreach (['target', 'arguments', 'directory', 'marker'] as $property) {
            $foreign = $record;
            $foreign[$property] .= '-foreign';
            Instance::atomicFile($shortcut, json_encode($foreign));
            self::assertContains('shortcut_conflict', $this->install($instance, 'Windows')['warnings']);
            self::assertSame($foreign, json_decode(file_get_contents($shortcut), true));
        }

        $legacy = ['target' => str_replace('/', '\\', $result['file']), 'arguments' => '',
            'directory' => str_replace('/', '\\', $instance->root), 'marker' => $record['marker']];
        Instance::atomicFile($shortcut, json_encode($legacy));
        self::assertSame([], $this->install($instance, 'Windows')['warnings']);
        self::assertSame($legacy, json_decode(file_get_contents($shortcut), true));
        $legacy['arguments'] = 'foreign';
        Instance::atomicFile($shortcut, json_encode($legacy));
        self::assertContains('shortcut_conflict', $this->install($instance, 'Windows')['warnings']);
    }

    public function test_windows_never_uses_path_lookup_when_the_system_directory_is_invalid(): void
    {
        $construct = new \ReflectionMethod(DesktopEntry::class, 'windowsShortcutLaunch');
        $previous = getenv('SystemRoot');
        $exception = null;
        putenv('SystemRoot=relative-system-directory');
        try {
            $construct->invoke(null, $this->directory.'/fixed-entry.cmd');
        } catch (\RuntimeException $error) {
            $exception = $error;
        } finally {
            putenv($previous === false ? 'SystemRoot' : 'SystemRoot='.$previous);
        }
        self::assertInstanceOf(\RuntimeException::class, $exception);
        self::assertSame('System PowerShell is unavailable.', $exception->getMessage());
        self::assertSame([], $this->powerShellCalls, 'A missing fixed system target must not fall back to PATH.');
        self::assertSame([], array_values(array_diff(scandir($this->directory), ['.', '..'])),
            'The pure constructor must not write an entry, ACL state or registration metadata.');
    }

    public function test_windows_parent_preserves_failure_visibility_without_call_or_interpolated_paths(): void
    {
        $instance = $this->instance("中文 it's %value% !name! \$cash & [workspace]");
        $result = $this->install($instance, 'Windows', false);
        $contents = file_get_contents($result['file']);
        self::assertStringContainsString('set "YANJI_ENTRY_ROOT=%~dp0"', $contents);
        self::assertStringContainsString('DisableDelayedExpansion', $contents);
        self::assertStringContainsString('if errorlevel 1 (', $contents);
        self::assertStringContainsString("  pause\r\n", $contents);
        self::assertStringContainsString('Please run yanji doctor.', $contents);
        self::assertStringNotContainsString('call ', strtolower($contents));
        self::assertSame(1, preg_match('/-EncodedCommand ([A-Za-z0-9+\/=]+)/', $contents, $match));
        $script = mb_convert_encoding(base64_decode($match[1], true), 'UTF-8', 'UTF-16LE');
        self::assertStringContainsString('%YANJI_ENTRY_ROOT%yanji.cmd', $script);
        self::assertStringContainsString('/d /v:off /s /c', $script);
        self::assertStringContainsString('-NoNewWindow -PassThru -Wait', $script);
        self::assertStringNotContainsString($instance->root, $script);
        self::assertDoesNotMatchRegularExpression('/[\x80-\xff]/', $script, 'The encoded PowerShell command must remain ASCII.');
    }

    public function test_windows_chinese_failure_message_is_utf8_after_an_ascii_codepage_switch(): void
    {
        $result = $this->install($this->instance(), 'Windows', false);
        $contents = file_get_contents($result['file']);
        self::assertStringStartsWith("@echo off\r\n", $contents, 'The batch file must not start with a UTF-8 BOM.');
        self::assertTrue(mb_check_encoding($contents, 'UTF-8'));
        $switch = "chcp 65001 >nul\r\n";
        $offset = strpos($contents, $switch);
        self::assertNotFalse($offset);
        self::assertDoesNotMatchRegularExpression('/[\x80-\xff]/', substr($contents, 0, $offset + strlen($switch)));
        $message = '言及暂时无法打开，请保留此窗口中的错误信息并反馈。按任意键关闭窗口。';
        self::assertStringContainsString($message, $contents);
        self::assertLessThan(strpos($contents, $message), $offset, 'Switch the code page before parsing the Chinese block.');
    }

    public function test_native_fixture_executes_only_its_managed_launcher_and_preserves_failure_status(): void
    {
        $root = dirname(__DIR__, 2);
        $process = new Process([PHP_BINARY, $root.'/tests/fixtures/native-desktop-entry.php', $root.'/portable'],
            null, null, '', PHP_OS_FAMILY === 'Windows' ? 600 : 90);
        $process->run();
        self::assertSame(0, $process->getExitCode(), 'The isolated native entry fixture must complete both success and deliberate failure. '
            .$process->getErrorOutput());
        self::assertSame('', $process->getErrorOutput());
        $record = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('native-desktop-entry', $record['fixture']);
        self::assertSame(PHP_OS_FAMILY, $record['platform']);
        self::assertTrue($record['supplied_entry_class_verified']);
        self::assertTrue($record['temporary_known_folders_only']);
        self::assertTrue($record['native_shortcuts_registered']);
        self::assertTrue($record['canonical_only_invoked_open']);
        self::assertSame(PHP_OS_FAMILY === 'Windows' ? 1 : 7, $record['canonical_failure_exit_code']);
        self::assertSame(PHP_OS_FAMILY === 'Windows' ? true : null, $record['windows_error_visible']);
        self::assertFalse($record['browser_or_services_invoked']);
        self::assertFalse($record['real_user_desktop_touched']);
    }

    public function test_describe_is_read_only_and_rejects_unverified_metadata_and_warning_messages(): void
    {
        $instance = $this->instance();
        self::assertNull(DesktopEntry::describe($instance, 'Darwin')['file']);
        self::assertFileDoesNotExist($instance->root.'/desktop-entry.json');
        self::assertDirectoryDoesNotExist($this->directory.'/known folders');
        $result = $this->install($instance, 'Darwin');
        $statePath = $instance->root.'/desktop-entry.json';
        $state = json_decode(file_get_contents($statePath), true);
        $state['file'] = 'https://example.com/#ticket=sk-test-untrusted';
        $state['warnings'] = ['shortcut_failed', 'sk-test-never-show-this', ['path' => 'sk-test-hidden']];
        $state['shortcuts'][0]['path'] = $instance->root.'/.env';
        Instance::atomicFile($statePath, json_encode($state));
        $before = file_get_contents($statePath);
        $description = DesktopEntry::describe($instance, 'Darwin');
        self::assertSame($result['file'], $description['file']);
        self::assertSame([], $description['shortcuts']);
        self::assertSame(['shortcut_failed', 'shortcut_conflict'], $description['warnings']);
        self::assertSame($before, file_get_contents($statePath));
        self::assertStringNotContainsString('sk-test-', json_encode($description));
    }

    public function test_foreign_registration_metadata_is_not_replaced(): void
    {
        $instance = $this->instance();
        Instance::atomicFile($instance->root.'/desktop-entry.json', 'foreign state fixture');
        $result = $this->install($instance, 'Darwin');
        self::assertFileExists($result['file']);
        self::assertContains('entry_state_conflict', $result['warnings']);
        self::assertSame('foreign state fixture', file_get_contents($instance->root.'/desktop-entry.json'));
    }

    public function test_deleted_or_non_executable_managed_launcher_is_reported_as_unavailable(): void
    {
        $instance = $this->instance();
        $result = $this->install($instance, 'Darwin', false);
        unlink($instance->root.'/yanji');
        self::assertFileExists($result['file']);
        self::assertSame(['launcher_unavailable'], DesktopEntry::describe($instance, 'Darwin')['warnings']);
        self::assertNull(DesktopEntry::describe($instance, 'Darwin')['file']);
        self::assertNull($this->install($instance, 'Darwin')['file']);
        self::assertFileDoesNotExist($instance->root.'/yanji');
        if (PHP_OS_FAMILY !== 'Windows') {
            Instance::atomicFile($instance->root.'/yanji', "#!/bin/sh\n", 0600);
            self::assertNull(DesktopEntry::describe($instance, 'Darwin')['file']);
        }
    }

    public function test_symbolic_links_are_never_followed_or_replaced(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('This file-link fixture requires Unix symlink permissions.');
        }
        $instance = $this->instance();
        $result = $this->install($instance, 'Darwin', false);
        $foreign = $this->directory.'/foreign';
        Instance::atomicFile($foreign, 'foreign fixture');
        unlink($result['file']);
        symlink($foreign, $result['file']);
        self::assertNull($this->install($instance, 'Darwin')['file']);
        self::assertTrue(is_link($result['file']));
        self::assertSame('foreign fixture', file_get_contents($foreign));
        unlink($result['file']);
        $result = $this->install($instance, 'Darwin', false);
        mkdir($this->folder('desktop'), 0700, true);
        $shortcut = $this->folder('desktop').'/'.$result['name'].'.command';
        symlink($foreign, $shortcut);
        self::assertContains('shortcut_conflict', $this->install($instance, 'Darwin')['warnings']);
        self::assertTrue(is_link($shortcut));
        self::assertSame('foreign fixture', file_get_contents($foreign));
        unlink($instance->root.'/yanji');
        symlink($foreign, $instance->root.'/yanji');
        self::assertNull(DesktopEntry::describe($instance, 'Darwin')['file']);
        self::assertSame(['launcher_unavailable'], $this->install($instance, 'Darwin')['warnings']);
    }

    public function test_mac_command_runs_only_the_bound_fake_launcher_with_the_fixed_open_argument(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('The Mac command fixture executes /bin/sh.');
        }
        $instance = $this->instance('中文 it\'s "quoted" `backtick` $value; & [work]');
        Instance::atomicFile($instance->root.'/yanji', "#!/bin/sh\nprintf '%s\\n' \"\$@\" > \"\$(dirname \"\$0\")/invocations\"\n", 0700);
        $result = $this->install($instance, 'Darwin', false);
        $process = new Process(['/bin/sh', $result['file'], 'https://example.com/ignored'], null, ['LC_ALL' => 'C']);
        $process->mustRun();
        self::assertSame("open\n", file_get_contents($instance->root.'/invocations'));
        self::assertSame('', $process->getOutput().$process->getErrorOutput());
    }

    public function test_linux_exec_escapes_both_desktop_strings_and_arguments_and_percent_field_codes(): void
    {
        $instance = $this->instance("中文 it's %field% \$cash & [work]");
        $result = $this->install($instance, 'Linux', false);
        $contents = file_get_contents($result['file']);
        self::assertSame(1, preg_match('/^Exec=\/bin\/sh "(.*)" open$/m', $contents, $match));
        self::assertStringContainsString('%%field%%', $match[1]);
        self::assertStringContainsString('\\\\$', $match[1]);
        $value = str_replace('\\\\', '\\', $match[1]);
        $argument = preg_replace_callback('/\\\\([\\\\"`$])/', static fn (array $part): string => $part[1], $value);
        self::assertSame($instance->root.'/yanji', str_replace('%%', '%', $argument));
    }
}
