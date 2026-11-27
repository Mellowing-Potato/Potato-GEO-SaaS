<?php

namespace Tests\Feature;

use App\Contracts\Outbound\HostResolver;
use App\Contracts\Outbound\OutboundTransport;
use App\Jobs\RunAiVisibilityJob;
use App\Models\AiVisibilityRun;
use App\Models\ModelCallLog;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TenantAiKey;
use App\Models\User;
use App\Services\Geo\AiVisibility\AiVisibilityService;
use App\Services\Outbound\OutboundRequestFailedException;
use App\Services\Outbound\ResolvedOutboundTarget;
use App\Support\TenantContext;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Real queue job/service/client with only DNS and supplier transport replaced. */
class AiVisibilityConnectionSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Project $project;
    private TenantAiKey $key;
    private object $transport;

    protected function setUp(): void
    {
        parent::setUp();
        config(['geo.visibility.allowed_hosts' => ['ark.example.test', 'other.example.test']]);
        $this->tenant = Tenant::create(['name' => '监测快照测试', 'subdomain' => 'visibility-snapshot', 'status' => 'active']);
        TenantContext::set($this->tenant);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'admin']);
        $this->project = Project::create(['owner_id' => $this->user->id, 'name' => '测试项目', 'brand_name' => '测试品牌']);
        $this->key = TenantAiKey::create(['slot' => 'visibility', 'provider' => 'volces',
            'encrypted_key' => 'sk-test-visibility-original', 'is_active' => true,
            'metadata' => ['base_url' => 'https://ark.example.test/api/v3', 'model' => 'test-model-original']]);
        TenantContext::forget();
        Queue::fake([RunAiVisibilityJob::class]);
        $this->app->instance(HostResolver::class, new class implements HostResolver {
            public function resolve(string $host): array { return ['93.184.216.34']; }
        });
        $this->transport = new class implements OutboundTransport {
            public array $requests = [];
            public bool $interrupt = false;

            public function send(PendingRequest $request, string $method, ResolvedOutboundTarget $target,
                array $data, int $maxBytes, bool $crossOrigin = false): Response
            {
                $this->requests[] = ['method' => $method, 'url' => $target->url, 'body' => $data];
                $payload = ['id' => 'resp_snapshot_original', 'status' => 'completed', 'model' => 'test-model-original',
                    'output' => [['type' => 'web_search_call', 'action' => ['query' => '测试品牌']],
                        ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '测试回答', 'annotations' => []]]]],
                    'usage' => ['input_tokens' => 8, 'output_tokens' => 4]];
                if ($method === 'GET') {
                    return new Response(new PsrResponse(200, ['Content-Type' => 'application/json'], json_encode($payload)));
                }
                $options = $request->getOptions();
                $response = new PsrResponse(200, ['Content-Type' => 'text/event-stream']);
                ($options['on_headers'])($response);
                $options['sink']->write('data: '.json_encode(['type' => 'response.created', 'response' => ['id' => $payload['id']]])."\n\n");
                if ($this->interrupt) {
                    throw new OutboundRequestFailedException;
                }
                $options['sink']->write('data: '.json_encode(['type' => 'response.completed', 'response' => $payload])."\n\n");

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

    public static function changedIdentities(): array
    {
        return ['model' => ['model'], 'credential' => ['key'], 'endpoint' => ['endpoint']];
    }

    #[DataProvider('changedIdentities')]
    public function test_queued_configuration_change_fails_before_any_supplier_request(string $field): void
    {
        [$run] = $this->queueQuestions(['排队的问题']);
        $this->changeIdentity($field);

        $this->execute($run);

        $this->assertSame([], $this->transport->requests);
        $this->assertStoppedWithoutSpend($run);
        $this->assertStringContainsString('模型连接已变更', $run->fresh()->error_message);
        $this->assertSame('test-model-original', $run->fresh()->model);
        $this->assertSame(0, ModelCallLog::withoutGlobalScopes()->count());
    }

    #[DataProvider('changedIdentities')]
    public function test_later_questions_cannot_switch_connection_after_partial_batch_completion(string $field): void
    {
        [$first, $second] = $this->queueQuestions(['第一个问题', '第二个问题']);
        $this->execute($first);
        $this->changeIdentity($field);
        $this->execute($second);

        $this->assertSame(['POST'], array_column($this->transport->requests, 'method'));
        $this->assertSame(AiVisibilityRun::STATUS_COMPLETED, $first->fresh()->status);
        $this->assertStoppedWithoutSpend($second);
        $this->assertSame('test-model-original', $this->transport->requests[0]['body']['model']);
        $this->assertSame(1, ModelCallLog::withoutGlobalScopes()->count());
        $this->assertSame('test-model-original', ModelCallLog::withoutGlobalScopes()->firstOrFail()->model);
        $report = app(AiVisibilityService::class)->buildMarkdownReport($first->fresh());
        $this->assertStringContainsString('test-model-original', $report);
        $this->assertStringNotContainsString('test-model-changed', $report);
    }

    public function test_unchanged_batch_uses_one_hidden_nonsecret_snapshot_and_completes(): void
    {
        $runs = $this->queueQuestions(['第一个问题', '第二个问题']);
        $snapshot = $runs[0]->model_connection_snapshot;
        $this->assertIsArray($snapshot);
        $this->assertSame('test-model-original', $snapshot['model']);
        $this->assertSame($this->tenant->id, $snapshot['tenant_id']);
        $this->assertSame($this->key->id, $snapshot['connection_id']);
        $this->assertSame(hash('sha256', 'https://ark.example.test/api/v3'), $snapshot['endpoint_fingerprint']);
        $this->assertArrayNotHasKey('api_key', $snapshot);
        $this->assertArrayNotHasKey('base_url', $snapshot);

        foreach ($runs as $run) {
            $this->assertSame($snapshot, $run->model_connection_snapshot);
            $raw = DB::table('ai_visibility_runs')->where('id', $run->id)->value('model_connection_snapshot');
            $this->assertStringNotContainsString('sk-test-visibility-original', $raw);
            $this->assertStringNotContainsString('ark.example.test', $raw);
            $this->assertArrayNotHasKey('model_connection_snapshot', $run->toArray());
            $this->execute($run);
            $this->assertSame(AiVisibilityRun::STATUS_COMPLETED, $run->fresh()->status);
            $this->assertSame('test-model-original', $run->fresh()->model);
        }
        $this->assertSame(['POST', 'POST'], array_column($this->transport->requests, 'method'));
        $this->assertSame(2, ModelCallLog::withoutGlobalScopes()->count());
    }

    public function test_configuration_change_during_batch_creation_does_not_split_queued_identity(): void
    {
        $changed = false;
        AiVisibilityRun::created(function () use (&$changed): void {
            if (! $changed) {
                $changed = true;
                $this->changeIdentity('model');
            }
        });
        $runs = $this->queueQuestions(['第一个问题', '第二个问题']);
        $this->assertTrue($changed);
        foreach ($runs as $run) {
            $this->assertSame('test-model-original', $run->model);
            $this->assertSame($runs[0]->model_connection_snapshot, $run->model_connection_snapshot);
            $this->execute($run);
            $this->assertStoppedWithoutSpend($run);
        }
        $this->assertSame([], $this->transport->requests);
    }

    #[DataProvider('changedIdentities')]
    public function test_configuration_change_while_persisting_receipt_stops_before_post(string $field): void
    {
        [$run] = $this->queueQuestions(['准备发送的问题']);
        $changed = false;
        AiVisibilityRun::updated(function (AiVisibilityRun $updated) use (&$changed, $field): void {
            if (! $changed && data_get($updated->metadata, 'provider_attempt.request_id')) {
                $changed = true;
                $this->changeIdentity($field);
            }
        });

        $this->execute($run);

        $this->assertTrue($changed, 'The race must occur after the durable receipt checkpoint.');
        $this->assertSame([], $this->transport->requests);
        $this->assertStoppedWithoutSpend($run);
        $this->assertSame(0, ModelCallLog::withoutGlobalScopes()->count());
    }

    public function test_historical_pending_run_without_snapshot_cannot_spend_current_credentials(): void
    {
        [$run] = $this->queueQuestions(['旧任务']);
        $run->update(['model_connection_snapshot' => null]);

        $this->execute($run);

        $this->assertStoppedWithoutSpend($run);
        $this->assertStringContainsString('缺少入队时的模型连接记录', $run->fresh()->error_message);
        $this->assertSame([], $this->transport->requests);
    }

    public function test_jsonb_reordered_snapshot_preserves_matching_identity(): void
    {
        [$run] = $this->queueQuestions(['数据库回读的问题']);
        $snapshot = $run->model_connection_snapshot;
        $this->assertIsArray($snapshot);
        $run->update(['model_connection_snapshot' => array_reverse($snapshot, true)]);

        $this->execute($run);

        $this->assertSame(AiVisibilityRun::STATUS_COMPLETED, $run->fresh()->status);
        $this->assertSame(['POST'], array_column($this->transport->requests, 'method'));
    }

    public function test_old_receipt_without_snapshot_recovers_only_by_get_after_original_identity_is_restored(): void
    {
        [$run] = $this->queueQuestions(['未知结果的问题']);
        $this->transport->interrupt = true;
        $this->execute($run);
        $this->assertSame(AiVisibilityRun::STATUS_RECONCILIATION_REQUIRED, $run->fresh()->status);
        $this->assertSame('resp_snapshot_original', data_get($run->fresh()->metadata, 'provider_attempt.provider_request_id'));
        $run->update(['model_connection_snapshot' => null]);

        $this->changeIdentity('key');
        $this->execute($run); // An ordinary queue delivery must not repost an unresolved run.
        Artisan::call('geo:reconcile-ai-visibility');
        $this->assertSame(['POST'], array_column($this->transport->requests, 'method'));
        $this->assertSame(AiVisibilityRun::STATUS_RECONCILIATION_REQUIRED, $run->fresh()->status);

        $this->key->update(['encrypted_key' => 'sk-test-visibility-original']);
        Artisan::call('geo:reconcile-ai-visibility');
        $this->assertSame(AiVisibilityRun::STATUS_COMPLETED, $run->fresh()->status);
        $this->assertSame(['POST', 'GET'], array_column($this->transport->requests, 'method'));
        $this->assertStringEndsWith('/responses/resp_snapshot_original', $this->transport->requests[1]['url']);
        $this->assertSame('test-model-original', $run->fresh()->model);
        $this->assertSame(1, ModelCallLog::withoutGlobalScopes()->count());
    }

    private function queueQuestions(array $questions): array
    {
        TenantContext::set($this->tenant);
        try {
            $batch = app(AiVisibilityService::class)->createRuns($this->user->id, $this->project->id, $questions);
            Queue::assertPushed(RunAiVisibilityJob::class, count($questions));

            return $batch['runs'];
        } finally {
            TenantContext::forget();
        }
    }

    private function execute(AiVisibilityRun $run): void
    {
        (new RunAiVisibilityJob($run->id, $this->tenant->id))->handle(app(AiVisibilityService::class));
        $this->assertFalse(TenantContext::has());
    }

    private function changeIdentity(string $field): void
    {
        if ($field === 'key') {
            $this->key->update(['encrypted_key' => 'sk-test-visibility-changed']);
        } else {
            $metadata = $this->key->fresh()->metadata;
            $metadata[$field === 'endpoint' ? 'base_url' : 'model'] = $field === 'endpoint'
                ? 'https://other.example.test/api/v3' : 'test-model-changed';
            $this->key->update(['metadata' => $metadata]);
        }
    }

    private function assertStoppedWithoutSpend(AiVisibilityRun $run): void
    {
        $run = $run->fresh();
        $this->assertSame(AiVisibilityRun::STATUS_FAILED, $run->status);
        $this->assertNotNull($run->completed_at);
        $this->assertNull(data_get($run->metadata, 'model_client_request_id'));
        $this->assertNull(data_get($run->metadata, 'provider_attempt'));
        $this->assertNull(data_get($run->metadata, 'model_call_log_id'));
        $this->assertSame(0, $run->prompt_tokens);
        $this->assertSame(0, $run->completion_tokens);
        $this->assertNull($run->answer_text);
    }
}
