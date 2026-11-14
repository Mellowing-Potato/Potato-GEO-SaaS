<?php

namespace Tests\Feature;

use App\Jobs\DeliverLeadNotificationJob;
use App\Models\LeadForm;
use App\Models\LeadSubmission;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\FakesSafeOutbound;
use Tests\TestCase;

/**
 * 单 8 收尾：新线索通知出口。
 *
 * 背景：模块原本没有任何通知通道，线索落库后没人被告知。邮件和短信两条路在生产
 * 都是死的（MAIL_MAILER=log；四类通知短信已拍板搁置），所以走 webhook。
 *
 * 本类盯住四个真会咬人的点，其余是常规覆盖：
 *   ① 队列名写错 → 任务永远躺在 jobs 表里没人跑，且不报任何错；
 *   ② 地址是凭据（机器人 URL 带 key）→ 接口不许回明文；
 *   ③ 空输入框不能被当成「清空」→ 否则改个表单名就把通知配置抹了；
 *   ④ 地址是租户自己填的 → SSRF 必须真的被拦。
 */
class LeadNotificationTest extends TestCase
{
    use FakesSafeOutbound, RefreshDatabase;

    /**
     * 生产 geo-worker 命令行里写死的监听队列（实查 docker inspect geo-worker）。
     * 派到这七个之外的队列名，任务不会被任何进程消费 —— 而且完全静默。
     */
    private const PRODUCTION_WORKER_QUEUES = [
        'publishing', 'distribution', 'skills', 'tasks', 'url-import', 'knowledge', 'billing',
    ];

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

