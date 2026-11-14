<?php

namespace Tests\Feature;

use App\Services\AI\AiProviderServiceImpl;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GatewayCapabilityContractTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;
    use \Tests\Support\UsesWorkspaceModels;

    private function configure(): void { $this->useWorkspaceModels(); }

    public function test_task_step_and_recovery_reach_gateway_and_receipt_returns_unchanged(): void
    {
        $this->configure();
        $receipt = ['request_id' => 'gw-test-1', 'routing' => ['task' => ['profile' => 'analysis'], 'version' => 'revision-1']];
        Http::fake(['https://models.example.test/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'report'], 'finish_reason' => 'stop']], 'id' => 'test-provider-request', 'gateway' => $receipt,
        ])]);
        $result = app(AiProviderServiceImpl::class)->generate('report', options: ['provider' => 'deepseek',
            'model' => 'text', 'feature_id' => 'yao-geo-page-audit', 'task_id' => 'skill:42',
            'step_id' => 'page-evidence', 'recovery_mode' => 'nonthinking']);
        $this->assertSame('custom', $result->provider);
        $this->assertSame('test-provider-request', $result->gateway['provider_request_id']);
        $this->assertArrayNotHasKey('routing', $result->gateway);
        $this->assertSame('skill:42', $result->gateway['attribution']['task_id']);
        $this->assertSame('page-evidence', $result->gateway['attribution']['step_id']);
        Http::assertSent(fn ($r) => array_keys($r->data()) === ['model', 'messages', 'max_tokens'] && $r['model'] === 'test-text');
        Http::assertSentCount(1);
    }

    public function test_failed_gateway_request_keeps_correlation_without_exposing_response_body(): void
    {
        $this->configure();
        Http::fake(['https://models.example.test/v1/chat/completions' => Http::response(
            ['id' => 'test-failed-1', 'error' => ['message' => 'private supplier detail']], 502, ['X-Gateway-Request-Id' => 'gw-failed-1'])]);
        $result = app(AiProviderServiceImpl::class)->generate('test', options: ['feature_id' => 'batch-titles']);
        $this->assertFalse($result->success);
        $this->assertSame('test-failed-1', $result->gateway['provider_request_id']);
        $this->assertSame('unknown', $result->gateway['status']);
        Http::assertSentCount(1);
        $this->assertStringNotContainsString('private supplier detail', $result->errorMessage);
    }

    public function test_query_embedding_keeps_parent_task_and_gateway_receipt(): void
    {
        $this->configure();
        Http::fake(['https://models.example.test/v1/embeddings' => Http::response([
            'id' => 'test-embedding-1', 'data' => [['index' => 0, 'embedding' => array_pad([0.1, 0.2], 1024, 0.0)]], 'usage' => ['total_tokens' => 12],
            'gateway' => ['request_id' => 'gw-embed-1'],
        ])]);
        $result = app(AiProviderServiceImpl::class)->embed('query', 'embedding', ['feature_id' => 'yao-geo-page-audit', 'task_id' => 'skill:42']);
        $this->assertSame('test-embedding-1', $result['gateway']['provider_request_id']);
        $this->assertSame('skill:42', $result['gateway']['attribution']['task_id']);
        Http::assertSent(fn ($r) => array_keys($r->data()) === ['model', 'dimensions', 'encoding_format', 'input'] && $r['model'] === 'test-embedding');
        Http::assertSentCount(1);
    }
}
