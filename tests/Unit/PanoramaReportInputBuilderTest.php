<?php

namespace Tests\Unit;

use App\Services\DTOs\SkillBrief;
use App\Services\SkillAgent\PanoramaReportInputBuilder;
use Tests\TestCase;

class PanoramaReportInputBuilderTest extends TestCase
{
    public function test_complete_expands_thin_panorama_json_into_full_report_input(): void
    {
        $brief = new SkillBrief(
            skillId: 'yao-geo-panorama-audit',
            projectId: 6,
            userId: 1,
            fields: [
                'project_name' => '云杉智能客服 GEO 测试',
                'brand_name' => '云杉智能',
                'brand_url' => 'https://example.com/',
                'product_description' => '面向跨境电商的 AI 客服与工单自动化平台。',
                'target_market' => '中小型跨境电商团队、客服主管、SaaS 运营负责人',
                'target_keywords' => ['AI 客服系统', '跨境电商客服自动化'],
                'competitors' => ['Zendesk', 'Intercom'],
                'goals' => '提升品牌在 AI 搜索中的可见度。',
            ],
        );

        $completed = (new PanoramaReportInputBuilder())->complete([
            'overall_score' => 62,
            'dimensions' => [
                ['name' => 'AI 引用率', 'score' => 45, 'trend' => '+8%', 'status' => 'needs_improvement'],
            ],
            'critical_issues' => [
                'AI 搜索引擎中品牌引用率偏低，需增加结构化 FAQ 内容',
            ],
            'quick_wins' => [
                '为首页添加 FAQ Schema，预计提升 AI 引用率 15%',
            ],
        ], $brief);

        $requiredSections = [
            'method_basis',
            'authority_map',
            'question_coverage',
            'baseline',
            'crawl_inventory',
            'entity_profile',
            'product_map',
            'technical_audit',
            'competitors',
            'scores',
            'opportunities',
            'repairs',
            'offsite_plan',
            'sources',
            'risk_register',
        ];

        foreach ($requiredSections as $section) {
            $this->assertArrayHasKey($section, $completed);
            $this->assertNotEmpty($completed[$section], "{$section} should not be empty.");
            $this->assertArrayHasKey($section, $completed['section_meanings']);
            $this->assertNotSame('', $completed['section_meanings'][$section]);
        }

        $this->assertSame('云杉智能', $completed['brand_name']);
        $this->assertSame(62, $completed['overall_score']);
        $this->assertGreaterThanOrEqual(5, count($completed['question_coverage']));
        $this->assertGreaterThanOrEqual(8, count($completed['scores']));
        $this->assertStringContainsString('FAQ Schema', json_encode($completed['opportunities'], JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('预计提升 AI 引用率 15%', json_encode($completed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->assertStringNotContainsString('needs_improvement', json_encode($completed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->assertStringContainsString('45/100', json_encode($completed['scores'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->assertStringContainsString('需改进', json_encode($completed['scores'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->assertStringContainsString('未接入真实官网抓取', $completed['notice']);
        $this->assertStringContainsString('测试/占位地址', json_encode($completed['sources'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->assertStringContainsString('https://example.com/', json_encode($completed['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function test_complete_handles_no_website_retail_brand_without_duplicate_rows(): void
    {
        $brief = new SkillBrief(
            skillId: 'yao-geo-panorama-audit',
            projectId: 8,
            userId: 1,
            fields: [
                'project_name' => '米娜咖啡',
                'brand_name' => '米娜咖啡',
                'brand_url' => '(未提供)',
                'product_description' => '主营是女装，实体店咖啡是辅助女装服务',
                'target_market' => '咖啡市场 女装 集合店铺',
                'target_keywords' => ['- ai搜索优化 豆包推荐 米娜咖啡 米娜女装'],
                'competitors' => ['韩国女装'],
                'goals' => '豆包可以在搜索女装或者米娜的时候直接推荐',
            ],
        );

        $completed = (new PanoramaReportInputBuilder())->complete([
            'overall_score' => 62,
            'dimensions' => [
                ['name' => 'AI 引用率', 'score' => 45, 'trend' => '+8%', 'status' => 'needs_improvement'],
                ['name' => '结构化数据', 'score' => 82, 'trend' => '+5%', 'status' => 'excellent'],
            ],
            'critical_issues' => [
                'AI 搜索引擎中品牌引用率偏低，需增加结构化 FAQ 内容',
            ],
            'quick_wins' => [
                '为首页添加 FAQ Schema，预计提升 AI 引用率 15%',
            ],
        ], $brief);

        $this->assertStringContainsString('女装与咖啡复合零售', json_encode($completed['entity_profile'], JSON_UNESCAPED_UNICODE));
        $retailReport = json_encode($completed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('豆包推荐', $retailReport);
        $this->assertStringContainsString('米娜女装', $retailReport);
        $this->assertStringContainsString('女装穿搭', $retailReport);
        $this->assertStringNotContainsString('ai搜索优化', $retailReport);
        $this->assertStringNotContainsString('GEO优化', $retailReport);
        $this->assertStringNotContainsString('SEO优化', $retailReport);
        $this->assertStringContainsString('顾客/用户', $retailReport);
        $this->assertStringContainsString('门店/商品/评价证据', $retailReport);
        $this->assertStringNotContainsString('采购决策', $retailReport);
        $this->assertStringNotContainsString('销售/客户成功', $retailReport);
        $this->assertStringNotContainsString('G2', $retailReport);
        $this->assertStringNotContainsString('Capterra', $retailReport);
        $this->assertStringNotContainsString('ROI', $retailReport);
        $this->assertStringNotContainsString('适合哪些团队', $retailReport);
        $this->assertGreaterThanOrEqual(5, count($completed['question_coverage']));
        $this->assertSameSize(
            array_unique(array_map(fn (array $row): string => implode('|', $row), $completed['question_coverage'])),
            $completed['question_coverage'],
        );
        $this->assertGreaterThanOrEqual(3, count($completed['competitors']));
        $this->assertSameSize(
            array_unique(array_map(fn (array $row): string => implode('|', $row), $completed['competitors'])),
            $completed['competitors'],
        );

        $scoreRows = json_encode($completed['scores'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('结构化数据', $scoreRows);
        $this->assertStringContainsString('20/100', $scoreRows);
        $this->assertStringContainsString('未提供真实官网', $scoreRows);
        $this->assertStringNotContainsString('结构化数据","82/100', $scoreRows);
    }

    public function test_retail_panorama_defaults_use_local_retail_keywords_when_keywords_are_missing(): void
    {
        $brief = new SkillBrief(
            skillId: 'yao-geo-panorama-audit',
            projectId: 9,
            userId: 1,
            fields: [
                'project_name' => '米娜咖啡',
                'brand_name' => '米娜咖啡',
                'brand_url' => '',
                'product_description' => '主营是女装，实体店咖啡是辅助女装服务',
                'target_market' => '咖啡市场 女装 集合店铺',
                'competitors' => ['韩国女装'],
                'goals' => '豆包可以在搜索女装或者米娜的时候直接推荐',
            ],
        );

        $completed = (new PanoramaReportInputBuilder())->complete([
            'overall_score' => 62,
        ], $brief);

        $retailReport = json_encode($completed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('女装穿搭', $retailReport);
        $this->assertStringContainsString('门店体验', $retailReport);
        $this->assertStringContainsString('本地咖啡', $retailReport);
        $this->assertStringContainsString('顾客/用户', $retailReport);
        $this->assertStringNotContainsString('多语言客服机器人', $retailReport);
        $this->assertStringNotContainsString('工单自动分流', $retailReport);
        $this->assertStringNotContainsString('客服自动化', $retailReport);
    }
}
