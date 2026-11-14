<?php

namespace App\Providers;

use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureApiIdempotency;
use App\Http\Middleware\EnsureApiScope;
use App\Http\Middleware\IdentifyTenant;
use App\Http\Middleware\LogAdminActivity;
use Illuminate\Support\ServiceProvider;

class TenantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // 注册中间件别名，方便路由使用 ->middleware('tenant') / ->middleware('can:xxx')
        $router = $this->app['router'];
        $router->aliasMiddleware('tenant', IdentifyTenant::class);
        $router->aliasMiddleware('can', CheckPermission::class);
        $router->aliasMiddleware('log-admin-activity', LogAdminActivity::class);
        $router->aliasMiddleware('idempotency', EnsureApiIdempotency::class);
        $router->aliasMiddleware('scope', EnsureApiScope::class);
    }
}
