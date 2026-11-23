<?php

namespace App\Services\Backup;

use PDO;
use RuntimeException;

/** A self-contained, single-file SQLite snapshot, including all committed WAL frames. */
final class SqliteSnapshot
{
    /** @return array{bytes:int,sha256:string} */
    public static function create(string $source, string $destination, bool $withChecksum = false): array
    {
        if (! is_file($source) || is_link($source) || filesize($source) === 0) {
            throw new RuntimeException('源 SQLite 数据文件缺失、为空或为链接。');
        }
        $parent = realpath(dirname($destination));
        if ($parent === false || file_exists($destination) || is_link($destination)) {
            throw new RuntimeException('请为 SQLite 备份选择已有目录内的新文件。');
        }
        $temp = $parent.'/.sqlite-snapshot-'.bin2hex(random_bytes(12));
        $handle = fopen($temp, 'xb');
        if (! $handle) {
            throw new RuntimeException('无法创建 SQLite 临时快照。');
        }
        fclose($handle);
        try {
            if (! chmod($temp, 0600)) {
                throw new RuntimeException('无法保护 SQLite 临时快照。');
            }
            $db = self::openReadOnly($source);
            $db->exec('PRAGMA busy_timeout=5000');
            $vacuum = $db->prepare('VACUUM INTO ?');
            if (! $vacuum->execute([$temp])) {
                throw new RuntimeException('SQLite 一致快照未完成。');
            }
            unset($vacuum, $db);
            $verified = self::inspect($temp);
            $checksumPath = $destination.'.sha256';
            if ($withChecksum) {
                $text = $verified['sha256'].'  '.basename($destination)."\n";
                if (file_put_contents($temp.'.sha256', $text) !== strlen($text) || ! chmod($temp.'.sha256', 0600)
                    || ! @link($temp.'.sha256', $checksumPath)) {
                    throw new RuntimeException('无法发布 SQLite 备份校验和，请选择新文件名。');
                }
            }
            // Publish the database last, without replacing another backup.
            if (! @link($temp, $destination)) {
                if ($withChecksum) {
                    unlink($checksumPath);
                }
                throw new RuntimeException('无法发布完整的 SQLite 备份，请确认文件名未被占用。');
            }

            return $verified;
        } finally {
            unlink($temp);
            if (is_file($temp.'.sha256')) {
                unlink($temp.'.sha256');
            }
        }
    }

    /** @return array{bytes:int,sha256:string} */
    public static function inspect(string $path): array
    {
        clearstatcache(true, $path);
        $bytes = filesize($path);
        if ($bytes === false || $bytes < 100 || file_get_contents($path, false, null, 0, 16) !== "SQLite format 3\0") {
            throw new RuntimeException('SQLite 快照文件头或大小无效。');
        }
        $db = self::openReadOnly($path);
        if ($db->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN) !== ['ok']) {
            throw new RuntimeException('SQLite 快照未通过 integrity_check。');
        }
        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            throw new RuntimeException('无法计算 SQLite 快照摘要。');
        }

        return ['bytes' => $bytes, 'sha256' => $hash];
    }

    private static function openReadOnly(string $path): PDO
    {
        $flags = PHP_VERSION_ID >= 80400 ? \Pdo\Sqlite::ATTR_OPEN_FLAGS : PDO::SQLITE_ATTR_OPEN_FLAGS;
        $readOnly = PHP_VERSION_ID >= 80400 ? \Pdo\Sqlite::OPEN_READONLY : PDO::SQLITE_OPEN_READONLY;

        return new PDO('sqlite:'.$path, options: [$flags => $readOnly, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}
