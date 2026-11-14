<?php

namespace Tests\Feature;

use App\Models\LeadForm;
use App\Models\LeadSubmission;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Lead\LeadFormFields;
use App\Support\Permissions;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 线索表单模块验收（单 8）。
 *
 * 覆盖执行书 §六 判据 L1–L9（公开表单 + 跨租户 + 限流 + 上下文清理 + 字段丢弃 + 体积上限 + XSS 转义 + 复合唯一）。
 * 公开路由按 config('geo.lead_form_enabled') 在 boot 时注册（默认 false），本类在
 * getEnvironmentSetUp 把开关打开；L10（关 → 404）在单独的 LeadFormSwitchOffTest 里验证。
 *
 * 两件事必须强调（评审重点）：
 *  - L2 跨租户 404 必须有自动化测试（执行书 §六「L2 是重点，不过本单不通过」）；
 *  - 限流用生产默认值（IP 5/min + 表单 60/min），不临时改小，确保验的是真实机制。
 */
class LeadFormModuleTest extends TestCase
{
    use RefreshDatabase;

    private const LIMITER = 'lead-form-submit';

    protected function setUp(): void
    {
        parent::setUp();

        // 公开路由在 boot 时按 config 注册；本类用 createApplication 覆盖在 boot 前把开关打开，
        // 然后 refreshApplication 重建 app（in-memory sqlite 被清掉，需重 migrate）。
        $this->refreshApplication();
        $this->artisan('migrate', ['--force' => true]);
        // 限流计数走 array 缓存（phpunit.xml CACHE_STORE=array），每测试清空避免跨测试累积。
        Cache::flush();
        config(['app.tenant_domain' => 'geo.example.test']);
    }

    /**
     * 本版本 Laravel 的 createApplication() 在返回前就 bootstrap 了内核（路由在此时加载），
     * 而框架并不调用 getEnvironmentSetUp —— 所以开关必须在 boot 之前、用 env 落好，
     * 否则 routes/web.php 的 switch-gated 块读到的永远是默认 false，/forms/* 永不注册。
     * 这里在每次 boot（含 refreshApplication）前把总开关打开。
     */
    public function createApplication()
    {
        putenv('GEO_LEAD_FORM_ENABLED=true');
        $_ENV['GEO_LEAD_FORM_ENABLED'] = 'true';
        $_SERVER['GEO_LEAD_FORM_ENABLED'] = 'true';

        return parent::createApplication();
    }

    // ───────────────────────── 辅助 ─────────────────────────

