<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * 租户上下文服务
 *
 * 在请求生命周期内维护当前租户信息，供 TenantScope、中间件、
 * 控制器和后台任务使用。
 */
class TenantContext
{
    protected static ?Tenant $tenant = null;
    protected static bool $queueGuard = false;

    /**
     * 设置当前租户
     */
    public static function set(Tenant $tenant): void
    {
        static::$tenant = $tenant;
    }

    /**
     * 获取当前租户（可能为 null，如平台管理员操作时）
     */
    public static function get(): ?Tenant
    {
        return static::$tenant;
    }

    /**
     * 获取当前租户 ID（可能为 null）
     */
    public static function id(): ?int
    {
        // A queue job without a validated tenant must see no tenant-owned rows.
        // -1 is reserved as a deny-all scope value; it is never a real tenant ID.
        return static::$tenant?->id ?? (static::$queueGuard ? -1 : null);
    }

    /**
     * 判断是否已设置租户上下文
     */
    public static function has(): bool
    {
        return static::$tenant !== null;
    }

    /**
     * 清除租户上下文（请求结束时自动调用）
     */
    public static function forget(): void
    {
        static::$tenant = null;
    }

    public static function beginQueueJob(): void
    {
        static::$tenant = null;
        static::$queueGuard = true;
    }

    public static function endQueueJob(): void
    {
        static::$tenant = null;
        static::$queueGuard = false;
    }

    /**
     * 在回调内临时绕过租户作用域（平台管理员操作时使用）
     *
     * 用法：
     *   TenantContext::bypassScope(function () {
     *       return Tenant::withCount('users')->get();
     *   });
     */
    public static function bypassScope(callable $callback): mixed
    {
        $previous = static::$tenant;
        $previousGuard = static::$queueGuard;
        static::$tenant = null;
        static::$queueGuard = false;

        try {
            return $callback();
        } finally {
            static::$tenant = $previous;
            static::$queueGuard = $previousGuard;
        }
    }
}
