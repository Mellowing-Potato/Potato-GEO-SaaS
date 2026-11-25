<?php

// This helper only mints a browser nonce for a disposable native acceptance-test instance.
use Yanji\Portable\Instance;
use Yanji\Portable\DesktopLauncher;

ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function desktopFixtureDiagnostic(Throwable $error, string $stage): array
{
    $classes = ['RuntimeException', 'ErrorException', 'Error', 'TypeError', 'ValueError', 'JsonException',
        'ParseError', 'LogicException', 'InvalidArgumentException', 'Illuminate\\Database\\QueryException'];
    $files = ['Platform.php', 'Instance.php', 'Installation.php', 'DesktopLauncher.php',
        'PortableDesktopAccess.php', 'portable-desktop.php'];
    $codes = [
        '本机自动进入仅适用于已启用单人模式的本机便携实例。' => 'desktop_disabled',
        '浏览器启动文件必须是实例运行目录内的新私有文件。' => 'launch_path_rejected',
        '本机单人模式要求唯一的本机管理员工作区。' => 'owner_not_unique',
        '实例密钥未配置。' => 'instance_key_missing',
        '本机运行目录必须是当前用户的私有目录。' => 'run_directory_untrusted',
        '本机启动授权文件无效。' => 'ticket_path_untrusted',
        '无法读取本机启动授权。' => 'ticket_file_open_failed',
        '无法锁定本机启动授权。' => 'ticket_file_lock_failed',
        '本机启动授权文件已变化。' => 'ticket_file_changed',
        '无法保存本机启动授权。' => 'ticket_write_failed',
        '无法创建浏览器启动文件。' => 'launch_output_open_failed',
        '实例含无法保护的文件或链接，请核对所有者和备份。' => 'file_permissions_rejected',
        '无法在 Windows 上设置实例目录或文件的独占权限。' => 'windows_acl_write_failed',
        '本机工作台授权未完成，请运行 yanji doctor。' => 'launcher_rejected',
        '无法保护本机浏览器启动文件。' => 'launch_html_untrusted',
    ];
    $file = basename(str_replace('\\', '/', $error->getFile()));
    $class = get_class($error);

    return ['fixture' => 'portable-desktop', 'stage' => $stage,
        'exception' => in_array($class, $classes, true) ? $class : 'Throwable',
        'code' => $codes[$error->getMessage()] ?? 'fixture_exception',
        'file' => in_array($file, $files, true) ? $file : 'unknown', 'line' => max(0, $error->getLine())];
}

/** Read only derived booleans from the disposable instance; never serialize filesystem identities or contents. */
function desktopFixtureApplicationFlags(Instance $instance): array
{
    $flags = [];
    foreach (['root' => $instance->root, 'run' => $instance->root.'/run'] as $name => $path) {
        try {
            $flags[$name.'_private'] = \Yanji\Portable\Platform::isPrivate($path);
            $flags[$name.'_private_check_ok'] = true;
        } catch (Throwable) {
            $flags[$name.'_private'] = false;
            $flags[$name.'_private_check_ok'] = false;
        }
    }
    $flags['desktop_enabled'] = \App\Support\PortableDesktopAccess::enabled();
    $ticket = $instance->root.'/run/desktop-ticket.json';
    $flags['ticket_exists'] = is_file($ticket);
    $flags['ticket_is_link'] = is_link($ticket);
    $handle = $flags['ticket_is_link'] ? false : @fopen($ticket, 'rb');
    $stat = $handle ? fstat($handle) : false;
    $current = @lstat($ticket);
    if ($handle) { fclose($handle); }
    $flags['fstat_ok'] = is_array($stat);
    $flags['lstat_ok'] = is_array($current);
    $flags['handle_regular'] = $stat && ($stat['mode'] & 0170000) === 0100000;
    $flags['path_regular'] = $current && ($current['mode'] & 0170000) === 0100000;
    $flags['dev_equal'] = $stat && $current && $stat['dev'] === $current['dev'];
    $flags['ino_equal'] = $stat && $current && $stat['ino'] === $current['ino'];
    $flags['ticket_nonempty'] = $stat && $stat['size'] > 0;

    return $flags;
}

