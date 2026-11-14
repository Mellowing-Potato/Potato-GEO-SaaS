<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 高成本接口限流（计划 §9.4，2026-09-21）。
 *
 * 这一条判据要守两件事，缺一不可：
 *   1. **默认不限流**：没有配置时 `Limit::none()`，与改动前行为一致 —— 口径是产品决策，
 *      代码不能替产品把用户挡在门外；
 *   2. **填上就真生效**：配 `'max,decay'` 之后，第 max+1 次会被判为超限（计数键是租户+用户）。
 *
 * 另外静态检查 8 条花钱路由都挂上了对应的 `throttle:geo-*`（漏挂 = 旋钮是假的）。
 */
class HighCostEndpointRateLimitTest extends TestCase
{
    /** 路由名 → 限流名（与 routes/api.php 里挂的字符串一致）。 */
    private const ROUTES = [
        'api/v1/skills/execute' => 'geo-skill-execute',
        'api/v1/skills/execute-async' => 'geo-skill-execute',
        'api/v1/skills/suggest-keywords' => 'geo-skill-suggest',
        'api/v1/skills/suggest-competitors' => 'geo-skill-suggest',
        'api/v1/projects/{project}/analyses' => 'geo-analysis-create',
        'api/v1/knowledge-bases/{knowledgeBase}/documents' => 'geo-knowledge-ingest',
        'api/v1/knowledge-bases/{knowledgeBase}/ingest-url' => 'geo-knowledge-ingest',
        'api/v1/url-imports' => 'geo-url-import',
    ];

    public function test_money_routes_carry_the_expected_throttle_middleware(): void
    {
        $missing = [];
        foreach (self::ROUTES as $uri => $limiter) {
            $route = collect(Route::getRoutes()->getRoutes())
                ->first(fn (RoutingRoute $r) => $r->uri() === $uri && in_array('POST', $r->methods(), true));
            if (! $route) {
                $missing[] = $uri.'（路由不存在？）';

                continue;
            }
            if (! in_array('throttle:'.$limiter, $route->gatherMiddleware(), true)) {
                $missing[] = $uri.' → 缺 throttle:'.$limiter;
            }
        }

        $this->assertSame([], $missing, "花钱的路由没挂上限流旋钮：\n".implode("\n", $missing));
    }

    public function test_limits_are_off_by_default(): void
    {
        foreach (array_keys(AppServiceProviderTestLimits()) as $limiterName) {
            $limits = RateLimiter::limiter($limiterName)(Request::create('/'));
            $limits = is_array($limits) ? $limits : [$limits];
            $this->assertCount(1, $limits);
            $this->assertInstanceOf(Limit::class, $limits[0]);
            // Laravel 的 Limit::none() 用 PHP_INT_MAX 表示"不限"，不是 null —— 两种都算不限流。
            $this->assertTrue(
                $limits[0]->maxAttempts === null || $limits[0]->maxAttempts === PHP_INT_MAX,
                $limiterName.'：没配口径时必须是不限流（实际 maxAttempts = '
                    .var_export($limits[0]->maxAttempts, true).'）',
            );
        }
    }

    public function test_configured_limit_throttles_after_max_attempts(): void
    {
        config(['geo.rate_limits.skill_execute' => '2,60']);

        $limiter = RateLimiter::limiter('geo-skill-execute');
        $request = Request::create('/api/v1/skills/execute');
        $request->setUserResolver(fn () => new class
        {
            public int $id = 7;

            public int $tenant_id = 42;
        });
        $limits = $limiter($request);
        $limit = is_array($limits) ? $limits[0] : $limits;
        $this->assertSame(2, $limit->maxAttempts);
        $this->assertSame(42, $request->user()->tenant_id); // 计数键取自租户+用户
        $this->assertStringContainsString('tenant:42:user:7', $limit->key);

        $store = app('cache')->store();
        $hit = fn () => RateLimiter::hit($limit->key, 60);
        $hit();
        $hit();
        $this->assertTrue(RateLimiter::tooManyAttempts($limit->key, 2), '第 3 次应当被判超限');
        $this->assertGreaterThan(0, RateLimiter::availableIn($limit->key));

        // 换个租户/用户是另一只桶 —— 一个人的狂刷不该拖垮同租户同事，也不该跨租户串号。
        $other = Request::create('/api/v1/skills/execute');
        $other->setUserResolver(fn () => new class
        {
            public int $id = 8;

            public int $tenant_id = 42;
        });
        $otherLimit = $limiter($other);
        $otherLimit = is_array($otherLimit) ? $otherLimit[0] : $otherLimit;
        $this->assertNotSame($limit->key, $otherLimit->key);
        $this->assertFalse(RateLimiter::tooManyAttempts($otherLimit->key, 2));
    }

    public function test_broken_configuration_is_treated_as_unlimited(): void
    {
        // 写错的配置当"没配"：限流配置把所有人挡在门外，比不限流更糟。
        foreach (['abc', '10', '10,', ',60', '0,60', '10,-5'] as $bad) {
            $this->assertNull(AppServiceProvider::parseRateLimit($bad), $bad);
        }
        $this->assertSame([30, 60], AppServiceProvider::parseRateLimit('30,60'));
    }
}

/** 五个限流名的清单（避免测试里手写第二份）。 */
function AppServiceProviderTestLimits(): array
{
    return [
        'geo-skill-execute' => 'skill_execute',
        'geo-skill-suggest' => 'skill_suggest',
        'geo-analysis-create' => 'analysis_create',
        'geo-knowledge-ingest' => 'knowledge_ingest',
        'geo-url-import' => 'url_import',
    ];
}
