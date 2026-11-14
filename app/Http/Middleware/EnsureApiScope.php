<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 作用域强制中间件（复刻 GEOFlow EnsureApiScope，适配 Sanctum）。
 *
 * 挂在需要作用域 Token 鉴权的路由上：检查当前已认证用户的 Token 是否具备指定 scope。
 * Sanctum 的 `*` 通配 ability 视为拥有全部 scope（SPA 全权限 Token 不受影响）。
 *
 * 用法：->middleware('scope:articles:write')
 */
class EnsureApiScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $user = $request->user();
        if (! $user) {
            abort(401, '未认证');
        }

        if (! method_exists($user, 'tokenCan') || ! $user->tokenCan($scope)) {
            abort(403, '当前 Token 没有访问此接口的权限（所需 scope: '.$scope.'）');
        }

        return $next($request);
    }
}
