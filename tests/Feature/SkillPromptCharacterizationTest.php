<?php

namespace Tests\Feature;

use App\Services\DTOs\SkillBrief;
use App\Services\SkillAgent\PageObservation;
use App\Services\SkillAgent\SkillAgentRunnerService;
use App\Services\SkillAgent\SkillAssets;
use App\Services\SkillAgent\SkillPromptBuilder;
use Tests\TestCase;

/**
 * 提示词构造的特征化测试（计划 §7 第 3 步：`SkillAgentRunnerService` 按职责拆小的前置条件）。
 *
 * 为什么要这么做：这个服务的渲染路径有一批测试需要 `yao-render` 容器镜像才能跑，
 * 因此提示词构造使用独立的特征化测试覆盖。
 * 也就是说"抽出提示词构造器"这件事，靠现有测试**证明不了没改坏**。
 * 于是先冻结行为：把当前实现（拆分前在 `SkillAgentRunnerService` 里的私有方法）
 * 对一组代表性输入的输出逐条取 sha256，然后再做拆分 —— 拆分后同样的输入必须得到同样的输出。
 *
 * 覆盖：
 *   - `buildChunkUserPrompt`：分节路的四条分支（markdown/JSON × 有/无页面观察 × intent-miner 结构谱）
 *   - `buildPipelinePrompts`：单次调用路（system + user 两段，含 hasRenderPipeline 两个取值）
 *   - `buildFallbackUserPrompt`：四个技能前缀各自的兜底提示词
 *
 * 除了哈希，另有几条**结构性断言**：它们记录的是"为什么这么写"，
 * 比如页面事实必须排在「前置阶段输出」之前（2026-09-06 execution 52 的教训：
 * 抓到的事实没进提示词，报告就写成"客户没有官网"）。
 */
class SkillPromptCharacterizationTest extends TestCase
{
    /** 拆分前实测冻结的指纹（sha256 => 长度）。改动提示词构造会立刻红，这是刻意的。 */
    private const FROZEN = [
        'chunk_markdown_no_obs' => ['45addf499cda637d7115dea4cd6374a44b695f9d44e8df93e7f7ce0f867884cc', 975],
        'chunk_markdown_with_obs' => ['e326f74de33e07536158663f9e6d532b8147e08eb87dd75a20f8a805897a6ab7', 2089],
        'chunk_json_no_obs' => ['a7e2b9a12646d8ca2dda5e944d02947a2d7dd165190c169541239c543463a08f', 2049],
        'chunk_json_intent' => ['798e92465d0bfbff8a4a38af4edb8c1b4c620d62cb2b3d8c4d1e175a83077de0', 2105],
        'pipeline_render_system' => ['bf8ecdc671c8541b9922cddf7b2c28b28ead3ccd02bee5f8b0fba030953e0e5a', 215],
        // G2: explainer now spells out the 80–120-character core answer rule.
        'pipeline_render_user' => ['a8576ea2defa445d64cf68eb8fabba7faed95d2222668587f2458123768f906e', 3081],
        'pipeline_no_render_user' => ['f346b8614c90e19be375a2937ddf74a0481279541678c47f5aff41e503fa5f1a', 2792],
        'fallback_yao-geo-explainer-builder' => ['e83f85823c863586f3802c8cdda2b0eb934a5fdca176338418b3a73767595c01', 1713],
        'fallback_yao-geo-brand-graph' => ['8632608254cfde31900ce8664a2ace7c7e285f3ac8308ce3238f6ab641bf3915', 1585],
        'fallback_yao-geo-knowledge-base-builder' => ['55062a5b80f7c6c27377390e9f68b39c40a527ae395d5880dd4deb91bae9e7a8', 1256],
        'fallback_yao-geo-intent-miner' => ['a593578308a868527c0cbd75d3e82f0f94ff32fa735822579c395720d7a85fa3', 332],
    ];

    private function assertFingerprint(string $key, string $text): void
    {
        [$sha, $len] = self::FROZEN[$key];
        $this->assertSame($len, strlen($text), "{$key}：长度变了（提示词内容被动过）");
        $this->assertSame($sha, hash('sha256', $text), "{$key}：内容变了（sha256 不一致）");
    }

