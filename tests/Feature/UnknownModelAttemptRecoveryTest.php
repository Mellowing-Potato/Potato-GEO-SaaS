<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ModelCallLog;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\UnknownModelAttemptRecovery;
use App\Services\Billing\UnrecordedModelResult;
use App\Services\DTOs\AiProviderCallResult;
use App\Services\DTOs\SkillBrief;
use App\Services\Contracts\AiProvider;
use App\Services\SkillAgent\RenderBridgeService;
use App\Services\SkillAgent\RenderResult;
use App\Services\SkillAgent\SkillRunPreparer;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UnknownModelAttemptRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private ?string $skillsRoot = null;

    private array $previousSkillsRepoPath = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        if ($this->skillsRoot !== null) {
            [$process, $environment, $server] = $this->previousSkillsRepoPath;
            putenv($process === false ? 'SKILLS_REPO_PATH' : 'SKILLS_REPO_PATH='.$process);
            if ($environment === null) {
                unset($_ENV['SKILLS_REPO_PATH']);
            } else {
                $_ENV['SKILLS_REPO_PATH'] = $environment;
            }
            if ($server === null) {
                unset($_SERVER['SKILLS_REPO_PATH']);
            } else {
                $_SERVER['SKILLS_REPO_PATH'] = $server;
            }
            File::deleteDirectory($this->skillsRoot);
        }
        parent::tearDown();
    }

    /**
     * The upstream skill source copy is not versioned in this repository. Give the
     * skill an installed directory so the render pipeline is selected without
     * depending on whichever source copy the test machine happens to have.
     */
    private function installUpstreamSkillDirectory(string $skillId): void
    {
        $this->previousSkillsRepoPath = [getenv('SKILLS_REPO_PATH'), $_ENV['SKILLS_REPO_PATH'] ?? null, $_SERVER['SKILLS_REPO_PATH'] ?? null];
        $this->skillsRoot = sys_get_temp_dir().'/unknown-attempt-skills-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->skillsRoot.'/'.$skillId);
        putenv('SKILLS_REPO_PATH='.$this->skillsRoot);
        $_ENV['SKILLS_REPO_PATH'] = $this->skillsRoot;
        $_SERVER['SKILLS_REPO_PATH'] = $this->skillsRoot;
    }

    public function test_read_only_probe_and_apply_use_original_attempt_once_and_keep_output_sealed(): void
    {
        $execution = $this->unknownExecution();
        $receipt = $this->settledReceipt($execution);
        $this->saveLocalResult($execution);
        Http::fake();

        $recovery = app(UnknownModelAttemptRecovery::class);
        $this->assertSame('ready_for_usage_recovery', $recovery->recover($execution->id)['status']);
        $this->assertSame(0, ModelCallLog::query()->count());
        Http::assertNothingSent();

        $result = $recovery->recover($execution->id, true);
        $this->assertSame('usage_recorded_delivery_pending', $result['status']);
        $log = ModelCallLog::query()->sole();
        $this->assertSame('gateway-original', $log->gateway_request_id);
        $this->assertSame('completed', $log->status);
        $this->assertSame('original-attempt', data_get($log->metadata, 'gateway.attribution.client_request_id'));
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $saved = $execution->fresh();
        $this->assertSame('billing_pending', $saved->status);
        $this->assertSame('model_result_recorded', data_get($saved->billing_pending_result, 'kind'));
        $this->assertSame($execution->id, data_get($saved->billing_pending_result, 'brief_data.executionId'));
        $this->assertSame('Recovered but not delivered', data_get($saved->billing_pending_result, 'model_result.content'));
        $this->assertEmpty($saved->output_data);
        $this->assertNull($saved->article_id);
        $this->assertSame(0, Article::query()->count());
        Sanctum::actingAs($saved->user, ['*']);
        $tenant = Tenant::query()->findOrFail($saved->user->tenant_id);
        $response = $this->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain)
            ->getJson('/api/v1/executions/'.$saved->id.'/status')
            ->assertOk()->assertJsonPath('execution.billing.recovery_stage', 'model_result_recorded');
        $this->assertStringNotContainsString('Recovered but not delivered', $response->getContent());
        $this->assertSame('usage_recorded_delivery_pending', $recovery->recover($execution->id, true)['status']);
        $this->assertSame(1, ModelCallLog::query()->count());
        Http::assertNothingSent();
        $saved->update(['status' => 'cancelled']);
        $this->assertSame('cancelled_manual_review', $recovery->recover($execution->id, true)['status']);
        $saved->update(['status' => 'completed']);
        $this->assertSame('state_conflict', $recovery->recover($execution->id, true)['status']);
        $other = $this->unknownExecution();
        $other->update(['billing_pending_result' => $snapshot = $saved->billing_pending_result]);
        $this->assertSame('receipt_unconfirmed', $recovery->recover($other->id, true)['status']);
        $other->update(['billing_pending_result' => array_replace($snapshot, ['model_call_log_id' => 999999])]);
        $this->assertSame('receipt_unconfirmed', $recovery->recover($other->id, true)['status']);
        Http::assertNothingSent();
    }

    public function test_successful_result_lost_before_local_usage_write_is_compared_then_recovered(): void
    {
        $execution = $this->unknownExecution();
        $this->saveLocalResult($execution);
        $snapshot = $execution->fresh()->billing_pending_result;
        $this->assertSame('model_result_unrecorded', $snapshot['kind']);
        Http::fake();
        $recovery = app(UnknownModelAttemptRecovery::class);
        $this->assertSame('ready_for_usage_recovery', $recovery->recover($execution->id)['status']);
        $this->assertDatabaseCount('model_call_logs', 0);
        $this->assertSame('usage_recorded_delivery_pending', $recovery->recover($execution->id, true)['status']);
        $this->assertSame('model_result_recorded', $execution->fresh()->billing_pending_result['kind']);
        $this->assertSame('Recovered but not delivered', $execution->fresh()->billing_pending_result['model_result']['content']);
        $this->assertDatabaseCount('model_call_logs', 1);
        $this->assertDatabaseCount('articles', 0);
        Http::assertNothingSent();
    }

    public function test_recorded_single_call_resumes_delivery_without_a_second_supplier_call(): void
    {
        $execution = $this->unknownExecution();
        $snapshot = $execution->billing_pending_result;
        $brief = SkillBrief::fromArray($snapshot['brief_data']);
        $inputs = app(SkillRunPreparer::class)->deliveryInputs($brief);
        $snapshot['delivery_context'] = [
            'path' => 'single', 'attempt' => 1,
            'asset_fingerprint' => $inputs['fingerprint'],
            'primary_model' => 'text', 'prior_tokens' => 0,
        ];
        $execution->update(['billing_pending_result' => $snapshot]);
        $receipt = $this->settledReceipt($execution);
        $body = '# Recovered report'.str_repeat("\n\nA verified result paragraph.", 8);
        $receipt['outcome']['delivery']['choices'][0]['message']['content'] = $body;
        $this->saveLocalResult($execution, $body);
        Http::fake();

        $recovery = app(UnknownModelAttemptRecovery::class);
        $this->assertSame('ready_for_usage_recovery', $recovery->recover($execution->id)['status']);
        $this->assertSame(0, ModelCallLog::query()->count());
        $this->assertSame(0, Article::query()->count());
        Http::assertNothingSent();
        $this->assertSame('delivery_completed', $recovery->recover($execution->id, true)['status']);
        $saved = $execution->fresh();
        $this->assertSame('completed', $saved->status);
        $this->assertSame($body, data_get($saved->output_data, 'result_text'));
        $this->assertNotNull($saved->article_id);
        $this->assertSame(1, Article::query()->count());
        $this->assertSame(1, ModelCallLog::query()->count());
        $this->assertSame('not_applicable', $recovery->recover($execution->id, true)['status']);
        Http::assertNothingSent();
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_prepared_and_unknown_attempts_stay_pending_without_any_provider_request(): void
    {
        $execution = $this->unknownExecution();
        Http::fake();
        foreach (['model_attempt_prepared', 'model_attempt_unknown'] as $kind) {
            $snapshot = $execution->fresh()->billing_pending_result;
            $snapshot['kind'] = $kind;
            $execution->update(['billing_pending_result' => $snapshot]);
            foreach ([false, true] as $apply) {
                $this->assertSame('unknown_manual_review', app(UnknownModelAttemptRecovery::class)->recover($execution->id, $apply)['status']);
            }
            $this->assertSame($kind, $execution->fresh()->billing_pending_result['kind']);
        }
        $this->assertDatabaseCount('model_call_logs', 0);
        $this->assertDatabaseCount('articles', 0);
        Http::assertNothingSent();
    }

    public function test_recorded_single_call_with_changed_assets_stays_sealed(): void
    {
        $execution = $this->unknownExecution();
        $snapshot = $execution->billing_pending_result;
        $snapshot['delivery_context'] = [
            'path' => 'single', 'attempt' => 1,
            'asset_fingerprint' => str_repeat('0', 64),
            'primary_model' => 'text', 'prior_tokens' => 0,
        ];
        $execution->update(['billing_pending_result' => $snapshot]);
        $this->saveLocalResult($execution, 'Recovered but not delivered');
        Http::fake();

        $recovery = app(UnknownModelAttemptRecovery::class);
        $this->assertSame('delivery_context_unavailable', $recovery->recover($execution->id, true)['status']);
        $this->assertSame('billing_pending', $execution->fresh()->status);
        $this->assertEmpty($execution->fresh()->output_data);
        $this->assertSame(0, Article::query()->count());
        Http::assertNothingSent();
    }

    public function test_recorded_result_delivers_without_a_wallet_receipt(): void
    {
        $execution = $this->unknownExecution();
        $snapshot = $execution->billing_pending_result;
        $inputs = app(SkillRunPreparer::class)->deliveryInputs(SkillBrief::fromArray($snapshot['brief_data']));
        $snapshot['delivery_context'] = [
            'path' => 'single', 'attempt' => 1,
            'asset_fingerprint' => $inputs['fingerprint'],
            'primary_model' => 'text', 'prior_tokens' => 0,
        ];
        $execution->update(['billing_pending_result' => $snapshot]);
        $receipt = $this->settledReceipt($execution);
        $receipt['outcome']['delivery']['choices'][0]['message']['content'] = '# Held report'.str_repeat(' verified', 15);
        $this->saveLocalResult($execution, $receipt['outcome']['delivery']['choices'][0]['message']['content']);
        Http::fake();
        $recovery = app(UnknownModelAttemptRecovery::class);
        $this->assertSame('delivery_completed', $recovery->recover($execution->id, true)['status']);
        $this->assertSame('not_applicable', $recovery->recover($execution->id, true)['status']);
        $this->assertSame(1, ModelCallLog::query()->count());
        $this->assertSame('completed', $execution->fresh()->status);
        $this->assertSame(1, Article::query()->count());
        Http::assertNothingSent();
    }

    public function test_recorded_result_from_disabled_customer_debit_still_requires_usage_but_can_deliver(): void
    {
        $execution = $this->unknownExecution();
        $snapshot = $execution->billing_pending_result;
        $inputs = app(SkillRunPreparer::class)->deliveryInputs(SkillBrief::fromArray($snapshot['brief_data']));
        $snapshot['delivery_context'] = [
            'path' => 'single', 'attempt' => 1,
            'asset_fingerprint' => $inputs['fingerprint'],
            'primary_model' => 'text', 'prior_tokens' => 0,
            'wallet_required' => false,
        ];
        $execution->update(['billing_pending_result' => $snapshot]);
        $receipt = $this->settledReceipt($execution);
        $receipt['outcome']['delivery']['choices'][0]['message']['content'] = '# Recovered report'.str_repeat(' verified', 15);
        $this->saveLocalResult($execution, $receipt['outcome']['delivery']['choices'][0]['message']['content']);
        Http::fake();

        $recovery = app(UnknownModelAttemptRecovery::class);
        $this->assertSame('delivery_completed', $recovery->recover($execution->id, true)['status']);
        $this->assertSame('completed', ModelCallLog::query()->sole()->status);
        $this->assertSame('completed', $execution->fresh()->status);
        $this->assertSame(1, Article::query()->count());
        Http::assertNothingSent();
    }

    public function test_recorded_structured_result_runs_renderer_once_without_supplier_replay(): void
    {
        $this->installUpstreamSkillDirectory('yao-geo-intent-miner');
        $execution = $this->unknownExecution();
        $provider = $this->createMock(AiProvider::class);
        $provider->expects($this->never())->method('generate');
        $renderer = $this->createMock(RenderBridgeService::class);
        $renderer->method('hasRenderScript')->willReturn(true);
        $renderer->expects($this->once())->method('render')->willReturn(new RenderResult(
            success: true,
            renderedPaths: ['md' => 'executions/'.$execution->id.'/recovered.md'],
            qualityReport: ['passed' => true, 'score' => 90],
        ));
        $this->app->instance(AiProvider::class, $provider);
        $this->app->instance(RenderBridgeService::class, $renderer);

        $snapshot = $execution->billing_pending_result;
        $inputs = app(SkillRunPreparer::class)->deliveryInputs(SkillBrief::fromArray($snapshot['brief_data']));
        $snapshot['delivery_context'] = [
            'path' => 'single', 'attempt' => 1,
            'asset_fingerprint' => $inputs['fingerprint'],
            'primary_model' => 'text', 'prior_tokens' => 0,
        ];
        $execution->update(['billing_pending_result' => $snapshot]);
        $receipt = $this->settledReceipt($execution);
        $receipt['outcome']['delivery']['choices'][0]['message']['content'] = json_encode([
            'title' => 'Recovered', 'summary' => str_repeat('Verified output. ', 10),
        ], JSON_THROW_ON_ERROR);
        $this->saveLocalResult($execution, $receipt['outcome']['delivery']['choices'][0]['message']['content']);
        Http::fake();

        $recovery = app(UnknownModelAttemptRecovery::class);
        $this->assertSame('delivery_completed', $recovery->recover($execution->id, true)['status']);
        $saved = $execution->fresh();
        $this->assertSame('executions/'.$execution->id.'/recovered.md', data_get($saved->output_data, 'markdown'));
        $this->assertSame('Recovered', data_get($saved->output_data, 'structured.title'));
        $this->assertSame(['markdown'], data_get($saved->output_data, 'rendered_formats'));
        $this->assertSame(1, Article::query()->count());
        Http::assertNothingSent();
    }

    public function test_short_primary_result_does_not_start_a_new_fallback_during_recovery(): void
    {
        $execution = $this->unknownExecution();
        $snapshot = $execution->billing_pending_result;
        $inputs = app(SkillRunPreparer::class)->deliveryInputs(SkillBrief::fromArray($snapshot['brief_data']));
        $snapshot['delivery_context'] = [
            'path' => 'single', 'attempt' => 1,
            'asset_fingerprint' => $inputs['fingerprint'],
            'primary_model' => 'text', 'prior_tokens' => 0,
        ];
        $execution->update(['billing_pending_result' => $snapshot]);
        $this->saveLocalResult($execution, 'Recovered but not delivered');
        Http::fake();

        $recovery = app(UnknownModelAttemptRecovery::class);
        $this->assertSame('delivery_context_unavailable', $recovery->recover($execution->id, true)['status']);
        $this->assertSame('delivery_context_unavailable', $recovery->recover($execution->id, true)['status']);
        $this->assertSame('billing_pending', $execution->fresh()->status);
        $this->assertSame(0, Article::query()->count());
        Http::assertNothingSent();
    }

    public function test_unsettled_wrong_owner_and_conflicting_input_never_record_or_debit(): void
    {
        $execution = $this->unknownExecution();
        $this->saveLocalResult($execution);
        $original = $execution->fresh()->billing_pending_result;
        Http::fake();
        foreach (['executionId', 'projectId', 'userId'] as $field) {
            $snapshot = $original;
            $snapshot['brief_data'][$field] += 1;
            $execution->update(['billing_pending_result' => $snapshot]);
            $this->assertSame('brief_identity_conflict', app(UnknownModelAttemptRecovery::class)->recover($execution->id, true)['status']);
        }
        $snapshot = $original;
        $snapshot['gateway']['attribution']['task_id'] = 'skill:someone-else';
        $execution->update(['billing_pending_result' => $snapshot]);
        $this->assertSame('identity_unconfirmed', app(UnknownModelAttemptRecovery::class)->recover($execution->id, true)['status']);
        $snapshot = $original;
        $snapshot['known_model_result']['total_tokens'] = -1;
        $execution->update(['billing_pending_result' => $snapshot]);
        $this->assertSame('receipt_unconfirmed', app(UnknownModelAttemptRecovery::class)->recover($execution->id, true)['status']);
        $this->assertDatabaseCount('model_call_logs', 0);
        $this->assertDatabaseCount('articles', 0);
        Http::assertNothingSent();
    }

    public function test_cancelled_execution_does_not_start_customer_debit(): void
    {
        $execution = $this->unknownExecution();
        $execution->update(['status' => 'cancelled']);
        Http::fake();

        $this->assertSame('cancelled_manual_review', app(UnknownModelAttemptRecovery::class)->recover($execution->id, true)['status']);
        $this->assertSame(0, ModelCallLog::query()->count());
        $this->assertSame('cancelled', $execution->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_stale_completed_execution_does_not_query_or_reopen_the_task(): void
    {
        $execution = $this->unknownExecution();
        $execution->update(['status' => 'completed']);
        Http::fake();

        $this->assertSame('state_conflict', app(UnknownModelAttemptRecovery::class)->recover($execution->id, true)['status']);
        $this->assertSame('completed', $execution->fresh()->status);
        $this->assertSame(0, ModelCallLog::query()->count());
        Http::assertNothingSent();
    }

    public function test_read_only_probe_does_not_claim_ready_without_recording_metadata(): void
    {
        $execution = $this->unknownExecution();
        $this->saveLocalResult($execution);
        $execution->refresh();
        $snapshot = $execution->billing_pending_result;
        unset($snapshot['record_metadata']);
        $execution->update(['billing_pending_result' => $snapshot]);
        Http::fake();

        $this->assertSame('metadata_unconfirmed', app(UnknownModelAttemptRecovery::class)->recover($execution->id)['status']);
        $this->assertSame(0, ModelCallLog::query()->count());
        Http::assertNothingSent();
    }

    public function test_artisan_entry_is_read_only_by_default_and_omits_model_content(): void
    {
        $execution = $this->unknownExecution();
        $this->saveLocalResult($execution);
        $execution->refresh();
        Http::fake();

        $this->assertSame(0, Artisan::call('geo:recover-unknown-model-attempt', [
            'execution' => $execution->id, '--json' => true,
        ]));
        $this->assertStringContainsString('"status":"ready_for_usage_recovery"', Artisan::output());
        $this->assertStringNotContainsString('Recovered but not delivered', Artisan::output());
        $this->assertSame(0, ModelCallLog::query()->count());
        Http::assertNothingSent();
    }

    public function test_backlog_inventory_lists_checkpoint_states_without_content_or_http(): void
    {
        $first = $this->unknownExecution();
        $second = $this->unknownExecution();
        $second->update(['billing_pending_result' => [
            'version' => 1, 'kind' => 'model_result_recorded',
            'model_call_log_id' => 77,
            'model_result' => ['content' => 'PRIVATE_RECOVERED_BODY'],
        ]]);
        $third = $this->unknownExecution();
        $third->update(['billing_pending_result' => [
            'version' => 1, 'kind' => 'model_attempt_prepared',
            'brief_data' => ['fields' => ['brand_name' => 'PRIVATE_PREPARED_BRAND']],
        ]]);
        Http::fake();

        $this->assertSame(0, Artisan::call('geo:inspect-pending-model-results', [
            '--older-than' => 0, '--scan' => 10, '--json' => true,
        ]));
        $body = Artisan::output();
        $this->assertStringContainsString('"checkpoint_kind":"model_attempt_unknown"', $body);
        $this->assertStringContainsString('"checkpoint_kind":"model_result_recorded"', $body);
        $this->assertStringContainsString('"checkpoint_kind":"model_attempt_prepared"', $body);
        $this->assertStringContainsString('"model_call_log_id":77', $body);
        $this->assertStringNotContainsString('PRIVATE_RECOVERED_BODY', $body);
        $this->assertStringNotContainsString('PRIVATE_PREPARED_BRAND', $body);
        $this->assertStringNotContainsString('Acme', $body);
        Http::assertNothingSent();
    }

    private function unknownExecution(): SkillExecution
    {
        $tenant = Tenant::query()->create([
            'subdomain' => 'unknown-recovery-'.bin2hex(random_bytes(3)),
            'name' => 'Unknown Recovery', 'plan' => 'basic', 'status' => 'active',
        ]);
        TenantContext::set($tenant);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $project = Project::query()->create(['owner_id' => $user->id, 'name' => 'Recovery']);
        $execution = SkillExecution::query()->create([
            'project_id' => $project->id, 'user_id' => $user->id,
            'skill_id' => 'yao-geo-intent-miner', 'skill_name' => 'Intent Miner',
            'category' => 'geo-research', 'status' => 'billing_pending',
            'execution_mode' => 'sync', 'input_data' => [],
        ]);
        $execution->update(['billing_pending_result' => [
            'version' => 1, 'kind' => 'model_attempt_unknown',
            'gateway' => ['http_status' => 202, 'request_id' => 'gateway-original',
                'attribution' => $this->attribution($execution)],
            'record_metadata' => ['attempt' => 1, 'recovery' => false],
            'requested_model' => 'text',
            'brief_data' => ['skillId' => $execution->skill_id,
                'projectId' => $execution->project_id, 'userId' => $execution->user_id,
                'executionId' => $execution->id, 'fields' => ['brand_name' => 'Acme']],
        ]]);

        return $execution->fresh();
    }

    private function attribution(SkillExecution $execution): array
    {
        return ['client_request_id' => 'original-attempt', 'task_id' => 'skill:'.$execution->id,
            'feature_id' => $execution->skill_id, 'step_id' => 'single:primary'];
    }

    private function settledReceipt(SkillExecution $execution): array
    {
        return [
            'status' => 'settled', 'request_id' => 'gateway-original',
            'client_request_id' => 'original-attempt', 'requested_model' => 'text',
            'attribution' => $this->attribution($execution),
            'outcome' => ['delivery' => [
                'model' => 'text',
                'choices' => [['message' => ['content' => 'Recovered but not delivered'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
                'gateway' => ['request_id' => 'gateway-original'],
            ]],
        ];
    }
    private function saveLocalResult(SkillExecution $execution, string $content = 'Recovered but not delivered'): void
    {
        $snapshot = $execution->fresh()->billing_pending_result;
        (new UnrecordedModelResult(new AiProviderCallResult(
            success: true, provider: 'custom', model: 'test-text', content: $content,
            promptTokens: 10, completionTokens: 5, totalTokens: 15, finishReason: 'stop',
            gateway: ['status' => 'completed', 'request_id' => 'gateway-original', 'attribution' => $this->attribution($execution)],
        ), ['attempt' => 1, 'recovery' => false], new \RuntimeException('local log failed')))
            ->withDeliveryContext($snapshot['delivery_context'] ?? [])
            ->hold($execution, $snapshot['brief_data']);
    }

}
