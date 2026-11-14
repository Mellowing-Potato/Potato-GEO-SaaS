<?php

namespace Tests\Feature;

use App\Models\LeadForm;
use App\Models\LeadFormVisit;
use App\Models\LeadSubmission;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Lead\LeadFormFields;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * 单 8 · 工程师评审修复回归（2026-08-28 评审不通过 → 修复后补测）。
 *
 * 评审指出「管理端只做了三条冒烟，改线索状态这条主流程一条测试都没有，阻断项就藏在那里」
 * ——本文件每个测试对应一条评审意见，缺一不可：
 *  - A1：handled_by 必须落当前操作人（user id），不是租户 id（FK 指向 users.id）；
 *  - A2：表单维度限流 key 必须带租户段，否则 A 租户打满后 B 租户同名表单首次提交就 429；
 *  - A3：defaultFields 的标签是落库数据，必须是成文中文，不能是翻译 key；
 *  - H1：浏览→提交→转化全漏斗（此前 lead_form_visits 只写不读，统计里没有漏斗）；
 *  - H2：公开 GET 页挂限流（此前零限流但每次访问落 visit 行），双维度 per IP + per 表单；
 *  - H3：自定义日期区间下界钳制（1900-01-01 不得生成 4 万个 trend 点）；
 *  - M1：只传 status 不清空 note（部分更新语义）；
 *  - M2：大写 tenantSlug 页面 200 时也要落 visit 行；
 *  - M3：无 JS 提交后服务端直出成功/错误提示（302 + flash）；
 *  - 删除语义：应用层 422 与 DB 层 nullOnDelete 是两层不是矛盾，行为钉死防被「统一」掉；
 *  - C4：64KB 上限不能只判 php://input（multipart 走 $_POST，那条路此前完全不设防）。
 *
 * 惯例沿 LeadFormModuleTest：GEO_LEAD_FORM_ENABLED 在 boot 前落 env；限流走生产默认值。
 */
class LeadReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshApplication();
        $this->artisan('migrate', ['--force' => true]);
        Cache::flush();
        config(['app.tenant_domain' => 'geo.example.test']);
    }

    public function createApplication()
    {
        putenv('GEO_LEAD_FORM_ENABLED=true');
        $_ENV['GEO_LEAD_FORM_ENABLED'] = 'true';
        $_SERVER['GEO_LEAD_FORM_ENABLED'] = 'true';

        return parent::createApplication();
    }

    // ───────────────────────── 辅助 ─────────────────────────

    private function makeForm(Tenant $tenant, string $slug, array $fields, string $status = 'active'): LeadForm
    {
        TenantContext::set($tenant);
        try {
            return LeadForm::query()->create([
                'name' => '表单 '.$slug,
                'slug' => $slug,
                'status' => $status,
                'description' => '说明 '.$slug,
                'submit_button_label' => '提交',
                'success_message' => '提交成功',
                'fields' => $fields,
            ]);
        } finally {
            TenantContext::forget();
        }
    }

    private function basicFields(): array
    {
        return [
            ['name' => 'name', 'label' => '姓名', 'type' => 'text', 'required' => true, 'options' => []],
            ['name' => 'phone', 'label' => '电话', 'type' => 'phone', 'required' => false, 'options' => []],
        ];
    }

    private function forTenant(Tenant $tenant)
    {
        return $this->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }

    /**
     * 关键构造：让「租户 id ≠ 操作人 user id」且「库中不存在与租户同号的 user」——
     * 这正是评审 A1 两个探针的故障形态（旧代码会把 handled_by 落成租户 id，触发 FK 500 /
     * handler_name 变 null）。LeadFormModuleTest 的辅助方法每建一个租户就配一个 user，
     * 两者 id 天然同号，把这个 bug 完美藏住了——本测试就是补上这个盲区。
     */
    private function createDivergedTenantAdmin(string $subdomain): array
    {
        // 垫背租户：占掉一个租户 id，让目标租户 id 与后续 user id 错开。
        Tenant::query()->create([
            'subdomain' => 'decoy-'.$subdomain,
            'name' => 'DECOY',
            'plan' => 'basic',
            'status' => 'active',
        ]);

        $tenant = Tenant::query()->create([
            'subdomain' => $subdomain,
            'name' => strtoupper($subdomain),
            'plan' => 'basic',
            'status' => 'active',
        ]);

        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain),
            'role' => 'admin',
        ]);

        // 明示盲区前提：目标租户 id 与 user id 必须不同号，且无同号 user 存在。
        assert($tenant->id !== $admin->id, '本测试要求 tenant id 与 user id 错开，否则测不到 A1');
        assert(User::query()->whereKey($tenant->id)->doesntExist(), '库中不应存在与租户同号的 user');

        return [$tenant, $admin];
    }

    private function makeSubmission(Tenant $tenant, LeadForm $form): LeadSubmission
    {
        TenantContext::set($tenant);
        try {
            return LeadSubmission::query()->create([
                'tenant_id' => $tenant->id,
                'lead_form_id' => $form->id,
                'status' => LeadSubmission::STATUS_NEW,
                'payload' => ['name' => '张三', 'phone' => '13800000015'],
            ]);
        } finally {
            TenantContext::forget();
        }
    }

    // ───────────────────────── A1：handled_by 落当前操作人 ─────────────────────────

    public function test_a1_update_records_acting_user_not_tenant_id(): void
    {
        [$tenant, $admin] = $this->createDivergedTenantAdmin('tenant-a');
        $form = $this->makeForm($tenant, 'contact', $this->basicFields());
        $submission = $this->makeSubmission($tenant, $form);

        $response = $this->forTenant($tenant)
            ->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/leads/'.$submission->id, [
                'status' => LeadSubmission::STATUS_CONTACTED,
            ]);

        // 旧代码这里会 500（handled_by 落租户 id → FK 违约）；新代码 200。
        $response->assertStatus(200);

        $fresh = LeadSubmission::query()->firstOrFail();
        $this->assertSame($admin->id, (int) $fresh->handled_by, 'handled_by 应落当前操作人 user id');
        $this->assertNotSame($tenant->id, (int) $fresh->handled_by, 'handled_by 绝不能落租户 id');
        $this->assertNotNull($fresh->handled_at, 'handled_at 应随状态变更落值');

        // handler_name 能正确回显操作人姓名（旧代码此处为 null）。
        $response->assertJsonPath('data.handler_name', $admin->name);
    }

    public function test_a1b_update_does_not_500_when_no_same_id_user_exists(): void
    {
        // 评审原探针形态：tenant_id=9、库里无 id=9 的 user → PUT 直接 500。
        [$tenant, $admin] = $this->createDivergedTenantAdmin('tenant-b');
        $form = $this->makeForm($tenant, 'contact', $this->basicFields());
        $submission = $this->makeSubmission($tenant, $form);

        $this->forTenant($tenant)
            ->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/leads/'.$submission->id, [
                'status' => LeadSubmission::STATUS_CONVERTED,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', LeadSubmission::STATUS_CONVERTED)
            ->assertJsonPath('data.handler_name', $admin->name);
    }

    // ───────────────────────── A2：表单限流桶带租户段 ─────────────────────────

    public function test_a2_tenant_a_burst_does_not_throttle_tenant_b_same_slug(): void
    {
        // 冻结时钟：限流窗口是 60 秒，本用例要连打 60 次以上才撞得到阈值。
        // 不冻就是在跟墙钟赛跑——机器一慢，后面几次落到窗口外、计数被重置，
        // 该 429 的地方拿到 200，测试偶发红。红的是机器快慢，不是业务代码。
        // 本文件下面两条限流用例同理。这行不是可有可无的样板，删掉 flake 就回来。
        $this->freezeTime();

        $tenantA = Tenant::query()->create(['subdomain' => 'tenant-a', 'name' => 'A', 'plan' => 'basic', 'status' => 'active']);
        $tenantB = Tenant::query()->create(['subdomain' => 'tenant-b', 'name' => 'B', 'plan' => 'basic', 'status' => 'active']);
        $this->makeForm($tenantA, 'contact', $this->basicFields());
        $this->makeForm($tenantB, 'contact', $this->basicFields());

        // A 租户的 contact 用 60 个不同 IP 打满表单维度阈值（60/min）。
        for ($i = 1; $i <= 60; $i++) {
            $this->withoutMiddleware(VerifyCsrfToken::class)
                ->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.($i % 254 + 1)])
                ->postJson('/forms/tenant-a/contact', ['name' => 'A'.$i])
                ->assertStatus(200);
        }

        // 全新 IP 提交 B 租户同名表单：旧代码共用「form:contact」桶 → 首次即 429；
        // 新代码 key 带租户段（form:tenant-b:contact）→ 200。
        $this->withoutMiddleware(VerifyCsrfToken::class)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
            ->postJson('/forms/tenant-b/contact', ['name' => 'B客户'])
            ->assertStatus(200)
            ->assertJson(['ok' => true]);

        // 反向对照：A 租户再打同表单仍应 429（桶确实满了，不是没生效）。
        $this->withoutMiddleware(VerifyCsrfToken::class)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.78'])
            ->postJson('/forms/tenant-a/contact', ['name' => 'A再次'])
            ->assertStatus(429);
    }

    // ───────────────────────── A3：默认字段是成文中文，不是翻译 key ─────────────────────────

    public function test_a3_default_fields_render_chinese_labels_not_translation_keys(): void
    {
        $tenant = Tenant::query()->create(['subdomain' => 'tenant-a', 'name' => 'A', 'plan' => 'basic', 'status' => 'active']);
        $this->makeForm($tenant, 'default-fields', LeadFormFields::defaultFields());

        $html = $this->get('/forms/tenant-a/default-fields')->assertStatus(200)->getContent();

        // 公开页标签必须是成文中文（落库数据），绝不能出现裸翻译 key。
        $this->assertStringContainsString('姓名', $html);
        $this->assertStringContainsString('电话', $html);
        $this->assertStringContainsString('邮箱', $html);
        $this->assertStringContainsString('留言内容', $html);
        $this->assertStringNotContainsString('admin.lead_forms', $html, '公开页出现了裸翻译 key');
        $this->assertStringNotContainsString('__(', $html);

        // select/checkbox 不带 options 时的兜底选项也必须是成文中文（走 normalizePosted，
        // 此前是 __('admin.lead_forms.defaults.options') 这类不存在的 key）。
        $normalized = LeadFormFields::normalizePosted([
            ['name' => 'region', 'label' => '地区', 'type' => 'select', 'required' => true],
            ['name' => 'agree', 'label' => '同意条款', 'type' => 'checkbox', 'required' => true],
        ]);
        $this->assertSame(['选项一'], $normalized[0]['options']);
        $this->assertSame(['同意'], $normalized[1]['options']);
    }

    // ───────────────────────── H2：公开 GET 页限流 ─────────────────────────

    public function test_h2_public_get_page_is_rate_limited(): void
    {
        // 冻结时钟，同 A2：61 次 GET 必须落在同一个 60 秒窗口内，否则偶发红。
        $this->freezeTime();

        $tenant = Tenant::query()->create(['subdomain' => 'tenant-a', 'name' => 'A', 'plan' => 'basic', 'status' => 'active']);
        $this->makeForm($tenant, 'contact', $this->basicFields());

        // 同一访客连打 61 次 GET（IP 维度阈值 60/min）→ 第 61 次 429。
        for ($i = 1; $i <= 61; $i++) {
            $status = $this
                ->withServerVariables(['REMOTE_ADDR' => '172.18.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.99'])
                ->get('/forms/tenant-a/contact')
                ->getStatusCode();
            $this->assertSame($i <= 60 ? 200 : 429, $status, "第 {$i} 次 GET 状态码不对");
        }
    }

    /**
     * 三轮评审（TrustProxies 落地后）：同一个边缘代理后面的两个访客，限流桶必须是分开的。
     *
     * 这条是整条链路的验收——配置没生效的话，$request->ip() 对所有人都是代理地址，
     * 甲把桶打满，乙第一次访问就 429，公开表单页对全平台所有访客同时失效。
     */
    public function test_h2_clients_behind_the_same_proxy_get_separate_buckets(): void
    {
        // 冻结时钟，同 A2：访客甲那 61 次请求必须落在同一个 60 秒窗口内，否则偶发红。
        $this->freezeTime();

        $tenant = Tenant::query()->create(['subdomain' => 'tenant-a', 'name' => 'A', 'plan' => 'basic', 'status' => 'active']);
        $this->makeForm($tenant, 'contact', $this->basicFields());

        // 访客甲把自己的桶打满（60/min）。REMOTE_ADDR 全程是同一个边缘代理。
        for ($i = 1; $i <= 60; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.10'])
                ->get('/forms/tenant-a/contact')
                ->assertStatus(200);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.10'])
            ->get('/forms/tenant-a/contact')
            ->assertStatus(429);

        // 访客乙（同一个代理、不同真实地址）必须不受影响。
        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.20'])
            ->get('/forms/tenant-a/contact')
            ->assertStatus(200);
    }

    /**
     * 第二道维度：换 IP 的分布式刷不受 per-IP 约束，所以每张表单还有一条写入总闸。
     * 这里直接断言限流器的配置（600/min、按租户+表单计），不打 600 次真实请求——
     * 那样只是把同一件事跑慢 3 秒。
     */
    public function test_h2_view_limiter_keeps_a_per_form_backstop(): void
    {
        $tenant = Tenant::query()->create(['subdomain' => 'tenant-a', 'name' => 'A', 'plan' => 'basic', 'status' => 'active']);
        $this->makeForm($tenant, 'contact', $this->basicFields());
        $this->get('/forms/tenant-a/contact')->assertStatus(200);

        $limits = (RateLimiter::limiter('lead-form-view'))(request());

        $this->assertCount(2, $limits, 'GET 限流应是双维度：per IP + per 表单');
        $this->assertSame(60, $limits[0]->maxAttempts);
        $this->assertStringContainsString('view-ip:', $limits[0]->key);
        $this->assertSame(600, $limits[1]->maxAttempts);
        $this->assertStringContainsString('view-form:tenant-a:contact', $limits[1]->key);
    }

    /** 落库的访客 IP 必须是真实客户端，不是代理地址（滥用取证靠这一列）。 */
    public function test_visit_row_records_the_real_client_ip_not_the_proxy(): void
    {
        $tenant = Tenant::query()->create(['subdomain' => 'tenant-ip', 'name' => 'IP', 'plan' => 'basic', 'status' => 'active']);
        $this->makeForm($tenant, 'contact', $this->basicFields());

        $this->withServerVariables([
            'REMOTE_ADDR' => '172.18.0.5',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.55',
        ])->get('/forms/tenant-ip/contact')->assertStatus(200);

        $visit = LeadFormVisit::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('203.0.113.55', $visit->ip_address, 'visit 行记的是代理地址，不是真实访客');
    }

    // ───────────────── 二轮评审：公开页不许发绝对 URL（混合内容） ─────────────────

    /**
     * 原病灶：边缘 Caddy 终止 TLS → 明文 HTTP 转给 geo-saas，而当时没配 TrustProxies，
     * Laravel 认定的协议是 http。asset() / url()->current() 于是在 https 页面上吐出 http://
     * 的样式表、脚本、表单 action 和 fetch 地址 —— 全被浏览器当混合内容拦掉。
     * 根因已由 bootstrap/app.php 的 trustProxies 修掉（见 TrustedProxyTest），
     * 但页面本身继续用根相对路径：正确性不该取决于代理配置对不对。这条守着后半句。
     */
    public function test_public_page_emits_no_absolute_urls(): void
    {
        $tenant = Tenant::query()->create(['subdomain' => 'tenant-mixed', 'name' => 'MIXED', 'plan' => 'basic', 'status' => 'active']);
        $this->makeForm($tenant, 'contact', $this->basicFields());

        $html = $this->withServerVariables([
            // 复刻生产：明文到达容器，但带着 Caddy 发的 X-Forwarded-Proto。
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'REMOTE_ADDR' => '172.18.0.5',
        ])->get('/forms/tenant-mixed/contact')->assertStatus(200)->getContent();

        foreach (['href="http', 'src="http', 'action="http'] as $needle) {
            $this->assertStringNotContainsString($needle, $html, "页面发了绝对 URL（{$needle}），https 下会被当混合内容拦掉");
        }
        // JS 配置里的 submitUrl 是 JSON 转义过的，单独查一遍。
        $this->assertStringNotContainsString('http:\/\/', $html, 'submitUrl 发了绝对 http URL');

        // 正面断言：资源走根相对路径，且带破缓存版本号（与本仓其它视图同一套）。
        $this->assertMatchesRegularExpression('#href="/geo-lead-form\.css\?v=\d+"#', $html);
        $this->assertMatchesRegularExpression('#src="/geo-lead-form\.js\?v=\d+"#', $html);
        $this->assertStringContainsString('action="/forms/tenant-mixed/contact"', $html);
    }

    // ───────────────────────── H3：日期区间下界钳制 ─────────────────────────

    public function test_h3_ancient_date_from_is_clamped_to_366_days(): void
    {
        $tenant = Tenant::query()->create(['subdomain' => 'tenant-clamp', 'name' => 'CLAMP', 'plan' => 'basic', 'status' => 'active']);
        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => \Tests\Support\FixtureIdentity::phone('clamp'),
            'role' => 'admin',
        ]);

        $response = $this->forTenant($tenant)
            ->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/lead-analytics?lead_date_from=1900-01-01')
            ->assertStatus(200);

        // 修复前：1900-01-01 → 4.6 万个 trend 点、响应 MB 级；修复后：≤ 366 天。
        $trend = $response->json('summary.trend');
        $this->assertIsArray($trend);
        $this->assertLessThanOrEqual(366, count($trend), 'trend 点数超过 366 天，下界钳制没生效');
        $this->assertGreaterThan(0, count($trend));
    }

    // ───────────────────────── M1：只传 status 不清空 note ─────────────────────────

    public function test_m1_note_is_preserved_when_omitted_and_cleared_when_explicitly_empty(): void
    {
        [$tenant, $admin] = $this->createDivergedTenantAdmin('tenant-c');
        $form = $this->makeForm($tenant, 'contact', $this->basicFields());
        $submission = $this->makeSubmission($tenant, $form);

        // 先写一条备注。
        $this->forTenant($tenant)
            ->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/leads/'.$submission->id, [
                'status' => LeadSubmission::STATUS_CONTACTED,
                'note' => '首次跟进备注',
            ])
            ->assertStatus(200);

        // ① 只传 status（note 不在 payload 里）→ 备注必须保留。
        $this->forTenant($tenant)
            ->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/leads/'.$submission->id, [
                'status' => LeadSubmission::STATUS_CONTACTED,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.note', '首次跟进备注');

        // ② 显式传空串 → 才清空（传了就覆盖，含空值）。
        $this->forTenant($tenant)
            ->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/leads/'.$submission->id, [
                'status' => LeadSubmission::STATUS_CONVERTED,
                'note' => '',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.note', '');
    }

    // ───────────────────────── M2：大写 tenantSlug 也落 visit 行 ─────────────────────────

    public function test_m2_uppercase_tenant_slug_still_records_visit(): void
    {
        $tenant = Tenant::query()->create(['subdomain' => 'tenant-a', 'name' => 'A', 'plan' => 'basic', 'status' => 'active']);
        $this->makeForm($tenant, 'contact', $this->basicFields());

        // resolve 侧对租户 slug 忽略大小写 → 页面 200。
        $this->get('/forms/TENANT-A/contact')->assertStatus(200);

        // 修复前：terminate 用原始大小写查 subdomain 查不到 → 访问了页面却落 0 行。
        $this->assertSame(1, LeadFormVisit::query()->count(), '大写 tenantSlug 的访问未落 visit 行');
        $visit = LeadFormVisit::query()->firstOrFail();
        $this->assertSame($tenant->id, (int) $visit->tenant_id);
        $this->assertSame(200, (int) $visit->status_code);
    }

    // ───────────────────────── M3：无 JS 降级提示 ─────────────────────────

    public function test_m3_no_js_submit_shows_server_rendered_success_flash(): void
    {
        $tenant = Tenant::query()->create(['subdomain' => 'tenant-a', 'name' => 'A', 'plan' => 'basic', 'status' => 'active']);
        $this->makeForm($tenant, 'contact', $this->basicFields());

        // 普通 POST（不带 Accept: JSON，模拟浏览器关 JS 的原生 form 提交）→ 302 回表单页，
        // followRedirects 保持同一会话，flash 才能带过去。
        $response = $this->from('/forms/tenant-a/contact')
            ->withoutMiddleware(VerifyCsrfToken::class)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.88'])
            ->post('/forms/tenant-a/contact', ['name' => '张三', 'phone' => '13800000015']);
        $response->assertStatus(302);

        $html = $this->followRedirects($response)->getContent();
        // flash 成功提示必须由服务端直出（此前只有 JS 会写 #lf-alert）。
        $this->assertStringContainsString('提交成功', $html, '无 JS 提交后页面看不到成功提示');
        $this->assertStringContainsString('lf-alert--ok', $html, '成功提示未复用 lf-alert 样式');
    }

    public function test_m3_no_js_validation_failure_shows_server_rendered_errors(): void
    {
        $tenant = Tenant::query()->create(['subdomain' => 'tenant-a', 'name' => 'A', 'plan' => 'basic', 'status' => 'active']);
        $this->makeForm($tenant, 'contact', $this->basicFields());

        // 必填字段留空 → 302 回 + errors；页面必须服务端直出错误（followRedirects 保持会话）。
        $response = $this->from('/forms/tenant-a/contact')
            ->withoutMiddleware(VerifyCsrfToken::class)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.89'])
            ->post('/forms/tenant-a/contact', ['phone' => '13800000015']);
        $response->assertStatus(302);

        $html = $this->followRedirects($response)->getContent();
        $this->assertStringContainsString('lf-alert--err', $html, '无 JS 校验失败后页面看不到错误提示');
        $this->assertStringContainsString('姓名', $html, '错误提示应含字段标签');

        // 校验失败不得落库。
        $this->assertSame(0, LeadSubmission::query()->count());
    }

    // ───────────────────────── H1：浏览→提交→转化 全漏斗（真实流量） ─────────────────────────

    /**
     * 评审 H1：lead_form_visits 此前是只写不读的表——中间件每次访问都落行，
     * 但全仓没有任何代码读它，统计只查 lead_submissions，「转化分析」根本没有漏斗。
     * 本用例走真实 HTTP 流量：开页 ×2 + 撞死链 ×1 → 提交 ×1 → 标记转化 ×1，
     * 断言统计接口把三层数都读出来，且 404 不进分母。
     */
    public function test_h1_funnel_reads_visits_and_excludes_404_from_denominator(): void
    {
        [$tenant, $admin] = $this->createDivergedTenantAdmin('tenant-funnel');
        $this->makeForm($tenant, 'contact', $this->basicFields());

        // ① 浏览：成功打开 2 次。
        $this->get('/forms/tenant-funnel/contact')->assertStatus(200);
        $this->get('/forms/tenant-funnel/contact')->assertStatus(200);

        // ② 死链：不存在的表单 404 —— 照旧落 visit 行（爬虫信号），但不得进漏斗分母。
        $this->get('/forms/tenant-funnel/no-such-form')->assertStatus(404);

        $this->assertSame(3, LeadFormVisit::withoutGlobalScopes()->count(), '三次访问都应落 visit 行（含 404）');

        // ③ 提交 1 条。
        $this->withoutMiddleware(VerifyCsrfToken::class)
            ->postJson('/forms/tenant-funnel/contact', ['name' => '张三', 'phone' => '13800000015'])
            ->assertStatus(200);

        // ④ 后台标记转化。
        $submission = LeadSubmission::withoutGlobalScopes()->firstOrFail();
        $this->forTenant($tenant)
            ->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/leads/'.$submission->id, ['status' => LeadSubmission::STATUS_CONVERTED])
            ->assertStatus(200);

        $summary = $this->forTenant($tenant)
            ->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/lead-analytics')
            ->assertStatus(200)
            ->json('summary');

        $this->assertTrue($summary['funnel']['views_tracked'], '采集表存在时应明确回 true，不能拿 0 冒充没人访问');
        $this->assertSame(2, $summary['funnel']['views'], '浏览量只数成功打开的 2 次，404 不算');
        $this->assertSame(1, $summary['funnel']['submissions']);
        $this->assertSame(1, $summary['funnel']['converted']);
        // 注意取 (float)：整数值的比率经 json_encode 会掉小数位（50.0 → 50），
        // 这是本仓 conversion_rate 一直以来的行为，不在本次修复范围，测试按值比。
        $this->assertSame(50.0, (float) $summary['funnel']['view_to_submission_rate'], '2 次浏览 1 条提交 = 50%');
        $this->assertSame(100.0, (float) $summary['funnel']['submission_to_converted_rate']);

        // 趋势线同口径：今天这格的 views 也应是 2（不是 3）。
        $today = now()->toDateString();
        $todayPoint = collect($summary['trend'])->firstWhere('date', $today);
        $this->assertNotNull($todayPoint, '趋势线应包含今天');
        $this->assertSame(2, $todayPoint['views']);
        $this->assertSame(1, $todayPoint['submissions']);
    }

    /**
     * 漏斗的「提交」不套 status 筛选：筛「新线索」时分子分母必须同源，
     * 否则浏览 2 / 提交 0（已转化的被筛掉）会算出 0% 的假转化率。
     */
    public function test_h1_funnel_submissions_ignore_status_filter(): void
    {
        [$tenant, $admin] = $this->createDivergedTenantAdmin('tenant-funnel2');
        $form = $this->makeForm($tenant, 'contact', $this->basicFields());
        $submission = $this->makeSubmission($tenant, $form);

        $this->forTenant($tenant)
            ->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/leads/'.$submission->id, ['status' => LeadSubmission::STATUS_CONVERTED])
            ->assertStatus(200);

        $summary = $this->forTenant($tenant)
            ->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/lead-analytics?lead_status='.LeadSubmission::STATUS_NEW)
            ->assertStatus(200)
            ->json('summary');

        // KPI 区按筛选走：筛 new，这条已转化的不算。
        $this->assertSame(0, $summary['kpis']['submissions']);
        // 漏斗区不套筛选：这条提交仍在漏斗里。
        $this->assertSame(1, $summary['funnel']['submissions']);
        $this->assertSame(1, $summary['funnel']['converted']);
    }

    // ───────────────────────── 中低项：删除语义与 nullOnDelete 分层 ─────────────────────────

    /**
     * 评审指出 destroy 的 422 与迁移里的 nullOnDelete 像是「对同一问题的两个矛盾答案」。
     * 结论是两层各司其职、都要在：应用层不给「一键删掉客户线索」的入口（本用例钉住），
     * DB 层 nullOnDelete 兜别的路径（租户注销级联 / 人工 SQL）。这里把行为固定下来，
     * 免得下一个人为了「一致」把其中一层删掉。
     */
    public function test_form_with_submissions_cannot_be_deleted_and_message_points_to_deactivate(): void
    {
        [$tenant, $admin] = $this->createDivergedTenantAdmin('tenant-del');
        $form = $this->makeForm($tenant, 'contact', $this->basicFields());
        $this->makeSubmission($tenant, $form);

        $this->forTenant($tenant)
            ->actingAs($admin, 'sanctum')
            ->deleteJson('/api/v1/lead-forms/'.$form->id)
            ->assertStatus(422)
            // 文案要给出下一步（停用），不能只说「不能删除」。
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, '停用'));

        $this->assertSame(1, LeadForm::withoutGlobalScopes()->count(), '表单不应被删除');
        $this->assertSame(1, LeadSubmission::withoutGlobalScopes()->count(), '线索不应被删除');

        // 没有提交的表单照常可删（规则只挡有线索的那种）。
        $empty = $this->makeForm($tenant, 'empty-form', $this->basicFields());
        $this->forTenant($tenant)
            ->actingAs($admin, 'sanctum')
            ->deleteJson('/api/v1/lead-forms/'.$empty->id)
            ->assertStatus(200);

        $this->assertNull(LeadForm::withoutGlobalScopes()->find($empty->id));
    }
    // ───────────────────────── 中低项：64KB 上限不能只判 php://input ─────────────────────────

    /**
     * 评审 C4：L7 用 JSON body 验的 64KB 上限，只判 getContent()。真实 multipart/form-data
     * 会被 PHP 解进 $_POST、php://input 读出来是空的 —— 那条路等于没有上限。
     * 这里用「body 小、Content-Length 大」这个 multipart 的特征形态复现，钉住修复后的取大逻辑。
     */
    public function test_c4_oversized_multipart_shaped_request_is_rejected(): void
    {
        $tenant = Tenant::query()->create(['subdomain' => 'tenant-size', 'name' => 'SIZE', 'plan' => 'basic', 'status' => 'active']);
        $this->makeForm($tenant, 'contact', $this->basicFields());

        // 表单字段本身很小（getContent 长度远小于 64KB），但声明的 Content-Length 是 70KB。
        $response = $this->withoutMiddleware(VerifyCsrfToken::class)->call(
            'POST',
            '/forms/tenant-size/contact',
            ['name' => '张三'],
            [], [],
            ['CONTENT_LENGTH' => (string) (70 * 1024), 'HTTP_ACCEPT' => 'application/json'],
        );

        $this->assertSame(422, $response->getStatusCode(), '声明超 64KB 的请求应被早退拒绝');
        $this->assertSame(0, LeadSubmission::withoutGlobalScopes()->count(), '超限请求不得落库');
    }

    /** 正常大小的请求不能被上面的取大逻辑误伤。 */
    public function test_c4_normal_sized_submission_still_passes(): void
    {
        $tenant = Tenant::query()->create(['subdomain' => 'tenant-size2', 'name' => 'SIZE2', 'plan' => 'basic', 'status' => 'active']);
        $this->makeForm($tenant, 'contact', $this->basicFields());

        $this->withoutMiddleware(VerifyCsrfToken::class)
            ->post('/forms/tenant-size2/contact', ['name' => '张三', 'phone' => '13800000015'])
            ->assertStatus(302);

        $this->assertSame(1, LeadSubmission::withoutGlobalScopes()->count());
    }
}
