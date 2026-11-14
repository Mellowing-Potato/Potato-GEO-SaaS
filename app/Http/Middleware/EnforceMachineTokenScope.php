<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/** Machine credentials require an explicit route scope in addition to user permissions. */
class EnforceMachineTokenScope
{
    public function handle(Request $request, Closure $next)
    {
        $bearer = $request->bearerToken();
        $token = $bearer === null ? null : PersonalAccessToken::findToken($bearer);
        if ($token === null || $token->can('*')) {
            return $next($request);
        }

        \App\Services\Auth\LocalAccounts::assertActive($token->tokenable);

        // Identity inspection and self-revocation do not grant business or credential access.
        $route = $request->route();
        if (in_array($route?->uri(), ['api/v1/auth/me', 'api/v1/me', 'api/v1/me/permissions', 'api/v1/auth/logout'], true)) {
            return $next($request);
        }

        $scopes = [];
        foreach ($route?->gatherMiddleware() ?? [] as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'scope:')) {
                $scopes[] = substr($middleware, 6);
            }
        }
        if ($scopes === []) {
            abort(403, '此接口仅支持登录会话，不对机器 API Token 开放。');
        }
        foreach ($scopes as $scope) {
            if (! $token->can($scope)) {
                abort(403, '当前 Token 没有访问此接口的权限（所需 scope: '.$scope.'）');
            }
        }

        return $next($request);
    }
}
