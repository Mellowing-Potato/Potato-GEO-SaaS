<?php

namespace Tests\Feature;

use App\Models\ModelCallLog;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\Tenant;
use App\Models\TenantAiKey;
use App\Models\User;
use App\Services\AI\AiProviderServiceImpl;
use App\Services\AI\ModelConnectionTester;
use App\Services\Billing\ModelUsageRecorder;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelEndpointProtocolTest extends TestCase
{
    use RefreshDatabase;

    private $server;
    private array $pipes = [];
    private string $requests;
    private int $port;
    private Tenant $tenant;
    private User $user;
    private SkillExecution $execution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requests = tempnam(sys_get_temp_dir(), 'model-http-');
        $this->server = proc_open(['python3', base_path('tests/Support/model_http_fixture.py'), $this->requests],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes);
        $this->assertIsResource($this->server);
        $this->port = (int) trim(fgets($this->pipes[1]));
        $this->assertGreaterThan(0, $this->port);
        config(['geo.outbound_private_targets' => ['127.0.0.1:'.$this->port], 'geo.model_http_timeout' => 1]);
        $this->tenant = Tenant::create(['name' => '协议测试', 'subdomain' => 'protocol', 'status' => 'active']);
        TenantContext::set($this->tenant);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $project = Project::create(['tenant_id' => $this->tenant->id, 'owner_id' => $this->user->id, 'name' => '协议项目']);
        $this->execution = SkillExecution::create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id,
            'user_id' => $this->user->id, 'skill_id' => 'test-skill', 'skill_name' => '测试技能',
            'category' => 'geo_research', 'status' => 'running', 'input_data' => []]);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            foreach ($this->pipes as $pipe) { fclose($pipe); }
            proc_close($this->server);
        }
        if (isset($this->requests)) { @unlink($this->requests); }
        TenantContext::forget();
        parent::tearDown();
    }

    private function configure(string $mode = 'success', string $slot = 'text', string $provider = 'custom', string $secret = 'sk-test-protocol'): TenantAiKey
    {
        return TenantAiKey::updateOrCreate(['tenant_id' => $this->tenant->id, 'slot' => $slot],
            ['provider' => $provider, 'encrypted_key' => $secret, 'is_active' => true,
                'metadata' => ['base_url' => 'http://127.0.0.1:'.$this->port.'/'.$mode,
                    'model' => $slot === 'embedding' ? 'test-vector' : 'test-text', 'json_mode' => true]]);
    }

    private function requests(): array
    {
        return array_map(fn ($line) => json_decode($line, true), file($this->requests, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    public function test_direct_success_has_only_public_payload_and_headers_and_one_usage_record_on_replay(): void
    {
        $this->configure();
        $result = app(AiProviderServiceImpl::class)->generate('hello', options: ['feature_id' => 'test-feature', 'task_id' => 'test-task']);
        $this->assertTrue($result->success);
        $this->assertSame('completed', $result->gateway['status']);
        $this->assertSame(7, $result->totalTokens);
        $requests = $this->requests();
        $this->assertCount(1, $requests);
        $this->assertSame(['model', 'messages', 'max_tokens'], array_keys($requests[0]['body']));
        $this->assertSame('test-text', $requests[0]['body']['model']);
        $headers = array_map('strtolower', array_keys($requests[0]['headers']));
        sort($headers);
        $this->assertSame(['accept', 'accept-encoding', 'authorization', 'content-length', 'content-type', 'host', 'user-agent'], $headers);
        $this->assertSame('identity', $requests[0]['headers']['Accept-Encoding']);
        $recorder = app(ModelUsageRecorder::class);
        $first = $recorder->record($this->execution, $result);
        $this->assertSame($first->id, $recorder->record($this->execution, $result)->id);
        $this->assertDatabaseCount('model_call_logs', 1);
        $this->assertCount(1, $this->requests());
    }

    public function test_connection_refusal_is_unsent_and_never_retried(): void
    {
        $this->configure();
        proc_terminate($this->server);
        foreach ($this->pipes as $pipe) { fclose($pipe); }
        proc_close($this->server);
        $result = app(AiProviderServiceImpl::class)->generate('hello');
        $this->assertFalse($result->success);
        $this->assertSame('failed', $result->gateway['status']);
        $this->assertFalse($result->gateway['request_sent']);
        $this->assertCount(0, $this->requests());
    }

    public function test_read_timeout_is_unknown_and_sends_once(): void
    {
        $this->configure('timeout');
        $result = app(AiProviderServiceImpl::class)->generate('hello');
        $this->assertFalse($result->success);
        $this->assertSame('unknown', $result->gateway['status']);
        $this->assertTrue($result->gateway['request_sent']);
        $this->assertSame('结果未知，可手动重新执行', $result->errorMessage);
        $this->assertSame('unknown', app(ModelUsageRecorder::class)->record($this->execution, $result)->status);
        $this->assertCount(1, $this->requests());
    }

    public function test_http_500_is_unknown_and_sends_once(): void
    {
        $this->configure('error');
        $result = app(AiProviderServiceImpl::class)->generate('hello');
        $this->assertFalse($result->success);
        $this->assertSame('unknown', $result->gateway['status']);
        $this->assertSame(500, $result->gateway['http_status']);
        $this->assertCount(1, $this->requests());
    }

    public function test_missing_usage_preserves_content_and_marks_usage_unavailable(): void
    {
        $this->configure('no-usage');
        $result = app(AiProviderServiceImpl::class)->generate('hello');
        $this->assertTrue($result->success);
        $this->assertSame('{"ok":true}', $result->content);
        $this->assertNull($result->gateway['usage']);
        $this->assertFalse($result->gateway['usage_available']);
        $log = app(ModelUsageRecorder::class)->record($this->execution, $result);
        $this->assertNull($log->total_tokens);
        $this->assertSame('completed', $log->status);
        $this->assertCount(1, $this->requests());
    }

    public function test_same_provider_request_id_does_not_merge_vendors_or_rotated_credentials(): void
    {
        foreach ([['openai', 'sk-test-first'], ['deepseek', 'sk-test-second'], ['deepseek', 'sk-test-rotated']] as [$provider, $secret]) {
            $this->configure(provider: $provider, secret: $secret);
            $result = app(AiProviderServiceImpl::class)->generate('hello');
            $this->assertTrue($result->success);
            $this->assertSame('test-same-id', $result->gateway['provider_request_id']);
            app(ModelUsageRecorder::class)->record($this->execution, $result);
        }
        $this->assertDatabaseCount('model_call_logs', 3);
        $this->assertCount(3, $this->requests());
        $this->assertSame(['Bearer sk-test-first', 'Bearer sk-test-second', 'Bearer sk-test-rotated'],
            array_map(fn ($request) => $request['headers']['Authorization'], $this->requests()));
        $this->assertCount(3, array_unique(ModelCallLog::get()->map(fn ($row) => $row->metadata['gateway']['credential_fingerprint'])->all()));
    }

    public function test_embedding_requests_1024_and_rejects_mismatched_response_without_retry(): void
    {
        $this->configure('wrong-dimension', 'embedding');
        $result = app(AiProviderServiceImpl::class)->embed('hello');
        $this->assertSame([], $result['embedding']);
        $this->assertSame('failed', $result['gateway']['status']);
        $this->assertStringContainsString('1024', $result['error']);
        $requests = $this->requests();
        $this->assertCount(1, $requests);
        $this->assertSame(1024, $requests[0]['body']['dimensions']);
        $this->assertSame(5, $result['tokens']);
    }

    public function test_valid_embedding_probe_returns_proof_but_invalid_dimension_does_not(): void
    {
        foreach (['success', 'wrong-dimension'] as $mode) {
            $key = $this->configure($mode, 'embedding');
            $data = ['slot' => 'embedding', 'provider' => 'custom', 'base_url' => $key->metadata['base_url'],
                'model' => 'test-vector', 'api_key' => $key->getDecryptedKey()];
            $result = app(ModelConnectionTester::class)->test($data, $this->tenant->id, $this->user->id);
            $this->assertSame($mode === 'success', $result['success']);
            if ($mode === 'success') { $this->assertNotEmpty($result['verification_token']); }
            else { $this->assertNull($result['verification_token']); }
        }
        $this->assertCount(2, $this->requests());
        $this->assertSame(['completed', 'failed'], ModelCallLog::orderBy('id')->pluck('status')->all());
    }

    public function test_invalid_structured_output_keeps_original_usage_without_second_attempt(): void
    {
        $this->configure('invalid-json');
        $result = app(AiProviderServiceImpl::class)->generateStructured('hello', '{"type":"object"}');
        $this->assertFalse($result->success);
        $this->assertSame('not JSON', $result->content);
        $this->assertSame(7, $result->totalTokens);
        $this->assertCount(1, $this->requests());
    }
    public function test_real_http_stream_records_response_id_before_timeout_and_never_reposts(): void
    {
        $ids = [];
        $stream = new \App\Services\Geo\AiVisibility\ArkResponseStream(4096, function (string $id) use (&$ids): void { $ids[] = $id; });
        try {
            app(\App\Services\Outbound\SafeOutboundHttpClient::class)->withEnforcement()->post(
                app(\App\Services\Outbound\SecureHttpFactory::class)->accept('text/event-stream')->asJson()
                    ->timeout(1)->withOptions(['sink' => $stream]),
                'http://127.0.0.1:'.$this->port.'/stream-timeout/responses', ['stream' => true], 4096,
            );
            $this->fail('Expected stream read timeout.');
        } catch (\App\Services\Outbound\OutboundRequestFailedException $e) {
            $this->assertFalse($e->beforeSend);
        }
        $this->assertSame(['test-response'], $ids);
        $this->assertNull($stream->response());
        $this->assertCount(1, $this->requests());
    }

    public function test_ingestion_reuses_saved_batches_and_unknown_batch_is_never_sent_again(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->configure('success', 'embedding');
        $base = \App\Models\KnowledgeBase::create(['tenant_id' => $this->tenant->id, 'name' => '协议知识库', 'type' => 'general']);
        $service = new \App\Services\Knowledge\DocumentIngestionService(app(AiProviderServiceImpl::class));
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('sample.txt', '测试知识库内容。');
        $document = $service->stageFile($base->id, $file, projectId: $this->execution->project_id, uploadedBy: $this->user->id);
        $this->assertSame('ready', $service->process($document)->status);
        $this->assertSame(1, $document->chunks()->count());
        $document->update(['status' => 'processing']); // Worker stopped after saving vectors, before marking ready.
        $this->assertSame('ready', $service->process($document)->status);
        $this->assertSame(1, $document->chunks()->count());
        $this->assertCount(1, $this->requests());

        $this->configure('error', 'embedding');
        $other = $service->stageFile($base->id, $file, projectId: $this->execution->project_id, uploadedBy: $this->user->id);
        foreach ([1, 2] as $attempt) {
            try { $service->process($other); $this->fail('Expected unknown embedding failure.'); }
            catch (\App\Exceptions\IngestionFailure $e) { $this->assertStringContainsString('结果未知', $e->getMessage()); }
        }
        $this->assertSame(0, $other->chunks()->count());
        $this->assertCount(2, $this->requests());
    }

    public function test_prepared_text_attempt_cannot_be_overwritten_and_unknown_is_in_usage_log(): void
    {
        $brief = new \App\Services\DTOs\SkillBrief(skillId: $this->execution->skill_id,
            projectId: $this->execution->project_id, userId: $this->user->id, executionId: $this->execution->id);
        \App\Services\Billing\PreparedModelAttempt::save($brief, 'first', 'text', []);
        try { \App\Services\Billing\PreparedModelAttempt::save($brief, 'first', 'text', []); $this->fail('Prepared call must not be sent again.'); }
        catch (\App\Services\Billing\UnresolvedModelAttempt $e) { $this->assertSame('结果未知，可手动重新执行', $e->getMessage()); }
        $this->configure('error');
        $result = app(AiProviderServiceImpl::class)->generate('hello');
        \App\Services\Billing\UnresolvedModelAttempt::fromFailure($result)->hold($this->execution, $brief->toArray());
        $this->assertSame('billing_pending', $this->execution->fresh()->status);
        $this->assertDatabaseHas('model_call_logs', ['skill_execution_id' => $this->execution->id, 'status' => 'unknown', 'total_tokens' => null]);
        $this->assertCount(1, $this->requests());
    }

}
