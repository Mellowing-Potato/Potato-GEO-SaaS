<?php

namespace Tests\Feature;

use App\Jobs\ExecuteSkillJob;
use App\Models\Article;
use App\Models\ModelCallLog;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\SkillExecutionRelay;
use App\Models\Tenant;
use App\Models\TitleLibrary;
use App\Models\User;
use App\Services\Billing\ModelUsageRecorder;
use App\Services\Billing\UnresolvedModelAttempt;
use App\Services\Billing\PreparedEmbeddingAttempt;
use App\Services\Billing\UnresolvedEmbeddingAttempt;
use App\Services\Billing\UnrecordedModelResult;
use App\Services\Content\MaterialLibraryService;
use App\Services\Contracts\SkillAgentRunner;
use App\Services\DTOs\AiProviderCallResult;
use App\Services\DTOs\RelayProgress;
use App\Services\DTOs\SkillBrief;
use App\Services\DTOs\SkillExecutionResult;
use App\Services\Reports\ReportAssetService;
use App\Services\SkillAgent\SkillAgentRunnerService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Queue delivery reliability, independently of report content generation.
 * Runner doubles return real DTOs; report, material, usage writes
 * use the production services against the test database. No external requests.
 */
class SkillExecutionReliabilityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private SkillExecution $execution;

    private SkillBrief $brief;

    private TitleLibrary $title;

    private int $modelCalls = 0;


    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->tenant = Tenant::create([
            'subdomain' => 'skill-reliability', 'name' => 'Reliability', 'plan' => 'basic', 'status' => 'active',
        ]);
        $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'admin']);
        TenantContext::set($this->tenant);
        $project = Project::create(['owner_id' => $user->id, 'name' => 'Reliability']);
        $this->execution = SkillExecution::create([
            'project_id' => $project->id, 'user_id' => $user->id,
            'skill_id' => 'yao-geo-intent-miner', 'skill_name' => 'Intent miner',
            'category' => 'geo-research', 'status' => 'pending', 'execution_mode' => 'async', 'input_data' => [],
        ]);
        $this->title = TitleLibrary::create(['title' => 'Selected title']);
        $this->brief = new SkillBrief(
            $this->execution->skill_id, $project->id, $user->id,
            materialRefs: [['type' => 'title', 'id' => $this->title->id]], executionId: $this->execution->id,
        );
        TenantContext::forget();

    }

    public function test_duplicate_initial_delivery_runs_and_records_usage_only_once(): void
    {
        $runner = $this->singleRunner();
        $this->runStep($runner, 0);
        $this->runStep($runner, 0);

        $this->assertSame('completed', $this->execution->fresh()->status);
        $this->assertUsage(1);
        $this->assertSame(1, Article::count());
        $this->assertSame(1, $this->title->fresh()->usage_count);
        $this->assertSame(150, $this->execution->fresh()->token_usage);
    }

    public function test_async_unknown_gateway_attempt_keeps_original_receipt_pending(): void
    {
        $runner = $this->createMock(SkillAgentRunner::class);
        $runner->expects($this->once())->method('execute')->willThrowException(
            new UnresolvedModelAttempt([
                'http_status' => 202, 'request_id' => 'gateway-original',
                'attribution' => ['client_request_id' => 'original-attempt'],
            ]),
        );

        $this->runStep($runner, 0);
        $saved = $this->execution->fresh();
        $this->assertSame('billing_pending', $saved->status);
        $this->assertSame('model_attempt_unknown', $saved->billing_pending_result['kind']);
        $this->assertSame('gateway-original', data_get($saved->billing_pending_result, 'gateway.request_id'));
        $this->assertSame($saved->id, data_get($saved->billing_pending_result, 'brief_data.executionId'));
        $this->assertSame(0, ModelCallLog::count());
        $this->assertSame(0, Article::count());
    }

    public function test_async_pending_embedding_keeps_original_query_and_stops_delivery(): void
    {
        $runner = $this->createMock(SkillAgentRunner::class);
        $runner->expects($this->once())->method('execute')->willReturnCallback(function (SkillBrief $brief) {
            $clientId = PreparedEmbeddingAttempt::save(
                $brief->executionId, $brief->projectId, $brief->userId,
                $brief->skillId, 'skill:'.$brief->executionId, 'original query', $brief->toArray(),
            );
            throw new UnresolvedEmbeddingAttempt([
                'http_status' => 202, 'attribution' => ['client_request_id' => $clientId],
            ]);
        });

        $this->runStep($runner, 0);
        $saved = $this->execution->fresh();
        $this->assertSame('billing_pending', $saved->status);
        $this->assertSame('embedding_attempt_unknown', data_get($saved->billing_pending_result, 'kind'));
        $this->assertSame('original query', data_get($saved->billing_pending_result, 'query'));
        $this->assertSame(0, Article::count());
        $this->assertSame(0, ModelCallLog::count());
        $this->assertSame(0, $this->title->fresh()->usage_count);
    }

    public function test_async_successful_model_with_local_usage_failure_stays_sealed(): void
    {
        $runner = $this->createMock(SkillAgentRunner::class);
        $runner->expects($this->once())->method('execute')->willThrowException(
            new UnrecordedModelResult(new AiProviderCallResult(
                success: true, provider: 'gateway', model: 'text',
                content: 'PRIVATE_ASYNC_MODEL_RESULT', promptTokens: 10,
                completionTokens: 5, totalTokens: 15,
                gateway: ['request_id' => 'async-local-failure'],
            ), ['attempt' => 1, 'recovery' => false], new \RuntimeException('local write failed')),
        );

        $this->runStep($runner, 0);
        $saved = $this->execution->fresh();
        $this->assertSame('billing_pending', $saved->status);
        $this->assertSame('model_result_unrecorded', data_get($saved->billing_pending_result, 'kind'));
        $this->assertSame('PRIVATE_ASYNC_MODEL_RESULT', data_get($saved->billing_pending_result, 'known_model_result.content'));
        $this->assertSame($saved->id, data_get($saved->billing_pending_result, 'brief_data.executionId'));
        $this->assertSame(0, Article::count());
    }

    public function test_async_skill_delivers_and_consumes_material_without_billing_configuration(): void
    {

        $runner = $this->createMock(SkillAgentRunner::class);
        $runner->expects($this->once())->method('execute')->willReturnCallback(function (): SkillExecutionResult {
            ModelCallLog::query()->create([
                'tenant_id' => $this->tenant->id,
                'project_id' => $this->execution->project_id,
                'user_id' => $this->execution->user_id,
                'skill_execution_id' => $this->execution->id,
                'provider' => 'gateway', 'model' => 'text', 'status' => 'completed',
                'prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150,
                'duration_seconds' => 1.0, 'metadata' => ['points_deduction_status' => 'pending'],
                'gateway_request_id' => 'async-skill-wallet-pending',
            ]);

            return new SkillExecutionResult(
                executionId: $this->execution->id, skillId: $this->execution->skill_id,
                success: true, intermediateData: ['raw_output' => '# PRIVATE_ASYNC_RESULT'],
                qualityReport: ['passed' => true], tokenUsage: 150,
            );
        });

        $this->runStep($runner, 0);
        $this->runStep($runner, 0);
        Http::assertNothingSent();
        $this->assertSame(1, ModelCallLog::withoutGlobalScopes()->count());
        $this->assertSame('completed', $this->execution->fresh()->status);
        $this->assertSame(1, Article::withoutGlobalScopes()->count());
        $this->assertSame(1, $this->title->fresh()->usage_count);
    }

    public function test_failed_skill_retains_partial_output_without_creating_an_article(): void
    {

        $runner = $this->createMock(SkillAgentRunner::class);
        $runner->method('execute')->willReturnCallback(function (): SkillExecutionResult {
            ModelCallLog::query()->create([
                'tenant_id' => $this->tenant->id,
                'project_id' => $this->execution->project_id,
                'user_id' => $this->execution->user_id,
                'skill_execution_id' => $this->execution->id,
                'provider' => 'gateway', 'model' => 'text', 'status' => 'completed',
                'prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150,
                'duration_seconds' => 1.0, 'metadata' => ['points_deduction_status' => 'pending'],
                'gateway_request_id' => 'failed-skill-wallet-pending',
            ]);

            return new SkillExecutionResult(
                executionId: $this->execution->id, skillId: $this->execution->skill_id,
                success: false, intermediateData: ['raw_output' => 'PRIVATE_FAILED_SKILL_PARTIAL'],
                tokenUsage: 150, errorMessage: 'Skill quality check failed.',
            );
        });

        $this->runStep($runner, 0);
        Http::assertNothingSent();
        $this->assertSame('failed', $this->execution->fresh()->status);
        $this->assertSame('PRIVATE_FAILED_SKILL_PARTIAL', data_get($this->execution->fresh()->output_data, 'result_text'));
        $this->assertNull($this->execution->fresh()->billing_pending_result);
        $this->assertSame(0, Article::withoutGlobalScopes()->count());
        $this->assertSame(0, $this->title->fresh()->usage_count);
    }

    public function test_duplicate_relay_step_does_not_consume_the_next_chunk(): void
    {
        $runner = $this->relayRunner();
        $this->runStep($runner, 0);
        $this->runStep($runner, 1);
        $this->runStep($runner, 1);

        $this->assertUsage(1);
        $this->assertSame(1, $this->relay()->completed_steps);
        $this->assertSame([2], $this->relay()->state['pending']);
        $this->assertSame(1, Queue::pushed(ExecuteSkillJob::class, fn ($job) => $job->step === 2)->count());

        $this->runStep($runner, 2);
        $this->runStep($runner, 3);
        $this->runStep($runner, 3);
        $this->assertUsage(2);
        $this->assertSame('completed', $this->execution->fresh()->status);
        $this->assertSame(1, Article::count());
        $this->assertSame(1, $this->title->fresh()->usage_count);
        $this->assertSame(300, $this->execution->fresh()->token_usage);
    }

    public function test_future_relay_step_is_ignored_until_its_predecessor_finishes(): void
    {
        $runner = $this->relayRunner();
        $this->runStep($runner, 0);
        $this->runStep($runner, 2);
        $this->assertUsage(0);
        $this->assertSame(0, $this->relay()->completed_steps);
        Queue::assertNotPushed(ExecuteSkillJob::class, fn ($job) => $job->step === 3);
        $this->runStep($runner, 1);
        $this->assertUsage(1);
    }

    public function test_overlapping_delivery_of_the_same_step_cannot_call_the_model_twice(): void
    {
        $entered = false;
        $runner = null;
        $runner = $this->relayRunner(function () use (&$entered, &$runner): void {
            if (! $entered) {
                $entered = true;
                $this->runStep($runner, 1);
                $this->assertSame($this->tenant->id, TenantContext::id());
            }
        });
        $this->runStep($runner, 0);
        $this->runStep($runner, 1);
        $this->assertUsage(1);
        $this->assertSame(1, $this->relay()->completed_steps);
    }

    #[DataProvider('terminalStates')]
    public function test_late_relay_delivery_preserves_the_terminal_state_and_recorded_usage(string $status): void
    {
        $runner = $this->relayRunner();
        $this->runStep($runner, 0);
        $this->runStep($runner, 1);
        $this->execution->update(['status' => $status, 'error_message' => 'Original terminal reason']);
        $this->runStep($runner, 2);

        $this->assertSame($status, $this->execution->fresh()->status);
        $this->assertSame('Original terminal reason', $this->execution->fresh()->error_message);
        $this->assertUsage(1);
        $this->assertSame(0, SkillExecutionRelay::count());
        $this->assertSame(0, Article::count());
        $this->assertSame(0, $this->title->fresh()->usage_count);
        Queue::assertNotPushed(ExecuteSkillJob::class, fn ($job) => $job->step === 3);
    }

    public static function terminalStates(): array
    {
        return [['cancelled'], ['failed']];
    }

    #[DataProvider('terminalStates')]
    public function test_a_late_model_result_preserves_terminal_status_and_returned_evidence(string $status): void
    {
        $runner = $this->singleRunner(function () use ($status): void {
            $this->execution->update(['status' => $status, 'error_message' => 'Original terminal reason']);
        });
        $this->runStep($runner, 0);
        $fresh = $this->execution->fresh();
        $this->assertSame($status, $fresh->status);
        $this->assertSame('Original terminal reason', $fresh->error_message);
        $this->assertSame(150, $fresh->token_usage);
        $this->assertSame('# Saved evidence', $fresh->output_data['result_text']);
        $this->assertSame('executions/evidence.md', $fresh->output_data['markdown']);
        $this->assertUsage(1);
        $this->assertSame(0, Article::count());
        $this->assertSame(0, $this->title->fresh()->usage_count);
    }

    public function test_exception_after_cancellation_does_not_replace_the_cancellation_reason(): void
    {
        $runner = $this->singleRunner(function (): void {
            $this->execution->update(['status' => 'cancelled', 'error_message' => 'Original cancellation']);
            throw new \RuntimeException('Late worker failure');
        });
        $this->runStep($runner, 0);
        $this->assertSame('cancelled', $this->execution->fresh()->status);
        $this->assertSame('Original cancellation', $this->execution->fresh()->error_message);
        $this->assertUsage(1);
    }

    public function test_failed_result_keeps_generated_text_and_usage_without_a_success_article(): void
    {
        $runner = $this->singleRunner(success: false);
        $this->runStep($runner, 0);
        $this->runStep($runner, 0);
        $this->assertSame('failed', $this->execution->fresh()->status);
        $this->assertSame('# Saved evidence', $this->execution->fresh()->output_data['result_text']);
        $this->assertSame(150, $this->execution->fresh()->token_usage);
        $this->assertUsage(1);
        $this->assertSame(0, Article::count());
        $this->assertSame(0, $this->title->fresh()->usage_count);
    }

    #[DataProvider('outOfOrderFailedSteps')]
    public function test_out_of_order_failed_callback_cannot_destroy_the_current_relay_step(int $step): void
    {
        $runner = $this->relayRunner();
        $this->runStep($runner, 0);
        $this->runStep($runner, 1);
        $this->job($step)->failed(new \RuntimeException('Duplicate delivery expired'));
        $this->assertSame('running', $this->execution->fresh()->status);
        $this->assertSame(1, $this->relay()->completed_steps);
        $this->runStep($runner, 2);
        $this->assertUsage(2);
    }

    public static function outOfOrderFailedSteps(): array
    {
        return [['step' => 0], ['step' => 1], ['step' => 3]];
    }

    public function test_failed_callback_for_the_current_step_still_fails_the_execution(): void
    {
        $runner = $this->relayRunner();
        $this->runStep($runner, 0);
        $this->runStep($runner, 1);
        $this->job(2)->failed(new \RuntimeException('Worker stopped'));
        $this->assertSame('failed', $this->execution->fresh()->status);
        $this->assertSame('Worker stopped', $this->execution->fresh()->error_message);
        $this->assertSame(0, SkillExecutionRelay::count());
        $this->assertUsage(1);
    }

    public function test_report_and_material_writes_roll_back_together_when_persistence_fails(): void
    {
        $materials = $this->createMock(MaterialLibraryService::class);
        $materials->method('recordUsage')->willThrowException(new \RuntimeException('Material storage unavailable'));
        $this->job(0)->handle($this->singleRunner(), app(ReportAssetService::class), $materials);
        $this->assertSame('failed', $this->execution->fresh()->status);
        $this->assertSame(0, Article::count(), 'A failed save must not leave an unlinked success article.');
        $this->assertUsage(1);
    }

    private function singleRunner(?callable $duringCall = null, bool $success = true): SkillAgentRunner
    {
        $runner = $this->createMock(SkillAgentRunner::class);
        $runner->method('execute')->willReturnCallback(function () use ($duringCall, $success): SkillExecutionResult {
            $this->recordModelCall();
            $duringCall?->__invoke();

            return $this->executionResult($success, 150);
        });

        return $runner;
    }

    private function relayRunner(?callable $duringCall = null): SkillAgentRunnerService
    {
        $runner = $this->createMock(SkillAgentRunnerService::class);
        $runner->expects($this->never())->method('execute');
        $runner->method('beginRelay')->willReturnCallback(function (): RelayProgress {
            SkillExecutionRelay::create([
                'skill_execution_id' => $this->execution->id, 'tenant_id' => $this->tenant->id,
                'total_steps' => 2, 'completed_steps' => 0, 'state' => ['pending' => [1, 2], 'token_sum' => 0],
            ]);

            return new RelayProgress(false, 0, 2, 2);
        });
        $runner->method('advanceRelay')->willReturnCallback(function () use ($duringCall): RelayProgress {
            $relay = $this->relay();
            $state = $relay->state;
            if ($state['pending'] === []) {
                return new RelayProgress(false, $relay->completed_steps, 2, 0);
            }
            $duringCall?->__invoke();
            $this->recordModelCall();
            array_shift($state['pending']);
            $state['token_sum'] += 150;
            $relay->update(['completed_steps' => $relay->completed_steps + 1, 'state' => $state]);

            return new RelayProgress(true, $relay->completed_steps, 2, count($state['pending']));
        });
        $runner->method('finalizeRelay')->willReturnCallback(function (): SkillExecutionResult {
            $tokens = $this->relay()->state['token_sum'];
            $this->relay()->delete();

            return $this->executionResult(true, $tokens);
        });

        return $runner;
    }

    private function recordModelCall(): void
    {
        $this->modelCalls++;
        app(ModelUsageRecorder::class)->record($this->execution, new AiProviderCallResult(
            success: true, provider: 'fake', model: 'test', content: '# Saved evidence',
            promptTokens: 100, completionTokens: 50, totalTokens: 150,
        ));
    }

    private function executionResult(bool $success, int $tokens): SkillExecutionResult
    {
        return new SkillExecutionResult(
            executionId: $this->execution->id, skillId: $this->execution->skill_id, success: $success,
            outputPaths: ['md' => 'executions/evidence.md'],
            intermediateData: ['raw_output' => '# Saved evidence'],
            tokenUsage: $tokens, errorMessage: $success ? null : 'Incomplete report',
        );
    }

    private function job(int $step): ExecuteSkillJob
    {
        return new ExecuteSkillJob($this->execution->id, $this->brief->toArray(), $this->tenant->id, $step);
    }

    private function runStep(SkillAgentRunner $runner, int $step): void
    {
        $this->job($step)->handle($runner, app(ReportAssetService::class), app(MaterialLibraryService::class));
    }

    private function relay(): SkillExecutionRelay
    {
        return SkillExecutionRelay::where('skill_execution_id', $this->execution->id)->firstOrFail();
    }

    private function assertUsage(int $count): void
    {
        $this->assertSame($count, $this->modelCalls);
        $this->assertSame($count, ModelCallLog::count());
        $this->assertSame(150 * $count, (int) ModelCallLog::sum('total_tokens'));
    }
}
