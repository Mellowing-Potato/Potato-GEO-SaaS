<?php

declare(strict_types=1);

// The shell already checked this candidate against the pinned release manifest.
[, $staging, $release] = $argv;
error_clear_last();
$lock = @fopen(dirname($release).'/.publish.lock', PHP_OS_FAMILY === 'Windows' ? 'cb' : 'ce');
if (!$lock || !flock($lock, LOCK_EX)) {
    $detail = error_get_last()['message'] ?? '';
    fwrite(STDERR, '无法发布已核验的程序版本，可使用原命令重试。'.($detail !== '' ? " ($detail)" : '')."\n");
    exit(1);
}
if (!is_dir($release)) {
    $moved = false;
    $lastError = '';
    $attempts = (PHP_OS_FAMILY === 'Windows' || PHP_OS_FAMILY === 'Darwin') ? 20 : 1;
    $origMode = null;
    if (PHP_OS_FAMILY !== 'Windows') {
        $perms = @fileperms($staging);
        if ($perms !== false) {
            $origMode = $perms & 0777;
        }
    }
    for ($i = 0; $i < $attempts; $i++) {
        $temporarilyWritable = false;
        error_clear_last();
        if (@rename($staging, $release)) {
            $moved = true;
            break;
        }
        $lastError = error_get_last()['message'] ?? '';
        // Some Unix filesystems (notably macOS APFS/Sequoia) reject renaming a 0500 read-only directory.
        // Under LOCK_EX, relax only the top staging directory inode during the rename and immediately restore it.
        if (PHP_OS_FAMILY !== 'Windows' && $origMode !== null && ($origMode & 0200) === 0) {
            if (@chmod($staging, $origMode | 0200)) {
                $temporarilyWritable = true;
                error_clear_last();
                if (@rename($staging, $release)) {
                    error_clear_last();
                    if (!@chmod($release, $origMode)) {
                        $detail = error_get_last()['message'] ?? '';
                        fwrite(STDERR, '无法恢复已发布程序目录的只读权限，请检查目录后重试。'.($detail !== '' ? " ($detail)" : '')."\n");
                        exit(1);
                    }
                    $moved = true;
                    break;
                }
                $lastError = error_get_last()['message'] ?? '';
            }
        }
        if ($temporarilyWritable) {
            @chmod($staging, $origMode);
        }
        if ($i + 1 < $attempts) {
            usleep(150000);
        }
    }
    if (!$moved) {
        fwrite(STDERR, '无法发布已核验的程序版本，可使用原命令重试。'.($lastError !== '' ? " ($lastError)" : '')."\n");
        exit(1);
    }
}
