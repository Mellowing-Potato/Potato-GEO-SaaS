<?php

declare(strict_types=1);

namespace Yanji\Portable;

use RuntimeException;

require_once __DIR__.'/Platform.php';

final class Supervisor
{
    private array $children = [];
    private bool $stopping = false;
    private int $startTime = 0;
    private string $controlToken = '';
    private ?string $verifiedControlFingerprint = null;

    public function __construct(private Instance $instance) {}

    public function request(string $action): ?array
    {
        if (Platform::isWindows()) {
            return $this->requestWindows($action);
        }
        $path = $this->instance->socketPath();
        $directory = @lstat(dirname($path));
        if (!$directory) {
            return null;
        }
        if (($directory['mode'] & 0170077) !== 0040000 || $directory['uid'] !== posix_geteuid()) {
            throw new RuntimeException('管理套接字父目录的类型、权限或所有者异常。');
        }
        clearstatcache(true, $path);
        $endpoint = @lstat($path);
        if (!$endpoint) {
            return null;
        }
        if (($endpoint['mode'] & 0170077) !== 0140000 || $endpoint['uid'] !== posix_geteuid()) {
            throw new RuntimeException('管理套接字的类型、权限或所有者异常。');
        }
        $socket = @stream_socket_client('unix://'.$path, $errno, $message, 0.4);
        if (!$socket) {
            return null;
        }
        try {
            stream_set_timeout($socket, 3);
            fwrite($socket, json_encode(['action' => $action, 'instance' => hash('sha256', $this->instance->root)], JSON_THROW_ON_ERROR)."\n");
            $line = fgets($socket, 16384);
            $reply = $line === false ? null : json_decode($line, true);
            if (!is_array($reply) || isset($reply['error']) || ($reply['instance'] ?? '') !== hash('sha256', $this->instance->root)) {
                throw new RuntimeException('管理端点未确认本实例身份。');
            }
            return $reply;
        } finally {
            fclose($socket);
        }
    }

    private function requestWindows(string $action): ?array
    {
        $path = $this->instance->socketPath();
        clearstatcache(true, $path);
        if (!is_file($path)) {
            $this->verifiedControlFingerprint = null;
            return null;
        }
        $fingerprint = $path.':'.((string) @filemtime($path)).':'.((string) @filesize($path));
        if ($this->verifiedControlFingerprint !== $fingerprint) {
            if (!Platform::isPrivate($path)) {
                throw new RuntimeException('管理端点文件的权限或类型异常。');
            }
            $this->verifiedControlFingerprint = $fingerprint;
        }
        $info = json_decode((string) @file_get_contents($path), true);
        $port = $info['port'] ?? null;
        $token = $info['token'] ?? null;
        $identity = hash('sha256', $this->instance->root);
        if (!is_int($port) || $port < 1 || $port > 65535 || !is_string($token) || strlen($token) !== 64
            || ($info['instance'] ?? null) !== $identity) {
            throw new RuntimeException('管理端点配置损坏或不属于本实例。');
        }
        $socket = @stream_socket_client('tcp://127.0.0.1:'.$port, $errno, $message, 1.0);
        if (!$socket) {
            return null;
        }
        try {
            stream_set_timeout($socket, 10);
            fwrite($socket, json_encode(['action' => $action, 'instance' => $identity, 'token' => $token], JSON_THROW_ON_ERROR)."\n");
            $line = fgets($socket, 16384);
            if ($line === false && $action === 'status' && (stream_get_meta_data($socket)['timed_out'] ?? false)) {
                return null;
            }
            $reply = $line === false ? null : json_decode($line, true);
            if (!is_array($reply) || isset($reply['error']) || ($reply['instance'] ?? '') !== $identity) {
                throw new RuntimeException('管理端点未确认本实例身份。');
            }
            return $reply;
        } finally {
            fclose($socket);
        }
    }

