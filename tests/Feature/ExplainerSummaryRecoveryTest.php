<?php

namespace Tests\Feature;

use App\Models\ModelCallLog;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\SkillExecutionRelay;
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
use App\Services\SkillAgent\SkillChunkAssembly;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class ExplainerSummaryRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const SKILL = 'yao-geo-explainer-builder';

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    public function test_unicode_boundaries_and_field_scope(): void
    {
        $assembly = new SkillChunkAssembly;
        foreach ([79 => true, 80 => false, 120 => false, 121 => true] as $count => $invalid) {
            $answer = str_repeat('字', $count);
            $this->assertSame($invalid ? ['core_answer'] : [], $assembly->fieldIssues(self::SKILL, ['title' => '文章', 'core_answer' => $answer], ['title', 'core_answer']));
        }
        foreach ([null, 4, '', '   ', str_repeat('字 ', 79)] as $answer) {
            $this->assertSame(['core_answer'], $assembly->fieldIssues(self::SKILL, ['title' => '文章', 'core_answer' => $answer], ['title', 'core_answer']));
        }
        $this->assertSame([], $assembly->fieldIssues(self::SKILL, ['title' => '文章'], ['title']));
        $this->assertSame([], $assembly->fieldIssues('yao-geo-ranking-article-builder', ['title' => '文章'], ['title', 'core_answer']));
    }

    public function test_valid_answer_adds_no_recovery_call(): void
    {
        [$result, $calls] = $this->runWithAnswer(str_repeat('字', 80));
        $this->assertTrue($result->success, $result->errorMessage ?? 'failed');
        $this->assertCount(4, $calls);
        $this->assertSame(4, $result->llmCallCount);
    }

    public function test_complete_lead_sentence_prevents_paid_rewrite_and_keeps_article_body(): void
    {
        $lead = str_repeat('按课题整理文献和分析记录，', 6).'再核对结果。';
        $answer = $lead.str_repeat('清禾可研支持项目中的科研工作流程。', 4);
        $this->assertGreaterThan(120, mb_strlen(preg_replace('/\s+/u', '', $answer)));

        [$result, $calls] = $this->runWithAnswer($answer, relay: true);

        $this->assertTrue($result->success, $result->errorMessage ?? 'failed');
        $this->assertCount(4, $calls);
        $this->assertSame($lead, $result->intermediateData['structured']['core_answer']);
        $this->assertSame('正文保留', $result->intermediateData['structured']['article_sections'][0]['paragraphs'][0]);
    }

    public function test_completed_chunk_with_one_missing_array_closer_needs_no_model_repair(): void
    {
        [$result, $calls] = $this->runWithAnswer(str_repeat('字', 80), brokenCoreJson: true);
        $this->assertTrue($result->success, $result->errorMessage ?? 'failed');
        $this->assertCount(4, $calls);
        $this->assertSame(4, $result->llmCallCount);
        $this->assertSame('正文保留', $result->intermediateData['structured']['article_sections'][0]['paragraphs'][0]);
    }

    public function test_invalid_answer_recovers_only_that_field_with_same_task_and_budget(): void
    {
        [$result, $calls, $records] = $this->runWithAnswer(str_repeat('字', 79), str_repeat('改', 120));
        $this->assertTrue($result->success, $result->errorMessage ?? 'failed');
        $this->assertCount(5, $calls);
        $this->assertSame(['core_answer'], $result->intermediateData['chunk_meta']['article_core']['attempts'][1]['fields']);
        $this->assertTrue($result->intermediateData['chunk_meta']['article_core']['attempts'][1]['recovery']);
        $this->assertStringContainsString(str_repeat('字', 79), $calls[2]['prompt']);
        $this->assertStringContainsString('只输出以下字段，其余字段一律不要出现：core_answer', $calls[2]['prompt']);
        $this->assertSame('model:article_core:attempt:1', $calls[1]['options']['step_id']);
        $this->assertSame('model:article_core.core_answer:attempt:1', $calls[2]['options']['step_id']);
        $this->assertNotSame($calls[1]['options']['client_request_id'], $calls[2]['options']['client_request_id']);
        $this->assertSame(str_repeat('改', 120), $result->intermediateData['structured']['core_answer']);
        $this->assertSame('正文保留', $result->intermediateData['structured']['article_sections'][0]['paragraphs'][0]);
        $this->assertSame(5, $result->llmCallCount);
        $this->assertSame(500, $result->tokenUsage);
        $this->assertCount(5, $records);
        $this->assertCount(1, array_unique(array_column($records, 'execution_id')));
        $this->assertSame('core_answer', $records[2]['metadata']['fields'][0]);
        $this->assertTrue($records[2]['metadata']['recovery']);
    }

    public function test_relay_persists_and_resumes_the_targeted_recovery(): void
    {
        [$result, $calls, $records] = $this->runWithAnswer(str_repeat('字', 79), str_repeat('改', 80), relay: true);
        $this->assertTrue($result->success, $result->errorMessage ?? 'failed');
        $this->assertCount(5, $calls);
        $this->assertCount(5, $records);
        $this->assertNotSame($calls[1]['options']['client_request_id'], $calls[2]['options']['client_request_id']);
        $this->assertSame(['core_answer'], $result->intermediateData['chunk_meta']['article_core']['attempts'][1]['fields']);
        $this->assertSame(str_repeat('改', 80), $result->intermediateData['structured']['core_answer']);
        $this->assertSame(0, SkillExecutionRelay::count());
    }

    public function test_failed_repair_and_exhausted_budget_remain_incomplete(): void
    {
        [$failed, $calls] = $this->runWithAnswer(str_repeat('字', 79), str_repeat('仍', 79));
        $this->assertFalse($failed->success);
        $this->assertCount(5, $calls);
        $this->assertSame(['core_answer'], $failed->intermediateData['chunk_meta']['article_core']['missing_fields']);
        [$exhausted, $calls] = $this->runWithAnswer(str_repeat('字', 79), null, 0);
        $this->assertFalse($exhausted->success);
        $this->assertCount(4, $calls);
        $this->assertSame('call_budget_exhausted', $exhausted->intermediateData['chunk_meta']['article_core']['recovery_skipped']);
    }

    private function runWithAnswer(string $answer, ?string $repair = null, int $budget = 6, bool $brokenCoreJson = false, bool $relay = false): array
    {
        config(['geo.chunk_recovery_max_calls' => $budget, 'geo.chunk_strict' => true, 'geo.analysis_chunked' => $relay]);
        $tenant = Tenant::create(['subdomain' => 'summary-'.uniqid(), 'name' => 'Summary', 'plan' => 'basic', 'status' => 'active']);
        TenantContext::set($tenant);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $project = Project::create(['owner_id' => $user->id, 'name' => 'Summary']);
        $execution = SkillExecution::create([
            'project_id' => $project->id, 'user_id' => $user->id, 'skill_id' => self::SKILL,
            'skill_name' => '解释文章', 'category' => SkillCategory::GeoResearch->value,
            'stage_index' => 2, 'status' => 'running', 'execution_mode' => 'async', 'input_data' => [],
        ]);
        $responses = [
            ['research_basis' => [['研究依据']]],
            ['title' => '原文标题', 'core_answer' => $answer, 'article_sections' => [['heading' => '正文', 'paragraphs' => ['正文保留']]]],
            ['key_values' => [['价值']]],
            ['faq' => [['问题', '回答']]],
        ];
        if ($repair !== null) {
            array_splice($responses, 2, 0, [['core_answer' => $repair]]);
        }
        $calls = [];
        $ai = $this->createMock(AiProvider::class);
        $ai->method('generate')->willReturnCallback(function ($prompt, $system, $options) use (&$calls, $responses, $brokenCoreJson): AiProviderCallResult {
            $index = count($calls);
            $calls[] = compact('prompt', 'options');
            $this->assertArrayHasKey($index, $responses, 'Unexpected model call');
            $content = json_encode($responses[$index], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if ($brokenCoreJson && $index === 1) {
                $content = preg_replace('/\]\}$/', '}', $content);
            }
            return new AiProviderCallResult(
                success: true, provider: 'mock', model: 'text',
                content: $content,
                promptTokens: 50, completionTokens: 50, totalTokens: 100, finishReason: 'stop',
            );
        });
        $records = [];
        $usage = $this->createMock(ModelUsageRecorder::class);
        $usage->method('record')->willReturnCallback(function (SkillExecution $recordExecution, AiProviderCallResult $result, array $metadata) use (&$records): ModelCallLog {
            $records[] = ['execution_id' => $recordExecution->id, 'metadata' => $metadata];
            return new ModelCallLog;
        });
        $bridge = $this->createMock(RenderBridgeService::class);
        $bridge->method('hasRenderScript')->willReturn(true);
        $bridge->method('render')->willReturn(new RenderResult(success: true, renderedPaths: []));
        $definition = new SkillDefinition(self::SKILL, '解释文章', SkillCategory::GeoResearch, 'stable', false, 2, '');
        $assets = new SkillAssets(self::SKILL, '# 方法', [], '/mock/render.py', null, true);
        $registry = $this->createMock(SkillRegistry::class);
        $registry->method('find')->willReturn($definition);
        $loader = $this->createMock(SkillAssetLoader::class);
        $loader->method('loadAll')->willReturn($assets);
        $runner = new SkillAgentRunnerService(
            $registry, $ai, $this->createMock(KnowledgeRetrieval::class),
            $loader, $bridge, $this->createMock(PromptTemplateService::class), $usage,
        );
        $specs = (new ReflectionMethod($runner, 'chunkSpecsFor'))->invoke($runner, self::SKILL, true);
        $brief = new SkillBrief(self::SKILL, $project->id, $user->id, executionId: $execution->id);
        if ($relay) {
            $progress = $runner->beginRelay($brief);
            $this->assertNotNull($progress);
            for ($step = 0; $progress->pendingCount > 0 && $step < 10; $step++) {
                $progress = $runner->advanceRelay($brief);
            }
            $this->assertSame(0, $progress->pendingCount);
            $result = $runner->finalizeRelay($brief);
        } else {
            $result = (new ReflectionMethod($runner, 'executeChunked'))->invoke(
                $runner, $definition, $assets, $brief,
                '系统提示', '', microtime(true), (string) $tenant->id, $specs,
            );
        }

        return [$result, $calls, $records];
    }
}
