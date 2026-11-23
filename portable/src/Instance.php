<?php

declare(strict_types=1);

namespace Yanji\Portable;

use RuntimeException;
use SQLite3;

require_once __DIR__.'/Platform.php';

final class Instance
{
    public readonly string $root;

    public function __construct(string $root)
    {
        if (is_link($root)) {
            throw new RuntimeException('实例根目录不能是符号链接。');
        }
        $this->root = self::physicalPath($root);
    }

    public static function physicalPath(string $value): string
    {
        $normalized = Platform::normalize($value);
        if (!Platform::isAbsolute($value) || preg_match('/[\x00-\x1f]|(?:^|\/)\.\.?(?:\/|$)/', $normalized)) {
            throw new RuntimeException('需要不含控制字符的本地绝对路径。');
        }
        if (Platform::isRoot($normalized)) {
            throw new RuntimeException('不能将文件系统根目录用于实例或程序。');
        }
        $path = $normalized;
        $missing = [];
        while (!file_exists($path)) {
            if (is_link($path)) {
                throw new RuntimeException('目标路径含无效符号链接。');
            }
            $missing[] = basename($path);
            $next = Platform::parentDir($path);
            if ($next === $path) {
                throw new RuntimeException('目标路径不可用。');
            }
            $path = $next;
        }
        $parent = realpath($path);
        if ($parent === false || ($missing && !is_dir($parent))) {
            throw new RuntimeException('目标路径不可用。');
        }
        $parentNorm = Platform::normalize($parent);
        if (!$missing) {
            return $parentNorm;
        }
        $base = rtrim($parentNorm, '/');
        return $base.'/'.implode('/', array_reverse($missing));
    }

    private static function managedInstance(string $root): bool
    {
        $marker = $root.'/.portable-layout';
        if (is_file($marker) && !is_link($marker) && file_get_contents($marker) === "yanji-portable-v1\n"
            && (Platform::isWindows() || fileowner($marker) === posix_geteuid())) {
            return true;
        }
        if (Platform::isWindows()) {
            $ptr = $root.'/current-release.txt';
            if (is_file($ptr) && !is_link($ptr) && is_file($root.'/instance.json') && is_file($root.'/yanji.cmd')) {
                $rel = trim((string) @file_get_contents($ptr));
                return $rel !== '' && is_file($rel.'/.archive-sha256') && is_file($rel.'/release.json');
            }
            return false;
        }
        return is_link($root.'/current') && is_file($root.'/instance.json') && is_file($root.'/yanji')
            && is_file($root.'/current/.archive-sha256') && is_file($root.'/current/release.json');
    }

    private static function managedProgram(string $root): bool
    {
        return (is_file($root.'/.yanji-program') && !is_link($root.'/.yanji-program'))
            || (is_file($root.'/releases/.publish.lock') && !is_link($root.'/releases/.publish.lock'))
            || (is_file($root.'/release.json') && is_file(Platform::phpBinary($root)) && is_dir($root.'/app/portable'));
    }

    public static function assertOutsideManagedTrees(string $path): void
    {
        $parent = self::physicalPath($path);
        while ($parent !== '' && !Platform::isRoot($parent)) {
            if (self::managedInstance($parent) || self::managedProgram($parent)) {
                throw new RuntimeException('目标在已有实例或程序内部，请选择独立的同级目录。');
            }
            $next = Platform::parentDir($parent);
            if ($next === $parent) {
                break;
            }
            $parent = $next;
        }
    }

    public function validateDestination(): void
    {
        $parent = Platform::parentDir($this->root);
        if (!Platform::isRoot($parent)) {
            self::assertOutsideManagedTrees($parent);
        }
        if (!file_exists($this->root) || (is_dir($this->root) && count(scandir($this->root)) === 2) || self::managedInstance($this->root)) {
            return;
        }
        throw new RuntimeException('目标目录已有其他文件，请选择空目录或已有言及实例。');
    }

