<?php

namespace Tests\Unit;

use App\Services\SkillAgent\RenderSchemaRegistry;
use Tests\TestCase;

class PageBlueprintContractTest extends TestCase
{
    public function test_normalization_repairs_only_fixed_page_blueprint_contract_text(): void
    {
        $data = (new RenderSchemaRegistry)->normalizeInput('yao-geo-page-blueprint', [
            'title' => '四川 GEO 服务页蓝图',
            'brand_name' => '孟德智达',
            'analysis_date' => '2026-09-08',
            'summary_cards' => [['label' => '类型', 'value' => '服务页']],
            'sections' => [
                ['title' => '页面模块与信息架构', 'paragraphs' => ['原始业务内容']],
                ['title' => 'Schema 建议', 'bullets' => ['Schema 只描述正文事实']],
                ['title' => '结尾', 'paragraphs' => ['保留原文']],
            ],
        ]);

        $this->assertStringContainsString('GEO 页面设计方案', $data['subtitle']);
        $this->assertSame('页面模块与信息架构图', $data['sections'][0]['title']);
        $this->assertSame(['原始业务内容'], $data['sections'][0]['paragraphs']);
        $this->assertStringContainsString('FAQPage 正文可见', implode('\n', $data['sections'][1]['bullets']));
        $this->assertSame('保留原文', $data['sections'][2]['paragraphs'][0]);
    }

    public function test_prompt_declares_exact_sections_and_current_monitoring_boundary(): void
    {
        $prompt = (new RenderSchemaRegistry)->buildSchemaPrompt('yao-geo-page-blueprint');

        $this->assertStringContainsString('页面模块与信息架构图', $prompt);
        $this->assertStringContainsString('FAQPage 正文可见', $prompt);
        $this->assertStringContainsString('当前产品仅接入豆包监测', $prompt);
    }
}
