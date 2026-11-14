<?php

namespace Tests\Feature;

use App\Jobs\ExecuteSkillJob;
use App\Models\ModelCallLog;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\SkillExecutionRelay;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\ModelUsageRecorder;
use App\Services\Billing\ChunkRecordedDeliveryRecovery;
use App\Services\Billing\UnknownModelAttemptRecovery;
use App\Services\Content\PromptTemplateService;
use App\Services\Contracts\AiProvider;
use App\Services\Contracts\KnowledgeRetrieval;
use App\Services\Contracts\SkillAgentRunner;
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
use App\Services\SkillAgent\SkillChunkAssembly;
use App\Services\SkillAgent\SkillChunkRunner;
use App\Services\SkillAgent\SkillRunPreparer;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 接力执行：一段一个队列任务，首尾相接。
 *
 * 守的是什么——分段报告原本一个 PHP 进程从头跑到尾，撞死在 set_time_limit(420) 上
 * （2026-09-06 生产 skill_executions id=44：三段跑了 389 秒，第四段没跑完进程就被打死，
 * 白扣 ¥0.377）。一个进程按实测 16.4 秒/千词元只够产出约 25,600 词元，而一份完整报告
 * 要 35,000～40,000 词元 —— 单进程装不下，怎么调参数都不行。
 *
 * 所以本文件最核心的断言只有一条：**每个队列任务最多只发一次模型请求**。
 * 这一条塌了，接力就退化回原来那个必死的形状。
 */
class SkillRelayExecutionTest extends TestCase
{
    use RefreshDatabase;

    private const SKILL = 'yao-geo-intent-miner';

    private array $calls = [];

    private Tenant $tenant;

    private User $user;

    private Project $project;

    private SkillExecution $execution;

    public function test_each_queue_job_makes_at_most_one_model_call_and_the_run_completes(): void
    {
        $this->bootRelay($this->baseResponses());

        $callsPerStep = $this->drive();

        // 第 0 步只做准备（资产 / RAG / prompt），不该发模型请求。
        $this->assertSame(0, $callsPerStep[0], '第 0 步不该发模型请求');

        // 中间每一步恰好一段、恰好一次调用 —— 这就是「一个进程装不下」的解法。
        $chunkSteps = array_slice($callsPerStep, 1, -1);
        $this->assertSame(
            array_fill(0, $this->chunkCount(), 1),
            array_values($chunkSteps),
            '每个队列任务必须恰好跑一段、发一次模型请求',
        );

        // 最后一步是收尾（完整性闸 + 合并 + 渲染），也不发模型请求。
        $this->assertSame(0, end($callsPerStep), '收尾那一步不该发模型请求');

        // 总任务数 = 1 准备 + N 段 + 1 收尾
        $this->assertCount($this->chunkCount() + 2, $callsPerStep);

        $this->execution->refresh();
        $this->assertSame('completed', $this->execution->status);

        // 分头生成的段必须真的合成了一份完整报告：21 个顶层字段一个都不能少。
        $structured = $this->execution->output_data['structured'] ?? null;
        $this->assertIsArray($structured, '接力收尾必须产出 structured');
        foreach (array_keys($this->report()) as $field) {
            $this->assertArrayHasKey($field, $structured, "合并后缺字段：{$field}");
        }

        // 收尾后状态行必须删掉：它带着每段的模型原文，留着白占地方。
        $this->assertSame(0, SkillExecutionRelay::count(), '收尾后接力状态行应被删除');
    }

    public function test_successful_relay_preserves_a_bounded_callers_timeout(): void
    {
        $this->bootRelay($this->baseResponses());
        $original = (int) ini_get('max_execution_time');
        set_time_limit(37);
        try {
            $this->drive();
            $this->assertSame(37, (int) ini_get('max_execution_time'));
            $this->assertSame('completed', $this->execution->fresh()->status);
        } finally {
            set_time_limit($original);
        }
    }

    public function test_step_zero_seeds_state_without_calling_the_model(): void
    {
        $this->bootRelay($this->baseResponses());

        $this->runStep(0);

        $this->assertSame([], $this->calls, '准备阶段不该发模型请求');

        $relay = SkillExecutionRelay::where('skill_execution_id', $this->execution->id)->firstOrFail();
        $this->assertSame($this->chunkCount(), $relay->total_steps);
        $this->assertSame(0, $relay->completed_steps);
        $this->assertCount($this->chunkCount(), $relay->state['pending']);

        Queue::assertPushed(ExecuteSkillJob::class, fn (ExecuteSkillJob $job): bool => $job->step === 1);
    }

