<?php

declare(strict_types=1);

// The shell already checked this candidate against the pinned release manifest.
[, $staging, $release] = $argv;
$lock = fopen(dirname($release).'/.publish.lock', PHP_OS_FAMILY === 'Windows' ? 'cb' : 'ce');
if (!$lock || !flock($lock, LOCK_EX)) {
    fwrite(STDERR, "无法发布已核验的程序版本，可使用原命令重试。\n");
    exit(1);
}
if (!is_dir($release)) {
    $moved = false;
    $attempts = PHP_OS_FAMILY === 'Windows' ? 20 : 1;
    for ($i = 0; $i < $attempts; $i++) {
        if (@rename($staging, $release)) {
            $moved = true;
            break;
        }
        usleep(150000);
    }
    if (!$moved) {
        fwrite(STDERR, "无法发布已核验的程序版本，可使用原命令重试。\n");
        exit(1);
    }
}
