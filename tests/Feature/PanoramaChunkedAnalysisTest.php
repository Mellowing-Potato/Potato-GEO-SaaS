<?php

namespace Tests\Feature;

use App\Models\ModelCallLog;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\ModelUsageRecorder;
use App\Services\Content\PromptTemplateService;
use App\Services\Contracts\AiProvider;
use App\Services\Contracts\KnowledgeRetrieval;
use App\Services\Contracts\SkillRegistry;
use App\Services\DTOs\AiProviderCallResult;
use App\Services\DTOs\SkillBrief;
use App\Services\DTOs\SkillDefinition;
use App\Services\DTOs\SkillExecutionResult;
use App\Services\Enums\SkillCategory;
use App\Services\SkillAgent\RenderBridgeService;
use App\Services\SkillAgent\RenderResult;
use App\Services\SkillAgent\SkillAgentRunnerService;
// 分段谱常量已迁到 SkillChunkPlan（计划 §7 第 3 步）；这里读的是同一份数据的新家。
use App\Services\SkillAgent\SkillChunkPlan;
use App\Services\SkillAgent\SkillAssetLoader;
use App\Services\SkillAgent\SkillAssets;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 长输出技能分段生成（D 根治）直测：
 *  - panorama-audit 4 段 / execution-roadmap 3 段，各一次 LLM 调用 → 合并出含全部区域的 structured
 *  - 扣费收口：每段一条 ModelCallLog（runner 唯一记账），不重复翻倍
 */
class PanoramaChunkedAnalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_panorama_chunked_merges_four_sections_and_bills_once_per_chunk(): void
    {
        [$tenant, $admin, $project] = $this->tenantProjectAdmin();

        TenantContext::set($tenant);
        try {
            $execution = SkillExecution::query()->create([
                'tenant_id' => $tenant->id,
                'project_id' => $project->id,
                'user_id' => $admin->id,
                'skill_id' => 'yao-geo-panorama-audit',
                'skill_name' => '全景诊断',
                'category' => SkillCategory::GeoStrategy->value,
                'stage_index' => 1,
                'status' => 'running',
                'execution_mode' => 'async',
                'input_data' => [],
            ]);
        } finally {
            TenantContext::forget();
        }

        // 每段返回该段自己的字段（不同 token 数以验证求和）
        $aiProvider = $this->createMock(AiProvider::class);
        $aiProvider->method('generate')->willReturnOnConsecutiveCalls(
            $this->chunkResult($this->chunkFoundation(), 100),
            $this->chunkResult($this->chunkEvidence(), 110),
            $this->chunkResult($this->chunkAssessment(), 120),
            $this->chunkResult($this->chunkAction(), 130),
        );

        // 渲染成功（不实跑 Python）
        $renderBridge = $this->createMock(RenderBridgeService::class);
        $renderBridge->method('render')->willReturn(new RenderResult(
            success: true,
            renderedPaths: [
                'markdown' => "executions/{$execution->id}/panorama.md",
                'html' => "executions/{$execution->id}/report.html",
            ],
            qualityReport: ['passed' => true, 'score' => 90, 'checks' => []],
        ));

        $runner = new SkillAgentRunnerService(
            $this->createMock(SkillRegistry::class),
            $aiProvider,
            $this->createMock(KnowledgeRetrieval::class),
            $this->createMock(SkillAssetLoader::class),
            $renderBridge,
            $this->createMock(PromptTemplateService::class),
            app(ModelUsageRecorder::class), // 真实记账器 → 落真实 ModelCallLog
        );

        $assets = new SkillAssets(
            skillId: 'yao-geo-panorama-audit',
            skillMd: '# SKILL 方法论',
            references: [],
            renderScript: '/skills/render_panorama.py',
            exampleJsonSchema: '{"title":"string","summary":"[[title,detail]]"}',
            hasAssets: true,
        );

        $skill = new SkillDefinition(
            id: 'yao-geo-panorama-audit',
            name: '全景诊断',
            category: SkillCategory::GeoStrategy,
            maturity: 'beta',
            requiresWeb: false,
            stageIndex: 1,
            description: 'panorama',
        );

        $brief = new SkillBrief(
            skillId: 'yao-geo-panorama-audit',
            projectId: $project->id,
            userId: $admin->id,
            fields: ['brand_name' => 'Acme'],
            executionId: $execution->id,
        );

        TenantContext::set($tenant);
        try {
            $specs = $this->invokePrivate($runner, 'chunkSpecsFor', ['yao-geo-panorama-audit', true]);
            $method = new ReflectionMethod($runner, 'executeChunked');
            $method->setAccessible(true);
            /** @var SkillExecutionResult $result */
            $result = $method->invoke(
                $runner, $skill, $assets, $brief, 'SYS', '', microtime(true), (string) $tenant->id, $specs,
            );
        } finally {
            TenantContext::forget();
        }

        // 分段执行结果
        $this->assertTrue($result->success);
        $this->assertSame(4, $result->llmCallCount);
        $this->assertSame(460, $result->tokenUsage); // 100+110+120+130
        $this->assertSame(4, $result->intermediateData['chunks_parsed']);
        $this->assertTrue($result->intermediateData['chunked']);
        $this->assertTrue($result->intermediateData['render_success']);

        // 合并出的 structured 覆盖四段各自的区域
        $structured = $result->intermediateData['structured'];
        $this->assertIsArray($structured);
        $this->assertSame('Acme', $structured['brand_name']);
        foreach (['summary', 'baseline', 'competitors', 'scores', 'risk_register'] as $section) {
            $this->assertArrayHasKey($section, $structured, "缺少区域 {$section}");
            $this->assertNotEmpty($structured[$section], "区域 {$section} 为空");
        }

        // 扣费收口：每段一条日志、共 4 条，绝不因分段重复翻倍
        $this->assertSame(4, ModelCallLog::query()->where('skill_execution_id', $execution->id)->count());
    }

    public function test_roadmap_chunked_merges_three_sections_and_bills_once_per_chunk(): void
    {
        [$tenant, $admin, $project] = $this->tenantProjectAdmin();

        TenantContext::set($tenant);
        try {
            $execution = SkillExecution::query()->create([
                'tenant_id' => $tenant->id,
                'project_id' => $project->id,
                'user_id' => $admin->id,
                'skill_id' => 'yao-geo-execution-roadmap',
                'skill_name' => '执行路线图',
                'category' => SkillCategory::GeoStrategy->value,
                'stage_index' => 3,
                'status' => 'running',
                'execution_mode' => 'sync',
                'input_data' => [],
            ]);
        } finally {
            TenantContext::forget();
        }

        $aiProvider = $this->createMock(AiProvider::class);
        $aiProvider->method('generate')->willReturnOnConsecutiveCalls(
            $this->chunkResult($this->roadmapFoundation(), 100),
            $this->chunkResult($this->roadmapPackages(), 110),
            $this->chunkResult($this->roadmapGovernance(), 120),
        );

        $renderBridge = $this->createMock(RenderBridgeService::class);
        $renderBridge->method('render')->willReturn(new RenderResult(
            success: true,
            renderedPaths: ['markdown' => "executions/{$execution->id}/roadmap.md"],
            qualityReport: ['passed' => true, 'score' => 90, 'checks' => []],
        ));

        $runner = new SkillAgentRunnerService(
            $this->createMock(SkillRegistry::class),
            $aiProvider,
            $this->createMock(KnowledgeRetrieval::class),
            $this->createMock(SkillAssetLoader::class),
            $renderBridge,
            $this->createMock(PromptTemplateService::class),
            app(ModelUsageRecorder::class),
        );

        $assets = new SkillAssets(
            skillId: 'yao-geo-execution-roadmap',
            skillMd: '# SKILL 方法论',
            references: [],
            renderScript: '/skills/render_roadmap.py',
            exampleJsonSchema: '{"report":{"company":"string"},"roadmap":"[[phase,goal]]"}',
            hasAssets: true,
        );

        $skill = new SkillDefinition(
            id: 'yao-geo-execution-roadmap',
            name: '执行路线图',
            category: SkillCategory::GeoStrategy,
            maturity: 'beta',
            requiresWeb: false,
            stageIndex: 3,
            description: 'roadmap',
        );

        $brief = new SkillBrief(
            skillId: 'yao-geo-execution-roadmap',
            projectId: $project->id,
            userId: $admin->id,
            fields: ['brand_name' => 'Acme'],
            executionId: $execution->id,
        );

        TenantContext::set($tenant);
        try {
            $specs = $this->invokePrivate($runner, 'chunkSpecsFor', ['yao-geo-execution-roadmap', true]);
            $this->assertIsArray($specs, 'execution-roadmap 应命中分段谱');
            $this->assertCount(3, $specs);

            $method = new ReflectionMethod($runner, 'executeChunked');
            $method->setAccessible(true);
            /** @var SkillExecutionResult $result */
            $result = $method->invoke(
                $runner, $skill, $assets, $brief, 'SYS', '', microtime(true), (string) $tenant->id, $specs,
            );
        } finally {
            TenantContext::forget();
        }

        $this->assertTrue($result->success);
        $this->assertSame(3, $result->llmCallCount);
        $this->assertSame(330, $result->tokenUsage); // 100+110+120
        $this->assertSame(3, $result->intermediateData['chunks_parsed']);
        $this->assertTrue($result->intermediateData['chunked']);

        // 三段各自区域全部合并进 structured（绕开单次截断的根治点）
        $structured = $result->intermediateData['structured'];
        $this->assertIsArray($structured);
        foreach (['report', 'executive_summary', 'project_packages', 'roadmap', 'governance_plan', 'resource_budget'] as $section) {
            $this->assertArrayHasKey($section, $structured, "缺少区域 {$section}");
            $this->assertNotEmpty($structured[$section], "区域 {$section} 为空");
        }

        // 扣费收口：每段一条日志、共 3 条，同步/异步一致，不重复翻倍
        $this->assertSame(3, ModelCallLog::query()->where('skill_execution_id', $execution->id)->count());
    }

    /**
     * 契约守护：EXPLAINER_CHUNKS 四段字段并集必须精确等于 report_input 的 42 个顶层键，
     * 且无重叠。任何分段漏字段/重复字段都会在合并时静默丢内容 → 此测试当场拦截。
     */
    public function test_explainer_chunks_cover_all_report_input_keys_without_overlap(): void
    {
        $chunks = (new \ReflectionClass(SkillChunkPlan::class))->getConstant('EXPLAINER_CHUNKS');
        $this->assertIsArray($chunks);
        $this->assertCount(4, $chunks);
        $this->assertSame(
            ['research_evidence', 'article_core', 'knowledge_method', 'brand_publish'],
            array_column($chunks, 'key'),
            '解释文章必须先生成证据，再在同样四次调用内写正文',
        );

        $all = [];
        foreach ($chunks as $c) {
            $all = array_merge($all, $c['fields']);
        }

        // 无重叠：并集长度 == 各段长度之和
        $this->assertSame(count($all), count(array_unique($all)), 'EXPLAINER_CHUNKS 分段字段有重叠');

        // 精确覆盖 report_input.json 全部 42 个顶层键（yao-geo-explainer-builder 示例契约）
        $expected = [
            'skill_id', 'slug', 'title', 'topic', 'target_brand', 'target_audience', 'use_case',
            'sensitive_domain', 'brand_insertion_allowed', 'max_brand_mentions', 'analysis_date', 'core_answer',
            'article_sections', 'analysis_summary', 'problem_type_map', 'audience_scenarios', 'research_basis',
            'evidence_assessment', 'data_availability', 'data_source_plan', 'fact_verification_matrix', 'key_values',
            'entity_map', 'definitions', 'principles', 'steps', 'pre_publish_checks', 'selection_criteria',
            'decision_paths', 'misconceptions', 'example', 'brand_scenarios', 'faq', 'glossary', 'brand_insertions',
            'platform_notes', 'schema_recommendations', 'measurement_plan', 'content_gap_opportunities',
            'compliance_notes', 'pending_confirmations', 'sources',
        ];
        sort($all);
        sort($expected);
        $this->assertSame($expected, $all, 'EXPLAINER_CHUNKS 未精确覆盖 report_input 全部顶层键');
    }

    /**
     * 契约守护：INTENT_MINER_CHUNKS 各段字段并集必须精确等于 report_input 的 21 个顶层键，
     * 且无重叠。键名来自 examples/hubspot-cn-demo/input/report_input.json（Python 渲染脚本的
     * 真实输入契约），与 RenderSchemaRegistry::intentMiner() 的 required+defaults+question_bank 一致。
     */
    public function test_intent_miner_chunks_cover_all_report_input_keys_without_overlap(): void
    {
        $chunks = (new \ReflectionClass(SkillChunkPlan::class))->getConstant('INTENT_MINER_CHUNKS');
        $this->assertIsArray($chunks);
        $this->assertCount(7, $chunks);

        $all = [];
        foreach ($chunks as $c) {
            $all = array_merge($all, $c['fields']);
        }

        $this->assertSame(count($all), count(array_unique($all)), 'INTENT_MINER_CHUNKS 分段字段有重叠');

        $expected = [
            'slug', 'title', 'subtitle', 'brand_name', 'analysis_date', 'prepared_by', 'limitations',
            'summary_cards', 'sections', 'question_bank', 'scoring_matrix', 'prompt_library',
            'follow_up_chains', 'data_sources', 'ai_sampling_plan', 'calibration_actions',
            'content_topics', 'faq_bank', 'knowledge_base_entries', 'evidence_sources', 'action_roadmap',
        ];
        sort($all);
        sort($expected);
        $this->assertSame($expected, $all, 'INTENT_MINER_CHUNKS 未精确覆盖 report_input 全部顶层键');
    }

    /**
     * 回归守护：意图挖掘必须命中分段谱。它此前未登记 → 38k 字符的产出裸跑单次调用被截断。
     */
    public function test_intent_miner_is_registered_in_chunk_specs(): void
    {
        $runner = app(SkillAgentRunnerService::class);
        $specs = $this->invokePrivate($runner, 'chunkSpecsFor', ['yao-geo-intent-miner', true]);

        $this->assertIsArray($specs, '意图挖掘未命中分段谱，会退回单次调用并被截断');
        // 7 段而不是 4 段：单次网关调用按 280 秒预算反算 max_tokens，上限落在约 12k 词元，
        // 原来的 report_body / scoring_sampling 都会超出并被截断（2026-09-06 id=44 实证）。
        $this->assertCount(7, $specs);
        $this->assertSame(
            [
                'report_head', 'report_sections', 'question_bank', 'scoring_matrix',
                'sampling_calibration', 'content_topics', 'evidence_roadmap',
            ],
            array_column($specs, 'key'),
        );
    }

    public function test_explainer_chunked_merges_four_sections_and_bills_once_per_chunk(): void
    {
        [$tenant, $admin, $project] = $this->tenantProjectAdmin();

        TenantContext::set($tenant);
        try {
            $execution = SkillExecution::query()->create([
                'tenant_id' => $tenant->id,
                'project_id' => $project->id,
                'user_id' => $admin->id,
                'skill_id' => 'yao-geo-explainer-builder',
                'skill_name' => '解释型文章',
                'category' => SkillCategory::GeoContentProduction->value,
                'stage_index' => 4,
                'status' => 'running',
                'execution_mode' => 'sync',
                'input_data' => [],
            ]);
        } finally {
            TenantContext::forget();
        }

        $aiProvider = $this->createMock(AiProvider::class);
        $aiProvider->method('generate')->willReturnOnConsecutiveCalls(
            $this->chunkResult($this->explainerResearch(), 100),
            $this->chunkResult($this->explainerCore(), 110),
            $this->chunkResult($this->explainerMethod(), 120),
            $this->chunkResult($this->explainerBrand(), 130),
        );

        $renderBridge = $this->createMock(RenderBridgeService::class);
        $renderBridge->method('render')->willReturn(new RenderResult(
            success: true,
            renderedPaths: ['markdown' => "executions/{$execution->id}/explainer.md"],
            qualityReport: ['passed' => true, 'score' => 90, 'checks' => []],
        ));

        $runner = new SkillAgentRunnerService(
            $this->createMock(SkillRegistry::class),
            $aiProvider,
            $this->createMock(KnowledgeRetrieval::class),
            $this->createMock(SkillAssetLoader::class),
            $renderBridge,
            $this->createMock(PromptTemplateService::class),
            app(ModelUsageRecorder::class),
        );

        $assets = new SkillAssets(
            skillId: 'yao-geo-explainer-builder',
            skillMd: '# SKILL 方法论',
            references: [],
            renderScript: '/skills/render_explainer.py',
            exampleJsonSchema: '{"title":"string","article_sections":"[[heading,body]]"}',
            hasAssets: true,
        );

        $skill = new SkillDefinition(
            id: 'yao-geo-explainer-builder',
            name: '解释型文章',
            category: SkillCategory::GeoContentProduction,
            maturity: 'beta',
            requiresWeb: false,
            stageIndex: 4,
            description: 'explainer',
        );

        $brief = new SkillBrief(
            skillId: 'yao-geo-explainer-builder',
            projectId: $project->id,
            userId: $admin->id,
            fields: ['brand_name' => 'Acme'],
            executionId: $execution->id,
        );

        TenantContext::set($tenant);
        try {
            $specs = $this->invokePrivate($runner, 'chunkSpecsFor', ['yao-geo-explainer-builder', true]);
            $this->assertIsArray($specs, 'explainer 应命中分段谱');
            $this->assertCount(4, $specs);

            $method = new ReflectionMethod($runner, 'executeChunked');
            $method->setAccessible(true);
            /** @var SkillExecutionResult $result */
            $result = $method->invoke(
                $runner, $skill, $assets, $brief, 'SYS', '', microtime(true), (string) $tenant->id, $specs,
            );
        } finally {
            TenantContext::forget();
        }

        $this->assertTrue($result->success);
        $this->assertSame(4, $result->llmCallCount);
        $this->assertSame(460, $result->tokenUsage); // 100+110+120+130
        $this->assertSame(4, $result->intermediateData['chunks_parsed']);
        $this->assertTrue($result->intermediateData['chunked']);

        // 四段各自区域全部合并进 structured（绕开单次 8192 截断的根治点）
        $structured = $result->intermediateData['structured'];
        $this->assertIsArray($structured);
        foreach (['article_sections', 'research_basis', 'steps', 'faq'] as $section) {
            $this->assertArrayHasKey($section, $structured, "缺少区域 {$section}");
            $this->assertNotEmpty($structured[$section], "区域 {$section} 为空");
        }

        // 扣费收口：每段一条日志、共 4 条，同步/异步一致，不因分段翻倍
        $this->assertSame(4, ModelCallLog::query()->where('skill_execution_id', $execution->id)->count());
    }

    /**
     * 分段完整性闸 ①：某一段调用失败 → 整单判失败，不进渲染、不落 completed。
     * 此前只要还有一段成功就照常出报告，缺的表由补齐逻辑填空数组或模板行。
     */
    public function test_failed_chunk_fails_whole_run_and_skips_render(): void
    {
        [$tenant, $admin, $project, $execution] = $this->panoramaFixture();

        // 第 2 段（事实基线与实体画像）调用失败。失败会触发一次档位升级重试，
        // 故这一段消耗两次 generate，共 5 次。
        $aiProvider = $this->createMock(AiProvider::class);
        $aiProvider->method('generate')->willReturnOnConsecutiveCalls(
            $this->chunkResult($this->chunkFoundation(), 100),
            $this->failedChunkResult(),
            $this->failedChunkResult(),
            $this->chunkResult($this->chunkAssessment(), 120),
            $this->chunkResult($this->chunkAction(), 130),
        );

        // 缺内容就不该渲染：渲染一次就等于往下走了半份报告
        $renderBridge = $this->createMock(RenderBridgeService::class);
        $renderBridge->expects($this->never())->method('render');

        $result = $this->runChunked($tenant, $admin, $project, $execution, $aiProvider, $renderBridge);

        $this->assertFalse($result->success, '有段失败时不得当成功交付');
        $this->assertStringContainsString('事实基线与实体画像', $result->errorMessage ?? '', '要告诉用户是哪一段没成');
        $this->assertStringNotContainsString('/', $result->errorMessage ?? '', '带斜杠会被 friendlySkillError 换成英文通用错误');
        $this->assertNull($result->intermediateData['structured'] ?? null, '失败不得留下补齐过的结构化数据');
        $this->assertSame(['事实基线与实体画像'], $result->intermediateData['failed_chunks'] ?? []);

        // 4 段、5 次真实调用：升级前失败的调用也留记录，tokens 照实带回。
        $this->assertSame(5, ModelCallLog::query()->where('skill_execution_id', $execution->id)->count());
        $this->assertSame(5, $result->llmCallCount);
        $this->assertSame(350, $result->tokenUsage); // 100 + 0 + 120 + 130
    }

    /**
     * 分段完整性闸 ②：某一段 JSON 能解析、但一个自有字段都没交（空对象）
     * → 与解析失败同等对待。只看 json_decode 成没成会把这种空段放行。
     *
     * 这里把补跑预算关掉，单测这道闸本身。2026-09-06 补跑铺到综合诊断之后，
     * 同样的空段默认会先被补一次才轮到这道闸 —— 那条路见下面那个用例。
     */
    public function test_chunk_with_no_claimed_fields_fails_whole_run(): void
    {
        config(['geo.chunk_recovery_max_calls' => 0]);
        [$tenant, $admin, $project, $execution] = $this->panoramaFixture();

        $aiProvider = $this->createMock(AiProvider::class);
        $aiProvider->method('generate')->willReturnOnConsecutiveCalls(
            $this->chunkResult($this->chunkFoundation(), 100),
            $this->chunkResult($this->chunkEvidence(), 110),
            // 第 3 段：合法 JSON，但只回了别段的字段 → 本段等于什么都没交
            $this->chunkResult(['title' => '技术审计', 'summary' => [['结论', '越权字段']]], 120),
            $this->chunkResult($this->chunkAction(), 130),
        );

        $renderBridge = $this->createMock(RenderBridgeService::class);
        $renderBridge->expects($this->never())->method('render');

        $result = $this->runChunked($tenant, $admin, $project, $execution, $aiProvider, $renderBridge);

        $this->assertFalse($result->success, '空段必须判失败，不能靠补齐逻辑填空数组糊过去');
        $this->assertStringContainsString('技术审计与竞争评分', $result->errorMessage ?? '');
        $this->assertSame(0, $result->intermediateData['chunk_meta']['assessment']['fields'] ?? -1);
        $this->assertTrue($result->intermediateData['chunk_meta']['assessment']['parsed'] ?? false, 'JSON 是解析成功的，判失败靠的是字段数');
        $this->assertSame(3, $result->intermediateData['chunks_parsed'] ?? -1);
    }

    /**
     * 空段先补一次再说，别直接判死。
     *
     * 2026-09-06 老板拍板把补跑从意图挖掘铺到其余三个分段技能。综合诊断这类技能的验收
     * 口径是「这段至少交出一个字段」，分辨不到字段级，所以补跑单位是整段：一次调用，
     * 把这段该交的字段整批要回来。补回来了就照常出报告，不该让客户为我们的缺陷再付一次钱。
     */
    public function test_chunk_with_no_claimed_fields_is_repaired_instead_of_failing(): void
    {
        config(['geo.chunk_recovery_max_calls' => 6]);
        [$tenant, $admin, $project, $execution] = $this->panoramaFixture();

        $aiProvider = $this->createMock(AiProvider::class);
        $aiProvider->method('generate')->willReturnOnConsecutiveCalls(
            $this->chunkResult($this->chunkFoundation(), 100),
            $this->chunkResult($this->chunkEvidence(), 110),
            // 第 3 段：合法 JSON，但只回了别段的字段 → 本段等于什么都没交
            $this->chunkResult(['title' => '技术审计', 'summary' => [['结论', '越权字段']]], 120),
            // 补跑：整段重来一次，这次交对了
            $this->chunkResult($this->chunkAssessment(), 140),
            $this->chunkResult($this->chunkAction(), 130),
        );

        $renderBridge = $this->createMock(RenderBridgeService::class);
        $renderBridge->expects($this->once())->method('render')
            ->willReturn(new RenderResult(success: true, renderedPaths: ['html' => 'executions/report.html']));

        $result = $this->runChunked($tenant, $admin, $project, $execution, $aiProvider, $renderBridge);

        $this->assertTrue($result->success, '补回来了就该正常出报告');
        $this->assertSame(5, $result->llmCallCount, '4 段 + 1 次整段补跑');
        $this->assertSame(4, $result->intermediateData['chunks_parsed'] ?? -1);

        $meta = $result->intermediateData['chunk_meta']['assessment'];
        $this->assertTrue($meta['complete']);
        // 第二次调用必须是补跑，不是换模型重试——两者调用次数一样，只有这个标记能分开。
        $this->assertTrue($meta['attempts'][1]['recovery'], '第二次调用应当是补跑');
    }

    /**
     * 逃生阀：chunk_strict=false 时退回旧行为——还有段有内容就照常出报告。
     * 留它是为了上线后失败率爆掉能改一行 env 止血，不用重建镜像。
     * 放行的残缺报告必须留痕（incomplete_chunks），否则事后查不出哪些是松闸出去的。
     */
    public function test_loose_gate_lets_incomplete_report_through_but_records_it(): void
    {
        config(['geo.chunk_strict' => false]);

        [$tenant, $admin, $project, $execution] = $this->panoramaFixture();

        $aiProvider = $this->createMock(AiProvider::class);
        $aiProvider->method('generate')->willReturnOnConsecutiveCalls(
            $this->chunkResult($this->chunkFoundation(), 100),
            $this->failedChunkResult(),
            $this->failedChunkResult(), // 失败触发一次升级重试
            $this->chunkResult($this->chunkAssessment(), 120),
            $this->chunkResult($this->chunkAction(), 130),
        );

        $renderBridge = $this->createMock(RenderBridgeService::class);
        $renderBridge->expects($this->once())->method('render')->willReturn(new RenderResult(
            success: true,
            renderedPaths: ['markdown' => "executions/{$execution->id}/panorama.md"],
            qualityReport: ['passed' => true, 'score' => 80, 'checks' => []],
        ));

        $result = $this->runChunked($tenant, $admin, $project, $execution, $aiProvider, $renderBridge);

        $this->assertTrue($result->success, '逃生阀开着时应退回旧行为');
        $this->assertSame(['事实基线与实体画像'], $result->intermediateData['incomplete_chunks'] ?? []);
        $this->assertSame('loose', $result->intermediateData['chunk_gate'] ?? null);
        $this->assertIsArray($result->intermediateData['structured'] ?? null);
    }

    /**
     * 逃生阀开着也有底线：一段内容都没有时照样判失败（这是关掉闸门前的老行为）。
     */
    public function test_loose_gate_still_fails_when_no_chunk_produced_content(): void
    {
        config(['geo.chunk_strict' => false]);

        [$tenant, $admin, $project, $execution] = $this->panoramaFixture();

        $aiProvider = $this->createMock(AiProvider::class);
        $aiProvider->method('generate')->willReturn($this->failedChunkResult());

        $renderBridge = $this->createMock(RenderBridgeService::class);
        $renderBridge->expects($this->never())->method('render');

        $result = $this->runChunked($tenant, $admin, $project, $execution, $aiProvider, $renderBridge);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('都没有产出可用内容', $result->errorMessage ?? '');
    }

    /**
     * 默认必须是严格的：没人显式配置时，缺段就判失败。
     * 这条守的是「逃生阀忘了关」——默认值一旦被改成 false，闸门等于没上。
     */
    public function test_gate_defaults_to_strict(): void
    {
        $this->assertTrue(
            (bool) config('geo.chunk_strict'),
            'chunk_strict 默认必须为 true，否则分段完整性闸等于没装',
        );
    }

    /**
     * 逃生阀必须真的能被 env 扳动。
     *
     * 🔴 这条是补票：上一版写成「(string) env(...) 再比对 false 字面量」，而 env()
     * 拿到的不是原始字符串——Laravel 会把 "false" 先转成布尔 false，(string) false
     * 是空串，比对永远不成立，开关是死的。当时只测了「默认是严格」，测试全绿而开关
     * 根本扳不动，是发版前进镜像实跑才抓到的。所以这里逐个取值实测，不测默认值。
     *
     * 直接重新求值配置文件（它是个纯 return 数组、无副作用），这样测的是真解析逻辑，
     * 不是已经装进容器的那份缓存。
     */
    public function test_chunk_strict_env_switch_actually_flips(): void
    {
        $cases = [
            // 显式关闭：松闸
            ['false', false], ['FALSE', false], ['0', false], ['off', false], ['no', false],
            // 显式开启
            ['true', true], ['1', true], ['yes', true],
            // 空值 / 空白 / 乱码：一律保持严格，绝不能悄悄把安全闸关掉
            ['', true], ['   ', true], ['garbage', true],
        ];

        try {
            foreach ($cases as [$raw, $expected]) {
                Env::getRepository()->set('GEO_CHUNK_STRICT', $raw);
                $config = require config_path('geo.php');

                $this->assertSame(
                    $expected,
                    $config['chunk_strict'],
                    "GEO_CHUNK_STRICT=\"{$raw}\" 时严格闸应为 ".($expected ? '开' : '关'),
                );
            }
        } finally {
            Env::getRepository()->clear('GEO_CHUNK_STRICT');
        }

        $config = require config_path('geo.php');
        $this->assertTrue($config['chunk_strict'], '未设置这个环境变量时必须保持严格');
    }

    /**
     * 截断质量闸：未登记分段谱的渲染管线技能，单次输出撞上限（finish_reason=length）
     * 且渲染未成功 → 必须显式失败，绝不静默落半截报告标 completed。
     */
    public function test_truncated_render_pipeline_execution_fails_loudly(): void
    {
        [$tenant, $admin, $project] = $this->tenantProjectAdmin();

        $skillId = 'yao-geo-comparison-writer'; // 非 panorama/roadmap/explainer → 不分段

        $skill = new SkillDefinition(
            id: $skillId,
            name: '对比型文章',
            category: SkillCategory::GeoContentProduction,
            maturity: 'stable',
            requiresWeb: false,
            stageIndex: 4,
            description: 'comparison',
        );

        $assets = new SkillAssets(
            skillId: $skillId,
            skillMd: '# SKILL 方法论',
            references: [],
            renderScript: '/skills/render_comparison.py',
            exampleJsonSchema: '{"title":"string"}',
            hasAssets: true,
        );

        $registry = $this->createMock(SkillRegistry::class);
        $registry->method('find')->willReturn($skill);

        $assetLoader = $this->createMock(SkillAssetLoader::class);
        $assetLoader->method('loadAll')->willReturn($assets);

        $prompts = $this->createMock(PromptTemplateService::class);
        $prompts->method('applyOverride')->willReturn($assets);

        $renderBridge = $this->createMock(RenderBridgeService::class);
        $renderBridge->method('hasRenderScript')->willReturn(true);

        // 撞上限被截断：finish_reason=length + 无法解析的残缺 JSON。
        // 正文必须足够长——真被截断的报告是几千字，只有几十字那种是「推理吃光预算」，
        // 走的是另一条分支（见 test_reasoning_starved_output_does_not_escalate）。
        $truncated = '```json'."\n".'{"title":"对比","sections":['
            .str_repeat('{"heading":"小节标题","body":"这里是正文内容，反复若干段。"},', 40)
            .'{"heading":"最后一节","body":"正文被截断在这里';

        $aiProvider = $this->createMock(AiProvider::class);
        $aiProvider->expects($this->once())->method('generate')->willReturn(new AiProviderCallResult(
            success: true,
            provider: 'gateway',
            model: 'text',
            content: $truncated,
            promptTokens: 4000,
            completionTokens: 8192,
            totalTokens: 12192,
            durationSeconds: 2.0,
            finishReason: 'length',
        ));

        $runner = new SkillAgentRunnerService(
            $registry,
            $aiProvider,
            $this->createMock(KnowledgeRetrieval::class),
            $assetLoader,
            $renderBridge,
            $prompts,
            app(ModelUsageRecorder::class),
        );

        TenantContext::set($tenant);
        try {
            $execution = SkillExecution::query()->create([
                'tenant_id' => $tenant->id,
                'project_id' => $project->id,
                'user_id' => $admin->id,
                'skill_id' => $skillId,
                'skill_name' => '对比型文章',
                'category' => SkillCategory::GeoContentProduction->value,
                'stage_index' => 4,
                'status' => 'running',
                'execution_mode' => 'sync',
                'input_data' => [],
            ]);

            $brief = new SkillBrief(
                skillId: $skillId,
                projectId: $project->id,
                userId: $admin->id,
                fields: ['brand_name' => 'Acme'],
                executionId: $execution->id,
            );

            $result = $runner->execute($brief);
        } finally {
            TenantContext::forget();
        }

        // 截断 → 显式失败，不假成功
        $this->assertFalse($result->success, '截断输出必须显式失败');

        // 2026-09-07 文案改口径（本用例的行为没变，变的是「该怎么跟客户解释」）：
        // 这个夹具的 completionTokens 是 8192，而 runner 请求的是 16384 —— 也就是说
        // 砍断它的东西在我们请求的额度**以下**（8192 是早期 provider 的硬上限）。
        // 这类情况和客户写了多长无关，叫他「精简输入」照做也不会好转，还会再扣一次钱。
        // 生产 execution 53 是同一类：请求 16384、实得 12810，网关按剩余时间反算的上限。
        $this->assertStringContainsString('单次生成的上限', $result->errorMessage ?? '');
        $this->assertStringNotContainsString(
            '精简输入',
            $result->errorMessage ?? '',
            '被我们请求额度以下的上限砍断时，不能把责任推给客户的输入',
        );
        $this->assertTrue($result->intermediateData['gateway_time_capped'] ?? false);

        // tokens 已真实消耗 → 照常记一条账（不因失败而漏记，也不重复）
        $this->assertSame(1, ModelCallLog::query()->where('skill_execution_id', $execution->id)->count());
    }

    /**
     * 推理吃光输出预算（finish_reason=length + 正文近乎为空）时：
     *   ① 不得升级档位——升级只会换到推理更重的模型，同样写不出正文，白扣第二次钱；
     *   ② 错误提示不得叫用户「精简输入」——输入长短跟这个故障无关。
     * 根因见 2026-08-18 直连实测：DeepSeek 把推理 token 算进 max_tokens。
     */
    public function test_reasoning_starved_output_does_not_escalate(): void
    {
        [$tenant, $admin, $project] = $this->tenantProjectAdmin();

        $skillId = 'yao-geo-comparison-writer'; // 非分段技能，走单次调用路径

        $skill = new SkillDefinition(
            id: $skillId,
            name: '对比型文章',
            category: SkillCategory::GeoContentProduction,
            maturity: 'stable',
            requiresWeb: false,
            stageIndex: 4,
            description: 'comparison',
        );

        $assets = new SkillAssets(
            skillId: $skillId,
            skillMd: '# SKILL 方法论',
            references: [],
            renderScript: '/skills/render_comparison.py',
            exampleJsonSchema: '{"title":"string"}',
            hasAssets: true,
        );

        $registry = $this->createMock(SkillRegistry::class);
        $registry->method('find')->willReturn($skill);

        $assetLoader = $this->createMock(SkillAssetLoader::class);
        $assetLoader->method('loadAll')->willReturn($assets);

        $prompts = $this->createMock(PromptTemplateService::class);
        $prompts->method('applyOverride')->willReturn($assets);

        $renderBridge = $this->createMock(RenderBridgeService::class);
        $renderBridge->method('hasRenderScript')->willReturn(true);

        // 推理把 max_tokens 用光：completion 全是推理 token，正文 0 字
        $aiProvider = $this->createMock(AiProvider::class);
        $aiProvider->expects($this->once()) // ← 关键：只调一次，不升级
            ->method('generate')
            ->willReturn(new AiProviderCallResult(
                success: true,
                provider: 'gateway',
                model: 'text',
                content: '',
                promptTokens: 4000,
                completionTokens: 8192,
                totalTokens: 12192,
                durationSeconds: 2.0,
                finishReason: 'length',
            ));

        $runner = new SkillAgentRunnerService(
            $registry,
            $aiProvider,
            $this->createMock(KnowledgeRetrieval::class),
            $assetLoader,
            $renderBridge,
            $prompts,
            app(ModelUsageRecorder::class),
        );

        TenantContext::set($tenant);
        try {
            $execution = SkillExecution::query()->create([
                'tenant_id' => $tenant->id,
                'project_id' => $project->id,
                'user_id' => $admin->id,
                'skill_id' => $skillId,
                'skill_name' => '对比型文章',
                'category' => SkillCategory::GeoContentProduction->value,
                'stage_index' => 4,
                'status' => 'running',
                'execution_mode' => 'sync',
                'input_data' => [],
            ]);

            $brief = new SkillBrief(
                skillId: $skillId,
                projectId: $project->id,
                userId: $admin->id,
                fields: ['brand_name' => 'Acme'],
                executionId: $execution->id,
            );

            $result = $runner->execute($brief);
        } finally {
            TenantContext::forget();
        }

        $this->assertFalse($result->success);
        $this->assertStringContainsString('推理', $result->errorMessage ?? '');
        $this->assertStringNotContainsString(
            '精简输入',
            $result->errorMessage ?? '',
            '正文 0 字跟输入长短无关，不能叫用户精简输入',
        );

        // 只有一次真实调用 → 只记一条账，没有因升级而重复计费
        $this->assertSame(1, ModelCallLog::query()->where('skill_execution_id', $execution->id)->count());
    }

    private function explainerCore(): array
    {
        return [
            'skill_id' => 'yao-geo-explainer-builder',
            'slug' => 'acme-guide',
            'title' => 'Acme 使用指南',
            'topic' => 'AI 搜索',
            'target_brand' => 'Acme',
            'target_audience' => '中小企业运营',
            'use_case' => '内容营销',
            'sensitive_domain' => '否',
            'brand_insertion_allowed' => '是',
            'max_brand_mentions' => '3',
            'analysis_date' => '2026-07-29',
            'core_answer' => str_repeat('面向具体问题核对原始资料，清楚解释事实与适用边界，并给出读者可执行的下一步。', 3),
            'article_sections' => [['引言', '正文一'], ['方法', '正文二']],
            'analysis_summary' => [['结论', '摘要']],
        ];
    }

    private function explainerResearch(): array
    {
        return [
            'research_basis' => [['研究', '结论', '来源']],
            'sources' => [['事实', '类型', 'url']],
            'evidence_assessment' => [['主张', '成熟度', '来源']],
            'data_availability' => [['数据', '可得性', '备注']],
            'data_source_plan' => [['字段', '来源', '采集']],
            'fact_verification_matrix' => [['事实', '核验', '状态']],
        ];
    }

    private function explainerMethod(): array
    {
        return [
            'key_values' => [['价值', '说明']],
            'entity_map' => [['实体', '关系', '来源']],
            'definitions' => [['术语', '定义']],
            'principles' => [['原则', '说明']],
            'steps' => [['步骤', '动作', '工具']],
            'problem_type_map' => [['问题类型', '意图', '示例']],
            'audience_scenarios' => [['受众', '场景', '需求']],
            'selection_criteria' => [['标准', '权重']],
            'decision_paths' => [['路径', '条件', '结论']],
            'misconceptions' => [['误区', '澄清']],
            'example' => [['示例', '说明']],
        ];
    }

    private function explainerBrand(): array
    {
        return [
            'faq' => [['问题', '回答']],
            'glossary' => [['词条', '解释']],
            'brand_scenarios' => [['场景', '植入']],
            'brand_insertions' => [['位置', '文案', '边界']],
            'platform_notes' => [['平台', '适配']],
            'schema_recommendations' => [['类型', '用途', '必填']],
            'measurement_plan' => [['指标', '工具', '频率']],
            'content_gap_opportunities' => [['缺口', '机会']],
            'pre_publish_checks' => [['检查', '标准']],
            'compliance_notes' => [['合规', '说明']],
            'pending_confirmations' => [['待确认', '负责人']],
        ];
    }

    /**
     * 全景分段测试的公共夹具：租户 + 管理员 + 项目 + 一条 running 的执行记录。
     *
     * @return array{0: Tenant, 1: User, 2: Project, 3: SkillExecution}
     */
    private function panoramaFixture(): array
    {
        [$tenant, $admin, $project] = $this->tenantProjectAdmin();

        TenantContext::set($tenant);
        try {
            $execution = SkillExecution::query()->create([
                'tenant_id' => $tenant->id,
                'project_id' => $project->id,
                'user_id' => $admin->id,
                'skill_id' => 'yao-geo-panorama-audit',
                'skill_name' => '全景诊断',
                'category' => SkillCategory::GeoStrategy->value,
                'stage_index' => 1,
                'status' => 'running',
                'execution_mode' => 'async',
                'input_data' => [],
            ]);
        } finally {
            TenantContext::forget();
        }

        return [$tenant, $admin, $project, $execution];
    }

    /**
     * 直接驱动 executeChunked（跳过注册表/资产加载），返回分段执行结果。
     */
    private function runChunked(
        Tenant $tenant,
        User $admin,
        Project $project,
        SkillExecution $execution,
        AiProvider $aiProvider,
        RenderBridgeService $renderBridge,
    ): SkillExecutionResult {
        $runner = new SkillAgentRunnerService(
            $this->createMock(SkillRegistry::class),
            $aiProvider,
            $this->createMock(KnowledgeRetrieval::class),
            $this->createMock(SkillAssetLoader::class),
            $renderBridge,
            $this->createMock(PromptTemplateService::class),
            app(ModelUsageRecorder::class),
        );

        $assets = new SkillAssets(
            skillId: 'yao-geo-panorama-audit',
            skillMd: '# SKILL 方法论',
            references: [],
            renderScript: '/skills/render_panorama.py',
            exampleJsonSchema: '{"title":"string"}',
            hasAssets: true,
        );

        $skill = new SkillDefinition(
            id: 'yao-geo-panorama-audit',
            name: '全景诊断',
            category: SkillCategory::GeoStrategy,
            maturity: 'beta',
            requiresWeb: false,
            stageIndex: 1,
            description: 'panorama',
        );

        $brief = new SkillBrief(
            skillId: 'yao-geo-panorama-audit',
            projectId: $project->id,
            userId: $admin->id,
            fields: ['brand_name' => 'Acme'],
            executionId: $execution->id,
        );

        TenantContext::set($tenant);
        try {
            $specs = $this->invokePrivate($runner, 'chunkSpecsFor', ['yao-geo-panorama-audit', true]);
            $method = new ReflectionMethod($runner, 'executeChunked');
            $method->setAccessible(true);

            return $method->invoke(
                $runner, $skill, $assets, $brief, 'SYS', '', microtime(true), (string) $tenant->id, $specs,
            );
        } finally {
            TenantContext::forget();
        }
    }

    private function failedChunkResult(): AiProviderCallResult
    {
        return new AiProviderCallResult(
            success: false,
            provider: 'gateway',
            model: 'text',
            content: '',
            durationSeconds: 1.0,
            errorMessage: 'upstream timeout',
        );
    }

    private function invokePrivate(object $target, string $method, array $args): mixed
    {
        $m = new ReflectionMethod($target, $method);
        $m->setAccessible(true);

        return $m->invoke($target, ...$args);
    }

    private function roadmapFoundation(): array
    {
        return [
            'report' => ['title' => '执行路线图', 'company' => 'Acme', 'website' => 'https://acme.example.test'],
            'executive_summary' => '面向 AI 搜索的执行路线图摘要',
            'diagnosis_bridge' => [['诊断', '结论', '动作']],
            'north_star_metrics' => [['指标', '基线', '目标']],
            'target_platforms' => ['知乎', '小红书', '微信公众号'],
            'analysis_dimensions' => [['维度', '现状', '缺口']],
            'evidence_maturity' => [['证据', '成熟度', '来源']],
            'source_basis' => [['来源', '事实', '用途']],
        ];
    }

    private function roadmapPackages(): array
    {
        return [
            'project_packages' => [['包', '目标', '产出', '周期', '负责人']],
            'platform_actions' => [['平台', '动作', '主张', '优先级']],
            'roadmap' => [['阶段', '目标', '里程碑', '产出']],
        ];
    }

    private function roadmapGovernance(): array
    {
        return [
            'governance_plan' => [['环节', '角色', '频率']],
            'monitoring_plan' => [['指标', '工具', '频率']],
            'risk_plan' => [['风险', '影响', '处理']],
            'resource_budget' => [['项', '投入', '产出']],
            'real_data_plan' => [['数据', '来源', '采集']],
            'data_acquisition' => [['字段', '方法', '状态']],
        ];
    }

    private function chunkResult(array $json, int $tokens): AiProviderCallResult
    {
        return new AiProviderCallResult(
            success: true,
            provider: 'gateway',
            model: 'text',
            content: "```json\n".json_encode($json, JSON_UNESCAPED_UNICODE)."\n```",
            promptTokens: (int) ($tokens / 2),
            completionTokens: (int) ($tokens / 2),
            totalTokens: $tokens,
            durationSeconds: 1.0,
        );
    }

    private function chunkFoundation(): array
    {
        return [
            'title' => '孟德 GEO 全景诊断报告',
            'brand_name' => 'Acme',
            'subtitle' => '面向 AI 搜索的可见性诊断',
            'summary' => [['结论', '分段诊断已生成']],
            'method_basis' => [['方法', '执行', '字段', '风险'], ['m2', 'e2', 'f2', 'r2'], ['m3', 'e3', 'f3', 'r3']],
            'authority_map' => [['来源', '原则', '应用', '边界'], ['a2', 'p2', 'ap2', 'b2'], ['a3', 'p3', 'ap3', 'b3']],
            'question_coverage' => [['意图', '问题', '状态', '缺口', '动作'], ['i2', 'q2', 's2', 'g2', 'ac2']],
        ];
    }

    private function chunkEvidence(): array
    {
        return [
            'baseline' => [['域', '页', '事实', '来源', '状态', '方向'], ['d2', 'p2', 'f2', 's2', 'st2', 'dir2']],
            'crawl_inventory' => [['类型', 'url', '状态', '事实', '缺失', '下一步']],
            'entity_profile' => [['项', '值', '来源', '状态', '动作']],
            'product_map' => [['产品', '能力', '受众', '模型', '来源', '缺口']],
        ];
    }

    private function chunkAssessment(): array
    {
        return [
            'technical_audit' => [['项', '状态', '证据', '风险', '动作']],
            'competitors' => [['扣子', '优势', '缺口', '参考', '边界'], ['Dify', '优势2', '缺口2', '参考2', '边界2']],
            'scores' => [
                ['语义密度', '4/5', '证', '动作'],
                ['结构规范性', '4/5', '证', '动作'],
                ['可引用性', '3/5', '证', '动作'],
                ['权威信号', '3/5', '证', '动作'],
                ['可读性', '5/5', '证', '动作'],
                ['鲁棒性', '3/5', '证', '动作'],
                ['新颖性', '4/5', '证', '动作'],
                ['跨域贡献', '3/5', '证', '动作'],
            ],
        ];
    }

    private function chunkAction(): array
    {
        return [
            'opportunities' => [['内容', '机会', '问题', '价值', '成本', '优先级', '负责人', '标准']],
            'repairs' => [['模块', '问题', '动作', '产出', '来源', '标准']],
            'offsite_plan' => [['渠道', '动作', '主张', '优先级', '负责人', '标准']],
            'sources' => [['事实', '类型', '状态', '用途', '备注']],
            'risk_register' => [['风险', '影响', '优先级', '负责人', '处理']],
            'section_meanings' => ['method_basis' => '方法论依据'],
            'conclusion' => '收尾结论',
        ];
    }

    private function tenantProjectAdmin(): array
    {
        $tenant = Tenant::query()->create([
            'subdomain' => 'chunked-panorama',
            'name' => 'CHUNKED',
            'plan' => 'basic',
            'status' => 'active',
        ]);

        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => '13800000016',
            'role' => 'admin',
        ]);

        TenantContext::set($tenant);
        try {
            $project = Project::query()->create([
                'owner_id' => $admin->id,
                'name' => 'Chunked Project',
                'brand_name' => 'Acme',
                'brand_url' => 'https://acme.example.test',
                'settings' => [],
            ]);
        } finally {
            TenantContext::forget();
        }

        return [$tenant, $admin, $project];
    }
}
