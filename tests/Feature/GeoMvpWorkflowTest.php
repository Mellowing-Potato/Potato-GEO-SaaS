<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\GeoAnalysisController;
use App\Jobs\RunGeoAnalysisJob;
use App\Models\Article;
use App\Models\ArticlePublication;
use App\Models\AiVisibilityRun;
use App\Models\ModelCallLog;
use App\Models\Project;
use App\Models\PublishingChannel;
use App\Models\SkillExecution;
use App\Models\Tenant;
use App\Models\TenantAiKey;
use App\Models\User;
use App\Services\AI\AiProviderServiceImpl;
use App\Services\Contracts\SkillAgentRunner;
use App\Services\DTOs\AiProviderCallResult;
use App\Services\DTOs\SkillBrief;
use App\Services\DTOs\SkillExecutionResult;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GeoMvpWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Support\UsesWorkspaceModels;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.tenant_domain' => 'geo.example.test']);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();

        parent::tearDown();
    }

    public function test_legacy_analysis_delivers_without_a_wallet_receipt(): void
    {
        config([
            'geo.analysis_pipeline_mode' => 'legacy',
        ]);
        Queue::fake();
        [$tenant, $admin] = $this->createTenantAdmin('legacy-billing-pending');
        $project = $this->createProjectFor($tenant, $admin);
        $this->useWorkspaceModels($tenant);
        $provider = $this->createMock(AiProviderServiceImpl::class);
        $provider->expects($this->once())->method('generateStructured')->willReturn(new AiProviderCallResult(
            success: true, provider: 'gateway', model: 'text',
            content: 'PRIVATE_LEGACY_REPORT_BODY',
            structuredOutput: [
                'summary' => 'PRIVATE_LEGACY_REPORT_SUMMARY',
                'key_findings' => ['分析发现'], 'recommendations' => ['建议'], 'next_actions' => ['执行'],
            ],
            promptTokens: 100, completionTokens: 50, totalTokens: 150,
            gateway: ['request_id' => 'gateway-legacy-analysis-wallet-pending'],
        ));
        $this->app->instance(AiProviderServiceImpl::class, $provider);
        Http::fake(['*/internal/points/debit' => \Illuminate\Support\Facades\Http::sequence()
            ->push(['message' => 'wallet response lost'], 503)
            ->push(['status' => 'deducted', 'external_transaction_id' => 'txn-legacy-analysis',
                'amount_fen' => 0.1, 'points_deducted' => 10])]);
        Sanctum::actingAs($admin, ['*']);

        $first = $this->forTenant($tenant)->postJson("/api/v1/projects/{$project->id}/analyses")
            ->assertCreated()->assertJsonPath('analysis.status', 'completed');
        $execution = SkillExecution::query()->firstOrFail();
        Http::assertNothingSent();
        $this->assertSame('completed', $execution->fresh()->status);
        $this->assertNull($execution->fresh()->billing_pending_result);
        $this->assertSame(1, Article::query()->count());
        $this->forTenant($tenant)->getJson("/api/v1/projects/{$project->id}/analyses/{$execution->id}")
            ->assertJsonPath('analysis.report.summary', 'PRIVATE_LEGACY_REPORT_SUMMARY')
            ->assertJsonPath('analysis.model_usage.total_tokens', 150);
        Http::assertNothingSent();
    }

    public function test_project_creation_saves_geo_profile_and_initializes_stages(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('tenant-a');
        Sanctum::actingAs($admin, ['*']);

        $response = $this
            ->forTenant($tenant)
            ->postJson('/api/v1/projects', [
                'name' => 'Acme GEO',
                'brand_name' => 'Acme',
                'brand_url' => 'https://acme.example',
                'product_description' => 'AI search optimization platform',
                'target_market' => 'B2B SaaS teams',
                'target_keywords' => ['AI search visibility', 'GEO platform'],
                'competitors' => ['Competitor One', 'Competitor Two'],
                'goals' => 'Increase AI citations.',
            ]);

        $response
            ->assertStatus(201)
            ->assertJsonPath('owner_id', $admin->id)
            ->assertJsonPath('settings.product_description', 'AI search optimization platform')
            ->assertJsonPath('settings.target_keywords.0', 'AI search visibility')
            ->assertJsonPath('settings.competitors.1', 'Competitor Two')
            ->assertJsonCount(6, 'stages');

        $project = Project::query()->firstOrFail();
        $this->assertSame($tenant->id, $project->tenant_id);
        $this->assertSame($admin->id, $project->owner_id);
    }

    public function test_project_routes_are_scoped_to_current_tenant(): void
    {
        [$tenantA, $adminA] = $this->createTenantAdmin('tenant-a');
        [$tenantB, $adminB] = $this->createTenantAdmin('tenant-b');
        $projectB = $this->createProjectFor($tenantB, $adminB);

        Sanctum::actingAs($adminA, ['*']);

        $this
            ->forTenant($tenantA)
            ->getJson("/api/v1/projects/{$projectB->id}")
            ->assertNotFound();
    }

    public function test_project_can_be_deleted_and_removed_from_project_list(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('tenant-delete');
        $project = $this->createProjectFor($tenant, $admin);

        TenantContext::set($tenant);
        try {
            $execution = SkillExecution::query()->create([
                'project_id' => $project->id,
                'user_id' => $admin->id,
                'skill_id' => Project::GEO_ANALYSIS_SKILL_ID,
                'skill_name' => 'GEO Analysis',
                'category' => 'geo-strategy',
                'status' => 'completed',
            ]);

            $article = Article::query()->create([
                'project_id' => $project->id,
                'author_id' => $admin->id,
                'title' => 'Deleted project report',
                'body_markdown' => '# Deleted project report',
                'status' => 'approved',
                'source_type' => 'geo_analysis',
                'source_skill' => Project::GEO_ANALYSIS_SKILL_ID,
                'skill_execution_id' => $execution->id,
            ]);

            $execution->update(['article_id' => $article->id]);

            $modelCallLog = ModelCallLog::query()->create([
                'project_id' => $project->id,
                'user_id' => $admin->id,
                'skill_execution_id' => $execution->id,
                'provider' => 'mock',
                'model' => 'mock-model',
                'status' => 'completed',
            ]);
        } finally {
            TenantContext::forget();
        }

        Sanctum::actingAs($admin, ['*']);

        $this
            ->forTenant($tenant)
            ->deleteJson("/api/v1/projects/{$project->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('projects', ['id' => $project->id]);

        $this
            ->forTenant($tenant)
            ->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // 2026-09-21 口径：删除 = 进垃圾桶（7 天内可恢复）→ 文章与分析报告**原地保留**，
        // 期满由 geo:projects-purge 才真删。这一段现在守"内容还在"；
        // "清理后真删、流水照留"的完整守卫在 ProjectTrashTest / ProjectDeleteKeepsCallLogsTest。
        $this->assertDatabaseHas('articles', ['id' => $article->id]);
        $this->assertDatabaseHas('skill_executions', ['id' => $execution->id]);

        // ⚠️ 调用流水**不删**（老板 2026-09-04 拍板：跟钱有关的记录必须有据可查）。
        //    这条断言原来写的是 assertDatabaseMissing —— 守的是被推翻的旧行为，已反转。
        //    完整的守卫在 ProjectDeleteKeepsCallLogsTest（含归属快照与用量明细）。
        $this->assertDatabaseHas('model_call_logs', ['id' => $modelCallLog->id]);

        $this
            ->forTenant($tenant)
            ->getJson('/api/v1/articles')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }




    public function test_analysis_creation_uses_workspace_model_and_persists_result(): void
    {


        [$tenant, $admin] = $this->createTenantAdmin('tenant-a');
        $project = $this->createProjectFor($tenant, $admin, [
            'brand_name' => 'Acme',
            'brand_url' => 'https://acme.example',
            'settings' => [
                'product_description' => 'AI search optimization platform',
                'target_keywords' => ['GEO platform'],
                'competitors' => ['Competitor One'],
                'goals' => 'Increase AI citations.',
            ],
        ]);
        $this->useWorkspaceModels($tenant);

        Sanctum::actingAs($admin, ['*']);

        Http::fake([
            'https://models.example.test/v1/chat/completions' => \Illuminate\Support\Facades\Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'summary' => 'Acme has a clear GEO opportunity.',
                                'key_findings' => ['Brand pages lack AI-ready answers.'],
                                'recommendations' => ['Publish FAQ-led comparison content.'],
                                'next_actions' => ['Run a content gap sprint.'],
                                'overall_score' => 72,
                            ]),
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 20,
                    'completion_tokens' => 22,
                    'total_tokens' => 42,
                ],
            ]),
        ]);

        $response = $this
            ->forTenant($tenant)
            ->postJson("/api/v1/projects/{$project->id}/analyses", [
                'notes' => 'Focus on quick wins.',
            ]);

        $response
            ->assertStatus(201)
            ->assertJsonPath('analysis.status', 'completed')
            ->assertJsonPath('analysis.output_data.analysis.summary', 'Acme has a clear GEO opportunity.')
            ->assertJsonPath('analysis.output_data.analysis.key_findings.0', 'Brand pages lack AI-ready answers.')
            ->assertJsonPath('analysis.report.summary', 'Acme has a clear GEO opportunity.')
            ->assertJsonPath('analysis.report.overall_score', 72)
            ->assertJsonPath('analysis.model_usage.total_tokens', 42)
            ->assertJsonPath('analysis.token_usage', 42)
            ->assertJsonStructure(['analysis' => ['article_id', 'article_url', 'article_download_url']])
            ->assertJsonCount(1, 'analysis.model_call_logs');

        $execution = SkillExecution::query()->firstOrFail();
        $this->assertSame(Project::GEO_ANALYSIS_SKILL_ID, $execution->skill_id);
        $this->assertSame($tenant->id, $execution->tenant_id);
        $this->assertSame($project->id, $execution->project_id);
        $this->assertSame($admin->id, $execution->user_id);

        $article = Article::query()->firstOrFail();
        $this->assertSame($article->id, $execution->article_id);
        $this->assertSame($execution->id, $article->skill_execution_id);
        $this->assertSame('geo_analysis', $article->source_type);
        $this->assertSame(Project::GEO_ANALYSIS_SKILL_ID, $article->source_skill);
        $this->assertSame('Acme has a clear GEO opportunity.', $article->metadata['structured']['summary']);

        $this->assertDatabaseHas('model_call_logs', [
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'user_id' => $admin->id,
            'skill_execution_id' => $execution->id,
            'provider' => 'custom',
            'model' => 'test-text',
            'total_tokens' => 42,
        ]);
        $outputData = $execution->output_data;
        $outputData['model_usage']['points_deduction_failure_reason'] = 'Output token=sk-test-59e813e0200ce5739bb failed for fixture-user-7@example.com phone 13800000027';
        $outputData['model_usage']['external_transaction_id'] = 'qh_geo_output_secret_123456';
        $outputData['model_usage']['points_deduction_idempotency_key'] = 'geo-model-call-output-secret';
        $outputData['model_call']['points_deduction_failure_reason'] = 'Output token=sk-test-59e813e0200ce5739bb failed for fixture-user-7@example.com phone 13800000027';
        $outputData['model_call']['external_transaction_id'] = 'qh_geo_call_secret_123456';
        $outputData['model_call']['points_deduction_idempotency_key'] = 'geo-model-call-call-secret';
        $execution->forceFill(['output_data' => $outputData])->save();
        ModelCallLog::query()->firstOrFail()->update([
            'metadata' => [
                'points_deduction_status' => 'failed',
                'points_deduction_failure_reason' => 'Debit api_key=qh-analysis-secret-1234567890 failed for fixture-user-7@example.com phone 13800000027',
                'external_transaction_id' => 'qh_txn_analysis_secret_123456',
                'points_deduction_idempotency_key' => 'geo-model-call-analysis-secret',
            ],
        ]);
        $article->forceFill([
            'body_markdown' => $article->body_markdown."\n\nExternal transaction: qh_geo_article_secret_123456\nIdempotency key: geo-model-call-article-secret",
            'metadata' => array_merge($article->metadata ?? [], [
                'structured' => array_merge($article->metadata['structured'] ?? [], [
                    'model_usage' => [
                        'external_transaction_id' => 'qh_txn_article_secret_123456',
                        'points_deduction_idempotency_key' => 'geo-model-call-article-secret',
                    ],
                ]),
            ]),
        ])->save();

        $this
            ->forTenant($tenant)
            ->getJson("/api/v1/projects/{$project->id}/analyses")
            ->assertOk()
            ->assertJsonPath('data.0.status', 'completed');

        $showResponse = $this
            ->forTenant($tenant)
            ->getJson("/api/v1/projects/{$project->id}/analyses/{$execution->id}")
            ->assertOk()
            ->assertJsonPath('analysis.output_data.analysis.next_actions.0', 'Run a content gap sprint.')
            ->assertJsonPath('analysis.output_data.model_usage.points_deduction_failure_reason', 'Output token=[redacted_secret] failed for [redacted_email] phone [redacted_phone]')
            ->assertJsonPath('analysis.output_data.model_usage.external_transaction_id', '[redacted_id]')
            ->assertJsonPath('analysis.output_data.model_usage.points_deduction_idempotency_key', '[redacted_id]')
            ->assertJsonPath('analysis.output_data.model_call.points_deduction_failure_reason', 'Output token=[redacted_secret] failed for [redacted_email] phone [redacted_phone]')
            ->assertJsonMissingPath('analysis.model_usage.external_transaction_id_present')
            ->assertJsonMissingPath('analysis.model_usage.external_transaction_fingerprint')
            ->assertJsonMissingPath('analysis.model_usage.points_deduction_failure_reason')
            ->assertJsonMissingPath('analysis.model_call_logs.0.external_transaction_id_present')
            ->assertJsonMissingPath('analysis.model_call_logs.0.external_transaction_fingerprint')
            ->assertJsonMissingPath('analysis.model_call_logs.0.points_deduction_failure_reason')
            ->assertJsonPath('analysis.article_id', $article->id)
            ->assertJsonPath('analysis.download_url', "/api/v1/projects/{$project->id}/analyses/{$execution->id}/download");
        $this->assertStringNotContainsString('sk-test-59e813e0200ce5739bb', $showResponse->getContent());
        $this->assertStringNotContainsString('qh-analysis-secret-1234567890', $showResponse->getContent());
        $this->assertStringNotContainsString('qh_geo_output_secret_123456', $showResponse->getContent());
        $this->assertStringNotContainsString('qh_geo_call_secret_123456', $showResponse->getContent());
        $this->assertStringNotContainsString('qh_txn_analysis_secret_123456', $showResponse->getContent());
        $this->assertStringNotContainsString('geo-model-call-output-secret', $showResponse->getContent());
        $this->assertStringNotContainsString('geo-model-call-call-secret', $showResponse->getContent());
        $this->assertStringNotContainsString('geo-model-call-analysis-secret', $showResponse->getContent());
        $this->assertStringNotContainsString('Internal point deduction', $showResponse->getContent());
        $this->assertStringNotContainsString('fixture-user-7@example.com', $showResponse->getContent());
        $this->assertStringNotContainsString('13800000027', $showResponse->getContent());

        $articleListResponse = $this
            ->forTenant($tenant)
            ->getJson("/api/v1/projects/{$project->id}/articles")
            ->assertOk()
            ->assertJsonPath('data.0.id', $article->id)
            ->assertJsonPath('data.0.source_type', 'geo_analysis')
            ->assertJsonPath('data.0.structured.summary', 'Acme has a clear GEO opportunity.')
            ->assertJsonPath('data.0.structured.model_usage.external_transaction_id', '[redacted_id]')
            ->assertJsonPath('data.0.structured.model_usage.points_deduction_idempotency_key', '[redacted_id]');
        $this->assertStringNotContainsString('qh_txn_article_secret_123456', $articleListResponse->getContent());
        $this->assertStringNotContainsString('geo-model-call-article-secret', $articleListResponse->getContent());

        $markdown = $response->json('analysis.report_markdown');
        $this->assertStringContainsString('## 综合结论', $markdown);
        $this->assertStringContainsString('## 模型消耗', $markdown);

        $download = $this
            ->forTenant($tenant)
            ->get("/api/v1/projects/{$project->id}/analyses/{$execution->id}/download");

        $download->assertOk();
        $this->assertStringContainsString('GEO Analysis Report', $download->getContent());
        $this->assertStringContainsString('Total tokens: 42', $download->getContent());

        $articleDownload = $this
            ->forTenant($tenant)
            ->get("/api/v1/articles/{$article->id}/download");

        $articleDownload->assertOk();
        $this->assertStringContainsString('GEO Analysis Report', $articleDownload->getContent());
        $this->assertStringNotContainsString('qh_geo_article_secret_123456', $articleDownload->getContent());
        $this->assertStringNotContainsString('geo-model-call-article-secret', $articleDownload->getContent());

        Http::assertSent(function ($request) {
            return $request->url() === 'https://models.example.test/v1/chat/completions'
                && $request->header('Authorization')[0] === 'Bearer sk-test-text'
                && $request['model'] === 'test-text';
        });
    }

    public function test_async_mode_enqueues_job_returns_pending_and_guards_duplicate(): void
    {
        config([
            'geo.analysis_async' => true,
        ]);
        Queue::fake();

        [$tenant, $admin] = $this->createTenantAdmin('tenant-async');
        $project = $this->createProjectFor($tenant, $admin);
        $this->useWorkspaceModels($tenant);

        Sanctum::actingAs($admin, ['*']);

        $this
            ->forTenant($tenant)
            ->postJson("/api/v1/projects/{$project->id}/analyses")
            ->assertStatus(202)
            ->assertJsonPath('analysis.status', 'pending');

        $execution = SkillExecution::query()->firstOrFail();
        $this->assertSame('pending', $execution->status);
        $this->assertSame(Project::GEO_ANALYSIS_SKILL_ID, $execution->skill_id);

        Queue::assertPushed(RunGeoAnalysisJob::class, function ($job) use ($execution, $project, $admin, $tenant) {
            return $job->executionId === $execution->id
                && $job->projectId === $project->id
                && $job->userId === $admin->id
                && $job->tenantId === $tenant->id
                && ($job->input['project_id'] ?? null) === $project->id;
        });

        // 未终态期间重复提交应被并发闸拒绝，且不重复入队。
        $this
            ->forTenant($tenant)
            ->postJson("/api/v1/projects/{$project->id}/analyses")
            ->assertStatus(409)
            ->assertJsonPath('action', 'analysis_already_running');

        Queue::assertPushed(RunGeoAnalysisJob::class, 1);
        $this->assertSame(1, SkillExecution::query()->count());
    }

    public function test_async_analysis_job_persists_completed_analysis(): void
    {


        [$tenant, $admin] = $this->createTenantAdmin('tenant-async-job');
        $project = $this->createProjectFor($tenant, $admin, [
            'brand_name' => 'Acme',
            'brand_url' => 'https://acme.example',
            'settings' => [
                'product_description' => 'AI search optimization platform',
                'target_keywords' => ['GEO platform'],
                'competitors' => ['Competitor One'],
                'goals' => 'Increase AI citations.',
            ],
        ]);
        $this->useWorkspaceModels($tenant);

        Http::fake([
            'https://models.example.test/v1/chat/completions' => \Illuminate\Support\Facades\Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'summary' => 'Acme has a clear GEO opportunity.',
                                'key_findings' => ['Brand pages lack AI-ready answers.'],
                                'recommendations' => ['Publish FAQ-led comparison content.'],
                                'next_actions' => ['Run a content gap sprint.'],
                                'overall_score' => 72,
                            ]),
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 22, 'total_tokens' => 42],
            ]),
        ]);

        // 如 store() 一样先落 pending 行，再交给 Job（模拟队列 Worker 独立进程）。
        TenantContext::set($tenant);
        $input = ['project_id' => $project->id, 'brand_name' => 'Acme', 'notes' => 'async'];
        $execution = SkillExecution::query()->create([
            'project_id' => $project->id,
            'user_id' => $admin->id,
            'skill_id' => Project::GEO_ANALYSIS_SKILL_ID,
            'skill_name' => 'GEO MVP Analysis',
            'category' => 'geo-strategy',
            'stage_index' => 1,
            'status' => 'pending',
            'input_data' => $input,
        ]);
        $executionId = $execution->id;
        TenantContext::forget();

        (new RunGeoAnalysisJob($executionId, $project->id, $admin->id, $input, $tenant->id))
            ->handle(app(GeoAnalysisController::class));

        // Job 结束会 forget 租户上下文，故用 withoutGlobalScopes 校验落库结果。
        $fresh = SkillExecution::withoutGlobalScopes()->findOrFail($executionId);
        $this->assertSame('completed', $fresh->status);
        $this->assertNotNull($fresh->article_id);
        $this->assertSame('Acme has a clear GEO opportunity.', $fresh->output_data['analysis']['summary']);
        $this->assertSame(42, $fresh->token_usage);
        $this->assertSame(1, Article::withoutGlobalScopes()->where('skill_execution_id', $executionId)->count());
    }

    public function test_analysis_full_pipeline_uses_runner_execution_id_and_normalizes_formats(): void
    {
        config(['geo.analysis_pipeline_mode' => 'full']);
        Storage::fake('public');

        $runner = new class implements SkillAgentRunner
        {
            public ?SkillBrief $brief = null;

            public function execute(SkillBrief $brief): SkillExecutionResult
            {
                $this->brief = $brief;
                $executionId = $brief->executionId;

                Storage::disk('public')->put(
                    "executions/{$executionId}/panorama.md",
                    "# GEO Analysis Report\n\n## 综合结论\n完整管线报告。",
                );
                Storage::disk('public')->put(
                    "executions/{$executionId}/report.html",
                    '<html><body>完整管线 HTML</body></html>',
                );

                return new SkillExecutionResult(
                    executionId: (int) $executionId,
                    skillId: $brief->skillId,
                    success: true,
                    outputPaths: [
                        'md' => "executions/{$executionId}/panorama.md",
                        'html' => "executions/{$executionId}/report.html",
                    ],
                    intermediateData: [
                        'pipeline_mode' => 'full',
                        'rendered_formats' => ['md', 'html'],
                        'structured' => [
                            'brand_name' => 'Acme',
                            'summary' => [['结论', '完整管线诊断已生成']],
                            'question_coverage' => [
                                ['AI 搜索', '用户会如何提问', '缺口', '补齐 FAQ'],
                            ],
                            'opportunities' => [
                                ['内容', '发布 FAQ 中心', '提升 AI 引用', '高'],
                            ],
                            'scores' => [
                                ['语义密度', '4/5', '内容语义明确', '继续增强'],
                                ['结构规范性', '4/5', '结构清晰', '补 Schema'],
                                ['可引用性', '3/5', '引用内容不足', '补来源'],
                                ['权威信号', '3/5', '外部信号不足', '补背书'],
                                ['可读性', '5/5', '表达清楚', '保持'],
                                ['鲁棒性', '3/5', '多平台验证不足', '补测试'],
                                ['新颖性', '4/5', '主题有差异', '扩展案例'],
                                ['跨域贡献', '3/5', '行业语料不足', '补充'],
                            ],
                        ],
                    ],
                    qualityReport: ['passed' => true, 'score' => 88, 'checks' => []],
                    durationSeconds: 1.2,
                    llmCallCount: 1,
                    tokenUsage: 30,
                    lastLlmResult: new AiProviderCallResult(
                        success: true,
                        provider: 'gateway',
                        model: 'text',
                        content: '{"ok":true}',
                        promptTokens: 10,
                        completionTokens: 20,
                        totalTokens: 30,
                        durationSeconds: 1.2,
                    ),
                );
            }

            public function executeAsync(SkillBrief $brief): int
            {
                return 0;
            }

            public function getExecutionStatus(int $executionId): SkillExecutionResult
            {
                return SkillExecutionResult::failure('', 'not implemented');
            }

            public function cancel(int $executionId): bool
            {
                return false;
            }
        };
        $this->app->instance(SkillAgentRunner::class, $runner);

        [$tenant, $admin] = $this->createTenantAdmin('tenant-full-pipeline');
        $project = $this->createProjectFor($tenant, $admin, [
            'brand_name' => 'Acme',
            'brand_url' => null,
            'settings' => [
                'product_description' => 'AI search optimization platform',
                'target_keywords' => ['GEO platform'],
                'competitors' => ['Competitor One'],
                'goals' => 'Increase AI citations.',
            ],
        ]);
        $this->useWorkspaceModels($tenant);

        Sanctum::actingAs($admin, ['*']);

        $response = $this
            ->forTenant($tenant)
            ->postJson("/api/v1/projects/{$project->id}/analyses")
            ->assertCreated()
            ->assertJsonPath('analysis.pipeline_mode', 'full')
            ->assertJsonPath('analysis.available_formats', ['markdown', 'html'])
            ->assertJsonPath('analysis.rendered_formats', ['markdown', 'html'])
            ->assertJsonPath('analysis.model_usage.total_tokens', 30)
            ->assertJsonPath('analysis.report.overall_score', 73);

        $executionId = $response->json('analysis.id');
        $this->assertSame($executionId, $runner->brief?->executionId);
        $this->assertSame('(未提供)', $runner->brief?->fields['brand_url']);
        $this->assertSame("executions/{$executionId}/panorama.md", $response->json('analysis.output_data.markdown'));
        $this->assertNull($response->json('analysis.output_data.md'));
        $this->assertStringContainsString("executions/{$executionId}/", $response->json('analysis.output_data.html'));

        $this->assertDatabaseHas('model_call_logs', [
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'user_id' => $admin->id,
            'skill_execution_id' => $executionId,
            'provider' => 'gateway',
            'model' => 'text',
            'total_tokens' => 30,
        ]);

        $markdownDownload = $this
            ->forTenant($tenant)
            ->get("/api/v1/projects/{$project->id}/analyses/{$executionId}/download/markdown");
        $markdownDownload->assertOk();
        $this->assertStringContainsString('完整管线报告', $markdownDownload->streamedContent());

        $htmlDownload = $this
            ->forTenant($tenant)
            ->get("/api/v1/projects/{$project->id}/analyses/{$executionId}/download/html");
        $htmlDownload->assertOk();
        $this->assertStringContainsString('完整管线 HTML', $htmlDownload->streamedContent());

        $this
            ->forTenant($tenant)
            ->get("/api/v1/projects/{$project->id}/analyses/{$executionId}/download/md")
            ->assertStatus(400);
    }

    /**
     * 分析失败时把模型的原始回复和分段成败明细留在执行记录里（排查用）。
     * 同时守住两条边界：客户拿不到半截报告的下载入口，报告正文里也不能冒出这段原始输出。
     */
    public function test_failed_analysis_keeps_raw_output_for_diagnosis_without_exposing_a_report(): void
    {
        config(['geo.analysis_pipeline_mode' => 'full']);

        $longRawOutput = '这是第一段的原始输出。'.str_repeat('模型写了很多内容。', 3000);

        $runner = new class($longRawOutput) implements SkillAgentRunner
        {
            public function __construct(private readonly string $rawOutput) {}

            public function execute(SkillBrief $brief): SkillExecutionResult
            {
                return new SkillExecutionResult(
                    executionId: (int) $brief->executionId,
                    skillId: $brief->skillId,
                    success: false,
                    outputPaths: [],
                    intermediateData: [
                        'raw_output' => $this->rawOutput,
                        'pipeline_mode' => 'full',
                        'chunked' => true,
                        'chunk_count' => 4,
                        'chunks_parsed' => 3,
                        'chunk_meta' => [
                            'foundation' => ['ok' => true, 'parsed' => true, 'fields' => 8, 'tokens' => 100],
                            'evidence' => ['ok' => false, 'parsed' => false, 'fields' => 0, 'tokens' => 0],
                        ],
                        'failed_chunks' => ['事实基线与实体画像'],
                    ],
                    durationSeconds: 12.0,
                    llmCallCount: 4,
                    tokenUsage: 350,
                    errorMessage: '报告分 4 段生成，其中 1 段没有成功：事实基线与实体画像。为避免给出缺内容的报告，本次判为失败，请重试。',
                );
            }

            public function executeAsync(SkillBrief $brief): int
            {
                return 0;
            }

            public function getExecutionStatus(int $executionId): SkillExecutionResult
            {
                return SkillExecutionResult::failure('', 'not implemented');
            }

            public function cancel(int $executionId): bool
            {
                return false;
            }
        };
        $this->app->instance(SkillAgentRunner::class, $runner);

        [$tenant, $admin] = $this->createTenantAdmin('tenant-failed-diagnostics');
        $project = $this->createProjectFor($tenant, $admin, [
            'brand_name' => 'Acme',
            'brand_url' => 'https://acme.example',
        ]);
        $this->useWorkspaceModels($tenant);

        Sanctum::actingAs($admin, ['*']);

        $response = $this
            ->forTenant($tenant)
            ->postJson("/api/v1/projects/{$project->id}/analyses")
            ->assertStatus(502)
            ->assertJsonPath('action', 'skill_agent_pipeline_failed');

        $executionId = $response->json('execution.id');

        // 给客户的错误响应里不带排查线索（几万字原始输出没必要发出去）
        $this->assertNull($response->json('execution.output_data.failure_diagnostics'));

        // 列表同理：一页 20 条各带一份原始输出会把这个每次切项目都要调的接口撑爆
        $this
            ->forTenant($tenant)
            ->getJson("/api/v1/projects/{$project->id}/analyses")
            ->assertOk()
            ->assertJsonPath('data.0.id', $executionId)
            ->assertJsonPath('data.0.status', 'failed')
            ->assertJsonPath('data.0.output_data.failure_diagnostics', null);

        $execution = SkillExecution::findOrFail($executionId);
        $diagnostics = $execution->output_data['failure_diagnostics'] ?? null;

        // 线索留下了
        $this->assertIsArray($diagnostics, '失败也要留下排查线索');
        $this->assertSame(['事实基线与实体画像'], $diagnostics['failed_chunks']);
        $this->assertSame(3, $diagnostics['chunks_parsed']);
        $this->assertSame(0, $diagnostics['chunk_meta']['evidence']['fields']);
        $this->assertStringStartsWith('这是第一段的原始输出。', $diagnostics['raw_output']);

        // 原始输出按上限截断，并写明真实长度
        $this->assertLessThan(mb_strlen($longRawOutput), mb_strlen($diagnostics['raw_output']));
        $this->assertStringContainsString('此处只留开头', $diagnostics['raw_output']);
        $this->assertSame(mb_strlen($longRawOutput), $diagnostics['raw_output_chars']);

        // 边界：不能因为存了这份东西就让失败的分析看起来有报告可下载。
        // 前端轮询的是 show 端点，按它的返回判断有没有产物。
        $show = $this
            ->forTenant($tenant)
            ->getJson("/api/v1/projects/{$project->id}/analyses/{$executionId}")
            ->assertOk()
            ->assertJsonPath('analysis.status', 'failed');

        $this->assertSame([], $show->json('analysis.available_formats'));
        $this->assertSame([], $show->json('analysis.rendered_formats'));
        $this->assertStringNotContainsString(
            '模型写了很多内容。',
            (string) $show->json('analysis.report_markdown'),
            '报告正文不得混入失败时留存的原始输出',
        );

        // 失败原因照常回给用户
        $this->assertStringContainsString('事实基线与实体画像', (string) $show->json('analysis.error_message'));
    }

    public function test_analysis_creation_returns_conflict_when_project_lock_is_held(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('tenant-analysis-lock');
        $project = $this->createProjectFor($tenant, $admin, [
            'brand_name' => 'LockTest',
            'brand_url' => 'https://lock.example',
        ]);
        $this->useWorkspaceModels($tenant);

        Sanctum::actingAs($admin, ['*']);

        $lock = Cache::lock("geo_analysis:project:{$project->id}", 600);
        $this->assertTrue($lock->get());

        try {
            $this
                ->forTenant($tenant)
                ->postJson("/api/v1/projects/{$project->id}/analyses")
                ->assertStatus(409)
                ->assertJsonPath('action', 'analysis_already_running');
        } finally {
            $lock->release();
        }
    }

    public function test_analysis_report_normalizes_structured_list_items_for_display(): void
    {


        [$tenant, $admin] = $this->createTenantAdmin('tenant-readable-report');
        $project = $this->createProjectFor($tenant, $admin, [
            'brand_name' => 'Acme',
            'brand_url' => 'https://acme.example',
            'settings' => [
                'product_description' => 'AI search optimization platform',
                'target_keywords' => ['GEO platform'],
                'competitors' => ['Competitor One'],
                'goals' => 'Increase AI citations.',
            ],
        ]);
        $this->useWorkspaceModels($tenant);

        Sanctum::actingAs($admin, ['*']);

        Http::fake([
            'https://models.example.test/v1/chat/completions' => \Illuminate\Support\Facades\Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'summary' => '当前品牌有明确的 GEO 优化空间。',
                                'key_findings' => [
                                    [
                                        'finding' => 'AI 搜索可见度较低',
                                        'detail' => '核心页面缺少可被引用的问答内容',
                                    ],
                                    json_encode([
                                        'finding' => '竞品内容覆盖更完整',
                                        'detail' => '对比页和场景页缺失',
                                    ]),
                                ],
                                'recommendations' => [
                                    [
                                        'priority' => '高',
                                        'action' => '补齐 FAQ 问答页',
                                        'details' => '先覆盖 10 个高频问题',
                                        'expected_impact' => '提升 AI 引用概率',
                                    ],
                                ],
                                'next_actions' => [
                                    [
                                        'phase' => '第 1 周',
                                        'tasks' => ['整理客户问题', '发布 FAQ 初稿'],
                                    ],
                                ],
                                'overall_score' => 64,
                            ]),
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 12,
                    'completion_tokens' => 18,
                    'total_tokens' => 30,
                ],
            ]),
        ]);

        $response = $this
            ->forTenant($tenant)
            ->postJson("/api/v1/projects/{$project->id}/analyses")
            ->assertStatus(201)
            ->assertJsonPath('analysis.report.summary', '当前品牌有明确的 GEO 优化空间。')
            ->assertJsonPath('analysis.report.key_findings.0', 'AI 搜索可见度较低；说明：核心页面缺少可被引用的问答内容')
            ->assertJsonPath('analysis.report.key_findings.1', '竞品内容覆盖更完整；说明：对比页和场景页缺失')
            ->assertJsonPath('analysis.report.recommendations.0', '补齐 FAQ 问答页；优先级：高；说明：先覆盖 10 个高频问题；预期影响：提升 AI 引用概率')
            ->assertJsonPath('analysis.report.next_actions.0', '第 1 周；任务：整理客户问题；发布 FAQ 初稿');

        $this->assertStringNotContainsString('{"finding"', $response->json('analysis.report_markdown'));
    }

    public function test_analysis_ignores_retired_monthly_tenant_limit_before_model_call(): void
    {
        config([
            'geo.monthly_analysis_limit' => 1,
        ]);

        [$tenant, $admin] = $this->createTenantAdmin('tenant-a');
        $project = $this->createProjectFor($tenant, $admin);
        $this->useWorkspaceModels($tenant);

        TenantContext::set($tenant);
        try {
            SkillExecution::query()->create([
                'project_id' => $project->id,
                'user_id' => $admin->id,
                'skill_id' => Project::GEO_ANALYSIS_SKILL_ID,
                'skill_name' => 'GEO MVP Analysis',
                'category' => 'geo-strategy',
                'stage_index' => 1,
                'status' => 'completed',
                'input_data' => [],
                'output_data' => [],
            ]);
        } finally {
            TenantContext::forget();
        }

        Sanctum::actingAs($admin, ['*']);
        Http::fake([
            'https://models.example.test/v1/chat/completions' => \Illuminate\Support\Facades\Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'summary' => '月度次数限制已下线，分析可以继续执行。',
                                'key_findings' => ['旧次数计数不再阻断 GEO 分析。'],
                                'recommendations' => ['后续按清禾土豆条积分扣点。'],
                                'next_actions' => ['继续验证扣点链路。'],
                                'overall_score' => 61,
                            ]),
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 10,
                    'completion_tokens' => 12,
                    'total_tokens' => 22,
                ],
            ]),
        ]);

        $this
            ->forTenant($tenant)
            ->postJson("/api/v1/projects/{$project->id}/analyses")
            ->assertStatus(201)
            ->assertJsonPath('analysis.status', 'completed');

        $this->assertSame(2, SkillExecution::query()->count());
        $this->assertSame(1, ModelCallLog::query()->count());
    }

    public function test_analysis_gateway_error_holds_original_attempt_without_usage_log_or_report_asset(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('tenant-a');
        $project = $this->createProjectFor($tenant, $admin);
        $this->useWorkspaceModels($tenant);

        Sanctum::actingAs($admin, ['*']);

        Http::fake([
            'https://models.example.test/v1/chat/completions' => \Illuminate\Support\Facades\Http::response([
                'error' => ['message' => 'api_key=sk-test-41c78ed8722bc9f420a4f failed for fixture-user-7@example.com phone 13800000027'],
            ], 500),
        ]);

        $response = $this
            ->forTenant($tenant)
            ->postJson("/api/v1/projects/{$project->id}/analyses")
            ->assertStatus(202)
            ->assertJsonPath('analysis.status', 'billing_pending');

        $execution = SkillExecution::query()->firstOrFail();
        $this->assertSame($tenant->id, $execution->tenant_id);
        $this->assertSame($project->id, $execution->project_id);
        $this->assertSame('billing_pending', $execution->status);
        $this->assertSame('model_attempt_unknown', data_get($execution->billing_pending_result, 'kind'));
        $this->assertNotEmpty(data_get($execution->billing_pending_result, 'gateway.attribution.client_request_id'));
        $this->assertStringNotContainsString('sk-test-41c78ed8722bc9f420a4f', $response->getContent());
        $this->assertStringNotContainsString('fixture-user-7@example.com', $response->getContent());
        $this->assertStringNotContainsString('13800000027', $response->getContent());
        $this->assertStringNotContainsString('sk-test-41c78ed8722bc9f420a4f', (string) $execution->error_message);
        $this->assertSame(1, ModelCallLog::query()->count());
        $this->assertSame('unknown', ModelCallLog::query()->first()->status);
        $this->assertNull(ModelCallLog::query()->first()->total_tokens);
        $this->assertSame(0, Article::query()->count());
        Http::assertSent(fn ($request) => $request->url() === 'https://models.example.test/v1/chat/completions');
        Http::assertSentCount(1);
    }

    public function test_analysis_cannot_run_against_another_tenants_project(): void
    {
        [$tenantA, $adminA] = $this->createTenantAdmin('tenant-a');
        [$tenantB, $adminB] = $this->createTenantAdmin('tenant-b');
        $projectB = $this->createProjectFor($tenantB, $adminB);

        $this->useWorkspaceModels($tenantA);

        Sanctum::actingAs($adminA, ['*']);

        $this
            ->forTenant($tenantA)
            ->postJson("/api/v1/projects/{$projectB->id}/analyses")
            ->assertNotFound();

        $this->assertSame(0, SkillExecution::query()->count());
    }

    /**
     * 阶段推进条件不满足时，工作流服务抛 \DomainException。此前没有映射 →
     * 渲染成 500「服务器错误」，而「当前阶段未满足完成条件: …」本来就是给用户
     * 看的、可操作的话。现在必须回 422 并把原话带给前端。
     */
    public function test_unmet_stage_requirements_return_422_with_actionable_message(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('tenant-stage-gate');
        Sanctum::actingAs($admin, ['*']);

        // 经 API 建项目，六个阶段随之初始化
        $projectId = $this
            ->forTenant($tenant)
            ->postJson('/api/v1/projects', [
                'name' => 'Stage Gate',
                'brand_name' => 'Acme',
                'brand_url' => 'https://acme.example',
            ])
            ->assertStatus(201)
            ->json('id');

        TenantContext::set($tenant);
        try {
            $stage = \App\Models\ProjectStage::query()
                ->where('project_id', $projectId)
                ->where('stage_index', 0)
                ->firstOrFail();
        } finally {
            TenantContext::forget();
        }

        // 研究阶段一个 skill 都没跑过 → 完成条件不满足
        $response = $this
            ->forTenant($tenant)
            ->postJson("/api/v1/projects/{$projectId}/stages/{$stage->id}/advance");

        $response->assertStatus(422);
        $this->assertStringContainsString('未满足完成条件', (string) $response->json('message'));
    }

    public function test_final_stage_requires_evidence_and_completes_stage_with_project(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('tenant-final-stage');
        Sanctum::actingAs($admin, ['*']);

        $projectId = $this->forTenant($tenant)->postJson('/api/v1/projects', [
            'name' => 'Final stage',
            'brand_name' => 'Acme',
            'brand_url' => 'https://acme.example',
        ])->assertCreated()->json('id');

        TenantContext::set($tenant);
        $project = Project::query()->findOrFail($projectId);
        $project->stages()->where('stage_index', '<', 5)->update(['status' => 'completed', 'progress' => 100]);
        $project->stages()->where('stage_index', 5)->update(['status' => 'active', 'started_at' => now()]);
        $project->update(['current_stage' => 5, 'progress' => 83]);
        $finalStage = $project->stages()->where('stage_index', 5)->firstOrFail();
        $researchStage = $project->stages()->where('stage_index', 0)->firstOrFail();

        $this->assertFalse(app(\App\Services\Contracts\ProjectWorkflow::class)->canAdvance($project));
        $this->forTenant($tenant)->postJson("/api/v1/projects/{$projectId}/stages/{$researchStage->id}/advance")
            ->assertStatus(422)->assertJsonPath('message', '只能推进当前阶段');
        $this->forTenant($tenant)->postJson("/api/v1/projects/{$projectId}/stages/{$finalStage->id}/advance")
            ->assertStatus(422);

        TenantContext::set($tenant);
        SkillExecution::query()->create([
            'project_id' => $projectId,
            'user_id' => $admin->id,
            'skill_id' => 'yao-geo-content-refiner',
            'skill_name' => '内容精修',
            'category' => 'geo-content',
            'status' => 'completed',
        ]);
        $this->assertFalse(app(\App\Services\Contracts\ProjectWorkflow::class)->canAdvance($project));
        $article = Article::query()->create([
            'project_id' => $projectId,
            'author_id' => $admin->id,
            'title' => 'Published project content',
            'body_markdown' => 'Public research content.',
            'status' => Article::STATUS_APPROVED,
        ]);
        $channel = PublishingChannel::query()->create([
            'name' => 'External channel',
            'type' => PublishingChannel::TYPE_HTTP_WEBHOOK,
            'endpoint_url' => 'https://publisher.example.test/api',
        ]);
        ArticlePublication::query()->create([
            'article_id' => $article->id,
            'publishing_channel_id' => $channel->id,
            'status' => ArticlePublication::STATUS_PUBLISHED,
            'remote_url' => 'https://publisher.example.test/article',
            'published_at' => now()->subHour(),
        ]);
        AiVisibilityRun::query()->create([
            'project_id' => $projectId,
            'user_id' => $admin->id,
            'batch_id' => (string) \Illuminate\Support\Str::uuid(),
            'question' => '品牌是否可见？',
            'status' => AiVisibilityRun::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
        $this->assertTrue(app(\App\Services\Contracts\ProjectWorkflow::class)->canAdvance($project));

        $this->forTenant($tenant)->postJson("/api/v1/projects/{$projectId}/stages/{$finalStage->id}/advance")
            ->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('progress', 100)
            ->assertJsonPath('stages.5.status', 'completed')->assertJsonPath('stages.5.progress', 100);
        $this->assertNotNull($finalStage->fresh()->completed_at);
        $this->assertFalse(app(\App\Services\Contracts\ProjectWorkflow::class)->canAdvance($project->fresh()));
        $this->forTenant($tenant)->getJson('/api/v1/projects')
            ->assertOk()->assertJsonPath('data.0.id', $projectId)
            ->assertJsonPath('data.0.status', 'completed');
        $this->forTenant($tenant)->postJson("/api/v1/projects/{$projectId}/stages/{$finalStage->id}/advance")
            ->assertStatus(422)->assertJsonPath('message', '项目已完成');
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

    private function createProjectFor(Tenant $tenant, User $owner, array $overrides = []): Project
    {
        TenantContext::set($tenant);

        try {
            return Project::query()->create(array_merge([
                'owner_id' => $owner->id,
                'name' => $tenant->name.' Project',
                'brand_name' => $tenant->name,
                'brand_url' => 'https://'.$tenant->subdomain.'.example.test',
                'settings' => [],
            ], $overrides));
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
