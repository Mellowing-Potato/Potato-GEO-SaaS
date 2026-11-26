<?php

declare(strict_types=1);

use Yanji\Portable\Backup;
use Yanji\Portable\DesktopLauncher;
use Yanji\Portable\DesktopEntry;
use Yanji\Portable\Installation;
use Yanji\Portable\Instance;
use Yanji\Portable\Platform;
use Yanji\Portable\Onboarding;
use Yanji\Portable\Supervisor;
use Yanji\Portable\Upgrade;

umask(0077);
foreach (['Platform', 'Instance', 'Supervisor', 'Installation', 'DesktopLauncher', 'DesktopEntry', 'Onboarding', 'Upgrade', 'JobClock'] as $class) {
    require __DIR__.'/src/'.$class.'.php';
}
if (is_file(__DIR__.'/src/Backup.php')) {
    require __DIR__.'/src/Backup.php';
}

function runPortable(array $args): int
{
    $command = array_shift($args) ?: 'help';
    if (in_array($command, ['--help', '-h', 'help'], true) || ($command === '--instance-default' && count($args) <= 1)) {
        echo "yanji start | stop | restart | open | entry | onboarding | status | logs | doctor | backup --output FILE | restore --from FILE --instance DIR --port N | upgrade --manifest FILE --manifest-sha256 SHA | reset-password --email EMAIL | magpie --tenant=ID\n--no-browser skips opening a desktop browser; authentication stays enabled.\n";
        return 0;
    }
    $options = [];
    $booleans = ['probe','authorize','no-browser'];
    $allowed = ['instance', 'instance-default', 'release', 'port', 'email', 'password-file', 'output',
        'from', 'manifest', 'manifest-sha256', 'package', 'tenant', 'python-bin', ...$booleans];
    while ($args) {
        $arg = array_shift($args);
        [$name,$value] = array_pad(explode('=', $arg, 2), 2, null);
        if (!str_starts_with($name, '--') || !in_array($key = substr($name, 2), $allowed, true) || isset($options[$key])) {
            throw new RuntimeException('参数未知或重复，请运行 yanji help。');
        }
        if (in_array($key, $booleans, true)) {
            if ($value !== null) {
                throw new RuntimeException('开关仅接受不带值的形式。');
            }
            $options[$key] = true;
        } else {
            $options[$key] = $value ?? array_shift($args) ?? throw new RuntimeException('选项缺少参数。');
        }
    }
    $root = $options['instance'] ?? $options['instance-default'] ?? throw new RuntimeException('请指定 --instance。');
    $i = new Instance($root);
    $services = new Supervisor($i);
    $setup = new Installation($i);
    $release = Platform::normalize((string) realpath(__DIR__.'/../..'));
    if ($command === 'restore') {
        $from = $options['from'] ?? throw new RuntimeException('请使用 --from 指定备份文件。');
        $port = filter_var($options['port'] ?? 8000, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>65535]]);
        if (!$port) {
            throw new RuntimeException('端口无效。');
        }
        $python = $options['python-bin'] ?? null;
        $setup->checkPython($release, $python);
        Backup::restore($i, $from, $release, $port);
        $setup->configure($release, $port);
        $setup->protectInstancePaths();
        $setup->savePython($python);
        $setup->finishRestore();
        prepareDesktopEntry($i);
        $services->start();
        echo "恢复完成 http://127.0.0.1:".$i->port()."\n";
        if (!DesktopLauncher::open($i, automatic: true, noBrowser: isset($options['no-browser']))) {
            echo "未能自动拉起浏览器，请直接在浏览器打开 http://127.0.0.1:".$i->port()."（或在图形桌面运行 open）。\n";
        }
        return 0;
    }
    if ($command === '__supervise') {
        $services->serve();
        return 0;
    }
    if ($command === 'upgrade') {
        $installed = $i->release();
        if (Platform::isWindows()) {
            $script = Platform::nativePath($installed.'/app/scripts/install-local.ps1');
            $forward = [Platform::windowsPowerShell(), '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $script,
                '-Upgrade', '-ProgramHome', Platform::nativePath(dirname($installed, 2)), '-Instance', Platform::nativePath($i->root)];
            $map = ['manifest' => '-Manifest', 'manifest-sha256' => '-ManifestSha256', 'package' => '-Package'];
            foreach ($map as $key => $flag) {
                if (isset($options[$key])) {
                    array_push($forward, $flag, $options[$key]);
                }
            }
            if (isset($options['no-browser'])) { $forward[] = '-NoBrowser'; }
            $procEnv = Platform::windowsPowerShellEnv(getenv());
        } else {
            $script = $installed.'/app/scripts/install-local.sh';
            $forward = ['/bin/sh', $script, '--upgrade', '--program-home', dirname($installed, 2), '--instance', $i->root];
            foreach (['manifest', 'manifest-sha256', 'package'] as $key) {
                if (isset($options[$key])) {
                    array_push($forward, '--'.$key, $options[$key]);
                }
            }
            if (isset($options['no-browser'])) { $forward[] = '--no-browser'; }
            $procEnv = null;
        }
        $p = proc_open($forward, [STDIN, STDOUT, STDERR], $pipes, null, $procEnv, ['bypass_shell' => true]);
        return is_resource($p) ? proc_close($p) : 1;
    }
    if ($command === '__install') {
        $i->validateDestination();
        Instance::privateDir($i->root);
        Instance::privateDir($i->root.'/run');
    }
    if ($command === 'status') {
        $status = $services->request('status');
        echo json_encode($status ?? ['ready'=>false,'services'=>[], 'state'=>'stopped'], JSON_UNESCAPED_SLASHES)."\n";
        return 0;
    }
    if ($command === 'onboarding') {
        echo json_encode(Onboarding::report($i, $services->request('status')),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        return 0;
    }
    if ($command === 'logs') {
        $log = $i->root.'/logs/lifecycle.log';
        if (is_file($log) && !is_link($log)) {
            $f = new SplFileObject($log);
            $f->seek(PHP_INT_MAX);
            $f->seek(max(0, $f->key() - 100));
            while (!$f->eof()) {
                echo $f->fgets();
            }
        }
        return 0;
    }
    if ($command === 'doctor') {
        $i->release();
        $required = ['pdo_sqlite', 'sqlite3', 'gd', 'zip', 'curl', 'mbstring', 'openssl', 'fileinfo', 'xml', 'dom', 'ctype', 'tokenizer'];
        if (!Platform::isWindows()) {
            array_push($required, 'pcntl', 'posix');
        }
        $missing = array_values(array_filter($required, static fn ($name) => !extension_loaded($name)));
        $ready = $i->artisan(['yanji:ready', '--portable'], quiet: true, uncached: true) === 0;
        $skill = $i->artisan(['geo:audit-skill-assets'], quiet: true, uncached: true) === 0;
        $python = Installation::pythonStatus($i);
        $permissions = true;
        foreach (['.env', 'database/database.sqlite', 'storage', 'logs', 'bootstrap-cache'] as $path) {
            $permissions = $permissions && Platform::isPrivate($i->root.'/'.$path);
        }
        echo json_encode(['instance' => $i->root, 'release' => $i->release(), 'php' => PHP_VERSION, 'ini' => php_ini_loaded_file(),
            'missing_extensions' => $missing, 'permissions' => $permissions, 'database_key_admin_ready' => $ready,
            'skill_assets_ready' => $skill, 'python' => $python, 'services' => $services->request('status')], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        return $ready && !$missing && $permissions && $skill && $python['basic'] ? 0 : 1;
    }
    $lock = $i->commandLock();
    try {
        $explicitPort = null;
        if (isset($options['port'])) {
            $explicitPort = filter_var($options['port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
            if (!$explicitPort) {
                throw new RuntimeException('端口需为 1 至 65535。');
            }
        }
        $autoUpgrade = $command === '__install' && Installation::shouldUpgradeExisting(
            $i,
            $release,
            $explicitPort,
            $options['email'] ?? null,
            $options['password-file'] ?? null,
        );
        if ($command !== '__upgrade' && !$autoUpgrade && is_file($i->root.'/run/upgrade-hold') && $command !== 'stop') {
            throw new RuntimeException('升级维护门仍在，请重新执行原升级命令恢复，或向新目录恢复备份。');
        }
        if (in_array($command, ['__install','__upgrade','start','restart','open','entry','reset-password','magpie'], true)) {
            $i->discardConfigurationCache();
        }
        switch ($command) {
            case '__install':
                if (!file_exists($i->root.'/.portable-layout')) {
                    Instance::atomicFile($i->root.'/.portable-layout', "yanji-portable-v1\n");
                }
                if ($release !== Platform::normalize((string) ($options['release'] ?? ''))) {
                    throw new RuntimeException('程序版本路径不匹配。');
                }
                if ($autoUpgrade) {
                    $setup->checkPython($release, $options['python-bin'] ?? null);
                    fwrite(STDERR, "检测到已有言及实例，正在自动备份并平滑升级程序版本...\n");
                    Upgrade::run($i, $release, $services);
                    $setup->savePython($options['python-bin'] ?? null);
                    prepareDesktopEntry($i);
                    $port = $i->port();
                    echo "已就绪 http://127.0.0.1:".$port."  管理命令 ".Platform::nativePath($i->root.'/'.Platform::launcherName())."\n";
                    if (!DesktopLauncher::open($i, automatic: true, noBrowser: isset($options['no-browser']))) {
                        echo "未能自动拉起浏览器，请直接在浏览器打开 http://127.0.0.1:".$port."（或在图形桌面运行 open）。\n";
                    }
                    break;
                }
                $port = $explicitPort ?? (is_file($i->root.'/instance.json') ? $i->port() : 8000);
                $setup->install($release, $port, $options['email'] ?? null, $options['password-file'] ?? null, $options['python-bin'] ?? null);
                prepareDesktopEntry($i);
                $services->start();
                echo "已就绪 http://127.0.0.1:".$port."  管理命令 ".Platform::nativePath($i->root.'/'.Platform::launcherName())."\n";
                if (!DesktopLauncher::open($i, automatic: true, noBrowser: isset($options['no-browser']))) {
                    echo "未能自动拉起浏览器，请直接在浏览器打开 http://127.0.0.1:".$port."（或在图形桌面运行 open）。\n";
                }
                break;
            case '__upgrade':
                if ($release !== Platform::normalize((string) ($options['release'] ?? ''))) {
                    throw new RuntimeException('升级版本路径不匹配。');
                }
                Upgrade::run($i, $release, $services);
                prepareDesktopEntry($i);
                if (!DesktopLauncher::open($i, automatic: true, noBrowser: isset($options['no-browser']))) {
                    echo "未能自动拉起浏览器，请直接在浏览器打开 http://127.0.0.1:".$i->port()."（或在图形桌面运行 open）。\n";
                }
                break;
            case 'entry':
                if (!Installation::desktopMode($i)) {
                    throw new RuntimeException('此实例使用账号登录，不创建免登录的本机打开入口。');
                }
                prepareDesktopEntry($i);
                break;
            case 'start':
            case 'open':
                if (Installation::desktopMode($i) && DesktopEntry::describe($i)['file'] === null) {
                    prepareDesktopEntry($i);
                }
                $services->start();
                echo "已就绪 http://127.0.0.1:".$i->port()."\n";
                if (!DesktopLauncher::open($i, automatic: $command !== 'open', noBrowser: isset($options['no-browser']))) {
                    echo "未能自动拉起浏览器，请直接在浏览器打开 http://127.0.0.1:".$i->port()."（或在图形桌面运行 open）。\n";
                }
                break;
            case 'stop':
                $services->stop();
                break;
            case 'restart':
                $services->stop();
                $services->start();
                if (!DesktopLauncher::open($i, automatic: true, noBrowser: isset($options['no-browser']))) {
                    echo "未能自动拉起浏览器，请直接在浏览器打开 http://127.0.0.1:".$i->port()."（或在图形桌面运行 open）。\n";
                }
                break;
            case 'backup':
                $output = $options['output'] ?? throw new RuntimeException('请使用 --output 指定新的备份文件。');
                $running = $services->request('status') !== null;
                $services->stop();
                try {
                    Backup::create($i, $output);
                } finally {
                    if ($running) {
                        $services->start();
                    }
                }
                echo "备份已完成。\n";
                break;
            case 'reset-password':
                $email = $options['email'] ?? throw new RuntimeException('请指定要改密的 --email。');
                [$args,$input] = Installation::passwordInput(['yanji:reset-password', $email, '--portable'], $options['password-file'] ?? null);
                return $i->artisan($args, $input);
            case 'magpie':
                $args = ['yanji:magpie'];
                foreach (['tenant','probe','authorize'] as $key) {
                    if (isset($options[$key])) {
                        $args[] = '--'.$key.($options[$key] === true ? '' : '='.$options[$key]);
                    }
                }
                return $i->artisan($args);
            default:
                throw new RuntimeException('命令未知，请运行 yanji help。');
        }
        return 0;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function prepareDesktopEntry(Instance $instance): void
{
    if (!Installation::desktopMode($instance)) {
        return;
    }
    $entry = DesktopEntry::install($instance);
    if ($entry['file'] === null) {
        throw new RuntimeException('未能创建本机打开入口，请检查安装目录的权限或重名文件后重试。');
    }
    echo '日常使用可直接打开 http://127.0.0.1:'.$instance->port().' ，或双击“'.$entry['name'].'”自动启动并打开工作台。'."\n";
    if ($entry['warnings']) {
        echo '桌面或菜单入口未能全部创建，已保留本机双击入口：'.Platform::nativePath((string) $entry['file'])."\n";
    }
}

try {
    exit(runPortable(array_slice($argv, 1)));
} catch (Throwable $error) {
    fwrite(STDERR, $error instanceof RuntimeException ? $error->getMessage()."\n" : "操作失败，请运行 yanji doctor。\n");
    exit(1);
}
