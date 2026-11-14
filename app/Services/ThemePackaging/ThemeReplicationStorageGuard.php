<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted theme generation, packaging and storage for independent workspaces.
 * Upstream: app/Services/Admin/SiteThemeReplication/ThemeReplicationStorageGuard.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Services\ThemePackaging;

use FilesystemIterator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * 存储写入守卫 —— 原样复刻自上游 GEOFlow v2.3.0（277 行，一字未省）
 *
 * ⚠️ 这是安全代码，判断逻辑一行都没动。只做了三处 geo-saas 适配：
 *   1. namespace 换成 App\Services\ThemePackaging
 *   2. reject 消息从 __('theme_package.*') 换成字面量常量（geo-saas 无 lang 目录）
 *   3. 类从 final 改成普通 class —— geo-saas 的测试要能对它做变异验证与桩替换
 *
 * 它做的事：
 *   - 每一级目录都 realpath() 校验，符号链接直接拒
 *   - 写文件走「临时文件 + rename」原子替换，'xb' 独占创建 + fsync + 复查 mode/size
 *   - 删目录递归逐项校验，每一项都必须仍在 root 内
 *
 * 关于租户：本类的 root 仍是「local 盘根目录」，没有改成租户目录。
 * 租户隔离靠「相对路径里插一层 {tenant_id}」+「端点先校验记录归属」两件事完成，
 * 而不是靠改这段安全代码 —— 改它只会增加出事的概率。
 */
class ThemeReplicationStorageGuard
{
    public function __construct(private readonly ThemeReplicationPackagePathGuard $pathGuard) {}

    public function positiveInteger(mixed $value): int
    {
        return $this->pathGuard->positiveInteger($value);
    }

    public function ensureStorageDirectory(string $relativePath): string
    {
        return $this->ensureDirectoryWithinRoot(
            $this->storageRoot(),
            $relativePath,
        );
    }

    public function writeStorageFile(string $relativePath, string $contents): void
    {
        $this->assertSafeRelativePath($relativePath);
        $directory = trim(str_replace('\\', '/', dirname($relativePath)), '/.');
        $directoryReal = $this->ensureStorageDirectory($directory);
        $target = Storage::disk('local')->path($relativePath);
        $temporary = $directoryReal.DIRECTORY_SEPARATOR.'.'.basename($relativePath).'.'.Str::random(20).'.tmp';

        if (@lstat($target) !== false && (is_link($target) || is_dir($target))) {
            $this->reject();
        }

        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            $this->reject();
        }

