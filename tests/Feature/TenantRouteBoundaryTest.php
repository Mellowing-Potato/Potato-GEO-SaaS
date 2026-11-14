<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TenantRouteBoundaryTest extends TestCase
{
    public function test_every_business_api_route_requires_auth_and_tenant_context(): void
    {
        $public = [
            'api/v1/account/login', 'api/v1/account/register',
            'api/v1/account/forgot-password', 'api/v1/account/reset-password',
            'api/v1/auth/session-login',
        ];
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/')) {
                continue;
            }
            $middleware = $route->gatherMiddleware();
            if (in_array($uri, $public, true)) {
                continue;
            }
            $this->assertContains('auth:sanctum', $middleware, $uri.' lacks authentication');
            if (str_starts_with($uri, 'api/v1/platform/')) {
                $this->assertContains('can:platform:operations', $middleware, $uri.' lacks platform authorization');
                $this->assertContains('log-admin-activity', $middleware, $uri.' lacks audit');
            } elseif ($uri !== 'api/v1/auth/me') {
                $this->assertContains('tenant', $middleware, $uri.' lacks tenant context');
            }
            $checked++;
        }

        $this->assertGreaterThan(200, $checked, 'Route audit must cover the current API surface');
    }
}
