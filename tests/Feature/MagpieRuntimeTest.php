<?php

namespace Tests\Feature;

use App\Contracts\Outbound\HostResolver;
use App\Contracts\Outbound\OutboundTransport;
use App\Jobs\ExecuteSkillJob;
use App\Jobs\RunTaskJob;
use App\Models\Article;
use App\Models\KnowledgeBase;
use App\Models\ModelCallLog;
use App\Models\ModelConnectionProfile;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\Tenant;
use App\Models\TenantAiKey;
use App\Models\User;
use App\Services\AI\AiProviderServiceImpl;
use App\Services\AI\ModelConfiguration;
use App\Services\AI\ModelConnectionTester;
use App\Services\AI\ModelSources;
use App\Services\Billing\PreparedModelAttempt;
use App\Services\Billing\UnknownModelAttemptRecovery;
use App\Services\Billing\UnresolvedModelAttempt;
use App\Services\Contracts\AiProvider;
use App\Services\Contracts\SkillAgentRunner;
use App\Services\DTOs\SkillBrief;
use App\Services\Knowledge\DocumentIngestionService;
use App\Services\Outbound\OutboundRequestFailedException;
use App\Services\Outbound\ResolvedOutboundTarget;
use App\Services\SkillAgent\SkillModelCaller;
use App\Support\TenantContext;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class MagpieRuntimeTest extends TestCase
{
    use RefreshDatabase;

    private const TEXT_MODEL = 'route/group writing — a deliberately long gateway model identifier used to verify every receipt retains its original identity';

    private const VISION_MODEL = 'provider/model-picture';

    private Tenant $tenant;

    private User $user;

    private Project $project;

    private object $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Fixture Workspace', 'subdomain' => 'magpie-runtime', 'status' => 'active']);
        TenantContext::set($this->tenant);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'admin']);
        $this->project = Project::create(['owner_id' => $this->user->id, 'name' => 'Fixture Project']);
        config(['app.tenant_domain' => 'geo.example.test', 'geo.magpie_targets' => ['fixture' => [
            'origin' => 'http://127.0.0.1:43251', 'enabled' => true, 'tenant_ids' => [$this->tenant->id],
            'revision' => 1, 'auth_mode' => 'gateway_key',
        ]]]);
        $this->app->instance(HostResolver::class, new class implements HostResolver
        {
            public function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
        $this->transport = new class implements OutboundTransport
        {
            public array $calls = [];
            public array $models = [];
            public ?\Closure $onChat = null;

            public function send(
                PendingRequest $request,
                string $method,
                ResolvedOutboundTarget $target,
                array $data,
                int $maxBytes,
                bool $crossOrigin = false,
            ): Response {
                $path = parse_url($target->url, PHP_URL_PATH);
                $this->calls[] = [$target->host, $path, $data];
                if ($path === '/v1/chat/completions' && $this->onChat !== null) {
                    return ($this->onChat)($data);
                }

                return $this->response(match ($path) {
                    '/api/hello' => ['name' => 'magpie', 'version' => 'test-gateway'],
                    '/v1/models' => ['data' => $this->models],
                    '/v1/embeddings' => ['data' => [['index' => 0, 'embedding' => array_pad([1.0], 1024, 0.0)]],
                        'usage' => ['total_tokens' => 9]],
                    default => throw new \LogicException('Unexpected fixture request.'),
                });
            }

            public function response(array $body, int $status = 200): Response
            {
                return new Response(new PsrResponse($status, [], json_encode($body, JSON_THROW_ON_ERROR)));
            }

            public function chat(string $content): Response
            {
                return $this->response(['id' => 'gateway-fixture', 'choices' => [[
                    'message' => ['content' => $content], 'finish_reason' => 'stop',
                ]], 'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 15, 'total_tokens' => 35]]);
            }

            public function paid(): array
            {
                return array_values(array_filter($this->calls, fn (array $call): bool => $call[1] === '/v1/chat/completions'));
            }
        };
        $this->transport->models = [
            ['id' => self::TEXT_MODEL, 'modalities' => ['input' => ['text']]],
            ['id' => self::VISION_MODEL, 'modalities' => ['input' => ['text', 'image']]],
        ];
        $this->transport->onChat = fn () => $this->transport->chat('GEO gateway fixture');
        $this->app->instance(OutboundTransport::class, $this->transport);
        $this->app->instance(AiProvider::class, $this->app->make(AiProviderServiceImpl::class));
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    public function test_text_structured_output_and_connection_test_share_the_selected_full_model_identity(): void
    {
        $profile = $this->activate('text', self::TEXT_MODEL);
        $provider = app(AiProviderServiceImpl::class);
        $text = $provider->generate('Generate text');
        $this->assertTrue($text->success);
        $this->assertSame('magpie', $text->provider);
        $this->assertSame(self::TEXT_MODEL, $text->model);
        $this->assertSame(35, $text->totalTokens);
        $this->assertSame('gateway-fixture', $text->gateway['provider_request_id']);

        $this->transport->onChat = fn () => $this->transport->chat('{"title":"Demo article"}');
        $schema = '{"type":"object","required":["title"],"properties":{"title":{"type":"string"}}}';
        $structured = $provider->generateStructured('Write article', $schema);
        $this->assertTrue($structured->success);
        $this->assertSame(['title' => 'Demo article'], $structured->structuredOutput);
        $request = $this->transport->paid()[1][2];
        $this->assertStringContainsString($schema, $request['messages'][0]['content']);
        $this->assertArrayNotHasKey('response_format', $request);
        $this->assertArrayNotHasKey('enable_thinking', $request);
        $this->assertArrayNotHasKey('thinking', $request);
        $this->assertFalse($request['stream']);

        $this->transport->onChat = fn () => $this->transport->chat('{broken');
        $invalid = $provider->generateStructured('Write article', $schema);
        $this->assertFalse($invalid->success);
        $this->assertNull($invalid->structuredOutput);
        $this->assertSame(35, $invalid->totalTokens);
        $this->assertCount(3, $this->transport->paid());

        $this->transport->onChat = fn () => $this->transport->chat('{"title":[] }');
        $invalidShape = $provider->generateStructured('Write article', $schema);
        $this->assertFalse($invalidShape->success);
        $this->assertNull($invalidShape->structuredOutput);
        $this->assertStringContainsString('数据结构', $invalidShape->errorMessage);
        $this->assertSame(35, $invalidShape->totalTokens);

        $this->transport->onChat = fn () => $this->transport->chat('OK');
        $this->assertTrue(app(ModelConnectionTester::class)->testProfile($profile, $this->user->id)['success']);
        $log = ModelCallLog::query()->sole();
        $this->assertSame('magpie', $log->provider);
        $this->assertSame(self::TEXT_MODEL, $log->model);
        $this->assertSame(35, $log->total_tokens);
        $this->assertSame(array_fill(0, 5, self::TEXT_MODEL), array_column(array_column($this->transport->paid(), 2), 'model'));
    }

    public function test_legacy_report_rejects_incomplete_magpie_json_and_records_the_real_usage(): void
    {
        config(['geo.analysis_pipeline_mode' => 'legacy', 'geo.analysis_async' => false]);
        $this->activate('text', self::TEXT_MODEL);
        $this->transport->onChat = fn () => $this->transport->chat('{"summary":"Incomplete JSON object"}');
        Sanctum::actingAs($this->user, ['*']);
        $this->withServerVariables(['HTTP_HOST' => $this->tenant->subdomain.'.geo.example.test'])
            ->withHeader('X-Tenant', $this->tenant->subdomain)
            ->postJson('/api/v1/projects/'.$this->project->id.'/analyses');

        $execution = SkillExecution::withoutGlobalScopes()->sole();
        $this->assertSame('failed', $execution->status);
        $this->assertStringContainsString('数据结构', $execution->error_message);
        $this->assertNull($execution->article_id);
        $this->assertSame(0, Article::withoutGlobalScopes()->count());
        $log = ModelCallLog::withoutGlobalScopes()->sole();
        $this->assertSame('magpie', $log->provider);
        $this->assertSame(35, $log->total_tokens);
        $this->assertCount(1, $this->transport->paid());

        $this->transport->onChat = fn () => $this->transport->chat(json_encode([
            'summary' => 'Complete fixture report', 'key_findings' => ['Finding'],
            'recommendations' => ['Recommendation'], 'next_actions' => ['Action'],
        ], JSON_THROW_ON_ERROR));
        $this->postJson('/api/v1/projects/'.$this->project->id.'/analyses')->assertCreated();
        $completed = SkillExecution::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame('completed', $completed->status);
        $this->assertSame('Complete fixture report', $completed->output_data['analysis']['summary']);
        $this->assertNotNull($completed->article_id);
        $this->assertCount(2, $this->transport->paid());
    }

    public function test_vision_status_and_upload_recognize_pdf_using_magpie_with_legacy_embeddings(): void
    {
        Storage::fake('local');
        config(['geo.knowledge.vision_enabled' => true]);
        $profile = $this->activate('vision', self::VISION_MODEL);
        $legacy = TenantAiKey::create(['slot' => 'embedding', 'provider' => 'custom', 'is_active' => true,
            'encrypted_key' => 'sk-test-vector', 'metadata' => ['base_url' => 'https://models.example.test/v1',
                'model' => 'legacy-vector', 'dimensions' => 1024]]);
        $this->assertNull(app(ModelConfiguration::class)->active('vision'));
        $base = KnowledgeBase::create(['project_id' => $this->project->id, 'name' => 'Fixture Materials', 'status' => 'active']);
        Sanctum::actingAs($this->user, ['*']);
        $this->withServerVariables(['HTTP_HOST' => $this->tenant->subdomain.'.geo.example.test'])
            ->withHeader('X-Tenant', $this->tenant->subdomain)
            ->getJson('/api/v1/knowledge-bases?project_id='.$this->project->id)
            ->assertOk()->assertJsonPath('model_configuration.vision_configured', true);
        TenantContext::set($this->tenant);
        $this->transport->onChat = fn () => $this->transport->chat('Synthetic visual receipt with reference 42 and text for vector retrieval.');
        $ingestion = app(DocumentIngestionService::class);
        $upload = UploadedFile::fake()->createWithContent('synthetic.pdf', $this->syntheticPdf());
        $document = $ingestion->ingestFile($base->id, $upload, 'Synthetic PDF', $this->project->id, $this->user->id);
        $this->assertSame('ready', $document->status);
        $this->assertSame([1], $document->metadata['extraction']['vision_pages']);
        $this->assertStringContainsString('Synthetic visual receipt', $document->chunks()->firstOrFail()->content);
        $vision = $this->transport->paid();
        $this->assertCount(1, $vision);
        $this->assertSame(self::VISION_MODEL, $vision[0][2]['model']);
        $picture = $vision[0][2]['messages'][1]['content'][0]['image_url']['url'];
        $this->assertStringStartsWith('data:image/jpeg;base64,', $picture);
        $vector = array_values(array_filter($this->transport->calls, fn (array $call): bool => $call[1] === '/v1/embeddings'));
        $this->assertSame('models.example.test', $vector[0][0]);
        $this->assertSame($legacy->metadata['model'], $vector[0][2]['model']);
        $this->assertSame(1024, $vector[0][2]['dimensions']);

        foreach ([['modalities' => ['input' => ['text']]], []] as $index => $metadata) {
            $this->transport->models[1] = ['id' => self::VISION_MODEL] + $metadata;
            $provider = app(AiProviderServiceImpl::class);
            $result = $provider->generate('Read picture', options: ['images' => [$picture]]);
            $this->assertFalse($result->success);
            $this->assertFalse($result->gateway['request_sent']);
            $message = $index === 0 ? '不支持图片输入' : '未确认';
            $this->assertStringContainsString($message, $result->errorMessage);
            $this->assertStringContainsString($message, app(ModelConnectionTester::class)->testProfile($profile, $this->user->id)['message']);
        }
        $this->assertCount(1, $this->transport->paid());
    }

    public function test_queue_submission_and_late_reply_keep_the_original_connection_and_stop_the_next_section(): void
    {
        $profile = $this->activate('text', self::TEXT_MODEL);
        $brief = $this->queuedBrief();
        $execution = SkillExecution::findOrFail($brief->executionId);
        $this->assertSame(self::TEXT_MODEL, $execution->model_connection_snapshot['model']);
        $this->assertStringNotContainsString('sk-test-magpie', json_encode($execution->model_connection_snapshot));
        $execution->update(['status' => 'running']);
        $this->transport->onChat = function () use ($profile): Response {
            app(ModelSources::class)->save(['api_key' => 'sk-test-edited'], $profile->id);

            return $this->transport->chat(str_repeat('Section text with evidence. ', 10));
        };
        $caller = app(SkillModelCaller::class);
        [$first] = $caller->generateAndRecord('First section', 'Fixture', 512, 'text', $brief, ['chunk' => 'one'], allowEscalation: false);
        $this->assertTrue($first->success);
        $log = ModelCallLog::query()->sole();
        $this->assertSame('magpie', $log->provider);
        $this->assertSame(self::TEXT_MODEL, $log->model);
        $this->assertSame(35, $log->total_tokens);
        $this->assertSame($execution->model_connection_snapshot['credential_fingerprint'], $first->gateway['credential_fingerprint']);
        [$second] = $caller->generateAndRecord('Second section', 'Fixture', 512, 'text', $brief, ['chunk' => 'two'], allowEscalation: false);
        $this->assertFalse($second->success);
        $this->assertFalse($second->gateway['request_sent']);
        $this->assertStringContainsString('模型连接已变更', $second->errorMessage);
        $this->assertCount(1, $this->transport->paid());
        $this->assertDatabaseCount('model_call_logs', 1);
    }

    public function test_scheduled_job_keeps_its_own_workspace_selection_and_never_falls_back_after_switch(): void
    {
        Queue::fake();
        TenantAiKey::create(['slot' => 'text', 'provider' => 'custom', 'is_active' => true,
            'encrypted_key' => 'sk-test-direct', 'metadata' => ['base_url' => 'https://models.example.test/v1', 'model' => 'direct-text']]);
        $this->activate('text', self::TEXT_MODEL);
        Task::create(['project_id' => $this->project->id, 'user_id' => $this->user->id, 'name' => 'Scheduled fixture',
            'skill_id' => 'yao-geo-intent-miner', 'cadence' => Task::CADENCE_DAILY, 'timezone' => 'UTC',
            'input_data' => ['brand_name' => 'Fixture'], 'status' => Task::STATUS_ACTIVE, 'next_run_at' => now()->subMinute()]);
        $other = Tenant::create(['name' => 'Second Workspace', 'subdomain' => 'magpie-runtime-other', 'status' => 'active']);
        TenantContext::set($other);
        $this->assertSame(0, Artisan::call('tasks:tick'));
        $this->assertSame($other->id, TenantContext::id());
        $scheduled = Queue::pushed(RunTaskJob::class)->sole();
        $this->assertSame($this->tenant->id, $scheduled->modelConnectionSnapshot['tenant_id']);
        $this->assertSame(self::TEXT_MODEL, $scheduled->modelConnectionSnapshot['model']);

        TenantContext::set($this->tenant);
        $this->queuedBrief();
        $interactive = Queue::pushed(ExecuteSkillJob::class)->sole();
        app(ModelSources::class)->bind('text', ['source_type' => 'legacy']);
        $this->app->call([$scheduled, 'handle']);
        $this->assertFalse(TenantContext::has());
        $this->app->call([$interactive, 'handle']);
        $this->assertFalse(TenantContext::has());
        $execution = SkillExecution::withoutGlobalScopes()->findOrFail($interactive->executionId);
        $this->assertSame('failed', $execution->status);
        $this->assertStringContainsString('模型连接已变更', $execution->error_message);
        $run = TaskRun::withoutGlobalScopes()->sole();
        $this->assertSame(TaskRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('模型连接已变更', $run->error_message);
        $this->assertSame([], $this->transport->paid());
        $this->assertDatabaseCount('model_call_logs', 0);
    }

    #[DataProvider('unknownResponses')]
    public function test_unknown_attempt_is_never_replayed_and_never_creates_zero_usage(string $failure): void
    {
        $this->activate('text', self::TEXT_MODEL);
        $brief = $this->queuedBrief();
        $execution = SkillExecution::findOrFail($brief->executionId);
        $execution->update(['status' => 'running']);
        $this->transport->onChat = fn () => match ($failure) {
            'timeout' => throw new OutboundRequestFailedException,
            'truncated' => new Response(new PsrResponse(200, [], '{"choices":')),
            'invalid' => $this->transport->response(['choices' => [false]]),
            default => $this->transport->response(['error' => ['message' => 'test outcome unknown']], 502),
        };
        $caller = app(SkillModelCaller::class);

        try {
            $caller->generateAndRecord('First section', 'Fixture', 512, 'text', $brief);
            $this->fail('An unconfirmed model response must be held for review.');
        } catch (UnresolvedModelAttempt $e) {
            $e->hold($execution, $brief->toArray());
        }

        $log = ModelCallLog::query()->sole();
        $this->assertSame('unknown', $log->status);
        $this->assertNull($log->total_tokens);
        $this->assertSame(self::TEXT_MODEL, $log->model);
        $this->assertGreaterThan(0, data_get($log->metadata, 'gateway.duration_seconds'));
        $this->assertTrue(data_get($log->metadata, 'gateway.request_sent'));
        $this->assertSame('unknown_manual_review', app(UnknownModelAttemptRecovery::class)->recover($execution->id, true)['status']);
        $execution->refresh()->update(['status' => 'running']);
        try {
            $caller->generateAndRecord('First section', 'Fixture', 512, 'text', $brief);
            $this->fail('An unresolved original attempt must prevent a second paid request.');
        } catch (UnresolvedModelAttempt) {
            $this->assertCount(1, $this->transport->paid());
        }
    }

    public static function unknownResponses(): array
    {
        return ['timeout' => ['timeout'], 'truncated body' => ['truncated'],
            'invalid body format' => ['invalid'], 'HTTP server error' => ['http']];
    }

    public function test_known_result_recovers_usage_locally_after_the_magpie_source_is_revoked(): void
    {
        $this->activate('text', self::TEXT_MODEL);
        $brief = $this->queuedBrief();
        $execution = SkillExecution::findOrFail($brief->executionId);
        $execution->update(['status' => 'running']);
        $id = PreparedModelAttempt::save($brief, 'single:primary', 'text', []);
        $result = app(AiProviderServiceImpl::class)->generate('Fixture prompt', options: [
            'execution_id' => $execution->id, 'task_id' => 'skill:'.$execution->id,
            'feature_id' => $brief->skillId, 'client_request_id' => $id,
        ]);
        $this->assertTrue($result->success);
        PreparedModelAttempt::resolved($execution->id, $id, $result);
        $execution->refresh()->update(['status' => 'billing_pending']);

        config(['geo.magpie_targets.fixture.enabled' => false]);
        app(ModelSources::class)->bind('text', ['source_type' => 'disabled']);
        $calls = count($this->transport->calls);
        $recovery = app(UnknownModelAttemptRecovery::class)->recover($execution->id, true);
        $this->assertSame('usage_recorded_delivery_pending', $recovery['status']);
        $log = ModelCallLog::query()->sole();
        $this->assertSame(self::TEXT_MODEL, $log->model);
        $this->assertSame(35, $log->total_tokens);
        $this->assertCount($calls, $this->transport->calls);
    }

    private function activate(string $slot, string $model): ModelConnectionProfile
    {
        $sources = app(ModelSources::class);
        $profile = $sources->save(['slot' => $slot, 'model' => $model, 'target_alias' => 'fixture', 'api_key' => 'sk-test-magpie']);
        $sources->bind($slot, ['source_type' => 'profile', 'profile_id' => $profile->id]);

        return $profile;
    }

    private function queuedBrief(): SkillBrief
    {
        Queue::fake();
        $brief = new SkillBrief('yao-geo-intent-miner', $this->project->id, $this->user->id, ['brand_name' => 'Fixture']);
        $id = app(SkillAgentRunner::class)->executeAsync($brief);

        return SkillBrief::fromArray(array_replace($brief->toArray(), ['executionId' => $id]));
    }

    /** Small PDF with one image and no text layer, built from synthetic pixel data. */
    private function syntheticPdf(): string
    {
        $image = '<</Type /XObject /Subtype /Image /Width 2 /Height 2 /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /ASCIIHexDecode /Length 9>>'
            ."\nstream\n00ffffff>\nendstream";
        $drawing = 'q 200 0 0 200 0 0 cm /Im1 Do Q';
        $objects = [
            '<</Type /Catalog /Pages 2 0 R>>',
            '<</Type /Pages /Kids [3 0 R] /Count 1>>',
            '<</Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Resources <</XObject <</Im1 4 0 R>>>> /Contents 5 0 R>>',
            $image,
            '<</Length '.strlen($drawing).">>\nstream\n".$drawing."\nendstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = "0000000000 65535 f \n";
        foreach ($objects as $index => $object) {
            $offsets .= sprintf("%010d 00000 n \n", strlen($pdf));
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }

        return $pdf."xref\n0 6\n".$offsets."trailer\n<</Size 6 /Root 1 0 R>>\nstartxref\n".strlen($pdf)."\n%%EOF\n";
    }
}
