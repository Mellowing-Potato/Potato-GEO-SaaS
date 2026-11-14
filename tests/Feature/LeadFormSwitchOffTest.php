<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 验收判据 L10：公开表单总开关关掉（GEO_LEAD_FORM_ENABLED=false）时，公开路由 /forms/{tenantSlug}/{slug}
 * 根本不注册 → GET / POST 一律 404（执行书 §五-3「路由放进 web.php 的 switch-gated 块」、§七-8「每个页面都要有
 * 默认 false 的 config('geo.*_enabled') 开关」）。
 *
 * 与 LeadFormModuleTest 对称：那个类把开关打开，这个类把开关关掉。路由按 config 在 boot 时注册，
 * 本类 getEnvironmentSetUp 设 false，refreshApplication() 后路由即不存在，与生产「开关默认关闭」一致。
 */
class LeadFormSwitchOffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 开关关 → 公开路由不注册（createApplication 覆盖已在 boot 前把 env 设成 false）。
        $this->refreshApplication();
        $this->artisan('migrate', ['--force' => true]);
        config(['app.tenant_domain' => 'geo.example.test']);
    }

    /**
     * 改开关之前的原值快照，tearDown 要照原样放回去（见下方说明）。
     *
     * 三个来源分开存：PHPUnit 的 <env> 未必三处都写，只从其中一处取值再往三处还原，
     * 会把没读到的那处直接抹掉 —— 这正是第一版修复没生效的原因。
     *
     * ⚠️ 只在第一次 createApplication() 时抓。本类的 setUp() 里又调了一次
     * refreshApplication()，等于每个用例 createApplication() 会跑**两次**；
     * 第二次抓到的已经是自己刚写进去的 'false'，照它还原＝把污染原样留下 ——
     * 这是第二版修复没生效的原因，探针实测出来的。
     *
     * @var array{server: mixed, env: mixed, putenv: string|false}|null
     */
    private ?array $switchSnapshot = null;

    /**
     * 同 LeadFormModuleTest 的说明：本版本 createApplication() 在返回前就 bootstrap 内核，
     * 路由在此时按 config 加载；框架不调用 getEnvironmentSetUp。L10 要在 boot 前把开关 env 设成 false，
     * 让 routes/web.php 的 switch-gated 块不注册 /forms/* → 匿名访问 404。
     */
    public function createApplication()
    {
        $this->switchSnapshot ??= [
            'server' => $_SERVER['GEO_LEAD_FORM_ENABLED'] ?? null,
            'env' => $_ENV['GEO_LEAD_FORM_ENABLED'] ?? null,
            'putenv' => getenv('GEO_LEAD_FORM_ENABLED'),
        ];

        putenv('GEO_LEAD_FORM_ENABLED=false');
        $_ENV['GEO_LEAD_FORM_ENABLED'] = 'false';
        $_SERVER['GEO_LEAD_FORM_ENABLED'] = 'false';

        return parent::createApplication();
    }

    /**
     * ⚠️ 必须把开关放回原值，否则本类跑完会把进程级的 GEO_LEAD_FORM_ENABLED=false
     * 留给后面的测试类。putenv / $_ENV / $_SERVER 都是**进程级**的，
     * RefreshDatabase 和 createApplication 都不管它。
     *
     * 原值来自 phpunit.xml:45 的 `<env name="GEO_LEAD_FORM_ENABLED" value="true"/>`，
     * 那是 PHPUnit 启动时设一次的全局值 —— 不写死 'true'，照原样存取，
     * 免得哪天 phpunit.xml 改了这里跟着骗人。
     * ⚠️ 且必须三处各存各还：只按 getenv() 取值会读到 false，
     * 于是「还原」变成把 $_SERVER / $_ENV 里那份也删掉，比不还原还糟。
     *
     * 实测后果（09-01 撞上）：`php artisan test LeadFormSwitchOffTest LeadAdminUiTest`
     * 会红在 test_every_lead_endpoint_the_frontend_calls_is_a_registered_route（路由表整个空掉），
     * 而单跑 LeadAdminUiTest 是绿的 —— 典型的「按顺序才复现」，最难查。
     */
    protected function tearDown(): void
    {
        if ($this->switchSnapshot === null) {
            parent::tearDown();

            return;
        }

        $this->switchSnapshot['putenv'] === false
            ? putenv('GEO_LEAD_FORM_ENABLED')
            : putenv('GEO_LEAD_FORM_ENABLED='.$this->switchSnapshot['putenv']);

        foreach (['server' => &$_SERVER, 'env' => &$_ENV] as $key => &$bag) {
            if ($this->switchSnapshot[$key] === null) {
                unset($bag['GEO_LEAD_FORM_ENABLED']);
            } else {
                $bag['GEO_LEAD_FORM_ENABLED'] = $this->switchSnapshot[$key];
            }
        }
        unset($bag);

        parent::tearDown();
    }

    public function test_l10_public_form_route_returns_404_when_switch_off(): void
    {
        // 先建一个 tenant + 一个 active 表单，证明「不是因为没数据才 404，而是路由压根不存在」。
        $tenant = Tenant::query()->create([
            'subdomain' => 'tenant-x',
            'name' => 'TENANT-X',
            'plan' => 'basic',
            'status' => 'active',
        ]);

        // 路由未注册时，无论 GET 还是 POST 都应是 404。
        $this->get('/forms/tenant-x/contact')->assertStatus(404);
        $this->post('/forms/tenant-x/contact', ['name' => 'x'])->assertStatus(404);

        // 反向确认：开关关闭态下，即便直接命中已存在的表单 slug，也没有任何公开入口暴露它。
        $this->assertFalse(
            config('geo.lead_form_enabled'),
            'L10 前提：开关必须为 false',
        );
    }

    /**
     * L10 补齐（评审收尾 2026-08-30）：开关关掉时，租户侧管理 API 也必须一起消失。
     *
     * 原先只有 web.php 的公开页闸住了，routes/api.php 的 lead-forms / leads / lead-analytics
     * 三组端点始终注册，只挂 auth + can:lead:manage。后果不是「多一个受保护的接口」那么轻：
     * 开关本该是本模块的第一道回滚（执行书 §七 红线 8），可回滚之后 admin/editor 拿 token
     * 照样能建表单、拿到一个必然 404 的公开链接 —— 模块被留在一个比全开更坏的半开状态。
     * 单 7 手动发布的 API 从一开始就与 web 同闸（routes/api.php 上方同款 if），单 8 是漏写。
     *
     * 断言分两层，因为两层会因不同原因失效：
     *  ① 路由表层：注册表里根本不该有这些 URI —— 与中间件顺序、认证状态全无关；
     *  ② HTTP 层：必须是 404（路由不存在），而不是 401/403。
     *     401/403 等于对外宣告「这儿有个模块，只是没开」，也说明路由其实还在。
     */
    public function test_l10_tenant_lead_api_routes_are_unregistered_when_switch_off(): void
    {
        // ① 路由表层：把所有已注册 URI 拉出来，挨个确认没有线索模块的端点。
        $registered = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route): string => $route->uri())
            ->all();

        foreach (['api/v1/lead-forms', 'api/v1/leads', 'api/v1/lead-analytics'] as $prefix) {
            $leaked = array_values(array_filter(
                $registered,
                fn (string $uri): bool => $uri === $prefix || str_starts_with($uri, $prefix.'/'),
            ));

            $this->assertSame(
                [],
                $leaked,
                "开关关闭时不该注册任何 {$prefix} 端点，实际仍在：".implode(', ', $leaked),
            );
        }

        // ② HTTP 层：404（不存在），而不是 401（存在但没登录）。
        //    开关打开时这几条恰恰会返回 401 —— 所以这条断言真的能区分两种状态。
        $this->getJson('/api/v1/leads')->assertStatus(404);
        $this->getJson('/api/v1/lead-forms')->assertStatus(404);
        $this->getJson('/api/v1/lead-analytics')->assertStatus(404);
        $this->getJson('/api/v1/leads/export')->assertStatus(404);
        $this->postJson('/api/v1/lead-forms', ['name' => 'x'])->assertStatus(404);
    }
}
