<?php

namespace Tests\Unit;

use App\Services\DTOs\SkillBrief;
use App\Services\SkillAgent\SkillDeliveryAssembler;
use Tests\TestCase;

final class IntentReportIntegrityTest extends TestCase
{
    public function test_complete_list_corrects_model_summary_and_marks_sampling_as_planned(): void
    {
        $raw = ['analysis_date' => '2026-05-18',
            'summary_cards' => [['label' => '意图问题库规模', 'value' => '42 条'],
                ['label' => 'P0 优先意图', 'value' => '18 条']],
            'sections' => [['title' => '执行摘要', 'paragraphs' => [
                '共 42 条问题，其中 P0 18 条。研究覆盖豆包等平台。']]],
            'question_bank' => [
                ['question_id' => 'Q001', 'question' => '一？', 'priority' => 'P0'],
                ['question_id' => 'Q002', 'question' => '二？', 'priority' => 'P1'],
                ['question_id' => 'Q003', 'question' => '三？', 'priority' => 'P1'],
            ],
            'ai_sampling_plan' => [['platform' => '豆包', 'status' => '已覆盖']]];
        $data = app(SkillDeliveryAssembler::class)->prepareStructuredInput($raw, $this->brief());
        $this->assertSame(3, $data['report_integrity']['question_count']);
        $this->assertSame(1, $data['report_integrity']['p0_count']);
        $this->assertSame('3 条', $data['summary_cards'][0]['value']);
        $this->assertSame('1 条', $data['summary_cards'][1]['value']);
        $this->assertStringContainsString('共 3 条问题', $data['sections'][0]['paragraphs'][0]);
        $this->assertStringContainsString('P0 1 条', $data['sections'][0]['paragraphs'][0]);
        $this->assertStringContainsString('计划监测', $data['sections'][0]['paragraphs'][0]);
        $this->assertSame('计划监测，尚未采样', $data['ai_sampling_plan'][0]['status']);
        $this->assertSame('', $data['analysis_date']);
        $this->assertNull($data['sampling_date']);
        $this->assertMatchesRegularExpression('/[+-]\d\d:\d\d$|Z$/', $data['generated_at']);
        $this->assertSame('42 条', $raw['summary_cards'][0]['value']);
    }

    public function test_zero_and_unparseable_lists_never_invent_counts(): void
    {
        $assembler = app(SkillDeliveryAssembler::class);
        $zero = $assembler->prepareStructuredInput(['question_bank' => [
            ['question_id' => 'Q1', 'priority' => 'P1']],
            'summary_cards' => [['label' => 'P0 数量', 'value' => '18']]], $this->brief());
        $this->assertSame(0, $zero['report_integrity']['p0_count']);
        $this->assertSame('0 条', $zero['summary_cards'][0]['value']);
        $empty = $assembler->prepareStructuredInput(['question_bank' => []], $this->brief());
        $this->assertSame(0, $empty['report_integrity']['question_count']);
        $pending = $assembler->prepareStructuredInput(['question_bank' => 'broken',
            'summary_cards' => [['label' => '问题总数', 'value' => '42 条']],
            'summary' => '共 42 条问题，P0 18 条。'], $this->brief());
        $this->assertSame('pending', $pending['report_integrity']['status']);
        $this->assertArrayNotHasKey('question_count', $pending['report_integrity']);
        $this->assertSame('待校验', $pending['summary_cards'][0]['value']);
        $this->assertStringNotContainsString('42 条', $pending['summary']);
        $this->assertStringNotContainsString('18 条', $pending['summary']);
    }

    private function brief(): SkillBrief
    {
        return new SkillBrief('yao-geo-intent-miner', 1, 2, ['brand_name' => '言及']);
    }
}
