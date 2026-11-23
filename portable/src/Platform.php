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

    /** Return CLI flags that load Yanji's runtime PHP settings reliably, even in non-ASCII Windows paths. */
    public static function phpArgs(string $release, string $instanceRoot): array
    {
        if (!self::isWindows()) {
            return ['-c', $instanceRoot.'/php.ini'];
        }
        $ca = self::windowsShortPath(self::nativePath($release.'/runtime/ca/cacert.pem'));
        $ext = self::windowsShortPath(self::nativePath($release.'/runtime/bin/ext'));
        return [
            '-n',
            '-d', 'upload_max_filesize=10M',
            '-d', 'post_max_size=20M',
            '-d', 'memory_limit=512M',
            '-d', 'display_errors=Off',
            '-d', 'log_errors=On',
            '-d', 'expose_php=Off',
            '-d', 'variables_order=EGPCS',
            '-d', 'curl.cainfo="'.$ca.'"',
            '-d', 'openssl.cafile="'.$ca.'"',
            '-d', 'extension_dir="'.$ext.'"',
            '-d', 'extension=curl',
            '-d', 'extension=fileinfo',
            '-d', 'extension=gd',
            '-d', 'extension=mbstring',
            '-d', 'extension=openssl',
            '-d', 'extension=pdo_sqlite',
            '-d', 'extension=sqlite3',
            '-d', 'extension=zip',
        ];
    }

    private static array $shortPathCache = [];

    /** Resolve an ASCII Win32 8.3 short path when a Windows path contains non-ASCII characters. */
    public static function windowsShortPath(string $path): string
    {
        if (!self::isWindows()) {
            return $path;
        }
        $real = realpath($path);
        $native = self::nativePath($real !== false ? $real : $path);
        if (preg_match('/[^\x20-\x7e]/', $native) !== 1) {
            return $native;
        }
        if (isset(self::$shortPathCache[$native])) {
            return self::$shortPathCache[$native];
        }
        $ps = <<<'PS'
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$p = $env:YANJI_SP
$fso = [Activator]::CreateInstance([Type]::GetTypeFromProgID('Scripting.FileSystemObject'))
if ([System.IO.Directory]::Exists($p)) { [Console]::Out.WriteLine($fso.GetFolder($p).ShortPath) }
elseif ([System.IO.File]::Exists($p)) { [Console]::Out.WriteLine($fso.GetFile($p).ShortPath) }
else { [Console]::Out.WriteLine($p) }
PS;
        $env = self::windowsPowerShellEnv(getenv());
        $env['YANJI_SP'] = $native;
        $proc = @proc_open(
            [self::windowsPowerShell(), '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', self::encodePowerShellCommand($ps)],
            [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['file', 'NUL', 'w']],
            $pipes,
            null,
            $env,
            ['bypass_shell' => true],
        );
        if (!is_resource($proc)) {
            return self::$shortPathCache[$native] = $native;
        }
        $out = trim((string) stream_get_contents($pipes[1], 4096));
        fclose($pipes[1]);
        $resolved = (proc_close($proc) === 0 && $out !== '') ? $out : $native;
        return self::$shortPathCache[$native] = $resolved;
    }

    /** Return an ASCII PHPRC directory for FrankenPHP on Windows (containing php.ini). */
    public static function windowsIniDir(string $instanceRoot): string
    {
        $short = self::windowsShortPath($instanceRoot);
        if (preg_match('/[^\x20-\x7e]/', $short) !== 1) {
            return $short;
        }
        $fallback = rtrim(self::nativePath(sys_get_temp_dir()), '\\').'\\yanji-ini-'.substr(hash('sha256', $instanceRoot), 0, 16);
        if (!is_dir($fallback)) {
            @mkdir($fallback, 0700, true);
        }
        $srcIni = $instanceRoot.'/php.ini';
        $dstIni = $fallback.'\\php.ini';
        if (is_file($srcIni) && (!is_file($dstIni) || @filemtime($dstIni) < @filemtime($srcIni) || @filesize($dstIni) !== @filesize($srcIni))) {
            @copy($srcIni, $dstIni);
        }
        return $fallback;
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

    private static function windowsPowerShellEnv(array $env): array
    {
        $sysRoot = getenv('SystemRoot') ?: (getenv('WINDIR') ?: 'C:\\Windows');
        $psMod = rtrim($sysRoot, '\\/').'\\System32\\WindowsPowerShell\\v1.0\\Modules';
        foreach (array_keys($env) as $k) {
            if (strcasecmp((string) $k, 'PSModulePath') === 0) {
                unset($env[$k]);
            }
        }
        $env['PSModulePath'] = $psMod;
        return $env;
    }

    public static function encodePowerShellCommand(string $asciiScript): string
    {
        $utf16 = '';
        $len = strlen($asciiScript);
        for ($i = 0; $i < $len; $i++) {
            $utf16 .= $asciiScript[$i]."\x00";
        }
        return base64_encode($utf16);
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
        $real = realpath($path);
        $native = self::nativePath($real !== false ? $real : $path);
        $ps = <<<'PS'
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$p = $env:YANJI_ACL_PATH
$me = [Security.Principal.WindowsIdentity]::GetCurrent().User
if ([System.IO.Directory]::Exists($p)) {
    $acl = [System.Security.AccessControl.DirectorySecurity]::new()
    $acl.SetAccessRuleProtection($true, $false)
    $rule = [System.Security.AccessControl.FileSystemAccessRule]::new($me, 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow')
    $acl.SetAccessRule($rule)
    [System.IO.Directory]::SetAccessControl($p, $acl)
} else {
    $acl = [System.Security.AccessControl.FileSecurity]::new()
    $acl.SetAccessRuleProtection($true, $false)
    $rule = [System.Security.AccessControl.FileSystemAccessRule]::new($me, 'FullControl', 'Allow')
    $acl.SetAccessRule($rule)
    [System.IO.File]::SetAccessControl($p, $acl)
}
PS;
        $env = self::windowsPowerShellEnv(getenv());
        $env['YANJI_ACL_PATH'] = $native;
        $proc = @proc_open(
            [self::windowsPowerShell(), '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', self::encodePowerShellCommand($ps)],
            [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']],
            $pipes,
            null,
            $env,
            ['bypass_shell' => true],
        );
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
        $real = realpath($path);
        $native = self::nativePath($real !== false ? $real : $path);
        $ps = <<<'PS'
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$p = $env:YANJI_ACL_PATH
$me = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
$allowed = @($me, 'S-1-3-4', 'S-1-5-18', 'S-1-5-32-544')
$acl = if ([System.IO.Directory]::Exists($p)) { [System.IO.Directory]::GetAccessControl($p) } else { [System.IO.File]::GetAccessControl($p) }
$rules = @($acl.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier]))
if ($rules.Count -eq 0) { exit 2 }
$hasMe = $false
foreach ($r in $rules) {
    $sid = $r.IdentityReference.Value
    if ($sid -eq $me) { $hasMe = $true }
    if ($allowed -notcontains $sid) { exit 3 }
}
if (-not $hasMe) { exit 4 }
exit 0
PS;
        $env = self::windowsPowerShellEnv(getenv());
        $env['YANJI_ACL_PATH'] = $native;
        $proc = @proc_open(
            [self::windowsPowerShell(), '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', self::encodePowerShellCommand($ps)],
            [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']],
            $pipes,
            null,
            $env,
            ['bypass_shell' => true],
        );
        return is_resource($proc) && proc_close($proc) === 0;
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
$ProgressPreference = 'SilentlyContinue'
$code = @'
using System;
using System.ComponentModel;
using System.Runtime.InteropServices;
using System.Text;

public static class YanjiDetacher {
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    public struct STARTUPINFO {
        public int cb;
        public string lpReserved;
        public string lpDesktop;
        public string lpTitle;
        public int dwX, dwY, dwXSize, dwYSize, dwXCountChars, dwYCountChars, dwFillAttribute, dwFlags;
        public short wShowWindow, cbReserved2;
        public IntPtr lpReserved2, hStdInput, hStdOutput, hStdError;
    }

    [StructLayout(LayoutKind.Sequential)]
    public struct PROCESS_INFORMATION {
        public IntPtr hProcess;
        public IntPtr hThread;
        public int dwProcessId;
        public int dwThreadId;
    }

    [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
    private static extern bool CreateProcessW(
        string lpApplicationName,
        StringBuilder lpCommandLine,
        IntPtr lpProcessAttributes,
        IntPtr lpThreadAttributes,
        bool bInheritHandles,
        uint dwCreationFlags,
        IntPtr lpEnvironment,
        string lpCurrentDirectory,
        ref STARTUPINFO lpStartupInfo,
        out PROCESS_INFORMATION lpProcessInformation
    );

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern bool CloseHandle(IntPtr hObject);

    public static int Launch(string exe, string cmdLine, string cwd) {
        var si = new STARTUPINFO();
        si.cb = Marshal.SizeOf(typeof(STARTUPINFO));
        PROCESS_INFORMATION pi;
        var sb = new StringBuilder(cmdLine);
        bool ok = CreateProcessW(exe, sb, IntPtr.Zero, IntPtr.Zero, false, 0x08000200u, IntPtr.Zero, cwd, ref si, out pi);
        if (!ok) {
            throw new Win32Exception(Marshal.GetLastWin32Error());
        }
        int pid = pi.dwProcessId;
        CloseHandle(pi.hThread);
        CloseHandle(pi.hProcess);
        return pid;
    }
}
'@
Add-Type -TypeDefinition $code -Language CSharp
$count = [int]$env:YANJI_DETACH_ARGC
$all = @($env:YANJI_DETACH_EXE)
for ($i = 0; $i -lt $count; $i++) {
    $all += [string][Environment]::GetEnvironmentVariable("YANJI_DETACH_ARG_$i")
}
$quoted = foreach ($s in $all) {
    if ($s -eq '' -or $s -match '[\s"]') {
        $s = $s -replace '(\\*)"', '$1$1\"'
        $s = $s -replace '(\\+)$', '$1$1'
        '"' + $s + '"'
    } else {
        $s
    }
}
$cmdLine = ($quoted -join ' ')
$pidOut = [YanjiDetacher]::Launch($env:YANJI_DETACH_EXE, $cmdLine, $env:YANJI_DETACH_CWD)
[System.IO.File]::WriteAllText($env:YANJI_DETACH_PIDFILE, "$pidOut")
PS;
        $pidFile = dirname($stderrLog).'/.spawn-pid-'.bin2hex(random_bytes(8));
        $values = array_values($args);
        $extra = [
            'YANJI_DETACH_EXE' => self::nativePath($exe),
            'YANJI_DETACH_ARGC' => (string) count($values),
            'YANJI_DETACH_CWD' => self::nativePath($cwd),
            'YANJI_DETACH_PIDFILE' => self::nativePath($pidFile),
            'YANJI_MANAGER_LOG' => self::nativePath($stdoutLog),
            'YANJI_MANAGER_ERR_LOG' => self::nativePath($stderrLog),
        ];
        foreach ($values as $idx => $val) {
            $extra['YANJI_DETACH_ARG_'.$idx] = (string) $val;
        }
        $launchEnv = self::windowsPowerShellEnv($env + $extra);
        $proc = @proc_open(
            [self::windowsPowerShell(), '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', self::encodePowerShellCommand($ps51)],
            [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', self::nativePath($stderrLog), 'a']],
            $pipes,
            $cwd,
            $launchEnv,
            ['bypass_shell' => true],
        );
        try {
            if (!is_resource($proc)) {
                throw new RuntimeException('无法在 Windows 上启动实例托管器。');
            }
            $code = proc_close($proc);
            $out = is_file($pidFile) ? trim((string) file_get_contents($pidFile)) : '';
            if ($code !== 0 || !preg_match('/\A\d+\z/', $out)) {
                throw new RuntimeException('无法在 Windows 上确认后台托管器进程号。');
            }
            return (int) $out;
        } finally {
            if (is_file($pidFile)) {
                @unlink($pidFile);
            }
        }
    }

    public static function createDirectoryJunction(string $link, string $target): void
    {
        if (is_dir($link) || is_link($link)) {
            @rmdir(self::nativePath($link));
        }
        $env = self::windowsPowerShellEnv(getenv());
        $env['YANJI_J_LINK'] = self::nativePath($link);
        $env['YANJI_J_TARGET'] = self::nativePath($target);
        $ps = 'New-Item -ItemType Junction -Path $env:YANJI_J_LINK -Target $env:YANJI_J_TARGET -Force | Out-Null';
        $proc = @proc_open(
            [self::windowsPowerShell(), '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', self::encodePowerShellCommand($ps)],
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