    public static function programPath(string $program, Instance $instance): string
    {
        if (is_link($program)) {
            throw new RuntimeException('程序根目录不能是符号链接。');
        }
        $root = self::physicalPath($program);
        Platform::assertProtectedAncestors($root.'/releases');
        Platform::assertProtectedAncestors($instance->root);
        foreach ([$root.'/.yanji-program', $root.'/releases/.publish.lock'] as $marker) {
            if (is_link($marker) || (file_exists($marker) && (!is_file($marker) || (!Platform::isWindows() && fileowner($marker) !== posix_geteuid())))) {
                throw new RuntimeException('程序标识或发布锁的类型、所有者异常。');
            }
        }
        if ($root === $instance->root || str_starts_with($root.'/', $instance->root.'/')
            || str_starts_with($instance->root.'/', $root.'/')) {
            throw new RuntimeException('实例和程序必须使用分开的物理目录。');
        }
        $parent = Platform::parentDir($root);
        if (!Platform::isRoot($parent)) {
            self::assertOutsideManagedTrees($parent);
        }
        if (file_exists($root) && (!is_dir($root) || (!self::managedProgram($root) && count(scandir($root)) !== 2))) {
            throw new RuntimeException('程序目标含其他数据，请选择空目录。');
        }
        $instance->validateDestination();
        return $root;
    }

