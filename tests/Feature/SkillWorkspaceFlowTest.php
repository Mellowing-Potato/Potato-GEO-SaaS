<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\ModelCallLog;
use App\Models\Article;
use App\Models\Tenant;
use App\Models\User;
use App\Models\TitleLibrary;
use App\Models\KeywordLibrary;
use App\Models\Author;
use App\Services\Contracts\SkillAgentRunner;
use App\Services\Contracts\SkillRegistry;
use App\Services\Content\MaterialLibraryService;
use App\Services\Billing\UnresolvedModelAttempt;
use App\Services\Billing\PreparedEmbeddingAttempt;
use App\Services\Billing\UnresolvedEmbeddingAttempt;
use App\Services\Billing\UnrecordedModelResult;
use App\Services\DTOs\AiProviderCallResult;
use App\Services\DTOs\SkillBrief;
use App\Services\DTOs\SkillExecutionResult;
use App\Support\TenantContext;
use App\Jobs\ExecuteSkillJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesSafeOutbound;
use Tests\TestCase;

class SkillWorkspaceFlowTest extends TestCase
{
    use FakesSafeOutbound, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.tenant_domain' => 'geo.example.test', 'geo.skill_execution_claim_required' => false]);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();

        parent::tearDown();
    }

    public function test_skills_api_returns_registry_metadata_and_dynamic_brief_fields(): void
    {
        $this->useRegistryFixture();
        [$tenant, $admin] = $this->createTenantAdmin('tenant-a');
        Sanctum::actingAs($admin, ['*']);

        $this
            ->forTenant($tenant)
            ->getJson('/api/v1/skills')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'yao-geo-panorama-audit')
            ->assertJsonPath('data.0.category', 'geo-strategy')
            ->assertJsonPath('data.0.requires_web', true)
            ->assertJsonPath('data.0.brief_fields.0.key', 'brand_name')
            ->assertJsonPath('data.0.brief_fields.0.required', true);
    }

    public function test_effect_monitor_routes_to_real_panel_without_generic_model_execution(): void
    {
        config(['geo.visibility_panel_enabled' => true]);
        [$tenant, $admin] = $this->createTenantAdmin('tenant-a');
        $project = $this->createProjectFor($tenant, $admin);
        Sanctum::actingAs($admin, ['*']);

        $response = $this
            ->forTenant($tenant)
            ->getJson('/api/v1/skills')
            ->assertOk();

        $skill = collect($response->json('data'))->firstWhere('id', 'yao-geo-effect-monitor');
        $this->assertIsArray($skill);
        $this->assertSame('豆包 AI 回答中的品牌曝光与信源监测', $skill['description']);
        $this->assertSame([], $skill['brief_fields']);
        $this->assertSame('/visibility', $skill['action_path']);
        $this->assertSame('进入效果监测', $skill['action_label']);
        $this->assertTrue($skill['available']);

        // 即使旧前端或手工请求还打通用执行接口，也必须在创建执行记录/调用模型前拦下。
        $this
            ->forTenant($tenant)
            ->postJson('/api/v1/skills/execute-async', [
                'skill_id' => 'yao-geo-effect-monitor',
                'project_id' => $project->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('action', 'open_skill_action')
            ->assertJsonPath('action_path', '/visibility');

        $this->assertDatabaseMissing('skill_executions', [
            'skill_id' => 'yao-geo-effect-monitor',
            'project_id' => $project->id,
        ]);
    }

    public function test_channel_publishing_opens_real_business_entry_without_model_execution(): void
    {
        config(['geo.manual_publish_enabled' => true]);
        [$tenant, $admin] = $this->createTenantAdmin('publishing-skill');
        $project = $this->createProjectFor($tenant, $admin);
        Sanctum::actingAs($admin, ['*']);
        $response = $this->forTenant($tenant)->getJson('/api/v1/skills')->assertOk();
        $skill = collect($response->json('data'))->firstWhere('id', 'yao-geo-channel-publishing');
        $this->assertSame('/#publishing', $skill['action_path']);
        $this->assertFalse($skill['requires_web']);
        $this->assertTrue($skill['available']);
        foreach (['execute', 'execute-async'] as $endpoint) {
            $this->forTenant($tenant)->postJson('/api/v1/skills/'.$endpoint, [
                'skill_id' => $skill['id'], 'project_id' => $project->id,
            ])->assertStatus(422)->assertJsonPath('action_path', '/#publishing');
        }
        $this->assertDatabaseCount('skill_executions', 0);
        $this->assertDatabaseCount('model_call_logs', 0);
    }

    public function test_sync_skill_execution_saves_successful_result(): void
    {
        $this->useRegistryFixture();
        $this->fakeRunner(success: true);
        [$tenant, $admin] = $this->createTenantAdmin('tenant-a');
        $project = $this->createProjectFor($tenant, $admin);
        Sanctum::actingAs($admin, ['*']);

        $response = $this
            ->forTenant($tenant)
            ->postJson('/api/v1/skills/execute', [
                'skill_id' => 'yao-geo-panorama-audit',
                'project_id' => $project->id,
                'fields' => [
                    'brand_name' => 'Acme',
                    'brand_url' => 'https://acme.example',
                ],
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('execution.status', 'completed')
            ->assertJsonPath('execution.skill_id', 'yao-geo-panorama-audit')
            ->assertJsonPath('execution.result_text', '# Skill result')
            ->assertJsonPath('execution.structured.summary', 'Ready');

        $execution = SkillExecution::query()->firstOrFail();
        $this->assertSame($tenant->id, $execution->tenant_id);
        $this->assertSame($project->id, $execution->project_id);
        $this->assertSame('completed', $execution->status);
        $this->assertSame(33, $execution->token_usage);
        $this->assertSame('Acme', $execution->input_data['brand_name']);

        $this
            ->forTenant($tenant)
            ->getJson("/api/v1/executions?project_id={$project->id}")
            ->assertOk()
            ->assertJsonPath('data.0.result_text', '# Skill result');
    }

    public function test_sync_unknown_gateway_attempt_returns_pending_without_article(): void
    {
        $this->useRegistryFixture();
        $runner = $this->createMock(SkillAgentRunner::class);
        $runner->method('execute')->willThrowException(new UnresolvedModelAttempt([
            'http_status' => 202, 'request_id' => 'sync-original',
            'attribution' => ['client_request_id' => 'sync-attempt'],
        ]));
        $this->app->instance(SkillAgentRunner::class, $runner);
        [$tenant, $admin] = $this->createTenantAdmin('sync-unknown');
        $project = $this->createProjectFor($tenant, $admin);
        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)->postJson('/api/v1/skills/execute', [
            'skill_id' => 'yao-geo-panorama-audit',
            'project_id' => $project->id,
            'fields' => ['brand_name' => 'Acme', 'brand_url' => 'https://acme.example'],
        ])->assertStatus(202)->assertJsonPath('action', 'billing_pending')
            ->assertJsonPath('execution.status', 'billing_pending')
            ->assertJsonPath('execution.billing.recovery_stage', 'model_attempt_unknown');

        $execution = SkillExecution::query()->latest('id')->firstOrFail();
        $this->assertSame('sync-original', data_get($execution->billing_pending_result, 'gateway.request_id'));
        $this->assertSame('Acme', data_get($execution->billing_pending_result, 'brief_data.fields.brand_name'));
        $this->assertSame($execution->id, data_get($execution->billing_pending_result, 'brief_data.executionId'));
        $this->assertSame(0, Article::query()->count());
    }

    public function test_sync_pending_embedding_returns_pending_without_article(): void
    {
        $this->useRegistryFixture();
        $runner = $this->createMock(SkillAgentRunner::class);
        $runner->method('execute')->willReturnCallback(function (SkillBrief $brief) {
            $clientId = PreparedEmbeddingAttempt::save(
                $brief->executionId, $brief->projectId, $brief->userId,
                $brief->skillId, 'skill:'.$brief->executionId, 'sync query', $brief->toArray(),
            );
            throw new UnresolvedEmbeddingAttempt([
                'http_status' => 202, 'attribution' => ['client_request_id' => $clientId],
            ]);
        });
        $this->app->instance(SkillAgentRunner::class, $runner);
        [$tenant, $admin] = $this->createTenantAdmin('sync-embedding-pending');
        $project = $this->createProjectFor($tenant, $admin);
        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)->postJson('/api/v1/skills/execute', [
            'skill_id' => 'yao-geo-panorama-audit', 'project_id' => $project->id,
            'fields' => ['brand_name' => 'Acme', 'brand_url' => 'https://acme.example'],
        ])->assertStatus(202)->assertJsonPath('action', 'billing_pending')
            ->assertJsonPath('execution.status', 'billing_pending')
            ->assertJsonPath('execution.billing.recovery_stage', 'embedding_attempt_unknown');

        $execution = SkillExecution::query()->latest('id')->firstOrFail();
        $this->assertSame('sync query', data_get($execution->billing_pending_result, 'query'));
        $this->assertSame(0, Article::query()->count());
    }

    public function test_sync_successful_model_with_local_usage_failure_seals_content(): void
    {
        $this->useRegistryFixture();
        $runner = $this->createMock(SkillAgentRunner::class);
        $runner->method('execute')->willThrowException(new UnrecordedModelResult(
            new AiProviderCallResult(success: true, provider: 'gateway', model: 'text',
                content: 'PRIVATE_MODEL_RESULT', promptTokens: 10, completionTokens: 5,
                totalTokens: 15, gateway: ['request_id' => 'sync-local-failure']),
            ['attempt' => 1, 'recovery' => false],
            new \RuntimeException('isolated local write failure'),
        ));
        $this->app->instance(SkillAgentRunner::class, $runner);
        [$tenant, $admin] = $this->createTenantAdmin('sync-local-write-failure');
        $project = $this->createProjectFor($tenant, $admin);
        Sanctum::actingAs($admin, ['*']);

        $response = $this->forTenant($tenant)->postJson('/api/v1/skills/execute', [
            'skill_id' => 'yao-geo-panorama-audit',
            'project_id' => $project->id,
            'fields' => ['brand_name' => 'Acme', 'brand_url' => 'https://acme.example'],
        ])->assertStatus(202)->assertJsonPath('action', 'billing_pending')
            ->assertJsonPath('execution.billing.recovery_stage', 'model_result_unrecorded');
        $this->assertStringNotContainsString('PRIVATE_MODEL_RESULT', $response->getContent());
        $execution = SkillExecution::query()->latest('id')->firstOrFail();
        $this->assertSame('model_result_unrecorded', data_get($execution->billing_pending_result, 'kind'));
        $this->assertSame('PRIVATE_MODEL_RESULT', data_get($execution->billing_pending_result, 'known_model_result.content'));
        $this->assertSame('Acme', data_get($execution->billing_pending_result, 'brief_data.fields.brand_name'));
        $this->assertStringNotContainsString('PRIVATE_MODEL_RESULT',
            (string) $execution->getRawOriginal('billing_pending_result'));
        $this->assertSame(0, Article::query()->count());
    }

    public function test_sync_skill_delivers_without_a_wallet_receipt(): void
    {

        $this->useRegistryFixture();
        [$tenant, $admin] = $this->createTenantAdmin('skill-wallet-sync');
        $project = $this->createProjectFor($tenant, $admin);
        $logId = null;
        $runner = $this->createMock(SkillAgentRunner::class);
        $runner->expects($this->once())->method('execute')
            ->willReturnCallback(function (SkillBrief $brief) use ($tenant, $admin, $project, &$logId): SkillExecutionResult {
                $log = ModelCallLog::query()->create([
                    'tenant_id' => $tenant->id, 'project_id' => $project->id,
                    'user_id' => $admin->id, 'skill_execution_id' => $brief->executionId,
                    'provider' => 'gateway', 'model' => 'text', 'status' => 'completed',
                    'prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30,
                    'duration_seconds' => 1.0, 'metadata' => [
                        'points_deduction_status' => 'pending',
                        'points_debit_prepared_at' => now()->toISOString(),
                    ],
                    'gateway_request_id' => 'sync-skill-wallet-pending',
                ]);
                $logId = $log->id;

                return new SkillExecutionResult(
                    executionId: $brief->executionId, skillId: $brief->skillId, success: true,
                    intermediateData: [
                        'raw_output' => 'PRIVATE_MODEL_RESULT',
                        'structured' => ['title' => 'Private skill result'],
                        'billing_pending_article_content' => '# PRIVATE_ARTICLE',
                    ],
                    qualityReport: ['passed' => true], tokenUsage: 30,
                );
            });
        $this->app->instance(SkillAgentRunner::class, $runner);
        Sanctum::actingAs($admin, ['*']);

        $response = $this->forTenant($tenant)->postJson('/api/v1/skills/execute', [
            'skill_id' => 'yao-geo-panorama-audit', 'project_id' => $project->id,
            'fields' => ['brand_name' => 'Acme', 'brand_url' => 'https://acme.example'],
        ])->assertOk()->assertJsonPath('execution.status', 'completed');
        $execution = SkillExecution::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('completed', $execution->fresh()->status);
        $this->assertSame('# PRIVATE_ARTICLE', Article::withoutGlobalScopes()->firstOrFail()->body_markdown);
    }


    public function test_knowledge_base_builder_routes_to_real_ingestion_panel_without_model_execution(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('tenant-a');
        $project = $this->createProjectFor($tenant, $admin);
        Sanctum::actingAs($admin, ['*']);

        $response = $this->forTenant($tenant)->getJson('/api/v1/skills')->assertOk();
        $skill = collect($response->json('data'))->firstWhere('id', 'yao-geo-knowledge-base-builder');

        $this->assertSame([], $skill['brief_fields']);
        $this->assertSame('/#knowledge', $skill['action_path']);
        $this->assertSame('进入企业知识库', $skill['action_label']);

        $this->forTenant($tenant)->postJson('/api/v1/skills/execute-async', [
            'skill_id' => 'yao-geo-knowledge-base-builder',
            'project_id' => $project->id,
        ])->assertStatus(422)
            ->assertJsonPath('action', 'open_skill_action')
            ->assertJsonPath('action_path', '/#knowledge');

        $this->assertDatabaseMissing('skill_executions', [
            'skill_id' => 'yao-geo-knowledge-base-builder',
            'project_id' => $project->id,
        ]);
    }

    public function test_site_template_routes_to_real_theme_package_panel_without_model_execution(): void
    {
        config(['geo.theme_package_enabled' => true]);
        [$tenant, $admin] = $this->createTenantAdmin('tenant-a');
        $project = $this->createProjectFor($tenant, $admin);
        Sanctum::actingAs($admin, ['*']);

        $response = $this->forTenant($tenant)->getJson('/api/v1/skills')->assertOk();
        $skill = collect($response->json('data'))->firstWhere('id', 'yao-geoflow-template');

        $this->assertSame('GEOFlow 真实主题包生成与预览', $skill['description']);
        $this->assertSame([], $skill['brief_fields']);
        $this->assertSame('/theme-package', $skill['action_path']);
        $this->assertSame('进入主题包生成', $skill['action_label']);
        $this->assertTrue($skill['available']);

        $this->forTenant($tenant)->postJson('/api/v1/skills/execute-async', [
            'skill_id' => 'yao-geoflow-template',
            'project_id' => $project->id,
        ])->assertStatus(422)
            ->assertJsonPath('action', 'open_skill_action')
            ->assertJsonPath('action_path', '/theme-package');

        $this->assertDatabaseMissing('skill_executions', [
            'skill_id' => 'yao-geoflow-template',
            'project_id' => $project->id,
        ]);
    }

    public function test_execution_responses_summarize_usage_without_amounts(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('tenant-a');
        $project = $this->createProjectFor($tenant, $admin);
        Sanctum::actingAs($admin, ['*']);

        TenantContext::set($tenant);
        try {
            $execution = SkillExecution::query()->create([
                'project_id' => $project->id,
                'user_id' => $admin->id,
                'skill_id' => 'yao-geo-explainer-builder',
                'skill_name' => '解释型文章',
                'category' => 'geo-content',
                'stage_index' => 2,
                'status' => 'failed',
                'execution_mode' => 'async',
                'input_data' => [],
                'error_message' => '最后一段失败',
            ]);

            ModelCallLog::query()->create([
                'tenant_id' => $tenant->id,
                'project_id' => $project->id,
                'user_id' => $admin->id,
                'skill_execution_id' => $execution->id,
                'provider' => 'gateway',
                'model' => 'text',
                'status' => 'completed',
                'prompt_tokens' => 100,
                'completion_tokens' => 200,
                'total_tokens' => 300,
                'metadata' => [
                    'points_deduction_status' => 'deducted',
                    'points_deducted' => 690.38,
                    // 内部成本即使存在于日志，也绝不能进入租户执行详情。
                    'upstream_cost_fen' => 999999,
                ],
            ]);
            ModelCallLog::query()->create([
                'tenant_id' => $tenant->id,
                'project_id' => $project->id,
                'user_id' => $admin->id,
                'skill_execution_id' => $execution->id,
                'provider' => 'gateway',
                'model' => 'text',
                // 模型已返回结果、但这一次客户扣点因余额不足失败，才属于客户
                // 计费链路的“部分已扣点”。provider 自身失败由平台承担，不应混入。
                'status' => 'completed',
                'metadata' => [
                    'points_deduction_status' => 'insufficient_balance',
                    'points_deduction_failure_reason' => 'insufficient balance',
                ],
            ]);
        } finally {
            TenantContext::forget();
        }

        $detail = $this
            ->forTenant($tenant)
            ->getJson('/api/v1/executions/'.$execution->id)
            ->assertOk()
            ->assertJsonPath('billing.model_call_count', 2)
            ->assertJsonPath('billing.total_tokens', 300)
            // 积分协议 v2：points_charged_fen 保留旧名，数值与 points_deducted 同为积分。
            ->assertJsonMissingPath('billing.points_charged_fen')
            ->assertJsonMissingPath('billing.points_deduction_status');

        $this->assertArrayNotHasKey('upstream_cost_fen', $detail->json('billing'));
        $this->assertStringNotContainsString('999999', (string) $detail->getContent());

        $this
            ->forTenant($tenant)
            ->getJson('/api/v1/executions/'.$execution->id.'/status')
            ->assertOk()
            ->assertJsonPath('execution.billing.model_call_count', 2)
            ->assertJsonPath('execution.billing.total_tokens', 300)
            ->assertJsonMissingPath('execution.billing.points_charged_fen')
            ->assertJsonMissingPath('execution.billing.points_deduction_status');
    }

    public function test_sync_skill_execution_cannot_use_another_tenants_project(): void
    {
        $this->useRegistryFixture();
        $this->fakeRunner(success: true);
        [$tenantA, $adminA] = $this->createTenantAdmin('tenant-a');
        [$tenantB, $adminB] = $this->createTenantAdmin('tenant-b');
        $projectB = $this->createProjectFor($tenantB, $adminB);
        Sanctum::actingAs($adminA, ['*']);

        $this
            ->forTenant($tenantA)
            ->postJson('/api/v1/skills/execute', [
                'skill_id' => 'yao-geo-panorama-audit',
                'project_id' => $projectB->id,
                'fields' => ['brand_name' => 'Tenant A'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['project_id']);

        $this->assertSame(0, SkillExecution::query()->count());
    }

    public function test_failed_skill_execution_persists_failed_status_with_friendly_error(): void
    {
        $this->useRegistryFixture();
        $this->fakeRunner(success: false);
        [$tenant, $admin] = $this->createTenantAdmin('tenant-a');
        $project = $this->createProjectFor($tenant, $admin);
        Sanctum::actingAs($admin, ['*']);

        $response = $this
            ->forTenant($tenant)
            ->postJson('/api/v1/skills/execute', [
                'skill_id' => 'yao-geo-panorama-audit',
                'project_id' => $project->id,
                'fields' => ['brand_name' => 'Acme'],
            ]);

        $response
            ->assertStatus(502)
            ->assertJsonPath('action', 'skill_execution_failed')
            ->assertJsonPath('execution.status', 'failed')
            ->assertJsonPath('message', 'Skill execution failed. Please review the brief and try again.');

        $execution = SkillExecution::query()->firstOrFail();
        $this->assertSame('failed', $execution->status);
        $this->assertSame('Skill execution failed. Please review the brief and try again.', $execution->error_message);
        $this->assertStringNotContainsString('C:\\secret', $execution->error_message);
    }

    public function test_explicit_material_refs_inject_into_brief_and_record_usage(): void
    {
        $this->useRegistryFixture();
        $this->fakeRunner(success: true);
        [$tenant, $admin] = $this->createTenantAdmin('tenant-a');
        $project = $this->createProjectFor($tenant, $admin);
        Sanctum::actingAs($admin, ['*']);

        TenantContext::set($tenant);
        $title = TitleLibrary::query()->create(['title' => '显式标题', 'tenant_id' => $tenant->id]);
        $keyword = KeywordLibrary::query()->create(['keyword' => 'SEO词', 'tenant_id' => $tenant->id]);
        $author = Author::query()->create(['name' => '张三', 'tenant_id' => $tenant->id]);
        TenantContext::forget();

        // 即便用户同时填了 query，显式选料仍优先注入（显式选择优先、留空才兜底）
        $response = $this
            ->forTenant($tenant)
            ->postJson('/api/v1/skills/execute', [
                'skill_id' => 'yao-geo-panorama-audit',
                'project_id' => $project->id,
                'fields' => ['brand_name' => 'Acme'],
                'query' => '一些背景说明',
                'material_refs' => [
                    ['type' => 'title', 'id' => $title->id],
                    ['type' => 'keyword', 'id' => $keyword->id],
                    ['type' => 'author', 'id' => $author->id],
                ],
            ]);

        $response->assertOk();

        $exec = SkillExecution::query()->firstOrFail();
        $this->assertSame('显式标题', $exec->input_data['topic'] ?? null);
        $this->assertSame('张三', $exec->input_data['author_name'] ?? null);
        $this->assertStringContainsString('SEO词', $exec->input_data['target_keywords'] ?? '');
        $this->assertSame('一些背景说明', $exec->input_data['supplemental_query'] ?? null);
        $this->assertSame([
            ['type' => 'title', 'id' => $title->id],
            ['type' => 'keyword', 'id' => $keyword->id],
            ['type' => 'author', 'id' => $author->id],
        ], $exec->input_data['material_refs'] ?? null);

        // 执行成功 → 选中素材的 usage_count 自增（同步内联 recordUsage）
        $this->assertSame(1, TitleLibrary::query()->whereKey($title->id)->value('usage_count'));
        $this->assertSame(1, KeywordLibrary::query()->whereKey($keyword->id)->value('usage_count'));
        $this->assertSame(1, Author::query()->whereKey($author->id)->value('usage_count'));
    }

    public function test_async_job_records_material_usage_on_success(): void
    {
        $this->useRegistryFixture();
        [$tenant, $admin] = $this->createTenantAdmin('tenant-a');
        $project = $this->createProjectFor($tenant, $admin);

        TenantContext::set($tenant);
        $title = TitleLibrary::query()->create(['title' => '异步标题', 'tenant_id' => $tenant->id]);
        $keyword = KeywordLibrary::query()->create(['keyword' => '异步词', 'tenant_id' => $tenant->id]);
        TenantContext::forget();

        // 成功 runner + 轻量 reportAssets 替身，只验证 Job 在成功时调用 recordUsage
        $this->app->instance(SkillAgentRunner::class, new class implements SkillAgentRunner {
            public function execute(SkillBrief $brief): SkillExecutionResult
            {
                return new SkillExecutionResult(
                    executionId: 0, skillId: $brief->skillId, success: true,
                    outputPaths: [], intermediateData: [], qualityReport: [], durationSeconds: 1, llmCallCount: 1, tokenUsage: 1,
                );
            }
            public function executeAsync(SkillBrief $brief): int { return 0; }
            public function getExecutionStatus(int $executionId): SkillExecutionResult { return SkillExecutionResult::failure('', 'x'); }
            public function cancel(int $executionId): bool { return false; }
        });
        $this->app->instance(\App\Services\Reports\ReportAssetService::class, new class extends \App\Services\Reports\ReportAssetService {
            public function __construct() {}
            public function createOrLinkForSkillExecution(\App\Models\SkillExecution $execution, string $markdown, ?int $candidateArticleId = null, ?array $structured = null, ?array $qualityReport = null): \App\Models\Article
            {
                return new \App\Models\Article();
            }
            public function outputDataWithArticle(array $outputData, \App\Models\Article $article): array
            {
                return $outputData;
            }
        });

        TenantContext::set($tenant);
        try {
            $execution = SkillExecution::query()->create([
                'project_id' => $project->id, 'user_id' => $admin->id, 'skill_id' => 'yao-geo-panorama-audit',
                'skill_name' => 'Panorama', 'category' => 'geo-strategy', 'stage_index' => 0,
                'status' => 'pending', 'execution_mode' => 'async', 'input_data' => [],
            ]);
        } finally {
            TenantContext::forget();
        }

        $brief = new SkillBrief(
            skillId: 'yao-geo-panorama-audit', projectId: $project->id, userId: $admin->id,
            fields: [], query: null,
            materialRefs: [['type' => 'title', 'id' => $title->id], ['type' => 'keyword', 'id' => $keyword->id]],
        );

        $job = new ExecuteSkillJob($execution->id, $brief->toArray(), $tenant->id);
        $job->handle(
            resolve(SkillAgentRunner::class),
            resolve(\App\Services\Reports\ReportAssetService::class),
            resolve(MaterialLibraryService::class),
        );

        $this->assertSame('completed', SkillExecution::query()->whereKey($execution->id)->value('status'));
        $this->assertSame(1, TitleLibrary::query()->whereKey($title->id)->value('usage_count'));
        $this->assertSame(1, KeywordLibrary::query()->whereKey($keyword->id)->value('usage_count'));
    }

    public function test_async_enqueue_restores_request_tenant_after_inline_job_clears_context(): void
    {
        config(['geo.skill_execution_claim_required' => true]);
        $this->useRegistryFixture();
        [$tenant, $admin] = $this->createTenantAdmin('async-response-context');
        $project = $this->createProjectFor($tenant, $admin);
        Sanctum::actingAs($admin, ['*']);
        $this->mock(SkillAgentRunner::class, function ($mock) use ($admin, $project): void {
            $mock->shouldReceive('executeAsync')->once()->andReturnUsing(
                function (SkillBrief $brief) use ($admin, $project): int {
                    $this->assertSame('skill-inline-context', $brief->clientRequestKey);
                    $execution = SkillExecution::query()->create([
                        'project_id' => $project->id, 'user_id' => $admin->id,
                        'skill_id' => $brief->skillId, 'skill_name' => 'Panorama',
                        'category' => 'geo-strategy', 'stage_index' => 0,
                        'status' => 'pending', 'execution_mode' => 'async', 'input_data' => [],
                    ]);
                    TenantContext::forget();
                    return $execution->id;
                },
            );
        });

        $payload = [
            'skill_id' => 'yao-geo-panorama-audit', 'project_id' => $project->id,
            'fields' => ['brand_name' => 'Acme', 'brand_url' => 'https://acme.example'],
        ];
        $this->forTenant($tenant)->postJson('/api/v1/skills/execute-async/claim', [
            'payload' => $payload, 'request_key' => 'skill-inline-context',
        ])->assertOk();
        $this->forTenant($tenant)->postJson('/api/v1/skills/execute-async', $payload,
            ['X-Idempotency-Key' => 'skill-inline-context'])
            ->assertStatus(202)
            ->assertJsonPath('execution.user_id', $admin->id)
            ->assertJsonPath('execution.project_id', $project->id);
        $this->forTenant($tenant)->postJson('/api/v1/skills/execute-async/seen', [
            'request_key' => 'skill-inline-context',
        ])->assertOk();
    }

    public function test_started_async_execution_recovers_after_controller_returns_500(): void
    {
        config(['geo.skill_execution_claim_required' => true]);
        $this->useRegistryFixture();
        [$tenant, $admin] = $this->createTenantAdmin('async-response-lost');
        $project = $this->createProjectFor($tenant, $admin);
        Sanctum::actingAs($admin, ['*']);
        $this->mock(SkillAgentRunner::class, function ($mock) use ($admin, $project): void {
            $mock->shouldReceive('executeAsync')->once()->andReturnUsing(
                function (SkillBrief $brief) use ($admin, $project): int {
                    $this->assertSame('skill-lost-response', $brief->clientRequestKey);
                    SkillExecution::query()->create([
                        'project_id' => $project->id, 'user_id' => $admin->id,
                        'skill_id' => $brief->skillId, 'skill_name' => 'Panorama',
                        'category' => 'geo-strategy', 'stage_index' => 0,
                        'status' => 'running', 'execution_mode' => 'async', 'input_data' => [],
                        'client_request_key' => $brief->clientRequestKey,
                    ]);
                    throw new \RuntimeException('response lost after the original task started');
                },
            );
        });
        $payload = [
            'skill_id' => 'yao-geo-panorama-audit', 'project_id' => $project->id,
            'fields' => ['brand_name' => 'Acme', 'brand_url' => 'https://acme.example'],
        ];
        $this->forTenant($tenant)->postJson('/api/v1/skills/execute-async/claim', [
            'payload' => $payload, 'request_key' => 'skill-lost-response',
        ])->assertOk();
        $this->forTenant($tenant)->postJson('/api/v1/skills/execute-async', $payload,
            ['X-Idempotency-Key' => 'skill-lost-response'])->assertStatus(500);
        $this->assertNull(\App\Models\ApiIdempotencyKey::query()->first()->response_status);
        $executionId = SkillExecution::query()->where('client_request_key', 'skill-lost-response')->value('id');
        $this->assertNotNull($executionId);
        $this->forTenant($tenant)->postJson('/api/v1/skills/execute-async/claim', [
            'payload' => $payload, 'request_key' => 'new-browser-key',
        ])->assertOk()->assertJsonPath('request_key', 'skill-lost-response')
            ->assertJsonPath('recovered_execution', true);
        $this->forTenant($tenant)->postJson('/api/v1/skills/execute-async', $payload,
            ['X-Idempotency-Key' => 'skill-lost-response'])
            ->assertStatus(202)->assertJsonPath('execution.id', $executionId);
        $this->assertDatabaseCount('skill_executions', 1);
    }

    public function test_async_enqueue_does_not_cache_success_without_owned_execution_row(): void
    {
        config(['geo.skill_execution_claim_required' => true]);
        $this->useRegistryFixture();
        [$tenant, $admin] = $this->createTenantAdmin('async-no-row');
        $project = $this->createProjectFor($tenant, $admin);
        Sanctum::actingAs($admin, ['*']);
        $this->mock(SkillAgentRunner::class, function ($mock): void {
            $mock->shouldReceive('executeAsync')->once()->andReturn(999999);
        });
        $payload = [
            'skill_id' => 'yao-geo-panorama-audit', 'project_id' => $project->id,
            'fields' => ['brand_name' => 'Acme', 'brand_url' => 'https://acme.example'],
        ];
        $this->forTenant($tenant)->postJson('/api/v1/skills/execute-async/claim', [
            'payload' => $payload, 'request_key' => 'skill-no-row',
        ])->assertOk();
        $this->forTenant($tenant)->postJson('/api/v1/skills/execute-async', $payload,
            ['X-Idempotency-Key' => 'skill-no-row'])
            ->assertStatus(503)->assertJsonPath('action', 'skill_execution_result_pending');
        $this->assertNull(\App\Models\ApiIdempotencyKey::query()->first()->response_status);
        $this->forTenant($tenant)->postJson('/api/v1/skills/execute-async', $payload,
            ['X-Idempotency-Key' => 'skill-no-row'])->assertStatus(409);
    }

    public function test_real_async_runner_persists_request_key_before_queue_dispatch(): void
    {
        config(['geo.skill_execution_claim_required' => true]);
        $this->useRegistryFixture();
        [$tenant, $admin] = $this->createTenantAdmin('async-real-runner-key');
        $project = $this->createProjectFor($tenant, $admin);
        Sanctum::actingAs($admin, ['*']);
        Queue::fake();
        $payload = [
            'skill_id' => 'yao-geo-panorama-audit', 'project_id' => $project->id,
            'fields' => ['brand_name' => 'Acme', 'brand_url' => 'https://acme.example'],
        ];
        $this->forTenant($tenant)->postJson('/api/v1/skills/execute-async/claim', [
            'payload' => $payload, 'request_key' => 'skill-real-runner',
        ])->assertOk();
        $response = $this->forTenant($tenant)->postJson('/api/v1/skills/execute-async', $payload,
            ['X-Idempotency-Key' => 'skill-real-runner'])->assertStatus(202);
        $executionId = $response->json('execution.id');
        $this->assertDatabaseHas('skill_executions', [
            'id' => $executionId, 'tenant_id' => $tenant->id, 'user_id' => $admin->id,
            'client_request_key' => 'skill-real-runner', 'status' => 'pending',
        ]);
        Queue::assertPushed(ExecuteSkillJob::class, 1);
    }

    private function useRegistryFixture(): void
    {
        $path = storage_path('framework/testing/workspace-skill-registry.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'skills' => [[
                'id' => 'yao-geo-panorama-audit',
                'family' => 'geo-strategy',
                'maturity' => 'beta',
                'requires_web' => true,
                'primary_outputs' => ['geo-panorama-audit-report'],
                'status' => 'active',
                'path' => 'skills/yao-geo-panorama-audit',
                'summary' => 'Run a GEO panorama audit.',
            ]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // This fixture explicitly approves its own IDs; production remains capped at 17.
        config(['geo.skill_registry_path' => $path, 'geo.skill_allowed_ids' => array_column(array_filter(json_decode(file_get_contents($path), true)['skills'], fn ($row) => ($row['status'] ?? null) === 'active'), 'id')]);
        $this->app->forgetInstance(SkillRegistry::class);
    }

    private function fakeRunner(bool $success): void
    {
        $this->app->instance(SkillAgentRunner::class, new class($success) implements SkillAgentRunner {
            public function __construct(private readonly bool $success) {}

            public function execute(SkillBrief $brief): SkillExecutionResult
            {
                if (! $this->success) {
                    return SkillExecutionResult::failure($brief->skillId, 'C:\\secret\\runner.php failed with stack trace');
                }

                return new SkillExecutionResult(
                    executionId: 0,
                    skillId: $brief->skillId,
                    success: true,
                    outputPaths: [],
                    intermediateData: [
                        'raw_output' => '# Skill result',
                        'structured' => ['summary' => 'Ready'],
                        'pipeline_mode' => 'fallback',
                    ],
                    qualityReport: ['passed' => true, 'score' => 88],
                    durationSeconds: 1.2,
                    llmCallCount: 1,
                    tokenUsage: 33,
                );
            }

            public function executeAsync(SkillBrief $brief): int
            {
                return 0;
            }

            public function getExecutionStatus(int $executionId): SkillExecutionResult
            {
                return SkillExecutionResult::failure('', 'Not implemented in test.');
            }

            public function cancel(int $executionId): bool
            {
                return false;
            }
        });
    }

    public function test_suggest_keywords_charges_one_assist_call_and_is_hidden_from_history(): void
    {
        $this->useRegistryFixture();
        [$tenant, $admin] = $this->createTenantAdmin('tenant-a');
        $project = $this->createProjectFor($tenant, $admin);
        Sanctum::actingAs($admin, ['*']);

        // AI 辅助建议的模型调用（generateStructured）返回成功
        $this->mock(\App\Services\Contracts\AiProvider::class, function ($m) {
            $m->shouldReceive('generateStructured')->andReturn(new \App\Services\DTOs\AiProviderCallResult(
                success: true,
                provider: 'gateway',
                model: 'text',
                structuredOutput: ['keywords' => ['geo 优化', 'AI 搜索', '内容营销']],
                promptTokens: 200,
                completionTokens: 300,
                totalTokens: 500,
                durationSeconds: 1.0,
                finishReason: 'stop',
            ));
        });

        $response = $this
            ->forTenant($tenant)
            ->withHeader('X-Assist-Attempt-Key', 'suggest-keywords-fixture-1')
            ->postJson('/api/v1/skills/suggest-keywords', [
                'domain' => 'https://acme.example',
                'project_id' => $project->id,
            ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('keywords'));

        // 扣费收口：建了一条 assist 模式的 execution，并记了一条 ModelCallLog（唯一记账）
        $assist = SkillExecution::query()
            ->where('project_id', $project->id)
            ->where('execution_mode', 'assist')
            ->where('skill_id', 'ai-assist-keywords')
            ->first();
        $this->assertNotNull($assist, 'AI 辅助建议应建 assist execution 用于扣费');
        $this->assertSame(1, \App\Models\ModelCallLog::query()->where('skill_execution_id', $assist->id)->count());

        // assist execution 不出现在「项目技能执行记录」历史列表里
        $history = $this
            ->forTenant($tenant)
            ->getJson('/api/v1/executions?project_id='.$project->id)
            ->assertOk();
        $ids = collect($history->json('data'))->pluck('id')->all();
        $this->assertNotContains($assist->id, $ids, 'assist execution 不应污染执行历史');
    }

    public function test_assist_suggestion_requires_attempt_identity_before_model_send(): void
    {
        $this->useRegistryFixture();
        [$tenant, $admin] = $this->createTenantAdmin('tenant-assist-key');
        $project = $this->createProjectFor($tenant, $admin);
        Sanctum::actingAs($admin, ['*']);
        $this->mock(\App\Services\Contracts\AiProvider::class, function ($mock) {
            $mock->shouldNotReceive('generateStructured');
        });

        $this->forTenant($tenant)->postJson('/api/v1/skills/suggest-keywords', [
            'domain' => 'GEO 服务', 'project_id' => $project->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->forTenant($tenant)->withHeader('X-Assist-Attempt-Key', 'assist-no-project')
            ->postJson('/api/v1/skills/suggest-keywords', ['domain' => 'GEO 服务'])
            ->assertUnprocessable()->assertJsonValidationErrors('project_id');
    }

    public function test_assist_suggestion_reuses_task_and_debit_on_same_attempt_replay(): void
    {
        $this->useRegistryFixture();
        [$tenant, $admin] = $this->createTenantAdmin('tenant-assist-replay');
        $project = $this->createProjectFor($tenant, $admin);
        Sanctum::actingAs($admin, ['*']);
        $optionsSeen = [];
        $this->mock(\App\Services\Contracts\AiProvider::class, function ($mock) use (&$optionsSeen) {
            $mock->shouldReceive('generateStructured')->twice()->andReturnUsing(
                function ($prompt, $schema, $system, $options) use (&$optionsSeen) {
                    $optionsSeen[] = $options;

                    return new \App\Services\DTOs\AiProviderCallResult(
                        success: true, provider: 'gateway', model: 'text',
                        structuredOutput: ['keywords' => ['GEO 优化']],
                        gateway: ['request_id' => 'assist-gateway-replay-1'],
                    );
                },
            );
        });

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->forTenant($tenant)->withHeader('X-Assist-Attempt-Key', 'assist-replay-one')
                ->postJson('/api/v1/skills/suggest-keywords', [
                    'domain' => 'GEO 服务', 'project_id' => $project->id,
                ])->assertOk();
        }

        $this->assertSame($optionsSeen[0]['task_id'], $optionsSeen[1]['task_id']);
        $this->assertSame('suggest', $optionsSeen[0]['step_id']);
        $this->assertSame('keyword-recommendation', $optionsSeen[0]['feature_id']);
        $this->assertSame(1, \App\Models\ModelCallLog::query()->count());
    }

    public function test_assist_suggestion_reports_gateway_conflict_without_another_debit(): void
    {
        $this->useRegistryFixture();
        [$tenant, $admin] = $this->createTenantAdmin('tenant-assist-conflict');
        $project = $this->createProjectFor($tenant, $admin);
        Sanctum::actingAs($admin, ['*']);
        $this->mock(\App\Services\Contracts\AiProvider::class, function ($mock) {
            $mock->shouldReceive('generateStructured')->once()->andReturn(
                \App\Services\DTOs\AiProviderCallResult::failure(
                    'gateway', 'text', 'conflict', ['http_status' => 409],
                ),
            );
        });

        $this->forTenant($tenant)->withHeader('X-Assist-Attempt-Key', 'assist-conflict-one')
            ->postJson('/api/v1/skills/suggest-keywords', [
                'domain' => 'GEO 服务', 'project_id' => $project->id,
            ])->assertConflict();
        $this->assertSame(0, \App\Models\ModelCallLog::query()->count());
    }

    public function test_assist_suggestion_keeps_result_pending_when_local_usage_save_fails(): void
    {
        $this->useRegistryFixture();
        [$tenant, $admin] = $this->createTenantAdmin('tenant-assist-save-failure');
        $project = $this->createProjectFor($tenant, $admin);
        Sanctum::actingAs($admin, ['*']);
        $this->mock(\App\Services\Contracts\AiProvider::class, function ($mock) {
            $mock->shouldReceive('generateStructured')->once()->andReturn(
                new \App\Services\DTOs\AiProviderCallResult(
                    success: true, provider: 'gateway', model: 'text',
                    structuredOutput: ['keywords' => ['GEO 优化']],
                    gateway: ['request_id' => 'assist-unsaved-gateway-1'],
                ),
            );
        });
        $this->mock(\App\Services\Billing\AssistUsageRecorder::class, function ($mock) {
            $mock->shouldReceive('record')->once()->andReturn(null);
        });

        $this->forTenant($tenant)->withHeader('X-Assist-Attempt-Key', 'assist-unsaved-one')
            ->postJson('/api/v1/skills/suggest-keywords', [
                'domain' => 'GEO 服务', 'project_id' => $project->id,
            ])->assertStatus(503)->assertJsonPath('action', 'idempotency_result_pending')
            ->assertDontSee('GEO 优化');
    }

    // ════════════════════════════════════════════════════════════════════
    // 竞品推荐的官网抓取（brand_url）—— 本仓库第三个对外抓取入口
    //
    // 这个入口一度是防护最弱的一个：brand_url 的验证只有 `nullable|url`，
    // 而 Laravel 的 url 规则认 http://127.0.0.1:8792/（网关自己）和
    // http://169.254.169.254/（云元数据）。抓回来的正文会进提示词，模型输出
    // 再回给用户 —— 内网内容有渠道漏出去，所以必须按对外抓取的标准盯着。
    // ════════════════════════════════════════════════════════════════════

    public function test_suggest_competitors_fetches_brand_site_through_the_safe_outbound_chain(): void
    {
        $this->fakeSafeOutbound('<html><head><title>ACME 工业清洗设备</title></head><body>专注食品厂 CIP 清洗系统</body></html>');
        $this->useRegistryFixture();
        [$tenant, $admin] = $this->createTenantAdmin('tenant-a');
        $project = $this->createProjectFor($tenant, $admin);
        Sanctum::actingAs($admin, ['*']);

        $prompt = $this->mockCompetitorAi();

        $this
            ->forTenant($tenant)
            ->withHeader('X-Assist-Attempt-Key', 'test-st-competitors-fixture-1')
            ->postJson('/api/v1/skills/suggest-competitors', [
                'brand_name' => 'ACME',
                'brand_url' => 'https://acme.example/',
                'project_id' => $project->id,
            ])
            ->assertOk()
            ->assertJsonPath('competitors.0', '甲公司');

        // 走的是安全链（假 transport 记录到了），不是原生门面。
        $this->assertOutboundRequestCount(1);
        // 带上了响应体上限：抓到超大页面不会把 worker 内存撑爆。
        $this->assertOutboundMaxBytes(5 * 1024 * 1024);
        // 抓到的正文确实进了提示词 —— 证明这条链路没被改成「抓了但没用」。
        $this->assertStringContainsString('ACME 工业清洗设备', (string) $prompt->value);
    }

    public function test_suggest_competitors_extends_and_restores_the_php_request_time_budget(): void
    {
        $previous = (string) ini_get('max_execution_time');
        ini_set('max_execution_time', '30');
        try {
            $this->useRegistryFixture();
            [$tenant, $admin] = $this->createTenantAdmin('assist-time-budget');
            $project = $this->createProjectFor($tenant, $admin);
            Sanctum::actingAs($admin, ['*']);
            $capture = $this->mockCompetitorAi();

            $this->forTenant($tenant)
                ->withHeader('X-Assist-Attempt-Key', 'suggest-competitors-time-budget')
                ->postJson('/api/v1/skills/suggest-competitors', [
                    'brand_name' => 'ACME',
                    'project_id' => $project->id,
                ])->assertOk();

            $this->assertGreaterThan(30, $capture->maxExecutionTime);
            $this->assertSame(30, (int) ini_get('max_execution_time'));
        } finally {
            ini_set('max_execution_time', $previous);
        }
    }

    public function test_suggest_competitors_does_not_fetch_a_brand_url_that_resolves_into_the_intranet(): void
    {
        // 域名是公网的，DNS 却解析到云元数据地址 —— 经典 SSRF 打法。
        $this->fakeSafeOutbound('<html><title>不该被抓到</title></html>', 200, ['169.254.169.254']);
        $this->useRegistryFixture();
        [$tenant, $admin] = $this->createTenantAdmin('tenant-a');
        $project = $this->createProjectFor($tenant, $admin);
        Sanctum::actingAs($admin, ['*']);

        $prompt = $this->mockCompetitorAi();

        $this
            ->forTenant($tenant)
            ->withHeader('X-Assist-Attempt-Key', 'test-st-competitors-fixture-2')
            ->postJson('/api/v1/skills/suggest-competitors', [
                'brand_name' => 'ACME',
                'brand_url' => 'https://attacker-controlled.example/',
                'project_id' => $project->id,
            ])
            // 抓不到官网不该让整个接口失败：品牌名 + 项目描述还能给出结果。
            ->assertOk();

        // 拦在发包之前，一个包都没出去。
        $this->assertOutboundRequestCount(0);
        $this->assertStringNotContainsString('不该被抓到', (string) $prompt->value);
    }

    public function test_suggest_competitors_does_not_fetch_the_local_gateway(): void
    {
        // 网关就跑在 127.0.0.1:8792，是这条链路能够到的最敏感的目标。
        $this->fakeSafeOutbound('{"models":["geo-max"]}');
        $this->useRegistryFixture();
        [$tenant, $admin] = $this->createTenantAdmin('tenant-a');
        $project = $this->createProjectFor($tenant, $admin);
        Sanctum::actingAs($admin, ['*']);

        $prompt = $this->mockCompetitorAi();

        $this
            ->forTenant($tenant)
            ->withHeader('X-Assist-Attempt-Key', 'test-st-competitors-fixture-3')
            ->postJson('/api/v1/skills/suggest-competitors', [
                'brand_name' => 'ACME',
                'brand_url' => 'http://127.0.0.1:8792/v1/models',
                'project_id' => $project->id,
            ])
            ->assertOk();

        $this->assertOutboundRequestCount(0);
        $this->assertStringNotContainsString('text', (string) $prompt->value);
    }

    /**
     * 把竞品推荐用到的模型调用打桩，并返回一个能读到「实际发出的提示词」的句柄。
     *
     * 之所以要看提示词：抓取被拦有两种可能 —— 真的没抓，或者抓了但内容没进提示词。
     * 只断言「没发包」不足以证明内网内容没漏出去，得连提示词一起验。
     *
     * @return object{value: ?string}
     */
    private function mockCompetitorAi(): object
    {
        $capture = new class
        {
            public ?string $value = null;
            public ?int $maxExecutionTime = null;
        };

        $this->mock(\App\Services\Contracts\AiProvider::class, function ($m) use ($capture) {
            $m->shouldReceive('generateStructured')->andReturnUsing(
                function (string $prompt) use ($capture): \App\Services\DTOs\AiProviderCallResult {
                    $capture->value = $prompt;
                    $capture->maxExecutionTime = (int) ini_get('max_execution_time');

                    return new \App\Services\DTOs\AiProviderCallResult(
                        success: true,
                        provider: 'gateway',
                        model: 'text',
                        structuredOutput: ['competitors' => ['甲公司', '乙公司']],
                        promptTokens: 200,
                        completionTokens: 300,
                        totalTokens: 500,
                        durationSeconds: 1.0,
                        finishReason: 'stop',
                    );
                }
            );
        });

        return $capture;
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
