<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\GeoAnalysisController;
use App\Jobs\RunGeoAnalysisJob;
use App\Models\Article;
use App\Models\ModelCallLog;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\Tenant;
use App\Models\TenantAiKey;
use App\Models\User;
use App\Services\AI\AiProviderServiceImpl;
use App\Services\Billing\ModelUsageRecorder;
use App\Services\Billing\UnknownModelAttemptRecovery;
use App\Services\Contracts\SkillAgentRunner;
use App\Services\DTOs\AiProviderCallResult;
use App\Services\DTOs\SkillBrief;
use App\Services\DTOs\SkillExecutionResult;
use App\Services\Geo\GeoAnalysisSummaryExtractor;
use App\Services\Reports\ReportAssetService;
use App\Support\BillingContext;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GeoAnalysisReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.tenant_domain' => 'geo.example.test',
            'geo.analysis_pipeline_mode' => 'full']);
        Http::preventStrayRequests();
        Http::fake();
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        TenantContext::forget();
        BillingContext::forget();
        parent::tearDown();
    }

    public function test_duplicate_delivery_calls_runner_once_and_keeps_single_usage_and_report(): void
    {
        [$tenant, $user, $project, $execution] = $this->fixture();
        $this->runnerOnce(function (SkillBrief $brief) use ($tenant, $user, $execution): SkillExecutionResult {
            $this->assertSame($tenant->id, TenantContext::id());
            $this->assertSame($user->id, BillingContext::userId());
            $this->assertSame($execution->id, $brief->executionId);
            $this->recordUsage($execution);
            Storage::disk('public')->put("executions/{$execution->id}/reliability.md", '# Isolated report');

            return $this->success($execution);
        });

        $job = $this->job($tenant, $user, $project, $execution);
        $controller = app(GeoAnalysisController::class);
        $job->handle($controller);
        $first = $execution->fresh()->getAttributes();
        $job->handle($controller);

        $this->assertSame($first, $execution->fresh()->getAttributes());
        $this->assertSame('completed', $execution->fresh()->status);
        $this->assertSame(30, $execution->fresh()->output_data['model_usage']['total_tokens']);
        $this->assertSame(1, ModelCallLog::withoutGlobalScopes()->count());
        $this->assertSame(1, Article::withoutGlobalScopes()->count());
        $this->assertSame('# Isolated report', Article::withoutGlobalScopes()->firstOrFail()->body_markdown);
        $this->assertNull(TenantContext::id());
        $this->assertNull(BillingContext::userId());
        $this->assertSame(1, $job->tries);
    }

    public function test_full_pipeline_report_delivers_without_wallet_receipts(): void
    {

        [$tenant, $user, $project, $execution] = $this->fixture();
        $logId = null;
        $this->runnerOnce(function (SkillBrief $brief) use ($tenant, $user, $project, $execution, &$logId): SkillExecutionResult {
            ModelCallLog::query()->create([
                'tenant_id' => $tenant->id, 'project_id' => $project->id,
                'user_id' => $user->id, 'skill_execution_id' => $execution->id,
                'provider' => 'gateway', 'model' => 'text', 'status' => 'failed',
                'prompt_tokens' => 5, 'completion_tokens' => 0, 'total_tokens' => 5,
                'duration_seconds' => 0.5,
                'metadata' => ['points_deduction_status' => 'waived', 'customer_charge_waived' => true],
                'gateway_request_id' => 'full-analysis-failed-attempt-waived',
            ]);
            $log = ModelCallLog::query()->create([
                'tenant_id' => $tenant->id, 'project_id' => $project->id,
                'user_id' => $user->id, 'skill_execution_id' => $execution->id,
                'provider' => 'gateway', 'model' => 'text', 'status' => 'completed',
                'prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30,
                'duration_seconds' => 1.0,
                'metadata' => ['points_deduction_status' => 'pending', 'points_debit_prepared_at' => now()->toISOString()],
                'gateway_request_id' => 'full-analysis-wallet-pending',
            ]);
            $logId = $log->id;
            Storage::disk('public')->put("executions/{$execution->id}/reliability.md", '# PRIVATE_FULL_REPORT');

            return $this->success($execution);
        });

        $this->job($tenant, $user, $project, $execution)->handle(app(GeoAnalysisController::class));
        $this->assertSame('completed', $execution->fresh()->status);
        $this->assertSame(1, Article::withoutGlobalScopes()->count());
    }

    public function test_full_pipeline_embedding_does_not_inflate_text_report_usage(): void
    {

        [$tenant, $user, $project, $execution] = $this->fixture();
        $textLogId = null;
        $this->runnerOnce(function (SkillBrief $brief) use ($tenant, $user, $project, $execution, &$textLogId): SkillExecutionResult {
            ModelCallLog::query()->create([
                'tenant_id' => $tenant->id, 'project_id' => $project->id,
                'user_id' => $user->id, 'skill_execution_id' => $execution->id,
                'provider' => 'gateway', 'model' => 'embedding', 'status' => 'completed',
                'prompt_tokens' => 50, 'completion_tokens' => 0, 'total_tokens' => 50,
                'duration_seconds' => 0,
                'metadata' => ['usage_kind' => 'embedding', 'direct_gateway_settlement' => true,
                    'points_deduction_status' => 'deducted', 'points_deducted' => 2],
                'gateway_request_id' => 'full-analysis-embedding-cost',
            ]);
            $text = ModelCallLog::query()->create([
                'tenant_id' => $tenant->id, 'project_id' => $project->id,
                'user_id' => $user->id, 'skill_execution_id' => $execution->id,
                'provider' => 'gateway', 'model' => 'text', 'status' => 'completed',
                'prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30,
                'duration_seconds' => 1.0,
                'metadata' => ['points_deduction_status' => 'pending',
                    'points_debit_prepared_at' => now()->toISOString()],
                'gateway_request_id' => 'full-analysis-text-wallet-pending',
            ]);
            $textLogId = $text->id;
            Storage::disk('public')->put("executions/{$execution->id}/reliability.md", '# PRIVATE_REPORT');

            return $this->success($execution);
        });

        $this->job($tenant, $user, $project, $execution)->handle(app(GeoAnalysisController::class));
        $this->assertSame('completed', $execution->fresh()->status);
        $usage = $execution->fresh()->output_data['model_usage'];
        $this->assertSame(1, $usage['call_count']);
        $this->assertSame(30, $usage['total_tokens']);
        $this->assertSame(2, ModelCallLog::withoutGlobalScopes()->count());
        $this->assertSame(1, Article::withoutGlobalScopes()->count());
    }

    public function test_embedding_only_receipt_cannot_release_pending_analysis_report(): void
    {

        [$tenant, $user, $project, $execution] = $this->fixture();
        $this->withTenant($tenant, function () use ($tenant, $user, $project, $execution): void {
            $execution->update([
                'status' => 'billing_pending',
                'billing_pending_result' => [
                    'version' => 1, 'kind' => 'full_geo_analysis',
                    'attributes' => ['status' => 'completed', 'output_data' => [
                        'analysis' => ['summary' => 'PRIVATE_ANALYSIS'],
                        'result_text' => 'PRIVATE_ANALYSIS',
                    ]],
                ],
            ]);
            ModelCallLog::query()->create([
                'tenant_id' => $tenant->id, 'project_id' => $project->id,
                'user_id' => $user->id, 'skill_execution_id' => $execution->id,
                'provider' => 'gateway', 'model' => 'embedding', 'status' => 'completed',
                'prompt_tokens' => 50, 'completion_tokens' => 0, 'total_tokens' => 50,
                'duration_seconds' => 0,
                'metadata' => ['direct_gateway_settlement' => true,
                    'points_deduction_status' => 'deducted', 'points_deducted' => 2],
                'gateway_request_id' => 'analysis-legacy-embedding-only',
            ]);
        });

        $this->assertFalse(app(GeoAnalysisController::class)->finalizePendingAnalysis($execution->id));
        $this->assertSame('billing_pending', $execution->fresh()->status);
        $this->assertSame(0, Article::withoutGlobalScopes()->count());
    }

    public function test_embedding_cost_row_does_not_suppress_missing_text_usage_record(): void
    {
        [$tenant, $user, $project, $execution] = $this->fixture();
        $this->runnerOnce(function (SkillBrief $brief) use ($tenant, $user, $project, $execution): SkillExecutionResult {
            ModelCallLog::query()->create([
                'tenant_id' => $tenant->id, 'project_id' => $project->id,
                'user_id' => $user->id, 'skill_execution_id' => $execution->id,
                'provider' => 'gateway', 'model' => 'embedding', 'status' => 'completed',
                'prompt_tokens' => 50, 'completion_tokens' => 0, 'total_tokens' => 50,
                'duration_seconds' => 0,
                'metadata' => ['usage_kind' => 'embedding', 'direct_gateway_settlement' => true],
                'gateway_request_id' => 'analysis-embedding-before-text-log',
            ]);
            Storage::disk('public')->put("executions/{$execution->id}/reliability.md", '# Isolated report');

            return $this->success($execution);
        });

        $this->job($tenant, $user, $project, $execution)->handle(app(GeoAnalysisController::class));
        $this->assertSame('completed', $execution->fresh()->status);
        $logs = ModelCallLog::withoutGlobalScopes()->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame('embedding', $logs[0]->model);
        $this->assertSame('mock-analysis', $logs[1]->model);
        $this->assertSame(30, $execution->fresh()->output_data['model_usage']['total_tokens']);
    }

    public function test_failed_full_pipeline_keeps_partial_output_private(): void
    {

        [$tenant, $user, $project, $execution] = $this->fixture();
        $this->runnerOnce(function (SkillBrief $brief) use ($tenant, $user, $project, $execution): SkillExecutionResult {
            ModelCallLog::query()->create([
                'tenant_id' => $tenant->id, 'project_id' => $project->id,
                'user_id' => $user->id, 'skill_execution_id' => $execution->id,
                'provider' => 'gateway', 'model' => 'text', 'status' => 'completed',
                'prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30,
                'duration_seconds' => 1.0, 'metadata' => ['points_deduction_status' => 'pending'],
                'gateway_request_id' => 'failed-analysis-wallet-pending',
            ]);

            return new SkillExecutionResult(
                executionId: $brief->executionId, skillId: $brief->skillId, success: false,
                intermediateData: ['raw_output' => 'PRIVATE_FAILED_ANALYSIS_PARTIAL'],
                tokenUsage: 30, errorMessage: 'Report failed quality check.',
            );
        });

        $this->job($tenant, $user, $project, $execution)->handle(app(GeoAnalysisController::class));
        $this->assertSame('failed', $execution->fresh()->status);
        $this->assertSame('PRIVATE_FAILED_ANALYSIS_PARTIAL', data_get($execution->fresh()->output_data, 'failure_diagnostics.raw_output'));
        $this->assertArrayNotHasKey('markdown', $execution->fresh()->output_data);
        $this->assertArrayNotHasKey('analysis', $execution->fresh()->output_data);
        $this->assertNull($execution->fresh()->billing_pending_result);
        $this->assertSame(0, Article::withoutGlobalScopes()->count());
    }

    #[DataProvider('nonPendingStatuses')]
    public function test_duplicate_delivery_does_not_rewrite_state_when_project_was_deleted(string $status): void
    {
        [$tenant, $user, $project, $execution] = $this->fixture($status);
        $execution->update(['error_message' => 'Original outcome', 'output_data' => ['checkpoint' => 'retained']]);
        $project->delete();
        $before = $execution->fresh()->getAttributes();
        $this->runnerNever();

        $this->job($tenant, $user, $project, $execution)->handle(app(GeoAnalysisController::class));

        $this->assertSame($before, $execution->fresh()->getAttributes());
        $this->assertSame(0, ModelCallLog::withoutGlobalScopes()->count());
    }

    public static function nonPendingStatuses(): array
    {
        return [['completed'], ['failed'], ['cancelled'], ['running']];
    }

    public function test_missing_project_fails_only_an_unclaimed_execution(): void
    {
        [$tenant, $user, $project, $execution] = $this->fixture();
        $project->delete();
        $this->runnerNever();

        $this->job($tenant, $user, $project, $execution)->handle(app(GeoAnalysisController::class));

        $this->assertSame('failed', $execution->fresh()->status);
        $this->assertSame('Project no longer exists.', $execution->fresh()->error_message);
        $this->assertNull(TenantContext::id());
    }

    #[DataProvider('terminalStatuses')]
    public function test_late_failure_callback_preserves_terminal_outcome_and_usage(string $status): void
    {
        [$tenant, $user, $project, $execution] = $this->fixture($status);
        $execution->update(['error_message' => 'Original outcome', 'output_data' => ['checkpoint' => 'retained']]);
        $this->withTenant($tenant, fn () => $this->recordUsage($execution));
        $before = $execution->fresh()->getAttributes();

        $this->job($tenant, $user, $project, $execution)->failed(new \RuntimeException('Late worker error'));

        $this->assertSame($before, $execution->fresh()->getAttributes());
        $this->assertSame(1, ModelCallLog::withoutGlobalScopes()->count());
        $this->assertNull(TenantContext::id());
    }

    public static function terminalStatuses(): array
    {
        return [['completed'], ['failed'], ['cancelled']];
    }

    #[DataProvider('terminalStatuses')]
    public function test_paused_tenant_duplicate_delivery_preserves_terminal_evidence(string $status): void
    {
        [$tenant, $user, $project, $execution] = $this->fixture($status);
        $execution->update(['output_data' => ['checkpoint' => 'retained'], 'error_message' => 'Original outcome']);
        $tenant->update(['status' => 'paused']);
        $before = $execution->fresh()->getAttributes();
        $this->runnerNever();

        $this->job($tenant, $user, $project, $execution)->handle(app(GeoAnalysisController::class));

        $this->assertSame($before, $execution->fresh()->getAttributes());
        $this->assertSame(0, ModelCallLog::withoutGlobalScopes()->count());
    }

    public function test_paused_tenant_does_not_start_pending_model_work(): void
    {
        [$tenant, $user, $project, $execution] = $this->fixture();
        $tenant->update(['status' => 'paused']);
        $this->runnerNever();

        $this->job($tenant, $user, $project, $execution)->handle(app(GeoAnalysisController::class));

        $this->assertSame('failed', $execution->fresh()->status);
        $this->assertSame(0, ModelCallLog::withoutGlobalScopes()->count());
        $this->assertNull(TenantContext::id());
    }

    public function test_failure_callback_finalizes_running_execution_without_losing_prior_evidence(): void
    {
        [$tenant, $user, $project, $execution] = $this->fixture('running');
        $execution->update(['output_data' => ['checkpoint' => 'retained'], 'token_usage' => 30]);
        $this->withTenant($tenant, fn () => $this->recordUsage($execution));

        $this->job($tenant, $user, $project, $execution)->failed(new \RuntimeException('worker api_key=sk-test-41c78ed8722bc9f420a4f'));

        $fresh = $execution->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertSame(['checkpoint' => 'retained'], $fresh->output_data);
        $this->assertSame(30, $fresh->token_usage);
        $this->assertStringNotContainsString('sk-test-e0531d5f3e', $fresh->error_message);
        $this->assertSame(1, ModelCallLog::withoutGlobalScopes()->count());
        $this->assertNull(TenantContext::id());
    }

    public function test_worker_exception_preserves_prepared_analysis_model_attempt_for_original_request_review(): void
    {
        [$tenant, $user, $project, $execution] = $this->fixture();
        $checkpoint = [
            'version' => 1, 'kind' => 'model_attempt_prepared',
            'gateway' => ['attribution' => [
                'client_request_id' => 'analysis-original-model-attempt',
                'task_id' => 'skill:'.$execution->id,
                'feature_id' => Project::GEO_ANALYSIS_SKILL_ID,
                'step_id' => 'single:primary',
            ]],
        ];
        $this->runnerOnce(function () use ($execution, $checkpoint): never {
            $execution->fresh()->update(['billing_pending_result' => $checkpoint]);
            throw new \RuntimeException('Gateway response was lost');
        });

        $job = $this->job($tenant, $user, $project, $execution);
        $controller = app(GeoAnalysisController::class);
        $job->handle($controller);
        $first = $execution->fresh()->getAttributes();
        $job->handle($controller);
        $job->failed(new \RuntimeException('Late queue callback'));

        $this->assertSame($first, $execution->fresh()->getAttributes());
        $this->assertSame('billing_pending', $execution->fresh()->status);
        $this->assertSame($checkpoint, $execution->fresh()->billing_pending_result);
        $this->assertSame([], $execution->fresh()->output_data ?? []);
        $this->assertSame(0, ModelCallLog::withoutGlobalScopes()->count());
        $this->assertSame(0, Article::withoutGlobalScopes()->count());
        $this->assertNull(TenantContext::id());
    }

    #[DataProvider('analysisPreparedCheckpointKinds')]
    public function test_failure_callback_preserves_prepared_analysis_attempt(string $kind): void
    {
        [$tenant, $user, $project, $execution] = $this->fixture('running');
        $checkpoint = ['version' => 1, 'kind' => $kind,
            'gateway' => ['attribution' => ['client_request_id' => 'analysis-original-attempt']]];
        $execution->update(['billing_pending_result' => $checkpoint]);

        $this->job($tenant, $user, $project, $execution)->failed(new \RuntimeException('Worker stopped'));

        $this->assertSame('billing_pending', $execution->fresh()->status);
        $this->assertSame($checkpoint, $execution->fresh()->billing_pending_result);
        $this->assertSame(0, Article::withoutGlobalScopes()->count());
        $this->assertNull(TenantContext::id());
    }

    public static function analysisPreparedCheckpointKinds(): array
    {
        return [['model_attempt_prepared'], ['embedding_attempt_prepared'], ['embedding_result_recorded']];
    }

    public function test_cancellation_during_legacy_model_call_retains_usage_without_publishing_report(): void
    {
        config(['geo.analysis_pipeline_mode' => 'legacy']);
        [$tenant, $user, $project, $execution] = $this->fixture();
        $provider = $this->createMock(AiProviderServiceImpl::class);
        $provider->expects($this->once())->method('generateStructured')->willReturnCallback(function () use ($execution) {
            $execution->update(['status' => 'cancelled', 'error_message' => '用户手动取消']);

            return $this->modelResult();
        });
        $this->app->instance(AiProviderServiceImpl::class, $provider);

        $this->job($tenant, $user, $project, $execution)->handle(app(GeoAnalysisController::class));

        $this->assertSame('cancelled', $execution->fresh()->status);
        $this->assertSame('用户手动取消', $execution->fresh()->error_message);
        $this->assertSame(1, ModelCallLog::withoutGlobalScopes()->count());
        $this->assertSame(30, ModelCallLog::withoutGlobalScopes()->sum('total_tokens'));
        $this->assertSame(0, Article::withoutGlobalScopes()->count());
    }

    public function test_legacy_model_failure_does_not_overwrite_cancellation(): void
    {
        config(['geo.analysis_pipeline_mode' => 'legacy']);
        [$tenant, $user, $project, $execution] = $this->fixture();
        $provider = $this->createMock(AiProviderServiceImpl::class);
        $provider->expects($this->once())->method('generateStructured')->willReturnCallback(function () use ($execution) {
            $execution->update(['status' => 'cancelled', 'error_message' => '用户手动取消']);

            return AiProviderCallResult::failure('mock', 'mock-analysis', 'model timed out');
        });
        $this->app->instance(AiProviderServiceImpl::class, $provider);

        $this->job($tenant, $user, $project, $execution)->handle(app(GeoAnalysisController::class));

        $this->assertSame('cancelled', $execution->fresh()->status);
        $this->assertSame('用户手动取消', $execution->fresh()->error_message);
        $this->assertSame('skill:'.$execution->id,
            data_get($execution->fresh()->billing_pending_result, 'gateway.attribution.task_id'));
        $this->assertSame(0, ModelCallLog::withoutGlobalScopes()->count());
        $this->assertSame(0, Article::withoutGlobalScopes()->count());
    }

    public function test_legacy_analysis_saves_original_attempt_before_model_send(): void
    {
        config(['geo.analysis_pipeline_mode' => 'legacy']);
        [$tenant, $user, $project, $execution] = $this->fixture();
        $provider = $this->createMock(AiProviderServiceImpl::class);
        $provider->expects($this->once())->method('generateStructured')
            ->willReturnCallback(function ($prompt, $schema, $system, $options) use ($execution): AiProviderCallResult {
                $prepared = $execution->fresh()->billing_pending_result;
                $this->assertSame('model_attempt_prepared', $prepared['kind']);
                $this->assertSame($options['client_request_id'],
                    $prepared['gateway']['attribution']['client_request_id']);
                $this->assertSame('skill:'.$execution->id, $options['task_id']);
                $this->assertSame('legacy_geo_analysis', $prepared['delivery_context']['path']);

                return $this->modelResult();
            });
        $this->app->instance(AiProviderServiceImpl::class, $provider);

        $this->job($tenant, $user, $project, $execution)->handle(app(GeoAnalysisController::class));

        $this->assertSame('completed', $execution->fresh()->status);
        $this->assertNull($execution->fresh()->billing_pending_result);
        $this->assertSame(1, ModelCallLog::withoutGlobalScopes()->count());
        $this->assertSame(1, Article::withoutGlobalScopes()->count());
    }

    public function test_legacy_local_pre_send_rejection_clears_prepared_attempt(): void
    {
        config(['geo.analysis_pipeline_mode' => 'legacy']);
        [$tenant, $user, $project, $execution] = $this->fixture();
        $provider = $this->createMock(AiProviderServiceImpl::class);
        $provider->expects($this->once())->method('generateStructured')->willReturn(
            AiProviderCallResult::failure('gateway', 'text', 'No key', ['pre_send_rejection' => true]),
        );
        $this->app->instance(AiProviderServiceImpl::class, $provider);

        $this->job($tenant, $user, $project, $execution)->handle(app(GeoAnalysisController::class));

        $this->assertSame('failed', $execution->fresh()->status);
        $this->assertNull($execution->fresh()->billing_pending_result);
        $this->assertSame(0, ModelCallLog::withoutGlobalScopes()->count());
    }

    public function test_legacy_unparseable_paid_response_records_supplier_usage_and_fails_without_report(): void
    {
        config(['geo.analysis_pipeline_mode' => 'legacy']);
        [$tenant, $user, $project, $execution] = $this->fixture();
        $provider = $this->createMock(AiProviderServiceImpl::class);
        $provider->expects($this->once())->method('generateStructured')
            ->willReturnCallback(fn ($prompt, $schema, $system, $options): AiProviderCallResult =>
                new AiProviderCallResult(
                    success: false, provider: 'gateway', model: 'text', content: 'invalid JSON',
                    promptTokens: 10, completionTokens: 5, totalTokens: 15,
                    errorMessage: 'LLM 返回内容无法解析为有效 JSON',
                    gateway: ['status' => 'completed', 'http_status' => 200, 'request_id' => 'legacy-invalid-paid',
                        'attribution' => ['client_request_id' => $options['client_request_id']]],
                ));
        $this->app->instance(AiProviderServiceImpl::class, $provider);

        $this->job($tenant, $user, $project, $execution)->handle(app(GeoAnalysisController::class));

        $this->assertSame('failed', $execution->fresh()->status);
        $this->assertNull($execution->fresh()->billing_pending_result);
        $this->assertSame(15, $execution->fresh()->token_usage);
        $this->assertSame('legacy-invalid-paid', ModelCallLog::withoutGlobalScopes()->sole()->gateway_request_id);
        $this->assertSame(0, Article::withoutGlobalScopes()->count());
    }

    public function test_legacy_unknown_attempt_stays_pending_without_model_replay(): void
    {
        config(['geo.analysis_pipeline_mode' => 'legacy']);
        [$tenant, $user, $project, $execution] = $this->fixture();
        $provider = $this->createMock(AiProviderServiceImpl::class);
        $provider->expects($this->once())->method('generateStructured')
            ->willReturnCallback(fn ($prompt, $schema, $system, $options): AiProviderCallResult =>
                AiProviderCallResult::failure('custom', 'test-text', '结果未知，可手动重新执行', [
                    'status' => 'unknown', 'provider' => 'custom', 'model' => 'test-text',
                    'request_id' => 'test-legacy-original',
                    'attribution' => ['client_request_id' => $options['client_request_id'],
                        'task_id' => $options['task_id'], 'feature_id' => $options['feature_id'], 'step_id' => $options['step_id']],
                ]));
        $this->app->instance(AiProviderServiceImpl::class, $provider);
        $this->job($tenant, $user, $project, $execution)->handle(app(GeoAnalysisController::class));
        $this->assertSame('billing_pending', $execution->fresh()->status);
        $this->assertSame('model_attempt_unknown', data_get($execution->fresh()->billing_pending_result, 'kind'));
        foreach ([false, true] as $apply) {
            $this->assertSame('unknown_manual_review', app(UnknownModelAttemptRecovery::class)->recover($execution->id, $apply)['status']);
        }
        $this->assertSame('billing_pending', $execution->fresh()->status);
        $this->assertSame('unknown', ModelCallLog::withoutGlobalScopes()->sole()->status);
        $this->assertNull(ModelCallLog::withoutGlobalScopes()->sole()->total_tokens);
        $this->assertSame(0, Article::withoutGlobalScopes()->count());
    }

    public function test_legacy_saved_result_recovers_original_report_without_model_replay(): void
    {
        config(['geo.analysis_pipeline_mode' => 'legacy']);
        [$tenant, $user, $project, $execution] = $this->fixture();
        $this->withTenant($tenant, function () use ($execution): void {
            $brief = new SkillBrief(skillId: $execution->skill_id, projectId: $execution->project_id,
                userId: $execution->user_id, executionId: $execution->id);
            $content = json_encode(['summary' => 'Recovered original analysis', 'key_findings' => ['Original evidence'],
                'recommendations' => ['Review'], 'next_actions' => ['Act']]);
            (new \App\Services\Billing\UnrecordedModelResult(new AiProviderCallResult(
                success: true, provider: 'custom', model: 'test-text', content: $content,
                promptTokens: 10, completionTokens: 20, totalTokens: 30,
                gateway: ['request_id' => 'test-legacy-saved', 'status' => 'completed',
                    'attribution' => ['task_id' => 'skill:'.$execution->id, 'feature_id' => 'geo-analysis', 'client_request_id' => 'test-saved']],
            ), [], new \RuntimeException('local write failed')))->withDeliveryContext(['path' => 'legacy_geo_analysis'])->hold($execution, $brief->toArray());
        });
        $provider = $this->createMock(AiProviderServiceImpl::class);
        $provider->expects($this->never())->method('generateStructured');
        $this->app->instance(AiProviderServiceImpl::class, $provider);
        $recovery = app(UnknownModelAttemptRecovery::class);
        $this->assertSame('ready_for_usage_recovery', $recovery->recover($execution->id)['status']);
        $this->assertSame('delivery_completed', $recovery->recover($execution->id, true)['status']);
        $this->assertSame('completed', $execution->fresh()->status);
        $this->assertSame('Recovered original analysis', $execution->fresh()->output_data['analysis']['summary']);
        $this->assertSame(1, ModelCallLog::withoutGlobalScopes()->count());
        $this->assertSame(1, Article::withoutGlobalScopes()->count());
        $this->assertNull($execution->fresh()->billing_pending_result);
        $this->assertSame('not_applicable', $recovery->recover($execution->id, true)['status']);
    }

    public function test_cancellation_after_full_runner_returns_wins_against_result_write(): void
    {
        [$tenant, $user, $project, $execution] = $this->fixture();
        $this->runnerOnce(function () use ($execution) {
            $this->recordUsage($execution);

            return $this->success($execution);
        });
        $extractor = $this->createMock(GeoAnalysisSummaryExtractor::class);
        $extractor->expects($this->once())->method('extract')->willReturnCallback(function () use ($execution) {
            // This occurs after the controller's first cancellation read and before its final write.
            $execution->update(['status' => 'cancelled', 'error_message' => '用户手动取消']);

            return ['summary' => 'Completed model work', 'key_findings' => [], 'recommendations' => [], 'next_actions' => [], 'overall_score' => null];
        });
        $this->app->instance(GeoAnalysisSummaryExtractor::class, $extractor);

        $this->job($tenant, $user, $project, $execution)->handle(app(GeoAnalysisController::class));

        $this->assertSame('cancelled', $execution->fresh()->status);
        $this->assertSame('用户手动取消', $execution->fresh()->error_message);
        $this->assertSame(1, ModelCallLog::withoutGlobalScopes()->count());
        $this->assertSame(0, Article::withoutGlobalScopes()->count());
    }

    #[DataProvider('runnerOutcomes')]
    public function test_full_runner_cancellation_preserves_already_recorded_usage_and_checkpoint(bool $success): void
    {
        [$tenant, $user, $project, $execution] = $this->fixture();
        $this->runnerOnce(function () use ($execution, $success) {
            $this->recordUsage($execution);
            $execution->fresh()->update([
                'status' => 'cancelled',
                'error_message' => '用户手动取消',
                'token_usage' => 30,
                'output_data' => ['checkpoint' => 'Completed section body'],
            ]);

            return $success ? $this->success($execution) : SkillExecutionResult::failure($execution->skill_id, 'Cancelled at section boundary');
        });

        $this->job($tenant, $user, $project, $execution)->handle(app(GeoAnalysisController::class));

        $fresh = $execution->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame('用户手动取消', $fresh->error_message);
        $this->assertSame(['checkpoint' => 'Completed section body'], $fresh->output_data);
        $this->assertSame(30, $fresh->token_usage);
        $this->assertSame(1, ModelCallLog::withoutGlobalScopes()->count());
        $this->assertSame(0, Article::withoutGlobalScopes()->count());
    }

    public static function runnerOutcomes(): array
    {
        return [['success' => true], ['success' => false]];
    }

    public function test_invalid_structured_result_keeps_usage_and_raw_diagnostics_without_publishing_report(): void
    {
        [$tenant, $user, $project, $execution] = $this->fixture();
        $this->runnerOnce(function () use ($execution) {
            $this->recordUsage($execution);

            return new SkillExecutionResult(
                executionId: $execution->id,
                skillId: Project::GEO_ANALYSIS_SKILL_ID,
                success: true,
                intermediateData: ['pipeline_mode' => 'full', 'structured' => null, 'raw_output' => '{"incomplete":'],
                tokenUsage: 30,
                lastLlmResult: $this->modelResult(),
            );
        });

        $this->job($tenant, $user, $project, $execution)->handle(app(GeoAnalysisController::class));

        $fresh = $execution->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertFalse($fresh->quality_report['passed']);
        $this->assertSame('{"incomplete":', $fresh->output_data['failure_diagnostics']['raw_output']);
        $this->assertSame(30, $fresh->output_data['model_usage']['total_tokens']);
        $this->assertSame(1, ModelCallLog::withoutGlobalScopes()->count());
        $this->assertSame(0, Article::withoutGlobalScopes()->count());
    }

    public function test_failed_result_merges_diagnostics_with_fresh_runner_checkpoint(): void
    {
        [$tenant, $user, $project, $execution] = $this->fixture();
        $this->runnerOnce(function () use ($execution) {
            $this->recordUsage($execution);
            $execution->fresh()->update(['output_data' => ['checkpoint' => ['completed_section' => 'evidence']]]);

            return new SkillExecutionResult(
                executionId: $execution->id,
                skillId: Project::GEO_ANALYSIS_SKILL_ID,
                success: false,
                intermediateData: ['raw_output' => 'Partial evidence body', 'failed_chunks' => ['assessment']],
                tokenUsage: 30,
                errorMessage: 'Assessment failed',
            );
        });

        $this->job($tenant, $user, $project, $execution)->handle(app(GeoAnalysisController::class));

        $fresh = $execution->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertSame(['completed_section' => 'evidence'], $fresh->output_data['checkpoint'] ?? null);
        $this->assertSame('Partial evidence body', $fresh->output_data['failure_diagnostics']['raw_output']);
        $this->assertSame(['assessment'], $fresh->output_data['failure_diagnostics']['failed_chunks']);
        $this->assertSame(30, $fresh->token_usage);
        $this->assertSame(1, ModelCallLog::withoutGlobalScopes()->count());
        $this->assertSame(0, Article::withoutGlobalScopes()->count());
    }

    public function test_exception_after_recorded_usage_keeps_evidence_and_is_not_automatically_retried(): void
    {
        [$tenant, $user, $project, $execution] = $this->fixture();
        $this->runnerOnce(function () use ($execution): never {
            $this->recordUsage($execution);
            $execution->fresh()->update(['output_data' => ['checkpoint' => 'model body'], 'token_usage' => 30]);
            throw new \RuntimeException('render api_key=sk-test-41c78ed8722bc9f420a4f failed');
        });

        $job = $this->job($tenant, $user, $project, $execution);
        $controller = app(GeoAnalysisController::class);
        $job->handle($controller);
        $first = $execution->fresh()->getAttributes();
        $job->handle($controller);
        $job->failed(new \RuntimeException('Later queue callback'));

        $this->assertSame($first, $execution->fresh()->getAttributes());
        $this->assertSame('failed', $execution->fresh()->status);
        $this->assertSame(['checkpoint' => 'model body'], $execution->fresh()->output_data);
        $this->assertStringNotContainsString('sk-test-e0531d5f3e', $execution->fresh()->error_message);
        $this->assertSame(1, ModelCallLog::withoutGlobalScopes()->count());
        $this->assertSame(0, Article::withoutGlobalScopes()->count());
        $this->assertNull(BillingContext::userId());
        $this->assertNull(TenantContext::id());
    }

    public function test_user_retry_creates_new_attempt_without_mutating_failed_attempt_or_usage(): void
    {
        config(['geo.analysis_async' => true]);
        Queue::fake();
        [$tenant, $user, $project, $execution] = $this->fixture('failed');
        $execution->update(['output_data' => ['checkpoint' => 'Original partial body'], 'token_usage' => 30]);
        $this->withTenant($tenant, fn () => $this->recordUsage($execution));
        TenantAiKey::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'gateway',
            'encrypted_key' => 'sk-test-fake-69624a1',
            'is_active' => true,
        ]);
        $before = $execution->fresh()->getAttributes();
        Sanctum::actingAs($user, ['*']);

        $response = $this->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain)
            ->postJson("/api/v1/projects/{$project->id}/analyses", ['notes' => 'Retry as a new attempt'])
            ->assertStatus(202)
            ->assertJsonPath('analysis.status', 'pending');

        $this->assertNotSame($execution->id, $response->json('analysis.id'));
        $this->assertSame($before, $execution->fresh()->getAttributes());
        $this->assertSame(2, SkillExecution::withoutGlobalScopes()->count());
        $this->assertSame(1, ModelCallLog::withoutGlobalScopes()->count());
        Queue::assertPushed(RunGeoAnalysisJob::class, 1);
        Queue::assertPushed(RunGeoAnalysisJob::class, fn ($job) => $job->executionId === $response->json('analysis.id'));
    }

    #[DataProvider('reportFailures')]
    public function test_report_asset_failure_rolls_back_article_and_completion_but_keeps_usage_and_output(string $mode, string $method): void
    {
        config(['geo.analysis_pipeline_mode' => $mode]);
        [$tenant, $user, $project, $execution] = $this->fixture();
        Storage::disk('public')->put("executions/{$execution->id}/reliability.md", '# Isolated report');
        if ($mode === 'legacy') {
            $provider = $this->createMock(AiProviderServiceImpl::class);
            $provider->expects($this->once())->method('generateStructured')->willReturn($this->modelResult());
            $this->app->instance(AiProviderServiceImpl::class, $provider);
        } else {
            $this->runnerOnce(function () use ($execution) {
                $this->recordUsage($execution);

                return $this->success($execution);
            });
        }
        // Exercise both creation failure and a throw after the real Article was saved.
        // The latter verifies rollback of partial writes, not only a pre-write exception.
        $assets = $this->getMockBuilder(ReportAssetService::class)->onlyMethods([$method])->getMock();
        $assets->expects($this->once())->method($method)->willReturnCallback(function () use ($execution, $method): never {
            $this->assertSame($method === 'outputDataWithArticle' ? 1 : 0, Article::query()->where('skill_execution_id', $execution->id)->count());
            throw new \RuntimeException('Report asset api_key=sk-test-41c78ed8722bc9f420a4f failed');
        });
        $this->app->instance(ReportAssetService::class, $assets);

        $job = $this->job($tenant, $user, $project, $execution);
        $controller = app(GeoAnalysisController::class);
        $job->handle($controller);
        $first = $execution->fresh()->getAttributes();
        $job->handle($controller);

        $fresh = $execution->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertSame($first, $fresh->getAttributes());
        $this->assertNull($fresh->article_id);
        $this->assertSame(0, Article::withoutGlobalScopes()->count());
        $this->assertSame(1, ModelCallLog::withoutGlobalScopes()->count());
        $this->assertSame(30, $fresh->token_usage);
        $this->assertSame(30, $fresh->output_data['model_usage']['total_tokens']);
        $this->assertSame('Completed model work', $fresh->output_data['analysis']['summary']);
        $this->assertStringContainsString('Report asset', $fresh->error_message);
        $this->assertStringNotContainsString('sk-test-e0531d5f3e', $fresh->error_message);
        $this->assertSame('# Isolated report', Storage::disk('public')->get("executions/{$execution->id}/reliability.md"));
    }

    public static function reportFailures(): array
    {
        return [
            ['legacy', 'createForGeoAnalysis'],
            ['legacy', 'outputDataWithArticle'],
            ['full', 'createForGeoAnalysis'],
            ['full', 'outputDataWithArticle'],
        ];
    }

    private function fixture(string $status = 'pending'): array
    {
        $tenant = Tenant::query()->create(['subdomain' => 'analysis-reliability', 'name' => 'Analysis', 'plan' => 'basic', 'status' => 'active']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);

        return $this->withTenant($tenant, function () use ($tenant, $user, $status) {
            $project = Project::query()->create(['owner_id' => $user->id, 'name' => 'Analysis fixture', 'brand_name' => 'Isolated brand']);
            $execution = SkillExecution::query()->create([
                'project_id' => $project->id,
                'user_id' => $user->id,
                'skill_id' => Project::GEO_ANALYSIS_SKILL_ID,
                'skill_name' => 'GEO analysis',
                'category' => 'geo-strategy',
                'stage_index' => 1,
                'status' => $status,
                'input_data' => [],
            ]);

            return [$tenant, $user, $project, $execution];
        });
    }

    private function withTenant(Tenant $tenant, callable $callback): mixed
    {
        TenantContext::set($tenant);
        try {
            return $callback();
        } finally {
            TenantContext::forget();
        }
    }

    private function job(Tenant $tenant, User $user, Project $project, SkillExecution $execution): RunGeoAnalysisJob
    {
        return new RunGeoAnalysisJob($execution->id, $project->id, $user->id, ['brand_name' => 'Isolated brand'], $tenant->id);
    }

    private function runnerOnce(callable $callback): void
    {
        $runner = $this->createMock(SkillAgentRunner::class);
        $runner->expects($this->once())->method('execute')->willReturnCallback($callback);
        $this->app->instance(SkillAgentRunner::class, $runner);
    }

    private function runnerNever(): void
    {
        $runner = $this->createMock(SkillAgentRunner::class);
        $runner->expects($this->never())->method('execute');
        $this->app->instance(SkillAgentRunner::class, $runner);
    }

    private function recordUsage(SkillExecution $execution): ModelCallLog
    {
        return app(ModelUsageRecorder::class)->record($execution, $this->modelResult(), ['source' => 'isolated_analysis_regression']);
    }

    private function modelResult(): AiProviderCallResult
    {
        return new AiProviderCallResult(
            success: true,
            provider: 'mock',
            model: 'mock-analysis',
            content: 'Completed model work',
            structuredOutput: ['summary' => 'Completed model work', 'key_findings' => ['Evidence'], 'recommendations' => ['Verify'], 'next_actions' => ['Review']],
            promptTokens: 10,
            completionTokens: 20,
            totalTokens: 30,
            durationSeconds: 1.0,
        );
    }

    private function success(SkillExecution $execution): SkillExecutionResult
    {
        return new SkillExecutionResult(
            executionId: $execution->id,
            skillId: Project::GEO_ANALYSIS_SKILL_ID,
            success: true,
            outputPaths: ['markdown' => "executions/{$execution->id}/reliability.md"],
            intermediateData: ['pipeline_mode' => 'full', 'structured' => ['summary' => 'Completed model work']],
            tokenUsage: 30,
            lastLlmResult: $this->modelResult(),
        );
    }
}
