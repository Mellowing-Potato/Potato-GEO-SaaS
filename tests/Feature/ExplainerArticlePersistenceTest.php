<?php

namespace Tests\Feature;

use App\Models\Article;
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
use App\Services\Enums\SkillCategory;
use App\Services\SkillAgent\RenderBridgeService;
use App\Services\SkillAgent\RenderResult;
use App\Services\SkillAgent\SkillAgentRunnerService;
use App\Services\SkillAgent\SkillAssetLoader;
use App\Services\SkillAgent\SkillAssets;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExplainerArticlePersistenceTest extends TestCase
{
    use RefreshDatabase;

    private const SKILL = 'yao-geo-explainer-builder';

    protected function setUp(): void
    {
        parent::setUp();
        // Each case owns its fake directory, including when other suites fake public concurrently.
        Storage::set('reports', Storage::fake('explainer-persistence-'.Str::uuid()));
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        Storage::disk('reports')->deleteDirectory('executions');
        parent::tearDown();
    }

    public static function executionModesAndQuality(): iterable
    {
        foreach ([false, true] as $chunked) {
            foreach (['passed', 'wrong_hash', 'renderer_failed', 'unrepresented_failure'] as $quality) {
                yield ($chunked ? 'chunked' : 'single').'_'.$quality => [$chunked, $quality];
            }
        }
    }

    #[DataProvider('executionModesAndQuality')]
    public function test_runner_persists_reviewed_article_and_updates_quality_download(bool $chunked, string $quality): void
    {
        config(['geo.analysis_chunked' => $chunked, 'geo.chunk_strict' => true]);
        [$tenant, $admin, $project, $execution] = $this->fixture();
        $publishedBody = "# 规范化后的解释文章\n\n";
        for ($section = 1; $section <= 4; $section++) {
            $publishedBody .= "## 经过核验的章节{$section}\n\n"
                .str_repeat('该方案围绕一个明确的使用场景说明操作步骤，并保留人工核验记录。', 22)."\n\n";
        }
        $renderedQuality = [
            'passed' => in_array($quality, ['passed', 'wrong_hash'], true),
            'score' => 90,
            'publishable_article_sha256' => hash('sha256', $quality === 'wrong_hash' ? 'a different article' : $publishedBody),
            'checks' => $quality === 'unrepresented_failure' ? [] : [
                ['name' => 'renderer_fact_check', 'passed' => $quality !== 'renderer_failed'],
            ],
        ];
        $directory = "executions/{$execution->id}";
        $reportPath = $directory.'/quality-report.json';
        $researchPath = $directory.'/research-report.md';
        $chunks = $this->modelChunks();
        $modelOutputs = $chunked
            ? [$chunks[1], $chunks[0], $chunks[2], $chunks[3]]
            : [array_merge(...$chunks)];
        $ai = $this->createMock(AiProvider::class);
        $ai->expects($this->exactly(count($modelOutputs)))->method('generate')
            ->willReturnOnConsecutiveCalls(...array_map(fn ($data) => new AiProviderCallResult(
                success: true,
                provider: 'local-test',
                model: 'text',
                content: json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                promptTokens: 50,
                completionTokens: 50,
                totalTokens: 100,
                finishReason: 'stop',
            ), $modelOutputs));
        $assets = new SkillAssets(self::SKILL, '# 本地解释文章规范', [], '/test/render.py', '{"title":"string"}', true);
        $skill = new SkillDefinition(self::SKILL, '解释文章', SkillCategory::GeoContentProduction, 'stable', false, 2, 'local test');
        $registry = $this->createMock(SkillRegistry::class);
        $registry->method('find')->willReturn($skill);
        $loader = $this->createMock(SkillAssetLoader::class);
        $loader->method('loadAll')->willReturn($assets);
        $prompts = $this->createMock(PromptTemplateService::class);
        $prompts->method('applyOverride')->willReturn($assets);
        $knowledge = $this->createMock(KnowledgeRetrieval::class);
        $knowledge->method('retrieve')->willReturn([]);
        $knowledge->method('buildContextString')->willReturn('');
        $billing = $this->createMock(ModelUsageRecorder::class);
        $billing->expects($this->exactly(count($modelOutputs)))->method('record')->willReturn(new ModelCallLog);
        $bridge = $this->createMock(RenderBridgeService::class);
        $bridge->method('hasRenderScript')->willReturn(true);
        $bridge->expects($this->once())->method('render')->willReturnCallback(
            function (array $structured) use ($chunks, $publishedBody, $renderedQuality, $reportPath, $researchPath): RenderResult {
                $this->assertSame($chunks[0]['article_sections'], $structured['article_sections']);
                $this->assertStringContainsString('RAW_UNNORMALIZED_BODY', $structured['publishable_article_markdown']);
                $this->assertNotSame($publishedBody, $structured['publishable_article_markdown']);
                Storage::disk('reports')->put($researchPath, '# RESEARCH_REPORT_NOT_ARTICLE');
                Storage::disk('reports')->put($reportPath, json_encode($renderedQuality, JSON_THROW_ON_ERROR));

                return new RenderResult(
                    success: true,
                    renderedPaths: ['markdown' => $researchPath, 'quality-report' => $reportPath],
                    qualityReport: $renderedQuality,
                    publishableArticle: $publishedBody,
                );
            },
        );
        $runner = new SkillAgentRunnerService($registry, $ai, $knowledge, $loader, $bridge, $prompts, $billing);
        $brief = new SkillBrief(self::SKILL, $project->id, $admin->id, ['brand_name' => 'Fixture Brand'], executionId: $execution->id);

        TenantContext::set($tenant);
        try {
            $result = $runner->execute($brief);
        } finally {
            TenantContext::forget();
        }

        $this->assertTrue($result->success, $result->errorMessage ?? 'runner failed');
        $this->assertSame($chunked ? 4 : 1, $result->llmCallCount);
        if ($chunked) {
            $this->assertTrue($result->intermediateData['chunked']);
            $this->assertSame(4, $result->intermediateData['chunks_parsed']);
        }
        $article = Article::withoutGlobalScopes()->findOrFail($result->intermediateData['article_id']);
        $this->assertSame($publishedBody, $article->body_markdown);
        $this->assertSame('规范化后的解释文章', $article->title);
        $this->assertSame('editing', $article->status);
        $this->assertSame($execution->id, $article->skill_execution_id);
        $this->assertStringNotContainsString('RAW_UNNORMALIZED_BODY', $article->body_markdown);
        $this->assertStringNotContainsString('RESEARCH_REPORT_NOT_ARTICLE', $article->body_markdown);
        $this->assertSame($chunks[0]['article_sections'], $result->intermediateData['structured']['article_sections']);
        $this->assertSame($chunks[1]['research_basis'], $result->intermediateData['structured']['research_basis']);

        $this->assertSame($quality === 'passed', $result->qualityReport['passed']);
        $checks = array_column($result->qualityReport['checks'], null, 'name');
        $this->assertTrue($checks['publishable_article_2000_to_4000_chars']['passed']);
        $this->assertSame($quality !== 'wrong_hash', $checks['publishable_article_matches_review']['passed']);
        if ($quality === 'wrong_hash') {
            $this->assertContains('publishable_article_matches_review', $result->qualityReport['issues']);
        } elseif ($quality === 'renderer_failed') {
            $this->assertFalse($checks['renderer_fact_check']['passed']);
        }
        $this->assertSame($reportPath, $result->outputPaths['quality-report']);
        $this->assertSame($result->qualityReport, json_decode(Storage::disk('reports')->get($reportPath), true, flags: JSON_THROW_ON_ERROR));
        $this->assertNotSame($renderedQuality, $result->qualityReport, 'final artifact must include the runner checks');
    }

    private function modelChunks(): array
    {
        return [
            [
                'skill_id' => self::SKILL, 'slug' => 'raw-explainer', 'title' => '原始模型标题',
                'topic' => '使用场景', 'target_brand' => 'Fixture Brand', 'target_audience' => '运营人员',
                'use_case' => '产品选择', 'sensitive_domain' => '否', 'brand_insertion_allowed' => '是',
                'max_brand_mentions' => 3, 'analysis_date' => '2026-09-08', 'core_answer' => str_repeat('回答应先明确适用场景和核验边界。', 6),
                'article_sections' => [['heading' => '原始章节', 'paragraphs' => ['RAW_UNNORMALIZED_BODY 旧品牌全称。']]],
                'analysis_summary' => [['结论', '模型原始内容']],
            ],
            [
                'research_basis' => [['研究记录', '原始依据', '待核验']], 'sources' => [['来源', '官方资料']],
                'evidence_assessment' => [['主张', '待核验']], 'data_availability' => [['数据', '缺失']],
                'data_source_plan' => [['数据', '采集方案']], 'fact_verification_matrix' => [['事实', '人工核验']],
            ],
            [
                'key_values' => [['价值', '说明']], 'entity_map' => [['实体', '关系']],
                'definitions' => [['术语', '定义']], 'principles' => [['原则', '说明']],
                'steps' => [['步骤', '检查']], 'problem_type_map' => [['问题', '类型']],
                'audience_scenarios' => [['受众', '场景']], 'selection_criteria' => [['标准', '权重']],
                'decision_paths' => [['条件', '路径']], 'misconceptions' => [['误区', '澄清']], 'example' => [['案例', '说明']],
            ],
            [
                'faq' => [['问题', '答案']], 'glossary' => [['词条', '解释']],
                'brand_scenarios' => [['品牌', '场景']], 'brand_insertions' => [['位置', '边界']],
                'platform_notes' => [['平台', '适配']], 'schema_recommendations' => [['类型', '用途']],
                'measurement_plan' => [['指标', '频率']], 'content_gap_opportunities' => [['缺口', '机会']],
                'pre_publish_checks' => [['检查', '标准']], 'compliance_notes' => [['范围', '说明']],
                'pending_confirmations' => [['待确认', '负责人']],
            ],
        ];
    }

    private function fixture(): array
    {
        $tenant = Tenant::query()->create(['subdomain' => 'explainer-persist', 'name' => 'Persistence', 'plan' => 'points', 'status' => 'active']);
        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        TenantContext::set($tenant);
        try {
            $project = Project::query()->create(['owner_id' => $admin->id, 'name' => 'Persistence', 'brand_name' => 'Fixture Brand', 'settings' => []]);
            $execution = SkillExecution::query()->create([
                'project_id' => $project->id, 'user_id' => $admin->id, 'skill_id' => self::SKILL,
                'skill_name' => '解释文章', 'category' => SkillCategory::GeoContentProduction->value,
                'stage_index' => 2, 'status' => 'running', 'execution_mode' => 'sync', 'input_data' => [],
            ]);
        } finally {
            TenantContext::forget();
        }

        return [$tenant, $admin, $project, $execution];
    }
}
