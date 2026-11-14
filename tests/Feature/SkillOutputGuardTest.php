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
use App\Services\SkillAgent\SkillAssetLoader;
use App\Services\SkillAgent\SkillAssets;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 「交白卷 / 交半截」都不许标成成功 —— 无论这个技能有没有渲染脚本。
 *
 * 背景（2026-09-06 生产 execution 51，页面审计）：
 * 空输出闸和截断闸原本都前置了 $hasRenderPipeline，等于「没有渲染脚本的技能交白卷不算
 * 问题」。页面审计没有渲染脚本，于是两道闸全部绕过：
 *
 *   finish_reason=length、completion_tokens=4096、content_length=0
 *   → 空输出闸不进 → 截断闸不进 → 走到「保存原始 Markdown」存了个空串
 *   → 状态标 completed、扣费 ¥0.071、客户拿到一份只有一行英文的空报告。
 *
 * 而 4096 这个额度本身也是病根：它当时按「有没有渲染脚本」分档，没有依据 —— 报告要多长
 * 取决于报告本身，和它是 JSON 还是 Markdown 无关。页面审计走 geo-reason 推理档，推理
 * token 按 DeepSeek 口径算进 max_tokens，4096 全被推理链吃光。
 *
 * 这个用例把三件事钉死，别再退回去。
 */
class SkillOutputGuardTest extends TestCase
{
    use RefreshDatabase;

    /** 每次 generate() 调用的入参，按顺序 */
    private array $calls = [];

    /**
     * 复刻 execution 51：无渲染脚本 + 推理档把额度烧光 + 正文 0 字。
     * 必须自动降到写作档补交正文；恢复调用由平台承担，不能让用户手动重试再扣一次。
     */
    public function test_starved_output_from_a_reasoning_skill_records_both_calls(): void
    {
        $result = $this->runSkill(
            hasRenderScript: false,
            responses: [$this->starved(), $this->ok(model: 'text')],
        );

        $this->assertTrue($result->success);
        $this->assertCount(2, $this->calls);
        $this->assertSame('text', $this->calls[0]['options']['model']);
        $this->assertSame('text', $this->calls[1]['options']['model'], '恢复只能降到写作档，不能升级到更重推理档');
        $logs = ModelCallLog::withoutGlobalScopes()->orderBy('id')->get();
        $this->assertCount(2, $logs, '两次真实调用都要留下 token 与成本记录');
        $this->assertSame(24170, $result->tokenUsage, '执行总用量必须包含失败首轮和恢复调用');
    }

    /**
     * 写了一半撞上限：同样判失败，但已经写出来的正文要交回去。
     * 钱已经扣了，不能让客户既付了钱又一个字都拿不到。
     */
    public function test_truncated_markdown_output_fails_but_keeps_what_was_written(): void
    {
        $written = str_repeat('这一段是已经写出来的审计正文。', 20);

        $result = $this->runSkill(
            hasRenderScript: false,
            responses: [$this->truncated($written)],
        );

        $this->assertFalse($result->success);
        $this->assertStringContainsString('精简输入', (string) $result->errorMessage);
        $this->assertSame($written, $result->intermediateData['raw_output'] ?? null,
            '判失败也要把已写出的正文带回去，ExecuteSkillJob 会无条件落进 result_text');
        $this->assertTrue($result->intermediateData['truncated_at_token_cap'] ?? false);
        $this->assertFalse($result->intermediateData['reasoning_starved'] ?? true);
    }

    /**
     * 撞的是网关的时间上限时，不许叫客户「精简输入」。
     *
     * 生产 execution 53 实证：请求 16384、实得 12810、耗时 228.9 秒、finish=length。
     * 网关会按「这次请求还剩多少秒」反算一个更低的上限（超时 280 秒，实测约 17.9
     * 毫秒/词元 → 天花板落在 12k 上下），所以完成词元远低于我们请求的额度也会被截断。
     * 这种情况下卡住它的是我们的架构上限，客户改输入没有用；照着「精简输入」重试
     * 会撞同一堵墙、再扣一次钱 —— 那就是让客户为我们的缺陷付第二次费。
     */
    public function test_a_gateway_time_cap_is_not_blamed_on_the_users_input(): void
    {
        $result = $this->runSkill(
            hasRenderScript: false,
            responses: [$this->truncated(str_repeat('这一段是已经写出来的审计正文。', 60), completionTokens: 12810)],
        );

        $this->assertFalse($result->success);
        $this->assertStringNotContainsString('精简输入', (string) $result->errorMessage,
            '卡住它的是平台单次生成上限，叫客户精简输入等于让他白重试一次再扣一次钱');
        $this->assertStringContainsString('我们这边的限制', (string) $result->errorMessage);
        $this->assertTrue($result->intermediateData['gateway_time_capped'] ?? false);
    }