    public static function privateDir(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('不能创建实例目录，请检查路径和权限。');
        }
        if (is_link($path)) {
            throw new RuntimeException('实例目录须由当前用户持有，并且可设置为私有。');
        }
        if (!Platform::isWindows()) {
            if (fileowner($path) !== posix_geteuid() || !chmod($path, 0700)) {
                throw new RuntimeException('实例目录须由当前用户持有，并且可设置为私有。');
            }
        }
    }

    public function prepare(): void
    {
        foreach (['', 'run', 'logs', 'database', 'bootstrap-cache', 'public', 'storage', 'storage/app', 'storage/app/private',
            'storage/app/public', 'storage/framework', 'storage/framework/cache', 'storage/framework/cache/data', 'storage/framework/sessions',
            'storage/framework/views', 'storage/logs', 'xdg', 'xdg/config', 'xdg/data'] as $dir) {
            self::privateDir(rtrim($this->root.'/'.$dir, '/'));
        }
        if (Platform::isWindows()) {
            Platform::protectPrivate($this->root, true);
        }
    }

    public function hasReleasePointer(): bool
    {
        return Platform::isWindows()
            ? is_file($this->root.'/current-release.txt') && !is_link($this->root.'/current-release.txt')
            : is_link($this->root.'/current');
    }

    public function release(): string
    {
        if (Platform::isWindows()) {
            $ptr = $this->root.'/current-release.txt';
            if (!is_file($ptr) || is_link($ptr)) {
                throw new RuntimeException('实例缺少已验证的程序版本，请重新运行安装器。');
            }
            $raw = trim((string) file_get_contents($ptr));
            $path = $raw === '' ? false : realpath($raw);
        } else {
            $path = realpath($this->root.'/current');
        }
        if ($path === false) {
            throw new RuntimeException('实例缺少已验证的程序版本，请重新运行安装器。');
        }
        $norm = Platform::normalize($path);
        if (!is_file($norm.'/app/artisan') || !Platform::isExecutable(Platform::phpBinary($norm))) {
            throw new RuntimeException('实例缺少已验证的程序版本，请重新运行安装器。');
        }
        return $norm;
    }

    public function port(): int
    {
        $path = $this->root.'/instance.json';
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('缺少实例配置，请检查实例目录。');
        }
        $config = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $port = $config['port'] ?? null;
        if (!is_int($port) || $port < 1 || $port > 65535) {
            throw new RuntimeException('实例端口无效。');
        }
        return $port;
    }

    public static function atomicFile(string $path, string $contents, int $mode = 0600): void
    {
        $tmp = dirname($path).'/.write-'.bin2hex(random_bytes(12));
        $handle = fopen($tmp, 'xb');
        if ($handle === false) {
            throw new RuntimeException('无法创建临时文件。');
        }
        try {
            if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle) || (!Platform::isWindows() && !chmod($tmp, $mode))) {
                throw new RuntimeException('无法完整保存实例文件。');
            }
            fclose($handle);
            $handle = null;
            if (!rename($tmp, $path)) {
                throw new RuntimeException('无法发布实例文件。');
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    public function activate(string $release): void
    {
        $release = Platform::normalize($release);
        if (Platform::isWindows()) {
            $this->activateWindows($release);
            return;
        }
        $public = $this->root.'/public';
        $names = [];
        foreach (new \DirectoryIterator($release.'/app/public') as $item) {
            $name = $item->getFilename();
            if ($item->isDot() || $name[0] === '.' || $name === 'storage') {
                continue;
            }
            $names[$name] = '../current/app/public/'.$name;
        }
        $names['storage'] = '../storage/app/public';
        $obsolete = [];
        foreach (new \DirectoryIterator($public) as $item) {
            if ($item->isDot()) {
                continue;
            }
            $name = $item->getFilename();
            if (!$item->isLink() || readlink($item->getPathname()) !== ($names[$name] ?? '../current/app/public/'.$name)) {
                throw new RuntimeException('实例 public 含非管理文件，拒绝覆盖。');
            }
            if (!isset($names[$name])) {
                $obsolete[] = $item->getPathname();
            }
        }
        $current = $this->root.'/current';
        if (file_exists($current) && !is_link($current)) {
            throw new RuntimeException('程序指针不是管理链接，拒绝覆盖。');
        }
        $created = [];
        $tmp = $this->root.'/run/release-'.bin2hex(random_bytes(8));
        try {
            foreach ($names as $name => $target) {
                $path = $public.'/'.$name;
                if (!is_link($path)) {
                    if (!symlink($target, $path)) {
                        throw new RuntimeException('不能建立实例公共目录映射。');
                    }
                    $created[] = $path;
                }
            }
            if (!symlink($release, $tmp) || !rename($tmp, $current)) {
                throw new RuntimeException('不能切换程序目录。');
            }
        } catch (\Throwable $error) {
            foreach ($created as $path) {
                unlink($path);
            }
            throw $error;
        } finally {
            if (is_link($tmp)) {
                unlink($tmp);
            }
        }
        // Obsolete links now point to absent assets. Leaving one behind after a filesystem error exposes no file.
        foreach ($obsolete as $path) {
            unlink($path);
        }
        $this->discardBootstrapCaches();
    }

    /** Mirror static release public assets and link storage via a non-privileged NTFS junction. */
    private function activateWindows(string $release): void
    {
        $public = $this->root.'/public';
        $manifestPath = $this->root.'/run/managed-public.json';
        $managed = is_file($manifestPath) ? (json_decode((string) file_get_contents($manifestPath), true) ?: []) : [];
        foreach (new \DirectoryIterator($public) as $item) {
            if ($item->isDot()) {
                continue;
            }
            $name = $item->getFilename();
            if ($name === 'storage') {
                continue;
            }
            if (!in_array($name, $managed, true)) {
                throw new RuntimeException('实例 public 含非管理文件，拒绝覆盖。');
            }
        }
        $entries = [];
        foreach (new \DirectoryIterator($release.'/app/public') as $item) {
            $name = $item->getFilename();
            if ($item->isDot() || $name[0] === '.' || $name === 'storage') {
                continue;
            }
            $entries[] = $name;
        }
        $obsolete = array_diff($managed, $entries);
        foreach ($entries as $name) {
            $src = $release.'/app/public/'.$name;
            $dst = $public.'/'.$name;
            if (is_dir($src)) {
                Platform::createDirectoryJunction($dst, $src);
            } elseif (is_file($src)) {
                if (is_dir($dst) && !is_link($dst)) {
                    throw new RuntimeException('实例 public 含非管理文件，拒绝覆盖。');
                }
                if (str_ends_with(strtolower($name), '.php')) {
                    // Forward into the release directory so __DIR__ resolves to <release>/app/public
                    // (matching Unix symlink semantics without requiring Developer Mode or elevation).
                    $stub = "<?php\n\ndeclare(strict_types=1);\n\nrequire ".var_export($src, true).";\n";
                    self::atomicFile($dst, $stub, 0600);
                } elseif (!copy($src, $dst)) {
                    throw new RuntimeException('不能同步实例公共文件。');
                }
            }
        }
        Platform::createDirectoryJunction($public.'/storage', $this->root.'/storage/app/public');
        self::atomicFile($manifestPath, json_encode(array_values($entries), JSON_THROW_ON_ERROR));
        self::atomicFile($this->root.'/current-release.txt', $release."\n");
        foreach ($obsolete as $name) {
            $path = $public.'/'.$name;
            if (is_dir($path)) {
                @rmdir(Platform::nativePath($path));
            } elseif (is_file($path)) {
                @unlink($path);
            }
        }
        $this->discardBootstrapCaches();
    }

    public function discardBootstrapCaches(): void
    {
        $dir = $this->root.'/bootstrap-cache';
        if (is_link($dir)) {
            throw new RuntimeException('实例启动缓存目录异常，拒绝执行服务或维护操作。');
        }
        foreach (['services.php', 'packages.php', 'config.php', 'routes.php', 'events.php'] as $file) {
            $path = $dir.'/'.$file;
            if (is_link($path) || (is_file($path) && ((!Platform::isWindows() && fileowner($path) !== posix_geteuid()) || !unlink($path)))) {
                throw new RuntimeException('实例启动缓存的路径或所有者异常，拒绝执行服务或维护操作。');
            }
        }
    }

    public function environment(bool $uncached = false): array
    {
        $release = $this->release();
        // Parent shells can carry APP_KEY, database credentials or environment overrides for another app.
        $keep = ['HOME', 'USER', 'LANG', 'LC_ALL', 'PATH', 'TMPDIR', 'TZ',
            'SystemRoot', 'COMSPEC', 'PATHEXT', 'WINDIR', 'TEMP', 'TMP',
            'LOCALAPPDATA', 'APPDATA', 'USERPROFILE', 'SystemDrive', 'NUMBER_OF_PROCESSORS', 'PROCESSOR_ARCHITECTURE'];
        if (Platform::isWindows()) {
            $keepUpper = array_flip(array_map('strtoupper', $keep));
            $env = [];
            $existingPath = null;
            foreach (getenv() as $k => $v) {
                $upper = strtoupper($k);
                if (!isset($keepUpper[$upper])) {
                    continue;
                }
                if ($upper === 'PATH') {
                    $existingPath = $v;
                } else {
                    $env[$k] = $v;
                }
            }
            $existingPath ??= 'C:\\Windows\\System32;C:\\Windows;C:\\Windows\\System32\\WindowsPowerShell\\v1.0';
        } else {
            $env = array_intersect_key(getenv(), array_flip($keep));
            $existingPath = $env['PATH'] ?? '/usr/bin:/bin';
        }
        $port = $this->port();
        $storage = $this->root.'/storage';
        $binPath = Platform::nativePath($release.'/runtime/bin');
        $env = array_merge($env, [
            'PATH' => $binPath.PATH_SEPARATOR.$existingPath,
            'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://127.0.0.1:'.$port,
            'YANJI_PORTABLE_INSTANCE' => 'true',
            'YANJI_PORT' => (string) $port, 'YANJI_ENV_PATH' => $this->root,
            'YANJI_UPGRADE_HOLD' => $this->root.'/run/upgrade-hold',
            'YANJI_STOP_FILE' => $this->root.'/run/worker-stop',
            'YANJI_PUBLIC_PATH' => $this->root.'/public', 'LARAVEL_STORAGE_PATH' => $storage,
            'DB_SQLITE_TRANSACTION_MODE' => 'IMMEDIATE',
            'DB_URL' => '', 'DB_QUEUE_CONNECTION' => 'sqlite',
            'SESSION_DRIVER' => 'file', 'CACHE_STORE' => 'file',
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->root.'/database/database.sqlite',
            'YANJI_BROWSER_COOKIE_NAME' => 'geo_session_'.substr(hash('sha256', $this->root), 0, 24),
            'SESSION_COOKIE' => 'yanji_'.substr(hash('sha256', $this->root), 0, 24),
            'CACHE_PREFIX' => 'yanji_'.substr(hash('sha256', $this->root), 0, 24).'_',
            'QUEUE_CONNECTION' => 'database', 'WORKER_ID' => 'portable-worker', 'GEO_WORKER_HEARTBEAT_ENABLED' => 'true',
            'XDG_CONFIG_HOME' => $this->root.'/xdg/config', 'XDG_DATA_HOME' => $this->root.'/xdg/data',
            'YANJI_CA_BUNDLE' => Platform::isWindows() ? Platform::windowsShortPath($release.'/runtime/ca/cacert.pem') : $release.'/runtime/ca/cacert.pem',
            'PYTHONDONTWRITEBYTECODE' => '1',
            'PHPRC' => Platform::isWindows() ? Platform::windowsIniDir($this->root) : $this->root.'/php.ini',
            'PHP_INI_SCAN_DIR' => '',
        ]);
        $env['PYTHON_BIN'] = $this->pythonBin($release);
        if (is_dir($release.'/runtime/fonts')) {
            $env['YANJI_REPORT_FONTS_DIR'] = $release.'/runtime/fonts';
        }
        foreach (['SERVICES', 'PACKAGES', 'CONFIG', 'ROUTES', 'EVENTS'] as $kind) {
            $env['APP_'.$kind.'_CACHE'] = $this->root.'/bootstrap-cache/'.strtolower($kind).'.php';
        }
        if ($uncached) {
            // Read-only checks must not consume a cached URL or private paths from a different instance.
            $env['APP_CONFIG_CACHE'] = Platform::isWindows()
                ? $this->root.'/run/nonexistent-read-only-config.php'
                : '/dev/null/yanji-read-only-config.php';
        }
        return $env;
    }

    public function pythonBin(string $release): string
    {
        $pythonPath = $this->root.'/python-path';
        if (is_file($pythonPath) && !is_link($pythonPath)) {
            return rtrim(file_get_contents($pythonPath), "\r\n");
        }
        if (Platform::isWindows()) {
            $bundled = $release.'/runtime/python/python.exe';
            return is_file($bundled) ? $bundled : 'python';
        }
        return is_executable($release.'/runtime/python/bin/python3') ? $release.'/runtime/python/bin/python3' : 'python3';
    }

    public function discardConfigurationCache(): void
    {
        $dir = $this->root.'/bootstrap-cache';
        $path = $dir.'/config.php';
        if (is_link($dir) || is_link($path) || (is_file($path) && ((!Platform::isWindows() && fileowner($path) !== posix_geteuid()) || !unlink($path)))) {
            throw new RuntimeException('实例配置缓存的路径或所有者异常，拒绝执行服务或维护操作。');
        }
    }

    public function artisan(array $args, ?string $input = null, bool $quiet = false, bool $uncached = false): int
    {
        $release = $this->release();
        $null = Platform::nullDevice();
        $stdio = [0 => $input === null ? STDIN : ['pipe', 'r'], 1 => $quiet ? ['file', $null, 'w'] : STDOUT,
            2 => $quiet ? ['file', $null, 'w'] : STDERR];
        $process = proc_open([Platform::phpBinary($release), ...Platform::phpArgs($release, $this->root), $release.'/app/artisan', ...$args],
            $stdio, $pipes, $release.'/app', $this->environment($uncached), ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('无法启动应用管理命令。');
        }
        if ($input !== null) {
            fwrite($pipes[0], $input);
            fclose($pipes[0]);
        }
        return proc_close($process);
    }

    public function commandLock()
    {
        $path = $this->root.'/run/operation.lock';
        if (is_link($path) || !($lock = @fopen($path, Platform::lockMode()))) {
            throw new RuntimeException('实例锁不可写，请检查目录和所有者。');
        }
        $wouldBlock = 0;
        if (!flock($lock, LOCK_EX | LOCK_NB, $wouldBlock)) {
            fclose($lock);
            throw new RuntimeException($wouldBlock ? '另一个安装或管理操作正在执行，请稍后重试。' : '本地文件系统不支持安全锁。');
        }
        return $lock;
    }

    public function socketPath(): string
    {
        if (Platform::isWindows()) {
            return $this->root.'/run/control.json';
        }
        // Short private socket path also works with long Unicode instance paths on macOS.
        $dir = '/tmp/yanji-'.posix_geteuid();
        return $dir.'/'.substr(hash('sha256', $this->root), 0, 40).'.sock';
    }

    public function heartbeat(string $role, int $since, string $generation, int $pid): ?string
    {
        $db = null;
        try {
            $db = new SQLite3($this->root.'/database/database.sqlite', SQLITE3_OPEN_READONLY);
            $db->enableExceptions(true);
            $db->busyTimeout(400);
            $id = $role === 'scheduler' ? 'scheduler' : 'portable-worker';
            $row = $db->querySingle("SELECT status,last_seen_at,meta FROM worker_heartbeats WHERE worker_id = '".$id."'", true);
            $meta = json_decode($row['meta'] ?? '', true);
            $time = strtotime(($row['last_seen_at'] ?? '').' UTC');
            if (($row['status'] ?? null) !== 'running' || ($meta['generation'] ?? null) !== $generation
                || ($role === 'worker' && ($meta['pid'] ?? null) !== $pid) || !$time || $time < $since || $time > time()) {
                return null;
            }
            return $row['last_seen_at'];
        } catch (\Throwable) {
            return null;
        } finally {
            $db?->close();
        }
    }
}
