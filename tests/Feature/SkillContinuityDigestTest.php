<?php

namespace Tests\Feature;

use App\Services\SkillAgent\SkillAgentRunnerService;
use App\Services\SkillAgent\SkillContinuityDigest;
use Tests\TestCase;

/**
 * 分节续写摘要 · 特征化测试（计划 §7 第 3 步：`SkillAgentRunnerService` 按职责拆小的第三桶）。
 *
 * 同一套路：拆分前用反射冻结六条分支的输出 sha256，拆分后必须逐字一致。
 *
 * 这几条摘要直接决定"后面几节会不会把前面写过的内容重写一遍、编号对不对得上"，
 * 而它们各自的取舍不同（explainer 只给事实纪律、panorama 给竞品与基线样本、
 * markdown 只给每节开头 1200 字、generic 兜底列字段名），所以逐条冻结而不是只测一条。
 */
class SkillContinuityDigestTest extends TestCase
{
    private const FROZEN = [
        'explainer' => '5d14a4e175ed36fa1a64944474ed3b2cee5b6caea9f79c05e1e04505e22ae8d4',
        'panorama' => '8865ab7ad072e6aa1cfc584f39ec93bcfe26d4f587c6460164cc4f25dc6e7d2a',
        'generic' => '40f0c8d5ef441599b57fd8514aaebf7cf5e42d4931699895f3a4f583d692d332',
        'empty' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
        'markdown' => '5459045fe830846225fa1d90ba1c8a158ab0f36ac0a318f1c9f57e54fddb8767',
        'markdown_empty_state' => 'bfcc062e3e2d7a0d6fc305182fe6e08c39c67140b9a5faba66caed160fad4733',
    ];

    private function explainerMerged(): array
    {
        return [
            'data_availability' => [['来源', '状态'], ['官网', '已获取']],
            'fact_verification_matrix' => [['事实', '结论'], ['价格', '待核验']],
            'article_sections' => "第一节正文……\n第二节正文……",
        ];
    }

    private function panoramaMerged(): array
    {
        return [
            'brand_name' => '示例品牌',
            'competitors' => [['竞品A', '域名'], ['竞品B', '域名']],
            'baseline' => [['example.test', '来源'], ['other.test', '来源']],
        ];
    }

    private function genericMerged(): array
    {
        return [
            'report' => ['company' => '示例公司'],
            'target_platforms' => [['知乎'], ['小红书'], ['公众号']],
            'question_bank' => [['id' => 'Q1'], ['id' => 'Q2']],
            'summary' => '概览文本',
        ];
    }

    private function markdownState(): array
    {
        return [
            'specs' => [
                ['key' => 'first', 'label' => '第一节', 'fields' => ['section_one']],
                ['key' => 'second', 'label' => '第二节', 'fields' => ['section_two']],
                ['key' => 'third', 'label' => '第三节', 'fields' => ['section_three']],
            ],
            'merged' => [
                'section_one' => '第一节写好了。',
                'section_two' => str_repeat('第二节内容。', 300),
            ],
        ];
    }

    private function assertFrozen(string $key, string $text): void
    {
        $this->assertSame(self::FROZEN[$key], hash('sha256', $text), "{$key}：续写摘要内容变了");
    }

    public function test_continuity_digest_keeps_its_frozen_output(): void
    {
        $digest = app(SkillContinuityDigest::class);

        $explainer = $digest->forSkill('yao-geo-explainer-builder', $this->explainerMerged(), 'article_core');
        $this->assertFrozen('explainer', $explainer);
        $this->assertStringContainsString('核验候选', $explainer, '解释型文章必须带上事实核验纪律');

        $panorama = $digest->forSkill('yao-geo-panorama-audit', $this->panoramaMerged());
        $this->assertFrozen('panorama', $panorama);
        $this->assertStringContainsString('已识别竞品', $panorama);
        $this->assertStringContainsString('基线覆盖', $panorama);

        $generic = $digest->forSkill('yao-geo-other-skill', $this->genericMerged());
        $this->assertFrozen('generic', $generic);
        $this->assertStringContainsString('已产出字段：report、target_platforms、question_bank、summary', $generic);
        $this->assertStringContainsString('目标平台：知乎、小红书、公众号', $generic);
        $this->assertStringContainsString('后续问题引用只使用以下已生成 ID', $generic, '拆开问题库后必须锁定同一批问题 ID');

        // 空状态不产出任何摘要（不能凭空给一段"已经写到哪"）
        $this->assertFrozen('empty', $digest->forSkill('yao-geo-other-skill', []));
        $this->assertSame('', $digest->forSkill('yao-geo-other-skill', []));
    }

    public function test_markdown_continuity_digest_keeps_its_frozen_output(): void
    {
        $digest = app(SkillContinuityDigest::class);

        $text = $digest->markdown($this->markdownState());
        $this->assertFrozen('markdown', $text);
        $this->assertStringContainsString('本报告的完整章节顺序：1. 第一节；2. 第二节；3. 第三节', $text);
        $this->assertStringContainsString('下面是已经写好的章节', $text, '有已写好的章节时要给出衔接段落');
        // 写好的章节只给开头：超长的那节必须被截断并标注。
        $this->assertStringContainsString('（本节后半略）', $text);

        // 一节都没写时只给大纲，不能出现"下面是已经写好的章节"这段（否则模型以为有人写过）。
        $outlineOnly = $digest->markdown(['specs' => $this->markdownState()['specs'], 'merged' => []]);
        $this->assertStringContainsString('本报告的完整章节顺序', $outlineOnly);
        $this->assertStringNotContainsString('下面是已经写好的章节', $outlineOnly);

        $this->assertFrozen('markdown_empty_state', $digest->markdown([]));
    }

    /** 服务侧可见行为同样不变（runChunkTask 走的是这两个转发方法）。 */
    public function test_service_entry_points_are_unchanged(): void
    {
        $service = app(SkillAgentRunnerService::class);
        $ref = new \ReflectionClass($service);

        $forSkill = $ref->getMethod('continuityDigestFor');
        $forSkill->setAccessible(true);
        $this->assertFrozen('generic', $forSkill->invoke($service, 'yao-geo-other-skill', $this->genericMerged()));

        $markdown = $ref->getMethod('markdownContinuityDigest');
        $markdown->setAccessible(true);
        $this->assertFrozen('markdown', $markdown->invoke($service, $this->markdownState()));
    }
}
