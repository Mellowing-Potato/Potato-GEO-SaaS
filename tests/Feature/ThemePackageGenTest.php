<?php

namespace Tests\Feature;

use App\Contracts\Outbound\HostResolver;
use App\Jobs\IterateSiteThemeReplicationJob;
use App\Jobs\RunSiteThemeReplicationJob;
use App\Models\SiteThemeReplication;
use App\Models\SiteThemeReplicationVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Services\Outbound\SecureHttpFactory;
use App\Services\ThemePackaging\SiteThemeReplicationService;
use App\Services\ThemePackaging\ThemePackagePaths;
use App\Services\ThemePackaging\ThemeReferenceFetcher;
use App\Services\ThemePackaging\ThemeReplicationPackagePathGuard;
use App\Services\ThemePackaging\ThemeReplicationPipelineService;
use App\Support\TenantContext;
use App\Support\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use ReflectionProperty;
use RuntimeException;
use Tests\Concerns\FakesSafeOutbound;
use Tests\TestCase;
use ZipArchive;

/**
 * 单 10 · 主题包生成 · 验收测试（执行书 §五 V1–V7、V9；V8 在 ThemePackageSwitchOffTest；V10 走 pg16 手动核验）。
 *
 * 总开关在本类 createApplication() 里于 boot 前设为 true（与 LeadFormModuleTest 同构），
 * 路由才注册。测试环境：phpunit.xml 锁 sqlite :memory:，QUEUE_CONNECTION=sync（Job 同步跑，
 * 便于验证 Job 内的租户上下文）。
 *
 * ⚠️ 每次测试的 POST/DELETE 都带 X-Idempotency-Key，避免幂等中间件回放串味。
 * ⚠️ 主题包生成链路不接 AI、不抓外网（执行书 §〇-3），但 RunSiteThemeReplicationJob 真实会跑
 *    ThemeReplicationPipelineService；测试里把它 bind 成 no-op，避免真实抓外网 / 生成，只验「Job
 *    在跑 pipeline 之前设置了租户上下文」（V2）。
 */
