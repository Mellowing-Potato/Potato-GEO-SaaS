<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Routing\Route as ApiRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TenantRouteBoundaryTest extends TestCase
{
    public function test_every_business_api_route_requires_auth_and_tenant_context(): void
    {
        $this->auditRoutes(Route::getRoutes());
    }

    private function auditRoutes(iterable $routes): void
    {
        $public = [
            'api/v1/account/login' => ['action' => 'productLogin', 'throttle' => 'throttle:10,1'],
            'api/v1/account/register' => ['action' => 'register', 'throttle' => 'throttle:5,1'],
            'api/v1/account/forgot-password' => ['action' => 'forgotPassword', 'throttle' => 'throttle:5,1'],
            'api/v1/account/reset-password' => ['action' => 'resetPassword', 'throttle' => 'throttle:10,1'],
            'api/v1/auth/session-login' => ['action' => 'sessionLogin', 'throttle' => 'throttle:60,1'],
            'api/v1/auth/local-start' => ['action' => 'localStart', 'throttle' => 'throttle:10,1'],
        ];
        $seenPublic = [];
        $checked = 0;

        foreach ($routes as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/')) {
                continue;
            }
            $middleware = $route->gatherMiddleware();
            if (isset($public[$uri])) {
                $this->assertArrayNotHasKey($uri, $seenPublic, $uri.' has duplicate public authentication entries');
                $this->assertSame(AuthController::class.'@'.$public[$uri]['action'], $route->getActionName(), $uri.' has an unexpected authentication action');
                $this->assertSame(['POST'], $route->methods(), $uri.' must accept only POST');
                $throttles = array_values(array_filter($middleware,
                    static fn ($entry): bool => is_string($entry) && str_starts_with($entry, 'throttle:')));
                $this->assertSame([$public[$uri]['throttle']], $throttles, $uri.' has an unexpected authentication rate limit');
                $seenPublic[$uri] = true;
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

        $this->assertSame([], array_keys(array_diff_key($public, $seenPublic)), 'Every expected public authentication entry must exist');
        $this->assertGreaterThan(200, $checked, 'Route audit must cover the current API surface');
    }

    #[DataProvider('boundaryMutations')]
    public function test_route_audit_rejects_authentication_boundary_drift(string $mutation, string $expectedFailure): void
    {
        $routes = [];
        $changed = false;
        foreach (Route::getRoutes() as $route) {
            $isBusinessTarget = $mutation === 'business authentication'
                && $route->uri() === 'api/v1/projects' && in_array('GET', $route->methods(), true);
            $isLocalStartTarget = $mutation !== 'business authentication' && $route->uri() === 'api/v1/auth/local-start';
            if (! $isBusinessTarget && ! $isLocalStartTarget) {
                $routes[] = $route;
                continue;
            }
            $changed = true;
            if ($mutation === 'missing authentication entry') {
                continue;
            }
            $action = $route->getAction();
            // The existing URI already includes the group prefix; do not apply it a second time.
            unset($action['prefix']);
            $methods = $route->methods();
            if ($mutation === 'business authentication') {
                $action['middleware'] = array_values(array_filter($action['middleware'], static fn ($entry): bool => $entry !== 'auth:sanctum'));
            } elseif ($mutation === 'local-start action') {
                $action['uses'] = $action['controller'] = AuthController::class.'@sessionLogin';
            } elseif ($mutation === 'local-start method') {
                $methods = ['GET'];
            } elseif ($mutation === 'local-start rate limit') {
                $action['middleware'] = array_values(array_filter($action['middleware'],
                    static fn ($entry): bool => ! is_string($entry) || ! str_starts_with($entry, 'throttle:')));
                $action['middleware'][] = 'throttle:60,1';
            }
            $changedRoute = new ApiRoute($methods, $route->uri(), $action);
            $this->assertSame($route->uri(), $changedRoute->uri(), 'Mutations must retain the route being audited.');
            $routes[] = $changedRoute;
        }
        $this->assertTrue($changed, 'The mutation must actually change an existing route.');
        $failure = null;
        try {
            $this->auditRoutes($routes);
        } catch (AssertionFailedError $error) {
            $failure = $error;
        }
        $this->assertInstanceOf(AssertionFailedError::class, $failure, 'The route auditor must reject the mutation.');
        $this->assertStringContainsString($expectedFailure, $failure->getMessage());
    }

    public static function boundaryMutations(): array
    {
        return [
            'business auth remains required' => ['business authentication', 'api/v1/projects lacks authentication'],
            'nonce action remains fixed' => ['local-start action', 'api/v1/auth/local-start has an unexpected authentication action'],
            'nonce method remains POST' => ['local-start method', 'api/v1/auth/local-start must accept only POST'],
            'nonce throttle remains fixed' => ['local-start rate limit', 'api/v1/auth/local-start has an unexpected authentication rate limit'],
            'nonce endpoint cannot disappear' => ['missing authentication entry', 'Every expected public authentication entry must exist'],
        ];
    }
}
