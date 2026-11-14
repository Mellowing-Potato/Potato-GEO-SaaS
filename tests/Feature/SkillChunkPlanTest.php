<?php

namespace Tests\Feature;

use App\Services\DTOs\AiProviderCallResult;
use App\Services\SkillAgent\SkillAgentRunnerService;
use App\Services\SkillAgent\SkillChunkPlan;
use Tests\TestCase;

/**
 * 分节谱与初始状态 · 特征化测试（计划 §7 第 3 步：`SkillAgentRunnerService` 按职责拆小的第四桶）。
 *
 * 分段谱是报告结构的**唯一事实源**：字段漏一个、顺序错一步，合并出来的报告就少一节或串位，
 * 而且不报错。搬出服务常量区时按老规矩先冻结指纹，搬完必须逐字一致。
 *
 * 冻结的对象：四个技能的分段谱 + 页面审计的 markdown 分节谱 + 初始状态 + markdown 分节包装。
 */
class SkillChunkPlanTest extends TestCase
{
    /** 拆分前实测冻结的指纹（对谱/状态数组的 json_encode 取 sha256）。 */
    private const SPEC_FROZEN = [
        'yao-geo-panorama-audit' => ['dd27158f747bd1e25f03f6efbe71f759c5d8c337fdcb829ee652aedfa0eebef7', 4],
        'yao-geo-execution-roadmap' => ['9e9e8ea27594aaf98f19c97e47f08cabca7f3aa91d9c7bcf00a83640840429bf', 3],
        'yao-geo-explainer-builder' => ['eb6467cc364457ee414f5df593997e57df089edb60f7711e78c10a0801afa4ae', 4],
        'yao-geo-intent-miner' => ['fe2bdc718834af5074fd6feec07629c7a1fc67cc8b26cc15a5e4fec100ca2177', 7],
    ];

    private const MARKDOWN_SPEC_SHA = 'b7544b92000232b4c8511fbaca95f294b26ebadfb6526639ff3670df2b60803f';
    private const STATE_SHA = 'a729b2fe83861aa732b7e55cde07cd7322cc12cdd3becef74575139315070d39';

    public function test_chunk_specs_are_frozen_per_skill(): void
    {
        $plan = app(SkillChunkPlan::class);

        foreach (self::SPEC_FROZEN as $skillId => [$sha, $chunkCount]) {
            $specs = $plan->specsFor($skillId, true);
            $this->assertIsArray($specs, "{$skillId} 应当有分段谱");
            $this->assertCount($chunkCount, $specs, "{$skillId} 的分段数变了");
            $this->assertSame($sha, hash('sha256', json_encode($specs, JSON_UNESCAPED_UNICODE)), "{$skillId} 的谱内容变了");

            foreach ($specs as $spec) {
                $this->assertArrayHasKey('key', $spec);
                $this->assertArrayHasKey('label', $spec);
                $this->assertArrayHasKey('fields', $spec);
                $this->assertNotEmpty($spec['fields'], "{$skillId}:{$spec['key']} 不能没有字段");
            }
        }

        // 没有渲染管线的技能走 markdown 分节谱；页面审计有、其他技能没有。
        $this->assertSame(
            self::MARKDOWN_SPEC_SHA,
            hash('sha256', json_encode($plan->specsFor('yao-geo-page-audit', false), JSON_UNESCAPED_UNICODE)),
        );
        $this->assertNull($plan->specsFor('yao-geo-unknown-skill', true), '没登记谱的技能不参与分段');
    }

    public function test_chunk_state_shape_is_frozen(): void
    {
        $plan = app(SkillChunkPlan::class);

        $state = $plan->newChunkState(
            [['key' => 'a', 'label' => 'A', 'fields' => ['x']]],
            '知识片段',
            12345.678,
            null,
        );
        // started_at / start_time 是时钟值，不参与指纹（其余全部冻结）。
        unset($state['started_at'], $state['start_time']);

        $this->assertSame(self::STATE_SHA, hash('sha256', json_encode($state, JSON_UNESCAPED_UNICODE)));
        $this->assertSame([
            'specs', 'knowledge_context', 'page_observation', 'merged', 'chunk_outputs', 'chunk_meta',
            'chunk_partials', 'raw_parts', 'token_sum', 'call_count', 'recovery_calls_left', 'pending', 'cancelled',
        ], array_keys($state));

        // 三条"接力时不能重来"的约定：谱、RAG 结果、页面抓取结果都要存进状态。
        $this->assertSame([['key' => 'a', 'label' => 'A', 'fields' => ['x']]], $state['specs']);
        $this->assertSame('知识片段', $state['knowledge_context']);
        $this->assertArrayHasKey('page_observation', $state);
    }

    public function test_markdown_chunk_wrapping_rejects_unusable_output(): void
    {
        $plan = app(SkillChunkPlan::class);
        $spec = ['key' => 'section_one', 'label' => '第一节', 'fields' => ['section_one']];

        $ok = new AiProviderCallResult(success: true, provider: 'gateway', model: 'text', content: str_repeat('正文段落。', 40));
        $wrapped = $plan->markdownChunkData($spec, $ok);
        $this->assertSame(['section_one'], array_keys($wrapped), '包装形状必须是 [本节字段 => 正文]');
        $this->assertSame(trim((string) $ok->content), $wrapped['section_one']);

        // 交白卷和交半句话都不认：低于 MIN_USABLE_OUTPUT_CHARS 一律 null（调用方会补跑）。
        $short = new AiProviderCallResult(success: true, provider: 'gateway', model: 'text', content: '太短');
        $this->assertNull($plan->markdownChunkData($spec, $short));

        $failed = new AiProviderCallResult(success: false, provider: 'gateway', model: 'text', content: str_repeat('失败但很长。', 40));
        $this->assertNull($plan->markdownChunkData($spec, $failed), '失败的调用不能算产出');

        $this->assertSame(40, SkillChunkPlan::MIN_USABLE_OUTPUT_CHARS);
        $this->assertTrue(SkillChunkPlan::specsAreMarkdown([['format' => 'markdown']]));
        $this->assertFalse(SkillChunkPlan::specsAreMarkdown([['format' => 'json']]));
    }

    /** 服务侧可见行为不变（分段入口、初始状态、markdown 包装都走转发方法）。 */
    public function test_service_entry_points_are_unchanged(): void
    {
        $service = app(SkillAgentRunnerService::class);
        $ref = new \ReflectionClass($service);

        $specs = $ref->getMethod('chunkSpecsFor');
        $specs->setAccessible(true);
        [$sha, $count] = self::SPEC_FROZEN['yao-geo-panorama-audit'];
        $plan = $specs->invoke($service, 'yao-geo-panorama-audit', true);
        $this->assertCount($count, $plan);
        $this->assertSame($sha, hash('sha256', json_encode($plan, JSON_UNESCAPED_UNICODE)));

        $state = $ref->getMethod('newChunkState');
        $state->setAccessible(true);
        $built = $state->invoke($service, [['key' => 'a', 'label' => 'A', 'fields' => ['x']]], '知识片段', 12345.678, null);
        unset($built['started_at'], $built['start_time']);
        $this->assertSame(self::STATE_SHA, hash('sha256', json_encode($built, JSON_UNESCAPED_UNICODE)));

        $this->assertTrue(SkillChunkPlan::specsAreMarkdown([['format' => 'markdown']]));
    }
}
