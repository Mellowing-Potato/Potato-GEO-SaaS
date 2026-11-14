<?php

namespace Tests\Feature;

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
use App\Services\SkillAgent\PageFetchService;
use App\Services\SkillAgent\PageObservation;
use App\Services\SkillAgent\RenderBridgeService;
use App\Services\SkillAgent\SkillAgentRunnerService;
use App\Services\SkillAgent\SkillAssetLoader;
use App\Services\SkillAgent\SkillAssets;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 页面审计接上抓取之后，执行器该怎么表现。
 *
 * 三条底线，来源都是生产 execution 52（客户填了能打开的 example.test，
 * 报告却说「官网缺失」，并且照扣了 ¥0.13）：
 *   1. 抓不到 → 判失败、一次模型都不调、零扣费。绝不退回让模型凭印象编。
 *   2. 抓到了 → 事实必须进提示词，且排在「前置阶段输出」前面并压过它。
 *   3. 客户当场填的地址要记回项目，别让下一个技能继续拿空字段推出「官网缺失」。
 */
class PageAuditRunnerTest extends TestCase
{
    use RefreshDatabase;

    private const SKILL_ID = 'yao-geo-page-audit';

    /** 每次 generate() 的入参，按顺序 */
    private array $calls = [];

    private Project $project;

    private User $user;

    /**
     * 抓不到就到此为止：不调模型，客户零扣费。
     *
     * 这是老板的第一条规矩「不能因为我们的功能问题让客户叠加扣费」在这条路上的落点 ——
     * 抓取失败是我们没拿到原料，不该让客户为此付钱。
     */
    public function test_a_failed_fetch_aborts_before_any_model_call(): void
    {
        $result = $this->runPageAudit(
            observation: PageObservation::failure(
                'https://example.com/gone', 'http_error', '目标页面返回 404，页面不存在或已经下线，请确认地址是否正确。'
            ),
        );

        $this->assertFalse($result->success);
        $this->assertCount(0, $this->calls, '抓不到页面还去调模型，就是让客户为我们拿不到原料买单');
        $this->assertSame(0, $result->llmCallCount);
        $this->assertSame(0, $result->tokenUsage);

        // 错误文案要两句都在：先说为什么没有报告，再说是什么挡住了
        $this->assertStringContainsString('需要真实抓取', (string) $result->errorMessage);
        $this->assertStringContainsString('404', (string) $result->errorMessage);
        $this->assertTrue($result->intermediateData['page_fetch_failed'] ?? false);
    }

    /**
     * 🔴 抓不到绝不允许退回「让模型凭印象编一份」。
     * 这是第一层刚修掉的那类假成功，而且更坏 —— 报告读起来像真查过。
     */
    public function test_a_failed_fetch_never_falls_back_to_letting_the_model_invent(): void
    {
        $result = $this->runPageAudit(
            observation: PageObservation::failure('https://example.com', 'timeout', '目标站点 15 秒内没有响应。'),
            responses: [$this->ok('# 一份编出来的报告')],
        );

        $this->assertFalse($result->success, '抓取失败却出了报告 —— 这份报告的内容没有任何依据');
        $this->assertSame([], $this->calls);
        $this->assertArrayNotHasKey('raw_output', $result->intermediateData);
    }

    /**
     * 抓到了：事实要进提示词，而且必须排在「前置阶段输出」**前面**。
     *
     * execution 52 栽的就是顺序 —— 模型看到前置阶段那句「官网缺失」，就照抄了，
     * 哪怕客户当场填的地址是活的。实测事实必须排在传闻前面，并明确说冲突时听谁的。
     */
    public function test_observed_facts_outrank_what_an_earlier_stage_claimed(): void
    {
        $result = $this->runPageAudit(
            observation: $this->observed(),
            previousStage: ['panorama' => '官网缺失，品牌无事实源'],
        );

        $this->assertTrue($result->success);
        $prompt = $this->calls[0]['prompt'];

        $observationAt = mb_strpos($prompt, '## 页面实测观察');
        $previousAt = mb_strpos($prompt, '## 前置阶段输出');
        $this->assertNotFalse($observationAt, '抓到的事实根本没进提示词');
        $this->assertNotFalse($previousAt);
        $this->assertLessThan($previousAt, $observationAt, '实测事实必须排在前置结论前面');

        // 光有顺序不够，得把话说明白
        $this->assertStringContainsString('以本段为准', $prompt);
        $this->assertStringContainsString('二手信息', $prompt);

        // 事实本身要在里面，模型才有得可依
        $this->assertStringContainsString('孟德智达 — AI 搜索优化', $prompt);
        $this->assertStringContainsString('"h1_count": 1', $prompt);
    }

