<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ModelCallLog;
use App\Models\SkillExecution;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\UnresolvedEmbeddingAttempt;
use App\Services\Billing\EmbeddingUsageRecorder;
use App\Services\Billing\PreparedEmbeddingAttempt;
use App\Services\Billing\PendingEmbeddingAttemptInspector;
use App\Services\AI\AiProviderServiceImpl;
use App\Services\Contracts\AiProvider;
use App\Services\Knowledge\KnowledgeRetrievalServiceImpl;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SkillEmbeddingCheckpointTest extends TestCase
{
    use \Tests\Support\UsesWorkspaceModels;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    public function test_pending_gateway_embedding_stops_retrieval_after_durable_intent(): void
    {
        [$execution, $user] = $this->execution();
        $provider = $this->createMock(AiProvider::class);
        $provider->expects($this->once())->method('embed')
            ->willReturnCallback(function (string $query, string $model, array $options) use ($execution) {
                $prepared = $execution->fresh()->billing_pending_result;
                $this->assertSame('embedding_attempt_prepared', $prepared['kind']);
                $this->assertSame(hash('sha256', $query), $prepared['query_hash']);
                $this->assertSame($options['client_request_id'],
                    data_get($prepared, 'gateway.attribution.client_request_id'));

                return ['embedding' => [], 'gateway' => [
                    'http_status' => 202,
                    'attribution' => ['client_request_id' => $options['client_request_id']],
                ]];
            });

        try {
            (new KnowledgeRetrievalServiceImpl($provider))->retrieve('付费检索原问题', filters: $this->filters($execution, $user));
            $this->fail('An unresolved paid vector request must not become an empty RAG context.');
        } catch (UnresolvedEmbeddingAttempt $pending) {
            $pending->hold($execution);
        }

        $held = $execution->fresh();
        $this->assertSame('billing_pending', $held->status);
        $this->assertSame('embedding_attempt_unknown', data_get($held->billing_pending_result, 'kind'));
        $this->assertSame('付费检索原问题', data_get($held->billing_pending_result, 'query'));
        $this->assertSame([], $held->output_data);
        $this->artisan('geo:inspect-pending-model-results', ['--older-than' => 0, '--json' => true])
            ->expectsOutputToContain('"checkpoint_kind":"embedding_attempt_unknown"')
            ->assertExitCode(0);
    }

    public function test_prepared_embedding_is_reaped_as_pending_after_process_death(): void
    {
        [$execution, $user] = $this->execution();
        config(['queue.default' => 'database']);
        $provider = $this->createMock(AiProvider::class);
        $provider->expects($this->once())->method('embed')->willReturnCallback(function () use ($execution) {
            $this->assertSame('embedding_attempt_prepared', data_get($execution->fresh()->billing_pending_result, 'kind'));
            throw new \RuntimeException('process lost after send');
        });

        try {
            (new KnowledgeRetrievalServiceImpl($provider))->retrieve('same request', filters: $this->filters($execution, $user));
            $this->fail('Expected a simulated post-send failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('process lost after send', $e->getMessage());
        }

        SkillExecution::whereKey($execution->id)->update(['updated_at' => now()->subMinutes(30)]);
        $this->artisan('geo:reap-stuck-executions')->assertExitCode(0);
        $held = $execution->fresh();
        $this->assertSame('billing_pending', $held->status);
        $this->assertSame('embedding_attempt_prepared', data_get($held->billing_pending_result, 'kind'));
    }

    public function test_mismatched_execution_owner_is_rejected_before_embedding(): void
    {
        [$execution, $user] = $this->execution();
        $other = User::factory()->create(['tenant_id' => $execution->tenant_id]);
        $provider = $this->createMock(AiProvider::class);
        $provider->expects($this->never())->method('embed');

        $this->expectException(\RuntimeException::class);
        (new KnowledgeRetrievalServiceImpl($provider))->retrieve('should never leave GEO',
            filters: $this->filters($execution, $other));
    }

    public function test_unsent_embedding_failure_retires_its_checkpoint(): void
    {
        [$execution, $user] = $this->execution();
        $provider = $this->createMock(AiProvider::class);
        $provider->expects($this->once())->method('embed')->willReturn([
            'embedding' => [], 'tokens' => 0, 'attempt_sent' => false,
        ]);

        $this->assertSame([], (new KnowledgeRetrievalServiceImpl($provider))->retrieve('no API key',
            filters: $this->filters($execution, $user)));
        $this->assertNull($execution->fresh()->billing_pending_result);
    }

    public function test_changed_query_cannot_reuse_the_prepared_paid_attempt(): void
    {
        [$execution, $user] = $this->execution();
        $provider = $this->createMock(AiProvider::class);
        $provider->expects($this->once())->method('embed')->willReturn(['embedding' => array_pad([0.1, 0.2], 1024, 0.0)]);
        $service = new KnowledgeRetrievalServiceImpl($provider);
        $service->retrieve('original', filters: $this->filters($execution, $user));

        $this->expectException(\RuntimeException::class);
        $service->retrieve('changed', filters: $this->filters($execution, $user));
    }

    public function test_successful_vector_is_recorded_and_reused_without_another_gateway_post(): void
    {
        [$execution, $user] = $this->execution();
        $provider = $this->createMock(AiProvider::class);
        $provider->expects($this->once())->method('embed')->willReturn([
            'embedding' => array_pad([0.125, 0.25], 1024, 0.0), 'tokens' => 3, 'gateway' => [
                'request_id' => 'original-vector', 'status' => 'completed', 'provider' => 'custom', 'model' => 'test-embedding',
            ],
        ]);
        $service = new KnowledgeRetrievalServiceImpl($provider);
        $this->assertSame([], $service->retrieve('original', filters: $this->filters($execution, $user)));
        $log = ModelCallLog::query()->where('gateway_request_id', 'original-vector')->firstOrFail();
        $this->assertSame($execution->id, $log->skill_execution_id);
        $this->assertSame('test-embedding', $log->model);
        $this->assertSame(3, $log->total_tokens);
        // 积分协议 v2：charged_fen 保留旧名，数值与 points_deducted 同为积分。
        $this->assertSame('embedding', $log->metadata['usage_kind']);
        $this->assertSame('embedding_result_recorded', data_get($execution->fresh()->billing_pending_result, 'kind'));
        $this->assertStringNotContainsString('0.125',
            (string) DB::table('skill_executions')->where('id', $execution->id)->value('billing_pending_result'));
        $this->assertSame([], $service->retrieve('original', filters: $this->filters($execution, $user)));
        $this->assertSame(1, ModelCallLog::query()->where('gateway_request_id', 'original-vector')->count());
    }

    public function test_wrong_dimension_vector_is_rejected_before_retrieval(): void
    {
        [$execution, $user] = $this->execution();
        $provider = $this->createMock(AiProvider::class);
        $provider->expects($this->once())->method('embed')->willReturn(['embedding' => [0.125, 0.25], 'tokens' => 3]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Embedding result is not a finite vector.');
        (new KnowledgeRetrievalServiceImpl($provider))->retrieve('wrong dimensions', filters: $this->filters($execution, $user));
    }

    public function test_gateway_embedding_request_cannot_be_claimed_by_another_execution(): void
    {
        [$execution] = $this->execution();
        $other = $execution->replicate();
        $other->save();
        $receipt = ['request_id' => 'one-gateway-vector', 'status' => 'completed', 'provider' => 'custom', 'model' => 'test-embedding'];
        $recorder = app(EmbeddingUsageRecorder::class);
        $recorder->record($execution, $receipt, 3);

        try {
            $recorder->record($other, $receipt, 3);
            $this->fail('A settled gateway request must have only one GEO owner.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('another usage owner', $e->getMessage());
        }
        $this->assertSame(1, ModelCallLog::query()->where('gateway_request_id', 'one-gateway-vector')->count());
    }

    public function test_settled_original_vector_can_queue_only_its_original_execution(): void
    {
        [$execution, $user] = $this->execution();
        $execution->update(['execution_mode' => 'async']);
        $query = 'original saved query';
        $this->savedVector($execution, $user, $query);
        config(['queue.default' => 'database', 'queue.connections.database.after_commit' => false]);
        $result = app(PendingEmbeddingAttemptInspector::class)->recover($execution->id);
        $this->assertSame('original_execution_queued', $result['status']);
        $this->assertSame('pending', $execution->fresh()->status);
        $this->assertSame('embedding_result_recorded', data_get($execution->fresh()->billing_pending_result, 'kind'));
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame('saved-vector', data_get($execution->fresh()->gateway_requests, '0.request_id'));
        $this->assertCount(1, $execution->fresh()->gateway_requests);
        $log = ModelCallLog::query()->where('gateway_request_id', 'saved-vector')->firstOrFail();
        $this->assertSame($execution->id, $log->skill_execution_id);
        $this->assertSame(3, $log->total_tokens);
        $this->assertSame('embedding', $log->metadata['usage_kind']);
        $this->assertSame('state_conflict', app(PendingEmbeddingAttemptInspector::class)->recover($execution->id)['status']);
        $this->assertSame(1, DB::table('jobs')->count());
        $execution->update(['status' => 'running']);
        $noPost = $this->createMock(AiProvider::class);
        $noPost->expects($this->never())->method('embed');
        $this->assertSame([], (new KnowledgeRetrievalServiceImpl($noPost))->retrieve($query, filters: $this->filters($execution, $user)));
        $this->assertSame(1, ModelCallLog::query()->where('gateway_request_id', 'saved-vector')->count());
    }

    public function test_read_only_local_inspection_checks_original_query_model_and_hides_vector(): void
    {
        [$execution, $user] = $this->execution();
        $query = '原向量查询';
        $this->savedVector($execution, $user, $query);
        $before = DB::table('skill_executions')->where('id', $execution->id)->value('billing_pending_result');
        $inspector = app(PendingEmbeddingAttemptInspector::class);
        $this->assertSame(['status' => 'ready_for_result_recovery', 'execution_id' => $execution->id], $inspector->inspect($execution->id));
        $this->artisan('geo:inspect-pending-embedding-attempt', ['execution' => $execution->id, '--json' => true])
            ->expectsOutputToContain('"status":"ready_for_result_recovery"')->assertExitCode(0);
        $this->assertSame($before, DB::table('skill_executions')->where('id', $execution->id)->value('billing_pending_result'));
        $snapshot = $execution->fresh()->billing_pending_result;
        $wrong = $snapshot;
        $wrong['query_hash'] = 'different-query';
        $execution->update(['billing_pending_result' => $wrong]);
        $this->assertSame('identity_unconfirmed', $inspector->inspect($execution->id)['status']);
        $wrong = $snapshot;
        $wrong['gateway']['attribution']['task_id'] = 'another-task';
        $execution->update(['billing_pending_result' => $wrong]);
        $this->assertSame('identity_unconfirmed', $inspector->inspect($execution->id)['status']);
        $wrong = $snapshot;
        $wrong['vector'] = [0.125, 0.25];
        $execution->update(['billing_pending_result' => $wrong]);
        $this->assertSame('receipt_unconfirmed', $inspector->inspect($execution->id)['status']);
        $execution->update(['billing_pending_result' => $snapshot]);
        \App\Models\TenantAiKey::where('slot', 'embedding')->update(['metadata' => ['model' => 'different-vector', 'base_url' => 'https://models.example.test/v1']]);
        $this->assertSame('embedding_model_changed', $inspector->inspect($execution->id)['status']);
        $this->assertDatabaseCount('model_call_logs', 0);
    }

    private function savedVector(SkillExecution $execution, User $user, string $query): void
    {
        $brief = ['executionId' => $execution->id, 'projectId' => $execution->project_id, 'userId' => $user->id,
            'skillId' => $execution->skill_id, 'query' => $query, 'fields' => [], 'materialRefs' => []];
        $client = PreparedEmbeddingAttempt::save($execution->id, $execution->project_id, $user->id,
            $execution->skill_id, 'skill:'.$execution->id, $query, $brief);
        PreparedEmbeddingAttempt::recordResult($execution->id, $client, array_pad([0.125, 0.25], 1024, 0.0), [
            'request_id' => 'saved-vector', 'status' => 'completed', 'provider' => 'custom', 'model' => 'test-embedding', 'usage' => ['total_tokens' => 3]]);
        $execution->update(['status' => 'billing_pending']);
    }

    private function execution(): array
    {
        $tenant = Tenant::query()->create([
            'subdomain' => 'embedding-checkpoint-'.bin2hex(random_bytes(3)),
            'name' => 'Embedding checkpoint', 'plan' => 'basic', 'status' => 'active',
        ]);
        TenantContext::set($tenant);
        $this->configureWorkspaceModelSlots($tenant);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $project = Project::query()->create(['owner_id' => $user->id, 'name' => 'Checkpoint']);
        $execution = SkillExecution::query()->create([
            'project_id' => $project->id, 'user_id' => $user->id,
            'skill_id' => 'yao-geo-intent-miner', 'skill_name' => 'Intent Miner',
            'category' => 'geo-research', 'status' => 'running',
            'execution_mode' => 'sync', 'input_data' => [],
        ]);

        return [$execution, $user];
    }

    private function filters(SkillExecution $execution, User $user): array
    {
        return [
            'project_id' => $execution->project_id, 'user_id' => $user->id,
            'feature_id' => $execution->skill_id, 'task_id' => 'skill:'.$execution->id,
            'execution_id' => $execution->id,
        ];
    }
}
