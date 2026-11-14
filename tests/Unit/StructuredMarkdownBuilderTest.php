<?php

namespace Tests\Unit;

use App\Services\SkillAgent\StructuredMarkdownBuilder;
use Tests\TestCase;

/**
 * 结构化 JSON → Markdown 适配层。
 *
 * 11 个渲染脚本里 9 个吃 `--input <json>`，另外两个吃 `--source <markdown>`。
 * 这个类是让后者也能走同一条管线的那一层。
 *
 * 两条底线：
 *   1) **只转换，不编造** —— 交付物是给客户看的，模型没给的内容不能凭空补；
 *   2) 表格里的 `|` 必须转义，否则会把单元格切断，客户拿到的表是错位的。
 */
class StructuredMarkdownBuilderTest extends TestCase
{
    private function build(array $data, string $heading = '结构化摘要'): string
    {
        return (new StructuredMarkdownBuilder())->build($data, $heading);
    }

    public function test_renders_the_documented_shape(): void
    {
        $md = $this->build([
            'title' => '品牌知识库',
            'subtitle' => '副标题',
            'brand_name' => '孟德智达',
            'analysis_date' => '2026-08-09',
            'summary_cards' => [
                ['label' => '覆盖平台', 'value' => 'ChatGPT、豆包', 'note' => '已验证'],
            ],
            'sections' => [
                [
                    'title' => '系统化品牌知识库',
                    'paragraphs' => ['第一段正文。'],
                    'bullets' => ['要点一'],
                    'table' => ['headers' => ['项目', '内容'], 'rows' => [['成立', '2024']]],
                ],
            ],
        ]);

        $this->assertStringContainsString('# 品牌知识库', $md);
        $this->assertStringContainsString('> 副标题', $md);
        $this->assertStringContainsString('**孟德智达** · **2026-08-09**', $md);
        $this->assertStringContainsString('## 结构化摘要', $md);
        $this->assertStringContainsString('- **覆盖平台**：ChatGPT、豆包（已验证）', $md);
        $this->assertStringContainsString('## 系统化品牌知识库', $md);
        $this->assertStringContainsString('第一段正文。', $md);
        $this->assertStringContainsString('- 要点一', $md);
        $this->assertStringContainsString('| 项目 | 内容 |', $md);
        $this->assertStringContainsString('| 成立 | 2024 |', $md);
    }

    public function test_summary_heading_is_caller_supplied(): void
    {
        // 知识库构建的脚本按字面找「结构化摘要」，排名型文章不需要那个词。
        $md = $this->build(['title' => 'X', 'summary_cards' => [['label' => 'A', 'value' => 'B']]], '榜单速览');

        $this->assertStringContainsString('## 榜单速览', $md);
        $this->assertStringNotContainsString('结构化摘要', $md);
    }

    public function test_content_shape_keeps_summary_values_and_complete_markdown_body(): void
    {
        $md = $this->build([
            'title' => '知识库',
            'summary_cards' => [['title' => '来源边界', 'content' => '仅使用用户提交资料']],
            'sections' => [[
                'title' => '系统化品牌知识库',
                'content' => "### 企业信息\n\n| 项目 | 内容 |\n| --- | --- |\n| 品牌 | 孟德智达 |",
            ]],
        ]);

        $this->assertStringContainsString('- **来源边界**：仅使用用户提交资料', $md);
        $this->assertStringContainsString("## 系统化品牌知识库\n\n### 企业信息", $md);
        $this->assertStringContainsString('| 品牌 | 孟德智达 |', $md);
    }

    public function test_pipes_in_content_are_escaped(): void
    {
        // 不转义的话这一行会被解析成 4 列，表格整体错位。
        $md = $this->build([
            'title' => 'X',
            'sections' => [[
                'title' => 'T',
                'table' => ['headers' => ['列'], 'rows' => [['a|b']]],
            ]],
        ]);

        $this->assertStringContainsString('| a\\|b |', $md);
    }

    public function test_ragged_rows_are_padded_not_dropped(): void
    {
        // 列数对不上是模型的常见错误。丢掉整行等于悄悄吞掉客户的数据，
        // 补齐至少内容还在。
        $md = $this->build([
            'title' => 'X',
            'sections' => [[
                'title' => 'T',
                'table' => [
                    'headers' => ['甲', '乙', '丙'],
                    'rows' => [['只有一列'], ['一', '二', '三', '多余的第四列']],
                ],
            ]],
        ]);

        $this->assertStringContainsString('| 只有一列 |  |  |', $md);
        $this->assertStringContainsString('| 一 | 二 | 三 |', $md);
        $this->assertStringNotContainsString('多余的第四列', $md);
    }

    public function test_nothing_is_invented_when_fields_are_missing(): void
    {
        // 缺什么就少什么，不留「暂无」「N/A」这类占位 —— 那等于替模型编内容。
        $md = $this->build(['sections' => [['title' => '仅此一节']]]);

        $this->assertStringContainsString('## 仅此一节', $md);
        $this->assertStringNotContainsString('暂无', $md);
        $this->assertStringNotContainsString('N/A', $md);
        $this->assertStringNotContainsString('##  ', $md);
        // 连标题都没有时给一个中性兜底，而不是空的 "# "
        $this->assertStringContainsString('# 未命名报告', $md);
    }

    public function test_incomplete_tables_are_skipped_rather_than_rendered_broken(): void
    {
        // 只有表头没有数据行的表，渲染出来是个空壳，不如不画。
        $md = $this->build([
            'title' => 'X',
            'sections' => [[
                'title' => 'T',
                'table' => ['headers' => ['甲', '乙'], 'rows' => []],
            ]],
        ]);

        $this->assertStringNotContainsString('| 甲 | 乙 |', $md);
    }

    public function test_scalar_and_nested_values_degrade_readably(): void
    {
        $md = $this->build([
            'title' => 'X',
            'summary_cards' => ['一句话卡片'],
            'sections' => [[
                'title' => 'T',
                'bullets' => [['嵌套', '数组']],
            ]],
        ]);

        $this->assertStringContainsString('- 一句话卡片', $md);
        $this->assertStringContainsString('- 嵌套、数组', $md);
    }
}
