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
use App\Services\SkillAgent\RenderBridgeService;
use App\Services\SkillAgent\RenderResult;
use App\Services\SkillAgent\SkillAgentRunnerService;
use App\Services\SkillAgent\SkillAssetLoader;
use App\Services\SkillAgent\SkillAssets;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 意图挖掘之外的分段技能，坏了一段能不能自救。
 *
 * 背景：补跑机制 2026-09-06 上线时只对意图挖掘生效，另外三个分段技能（综合诊断／
 * 执行路线图／解释型文章）一段没交就整单判失败，客户得重跑全部段落、再付一次钱。
 * 同日老板拍板铺开。这个文件守的是「铺开之后确实能救回来」。
 *
 * 用执行路线图当代表，因为它最能暴露切法问题：第一段有 8 个字段，
 * 照意图挖掘那样逐字段补要 8 次调用，超过默认预算 6 —— 会触发「预算不够就整批放弃」，
 * 一次都不补，机制等于对它不存在。所以它的补跑单位是「整段」不是「字段」。
 */
class RoadmapChunkRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const SKILL = 'yao-geo-execution-roadmap';

    /** 默认预算。故意小于第一段的字段数（8），逐字段切法在这个预算下必然一次都补不成。 */
    private const BUDGET = 6;

    private array $calls = [];

    private SkillExecution $execution;

    public function test_baseline_runs_one_call_per_chunk(): void
    {
        $result = $this->runRoadmap($this->baseResponses());

        $this->assertTrue($result->success);
        $this->assertSame($this->chunkCount(), $result->llmCallCount);
        foreach ($this->calls as $call) {
            $this->assertTrue($call['options']['json_object'], '分段调用必须强制 JSON 语法');
        }
    }

    /**
     * 模型「写完了」(finish_reason=stop) 但回复不是合法 JSON —— 整段重来一次。
     *
     * 这就是生产 execution 46 上意图挖掘坏掉的形态。换成执行路线图，旧代码连补都不补。
     */
    public function test_unparseable_chunk_is_retried_as_a_single_whole_chunk_call(): void
    {
        $responses = $this->baseResponses();
        $responses[0] = $this->response($this->notJson('第一段'));
        array_splice($responses, 1, 0, [$this->response($this->chunkPayload(0))]);

        $result = $this->runRoadmap($responses);

        $this->assertTrue($result->success);
        // 关键断言：8 个字段的一段只花 1 次补跑，不是 8 次。
        // 谁把切法改回逐字段，这里会变成 3（预算 6 装不下 8 个补跑任务，整批放弃）。
        $this->assertSame($this->chunkCount() + 1, $result->llmCallCount);

        $meta = $result->intermediateData['chunk_meta'][$this->specs()[0]['key']];
        $this->assertTrue($meta['attempts'][0]['ok'], '首次调用本身是成功的，坏的是内容不是链路');
        $this->assertTrue($meta['complete']);
        $this->assertSame([], $meta['missing_fields']);

        // 第二次调用必须是「补跑」，不是「换模型重试」。光看调用次数分不出这两者：
        // 坏回复只要短于 40 字符就会走升级分支，次数一样、fields 也一样，断言会凑巧对上。
        $this->assertTrue($meta['attempts'][1]['recovery'], '第二次调用应当是补跑，不是模型升级重试');

        // 重来的这一次要把整段字段都要回来，不是只要其中一个。
        $this->assertSame($this->specs()[0]['fields'], $meta['attempts'][1]['fields']);
        foreach ($this->specs()[0]['fields'] as $field) {
            $this->assertStringContainsString($field, $this->calls[1]['prompt']);
        }
    }

    /** 一次补一段，预算才够两段都坏的情况。逐字段切法在这里连第一段都补不动。 */
    public function test_two_broken_chunks_both_recover_inside_the_budget(): void
    {
        $responses = $this->baseResponses();
        $responses[0] = $this->response($this->notJson('第一段'));
        $responses[1] = $this->response($this->notJson('第二段'));
        array_splice($responses, 1, 0, [$this->response($this->chunkPayload(0))]);
        array_splice($responses, 3, 0, [$this->response($this->chunkPayload(1))]);

        $result = $this->runRoadmap($responses);

        $this->assertTrue($result->success);
        $this->assertSame($this->chunkCount() + 2, $result->llmCallCount);
        $this->assertSame($this->chunkCount(), $result->intermediateData['chunks_parsed']);
        foreach ([0, 1] as $i) {
            $this->assertTrue(
                $result->intermediateData['chunk_meta'][$this->specs()[$i]['key']]['attempts'][1]['recovery'],
                '两段都该走补跑，不是模型升级重试',
            );
        }
    }

    /** 预算是这条路唯一的闸：关掉就退回旧行为，坏 JSON 不重放、整单失败。 */
    public function test_no_repair_when_budget_is_off(): void
    {
        $responses = $this->baseResponses();
        $responses[0] = $this->response($this->notJson('第一段'));

        $result = $this->runRoadmap($responses, render: false, budget: 0);

        $this->assertFalse($result->success);
        $this->assertSame($this->chunkCount(), $result->llmCallCount);
    }

    /**
     * 被截断是另一回事：整段重来只会照样撞上限，拆小才是解药。所以截断一律按字段拆，
     * 跟意图挖掘同一套动作。这里打在第二段（3 个字段），拆完 3 次仍在预算内。
     *
     * ⚠️ 已知边界：第一段有 8 个字段，真被截断时要 8 次补跑，超过预算 6 会整批放弃、
     * 记 recovery_skipped=call_budget_exhausted。生产至今没出现过分段被截断（上限 16384，
     * 段是照这个上限切的）。真出现了要改的是分段谱切得更细，不是继续加预算。
     */
    public function test_truncated_chunk_is_split_field_by_field(): void
    {
        $responses = $this->baseResponses();
        $responses[1] = $this->response($this->chunkPayload(1), finish: 'length');
        $repairs = array_map(
            fn (string $field): AiProviderCallResult => $this->response([$field => $this->report()[$field]]),
            $this->specs()[1]['fields'],
        );
        array_splice($responses, 2, 0, $repairs);

        $result = $this->runRoadmap($responses);

        $this->assertTrue($result->success);
        $this->assertSame($this->chunkCount() + count($this->specs()[1]['fields']), $result->llmCallCount);
        foreach ($this->specs()[1]['fields'] as $i => $field) {
            $this->assertSame([$field], $result->intermediateData['chunk_meta'][$this->specs()[1]['key']]['attempts'][$i + 1]['fields']);
        }
    }

    private function runRoadmap(array $responses, bool $render = true, int $budget = self::BUDGET): SkillExecutionResult
    {
        Http::preventStrayRequests();
        config([ 'geo.chunk_recovery_max_calls' => $budget]);

        $tenant = Tenant::create(['subdomain' => 'roadmap-recovery', 'name' => 'Recovery', 'plan' => 'basic', 'status' => 'active']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        TenantContext::set($tenant);

        try {
            $project = Project::create(['owner_id' => $user->id, 'name' => 'Acme', 'brand_name' => 'Acme']);
            $this->execution = SkillExecution::create([
                'tenant_id' => $tenant->id, 'project_id' => $project->id, 'user_id' => $user->id,
                'skill_id' => self::SKILL, 'skill_name' => '执行路线图', 'category' => SkillCategory::GeoStrategy->value,
                'stage_index' => 1, 'status' => 'running', 'execution_mode' => 'async', 'input_data' => [],
            ]);

            $provider = $this->createMock(AiProvider::class);
            $provider->expects($this->exactly(count($responses)))->method('generate')->willReturnCallback(
                function ($prompt, $system, $options) use ($responses) {
                    $index = count($this->calls);
                    $this->calls[] = compact('prompt', 'system', 'options');

                    return $responses[$index];
                },
            );

            $bridge = $this->createMock(RenderBridgeService::class);
            $bridge->expects($render ? $this->once() : $this->never())->method('render')
                ->willReturn(new RenderResult(success: true, renderedPaths: ['html' => 'executions/report.html']));

            $runner = new SkillAgentRunnerService(
                $this->createMock(SkillRegistry::class), $provider, $this->createMock(KnowledgeRetrieval::class),
                $this->createMock(SkillAssetLoader::class), $bridge, $this->createMock(PromptTemplateService::class), app(ModelUsageRecorder::class),
            );

            return (new ReflectionMethod($runner, 'executeChunked'))->invoke(
                $runner,
                new SkillDefinition(id: self::SKILL, name: '执行路线图', category: SkillCategory::GeoStrategy, maturity: 'beta', requiresWeb: false, stageIndex: 1, description: ''),
                new SkillAssets(self::SKILL, '# 方法论', [], '/skills/render.py', json_encode($this->report(), JSON_UNESCAPED_UNICODE), true),
                new SkillBrief(self::SKILL, $project->id, $user->id, ['brand_name' => 'Acme', 'model' => 'text'], executionId: $this->execution->id),
                '请输出完整路线图', '', microtime(true), (string) $tenant->id, $this->specs(),
            );
        } finally {
            TenantContext::forget();
        }
    }

    /** 分段谱从代码推导，不写死——段数或字段改了，用例要跟着动而不是悄悄失真。 */
    private function specs(): array
    {
        return (new ReflectionMethod(app(SkillAgentRunnerService::class), 'chunkSpecsFor'))
            ->invoke(app(SkillAgentRunnerService::class), self::SKILL, true);
    }

    private function chunkCount(): int
    {
        return count($this->specs());
    }

    /** 全成功基线：每段一次调用，内容就是这段该交的字段。 */
    private function baseResponses(): array
    {
        return array_map(
            fn (int $i): AiProviderCallResult => $this->response($this->chunkPayload($i)),
            range(0, $this->chunkCount() - 1),
        );
    }

    private function chunkPayload(int $index): array
    {
        return array_intersect_key($this->report(), array_flip($this->specs()[$index]['fields']));
    }

    /**
     * 一段「写完了但不是 JSON」的回复。必须够长（> MIN_USABLE_OUTPUT_CHARS = 40 字符），
     * 否则会被当成空回复触发换模型重试，用例就测不到补跑那条路了 —— 短文案会让
     * 断言凑巧对上却其实走的是升级分支。
     */
    private function notJson(string $label): string
    {
        return str_repeat($label.'的回复根本不是合法 JSON，括号也没配对。', 5);
    }

    private function response(array|string $payload, string $finish = 'stop', int $tokens = 100): AiProviderCallResult
    {
        return new AiProviderCallResult(
            success: true, provider: 'gateway', model: 'text',
            content: is_array($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE) : $payload,
            promptTokens: 0, completionTokens: $tokens, totalTokens: $tokens,
            durationSeconds: 1.0, finishReason: $finish,
        );
    }

    /** 一份字段齐全的路线图报告，用作 schema 示例和各段的应答内容。 */
    private function report(): array
    {
        $report = [
            'report' => ['title' => 'Acme 执行路线图', 'brand_name' => 'Acme', 'timeline_days' => 90],
            'executive_summary' => ['90 天内补齐品牌问答与证据资产。'],
            'roadmap' => array_map(fn (int $d): array => [
                'phase' => $d.' 天', 'goal' => '阶段目标', 'tasks' => ['任务一', '任务二'], 'milestone' => '里程碑',
            ], [30, 60, 90]),
        ];

        foreach ([
            'diagnosis_bridge', 'north_star_metrics', 'target_platforms', 'analysis_dimensions',
            'evidence_maturity', 'source_basis', 'project_packages', 'platform_actions',
            'governance_plan', 'monitoring_plan', 'risk_plan', 'resource_budget',
            'real_data_plan', 'data_acquisition',
        ] as $field) {
            $report[$field] = [['name' => $field, 'description' => '基于品牌资料的'.$field.'内容']];
        }

        return $report;
    }
}
