<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DTOs\SkillBrief;
use App\Services\SkillAgent\PageFetchService;
use App\Services\SkillAgent\PageObservation;
use App\Services\SkillAgent\SkillPagePreflight;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CapturesLogs;
use Tests\TestCase;

/**
 * 执行前页面预检 · 特征化测试（计划 §7 第 3 步：`SkillAgentRunnerService` 按职责拆小的第八桶）。
 *
 * 这一桶把页面审计的"先抓再写"搬进 `SkillPagePreflight`：抓一次、抓失败就到此为止、
 * 抓成功把地址记回项目。端到端由 `PageAuditRunnerTest` / `PageAuditSectionRelayTest` 兜底
 * （它们走的是服务的转发方法，所以搬迁前后必然同一条路径）；这里冻结的是四条判据本身：
 *
 *   1. 页面审计用 url 抓单页；路线图用 brand_url 抓官网及实际链接的下载页；
 *      其余技能哪怕填了 url 字段也不会发请求；
 *   2. 逃生阀：`GEO_PAGE_FETCH_ENABLED=false` 时不抓（这条要说清它是什么：退回旧行为）；
 *   3. 没填地址：当场判 `missing_url`，**不调抓取器**；
 *   4. 记账口径：抓失败的收场是 0 次调用 / 0 词元 —— 客户不该为"我们没拿到原料"付钱。
 *
 * 另外钉住两条容易悄悄退化的日志纪律：只记 host（客户填的地址可能带预览 token 这类 query），
 * 以及回填官网时**已有值不许被覆盖**。
 */
class SkillPagePreflightTest extends TestCase
{
    use CapturesLogs;
    use RefreshDatabase;

    private const SKILL_ID = 'yao-geo-page-audit';

