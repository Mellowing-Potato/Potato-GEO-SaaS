<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 验收判据 V8（关态侧）：总开关 GEO_THEME_PACKAGE_ENABLED=false 时，全部主题包端点
 * 不注册 → 无论 GET 还是 POST 一律 404（执行书红线 6 / §七「每个页面都要有默认 false 的开关」）。
 *
 * 与 ThemePackageGenTest 对称：那个类把开关打开，这个类把开关关掉。路由按 config 在 boot 时注册，
 * 本类 createApplication() 在 boot 前把 env 设成 false，refreshApplication() 后路由即不存在。
 *
 * putenv / $_ENV / $_SERVER 三处都要存原值、tearDown 还原（参照 LeadFormSwitchOffTest，避免污染后续测试类）。
 */
class ThemePackageSwitchOffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 开关关 → 路由不注册。需要先 refreshApplication 让路由按 false 重新加载。
        $this->refreshApplication();
        $this->artisan('migrate', ['--force' => true]);
        config(['app.tenant_domain' => 'geo.example.test']);
    }

    private ?array $switchSnapshot = null;

    public function createApplication()
    {
        $this->switchSnapshot ??= [
            'server' => $_SERVER['GEO_THEME_PACKAGE_ENABLED'] ?? null,
            'env' => $_ENV['GEO_THEME_PACKAGE_ENABLED'] ?? null,
            'putenv' => getenv('GEO_THEME_PACKAGE_ENABLED'),
        ];

        putenv('GEO_THEME_PACKAGE_ENABLED=false');
        $_ENV['GEO_THEME_PACKAGE_ENABLED'] = 'false';
        $_SERVER['GEO_THEME_PACKAGE_ENABLED'] = 'false';

        return parent::createApplication();
    }

    protected function tearDown(): void
    {
        if ($this->switchSnapshot !== null) {
            $this->switchSnapshot['putenv'] === false
                ? putenv('GEO_THEME_PACKAGE_ENABLED')
                : putenv('GEO_THEME_PACKAGE_ENABLED='.$this->switchSnapshot['putenv']);

            foreach (['server' => &$_SERVER, 'env' => &$_ENV] as $key => &$bag) {
                if ($this->switchSnapshot[$key] === null) {
                    unset($bag['GEO_THEME_PACKAGE_ENABLED']);
                } else {
                    $bag['GEO_THEME_PACKAGE_ENABLED'] = $this->switchSnapshot[$key];
                }
            }
            unset($bag);
        }

        parent::tearDown();
    }

    public function test_v8_switch_off_all_endpoints_404(): void
    {
        $this->assertFalse(config('geo.theme_package_enabled'), 'V8 前提：开关必须为 false');

        // ① 路由表层：已注册 URI 里不应有任何 theme-packages 端点
        $registered = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route): string => $route->uri())
            ->all();
        $leaked = array_values(array_filter(
            $registered,
            fn (string $uri): bool => str_starts_with($uri, 'api/v1/theme-packages')
                || str_starts_with($uri, 'theme-package')
                || str_starts_with($uri, 'theme-preview'),
        ));
        $this->assertSame([], $leaked, '开关关闭时仍注册了主题包端点：'.implode(', ', $leaked));

        // ② HTTP 层：404（不存在），而不是 401/403（说明路由其实还在）
        $this->getJson('/api/v1/theme-packages')->assertStatus(404);
        $this->postJson('/api/v1/theme-packages', ['name' => 'x'])->assertStatus(404);
        $this->getJson('/api/v1/theme-packages/1')->assertStatus(404);
        $this->get('/theme-package')->assertStatus(404);
        $this->get('/theme-preview/1/home')->assertStatus(404);
    }
}
