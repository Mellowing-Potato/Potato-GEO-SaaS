<?php

declare(strict_types=1);

namespace Yanji\Portable;

use RuntimeException;
use Throwable;

require_once __DIR__.'/Instance.php';

/** Stable per-instance launchers. Authorization is issued only when `yanji open` runs. */
final class DesktopEntry
{
    private const FORMAT = 'yanji-desktop-entry-v1';
    private const WARNINGS = ['entry_unavailable', 'entry_conflict', 'launcher_unavailable',
        'desktop_unavailable', 'start_menu_unavailable', 'applications_unavailable',
        'shortcut_conflict', 'shortcut_failed', 'entry_state_conflict', 'entry_state_unavailable'];

    /** Test hooks resolve known folders and execute fixed PowerShell scripts without touching a real desktop. */
    public static function install(Instance $instance, bool $shortcuts = true, ?callable $knownFolders = null,
        ?callable $powerShell = null, ?string $platform = null): array
    {
        $platform ??= PHP_OS_FAMILY;
        $result = ['name' => '言及工作台', 'file' => null, 'shortcuts' => [], 'warnings' => []];
        try {
            [$name, $file, $contents] = self::entry($instance, $platform);
            $result['name'] = $name;
            $launcher = $instance->root.'/'.($platform === 'Windows' ? 'yanji.cmd' : 'yanji');
            if (!self::regular($launcher) || ($platform !== 'Windows' && !is_executable($launcher))) {
                return [...$result, 'warnings' => ['launcher_unavailable']];
            }
            Platform::assertProtectedAncestors($instance->root);
            if (!self::publishText($file, $contents)) {
                return [...$result, 'warnings' => ['entry_conflict']];
            }
            $result['file'] = $file;
        } catch (Throwable) {
            return [...$result, 'warnings' => ['entry_unavailable']];
        }

        $state = self::state($instance, $platform);
        if ($shortcuts) {
            $kinds = match ($platform) {
                'Windows' => ['desktop', 'start_menu'], 'Darwin' => ['desktop'], default => ['applications'],
            };
            foreach ($kinds as $kind) {
                $temporary = null;
                try {
                    $directory = $knownFolders !== null ? $knownFolders($kind)
                        : self::knownFolder($kind, $platform, $powerShell);
                    $directory = self::folder($directory);
                    if ($directory === null) {
                        $result['warnings'][] = $kind.'_unavailable';
                        continue;
                    }
                    $path = $directory.'/'.$name.($platform === 'Windows' ? '.lnk' : ($platform === 'Darwin' ? '.command' : '.desktop'));
                    if ($platform === 'Windows') {
                        if (file_exists($path) || is_link($path)) {
                            if (!self::regular($path) || !self::windowsShortcut($instance, $file, $path, true, $powerShell)) {
                                $result['warnings'][] = 'shortcut_conflict';
                                continue;
                            }
                        } else {
                            $temporary = $directory.'/.yanji-entry-'.bin2hex(random_bytes(12)).'.lnk';
                            if (!self::windowsShortcut($instance, $file, $temporary, false, $powerShell)
                                || !self::regular($temporary)
                                || !self::publishWindowsShortcut($temporary, $path, $powerShell)) {
                                $result['warnings'][] = 'shortcut_failed';
                                continue;
                            }
                        }
                    } elseif (!self::publishText($path, $contents)) {
                        $result['warnings'][] = 'shortcut_conflict';
                        continue;
                    }
                    $result['shortcuts'][] = ['kind' => $kind, 'path' => $path];
                } catch (Throwable) {
                    $result['warnings'][] = 'shortcut_failed';
                } finally {
                    if ($temporary !== null && self::regular($temporary)) {
                        @unlink($temporary);
                    }
                }
            }
        } elseif ($state !== null) {
            $result['shortcuts'] = self::verifiedShortcuts($instance, $platform, $state);
        }

        $statePath = $instance->root.'/desktop-entry.json';
        if ((file_exists($statePath) || is_link($statePath)) && $state === null) {
            $result['warnings'][] = 'entry_state_conflict';
        } else {
            try {
                $record = ['format' => self::FORMAT, 'instance' => hash('sha256', $instance->root),
                    'platform' => $platform, 'shortcuts' => [], 'warnings' => array_values(array_unique($result['warnings']))];
                foreach ($result['shortcuts'] as $shortcut) {
                    $record['shortcuts'][] = [...$shortcut, 'sha256' => hash_file('sha256', $shortcut['path'])];
                }
                Instance::atomicFile($statePath, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
            } catch (Throwable) {
                $result['warnings'][] = 'entry_state_unavailable';
            }
        }
        $result['warnings'] = array_values(array_unique($result['warnings']));
        return $result;
    }

    /** Purely read files; never run a desktop process or trust a stored canonical entry path. */
    public static function describe(Instance $instance, ?string $platform = null): array
    {
        $platform ??= PHP_OS_FAMILY;
        $result = ['name' => '言及工作台', 'file' => null, 'shortcuts' => [], 'warnings' => []];
        try {
            [$name, $file, $contents] = self::entry($instance, $platform);
            $result['name'] = $name;
            $launcher = $instance->root.'/'.($platform === 'Windows' ? 'yanji.cmd' : 'yanji');
            if (!self::regular($launcher) || ($platform !== 'Windows' && !is_executable($launcher))) {
                return [...$result, 'warnings' => ['launcher_unavailable']];
            }
            if (!self::matches($file, $contents)) {
                return [...$result, 'warnings' => [file_exists($file) || is_link($file) ? 'entry_conflict' : 'entry_unavailable']];
            }
            $result['file'] = $file;
            $state = self::state($instance, $platform);
            if ($state !== null) {
                $result['shortcuts'] = self::verifiedShortcuts($instance, $platform, $state);
                $result['warnings'] = array_values(array_unique(array_filter($state['warnings'] ?? [],
                    static fn ($value): bool => is_string($value) && in_array($value, self::WARNINGS, true))));
                if (count($result['shortcuts']) !== count($state['shortcuts'] ?? [])) {
                    $result['warnings'][] = 'shortcut_conflict';
                }
            } elseif (file_exists($instance->root.'/desktop-entry.json') || is_link($instance->root.'/desktop-entry.json')) {
                $result['warnings'][] = 'entry_state_conflict';
            }
        } catch (Throwable) {
            $result['warnings'][] = 'entry_unavailable';
        }
        return $result;
    }

    private static function entry(Instance $instance, string $platform): array
    {
        if (!in_array($platform, ['Windows', 'Darwin', 'Linux'], true)) {
            throw new RuntimeException('Unsupported desktop platform.');
        }
        $id = substr(hash('sha256', $instance->root), 0, 8);
        $name = '言及工作台-'.$instance->port().'-'.$id;
        if ($platform === 'Windows') {
            // The child command expands the root once, without CALL's second expansion.
            // Keep a failed double-click visible instead of immediately closing its console.
            $script = <<<'PS'
$ErrorActionPreference = 'Stop'
try {
    $exe = [IO.Path]::Combine($env:SystemRoot, 'System32\cmd.exe')
    $process = Start-Process -FilePath $exe -ArgumentList '/d /v:off /s /c ""%YANJI_ENTRY_ROOT%yanji.cmd" open"' -NoNewWindow -PassThru -Wait
    exit $process.ExitCode
} catch { exit 1 }
PS;
            $contents = "@echo off\r\nsetlocal DisableDelayedExpansion\r\nchcp 65001 >nul\r\nrem ".self::FORMAT.' '.$id
                ."\r\nset \"YANJI_ENTRY_ROOT=%~dp0\"\r\n\"%SystemRoot%\\System32\\WindowsPowerShell\\v1.0\\powershell.exe\" -NoProfile -NonInteractive -EncodedCommand "
                .Platform::encodePowerShellCommand($script)."\r\nif errorlevel 1 (\r\n  echo 言及暂时无法打开，请保留此窗口中的错误信息并反馈。按任意键关闭窗口。 1>&2\r\n  echo Yanji could not open this workspace. Please run yanji doctor. 1>&2\r\n  pause\r\n  exit /b 1\r\n)\r\n";
            return [$name, $instance->root.'/'.$name.'.cmd', $contents];
        }
        if ($platform === 'Darwin') {
            $contents = "#!/bin/sh\nset -eu\n# ".self::FORMAT.' '.$id."\nexec "
                ."'".str_replace("'", "'\"'\"'", $instance->root.'/yanji')."' open\n";
            return [$name, $instance->root.'/'.$name.'.command', $contents];
        }
        $executable = strtr($instance->root.'/yanji', ['\\' => '\\\\', '"' => '\\"', '`' => '\\`', '$' => '\\$', '%' => '%%']);
        // Desktop Entry string escapes are decoded before Exec argument quoting.
        $executable = str_replace('\\', '\\\\', $executable);
        $contents = "[Desktop Entry]\nType=Application\nVersion=1.0\nName=".$name
            ."\nComment=Open this local Yanji workspace\nExec=/bin/sh \"".$executable."\" open\nTerminal=false\nCategories=Office;\nX-Yanji-Entry=".self::FORMAT.'-'.$id."\n";
        return [$name, $instance->root.'/'.$name.'.desktop', $contents];
    }

    private static function regular(string $path): bool
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        return $stat !== false && ($stat['mode'] & 0170000) === 0100000
            && (Platform::isWindows() || $stat['uid'] === posix_geteuid());
    }

