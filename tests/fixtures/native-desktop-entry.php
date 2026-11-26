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
    $started = microtime(true);
    $stdout = $directory.'/process-stdout';
    $stderr = $directory.'/process-stderr';
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $stdout, 'w'],
        2 => ['file', $stderr, 'w']], $pipes, $directory, $environment, ['bypass_shell' => true]);
    entryRequire(is_resource($process), 'canonical_process_unavailable');
    fwrite($pipes[0], "\r\n");
    fclose($pipes[0]);
    // Native COM probes took 18–23 seconds; a registered entry adds nested PowerShell/cmd processes.
    $deadline = microtime(true) + 90;
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
        'stdout' => (string) file_get_contents($stdout), 'stderr' => (string) file_get_contents($stderr),
        'elapsed_ms' => (int) round((microtime(true) - $started) * 1000)];
}

/** Keep the primary gate unchanged; diagnose the current builder and its actual helper in private files. */
function entryWindowsProbe(Instance $instance, ?string $file, string $directory): array
{
    if ($file === null || !is_file($file)) {
        return ['code' => 'canonical_unavailable'];
    }
    $started = microtime(true);
    $result = [];
    try {
        $captured = null;
        $helperPath = $directory.'/production-helper-probe.lnk';
        $capture = static function (string $script, array $variables) use (&$captured): array {
            $captured = [$script, $variables];
            return ['code' => 74, 'stdout' => ''];
        };
        (new ReflectionMethod(DesktopEntry::class, 'windowsShortcut'))->invoke(null, $instance, $file, $helperPath, false, $capture);
        entryRequire(is_array($captured) && count($captured) === 2, 'current_builder_capture_unavailable');
        [$productionScript, $variables] = $captured;
        $helperStarted = microtime(true);
        $helper = (new ReflectionMethod(DesktopEntry::class, 'powerShell'))->invoke(null, $productionScript, $variables, null);
        clearstatcache(true, $helperPath);
        $stat = @lstat($helperPath);
        $regular = $stat !== false && ($stat['mode'] & 0170000) === 0100000;
        $result = ['production_helper' => ['code' => $helper['code'], 'stdout_bytes' => strlen($helper['stdout']),
            'elapsed_ms' => (int) round((microtime(true) - $helperStarted) * 1000),
            'shortcut_regular' => $regular, 'publish_hardlink_ok' => $regular && @link($helperPath, $helperPath.'.published.lnk')]];
        $script = <<<'PS'
$ErrorActionPreference = 'Stop'
$phase = 'com_shell'
$before = $null
$after = $null
$reloaded = $null
$extended = $null
$unicodeWriter = $null
function Compare-Arguments([string]$actual) {
    $expected = [string]$env:YANJI_ENTRY_ARGUMENTS
    return @{case_insensitive_match=($actual -ieq $expected);nonascii_as_question_match=($actual -ceq [regex]::Replace($expected,'[^\x00-\x7f]','?'));expected_nonascii_count=[regex]::Matches($expected,'[^\x00-\x7f]').Count;actual_nonascii_count=[regex]::Matches($actual,'[^\x00-\x7f]').Count;expected_question_count=[regex]::Matches($expected,'\?').Count;actual_question_count=[regex]::Matches($actual,'\?').Count}
}
function Read-Link($value) {
    $actualArguments = [string]$value.Arguments
    return @{target_matches=($value.TargetPath -ceq $env:YANJI_ENTRY_TARGET);target_case_insensitive_match=($value.TargetPath -ieq $env:YANJI_ENTRY_TARGET);target_exists=[IO.File]::Exists([string]$value.TargetPath);arguments_match=($actualArguments -ceq $env:YANJI_ENTRY_ARGUMENTS);description_matches=($value.Description -ceq $env:YANJI_ENTRY_MARKER);working_directory_matches=($value.WorkingDirectory -ceq $env:YANJI_ENTRY_WORKING_DIRECTORY);working_directory_case_insensitive_match=($value.WorkingDirectory -ieq $env:YANJI_ENTRY_WORKING_DIRECTORY);expected_arguments_length=([string]$env:YANJI_ENTRY_ARGUMENTS).Length;actual_arguments_length=$actualArguments.Length;argument_diff=(Compare-Arguments $actualArguments)}
}
try {
    $shell = New-Object -ComObject WScript.Shell
    $phase = 'shortcut_create'
    $link = $shell.CreateShortcut($env:YANJI_ENTRY_SHORTCUT)
    $phase = 'shortcut_target'
    $link.TargetPath = $env:YANJI_ENTRY_TARGET
    $phase = 'shortcut_arguments'
    $link.Arguments = $env:YANJI_ENTRY_ARGUMENTS
    $phase = 'shortcut_description'
    $link.Description = $env:YANJI_ENTRY_MARKER
    $phase = 'shortcut_working_directory'
    $link.WorkingDirectory = $env:YANJI_ENTRY_WORKING_DIRECTORY
    $phase = 'readback_before_save'
    $before = Read-Link $link
    $phase = 'shortcut_save'
    $link.Save()
    $phase = 'readback_after_save'
    $after = Read-Link $link
    $phase = 'readback_reloaded'
    $reloaded = Read-Link ($shell.CreateShortcut($env:YANJI_ENTRY_SHORTCUT))
    $phase = 'extended_arguments'
    $application = New-Object -ComObject Shell.Application
    $folder = $application.NameSpace([IO.Path]::GetDirectoryName($env:YANJI_ENTRY_SHORTCUT))
    $item = $folder.ParseName([IO.Path]::GetFileName($env:YANJI_ENTRY_SHORTCUT))
    $value = $item.ExtendedProperty('System.Link.Arguments')
    $extended = @{available=($null -ne $value);value_is_string=($value -is [string]);arguments_match=($value -is [string] -and $value -ceq $env:YANJI_ENTRY_ARGUMENTS);expected_arguments_length=([string]$env:YANJI_ENTRY_ARGUMENTS).Length;actual_arguments_length=([string]$value).Length;argument_diff=(Compare-Arguments ([string]$value))}
    $unicodePhase = 'create'
    try {
        $control = $shell.CreateShortcut($env:YANJI_ENTRY_UNICODE_SHORTCUT)
        $control.TargetPath = $env:YANJI_ENTRY_TARGET
        $control.Description = $env:YANJI_ENTRY_MARKER
        $control.WorkingDirectory = $env:YANJI_ENTRY_WORKING_DIRECTORY
        $control.Save()
        $unicodePhase = 'get_link'
        $controlFolder = $application.NameSpace([IO.Path]::GetDirectoryName($env:YANJI_ENTRY_UNICODE_SHORTCUT))
        $controlItem = $controlFolder.ParseName([IO.Path]::GetFileName($env:YANJI_ENTRY_UNICODE_SHORTCUT))
        $controlLink = $controlItem.GetLink
        $unicodePhase = 'arguments'
        $controlLink.Arguments = $env:YANJI_ENTRY_ARGUMENTS
        $writerReadback = [string]$controlLink.Arguments
        $unicodePhase = 'save'
        [void]$controlLink.Save()
        $unicodePhase = 'readback'
        $freshItem = $controlFolder.ParseName([IO.Path]::GetFileName($env:YANJI_ENTRY_UNICODE_SHORTCUT))
        $saved = $freshItem.ExtendedProperty('System.Link.Arguments')
        $unicodeWriter = @{code='saved';phase='complete';available=($null -ne $saved);value_is_string=($saved -is [string]);arguments_match=($saved -is [string] -and $saved -ceq $env:YANJI_ENTRY_ARGUMENTS);writer_readback_match=($writerReadback -ceq $env:YANJI_ENTRY_ARGUMENTS);expected_arguments_length=([string]$env:YANJI_ENTRY_ARGUMENTS).Length;actual_arguments_length=([string]$saved).Length;writer_readback_length=$writerReadback.Length;argument_diff=(Compare-Arguments ([string]$saved))}
    } catch { $unicodeWriter = @{code='exception';phase=$unicodePhase;hresult=[int]$_.Exception.HResult} }
    [Console]::Write((@{code='saved';phase='complete';before=$before;after=$after;reloaded=$reloaded;extended_arguments=$extended;unicode_writer_control=$unicodeWriter} | ConvertTo-Json -Compress -Depth 5))
    exit 0
} catch {
    $hresult = [int]$_.Exception.HResult
    [Console]::Write((@{code='exception';phase=$phase;hresult=$hresult;before=$before;after=$after;reloaded=$reloaded;extended_arguments=$extended;unicode_writer_control=$unicodeWriter} | ConvertTo-Json -Compress -Depth 5))
    exit 1
}
PS;
        $keep = array_flip(['SYSTEMROOT', 'WINDIR', 'TEMP', 'TMP', 'LOCALAPPDATA', 'APPDATA', 'USERPROFILE', 'PATH']);
        $environment = [];
        foreach (getenv() as $key => $value) {
            if (isset($keep[strtoupper($key)])) {
                $environment[$key] = $value;
            }
        }
        $controlPath = $directory.'/current-builder-control.lnk';
        $variables['YANJI_ENTRY_SHORTCUT'] = Platform::nativePath($controlPath);
        $variables['YANJI_ENTRY_UNICODE_SHORTCUT'] = Platform::nativePath($directory.'/unicode-writer-control.lnk');
        $environment = [...Platform::windowsPowerShellEnv($environment), ...$variables];
        $record = entryExecute([Platform::windowsPowerShell(), '-NoProfile', '-NonInteractive', '-EncodedCommand',
            Platform::encodePowerShellCommand($script)], $directory, $environment);
        $detail = json_decode($record['stdout'], true);
        $safe = [];
        if (is_array($detail)) {
            if (in_array($detail['code'] ?? null, ['saved', 'exception'], true)) {
                $safe['code'] = $detail['code'];
            }
            if (in_array($detail['phase'] ?? null, ['com_shell', 'shortcut_create', 'shortcut_target', 'shortcut_arguments',
                'shortcut_description', 'shortcut_working_directory', 'readback_before_save', 'shortcut_save',
                'readback_after_save', 'readback_reloaded', 'extended_arguments', 'complete'], true)) {
                $safe['phase'] = $detail['phase'];
            }
            if (is_int($detail['hresult'] ?? null)) {
                $safe['hresult'] = $detail['hresult'];
            }
            foreach (['before', 'after', 'reloaded'] as $when) {
                if (!is_array($detail[$when] ?? null)) {
                    continue;
                }
                foreach (['target_matches', 'target_case_insensitive_match', 'target_exists', 'arguments_match',
                    'description_matches', 'working_directory_matches', 'working_directory_case_insensitive_match'] as $key) {
                    if (is_bool($detail[$when][$key] ?? null)) {
                        $safe[$when][$key] = $detail[$when][$key];
                    }
                }
                foreach (['expected_arguments_length', 'actual_arguments_length'] as $key) {
                    $value = $detail[$when][$key] ?? null;
                    if (is_int($value) && $value >= 0 && $value <= 32768) {
                        $safe[$when][$key] = $value;
                    }
                }
            }
            if (is_array($detail['extended_arguments'] ?? null)) {
                foreach (['available', 'value_is_string', 'arguments_match'] as $key) {
                    if (is_bool($detail['extended_arguments'][$key] ?? null)) {
                        $safe['extended_arguments'][$key] = $detail['extended_arguments'][$key];
                    }
                }
                foreach (['expected_arguments_length', 'actual_arguments_length'] as $key) {
                    $value = $detail['extended_arguments'][$key] ?? null;
                    if (is_int($value) && $value >= 0 && $value <= 32768) {
                        $safe['extended_arguments'][$key] = $value;
                    }
                }
            }
            if (is_array($detail['unicode_writer_control'] ?? null)) {
                $unicode = $detail['unicode_writer_control'];
                if (in_array($unicode['code'] ?? null, ['saved', 'exception'], true)) {
                    $safe['unicode_writer_control']['code'] = $unicode['code'];
                }
                if (in_array($unicode['phase'] ?? null, ['create', 'get_link', 'arguments', 'save', 'readback', 'complete'], true)) {
                    $safe['unicode_writer_control']['phase'] = $unicode['phase'];
                }
                if (is_int($unicode['hresult'] ?? null)) {
                    $safe['unicode_writer_control']['hresult'] = $unicode['hresult'];
                }
                foreach (['available', 'value_is_string', 'arguments_match', 'writer_readback_match'] as $key) {
                    if (is_bool($unicode[$key] ?? null)) {
                        $safe['unicode_writer_control'][$key] = $unicode[$key];
                    }
                }
                foreach (['expected_arguments_length', 'actual_arguments_length', 'writer_readback_length'] as $key) {
                    $value = $unicode[$key] ?? null;
                    if (is_int($value) && $value >= 0 && $value <= 32768) {
                        $safe['unicode_writer_control'][$key] = $value;
                    }
                }
            }
            foreach (['before', 'after', 'reloaded', 'extended_arguments', 'unicode_writer_control'] as $when) {
                if (!is_array($detail[$when]['argument_diff'] ?? null)) {
                    continue;
                }
                foreach (['case_insensitive_match', 'nonascii_as_question_match'] as $key) {
                    if (is_bool($detail[$when]['argument_diff'][$key] ?? null)) {
                        $safe[$when]['argument_diff'][$key] = $detail[$when]['argument_diff'][$key];
                    }
                }
                foreach (['expected_nonascii_count', 'actual_nonascii_count', 'expected_question_count', 'actual_question_count'] as $key) {
                    $value = $detail[$when]['argument_diff'][$key] ?? null;
                    if (is_int($value) && $value >= 0 && $value <= 32768) {
                        $safe[$when]['argument_diff'][$key] = $value;
                    }
                }
            }
        }
        clearstatcache(true, $controlPath);
        $stat = @lstat($controlPath);
        $regular = $stat !== false && ($stat['mode'] & 0170000) === 0100000;
        $result['current_com_control'] = ['code' => $record['code'], 'stdout_bytes' => strlen($record['stdout']),
            'stderr_bytes' => strlen($record['stderr']), 'elapsed_ms' => $record['elapsed_ms'],
            'shortcut_regular' => $regular, 'publish_hardlink_ok' => $regular && @link($controlPath, $controlPath.'.published.lnk'),
            'detail' => $safe];
        return $result;
    } catch (Throwable) {
        return [...$result, 'code' => 'probe_unavailable', 'elapsed_ms' => (int) round((microtime(true) - $started) * 1000)];
    }
}