    /**
     * 真的把我们给的额度写满了 —— 那才是「内容太长」，这时提示精简才成立。
     */
    public function test_filling_the_whole_budget_still_suggests_shortening(): void
    {
        $result = $this->runSkill(
            hasRenderScript: false,
            responses: [$this->truncated(str_repeat('正文', 60), completionTokens: 16384)],
        );

        $this->assertFalse($result->success);
        $this->assertStringContainsString('精简输入', (string) $result->errorMessage);
        $this->assertFalse($result->intermediateData['gateway_time_capped'] ?? true);
    }

    /**
     * 有渲染脚本的技能行为不变 —— 这道闸本来就管着它，改动只是把范围放开。
     */
    public function test_render_pipeline_skill_still_fails_on_truncation(): void
    {
        $result = $this->runSkill(
            hasRenderScript: true,
            // 必须够长（> MIN_USABLE_OUTPUT_CHARS = 40），否则会被判成「交白卷」
            // 而走推理饿死那条分支，用例就测不到截断提示了。
            responses: [$this->truncated('{"title":"半截 JSON","sections":["'.str_repeat('内容', 40))],
            renderSucceeds: false,
        );

        $this->assertFalse($result->success);
        $this->assertStringContainsString('精简输入', (string) $result->errorMessage);
    }

    /**
     * 输出额度不再按「有没有渲染脚本」分档。4096 那一档是 execution 51 的病根。
     */
    public function test_output_budget_no_longer_depends_on_having_a_render_script(): void
    {
        $this->runSkill(hasRenderScript: false, responses: [$this->ok()]);
        $withoutScript = $this->calls[0]['options']['max_tokens'];

        $this->calls = [];
        $this->runSkill(hasRenderScript: true, responses: [$this->ok('{"title":"标题"}')]);
        $withScript = $this->calls[0]['options']['max_tokens'];

        $this->assertSame(16384, $withoutScript, '没有渲染脚本不等于报告更短，4096 会把推理档饿死');
        $this->assertSame($withScript, $withoutScript);
    }

    /**
     * 空回复但没撞上限 = 模型真的返回了空，换档位有意义，这条路不能被一起关掉。
     */
    public function test_empty_output_that_did_not_hit_the_cap_still_escalates(): void
    {
        $result = $this->runSkill(
            hasRenderScript: false,
            responses: [
                $this->ok('', finishReason: 'stop'),
                $this->ok(),
            ],
        );

        $this->assertTrue($result->success);
        $this->assertCount(2, $this->calls, '空回复且未撞上限 → 应该升级档位重试一次');
        $this->assertSame('text', $this->calls[0]['options']['model']);
        $this->assertSame('text', $this->calls[1]['options']['model'], 'geo-reason 的上一档是 geo-plus');
    }

    // ── 回复构造 ──────────────────────────────────────────────

    /** 正常的一份 Markdown 正文（够长，不会撞 MIN_USABLE_OUTPUT_CHARS=40 那道空输出闸） */
    private function ok(?string $content = null, string $finishReason = 'stop', string $model = 'text'): AiProviderCallResult
    {
        return new AiProviderCallResult(
            success: true, provider: 'gateway', model: $model,
            content: $content ?? str_repeat('这是一段正常长度的审计正文。', 10),
            promptTokens: 50, completionTokens: 50, totalTokens: 100,
            durationSeconds: 1.0, finishReason: $finishReason,
        );
    }

