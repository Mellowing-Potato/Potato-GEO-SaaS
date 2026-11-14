<?php

namespace Tests\Feature;

use App\Models\SensitiveWord;
use App\Models\Tenant;
use App\Services\Publishing\ArticleRiskScanner;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 单 7 · ArticleRiskScanner 复刻验收（执行书 §三-3：迁完必须补一条预置踩线样本测试）。
 *
 * 上游参考 tests/Feature/ArticleRiskScannerTest.php（判据借来，代码按本仓模型适配）。
 */
class ArticleRiskScannerTest extends TestCase
{
    use RefreshDatabase;

    private ArticleRiskScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $tenant = Tenant::query()->create([
            'subdomain' => 'scanner-test',
            'name' => 'Scanner Test',
            'plan' => 'basic',
            'status' => 'active',
        ]);
        TenantContext::set($tenant);

        $this->scanner = app(ArticleRiskScanner::class);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();

        parent::tearDown();
    }

    public function test_preset_blocked_sample_is_detected(): void
    {
        SensitiveWord::query()->create([
            'word' => '绝对安全保本',
            'severity' => 'blocked',
            'category' => 'compliance',
            'suggestion' => '删除绝对化承诺用语。',
        ]);

        $result = $this->scanner->scan([
            'title' => '理财产品介绍',
            'excerpt' => '本产品绝对安全保本，放心购买。',
            'content' => '正规内容，不含踩线词。',
            'keywords' => '',
            'meta_description' => '',
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame(1, $result['match_count']);
        $this->assertSame('excerpt', $result['matches'][0]['field']);
        $this->assertSame('blocked', $result['matches'][0]['severity']);
        $this->assertSame('compliance', $result['matches'][0]['category']);
        $this->assertStringContainsString('绝对安全保本', $result['matches'][0]['snippet']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['content_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['dictionary_hash']);
    }

    public function test_warning_sample_is_detected_without_blocking(): void
    {
        SensitiveWord::query()->create([
            'word' => '行业第一',
            'severity' => 'warning',
            'category' => 'advertising_law',
        ]);

        $result = $this->scanner->scan([
            'title' => '我们是行业第一的服务商',
            'excerpt' => '',
            'content' => '正文没有踩线词。',
            'keywords' => '',
            'meta_description' => '',
        ]);

        $this->assertSame('warning', $result['status']);
        $this->assertSame(1, $result['match_count']);
        $this->assertSame('title', $result['matches'][0]['field']);
    }

    public function test_matches_unicode_case_insensitively(): void
    {
        SensitiveWord::query()->create([
            'word' => 'MÜNCHEN',
            'category' => 'location',
            'suggestion' => 'Use a broader location.',
        ]);

        $result = $this->scanner->scan([
            'title' => 'A guide to münchen',
            'excerpt' => 'München and MÜNCHEN highlights',
            'content' => 'No match here.',
            'keywords' => '',
            'meta_description' => '',
        ]);

        $this->assertSame('warning', $result['status']);
        $this->assertSame(3, $result['match_count']);
        $this->assertSame(['title', 'excerpt'], array_column($result['matches'], 'field'));
        $this->assertSame([1, 2], array_column($result['matches'], 'count'));
    }

    public function test_blocked_rule_beats_separator_obfuscation(): void
    {
        SensitiveWord::query()->create([
            'word' => '内幕消息',
            'severity' => 'blocked',
        ]);

        // 用标点/空隙拆词的隐写变体也必须命中（compact fold）
        $result = $this->scanner->scan([
            'title' => '',
            'excerpt' => '',
            'content' => '悄悄告诉你，内 幕*消 息来了。',
            'keywords' => '',
            'meta_description' => '',
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame(1, $result['match_count']);
    }

    public function test_ignores_disabled_rules(): void
    {
        SensitiveWord::query()->create([
            'word' => '违规词',
            'severity' => 'blocked',
            'is_enabled' => false,
        ]);

        $result = $this->scanner->scan([
            'title' => '这里写着违规词',
            'excerpt' => '',
            'content' => '',
            'keywords' => '',
            'meta_description' => '',
        ]);

        $this->assertSame('clean', $result['status']);
        $this->assertSame(0, $result['match_count']);
    }

    public function test_clean_content_returns_clean_status(): void
    {
        SensitiveWord::query()->create(['word' => '不存在的词']);

        $result = $this->scanner->scan([
            'title' => 'A safe title',
            'excerpt' => 'A safe excerpt',
            'content' => 'Nothing risky here.',
            'keywords' => '',
            'meta_description' => '',
        ]);

        $this->assertSame('clean', $result['status']);
        $this->assertSame(0, $result['match_count']);
        $this->assertSame([], $result['matches']);
    }

    public function test_applies_to_scopes_rule_to_configured_field_only(): void
    {
        SensitiveWord::query()->create([
            'word' => '敏感词X',
            'severity' => 'blocked',
            'applies_to' => ['title'],
        ]);

        // 仅在 content 出现 → 因 applies_to 限定 title，不应命中（回归单7：applies_to 作用域曾失效）
        $clean = $this->scanner->scan([
            'title' => '正常标题',
            'excerpt' => '',
            'content' => '正文里有敏感词X，但不应被扫描到。',
            'keywords' => '',
            'meta_description' => '',
        ]);
        $this->assertSame('clean', $clean['status']);
        $this->assertSame(0, $clean['match_count']);

        // 仅在 title 出现 → 命中
        $hit = $this->scanner->scan([
            'title' => '标题含敏感词X',
            'excerpt' => '',
            'content' => '正文干净。',
            'keywords' => '',
            'meta_description' => '',
        ]);
        $this->assertSame('blocked', $hit['status']);
        $this->assertSame(1, $hit['match_count']);
        $this->assertSame('title', $hit['matches'][0]['field']);
    }

    /**
     * 评审项 10：content 字段（body_markdown）必须走真 Markdown 渲染管线
     * （Str::markdown → 块级标签转空格 → strip_tags）再提取可见文本。
     * 用真实 GFM 文档（标题/加粗/链接/表格/代码块）锁定渲染路径不丢检出。
     */
    public function test_content_markdown_is_rendered_to_visible_text(): void
    {
        SensitiveWord::query()->create([
            'word' => '绝对安全保本',
            'severity' => 'blocked',
            'category' => 'compliance',
        ]);
        SensitiveWord::query()->create([
            'word' => '表格机密词',
            'severity' => 'blocked',
            'category' => 'compliance',
        ]);

        $content = implode("\n", [
            '# 产品说明',
            '',
            '本方案**绝对安全保本**，欢迎咨询。',
            '[官网](https://example.com)',
            '',
            '| 项目 | 说明 |',
            '| --- | --- |',
            '| 表格机密词 | 无 |',
            '',
            '```',
            '合规提示代码块',
            '```',
        ]);

        $result = $this->scanner->scan([
            'title' => '标题',
            'excerpt' => '摘要',
            'content' => $content,
            'keywords' => '',
            'meta_description' => '',
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame(2, $result['match_count']);

        $words = array_column($result['matches'], 'word');
        $this->assertEqualsCanonicalizing(['绝对安全保本', '表格机密词'], $words);

        foreach ($result['matches'] as $match) {
            $this->assertSame('content', $match['field']);
            $this->assertStringContainsString($match['word'], $match['snippet']);
        }
    }

    public function test_rule_cache_version_bumps_on_rule_change(): void
    {
        $before = SensitiveWord::ruleCacheVersion();

        SensitiveWord::query()->create(['word' => '新词']);

        // afterCommit 在测试（无事务）里立即执行
        $this->assertGreaterThan($before, SensitiveWord::ruleCacheVersion());
    }

    /**
     * 评审阻断项 1：词表缓存必须按租户隔离。
     * B 租户绝不能扫到 A 租户的词表（含 suggestion 原文泄露）。
     */
    public function test_rule_cache_is_isolated_per_tenant(): void
    {
        // A 租户有一条 blocked 词（带内部整改建议）
        SensitiveWord::query()->create([
            'word' => 'A家机密拦截词',
            'severity' => 'blocked',
            'category' => 'compliance',
            'suggestion' => 'A 家内部整改建议：删掉这句。',
        ]);

        $payload = [
            'title' => '标题',
            'excerpt' => '文中出现A家机密拦截词。',
            'content' => '正文。',
            'keywords' => '',
            'meta_description' => '',
        ];

        // A 先扫（暖缓存）
        $aResult = $this->scanner->scan($payload);
        $this->assertSame('blocked', $aResult['status']);
        $this->assertSame(1, $aResult['match_count']);

        // 切到 B 租户：词表 0 行，必须 clean
        $tenantB = Tenant::query()->create([
            'subdomain' => 'scanner-test-b',
            'name' => 'Scanner Test B',
            'plan' => 'basic',
            'status' => 'active',
        ]);
        TenantContext::set($tenantB);

        $bResult = $this->scanner->scan($payload);
        $this->assertSame('clean', $bResult['status']);
        $this->assertSame(0, $bResult['match_count']);
        $this->assertSame([], $bResult['matches']);
    }

    /**
     * 评审项 6：record()/isFresh() 曾是零测试的死代码，且 record() 落库必炸
     * （建表无 created_at 列而模型 timestamps 开着）。此测试锁定落库+新鲜度判定全链路。
     */
    public function test_record_persists_scan_and_isFresh_validates(): void
    {
        $user = \App\Models\User::factory()->create([
            'tenant_id' => TenantContext::id(),

            'role' => 'editor',
        ]);

        $project = \App\Models\Project::query()->create([
            'owner_id' => $user->id,
            'name' => 'ARS Proj',
            'brand_name' => 'ARS',
            'brand_url' => 'https://ars.example.test',
            'settings' => [],
        ]);

        $article = \App\Models\Article::query()->create([
            'project_id' => $project->id,
            'author_id' => $user->id,
            'title' => '含行业第一的标题',
            'body_markdown' => '# 正文',
            'content_type' => 'general',
            'status' => 'published',
            'source_type' => 'manual',
            'metadata' => [],
        ]);

        SensitiveWord::query()->create([
            'word' => '行业第一',
            'severity' => 'warning',
            'category' => 'advertising_law',
        ]);

        $scan = $this->scanner->record($article, 'manual', $user->id);

        $this->assertSame('warning', $scan->status);
        $this->assertSame(1, $scan->match_count);
        $this->assertSame('manual', $scan->trigger);
        $this->assertSame((int) $article->id, (int) $scan->article_id);
        $this->assertNotNull($scan->scanned_at);

        // 内容未变 → 新鲜
        $this->assertTrue($this->scanner->isFresh($article, $scan->refresh()));

        // 词表变化（版本 bump → dictionary_hash 变）→ 不新鲜
        SensitiveWord::query()->create(['word' => '另一个新词']);
        $this->assertFalse($this->scanner->isFresh($article, $scan->refresh()));
    }
}
