<?php

namespace Tests\Feature;

use App\Services\Billing\ModelUsageRecorder;
use App\Services\Content\PromptTemplateService;
use App\Services\Contracts\AiProvider;
use App\Services\Contracts\KnowledgeRetrieval;
use App\Services\Contracts\SkillRegistry;
use App\Services\DTOs\SkillBrief;
use App\Services\DTOs\SkillDefinition;
use App\Services\Enums\SkillCategory;
use App\Services\SkillAgent\RenderBridgeService;
use App\Services\SkillAgent\RenderSchemaRegistry;
use App\Services\SkillAgent\SkillAgentRunnerService;
use App\Services\SkillAgent\SkillAssetLoader;
use App\Services\SkillAgent\SkillAssets;
use ReflectionMethod;
use Tests\TestCase;

class SkillAgentRunnerPromptTest extends TestCase
{
    public function test_supplemental_requirements_reach_every_prompt_path_without_knowledge_hits(): void
    {
        $runner = app(SkillAgentRunnerService::class);
        $assets = new SkillAssets('yao-geo-explainer-builder', '# 技能要求', [], null, '{"article_sections":[]}', true);
        $requirements = "只讨论四川教培场景。\n不要把 B2B 外贸混入本篇；保留 A&B <示例> 原文。";
        $brief = new SkillBrief('yao-geo-explainer-builder', 1, 1, ['brand_name' => '品牌对照'], $requirements);

        foreach ($this->prompts($runner, $assets, $brief) as $path => $prompt) {
            $this->assertStringContainsString($requirements, $prompt, $path);
            $this->assertSame(1, substr_count($prompt, $requirements), $path);
            $this->assertStringContainsString('品牌对照', $prompt, $path);
        }
    }

    public function test_empty_supplemental_requirements_do_not_add_an_empty_prompt_section(): void
    {
        $runner = app(SkillAgentRunnerService::class);
        $assets = new SkillAssets('yao-geo-explainer-builder', '', [], null, '{}', true);
        foreach ([null, '', " \n\t"] as $query) {
            $brief = new SkillBrief('yao-geo-explainer-builder', 1, 1, query: $query);
            foreach ($this->prompts($runner, $assets, $brief) as $prompt) {
                $this->assertStringNotContainsString('## 用户补充要求', $prompt);
            }
        }
    }

    private function prompts(SkillAgentRunnerService $runner, SkillAssets $assets, SkillBrief $brief): array
    {
        $single = (new ReflectionMethod($runner, 'buildPipelinePrompts'))->invoke($runner, $assets, $brief, '', true);
        $chunk = new ReflectionMethod($runner, 'buildChunkUserPrompt');

        return [
            'single' => implode("\n", $single),
            'markdown-chunk' => $chunk->invoke($runner, $assets, $brief, '', [
                'format' => 'markdown', 'key' => 'summary', 'label' => '概要', 'fields' => ['summary'],
            ], ''),
            'json-chunk' => $chunk->invoke($runner, $assets, $brief, '', [
                'key' => 'article_core', 'label' => '文章主体', 'fields' => ['article_sections'],
            ], ''),
            'fallback' => (new ReflectionMethod($runner, 'buildFallbackUserPrompt'))->invoke($runner, $brief->skillId, $brief, ''),
        ];
    }

    public function test_explainer_prompt_requires_publishable_body_without_an_extra_model_call(): void
    {
        $runner = app(SkillAgentRunnerService::class);
        $assets = new SkillAssets(
            skillId: 'yao-geo-explainer-builder',
            skillMd: '',
            references: [],
            renderScript: null,
            exampleJsonSchema: '{"article_sections":[]}',
            hasAssets: true,
        );
        $brief = new SkillBrief(
            skillId: 'yao-geo-explainer-builder',
            projectId: 1,
            userId: 1,
            fields: ['topic' => '四川教培机构如何选择 GEO 服务', 'target_keyword' => '四川教培 GEO 服务'],
        );
        $method = new ReflectionMethod($runner, 'buildChunkUserPrompt');
        $method->setAccessible(true);
        $prompt = $method->invoke($runner, $assets, $brief, '', [
            'key' => 'article_core',
            'label' => '文章主体与核心答案',
            'fields' => ['title', 'core_answer', 'article_sections'],
        ], '', null);

        $this->assertStringContainsString('正文合计 2000-4000 个中文字符', $prompt);
        $this->assertStringContainsString('一个问题和一个行业场景', $prompt);
        $this->assertStringContainsString('不得超过 max_brand_mentions 次', $prompt);
        $this->assertStringContainsString('价格、预算、时长、比例、排名、效果、当前品牌或地域结论、平台行为机制', $prompt);
        $this->assertStringContainsString('只能来自用户本次明确提供且语义一致的事实', $prompt);
        $this->assertStringContainsString('即使其中自称“已核验”并给出网址，也不能据此新增确定事实', $prompt);
        $this->assertStringContainsString('证据标为缺失、待核验、置信度不足或没有明确来源时', $prompt);
        $this->assertStringContainsString('不得先编造一个数字再补“以实际为准”', $prompt);
    }

