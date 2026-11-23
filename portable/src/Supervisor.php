<?php

declare(strict_types=1);

namespace Yanji\Portable;

use RuntimeException;

final class Supervisor
{
    private array $children = [];
    private bool $stopping = false;
    private int $startTime = 0;

    public function __construct(private Instance $instance) {}

    public function request(string $action): ?array
    {
        $socket = @stream_socket_client('unix://'.$this->instance->socketPath(), $errno, $message, 0.4);
        if (!$socket) {
            return null;
        }
        try {
            stream_set_timeout($socket, 3);
            fwrite($socket, $action."\n");
            $line = fgets($socket, 16384);
            $reply = $line === false ? null : json_decode($line, true);
            if (!is_array($reply) || ($reply['instance'] ?? '') !== hash('sha256', $this->instance->root)) {
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
            if ($this->instance->artisan(['yanji:ready', '--portable', '--quiet'], quiet: true) !== 0) {
                throw new RuntimeException('实例密钥、管理员或数据库未就绪，请运行 yanji doctor。');
            }
            $probe = @stream_socket_server('tcp://127.0.0.1:'.$this->instance->port(), $errno, $error);
            if (!$probe) {
                throw new RuntimeException('实例端口已被占用，请选择其他端口。');
            }
            fclose($probe);
            $pid = pcntl_fork();
            if ($pid < 0) {
                throw new RuntimeException('无法启动实例托管器。');
            }
            if ($pid === 0) {
                if (posix_setsid() < 0) {
                    exit(1);
                }
                $release = $this->instance->release();
                $this->execLogged([$release.'/runtime/bin/php', $release.'/app/portable/yanji.php',
                    '__supervise', '--instance', $this->instance->root], 'manager');
            }
        }
        $deadline = microtime(true) + 85;
        do {
            $status = $this->request('status');
            if ($status !== null && $status['ready']) {
                return $status;
            }
            if (isset($pid) && pcntl_waitpid($pid, $exit, WNOHANG) === $pid) {
                throw new RuntimeException('后台服务启动失败，本次子进程已收回。请运行 yanji doctor。');
            }
            usleep(200000);
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
            return;
        }
        $deadline = microtime(true) + self::DRAIN_SECONDS + 8;
        do {
            if (!file_exists($this->instance->socketPath())) {
                return;
            }
            usleep(100000);
            clearstatcache();
        } while (microtime(true) < $deadline);
        throw new RuntimeException('托管器尚未确认停止，未向外部 PID 发送信号。');
    }

    // The production worker timeout is 540 seconds. Allow in-flight requests to complete.
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
            foreach (['web','worker','scheduler'] as $role) {
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
                    $action = trim((string) fgets($client, 128));
                    if ($action === 'stop') {
                        $this->stopping = true;
                    }
                    if (in_array($action, ['status','stop'], true)) {
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
            $this->execLogged($cmd, $role, $role === 'worker' ? ['YANJI_WORKER_GENERATION'=>$generation] : []);
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
                || (pcntl_wifexited($status) && !in_array(pcntl_wexitstatus($status), [0,12], true))) {
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
        $p = proc_open([$release.'/runtime/bin/php', $release.'/app/portable/settle-timeout.php'],
            [0=>['file','/dev/null','r'], 1=>STDOUT, 2=>STDERR], $pipes, $release.'/app', $this->instance->environment());
        if (!is_resource($p) || proc_close($p) !== 0) {
            throw new RuntimeException('超时作业仍待确认，已暂停服务。');
        }
    }

    private function drain(): void
    {
        // Signal each owned leader first so that a running job can complete before its descendants terminate.
        foreach (['web','scheduler','worker'] as $role) {
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
        $context = stream_context_create(['http' => ['timeout' => 0.7, 'follow_location' => 0]]);
        $body = @file_get_contents('http://127.0.0.1:'.$this->instance->port().'/up', false, $context);
        $services = ['web' => ['pid' => $this->children['web']['pid'] ?? null, 'healthy' => $body !== false]];
        foreach (['worker','scheduler'] as $role) {
            $heartbeat = $this->instance->heartbeat($role, max($this->startTime, time() - 150));
            $services[$role] = ['pid' => $this->children[$role]['pid'] ?? null, 'heartbeat' => $heartbeat, 'healthy' => $heartbeat !== null];
        }
        return ['instance' => hash('sha256', $this->instance->root), 'manager_pid' => getmypid(),
            'ready' => !$this->stopping && !in_array(false, array_column($services, 'healthy'), true), 'services' => $services];
    }

    private function event(string $role, string $event): void
    {
        // This file contains only lifecycle metadata. Request bodies, credentials and command arguments stay out.
        file_put_contents($this->instance->root.'/logs/lifecycle.log', gmdate('c').' '.$role.' '.$event."\n", FILE_APPEND | LOCK_EX);
    }
}