class ThemePackageGenTest extends TestCase
{
    use FakesSafeOutbound, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 路由在 boot 时按 config 注册；refreshApplication 重建 app（in-memory sqlite 被清，需重 migrate）。
        $this->refreshApplication();
        $this->artisan('migrate', ['--force' => true]);
        config(['app.tenant_domain' => 'geo.example.test']);
        Cache::flush();
        // 本类会真写盘（草稿 / zip）。不用假盘的话产物会留在 storage/app/private 里，
        // 跑完一次测试就多一堆没人清的垃圾。假盘也是真实文件系统，
        // StorageGuard 的 realpath / fopen / rename 一样能跑。
        Storage::fake('local');
        $this->bindNoopPipeline();
    }

    public function createApplication()
    {
        putenv('GEO_THEME_PACKAGE_ENABLED=true');
        $_ENV['GEO_THEME_PACKAGE_ENABLED'] = 'true';
        $_SERVER['GEO_THEME_PACKAGE_ENABLED'] = 'true';

        return parent::createApplication();
    }

    protected function tearDown(): void
    {
        // V7 给模型注册了 throwing 的 deleting 监听器，必须清掉否则污染后续测试。
        SiteThemeReplication::flushEventListeners();
        Mockery::close();

        parent::tearDown();
    }

    // ───────────────────────── 辅助 ─────────────────────────

    private function bindNoopPipeline(): void
    {
        $mock = Mockery::mock(ThemeReplicationPipelineService::class);
        $mock->shouldIgnoreMissing();
        app()->instance(ThemeReplicationPipelineService::class, $mock);
    }

    private function tenantAdmin(string $subdomain, string $role = 'admin'): array
    {
        $tenant = Tenant::query()->create([
            'subdomain' => $subdomain,
            'name' => strtoupper($subdomain),
            'plan' => 'basic',
            'status' => 'active',
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain),
            'role' => $role,
        ]);

        return [$tenant, $user];
    }

    private function forTenant(Tenant $tenant)
    {
        return $this->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }

    /**
     * 在指定租户下直接建一条记录（绕过 Service::create 的配额检查与 Job 派发，
     * 纯 DB 行，用于隔离 / 清理 / 版本等判据）。BelongsToTenant 的 creating hook 自动填 tenant_id。
     */
    private function makeReplication(Tenant $tenant, array $attrs = []): SiteThemeReplication
    {
        TenantContext::set($tenant);
        try {
            return SiteThemeReplication::query()->create(array_merge([
                'name' => 'tp',
                'theme_id' => 'theme-'.$tenant->id,
                'status' => SiteThemeReplication::STATUS_READY,
                'home_url' => 'https://example.com/',
                'category_url' => 'https://example.com/c',
                'article_url' => 'https://example.com/a',
                'style_preference' => 'content_site',
                'compliance_status' => 'passed',
                'current_version' => 1,
            ], $attrs));
        } finally {
            TenantContext::forget();
        }
    }

    private function idemHeader(string $key): array
    {
        return ['X-Idempotency-Key' => $key];
    }

    // ───────────────────────── V1：跨租户隔离（验收重点）─────────────────────────

    public function test_v1_cross_tenant_isolation(): void
    {
        [$tenantA, $userA] = $this->tenantAdmin('tp-a');
        [$tenantB, $userB] = $this->tenantAdmin('tp-b');

        $repA = $this->makeReplication($tenantA, ['theme_id' => 'alpha']);
        $repB = $this->makeReplication($tenantB, ['theme_id' => 'beta']);

        // 以租户 B 身份访问
        Sanctum::actingAs($userB);
        $this->forTenant($tenantB);

        // 列表：只看得见本租户的
        $list = $this->getJson('/api/v1/theme-packages')->json('data');
        $this->assertCount(1, $list);
        $this->assertSame($repB->id, $list[0]['id']);

        // 详情 / 状态 / 重试 / 删除 / 下载：A 的 → 403（显式归属校验，执行书 V1）
        $this->getJson("/api/v1/theme-packages/{$repA->id}")->assertStatus(403);
        $this->getJson("/api/v1/theme-packages/{$repA->id}/status")->assertStatus(403);
        $this->postJson("/api/v1/theme-packages/{$repA->id}/retry", [], $this->idemHeader('r1'))->assertStatus(403);
        $this->deleteJson("/api/v1/theme-packages/{$repA->id}", [], $this->idemHeader('d1'))->assertStatus(403);
        $this->getJson("/api/v1/theme-packages/{$repA->id}/download")->assertStatus(403);

        // 预览（web 路由）：A 的 → 403
        $this->get("/theme-preview/{$repA->id}/home")->assertStatus(403);

        // 自己的详情 → 200，确认 403 不是「模块坏了」而是「真拦了」
        $this->getJson("/api/v1/theme-packages/{$repB->id}")->assertStatus(200);

        // 跨租户访问绝不能落任何库行
        $this->assertSame(1, (int) SiteThemeReplication::query()->where('tenant_id', $tenantB->id)->count());
        $this->assertSame(1, (int) SiteThemeReplication::query()->where('tenant_id', $tenantA->id)->count());
    }

    // ───────────────────────── V2：Job 内租户上下文（fail-open 坑）─────────────────────────

    public function test_v2_job_sets_tenant_context_before_pipeline(): void
    {
        [$tenantA, $userA] = $this->tenantAdmin('tp-ja');
        $rep = $this->makeReplication($tenantA);

        // 严格 mock：pipeline 跑的时候，TenantContext 必须是租户 A，否则断言失败。
        $triggered = false;
        $pipeline = Mockery::mock(ThemeReplicationPipelineService::class);
        $pipeline->shouldReceive('run')->once()->andReturnUsing(function (int $id) use ($tenantA, $rep, &$triggered): void {
            $triggered = true;
            $this->assertSame(
                $tenantA->id,
                TenantContext::id(),
                'pipeline 在 tenant 上下文就位前就跑了 —— 会跨租户读写（执行书红线 2）',
            );
            $this->assertSame($rep->id, $id);
        });
        app()->instance(ThemeReplicationPipelineService::class, $pipeline);

        // QUEUE_CONNECTION=sync，dispatch 即同步执行 handle()。
        RunSiteThemeReplicationJob::dispatch($rep->id, $tenantA->id);

        $this->assertTrue($triggered, 'pipeline 没有被调用');
        // 执行完 Job 应已清理上下文
        $this->assertNull(TenantContext::id());
    }

    // ───────────────────────── V3：路径穿越守卫 ─────────────────────────

    public function test_v3_path_traversal_guard_rejects_escape(): void
    {
        $guard = new ThemeReplicationPackagePathGuard;

        // 路径段里的 ../ 必须被拒（防目录逃逸）
        foreach (['../../etc', '../foo', '..\\..\\win', '/abs/path', 'C:\\windows'] as $bad) {
            try {
                $guard->positiveInteger($bad);
                $this->fail("positiveInteger 接受了危险输入：{$bad}");
            } catch (RuntimeException) {
                // 期望抛 RuntimeException
            }
        }

        // assertSafeRelativePath：段里出现 .. 即拒
        foreach (['../escape', 'a/../../b', '..'] as $badPath) {
            try {
                $guard->assertSafeRelativePath($badPath);
                $this->fail("assertSafeRelativePath 接受了危险路径：{$badPath}");
            } catch (RuntimeException) {
                // 期望
            }
        }

        // 合法的正整数 / 相对路径必须放行
        $this->assertSame(42, $guard->positiveInteger('42'));
        $this->assertSame(7, $guard->positiveInteger(7));
        $guard->assertSafeRelativePath('draft/1/views/home.blade.php');

        // 租户段只能是正整数
        try {
            $guard->validatedTenantId(-1);
            $this->fail('validatedTenantId 接受了负数');
        } catch (RuntimeException) {
            // 期望
        }
        $this->assertSame(5, $guard->validatedTenantId('5'));
    }

    // ───────────────────────── V4：单租户配额（超了拒绝新建，不删旧的）─────────────────────────

    public function test_v4_quota_blocks_new_create_when_exceeded(): void
    {
        [$tenantA, $userA] = $this->tenantAdmin('tp-q');
        $paths = app(ThemePackagePaths::class);

        // 沙箱无外网，example.com 真实 DNS 解析会失败并被判成「私网/不可达」→ 422 误杀。
        // 这里把 HostResolver 桩成「example.com → 公网 IP」，只让 URL 结构校验通过，
        // 不碰真实网络；IP literal（127/10/192.168）仍走 SafeOutboundHttpClient 的 CIDR 拦截（V9 不受影响）。
        $resolver = Mockery::mock(HostResolver::class);
        $resolver->shouldReceive('resolve')->with('example.com')->andReturn(['93.184.216.34']);
        app()->instance(HostResolver::class, $resolver);
        // SafeOutboundHttpClient 是 singleton，首次解析 Service 时即按当时 resolver 建好并缓存，
        // 必须清掉让它用上面桩好的 mock 重建（否则 mock 对已缓存实例无效）。
        app()->forgetInstance(SafeOutboundHttpClient::class);

        // 在租户 A 的存储目录下塞一个超过配额的废文件（50 字节上限）
        config(['geo.theme_package.max_bytes_per_tenant' => 50]);
        Storage::disk('local')->put($paths->tenantRoot($tenantA->id).'/junk.bin', str_repeat('x', 200));

        $used = app(SiteThemeReplicationService::class)->tenantBytesUsed($tenantA->id);
        $this->assertGreaterThanOrEqual(200, $used, 'tenantBytesUsed 没统计到已写文件');

        Sanctum::actingAs($userA);
        $this->forTenant($tenantA);

        $response = $this->postJson('/api/v1/theme-packages', [
            'name' => '超限主题包',
            'theme_id' => 'over-quota',
            'home_url' => 'https://example.com/',
            'category_url' => 'https://example.com/c',
            'article_url' => 'https://example.com/a',
        ], $this->idemHeader('store-q'));

        $response->assertStatus(422);
        // 超配额时必须拒绝新建，而不是删旧的
        $this->assertSame(0, (int) SiteThemeReplication::query()->where('tenant_id', $tenantA->id)->count());

        // ── 变异敏感性（等价于「去掉配额检查」）：上限设为 0 → 同样占用也不拦 ──
        config(['geo.theme_package.max_bytes_per_tenant' => 0]);
        $ok = $this->postJson('/api/v1/theme-packages', [
            'name' => '放量主题包',
            'theme_id' => 'no-quota',
            'home_url' => 'https://example.com/',
            'category_url' => 'https://example.com/c',
            'article_url' => 'https://example.com/a',
        ], $this->idemHeader('store-q2'));
        $ok->assertStatus(201);
        $this->assertSame(1, (int) SiteThemeReplication::query()->where('tenant_id', $tenantA->id)->count());
    }

    // ───────────────────────── V5：版本数上限删最老草稿，当前版本不受影响 ─────────────────────────

    public function test_v5_version_limit_drops_oldest_draft(): void
    {
        [$tenantA] = $this->tenantAdmin('tp-v');
        // current_version 必须与实际版本数一致：真实流程里 enforceVersionLimit 跑的时候，
        // current_version 就是「已有的最新一版」。写成 1 是不可能出现的状态。
        $rep = $this->makeReplication($tenantA, ['current_version' => 11]);
        $paths = app(ThemePackagePaths::class);
        $disk = Storage::disk('local');

        // 造 11 个版本（1..11），每版都落一个真实文件。
        // BelongsToTenant 的 creating hook 从 TenantContext 取 tenant_id，必须先 set。
        TenantContext::set($tenantA);
        try {
            for ($v = 1; $v <= 11; $v++) {
                SiteThemeReplicationVersion::query()->create([
                    'replication_id' => $rep->id,
                    'version' => $v,
                    'files_json' => ['files' => [['path' => 'views/home.blade.php', 'bytes' => 1, 'checksum' => "c{$v}"]]],
                    'bytes' => 1,
                ]);
                $disk->put($paths->draftFor($rep, $v).'/views/home.blade.php', "v{$v}");
            }
        } finally {
            TenantContext::forget();
        }
        $this->assertSame(11, (int) $rep->versions()->count());

        config(['geo.theme_package.max_versions' => 10]);
        app(SiteThemeReplicationService::class)->enforceVersionLimit($rep);

        // 闸门跑在「插入新版本行之前」（见 ThemeReplicationPipelineService），
        // 所以要腾到 limit-1=9，插完新的一版正好等于上限 10。
        // 原实现只删一条，留 10 条 + 新的一版 = 11 条，等于上限根本没守住。
        $this->assertSame(9, (int) $rep->versions()->count());
        $this->assertNull($rep->versions()->where('version', 1)->first());
        $this->assertNull($rep->versions()->where('version', 2)->first());
        $this->assertNotNull($rep->versions()->where('version', 3)->first());
        $this->assertNotNull($rep->versions()->where('version', 11)->first());

        // 🔴 只查库行是不够的：原实现删的是整个 draft/ 目录（连当前版本一起抹），
        //    库里看起来完全正确，磁盘上却什么都不剩 —— 生成成功但预览/下载全 404。
        $this->assertFalse(
            $disk->exists($paths->draftFor($rep, 1).'/views/home.blade.php'),
            '最老版本的文件没被删掉',
        );
        $this->assertFalse($disk->exists($paths->draftFor($rep, 2).'/views/home.blade.php'));
        $this->assertTrue(
            $disk->exists($paths->draftFor($rep, 11).'/views/home.blade.php'),
            '当前版本的文件被一起删了 —— deleteVersionFiles 删的是整个 draft/ 而不是那一版',
        );
        $this->assertTrue(
            $disk->exists($paths->draftFor($rep, 10).'/views/home.blade.php'),
            '中间版本的文件被误删',
        );
    }

    // ───────────────────────── V6：清理命令删 N 天前的，留 N 天内的 ─────────────────────────

    public function test_v6_prune_removes_old_keeps_recent(): void
    {
        [$tenantA] = $this->tenantAdmin('tp-p');
        $paths = app(ThemePackagePaths::class);

        // 老的一条：failed + 2 天前，带真实文件
        $old = $this->makeReplication($tenantA, [
            'status' => SiteThemeReplication::STATUS_FAILED,
            'theme_id' => 'old',
        ]);
        $old->forceFill(['updated_at' => now()->subDays(2)])->save();
        $oldFile = $paths->replicationRoot($tenantA->id, $old->id).'/draft/1/views/home.blade.php';
        Storage::disk('local')->put($oldFile, '<html>old</html>');

        // 新的一条：failed + 现在，带真实文件
        $new = $this->makeReplication($tenantA, [
            'status' => SiteThemeReplication::STATUS_FAILED,
            'theme_id' => 'new',
        ]);
        $newFile = $paths->replicationRoot($tenantA->id, $new->id).'/draft/1/views/home.blade.php';
        Storage::disk('local')->put($newFile, '<html>new</html>');

        $this->artisan('theme-packages:prune', ['--days' => 1, '--tenant' => $tenantA->id])
            ->assertSuccessful();

        // 老的：库行 + 文件都没了
        $this->assertNull(SiteThemeReplication::find($old->id), '老的记录没被清理');
        $this->assertFalse(Storage::disk('local')->exists($oldFile), '老的文件没被清理');

        // 新的：都还在
        $this->assertNotNull(SiteThemeReplication::find($new->id), '新的被误清');
        $this->assertTrue(Storage::disk('local')->exists($newFile), '新的文件被误清');
    }

    // ───────────────────────── V7：清理顺序——先删文件再删库行（反序留孤儿）─────────────────────────

    public function test_v7_delete_order_files_before_db_row(): void
    {
        [$tenantA] = $this->tenantAdmin('tp-o');
        $paths = app(ThemePackagePaths::class);

        $rep = $this->makeReplication($tenantA);
        $fileRel = $paths->replicationRoot($tenantA->id, $rep->id).'/draft/1/views/home.blade.php';
        Storage::disk('local')->put($fileRel, '<html>x</html>');
        $this->assertTrue(Storage::disk('local')->exists($fileRel));

        // 模拟「删库行时失败」（磁盘写入成功、但 DB 写入抛错）：删库行前必须已把文件删掉，
        // 否则反序（先删行再删文件）会因这次抛错留下孤儿文件。
        SiteThemeReplication::deleting(static function () {
            throw new RuntimeException('simulated db failure');
        });

        try {
            app(SiteThemeReplicationService::class)->deleteReplication($rep);
        } catch (RuntimeException) {
            // 预期的 DB 失败
        }

        // 正确的顺序（文件先删）：即便库行删除抛错，文件也应已不在。
        $this->assertFalse(
            Storage::disk('local')->exists($fileRel),
            '文件在删库行抛错时仍残留 —— 顺序反了会留孤儿文件（执行书红线 1 / V7）',
        );
        // 库行因删除抛错而保留（可重试）。
        $this->assertNotNull(SiteThemeReplication::find($rep->id));
    }

    // ───────────────────────── V8：开关开 → 端点可达（关态在 ThemePackageSwitchOffTest）─────────────────────────

    public function test_v8_switch_on_endpoints_reachable(): void
    {
        $this->assertTrue(config('geo.theme_package_enabled'), 'V8 前提：开关必须为 true');

        [$tenantA, $userA] = $this->tenantAdmin('tp-on');
        $rep = $this->makeReplication($tenantA);

        Sanctum::actingAs($userA);
        $this->forTenant($tenantA);

        $this->getJson('/api/v1/theme-packages')->assertStatus(200);
        $this->getJson("/api/v1/theme-packages/{$rep->id}")->assertStatus(200);
        $this->get('/theme-package')->assertStatus(200);
    }

    // ───────────────────────── V9：参考站私网地址被出站安全链拦下 ─────────────────────────

    public function test_v9_private_ref_url_blocked(): void
    {
        [$tenantA, $userA] = $this->tenantAdmin('tp-ssrf');

        Sanctum::actingAs($userA);
        $this->forTenant($tenantA);

        // 三个 URL 全是私网地址（127/10/192.168 都在 BLOCKED 列表，IP literal 不触发 DNS，离线可验）
        $response = $this->postJson('/api/v1/theme-packages', [
            'name' => '私网主题包',
            'theme_id' => 'private',
            'home_url' => 'http://127.0.0.1/',
            'category_url' => 'http://10.0.0.1/c',
            'article_url' => 'http://192.168.1.1/a',
        ], $this->idemHeader('store-ssrf'));

        $response->assertStatus(422);
        $this->assertSame(0, (int) SiteThemeReplication::query()->where('tenant_id', $tenantA->id)->count());
    }

    // ═════════════════════ 评审补测（09-01）═════════════════════
    //
    // 上一版 876 条全绿、功能却一次都跑不通，根因是每条用例都把
    // ThemeReplicationPipelineService 桩成 no-op —— 抓取 / 分析 / 写盘 / 打包 / 预览
    // 五段真码一段都没被执行过。下面这条 E2E 只桩最外层的网络传输，其余全走真码；
    // 它一条就能同时抓住四个阻断（抓取被自己的安全链拦死、zip 从没被创建、
    // 语言包放错目录、预览返回的是 Blade 源码）。

    /**
     * 参考站假响应：带 hex 配色与字体声明，让分析器有东西可抽。
     * 尖括号内不放任何会被 ThemeComplianceGuard 判违规的东西（外链 script / img / @import）。
     */
    private function referenceHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html><head><title>参考站首页</title>