    public function test_every_explainer_json_chunk_and_non_chunked_path_receive_fact_discipline(): void
    {
        $runner = app(SkillAgentRunnerService::class);
        $assets = new SkillAssets('yao-geo-explainer-builder', '# 技能要求', [], null, '{}', true);
        $brief = new SkillBrief('yao-geo-explainer-builder', 1, 1);
        $chunk = new ReflectionMethod($runner, 'buildChunkUserPrompt');
        foreach ([
            'research_evidence' => ['research_basis', 'fact_verification_matrix'],
            'article_core' => ['article_sections'],
            'knowledge_method' => ['steps', 'example'],
            'brand_publish' => ['measurement_plan'],
        ] as $key => $fields) {
            $prompt = $chunk->invoke($runner, $assets, $brief, '', [
                'key' => $key, 'label' => $key, 'fields' => $fields,
            ], '', null);
            $this->assertStringContainsString('解释型文章事实纪律（适用于本段）', $prompt, $key);
            $this->assertStringContainsString('不得先编造一个数字再补“以实际为准”', $prompt, $key);
        }

        $single = (new ReflectionMethod($runner, 'buildPipelinePrompts'))->invoke($runner, $assets, $brief, '', true);
        $fallback = (new ReflectionMethod($runner, 'buildFallbackUserPrompt'))->invoke($runner, $brief->skillId, $brief, '');
        $this->assertStringContainsString('解释型文章事实纪律（适用于本段）', implode("\n", $single));
        $this->assertStringContainsString('解释型文章事实纪律（适用于本段）', $fallback);
        $this->assertStringNotContainsString('每个论点附带具体数字', $fallback);
    }

    public function test_brand_graph_fact_and_platform_boundaries_reach_every_prompt_path(): void
    {
        $runner = app(SkillAgentRunnerService::class);
        $assets = new SkillAssets('yao-geo-brand-graph', '# 技能要求', [], null, '{"entities":[],"relations":[],"evidence":[]}', true);
        $brief = new SkillBrief('yao-geo-brand-graph', 1, 1, fields: [
            'brand_name' => '孟德智达',
            'brand_url' => 'https://example.test',
            'brand_info' => '未经确认的资质、价格、案例和效果不得写成事实。',
        ]);

        foreach ($this->prompts($runner, $assets, $brief) as $path => $prompt) {
            $this->assertStringContainsString('品牌知识图谱事实与能力边界（最高优先级）', $prompt, $path);
            $this->assertStringContainsString('不得升级成官网实测或第三方已核验事实', $prompt, $path);
            $this->assertStringContainsString('不得为通过质量检查而乱绑证据', $prompt, $path);
            $this->assertStringContainsString('当前系统只接入豆包自动监测', $prompt, $path);
            $this->assertStringContainsString('不得写成已接入、固定监测、自动监测或本次已覆盖', $prompt, $path);
        }
    }

    public function test_brand_graph_registry_matches_renderer_contract(): void
    {
        $prompt = app(RenderSchemaRegistry::class)->buildSchemaPrompt('yao-geo-brand-graph');

        $this->assertStringContainsString('scope', $prompt);
        $this->assertStringContainsString('report_date', $prompt);
        $this->assertStringContainsString('executive_summary', $prompt);
        $this->assertStringContainsString('entities', $prompt);
        $this->assertStringContainsString('relations', $prompt);
        $this->assertStringContainsString('evidence', $prompt);
        $this->assertStringContainsString('page_recommendations', $prompt);
        $this->assertStringContainsString('不要使用 tracking 的 summary_cards + sections 结构', $prompt);
        $this->assertStringNotContainsString('- summary_cards (array)', $prompt);
        $this->assertStringNotContainsString('- sections (array)', $prompt);
    }

