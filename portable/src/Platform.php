<?php

declare(strict_types=1);

namespace Yanji\Portable;

use RuntimeException;

/** Isolate OS-specific paths, permissions, process groups and detachment between Unix and Windows. */
final class Platform
{
    public static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    public static function nullDevice(): string
    {
        return self::isWindows() ? 'NUL' : '/dev/null';
    }

    public static function lockMode(): string
    {
        return self::isWindows() ? 'cb' : 'ce';
    }

    public static function normalize(string $path): string
    {
        $normalized = self::isWindows() ? str_replace('\\', '/', $path) : $path;
        if (self::isWindows() && preg_match('/\A[A-Za-z]:\/\z/', $normalized)) {
            return $normalized;
        }
        return rtrim($normalized, '/');
    }

    public static function nativePath(string $path): string
    {
        return self::isWindows() ? str_replace('/', '\\', $path) : $path;
    }

    public static function isAbsolute(string $path): bool
    {
        if (self::isWindows()) {
            return preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) === 1;
        }
        return str_starts_with($path, '/');
    }

    public static function isRoot(string $path): bool
    {
        $norm = self::normalize($path);
        if (self::isWindows()) {
            return preg_match('/\A[A-Za-z]:\/?\z/', $norm) === 1;
        }
        return $norm === '' || $norm === '/';
    }

    public static function parentDir(string $path): string
    {
        return self::normalize(dirname($path));
    }

    public static function isExecutable(string $path): bool
    {
        if (self::isWindows()) {
            return is_file($path) && preg_match('/\.(?:exe|cmd|bat|com)$/i', $path) === 1;
        }
        return is_executable($path);
    }

    public static function phpBinary(string $release): string
    {
        return $release.'/runtime/bin/'.(self::isWindows() ? 'php.exe' : 'php');
    }

    public static function webBinary(string $release): string
    {
        return $release.'/runtime/bin/'.(self::isWindows() ? 'frankenphp.exe' : 'frankenphp');
    }

    public static function launcherName(): string
    {
        return self::isWindows() ? 'yanji.cmd' : 'yanji';
    }

    private static ?array $windowsIdentity = null;

    public static function windowsUserIdentity(): array
    {
        if (self::$windowsIdentity !== null) {
            return self::$windowsIdentity;
        }
        $proc = @proc_open(['whoami', '/user', '/fo', 'csv', '/nh'],
            [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['file', 'NUL', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('无法获取当前 Windows 用户安全标识（SID）。');
        }
        $out = trim((string) stream_get_contents($pipes[1], 1024));
        fclose($pipes[1]);
        if (proc_close($proc) !== 0 || !preg_match('/\A"([^"]+)","(S-1-[0-9-]+)"\z/', $out, $m)) {
            throw new RuntimeException('无法解析当前 Windows 用户安全标识（SID）。');
        }
        self::$windowsIdentity = ['account' => $m[1], 'sid' => $m[2]];
        return self::$windowsIdentity;
    }

    public static function windowsUserSid(): string
    {
        return self::windowsUserIdentity()['sid'];
    }

    private static function windowsPowerShell(): string
    {
        $sysRoot = getenv('SystemRoot') ?: (getenv('WINDIR') ?: 'C:\\Windows');
        $ps = rtrim($sysRoot, '\\/').'\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
        return is_file($ps) ? $ps : 'powershell.exe';
    }

    public static function protectPrivate(string $path, bool $isDir, bool $executable = false): void
    {
        if (is_link($path) || ($isDir ? !is_dir($path) : !is_file($path))) {
            throw new RuntimeException('实例含无法保护的文件或链接，请核对所有者和备份。');
        }
        if (!self::isWindows()) {
            if (fileowner($path) !== posix_geteuid() || !chmod($path, $isDir ? 0700 : ($executable ? 0700 : 0600))) {
                throw new RuntimeException('实例含无法保护的文件或链接，请核对所有者和备份。');
            }
            return;
        }
        $sid = self::windowsUserSid();
        $grant = '*'.$sid.($isDir ? ':(OI)(CI)F' : ':F');
        $proc = @proc_open(['icacls', self::nativePath($path), '/inheritance:r', '/grant:r', $grant],
            [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes);
        if (!is_resource($proc) || proc_close($proc) !== 0) {
            throw new RuntimeException('无法在 Windows 上设置实例目录或文件的独占权限。');
        }
    }

    public static function isPrivate(string $path): bool
    {
        if (!file_exists($path) || is_link($path)) {
            return false;
        }
        if (!self::isWindows()) {
            return fileowner($path) === posix_geteuid() && (fileperms($path) & 0077) === 0;
        }
        $proc = @proc_open(['icacls', self::nativePath($path)],
            [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['file', 'NUL', 'w']], $pipes);
        if (!is_resource($proc)) {
            return false;
        }
        $output = (string) stream_get_contents($pipes[1], 16384);
        fclose($pipes[1]);
        if (proc_close($proc) !== 0 || $output === '') {
            return false;
        }
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $output) ?: [])));
        // Strip trailing summary line (e.g., "Successfully processed 1 files...").
        if (count($lines) > 1) {
            array_pop($lines);
        }
        if ($lines === []) {
            return false;
        }
        $native = self::nativePath($path);
        if (str_starts_with(strtolower($lines[0]), strtolower($native))) {
            $lines[0] = ltrim(substr($lines[0], strlen($native)));
        }
        $identity = self::windowsUserIdentity();
        $allowed = [strtolower($identity['account']), strtolower($identity['sid'])];
        foreach ($lines as $ace) {
            if ($ace === '') {
                continue;
            }
            $colon = strpos($ace, ':');
            if ($colon === false) {
                return false;
            }
            $principal = strtolower(trim(substr($ace, 0, $colon)));
            if (!in_array($principal, $allowed, true)) {
                return false;
            }
        }
        return true;
    }

    public static function assertProtectedAncestors(string $path): void
    {
        if (!self::isWindows()) {
            for ($dir = $path; ; $dir = dirname($dir)) {
                if (file_exists($dir)) {
                    $stat = lstat($dir);
                    $systemTemporary = $stat['uid'] === 0 && ($stat['mode'] & 01000) !== 0;
                    if (($stat['mode'] & 0170000) !== 0040000 || !in_array($stat['uid'], [0, posix_geteuid()], true)
                        || ($stat['mode'] & 0022) !== 0 && !$systemTemporary) {
                        throw new RuntimeException('程序目录的祖先可被其他用户替换，请选择当前用户的私有目录。');
                    }
                }
                if ($dir === '/') {
                    break;
                }
            }
            return;
        }
        $norm = self::normalize($path);
        if (preg_match('/\A[A-Za-z]:\/(?:Users\/Public|ProgramData)(?:\/|\z)/i', $norm)) {
            throw new RuntimeException('程序或实例目录不能位于公共共享目录，请选择当前用户的私有目录。');
        }
        $dir = $norm;
        while (true) {
            if (is_link($dir)) {
                throw new RuntimeException('目标祖先路径包含符号链接，拒绝初始化。');
            }
            if (self::isRoot($dir)) {
                break;
            }
            $next = self::parentDir($dir);
            if ($next === $dir) {
                break;
            }
            $dir = $next;
        }
    }

    public static function killProcessTree(int $pid): bool
    {
        if ($pid <= 0) {
            return true;
        }
        if (!self::isWindows()) {
            return posix_kill(-$pid, SIGKILL) || posix_get_last_error() === 3;
        }
        $proc = @proc_open(['taskkill', '/PID', (string) $pid, '/T', '/F'],
            [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes);
        if (!is_resource($proc)) {
            return false;
        }
        $code = proc_close($proc);
        // 0 = terminated, 128 = process already exited.
        return $code === 0 || $code === 128;
    }

    public static function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (!self::isWindows()) {
            return posix_kill($pid, 0);
        }
        $proc = @proc_open(['tasklist', '/FI', 'PID eq '.$pid, '/NH', '/FO', 'CSV'],
            [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['file', 'NUL', 'w']], $pipes);
        if (!is_resource($proc)) {
            return false;
        }
        $out = (string) stream_get_contents($pipes[1], 4096);
        fclose($pipes[1]);
        proc_close($proc);
        return str_contains($out, ',"'.$pid.'",');
    }

    public static function spawnDetachedWindows(string $exe, array $args, string $cwd, string $stdoutLog, string $stderrLog, array $env): int
    {
        $ps51 = <<<'PS'
$ErrorActionPreference = 'Stop'
$rawArgs = @((ConvertFrom-Json $env:YANJI_DETACH_ARGS))
$quoted = foreach ($a in $rawArgs) {
    $s = [string]$a
    if ($s -eq '' -or $s -match '[\s"]') {
        $s = $s -replace '(\\*)"', '$1$1\"'
        $s = $s -replace '(\\+)$', '$1$1'
        '"' + $s + '"'
    } else {
        $s
    }
}
$argString = ($quoted -join ' ')
$p = Start-Process -FilePath $env:YANJI_DETACH_EXE -ArgumentList $argString -WorkingDirectory $env:YANJI_DETACH_CWD -WindowStyle Hidden -RedirectStandardOutput $env:YANJI_DETACH_OUT -RedirectStandardError $env:YANJI_DETACH_ERR -PassThru
Write-Output $p.Id
PS;
        $launchEnv = $env + [
            'YANJI_DETACH_EXE' => self::nativePath($exe),
            'YANJI_DETACH_ARGS' => json_encode(array_values($args), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'YANJI_DETACH_CWD' => self::nativePath($cwd),
            'YANJI_DETACH_OUT' => self::nativePath($stdoutLog),
            'YANJI_DETACH_ERR' => self::nativePath($stderrLog),
        ];
        $proc = @proc_open(
            [self::windowsPowerShell(), '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-Command', $ps51],
            [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            $launchEnv,
            ['bypass_shell' => true],
        );
        if (!is_resource($proc)) {
            throw new RuntimeException('无法在 Windows 上启动实例托管器。');
        }
        $out = trim((string) stream_get_contents($pipes[1], 1024));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0 || !preg_match('/\A\d+\z/', $out)) {
            throw new RuntimeException('无法在 Windows 上确认后台托管器进程号。');
        }
        return (int) $out;
    }

    public static function createDirectoryJunction(string $link, string $target): void
    {
        if (is_dir($link) || is_link($link)) {
            @rmdir(self::nativePath($link));
        }
        $env = getenv();
        $env['YANJI_J_LINK'] = self::nativePath($link);
        $env['YANJI_J_TARGET'] = self::nativePath($target);
        $ps = 'New-Item -ItemType Junction -Path $env:YANJI_J_LINK -Target $env:YANJI_J_TARGET -Force | Out-Null';
        $proc = @proc_open(
            [self::windowsPowerShell(), '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-Command', $ps],
            [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']],
            $pipes,
            null,
            $env,
            ['bypass_shell' => true],
        );
        if (!is_resource($proc) || proc_close($proc) !== 0 || !is_dir($link)) {
            throw new RuntimeException('无法创建 Windows 公共存储目录联接。');
        }
    }
}