/** This only runs after a launch failure, in the same disposable instance and normal PHP environment. */
function desktopFixtureApplicationDiagnostic(Instance $instance): array
{
    $release = $instance->release();
    $output = $instance->root.'/run/browser-launch-diagnostic-'.bin2hex(random_bytes(8)).'.json';
    $command = [\Yanji\Portable\Platform::phpBinary($release),
        ...\Yanji\Portable\Platform::phpArgs($release, $instance->root), __FILE__,
        '--diagnose-desktop-app', $instance->root, $output];
    $null = \Yanji\Portable\Platform::nullDevice();
    $process = proc_open($command, [0 => ['file', $null, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes, $release.'/app', $instance->environment(), ['bypass_shell' => true]);
    if (!is_resource($process)) {
        return ['stage' => 'diagnostic_process', 'code' => 'spawn_failed'];
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $result = ['stage' => 'diagnostic_process', 'code' => $exit === 0 && $stdout === '' && $stderr === ''
        ? 'application_probe_passed' : 'application_probe_failed', 'exit_code' => $exit,
        'stdout_bytes' => strlen($stdout), 'stderr_bytes' => strlen($stderr)];
    $record = json_decode($stderr, true);
    if (is_array($record) && ($record['fixture'] ?? '') === 'portable-desktop') {
        // Only this fixture's fixed-format diagnostic is returned. Raw subprocess output is never logged.
        $result['detail'] = array_intersect_key($record, array_flip(['stage', 'code', 'exception', 'file', 'line', 'flags']));
    }

    return $result;
}

$stage = 'argument_guard';
$diagnostic = ($argv[1] ?? '') === '--diagnose-desktop-app';
$instance = null;
$output = null;
ob_start();
try {
    $arguments = $diagnostic ? array_slice($argv, 2) : array_slice($argv, 1);
    if (count($arguments) !== 2) {
        throw new InvalidArgumentException;
    }
    [$root, $output] = $arguments;
    $stage = 'instance_guard';
    if (!is_file($root.'/.portable-acceptance') || file_get_contents($root.'/.portable-acceptance') !== "desktop-fixture\n") {
        throw new RuntimeException;
    }
    $stage = 'release_resolve';
    $release = PHP_OS_FAMILY === 'Windows'
        ? trim((string) file_get_contents($root.'/current-release.txt'))
        : realpath($root.'/current');
    $stage = 'runtime_load';
    require $release.'/app/portable/src/Platform.php';
    require $release.'/app/portable/src/Instance.php';
    require $release.'/app/portable/src/Installation.php';
    require $release.'/app/portable/src/DesktopLauncher.php';
    $stage = 'instance_construct';
    $instance = new Instance($root);
    $stage = 'capture_guard';
    if (realpath(dirname($output)) !== realpath($instance->root.'/run') || file_exists($output) || is_link($output)) {
        throw new RuntimeException;
    }
    if ($diagnostic) {
        $stage = 'application_environment';
        foreach ($instance->environment() as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        $stage = 'application_bootstrap';
        require $release.'/app/vendor/autoload.php';
        $app = require $release.'/app/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $stage = 'application_launch_file';
        \App\Support\PortableDesktopAccess::createLaunchFile($output);
        if (is_file($output) && !is_link($output)) {
            unlink($output);
        }
    } else {
        $stage = 'desktop_launch';
        $opened = DesktopLauncher::open($instance, opener: static function (string $uri) use ($output, &$stage): bool {
            $stage = 'capture_write';
            Instance::atomicFile($output, json_encode(['launch_file_uri' => $uri], JSON_THROW_ON_ERROR)."\n");
            return true;
        });
        if (!$opened) {
            throw new RuntimeException;
        }
    }
    $unexpected = ob_get_clean();
    if ($unexpected !== '') {
        fwrite(STDERR, json_encode(['fixture' => 'portable-desktop', 'stage' => $stage,
            'exception' => 'none', 'code' => 'unexpected_output', 'stdout_bytes' => strlen($unexpected)])."\n");
        exit(70);
    }
    exit(0);
} catch (Throwable $error) {
    $bytes = strlen(ob_get_clean());
    $record = desktopFixtureDiagnostic($error, $stage) + ['stdout_bytes' => $bytes];
    if ($diagnostic && $stage === 'application_launch_file' && $instance !== null) {
        try {
            $record['flags'] = desktopFixtureApplicationFlags($instance);
        } catch (Throwable) {
            $record['flags'] = ['probe_failed' => true];
        }
    }
    if (!$diagnostic && $stage === 'desktop_launch' && $instance !== null) {
        try {
            $record['application'] = desktopFixtureApplicationDiagnostic($instance);
        } catch (Throwable $diagnosticError) {
            $record['application'] = desktopFixtureDiagnostic($diagnosticError, 'diagnostic_process');
        }
    }
    fwrite(STDERR, json_encode($record, JSON_UNESCAPED_SLASHES)."\n");
    exit(70);
}