    public function test_every_step_touches_updated_at_so_the_reaper_does_not_kill_a_long_relay(): void
    {
        $this->bootRelay($this->baseResponses());
        $this->runStep(0);

        // 假装这条执行已经 30 分钟没动过——清道夫判死的第一个条件是「running 且
        // 超过 15 分钟没动静」，而接力的总时长本来就会超过 15 分钟。
        SkillExecution::where('id', $this->execution->id)->update(['updated_at' => now()->subMinutes(30)]);

        $this->runStep(1);

        $this->assertNull($this->execution->fresh()->billing_pending_result,
            '接力状态提交后须在同一事务清除已完成段的发送前意图');

        $this->assertTrue(
            $this->execution->refresh()->updated_at->greaterThan(now()->subMinutes(1)),
            '每跑完一段必须碰一下 updated_at，否则长接力会被清道夫当成卡死清掉',
        );
    }

    public function test_cancelling_midway_stops_dispatching_further_steps(): void
    {
        $this->bootRelay($this->baseResponses());
        $this->runStep(0);
        $this->runStep(1);

        $this->execution->update(['status' => 'cancelled']);
        $callsBefore = count($this->calls);

        $this->runStep(2);

        $this->assertSame($callsBefore, count($this->calls), '取消之后不该再发模型请求');
        Queue::assertNotPushed(ExecuteSkillJob::class, fn (ExecuteSkillJob $job): bool => $job->step === 3);
        $this->assertSame(0, SkillExecutionRelay::count(), '取消后接力状态行应被清掉');
    }

    public function test_losing_relay_state_fails_the_run_instead_of_shipping_a_partial_report(): void
    {
        $this->bootRelay($this->baseResponses());
        $this->runStep(0);

        // 状态行没了（被清理 / 被人删了）：绝不能默默出一份缺内容的报告。
        SkillExecutionRelay::query()->delete();

        $this->runStep(1);

        $this->execution->refresh();
        $this->assertSame('failed', $this->execution->status);
        $this->assertStringContainsString('接力状态已丢失', (string) $this->execution->error_message);
    }

    public function test_queue_failure_preserves_relay_state_when_a_chunk_attempt_was_prepared(): void
    {
        $this->bootRelay($this->baseResponses());
        $this->runStep(0);
        $this->execution->update(['billing_pending_result' => [
            'version' => 1, 'kind' => 'model_attempt_prepared',
            'gateway' => ['attribution' => ['client_request_id' => 'original-chunk']],
        ]]);

        (new ExecuteSkillJob($this->execution->id, $this->brief()->toArray(), $this->tenant->id, 1))
            ->failed(new \RuntimeException('worker killed'));

        $this->assertSame('billing_pending', $this->execution->fresh()->status);
        $this->assertSame(1, SkillExecutionRelay::where('skill_execution_id', $this->execution->id)->count());
        $this->assertSame(0, SkillExecutionRelay::where('skill_execution_id', $this->execution->id)
            ->firstOrFail()->completed_steps);
    }

