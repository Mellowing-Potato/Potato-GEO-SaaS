<?php

namespace App\Support;

use RuntimeException;

final class InstanceEnvironment
{
    /** The callback receives the existing file, or null. Return null to leave it untouched. */
    public static function update(string $path, callable $change): void
    {
        $lockPath = dirname($path).'/.env.write.lock';
        if (is_link($lockPath)) {
            throw new RuntimeException('实例配置锁无效。');
        }
        $mask = umask(0077);
        try {
            $lock = @fopen($lockPath, 'c+b');
        } finally {
            umask($mask);
        }
        if (! $lock) {
            throw new RuntimeException('无法打开实例配置锁。');
        }
        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException('无法锁定实例配置。');
            }
            clearstatcache(true, $path);
            if (is_link($path) || (file_exists($path) && ! is_file($path))) {
                throw new RuntimeException('实例配置必须是普通文件。');
            }
            $current = is_file($path) ? file_get_contents($path) : null;
            if ($current === false) {
                throw new RuntimeException('无法读取实例配置。');
            }
            $next = $change($current);
            if ($next === null || $current === $next) {
                return;
            }
            $temp = dirname($path).'/.env-edit-'.bin2hex(random_bytes(16));
            $mask = umask(0077);
            try {
                $file = @fopen($temp, 'x+b');
            } finally {
                umask($mask);
            }
            if (! $file) {
                throw new RuntimeException('无法创建实例配置临时文件。');
            }
            try {
                try {
                    if (fwrite($file, $next) !== strlen($next) || ! fflush($file) || ! fsync($file)) {
                        throw new RuntimeException('无法保存实例配置。');
                    }
                } finally {
                    fclose($file);
                }
                if (! rename($temp, $path)) {
                    throw new RuntimeException('无法原子更新实例配置。');
                }
            } finally {
                if (file_exists($temp)) {
                    unlink($temp);
                }
            }
        } finally {
            fclose($lock);
        }
    }
}
