<?php

namespace Tests\Feature;

use App\Contracts\Outbound\HostResolver;
use App\Contracts\Outbound\OutboundTransport;
use App\Models\Tenant;
use App\Models\TenantAiKey;
use App\Services\Geo\AiVisibility\ArkResponseStream;
use App\Services\Geo\AiVisibility\DoubaoArkResponsesClient;
use App\Services\Outbound\OutboundRequestFailedException;
use App\Services\Outbound\ResolvedOutboundTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use App\Support\TenantContext;
use Tests\TestCase;

class ArkDirectResponsesTest extends TestCase
{
    use RefreshDatabase;

    private TenantAiKey $key;
    private object $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::create(['name' => '监测测试', 'subdomain' => 'ark-direct', 'status' => 'active']);
        TenantContext::set($tenant);
        $this->key = TenantAiKey::create(['tenant_id' => $tenant->id, 'slot' => 'visibility', 'provider' => 'volces',
            'encrypted_key' => 'sk-test-ark-original', 'is_active' => true,
            'metadata' => ['base_url' => 'https://ark.example.test/api/v3', 'model' => 'test-ark-model']]);
        config(['geo.visibility.allowed_hosts' => ['ark.example.test']]);
        $this->app->bind(HostResolver::class, fn () => new class implements HostResolver {
            public function resolve(string $host): array { return ['93.184.216.34']; }
        });
        $this->transport = new class implements OutboundTransport {
            public array $requests = [];
            public bool $interrupt = false;
            public bool $omitCreated = false;
            public bool $wrongGetId = false;
            public function payload(): array
            {
                return ['id' => 'resp_test_original', 'model' => 'test-ark-model', 'status' => 'completed',
                    'output' => [['type' => 'web_search_call', 'action' => ['query' => 'GEO']],
                        ['type' => 'web_search_call', 'action' => ['query' => '内容工作台']],
                        ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '测试回答', 'annotations' => []]]]],
                    'usage' => ['input_tokens' => 8, 'output_tokens' => 4, 'tool_usage' => ['web_search' => 99]]];
            }
            public function send(PendingRequest $request, string $method, ResolvedOutboundTarget $target,
                array $data, int $maxBytes, bool $crossOrigin = false): Response
            {
                $this->requests[] = ['method' => $method, 'url' => $target->url, 'body' => $data];
                if ($method === 'GET') {
                    $payload = $this->payload();
                    if ($this->wrongGetId) { $payload['id'] = 'resp_test_other'; }
                    return new Response(new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode($payload)));
                }
                $options = $request->getOptions();
                $response = new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'text/event-stream']);
                ($options['on_headers'])($response);
                if (! $this->omitCreated) {
                    $frame = 'data: '.json_encode(['type' => 'response.created', 'response' => ['id' => 'resp_test_original']])."\r\n\r\n";
                    // Split JSON, CRLF and Unicode boundaries exactly as a network can.
                    foreach (str_split($frame, 3) as $part) { $options['sink']->write($part); }
                }
                if ($this->interrupt) { throw new OutboundRequestFailedException; }
                $frame = 'data: '.json_encode(['type' => 'response.completed', 'response' => $this->payload()], JSON_UNESCAPED_UNICODE)."\n\n";
                foreach (str_split($frame, 7) as $part) { $options['sink']->write($part); }
                return new Response($response->withBody($options['sink']));
            }
        };
        $this->app->instance(OutboundTransport::class, $this->transport);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    public function test_stream_uses_direct_payload_counts_observed_tools_and_saves_id(): void
    {
        $saved = [];
        $result = app(DoubaoArkResponsesClient::class)->ask('问题', 'visibility:1', 'test-request-1', function ($receipt) use (&$saved) { $saved[] = $receipt; });
        $this->assertTrue($result->success, $result->errorMessage ?? '');
        $this->assertSame('测试回答', $result->answerText);
        $this->assertSame(2, $result->searchCallCount);
        $this->assertFalse($result->searchCountEstimated);
        $this->assertSame('resp_test_original', $saved[1]['provider_request_id']);
        $this->assertStringNotContainsString('sk-test-ark-original', json_encode($saved));
        $this->assertCount(1, $this->transport->requests);
        $this->assertSame(['model', 'input', 'tools', 'reasoning', 'stream', 'store', 'max_output_tokens'], array_keys($this->transport->requests[0]['body']));
        $this->assertSame([['type' => 'web_search']], $this->transport->requests[0]['body']['tools']);
        $this->assertSame(['effort' => 'low'], $this->transport->requests[0]['body']['reasoning']);
        $this->assertTrue($this->transport->requests[0]['body']['stream']);
        $this->assertTrue($this->transport->requests[0]['body']['store']);
    }

    public function test_interrupted_stream_recovers_with_get_and_never_reposts(): void
    {
        $this->transport->interrupt = true;
        $saved = [];
        $client = app(DoubaoArkResponsesClient::class);
        $pending = $client->ask('问题', 'visibility:1', 'test-request-1', function ($receipt) use (&$saved) { $saved = $receipt; });
        $this->assertFalse($pending->success);
        $this->assertTrue($pending->pendingReconciliation);
        $this->assertSame('resp_test_original', $saved['provider_request_id']);
        $recovered = $client->recover('问题', 'visibility:1', 'test-request-1', $saved);
        $this->assertTrue($recovered->success);
        $this->assertSame(['POST', 'GET'], array_column($this->transport->requests, 'method'));
        $this->assertStringEndsWith('/responses/resp_test_original', $this->transport->requests[1]['url']);
    }

    public function test_missing_id_or_changed_key_cannot_start_recovery_request(): void
    {
        $this->transport->interrupt = true;
        $saved = [];
        $client = app(DoubaoArkResponsesClient::class);
        $client->ask('问题', 'visibility:1', 'test-request-1', function ($receipt) use (&$saved) { $saved = $receipt; });
        $missing = $saved;
        unset($missing['provider_request_id']);
        $this->assertTrue($client->recover('问题', 'visibility:1', 'test-request-1', $missing)->pendingReconciliation);
        $this->key->update(['encrypted_key' => 'sk-test-ark-rotated']);
        $this->assertTrue($client->recover('问题', 'visibility:1', 'test-request-1', $saved)->pendingReconciliation);
        $this->assertCount(1, $this->transport->requests);
    }

    public function test_recovery_rejects_a_different_response_id(): void
    {
        $this->transport->interrupt = true;
        $saved = [];
        $client = app(DoubaoArkResponsesClient::class);
        $client->ask('问题', 'visibility:1', 'test-request-1', function ($receipt) use (&$saved) { $saved = $receipt; });
        $this->transport->wrongGetId = true;
        $result = $client->recover('问题', 'visibility:1', 'test-request-1', $saved);
        $this->assertFalse($result->success);
        $this->assertTrue($result->pendingReconciliation);
        $this->assertSame(['POST', 'GET'], array_column($this->transport->requests, 'method'));
    }

    public function test_stream_enforces_byte_limit_without_losing_saved_id(): void
    {
        $savedId = null;
        $sink = new ArkResponseStream(150, function ($id) use (&$savedId) { $savedId = $id; });
        $sink->write("data: {\"type\":\"response.created\",\"response\":{\"id\":\"resp_test_original\"}}\n\n");
        try {
            $sink->write(str_repeat('x', 151));
            $this->fail('Oversized streams must be rejected.');
        } catch (\App\Services\Outbound\OutboundRequestBlockedException $e) {
            $this->assertSame('response_too_large', $e->reasonCode);
        }
        $this->assertSame('resp_test_original', $savedId);
        $this->assertNull($sink->response());
    }

    public function test_missing_key_and_disallowed_host_send_no_request(): void
    {
        $this->key->update(['metadata' => ['base_url' => 'https://other.example.test/v1', 'model' => 'test-ark-model']]);
        $client = app(DoubaoArkResponsesClient::class);
        $this->assertFalse($client->ask('问题', 'visibility:1', 'test-request-1')->success);
        $this->key->delete();
        $this->assertSame('未配置豆包监测', $client->ask('问题', 'visibility:1', 'test-request-1')->errorMessage);
        $this->assertCount(0, $this->transport->requests);
    }
}