    public function test_recorded_original_chunk_merges_into_relay_and_queues_next_step_without_model_call(): void
    {
        $this->bootRelay($this->baseResponses());
        $this->runStep(0);
        config(['queue.default' => 'database']);
        $relay = SkillExecutionRelay::where('skill_execution_id', $this->execution->id)->firstOrFail();
        $task = $relay->state['pending'][0];
        $key = $task['parent']['key'];
        $response = $this->baseResponses()[0];
        $runner = app(SkillAgentRunner::class);
        $preparer = (new \ReflectionProperty($runner, 'runPreparer'))->getValue($runner);
        $chunkRunner = (new \ReflectionProperty($runner, 'chunkRunner'))->getValue($runner);
        $assembly = (new \ReflectionProperty($runner, 'chunkAssembly'))->getValue($runner);
        $this->assertInstanceOf(SkillRunPreparer::class, $preparer);
        $this->assertInstanceOf(SkillChunkRunner::class, $chunkRunner);
        $this->assertInstanceOf(SkillChunkAssembly::class, $assembly);
        $this->app->instance(ChunkRecordedDeliveryRecovery::class,
            new ChunkRecordedDeliveryRecovery($preparer, $chunkRunner, $assembly));

        TenantContext::set($this->tenant);
        try {
            $fingerprint = $preparer->deliveryInputs($this->brief())['fingerprint'];
            $log = ModelCallLog::create([
                'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
                'user_id' => $this->user->id, 'skill_execution_id' => $this->execution->id,
                'provider' => 'gateway', 'model' => 'text', 'status' => 'completed',
                'gateway_request_id' => 'recovered-chunk-gateway',
                'prompt_tokens' => $response->promptTokens,
                'completion_tokens' => $response->completionTokens,
                'total_tokens' => $response->totalTokens,
            ]);
            $this->execution->update([
                'status' => 'billing_pending',
                'billing_pending_result' => [
                    'version' => 1, 'kind' => 'model_result_recorded',
                    'model_call_log_id' => $log->id,
                    'gateway' => ['request_id' => 'recovered-chunk-gateway'],
                    'brief_data' => $this->brief()->toArray(),
                    'delivery_context' => [
                        'path' => 'chunk', 'attempt' => 1,
                        'step_id' => 'model:'.$key.':attempt:1',
                        'relay_completed_steps' => 0,
                        'relay_task_fingerprint' => hash('sha256', json_encode($task, JSON_THROW_ON_ERROR)),
                        'asset_fingerprint' => $fingerprint,
                        'wallet_required' => false,
                    ],
                    'model_result' => [
                        'content' => $response->content, 'model' => $response->model,
                        'finish_reason' => $response->finishReason,
                        'prompt_tokens' => $response->promptTokens,
                        'completion_tokens' => $response->completionTokens,
                        'total_tokens' => $response->totalTokens,
                    ],
                ],
            ]);
        } finally {
            TenantContext::forget();
        }

        $callsBefore = count($this->calls);
        $this->assertSame('usage_recorded_delivery_pending',
            app(UnknownModelAttemptRecovery::class)->recover($this->execution->id)['status']);
        $snapshot = $this->execution->fresh()->billing_pending_result;
        $this->execution->update(['billing_pending_result' => array_replace($snapshot, [
            'delivery_context' => array_replace($snapshot['delivery_context'], [
                'relay_task_fingerprint' => str_repeat('0', 64),
            ]),
        ])]);
        $this->assertSame('delivery_context_unavailable',
            app(UnknownModelAttemptRecovery::class)->recover($this->execution->id, true)['status']);
        $this->assertSame(0, $relay->fresh()->completed_steps);
        $this->execution->update(['billing_pending_result' => $snapshot]);
        config(['queue.default' => 'sync']);
        $this->assertSame('queue_unavailable',
            app(UnknownModelAttemptRecovery::class)->recover($this->execution->id, true)['status']);
        config(['queue.default' => 'database']);
        config(['queue.connections.database.after_commit' => true]);
        $this->assertSame('queue_unavailable',
            app(UnknownModelAttemptRecovery::class)->recover($this->execution->id, true)['status']);
        config(['queue.connections.database.after_commit' => false]);
        $this->assertSame('chunk_relay_resumed',
            app(UnknownModelAttemptRecovery::class)->recover($this->execution->id, true)['status']);
        $this->assertSame($callsBefore, count($this->calls));
        $this->assertSame('running', $this->execution->fresh()->status);
        $this->assertNull($this->execution->fresh()->billing_pending_result);
        $this->assertSame(1, $relay->fresh()->completed_steps);
        Queue::assertPushed(ExecuteSkillJob::class,
            fn (ExecuteSkillJob $job): bool => $job->executionId === $this->execution->id && $job->step === 2);

        // The recovered first chunk occupies the original first call slot.
        // Continue only the remaining steps; no provider call may repeat it.
        $this->calls[] = ['recovered_original_chunk' => true];
        for ($step = 2; $step <= $this->chunkCount() + 1; $step++) {
            $this->runStep($step);
        }
        $this->assertSame('completed', $this->execution->fresh()->status);
        $this->assertNotNull($this->execution->fresh()->article_id);
        $this->assertCount($this->chunkCount(), $this->calls);
    }

