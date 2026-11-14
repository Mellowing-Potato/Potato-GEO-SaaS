<?php

namespace Tests\Feature;

use App\Services\DTOs\SkillBrief;
use App\Services\SkillAgent\SkillAgentRunnerService;
use App\Services\SkillAgent\SkillDeliveryAssembler;
use Tests\TestCase;

/**
 * 交付内容组装 · 特征化测试（计划 §7 第 3 步：`SkillAgentRunnerService` 按职责拆小的第六桶）。
 *
 * 模型产出之后、交给渲染脚本与文章库之前的那几件纯准备工作搬进了 `SkillDeliveryAssembler`：
 * 补品牌字段 / 全景输入补齐 / 注入表头、把 explainer 结构化输出拼成可发布正文、
 * 给质量报告补两条 publishable 检查、按谱顺序拼 markdown 报告。
 *
 * 冻结的是"交付出去的东西长什么样"：正文拼法、字符数闸、品牌字段补齐、没写完的节怎么标注。
 */
class SkillDeliveryAssemblerTest extends TestCase
{
    private function brief(): SkillBrief
    {
        return new SkillBrief(
            skillId: 'yao-geo-explainer-builder',
            projectId: 1,
            userId: 2,
            fields: ['brand_name' => '示例品牌', 'brand_url' => 'https://example.test'],
            query: null,
        );
    }

    public function test_publishable_article_content_is_frozen(): void
    {
        $assembler = app(SkillDeliveryAssembler::class);

        $content = $assembler->publishableArticleContent('yao-geo-explainer-builder', [
            'title' => '示例标题',
            'article_sections' => [
                ['heading' => '第一节', 'body' => '第一段正文。'],
                ['heading' => '第二节', 'body' => ['第二段第一句。', '第二段第二句。']],
            ],
        ], 'FALLBACK');

        $this->assertSame(
            "# 示例标题\n\n## 第一节\n\n第一段正文。\n\n## 第二节\n\n第二段第一句。\n\n第二段第二句。\n",
            $content,
        );

        // paragraphs 形状（另一种节结构）同样要认
        $paragraphs = $assembler->publishableArticleContent('yao-geo-explainer-builder', [
            'title' => 'T',
            'article_sections' => [['heading' => 'H', 'paragraphs' => ['p1', 'p2']]],
        ], 'FALLBACK');
        $this->assertStringContainsString('## H', $paragraphs);
        $this->assertStringContainsString('p1', $paragraphs);
        $this->assertStringContainsString('p2', $paragraphs);

        // 只对 explainer 生效；标题或分节缺失时一律回落到 fallback（不许拼出半截正文）
        $this->assertSame('FALLBACK', $assembler->publishableArticleContent('yao-geo-panorama-audit', ['title' => 'T', 'article_sections' => [['heading' => 'H', 'body' => 'B']]], 'FALLBACK'));
        $this->assertSame('FALLBACK', $assembler->publishableArticleContent('yao-geo-explainer-builder', ['article_sections' => [['heading' => 'H', 'body' => 'B']]], 'FALLBACK'));
    }

    public function test_publishable_article_checks_are_frozen(): void
    {
        $assembler = app(SkillDeliveryAssembler::class);
        $report = ['passed' => true, 'score' => 80, 'checks' => [['name' => '内容长度', 'passed' => true]]];

        // 2500 字（去空白后）落在 2000–4000 区间 → 字符数检查通过
        $ok = $assembler->withPublishableChecks($report, 'yao-geo-explainer-builder', str_repeat('字', 2500));
        $chars = collect($ok['checks'])->firstWhere('name', 'publishable_article_2000_to_4000_chars');
        $this->assertSame(2500, $chars['chars']);
        $this->assertTrue($chars['passed']);
        $this->assertSame(2000, $chars['minimum']);
        $this->assertSame(4000, $chars['maximum']);
        // 还必须有"正文与审查一致"这条；它没通过时整份报告判不通过并给出待办
        $this->assertFalse($ok['passed'], '新增检查未通过时报告不能判通过');
        $this->assertContains('publishable_article_matches_review', $ok['issues']);
        $this->assertSame('修复后重新检查', $ok['next_action']);

        $short = $assembler->withPublishableChecks($report, 'yao-geo-explainer-builder', str_repeat('字', 1500));
        $this->assertContains('publishable_article_2000_to_4000_chars', $short['issues'], '低于 2000 字必须进待办');

        // 非 explainer 技能不动报告
        $untouched = $assembler->withPublishableChecks($report, 'yao-geo-panorama-audit', '短');
        $this->assertSame($report, $untouched);
    }