    public function start(): array
    {
        $existing = $this->request('status');
        if ($existing !== null && $existing['ready']) {
            return $existing;
        }
        if ($existing === null) {
            $this->assertNoOrphans();
            $this->instance->discardConfigurationCache();
            if ($this->instance->artisan(['yanji:ready', '--portable', '--quiet'], quiet: true) !== 0) {
                throw new RuntimeException('实例密钥、管理员或数据库未就绪，请运行 yanji doctor。');
            }
            $probe = @stream_socket_server('tcp://127.0.0.1:'.$this->instance->port(), $errno, $error);
            if (!$probe) {
                throw new RuntimeException('实例端口已被占用，请选择其他端口。');
            }
            fclose($probe);
            $release = $this->instance->release();
            if (Platform::isWindows()) {
                $pid = Platform::spawnDetachedWindows(
                    Platform::phpBinary($release),
                    [...Platform::phpArgs($release, $this->instance->root), $release.'/app/portable/yanji.php', '__supervise', '--instance', $this->instance->root],
                    $release.'/app',
                    $this->instance->root.'/logs/manager.log',
                    $this->instance->root.'/logs/manager-err.log',
                    $this->instance->environment(),
                );
            } else {
                $pid = pcntl_fork();
                if ($pid < 0) {
                    throw new RuntimeException('无法启动实例托管器。');
                }
                if ($pid === 0) {
                    if (posix_setsid() < 0) {
                        exit(1);
                    }
                    $this->execLogged([$release.'/runtime/bin/php', $release.'/app/portable/yanji.php',
                        '__supervise', '--instance', $this->instance->root], 'manager');
                }
            }
        }
        $deadline = microtime(true) + 85;
        do {
            $status = $this->request('status');
            if ($status !== null && $status['ready']) {
                return $status;
            }
            if (isset($pid)) {
                $exited = Platform::isWindows()
                    ? !Platform::isProcessAlive($pid)
                    : (pcntl_waitpid($pid, $exit, WNOHANG) === $pid);
                if ($exited) {
                    throw new RuntimeException('后台服务启动失败，本次子进程已收回。请运行 yanji doctor。');
                }
            }
            usleep(Platform::isWindows() ? 1000000 : 200000);
        } while (microtime(true) < $deadline);
        if ($existing === null) {
            $this->stop();
        }
        throw new RuntimeException('等待 Web 或后台心跳超时，请运行 yanji doctor。');
    }

    public function stop(): void
    {
        if ($this->request('stop') === null) {
            // A stale PID or socket never authorizes killing a host process.
            $this->assertNoOrphans();
            if (Platform::isWindows() && is_file($this->instance->socketPath())) {
                @unlink($this->instance->socketPath());
            }
            return;
        }
        $deadline = microtime(true) + self::DRAIN_SECONDS + 8;
        do {
            if (!file_exists($this->instance->socketPath())) {
                if (Platform::isWindows()) {
                    try {
                        $this->assertNoOrphans();
                        return;
                    } catch (RuntimeException) {
                        // Wait for supervisor lock release.
                    }
                } else {
                    return;
                }
            }
            usleep(100000);
            clearstatcache();
        } while (microtime(true) < $deadline);
        throw new RuntimeException('托管器尚未确认停止，未向外部 PID 发送信号。');
    }

    // The production worker timeout is 540 seconds. Allow in-flight requests to complete.
    private function assertNoOrphans(): void
    {
        $run = $this->instance->root.'/run';
        if (!is_dir($run)) {
            throw new RuntimeException('找不到实例。');
        }
        $lease = fopen($run.'/services.lock', Platform::lockMode());
        if (!$lease || !flock($lease, LOCK_EX | LOCK_NB)) {
            if (is_resource($lease)) {
                fclose($lease);
            }
            throw new RuntimeException('托管端点无法确认，但仍有服务持有实例锁；已暂停操作，请先核对后台进程。');
        }
        flock($lease, LOCK_UN);
        fclose($lease);
    }

    private const DRAIN_SECONDS = 570;

    /** Replacing the process avoids inherited terminal streams and shell interpolation of arguments. */
    private function execLogged(array $argv, string $role, array $extraEnv = []): never
    {
        $log = $this->instance->root.'/logs/'.$role.'.log';
        chdir($this->instance->release().'/app');
        pcntl_exec('/bin/sh', ['-c', 'exec </dev/null >>"$1" 2>&1; shift; exec "$@"', 'yanji', $log, ...$argv],
            $extraEnv + $this->instance->environment());
        exit(126);
    }