    private function assets(): SkillAssets
    {
        return new SkillAssets(
            skillId: 'yao-geo-explainer-builder',
            skillMd: "# SKILL 指令\n按结构输出。",
            references: ['ref.md' => '参考文档正文'],
            renderScript: null,
            // unknown 字段用来验证 JSON 路会按 spec 的 fields 裁剪示例结构
            exampleJsonSchema: '{"a":1,"b":"x","c":[1,2],"unknown":"drop"}',
            hasAssets: true,
        );
    }

    private function brief(): SkillBrief
    {
        return new SkillBrief(
            skillId: 'yao-geo-explainer-builder',
            projectId: 11,
            userId: 22,
            fields: [
                'topic' => '种植牙集采后的价格变化',
                'target_keywords' => ['种植牙价格', '集采政策'],
                'empty_field' => '',
                'article_length' => 1200,
                'nested' => ['depth' => 1],
            ],
            query: '补充要求：不要用绝对化用语',
            contextFromPreviousStage: ['prev_stage' => ['summary' => '上一阶段结论']],
            executionId: 33,
        );
    }

    private function observation(): PageObservation
    {
        return PageObservation::success('https://example.test/', 'https://example.test/', 200, [
            'title' => '示例站点',
            'has_canonical' => true,
            'h1_count' => 2,
        ]);
    }

    /** 分节路的提示词：四条分支的指纹 + 结构约束。 */
    public function test_chunk_user_prompt_keeps_its_frozen_output(): void
    {
        $builder = app(SkillPromptBuilder::class);
        $assets = $this->assets();
        $brief = $this->brief();
        $markdownSpec = ['key' => 'article_core', 'label' => '正文', 'format' => 'markdown', 'fields' => ['article_body', 'article_sections']];
        $jsonSpec = ['key' => 'overview', 'label' => '概览', 'format' => 'json', 'fields' => ['a', 'b']];
        $intentSpec = ['key' => 'intents', 'label' => '意图', 'format' => 'json', 'fields' => ['a', 'b', 'c']];

        $markdownNoObs = $builder->chunkUserPrompt($assets, $brief, '', $markdownSpec, '已产出片段摘要', null);
        $this->assertFingerprint('chunk_markdown_no_obs', $markdownNoObs);
        // markdown 路走的是「本节写法要求」，JSON 路才走「本段输出要求」——两条分支的指令不同。
        $this->assertStringContainsString('## 本次要写的一节', $markdownNoObs, 'markdown 路必须带本节写法要求');
        $this->assertStringContainsString('这份报告已经写到哪了', $markdownNoObs, 'markdown 路要带接续摘要');
        // 没有页面观察时不能出现"实测事实"段（那一段只在真的抓到页面时才有）。
        $this->assertStringNotContainsString('## 页面实测观察', $markdownNoObs, '没有页面观察时不该凭空出现事实段');

        $markdownWithObs = $builder->chunkUserPrompt($assets, $brief, '知识库片段', $markdownSpec, '已产出片段摘要', $this->observation());
        $this->assertFingerprint('chunk_markdown_with_obs', $markdownWithObs);
        // 🔴 execution 52 的教训：抓到的页面事实必须排在「前置阶段输出」之前，且必须在每一节里。
        $factsPos = mb_strpos($markdownWithObs, '## 页面实测观察');
        $prevStagePos = mb_strpos($markdownWithObs, '## 前置阶段输出');
        $this->assertNotFalse($factsPos, '有页面观察时每一节都要带事实段');
        if ($prevStagePos !== false) {
            $this->assertLessThan($prevStagePos, $factsPos, '实测事实必须排在传闻（前置阶段输出）之前');
        }

        $jsonNoObs = $builder->chunkUserPrompt($assets, $brief, '', $jsonSpec, '已产出片段摘要', null);
        $this->assertFingerprint('chunk_json_no_obs', $jsonNoObs);
        $this->assertStringContainsString('## 本段输出要求（第「概览」部分）', $jsonNoObs);
        $this->assertStringNotContainsString('"unknown"', $jsonNoObs, '示例结构必须按 spec 的 fields 裁剪');
        $this->assertStringContainsString('只输出以下字段，其余字段一律不要出现：a、b。', $jsonNoObs);

        $jsonIntent = $builder->chunkUserPrompt($assets, $brief, '知识库片段', $intentSpec, '已产出片段摘要', $this->observation());
        $this->assertFingerprint('chunk_json_intent', $jsonIntent);
    }