    public function test_brand_enrichment_fills_only_missing_fields(): void
    {
        $assembler = app(SkillDeliveryAssembler::class);

        $filled = $assembler->enrichWithBrandInfo([], $this->brief());
        $this->assertSame('示例品牌', $filled['brand_name']);
        $this->assertSame('示例品牌', $filled['brand']);
        $this->assertSame('https://example.test', $filled['brand_url']);
        $this->assertSame('https://example.test', $filled['website']);
        $this->assertArrayHasKey('generated_at', $filled);
        $this->assertArrayHasKey('report_date', $filled);

        // 已有值不许被覆盖（用户填的优先）
        $kept = $assembler->enrichWithBrandInfo(['brand_name' => '已有', 'website' => 'https://existing.test'], $this->brief());
        $this->assertSame('已有', $kept['brand_name']);
        $this->assertSame('https://existing.test', $kept['website']);
        $this->assertSame('示例品牌', $kept['brand'], '没填过的字段照常补上');
    }

    public function test_markdown_report_assembly_marks_unfinished_sections(): void
    {
        $assembler = app(SkillDeliveryAssembler::class);
        $specs = [
            ['key' => 'one', 'label' => '第一节', 'fields' => ['s1']],
            ['key' => 'two', 'label' => '第二节', 'fields' => ['s2']],
            ['key' => 'three', 'label' => '第三节', 'fields' => ['s3']],
        ];

        // 按谱顺序拼（不是按写完顺序）；没写完但有半截的节要显式标注
        $report = $assembler->assembleMarkdownReport($specs, ['s1' => '第一节正文', 's2' => '第二节正文'], ['three' => '半截内容']);
        $this->assertSame(
            "第一节正文\n\n第二节正文\n\n> ⚠️ 「第三节」这一节没有写完，以下是已经写出来的部分。\n\n半截内容",
            $report,
        );

        // 没有半截就整节跳过 —— 绝不能不加标记地混进去
        $this->assertSame('第一节正文', $assembler->assembleMarkdownReport($specs, ['s1' => '第一节正文'], []));
    }

    public function test_prepare_structured_input_injects_headers_and_publishable_body(): void
    {
        $assembler = app(SkillDeliveryAssembler::class);
        $brief = $this->brief();

        $prepared = $assembler->prepareStructuredInput(['title' => 'T'], $brief);
        $this->assertSame(['title', 'brand_name', 'brand', 'brand_url', 'website', 'generated_at', 'report_date', '_user_provided_facts', 'publishable_article_markdown'], array_keys($prepared));
        $this->assertIsArray($prepared['_user_provided_facts'], '用户提供的事实要单独留一份给渲染与核对');
        $this->assertArrayHasKey('publishable_article_markdown', $prepared);
    }

    /** 服务侧可见行为不变（`buildPublishableArticleContent` 是公开入口，测试直接调它）。 */
    public function test_service_public_entry_point_is_unchanged(): void
    {
        $service = app(SkillAgentRunnerService::class);

        $content = $service->buildPublishableArticleContent('yao-geo-explainer-builder', [
            'title' => 'GEO 服务是什么',
            'core_answer' => '这是直接回答。',
            'article_sections' => [
                ['heading' => '第一节', 'paragraphs' => ['第一段。', '第二段。']],
                ['heading' => '第二节', 'body' => '第三段。'],
            ],
        ], 'FALLBACK');

        $this->assertStringStartsWith('# GEO 服务是什么', $content);
        $this->assertStringContainsString('## 第一节', $content);
        $this->assertStringContainsString('第二段。', $content);
        $this->assertStringContainsString('第三段。', $content);
    }
}