    public function test_a_skill_without_chunk_specs_still_runs_in_a_single_job(): void
    {
        // 分段开关关掉＝没有分段谱＝不接力，必须原样走单进程老路。
        $this->bootRelay([$this->response($this->report())], chunked: false);

        $callsPerStep = $this->drive();

        $this->assertCount(1, $callsPerStep, '不分段的技能应该一个任务跑完，不该派发第二步');
        $this->assertSame(1, $callsPerStep[0]);
        $this->assertSame('completed', $this->execution->refresh()->status);
        $this->assertSame(0, SkillExecutionRelay::count(), '不接力就不该建状态行');
    }

    // ─────────────────────────────────────────────────────────────────────
    // 脚手架
    // ─────────────────────────────────────────────────────────────────────

    /**
     * 一直推进接力，直到没有下一步被派发为止。
     *
     * @return list<int> 每一步各发了几次模型请求
     */
    private function drive(int $guard = 30): array
    {
        $callsPerStep = [];

        for ($step = 0; $step <= $guard; $step++) {
            $before = count($this->calls);
            $this->runStep($step);
            $callsPerStep[] = count($this->calls) - $before;

            $next = $step + 1;
            $dispatched = Queue::pushed(
                ExecuteSkillJob::class,
                fn (ExecuteSkillJob $job): bool => $job->step === $next,
            )->isNotEmpty();

            if (! $dispatched) {
                return $callsPerStep;
            }
        }

        $this->fail("接力步数超过 {$guard} 步仍未收尾，八成是自己派发自己停不下来了");
    }

    private function runStep(int $step): void
    {
        $job = new ExecuteSkillJob($this->execution->id, $this->brief()->toArray(), $this->tenant->id, $step);
        $previousLimit = (int) ini_get('max_execution_time');
        $this->app->call([$job, 'handle']);
        $this->assertSame($previousLimit, (int) ini_get('max_execution_time'), "Step {$step} leaked its PHP timer into the next job.");
    }

    private function brief(): SkillBrief
    {
        return new SkillBrief(
            self::SKILL, $this->project->id, $this->user->id,
            ['brand_name' => 'Acme', 'model' => 'text'],
            executionId: $this->execution->id,
        );
    }

