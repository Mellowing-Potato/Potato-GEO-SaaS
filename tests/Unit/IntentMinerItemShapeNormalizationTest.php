<?php

namespace Tests\Unit;

use App\Services\SkillAgent\RenderSchemaRegistry;
use Tests\TestCase;

/**
 * 意图挖掘 report_input 的形状归一（批 C 第二件）。
 *
 * render_intent_miner_report.py 里所有条目都按 item.get(...) 处理，条目不是字典就
 * AttributeError、整个渲染进程退出，客户只拿到一段裸文字。模型把同一张表写成字符串
 * 数组、二维数组或 {id: 条目} 映射都很常见，所以在写 report_input.json 之前统一归一。
 */
class IntentMinerItemShapeNormalizationTest extends TestCase
{
    public function test_string_items_become_records_on_the_first_column(): void
    {
        $data = (new RenderSchemaRegistry())->normalizeInput('yao-geo-intent-miner', [
            'question_bank' => ['品牌的售后响应时间是多久？', '这个产品支持私有化部署吗？'],
        ]);

        $this->assertCount(2, $data['question_bank'], '真实问题不足时不能复制最后一条伪造数量');
        $this->assertSame(['question_id' => '品牌的售后响应时间是多久？'], $data['question_bank'][0]);
        $this->assertSame(['question_id' => '这个产品支持私有化部署吗？'], $data['question_bank'][1]);
    }

    public function test_delivery_minimums_match_the_intent_renderer_contract(): void
    {
        $registry = new RenderSchemaRegistry();
        $schema = $registry->getSchema('yao-geo-intent-miner');

        $this->assertSame(15, $schema['minimums']['sections']);
        $this->assertSame(18, $schema['minimums']['question_bank']);
        $this->assertSame(8, $schema['minimums']['follow_up_chains']);
        $this->assertCount(15, $registry->requiredSectionKeywords('yao-geo-intent-miner'));

        $prompt = $registry->buildSchemaPrompt('yao-geo-intent-miner', ['sections']);
        $this->assertStringContainsString('至少 15 条', $prompt);
        $this->assertStringContainsString('研究依据', $prompt);
        $this->assertStringContainsString('监测 Prompt', $prompt);
    }

    public function test_positional_rows_are_mapped_back_to_field_names(): void
    {
        $data = (new RenderSchemaRegistry())->normalizeInput('yao-geo-intent-miner', [
            'evidence_sources' => [
                ['官网白皮书', 'L1', '事实核验', 'https://example.test/wp', '已获取'],
                ['行业报告'],
            ],
        ]);

        $this->assertSame([
            'source' => '官网白皮书',
            'level' => 'L1',
            'use' => '事实核验',
            'url' => 'https://example.test/wp',
            'status' => '已获取',
        ], $data['evidence_sources'][0]);

        // 短行补空，不越界
        $this->assertSame('行业报告', $data['evidence_sources'][1]['source']);
        $this->assertSame('', $data['evidence_sources'][1]['status']);
    }

    public function test_id_keyed_map_becomes_a_list_and_single_record_stays_one_item(): void
    {
        $registry = new RenderSchemaRegistry();

        $mapped = $registry->normalizeInput('yao-geo-intent-miner', [
            'prompt_library' => [
                'P1' => ['prompt_id' => 'P1', 'platform' => '豆包', 'prompt' => '问一句'],
                'P2' => ['prompt_id' => 'P2', 'platform' => 'DeepSeek', 'prompt' => '再问一句'],
            ],
        ]);
        $this->assertCount(2, $mapped['prompt_library']);
        $this->assertSame('豆包', $mapped['prompt_library'][0]['platform']);

        $single = $registry->normalizeInput('yao-geo-intent-miner', [
            'prompt_library' => ['prompt_id' => 'P1', 'platform' => '千问', 'prompt' => '只有一条'],
        ]);
        $this->assertCount(1, $single['prompt_library']);
        $this->assertSame('千问', $single['prompt_library'][0]['platform']);
    }

    public function test_sections_are_normalized_without_faking_titles(): void
    {
        $data = (new RenderSchemaRegistry())->normalizeInput('yao-geo-intent-miner', [
            'sections' => [
                '整节写成了一句话',
                ['title' => '执行摘要', 'paragraphs' => '单条不是数组'],
                ['位置写法标题', '正文一', '正文二'],
            ],
        ]);

        // 一句话当正文，不当标题：质检按标题关键词判必需章节，冒充会让质检失真
        $this->assertSame('', $data['sections'][0]['title']);
        $this->assertSame(['整节写成了一句话'], $data['sections'][0]['paragraphs']);

        $this->assertSame(['单条不是数组'], $data['sections'][1]['paragraphs']);

        $this->assertSame('位置写法标题', $data['sections'][2]['title']);
        $this->assertSame(['正文一', '正文二'], $data['sections'][2]['paragraphs']);
    }

    public function test_section_table_is_forced_into_headers_and_two_dimensional_rows(): void
    {
        $data = (new RenderSchemaRegistry())->normalizeInput('yao-geo-intent-miner', [
            'sections' => [
                ['title' => '意图地图', 'paragraphs' => ['正文'], 'table' => ['headers' => '意图', 'rows' => '一行文本']],
                ['title' => '空表', 'paragraphs' => ['正文'], 'table' => '这不是表'],
            ],
        ]);

        $this->assertSame(['意图'], $data['sections'][0]['table']['headers']);
        $this->assertSame([['一行文本']], $data['sections'][0]['table']['rows']);

        // 救不回来的表直接去掉，好过让脚本崩在半路
        $this->assertArrayNotHasKey('table', $data['sections'][1]);
    }

    public function test_well_formed_records_are_left_alone(): void
    {
        $items = [
            ['question_id' => 'Q1', 'cluster' => '选型', 'question' => '怎么选？'],
            ['question_id' => 'Q2', 'cluster' => '价格', 'question' => '多少钱？'],
        ];

        $data = (new RenderSchemaRegistry())->normalizeInput('yao-geo-intent-miner', [
            'question_bank' => $items,
        ]);

        $this->assertSame($items, array_slice($data['question_bank'], 0, 2), '正常形状不得被改写');
    }

    public function test_unregistered_skill_payload_is_untouched(): void
    {
        $payload = ['question_bank' => ['照原样留着'], 'title' => '标题'];

        $data = (new RenderSchemaRegistry())->normalizeInput('yao-geo-tracking', $payload);

        $this->assertSame(['照原样留着'], $data['question_bank'], '没登记列定义的技能不做形状归一');
    }
}
