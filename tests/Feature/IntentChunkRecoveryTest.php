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
use ReflectionMethod;
use Tests\TestCase;

class IntentChunkRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const SKILL = 'yao-geo-intent-miner';

    private array $calls = [];

    private SkillExecution $execution;

    public function test_complete_run_uses_configured_budget_and_one_call_per_chunk(): void
    {
        config(['geo.chunk_max_tokens' => 24000]);
        $result = $this->runIntent($this->baseResponses());
        $this->assertTrue($result->success);
        $this->assertSame($this->chunkCount(), $result->llmCallCount);
        $this->assertSame($this->baseTokens(), $result->tokenUsage);
        foreach ($this->calls as $call) {
            $this->assertSame(24000, $call['options']['max_tokens']);
            // 每段回复都要 json_decode。不开这个开关，模型可以吐出括号配对错误的回复，
            // 而且 finish_reason 照样是 stop（生产 execution 46 实证）。
            $this->assertTrue($call['options']['json_object'], '分段调用必须强制 JSON 语法');
        }
        foreach (array_keys($this->report()) as $field) {
            $this->assertArrayHasKey($field, $result->intermediateData['structured']);
        }
        $this->assertSame([], $result->intermediateData['structured']['data_sources']);
    }

    public function test_8000_token_truncation_recovers_only_the_question_segment_and_counts_every_call(): void
    {
        $responses = $this->baseResponses();
        $responses[$this->questionIndex()] = $this->truncated();
        array_splice($responses, $this->questionIndex() + 1, 0, $this->questionRepairs());
        $result = $this->runIntent($responses);

        $this->assertTrue($result->success);
        $this->assertSame($this->chunkCount() + 3, $result->llmCallCount);
        $this->assertSame($this->chunkCount(), $result->intermediateData['chunk_count']);
        $this->assertSame($this->baseTokens() + 8300, $result->tokenUsage);
        $this->assertSame($this->chunkCount(), $result->intermediateData['chunks_parsed']);
        $this->assertSame(18, count($result->intermediateData['structured']['question_bank']));
        $meta = $result->intermediateData['chunk_meta']['question_bank'];
        $this->assertTrue($meta['complete']);
        $this->assertSame('length', $meta['attempts'][0]['finish_reason']);
        $this->assertSame(8000, $meta['attempts'][0]['completion_tokens']);
        $this->assertCount(4, $meta['attempts']);
        $this->assertStringContainsString('"priority":"P1",', $result->intermediateData['raw_output']);
        foreach (['question_bank', 'follow_up_chains', 'prompt_library'] as $i => $field) {
            $this->assertStringContainsString('只输出以下字段，其余字段一律不要出现：'.$field.'。', $this->calls[$this->questionIndex() + 1 + $i]['prompt']);
            $this->assertSame(16384, $this->calls[$this->questionIndex() + 1 + $i]['options']['max_tokens']);
            $this->assertSame('text', $this->calls[$this->questionIndex() + 1 + $i]['options']['model']);
        }
        $this->assertStringContainsString('Q5: 问题5', $this->calls[$this->questionIndex() + 2]['prompt']);
        $this->assertStringContainsString('Q5: 问题5', $this->calls[$this->questionIndex() + 4]['prompt']);
        $this->assertSame($this->chunkCount() + 3, ModelCallLog::where('skill_execution_id', $this->execution->id)->count());
        $this->assertSame($this->baseTokens() + 8300, (int) ModelCallLog::where('skill_execution_id', $this->execution->id)->sum('total_tokens'));
    }

    public function test_valid_json_missing_two_fields_repairs_them_without_regenerating_question_bank(): void
    {
        $responses = $this->baseResponses();
        $responses[$this->questionIndex()] = $this->response(['question_bank' => $this->report()['question_bank']]);
        $repairs = $this->questionRepairs();
        array_splice($responses, $this->questionIndex() + 1, 0, [$repairs[1], $repairs[2]]);
        $result = $this->runIntent($responses);
        $this->assertTrue($result->success);
        $this->assertSame($this->chunkCount() + 2, $result->llmCallCount);
        $this->assertSame(['follow_up_chains'], $result->intermediateData['chunk_meta']['question_bank']['attempts'][1]['fields']);
    }

    public function test_underfilled_lists_are_repaired_to_the_delivery_minimums(): void
    {
        $responses = $this->baseResponses();
        $underfilled = $this->questionFields();
        $underfilled['question_bank'] = array_slice($underfilled['question_bank'], 0, 12);
        $underfilled['follow_up_chains'] = array_slice($underfilled['follow_up_chains'], 0, 4);
        $responses[$this->questionIndex()] = $this->response($underfilled);
        $repairs = $this->questionRepairs();
        array_splice($responses, $this->questionIndex() + 1, 0, [$repairs[0], $repairs[1]]);

        $result = $this->runIntent($responses);

        $this->assertTrue($result->success);
        $this->assertSame($this->chunkCount() + 2, $result->llmCallCount);
        $this->assertCount(18, $result->intermediateData['structured']['question_bank']);
        $this->assertCount(8, $result->intermediateData['structured']['follow_up_chains']);
        $this->assertSame(['question_bank'], $result->intermediateData['chunk_meta']['question_bank']['attempts'][1]['fields']);
        $this->assertSame(['follow_up_chains'], $result->intermediateData['chunk_meta']['question_bank']['attempts'][2]['fields']);
    }

    public function test_valid_but_length_finished_json_is_not_accepted(): void
    {
        config(['geo.chunk_recovery_max_calls' => 0]);
        $responses = $this->baseResponses();
        $responses[$this->questionIndex()] = $this->response($this->questionFields(), finish: 'length');
        $result = $this->runIntent($responses, render: false);
        $this->assertFalse($result->success);
        $this->assertNull($result->intermediateData['structured'] ?? null);
        $this->assertSame(0, $result->intermediateData['chunk_meta']['question_bank']['fields']);
    }

    public function test_failed_field_repair_stays_failed_without_recursive_retry_or_render(): void
    {
        $responses = $this->baseResponses();
        $responses[$this->questionIndex()] = $this->truncated();
        $repairs = $this->questionRepairs();
        $repairs[0] = $this->truncated();
        array_splice($responses, $this->questionIndex() + 1, 0, $repairs);
        $result = $this->runIntent($responses, render: false);
        $this->assertFalse($result->success);
        $this->assertSame($this->chunkCount() + 3, $result->llmCallCount);
        $this->assertSame(['question_bank'], $result->intermediateData['chunk_meta']['question_bank']['missing_fields']);
        $this->assertNull($result->intermediateData['structured'] ?? null);
        $this->assertSame([], $result->outputPaths);
    }

    public function test_no_retry_when_recovery_budget_is_insufficient(): void
    {
        config(['geo.chunk_recovery_max_calls' => 2]);
        $responses = $this->baseResponses();
        $responses[$this->questionIndex()] = $this->truncated();
        $this->assertFalse($this->runIntent($responses, render: false)->success);
    }

    public function test_recovery_reserves_time_for_http_and_queue_completion(): void
    {
        $responses = $this->baseResponses();
        $responses[$this->questionIndex()] = $this->truncated();
        $result = $this->runIntent($responses, render: false, elapsed: 150);
        $this->assertFalse($result->success);
        $this->assertSame('insufficient_time', $result->intermediateData['chunk_meta']['question_bank']['recovery_skipped']);
    }

    public function test_reasoning_starvation_does_not_escalate_or_split(): void
    {
        $responses = $this->baseResponses();
        $responses[$this->questionIndex()] = $this->response('', finish: 'length', tokens: 8100);
        $result = $this->runIntent($responses, render: false);
        $this->assertFalse($result->success);
        $this->assertSame($this->chunkCount(), $result->llmCallCount);
        $this->assertSame($this->baseTokens() + 8000, $result->tokenUsage);
    }

    public function test_cancellation_prevents_scheduled_repairs(): void
    {
        $responses = $this->baseResponses();
        $responses[$this->questionIndex()] = $this->truncated();
        $stopAt = $this->questionIndex() + 2;
        $result = $this->runIntent(array_slice($responses, 0, $stopAt), render: false, cancelAfter: $stopAt);
        $this->assertTrue($result->intermediateData['cancelled']);
        $this->assertSame($stopAt, $result->llmCallCount);
    }

    public function test_empty_core_fields_cannot_pass_by_returning_one_other_field(): void
    {
        config(['geo.chunk_recovery_max_calls' => 0]);
        $responses = $this->baseResponses();
        $responses[$this->questionIndex()] = $this->response(array_replace($this->questionFields(), ['question_bank' => [], 'prompt_library' => []]));
        $result = $this->runIntent($responses, render: false);
        $this->assertFalse($result->success);
        $this->assertSame(['question_bank', 'prompt_library'], $result->intermediateData['chunk_meta']['question_bank']['missing_fields']);
    }

    public function test_a_single_object_with_many_keys_is_one_question_and_cannot_be_padded_to_five(): void
    {
        config(['geo.chunk_recovery_max_calls' => 0]);
        $responses = $this->baseResponses();
        $responses[$this->questionIndex()] = $this->response(array_replace($this->questionFields(), ['question_bank' => $this->report()['question_bank'][0]]));
        $result = $this->runIntent($responses, render: false);
        $this->assertFalse($result->success);
        $this->assertSame(['question_bank'], $result->intermediateData['chunk_meta']['question_bank']['missing_fields']);
    }

    public function test_question_rows_without_question_text_are_not_usable_content(): void
    {
        config(['geo.chunk_recovery_max_calls' => 0]);
        $responses = $this->baseResponses();
        $fields = $this->questionFields();
        $fields['question_bank'] = array_fill(0, 5, ['question_id' => 'Q1', 'question' => '  ']);
        $responses[$this->questionIndex()] = $this->response($fields);
        $result = $this->runIntent($responses, render: false);
        $this->assertFalse($result->success);
        $this->assertSame(['question_bank'], $result->intermediateData['chunk_meta']['question_bank']['missing_fields']);
    }

    /**
     * 模型「写完了」(finish_reason=stop) 但回复根本不是 JSON —— 按缺失字段逐个补。
     *
     * 这是 2026-09-06 生产 execution 46 的真实形态：7 段里 2 段是这样坏的。旧口径把
     * 「解析不出来」排除在补跑之外，于是一次都不补就整单判失败，客户只能重跑全部 7 段
     * ——用我们的缺陷收第二次钱。
     */
    public function test_complete_but_unparseable_json_is_repaired_field_by_field(): void
    {
        $responses = $this->baseResponses();
        $responses[$this->questionIndex()] = $this->response(str_repeat('这不是合法的报告 JSON。', 10));
        array_splice($responses, $this->questionIndex() + 1, 0, $this->questionRepairs());
        $result = $this->runIntent($responses);

        $this->assertTrue($result->success);
        $this->assertSame($this->chunkCount() + 3, $result->llmCallCount);
        $meta = $result->intermediateData['chunk_meta']['question_bank'];
        $this->assertTrue($meta['attempts'][0]['ok'], '首次调用本身是成功的，坏的是内容不是链路');
        $this->assertTrue($meta['complete']);
        $this->assertSame([], $meta['missing_fields']);
        foreach (['question_bank', 'follow_up_chains', 'prompt_library'] as $i => $field) {
            $this->assertStringContainsString(
                '只输出以下字段，其余字段一律不要出现：'.$field.'。',
                $this->calls[$this->questionIndex() + 1 + $i]['prompt'],
            );
        }
    }

    /** 补跑预算是这条路的闸：关掉预算就退回「坏 JSON 不重放」，不会无限烧钱。 */
    public function test_unparseable_json_is_not_replayed_when_recovery_budget_is_off(): void
    {
        config(['geo.chunk_recovery_max_calls' => 0]);
        $responses = $this->baseResponses();
        $responses[$this->questionIndex()] = $this->response(str_repeat('这不是合法的报告 JSON。', 10));
        $result = $this->runIntent($responses, render: false);
        $this->assertFalse($result->success);
        $this->assertSame($this->chunkCount(), $result->llmCallCount);
    }

    public function test_successful_fallback_keeps_the_initial_empty_calls_usage(): void
    {
        $responses = $this->baseResponses();
        array_splice($responses, 1, 0, [$this->response('', tokens: 250)]);
        $result = $this->runIntent($responses);
        $this->assertTrue($result->success);
        $this->assertSame($this->chunkCount() + 1, $result->llmCallCount);
        $this->assertSame($this->baseTokens() + 250, $result->tokenUsage);
        $this->assertSame($this->chunkCount() + 1, ModelCallLog::where('skill_execution_id', $this->execution->id)->count());
    }

    public function test_short_valid_field_repair_is_accepted_without_model_escalation(): void
    {
        $responses = $this->baseResponses();
        $fields = $this->questionFields();
        unset($fields['prompt_library']);
        $responses[$this->questionIndex()] = $this->response($fields);
        array_splice($responses, $this->questionIndex() + 1, 0, [$this->response(['prompt_library' => [['prompt' => '短问题']]])]);
        $result = $this->runIntent($responses);
        $this->assertTrue($result->success);
        $this->assertSame($this->chunkCount() + 1, $result->llmCallCount);
        $this->assertStringContainsString('prompt_library[]', $this->calls[$this->questionIndex() + 1]['prompt']);
        $this->assertStringNotContainsString('sections[]', $this->calls[$this->questionIndex() + 1]['prompt']);
        $this->assertStringNotContainsString('summary_cards[]', $this->calls[$this->questionIndex() + 1]['prompt']);
    }

    private function runIntent(array $responses, bool $render = true, float $elapsed = 0, ?int $cancelAfter = null): SkillExecutionResult
    {
        Http::preventStrayRequests();

        $tenant = Tenant::create(['subdomain' => 'intent-recovery', 'name' => 'Recovery', 'plan' => 'basic', 'status' => 'active']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        TenantContext::set($tenant);
        try {
            $project = Project::create(['owner_id' => $user->id, 'name' => 'Acme', 'brand_name' => 'Acme']);
            $this->execution = SkillExecution::create([
                'tenant_id' => $tenant->id, 'project_id' => $project->id, 'user_id' => $user->id,
                'skill_id' => self::SKILL, 'skill_name' => '意图挖掘', 'category' => SkillCategory::GeoResearch->value,
                'stage_index' => 0, 'status' => 'running', 'execution_mode' => 'async', 'input_data' => [],
            ]);
            $provider = $this->createMock(AiProvider::class);
            $provider->expects($this->exactly(count($responses)))->method('generate')->willReturnCallback(
                function ($prompt, $system, $options) use ($responses, $cancelAfter) {
                    $index = count($this->calls);
                    $this->calls[] = compact('prompt', 'system', 'options');
                    if (count($this->calls) === $cancelAfter) {
                        $this->execution->update(['status' => 'cancelled']);
                    }

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
            $specs = (new ReflectionMethod($runner, 'chunkSpecsFor'))->invoke($runner, self::SKILL, true);

            return (new ReflectionMethod($runner, 'executeChunked'))->invoke(
                $runner,
                new SkillDefinition(id: self::SKILL, name: '意图挖掘', category: SkillCategory::GeoResearch, maturity: 'stable', requiresWeb: false, stageIndex: 0, description: ''),
                new SkillAssets(self::SKILL, '# 方法论', [], '/skills/render.py', json_encode($this->report(), JSON_UNESCAPED_UNICODE), true),
                new SkillBrief(self::SKILL, $project->id, $user->id, ['brand_name' => 'Acme', 'model' => 'text'], executionId: $this->execution->id),
                '请输出完整报告', '', microtime(true) - $elapsed, (string) $tenant->id, $specs,
            );
        } finally {
            TenantContext::forget();
        }
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
        foreach (['follow_up_chains', 'prompt_library', 'scoring_matrix', 'ai_sampling_plan', 'calibration_actions', 'content_topics', 'faq_bank', 'knowledge_base_entries', 'action_roadmap'] as $field) {
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

    /**
     * 本技能的分段谱。测试一律从这里推导段数与顺序，不写死——
     * 2026-09-06 把 4 段重切成 7 段时，写死的那些断言全线报错，才补上这套推导。
     */
    private function specs(): array
    {
        $runner = app(SkillAgentRunnerService::class);

        return (new ReflectionMethod($runner, 'chunkSpecsFor'))->invoke($runner, self::SKILL, true);
    }

    private function chunkCount(): int
    {
        return count($this->specs());
    }

    /** 全成功基线的 token 总量：每段一次调用、每次 100。 */
    private function baseTokens(): int
    {
        return $this->chunkCount() * 100;
    }

    /** question_bank 段在分段谱里的下标——本文件的补跑用例全打在这一段上。 */
    private function questionIndex(): int
    {
        foreach ($this->specs() as $i => $spec) {
            if ($spec['key'] === 'question_bank') {
                return $i;
            }
        }

        $this->fail('分段谱里找不到 question_bank 段');
    }

    private function baseResponses(): array
    {
        return array_map(fn ($spec) => $this->response(array_intersect_key($this->report(), array_flip($spec['fields']))), $this->specs());
    }

    private function questionFields(): array
    {
        return array_intersect_key($this->report(), array_flip(['question_bank', 'follow_up_chains', 'prompt_library']));
    }

    private function questionRepairs(): array
    {
        return array_map(fn ($field) => $this->response([$field => $this->report()[$field]]), ['question_bank', 'follow_up_chains', 'prompt_library']);
    }

    private function truncated(): AiProviderCallResult
    {
        return $this->response('{"question_bank":[{"content_asset":"FAQ/对比文章","priority":"P1",', finish: 'length', tokens: 8100);
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
