<?php

namespace Tests\Feature;

use App\Jobs\DryRunPlatformSensitiveWordsJob;
use App\Models\Article;
use App\Models\PlatformSensitiveWord;
use App\Models\PlatformSensitiveWordDryRun;
use App\Models\Project;
use App\Models\SensitiveWord;
use App\Models\ArticlePublication;
use App\Models\ArticleRiskScan;
use App\Models\DistributionLog;
use App\Models\ManualPublication;
use App\Models\PublishingChannel;
use App\Models\SensitiveWordHit;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Distribution\DistributionOrchestrator;
use App\Services\Publishing\ArticlePublicationService;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Http;
use App\Services\Publishing\ArticleRiskScanner;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 平台级敏感词基线库 —— 阶段 A 机制测试（执行书 T11 的 PHP 机制矩阵）。
 *
 * ⚠️ 本文件只验证「机制」：闸门口径、缓存版本进位、跨租户可见性、接口鉴权、
 * 试算管线。使用的词（如「测试违禁词XYZ」）都是明显非真实政策的占位词，
 * 不涉及 T9 的真实词表初筛/法务定稿流程——那部分按老板要求仍处阶段 B，未经评审不许入库。
 */
class PlatformSensitiveWordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.tenant_domain' => 'geo.example.test']);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    private function platformAdmin(): User
    {
        return User::factory()->create([
            'tenant_id' => null,
            'role' => 'platform_admin',
        ]);
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

            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain.$role),
            'role' => $role,
        ]);

        return [$tenant, $user];
    }

    private function tenantProject(string $subdomain): array
    {
        $tenant = Tenant::query()->create([
            'subdomain' => $subdomain,
            'name' => strtoupper($subdomain),
            'plan' => 'points',
            'status' => 'active',
        ]);
        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,

            'email' => $subdomain.'@example.com',
            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain),
            'role' => 'admin',
        ]);

        TenantContext::set($tenant);
        try {
            $project = Project::query()->create([
                'owner_id' => $admin->id,
                'name' => strtoupper($subdomain).' Project',
                'brand_name' => strtoupper($subdomain),
                'brand_url' => 'https://'.$subdomain.'.example.test',
                'settings' => [],
            ]);
        } finally {
            TenantContext::forget();
        }

        return [$tenant, $admin, $project];
    }

    private function article(Tenant $tenant, Project $project, User $author, string $title): Article
    {
        TenantContext::set($tenant);

        try {
            $article = Article::query()->create([
                'project_id' => $project->id,
                'author_id' => $author->id,
                'title' => $title,
                'slug' => strtolower(str_replace(' ', '-', $title)),
                'body_markdown' => '# '.$title,
                'summary' => $title.' summary',
                'content_type' => 'general',
                'status' => 'draft',
                'source_type' => 'manual',
                'metadata' => [],
            ]);
            Article::withoutEvents(function () use ($article) {
                $article->forceFill(['status' => 'published', 'published_at' => now()])->save();
            });

            return $article;
        } finally {
            TenantContext::forget();
        }
    }

    /**
     * 与 article() 类似，但落 approved 状态（绕过模型事件，避免建时即触发自动分发），
     * 供出站闸门 HTTP 测试用：publish/retry 路由要求文章状态为 approved|published。
     */
    private function approvedArticle(Tenant $tenant, Project $project, User $author, string $title): Article
    {
        TenantContext::set($tenant);

        try {
            $article = Article::query()->create([
                'project_id' => $project->id,
                'author_id' => $author->id,
                'title' => $title,
                'slug' => strtolower(str_replace(' ', '-', $title)),
                'body_markdown' => '# '.$title,
                'summary' => $title.' summary',
                'content_type' => 'general',
                'status' => 'draft',
                'source_type' => 'manual',
                'metadata' => [],
            ]);
            Article::withoutEvents(function () use ($article) {
                $article->forceFill(['status' => 'approved'])->save();
            });

            return $article;
        } finally {
            TenantContext::forget();
        }
    }

    private function forTenant(Tenant $tenant): self
    {
        return $this
            ->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }

    #[Test]
    public function platform_admin_can_list_words_and_see_cache_version(): void
    {
        Sanctum::actingAs($this->platformAdmin(), ['*']);

        $this->getJson('/api/v1/platform/sensitive-words')
            ->assertStatus(200)
            ->assertJsonStructure(['data', 'total', 'enabled_total', 'rule_cache_version'])
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function store_inserts_words_and_bumps_cache_version(): void
    {
        Sanctum::actingAs($this->platformAdmin(), ['*']);
        $before = PlatformSensitiveWord::ruleCacheVersion();

        $this->postJson('/api/v1/platform/sensitive-words', [
            'words' => "测试违禁词A\n测试违禁词B, 测试违禁词C",
            'severity' => 'blocked',
            'category' => 'politics',
        ])
            ->assertStatus(201)
            ->assertJsonPath('inserted', 3)
            ->assertJsonPath('skipped_duplicates', 0);

        $this->assertSame(3, PlatformSensitiveWord::query()->count());
        $this->assertGreaterThan($before, PlatformSensitiveWord::ruleCacheVersion());
    }

    #[Test]
    public function resubmitting_an_existing_word_updates_it_instead_of_duplicating(): void
    {
        Sanctum::actingAs($this->platformAdmin(), ['*']);

        $this->postJson('/api/v1/platform/sensitive-words', ['words' => '复用词'])
            ->assertStatus(201)->assertJsonPath('inserted', 1);
        $id = PlatformSensitiveWord::query()->where('word', '复用词')->first()->id;

        $this->postJson('/api/v1/platform/sensitive-words', [
            'words' => '复用词',
            'severity' => 'warning',
        ])
            ->assertStatus(201)
            ->assertJsonPath('inserted', 0)
            ->assertJsonPath('updated', 1);

        $row = PlatformSensitiveWord::query()->find($id);
        $this->assertSame('warning', $row->severity);
        $this->assertTrue($row->is_enabled); // 重新提交默认重新启用
        $this->assertSame(1, PlatformSensitiveWord::query()->count());
    }

    #[Test]
    public function store_rejects_unknown_severity(): void
    {
        Sanctum::actingAs($this->platformAdmin(), ['*']);

        $this->postJson('/api/v1/platform/sensitive-words', [
            'words' => '坏等级',
            'severity' => 'bogus',
        ])->assertStatus(422);
    }

    #[Test]
    public function destroy_soft_disables_instead_of_physically_deleting(): void
    {
        Sanctum::actingAs($this->platformAdmin(), ['*']);
        $id = PlatformSensitiveWord::query()->create(['word' => '待停用'])->id;

        $this->deleteJson('/api/v1/platform/sensitive-words/'.$id)
            ->assertStatus(200)
            ->assertJsonPath('soft_disabled', true)
            ->assertJsonPath('data.is_enabled', false);

        // 物理行仍在（平台词永不物理删除，只是停用）
        $this->assertNotNull(PlatformSensitiveWord::query()->find($id));
        $this->assertSame(1, PlatformSensitiveWord::query()->count());
    }

    #[Test]
    public function platform_words_have_no_tenant_scope_and_are_visible_cross_tenant(): void
    {
        [$tenantA] = $this->makeTenantUser('iso-a', 'admin');
        [$tenantB] = $this->makeTenantUser('iso-b', 'admin');

        PlatformSensitiveWord::query()->create(['word' => '基线P1']);
        PlatformSensitiveWord::query()->create(['word' => '基线P2']);

        // 即使在某租户上下文内，平台词也不该被租户作用域过滤掉
        TenantContext::set($tenantA);
        $count = PlatformSensitiveWord::query()->count();
        TenantContext::forget();

        $this->assertSame(2, $count);

        // 另一租户也读得到同样的全部平台词
        TenantContext::set($tenantB);
        $this->assertSame(2, PlatformSensitiveWord::query()->count());
        TenantContext::forget();
    }

    #[Test]
    public function scanner_throws_without_tenant_context_fail_close(): void
    {
        $scanner = app(ArticleRiskScanner::class);

        $this->expectException(\RuntimeException::class);
        $scanner->scan([
            'title' => 'x', 'excerpt' => '', 'content' => '', 'keywords' => '', 'meta_description' => '',
        ]);
    }

    #[Test]
    public function platform_word_match_is_blocked_with_platform_origin(): void
    {
        [$tenant, $admin, $project] = $this->tenantProject('scan-tenant');
        $article = $this->article($tenant, $project, $admin, '标题含测试违禁词XYZ的正文');

        PlatformSensitiveWord::query()->create([
            'word' => '测试违禁词XYZ',
            'severity' => 'blocked',
            'category' => 'politics',
        ]);

        $scanner = app(ArticleRiskScanner::class);
        TenantContext::set($tenant);
        $result = $scanner->scan($scanner->articleContent($article));
        TenantContext::forget();

        $this->assertSame('blocked', $result['status']);
        $match = null;
        foreach ($result['matches'] as $m) {
            if ($m['word'] === '测试违禁词XYZ') {
                $match = $m;
                break;
            }
        }
        $this->assertNotNull($match, '平台词必须被命中');
        $this->assertSame(ArticleRiskScanner::ORIGIN_PLATFORM, $match['origin']);
        $this->assertSame('blocked', $match['severity']);
    }

    #[Test]
    public function non_privileged_role_gets_403_on_platform_endpoints(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('forbid-tenant', 'admin');
        Sanctum::actingAs($admin, ['*']);

        $this->withHeader('X-Tenant', $tenant->subdomain)
            ->getJson('/api/v1/platform/sensitive-words')
            ->assertStatus(403);

        $this->withHeader('X-Tenant', $tenant->subdomain)
            ->postJson('/api/v1/platform/sensitive-words', ['words' => 'x'])
            ->assertStatus(403);

        $this->withHeader('X-Tenant', $tenant->subdomain)
            ->getJson('/api/v1/platform/sensitive-word-hits/summary')
            ->assertStatus(403);
    }

    #[Test]
    public function hit_summary_aggregates_by_word_and_is_403_for_non_privileged(): void
    {
        [$tenant] = $this->makeTenantUser('hit-tenant', 'admin');

        SensitiveWordHit::query()->create([
            'tenant_id' => $tenant->id, 'article_id' => 11, 'word' => '聚合词',
            'severity' => 'blocked', 'origin' => SensitiveWordHit::ORIGIN_PLATFORM,
            'surface' => 'title', 'context' => '命中上下文', 'trigger' => 'create',
        ]);
        SensitiveWordHit::query()->create([
            'tenant_id' => $tenant->id, 'article_id' => 12, 'word' => '聚合词',
            'severity' => 'blocked', 'origin' => SensitiveWordHit::ORIGIN_PLATFORM,
            'surface' => 'title', 'context' => '另一篇命中', 'trigger' => 'create',
        ]);

        Sanctum::actingAs($this->platformAdmin(), ['*']);
        $this->getJson('/api/v1/platform/sensitive-word-hits/summary')
            ->assertStatus(200)
            ->assertJsonPath('data.0.word', '聚合词')
            ->assertJsonPath('data.0.article_count', 2)
            ->assertJsonPath('data.0.hit_count', 2);
    }

    #[Test]
    public function tenant_side_word_list_never_returns_platform_words(): void
    {
        PlatformSensitiveWord::query()->create(['word' => '平台词P']);
        [$tenant, $admin] = $this->makeTenantUser('sep-tenant', 'admin');
        TenantContext::set($tenant);
        SensitiveWord::query()->create(['tenant_id' => $tenant->id, 'word' => '租户词T']);
        TenantContext::forget();

        Sanctum::actingAs($admin, ['*']);
        $this->forTenant($tenant)
            ->getJson('/api/v1/sensitive-words')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.word', '租户词T');
    }

    /**
     * B1 真实验证（替换原「常量不含某字符串」的恒真假绿测试）。
     *
     * 直接落一条含 origin=platform 命中（含词本身 + snippet）的发布单，走 show / index
     * 两个真实 HTTP 出口，断言**整段响应体**都不出现平台红线词，且租户自己的命中照常可见。
     * 这条测试会在 B1 过滤逻辑（hidePlatformRiskMatches）被回退时直接转红。
     */
    #[Test]
    public function tenant_manual_publication_api_never_exposes_platform_word_in_risk_result(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('mp-b1', 'admin');
        config(['geo.manual_publish_enabled' => true]);

        TenantContext::set($tenant);
        $publication = ManualPublication::query()->create([
            'tenant_id' => $tenant->id,
            'persona_id' => 1,
            'created_by_user_id' => $admin->id,
            'platform' => 'zhihu',
            'content' => '作者自己写的内容，含租户自用词B。',
            'content_fingerprint' => hash('sha256', '作者自己写的内容，含租户自用词B。'),
            'status' => 'draft',
            'risk_result' => [
                'status' => 'blocked',
                'match_count' => 2,
                'matches' => [
                    [
                        'word' => '平台红线词P1',
                        'field' => 'content',
                        'count' => 1,
                        'severity' => 'blocked',
                        'category' => 'politics',
                        'origin' => ArticleRiskScanner::ORIGIN_PLATFORM,
                        'suggestion' => null,
                        'snippet' => '…平台红线词P1…',
                    ],
                    [
                        'word' => '租户自用词B',
                        'field' => 'content',
                        'count' => 1,
                        'severity' => 'blocked',
                        'category' => 'politics',
                        'origin' => ArticleRiskScanner::ORIGIN_TENANT,
                        'suggestion' => null,
                        'snippet' => '…租户自用词B…',
                    ],
                ],
            ],
        ]);
        TenantContext::forget();

        Sanctum::actingAs($admin, ['*']);

        // ── show 端点：整段响应体不得出现平台红线词 ──
        $resp = $this->forTenant($tenant)->getJson('/api/v1/manual-publications/'.$publication->id);
        $resp->assertStatus(200);
        // 走解码后的结构断言（响应体对非 ASCII 做了 \\uXXXX 转义，不能做字面串匹配）。
        $showMatches = $resp->json('data.risk_result.matches') ?? [];
        $showWords = array_column($showMatches, 'word');
        $this->assertNotContains('平台红线词P1', $showWords, 'show 响应泄露了平台红线词（B1 过滤失效）');
        $this->assertContains('租户自用词B', $showWords, '租户自己的命中应照常可见');
        $this->assertCount(1, $showMatches, 'risk_result 应只剩租户命中');

        // ── index 端点同样过滤 ──
        $indexResp = $this->forTenant($tenant)->getJson('/api/v1/manual-publications');
        $indexResp->assertStatus(200);
        $indexItems = $indexResp->json('data.data') ?? [];
        $indexWords = [];
        foreach ($indexItems as $item) {
            foreach (($item['risk_result']['matches'] ?? []) as $m) {
                $indexWords[] = $m['word'] ?? null;
            }
        }
        $this->assertNotContains('平台红线词P1', $indexWords, 'index 响应泄露了平台红线词（B1 过滤失效）');
    }

    #[Test]
    public function dry_run_enqueues_polls_and_reports_completed(): void
    {
        Sanctum::actingAs($this->platformAdmin(), ['*']);

        $resp = $this->postJson('/api/v1/platform/sensitive-words/dry-run', [
            'words' => "候选词X\n候选词Y",
        ])->assertStatus(202);
        $id = $resp->json('data.id');
        $this->assertNotNull($id);

        // 同步跑掉队列任务（测试环境不依赖 worker）
        (new DryRunPlatformSensitiveWordsJob($id))->handle(app(ArticleRiskScanner::class));

        $this->getJson('/api/v1/platform/sensitive-words/dry-run/'.$id)
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.candidates.0', '候选词X')
            ->assertJsonPath('data.by_word.0.word', '候选词X');
    }

    #[Test]
    public function dry_run_rejects_more_than_max_candidates(): void
    {
        Sanctum::actingAs($this->platformAdmin(), ['*']);

        $words = implode("\n", range(1, PlatformSensitiveWordDryRun::MAX_CANDIDATES + 1));
        $this->postJson('/api/v1/platform/sensitive-words/dry-run', ['words' => $words])
            ->assertStatus(422);
    }

    /**
     * 出站闸门：含平台红线词的稿子被拦，且要留完整审计痕迹（T6 + T7 + T10）。
     *
     * 这是本批最关键的闸门。原交付报告第五节声称「证明闸门逻辑不是恒绿假阳性」，
     * 但 6 个变异全落在 T1/T3/T4/T8，没有一个碰 complianceGate。下面这条 + 后续的
     * fail-close / process / publish 测试共同构成对合规闸门的真实覆盖——
     * 在 complianceGate() 首行插 `return null;` 会让它们至少转红。
     */
    #[Test]
    public function compliance_gate_blocks_article_with_platform_word_and_writes_audit_trail(): void
    {
        [$tenant, $admin, $project] = $this->tenantProject('gate-block');
        $article = $this->article($tenant, $project, $admin, '标题含敏感词ABC的正文');
        PlatformSensitiveWord::query()->create([
            'word' => '敏感词ABC', 'severity' => 'blocked', 'category' => 'politics',
        ]);

        TenantContext::set($tenant);
        $orchestrator = app(DistributionOrchestrator::class);
        $block = $orchestrator->complianceGate($article);
        TenantContext::forget();

        $this->assertIsArray($block);
        $this->assertTrue($block['blocked'], '含平台红线词的稿子应被拦下');
        $this->assertSame('sensitive_word', $block['reason']);
        $this->assertSame([], $block['publications'], '被拦下不应有任何 publication');

        // T7 命中日志：平台来源、具体词、trigger=distribute（评审项：缺 4 条 T6 断言之一）
        $this->assertTrue(
            SensitiveWordHit::query()
                ->where('origin', SensitiveWordHit::ORIGIN_PLATFORM)
                ->where('word', '敏感词ABC')
                ->where('trigger', SensitiveWordHit::TRIGGER_DISTRIBUTE)
                ->exists(),
            '出站命中未写入 sensitive_word_hits（T7）'
        );

        // distribution_logs 记 compliance_blocked，而非 scan_failed（两事件语义必须分得开）
        $this->assertTrue(
            DistributionLog::query()->where('event', 'compliance_blocked')->where('article_id', $article->id)->exists(),
            '分发日志应记 compliance_blocked'
        );
        $this->assertSame(
            0,
            DistributionLog::query()->where('event', 'compliance_scan_failed')->where('article_id', $article->id)->count(),
            '不应误记成 compliance_scan_failed'
        );

        // T10 作者可见落点：flagged_sensitive + 固定不点名文案
        $article->refresh();
        $this->assertTrue((bool) $article->flagged_sensitive);
        $this->assertSame(ArticleRiskScanner::PLATFORM_BLOCK_NOTICE, $article->distribution_blocked_reason);
    }

    /**
     * 出站闸门 fail-close：扫描本身抛异常时**也不入队**（读不到规则 ≠ 内容干净）。
     *
     * 直接 mock ArticleRiskScanner::record 抛异常，验证闸门兜底路径——
     * 这是审查指出的「4 条 T6 断言从未写」里的 scan-exception 分支。
     */
    #[Test]
    public function compliance_gate_fails_closed_on_scan_exception_and_blocks_enqueue(): void
    {
        [$tenant, $admin, $project] = $this->tenantProject('gate-fail');
        $article = $this->article($tenant, $project, $admin, '普通标题无敏感词');

        // mock 掉 ArticleRiskScanner，让 record() 抛异常（模拟词表读失败）
        $scanner = $this->mock(ArticleRiskScanner::class);
        $scanner->shouldReceive('record')->andThrow(new \RuntimeException('scan boom'));

        TenantContext::set($tenant);
        $orchestrator = app(DistributionOrchestrator::class);

        $block = $orchestrator->complianceGate($article);
        $this->assertTrue($block['blocked'], '扫描异常时也应 fail-close 拦下');
        $this->assertSame('scan_failed', $block['reason']);
        $this->assertSame([], $block['publications']);

        // 扫描失败时同样不得入队（评审查的「scan-exception still not enqueued」）
        $result = $orchestrator->enqueueForArticle($article);
        $this->assertTrue($result['blocked']);
        $this->assertSame([], $result['publications']);
        $this->assertSame(
            0,
            ArticlePublication::query()->where('article_id', $article->id)->count(),
            '扫描失败仍不应产生任何 ArticlePublication'
        );

        TenantContext::forget();
    }

    /**
     * enqueueForArticle 走真实扫描：被平台红线词拦下的稿子**不**产生任何 ArticlePublication。
     */
    #[Test]
    public function enqueue_for_article_is_blocked_and_not_enqueued_for_platform_word(): void
    {
        [$tenant, $admin, $project] = $this->tenantProject('gate-enqueue');
        $article = $this->article($tenant, $project, $admin, '标题含敏感词ENQ');
        PlatformSensitiveWord::query()->create([
            'word' => '敏感词ENQ', 'severity' => 'blocked', 'category' => 'politics',
        ]);

        TenantContext::set($tenant);
        $orchestrator = app(DistributionOrchestrator::class);
        $result = $orchestrator->enqueueForArticle($article);
        TenantContext::forget();

        $this->assertTrue($result['blocked']);
        $this->assertSame([], $result['publications']);
        $this->assertSame(
            0,
            ArticlePublication::query()->where('article_id', $article->id)->count(),
            '被平台红线词拦下的稿子不应产生任何 ArticlePublication'
        );
    }

    /**
     * B2：手动发布路径（ArticlePublicationService::publish）也过同一道出站闸门，
     * 含平台红线词的稿子在此抛 ValidationException，绝不发送到客户站点。
     */
    #[Test]
    public function article_publication_service_publish_throws_for_blocked_article(): void
    {
        [$tenant, $admin, $project] = $this->tenantProject('gate-publish');
        $article = $this->article($tenant, $project, $admin, '标题含敏感词PUB');
        PlatformSensitiveWord::query()->create([
            'word' => '敏感词PUB', 'severity' => 'blocked', 'category' => 'politics',
        ]);

        TenantContext::set($tenant);
        $channel = PublishingChannel::query()->create([
            'tenant_id' => $tenant->id,
            'name' => '测试渠道',
            'type' => PublishingChannel::TYPE_HTTP_WEBHOOK,
            'endpoint_url' => 'https://example.test/hook',
            'is_active' => true,
        ]);

        $service = app(ArticlePublicationService::class);
        $this->expectException(ValidationException::class);

        try {
            $service->publish($article, $channel, $admin);
        } finally {
            TenantContext::forget();
        }
    }

    /**
     * B2（防御）：队列真实发送点 process() 在发前再卡一次合规闸门，
     * 命中平台红线词时把 publication 标 FAILED（last_error=固定不点名文案），
     * 返回 message=blocked_by_sensitive_word，绝不落到外部 HTTP 发送。
     */
    #[Test]
    public function process_marks_publication_failed_when_blocked_by_sensitive_word(): void
    {
        [$tenant, $admin, $project] = $this->tenantProject('gate-process');
        $article = $this->article($tenant, $project, $admin, '标题含敏感词PROC');
        PlatformSensitiveWord::query()->create([
            'word' => '敏感词PROC', 'severity' => 'blocked', 'category' => 'politics',
        ]);

        TenantContext::set($tenant);
        $channel = PublishingChannel::query()->create([
            'tenant_id' => $tenant->id,
            'name' => '测试渠道',
            'type' => PublishingChannel::TYPE_HTTP_WEBHOOK,
            'endpoint_url' => 'https://example.test/hook',
            'is_active' => true,
        ]);
        $publication = ArticlePublication::query()->create([
            'tenant_id' => $tenant->id,
            'article_id' => $article->id,
            'publishing_channel_id' => $channel->id,
            'status' => ArticlePublication::STATUS_QUEUED,
            'attempt_count' => 0,
        ]);

        $orchestrator = app(DistributionOrchestrator::class);
        $result = $orchestrator->process($publication);
        TenantContext::forget();

        $this->assertFalse($result['ok']);
        $this->assertSame('blocked_by_sensitive_word', $result['message']);
        $publication->refresh();
        $this->assertSame(ArticlePublication::STATUS_FAILED, $publication->status);
        $this->assertSame(ArticleRiskScanner::PLATFORM_BLOCK_NOTICE, $publication->last_error);
    }

    /**
     * A-1 返工：executePublish 的出站闸门（ArticlePublicationService:189）。
     *
     * 为什么要单独守这一处：executePublish 是 PublishArticleJob 真正把稿子发出去的那个方法，
     * publish() / dispatchPublish() 两处已经有测试守着，**只有这个真实发送点原本是裸的**。
     * 整段闸门删掉后全仓依然全绿 —— 等于它随时可能被下一个人改掉而无人察觉。
     *
     * 三个前提缺一不可，否则测试会「绿得不是因为闸门」：
     * - 渠道 is_active = true（否则在闸门之前就被 markFailed('发布渠道已停用。') 拦下）
     * - 文章与渠道都真实存在（否则倒在 ! $article || ! $channel 那条分支）
     * - 全程有租户上下文（rules() 无上下文会 fail-close 抛异常）
     */
    #[Test]
    public function execute_publish_marks_publication_failed_and_sends_nothing_when_blocked(): void
    {
        [$tenant, $admin, $project] = $this->tenantProject('gate-exec');
        $article = $this->article($tenant, $project, $admin, '标题含敏感词EXEC');
        PlatformSensitiveWord::query()->create([
            'word' => '敏感词EXEC', 'severity' => 'blocked', 'category' => 'politics',
        ]);

        TenantContext::set($tenant);
        $channel = PublishingChannel::query()->create([
            'tenant_id' => $tenant->id,
            'name' => '测试渠道',
            'type' => PublishingChannel::TYPE_HTTP_WEBHOOK,
            'endpoint_url' => 'https://example.test/hook',
            'is_active' => true,
        ]);
        $publication = ArticlePublication::query()->create([
            'tenant_id' => $tenant->id,
            'article_id' => $article->id,
            'publishing_channel_id' => $channel->id,
            'status' => ArticlePublication::STATUS_QUEUED,
            'attempt_count' => 0,
            'metadata' => ['article_version' => $article->fresh()->version],
        ]);

        Http::fake();

        try {
            // executePublish 不抛异常，命中闸门时走 markFailed() 返回。
            $result = app(ArticlePublicationService::class)->executePublish($publication);
        } finally {
            TenantContext::forget();
        }

        $this->assertSame(ArticlePublication::STATUS_FAILED, $result->status);
        $this->assertSame(ArticleRiskScanner::PLATFORM_BLOCK_NOTICE, $result->last_error);
        // 状态对不等于没发出去：闸门若被挪到发送之后，上面两条照样绿，只有这条会红。
        Http::assertNothingSent();
    }

    /**
     * 返工 2（HTTP 实测）：四条「点按钮发布」路由全部过同一道出站闸门，
     * 含平台红线词的稿子被打回 422，且**任何外部 HTTP 请求都没发出去**（assertNothingSent）。
     * 这四条路由是用户日常真正会走的出站路径，原报告 T6 只覆盖了自动分发。
     */
    #[Test]
    public function http_publish_route_blocked_and_sends_nothing(): void
    {
        [$tenant, $admin, $project] = $this->tenantProject('http-pub');
        $article = $this->approvedArticle($tenant, $project, $admin, '标题含敏感词HTTPPUB');
        $channel = PublishingChannel::query()->create([
            'tenant_id' => $tenant->id, 'name' => 'Webhook', 'type' => PublishingChannel::TYPE_HTTP_WEBHOOK,
            'endpoint_url' => 'https://hooks.example.test/pub', 'auth_type' => 'none', 'is_active' => true,
        ]);
        PlatformSensitiveWord::query()->create(['word' => '敏感词HTTPPUB', 'severity' => 'blocked', 'category' => 'politics']);

        Sanctum::actingAs($admin, ['*']);
        Http::fake();

        $resp = $this->forTenant($tenant)
            ->postJson("/api/v1/articles/{$article->id}/publish", ['channel_id' => $channel->id])
            ->assertStatus(422);

        $this->assertSame(ArticleRiskScanner::PLATFORM_BLOCK_NOTICE, $resp->json('errors.article_id.0'));
        $this->assertStringNotContainsString('敏感词HTTPPUB', json_encode($resp->json(), JSON_UNESCAPED_UNICODE));
        Http::assertNothingSent();
        $this->assertSame(0, ArticlePublication::query()->where('article_id', $article->id)->count(), '被拦不应建 publication 记录');
    }

    #[Test]
    public function http_publish_async_route_blocked_and_sends_nothing(): void
    {
        [$tenant, $admin, $project] = $this->tenantProject('http-async');
        $article = $this->approvedArticle($tenant, $project, $admin, '标题含敏感词HTTPASYNC');
        $channel = PublishingChannel::query()->create([
            'tenant_id' => $tenant->id, 'name' => 'Webhook', 'type' => PublishingChannel::TYPE_HTTP_WEBHOOK,
            'endpoint_url' => 'https://hooks.example.test/async', 'auth_type' => 'none', 'is_active' => true,
        ]);
        PlatformSensitiveWord::query()->create(['word' => '敏感词HTTPASYNC', 'severity' => 'blocked', 'category' => 'politics']);

        Sanctum::actingAs($admin, ['*']);
        Http::fake();

        $resp = $this->forTenant($tenant)
            ->postJson("/api/v1/articles/{$article->id}/publish-async", ['channel_id' => $channel->id])
            ->assertStatus(422);

        $this->assertStringNotContainsString('敏感词HTTPASYNC', json_encode($resp->json(), JSON_UNESCAPED_UNICODE));
        Http::assertNothingSent();
        $this->assertSame(0, ArticlePublication::query()->where('article_id', $article->id)->count());
    }

    #[Test]
    public function http_publish_batch_route_blocked_and_reports_no_word(): void
    {
        [$tenant, $admin, $project] = $this->tenantProject('http-batch');
        $article = $this->approvedArticle($tenant, $project, $admin, '标题含敏感词HTTPBATCH');
        $channel = PublishingChannel::query()->create([
            'tenant_id' => $tenant->id, 'name' => 'Webhook', 'type' => PublishingChannel::TYPE_HTTP_WEBHOOK,
            'endpoint_url' => 'https://hooks.example.test/batch', 'auth_type' => 'none', 'is_active' => true,
        ]);
        PlatformSensitiveWord::query()->create(['word' => '敏感词HTTPBATCH', 'severity' => 'blocked', 'category' => 'politics']);

        Sanctum::actingAs($admin, ['*']);
        Http::fake();

        // 同步（async=false）与异步（async=true）两条分支都走闸门，各测一次。
        foreach ([false, true] as $async) {
            $resp = $this->forTenant($tenant)
                ->postJson("/api/v1/articles/{$article->id}/publish-batch", [
                    'channel_ids' => [$channel->id], 'async' => $async,
                ])
                ->assertStatus(422);
            $this->assertStringNotContainsString('敏感词HTTPBATCH', json_encode($resp->json(), JSON_UNESCAPED_UNICODE));
        }
        Http::assertNothingSent();
        $this->assertSame(0, ArticlePublication::query()->where('article_id', $article->id)->count());
    }

    #[Test]
    public function http_publish_retry_route_blocked_and_sends_nothing(): void
    {
        [$tenant, $admin, $project] = $this->tenantProject('http-retry');
        $article = $this->approvedArticle($tenant, $project, $admin, '标题含敏感词HTTPRETRY');
        $channel = PublishingChannel::query()->create([
            'tenant_id' => $tenant->id, 'name' => 'Webhook', 'type' => PublishingChannel::TYPE_HTTP_WEBHOOK,
            'endpoint_url' => 'https://hooks.example.test/retry', 'auth_type' => 'none', 'is_active' => true,
        ]);
        PlatformSensitiveWord::query()->create(['word' => '敏感词HTTPRETRY', 'severity' => 'blocked', 'category' => 'politics']);

        // 预先存在一条 publication（retry 路由要求），证明重试路径也被闸门拦下、且不新建记录。
        $publication = ArticlePublication::query()->create([
            'tenant_id' => $tenant->id, 'article_id' => $article->id,
            'publishing_channel_id' => $channel->id, 'status' => ArticlePublication::STATUS_QUEUED, 'attempt_count' => 0,
        ]);

        Sanctum::actingAs($admin, ['*']);
        Http::fake();

        $resp = $this->forTenant($tenant)
            ->postJson("/api/v1/articles/{$article->id}/publications/{$publication->id}/retry")
            ->assertStatus(422);

        $this->assertSame(ArticleRiskScanner::PLATFORM_BLOCK_NOTICE, $resp->json('errors.article_id.0'));
        $this->assertStringNotContainsString('敏感词HTTPRETRY', json_encode($resp->json(), JSON_UNESCAPED_UNICODE));
        Http::assertNothingSent();
        // 只应有预先存在的那一条，且闸门没有把它改成已发送。
        $this->assertSame(1, ArticlePublication::query()->where('article_id', $article->id)->count());
        $this->assertSame(ArticlePublication::STATUS_QUEUED, $publication->fresh()->status);
    }

    /**
     * Issue 5：article_risk_scans 表（出站闸门经 record() 高频写入）必须有 90 天清理，
     * 否则只进不出撑爆库。验证 prune 命令只删超龄记录、保留近期记录。
     */
    #[Test]
    public function article_risk_scans_prune_deletes_only_old_records(): void
    {
        [$tenant] = $this->makeTenantUser('prune-scan', 'admin');

        TenantContext::set($tenant);
        $old = ArticleRiskScan::query()->create([
            'tenant_id' => $tenant->id,
            'trigger' => 'distribute',
            'content_hash' => str_repeat('a', 64),
            'dictionary_hash' => str_repeat('b', 64),
            'status' => 'clean',
            'scanned_at' => now()->subDays(120),
        ]);
        $recent = ArticleRiskScan::query()->create([
            'tenant_id' => $tenant->id,
            'trigger' => 'distribute',
            'content_hash' => str_repeat('c', 64),
            'dictionary_hash' => str_repeat('d', 64),
            'status' => 'clean',
            'scanned_at' => now()->subDay(),
        ]);
        TenantContext::forget();

        $this->assertSame(2, ArticleRiskScan::query()->count());

        $this->artisan('article-risk-scans:prune', ['--days' => 90])->assertExitCode(0);

        $this->assertSame(1, ArticleRiskScan::query()->count(), '只应删掉超过 90 天的旧扫描记录');
        $this->assertNotNull($recent->fresh(), '近期的扫描记录应保留');
        $this->assertNull($old->fresh(), '120 天前的旧扫描记录应被清理');
    }
}