    private static function matches(string $path, string $contents): bool
    {
        return self::regular($path) && @filesize($path) === strlen($contents) && @file_get_contents($path) === $contents
            && (Platform::isWindows() || is_executable($path));
    }

    /** Publish with a same-directory hard link, which cannot replace an existing file or link. */
    private static function publishText(string $path, string $contents): bool
    {
        if (file_exists($path) || is_link($path)) {
            return self::matches($path, $contents);
        }
        $temporary = dirname($path).'/.yanji-entry-'.bin2hex(random_bytes(12));
        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Cannot create desktop entry.');
        }
        try {
            if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)
                || (!Platform::isWindows() && !chmod($temporary, 0700))) {
                throw new RuntimeException('Cannot write desktop entry.');
            }
            fclose($handle);
            $handle = null;
            return @link($temporary, $path) || self::matches($path, $contents);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            @unlink($temporary);
        }
    }

    /** Publish a Windows .lnk without overwriting; falls back to non-replacing File.Move on OneDrive folders. */
    private static function publishWindowsShortcut(string $temporary, string $path, ?callable $runner): bool
    {
        if (@link($temporary, $path)) {
            return true;
        }
        if (file_exists($path) || is_link($path)) {
            return false;
        }
        $record = self::powerShell('[IO.File]::Move($env:YANJI_ENTRY_TEMP, $env:YANJI_ENTRY_DEST)', [
            'YANJI_ENTRY_TEMP' => str_replace('/', '\\', $temporary),
            'YANJI_ENTRY_DEST' => str_replace('/', '\\', $path),
        ], $runner);
        return $record['code'] === 0 && $record['stdout'] === '' && self::regular($path);
    }

    private static function folder(mixed $path): ?string
    {
        if (!is_string($path) || !Platform::isAbsolute($path) || preg_match('/[\x00-\x1f]/', $path)) {
            return null;
        }
        $physical = Instance::physicalPath($path);
        Platform::assertProtectedAncestors($physical);
        if (!is_dir($physical) && !@mkdir($physical, 0700, true) && !is_dir($physical)) {
            return null;
        }
        return Platform::normalize((string) realpath($physical));
    }

    private static function knownFolder(string $kind, string $platform, ?callable $runner): ?string
    {
        if ($platform === 'Windows') {
            $special = $kind === 'desktop' ? 'DesktopDirectory' : 'Programs';
            $record = self::powerShell("[Console]::OutputEncoding = [Text.UTF8Encoding]::new(\$false); [Console]::Write([Environment]::GetFolderPath('".$special."'))", [], $runner);
            return $record['code'] === 0 ? trim($record['stdout']) : null;
        }
        $home = getenv('HOME');
        if ($platform === 'Darwin') {
            return is_string($home) && $home !== '' ? $home.'/Desktop' : null;
        }
        $data = getenv('XDG_DATA_HOME');
        return is_string($data) && $data !== '' ? $data.'/applications'
            : (is_string($home) && $home !== '' ? $home.'/.local/share/applications' : null);
    }

    private static function windowsShortcut(Instance $instance, string $file, string $path, bool $inspect, ?callable $runner): bool
    {
        [$target, $arguments, $workingDirectory] = self::windowsShortcutLaunch($file);
        [$bridgePath, $bridgeContents] = self::windowsShortcutBridge($file);
        if (!self::publishText($bridgePath, $bridgeContents)) {
            return false;
        }
        $script = <<<'PS'
$ErrorActionPreference = 'Stop'
# IShellLinkW and IPersistFile use Unicode for both properties and the destination filename.
Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;
using System.Runtime.InteropServices.ComTypes;
using System.Text;
[ComImport, Guid("000214F9-0000-0000-C000-000000000046"), InterfaceType(ComInterfaceType.InterfaceIsIUnknown)]
interface YanjiIShellLinkW {
    void GetPath([Out, MarshalAs(UnmanagedType.LPWStr)] StringBuilder path, int count, IntPtr data, uint flags);
    void GetIDList(out IntPtr idList);
    void SetIDList(IntPtr idList);
    void GetDescription([Out, MarshalAs(UnmanagedType.LPWStr)] StringBuilder text, int count);
    void SetDescription([MarshalAs(UnmanagedType.LPWStr)] string text);
    void GetWorkingDirectory([Out, MarshalAs(UnmanagedType.LPWStr)] StringBuilder path, int count);
    void SetWorkingDirectory([MarshalAs(UnmanagedType.LPWStr)] string path);
    void GetArguments([Out, MarshalAs(UnmanagedType.LPWStr)] StringBuilder arguments, int count);
    void SetArguments([MarshalAs(UnmanagedType.LPWStr)] string arguments);
    void GetHotkey(out short hotkey);
    void SetHotkey(short hotkey);
    void GetShowCmd(out int command);
    void SetShowCmd(int command);
    void GetIconLocation([Out, MarshalAs(UnmanagedType.LPWStr)] StringBuilder path, int count, out int index);
    void SetIconLocation([MarshalAs(UnmanagedType.LPWStr)] string path, int index);
    void SetRelativePath([MarshalAs(UnmanagedType.LPWStr)] string path, uint reserved);
    void Resolve(IntPtr window, uint flags);
    void SetPath([MarshalAs(UnmanagedType.LPWStr)] string path);
}
public static class YanjiPortableShellLink {
    public static void Create(string file, string target, string arguments, string description, string directory) {
        object value = Activator.CreateInstance(Type.GetTypeFromCLSID(new Guid("00021401-0000-0000-C000-000000000046")));
        try {
            var link = (YanjiIShellLinkW)value;
            link.SetPath(target);
            link.SetArguments(arguments);
            link.SetDescription(description);
            link.SetWorkingDirectory(directory);
            ((IPersistFile)value).Save(file, false);
        } finally { Marshal.FinalReleaseComObject(value); }
    }
    public static string[] Read(string file) {
        object value = Activator.CreateInstance(Type.GetTypeFromCLSID(new Guid("00021401-0000-0000-C000-000000000046")));
        try {
            ((IPersistFile)value).Load(file, 0);
            var link = (YanjiIShellLinkW)value;
            var path = new StringBuilder(4096);
            var arguments = new StringBuilder(4096);
            var description = new StringBuilder(4096);
            var directory = new StringBuilder(4096);
            link.GetPath(path, path.Capacity, IntPtr.Zero, 0);
            link.GetArguments(arguments, arguments.Capacity);
            link.GetDescription(description, description.Capacity);
            link.GetWorkingDirectory(directory, directory.Capacity);
            return new string[] { path.ToString(), arguments.ToString(), description.ToString(), directory.ToString() };
        } finally { Marshal.FinalReleaseComObject(value); }
    }
}
'@
function Open-SavedLinkItem {
    $application = New-Object -ComObject Shell.Application
    $folder = $application.NameSpace([IO.Path]::GetDirectoryName($env:YANJI_ENTRY_SHORTCUT))
    if ($null -eq $folder) { throw 'Shortcut folder is unavailable.' }
    $item = $folder.ParseName([IO.Path]::GetFileName($env:YANJI_ENTRY_SHORTCUT))
    if ($null -eq $item) { throw 'Shortcut item is unavailable.' }
    return $item
}
function Read-FullLinkArguments {
    $item = Open-SavedLinkItem
    $arguments = $item.ExtendedProperty('System.Link.Arguments')
    if ($arguments -isnot [string]) { throw 'Shortcut arguments are unavailable.' }
    return $arguments
}
if ($env:YANJI_ENTRY_INSPECT -eq '1') {
    $link = $null
    $fullArguments = ''
    try {
        $link = (Open-SavedLinkItem).GetLink
        if ($null -eq $link) { throw 'Shortcut reader is unavailable.' }
        $fullArguments = Read-FullLinkArguments
    } catch {
        $raw = [YanjiPortableShellLink]::Read($env:YANJI_ENTRY_SHORTCUT)
        $link = [PSCustomObject]@{ Path = $raw[0]; Description = $raw[2]; WorkingDirectory = $raw[3] }
        $fullArguments = $raw[1]
    }
    $current = $link.Path -ceq $env:YANJI_ENTRY_TARGET -and $fullArguments -ceq $env:YANJI_ENTRY_ARGUMENTS -and $link.WorkingDirectory -ceq $env:YANJI_ENTRY_WORKING_DIRECTORY
    $legacy = $link.Path -ceq $env:YANJI_ENTRY_FILE -and $fullArguments -ceq '' -and $link.WorkingDirectory -ceq $env:YANJI_ENTRY_DIRECTORY
    if ($link.Description -cne $env:YANJI_ENTRY_MARKER -or -not ($current -or $legacy)) { exit 3 }
} else {
    [YanjiPortableShellLink]::Create($env:YANJI_ENTRY_SHORTCUT, $env:YANJI_ENTRY_TARGET, $env:YANJI_ENTRY_ARGUMENTS, $env:YANJI_ENTRY_MARKER, $env:YANJI_ENTRY_WORKING_DIRECTORY)
    $link = $null
    $fullArguments = ''
    try {
        $link = (Open-SavedLinkItem).GetLink
        if ($null -eq $link) { throw 'Shortcut reader is unavailable.' }
        $fullArguments = Read-FullLinkArguments
    } catch {
        $raw = [YanjiPortableShellLink]::Read($env:YANJI_ENTRY_SHORTCUT)
        $link = [PSCustomObject]@{ Path = $raw[0]; Description = $raw[2]; WorkingDirectory = $raw[3] }
        $fullArguments = $raw[1]
    }
    if ($link.Path -cne $env:YANJI_ENTRY_TARGET -or $fullArguments -cne $env:YANJI_ENTRY_ARGUMENTS -or $link.Description -cne $env:YANJI_ENTRY_MARKER -or $link.WorkingDirectory -cne $env:YANJI_ENTRY_WORKING_DIRECTORY) { exit 3 }
}
PS;
        $record = self::powerShell($script, ['YANJI_ENTRY_FILE' => str_replace('/', '\\', $file),
            'YANJI_ENTRY_SHORTCUT' => str_replace('/', '\\', $path),
            'YANJI_ENTRY_DIRECTORY' => str_replace('/', '\\', $instance->root),
            'YANJI_ENTRY_TARGET' => $target, 'YANJI_ENTRY_ARGUMENTS' => $arguments,
            'YANJI_ENTRY_WORKING_DIRECTORY' => $workingDirectory,
            'YANJI_ENTRY_MARKER' => self::FORMAT.':'.hash('sha256', $instance->root),
            'YANJI_ENTRY_INSPECT' => $inspect ? '1' : '0'], $runner);
        return $record['code'] === 0 && $record['stdout'] === '';
    }

    private static function windowsShortcutLaunch(string $file): array
    {
        $systemRoot = str_replace('/', '\\', (string) (getenv('SystemRoot') ?: (getenv('WINDIR') ?: 'C:\\Windows')));
        if (preg_match('/\A[A-Za-z]:\\\\/', $systemRoot) !== 1 || preg_match('/[\x00-\x1f"]/', $systemRoot)) {
            throw new RuntimeException('System PowerShell is unavailable.');
        }
        $directory = rtrim($systemRoot, '\\').'\\System32\\WindowsPowerShell\\v1.0';
        $target = $directory.'\\powershell.exe';
        if (Platform::isWindows() && (!is_file($target) || is_link($target))) {
            throw new RuntimeException('System PowerShell is unavailable.');
        }
        // A stable private bridge keeps WSH arguments short without changing their meaning.
        [$bridgePath] = self::windowsShortcutBridge($file);
        $nativeBridge = str_replace('/', '\\', $bridgePath);
        if (preg_match('/[\x00-\x1f"]/', $nativeBridge)) {
            throw new RuntimeException('The private desktop bridge path is invalid.');
        }
        return [$target, '-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "'.$nativeBridge.'"', $directory];
    }

    private static function windowsShortcutBridge(string $file): array
    {
        $id = substr(hash('sha256', $file), 0, 16);
        $path = dirname($file).'/desktop-entry-'.$id.'.ps1';
        $encodedPath = base64_encode(str_replace('/', '\\', $file));
        $script = <<<'PS'
$ErrorActionPreference = 'Stop'
try {
    $env:YANJI_ENTRY_CANONICAL = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('CANONICAL_BASE64'))
    $exe = [IO.Path]::Combine($env:SystemRoot, 'System32\cmd.exe')
    $process = Start-Process -FilePath $exe -ArgumentList '/d /v:off /s /c ""%YANJI_ENTRY_CANONICAL%""' -NoNewWindow -PassThru -Wait
    exit $process.ExitCode
} catch { exit 1 }
PS;
        $script = str_replace('CANONICAL_BASE64', $encodedPath, $script);
        return [$path, '# '.self::FORMAT.' '.$id."\n".$script."\n"];
    }

    private static function powerShell(string $script, array $variables, ?callable $runner): array
    {
        if ($runner !== null) {
            return $runner($script, $variables);
        }
        $keep = array_flip(['SYSTEMROOT', 'WINDIR', 'TEMP', 'TMP', 'LOCALAPPDATA', 'APPDATA', 'USERPROFILE', 'PATH']);
        $environment = [];
        foreach (getenv() as $key => $value) {
            if (isset($keep[strtoupper($key)])) {
                $environment[$key] = $value;
            }
        }
        $environment = array_merge(Platform::windowsPowerShellEnv($environment), $variables);
        $null = Platform::nullDevice();
        $process = @proc_open([Platform::windowsPowerShell(), '-NoProfile', '-NonInteractive', '-EncodedCommand',
            Platform::encodePowerShellCommand($script)], [0 => ['file', $null, 'r'], 1 => ['pipe', 'w'],
            2 => ['file', $null, 'w']], $pipes, null, $environment, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            return ['code' => 1, 'stdout' => ''];
        }
        stream_set_blocking($pipes[1], false);
        $output = '';
        $deadline = microtime(true) + 15;
        do {
            $output .= (string) stream_get_contents($pipes[1], 8193 - min(strlen($output), 8193));
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (strlen($output) > 8192 || microtime(true) >= $deadline) {
                proc_terminate($process);
                $status['exitcode'] = 1;
                break;
            }
            usleep(10000);
        } while (true);
        fclose($pipes[1]);
        $closed = proc_close($process);
        return ['code' => $status['exitcode'] >= 0 ? $status['exitcode'] : $closed, 'stdout' => $output];
    }

    private static function state(Instance $instance, string $platform): ?array
    {
        $path = $instance->root.'/desktop-entry.json';
        if (!self::regular($path) || @filesize($path) > 16384) {
            return null;
        }
        $record = json_decode((string) @file_get_contents($path), true);
        return is_array($record) && ($record['format'] ?? null) === self::FORMAT
            && ($record['instance'] ?? null) === hash('sha256', $instance->root) && ($record['platform'] ?? null) === $platform
            && is_array($record['shortcuts'] ?? null) && is_array($record['warnings'] ?? null) ? $record : null;
    }

    private static function verifiedShortcuts(Instance $instance, string $platform, array $state): array
    {
        [$name, $file, $contents] = self::entry($instance, $platform);
        if ($platform === 'Windows') {
            [$bridgePath, $bridgeContents] = self::windowsShortcutBridge($file);
            if (!self::matches($bridgePath, $bridgeContents)) {
                return [];
            }
        }
        $verified = [];
        $kinds = $platform === 'Windows' ? ['desktop', 'start_menu'] : ($platform === 'Darwin' ? ['desktop'] : ['applications']);
        $extension = $platform === 'Windows' ? '.lnk' : ($platform === 'Darwin' ? '.command' : '.desktop');
        foreach (array_slice($state['shortcuts'], 0, 3) as $shortcut) {
            if (!is_array($shortcut) || !in_array($shortcut['kind'] ?? null, $kinds, true)
                || !is_string($shortcut['path'] ?? null) || !Platform::isAbsolute($shortcut['path'])
                || basename($shortcut['path']) !== $name.$extension || preg_match('/[\x00-\x1f]/', $shortcut['path'])
                || !is_string($shortcut['sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $shortcut['sha256']) !== 1
                || !self::regular($shortcut['path']) || !hash_equals($shortcut['sha256'], (string) @hash_file('sha256', $shortcut['path']))
                || ($platform !== 'Windows' && !self::matches($shortcut['path'], $contents))) {
                continue;
            }
            $verified[] = ['kind' => $shortcut['kind'], 'path' => $shortcut['path']];
        }
        return $verified;
    }
}
