<?php

namespace Tests\Feature;

use App\Contracts\Outbound\HostResolver;
use App\Contracts\Outbound\OutboundTransport;
use App\Jobs\RunAiVisibilityJob;
use App\Models\AiVisibilityRun;
use App\Models\AiVisibilitySource;
use App\Models\ModelCallLog;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Geo\AiVisibility\AiVisibilityService;
use App\Services\Outbound\OutboundRequestFailedException;
use App\Services\Outbound\ResolvedOutboundTarget;
use App\Support\TenantContext;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Direct Ark streaming, local usage, recovery and tenant isolation. */
class AiVisibilityRunTest extends TestCase
{
    use RefreshDatabase;

    private object $transport;



    protected function setUp(): void
    {
        parent::setUp();

        config(['app.tenant_domain' => 'geo.example.test']);
        config(['geo.visibility_panel_enabled' => true]);
        // Legacy endpoint behavior in this fixture is covered separately from
        // the claim/recovery contract in VisibilityRunClaimTest.
        config(['geo.visibility_run_claim_required' => false]);
        config(['geo.visibility.allowed_hosts' => ['ark.example.test']]);
        $this->app->instance(\App\Contracts\Outbound\HostResolver::class, new class implements \App\Contracts\Outbound\HostResolver {
            public function resolve(string $host): array { return ['93.184.216.34']; }
        });
        $captured = [];
        $this->fakeArkTransport($captured, $this->spikePayload());

    }

    /** GEO 子池可用积分（1 分 = 100 积分）。 */