    /**
     * 装好租户 / 项目 / 执行记录，并把真正的 SkillAgentRunnerService（带替身依赖）
     * 绑进容器——接力只认这个具体类，绑别的实现会被 instanceof 判掉、回落到老路。
     *
     * @param  list<AiProviderCallResult>  $responses  按调用顺序返回
     * @param  bool  $chunked  分段开关。false＝没有分段谱＝不接力，用来守护单进程老路。
     */
    private function bootRelay(array $responses, bool $chunked = true): void
    {
        Http::preventStrayRequests();
        Queue::fake();

        // 必须显式设。config/geo.php 里这个开关默认是关的（跟着 env 走），
        // 不设的话整个文件都会静悄悄地走单进程老路，测试全绿却什么都没守住。
        config(['geo.analysis_chunked' => $chunked]);

        $this->tenant = Tenant::create([
            'subdomain' => 'relay', 'name' => 'Relay', 'plan' => 'basic', 'status' => 'active',
        ]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'admin']);
        TenantContext::set($this->tenant);
        $this->project = Project::create([
            'owner_id' => $this->user->id, 'name' => 'Acme', 'brand_name' => 'Acme',
        ]);
        $this->execution = SkillExecution::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'user_id' => $this->user->id,
            'skill_id' => self::SKILL, 'skill_name' => '意图挖掘', 'category' => SkillCategory::GeoResearch->value,
            'stage_index' => 0, 'status' => 'pending', 'execution_mode' => 'async', 'input_data' => [],
        ]);
        TenantContext::forget();

        $assets = new SkillAssets(
            self::SKILL, '# 方法论', [], '/skills/render.py',
            json_encode($this->report(), JSON_UNESCAPED_UNICODE), true,
        );

        $registry = $this->createMock(SkillRegistry::class);
        $registry->method('find')->willReturn(new SkillDefinition(
            id: self::SKILL, name: '意图挖掘', category: SkillCategory::GeoResearch,
            maturity: 'stable', requiresWeb: false, stageIndex: 0, description: '',
        ));

        $assetLoader = $this->createMock(SkillAssetLoader::class);
        $assetLoader->method('loadAll')->willReturn($assets);

        $prompts = $this->createMock(PromptTemplateService::class);
        $prompts->method('applyOverride')->willReturn($assets);

        $renderBridge = $this->createMock(RenderBridgeService::class);
        $renderBridge->method('hasRenderScript')->willReturn(true);
        $renderBridge->method('render')->willReturn(
            new RenderResult(success: true, renderedPaths: ['html' => 'executions/report.html']),
        );

        $provider = $this->createMock(AiProvider::class);
        $provider->method('generate')->willReturnCallback(
            function ($prompt, $system, $options) use ($responses): AiProviderCallResult {
                $index = count($this->calls);
                $this->calls[] = compact('prompt', 'system', 'options');

                if (! isset($responses[$index])) {
                    $this->fail('模型被多调了一次：第 '.($index + 1).' 次调用没有对应的预置返回');
                }

                return $responses[$index];
            },
        );

        $this->app->instance(SkillAgentRunner::class, new SkillAgentRunnerService(
            $registry, $provider, $this->createMock(KnowledgeRetrieval::class),
            $assetLoader, $renderBridge, $prompts, app(ModelUsageRecorder::class),
        ));
    }

    private function specs(): array
    {
        $runner = app(SkillAgentRunnerService::class);

        return (new ReflectionMethod($runner, 'chunkSpecsFor'))->invoke($runner, self::SKILL, true);
    }

    private function chunkCount(): int
    {
        return count($this->specs());
    }

    /** @return list<AiProviderCallResult> 每段一个完整回答 */
    private function baseResponses(): array
    {
        return array_map(
            fn (array $spec): AiProviderCallResult => $this->response(
                array_intersect_key($this->report(), array_flip($spec['fields'])),
            ),
            $this->specs(),
        );
    }

    private function report(): array
    {
        $report = [
            'slug' => 'acme', 'title' => 'Acme 意图挖掘', 'subtitle' => '', 'brand_name' => 'Acme',
            'analysis_date' => '2026-09-06', 'prepared_by' => '分析团队', 'limitations' => '无实测数据',
            'summary_cards' => [['label' => '问题', 'value' => 18], ['label' => '范围', 'value' => '研究']],
            'sections' => array_map(fn ($title) => [
                'title' => $title,
                'paragraphs' => ['基于用户输入的分析。'],
            ], [
                '执行摘要与关键发现', '研究依据与方法', '事实校准与验证', '真实数据接入状态',
                '输入归一化与对象边界', '用户角色与场景矩阵', '双层意图地图', '问题簇与优先级',
                '五段式查询重写', '国内AI平台适配', '内容资产与FAQ映射', '监测Prompt与采样计划',
                '证据缺口与数据校准', '合规与禁止回答边界', '30/60/90天落地路线',
            ]),
            'question_bank' => array_map(fn ($i) => [
                'question_id' => 'Q'.$i, 'question' => '问题'.$i, 'cluster' => '购买', 'intent_type' => '比较',
                'standalone_rewrite' => '独立问法', 'retrieval_rewrite' => '检索问法', 'evidence_query' => '证据',
                'content_asset' => 'FAQ', 'priority' => 'P1', 'compliance_level' => 'L1-合规',
            ], range(1, 18)),
            'data_sources' => [], 'evidence_sources' => [],
        ];

        foreach ([
            'follow_up_chains', 'prompt_library', 'scoring_matrix', 'ai_sampling_plan',
            'calibration_actions', 'content_topics', 'faq_bank', 'knowledge_base_entries', 'action_roadmap',
        ] as $field) {
            $report[$field] = [['question_id' => 'Q5', 'description' => '基于问题5的分析内容']];
        }
        $report['follow_up_chains'] = array_map(fn ($i) => [
            'chain_id' => 'C'.$i,
            'root_question_id' => 'Q'.$i,
            'follow_up_question' => '问题'.$i.'需要什么证据？',
        ], range(1, 8));
        $report['prompt_library'] = [['prompt_id' => 'P1', 'prompt' => '请回答问题5并说明证据。']];

        return $report;
    }

    private function response(array|string $data, string $finish = 'stop', int $tokens = 100): AiProviderCallResult
    {
        return new AiProviderCallResult(
            success: true, provider: 'gateway', model: 'text',
            content: is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : $data,
            promptTokens: 100, completionTokens: max(0, $tokens - 100), totalTokens: $tokens, finishReason: $finish,
        );
    }
}
