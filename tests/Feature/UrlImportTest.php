<?php

namespace Tests\Feature;

use App\Jobs\ProcessUrlImportJob;
use App\Models\Article;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\UrlImport;
use App\Models\User;
use App\Services\Content\UrlImportProcessingService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesSafeOutbound;
use Tests\TestCase;

class UrlImportTest extends TestCase
{
    use FakesSafeOutbound;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.tenant_domain' => 'geo.example.test']);
        // 原先这里 config(['geo.url_import.ssrf_guard' => false]) 关掉防护好让 Http::fake 生效。
        // 现在抓取走出站安全链，防护无法关闭（也不该关），改用 fakeSafeOutbound() 打桩假 DNS + 假传输。
    }

    private const SAMPLE_HTML = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>示例文章标题</title></head><body>'
        . '<nav>导航不应出现</nav>'
        . '<article><h1>示例文章标题</h1><p>这是<strong>正文第一段</strong>，含<a href="/rel">相对链接</a>。</p>'
        . '<ul><li>项目一</li><li>项目二</li></ul>'
        . '<blockquote>引用内容</blockquote></article></body></html>';

    public function test_short_real_page_is_imported_with_review_warning_and_empty_page_fails(): void
    {
        [$tenant, $admin, $project] = $this->makeTenantAdminWithProject('tenant-a');
        $this->fakeSafeOutbound('<html><main><p>营业时间：周一至周五。</p></main></html>');
        $short = UrlImport::create([
            'tenant_id' => $tenant->id, 'project_id' => $project->id,
            'url' => 'https://example.com/hours', 'status' => 'pending',
        ]);
        (new ProcessUrlImportJob($tenant->id, $short->id))->handle($this->app->make(UrlImportProcessingService::class));
        $this->assertSame('completed', $short->fresh()->status);
        $this->assertSame('needs_review', $short->fresh()->metadata['extraction_quality']['state']);
        $this->assertStringContainsString('营业时间', $short->fresh()->metadata['extraction_quality']['preview']);

        $this->fakeSafeOutbound('<html><main></main></html>');
        $empty = UrlImport::create([
            'tenant_id' => $tenant->id, 'project_id' => $project->id,
            'url' => 'https://example.com/empty', 'status' => 'pending',
        ]);
        (new ProcessUrlImportJob($tenant->id, $empty->id))->handle($this->app->make(UrlImportProcessingService::class));
        $this->assertSame('failed', $empty->fresh()->status);
        $this->assertNull($empty->fresh()->article_id);
    }

    // ─── 提交 URL → 201 + 派发异步任务 ───
    public function test_admin_submit_creates_pending_import_and_dispatches_job(): void
    {
        Queue::fake();
        [$tenant, $admin, $project] = $this->makeTenantAdminWithProject('tenant-a');

        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->postJson('/api/v1/url-imports', [
                'url' => 'https://example.com/blog/post-1',
                'project_id' => $project->id,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.url', 'https://example.com/blog/post-1');

        $this->assertDatabaseHas('url_imports', [
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'status' => 'pending',
        ]);

        Queue::assertPushed(ProcessUrlImportJob::class, function (ProcessUrlImportJob $job) use ($tenant) {
            $import = UrlImport::where('tenant_id', $tenant->id)->first();

            return $import !== null
                && $job->urlImportId === $import->id
                && $job->tenantId === $tenant->id;
        });
    }

    // ─── 权限：member 不可提交 ───
    public function test_member_cannot_submit_import(): void
    {
        Queue::fake();
        [$tenant, $admin, $project] = $this->makeTenantAdminWithProject('tenant-a');

        $member = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => \Tests\Support\FixtureIdentity::phone('tenant-a_member'),
            'role' => 'member',
        ]);

        Sanctum::actingAs($member, ['*']);

        $this->forTenant($tenant)
            ->postJson('/api/v1/url-imports', [
                'url' => 'https://example.com/blog/post-1',
                'project_id' => $project->id,
            ])
            ->assertStatus(403);
    }

    // ─── 租户隔离：B 看不到 A 的导入 ───
    public function test_import_visible_only_to_own_tenant(): void
    {
        Queue::fake();
        [$tenantA, $adminA, $projectA] = $this->makeTenantAdminWithProject('tenant-a');
        [$tenantB, $adminB] = $this->makeTenantUser('tenant-b', 'admin');

        Sanctum::actingAs($adminA, ['*']);

        $this->forTenant($tenantA)
            ->postJson('/api/v1/url-imports', [
                'url' => 'https://example.com/blog/post-1',
                'project_id' => $projectA->id,
            ])
            ->assertStatus(201);

        $import = UrlImport::where('tenant_id', $tenantA->id)->first();

        Sanctum::actingAs($adminB, ['*']);

        $this->forTenant($tenantB)
            ->getJson('/api/v1/url-imports/' . $import->id)
            ->assertStatus(404);
    }

    // ─── 处理成功：生成草稿文章 ───
    public function test_processing_creates_draft_article(): void
    {
        $this->fakeSafeOutbound(self::SAMPLE_HTML, 200);
        [$tenant, $admin, $project] = $this->makeTenantAdminWithProject('tenant-a');

        $import = UrlImport::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'url' => 'https://example.com/blog/post-1',
            'status' => 'pending',
        ]);

        $job = new ProcessUrlImportJob($tenant->id, $import->id);
        $job->handle($this->app->make(UrlImportProcessingService::class));

        $this->assertDatabaseHas('articles', [
            'project_id' => $project->id,
            'status' => 'draft',
            'source_type' => 'url_import',
        ]);

        $article = Article::where('project_id', $project->id)->first();
        $this->assertNotNull($article);
        $this->assertStringContainsString('# 示例文章标题', $article->body_markdown);
        $this->assertStringContainsString('**正文第一段**', $article->body_markdown);
        $this->assertStringContainsString('- 项目一', $article->body_markdown);
        $this->assertStringContainsString('> 引用内容', $article->body_markdown);
        $this->assertStringContainsString('https://example.com', $article->body_markdown); // 相对链接已绝对化

        $this->assertSame('completed', $import->fresh()->status);
        $this->assertSame($article->id, $import->fresh()->article_id);
        $this->assertDatabaseHas('url_import_job_logs', [
            'url_import_id' => $import->id,
            'level' => 'info',
        ]);
    }

    // ─── 处理失败：标记 failed + 记录日志，不生成文章 ───
    public function test_processing_failure_marks_failed_and_logs(): void
    {
        // 必须真打桩成 500。改造前这里用 Http::fake('',500)，接入安全链后
        // Http::fake 对安全链不生效，测试会因为「连不上外网」而假绿 ——
        // 通过的原因和它要验的东西已经无关了。
        $this->fakeSafeOutbound('', 500);
        [$tenant, $admin, $project] = $this->makeTenantAdminWithProject('tenant-a');

        $import = UrlImport::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'url' => 'https://example.com/blog/post-1',
            'status' => 'pending',
        ]);

        $job = new ProcessUrlImportJob($tenant->id, $import->id);
        $job->handle($this->app->make(UrlImportProcessingService::class));

        $this->assertSame('failed', $import->fresh()->status);
        $this->assertNotNull($import->fresh()->error_message);
        $this->assertDatabaseMissing('articles', ['project_id' => $project->id]);
        $this->assertDatabaseHas('url_import_job_logs', [
            'url_import_id' => $import->id,
            'level' => 'error',
        ]);
    }

    // ─── 跳转：常见的 301/302 要跟得上，但跟进内网仍然要拦 ───
    //
    // 接入安全链后 maxRedirects 默认 0，如果调用处不显式传值，http→https、
    // 裸域→www 这类最普通的跳转会被翻译成「这个网址指向的是内网地址」——
    // 站点完全正常，用户却被指向一个根本不存在的原因。
    public function test_processing_follows_common_redirect(): void
    {
        $this->fakeSafeOutboundSequence([
            ['status' => 301, 'headers' => ['Location' => 'https://www.example.com/blog/post-1']],
            ['status' => 200, 'body' => self::SAMPLE_HTML],
        ]);
        [$tenant, $admin, $project] = $this->makeTenantAdminWithProject('tenant-a');

        $import = UrlImport::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'url' => 'http://example.com/blog/post-1',
            'status' => 'pending',
        ]);

        (new ProcessUrlImportJob($tenant->id, $import->id))
            ->handle($this->app->make(UrlImportProcessingService::class));

        $this->assertSame('completed', $import->fresh()->status, '普通 301 跳转被当成抓取失败了');
        $this->assertOutboundRequestCount(2);
        $this->assertSame('https://www.example.com/blog/post-1', $this->recordedOutboundUrl(1));
    }

    public function test_processing_rejects_redirect_into_intranet(): void
    {
        $this->fakeSafeOutboundSequence([
            ['status' => 302, 'headers' => ['Location' => 'http://169.254.169.254/latest/meta-data/']],
            ['status' => 200, 'body' => self::SAMPLE_HTML],
        ]);
        [$tenant, $admin, $project] = $this->makeTenantAdminWithProject('tenant-a');

        $import = UrlImport::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'url' => 'http://example.com/blog/post-1',
            'status' => 'pending',
        ]);

        (new ProcessUrlImportJob($tenant->id, $import->id))
            ->handle($this->app->make(UrlImportProcessingService::class));

        $this->assertSame('failed', $import->fresh()->status, '跳去云元数据竟然放行了');
        $this->assertStringContainsString('内网地址', (string) $import->fresh()->error_message);
        $this->assertDatabaseMissing('articles', ['project_id' => $project->id]);
        // 只发出了第一跳；跳转目标在发包**之前**就被判掉。
        $this->assertOutboundRequestCount(1);
    }

    /**
     * ─── SSRF 防护：内网/保留地址识别 ───
     *
     * 原来这里直接断言 UrlImportProcessingService::isPrivateOrReservedIp()。那个自建
     * 判定已随接入出站安全链删除（职责移交 SafeOutboundHttpClient），所以改为断言
     * 「URL 导入这条路径上，解析到这些地址的域名会被拦下」——测的是真实拦截效果，
     * 比测一个内部纯函数更接近线上行为。
     *
     * 100.64.0.0/10（CGNAT）是新增用例：原自建判定**放行**这一段（实测
     * FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE 对它返回通过），而云环境的内部服务
     * 常落在这里，等于一个真实可用的 SSRF 缺口。安全链封住了它。
     */
    public function test_ssrf_guard_blocks_private_and_reserved_targets(): void
    {
        $blocked = [
            '127.0.0.1',
            '10.0.0.5',
            '192.168.1.1',
            '172.16.5.5',
            '169.254.0.1',   // 云元数据
            '100.64.0.1',    // CGNAT —— 原自建判定漏掉的那一段
            '::1',
            'fc00::1',
        ];

        foreach ($blocked as $ip) {
            $this->fakeSafeOutbound(self::SAMPLE_HTML, 200, [$ip]);
            [$tenant, , $project] = $this->makeTenantAdminWithProject('t-' . md5($ip));

            $import = UrlImport::create([
                'tenant_id' => $tenant->id,
                'project_id' => $project->id,
                'url' => 'https://attacker-controlled.example.com/',
                'status' => 'pending',
            ]);

            (new ProcessUrlImportJob($tenant->id, $import->id))
                ->handle($this->app->make(UrlImportProcessingService::class));

            $this->assertSame('failed', $import->fresh()->status, "解析到 {$ip} 的域名必须被拦下");
            $this->assertOutboundRequestCount(0);  // 拦在发包之前，不是发出去再看
            $this->assertDatabaseMissing('articles', ['project_id' => $project->id]);
        }
    }

    /** 反向对照：正常公网目标必须能过，否则「零误杀」无从谈起。 */
    public function test_ssrf_guard_allows_public_targets(): void
    {
        $this->fakeSafeOutbound(self::SAMPLE_HTML, 200, ['93.184.216.34']);
        [$tenant, , $project] = $this->makeTenantAdminWithProject('tenant-public');

        $import = UrlImport::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'url' => 'https://example.com/blog/post-1',
            'status' => 'pending',
        ]);

        (new ProcessUrlImportJob($tenant->id, $import->id))
            ->handle($this->app->make(UrlImportProcessingService::class));

        $this->assertSame('completed', $import->fresh()->status);
        $this->assertOutboundRequestCount(1);
        $this->assertOutboundMaxBytes((int) config('geo.outbound_import_max_bytes'));
    }

    // ─── 内部辅助 ───
    private function makeTenantAdminWithProject(string $subdomain): array
    {
        $tenant = Tenant::query()->create([
            'subdomain' => $subdomain,
            'name' => strtoupper($subdomain),
            'plan' => 'basic',
            'status' => 'active',
        ]);

        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain . 'admin'),
            'role' => 'admin',
        ]);

        TenantContext::set($tenant);
        try {
            $project = Project::create([
                'owner_id' => $admin->id,
                'name' => $tenant->name . ' Project',
                'brand_name' => $tenant->name,
                'brand_url' => 'https://' . $subdomain . '.example.test',
                'settings' => [],
            ]);
        } finally {
            TenantContext::forget();
        }

        return [$tenant, $admin, $project];
    }

    private function makeTenantUser(string $subdomain, string $role): array
    {
        $tenant = Tenant::query()->create([
            'subdomain' => $subdomain,
            'name' => strtoupper($subdomain),
            'plan' => 'basic',
            'status' => 'active',
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain . $role),
            'role' => $role,
        ]);

        return [$tenant, $user];
    }

    private function forTenant(Tenant $tenant): self
    {
        return $this
            ->withServerVariables(['HTTP_HOST' => $tenant->subdomain . '.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }
}