        $published = false;
        try {
            try {
                $remaining = $contents;
                while ($remaining !== '') {
                    $written = fwrite($handle, $remaining);
                    if (! is_int($written) || $written < 1) {
                        $this->reject();
                    }
                    $remaining = substr($remaining, $written);
                }
                if (! fflush($handle)) {
                    $this->reject();
                }
                if (function_exists('fsync') && ! fsync($handle)) {
                    $this->reject();
                }
                $stat = fstat($handle);
                if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] !== strlen($contents)) {
                    $this->reject();
                }
            } finally {
                fclose($handle);
            }

            $this->assertDirectoryWithinRoot(
                $this->storageRoot(),
                $directory,
            );
            if (@lstat($target) !== false && (is_link($target) || is_dir($target))) {
                $this->reject();
            }
            if (! @rename($temporary, $target)) {
                $this->reject();
            }
            $published = true;
            $real = realpath($target);
            if (! is_string($real) || ! $this->isWithinDirectory($real, $directoryReal)) {
                $this->reject();
            }
        } catch (Throwable $exception) {
            @unlink($temporary);
            if ($published) {
                @unlink($target);
            }
            throw $exception;
        }
    }

    public function deleteStorageFile(string $relativePath): void
    {
        $this->assertSafeRelativePath($relativePath);
        $directory = trim(str_replace('\\', '/', dirname($relativePath)), '/.');
        $directoryReal = $this->assertDirectoryWithinRoot(
            $this->storageRoot(),
            $directory,
        );
        $absolutePath = Storage::disk('local')->path($relativePath);
        $stat = @lstat($absolutePath);
        if ($stat === false) {
            return;
        }
        if (is_link($absolutePath) || ($stat['mode'] & 0170000) !== 0100000) {
            $this->reject();
        }
        $real = realpath($absolutePath);
        if (! is_string($real) || ! $this->isWithinDirectory($real, $directoryReal) || ! @unlink($absolutePath)) {
            $this->reject();
        }
    }

    public function deleteStorageDirectory(string $relativePath): void
    {
        $this->deleteDirectoryWithinRoot(
            $this->storageRoot(),
            $relativePath,
        );
    }

    public function deleteFrameworkDirectory(string $relativePath): void
    {
        $this->deleteDirectoryWithinRoot(
            rtrim(storage_path('framework'), DIRECTORY_SEPARATOR),
            $relativePath,
        );
    }

    /**
     * 统计某个目录在磁盘上实际占多少字节（配额与磁盘影响说明都要用它）。
     *
     * 这是 geo-saas 新增的：上游是单租户后台，不需要按租户算账。
     * 走同样的守卫逻辑，遇到符号链接不跟随、直接跳过（不计数）。
     */
    public function directoryBytes(string $relativePath): int
    {
        $this->assertSafeRelativePath($relativePath);
        $rootAbsolute = $this->storageRoot();
        $absolutePath = $rootAbsolute.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (@lstat($absolutePath) === false) {
            return 0;
        }
        $directoryReal = $this->assertDirectoryWithinRoot($rootAbsolute, $relativePath);

        $total = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directoryReal, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            $itemPath = $item->getPathname();
            if (! str_starts_with($itemPath, $directoryReal.DIRECTORY_SEPARATOR)) {
                $this->reject();
            }
            if (is_link($itemPath) || ! $item->isFile()) {
                continue;
            }
            $stat = @stat($itemPath);
            if (is_array($stat)) {
                $total += (int) $stat['size'];
            }
        }

        return $total;
    }

    private function storageRoot(): string
    {
        return rtrim(Storage::disk('local')->path(''), DIRECTORY_SEPARATOR);
    }

    private function ensureDirectoryWithinRoot(string $rootAbsolute, string $relativePath): string
    {
        $segments = $this->segments($relativePath);
        $rootReal = $this->canonicalDirectory($rootAbsolute);
        $current = $rootAbsolute;

        foreach ($segments as $segment) {
            $current .= DIRECTORY_SEPARATOR.$segment;
            if (@lstat($current) === false && ! @mkdir($current, 0775)) {
                $this->reject();
            }
            $this->canonicalDirectory($current, $rootReal);
        }

        return $segments === [] ? $rootReal : $this->canonicalDirectory($current, $rootReal);
    }

    private function assertDirectoryWithinRoot(string $rootAbsolute, string $relativePath): string
    {
        $segments = $this->segments($relativePath);
        $rootReal = $this->canonicalDirectory($rootAbsolute);
        $current = $rootAbsolute;

        foreach ($segments as $segment) {
            $current .= DIRECTORY_SEPARATOR.$segment;
            $this->canonicalDirectory($current, $rootReal);
        }

        return $segments === [] ? $rootReal : $this->canonicalDirectory($current, $rootReal);
    }

    private function deleteDirectoryWithinRoot(string $rootAbsolute, string $relativePath): void
    {
        $this->assertSafeRelativePath($relativePath);
        $absolutePath = $rootAbsolute.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $this->assertExistingComponentsWithinRoot($rootAbsolute, $relativePath);
        if (@lstat($absolutePath) === false) {
            return;
        }
        $directoryReal = $this->assertDirectoryWithinRoot($rootAbsolute, $relativePath);

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directoryReal, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $itemPath = $item->getPathname();
                $stat = @lstat($itemPath);
                if ($stat === false || ! str_starts_with($itemPath, $directoryReal.DIRECTORY_SEPARATOR)) {
                    $this->reject();
                }
                if (is_link($itemPath)) {
                    if (! @unlink($itemPath)) {
                        $this->reject();
                    }

                    continue;
                }
                if ($item->isDir()) {
                    if (! @rmdir($itemPath)) {
                        $this->reject();
                    }
                } elseif ($item->isFile()) {
                    if (! @unlink($itemPath)) {
                        $this->reject();
                    }
                } else {
                    $this->reject();
                }
            }
            if (! @rmdir($directoryReal)) {
                $this->reject();
            }
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->reject();
        }
    }

    private function assertExistingComponentsWithinRoot(string $rootAbsolute, string $relativePath): void
    {
        $rootReal = $this->canonicalDirectory($rootAbsolute);
        $current = $rootAbsolute;
        foreach ($this->segments($relativePath) as $segment) {
            $current .= DIRECTORY_SEPARATOR.$segment;
            if (@lstat($current) === false) {
                return;
            }
            $this->canonicalDirectory($current, $rootReal);
        }
    }

    /** @return list<string> */
    private function segments(string $relativePath): array
    {
        $this->assertSafeRelativePath($relativePath);

        return $relativePath === '' ? [] : explode('/', $relativePath);
    }

    private function assertSafeRelativePath(string $relativePath): void
    {
        if (
            $relativePath === ''
            || $relativePath !== trim($relativePath, '/')
            || str_contains($relativePath, '\\')
            || str_contains($relativePath, '//')
            || preg_match('/[\x00-\x1F\x7F]/', $relativePath) === 1
        ) {
            $this->reject();
        }
        foreach (explode('/', $relativePath) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                $this->reject();
            }
        }
    }

    private function canonicalDirectory(string $absolutePath, ?string $allowedRoot = null): string
    {
        $stat = @lstat($absolutePath);
        if ($stat === false || is_link($absolutePath) || ($stat['mode'] & 0170000) !== 0040000) {
            $this->reject();
        }
        $real = realpath($absolutePath);
        if (! is_string($real) || ($allowedRoot !== null && ! $this->isWithinDirectory($real, $allowedRoot))) {
            $this->reject();
        }

        return $real;
    }

    private function isWithinDirectory(string $path, string $directory): bool
    {
        return $path === $directory || str_starts_with($path, rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }

    private function reject(): never
    {
        throw new RuntimeException(ThemeReplicationPackagePathGuard::INVALID_PATH_MESSAGE);
    }
}