function entryPublishedInspectProbe(Instance $instance, string $file, string $published, string $directory): array
{
    $result = [];
    try {
        $captured = null;
        $capture = static function (string $script, array $variables) use (&$captured): array {
            $captured = [$script, $variables];
            return ['code' => 74, 'stdout' => ''];
        };
        (new ReflectionMethod(DesktopEntry::class, 'windowsShortcut'))->invoke(null, $instance, $file, $published, true, $capture);
        entryRequire(is_array($captured), 'published_capture_unavailable');
        [$productionScript, $variables] = $captured;
        $started = microtime(true);
        $helper = (new ReflectionMethod(DesktopEntry::class, 'powerShell'))->invoke(null, $productionScript, $variables, null);
        $result['production_inspect'] = ['code' => $helper['code'], 'stdout_bytes' => strlen($helper['stdout']),
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000)];
        $script = <<<'PS'
$ErrorActionPreference = 'Stop'
$phase = 'wsh_read'
try {
    $wsh = New-Object -ComObject WScript.Shell
    $old = $wsh.CreateShortcut($env:YANJI_ENTRY_SHORTCUT)
    $wshRecord = @{target_match=($old.TargetPath -ceq $env:YANJI_ENTRY_TARGET);description_match=($old.Description -ceq $env:YANJI_ENTRY_MARKER);working_directory_match=($old.WorkingDirectory -ceq $env:YANJI_ENTRY_WORKING_DIRECTORY);target_empty=([string]::IsNullOrEmpty($old.TargetPath));description_empty=([string]::IsNullOrEmpty($old.Description));working_directory_empty=([string]::IsNullOrEmpty($old.WorkingDirectory))}
    $phase = 'shell_read'
    $application = New-Object -ComObject Shell.Application
    $folder = $application.NameSpace([IO.Path]::GetDirectoryName($env:YANJI_ENTRY_SHORTCUT))
    $item = $folder.ParseName([IO.Path]::GetFileName($env:YANJI_ENTRY_SHORTCUT))
    $link = $item.GetLink
    $arguments = $item.ExtendedProperty('System.Link.Arguments')
    $shellRecord = @{target_match=($link.Path -ceq $env:YANJI_ENTRY_TARGET);description_match=($link.Description -ceq $env:YANJI_ENTRY_MARKER);working_directory_match=($link.WorkingDirectory -ceq $env:YANJI_ENTRY_WORKING_DIRECTORY);arguments_available=($arguments -is [string]);arguments_match=($arguments -is [string] -and $arguments -ceq $env:YANJI_ENTRY_ARGUMENTS);expected_arguments_length=([string]$env:YANJI_ENTRY_ARGUMENTS).Length;actual_arguments_length=([string]$arguments).Length}
    [Console]::Write((@{code='read';phase='complete';published_file_exists=[IO.File]::Exists($env:YANJI_ENTRY_SHORTCUT);wsh=$wshRecord;shell=$shellRecord} | ConvertTo-Json -Compress -Depth 4))
    exit 0
} catch {
    [Console]::Write((@{code='exception';phase=$phase;hresult=[int]$_.Exception.HResult} | ConvertTo-Json -Compress))
    exit 1
}
PS;
        $keep = array_flip(['SYSTEMROOT', 'WINDIR', 'TEMP', 'TMP', 'LOCALAPPDATA', 'APPDATA', 'USERPROFILE', 'PATH']);
        $environment = [];
        foreach (getenv() as $key => $value) {
            if (isset($keep[strtoupper($key)])) {
                $environment[$key] = $value;
            }
        }
        $environment = [...Platform::windowsPowerShellEnv($environment), ...$variables];
        $read = entryExecute([Platform::windowsPowerShell(), '-NoProfile', '-NonInteractive', '-EncodedCommand',
            Platform::encodePowerShellCommand($script)], $directory, $environment);
        $detail = json_decode($read['stdout'], true);
        $safe = [];
        if (is_array($detail)) {
            if (in_array($detail['code'] ?? null, ['read', 'exception'], true)) {
                $safe['code'] = $detail['code'];
            }
            if (in_array($detail['phase'] ?? null, ['wsh_read', 'shell_read', 'complete'], true)) {
                $safe['phase'] = $detail['phase'];
            }
            if (is_int($detail['hresult'] ?? null)) {
                $safe['hresult'] = $detail['hresult'];
            }
            if (is_bool($detail['published_file_exists'] ?? null)) {
                $safe['published_file_exists'] = $detail['published_file_exists'];
            }
            foreach (['wsh', 'shell'] as $kind) {
                foreach (['target_match', 'description_match', 'working_directory_match', 'target_empty',
                    'description_empty', 'working_directory_empty', 'arguments_available', 'arguments_match'] as $key) {
                    if (is_bool($detail[$kind][$key] ?? null)) {
                        $safe[$kind][$key] = $detail[$kind][$key];
                    }
                }
                foreach (['expected_arguments_length', 'actual_arguments_length'] as $key) {
                    $value = $detail[$kind][$key] ?? null;
                    if (is_int($value) && $value >= 0 && $value <= 32768) {
                        $safe[$kind][$key] = $value;
                    }
                }
            }
        }
        $result['readback'] = ['code' => $read['code'], 'stdout_bytes' => strlen($read['stdout']),
            'stderr_bytes' => strlen($read['stderr']), 'elapsed_ms' => $read['elapsed_ms'], 'detail' => $safe];
    } catch (Throwable) {
        $result['code'] = 'published_probe_unavailable';
    }
    return $result;
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
    $knownFolders = static fn (string $kind): string => $temporary.'/中文 known-folders/'.$kind;
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
        $diagnostic['current_windows_probe'] = entryWindowsProbe($instance, $entry['file'], $temporary);
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
    $stage = 'repeated_registration';
    $stateHash = hash_file('sha256', $instance->root.'/desktop-entry.json');
    $shortcutHashes = array_map(static fn (array $shortcut): string => hash_file('sha256', $shortcut['path']), $entry['shortcuts']);
    $repeated = DesktopEntry::install($instance, true, $knownFolders);
    if ($repeated !== $entry) {
        $diagnostic['repeated_registration'] = [
            'warnings' => array_values(array_filter($repeated['warnings'], static fn ($value): bool => in_array($value, $warningCodes, true))),
            'shortcut_count' => count($repeated['shortcuts']),
            'shortcut_kinds' => array_values(array_filter(array_column($repeated['shortcuts'], 'kind'),
                static fn ($value): bool => in_array($value, ['desktop', 'start_menu', 'applications'], true))),
            'flags' => ['name_unchanged' => $repeated['name'] === $entry['name'], 'canonical_unchanged' => $repeated['file'] === $entry['file'],
                'state_sha_changed' => hash_file('sha256', $instance->root.'/desktop-entry.json') !== $stateHash,
                'shortcut_bytes_unchanged' => array_map(static fn (array $shortcut): string => hash_file('sha256', $shortcut['path']), $entry['shortcuts']) === $shortcutHashes],
        ];
        if ($windows) {
            [$bridge, $expectedBridge] = (new ReflectionMethod(DesktopEntry::class, 'windowsShortcutBridge'))->invoke(null, $entry['file']);
            $diagnostic['repeated_registration']['flags']['bridge_bytes_match'] = @file_get_contents($bridge) === $expectedBridge;
            $diagnostic['published_inspect'] = entryPublishedInspectProbe($instance, $entry['file'], $entry['shortcuts'][0]['path'], $temporary);
        }
    }
    entryRequire($repeated === $entry, 'repeated_registration_changed_entry');
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
    $executionTimes = ['canonical_success' => $success['elapsed_ms']];
    entryRequire($success['code'] === 0, 'canonical_success_exit_nonzero');
    entryRequire(file($instance->root.'/invocations', FILE_IGNORE_NEW_LINES) === ['open'], 'canonical_did_not_only_call_open');
    $stage = 'canonical_failure';
    Instance::atomicFile($instance->root.'/fail', "fixture\n");
    $failure = entryExecute($command, $temporary, $environment);
    $executionTimes['canonical_failure'] = $failure['elapsed_ms'];
    entryRequire($failure['code'] === ($windows ? 1 : 7), 'canonical_failure_exit_code_lost');
    entryRequire(file($instance->root.'/invocations', FILE_IGNORE_NEW_LINES) === ['open', 'open'], 'canonical_failure_called_another_command');
    if ($windows) {
        entryRequire(str_contains($failure['stdout'].$failure['stderr'], 'Please run yanji doctor.'),
            'windows_failure_not_visible');
        entryRequire(str_contains($failure['stdout'].$failure['stderr'], '言及暂时无法打开，请保留此窗口中的错误信息并反馈。'),
            'windows_chinese_failure_not_visible');
    }
    $shortcutCases = 0;
    $shortcutArgumentLengths = [];
    if ($windows) {
        $other = new Instance($temporary.'/other-instance');
        Instance::privateDir($other->root);
        Instance::atomicFile($other->root.'/yanji.cmd', $fake);
        $builder = (new ReflectionMethod(DesktopEntry::class, 'windowsShortcutLaunch'))->invoke(null, $entry['file']);
        entryRequire($builder[0] === Platform::windowsPowerShell() && $builder[2] === dirname(Platform::windowsPowerShell()),
            'registered_shortcut_builder_target_mismatch');
        [$bridgePath, $bridgeContents] = (new ReflectionMethod(DesktopEntry::class, 'windowsShortcutBridge'))->invoke(null, $entry['file']);
        entryRequire(dirname($bridgePath) === $instance->root && is_file($bridgePath) && !is_link($bridgePath)
            && file_get_contents($bridgePath) === $bridgeContents && Platform::isPrivate($bridgePath),
            'registered_shortcut_private_bridge_not_verified');
        $expectedArgumentsLength = intdiv(strlen(mb_convert_encoding($builder[1], 'UTF-16LE', 'UTF-8')), 2);
        entryRequire($expectedArgumentsLength <= 1023, 'registered_shortcut_arguments_exceed_wsh_limit');
        $expectedMarker = (new ReflectionClass(DesktopEntry::class))->getConstant('FORMAT').':'.hash('sha256', $instance->root);
        $metadataCapture = $temporary.'/registered-link-metadata.json';
        $shortcutScript = <<<'PS'
$ErrorActionPreference = 'Stop'
try {
    $application = New-Object -ComObject Shell.Application
    $folder = $application.NameSpace([IO.Path]::GetDirectoryName($env:YANJI_ENTRY_REGISTERED_LINK))
    $item = $folder.ParseName([IO.Path]::GetFileName($env:YANJI_ENTRY_REGISTERED_LINK))
    $link = $item.GetLink
    if ($null -eq $link -or $link.Path -cne $env:YANJI_ENTRY_EXPECTED_TARGET -or $link.WorkingDirectory -cne $env:YANJI_ENTRY_EXPECTED_DIRECTORY -or $link.Description -cne $env:YANJI_ENTRY_EXPECTED_MARKER) { exit 41 }
    $arguments = $item.ExtendedProperty('System.Link.Arguments')
    if ($arguments -isnot [string] -or $arguments -cne $env:YANJI_ENTRY_EXPECTED_ARGUMENTS) { exit 42 }
    if (-not ($arguments -cmatch '\A-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "([^"\r\n]+)"\z')) { exit 45 }
    $bridge = $Matches[1]
    if ($bridge -cne $env:YANJI_ENTRY_EXPECTED_BRIDGE -or [IO.Path]::GetDirectoryName($bridge) -cne $env:YANJI_ENTRY_EXPECTED_INSTANCE_ROOT) { exit 46 }
    if (([IO.File]::GetAttributes($bridge) -band [IO.FileAttributes]::ReparsePoint) -ne 0) { exit 47 }
    $decoded = [IO.File]::ReadAllText($bridge)
    $expectedBridge = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($env:YANJI_ENTRY_EXPECTED_BRIDGE_CONTENTS))
    if ($decoded -cne $expectedBridge) { exit 48 }
    if (-not $decoded.Contains($env:YANJI_ENTRY_EXPECTED_CANONICAL_BASE64) -or $decoded -match 'ticket=|APP_KEY|Bearer\s') { exit 43 }
    $metadata = @{expected_arguments_length=([string]$env:YANJI_ENTRY_EXPECTED_ARGUMENTS).Length;extended_arguments_length=$arguments.Length;shell_link_arguments_length=([string]$link.Arguments).Length;full_arguments_match=$true;private_bridge_matches=$true}
    [IO.File]::WriteAllText($env:YANJI_ENTRY_METADATA_CAPTURE, ($metadata | ConvertTo-Json -Compress), [Text.UTF8Encoding]::new($false))
    $process = Start-Process -FilePath $link.Path -ArgumentList $arguments -WorkingDirectory $link.WorkingDirectory -NoNewWindow -PassThru -Wait
    exit $process.ExitCode
} catch { exit 44 }
PS;
        $shortcutCommand = [Platform::windowsPowerShell(), '-NoProfile', '-NonInteractive', '-EncodedCommand',
            Platform::encodePowerShellCommand($shortcutScript)];
        foreach ($entry['shortcuts'] as $shortcut) {
            $shortcutEnvironment = [...$environment,
                'YANJI_ENTRY_REGISTERED_LINK' => Platform::nativePath($shortcut['path']),
                'YANJI_ENTRY_EXPECTED_TARGET' => Platform::windowsPowerShell(),
                'YANJI_ENTRY_EXPECTED_DIRECTORY' => dirname(Platform::windowsPowerShell()),
                'YANJI_ENTRY_EXPECTED_ARGUMENTS' => $builder[1], 'YANJI_ENTRY_EXPECTED_MARKER' => $expectedMarker,
                'YANJI_ENTRY_EXPECTED_BRIDGE' => Platform::nativePath($bridgePath),
                'YANJI_ENTRY_EXPECTED_BRIDGE_CONTENTS' => base64_encode($bridgeContents),
                'YANJI_ENTRY_EXPECTED_INSTANCE_ROOT' => Platform::nativePath($instance->root),
                'YANJI_ENTRY_METADATA_CAPTURE' => Platform::nativePath($metadataCapture),
                'YANJI_ENTRY_EXPECTED_CANONICAL_BASE64' => base64_encode(Platform::nativePath($entry['file']))];
            foreach ([false, true] as $fail) {
                if ($fail) {
                    Instance::atomicFile($instance->root.'/fail', "fixture\n");
                } else {
                    unlink($instance->root.'/fail');
                }
                @unlink($metadataCapture);
                $stage = 'registered_'.$shortcut['kind'].($fail ? '_failure' : '_success');
                $executed = entryExecute($shortcutCommand, $temporary, $shortcutEnvironment);
                $executionTimes[$stage] = $executed['elapsed_ms'];
                $diagnostic['last_execution'] = ['exit_code' => $executed['code'], 'elapsed_ms' => $executed['elapsed_ms'],
                    'stdout_bytes' => strlen($executed['stdout']), 'stderr_bytes' => strlen($executed['stderr'])];
                entryRequire($executed['code'] === ($fail ? 1 : 0), 'registered_shortcut_exit_code_mismatch');
                $metadata = json_decode((string) @file_get_contents($metadataCapture), true);
                entryRequire(is_array($metadata) && ($metadata['full_arguments_match'] ?? null) === true
                    && ($metadata['private_bridge_matches'] ?? null) === true
                    && ($metadata['expected_arguments_length'] ?? null) === $expectedArgumentsLength
                    && ($metadata['extended_arguments_length'] ?? null) === $expectedArgumentsLength
                    && is_int($metadata['shell_link_arguments_length'] ?? null)
                    && $metadata['shell_link_arguments_length'] >= 0 && $metadata['shell_link_arguments_length'] <= 32768,
                    'registered_shortcut_full_arguments_not_verified');
                $shortcutArgumentLengths[$shortcut['kind']] = $metadata;
                if ($fail) {
                    entryRequire(str_contains($executed['stdout'].$executed['stderr'], 'Please run yanji doctor.'),
                        'registered_shortcut_english_failure_not_visible');
                    entryRequire(str_contains($executed['stdout'].$executed['stderr'], '言及暂时无法打开，请保留此窗口中的错误信息并反馈。'),
                        'registered_shortcut_chinese_failure_not_visible');
                }
                $shortcutCases++;
                entryRequire(file($instance->root.'/invocations', FILE_IGNORE_NEW_LINES) === array_fill(0, 2 + $shortcutCases, 'open'),
                    'registered_shortcut_did_not_only_call_current_open');
                entryRequire(!file_exists($other->root.'/invocations'), 'registered_shortcut_reached_another_instance');
            }
        }
    }
    entryRequire($shortcutCases === ($windows ? 4 : 0), 'registered_shortcut_case_count_mismatch');
    entryRequire(file_get_contents($launcher) === $fake && file_get_contents($entry['file']) === $contents,
        'canonical_execution_changed_launchers');
    echo json_encode(['fixture' => 'native-desktop-entry', 'platform' => PHP_OS_FAMILY,
        'supplied_entry_class_verified' => true, 'temporary_known_folders_only' => true,
        'native_shortcuts_registered' => true, 'canonical_only_invoked_open' => true,
        'canonical_failure_exit_code' => $failure['code'], 'windows_error_visible' => $windows ? true : null,
        'registered_shortcut_execution_cases' => $shortcutCases,
        'registered_shortcuts_bound_to_current_instance' => $windows ? true : null,
        'registered_shortcut_argument_lengths' => $shortcutArgumentLengths,
        'execution_ms' => $executionTimes,
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
