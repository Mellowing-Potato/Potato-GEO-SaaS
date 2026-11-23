<?php

declare(strict_types=1);

namespace Yanji\Portable;

use RuntimeException;

final class Installation
{
    public function __construct(private Instance $instance) {}

    public function configure(string $release, int $port): void
    {
        $i = $this->instance;
        $i->prepare();
        if (!is_file($i->root.'/instance.json')) {
            Instance::atomicFile($i->root.'/instance.json', json_encode(['port' => $port], JSON_THROW_ON_ERROR)."\n");
        } elseif ($i->port() !== $port) {
            throw new RuntimeException('实例端口与原配置不同。恢复至新目录时可另选端口。');
        }
        $i->activate($release);
        Instance::atomicFile($i->root.'/yanji', file_get_contents($release.'/app/portable/yanji'), 0700);
        $ca = $release.'/runtime/ca/cacert.pem';
        if (!is_file($ca) || str_contains($ca, '"')) {
            throw new RuntimeException('程序缺少可用的 CA 文件。');
        }
        $ini = "upload_max_filesize=10M\npost_max_size=20M\nmemory_limit=512M\ndisplay_errors=Off\n"
            ."log_errors=On\nexpose_php=Off\nvariables_order=EGPCS\n"
            .'curl.cainfo="'.$ca."\"\n".'openssl.cafile="'.$ca."\"\n";
        Instance::atomicFile($i->root.'/php.ini', $ini);
    }

    public function install(string $release, int $port, ?string $email, ?string $passwordFile, ?string $python): void
    {
        $i = $this->instance;
        foreach (['.env','database','database/database.sqlite','instance.json', 'php.ini'] as $name) {
            $path = $i->root.'/'.$name;
            if (is_link($path)) {
                throw new RuntimeException('实例内的敏感路径指向外部文件，拒绝初始化。');
            }
        }
        $db = $i->root.'/database/database.sqlite';
        if (!is_file($i->root.'/.env') && is_file($db) && filesize($db) > 0) {
            throw new RuntimeException('现有数据库缺少原环境文件，请恢复配套备份。');
        }
        if (is_link($i->root.'/current') && $i->release() !== $release) {
            throw new RuntimeException('实例已有其他程序版本，请使用 yanji upgrade。');
        }
        $this->configure($release, $port);
        $this->configurePython($python);
        if (!is_file($i->root.'/.env')) {
            $template = file_get_contents($release.'/app/portable/instance.env.example');
            if ($template === false || !str_contains($template, 'DB_DATABASE=')) {
                throw new RuntimeException('程序缺少实例环境模板。');
            }
            // The explicit environment resolves relocated paths for restored instances.
            $quoted = "'".str_replace(["\\","'"], ["\\\\","\\'"], $db)."'";
            $env = preg_replace_callback('/^DB_DATABASE=.*$/m', static fn () => 'DB_DATABASE='.$quoted, $template);
            $env = preg_replace('/^SESSION_COOKIE=.*$/m', 'SESSION_COOKIE=yanji_'.substr(hash('sha256', $i->root), 0, 24), $env);
            $env = preg_replace('/^APP_URL=.*$/m', 'APP_URL=http://127.0.0.1:'.$port, $env);
            Instance::atomicFile($i->root.'/.env', $env);
        }
        foreach (['.env','database','storage','logs','bootstrap-cache'] as $name) {
            $path = $i->root.'/'.$name;
            if (is_dir($path)) {
                $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST);
                foreach ($files as $file) {
                    $this->securePath($file->getPathname());
                }
            }
            $this->securePath($path);
        }
        if ($i->artisan(['yanji:ready', '--portable', '--quiet'], quiet: true) === 0) {
            return;
        }
        $args = ['yanji:install','--portable'];
        if ($email !== null) {
            $args[] = '--email='.$email;
        }
        [$args, $input] = self::passwordInput($args, $passwordFile);
        if ($i->artisan($args, $input) !== 0 || $i->artisan(['yanji:ready','--portable'], quiet: true) !== 0) {
            throw new RuntimeException('初始化未完成。请使用原实例目录重试或运行 yanji doctor。');
        }
    }

    private function securePath(string $path): void
    {
        if (is_link($path) || (!is_file($path) && !is_dir($path)) || fileowner($path) !== posix_geteuid()
            || !chmod($path, is_dir($path) ? 0700 : 0600)) {
            throw new RuntimeException('实例含无法保护的文件或链接，请核对所有者和备份。');
        }
    }

    public function configurePython(?string $python): void
    {
        $i = $this->instance;
        if ($python !== null) {
            if (!str_starts_with($python, '/') || !is_executable($python) || strpbrk($python, "\r\n\0") !== false) {
                throw new RuntimeException('请指定本机 Python 解释器的绝对路径。');
            }
            Instance::atomicFile($i->root.'/python-path', $python."\n");
        }
        if (!self::pythonStatus($i)['basic']) {
            throw new RuntimeException('基础报告依赖缺失。请用 --python-bin 指定有 python-docx、Markdown、beautifulsoup4 的 Python。');
        }
    }

    public static function pythonStatus(Instance $i): array
    {
        $env = $i->environment();
        $bin = $env['PYTHON_BIN'];
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
        $process = @proc_open([$bin, '-I', '-c', $script],
            [0=>['file','/dev/null','r'], 1=>['pipe','w'], 2=>['file','/dev/null','w']], $pipes, null, $env);
        $data = null;
        if (is_resource($process)) {
            $text = stream_get_contents($pipes[1], 4096);
            fclose($pipes[1]);
            if (proc_close($process) === 0) {
                $data = json_decode($text, true);
            }
        }
        return ['interpreter'=>$bin] + (is_array($data) ? $data : ['basic'=>false]);
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
        if (!$stat || is_link($file) || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0077)
            || $stat['uid'] !== posix_geteuid() || $stat['size'] < 1 || $stat['size'] > 1024) {
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