    public function serve(): void
    {
        if (Platform::isWindows()) {
            $this->serveWindows();
            return;
        }
        $this->startTime = time();
        $lock = fopen($this->instance->root.'/run/supervisor.lock', 'ce');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('该实例已有托管器。');
        }
        $path = $this->instance->socketPath();
        Instance::privateDir(dirname($path));
        if (file_exists($path) || is_link($path)) {
            if (filetype($path) !== 'socket' || fileowner($path) !== posix_geteuid()) {
                throw new RuntimeException('管理端点存在非本实例套接字文件。');
            }
            $active = @stream_socket_client('unix://'.$path, $errno, $error, 0.2);
            if ($active) {
                fclose($active);
                throw new RuntimeException('管理端点仍由存活进程持有，拒绝替换。');
            }
            unlink($path);
        }
        $server = stream_socket_server('unix://'.$path, $errno, $message);
        if (!$server || !chmod($path, 0600)) {
            throw new RuntimeException('无法建立私有管理端点。');
        }
        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, function (): void { $this->stopping = true; });
        }
        try {
            $this->settleExpired();
            foreach (['web', 'worker', 'scheduler'] as $role) {
                $this->spawn($role);
            }
            while (!$this->stopping) {
                $this->watchChildren();
                $read = [$server];
                $write = $exclude = null;
                if (@stream_select($read, $write, $exclude, 0, 200000) !== 1) {
                    continue;
                }
                $client = @stream_socket_accept($server, 0);
                if (!$client) {
                    continue;
                }
                try {
                    stream_set_timeout($client, 1);
                    $query = json_decode((string) fgets($client, 256), true);
                    $identity = hash('sha256', $this->instance->root);
                    if (!is_array($query) || ($query['instance'] ?? null) !== $identity) {
                        @fwrite($client, json_encode(['instance' => $identity, 'error' => 'identity'])."\n");
                        continue;
                    }
                    $action = $query['action'] ?? null;
                    if ($action === 'stop') {
                        $this->stopping = true;
                    }
                    if (in_array($action, ['status', 'stop'], true)) {
                        @fwrite($client, json_encode($this->status(), JSON_THROW_ON_ERROR)."\n");
                    }
                } finally {
                    fclose($client);
                }
            }
        } finally {
            $this->drain();
            fclose($server);
            @unlink($path);
            fclose($lock);
        }
    }

    private function serveWindows(): void
    {
        $errLog = $this->instance->root.'/logs/manager-err.log';
        @ini_set('error_log', $errLog);
        $this->startTime = time();
        $lock = fopen($this->instance->root.'/run/supervisor.lock', 'cb');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('该实例已有托管器。');
        }
        $lease = fopen($this->instance->root.'/run/services.lock', 'cb');
        if (!$lease || !flock($lease, LOCK_SH | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('无法获取实例服务租约。');
        }
        @unlink($this->instance->root.'/run/worker-stop');
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
        if (!$server) {
            fclose($lease);
            fclose($lock);
            throw new RuntimeException('无法建立本地回环控制端点。');
        }
        $bound = stream_socket_get_name($server, false);
        $port = (int) substr((string) $bound, strrpos((string) $bound, ':') + 1);
        $this->controlToken = bin2hex(random_bytes(32));
        $controlPath = $this->instance->socketPath();
        $identity = hash('sha256', $this->instance->root);
        Instance::atomicFile($controlPath, json_encode([
            'instance' => $identity,
            'port' => $port,
            'token' => $this->controlToken,
            'pid' => getmypid(),
        ], JSON_THROW_ON_ERROR));
        Platform::protectPrivate($controlPath, false);

        try {
            $this->settleExpired();
            foreach (['web', 'worker', 'scheduler'] as $role) {
                $this->spawnWindows($role);
            }
            while (!$this->stopping) {
                $this->watchChildrenWindows();
                $read = [$server];
                $write = $exclude = null;
                if (@stream_select($read, $write, $exclude, 0, 200000) !== 1) {
                    continue;
                }
                $client = @stream_socket_accept($server, 0);
                if (!$client) {
                    continue;
                }
                try {
                    stream_set_timeout($client, 1);
                    $query = json_decode((string) fgets($client, 512), true);
                    if (!is_array($query) || ($query['instance'] ?? null) !== $identity
                        || !is_string($query['token'] ?? null) || !hash_equals($this->controlToken, $query['token'])) {
                        @fwrite($client, json_encode(['instance' => $identity, 'error' => 'identity'])."\n");
                        continue;
                    }
                    $action = $query['action'] ?? null;
                    if ($action === 'stop') {
                        $this->stopping = true;
                    }
                    if (in_array($action, ['status', 'stop'], true)) {
                        @fwrite($client, json_encode($this->status(), JSON_THROW_ON_ERROR)."\n");
                    }
                } finally {
                    fclose($client);
                }
            }
        } catch (\Throwable $e) {
            @file_put_contents($errLog, gmdate('c').' '.$e::class.': '.$e->getMessage()."\n", FILE_APPEND);
            throw $e;
        } finally {
            $this->drainWindows();
            fclose($server);
            @unlink($controlPath);
            @unlink($this->instance->root.'/run/worker-stop');
            flock($lease, LOCK_UN);
            fclose($lease);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function spawnWindows(string $role): void
    {
        $release = $this->instance->release();
        $php = Platform::phpBinary($release);
        $phpArgs = Platform::phpArgs($release, $this->instance->root);
        $cmd = match ($role) {
            'web' => [Platform::webBinary($release), 'run', '--adapter', 'caddyfile', '--config', $release.'/app/portable/Caddyfile'],
            'worker' => [$php, ...$phpArgs, $release.'/app/artisan', 'yanji:worker', '--no-interaction'],
            'scheduler' => [$php, ...$phpArgs, $release.'/app/artisan', 'schedule:work', '--no-interaction'],
        };
        $generation = bin2hex(random_bytes(16));
        $env = ['YANJI_SERVICE_GENERATION' => $generation];
        if ($role === 'worker') {
            $env['YANJI_WORKER_GENERATION'] = $generation;
        }
        $log = $this->instance->root.'/logs/'.$role.'.log';
        $proc = proc_open(
            $cmd,
            [0 => ['file', 'NUL', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            $release.'/app',
            $env + $this->instance->environment(),
            ['bypass_shell' => true],
        );
        if (!is_resource($proc)) {
            throw new RuntimeException('无法在 Windows 上创建服务子进程：'.$role);
        }
        $info = proc_get_status($proc);
        $this->children[$role] = ['proc' => $proc, 'pid' => (int) $info['pid'], 'since' => time(), 'generation' => $generation];
        $this->event($role, 'started');
    }

    private function watchChildrenWindows(): void
    {
        foreach ($this->children as $role => $child) {
            $info = proc_get_status($child['proc']);
            if ($info['running']) {
                if ($role === 'worker') {
                    JobClock::enforce($this->instance->root.'/run', $child['pid'], $child['generation']);
                }
                continue;
            }
            $exitCode = (int) $info['exitcode'];
            Platform::killProcessTree($child['pid']);
            proc_close($child['proc']);
            unset($this->children[$role]);
            $this->event($role, 'exited');
            $expired = is_file($this->instance->root.'/run/expired-job.json');
            if ($role !== 'worker' || (!$expired && !in_array($exitCode, [0, 12], true))
                || (time() <= $child['since'] + 1 && !$expired && $exitCode !== 0)) {
                throw new RuntimeException('服务异常退出，已停止本实例。');
            }
            $this->settleExpired();
            $this->spawnWindows('worker');
        }
    }

    private function drainWindows(): void
    {
        Instance::atomicFile($this->instance->root.'/run/worker-stop', "stop\n");
        foreach (['web', 'scheduler'] as $role) {
            if (isset($this->children[$role])) {
                Platform::killProcessTree($this->children[$role]['pid']);
            }
        }
        $deadline = microtime(true) + self::DRAIN_SECONDS;
        do {
            $workerRunning = false;
            if (isset($this->children['worker'])) {
                $info = proc_get_status($this->children['worker']['proc']);
                if ($info['running']) {
                    $workerRunning = true;
                    JobClock::enforce($this->instance->root.'/run', $this->children['worker']['pid'], $this->children['worker']['generation']);
                }
            }
            if (!$workerRunning) {
                break;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);
        foreach ($this->children as $role => $child) {
            Platform::killProcessTree($child['pid']);
            proc_close($child['proc']);
            $this->event($role, 'stopped');
        }
        $this->children = [];
        $this->settleExpired();
    }

    private function spawn(string $role): void
    {
        $release = $this->instance->release();
        $cmd = match ($role) {
            'web' => [$release.'/runtime/bin/frankenphp', 'run', '--adapter', 'caddyfile', '--config', $release.'/app/portable/Caddyfile'],
            'worker' => [$release.'/runtime/bin/php', $release.'/app/artisan', 'yanji:worker', '--no-interaction'],
            'scheduler' => [$release.'/runtime/bin/php', $release.'/app/artisan', 'schedule:work', '--no-interaction'],
        };
        $generation = bin2hex(random_bytes(16));
        $pid = pcntl_fork();
        if ($pid < 0) {
            throw new RuntimeException('无法创建服务子进程。');
        }
        if ($pid === 0) {
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGINT, SIG_DFL);
            if (!posix_setpgid(0, 0)) {
                exit(126);
            }
            // Deliberately inherit this shared lease across exec. A dead supervisor cannot authorize
            // backups, upgrades or a second generation while its services still own the database.
            $lease = fopen($this->instance->root.'/run/services.lock', 'c');
            if (!$lease || !flock($lease, LOCK_SH | LOCK_NB)) {
                exit(126);
            }
            $env = ['YANJI_SERVICE_GENERATION' => $generation];
            if ($role === 'worker') {
                $env['YANJI_WORKER_GENERATION'] = $generation;
            }
            $this->execLogged($cmd, $role, $env);
        }
        // Set the group on both sides before either can advance past exec.
        if (!@posix_setpgid($pid, $pid) && posix_getpgid($pid) !== $pid) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
            throw new RuntimeException('无法确认子进程归属。');
        }
        $this->children[$role] = ['pid' => $pid, 'since' => time(), 'generation' => $generation];
        $this->event($role, 'started');
    }

    private function exited(int $pid): bool
    {
        $info = [];
        // Retaining the zombie until its process group is drained prevents PID reuse.
        return pcntl_waitid(P_PID, $pid, $info, WEXITED | WNOHANG | WNOWAIT) && ($info['pid'] ?? 0) === $pid;
    }

    private function watchChildren(): void
    {
        foreach ($this->children as $role => $child) {
            $pid = $child['pid'];
            if (!$this->exited($pid)) {
                if ($role === 'worker') {
                    JobClock::enforce($this->instance->root.'/run', $pid, $child['generation']);
                }
                continue;
            }
            posix_kill(-$pid, SIGKILL);
            pcntl_waitpid($pid, $status);
            unset($this->children[$role]);
            $this->event($role, 'exited');
            if ($role !== 'worker' || (time() <= $child['since'] + 1 && !pcntl_wifexited($status))
                || (pcntl_wifexited($status) && !in_array(pcntl_wexitstatus($status), [0, 12], true))) {
                throw new RuntimeException('服务异常退出，已停止本实例。');
            }
            // A work-limit or memory exit and the framework's hard job timeout both require a successor.
            $this->settleExpired();
            $this->spawn('worker');
        }
    }

    private function settleExpired(): void
    {
        if (!is_file($this->instance->root.'/run/expired-job.json')) {
            return;
        }
        $release = $this->instance->release();
        $p = proc_open([Platform::phpBinary($release), ...Platform::phpArgs($release, $this->instance->root), $release.'/app/portable/settle-timeout.php'],
            [0 => ['file', Platform::nullDevice(), 'r'], 1 => STDOUT, 2 => STDERR], $pipes, $release.'/app', $this->instance->environment(), ['bypass_shell' => true]);
        if (!is_resource($p) || proc_close($p) !== 0) {
            throw new RuntimeException('超时作业仍待确认，已暂停服务。');
        }
    }

    private function drain(): void
    {
        // Signal each owned leader first so that a running job can complete before its descendants terminate.
        foreach (['web', 'scheduler', 'worker'] as $role) {
            if (isset($this->children[$role])) {
                posix_kill($this->children[$role]['pid'], SIGTERM);
            }
        }
        $deadline = microtime(true) + self::DRAIN_SECONDS;
        do {
            $running = array_filter($this->children, fn ($child) => !$this->exited($child['pid']));
            if (isset($running['worker'])) {
                JobClock::enforce($this->instance->root.'/run', $running['worker']['pid'], $running['worker']['generation']);
            }
            if (!$running) {
                break;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);
        foreach ($this->children as $role => $child) {
            posix_kill(-$child['pid'], SIGKILL);
            pcntl_waitpid($child['pid'], $status);
            $this->event($role, 'stopped');
        }
        $this->children = [];
        $this->settleExpired();
    }

    private function status(): array
    {
        $context = stream_context_create(['http' => ['timeout' => Platform::isWindows() ? 2.0 : 0.7, 'follow_location' => 0]]);
        $body = @file_get_contents('http://127.0.0.1:'.$this->instance->port().'/up', false, $context);
        $services = ['web' => ['pid' => $this->children['web']['pid'] ?? null, 'healthy' => $body !== false]];
        foreach (['worker', 'scheduler'] as $role) {
            $child = $this->children[$role] ?? null;
            $heartbeat = $child === null ? null : $this->instance->heartbeat($role, max($child['since'], time() - 150),
                $child['generation'], $child['pid']);
            $services[$role] = ['pid' => $this->children[$role]['pid'] ?? null, 'heartbeat' => $heartbeat, 'healthy' => $heartbeat !== null];
        }
        clearstatcache(true, $this->instance->root.'/run/upgrade-hold');
        return ['instance' => hash('sha256', $this->instance->root), 'manager_pid' => getmypid(),
            'writes_enabled' => !is_file($this->instance->root.'/run/upgrade-hold'),
            'ready' => !$this->stopping && !in_array(false, array_column($services, 'healthy'), true), 'services' => $services];
    }

    private function event(string $role, string $event): void
    {
        // This file contains only lifecycle metadata. Request bodies, credentials and command arguments stay out.
        file_put_contents($this->instance->root.'/logs/lifecycle.log', gmdate('c').' '.$role.' '.$event."\n", FILE_APPEND | LOCK_EX);
    }
}
