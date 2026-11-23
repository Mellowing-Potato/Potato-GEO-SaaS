<?php

declare(strict_types=1);

// The shell already checked this candidate against the pinned release manifest.
[, $staging, $release] = $argv;
$lock = fopen(dirname($release).'/.publish.lock', 'ce');
if (!$lock || !flock($lock, LOCK_EX) || (!is_dir($release) && !rename($staging, $release))) {
    fwrite(STDERR, "无法发布已核验的程序版本，可使用原命令重试。\n");
    exit(1);
}