    /** 单次调用路的 system + user 两段。 */
    public function test_pipeline_prompts_keep_their_frozen_output(): void
    {
        $builder = app(SkillPromptBuilder::class);

        [$system, $user] = $builder->pipelinePrompts($this->assets(), $this->brief(), '知识库片段', true, $this->observation());
        $this->assertFingerprint('pipeline_render_system', $system);
        $this->assertFingerprint('pipeline_render_user', $user);
        $this->assertStringContainsString('SKILL 执行指令', $system, 'system 段必须带 SKILL.md');
        $this->assertStringContainsString('参考文档: ref.md', $system, 'system 段必须带 references');
        $this->assertStringContainsString('请严格按照上述指令和参考文档完成 GEO 分析任务', $system);

        [, $userNoRender] = $builder->pipelinePrompts($this->assets(), $this->brief(), '知识库片段', false, $this->observation());
        $this->assertFingerprint('pipeline_no_render_user', $userNoRender);
        $this->assertNotSame($user, $userNoRender, 'hasRenderPipeline 必须影响 user 段（否则渲染交付物会没人管）');
    }

    /** 兜底提示词：四个技能前缀各一条。 */
    public function test_fallback_user_prompt_keeps_its_frozen_output(): void
    {
        $builder = app(SkillPromptBuilder::class);

        foreach ([
            'yao-geo-explainer-builder',
            'yao-geo-brand-graph',
            'yao-geo-knowledge-base-builder',
            'yao-geo-intent-miner',
        ] as $skillId) {
            $brief = new SkillBrief(
                skillId: $skillId,
                projectId: 11,
                userId: 22,
                fields: ['topic' => 'T', 'count' => 2],
                query: 'q',
                contextFromPreviousStage: ['p' => 1],
            );
            $prompt = $builder->fallbackUserPrompt($skillId, $brief, '知识库片段');
            $this->assertFingerprint('fallback_'.$skillId, $prompt);
            $this->assertStringContainsString('## Skill ID', $prompt);
            $this->assertStringContainsString('## 前置阶段输出', $prompt);
        }
    }

    /**
     * 服务本身**可见**的行为也不能变：拆分后服务里的私有入口应当只是转发给构造器。
     * 用反射调服务（与拆分前完全相同的调用方式），结果必须与冻结值一致。
     */
    public function test_service_private_entry_points_still_produce_the_same_prompts(): void
    {
        $service = app(SkillAgentRunnerService::class);
        $ref = new \ReflectionClass($service);
        $markdownSpec = ['key' => 'article_core', 'label' => '正文', 'format' => 'markdown', 'fields' => ['article_body', 'article_sections']];

        $chunk = $ref->getMethod('buildChunkUserPrompt');
        $chunk->setAccessible(true);
        $this->assertFingerprint(
            'chunk_markdown_with_obs',
            $chunk->invoke($service, $this->assets(), $this->brief(), '知识库片段', $markdownSpec, '已产出片段摘要', $this->observation()),
        );

        $pipeline = $ref->getMethod('buildPipelinePrompts');
        $pipeline->setAccessible(true);
        [$system, $user] = $pipeline->invoke($service, $this->assets(), $this->brief(), '知识库片段', true, $this->observation());
        $this->assertFingerprint('pipeline_render_system', $system);
        $this->assertFingerprint('pipeline_render_user', $user);

        $fallback = $ref->getMethod('buildFallbackUserPrompt');
        $fallback->setAccessible(true);
        // ⚠️ 必须用与上面 fallback 用例**同一份** brief：提示词里带品牌字段，
        // 换成内容更丰富的 brief 会让长度/哈希对不上（第一版就是这么写错的）。
        $variant = new SkillBrief(
            skillId: 'yao-geo-brand-graph',
            projectId: 11,
            userId: 22,
            fields: ['topic' => 'T', 'count' => 2],
            query: 'q',
            contextFromPreviousStage: ['p' => 1],
        );
        $this->assertFingerprint(
            'fallback_yao-geo-brand-graph',
            $fallback->invoke($service, 'yao-geo-brand-graph', $variant, '知识库片段'),
        );
    }

    /** 分节谱判定是共享的工具函数，拆分后服务与构造器必须看到同一套语义。 */
    public function test_is_markdown_spec_is_shared_and_unchanged(): void
    {
        $this->assertTrue(SkillPromptBuilder::isMarkdownSpec(['format' => 'markdown']));
        $this->assertFalse(SkillPromptBuilder::isMarkdownSpec(['format' => 'json']));
        $this->assertFalse(SkillPromptBuilder::isMarkdownSpec([]), '不写 format 默认按 JSON 处理');
    }
}
