<?php

declare(strict_types=1);

use Yanji\Portable\Backup;
use Yanji\Portable\Installation;
use Yanji\Portable\Instance;
use Yanji\Portable\Supervisor;
use Yanji\Portable\Upgrade;

umask(0077);
foreach (['Instance','Supervisor','Installation','Upgrade','JobClock'] as $class) {
    require __DIR__.'/src/'.$class.'.php';
}
if (is_file(__DIR__.'/src/Backup.php')) {
    require __DIR__.'/src/Backup.php';
}

function runPortable(array $args): int
{
    $command = array_shift($args) ?: 'help';
    if (in_array($command, ['--help', '-h', 'help'], true)) {
        echo "yanji start | stop | restart | status | logs | doctor | backup --output FILE | restore --from FILE --instance DIR --port N | upgrade --manifest FILE --manifest-sha256 SHA | reset-password --email EMAIL | magpie --tenant=ID\n";
        return 0;
    }
    $options = [];
    $booleans = ['probe','authorize'];
    $allowed = ['instance', 'instance-default', 'release', 'port', 'email', 'password-file', 'output',
        'from', 'manifest', 'manifest-sha256', 'package', 'tenant', 'python-bin', ...$booleans];
    while ($args) {
        $arg = array_shift($args);
        [$name,$value] = array_pad(explode('=', $arg, 2), 2, null);
        if (!str_starts_with($name, '--') || !in_array($key = substr($name, 2), $allowed, true) || isset($options[$key])) {
            throw new RuntimeException('参数未知或重复，请运行 yanji help。');
        }
        if (in_array($key, $booleans, true)) {
            $options[$key] = true;
        } else {
            $options[$key] = $value ?? array_shift($args) ?? throw new RuntimeException('选项缺少参数。');
        }
    }
    $root = $options['instance'] ?? $options['instance-default'] ?? throw new RuntimeException('请指定 --instance。');
    $i = new Instance($root);
    $services = new Supervisor($i);
    $setup = new Installation($i);
    $release = realpath(__DIR__.'/../..');
    if ($command === 'restore') {
        $from = $options['from'] ?? throw new RuntimeException('请使用 --from 指定备份文件。');
        $port = filter_var($options['port'] ?? 8000, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>65535]]);
        if (!$port) {
            throw new RuntimeException('端口无效。');
        }
        Backup::restore($i, $from, $release, $port);
        $setup->configure($release, $port);
        $setup->configurePython($options['python-bin'] ?? null);
        if ($i->artisan(['yanji:ready','--portable'], quiet:true) !== 0) {
            throw new RuntimeException('恢复的实例密钥与数据库未通过就绪检查。');
        }
        Instance::atomicFile($i->root.'/.portable-initialized', "verified\n");
        $services->start();
        echo "恢复完成 http://127.0.0.1:".$i->port()."\n";
        return 0;
    }
    if ($command === '__supervise') {
        $services->serve();
        return 0;
    }
    if ($command === 'upgrade') {
        $installed = $i->release();
        $script = $installed.'/app/scripts/install-local.sh';
        $forward = ['/bin/sh', $script, '--upgrade', '--program-home', dirname($installed,2), '--instance', $i->root];
        foreach (['manifest','manifest-sha256','package'] as $key) {
            if (isset($options[$key])) {
                array_push($forward, '--'.$key, $options[$key]);
            }
        }
        $p = proc_open($forward, [STDIN,STDOUT,STDERR], $pipes);
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
        $required = ['pdo_sqlite','sqlite3','gd','zip','curl','mbstring','openssl','fileinfo','xml','dom','ctype','tokenizer','pcntl','posix'];
        $missing = array_values(array_filter($required, static fn ($name) => !extension_loaded($name)));
        $ready = $i->artisan(['yanji:ready','--portable'], quiet: true) === 0;
        $skill = $i->artisan(['geo:audit-skill-assets'], quiet:true) === 0;
        $python = Installation::pythonStatus($i);
        $permissions = true;
        foreach (['.env', 'database/database.sqlite', 'storage','logs','bootstrap-cache'] as $path) {
            $path = $i->root.'/'.$path;
            $permissions = $permissions && file_exists($path) && !is_link($path)
                && fileowner($path) === posix_geteuid() && (fileperms($path) & 0077) === 0;
        }
        echo json_encode(['instance'=>$i->root, 'release'=>$i->release(), 'php'=>PHP_VERSION,'ini'=>php_ini_loaded_file(),
            'missing_extensions'=>$missing, 'permissions'=>$permissions,'database_key_admin_ready'=>$ready,
            'skill_assets_ready'=>$skill,'python'=>$python,'services'=>$services->request('status')], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
        return $ready && !$missing && $permissions && $skill && $python['basic'] ? 0 : 1;
    }
    $lock = $i->commandLock();
    try {
        if ($command !== '__upgrade' && is_file($i->root.'/run/upgrade-hold') && $command !== 'stop') {
            throw new RuntimeException('升级维护门仍在，请重新执行原升级命令恢复，或向新目录恢复备份。');
        }
        switch ($command) {
            case '__install':
                if (!file_exists($i->root.'/.portable-layout')) {
                    Instance::atomicFile($i->root.'/.portable-layout', "yanji-portable-v1\n");
                }
                if ($release !== ($options['release'] ?? '')) {
                    throw new RuntimeException('程序版本路径不匹配。');
                }
                $port = filter_var($options['port'] ?? (is_file($i->root.'/instance.json') ? $i->port() : 8000),
                    FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>65535]]);
                if (!$port) {
                    throw new RuntimeException('端口需为 1 至 65535。');
                }
                $setup->install($release, $port, $options['email'] ?? null, $options['password-file'] ?? null, $options['python-bin'] ?? null);
                $services->start();
                echo "已就绪 http://127.0.0.1:".$port."  管理命令 ".$i->root."/yanji\n";
                break;
            case '__upgrade':
                if ($release !== ($options['release'] ?? '')) {
                    throw new RuntimeException('升级版本路径不匹配。');
                }
                Upgrade::run($i, $release, $services);
                break;
            case 'start':
                $services->start();
                echo "已就绪 http://127.0.0.1:".$i->port()."\n";
                break;
            case 'stop':
                $services->stop();
                break;
            case 'restart':
                $services->stop();
                $services->start();
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

try {
    exit(runPortable(array_slice($argv, 1)));
} catch (Throwable $error) {
    fwrite(STDERR, $error instanceof RuntimeException ? $error->getMessage()."\n" : "操作失败，请运行 yanji doctor。\n");
    exit(1);
}
