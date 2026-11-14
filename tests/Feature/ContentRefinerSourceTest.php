<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Contracts\AiProvider;
use App\Services\Contracts\KnowledgeRetrieval;
use App\Services\Contracts\SkillRegistry;
use App\Services\Content\PromptTemplateService;
use App\Services\Billing\ModelUsageRecorder;
use App\Services\DTOs\AiProviderCallResult;
use App\Services\DTOs\SkillBrief;
use App\Services\DTOs\SkillDefinition;
use App\Services\Enums\SkillCategory;
use App\Services\SkillAgent\RenderBridgeService;
use App\Services\SkillAgent\SkillAgentRunnerService;
use App\Services\SkillAgent\SkillAssetLoader;
use App\Services\SkillAgent\SkillAssets;
use App\Services\SkillAgent\SkillDeliveryAssembler;
use App\Services\SkillAgent\RenderSchemaRegistry;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentRefinerSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    public function test_real_source_enters_full_and_fallback_prompts_once(): void
    {
        [$user, $project, $article] = $this->fixture();
        foreach ([true, false] as $hasAssets) {
            $loader = $this->createMock(SkillAssetLoader::class);
            $loader->method('loadAll')->willReturn(new SkillAssets(
                'yao-geo-content-refiner', $hasAssets ? '# 优化原文' : '', [], null, null, $hasAssets,
            ));
            $this->app->instance(SkillAssetLoader::class, $loader);
            $brief = new SkillBrief('yao-geo-content-refiner', $project->id, $user->id, ['article_id' => $article->id]);
            $prepared = (new \ReflectionMethod(app(SkillAgentRunnerService::class), 'prepareRun'))
                ->invoke(app(SkillAgentRunnerService::class), $brief);
            $prompt = $prepared['userPrompt'];
            $this->assertSame(1, substr_count($prompt, $article->body_markdown));
            $this->assertStringContainsString($article->title, $prompt);
            $this->assertStringContainsString('"article_id":'.$article->id, $prompt);
            $this->assertStringNotContainsString('其他文章的秘密事实', $prompt);
            $this->assertStringContainsString('refined_article', $prompt);
        }
    }

    public function test_invalid_foreign_empty_and_long_sources_stop_before_retrieval_or_model(): void
    {
        [$user, $project, $article] = $this->fixture();
        $knowledge = $this->createMock(KnowledgeRetrieval::class);
        $knowledge->expects($this->never())->method('retrieve');
        $ai = $this->createMock(AiProvider::class);
        $ai->expects($this->never())->method('generate');
        $this->app->instance(KnowledgeRetrieval::class, $knowledge);
        $this->app->instance(AiProvider::class, $ai);
        $prepare = new \ReflectionMethod(app(SkillAgentRunnerService::class), 'prepareRun');
        $other = Project::create(['owner_id' => $user->id, 'name' => 'Other project']);
        $cases = [
            [$project->id, 0], [$project->id, '1 OR 1=1'], [$project->id, 999999],
            [$other->id, $article->id],
        ];
        foreach ($cases as [$projectId, $articleId]) {
            try {
                $prepare->invoke(app(SkillAgentRunnerService::class), new SkillBrief(
                    'yao-geo-content-refiner', $projectId, $user->id, ['article_id' => $articleId], query: '应触发检索',
                ));
                $this->fail('Invalid source should stop in preparation.');
            } catch (\RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $foreign = Tenant::create(['subdomain' => 'foreign-refiner', 'name' => 'Foreign', 'plan' => 'basic', 'status' => 'active']);
        TenantContext::set($foreign);
        try {
            $prepare->invoke(app(SkillAgentRunnerService::class), new SkillBrief('yao-geo-content-refiner', $project->id, $user->id, ['article_id' => $article->id], query: '应触发检索'));
            $this->fail('Foreign tenant source should stop.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('不可访问', $e->getMessage());
        }
        TenantContext::set(Tenant::findOrFail($user->tenant_id));
        foreach (['', str_repeat('字', 50001)] as $body) {
            $article->update(['body_markdown' => $body]);
            try {
                $prepare->invoke(app(SkillAgentRunnerService::class), new SkillBrief('yao-geo-content-refiner', $project->id, $user->id, ['article_id' => $article->id], query: '应触发检索'));
                $this->fail('Empty or long source should stop.');
            } catch (\RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_only_valid_refined_body_is_publishable(): void
    {
        $schema = (new RenderSchemaRegistry)->getSchema('yao-geo-content-refiner');
        foreach (['notice', 'meta', 'summary', 'refined_article', 'scorecard', 'diff_report', 'cms_html_advice'] as $field) {
            $this->assertArrayHasKey($field, $schema['required']);
        }
        $this->assertArrayNotHasKey('summary_cards', $schema['required']);
        $this->assertArrayNotHasKey('sections', $schema['required']);
        $assembler = app(SkillDeliveryAssembler::class);
        $body = $assembler->publishableArticleContent('yao-geo-content-refiner', [
            'title' => '报告标题', 'scorecard' => ['内部评分'],
            'refined_article' => [
                ['heading' => '库存台账', 'paragraphs' => ['原文事实：纸箱 42 件。']],
                ['heading' => '预警', 'paragraphs' => ['阈值 50 件。']],
            ],
        ], '{"raw":true}');
        $this->assertStringStartsWith('# 库存台账', $body);
        $this->assertStringContainsString('## 预警', $body);
        $this->assertStringNotContainsString('内部评分', $body);
        $this->assertStringNotContainsString('raw', $body);
        foreach ([[], ['refined_article' => [['heading' => 'H', 'paragraphs' => ['']]]],
            ['refined_article' => [['heading' => 'H', 'paragraphs' => 'wrong type']]]] as $bad) {
            $this->assertSame('', $assembler->publishableArticleContent('yao-geo-content-refiner', $bad, 'fallback'));
        }
    }

    public function test_fallback_execution_creates_new_draft_only_for_valid_refined_body(): void
    {
        [$user, $project, $article] = $this->fixture();
        $prompts = [];
        $responses = [
            ['title' => '分析报告', 'scorecard' => [['内部评分']], 'refined_article' => [
                ['heading' => '优化后的正文', 'paragraphs' => ['纸箱实盘 42 件，阈值 50 件。']],
            ]],
            ['title' => '分析报告', 'analysis' => str_repeat('诊断内容。', 12), 'scorecard' => [['内部评分']]],
        ];
        $ai = $this->createMock(AiProvider::class);
        $ai->expects($this->exactly(2))->method('generate')->willReturnCallback(
            function ($prompt) use (&$prompts, &$responses): AiProviderCallResult {
                $prompts[] = $prompt;
                return new AiProviderCallResult(
                    success: true, provider: 'mock', model: 'text',
                    content: json_encode(array_shift($responses), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    promptTokens: 10, completionTokens: 20, totalTokens: 30, finishReason: 'stop',
                );
            },
        );
        $registry = $this->createMock(SkillRegistry::class);
        $registry->method('find')->willReturn(new SkillDefinition(
            'yao-geo-content-refiner', '内容精修', SkillCategory::GeoContentProduction, 'stable', false, 2, '',
        ));
        $loader = $this->createMock(SkillAssetLoader::class);
        $loader->method('loadAll')->willReturn(new SkillAssets('yao-geo-content-refiner', '', [], null, null, false));
        $bridge = $this->createMock(RenderBridgeService::class);
        $bridge->method('hasRenderScript')->willReturn(false);
        $runner = new SkillAgentRunnerService(
            $registry, $ai, $this->createMock(KnowledgeRetrieval::class), $loader, $bridge,
            $this->createMock(PromptTemplateService::class), $this->createMock(ModelUsageRecorder::class),
        );
        $execution = SkillExecution::create([
            'project_id' => $project->id, 'user_id' => $user->id,
            'skill_id' => 'yao-geo-content-refiner', 'skill_name' => '内容精修',
            'category' => SkillCategory::GeoContentProduction->value,
            'stage_index' => 2, 'status' => 'running', 'execution_mode' => 'sync', 'input_data' => [],
        ]);
        $brief = new SkillBrief('yao-geo-content-refiner', $project->id, $user->id,
            ['article_id' => $article->id], executionId: $execution->id);
        $good = $runner->execute($brief);
        $this->assertTrue($good->success, $good->errorMessage ?? 'failed');
        $draft = Article::findOrFail($good->intermediateData['article_id']);
        $this->assertSame($project->id, $draft->project_id);
        $this->assertSame('editing', $draft->status);
        $this->assertSame("# 优化后的正文\n\n纸箱实盘 42 件，阈值 50 件。\n", $draft->body_markdown);
        $this->assertSame('唯一原文事实：阈值 50 件，实盘 42 件。', $article->fresh()->body_markdown);
        $this->assertStringContainsString('唯一原文事实：阈值 50 件', $prompts[0]);

        $second = $execution->replicate();
        $second->save();
        $bad = $runner->execute(new SkillBrief('yao-geo-content-refiner', $project->id, $user->id,
            ['article_id' => $article->id], executionId: $second->id));
        $this->assertFalse($bad->success);
        $this->assertSame('refined_article_missing_or_invalid', $bad->intermediateData['delivery_incomplete']);
        $this->assertSame(3, Article::count(), 'Missing refined body must not create another article.');
    }

    private function fixture(): array
    {
        $tenant = Tenant::create(['subdomain' => 'refiner-source', 'name' => 'Refiner', 'plan' => 'basic', 'status' => 'active']);
        TenantContext::set($tenant);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $project = Project::create(['owner_id' => $user->id, 'name' => 'Source']);
        $article = Article::create([
            'project_id' => $project->id, 'author_id' => $user->id, 'title' => '唯一原文标题',
            'body_markdown' => '唯一原文事实：阈值 50 件，实盘 42 件。', 'status' => 'editing', 'content_type' => 'general',
        ]);
        Article::create([
            'project_id' => $project->id, 'author_id' => $user->id, 'title' => '其他文章',
            'body_markdown' => '其他文章的秘密事实', 'status' => 'editing', 'content_type' => 'general',
        ]);

        return [$user, $project, $article];
    }
}