    /** execution 51 的原样：撞上限、正文 0 字、推理链把额度吃光 */
    private function starved(): AiProviderCallResult
    {
        return new AiProviderCallResult(
            success: true, provider: 'gateway', model: 'text',
            content: '',
            promptTokens: 7686, completionTokens: 16384, totalTokens: 24070,
            durationSeconds: 66.6, finishReason: 'length',
            gateway: ['routing' => ['task' => ['profile' => 'analysis']]],
        );
    }

    /**
     * 写了一半撞上限。
     *
     * completionTokens 默认给 16384（＝把我们请求的额度写满了，属于「内容太长」）；
     * 传一个明显更小的值就是网关按时间预算提前钳死的那种（execution 53 实测 12810）。
     */
    private function truncated(string $content, int $completionTokens = 16384): AiProviderCallResult
    {
        return new AiProviderCallResult(
            success: true, provider: 'gateway', model: 'text',
            content: $content,
            promptTokens: 7686, completionTokens: $completionTokens,
            totalTokens: 7686 + $completionTokens,
            durationSeconds: 66.6, finishReason: 'length',
            gateway: ['routing' => ['task' => ['profile' => 'analysis']]],
        );
    }

    /**
     * @param  list<AiProviderCallResult>  $responses
     */
    private function runSkill(
        bool $hasRenderScript,
        array $responses,
        bool $renderSucceeds = true,
    ): SkillExecutionResult {
        Http::preventStrayRequests();
        config([ 'geo.analysis_chunked' => false]);

        // 有用例在同一个测试方法里跑两次（对比两种技能的额度），subdomain 唯一约束会撞。
        $tenant = Tenant::create([
            'subdomain' => 'output-guard-'.Tenant::count(),
            'name' => 'OutputGuard', 'plan' => 'basic', 'status' => 'active',
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        TenantContext::set($tenant);

        try {
            $project = Project::create(['owner_id' => $user->id, 'name' => 'Acme', 'brand_name' => 'Acme']);

            $skillId = 'test-output-guard-skill';
            $assets = new SkillAssets(
                $skillId, '# 方法论', [],
                $hasRenderScript ? '/skills/render.py' : null,
                json_encode(['title' => '标题'], JSON_UNESCAPED_UNICODE),
                true,
            );

            $registry = $this->createMock(SkillRegistry::class);
            // GeoPageTechnical = 页面审计所在的分类，走 geo-reason 推理档。
            $registry->method('find')->willReturn(new SkillDefinition(
                id: $skillId, name: '输出闸用例', category: SkillCategory::GeoPageTechnical,
                maturity: 'stable', requiresWeb: true, stageIndex: 3, description: '',
            ));

            $loader = $this->createMock(SkillAssetLoader::class);
            $loader->method('loadAll')->willReturn($assets);

            $prompts = $this->createMock(PromptTemplateService::class);
            $prompts->method('applyOverride')->willReturn($assets);

            $bridge = $this->createMock(RenderBridgeService::class);
            $bridge->method('hasRenderScript')->willReturn($hasRenderScript);
            $bridge->method('render')->willReturn(
                $renderSucceeds
                    ? new RenderResult(success: true, renderedPaths: ['html' => 'executions/report.html'])
                    : new RenderResult(success: false, errorMessage: 'render failed'),
            );

            $queue = $responses;
            $provider = $this->createMock(AiProvider::class);
            $provider->method('generate')->willReturnCallback(
                function ($prompt, $system, $options) use (&$queue) {
                    $this->calls[] = compact('prompt', 'system', 'options');

                    return array_shift($queue) ?? $this->ok();
                },
            );

            $runner = new SkillAgentRunnerService(
                $registry, $provider, $this->createMock(KnowledgeRetrieval::class),
                $loader, $bridge, $prompts, app(ModelUsageRecorder::class),
            );

            $executionId = SkillExecution::query()->create([
                    'project_id' => $project->id,
                    'user_id' => $user->id,
                    'skill_id' => $skillId,
                    'skill_name' => '输出闸用例',
                    'category' => SkillCategory::GeoPageTechnical->value,
                    'stage_index' => 3,
                    'status' => 'running',
                    'execution_mode' => 'async',
                    'input_data' => [],
                ])->id;

            return $runner->execute(new SkillBrief(
                $skillId, $project->id, $user->id, ['url' => 'https://example.com'], executionId: $executionId,
            ));
        } finally {
            TenantContext::forget();
        }
    }
}
