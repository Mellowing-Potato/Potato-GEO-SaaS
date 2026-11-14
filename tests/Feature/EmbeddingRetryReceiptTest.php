<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AI\AiProviderServiceImpl;
use App\Services\Knowledge\KnowledgeRetrievalServiceImpl;
use App\Services\Billing\UnresolvedEmbeddingAttempt;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Embedding requests are never automatically repeated after an uncertain result. */
class EmbeddingRetryReceiptTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Support\UsesWorkspaceModels;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useWorkspaceModels();
        // 检索服务从容器取 AiProvider；测试镜像设了 AI_MOCK_MODE=true，默认会拿到 MockAiProvider，
        // 永远不发网关请求。本文件测的是真实网关回执，所以显式绑定真实实现。
        $this->app->instance(\App\Services\Contracts\AiProvider::class, app(AiProviderServiceImpl::class));
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    /** @return array{0: Tenant, 1: User, 2: Project, 3: SkillExecution} */
    private function fixture(): array
    {
        $tenant = Tenant::create([
            'subdomain' => 'retry-'.bin2hex(random_bytes(4)),
            'name' => 'Retry Fixture',
            'status' => 'active',
        ]);
        TenantContext::set($tenant);
        $this->configureWorkspaceModelSlots($tenant);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $project = Project::create([
            'name' => 'Retry Fixture',
            'industry' => 'technology',
            'status' => 'active',
            'owner_id' => $user->id,
        ]);
        $execution = SkillExecution::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'skill_id' => 'batch-titles',
            'skill_name' => 'Titles',
            'category' => 'test',
            'status' => 'running',
        ]);

        return [$tenant, $user, $project, $execution];
    }

    public function test_every_embeddings_attempt_keeps_its_own_receipt(): void
    {
        $attempts = 0;
        Http::fake(['*/embeddings' => function () use (&$attempts) {
            $attempts++;
            return Http::response(['id' => 'test-first-failed', 'error' => 'busy'], 503);
        }]);
        $result = app(AiProviderServiceImpl::class)->embed('query', options: ['feature_id' => 'yao-geo-page-audit', 'task_id' => 'skill:42']);
        $this->assertSame([], $result['embedding']);
        $this->assertSame('test-first-failed', $result['gateway']['provider_request_id']);
        $this->assertSame('unknown', $result['gateway']['status']);
        $this->assertSame('skill:42', $result['gateway']['attribution']['task_id']);
        $this->assertSame(1, $attempts);
        Http::assertSentCount(1);
    }

    public function test_persisted_task_step_blocks_reentry_and_preserves_original_query(): void
    {
        [, $user, $project, $execution] = $this->fixture();
        $id = \App\Services\Billing\PreparedEmbeddingAttempt::save($execution->id, $project->id, $user->id, $execution->skill_id, 'skill:'.$execution->id, 'query');
        try {
            \App\Services\Billing\PreparedEmbeddingAttempt::save($execution->id, $project->id, $user->id, $execution->skill_id, 'skill:'.$execution->id, 'changed query');
            $this->fail('A prepared request cannot be sent again.');
        } catch (UnresolvedEmbeddingAttempt $pending) { $pending->hold($execution); }
        $this->assertSame($id, data_get($execution->fresh()->billing_pending_result, 'gateway.attribution.client_request_id'));
        $this->assertSame('query', $execution->fresh()->billing_pending_result['query']);
        Http::assertNothingSent();
    }

    public function test_pending_response_is_unknown_without_replaying_the_post(): void
    {
        Http::fake(['*/embeddings' => Http::sequence()->push(['id' => 'test-pending', 'status' => 'in_progress'], 202)
            ->push(['data' => [['index' => 0, 'embedding' => array_fill(0, 1024, 0.1)]], 'usage' => ['total_tokens' => 2]])]);
        $result = app(AiProviderServiceImpl::class)->embed('query', options: ['task_id' => 'skill:42']);
        $this->assertSame([], $result['embedding']);
        $this->assertSame('unknown', $result['gateway']['status']);
        $this->assertSame('test-pending', $result['gateway']['provider_request_id']);
        Http::assertSentCount(1);
    }

    public function test_supplier_conflict_is_a_safe_failure_with_original_attribution(): void
    {
        Http::fake(['*/embeddings' => Http::response(['id' => 'test-conflict', 'error' => ['message' => 'private upstream']], 409)]);
        $result = app(AiProviderServiceImpl::class)->embed('changed query', options: ['task_id' => 'knowledge-search:fixed-operation']);
        $this->assertSame('failed', $result['gateway']['status']);
        $this->assertSame('test-conflict', $result['gateway']['provider_request_id']);
        $this->assertSame('knowledge-search:fixed-operation', $result['gateway']['attribution']['task_id']);
        $this->assertStringNotContainsString('private upstream', $result['error']);
        Http::assertSentCount(1);
    }

    public function test_batch_conflict_stops_retries_and_reports_original_receipt(): void
    {
        Http::fake(['*/embeddings' => Http::response(['id' => 'test-batch-conflict', 'error' => ['message' => 'private upstream']], 409)]);
        $result = app(AiProviderServiceImpl::class)->embedBatch(['changed chunk'], options: ['task_id' => 'document:42', 'step_id' => '0']);
        $this->assertSame('failed', $result['gateway']['status']);
        $this->assertSame([], $result['embeddings']);
        $this->assertSame('test-batch-conflict', $result['gateway']['provider_request_id']);
        $this->assertSame('document:42', $result['gateway']['attribution']['task_id']);
        Http::assertSentCount(1);
    }

    public function test_retrieval_keeps_failed_receipt_on_the_execution(): void
    {
        [, $user, $project, $execution] = $this->fixture();
        $execution->update(['gateway_requests' => [['request_id' => 'previous-retrieval']]]);
        Http::fake(['https://models.example.test/v1/embeddings' => Http::response(
            ['id' => 'retrieval-failed', 'error' => 'busy'], 503,
        )]);

        $service = app(KnowledgeRetrievalServiceImpl::class);
        try {
            $service->retrieve('query', filters: [
                'project_id' => $project->id, 'user_id' => $user->id,
                'feature_id' => $execution->skill_id,
                'execution_id' => $execution->id,
                'task_id' => 'skill:'.$execution->id,
            ]);
            $this->fail('A sent embedding with a failed gateway receipt must stay pending.');
        } catch (UnresolvedEmbeddingAttempt $pending) {
            $pending->hold($execution);
        }

        $stored = $execution->fresh()->gateway_requests;
        $this->assertSame('previous-retrieval', $stored[0]['request_id']);
        $this->assertSame('retrieval-failed', $stored[1]['provider_request_id']);
        Http::assertSentCount(1);
        // 只重试一次的失败回执在原实现里会被整条丢掉（它没有顶层 request_id）。
        $this->assertSame('skill:'.$execution->id, $stored[1]['attribution']['task_id']);
        $this->assertSame('embedding_attempt_unknown', data_get($execution->fresh()->billing_pending_result, 'kind'));
    }

    public function test_one_pending_embedding_response_holds_original_request_without_resend(): void
    {
        [, $user, $project, $execution] = $this->fixture();
        Http::fake(['https://models.example.test/v1/embeddings' => Http::response([
            'id' => 'test-pending', 'status' => 'in_progress',
        ], 202)]);

        try {
            app(KnowledgeRetrievalServiceImpl::class)->retrieve('pending query', filters: [
                'project_id' => $project->id, 'user_id' => $user->id,
                'feature_id' => $execution->skill_id,
                'execution_id' => $execution->id,
                'task_id' => 'skill:'.$execution->id,
            ]);
            $this->fail('Pending gateway state must not fall through as empty context.');
        } catch (UnresolvedEmbeddingAttempt $pending) {
            $pending->hold($execution);
        }

        Http::assertSentCount(1);
        $held = $execution->fresh();
        $this->assertSame('billing_pending', $held->status);
        $this->assertSame('test-pending', data_get($held->billing_pending_result, 'gateway.provider_request_id'));
        $this->assertNotEmpty(data_get($held->billing_pending_result, 'gateway.attribution.client_request_id'));
        $this->assertSame('pending query', data_get($held->billing_pending_result, 'query'));
    }

    public function test_retrieval_does_not_write_receipts_into_another_project(): void
    {
        [, $user, $project, $execution] = $this->fixture();
        $execution->update(['gateway_requests' => [['request_id' => 'previous-retrieval']]]);
        $other = Project::create([
            'name' => 'Other Project',
            'industry' => 'technology',
            'status' => 'active',
            'owner_id' => $user->id,
        ]);
        Http::fake(['https://models.example.test/v1/embeddings' => Http::response(
            ['error' => 'busy'], 503, ['X-Gateway-Request-Id' => 'wrong-project'],
        )]);

        try {
            app(KnowledgeRetrievalServiceImpl::class)->retrieve('query', filters: [
                'project_id' => $other->id, 'user_id' => $user->id,
                'feature_id' => $execution->skill_id,
                'execution_id' => $execution->id,
                'task_id' => 'skill:'.$execution->id,
            ]);
            $this->fail('A foreign project must be rejected before the paid gateway request.');
        } catch (\RuntimeException $expected) {
            $this->assertStringContainsString('cannot be prepared', $expected->getMessage());
        }
        Http::assertNothingSent();

        $this->assertSame(
            [['request_id' => 'previous-retrieval']],
            $execution->fresh()->gateway_requests,
            '同租户但不同项目的检索不得把回执写进别人的执行记录',
        );
        $this->assertSame('previous-retrieval', $execution->fresh()->gateway_requests[0]['request_id']);
        $this->assertSame($project->id, $execution->project_id);
    }
}
