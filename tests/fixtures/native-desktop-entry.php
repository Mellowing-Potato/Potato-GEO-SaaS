<?php

declare(strict_types=1);

/** Exercise a supplied DesktopEntry against private fake launchers, never a browser or a real desktop. */
use Yanji\Portable\DesktopEntry;
use Yanji\Portable\Instance;
use Yanji\Portable\Platform;

umask(0077);
$stage = 'arguments';
$temporary = null;
$exitCode = 0;
$diagnostic = [];

function entryRequire(bool $condition, string $code): void
{
    if (!$condition) {
        throw new RuntimeException($code);
    }
}

function entryRemove(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') {
                entryRemove($path.'/'.$name);
            }
        }
        rmdir($path);
    } elseif (file_exists($path) || is_link($path)) {
        unlink($path);
    }
}

function entryExecute(array|string $command, string $directory, array $environment): array
{
    $stdout = $directory.'/process-stdout';
    $stderr = $directory.'/process-stderr';
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $stdout, 'w'],
        2 => ['file', $stderr, 'w']], $pipes, $directory, $environment, ['bypass_shell' => true]);
    entryRequire(is_resource($process), 'canonical_process_unavailable');
    fwrite($pipes[0], "\r\n");
    fclose($pipes[0]);
    $deadline = microtime(true) + 30;
    do {
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        if (microtime(true) >= $deadline) {
            Platform::killProcessTree($status['pid']);
            proc_close($process);
            throw new RuntimeException('canonical_process_timeout');
        }
        usleep(10000);
    } while (true);
    $closed = proc_close($process);
    return ['code' => $status['exitcode'] >= 0 ? $status['exitcode'] : $closed,
        'stdout' => (string) file_get_contents($stdout), 'stderr' => (string) file_get_contents($stderr)];
}

/** A failed primary registration stays failed; this separate probe reports only bounded native diagnostics. */
function entryWindowsProbe(Instance $instance, ?string $file, string $directory): array
{
    if ($file === null || !is_file($file)) {
        return ['code' => 'canonical_unavailable'];
    }
    $script = <<<'PS'
$ErrorActionPreference = 'Stop'
$phase = 'com_shell'
try {
    $shell = New-Object -ComObject WScript.Shell
    $phase = 'shortcut_create'
    $link = $shell.CreateShortcut($env:YANJI_ENTRY_SHORTCUT)
    $phase = 'shortcut_properties'
    $link.TargetPath = $env:YANJI_ENTRY_FILE
    $link.Arguments = ''
    $link.Description = $env:YANJI_ENTRY_MARKER
    $link.WorkingDirectory = $env:YANJI_ENTRY_DIRECTORY
    $phase = 'shortcut_save'
    $link.Save()
    [Console]::Write((@{code='saved';phase='complete';target_matches=($link.TargetPath -ceq $env:YANJI_ENTRY_FILE);arguments_empty=($link.Arguments -eq '');working_directory_matches=($link.WorkingDirectory -ceq $env:YANJI_ENTRY_DIRECTORY);marker_matches=($link.Description -ceq $env:YANJI_ENTRY_MARKER)} | ConvertTo-Json -Compress))
    exit 0
} catch {
    [Console]::Write((@{code='exception';phase=$phase;hresult=[int]$_.Exception.HResult} | ConvertTo-Json -Compress))
    exit 1
}
PS;
    $started = microtime(true);
    try {
        $keep = array_flip(['SYSTEMROOT', 'WINDIR', 'TEMP', 'TMP', 'LOCALAPPDATA', 'APPDATA', 'USERPROFILE', 'PATH']);
        $environment = [];
        foreach (getenv() as $key => $value) {
            if (isset($keep[strtoupper($key)])) {
                $environment[$key] = $value;
            }
        }
        $path = $directory.'/言及诊断快捷方式.lnk';
        $environment = [...Platform::windowsPowerShellEnv($environment),
            'YANJI_ENTRY_FILE' => Platform::nativePath($file),
            'YANJI_ENTRY_SHORTCUT' => Platform::nativePath($path),
            'YANJI_ENTRY_DIRECTORY' => Platform::nativePath($instance->root),
            'YANJI_ENTRY_MARKER' => 'yanji-native-entry-probe'];
        $record = entryExecute([Platform::windowsPowerShell(), '-NoProfile', '-NonInteractive', '-EncodedCommand',
            Platform::encodePowerShellCommand($script)], $directory, $environment);
        $detail = json_decode($record['stdout'], true);
        $safe = [];
        if (is_array($detail)) {
            if (in_array($detail['code'] ?? null, ['saved', 'exception'], true)) {
                $safe['code'] = $detail['code'];
            }
            if (in_array($detail['phase'] ?? null, ['com_shell', 'shortcut_create', 'shortcut_properties', 'shortcut_save', 'complete'], true)) {
                $safe['phase'] = $detail['phase'];
            }
            if (is_int($detail['hresult'] ?? null)) {
                $safe['hresult'] = $detail['hresult'];
            }
            foreach (['target_matches', 'arguments_empty', 'working_directory_matches', 'marker_matches'] as $key) {
                if (is_bool($detail[$key] ?? null)) {
                    $safe[$key] = $detail[$key];
                }
            }
        }
        $exists = is_file($path) && !is_link($path);
        return ['exit_code' => $record['code'], 'stdout_bytes' => strlen($record['stdout']),
            'stderr_bytes' => strlen($record['stderr']), 'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            'temporary_lnk_exists' => $exists, 'publish_hardlink_ok' => $exists && @link($path, $path.'.published.lnk'),
            'detail' => $safe];
    } catch (Throwable) {
        return ['code' => 'probe_unavailable', 'elapsed_ms' => (int) round((microtime(true) - $started) * 1000)];
    }
}

