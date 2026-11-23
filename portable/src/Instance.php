<?php

declare(strict_types=1);

namespace Yanji\Portable;

use RuntimeException;
use SQLite3;

final class Instance
{
    public readonly string $root;

    public function __construct(string $root)
    {
        if (!str_starts_with($root, '/') || preg_match('/[\x00-\x1f]|(?:^|\/)\.\.?(?:\/|$)/', $root)
            || is_link($root)) {
            throw new RuntimeException('实例需要本地绝对路径。');
        }
        $path = rtrim($root, '/');
        $missing = [];
        while ($path !== '' && !file_exists($path)) {
            $missing[] = basename($path);
            $path = dirname($path);
        }
        $parent = realpath($path);
        if ($path === '' || $parent === false || !is_dir($parent)) {
            throw new RuntimeException('实例目录无效。');
        }
        $this->root = rtrim($parent, '/').($missing ? '/'.implode('/', array_reverse($missing)) : '');
    }

    public function validateDestination(): void
    {
        if (!is_dir($this->root) || count(scandir($this->root)) === 2) {
            return;
        }
        if (is_file($this->root.'/instance.json') || is_file($this->root.'/.env') && is_dir($this->root.'/database')) {
            return;
        }
        // A concurrent installer may have created only its initial operation lock.
        $entries = array_diff(scandir($this->root), ['.', '..', 'run']);
        if (!$entries && is_dir($this->root.'/run') && !is_link($this->root.'/run')
            && !array_diff(scandir($this->root.'/run'), ['.', '..', 'operation.lock'])) {
            return;
        }
        throw new RuntimeException('目标目录已有其他文件，请选择空目录或已有言及实例。');
    }

    public static function privateDir(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('不能创建实例目录，请检查路径和权限。');
        }
        if (is_link($path) || fileowner($path) !== posix_geteuid() || !chmod($path, 0700)) {
            throw new RuntimeException('实例目录须由当前用户持有，并且可设置为私有。');
        }
    }

    public function prepare(): void
    {
        foreach (['', 'run', 'logs', 'database', 'bootstrap-cache', 'public', 'storage', 'storage/app', 'storage/app/private',
            'storage/app/public', 'storage/framework', 'storage/framework/cache', 'storage/framework/cache/data', 'storage/framework/sessions',
            'storage/framework/views', 'storage/logs', 'xdg', 'xdg/config', 'xdg/data'] as $dir) {
            self::privateDir(rtrim($this->root.'/'.$dir, '/'));
        }
    }

    public function release(): string
    {
        $path = realpath($this->root.'/current');
        if ($path === false || !is_file($path.'/app/artisan') || !is_executable($path.'/runtime/bin/php')) {
            throw new RuntimeException('实例缺少已验证的程序版本，请重新运行安装器。');
        }
        return $path;
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
            if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle) || !chmod($tmp, $mode)) {
                throw new RuntimeException('无法完整保存实例文件。');
            }
            if (!rename($tmp, $path)) {
                throw new RuntimeException('无法发布实例文件。');
            }
        } finally {
            fclose($handle);
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    public function activate(string $release): void
    {
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
    }

    public function environment(): array
    {
        $release = $this->release();
        // Parent shells can carry APP_KEY, database credentials or environment overrides for another app.
        $env = array_intersect_key(getenv(), array_flip(['HOME', 'USER', 'LANG', 'LC_ALL', 'PATH', 'TMPDIR', 'TZ']));
        $port = $this->port();
        $storage = $this->root.'/storage';
        $env = array_merge($env, [
            'PATH' => $release.'/runtime/bin:'.($env['PATH'] ?? '/usr/bin:/bin'),
            'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://127.0.0.1:'.$port,
            'YANJI_PORT' => (string) $port, 'YANJI_ENV_PATH' => $this->root,
            'YANJI_UPGRADE_HOLD' => $this->root.'/run/upgrade-hold',
            'YANJI_PUBLIC_PATH' => $this->root.'/public', 'LARAVEL_STORAGE_PATH' => $storage,
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->root.'/database/database.sqlite',
            'YANJI_BROWSER_COOKIE_NAME' => 'geo_session_'.substr(hash('sha256', $this->root), 0, 24),
            'SESSION_COOKIE' => 'yanji_'.substr(hash('sha256', $this->root), 0, 24),
            'CACHE_PREFIX' => 'yanji_'.substr(hash('sha256', $this->root), 0, 24).'_',
            'QUEUE_CONNECTION' => 'database', 'WORKER_ID' => 'portable-worker', 'GEO_WORKER_HEARTBEAT_ENABLED' => 'true',
            'XDG_CONFIG_HOME' => $this->root.'/xdg/config', 'XDG_DATA_HOME' => $this->root.'/xdg/data',
            'PHPRC' => $this->root.'/php.ini', 'PHP_INI_SCAN_DIR' => '',
        ]);
        $pythonPath = $this->root.'/python-path';
        $env['PYTHON_BIN'] = is_file($pythonPath) && !is_link($pythonPath)
            ? rtrim(file_get_contents($pythonPath), "\r\n")
            : (is_executable($release.'/runtime/python/bin/python3') ? $release.'/runtime/python/bin/python3' : 'python3');
        foreach (['SERVICES', 'PACKAGES', 'CONFIG', 'ROUTES', 'EVENTS'] as $kind) {
            $env['APP_'.$kind.'_CACHE'] = $this->root.'/bootstrap-cache/'.strtolower($kind).'.php';
        }
        return $env;
    }

    public function artisan(array $args, ?string $input = null, bool $quiet = false): int
    {
        $release = $this->release();
        $stdio = [0 => $input === null ? STDIN : ['pipe', 'r'], 1 => $quiet ? ['file', '/dev/null', 'w'] : STDOUT,
            2 => $quiet ? ['file', '/dev/null', 'w'] : STDERR];
        $process = proc_open([$release.'/runtime/bin/php', $release.'/app/artisan', ...$args],
            $stdio, $pipes, $release.'/app', $this->environment());
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
        if (is_link($path) || !($lock = @fopen($path, 'ce'))) {
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
        // Short private socket path also works with long Unicode instance paths on macOS.
        $dir = '/tmp/yanji-'.posix_geteuid();
        return $dir.'/'.substr(hash('sha256', $this->root), 0, 40).'.sock';
    }

    public function heartbeat(string $role, int $since): ?string
    {
        $db = null;
        try {
            $db = new SQLite3($this->root.'/database/database.sqlite', SQLITE3_OPEN_READONLY);
            $db->enableExceptions(true);
            $db->busyTimeout(400);
            $query = $role === 'scheduler' ? "worker_id = 'scheduler'" : "worker_id = 'portable-worker'";
            $value = $db->querySingle("SELECT MAX(last_seen_at) FROM worker_heartbeats WHERE ".$query);
            return is_string($value) && strtotime($value.' UTC') >= $since ? $value : null;
        } catch (\Throwable) {
            return null;
        } finally {
            $db?->close();
        }
    }
}
