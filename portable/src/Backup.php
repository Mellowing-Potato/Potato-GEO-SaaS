<?php

declare(strict_types=1);

namespace Yanji\Portable;

use App\Services\Backup\SqliteSnapshot;
use FilesystemIterator;
use RuntimeException;
use ZipArchive;

require_once __DIR__.'/../../app/Services/Backup/SqliteSnapshot.php';

/** Manage a stopped instance under the caller's operation lock. */
final class Backup
{
    private const DATABASE = 'database/database.sqlite';

    public static function create(Instance $instance, string $output): void
    {
        $parent = realpath(dirname($output));
        if ($parent === false || $parent === $instance->root || str_starts_with($parent.'/', $instance->root.'/')
            || file_exists($output) || is_link($output)) {
            throw new RuntimeException('请在实例外的已有目录选择新的备份文件。');
        }
        Instance::assertOutsideManagedTrees($parent.'/'.basename($output));
        $work = self::workspace($parent);
        try {
            $probe = $work.'/binding';
            Instance::privateDir($probe.'/database');
            $snapshot = $probe.'/'.self::DATABASE;
            if (is_link($instance->root.'/database') || is_link($instance->root.'/storage')) {
                throw new RuntimeException('数据目录不能指向实例外。');
            }
            $env = $instance->root.'/.env';
            if (! is_file($env) || is_link($env) || ($contents = file_get_contents($env)) === false) {
                throw new RuntimeException('实例缺少与数据库配套的原环境文件。');
            }
            Instance::atomicFile($probe.'/.env', $contents);
            SqliteSnapshot::create($instance->root.'/'.self::DATABASE, $snapshot);
            $release = $instance->release();
            $port = $instance->port();
            self::verifyBinding($probe, $release, $port);
            $zip = new ZipArchive;
            $archive = $work.'/instance.zip';
            if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new RuntimeException('不能创建备份归档。');
            }
            $manifest = ['schema' => 1, 'release' => basename($release), 'port' => $port,
                'created_at' => gmdate(DATE_ATOM), 'files' => [], 'directories' => []];
            try {
                self::addFile($zip, $manifest, '.env', $probe.'/.env');
                self::addFile($zip, $manifest, self::DATABASE, $snapshot);
                self::addStorage($zip, $manifest, $instance->root, 'storage/app');
                if (! $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
                    || ! $zip->setExternalAttributesName('manifest.json', ZipArchive::OPSYS_UNIX, 0100600 << 16)) {
                    throw new RuntimeException('不能保存备份清单。');
                }
            } finally {
                if (! $zip->close()) {
                    throw new RuntimeException('归档写入不完整。');
                }
            }
            self::readArchive($archive);
            if (! chmod($archive, 0600) || ! @link($archive, $output)) {
                throw new RuntimeException('不能发布备份，请确认目标文件名未被占用。');
            }
        } finally {
            self::removeWorkspace($work);
        }
    }

    public static function restore(Instance $target, string $archive, string $release, int $port): void
    {
        self::publishToEmpty($archive, $target->root,
            static fn (string $data) => self::verifyBinding($data, $release, $port), expectedRelease: basename($release));
    }

    private static function verifyBinding(string $data, string $release, int $port): void
    {
        $probe = new Instance($data);
        (new Installation($probe))->configure($release, $port);
        if ($probe->artisan(['yanji:ready', '--portable'], quiet: true) !== 0) {
            throw new RuntimeException('备份中的环境密钥、数据库或管理员未通过就绪验证。请核对配套的原始数据。');
        }
    }

    public static function extractToEmpty(string $archive, string $target): void
    {
        self::publishToEmpty($archive, $target);
    }

    private static function publishToEmpty(string $archive, string $target, ?callable $check = null, ?string $expectedRelease = null): void
    {
        Instance::assertOutsideManagedTrees($target);
        if (is_link($target) || file_exists($target) && (! is_dir($target) || count(scandir($target)) !== 2)) {
            throw new RuntimeException('恢复目标必须是新的空目录，请另选 --instance。');
        }
        $parent = dirname($target);
        $ancestor = $parent;
        while (! is_dir($ancestor)) {
            if (file_exists($ancestor) || is_link($ancestor) || dirname($ancestor) === $ancestor) {
                throw new RuntimeException('恢复目录的上级路径无效。');
            }
            $ancestor = dirname($ancestor);
        }
        $work = self::workspace($ancestor);
        try {
            $data = $work.'/inspection';
            Instance::privateDir($data);
            self::readArchive($archive, $data, $expectedRelease);
            SqliteSnapshot::inspect($data.'/'.self::DATABASE);
            if ($check !== null) {
                $check($data);
            }
            // Only persistent files cross this boundary. Probe-generated caches contain the staging path.
            $ready = $work.'/verified';
            Instance::privateDir($ready.'/storage');
            foreach (['.env', 'database', 'storage/app'] as $name) {
                if (! rename($data.'/'.$name, $ready.'/'.$name)) {
                    throw new RuntimeException('备份验证后未能准备完整数据。');
                }
            }
            if (! is_dir($parent) && ! @mkdir($parent, 0700, true) && ! is_dir($parent)) {
                throw new RuntimeException('不能创建恢复目录，请检查上级路径和权限。');
            }
            if (! @rename($ready, $target)) {
                throw new RuntimeException('目标已被占用或不可写，未发布恢复数据。');
            }
        } finally {
            self::removeWorkspace($work);
        }
    }

    private static function addStorage(ZipArchive $zip, array &$manifest, string $root, string $name): void
    {
        $dir = $root.'/'.$name;
        if (! is_dir($dir) || is_link($dir) || ! self::storageName($name)) {
            throw new RuntimeException('备份中的存储目录无效或含链接。');
        }
        $manifest['directories'][] = $name;
        foreach (new FilesystemIterator($dir) as $file) {
            $entry = $name.'/'.$file->getFilename();
            if (in_array($entry, ['storage/app/temp', 'storage/app/yanji-install.lock'], true)) {
                continue;
            }
            if ($file->isLink() || ! self::storageName($entry)) {
                throw new RuntimeException('存储数据包含不可安全归档的路径或链接。');
            }
            if ($file->isDir()) {
                self::addStorage($zip, $manifest, $root, $entry);
            } else {
                self::addFile($zip, $manifest, $entry, $file->getPathname());
            }
        }
    }

    private static function addFile(ZipArchive $zip, array &$manifest, string $name, string $source): void
    {
        if (! is_file($source) || is_link($source)) {
            throw new RuntimeException('实例所需的普通文件缺失或为链接。');
        }
        $bytes = filesize($source);
        $sha = hash_file('sha256', $source);
        if ($bytes === false || $sha === false || ! $zip->addFile($source, $name)
            || ! $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100600 << 16)) {
            throw new RuntimeException('无法读取完整的实例文件。');
        }
        $manifest['files'][$name] = ['bytes' => $bytes, 'sha256' => $sha];
    }

    /** Validate each archive member even when merely verifying a newly created backup. */
    private static function readArchive(string $archive, ?string $target = null, ?string $expectedRelease = null): void
    {
        $zip = new ZipArchive;
        if (! is_file($archive) || is_link($archive) || $zip->open($archive, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('无法读取完整的实例备份。');
        }
        try {
            $header = $zip->statName('manifest.json');
            if (! $header || $header['size'] < 2 || $header['size'] > 64 * 1024 * 1024) {
                throw new RuntimeException('备份清单缺失或超出可校验大小。');
            }
            $manifest = json_decode($zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
            $files = $manifest['files'] ?? null;
            $dirs = $manifest['directories'] ?? null;
            if (($manifest['schema'] ?? null) !== 1 || ! is_string($manifest['release'] ?? null)
                || ! is_array($files) || ! is_array($dirs) || ! array_is_list($dirs)
                || ! in_array('storage/app', $dirs, true) || ! isset($files['.env'], $files[self::DATABASE])
                || $zip->numFiles !== count($files) + 1) {
                throw new RuntimeException('恢复所需的程序版本、文件或清单条目不完整。');
            }
            if ($expectedRelease !== null && ! hash_equals($expectedRelease, $manifest['release'])) {
                throw new RuntimeException('备份不匹配当前程序版本。请用备份清单 release 对应的已保留程序恢复，再运行 yanji upgrade。');
            }
            foreach ($dirs as $dir) {
                if (! is_string($dir) || ! self::storageName($dir) || isset($files[$dir])) {
                    throw new RuntimeException('备份目录条目无效。');
                }
                if ($target !== null) {
                    Instance::privateDir($target.'/'.$dir);
                }
            }
            $seen = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = $stat['name'] ?? '';
                $os = $attributes = 0;
                if (isset($seen[$name]) || ! $zip->getExternalAttributesIndex($index, $os, $attributes)
                    || $os === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) !== 0100000) {
                    throw new RuntimeException('归档包含重名文件或特殊类型。');
                }
                $seen[$name] = true;
                if ($name === 'manifest.json') {
                    continue;
                }
                $record = $files[$name] ?? [];
                if (! in_array($name, ['.env', self::DATABASE], true) && ! self::storageName($name)
                    || ! is_int($record['bytes'] ?? null) || $record['bytes'] < 0 || $record['bytes'] !== $stat['size']
                    || ! is_string($record['sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $record['sha256'])) {
                    throw new RuntimeException('归档与文件清单不一致。');
                }
                $output = $target === null ? null : $target.'/'.$name;
                if ($output !== null) {
                    Instance::privateDir(dirname($output));
                }
                self::verifyStream($zip, $index, $record, $output);
            }
        } finally {
            $zip->close();
        }
    }

    private static function verifyStream(ZipArchive $zip, int $index, array $record, ?string $output): void
    {
        $input = $zip->getStreamIndex($index);
        if (! $input) {
            throw new RuntimeException('无法打开归档中的文件。');
        }
        $out = null;
        try {
            if ($output !== null && (! ($out = @fopen($output, 'xb')) || ! chmod($output, 0600))) {
                throw new RuntimeException('不能安全创建恢复文件。');
            }
            $hash = hash_init('sha256');
            $bytes = 0;
            while (! feof($input)) {
                $chunk = fread($input, 1024 * 1024);
                if ($chunk === false || $chunk === '' && ! feof($input)) {
                    throw new RuntimeException('归档文件读取不完整。');
                }
                $bytes += strlen($chunk);
                if ($bytes > $record['bytes'] || $out && fwrite($out, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('归档文件长度或写入不完整。');
                }
                hash_update($hash, $chunk);
            }
            if ($bytes !== $record['bytes'] || ! hash_equals($record['sha256'], hash_final($hash)) || $out && ! fflush($out)) {
                throw new RuntimeException('归档文件长度或 SHA-256 校验失败。');
            }
        } finally {
            fclose($input);
            if (is_resource($out)) {
                fclose($out);
            }
        }
    }

    private static function storageName(string $name): bool
    {
        if ($name !== 'storage/app' && ! str_starts_with($name, 'storage/app/')) {
            return false;
        }
        foreach (explode('/', $name) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || str_contains($part, '\\') || preg_match('/[\x00-\x1f\x7f]/', $part)) {
                return false;
            }
        }

        return true;
    }

    private static function workspace(string $parent): string
    {
        $path = $parent.'/.yanji-backup-'.bin2hex(random_bytes(12));
        if (! mkdir($path, 0700)) {
            throw new RuntimeException('不能创建本次备份专用临时目录。');
        }

        return $path;
    }

    private static function removeWorkspace(string $dir): void
    {
        foreach (new FilesystemIterator($dir) as $file) {
            if (! $file->isLink() && $file->isDir()) {
                self::removeWorkspace($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($dir);
    }
}