try {
    entryRequire(count($argv) === 2, 'portable_directory_required');
    $portable = realpath($argv[1]);
    entryRequire(is_string($portable) && is_file($portable.'/src/DesktopEntry.php'), 'packaged_entry_class_missing');
    require_once $portable.'/src/DesktopEntry.php';
    $stage = 'private_fixture';
    $temporary = sys_get_temp_dir().'/yanji-native-entry-'.bin2hex(random_bytes(12));
    entryRequire(mkdir($temporary, 0700), 'fixture_directory_unavailable');
    $temporary = Platform::normalize((string) realpath($temporary));
    $instance = new Instance($temporary."/中文 it's %value% !name! \$cash & [workspace]");
    $instance->prepare();
    Instance::atomicFile($instance->root.'/instance.json', '{"port":8137}');
    $windows = PHP_OS_FAMILY === 'Windows';
    $launcher = $instance->root.'/'.($windows ? 'yanji.cmd' : 'yanji');
    $quotedRoot = "'".str_replace("'", "'\"'\"'", $instance->root)."'";
    $fake = $windows
        ? "@echo off\r\nsetlocal DisableDelayedExpansion\r\nif not \"%~1\"==\"open\" exit /b 9\r\n>>\"%~dp0invocations\" echo %~1\r\nif exist \"%~dp0fail\" exit /b 7\r\nexit /b 0\r\n"
        : "#!/bin/sh\nset -eu\n[ \"\$#\" -eq 1 ] && [ \"\$1\" = open ] || exit 9\nprintf '%s\\n' \"\$1\" >> ".$quotedRoot."/invocations\nif [ -f ".$quotedRoot."/fail ]; then exit 7; fi\n";
    Instance::atomicFile($launcher, $fake, 0700);
    $stage = 'temporary_shortcuts';
    $knownFolders = static fn (string $kind): string => $temporary.'/known-folders/'.$kind;
    $entryStarted = microtime(true);
    $entry = DesktopEntry::install($instance, true, $knownFolders);
    $warningCodes = ['entry_unavailable', 'entry_conflict', 'launcher_unavailable', 'desktop_unavailable',
        'start_menu_unavailable', 'applications_unavailable', 'shortcut_conflict', 'shortcut_failed',
        'entry_state_conflict', 'entry_state_unavailable'];
    $diagnostic = [
        'elapsed_ms' => (int) round((microtime(true) - $entryStarted) * 1000),
        'warnings' => array_values(array_filter($entry['warnings'], static fn ($value): bool => in_array($value, $warningCodes, true))),
        'shortcut_count' => count($entry['shortcuts']),
        'shortcut_kinds' => array_values(array_filter(array_column($entry['shortcuts'], 'kind'),
            static fn ($value): bool => in_array($value, ['desktop', 'start_menu', 'applications'], true))),
        'flags' => ['canonical_created' => is_string($entry['file']) && is_file($entry['file']),
            'desktop_directory_created' => is_dir($knownFolders('desktop')),
            'start_menu_directory_created' => is_dir($knownFolders('start_menu')),
            'applications_directory_created' => is_dir($knownFolders('applications'))],
    ];
    $expectedKinds = match (PHP_OS_FAMILY) {
        'Windows' => ['desktop', 'start_menu'], 'Darwin' => ['desktop'], default => ['applications'],
    };
    if ($windows && $entry['warnings'] !== []) {
        $diagnostic['independent_windows_probe'] = entryWindowsProbe($instance, $entry['file'], $temporary);
    }
    entryRequire($entry['warnings'] === [], 'native_shortcut_registration_failed');
    entryRequire(is_string($entry['file']) && is_file($entry['file']), 'canonical_entry_missing');
    entryRequire(dirname($entry['file']) === $instance->root, 'canonical_entry_wrong_instance');
    entryRequire(array_column($entry['shortcuts'], 'kind') === $expectedKinds, 'native_shortcut_kind_mismatch');
    foreach ($entry['shortcuts'] as $shortcut) {
        entryRequire(is_file($shortcut['path']) && dirname($shortcut['path']) === $knownFolders($shortcut['kind']),
            'shortcut_escaped_private_fixture');
    }
    entryRequire(DesktopEntry::describe($instance) === $entry, 'registered_entry_description_changed');
    entryRequire(DesktopEntry::install($instance, true, $knownFolders) === $entry, 'repeated_registration_changed_entry');
    $contents = file_get_contents($entry['file']);
    foreach (['http://', 'https://', 'ticket=', 'APP_KEY', 'password', 'current-release'] as $forbidden) {
        entryRequire(!str_contains($contents, $forbidden), 'canonical_contains_transient_authorization');
    }
    $environment = getenv();
    if ($windows) {
        $environment['YANJI_ENTRY_NATIVE_FILE'] = Platform::nativePath($entry['file']);
        $command = '"'.getenv('SystemRoot').'\\System32\\cmd.exe" /d /v:off /s /c ""%YANJI_ENTRY_NATIVE_FILE%""';
        entryRequire(str_contains($contents, "  pause\r\n"), 'windows_failure_acknowledgement_missing');
    } elseif (PHP_OS_FAMILY === 'Darwin') {
        $command = ['/bin/sh', $entry['file']];
    } else {
        // Execute the fixed Desktop Entry command without involving a GUI desktop launcher.
        entryRequire(preg_match('/^Exec=(.+)$/m', $contents, $match) === 1, 'linux_exec_missing');
        $decoded = strtr($match[1], ['\\s' => ' ', '\\n' => "\n", '\\t' => "\t", '\\r' => "\r", '\\\\' => '\\']);
        entryRequire(str_starts_with($decoded, '/bin/sh "') && str_ends_with($decoded, '" open'), 'linux_exec_shape_changed');
        $executable = substr($decoded, strlen('/bin/sh "'), -strlen('" open'));
        $executable = strtr($executable, ['\\"' => '"', '\\`' => '`', '\\$' => '$', '\\\\' => '\\', '%%' => '%']);
        entryRequire($executable === $launcher, 'linux_exec_wrong_instance');
        $command = ['/bin/sh', $executable, 'open'];
    }
    $stage = 'canonical_success';
    $success = entryExecute($command, $temporary, $environment);
    entryRequire($success['code'] === 0, 'canonical_success_exit_nonzero');
    entryRequire(file($instance->root.'/invocations', FILE_IGNORE_NEW_LINES) === ['open'], 'canonical_did_not_only_call_open');
    $stage = 'canonical_failure';
    Instance::atomicFile($instance->root.'/fail', "fixture\n");
    $failure = entryExecute($command, $temporary, $environment);
    entryRequire($failure['code'] === ($windows ? 1 : 7), 'canonical_failure_exit_code_lost');
    entryRequire(file($instance->root.'/invocations', FILE_IGNORE_NEW_LINES) === ['open', 'open'], 'canonical_failure_called_another_command');
    if ($windows) {
        entryRequire(str_contains($failure['stdout'].$failure['stderr'], 'Please run yanji doctor.'),
            'windows_failure_not_visible');
        entryRequire(str_contains($failure['stdout'].$failure['stderr'], '言及暂时无法打开，请保留此窗口中的错误信息并反馈。'),
            'windows_chinese_failure_not_visible');
    }
    entryRequire(file_get_contents($launcher) === $fake && file_get_contents($entry['file']) === $contents,
        'canonical_execution_changed_launchers');
    echo json_encode(['fixture' => 'native-desktop-entry', 'platform' => PHP_OS_FAMILY,
        'supplied_entry_class_verified' => true, 'temporary_known_folders_only' => true,
        'native_shortcuts_registered' => true, 'canonical_only_invoked_open' => true,
        'canonical_failure_exit_code' => $failure['code'], 'windows_error_visible' => $windows ? true : null,
        'browser_or_services_invoked' => false, 'real_user_desktop_touched' => false], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    $code = preg_match('/\A[a-z0-9_]+\z/', $error->getMessage()) ? $error->getMessage() : 'unexpected_fixture_failure';
    fwrite(STDERR, json_encode(['fixture' => 'native-desktop-entry', 'stage' => $stage, 'code' => $code,
        'diagnostic' => $diagnostic], JSON_THROW_ON_ERROR)."\n");
    $exitCode = 1;
} finally {
    if ($temporary !== null) {
        entryRemove($temporary);
    }
}
exit($exitCode);
