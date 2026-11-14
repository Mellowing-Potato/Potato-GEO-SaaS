<?php

namespace Tests\Feature;

use App\Contracts\Outbound\HostResolver;
use App\Contracts\Outbound\OutboundTransport;
use App\Models\ModelCallLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AI\ModelConnectionTester;
use App\Services\Outbound\ResolvedOutboundTarget;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Tests\TestCase;

class ModelConnectionTesterTest extends TestCase
{
    use RefreshDatabase;

    private object $transport;
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => '连接测试', 'subdomain' => 'connection-probe', 'status' => 'active']);
        TenantContext::set($this->tenant);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        config(['geo.visibility.allowed_hosts' => ['ark.example.test']]);
        $this->app->bind(HostResolver::class, fn () => new class implements HostResolver {
            public function resolve(string $host): array { return ['93.184.216.34']; }
        });
        $this->transport = new class implements OutboundTransport {
            public array $requests = [];
            public string $status = 'completed';
            public function send(PendingRequest $request, string $method, ResolvedOutboundTarget $target,
                array $data, int $maxBytes, bool $crossOrigin = false): Response
            {
                $this->requests[] = $data;
                $body = ['id' => 'resp_test_connection', 'status' => $this->status,
                    'output' => [['type' => 'web_search_call', 'action' => ['query' => 'GEO']],
                        ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '仅用于测试的回答',
                            'annotations' => [['type' => 'url_citation', 'title' => '测试来源', 'url' => 'https://example.com']]]]]],
                    'usage' => ['input_tokens' => 100, 'output_tokens' => 339, 'total_tokens' => 439]];
                return new Response(new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode($body)));
            }
        };
        $this->app->instance(OutboundTransport::class, $this->transport);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    private function probe(): array
    {
        return app(ModelConnectionTester::class)->test(['slot' => 'visibility', 'provider' => 'volces',
            'base_url' => 'https://ark.example.test/api/v3', 'model' => 'test-ark-model',
            'api_key' => 'sk-test-connection'], $this->tenant->id, $this->user->id);
    }

    public function test_search_probe_has_a_bounded_answer_budget_without_extra_thinking(): void
    {
        $this->assertTrue($this->probe()['success']);
        $this->assertCount(1, $this->transport->requests);
        $payload = $this->transport->requests[0];
        $this->assertSame(1024, $payload['max_output_tokens']);
        $this->assertSame(['type' => 'disabled'], $payload['thinking']);
        $this->assertSame([['type' => 'web_search']], $payload['tools']);
        $this->assertSame('completed', ModelCallLog::firstOrFail()->status);
    }

    public function test_incomplete_answer_stays_failed_retains_usage_and_does_not_blame_key_or_retry(): void
    {
        $this->transport->status = 'incomplete';
        $result = $this->probe();
        $this->assertFalse($result['success']);
        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('方舟已接受请求', $result['message']);
        $this->assertStringContainsString('未自动重试', $result['message']);
        $this->assertCount(1, $this->transport->requests);
        $this->assertSame(439, ModelCallLog::firstOrFail()->total_tokens);
    }
}