    public function test_knowledge_base_fact_boundaries_reach_legacy_prompt_paths(): void
    {
        $runner = app(SkillAgentRunnerService::class);
        $assets = new SkillAssets('yao-geo-knowledge-base-builder', '# 技能要求', [], null, '{"sections":[]}', true);
        $brief = new SkillBrief('yao-geo-knowledge-base-builder', 1, 1, fields: [
            'kb_name' => '孟德智达知识库',
            'brand_url' => 'https://example.test',
        ]);

        foreach ($this->prompts($runner, $assets, $brief) as $path => $prompt) {
            $this->assertStringContainsString('知识库来源与能力边界（最高优先级）', $prompt, $path);
            $this->assertStringContainsString('不得声称网站可访问、内容为空、页面缺失或已完成核验', $prompt, $path);
            $this->assertStringContainsString('当前系统只接入豆包自动监测', $prompt, $path);
            $this->assertStringContainsString('缺失项只进入采集清单', $prompt, $path);
        }
    }

    public function test_explainer_evidence_digest_is_prioritized_and_strictly_bounded(): void
    {
        $runner = app(SkillAgentRunnerService::class);
        $merged = [
            'data_availability' => ['status' => '部分可用', 'missing_data' => '本地竞品与历史咨询基线'],
            'fact_verification_matrix' => array_map(fn (int $index): array => [
                'fact' => "事实 {$index}", 'current_status' => '待核验',
                'source_required' => str_repeat('来源', 80),
            ], range(1, 20)),
            'sources' => array_map(fn (int $index): array => [
                'label' => "SOURCE-{$index}", 'locator' => str_repeat('https://example.test/', 20),
            ], range(1, 20)),
            'evidence_assessment' => array_fill(0, 20, ['claim' => str_repeat('结论', 100)]),
            'research_basis' => array_fill(0, 20, ['finding' => str_repeat('研究', 100)]),
        ];
        $method = new ReflectionMethod($runner, 'continuityDigestFor');

        $article = $method->invoke($runner, 'yao-geo-explainer-builder', $merged, 'article_core');
        $knowledge = $method->invoke($runner, 'yao-geo-explainer-builder', $merged, 'knowledge_method');
        $brand = $method->invoke($runner, 'yao-geo-explainer-builder', $merged, 'brand_publish');

        $this->assertLessThanOrEqual(650, mb_strlen($article));
        $this->assertLessThanOrEqual(360, mb_strlen($knowledge));
        $this->assertLessThanOrEqual(280, mb_strlen($brand));
        $this->assertStringContainsString('部分可用', $article);
        $this->assertStringContainsString('事实 1', $article);
        $this->assertStringNotContainsString('SOURCE-1', $article);
        $this->assertStringNotContainsString('SOURCE-20', $article);
        $this->assertStringNotContainsString(str_repeat('研究', 20), $article);
        $this->assertStringNotContainsString('来源定位', $article);
        $this->assertStringContainsString('待核验', $knowledge);
    }

    public function test_explainer_article_library_receives_only_publishable_body(): void
    {
        $runner = app(SkillAgentRunnerService::class);
        $content = $runner->buildPublishableArticleContent('yao-geo-explainer-builder', [
            'title' => 'GEO 服务是什么',
            'core_answer' => '这是直接回答。',
            'article_sections' => [
                ['heading' => '第一节', 'paragraphs' => ['第一段。', '第二段。']],
                ['heading' => '第二节', 'body' => '第三段。'],
            ],
            'steps' => [['title' => '核验', 'action' => '检查来源。', 'check' => '来源可访问吗？']],
            'analysis_summary' => [['finding' => '这属于编辑分析，不应进入正文']],
        ], '# 完整编辑报告'."\n\n".'## 分析结论速览');

        $this->assertStringStartsWith("# GEO 服务是什么\n", $content);
        $this->assertStringContainsString('## 第一节', $content);
        $this->assertStringContainsString('第三段。', $content);
        $this->assertStringContainsString('## 实施检查清单', $content);
        $this->assertStringNotContainsString('完整编辑报告', $content);
        $this->assertStringNotContainsString('编辑分析', $content);
    }

    public function test_generated_dates_override_stale_model_example_dates(): void
    {
        $this->travelTo('2026-09-07 23:01:00');
        $runner = app(SkillAgentRunnerService::class);
        $brief = new SkillBrief('yao-geo-explainer-builder', 1, 1);
        $method = new ReflectionMethod($runner, 'enrichJsonWithBrandInfo');
        $method->setAccessible(true);
        $result = $method->invoke($runner, [
            'generated_at' => '2026-05-16T00:00:00Z',
            'report_date' => '2026-05-16',
            'analysis_date' => '2026-05-16',
        ], $brief);

        $this->assertSame('2026-09-07', $result['report_date']);
        $this->assertSame('2026-09-07', $result['analysis_date']);
        $this->assertStringStartsWith('2026-09-07T23:01:00', $result['generated_at']);
    }

