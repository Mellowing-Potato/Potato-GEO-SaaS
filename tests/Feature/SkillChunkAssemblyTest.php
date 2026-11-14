<?php

namespace Tests\Feature;

use App\Services\SkillAgent\SkillAgentRunnerService;
use App\Services\SkillAgent\SkillChunkAssembly;
use Tests\TestCase;

/**
 * 分节数据装配 · 特征化测试（计划 §7 第 3 步：`SkillAgentRunnerService` 按职责拆小的第五桶）。
 *
 * 一次分节调用回来后的四步（解析 → 合并 → 判缺字段 → 补跑任务）原来散在 3000 行执行引擎里，
 * 现在集中在 `SkillChunkAssembly`。搬出前先冻结行为，搬完逐条比对：
 *   - 解析：模型回包可能带说明文字/代码围栏，也要能抠出 JSON；解析不了返回 null（调用方会判失败）；
 *   - 合并：空值（null / '' / []）**不许覆盖**已有内容 —— 这是补跑时"别把写好的删掉"的底线；
 *   - 判缺：非意图挖掘技能按"整节有没有认领字段"判；意图挖掘按单字段粒度判（它一次只交一个字段）；
 *   - 补跑：markdown 谱没有字段粒度 → 整节重写，且被截断时把篇幅目标压到七成；
 *     JSON 谱按需整节重试或逐字段补跑。
 */
class SkillChunkAssemblyTest extends TestCase
{
    public function test_json_extraction_keeps_its_frozen_behavior(): void
    {
        $assembly = app(SkillChunkAssembly::class);

        $this->assertSame(['a' => 1, 'b' => 'x'], $assembly->extractJson('{"a":1,"b":"x"}'));
        $this->assertSame(['a' => 1], $assembly->extractJson("```json\n{\"a\":1}\n```"), '代码围栏要去掉');
        $this->assertSame(
            ['a' => 1, 'b' => [1, 2]],
            $assembly->extractJson("这是说明文字。\n{\"a\":1,\"b\":[1,2]}\n后面还有话"),
            '前后有散文也要能抠出 JSON 对象',
        );
        $this->assertNull($assembly->extractJson('not json at all'), '解析不了必须返回 null，由调用方判失败');
    }

    public function test_merge_never_overwrites_existing_content_with_empty_values(): void
    {
        $assembly = app(SkillChunkAssembly::class);

        $merged = $assembly->merge(
            ['a' => 'keep', 'b' => 'old'],
            ['a' => 'new', 'b' => '', 'c' => null, 'd' => [], 'e' => 'added'],
            ['a', 'b', 'c', 'd', 'e'],
        );

        $this->assertSame('new', $merged['a'], '非空值正常覆盖');
        $this->assertSame('old', $merged['b'], '空字符串不许覆盖已有内容（补跑时不能把写好的删掉）');
        $this->assertArrayNotHasKey('c', $merged, 'null 视为未产出');
        $this->assertArrayNotHasKey('d', $merged, '空数组视为未产出');
        $this->assertSame('added', $merged['e'], '新增字段照常并入');
        // 不在谱里的字段一概不并（防跨段污染）
        $this->assertSame(['a', 'b', 'e'], array_keys($merged));
    }

    public function test_field_issues_respect_skill_granularity(): void
    {
        $assembly = app(SkillChunkAssembly::class);

        // 非意图挖掘技能：认领了至少一个字段就算这节成了，否则整节的字段都要重交。
        $this->assertSame([], $assembly->fieldIssues('yao-geo-explainer-builder', ['a' => 'x'], ['a', 'b']));
        $this->assertSame(['a', 'b'], $assembly->fieldIssues('yao-geo-explainer-builder', [], ['a', 'b']));

        // 意图挖掘：单字段粒度 —— 重复的问题也算缺（同一批问题必须去重后再入库）。
        $this->assertSame(
            ['question_bank', 'intents'],
            $assembly->fieldIssues('yao-geo-intent-miner', ['question_bank' => [['question' => 'q1'], ['question' => 'q1']]], ['question_bank', 'intents']),
        );

        // 判空口径与合并完全一致：空字符串/null/空数组都不算认领。
        $this->assertSame(3, $assembly->countClaimed(['a' => 'x', 'b' => '', 'c' => null, 'd' => [1], 'e' => 0], ['a', 'b', 'c', 'd', 'e']));
    }

    public function test_repair_tasks_follow_the_spec_granularity(): void
    {
        $assembly = app(SkillChunkAssembly::class);

        // markdown 谱：整节重写，继承 format；被截断时篇幅目标压到七成。
        $markdown = $assembly->repairTasks(
            'yao-geo-page-audit',
            ['key' => 'one', 'label' => '第一节', 'format' => 'markdown', 'target_chars' => 3000],
            'one',
            [],
            true,
        );
        $this->assertCount(1, $markdown);
        $this->assertSame('one.retry', $markdown[0]['spec']['key']);
        $this->assertSame('第一节 · 重写', $markdown[0]['spec']['label']);
        $this->assertSame('markdown', $markdown[0]['spec']['format'], '补跑必须继承 format，否则重写那一节会掉回 JSON 模式');
        $this->assertSame(2100, $markdown[0]['spec']['target_chars'], '截断后压到七成，宁短勿断');
        $this->assertTrue($markdown[0]['spec']['retry_truncated']);
        $this->assertTrue($markdown[0]['recovery']);

        // JSON 谱 + 未截断 + 非意图挖掘：整节重试，字段清单就是缺的那些。
        $whole = $assembly->repairTasks(
            'yao-geo-explainer-builder',
            ['key' => 'overview', 'label' => '概览', 'fields' => ['a', 'b']],
            'overview',
            ['a', 'b'],
            false,
        );
        $this->assertCount(1, $whole);
        $this->assertSame('overview.retry', $whole[0]['spec']['key']);
        $this->assertSame(['a', 'b'], $whole[0]['spec']['fields']);

        // 意图挖掘：逐字段补跑（每节只交一个字段，重跑整节会浪费一次模型调用）。
        $perField = $assembly->repairTasks(
            'yao-geo-intent-miner',
            ['key' => 'intents', 'label' => '意图', 'fields' => ['a', 'b']],
            'intents',
            ['a', 'b'],
            false,
        );
        $this->assertCount(2, $perField);
        $this->assertSame(['intents.a', 'intents.b'], array_column(array_column($perField, 'spec'), 'key'));
        $this->assertSame([['a'], ['b']], array_column(array_column($perField, 'spec'), 'fields'));
    }

    /** 服务侧可见行为不变（四个调用点都走转发方法）。 */
    public function test_service_entry_points_are_unchanged(): void
    {
        $service = app(SkillAgentRunnerService::class);
        $ref = new \ReflectionClass($service);

        $extract = $ref->getMethod('extractJsonFromLlmOutput');
        $extract->setAccessible(true);
        $this->assertSame(['a' => 1], $extract->invoke($service, "```json\n{\"a\":1}\n```"));

        $merge = $ref->getMethod('mergeChunk');
        $merge->setAccessible(true);
        $this->assertSame(['a' => 'keep'], $merge->invoke($service, ['a' => 'keep'], ['a' => ''], ['a']));

        $issues = $ref->getMethod('chunkFieldIssues');
        $issues->setAccessible(true);
        $this->assertSame([], $issues->invoke($service, 'yao-geo-explainer-builder', ['a' => 'x'], ['a']));
    }
}
