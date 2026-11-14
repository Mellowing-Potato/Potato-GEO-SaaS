<?php

namespace Tests\Feature;

use App\Models\Article;
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
use App\Services\SkillAgent\SkillAssetLoader;
use App\Services\SkillAgent\SkillAssets;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 「回复要不要被 json_decode」和「要不要强制 JSON 语法」必须是同一个判断。
 *
 * 背景（2026-09-06 生产 execution 46）：以前只在提示词里写「必须是合法 JSON」，模型照样
 * 能吐出括号配对错误的回复，而且 finish_reason=stop —— 它自己认为写完了。语法只能由上游
 * 的解码约束保证，写在提示词里不算数。
 *
 * 反方向同样要守住：没有渲染脚本的技能直接出 Markdown 正文，对它强制 JSON 会毁掉输出。
 */
class SkillJsonModeTest extends TestCase
{
    use RefreshDatabase;

    private array $calls = [];

    public function test_render_pipeline_skill_forces_json_syntax_upstream(): void
    {
        $result = $this->runSkill(hasRenderScript: true);

        $this->assertTrue($result->success);
        $this->assertCount(1, $this->calls);
        $this->assertTrue(
            $this->calls[0]['options']['json_object'],
            '有渲染脚本 = 回复要交给 json_decode，必须让上游按 JSON 语法解码',
        );
    }

    public function test_markdown_skill_is_not_forced_into_json(): void
    {
        $result = $this->runSkill(hasRenderScript: false);

        $this->assertTrue($result->success);
        $this->assertCount(1, $this->calls);
        $this->assertFalse(
            $this->calls[0]['options']['json_object'],
            '没有渲染脚本的技能直接出 Markdown 正文，强制 JSON 会把正文变成 JSON 字符串',
        );
    }

    public function test_renderer_content_review_failure_cannot_be_marked_completed(): void
    {
        $result = $this->runSkill(true, new RenderResult(
            success: true,
            renderedPaths: ['markdown' => 'executions/65/report.md'],
            qualityReport: [
                'passed' => false,
                'blocking' => true,
                'issues' => ['缺少关键词：页面模块与信息架构图'],
            ],
            errorMessage: 'quality review failed',
        ), SkillCategory::GeoContentProduction);

        $this->assertFalse($result->success);
        $this->assertSame('executions/65/report.md', $result->outputPaths['markdown']);
        $this->assertFalse($result->qualityReport['passed']);
        $this->assertStringContainsString('页面模块与信息架构图', $result->errorMessage);
        $this->assertCount(1, $this->calls, '质量失败不能触发第二次模型调用');
        $this->assertSame(0, Article::query()->count(), '质量失败必须在创建文章之前收口');
    }

    public function test_one_missing_array_closer_is_repaired_only_for_a_completed_single_call(): void
    {
        $broken = '{"title":"标题","items":["例子"}'.str_repeat('。', 400);
        $stopped = $this->runSkill(true, content: $broken);
        $this->assertTrue($stopped->success);
        $this->assertSame(['title' => '标题', 'items' => ['例子']], array_intersect_key(
            $stopped->intermediateData['structured'], array_flip(['title', 'items']),
        ));
        $this->assertSame($broken, $stopped->intermediateData['raw_output']);
        $this->assertCount(1, $this->calls);

        $this->calls = [];
        $truncated = $this->runSkill(true, content: $broken, finishReason: 'length');
        $this->assertFalse($truncated->success);
        $this->assertTrue($truncated->intermediateData['truncated_at_token_cap']);
        $this->assertCount(1, $this->calls);

        $this->calls = [];
        $unknown = $this->runSkill(true, content: $broken, finishReason: null);
        $this->assertTrue($unknown->intermediateData['json_parse_failed']);
        $this->assertArrayNotHasKey('structured', $unknown->intermediateData);
        $this->assertCount(1, $this->calls);
    }

    private function runSkill(
        bool $hasRenderScript,
        ?RenderResult $renderResult = null,
        SkillCategory $category = SkillCategory::GeoResearch,
        ?string $content = null,
        ?string $finishReason = 'stop',
    ): SkillExecutionResult {
        Http::preventStrayRequests();
        config([ 'geo.analysis_chunked' => false]);

        $tenant = Tenant::create(['subdomain' => 'json-mode-'.uniqid(), 'name' => 'JsonMode', 'plan' => 'basic', 'status' => 'active']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        TenantContext::set($tenant);

        try {
            $project = Project::create(['owner_id' => $user->id, 'name' => 'Acme', 'brand_name' => 'Acme']);

            $skillId = 'test-json-mode-skill';
            $assets = new SkillAssets(
                $skillId, '# 方法论', [],
                $hasRenderScript ? '/skills/render.py' : null,
                json_encode(['title' => '标题'], JSON_UNESCAPED_UNICODE),
                true,
            );

            $registry = $this->createMock(SkillRegistry::class);
            $registry->method('find')->willReturn(new SkillDefinition(
                id: $skillId, name: 'JSON 模式用例', category: $category,
                maturity: 'stable', requiresWeb: false, stageIndex: 0, description: '',
            ));

            $loader = $this->createMock(SkillAssetLoader::class);
            $loader->method('loadAll')->willReturn($assets);

            $prompts = $this->createMock(PromptTemplateService::class);
            $prompts->method('applyOverride')->willReturn($assets);

            $bridge = $this->createMock(RenderBridgeService::class);
            $bridge->method('hasRenderScript')->willReturn($hasRenderScript);
            $bridge->method('render')->willReturn($renderResult ?? new RenderResult(success: true, renderedPaths: ['html' => 'executions/report.html']));

            $provider = $this->createMock(AiProvider::class);
            $provider->method('generate')->willReturnCallback(
                function ($prompt, $system, $options) use ($content, $finishReason) {
                    $this->calls[] = compact('prompt', 'system', 'options');

                    return new AiProviderCallResult(
                        success: true, provider: 'gateway', model: 'text',
                        content: $content ?? json_encode(['title' => '标题'], JSON_UNESCAPED_UNICODE).str_repeat('。', 400),
                        promptTokens: 50, completionTokens: 50, totalTokens: 100,
                        durationSeconds: 1.0, finishReason: $finishReason,
                    );
                },
            );

            $runner = new SkillAgentRunnerService(
                $registry, $provider, $this->createMock(KnowledgeRetrieval::class),
                $loader, $bridge, $prompts, app(ModelUsageRecorder::class),
            );

            $execution = SkillExecution::query()->create([
                'project_id' => $project->id, 'user_id' => $user->id,
                'skill_id' => $skillId, 'skill_name' => 'JSON 模式用例',
                'category' => $category->value, 'stage_index' => 0,
                'status' => 'running', 'execution_mode' => 'sync', 'input_data' => [],
            ]);
            return $runner->execute(new SkillBrief(
                $skillId, $project->id, $user->id, ['brand_name' => 'Acme', 'model' => 'text'],
                executionId: $execution->id,
            ));
        } finally {
            TenantContext::forget();
        }
    }
}
