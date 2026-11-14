<?php

namespace Tests\Feature;

use App\Services\AI\AiProviderServiceImpl;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Public model failures retain usage, local attempt identity and ownership. */
class GatewayFailureAttributionTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;
    use \Tests\Support\UsesWorkspaceModels;
    protected function setUp(): void
    {
        parent::setUp();
        $this->useWorkspaceModels(provider: 'openai');
    }

    public function test_unparseable_native_result_keeps_original_usage_without_a_second_paid_request(): void
    {
        config(['geo.native_json_schema' => true]);
        Http::fake(['https://models.example.test/v1/chat/completions' => \Illuminate\Support\Facades\Http::sequence()
            ->push(['id' => 'native-invalid', 'choices' => [['message' => ['content' => 'invalid JSON'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]], 200, ['X-Request-Id' => 'native-invalid'])
            ->push(['choices' => [['message' => ['content' => '{"ok":true}']]], 'usage' => ['total_tokens' => 15]], 200, ['X-Request-Id' => 'recovery-ok'])]);
        $result = app(AiProviderServiceImpl::class)->generateStructured('test', '{"type":"object"}', options: ['feature_id' => 'batch-titles']);
        $this->assertFalse($result->success);
        $this->assertSame(15, $result->totalTokens);
        $this->assertSame('stop', $result->finishReason);
        $this->assertSame('native-invalid', $result->gateway['provider_request_id']);
        $this->assertNotEmpty($result->gateway['attribution']['client_request_id']);
        Http::assertSentCount(1);
    }

    public function test_unparseable_delivered_response_keeps_tokens_and_task_evidence(): void
    {
        config(['geo.native_json_schema' => false]);
        Http::fake(['https://models.example.test/v1/chat/completions' => \Illuminate\Support\Facades\Http::response([
            'id' => 'parse-failed', 'choices' => [['message' => ['content' => '{broken'], 'finish_reason' => 'length']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ], 200, ['X-Request-Id' => 'parse-failed'])]);
        $result = app(AiProviderServiceImpl::class)->generateStructured('test', '{}', options: ['feature_id' => 'batch-titles', 'task_id' => 'skill:42']);
        $this->assertFalse($result->success);
        $this->assertSame(15, $result->totalTokens);
        $this->assertSame('length', $result->finishReason);
        $this->assertSame('parse-failed', $result->gateway['provider_request_id']);
        $this->assertSame('skill:42', $result->gateway['attribution']['task_id']);
    }

    public function test_failed_embedding_keeps_parent_task_and_does_not_retry(): void
    {
        Http::fake(['https://models.example.test/v1/embeddings' => \Illuminate\Support\Facades\Http::response(['id' => 'embedding-failed', 'error' => ['message' => 'private upstream']], 502, ['X-Request-Id' => 'embedding-failed'])]);
        $result = app(AiProviderServiceImpl::class)->embed('query', options: ['feature_id' => 'yao-geo-page-audit', 'task_id' => 'skill:42']);
        $this->assertSame([], $result['embedding']);
        $this->assertSame('embedding-failed', $result['gateway']['provider_request_id']);
        $this->assertSame('skill:42', $result['gateway']['attribution']['task_id']);
        Http::assertSentCount(1);
        $this->assertSame('unknown', $result['gateway']['status']);
        $this->assertSame(['model', 'dimensions', 'encoding_format', 'input'], array_keys(Http::recorded()->first()[0]->data()));
    }

    public function test_failed_batch_embedding_preserves_receipt_and_safe_customer_message(): void
    {
        Http::fake(['https://models.example.test/v1/embeddings' => \Illuminate\Support\Facades\Http::response(['id' => 'batch-failed', 'error' => ['message' => 'private upstream']], 402, ['X-Request-Id' => 'batch-failed'])]);
        $result = app(AiProviderServiceImpl::class)->embedBatch(['test'], options: ['task_id' => 'document:7']);
        $this->assertSame('batch-failed', $result['gateway']['provider_request_id']);
        $this->assertSame('document:7', $result['gateway']['attribution']['task_id']);
        $this->assertStringContainsString('模型服务拒绝了请求', $result['error']);
        $this->assertSame('failed', $result['gateway']['status']);
        Http::assertSentCount(1);
        $this->assertStringNotContainsString('private upstream', $result['error']);
    }

    public function test_connection_failure_keeps_client_correlation_without_inventing_gateway_id(): void
    {
        Http::fake(['https://models.example.test/v1/chat/completions' => Http::failedConnection()]);
        $result = app(AiProviderServiceImpl::class)->generate('test', options: ['feature_id' => 'batch-titles', 'task_id' => 'skill:42']);
        $this->assertFalse($result->success);
        $this->assertSame('skill:42', $result->gateway['attribution']['task_id']);
        $this->assertNotEmpty($result->gateway['attribution']['client_request_id']);
        $this->assertNull($result->gateway['provider_request_id']);
        $this->assertStringStartsWith('model:', $result->gateway['request_id']);
    }

    public function test_local_step_identity_is_not_sent_to_the_provider(): void
    {
        Http::fake(['*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);
        $provider = app(AiProviderServiceImpl::class);
        $first = $provider->generate('same', options: ['task_id' => 'skill:42', 'step_id' => 'chunk:1', 'client_request_id' => 'test-step-1']);
        $next = $provider->generate('next', options: ['task_id' => 'skill:42', 'step_id' => 'chunk:2', 'client_request_id' => 'test-step-2']);
        $this->assertSame('test-step-1', $first->gateway['attribution']['client_request_id']);
        $this->assertSame('test-step-2', $next->gateway['attribution']['client_request_id']);
        $this->assertNotSame($first->gateway['request_id'], $next->gateway['request_id']);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame(['model', 'messages', 'max_tokens'], array_keys($request->data()));
        }
        Http::assertSentCount(2);
    }

    public function test_pending_native_attempt_does_not_start_a_paid_fallback(): void
    {
        config(['geo.native_json_schema' => true]);
        Http::fake(['https://models.example.test/v1/chat/completions' => \Illuminate\Support\Facades\Http::response([
            'id' => 'pending-native', 'status' => 'in_progress',
        ], 202)]);
        $result = app(AiProviderServiceImpl::class)->generateStructured('same', '{}', options: [
            'feature_id' => 'batch-titles', 'task_id' => 'skill:42', 'step_id' => 'analysis',
        ]);
        $this->assertFalse($result->success);
        $this->assertSame('pending-native', $result->gateway['provider_request_id']);
        Http::assertSentCount(1);
    }

    public function test_native_parse_failure_keeps_the_original_local_attempt_identity(): void
    {
        config(['geo.native_json_schema' => true]);
        Http::fake(['*/chat/completions' => Http::response(['id' => 'test-invalid', 'choices' => [['message' => ['content' => 'invalid JSON']]]])]);
        $result = app(AiProviderServiceImpl::class)->generateStructured('same', '{}', options: ['task_id' => 'skill:42', 'client_request_id' => 'test-original']);
        $this->assertFalse($result->success);
        $this->assertSame('invalid JSON', $result->content);
        $this->assertSame('test-original', $result->gateway['attribution']['client_request_id']);
        $this->assertSame('test-invalid', $result->gateway['provider_request_id']);
        Http::assertSentCount(1);
    }

    public function test_native_gateway_error_does_not_start_a_second_paid_attempt(): void
    {
        config(['geo.native_json_schema' => true]);
        Http::fake(['https://models.example.test/v1/chat/completions' => \Illuminate\Support\Facades\Http::sequence()
            ->push(['id' => 'native-gateway-error', 'error' => 'upstream unavailable'], 502, ['X-Request-Id' => 'native-gateway-error'])
            ->push(['choices' => [['message' => ['content' => '{"ok":true}']]]], 200)]);

        $result = app(AiProviderServiceImpl::class)->generateStructured('test', '{"type":"object"}',
            options: ['feature_id' => 'batch-titles', 'task_id' => 'skill:42', 'step_id' => 'analysis']);

        $this->assertFalse($result->success);
        $this->assertSame(502, $result->gateway['http_status']);
        $this->assertSame('native-gateway-error', $result->gateway['provider_request_id']);
        Http::assertSentCount(1);
    }

    public function test_native_connection_loss_keeps_client_attempt_identity_without_fallback(): void
    {
        config(['geo.native_json_schema' => true]);
        // Failed connections are not added to Http::recorded(); count attempts at the fake boundary.
        $attempts = 0;
        Http::fake(['*/chat/completions' => function () use (&$attempts) {
            $attempts++;
            throw new \Illuminate\Http\Client\ConnectionException('test connection lost');
        }]);

        $result = app(AiProviderServiceImpl::class)->generateStructured('test', '{"type":"object"}',
            options: ['feature_id' => 'batch-titles', 'task_id' => 'skill:42', 'step_id' => 'analysis']);

        $this->assertFalse($result->success);
        $this->assertSame('skill:42', $result->gateway['attribution']['task_id']);
        $this->assertNotEmpty($result->gateway['attribution']['client_request_id']);
        $this->assertNull($result->gateway['provider_request_id']);
        $this->assertStringStartsWith('model:', $result->gateway['request_id']);
        $this->assertSame(1, $attempts);
    }
}
