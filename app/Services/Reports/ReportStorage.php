<?php

namespace App\Services\Reports;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/** Private reports take precedence; historical paths remain readable through authenticated controllers. */
final class ReportStorage
{
    public static function belongsToExecution(int $executionId, string $path): bool
    {
        $prefix = "executions/{$executionId}/";
        if ($executionId <= 0 || strlen($path) > 2048 || ! str_starts_with($path, $prefix)
            || str_contains($path, '\\') || preg_match('/[\x00-\x1F\x7F]/', $path)) {
            return false;
        }
        foreach (explode('/', substr($path, strlen($prefix))) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    public static function readDisk(string $path): FilesystemAdapter
    {
        $private = Storage::disk('reports');

        return $private->exists($path) ? $private : Storage::disk('public');
    }
}
