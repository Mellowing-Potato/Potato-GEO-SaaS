<?php

namespace App\Support;

use InvalidArgumentException;

final class PublicAssetVersion
{
    /** Called only with fixed public JS/CSS basenames from application views. */
    public static function for(string $filename): string
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\.(?:css|js)\z/D', $filename) !== 1) {
            throw new InvalidArgumentException('公共资源名称无效。');
        }
        $path = public_path($filename);
        $hash = is_file($path) ? @hash_file('sha256', $path) : false;

        // Portable archives normalize timestamps. Read current bytes rather than cache by mtime or size.
        return $hash === false ? (string) time() : $hash;
    }
}
