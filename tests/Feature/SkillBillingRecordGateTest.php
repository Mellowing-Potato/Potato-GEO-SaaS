<?php

namespace Tests\Feature;

use App\Jobs\ExecuteSkillJob;
use App\Models\Article;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\ModelUsageRecorder;
use App\Services\Billing\EmbeddingUsageRecorder;
use App\Services\Billing\UnresolvedModelAttempt;
use App\Services\Billing\UnrecordedModelResult;
use App\Services\Contracts\AiProvider;
use App\Services\Contracts\KnowledgeRetrieval;
use App\Services\DTOs\AiProviderCallResult;
use App\Services\DTOs\SkillBrief;
use App\Services\DTOs\SkillExecutionResult;
use App\Services\SkillAgent\SkillChunkAssembly;
use App\Services\SkillAgent\SkillAssets;
use App\Services\SkillAgent\SkillModelCaller;
use App\Services\SkillAgent\SkillSingleCallRunner;
use App\Services\SkillAgent\SkillRunPreparer;
use App\Services\Contracts\SkillRegistry;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class SkillBillingRecordGateTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    public function test_paid_retrieval_rejects_missing_and_mismatched_execution_before_embedding(): void
    {
        [$valid] = $this->paidExecution();
        $retrieval = $this->createMock(KnowledgeRetrieval::class);
        $retrieval->expects($this->never())->method('retrieve');
        $this->app->instance(KnowledgeRetrieval::class, $retrieval);

        foreach ([[null, $valid->userId], [$valid->executionId, $valid->userId + 1]] as [$executionId, $userId]) {
            $brief = new SkillBrief($valid->skillId, $valid->projectId, $userId,
                query: 'paid retrieval must not escape', executionId: $executionId);
            try {
                app(SkillRunPreparer::class)->prepare($brief);
                $this->fail('An unowned retrieval must be rejected before embedding.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('running durable skill execution', $e->getMessage());
            }
        }
    }


    public function test_chunk_model_result_cannot_pass_a_failed_paid_usage_record(): void
    {
        [$brief, $result] = $this->paidExecution();
        $provider = $this->createMock(AiProvider::class);
        $provider->expects($this->once())->method('generate')->willReturn($result);
        $recorder = $this->createMock(ModelUsageRecorder::class);
        $recorder->expects($this->once())->method('record')
            ->willThrowException(new RuntimeException('isolated database write failure'));
        $caller = new SkillModelCaller($provider, $recorder, app(SkillChunkAssembly::class));

        try {
            $caller->generateAndRecord('prompt', 'system', 128, 'text', $brief);
            $this->fail('A paid model result must not escape without its usage record.');
        } catch (UnrecordedModelResult $e) {
            $this->assertStringContainsString('暂缓交付', $e->getMessage());
            $e->hold(SkillExecution::findOrFail($brief->executionId));
        }
        $this->assertSame(0, Article::query()->count());
        $this->assertSame('model_result_unrecorded', data_get(
            SkillExecution::findOrFail($brief->executionId)->billing_pending_result, 'kind'));
    }

    public function test_single_call_result_cannot_pass_a_failed_paid_usage_record(): void
    {
        [$brief, $result] = $this->paidExecution();
        $recorder = $this->createMock(ModelUsageRecorder::class);
        $recorder->expects($this->once())->method('record')
            ->willThrowException(new RuntimeException('isolated database write failure'));
        $this->app->instance(ModelUsageRecorder::class, $recorder);
        $runner = app(SkillSingleCallRunner::class);
        $recordUsage = new ReflectionMethod(SkillSingleCallRunner::class, 'recordUsage');

        try {
            $recordUsage->invoke($runner, $brief, $result, ['attempt' => 1]);
            $this->fail('A paid model result must not continue to article creation.');
        } catch (UnrecordedModelResult $e) {
            $this->assertStringContainsString('暂缓交付', $e->getMessage());
            $e->hold(SkillExecution::findOrFail($brief->executionId));
        }
        $this->assertSame(0, Article::query()->count());
        $this->assertSame('model_result_unrecorded', data_get(
            SkillExecution::findOrFail($brief->executionId)->billing_pending_result, 'kind'));
    }

    public function test_single_call_saves_delivery_context_when_usage_write_fails(): void
    {
        [$brief, $result] = $this->paidExecution();
        $provider = $this->createMock(AiProvider::class);
        $provider->expects($this->once())->method('generate')->willReturn($result);
        $recorder = $this->createMock(ModelUsageRecorder::class);
        $recorder->expects($this->once())->method('record')
            ->willThrowException(new RuntimeException('isolated usage store failure'));
        $this->app->instance(AiProvider::class, $provider);
        $this->app->instance(ModelUsageRecorder::class, $recorder);

        try {
            app(SkillSingleCallRunner::class)->run($brief);
            $this->fail('The successful model result must remain sealed.');
        } catch (UnrecordedModelResult $e) {
            $e->hold(SkillExecution::findOrFail($brief->executionId), $brief->toArray());
        }

        $saved = SkillExecution::findOrFail($brief->executionId);
        $this->assertSame('model_result_unrecorded', data_get($saved->billing_pending_result, 'kind'));
        $this->assertSame('single', data_get($saved->billing_pending_result, 'delivery_context.path'));
        $this->assertSame(1, data_get($saved->billing_pending_result, 'delivery_context.attempt'));
        $this->assertSame(64, strlen(data_get($saved->billing_pending_result, 'delivery_context.asset_fingerprint')));
        $this->assertEmpty($saved->output_data);
        $this->assertSame(0, Article::query()->count());
    }

    public function test_single_call_persists_gateway_identity_before_request_and_holds_unexpected_failure(): void
    {
        [$brief] = $this->paidExecution();
        $provider = $this->createMock(AiProvider::class);
        $provider->expects($this->once())->method('generate')
            ->willReturnCallback(function ($prompt, $system, $options) use ($brief) {
                $snapshot = SkillExecution::findOrFail($brief->executionId)->billing_pending_result;
                $this->assertSame('model_attempt_prepared', $snapshot['kind']);
                $this->assertSame($options['client_request_id'], data_get($snapshot, 'gateway.attribution.client_request_id'));
                $this->assertSame('single:primary', data_get($snapshot, 'gateway.attribution.step_id'));
                $this->assertSame('single', data_get($snapshot, 'delivery_context.path'));
                $this->assertSame($brief->toArray(), $snapshot['brief_data']);

                throw new RuntimeException('process failed after request preparation');
            });
        $this->app->instance(AiProvider::class, $provider);

        try {
            app(SkillSingleCallRunner::class)->run($brief);
            $this->fail('An uncertain attempt must stay sealed.');
        } catch (UnresolvedModelAttempt $e) {
            $e->hold(SkillExecution::findOrFail($brief->executionId), $brief->toArray());
        }

        $saved = SkillExecution::findOrFail($brief->executionId);
        $this->assertSame('billing_pending', $saved->status);
        $this->assertSame('model_attempt_unknown', data_get($saved->billing_pending_result, 'kind'));
        $this->assertSame(0, Article::query()->count());
    }

    public function test_queue_failure_callback_preserves_prepared_attempt_for_reconciliation(): void
    {
        [$brief] = $this->paidExecution();
        $execution = SkillExecution::findOrFail($brief->executionId);
        $execution->update(['billing_pending_result' => [
            'version' => 1, 'kind' => 'model_attempt_prepared',
            'gateway' => ['attribution' => ['client_request_id' => 'saved-original']],
        ]]);

        (new ExecuteSkillJob($execution->id, $brief->toArray(), $execution->tenant_id))
            ->failed(new RuntimeException('worker died'));

        $saved = $execution->fresh();
        $this->assertSame('billing_pending', $saved->status);
        $this->assertSame('saved-original', data_get($saved->billing_pending_result,
            'gateway.attribution.client_request_id'));
        $this->assertStringContainsString('请勿重新提交', $saved->error_message);
    }

    public function test_chunk_call_persists_original_gateway_identity_before_network_send(): void
    {
        [$brief] = $this->paidExecution();
        $provider = $this->createMock(AiProvider::class);
        $provider->expects($this->once())->method('generate')
            ->willReturnCallback(function ($prompt, $system, $options) use ($brief) {
                $snapshot = SkillExecution::findOrFail($brief->executionId)->billing_pending_result;
                $this->assertSame('model_attempt_prepared', $snapshot['kind']);
                $this->assertSame($options['client_request_id'], data_get($snapshot, 'gateway.attribution.client_request_id'));
                $this->assertSame('model:opening:attempt:1', data_get($snapshot, 'gateway.attribution.step_id'));
                $this->assertSame('chunk', data_get($snapshot, 'delivery_context.path'));

                throw new RuntimeException('HTTP request died before its response');
            });
        $caller = new SkillModelCaller($provider, app(ModelUsageRecorder::class), app(SkillChunkAssembly::class));

        try {
            $caller->generateAndRecord('prompt', 'system', 128, 'text', $brief, ['chunk' => 'opening']);
            $this->fail('The original chunk attempt must be reconciled.');
        } catch (UnresolvedModelAttempt $e) {
            $e->hold(SkillExecution::findOrFail($brief->executionId), $brief->toArray());
        }

        $saved = SkillExecution::findOrFail($brief->executionId);
        $this->assertSame('billing_pending', $saved->status);
        $this->assertSame('model_attempt_unknown', data_get($saved->billing_pending_result, 'kind'));
        $this->assertSame('opening', data_get($saved->billing_pending_result, 'record_metadata.chunk'));
        $this->assertSame('chunk', data_get($saved->billing_pending_result, 'delivery_context.path'));
    }

    public function test_chunk_call_does_not_send_when_preparation_cannot_be_saved(): void
    {
        [$brief] = $this->paidExecution();
        SkillExecution::findOrFail($brief->executionId)->update(['status' => 'pending']);
        $provider = $this->createMock(AiProvider::class);
        $provider->expects($this->never())->method('generate');
        $caller = new SkillModelCaller($provider, app(ModelUsageRecorder::class), app(SkillChunkAssembly::class));

        $this->expectException(RuntimeException::class);
        $caller->generateAndRecord('prompt', 'system', 128, 'text', $brief, ['chunk' => 'opening']);
    }

    public function test_successful_gateway_result_stays_sealed_when_customer_debit_is_disabled_but_usage_write_fails(): void
    {
        [$brief, $result] = $this->paidExecution();

        $provider = $this->createMock(AiProvider::class);
        $provider->expects($this->once())->method('generate')->willReturn($result);
        $recorder = $this->createMock(ModelUsageRecorder::class);
        $recorder->expects($this->once())->method('record')
            ->willThrowException(new RuntimeException('local usage store unavailable'));

        try {
            (new SkillModelCaller($provider, $recorder, app(SkillChunkAssembly::class)))
                ->generateAndRecord('prompt', 'system', 128, 'text', $brief);
            $this->fail('A successful paid gateway result must keep its usage record.');
        } catch (UnrecordedModelResult $e) {
            $e->hold(SkillExecution::findOrFail($brief->executionId));
        }

        $saved = SkillExecution::findOrFail($brief->executionId);
        $this->assertSame('billing_pending', $saved->status);
        $this->assertSame('model_result_unrecorded', data_get($saved->billing_pending_result, 'kind'));
        $this->assertSame(0, Article::query()->count());
    }

    public function test_pending_gateway_attempt_stops_chunk_fallback_before_false_waiver(): void
    {
        [$brief] = $this->paidExecution();
        $pending = AiProviderCallResult::failure('gateway', 'text', 'pending', [
            'http_status' => 202,
            'request_id' => 'gateway-pending-'.$brief->executionId,
            'attribution' => ['client_request_id' => 'original-attempt'],
        ]);
        $provider = $this->createMock(AiProvider::class);
        $provider->expects($this->once())->method('generate')->willReturn($pending);
        $recorder = $this->createMock(ModelUsageRecorder::class);
        $recorder->expects($this->never())->method('record');
        $caller = new SkillModelCaller($provider, $recorder, app(SkillChunkAssembly::class));

        try {
            $caller->generateAndRecord('prompt', 'system', 128, 'text', $brief);
            $this->fail('A pending gateway attempt must not create a fallback attempt.');
        } catch (UnresolvedModelAttempt $e) {
            $e->hold(SkillExecution::findOrFail($brief->executionId));
        }

        $execution = SkillExecution::findOrFail($brief->executionId);
        $this->assertSame('billing_pending', $execution->status);
        $this->assertSame('model_attempt_unknown', $execution->billing_pending_result['kind']);
        $this->assertSame('original-attempt', data_get($execution->billing_pending_result, 'gateway.attribution.client_request_id'));
        $this->assertSame('text', data_get($execution->billing_pending_result, 'requested_model'));
        $this->assertSame(1, data_get($execution->billing_pending_result, 'record_metadata.attempt'));
        $this->assertSame(0, Article::query()->count());
    }

    public function test_pending_gateway_attempt_stops_single_call_fallback(): void
    {
        [$brief] = $this->paidExecution();
        $provider = $this->createMock(AiProvider::class);
        $provider->expects($this->once())->method('generate')->willReturn(
            AiProviderCallResult::failure('gateway', 'text', 'pending', [
                'http_status' => 202, 'request_id' => 'single-pending-'.$brief->executionId,
                'attribution' => ['client_request_id' => 'single-original'],
            ]),
        );
        $preparer = $this->createMock(SkillRunPreparer::class);
        $preparer->method('deliveryFingerprint')->willReturn(str_repeat('a', 64));
        $preparer->method('prepare')->willReturn([
            'skill' => app(SkillRegistry::class)->find($brief->skillId),
            'assets' => new SkillAssets($brief->skillId, '', [], null, null, false),
            'hasRenderPipeline' => false, 'knowledgeContext' => '',
            'systemPrompt' => 'system', 'userPrompt' => 'prompt', 'chunkSpecs' => null,
            'pageObservation' => null,
        ]);
        $this->app->instance(AiProvider::class, $provider);
        $this->app->instance(SkillRunPreparer::class, $preparer);

        try {
            app(SkillSingleCallRunner::class)->run($brief);
            $this->fail('The original attempt must remain pending.');
        } catch (UnresolvedModelAttempt $e) {
            $e->hold(SkillExecution::findOrFail($brief->executionId), $brief->toArray());
        }
        $saved = SkillExecution::findOrFail($brief->executionId);
        $this->assertSame('single', data_get($saved->billing_pending_result, 'delivery_context.path'));
        $this->assertSame(str_repeat('a', 64), data_get($saved->billing_pending_result, 'delivery_context.asset_fingerprint'));
    }

    public function test_late_unknown_receipt_preserves_a_cancelled_execution(): void
    {
        [$brief] = $this->paidExecution();
        $execution = SkillExecution::findOrFail($brief->executionId);
        $execution->update(['status' => 'cancelled']);

        (new UnresolvedModelAttempt([
            'http_status' => 202, 'request_id' => 'cancelled-original',
            'attribution' => ['client_request_id' => 'cancelled-attempt'],
        ]))->hold($execution);

        $saved = $execution->fresh();
        $this->assertSame('cancelled', $saved->status);
        $this->assertSame('cancelled-original', data_get($saved->billing_pending_result, 'gateway.request_id'));
        $this->assertSame(0, Article::query()->count());
    }

    /** @return array{SkillBrief, AiProviderCallResult} */
    private function paidExecution(): array
    {

        $tenant = Tenant::query()->create([
            'subdomain' => 'skill-billing-gate-'.bin2hex(random_bytes(3)),
            'name' => 'Skill Billing Gate', 'plan' => 'basic', 'status' => 'active',
        ]);
        TenantContext::set($tenant);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $project = Project::query()->create(['owner_id' => $user->id, 'name' => 'Billing Gate']);
        $execution = SkillExecution::query()->create([
            'project_id' => $project->id, 'user_id' => $user->id,
            'skill_id' => 'yao-geo-intent-miner', 'skill_name' => 'Intent Miner',
            'category' => 'geo-research', 'status' => 'running',
            'execution_mode' => 'sync', 'input_data' => [],
        ]);

        return [
            new SkillBrief('yao-geo-intent-miner', $project->id, $user->id, executionId: $execution->id),
            new AiProviderCallResult(
                success: true, provider: 'gateway', model: 'text',
                content: 'Model output must be held.', promptTokens: 40,
                completionTokens: 20, totalTokens: 60,
                gateway: ['request_id' => 'skill-record-gate-'.$execution->id],
            ),
        ];
    }
}
