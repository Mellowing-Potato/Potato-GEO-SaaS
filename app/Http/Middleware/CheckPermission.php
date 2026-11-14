<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 权限校验中间件
 *
 * 用法：
 *   Route::middleware('can:project:create')     — 单个权限
 *   Route::middleware('can:article:create,article:edit') — 需要同时满足多个权限
 *
 * 原理：
 *   从当前用户 ->role 查 Permissions 矩阵，不通过则 403。
 *   注意：这会覆盖 Laravel 默认的 Gate-based can()，因为我们用的是自建 RBAC。
 */
class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  string  ...$permissions  一个或多个权限 key
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => '未登录'], 401);
        }

        // 逐项检查，全部满足才放行
        foreach ($permissions as $permission) {
            if (! $user->hasPermission($permission)) {
                return response()->json([
                    'message' => '权限不足，需要权限: ' . $permission,
                    'required_permission' => $permission,
                    'your_role' => $user->role,
                ], 403);
            }
        }

        return $next($request);
    }
}
