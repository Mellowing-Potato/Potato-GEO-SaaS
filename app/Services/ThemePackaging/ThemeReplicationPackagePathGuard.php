<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted theme generation, packaging and storage for independent workspaces.
 * Upstream: app/Services/Admin/SiteThemeReplication/ThemeReplicationPackagePathGuard.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Services\ThemePackaging;

use RuntimeException;

/**
 * 路径词法守卫 —— 原样复刻自上游 GEOFlow v2.3.0（80 行，一字未省）
 *
 * ⚠️ 这是安全代码。判断逻辑一行都没动，只做了两处 geo-saas 适配：
 *   1. namespace 换成 App\Services\ThemePackaging
 *   2. reject 消息从 __('theme_package.*') 换成本类的字面量常量
 *      —— geo-saas 没有 lang 目录，__() 找不到 key 会直接把 key 字符串吐给用户。
 *
 * 它防的是：`../` 逃逸、绝对路径、盘符（`C:`）、控制字符、非 ASCII 字节。
 * 别"简化"这段，看不懂也照抄。
 */
class ThemeReplicationPackagePathGuard
{
    public const INVALID_PATH_MESSAGE = '主题包路径不合法：只允许存储根目录下的相对路径。';

    public const INVALID_THEME_ID_MESSAGE = '主题标识不合法：只允许字母、数字、下划线和连字符，长度 3~80。';

    public function positiveInteger(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/\A[1-9][0-9]*\z/D', $value)) {
            $integer = filter_var($value, FILTER_VALIDATE_INT, [
                'options' => [
                    'min_range' => 1,
                    'max_range' => PHP_INT_MAX,
                ],
            ]);

            if (is_int($integer) && (string) $integer === $value) {
                return $integer;
            }
        }

        $this->reject();
    }

    public function validatedThemeId(string $themeId): string
    {
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{1,78}[A-Za-z0-9]\z/D', $themeId)) {
            throw new RuntimeException(self::INVALID_THEME_ID_MESSAGE);
        }

        return $themeId;
    }

    public function assertSafeRelativePath(string $path): void
    {
        if (
            $path === ''
            || str_contains($path, '\\')
            || str_starts_with($path, '/')
            || preg_match('/\A[A-Za-z]:/', $path)
            || preg_match('/[\x00-\x1F\x7F]/', $path)
            || preg_match('/[^\x20-\x7E]/', $path)
        ) {
            $this->reject();
        }

        foreach (explode('/', $path) as $segment) {
            if (
                $segment === ''
                || $segment === '.'
                || $segment === '..'
                || preg_match('/\A[A-Za-z]:/', $segment)
            ) {
                $this->reject();
            }
        }
    }

    public function assertSafeArchiveEntry(string $entry, string $allowedPrefix): void
    {
        $this->assertSafeRelativePath($entry);

        $prefix = rtrim($allowedPrefix, '/').'/';
        $this->assertSafeRelativePath(rtrim($prefix, '/'));
        if ($entry === $prefix || ! str_starts_with($entry, $prefix)) {
            $this->reject();
        }
    }

    /**
     * 租户目录段：只可能是数字租户 id。
     * 这一层是 geo-saas 新增的（上游是单租户后台，没有这个概念）。
     */
    public function validatedTenantId(mixed $value): int
    {
        return $this->positiveInteger($value);
    }

    private function reject(): never
    {
        throw new RuntimeException(self::INVALID_PATH_MESSAGE);
    }
}