    /** 「观察到的」结论必须可追溯，这条纪律要写进提示词 */
    public function test_the_prompt_forbids_concluding_beyond_the_observation_list(): void
    {
        $this->runPageAudit(observation: $this->observed());
        $prompt = $this->calls[0]['prompt'];

        $this->assertStringContainsString('事实纪律', $prompt);
        $this->assertStringContainsString('未获取', $prompt);
        $this->assertStringContainsString('不得推测', $prompt);
    }

    /**
     * 客户在技能表单里填的地址，抓通了就记回项目。
     * 否则下一个技能还是拿着空的 brand_url，继续推出「官网缺失」。
     */
    public function test_a_working_url_is_remembered_on_the_project(): void
    {
        $project = $this->runAndReturnProject(brandUrl: '');

        $this->assertSame('https://example.test/', $project->fresh()->brand_url);
    }

    /** 客户自己填过的值不能被单次技能输入覆盖 */
    public function test_an_existing_brand_url_is_not_overwritten(): void
    {
        $project = $this->runAndReturnProject(brandUrl: 'https://old.example.com');

        $this->assertSame('https://old.example.com', $project->fresh()->brand_url);
    }

    /**
     * 一次执行只抓一遍客户的站点。
     *
     * 来源是生产 execution 53 的日志：同一次执行出现两条一模一样的抓取记录。
     * 根因是 ExecuteSkillJob 对每个技能都先调 beginRelay 探「要不要分段」，
     * 探路那一趟也走 prepareRun、也抓了一次；页面审计不分段，于是又走 execute 抓第二次。
     * 抓两遍不会算错账，但会让客户的站点白挨一次请求、执行也白等一次往返。
     */
    public function test_one_execution_fetches_the_customer_site_only_once(): void
    {
        $fetch = $this->createMock(PageFetchService::class);
        // 探路那趟不该抓 —— 只允许一次。
        $fetch->expects($this->once())->method('fetch')->willReturn($this->observed());

        $runner = $this->buildRunner(self::SKILL_ID, $fetch, [$this->ok()]);
        // beginRelay must see a persisted execution, as the queue path does.
        $execution = SkillExecution::query()->create([
            'project_id' => $this->project->id, 'user_id' => $this->user->id,
            'skill_id' => self::SKILL_ID, 'skill_name' => '页面审计',
            'category' => SkillCategory::GeoPageTechnical->value,
            'stage_index' => 3, 'status' => 'running',
            'execution_mode' => 'async', 'input_data' => [],
        ]);
        $brief = $this->briefFor(self::SKILL_ID, ['url' => 'http://example.test'], executionId: $execution->id);

        // 复刻 ExecuteSkillJob 的顺序：先 beginRelay 探路，返回 null 再走 execute。
        try {
            $this->assertNull($runner->beginRelay($brief), '页面审计不分段，探路应当返回 null');
            $this->assertTrue($runner->execute($brief)->success);
        } finally {
            TenantContext::forget();
        }
    }

    /** 没登记要抓页面的技能不该被牵连 —— 它们连一次请求都不该发 */
    public function test_skills_that_do_not_need_a_page_are_untouched(): void
    {
        $fetch = $this->createMock(PageFetchService::class);
        $fetch->expects($this->never())->method('fetch');

        $result = $this->runSkill('yao-geo-intent-miner', $fetch, [$this->ok()], []);

        $this->assertTrue($result->success);
    }

    /**
     * 逃生阀关掉时不抓 —— 但要清楚这等于退回旧行为，所以只在这里钉住「开关有效」，
     * 不为旧行为背书。
     */
    public function test_the_kill_switch_disables_fetching(): void
    {
        config(['geo.page_fetch_enabled' => false]);

        $fetch = $this->createMock(PageFetchService::class);
        $fetch->expects($this->never())->method('fetch');

        $result = $this->runSkill(self::SKILL_ID, $fetch, [$this->ok()], ['url' => 'https://example.test']);

        $this->assertTrue($result->success);
    }

    // ── 装配 ─────────────────────────────────────────────────

    private function observed(): PageObservation
    {
        return PageObservation::success('http://example.test', 'https://example.test/', 200, [
            'head' => ['title' => '孟德智达 — AI 搜索优化', 'canonical' => 'https://example.test/'],
            'heading_outline' => ['h1_count' => 1, 'level_skips' => []],
            'structured_data' => ['json_ld_types' => ['Organization']],
        ]);
    }

    private function runAndReturnProject(string $brandUrl): Project
    {
        $fetch = $this->createMock(PageFetchService::class);
        $fetch->method('fetch')->willReturn($this->observed());

        return $this->runSkill(
            self::SKILL_ID, $fetch, [$this->ok()], ['url' => 'http://example.test'],
            brandUrl: $brandUrl, returnProject: true,
        );
    }