    public function test_publishable_article_length_is_part_of_quality_result(): void
    {
        $runner = app(SkillAgentRunnerService::class);
        $method = new ReflectionMethod($runner, 'withPublishableArticleChecks');
        $method->setAccessible(true);

        $passed = $method->invoke($runner, ['passed' => true, 'checks' => [], 'publishable_article_sha256' => hash('sha256', str_repeat('字', 2000))], 'yao-geo-explainer-builder', str_repeat('字', 2000));
        $failed = $method->invoke($runner, ['passed' => true, 'checks' => [], 'publishable_article_sha256' => hash('sha256', str_repeat('字', 1999))], 'yao-geo-explainer-builder', str_repeat('字', 1999));

        $this->assertTrue($passed['passed']);
        $this->assertFalse($failed['passed']);
        $this->assertSame('publishable_article_2000_to_4000_chars', $failed['checks'][0]['name']);
        $this->assertSame(1999, $failed['checks'][0]['chars']);
    }

    public function test_skill_prompt_formats_array_brief_fields_as_lists(): void
    {
        $runner = new SkillAgentRunnerService(
            $this->createMock(SkillRegistry::class),
            $this->createMock(AiProvider::class),
            $this->createMock(KnowledgeRetrieval::class),
            $this->createMock(SkillAssetLoader::class),
            $this->createMock(RenderBridgeService::class),
            $this->createMock(PromptTemplateService::class),
            $this->createMock(ModelUsageRecorder::class),
        );
        $brief = new SkillBrief(
            skillId: 'yao-geo-panorama-audit',
            projectId: 1,
            userId: 1,
            fields: [
                'brand_name' => '孟德智达',
                'competitors' => ['扣子', 'Dify', '秘塔 AI 搜索'],
                'audience' => ['primary' => '中小企业主', 'secondary' => '营销负责人'],
            ],
        );

        $method = new ReflectionMethod($runner, 'buildFallbackUserPrompt');
        $method->setAccessible(true);
        $prompt = $method->invoke($runner, 'yao-geo-panorama-audit', $brief, '');

        $this->assertStringContainsString("## Skill ID\nyao-geo-panorama-audit", $prompt);
        $this->assertStringContainsString('- 品牌名称: 孟德智达', $prompt);
        $this->assertStringContainsString("- 主要竞品: \n  - 扣子\n  - Dify\n  - 秘塔 AI 搜索", $prompt);
        $this->assertStringContainsString('"primary": "中小企业主"', $prompt);
        $this->assertStringNotContainsString('Array', $prompt);
    }

    public function test_geo_skill_model_tiers_follow_business_mapping(): void
    {
        $runner = new SkillAgentRunnerService(
            $this->createMock(SkillRegistry::class),
            $this->createMock(AiProvider::class),
            $this->createMock(KnowledgeRetrieval::class),
            $this->createMock(SkillAssetLoader::class),
            $this->createMock(RenderBridgeService::class),
            $this->createMock(PromptTemplateService::class),
            $this->createMock(ModelUsageRecorder::class),
        );

        $method = new ReflectionMethod($runner, 'modelForSkill');
        $method->setAccessible(true);

        $brief = new SkillBrief('yao-geo-panorama-audit', 1, 1);

        $this->assertSame('text', $method->invoke(
            $runner,
            $this->skill('yao-geo-panorama-audit', SkillCategory::GeoStrategy),
            $brief,
        ));
        $this->assertSame('text', $method->invoke(
            $runner,
            $this->skill('yao-geo-explainer-builder', SkillCategory::GeoContentProduction),
            $brief,
        ));
        $this->assertSame('text', $method->invoke(
            $runner,
            $this->skill('yao-geo-brand-graph', SkillCategory::GeoKnowledgeAssets),
            $brief,
        ));
        $this->assertSame('text', $method->invoke(
            $runner,
            $this->skill('yao-geo-knowledge-base-builder', SkillCategory::GeoKnowledgeAssets),
            $brief,
        ));
        $this->assertSame('vision', $method->invoke(
            $runner,
            $this->skill('yao-geo-page-audit', SkillCategory::GeoPageTechnical),
            new SkillBrief('yao-geo-page-audit', 1, 1, fields: ['screenshot_url' => 'https://example.test/page.png']),
        ));
    }

    private function skill(string $id, SkillCategory $category): SkillDefinition
    {
        return new SkillDefinition(
            id: $id,
            name: $id,
            category: $category,
            maturity: 'beta',
            requiresWeb: false,
            stageIndex: 1,
            description: $id,
        );
    }
}