<meta name="description" content="用于主题包生成的参考页面">
<link rel="stylesheet" href="/assets/site.css">
<style>
:root {
  --page-background: #f7f4ed;
  --brand: #1d4ed8;
  --site-font-mono: "JetBrains Mono", monospace;
  --site-font-sans: "Source Han Sans", Helvetica, sans-serif;
}
body { font-family: var(--site-font-sans); color: #1d4ed8; }
.card { background: #f59e0b; border-radius: 12px; padding: 20px; }
</style>
</head>
<body><header><nav><a href="/">首页</a></nav></header>
<main><h1>参考标题</h1><h2>次级标题</h2>
<section class="card grid"><article><p>正文样例。</p></article></section></main>
<footer>页脚</footer></body></html>
HTML;
    }

    /** 让本用例走真实 pipeline（setUp 里默认桩成了 no-op）。 */
    private function useRealPipeline(): void
    {
        app()->forgetInstance(ThemeReplicationPipelineService::class);
    }

    /**
     * V10：端到端跑通一次真实生成 —— 创建 → 抓取 → 分析 → 写盘 → 扫描 → 预览 → 下载 zip。
     *
     * 只桩最外层 OutboundTransport（FakesSafeOutbound：假 DNS + 假响应），
     * 地址判定、SecurePendingRequest 校验、写盘守卫、打包校验全是真的在跑。
     */
    public function test_v10_end_to_end_generates_previewable_and_downloadable_package(): void
    {
        [$tenant, $user] = $this->tenantAdmin('tp-e2e');
        $this->useRealPipeline();
        $this->fakeSafeOutbound($this->referenceHtml());

        Sanctum::actingAs($user);
        $this->forTenant($tenant);

        // QUEUE_CONNECTION=sync：POST 返回时整条流水线已经跑完。
        $created = $this->postJson('/api/v1/theme-packages', [
            'name' => '端到端主题包',
            'theme_id' => 'e2e-theme',
            'home_url' => 'https://example.com/',
            'category_url' => 'https://example.com/c',
            'article_url' => 'https://example.com/a',
            'style_preference' => 'brand_site',
        ], $this->idemHeader('store-e2e'));
        $created->assertStatus(201);
        $id = (int) $created->json('data.id');

        $rep = SiteThemeReplication::withoutGlobalScope(TenantScope::class)->find($id);
        $this->assertNotNull($rep);
        $this->assertSame(
            SiteThemeReplication::STATUS_READY,
            (string) $rep->status,
            '生成没跑通，error_message='.(string) $rep->error_message,
        );
        $this->assertSame(1, (int) $rep->current_version);
        $this->assertSame('brand_site', (string) $rep->style_preference, 'style_preference 被静默改写了');
        $this->assertSame(
            ['Source Han Sans', 'Helvetica', 'sans-serif'],
            array_slice((array) data_get($rep->analysis_json, 'tokens.font_families', []), 0, 3),
            '字体族应拆开、去掉 CSS 引号并保留可读大小写',
        );
        $this->assertSame(
            '#1d4ed8',
            data_get($rep->analysis_json, 'tokens.colors.0'),
            '明确命名的品牌色必须排在页面底色之前，不能把浅色背景误当主色',
        );

        // 版本行：文件清单与字节数都要落库（bytes 原来永远是 0）
        $version = $rep->versions()->latest('version')->first();
        $this->assertNotNull($version);
        $this->assertGreaterThan(0, (int) $version->bytes, 'versions.bytes 没写 —— 清理任务算不出回收了多少');
        $this->assertSame(
            '"Source Han Sans", Helvetica, sans-serif',
            data_get($version->blueprint_json, 'tokens.typography.font_family'),
            '生成 CSS 应保留完整字体回退栈，并给含空格的字体名加引号',
        );

        // 草稿文件真落盘
        $paths = app(ThemePackagePaths::class);
        $disk = Storage::disk('local');
        $this->assertTrue($disk->exists($paths->draftFor($rep, 1).'/views/home.blade.php'));
        $this->assertTrue($disk->exists($paths->draftFor($rep, 1).'/assets/theme.css'));

        // ── 下载：zip 是 download 端点现打的，端点必须真给出一个能解开的包 ──
        $download = $this->get("/api/v1/theme-packages/{$id}/download");
        $download->assertStatus(200);
        $download->assertDownload('e2e-theme-v1.zip');

        $zipPath = tempnam(sys_get_temp_dir(), 'tp').'.zip';
        file_put_contents($zipPath, $download->streamedContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true, 'zip 打不开');
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = (string) $zip->getNameIndex($i);
        }
        $zip->close();
        @unlink($zipPath);

        $this->assertContains('resources/views/theme/e2e-theme/home.blade.php', $entries);
        $this->assertContains('public/themes/e2e-theme/theme.css', $entries);

        // package_path 要回写到版本行
        $this->assertNotNull($version->fresh()->package_path, 'versions.package_path 没回写');

        // ── 预览：必须是渲染好的 HTML，不能是 Blade 源码 ──
        $preview = $this->get("/theme-preview/{$id}/home");
        $preview->assertStatus(200);
        $html = $preview->getContent();

        $this->assertStringNotContainsString('@extends', $html, '预览把草稿 Blade 源码原样吐出来了');
        $this->assertStringNotContainsString('@forelse', $html);
        $this->assertStringNotContainsString('{{ $siteTitle }}', $html);
        // 抽到的主色（参考站里出现最多的 hex）必须真的进了预览
        $this->assertStringContainsString('--rep-primary', $html, '预览没套上生成的 theme.css');
        // 语言包放错目录时，这里会是原始 key「theme_package.safe_preview.notice」
        $this->assertStringNotContainsString('theme_package.', $html, '__() 吐出了原始 key —— 语言包目录不对');

        $preview->assertHeader('X-Frame-Options', 'DENY');
    }

    /**
     * V11：迭代任务跑完也必须清租户上下文。
     *
     * 交付报告 §七 缺陷 5 只修了 RunSiteThemeReplicationJob，
     * IterateSiteThemeReplicationJob 原样留着漏 —— 同一个 worker 进程上，
     * 后面任何不自己设上下文的 Job 都会继承这个租户（TenantScope fail-open）。
     */
    public function test_v11_iterate_job_clears_tenant_context(): void
    {
        [$tenant] = $this->tenantAdmin('tp-it');
        $rep = $this->makeReplication($tenant, ['status' => SiteThemeReplication::STATUS_ITERATING]);

        $seen = null;
        $pipeline = Mockery::mock(ThemeReplicationPipelineService::class);
        $pipeline->shouldReceive('iterate')->once()->andReturnUsing(function () use (&$seen): void {
            $seen = TenantContext::id();
        });
        app()->instance(ThemeReplicationPipelineService::class, $pipeline);

        IterateSiteThemeReplicationJob::dispatch($rep->id, '再紧凑一点', $tenant->id);

        $this->assertSame($tenant->id, $seen, 'iterate 跑的时候没有租户上下文');
        $this->assertNull(TenantContext::id(), 'Job 跑完没清理租户上下文（执行书红线 2）');
    }

    /**
     * V12：撞 theme_id 唯一约束时，响应体里不许出现 SQL / 库名。
     *
     * QueryException 继承自 PDOException → 继承自 RuntimeException，
     * 会被 store() 的 catch 当成「客户端错误」原样吐出去，
     * 连 Connection / Database 名和用户填的全部值一起。
     */
    public function test_v12_duplicate_theme_id_returns_clean_422(): void
    {
        [$tenant, $user] = $this->tenantAdmin('tp-dup');
        $this->makeReplication($tenant, ['theme_id' => 'dup-theme']);

        $resolver = Mockery::mock(HostResolver::class);
        $resolver->shouldReceive('resolve')->andReturn(['93.184.216.34']);
        app()->instance(HostResolver::class, $resolver);
        app()->forgetInstance(SafeOutboundHttpClient::class);

        Sanctum::actingAs($user);
        $this->forTenant($tenant);

        $response = $this->postJson('/api/v1/theme-packages', [
            'name' => '重名主题包',
            'theme_id' => 'dup-theme',
            'home_url' => 'https://example.com/',
            'category_url' => 'https://example.com/c',
            'article_url' => 'https://example.com/a',
        ], $this->idemHeader('store-dup'));

        $response->assertStatus(422);
        $message = (string) $response->json('message');
        $this->assertStringNotContainsString('SQL', $message);
        $this->assertStringNotContainsString('insert into', $message);
        $this->assertStringNotContainsString('Connection:', $message);
        $this->assertSame(__('theme_package.validation.theme_id_exists'), $message);
    }

    /**
     * V13：style_preference 白名单，控制器与 Service 必须是同一份。
     * 两边不一致的后果是静默的：放行的值 Service 认不出 → 默默回落 content_site。
     */
    public function test_v13_style_preference_whitelist_is_consistent(): void
    {
        [$tenant, $user] = $this->tenantAdmin('tp-style');

        $resolver = Mockery::mock(HostResolver::class);
        $resolver->shouldReceive('resolve')->andReturn(['93.184.216.34']);
        app()->instance(HostResolver::class, $resolver);
        app()->forgetInstance(SafeOutboundHttpClient::class);

        Sanctum::actingAs($user);
        $this->forTenant($tenant);

        $i = 0;
        foreach (['content_site', 'brand_site', 'news_site'] as $style) {
            $created = $this->postJson('/api/v1/theme-packages', [
                'name' => '版式 '.$style,
                'theme_id' => 'style-'.str_replace('_', '-', $style),
                'home_url' => 'https://example.com/',
                'category_url' => 'https://example.com/c',
                'article_url' => 'https://example.com/a',
                'style_preference' => $style,
            ], $this->idemHeader('store-style-'.(++$i)));

            $created->assertStatus(201);
            $this->assertSame(
                $style,
                (string) $created->json('data.style_preference'),
                "选了 {$style}，存进去的却不是它 —— 两侧白名单对不上",
            );
        }

        // 白名单外的值必须被挡在校验层，而不是进去以后被悄悄改写
        $this->postJson('/api/v1/theme-packages', [
            'name' => '不存在的版式',
            'theme_id' => 'style-magazine',
            'home_url' => 'https://example.com/',
            'category_url' => 'https://example.com/c',
            'article_url' => 'https://example.com/a',
            'style_preference' => 'magazine',
        ], $this->idemHeader('store-style-x'))->assertStatus(422);
    }

    /**
     * V14：表不存在时，清理命令一个文件都不许删。
     *
     * 原实现在这种情况下仍跑孤儿扫描，而扫描里「查不到库行 = 孤儿」——
     * 迁移一回滚，次日 03:35 全租户产物清零。
     */
    public function test_v14_prune_deletes_nothing_when_schema_missing(): void
    {
        [$tenant] = $this->tenantAdmin('tp-noschema');
        $paths = app(ThemePackagePaths::class);
        $file = $paths->replicationRoot($tenant->id, 1).'/draft/1/views/home.blade.php';
        Storage::disk('local')->put($file, '<html>keep me</html>');

        Schema::drop('site_theme_replication_logs');
        Schema::drop('site_theme_replication_versions');
        Schema::drop('site_theme_replications');

        $this->artisan('theme-packages:prune', ['--days' => 1])->assertSuccessful();

        $this->assertTrue(
            Storage::disk('local')->exists($file),
            '表不存在时把产物删了 —— 一次迁移回滚就会清空全租户磁盘',
        );
    }

    /**
     * V15：保留期到了要回收 ready 的产物，但库行必须留着，且能重新生成。
     * 红线 1 第 1 条要求删 N 天前的生成物与 zip；原实现只清 failed/archived，
     * 占磁盘最大头的 ready 草稿永远不回收。
     */
    public function test_v15_prune_reclaims_ready_artifacts_but_keeps_row(): void
    {
        [$tenant] = $this->tenantAdmin('tp-reclaim');
        $paths = app(ThemePackagePaths::class);
        $disk = Storage::disk('local');

        $rep = $this->makeReplication($tenant, [
            'theme_id' => 'reclaim-me',
            'generated_files_json' => ['files' => [['path' => 'views/home.blade.php']]],
            'preview_snapshot_json' => ['version' => 1],
        ]);
        $rep->forceFill(['updated_at' => now()->subDays(30)])->save();

        $file = $paths->draftFor($rep, 1).'/views/home.blade.php';
        $disk->put($file, '<html>old draft</html>');

        $this->artisan('theme-packages:prune', ['--days' => 14, '--tenant' => $tenant->id])
            ->assertSuccessful();

        // 文件收了
        $this->assertFalse($disk->exists($file), '超期的 ready 草稿没被回收');
        // 库行留着
        $fresh = SiteThemeReplication::withoutGlobalScope(TenantScope::class)->find($rep->id);
        $this->assertNotNull($fresh, '把用户的交付记录一起删了');
        $this->assertNull($fresh->generated_files_json);
        $this->assertFalse($fresh->isPreviewReady());
        $this->assertFalse($fresh->canPackage());
        // 能重新生成（retry 会写日志行，日志的 tenant_id 取自 TenantContext；
        // 生产上这条是控制器在租户上下文里调的，测试直接调就得自己摆好上下文）
        TenantContext::set($tenant);
        try {
            $retried = app(SiteThemeReplicationService::class)->retry($fresh);
        } finally {
            TenantContext::forget();
        }
        $this->assertSame(SiteThemeReplication::STATUS_QUEUED, (string) $retried->status);
    }

    /**
     * V16：抓参考站必须用 SecureHttpFactory。
     *
     * 用原厂 Illuminate\Http\Client\Factory 的话，造出来的是普通 PendingRequest，
     * 而 LaravelPinnedOutboundTransport 第一件事就是 instanceof SecurePendingRequest ——
     * 生产上每一次抓取都被自己的出站安全链以 secure_pending_request_required 拦死。
     * 这条断言便宜，且失败时直接指出原因（V10 是行为层的同一件事）。
     */
    public function test_v16_reference_fetcher_uses_secure_http_factory(): void
    {
        $fetcher = app(ThemeReferenceFetcher::class);
        $property = new ReflectionProperty($fetcher, 'http');

        $this->assertInstanceOf(
            SecureHttpFactory::class,
            $property->getValue($fetcher),
            '抓取用的不是 SecureHttpFactory —— 生产上 100% 抓不到任何参考站',
        );
    }

    // ─────────── 主题包页重做（2026-09-03）：列表创建人 + 失败建议 ───────────

    /**
     * V17：列表带创建人姓名，且不带 users 表的敏感列。
     *
     * 缺陷 6：界面上「创建人」这一列显示的是裸整数 user_id。
     * 修法是 with('creator:id,name')，**只两列** —— with('creator') 整行会把
     * 手机号、密码 hash、历史身份标识 一起发到前端。这条同时守住这两件事。
     */
    public function test_v17_index_returns_creator_name_without_sensitive_columns(): void
    {
        [$tenant, $user] = $this->tenantAdmin('tp-creator');
        $user->forceFill(['name' => '陈莹'])->save();
        $this->makeReplication($tenant, ['created_by_user_id' => $user->id]);

        Sanctum::actingAs($user);
        $response = $this->forTenant($tenant)->getJson('/api/v1/theme-packages')->assertOk();

        $creator = $response->json('data.0.creator');
        $this->assertSame($user->id, $creator['id']);
        $this->assertSame('陈莹', $creator['name']);
        $this->assertSame(['id', 'name'], array_keys($creator), 'creator 只能带 id 与 name 两列');

        // 整个响应体里不许出现手机号或密码 hash（不只是 creator 那一段）。
        $raw = $response->getContent();
        $this->assertStringNotContainsString((string) $user->phone, $raw);
        $this->assertStringNotContainsString('password', $raw);
    }

    /**
     * V18：failureAdvice() 的五个 type 在 lang 文件里都有对应的三个 key。
     *
     * 这是「有没有漏 key」的机器判据：__() 找不到 key 时会把 **key 字符串本身**
     * 当文案返回，所以只要断言「翻出来的东西不等于 key 自己」就能发现漏了哪一条。
     * 改这个 match 的分支值时，这条会立刻炸——正是 'ai' 分支当年该被拦住的地方。
     */
    public function test_v18_failure_advice_types_all_have_lang_keys(): void
    {
        foreach (['scan', 'fetch', 'file', 'blueprint', 'unknown'] as $type) {
            foreach (['title', 'desc'] as $suffix) {
                $key = 'theme_package.failure.'.$type.'_'.$suffix;
                $this->assertNotSame($key, __($key), $key.' 没有对应文案，界面上会直接显示这行 key');
            }

            $actionsKey = 'theme_package.failure.'.$type.'_actions';
            $actions = __($actionsKey);
            $this->assertIsArray($actions, $actionsKey.' 没有对应文案，界面上会直接显示这行 key');
            $this->assertCount(3, $actions, $actionsKey.' 应该是三条处置办法');
        }
    }

    /**
     * V19：show 与 status 都带 failure_advice；非 failed 时为 null。
     *
     * 顺带钉死缺陷 10：一条最常见的 'Failed to fetch https://…' 必须判成 fetch。
     * 修之前它会命中 str_contains($message, 'ai')（f-ai-l 里那两个字母），
     * 被判成 type='ai'，而 lang 里没有 ai_* —— 客户看到的标题就是
     * 「theme_package.failure.ai_title」这行原始 key。
     */
    public function test_v19_show_and_status_carry_failure_advice(): void
    {
        [$tenant, $user] = $this->tenantAdmin('tp-advice');
        $ready = $this->makeReplication($tenant, ['theme_id' => 'tp-advice-ok']);
        $failed = $this->makeReplication($tenant, [
            'theme_id' => 'tp-advice-bad',
            'status' => SiteThemeReplication::STATUS_FAILED,
            'error_message' => 'Failed to fetch https://x/',
        ]);

        Sanctum::actingAs($user);

        // 非 failed → null（前端判空即可）
        $this->forTenant($tenant)->getJson('/api/v1/theme-packages/'.$ready->id)
            ->assertOk()->assertJsonPath('failure_advice', null);
        $this->forTenant($tenant)->getJson('/api/v1/theme-packages/'.$ready->id.'/status')
            ->assertOk()->assertJsonPath('failure_advice', null);

        foreach (['', '/status'] as $suffix) {
            $advice = $this->forTenant($tenant)
                ->getJson('/api/v1/theme-packages/'.$failed->id.$suffix)
                ->assertOk()
                ->json('failure_advice');

            $this->assertSame('fetch', $advice['type'], '「Failed to fetch …」必须判成抓取失败，不是 AI 失败');
            $this->assertStringNotContainsString('theme_package.failure.', (string) $advice['title'], '露出了原始翻译 key');
            $this->assertCount(3, $advice['actions']);
        }
    }

    /**
     * V20：详情带往次反馈（版本号 / 内容 / 时间），且不带 versions 的大字段。
     *
     * 迭代抽屉要列往次反馈；versions 行里的 blueprint_json / files_json /
     * compliance_report_json 三个大字段界面一个都用不到，整行带出去纯属浪费带宽。
     */
    public function test_v20_show_returns_version_feedback_only(): void
    {
        [$tenant, $user] = $this->tenantAdmin('tp-iter-history');
        $rep = $this->makeReplication($tenant, ['theme_id' => 'tp-iter-history-1', 'current_version' => 2]);

        TenantContext::set($tenant);
        try {
            SiteThemeReplicationVersion::query()->create([
                'replication_id' => $rep->id,
                'version' => 2,
                'prompt_hash' => str_repeat('a', 64),
                'feedback' => '标题字重降一档',
                'blueprint_json' => ['big' => str_repeat('x', 200)],
                'files_json' => ['files' => []],
                'bytes' => 10,
            ]);
        } finally {
            TenantContext::forget();
        }

        Sanctum::actingAs($user);
        $version = $this->forTenant($tenant)
            ->getJson('/api/v1/theme-packages/'.$rep->id)
            ->assertOk()
            ->json('data.versions.0');

        $this->assertSame(2, $version['version']);
        $this->assertSame('标题字重降一档', $version['feedback']);
        $this->assertArrayNotHasKey('blueprint_json', $version, 'versions 只该带四列，大字段不许出现');
        $this->assertArrayNotHasKey('files_json', $version);
    }
}