    /** @param list<AiProviderCallResult> $responses */
    private function runPageAudit(
        PageObservation $observation,
        array $responses = [],
        array $previousStage = [],
    ): SkillExecutionResult {
        $fetch = $this->createMock(PageFetchService::class);
        $fetch->method('fetch')->willReturn($observation);

        return $this->runSkill(
            self::SKILL_ID, $fetch,
            $responses === [] ? [$this->ok()] : $responses,
            ['url' => 'http://example.test'],
            previousStage: $previousStage,
        );
    }

    /** @param list<AiProviderCallResult> $responses */
    private function runSkill(
        string $skillId,
        PageFetchService $fetch,
        array $responses,
        array $fields,
        array $previousStage = [],
        string $brandUrl = '',
        bool $returnProject = false,
    ): SkillExecutionResult|Project {
        $runner = $this->buildRunner($skillId, $fetch, $responses, $brandUrl);
        $execution = SkillExecution::query()->create([
            'project_id' => $this->project->id, 'user_id' => $this->user->id,
            'skill_id' => $skillId, 'skill_name' => '页面审计',
            'category' => SkillCategory::GeoPageTechnical->value,
            'stage_index' => 3, 'status' => 'running',
            'execution_mode' => 'sync', 'input_data' => [],
        ]);
        $brief = $this->briefFor($skillId, $fields, $previousStage, $execution->id);

        try {
            $result = $runner->execute($brief);

            return $returnProject ? Project::findOrFail($brief->projectId) : $result;
        } finally {
            TenantContext::forget();
        }
    }

    /**
     * 建一套带替身的执行器，并把租户上下文就位。
     *
     * ⚠️ 调用方负责在用完后 TenantContext::forget() —— 单抓一次那个用例要在
     *    同一个上下文里连着调 beginRelay 和 execute，不能由本方法自己收尾。
     *
     * @param  list<AiProviderCallResult>  $responses
     */
    private function buildRunner(
        string $skillId,
        PageFetchService $fetch,
        array $responses,
        string $brandUrl = '',
    ): SkillAgentRunnerService {
        Http::preventStrayRequests();
        config([ 'geo.analysis_chunked' => false]);

        $tenant = Tenant::create([
            'subdomain' => 'page-audit-'.Tenant::count(),
            'name' => 'PageAudit', 'plan' => 'basic', 'status' => 'active',
        ]);
        $this->user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        TenantContext::set($tenant);

        $this->project = Project::create([
            'owner_id' => $this->user->id, 'name' => '孟德智达',
            'brand_name' => '孟德智达', 'brand_url' => $brandUrl,
        ]);

        $assets = new SkillAssets($skillId, '# 页面审计方法论', [], null, null, true);

        $registry = $this->createMock(SkillRegistry::class);
        $registry->method('find')->willReturn(new SkillDefinition(
            id: $skillId, name: '页面审计', category: SkillCategory::GeoPageTechnical,
            maturity: 'beta', requiresWeb: true, stageIndex: 3, description: '',
        ));

        $loader = $this->createMock(SkillAssetLoader::class);
        $loader->method('loadAll')->willReturn($assets);

        $prompts = $this->createMock(PromptTemplateService::class);
        $prompts->method('applyOverride')->willReturn($assets);

        $bridge = $this->createMock(RenderBridgeService::class);
        $bridge->method('hasRenderScript')->willReturn(false);

        $queue = $responses;
        $provider = $this->createMock(AiProvider::class);
        $provider->method('generate')->willReturnCallback(
            function ($prompt, $system, $options) use (&$queue) {
                $this->calls[] = compact('prompt', 'system', 'options');

                return array_shift($queue) ?? $this->ok();
            },
        );

        return new SkillAgentRunnerService(
            $registry, $provider, $this->createMock(KnowledgeRetrieval::class),
            $loader, $bridge, $prompts, app(ModelUsageRecorder::class),
            null, null, $fetch,
        );
    }

    private function briefFor(
        string $skillId,
        array $fields,
        array $previousStage = [],
        ?int $executionId = null,
    ): SkillBrief {
        return new SkillBrief(
            $skillId, $this->project->id, $this->user->id, $fields,
            contextFromPreviousStage: $previousStage,
            executionId: $executionId,
        );
    }

    private function ok(?string $content = null): AiProviderCallResult
    {
        return new AiProviderCallResult(
            success: true, provider: 'gateway', model: 'text',
            content: $content ?? str_repeat('这是一段正常长度的页面审计正文。', 10),
            promptTokens: 50, completionTokens: 50, totalTokens: 100,
            durationSeconds: 1.0, finishReason: 'stop',
        );
    }
}