    private function createTenantAdmin(string $subdomain, string $role = 'admin'): array
    {
        $tenant = Tenant::query()->create([
            'subdomain' => $subdomain,
            'name' => strtoupper($subdomain),
            'plan' => 'basic',
            'status' => 'active',
        ]);

        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain),
            'role' => $role,
        ]);

        return [$tenant, $admin];
    }

    /**
     * 在指定租户下建一个表单（BelongsToTenant 自动填 tenant_id）。
     */
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

    // ───────────────────────── L1：提交落到正确租户 ─────────────────────────

    public function test_l1_submission_is_attributed_to_the_correct_tenant(): void
    {
        [$tenantA, $adminA] = $this->createTenantAdmin('tenant-a');
        $form = $this->makeForm($tenantA, 'contact', $this->basicFields());

        $this->withoutMiddleware(VerifyCsrfToken::class)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson('/forms/tenant-a/contact', [
                'name' => '张三',
                'phone' => '13800000015',
            ])
            ->assertStatus(200)
            ->assertJson(['ok' => true]);

        $this->assertSame(1, LeadSubmission::query()->count());
        $submission = LeadSubmission::query()->firstOrFail();
        $this->assertSame($tenantA->id, (int) $submission->tenant_id);
        $this->assertSame($form->id, (int) $submission->lead_form_id);
        $this->assertSame('张三', $submission->payload['name']);
    }

    // ───────────────────────── L2：跨租户访问返回 404（重点）─────────────────────────

    public function test_l2_cross_tenant_form_returns_404_not_leaked(): void
    {
        [$tenantA, $adminA] = $this->createTenantAdmin('tenant-a');
        [$tenantB, $adminB] = $this->createTenantAdmin('tenant-b');

        // A 只有 alfa，B 只有 contact（不同 slug，证明「A 的链接打不开 B 的表单」）。
        $this->makeForm($tenantA, 'alfa', $this->basicFields());
        $this->makeForm($tenantB, 'contact', $this->basicFields());

        // 正确归属：各自能打开自己的。
        $this->get('/forms/tenant-a/alfa')->assertStatus(200);
        $this->get('/forms/tenant-b/contact')->assertStatus(200);

        // 跨租户泄漏必须被挡：用 A 的 subdomain 打 B 的 contact → 404；反之同理。
        $this->get('/forms/tenant-a/contact')->assertStatus(404);
        $this->get('/forms/tenant-b/alfa')->assertStatus(404);

        // 404 时绝不能落任何提交记录（没有「查全表」误建）。
        $this->assertSame(0, LeadSubmission::query()->count());
    }

    // ───────────────────────── L3：限流双维度（IP + 表单）─────────────────────────

    public function test_l3_ip_rate_limit_returns_429(): void
    {
        [$tenantA] = $this->createTenantAdmin('tenant-a');
        $this->makeForm($tenantA, 'rate-ip', $this->basicFields());

        // 同一 IP 连打 6 次（阈值 5/min）→ 第 6 次 429。
        for ($i = 1; $i <= 6; $i++) {
            $response = $this->withoutMiddleware(VerifyCsrfToken::class)
                ->withServerVariables(['REMOTE_ADDR' => '203.0.113.200'])
                ->postJson('/forms/tenant-a/rate-ip', ['name' => 'u'.$i]);
            $response->assertStatus($i <= 5 ? 200 : 429);
        }
    }

    public function test_l3_form_rate_limit_returns_429(): void
    {
        // 冻结时钟：表单维度的限流窗口是 60 秒，本用例要连打 61 次才撞得到阈值（60/min）。
        // 不冻就是在跟墙钟赛跑——机器一慢，最后几次落到窗口外、计数被重置，
        // 第 61 次拿到 200 而不是 429，测试偶发红。红的是机器快慢，不是业务代码。
        // 这行不是可有可无的样板，删掉 flake 就回来。
        $this->freezeTime();

        [$tenantA] = $this->createTenantAdmin('tenant-a');
        $this->makeForm($tenantA, 'rate-form', $this->basicFields());

        // 同一表单、61 个不同 IP（阈值 60/min）→ 第 61 次 429（IP 维度不会先触发）。
        for ($i = 1; $i <= 61; $i++) {
            $ip = '198.51.100.'.($i % 254 + 1);
            $response = $this->withoutMiddleware(VerifyCsrfToken::class)
                ->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/forms/tenant-a/rate-form', ['name' => 'u'.$i]);
            $response->assertStatus($i <= 60 ? 200 : 429);
        }
    }

    // ───────────────────────── L4：提交后上下文清理（连打不同租户不污染）─────────────────────────

    public function test_l4_tenant_context_is_cleaned_after_submit_no_cross_pollution(): void
    {
        [$tenantA] = $this->createTenantAdmin('tenant-a');
        [$tenantB] = $this->createTenantAdmin('tenant-b');
        $formA = $this->makeForm($tenantA, 'contact', $this->basicFields());
        $formB = $this->makeForm($tenantB, 'contact', $this->basicFields());

        // 第一次打 A 的表单。
        $this->withoutMiddleware(VerifyCsrfToken::class)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.31'])
            ->postJson('/forms/tenant-a/contact', ['name' => 'A客户'])
            ->assertStatus(200);
        // 提交后上下文必须已清理（FrankenPHP 常驻进程，不清理会污染下一请求）。
        $this->assertFalse(TenantContext::has(), '提交后 TenantContext 未清理');

        // 第二次打 B 的表单，必须不受第一次污染。
        $this->withoutMiddleware(VerifyCsrfToken::class)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.32'])
            ->postJson('/forms/tenant-b/contact', ['name' => 'B客户'])
            ->assertStatus(200);
        $this->assertFalse(TenantContext::has(), '第二次提交后 TenantContext 未清理');

        // 两条提交各自归属正确。
        $this->assertSame(2, LeadSubmission::query()->count());
        $this->assertSame(
            $tenantA->id,
            (int) LeadSubmission::query()->where('lead_form_id', $formA->id)->firstOrFail()->tenant_id,
        );
        $this->assertSame(
            $tenantB->id,
            (int) LeadSubmission::query()->where('lead_form_id', $formB->id)->firstOrFail()->tenant_id,
        );
    }

    // ───────────────────────── L5：异常路径（校验失败）后上下文也清理 ─────────────────────────

    public function test_l5_context_cleaned_after_validation_failure(): void
    {
        [$tenantA] = $this->createTenantAdmin('tenant-a');
        $this->makeForm($tenantA, 'contact', $this->basicFields());

        // name 是必填，故意留空 → 422。
        $this->withoutMiddleware(VerifyCsrfToken::class)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.41'])
            ->postJson('/forms/tenant-a/contact', ['phone' => '13800000015'])
            ->assertStatus(422);

        $this->assertFalse(TenantContext::has(), '校验失败后 TenantContext 未清理');
        $this->assertSame(0, LeadSubmission::query()->count(), '校验失败不应落库');

        // 异常路径清理后，再提交一次仍正确归属。
        $this->withoutMiddleware(VerifyCsrfToken::class)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
            ->postJson('/forms/tenant-a/contact', ['name' => '恢复'])
            ->assertStatus(200);
        $this->assertSame($tenantA->id, (int) LeadSubmission::query()->firstOrFail()->tenant_id);
    }

    // ───────────────────────── L6：声明之外的字段被丢弃 ─────────────────────────

    public function test_l6_undeclared_fields_are_dropped(): void
    {
        [$tenantA] = $this->createTenantAdmin('tenant-a');
        $this->makeForm($tenantA, 'contact', $this->basicFields());

        $this->withoutMiddleware(VerifyCsrfToken::class)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.51'])
            ->postJson('/forms/tenant-a/contact', [
                'name' => '张三',
                'phone' => '13800000015',
                'secret' => 'test-fake-2dc6d8d6c6',
            ])
            ->assertStatus(200);

        $payload = LeadSubmission::query()->firstOrFail()->payload;
        $this->assertArrayHasKey('name', $payload);
        $this->assertArrayHasKey('phone', $payload);
        $this->assertArrayNotHasKey('secret', $payload, '声明之外的字段被存库了');
    }

    // ───────────────────────── L7：payload 超 64KB → 422 ─────────────────────────

    public function test_l7_oversized_payload_returns_422(): void
    {
        [$tenantA] = $this->createTenantAdmin('tenant-a');
        $this->makeForm($tenantA, 'contact', $this->basicFields());

        $huge = str_repeat('x', 70 * 1024); // 70KB > 64KB 上限
        $this->withoutMiddleware(VerifyCsrfToken::class)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.61'])
            ->postJson('/forms/tenant-a/contact', ['name' => $huge])
            ->assertStatus(422);

        $this->assertSame(0, LeadSubmission::query()->count(), '超大 payload 不应落库');
    }

    // ───────────────────────── L8：表单文案 <script> 被转义 ─────────────────────────

    public function test_l8_tenant_script_in_copy_is_escaped_in_public_page(): void
    {
        [$tenantA] = $this->createTenantAdmin('tenant-a');
        $this->makeForm($tenantA, 'contact', $this->basicFields(), 'active');

        // 直接改文案注入脚本（模拟租户自己填的内容）。
        $form = LeadForm::query()->where('slug', 'contact')->where('tenant_id', $tenantA->id)->firstOrFail();
        TenantContext::set($tenantA);
        try {
            $form->update([
                'name' => '<script>alert(1)</script>',
                'description' => '<b>加粗</b>',
                'submit_button_label' => '<script>evil()</script>',
            ]);
        } finally {
            TenantContext::forget();
        }

        $html = $this->get('/forms/tenant-a/contact')->assertStatus(200)->getContent();

        // 转义后的实体必须出现，原始标签不能出现（XSS 漏出 = 失败）。
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<script>evil()</script>', $html);
        $this->assertStringNotContainsString('<b>加粗</b>', $html);
    }

    // ───────────────────────── L9：同 slug 两租户都能建（复合唯一约束）─────────────────────────

    public function test_l9_same_slug_allowed_across_tenants(): void
    {
        [$tenantA] = $this->createTenantAdmin('tenant-a');
        [$tenantB] = $this->createTenantAdmin('tenant-b');

        $formA = $this->makeForm($tenantA, 'contact', $this->basicFields());
        $formB = $this->makeForm($tenantB, 'contact', $this->basicFields());

        $this->assertNotNull($formA->id);
        $this->assertNotNull($formB->id);
        $this->assertSame(
            $tenantA->id,
            (int) LeadForm::query()->where('slug', 'contact')->where('tenant_id', $tenantA->id)->firstOrFail()->tenant_id,
        );
        $this->assertSame(
            $tenantB->id,
            (int) LeadForm::query()->where('slug', 'contact')->where('tenant_id', $tenantB->id)->firstOrFail()->tenant_id,
        );
        // 全局唯一会在这里直接抛错；复合唯一下两条都应存在。
        $this->assertSame(2, LeadForm::query()->where('slug', 'contact')->count());
    }

    // ───────────────────────── 管理端 API 冒烟（can:lead:manage 门 + 租户隔离）─────────────────────────

    public function test_admin_can_create_and_list_forms(): void
    {
        [$tenantA, $adminA] = $this->createTenantAdmin('tenant-a', 'admin');
        $this->makeForm($tenantA, 'contact', $this->basicFields());

        $this->forTenant($tenantA)
            ->actingAs($adminA, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/lead-forms', [
                'name' => '新表单',
                'slug' => 'new-form',
                'status' => 'active',
                'fields' => LeadFormFields::defaultFields(),
            ])
            ->assertStatus(201)
            ->assertJsonStructure(['data' => ['id', 'slug']]);

        $this->forTenant($tenantA)
            ->actingAs($adminA, 'sanctum')
            ->getJson('/api/v1/lead-forms')
            ->assertStatus(200)
            ->assertJsonStructure(['data', 'meta', 'stats']);
    }

    public function test_missing_form_name_returns_a_clear_chinese_validation_message(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('tenant-form-validation', 'admin');

        $this->forTenant($tenant)
            ->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/lead-forms', [
                'name' => '',
                'status' => 'active',
                'fields' => LeadFormFields::defaultFields(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', '请填写表单名称。');
    }

    public function test_member_is_forbidden_from_lead_admin(): void
    {
        [$tenantA, $member] = $this->createTenantAdmin('tenant-a', 'member');

        $this->forTenant($tenantA)
            ->actingAs($member, 'sanctum')
            ->getJson('/api/v1/lead-forms')
            ->assertStatus(403);

        // 权限矩阵应列出 lead:manage 仅限 admin/editor。
        $this->assertTrue(Permissions::allows('admin', 'lead:manage'));
        $this->assertTrue(Permissions::allows('editor', 'lead:manage'));
        $this->assertFalse(Permissions::allows('member', 'lead:manage'));
    }

    public function test_analytics_endpoint_returns_summary_for_tenant(): void
    {
        [$tenantA, $adminA] = $this->createTenantAdmin('tenant-a', 'admin');
        $form = $this->makeForm($tenantA, 'contact', $this->basicFields());
        TenantContext::set($tenantA);
        try {
            LeadSubmission::query()->create([
                'tenant_id' => $tenantA->id,
                'lead_form_id' => $form->id,
                'status' => LeadSubmission::STATUS_CONVERTED,
                'payload' => ['name' => 'x'],
            ]);
        } finally {
            TenantContext::forget();
        }

        $this->forTenant($tenantA)
            ->actingAs($adminA, 'sanctum')
            ->getJson('/api/v1/lead-analytics')
            ->assertStatus(200)
            ->assertJsonStructure(['summary' => ['kpis' => ['submissions', 'converted', 'conversion_rate']]]);
    }
}