    private function createTenantAdmin(string $subdomain): array
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
            'role' => 'admin',
        ]);

        return [$tenant, $admin];
    }

    private function makeForm(Tenant $tenant, string $slug, ?string $webhook = null, string $format = 'robot'): LeadForm
    {
        TenantContext::set($tenant);
        try {
            return LeadForm::query()->create([
                'name' => '表单 '.$slug,
                'slug' => $slug,
                'status' => 'active',
                'success_message' => '提交成功',
                'fields' => [
                    ['name' => 'name', 'label' => '姓名', 'type' => 'text', 'required' => true, 'options' => []],
                    ['name' => 'phone', 'label' => '电话', 'type' => 'phone', 'required' => false, 'options' => []],
                ],
                'notify_webhook_url' => $webhook,
                'notify_webhook_format' => $format,
            ]);
        } finally {
            TenantContext::forget();
        }
    }

    private function submit(string $tenantSlug, string $formSlug): TestResponse
    {
        return $this->withoutMiddleware(VerifyCsrfToken::class)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson("/forms/{$tenantSlug}/{$formSlug}", ['name' => '张三', 'phone' => '13800000015']);
    }

    private function forTenant(Tenant $tenant)
    {
        return $this->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }

    // ───────────── ① 队列名：写错就是静默失效 ─────────────

    /**
     * 跑 job 一律走容器解析依赖，不要手写 handle(app(X::class))。
     *
     * ⚠️ 手喂参数 = 测试和生产走两条不同的装配路径：生产由容器注入，
     * 测试由这行硬编码。单 8 就是这么漏的 —— job 里用了 Http facade
     * 而不是 SecureHttpFactory，测试全绿、生产 100% 发不出去。
     * 用 app()->call() 之后，handle() 加参数测试会自动跟上。
     */
    private function runJob(DeliverLeadNotificationJob $job): void
    {
        app()->call([$job, 'handle']);
    }

    public function test_notification_job_is_queued_on_a_queue_production_worker_actually_listens_to(): void
    {
        $job = new DeliverLeadNotificationJob(1, 1);

        $this->assertSame('publishing', $job->queue);
        $this->assertContains(
            $job->queue,
            self::PRODUCTION_WORKER_QUEUES,
            '通知任务派到了生产 worker 不监听的队列 —— 任务会永远躺在 jobs 表里，没人跑，也不报错。',
        );
    }

    // ───────────── 派发与跳过 ─────────────

    public function test_submission_queues_notification_when_webhook_configured(): void
    {
        Queue::fake();
        [$tenant] = $this->createTenantAdmin('tenant-n1');
        $form = $this->makeForm($tenant, 'contact', 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=SECRET');

        $this->submit('tenant-n1', 'contact')->assertStatus(200);

        $submission = LeadSubmission::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(LeadSubmission::NOTIFY_QUEUED, $submission->notify_status);
        $this->assertNull($submission->notified_at, '还没出结果，不该有终态时间。');

        Queue::assertPushed(
            DeliverLeadNotificationJob::class,
            fn (DeliverLeadNotificationJob $job): bool => $job->submissionId === $submission->id
                && $job->tenantId === $tenant->id,
        );
        $this->assertSame($form->id, $submission->lead_form_id);
    }

    public function test_submission_does_not_queue_notification_when_webhook_absent(): void
    {
        Queue::fake();
        [$tenant] = $this->createTenantAdmin('tenant-n2');
        $this->makeForm($tenant, 'contact');

        $this->submit('tenant-n2', 'contact')->assertStatus(200);

        Queue::assertNothingPushed();
        $submission = LeadSubmission::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(LeadSubmission::NOTIFY_SKIPPED, $submission->notify_status);
        $this->assertNotNull($submission->notified_at, '不需要通知也是终态，应有时间戳。');
    }

    // ───────────── 投递结果 ─────────────

    public function test_job_marks_sent_after_successful_delivery(): void
    {
        $this->fakeSafeOutbound('ok', 200);
        [$tenant] = $this->createTenantAdmin('tenant-n3');
        $form = $this->makeForm($tenant, 'contact', 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=K');
        $submission = $this->storeSubmission($tenant, $form);

        $this->runJob(new DeliverLeadNotificationJob($submission->id, $tenant->id));

        $submission->refresh();
        $this->assertSame(LeadSubmission::NOTIFY_SENT, $submission->notify_status);
        $this->assertNull($submission->notify_error);
        $this->assertNotNull($submission->notified_at);
        $this->assertOutboundRequestCount(1);
    }

    public function test_job_marks_failed_without_retry_on_client_error(): void
    {
        // 4xx＝地址写错 / 机器人被删，重试没有意义，必须直接落终态。
        $this->fakeSafeOutbound('{"errcode":93000}', 400);
        [$tenant] = $this->createTenantAdmin('tenant-n4');
        $form = $this->makeForm($tenant, 'contact', 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=K');
        $submission = $this->storeSubmission($tenant, $form);

        $this->runJob(new DeliverLeadNotificationJob($submission->id, $tenant->id));

        $submission->refresh();
        $this->assertSame(LeadSubmission::NOTIFY_FAILED, $submission->notify_status);
        $this->assertStringContainsString('400', (string) $submission->notify_error);
    }

    // ───────────── ④ SSRF：判定逻辑没被打桩，是真判定 ─────────────

    public function test_job_is_blocked_when_webhook_resolves_to_intranet(): void
    {
        // 假 DNS 让公网域名解析到内网地址 —— 这正是 DNS 重绑定的形状。
        $this->fakeSafeOutbound('ok', 200, ['10.0.0.7']);
        [$tenant] = $this->createTenantAdmin('tenant-n5');
        $form = $this->makeForm($tenant, 'contact', 'https://looks-public.example.com/hook');
        $submission = $this->storeSubmission($tenant, $form);

        $this->runJob(new DeliverLeadNotificationJob($submission->id, $tenant->id));

        $submission->refresh();
        $this->assertSame(LeadSubmission::NOTIFY_FAILED, $submission->notify_status);
        $this->assertStringContainsString('内网', (string) $submission->notify_error);
        $this->assertOutboundRequestCount(0);
    }

    // ───────────── 报文形状 ─────────────

    public function test_robot_payload_matches_wecom_and_dingtalk_contract(): void
    {
        [$tenant] = $this->createTenantAdmin('tenant-n6');
        $form = $this->makeForm($tenant, 'contact', 'https://qyapi.weixin.qq.com/x?key=K');
        $submission = $this->storeSubmission($tenant, $form);

        $payload = $this->callPrivate('robotPayload', [$form, $submission]);

        // 企业微信与钉钉的文本消息形状一致，这三个键缺一不可。
        $this->assertSame('text', $payload['msgtype']);
        $this->assertArrayHasKey('content', $payload['text']);
        // 键换成表单里配置的中文标签，而不是原始字段名。
        $this->assertStringContainsString('姓名：张三', $payload['text']['content']);
        $this->assertStringContainsString('电话：13800000015', $payload['text']['content']);
        // 不夹带多余顶层字段：群机器人对未知键是否宽容没有文档保证，猜错就是通知全线不通。
        $this->assertSame(['msgtype', 'text'], array_keys($payload));
    }

    public function test_json_payload_is_structured_for_self_hosted_receivers(): void
    {
        [$tenant] = $this->createTenantAdmin('tenant-n7');
        $form = $this->makeForm($tenant, 'contact', 'https://hooks.example.com/x', 'json');
        $submission = $this->storeSubmission($tenant, $form);

        $payload = $this->callPrivate('jsonPayload', [$tenant, $form, $submission]);

        $this->assertSame('lead.created', $payload['event']);
        $this->assertSame($tenant->subdomain, $payload['tenant']['subdomain']);
        $this->assertSame($submission->id, $payload['submission']['id']);
        $this->assertSame('张三', $payload['submission']['fields']['姓名']);
    }

    // ───────────── ② 掩码：地址是凭据 ─────────────

    public function test_api_never_returns_the_raw_webhook_url(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('tenant-n8');
        $secret = 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=SUPER-SECRET-KEY';
        $form = $this->makeForm($tenant, 'contact', $secret);

        $body = $this->actingAs($admin, 'sanctum')->forTenant($tenant)
            ->getJson("/api/v1/lead-forms/{$form->id}")
            ->assertStatus(200)
            ->json();

        $raw = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('SUPER-SECRET-KEY', (string) $raw, '机器人地址里的 key 泄露到了接口响应里。');
        $this->assertStringNotContainsString('key=', (string) $raw);
        $this->assertTrue($body['data']['notify_webhook_configured']);
        $this->assertStringContainsString('qyapi.weixin.qq.com', $body['data']['notify_webhook_masked']);
    }

    // ───────────── ③ 空输入框 ≠ 清空 ─────────────

    public function test_update_without_the_key_keeps_the_existing_webhook(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('tenant-n9');
        $secret = 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=KEEP-ME';
        $form = $this->makeForm($tenant, 'contact', $secret);

        // 只改表单名，请求里根本没有 notify_webhook_url 这个键。
        $this->actingAs($admin, 'sanctum')->forTenant($tenant)
            ->putJson("/api/v1/lead-forms/{$form->id}", [
                'name' => '改了个名字',
                'status' => 'active',
                'fields' => [['name' => 'name', 'label' => '姓名', 'type' => 'text', 'required' => true, 'options' => []]],
            ])
            ->assertStatus(200);

        $this->assertSame(
            $secret,
            LeadForm::withoutGlobalScopes()->find($form->id)->notify_webhook_url,
            '改表单名把通知地址清掉了 —— 这是数据丢失，不是「保存」。',
        );
    }

    public function test_update_with_empty_string_clears_the_webhook(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('tenant-n10');
        $form = $this->makeForm($tenant, 'contact', 'https://qyapi.weixin.qq.com/x?key=K');

        $this->actingAs($admin, 'sanctum')->forTenant($tenant)
            ->putJson("/api/v1/lead-forms/{$form->id}", [
                'name' => '表单 contact',
                'status' => 'active',
                'notify_webhook_url' => '',
                'fields' => [['name' => 'name', 'label' => '姓名', 'type' => 'text', 'required' => true, 'options' => []]],
            ])
            ->assertStatus(200);

        $this->assertNull(LeadForm::withoutGlobalScopes()->find($form->id)->notify_webhook_url);
    }

    // ───────────── 校验 ─────────────

    public function test_plain_http_webhook_is_rejected(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('tenant-n11');

        // 线索里是客户姓名和电话，明文过网等于把这些摊开。
        $this->actingAs($admin, 'sanctum')->forTenant($tenant)
            ->postJson('/api/v1/lead-forms', [
                'name' => '表单',
                'status' => 'active',
                'notify_webhook_url' => 'http://qyapi.weixin.qq.com/x?key=K',
                'fields' => [['name' => 'name', 'label' => '姓名', 'type' => 'text', 'required' => true, 'options' => []]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['notify_webhook_url']);
    }

    public function test_unknown_notify_format_is_rejected(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('tenant-n12');

        $this->actingAs($admin, 'sanctum')->forTenant($tenant)
            ->postJson('/api/v1/lead-forms', [
                'name' => '表单',
                'status' => 'active',
                'notify_webhook_format' => 'sms',
                'fields' => [['name' => 'name', 'label' => '姓名', 'type' => 'text', 'required' => true, 'options' => []]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['notify_webhook_format']);
    }

    // ───────────── 跨租户 ─────────────

    public function test_job_will_not_touch_a_submission_from_another_tenant(): void
    {
        $this->fakeSafeOutbound('ok', 200);
        [$tenantA] = $this->createTenantAdmin('tenant-n13');
        [$tenantB] = $this->createTenantAdmin('tenant-n14');
        $formA = $this->makeForm($tenantA, 'contact', 'https://qyapi.weixin.qq.com/x?key=K');
        $submissionA = $this->storeSubmission($tenantA, $formA);

        // 拿 B 租户的 id 去跑 A 的线索：查不到，不该发出任何请求。
        $this->runJob(new DeliverLeadNotificationJob($submissionA->id, $tenantB->id));

        $this->assertOutboundRequestCount(0);
        $this->assertSame(LeadSubmission::NOTIFY_QUEUED, $submissionA->refresh()->notify_status);
    }

    // ───────────── 内部辅助 ─────────────

    private function storeSubmission(Tenant $tenant, LeadForm $form): LeadSubmission
    {
        TenantContext::set($tenant);
        try {
            return LeadSubmission::query()->create([
                'tenant_id' => $tenant->id,
                'lead_form_id' => $form->id,
                'status' => LeadSubmission::STATUS_NEW,
                'payload' => ['name' => '张三', 'phone' => '13800000015'],
                'source_url' => 'https://example.com/p',
                'ip_address' => '203.0.113.10',
                'notify_status' => LeadSubmission::NOTIFY_QUEUED,
            ]);
        } finally {
            TenantContext::forget();
        }
    }

    /** 报文组装是私有方法（外部不该调），但形状必须锁死 —— 用反射直接验。 */
    private function callPrivate(string $method, array $args): array
    {
        $job = new DeliverLeadNotificationJob(0, 0);
        $ref = new \ReflectionMethod($job, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($job, $args);
    }
}
