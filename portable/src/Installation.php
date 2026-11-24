<?php

declare(strict_types=1);

namespace Yanji\Portable;

use RuntimeException;

require_once __DIR__.'/Platform.php';

final class Installation
{
    public function __construct(private Instance $instance) {}

    public function configure(string $release, int $port): void
    {
        $release = Platform::normalize($release);
        $i = $this->instance;
        $launcherName = Platform::launcherName();
        $launcher = @file_get_contents($release.'/app/portable/'.$launcherName);
        if ($launcher === false || $launcher === '' || !is_file($release.'/runtime/ca/cacert.pem')
            || !is_readable($release.'/runtime/ca/cacert.pem')) {
            throw new RuntimeException('程序缺少启动脚本或可用的 CA 文件。');
        }
        if (is_file($i->root.'/instance.json') && $i->port() !== $port) {
            throw new RuntimeException('实例端口与原配置不同。恢复至新目录时可另选端口。');
        }
        $i->prepare();
        if (!is_file($i->root.'/instance.json')) {
            Instance::atomicFile($i->root.'/instance.json', json_encode(['port' => $port], JSON_THROW_ON_ERROR)."\n");
        }
        $i->activate($release);
        if (Platform::isWindows()) {
            $launcher = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $launcher));
        }
        Instance::atomicFile($i->root.'/'.$launcherName, $launcher, 0700);
        $ini = "upload_max_filesize=10M\npost_max_size=20M\nmemory_limit=512M\ndisplay_errors=Off\n"
            ."log_errors=On\nexpose_php=Off\nvariables_order=EGPCS\n"
            ."curl.cainfo=\"\${YANJI_CA_BUNDLE}\"\nopenssl.cafile=\"\${YANJI_CA_BUNDLE}\"\n";
        if (Platform::isWindows()) {
            $extDir = Platform::windowsShortPath($release.'/runtime/bin/ext');
            $ini .= 'extension_dir="'.$extDir."\"\n"
                ."extension=curl\nextension=fileinfo\nextension=gd\nextension=mbstring\n"
                ."extension=openssl\nextension=pdo_sqlite\nextension=sqlite3\nextension=zip\n";
        }
        Instance::atomicFile($i->root.'/php.ini', $ini);
    }

    public function install(string $release, int $port, ?string $email, ?string $passwordFile, ?string $python): void
    {
        $release = Platform::normalize($release);
        $i = $this->instance;
        foreach (['.env', 'database', 'database/database.sqlite', 'instance.json', 'php.ini'] as $name) {
            $path = $i->root.'/'.$name;
            if (is_link($path)) {
                throw new RuntimeException('实例内的敏感路径指向外部文件，拒绝初始化。');
            }
        }
        $db = $i->root.'/database/database.sqlite';
        $initialized = $i->root.'/.portable-initialized';
        if (is_file($initialized) && (!is_file($db) || filesize($db) === 0 || !is_file($i->root.'/.env'))) {
            throw new RuntimeException('已初始化实例缺数据库或环境文件，请恢复配套备份。');
        }
        if (!is_file($db) && is_file($i->root.'/.env') && preg_match('/^APP_KEY=[^\r\n]+$/m', file_get_contents($i->root.'/.env'))) {
            throw new RuntimeException('原环境密钥仍在，但数据库缺失，请恢复配套备份。');
        }
        if (!is_file($i->root.'/.env') && is_file($db) && filesize($db) > 0) {
            throw new RuntimeException('现有数据库缺少原环境文件，请恢复配套备份。');
        }
        if ($i->hasReleasePointer() && $i->release() !== $release) {
            $keyed = is_file($i->root.'/.env') && preg_match('/^APP_KEY=[^\r\n]+$/m', file_get_contents($i->root.'/.env'));
            if (is_file($initialized) || $keyed || (is_file($db) && filesize($db) > 0)) {
                throw new RuntimeException('实例已有其他程序版本，请使用 yanji upgrade。');
            }
        }
        $fresh = !is_file($i->root.'/.env');
        $template = $fresh ? @file_get_contents($release.'/app/portable/instance.env.example') : null;
        if ($fresh && (!is_string($template) || !str_contains($template, 'DB_DATABASE='))) {
            throw new RuntimeException('程序缺少实例环境模板。');
        }
        $password = ($fresh || $passwordFile !== null) ? self::passwordInput([], $passwordFile) : null;
        if ($fresh && $passwordFile !== null && $email === null) {
            throw new RuntimeException('非交互初始化需要 --email 指定管理员邮箱。');
        }
        if ($fresh && $password !== null && $password[1] !== null) {
            $length = mb_strlen(rtrim($password[1], "\r\n"));
            if ($length < 8 || $length > 128) {
                throw new RuntimeException('管理员密码长度应为 8 至 128 个字符。');
            }
        }
        $this->checkPython($release, $python);
        $this->configure($release, $port);
        $this->savePython($python);
        if ($fresh) {
            // The explicit environment resolves relocated paths for restored instances.
            $quoted = '"'.strtr($db, ['\\' => '\\\\', '"' => '\\"', '$' => '\\$']).'"';
            $env = preg_replace_callback('/^DB_DATABASE=.*$/m', static fn () => 'DB_DATABASE='.$quoted, $template);
            $env = preg_replace('/^SESSION_COOKIE=.*$/m', 'SESSION_COOKIE=yanji_'.substr(hash('sha256', $i->root), 0, 24), $env);
            $env = preg_replace('/^APP_URL=.*$/m', 'APP_URL=http://127.0.0.1:'.$port, $env);
            Instance::atomicFile($i->root.'/.env', $env);
        }
        // Newer readiness probes inspect the schema before migrate can create an empty SQLite file.
        if (!file_exists($db)) {
            $freshDb = fopen($db, 'xb');
            if (!$freshDb) {
                throw new RuntimeException('无法创建实例数据库。');
            }
            fclose($freshDb);
        }
        $this->protectInstancePaths();
        if ($i->artisan(['yanji:ready', '--portable', '--quiet'], quiet: true) === 0) {
            Instance::atomicFile($initialized, "verified\n");
            return;
        }
        $args = ['yanji:install', '--portable'];
        if ($email !== null) {
            $args[] = '--email='.$email;
        }
        [$passwordArgs, $input] = $password ?? self::passwordInput([], $passwordFile);
        $args = [...$args, ...$passwordArgs];
        if ($i->artisan($args, $input) !== 0 || $i->artisan(['yanji:ready', '--portable'], quiet: true) !== 0) {
            throw new RuntimeException('初始化未完成。请使用原实例目录重试或运行 yanji doctor。');
        }
        Instance::atomicFile($initialized, "verified\n");
    }

    public function protectInstancePaths(): void
    {
        $i = $this->instance;
        $db = $i->root.'/database/database.sqlite';
        foreach (['.env', 'database', 'storage', 'logs', 'bootstrap-cache'] as $name) {
            $path = $i->root.'/'.$name;
            if (is_dir($path) && !Platform::isWindows()) {
                $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST);
                foreach ($files as $file) {
                    $this->securePath($file->getPathname());
                }
            }
            $this->securePath($path);
        }
        if (Platform::isWindows() && is_file($db)) {
            $this->securePath($db);
        }
    }

    private function securePath(string $path): void
    {
        Platform::protectPrivate($path, is_dir($path));
    }

    public function checkPython(string $release, ?string $python): void
    {
        if ($python !== null && (!Platform::isAbsolute($python) || !Platform::isExecutable($python)
            || strpbrk($python, "\r\n\0") !== false)) {
            throw new RuntimeException('请用 --python-bin 指定本机 Python 解释器的绝对路径。');
        }
        $bin = $python ?? $this->instance->pythonBin($release);
        if (!(self::probePython($bin)['basic'] ?? false)) {
            throw new RuntimeException('基础报告依赖缺失。请用 --python-bin 指定有 python-docx、Markdown、beautifulsoup4 的 Python。');
        }
    }

    public function savePython(?string $python): void
    {
        if ($python !== null) {
            Instance::atomicFile($this->instance->root.'/python-path', $python."\n");
        }
    }

    public static function pythonStatus(Instance $i): array
    {
        return self::probePython($i->pythonBin($i->release()));
    }

    private static function probePython(string $bin): array
    {
        $keep = ['HOME', 'LANG', 'LC_ALL', 'PATH', 'TMPDIR', 'SystemRoot', 'TEMP', 'TMP', 'LOCALAPPDATA', 'USERPROFILE'];
        if (Platform::isWindows()) {
            $keepUpper = array_flip(array_map('strtoupper', $keep));
            $env = [];
            foreach (getenv() as $k => $v) {
                if (isset($keepUpper[strtoupper((string) $k)])) {
                    $env[$k] = $v;
                }
            }
        } else {
            $env = array_intersect_key(getenv(), array_flip($keep));
        }
        $env['PYTHONUTF8'] = '1';
        $env['PYTHONDONTWRITEBYTECODE'] = '1';
        $script = <<<'PYTHON'
import importlib
import json
import shutil
import sys

def load_module(name):
    try:
        importlib.import_module(name)
        return True
    except (OSError, ImportError):
        return False

try:
    import bs4, markdown
    from docx import Document
    Document()
    basic = True
except (ImportError, OSError):
    basic = False
print(json.dumps(dict(basic=basic, version=sys.version.split()[0], weasyprint=load_module('weasyprint'),
    reportlab=load_module('reportlab'), pypdf=load_module('pypdf'), poppler=bool(shutil.which('pdftotext')))))
PYTHON;
        $null = Platform::nullDevice();
        $process = @proc_open([$bin, '-I', '-c', $script],
            [0 => ['file', $null, 'r'], 1 => ['pipe', 'w'], 2 => ['file', $null, 'w']], $pipes, null, $env, ['bypass_shell' => true]);
        $data = null;
        if (is_resource($process)) {
            $text = stream_get_contents($pipes[1], 4096);
            fclose($pipes[1]);
            if (proc_close($process) === 0) {
                $data = json_decode($text, true);
            }
        }
        return ['interpreter' => $bin] + (is_array($data) ? $data : ['basic' => false]);
    }

    public static function passwordInput(array $args, ?string $file): array
    {
        if ($file === null) {
            if (!stream_isatty(STDIN)) {
                throw new RuntimeException('此操作需要交互终端，或指定 --password-file 当前用户私有文件。');
            }
            return [$args, null];
        }
        $handle = @fopen($file, 'rb');
        $stat = $handle ? fstat($handle) : false;
        $privateOk = Platform::isWindows()
            ? Platform::isPrivate($file)
            : ($stat && (($stat['mode'] & 0077) === 0) && $stat['uid'] === posix_geteuid());
        if (!$stat || is_link($file) || ($stat['mode'] & 0170000) !== 0100000 || !$privateOk
            || $stat['size'] < 1 || $stat['size'] > 1024) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('密码输入须为当前用户私有的普通文件（0600）。');
        }
        try {
            $input = stream_get_contents($handle, 1025);
        } finally {
            fclose($handle);
        }
        if ($input === false || !preg_match('/\A[^\r\n]+(?:\r?\n)?\z/D', $input)) {
            throw new RuntimeException('密码文件只应包含一行。');
        }
        return [[...$args, '--password-stdin', '--no-interaction'], rtrim($input, "\r\n")."\n"];
    }
}