    private Tenant $tenant;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()->create([
            'subdomain' => 'page-preflight',
            'name' => 'PREFLIGHT',
            'plan' => 'basic',
            'status' => 'active',
        ]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'admin']);

        TenantContext::set($this->tenant);
        $this->project = Project::query()->create([
            'owner_id' => $this->user->id,
            'name' => 'Preflight',
            'brand_name' => 'Preflight',
            'brand_url' => '',
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();

        parent::tearDown();
    }

    private function brief(string $skillId, array $fields = []): SkillBrief
    {
        return new SkillBrief(
            skillId: $skillId,
            projectId: $this->project->id,
            userId: $this->user->id,
            fields: $fields,
            query: null,
        );
    }

    private function observed(string $url = 'https://brand.test/landing'): PageObservation
    {
        return PageObservation::success($url, 'https://brand.test/', 200, ['head' => ['title' => '品牌官网']]);
    }

    public function test_only_whitelisted_skills_fetch_a_page(): void
    {
        $fetch = $this->createMock(PageFetchService::class);
        $fetch->expects($this->once())->method('fetch')->with('https://brand.test/landing')->willReturn($this->observed());

        $preflight = new SkillPagePreflight($fetch);

        // 登记过的技能：地址两边有空白要去掉再抓（客户从浏览器复制常常带空格）
        $observation = $preflight->observe($this->brief(self::SKILL_ID, ['url' => '  https://brand.test/landing  ']));
        $this->assertNotNull($observation);
        $this->assertTrue($observation->ok);

        // 没登记的技能：哪怕字段名一样、值也是合法 URL，也不许发请求
        $this->assertNull($preflight->observe($this->brief('yao-geo-panorama-audit', ['url' => 'https://brand.test/landing'])));
        $this->assertNull($preflight->observe($this->brief('yao-geo-intent-miner', ['url' => 'https://brand.test/landing'])));
    }

    public function test_roadmap_fetches_homepage_and_its_actual_download_link(): void
    {
        $home = PageObservation::success('https://brand.test/', 'https://brand.test/', 200, [
            'links' => ['download_candidates' => [['anchor' => '获取客户端', 'href' => '/download.html']]],
            'prose' => ['first_paint_sample' => '品牌首页'],
            'fetched_at' => '2026-10-16T10:00:00+08:00',
        ]);
        $download = PageObservation::success('https://brand.test/download.html', 'https://brand.test/download.html', 200, [
            'head' => ['title' => '客户端下载'],
            'headings' => [['level' => 1, 'text' => '下载客户端']],
            'prose' => ['first_paint_sample' => 'Windows v0.1.0 现已提供，macOS Apple 芯片和 Intel 芯片版本现已提供。'],
            'fetched_at' => '2026-10-16T10:00:01+08:00',
        ]);
        $fetch = $this->createMock(PageFetchService::class);
        $seen = [];
        $fetch->expects($this->exactly(2))->method('fetch')->willReturnCallback(
            function (string $url) use (&$seen, $home, $download): PageObservation {
                $seen[] = $url;
                return count($seen) === 1 ? $home : $download;
            }
        );

        $observation = (new SkillPagePreflight($fetch))->observe($this->brief(
            'yao-geo-execution-roadmap', ['brand_url' => 'https://brand.test/']
        ));

        $this->assertSame(['https://brand.test/', 'https://brand.test/download.html'], $seen);
        $this->assertTrue($observation->ok);
        $this->assertSame('fetched', $observation->facts['related_download_page']['status']);
        $this->assertStringContainsString('现已提供', $observation->facts['related_download_page']['visible_text']);
        $this->assertSame('2026-10-16T10:00:01+08:00', $observation->facts['related_download_page']['fetched_at']);
    }

    public function test_roadmap_without_website_does_not_claim_a_crawl(): void
    {
        $fetch = $this->createMock(PageFetchService::class);
        $fetch->expects($this->never())->method('fetch');

        $this->assertNull((new SkillPagePreflight($fetch))->observe($this->brief(
            'yao-geo-execution-roadmap', ['brand_url' => '(未提供)']
        )));
    }

    public function test_roadmap_aborts_when_linked_download_page_cannot_be_verified(): void
    {
        $home = PageObservation::success('https://brand.test/', 'https://brand.test/', 200, [
            'links' => ['download_candidates' => [['anchor' => '下载', 'href' => '/download.html']]],
        ]);
        $download = PageObservation::failure('https://brand.test/download.html', 'http_error', '404', 404);
        $fetch = $this->createMock(PageFetchService::class);
        $fetch->expects($this->exactly(2))->method('fetch')->willReturnOnConsecutiveCalls($home, $download);

        $observation = (new SkillPagePreflight($fetch))->observe($this->brief(
            'yao-geo-execution-roadmap', ['brand_url' => 'https://brand.test/']
        ));

        $this->assertFalse($observation->ok);
        $this->assertSame('related_page_failed', $observation->failureCode);
        $result = (new SkillPagePreflight)->failureResult(
            $this->brief('yao-geo-execution-roadmap'), $observation, $this->tenant->id, microtime(true)
        );
        $this->assertSame(0, $result->llmCallCount);
        $this->assertSame(0, $result->tokenUsage);
    }

    public function test_the_kill_switch_disables_fetching(): void
    {
        config(['geo.page_fetch_enabled' => false]);

        $fetch = $this->createMock(PageFetchService::class);
        $fetch->expects($this->never())->method('fetch');

        $this->assertNull(
            (new SkillPagePreflight($fetch))->observe($this->brief(self::SKILL_ID, ['url' => 'https://brand.test'])),
            '逃生阀关掉时不抓页面 —— 这是"宁可要一份不可信报告"的开关，不是默认行为',
        );
    }

    public function test_a_missing_url_fails_without_calling_the_fetcher(): void
    {
        $fetch = $this->createMock(PageFetchService::class);
        $fetch->expects($this->never())->method('fetch');

        $observation = (new SkillPagePreflight($fetch))->observe($this->brief(self::SKILL_ID, ['url' => '   ']));

        $this->assertNotNull($observation);
        $this->assertFalse($observation->ok);
        $this->assertSame('missing_url', $observation->failureCode);
        $this->assertNotSame('', (string) $observation->failureMessage, '对客文案不能是空的');
    }

    public function test_only_the_host_is_logged_never_the_full_url(): void
    {
        $fetch = $this->createMock(PageFetchService::class);
        $fetch->method('fetch')->willReturn($this->observed('https://brand.test/preview?token=super-secret'));

        $this->captureLogs();
        (new SkillPagePreflight($fetch))->observe($this->brief(self::SKILL_ID, ['url' => 'https://brand.test/preview?token=super-secret']));

        $this->assertLogged(
            fn (string $level, string $message, array $context) => $message === 'Page fetched for skill'
                && ($context['host'] ?? null) === 'brand.test',
            '抓取日志里必须有一条带 host 的记录。',
        );
        $this->assertNotLogged(
            fn (string $level, string $message, array $context) => str_contains(json_encode($context) ?: '', 'super-secret'),
            '日志里不许出现完整 URL —— 客户的预览 token 不该进日志。',
        );
    }

    public function test_the_project_brand_url_is_backfilled_only_when_empty(): void
    {
        $fetch = $this->createMock(PageFetchService::class);
        $fetch->method('fetch')->willReturn($this->observed());
        $preflight = new SkillPagePreflight($fetch);

        // 空的 → 用最终地址回填（不是客户填的那个可能带跳转的原始地址）
        $preflight->observe($this->brief(self::SKILL_ID, ['url' => 'https://brand.test/landing']));
        $this->assertSame('https://brand.test/', $this->project->fresh()->brand_url);

        // 已填过 → 客户自己设的值不许被单次技能输入覆盖
        $this->project->fresh()->update(['brand_url' => 'https://old.example.com']);
        $preflight->observe($this->brief(self::SKILL_ID, ['url' => 'https://brand.test/landing']));
        $this->assertSame('https://old.example.com', $this->project->fresh()->brand_url);
    }

    public function test_a_backfill_failure_does_not_break_the_run(): void
    {
        $fetch = $this->createMock(PageFetchService::class);
        $fetch->method('fetch')->willReturn($this->observed());

        // 项目不存在（比如被删了）→ 回填静默失败，但这次执行照样往下走
        $preflight = new SkillPagePreflight($fetch);
        $orphan = new SkillBrief(
            skillId: self::SKILL_ID,
            projectId: $this->project->id + 999999,
            userId: $this->user->id,
            fields: ['url' => 'https://brand.test/landing'],
            query: null,
        );

        $observation = $preflight->observe($orphan);
        $this->assertNotNull($observation);
        $this->assertTrue($observation->ok, '回填失败不该拖垮这次执行 —— 报告该出还是要出');
    }

    public function test_the_failure_result_costs_the_customer_nothing(): void
    {
        $observation = PageObservation::failure('https://brand.test', 'http_error', '目标页面返回 404，请确认地址是否正确。');

        $result = (new SkillPagePreflight)->failureResult(
            $this->brief(self::SKILL_ID, ['url' => 'https://brand.test']),
            $observation,
            $this->tenant->id,
            microtime(true),
        );

        $this->assertFalse($result->success);
        // 0 次调用 / 0 词元：这一单没花过钱，账面上也要是 0
        $this->assertSame(0, $result->llmCallCount);
        $this->assertSame(0, $result->tokenUsage);
        $this->assertSame([], $result->outputPaths);
        $this->assertTrue($result->intermediateData['page_fetch_failed'] ?? false);
        $this->assertSame($observation->toArray(), $result->intermediateData['page_observation'] ?? null);
        $this->assertSame($this->tenant->id, $result->intermediateData['tenant_id'] ?? null);

        // 错误文案两句都要在：先说为什么没有报告，再说是什么挡住了
        $this->assertStringContainsString('需要真实抓取', (string) $result->errorMessage);
        $this->assertStringContainsString('404', (string) $result->errorMessage);
    }
}