    public function test_store_executes_question_and_persists_result(): void
    {
        $captured = [];
        $this->fakeArkTransport($captured, $this->spikePayload());

        [$tenantA, $adminA] = $this->createTenantAdmin('tenant-a');
        $projectA = $this->createProjectFor($tenantA, $adminA);
        Sanctum::actingAs($adminA, ['*']);

        config(['geo.visibility_run_claim_required' => true]);
        $payload = [
            'project_id' => $projectA->id,
            'questions' => ['2026年中国有哪些提供GEO服务的公司？'],
        ];
        $this->forTenant($tenantA)->postJson('/api/v1/visibility/runs/claim', [
            'payload' => $payload, 'request_key' => 'visibility-paid-once',
        ])->assertOk()->assertJsonPath('request_key', 'visibility-paid-once');

        $response = $this
            ->forTenant($tenantA)
            ->postJson('/api/v1/visibility/runs', $payload,
                ['X-Idempotency-Key' => 'visibility-paid-once']);

        $this->assertSame(202, $response->status(), json_encode($response->json()));
        $response->assertJsonStructure(['batch_id', 'question_count', 'runs' => [['id', 'status']]]);
        $replayed = $this->forTenant($tenantA)->postJson('/api/v1/visibility/runs', $payload,
            ['X-Idempotency-Key' => 'visibility-paid-once']);
        $this->assertSame(202, $replayed->status(), json_encode($replayed->json()));
        $replayed->assertJsonPath('batch_id', $response->json('batch_id'));
        $this->assertSame(1, AiVisibilityRun::query()->count());
        $this->forTenant($tenantA)->postJson('/api/v1/visibility/runs/seen', [
            'request_key' => 'visibility-paid-once',
        ])->assertOk();
        $this->forTenant($tenantA)->postJson('/api/v1/visibility/runs/claim', [
            'payload' => $payload, 'request_key' => 'visibility-next-batch',
        ])->assertOk()->assertJsonPath('request_key', 'visibility-next-batch');

        // 同步队列（phpunit QUEUE_CONNECTION=sync）下 Job 已在请求内执行完毕。
        $run = AiVisibilityRun::query()->firstOrFail();
        $this->assertSame(AiVisibilityRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(25171, $run->prompt_tokens);
        $this->assertSame(2184, $run->completion_tokens);
        $this->assertSame(11888, $run->cached_tokens);
        $this->assertSame(1, $run->search_call_count);
        $this->assertNull($run->upstream_cost_fen);
        $this->assertStringContainsString('回答正文', (string) $run->answer_text);
        $this->assertSame(['GEO 服务公司'], $run->searchQueries());
        $this->assertArrayNotHasKey('cost_breakdown', $run->metadata);

        // 判据 V2：title/url/site_name/summary 四字段非空。
        $source = AiVisibilitySource::query()->where('run_id', $run->id)->firstOrFail();
        $this->assertSame('国内GEO服务商推荐指南', $source->title);
        $this->assertSame('https://www.ithome.com/0/993/806.htm', $source->url);
        $this->assertSame('IT之家', $source->site_name);
        $this->assertNotSame('', (string) $source->summary);
        $this->assertNotNull($source->published_at);
        // logo_url 只在 metadata（单 3 硬要求 2）。
        $this->assertArrayNotHasKey('logo_url', $source->toArray());
        $this->assertSame('https://p3-volcsearch-sign.byteimg.com/abc?x-expires=1850788828', $source->metadata['logo_url_temporary']);

        $this->assertSame('doubao-seed-evolving', $captured['model']);
        $this->assertSame('visibility:'.$run->id.':supplier:1', $run->metadata['model_client_request_id']);
        $this->assertSame('2026年中国有哪些提供GEO服务的公司？', $captured['input']);
        $this->assertSame([['type' => 'web_search']], $captured['tools']);
        $this->assertSame(['effort' => 'low'], $captured['reasoning']);
        $this->assertTrue($captured['stream']);
        $this->assertCount(1, $this->transport->requests);

    }

    public function test_question_suggestions_reuse_latest_complete_intent_bank_without_model_call(): void
    {
        [$tenantA, $adminA] = $this->createTenantAdmin('tenant-suggestions');
        $projectA = $this->createProjectFor($tenantA, $adminA);

        TenantContext::set($tenantA);
        try {
            SkillExecution::query()->create([
                'project_id' => $projectA->id,
                'user_id' => $adminA->id,
                'skill_id' => 'yao-geo-intent-miner',
                'skill_name' => '意图挖掘',
                'category' => 'research',
                'status' => 'completed',
                'output_data' => [
                    'structured' => [
                        'question_bank' => [
                            ['question' => '品牌是做什么的？', 'cluster' => '品牌认知', 'priority' => 'P0'],
                            ['question' => '品牌适合哪些客户？', 'cluster' => '适用场景', 'priority' => 'P1'],
                        ],
                    ],
                ],
            ]);
            // 最新一份虽标记完成但字段缺失，接口应继续向前找，不能把空壳当问题库。
            SkillExecution::query()->create([
                'project_id' => $projectA->id,
                'user_id' => $adminA->id,
                'skill_id' => 'yao-geo-intent-miner',
                'skill_name' => '意图挖掘',
                'category' => 'research',
                'status' => 'completed',
                'output_data' => ['structured' => ['scoring_matrix' => []]],
            ]);
        } finally {
            TenantContext::forget();
        }

        Sanctum::actingAs($adminA, ['*']);
        $executionCount = SkillExecution::query()->count();

        $this->forTenant($tenantA)
            ->getJson('/api/v1/visibility/question-suggestions?project_id='.$projectA->id)
            ->assertOk()
            ->assertJsonPath('source', 'intent_report')
            ->assertJsonPath('incurs_charge', false)
            ->assertJsonPath('data.0.question', '品牌是做什么的？')
            ->assertJsonCount(2, 'data');

        $this->assertSame($executionCount, SkillExecution::query()->count());
        $this->assertSame(0, ModelCallLog::query()->count());
    }

    public function test_store_deduplicates_same_question_before_dispatch_and_charging(): void
    {
        $captured = [];
        $this->fakeArkTransport($captured, $this->spikePayload());

        [$tenantA, $adminA] = $this->createTenantAdmin('tenant-deduplicate');
        $projectA = $this->createProjectFor($tenantA, $adminA);
        Sanctum::actingAs($adminA, ['*']);

        $this->forTenant($tenantA)
            ->postJson('/api/v1/visibility/runs', [
                'project_id' => $projectA->id,
                'questions' => ['同一个问题', '  同一个问题  '],
            ])
            ->assertStatus(202)
            ->assertJsonPath('question_count', 1)
            ->assertJsonCount(1, 'runs');

        $this->assertSame(1, AiVisibilityRun::query()->count());
        $this->assertSame(1, ModelCallLog::query()->count());
    }

    public function test_monitor_records_supplier_model_and_tokens(): void
    {
        $captured = [];
        $this->fakeArkTransport($captured, $this->spikePayload());

        [$tenantA, $adminA] = $this->createTenantAdmin('tenant-a');
        $projectA = $this->createProjectFor($tenantA, $adminA);
        Sanctum::actingAs($adminA, ['*']);

        $this
            ->forTenant($tenantA)
            ->postJson('/api/v1/visibility/runs', [
                'project_id' => $projectA->id,
                'questions' => ['问题'],
            ])
            ->assertStatus(202);

        // assist 档 SkillExecution + ModelCallLog 落库，且 model 显式是 geo-reason（§10.3）。
        $execution = SkillExecution::query()->where('skill_id', 'ai-visibility')->firstOrFail();
        $this->assertSame('assist', $execution->execution_mode);
        $this->assertSame($projectA->id, $execution->project_id);

        $callLog = ModelCallLog::query()->where('skill_execution_id', $execution->id)->firstOrFail();
        $this->assertSame('doubao-seed-evolving', $callLog->model);
        $this->assertSame(25171, $callLog->prompt_tokens);
        $this->assertSame('ai-visibility', $callLog->metadata['assist_kind']);
        $run = AiVisibilityRun::query()->firstOrFail();
        $this->assertSame($run->id, $callLog->metadata['run_id']);
    }


    public function test_backfill_migration_repairs_the_100x_rows_and_is_repeatable(): void
    {
        // 历史脏行：单位错标成积分（生产 run id=1 就长这样）。
        DB::table('ai_visibility_runs')->insert([
            [
                'tenant_id' => 4, 'project_id' => 1, 'user_id' => 2,
                'batch_id' => 'batch-legacy', 'question' => '孟德智达是做什么的？',
                'status' => 'completed', 'points_charged_fen' => 292.00,
                'metadata' => json_encode(['points_deducted' => 292]),
                'created_at' => '2026-08-27 05:58:32', 'updated_at' => '2026-08-27 05:58:57',
            ],
            // 新码写对的行：迁移必须原样放过。
            [
                'tenant_id' => 4, 'project_id' => 1, 'user_id' => 2,
                'batch_id' => 'batch-new', 'question' => '第二个问题',
                'status' => 'completed', 'points_charged_fen' => 2.92,
                'metadata' => json_encode(['points_deducted' => 292]),
                'created_at' => '2026-08-28 10:00:00', 'updated_at' => '2026-08-28 10:00:20',
            ],
            // 拿不到原始积分的行：不猜、不动。
            [
                'tenant_id' => 4, 'project_id' => 1, 'user_id' => 2,
                'batch_id' => 'batch-unknown', 'question' => '第三个问题',
                'status' => 'completed', 'points_charged_fen' => 500.00,
                'metadata' => json_encode(['points_deduction_status' => 'failed']),
                'created_at' => '2026-08-27 06:00:00', 'updated_at' => '2026-08-27 06:00:20',
            ],
        ]);

        $migration = require database_path('migrations/2026_08_27_120000_fix_ai_visibility_points_charged_fen_unit.php');

        $migration->up();
        $this->assertBackfilledAmounts();

        // 可重复执行：再跑一次结果不变（不会把已修好的行再除一次）。
        $migration->up();
        $this->assertBackfilledAmounts();
    }

    private function assertBackfilledAmounts(): void
    {
        $amounts = DB::table('ai_visibility_runs')
            ->pluck('points_charged_fen', 'batch_id')
            ->map(fn ($value): float => (float) $value)
            ->all();

        $this->assertEqualsWithDelta(2.92, $amounts['batch-legacy'], 0.001);
        $this->assertEqualsWithDelta(2.92, $amounts['batch-new'], 0.001);
        $this->assertEqualsWithDelta(500.00, $amounts['batch-unknown'], 0.001);
    }

    public function test_unknown_transport_marks_run_for_reconciliation_and_charges_nothing(): void
    {
        $this->fakeThrowingTransport();

        [$tenantA, $adminA] = $this->createTenantAdmin('tenant-a');
        $projectA = $this->createProjectFor($tenantA, $adminA);
        Sanctum::actingAs($adminA, ['*']);

        $this
            ->forTenant($tenantA)
            ->postJson('/api/v1/visibility/runs', [
                'project_id' => $projectA->id,
                'questions' => ['问题'],
            ])
            ->assertStatus(202);

        $run = AiVisibilityRun::query()->firstOrFail();
        $this->assertSame(AiVisibilityRun::STATUS_RECONCILIATION_REQUIRED, $run->status);
        $this->assertNotEmpty($run->metadata['model_client_request_id']);
        $this->assertNotNull($run->error_message);

        $this->assertSame(1, SkillExecution::query()->where('skill_id', 'ai-visibility')->count());
        $this->assertSame(1, ModelCallLog::query()->count());
        $this->assertSame(0, AiVisibilitySource::query()->count());
    }

    public function test_pending_monitor_without_response_id_never_reposts(): void
    {
        $this->fakeThrowingTransport();
        $this->transport->omitId = true;
        [$tenant, $admin] = $this->createTenantAdmin('tenant-pending');
        $project = $this->createProjectFor($tenant, $admin);
        Sanctum::actingAs($admin, ['*']);
        $this->forTenant($tenant)->postJson('/api/v1/visibility/runs', [
            'project_id' => $project->id, 'questions' => ['待核对的问题'],
        ])->assertStatus(202);
        $run = AiVisibilityRun::query()->firstOrFail();
        Artisan::call('geo:reconcile-ai-visibility');
        Artisan::call('geo:reconcile-ai-visibility');
        $this->assertSame(AiVisibilityRun::STATUS_RECONCILIATION_REQUIRED, $run->fresh()->status);
        $this->assertSame(['POST'], array_column($this->transport->requests, 'method'));
        $this->assertSame(1, ModelCallLog::query()->count());
        $this->assertSame('unknown', ModelCallLog::query()->firstOrFail()->status);
        $this->assertNull(ModelCallLog::query()->firstOrFail()->total_tokens);
    }

    public function test_reconciler_only_delivers_confirmed_original_monitor_attempt(): void
    {
        $this->fakeThrowingTransport();
        $this->transport->ready = false;
        [$tenant, $admin] = $this->createTenantAdmin('tenant-recover');
        $project = $this->createProjectFor($tenant, $admin);
        Sanctum::actingAs($admin, ['*']);
        $this->forTenant($tenant)->postJson('/api/v1/visibility/runs', [
            'project_id' => $project->id, 'questions' => ['恢复问题'],
        ])->assertStatus(202);
        $run = AiVisibilityRun::query()->firstOrFail();
        $this->assertSame(AiVisibilityRun::STATUS_RECONCILIATION_REQUIRED, $run->status);
        $attemptId = $run->metadata['model_client_request_id'];
        Artisan::call('geo:reconcile-ai-visibility');
        $this->assertSame(AiVisibilityRun::STATUS_RECONCILIATION_REQUIRED, $run->fresh()->status);
        $this->assertSame(1, ModelCallLog::query()->count());

        $this->transport->ready = true;
        Artisan::call('geo:reconcile-ai-visibility');
        $this->assertSame(AiVisibilityRun::STATUS_COMPLETED, $run->fresh()->status);
        $this->assertSame(1, ModelCallLog::query()->count());
        $this->assertSame(1, AiVisibilitySource::query()->where('run_id', $run->id)->count());
        // Simulate a local terminal-write loss after the first recovery. The
        // same durable debit receipt is reused rather than making a new charge.
        AiVisibilityRun::query()->whereKey($run->id)
            ->update(['status' => AiVisibilityRun::STATUS_RECONCILIATION_REQUIRED]);
        Artisan::call('geo:reconcile-ai-visibility');
        $this->assertSame(AiVisibilityRun::STATUS_COMPLETED, $run->fresh()->status);
        $this->assertSame(1, ModelCallLog::query()->count());
        $this->assertSame(1, AiVisibilitySource::query()->where('run_id', $run->id)->count());
        $this->assertSame(['POST', 'GET', 'GET', 'GET'], array_column($this->transport->requests, 'method'));
        foreach (array_slice($this->transport->requests, 1) as $request) {
            $this->assertStringEndsWith('/responses/resp_001', $request['url']);
        }
        $this->assertSame($attemptId, $run->fresh()->metadata['model_client_request_id']);
        $this->assertSame('completed', ModelCallLog::query()->firstOrFail()->status);

    }

    //
    // 扣费是「调完模型才扣、扣不到也不拦」，所以余额不够时唯一挡得住花钱的位置
    // 就是发起之前。以下用例证明：挡住时一个 Job 都不派、一次上游都不调、一分不扣。







    public function test_tenant_b_cannot_see_tenant_a_runs(): void
    {
        // 判据 V7。
        $captured = [];
        $this->fakeArkTransport($captured, $this->spikePayload());

        [$tenantA, $adminA] = $this->createTenantAdmin('tenant-a');
        [$tenantB, $adminB] = $this->createTenantAdmin('tenant-b');
        $projectA = $this->createProjectFor($tenantA, $adminA);
        Sanctum::actingAs($adminA, ['*']);

        $this
            ->forTenant($tenantA)
            ->postJson('/api/v1/visibility/runs', [
                'project_id' => $projectA->id,
                'questions' => ['问题'],
            ])
            ->assertStatus(202);

        $runA = AiVisibilityRun::query()->firstOrFail();

        Sanctum::actingAs($adminB, ['*']);
        $this->forTenant($tenantB)->getJson('/api/v1/visibility/runs/'.$runA->id)->assertStatus(404);
        $this->forTenant($tenantB)->getJson('/api/v1/visibility/runs?batch_id='.$runA->batch_id)
            ->assertStatus(200)
            ->assertJsonPath('data', []);
    }

    public function test_cross_tenant_project_rejected(): void
    {
        $captured = [];
        $this->fakeArkTransport($captured, $this->spikePayload());

        [$tenantA, $adminA] = $this->createTenantAdmin('tenant-a');
        [$tenantB, $adminB] = $this->createTenantAdmin('tenant-b');
        $projectB = $this->createProjectFor($tenantB, $adminB);
        Sanctum::actingAs($adminA, ['*']);

        $this
            ->forTenant($tenantA)
            ->postJson('/api/v1/visibility/runs', [
                'project_id' => $projectB->id,
                'questions' => ['问题'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['project_id']);

        $this->assertSame(0, AiVisibilityRun::query()->count());
    }

    public function test_panel_disabled_returns_404(): void
    {
        config(['geo.visibility_panel_enabled' => false]);

        [$tenantA, $adminA] = $this->createTenantAdmin('tenant-a');
        $projectA = $this->createProjectFor($tenantA, $adminA);
        Sanctum::actingAs($adminA, ['*']);

        $this->forTenant($tenantA)->postJson('/api/v1/visibility/runs', [
            'project_id' => $projectA->id,
            'questions' => ['问题'],
        ])->assertStatus(404);

        $this->forTenant($tenantA)->getJson('/api/v1/visibility/runs')->assertStatus(404);
    }

    public function test_missing_api_key_fails_run_without_upstream_call(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('tenant-no-model');
        \App\Models\TenantAiKey::withoutGlobalScopes()->where('tenant_id', $tenant->id)->delete();
        $project = $this->createProjectFor($tenant, $admin);
        Sanctum::actingAs($admin, ['*']);
        $this->forTenant($tenant)->getJson('/api/v1/visibility/runs')->assertOk()->assertJsonPath('configured', false);
        $this->forTenant($tenant)->postJson('/api/v1/visibility/runs', [
            'project_id' => $project->id, 'questions' => ['问题'],
        ])->assertStatus(422)->assertJsonValidationErrors('configuration');
        $this->assertSame(0, AiVisibilityRun::query()->count());
        $this->assertSame(0, ModelCallLog::query()->count());
        $this->assertCount(0, $this->transport->requests);
    }

    public function test_empty_supplier_answer_keeps_observed_usage_without_charging_points(): void
    {
        $payload = $this->spikePayload();
        $payload['output'][2]['content'][0]['text'] = '';
        $captured = [];
        $this->fakeArkTransport($captured, $payload);

        [$tenant, $admin] = $this->createTenantAdmin('tenant-a');
        $project = $this->createProjectFor($tenant, $admin);
        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)->postJson('/api/v1/visibility/runs', [
            'project_id' => $project->id,
            'questions' => ['清禾可研是什么？'],
        ])->assertStatus(202);

        $run = AiVisibilityRun::query()->firstOrFail();
        $this->assertSame(AiVisibilityRun::STATUS_FAILED, $run->status);
        $this->assertSame('上游返回没有可读正文', $run->error_message);
        $this->assertSame(1, $run->search_call_count);
        $this->assertSame(25171, $run->prompt_tokens);
        $this->assertSame(2184, $run->completion_tokens);
        $this->assertSame(11888, $run->cached_tokens);
        $this->assertSame(['GEO 服务公司'], $run->searchQueries());
        $this->assertNull($run->points_charged_fen);
        $this->assertNull($run->answer_text);
    }

    public function test_incomplete_supplier_answer_keeps_usage_and_never_charges_points(): void
    {
        $payload = $this->spikePayload();
        $payload['status'] = 'incomplete';
        $payload['incomplete_details'] = ['reason' => 'length'];
        $captured = [];
        $this->fakeArkTransport($captured, $payload);
        [$tenant, $admin] = $this->createTenantAdmin('tenant-cutoff');
        $project = $this->createProjectFor($tenant, $admin);
        Sanctum::actingAs($admin, ['*']);
        $this->forTenant($tenant)->postJson('/api/v1/visibility/runs', [
            'project_id' => $project->id, 'questions' => ['科研工作流如何衔接？'],
        ])->assertStatus(202);
        $run = AiVisibilityRun::query()->firstOrFail();
        $this->assertSame(AiVisibilityRun::STATUS_FAILED, $run->status);
        $this->assertSame('上游回答达到长度上限，未生成完整结果。', $run->error_message);
        $this->assertSame(25171, $run->prompt_tokens);
        $this->assertSame(2184, $run->completion_tokens);
        $this->assertSame(1, $run->search_call_count);
        $this->assertNull($run->points_charged_fen);
        $this->assertNull($run->answer_text);
        $this->assertSame(1, ModelCallLog::query()->count());
    }

    public function test_download_markdown_and_html(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-23T06:31:48Z'));
        $captured = [];
        $this->fakeArkTransport($captured, $this->spikePayload());

        [$tenantA, $adminA] = $this->createTenantAdmin('tenant-a');
        $projectA = $this->createProjectFor($tenantA, $adminA);
        Sanctum::actingAs($adminA, ['*']);

        $this
            ->forTenant($tenantA)
            ->postJson('/api/v1/visibility/runs', [
                'project_id' => $projectA->id,
                'questions' => ['问题'],
            ])->assertStatus(202);

        $run = AiVisibilityRun::query()->firstOrFail();

        $markdown = $this->forTenant($tenantA)->getJson('/api/v1/visibility/runs/'.$run->id.'/download/markdown');
        $markdown->assertStatus(200);
        $this->assertStringContainsString('AI 可见性监测报告', $markdown->streamedContent());
        $this->assertStringContainsString('IT之家', $markdown->streamedContent());
        $this->assertStringContainsString('2026-10-23 14:31:48（北京时间，UTC+8）', $markdown->streamedContent());

        $html = $this->forTenant($tenantA)->getJson('/api/v1/visibility/runs/'.$run->id.'/download/html');
        $html->assertStatus(200);
        $this->assertStringContainsString('<!DOCTYPE html>', $html->streamedContent());
        $this->assertStringContainsString('2026-10-23 14:31:48（北京时间，UTC+8）', $html->streamedContent());

        // 不支持的格式 → 404。
        $this->forTenant($tenantA)->getJson('/api/v1/visibility/runs/'.$run->id.'/download/pdf')->assertStatus(404);
    }

    public function test_detail_and_download_do_not_present_duplicate_search_results_as_citations(): void
    {
        $payload = $this->spikePayload();
        $payload['output'][2]['content'][0]['text'] = '正文没有引用标记。';
        $captured = [];
        $this->fakeArkTransport($captured, $payload);

        [$tenantA, $adminA] = $this->createTenantAdmin('tenant-a');
        $projectA = $this->createProjectFor($tenantA, $adminA);
        Sanctum::actingAs($adminA, ['*']);

        $this->forTenant($tenantA)->postJson('/api/v1/visibility/runs', [
            'project_id' => $projectA->id,
            'questions' => ['问题'],
        ])->assertStatus(202);

        $run = AiVisibilityRun::query()->firstOrFail();
        $source = AiVisibilitySource::query()->where('run_id', $run->id)->firstOrFail();
        AiVisibilitySource::query()->create([
            ...$source->only([
                'tenant_id', 'run_id', 'source_type', 'title', 'url', 'domain', 'site_name',
                'snippet', 'summary', 'content_excerpt', 'published_at', 'rank_score',
                'authority_level', 'metadata',
            ]),
            'citation_key' => '[2]',
            'rank' => 2,
        ]);

        $detail = $this->forTenant($tenantA)->getJson('/api/v1/visibility/runs/'.$run->id);
        $detail->assertStatus(200)
            ->assertJsonPath('data.answer_citations_present', false)
            ->assertJsonCount(1, 'data.sources');

        $markdown = $this->forTenant($tenantA)->getJson('/api/v1/visibility/runs/'.$run->id.'/download/markdown');
        $markdown->assertStatus(200);
        $body = $markdown->streamedContent();
        $this->assertStringContainsString('检索参考（1 条）', $body);
        $this->assertStringContainsString('不代表正文逐条采用', $body);
        $this->assertStringNotContainsString('信源列表', $body);
    }

    public function test_upstream_cost_never_reaches_any_tenant_facing_output(): void
    {
        // 本模块三个出口全在租户侧（can:project:view），所以三个都必须查不到它。
        $captured = [];
        $this->fakeArkTransport($captured, $this->spikePayload());

        [$tenantA, $adminA] = $this->createTenantAdmin('tenant-a');
        $projectA = $this->createProjectFor($tenantA, $adminA);
        Sanctum::actingAs($adminA, ['*']);

        $this
            ->forTenant($tenantA)
            ->postJson('/api/v1/visibility/runs', [
                'project_id' => $projectA->id,
                'questions' => ['问题'],
            ])->assertStatus(202);

        $run = AiVisibilityRun::query()->firstOrFail();

        $this->assertNull($run->upstream_cost_fen);

        // 出口一：列表（连同 batches / overview 两个聚合块一起查）。
        $list = $this->forTenant($tenantA)->getJson('/api/v1/visibility/runs');
        $list->assertStatus(200);
        $this->assertStringNotContainsString('upstream_cost', $list->getContent());
        $list->assertJsonStructure(['data' => [['id', 'total_tokens', 'search_call_count']]]);

        // 出口二：详情。
        $detail = $this->forTenant($tenantA)->getJson('/api/v1/visibility/runs/'.$run->id);
        $detail->assertStatus(200);
        $this->assertStringNotContainsString('upstream_cost', $detail->getContent());
        $detail->assertJsonStructure(['data' => ['total_tokens', 'usage' => ['search_call_count']]]);

        // 出口三：客户能下载的报告正文。
        $markdown = $this->forTenant($tenantA)->getJson('/api/v1/visibility/runs/'.$run->id.'/download/markdown');
        $markdown->assertStatus(200);
        $this->assertStringNotContainsString('真实上游成本', $markdown->streamedContent());
        $this->assertStringNotContainsString('上游成本', $markdown->streamedContent());
    }

    public function test_missing_tool_usage_still_reports_observed_search_calls(): void
    {
        // 但估出来的数不许装成真值——search_count_estimated 必须一路透到接口。
        $payload = $this->spikePayload();
        unset($payload['usage']['tool_usage']);

        Log::spy();
        $captured = [];
        $this->fakeArkTransport($captured, $payload);

        [$tenantA, $adminA] = $this->createTenantAdmin('tenant-a');
        $projectA = $this->createProjectFor($tenantA, $adminA);
        Sanctum::actingAs($adminA, ['*']);

        $this
            ->forTenant($tenantA)
            ->postJson('/api/v1/visibility/runs', [
                'project_id' => $projectA->id,
                'questions' => ['问题'],
            ])->assertStatus(202);

        $run = AiVisibilityRun::query()->firstOrFail();

        $this->assertSame(1, $run->search_call_count);
        $this->assertFalse($run->searchCountEstimated());
        $this->assertArrayNotHasKey('cost_breakdown', $run->metadata);
        $this->forTenant($tenantA)->getJson('/api/v1/visibility/runs')
            ->assertOk()->assertJsonPath('data.0.search_call_count', 1)->assertJsonPath('data.0.search_count_estimated', false);
        $this->forTenant($tenantA)->getJson('/api/v1/visibility/runs/'.$run->id)
            ->assertOk()->assertJsonPath('data.search_count_estimated', false);
        Log::shouldNotHaveReceived('warning');
    }

    public function test_real_search_count_is_not_flagged_as_estimated(): void
    {
        // 否则这个标记会退化成永远亮着的装饰，等于没有。
        Log::spy();
        $captured = [];
        $this->fakeArkTransport($captured, $this->spikePayload());

        [$tenantA, $adminA] = $this->createTenantAdmin('tenant-a');
        $projectA = $this->createProjectFor($tenantA, $adminA);
        Sanctum::actingAs($adminA, ['*']);

        $this
            ->forTenant($tenantA)
            ->postJson('/api/v1/visibility/runs', [
                'project_id' => $projectA->id,
                'questions' => ['问题'],
            ])->assertStatus(202);

        $run = AiVisibilityRun::query()->firstOrFail();

        $this->assertSame(1, (int) $run->search_call_count);
        $this->assertFalse($run->searchCountEstimated());

        $this->forTenant($tenantA)->getJson('/api/v1/visibility/runs')
            ->assertStatus(200)->assertJsonPath('data.0.search_count_estimated', false);

        $this->forTenant($tenantA)->getJson('/api/v1/visibility/runs/'.$run->id)
            ->assertStatus(200)->assertJsonPath('data.search_count_estimated', false);

        Log::shouldNotHaveReceived('warning');
    }

    public function test_job_contract_one_question_no_retry(): void
    {
        // 判据 V8 的另一半：不重试 + 专用队列。
        $job = new RunAiVisibilityJob(1, 1);

        $this->assertSame('visibility', $job->queue);
        $this->assertSame(1, $job->tries);
        // 梯度：280 < worker --timeout 300 < retry_after 600。
        $this->assertLessThan(300, $job->timeout);
        $retryAfter = (int) config('queue.connections.database.retry_after');
        $this->assertGreaterThan($job->timeout, $retryAfter);
    }

    // ── 手法（与 CrossTenantProjectAccessTest / OutboundSecurityChainTest 同款）──

    private function fakeArkTransport(array &$captured, array $payload): void
    {
        $this->transport = new class($captured, $payload) implements \App\Contracts\Outbound\OutboundTransport {
            public array $requests = [];
            public bool $interrupt = false;
            public bool $omitId = false;
            public bool $ready = true;
            private array $captured;
            public function __construct(array &$captured, private array $payload) { $this->captured =& $captured; }
            public function send(\Illuminate\Http\Client\PendingRequest $request, string $method,
                \App\Services\Outbound\ResolvedOutboundTarget $target, array $data, int $maxBytes, bool $crossOrigin = false): \Illuminate\Http\Client\Response
            {
                $this->requests[] = ['method' => $method, 'url' => $target->url, 'body' => $data];
                if ($method === 'GET') {
                    $body = $this->ready ? $this->payload : ['id' => $this->payload['id'], 'status' => 'in_progress'];
                    return new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode($body)));
                }
                $this->captured = $data;
                $options = $request->getOptions();
                $response = new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'text/event-stream']);
                ($options['on_headers'])($response);
                if (! $this->omitId) {
                    $options['sink']->write('data: '.json_encode(['type' => 'response.created', 'response' => ['id' => $this->payload['id']]])."\n\n");
                }
                if ($this->interrupt) { throw new \App\Services\Outbound\OutboundRequestFailedException; }
                $options['sink']->write('data: '.json_encode(['type' => 'response.'.$this->payload['status'], 'response' => $this->payload])."\n\n");
                return new \Illuminate\Http\Client\Response($response->withBody($options['sink']));
            }
        };
        $this->app->instance(\App\Contracts\Outbound\OutboundTransport::class, $this->transport);
    }

    private function fakeThrowingTransport(): void
    {
        $captured = [];
        $this->fakeArkTransport($captured, $this->spikePayload());
        $this->transport->interrupt = true;
    }

    /** spike §10 实录形态（压缩为一信源）。 */
    private function spikePayload(): array
    {
        return [
            'id' => 'resp_001',
            'status' => 'completed',
            'model' => 'doubao-seed-evolving',
            'output' => [
                ['type' => 'reasoning', 'summary' => '思考过程'],
                [
                    'type' => 'web_search_call',
                    'action' => ['type' => 'search', 'query' => 'GEO 服务公司'],
                    'status' => 'success',
                ],
                [
                    'type' => 'message',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => '回答正文，见 [1]。',
                            'annotations' => [
                                [
                                    'type' => 'url_citation',
                                    'title' => '国内GEO服务商推荐指南',
                                    'url' => 'https://www.ithome.com/0/993/806.htm',
                                    'site_name' => 'IT之家',
                                    'publish_time' => '2026年08月25日 00:00:00(CST) 星期二',
                                    'summary' => str_repeat('整段正文摘录', 100),
                                    'logo_url' => 'https://p3-volcsearch-sign.byteimg.com/abc?x-expires=1850788828',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'usage' => [
                'input_tokens' => 25171,
                'input_tokens_details' => ['cached_tokens' => 11888],
                'output_tokens' => 2184,
                'total_tokens' => 27355,
                'tool_usage' => ['web_search' => 23],
            ],
        ];
    }

    private function createTenantAdmin(string $subdomain): array
    {
        $tenant = Tenant::query()->create([
            'subdomain' => $subdomain,
            'name' => strtoupper($subdomain),
            'plan' => 'basic',
            'status' => 'active',
        ]);

        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain),
            'role' => 'admin',
        ]);

        \App\Models\TenantAiKey::withoutGlobalScopes()->create(['tenant_id' => $tenant->id,
            'slot' => 'visibility', 'provider' => 'volces', 'encrypted_key' => 'sk-test-ark-visibility', 'is_active' => true,
            'metadata' => ['base_url' => 'https://ark.example.test/api/v3', 'model' => 'doubao-seed-evolving']]);
        return [$tenant, $admin];
    }

    private function createProjectFor(Tenant $tenant, User $owner): Project
    {
        TenantContext::set($tenant);

        try {
            return Project::query()->create([
                'owner_id' => $owner->id,
                'name' => $tenant->name.' Project',
                'brand_name' => $tenant->name,
                'brand_url' => 'https://'.$tenant->subdomain.'.example.test',
                'settings' => [],
            ]);
        } finally {
            TenantContext::forget();
        }
    }

    private function forTenant(Tenant $tenant): self
    {
        return $this
            ->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }
}
