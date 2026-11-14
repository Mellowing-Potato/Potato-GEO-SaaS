<?php

namespace Tests\Feature;

use App\Jobs\ExecuteSkillJob;
use App\Models\Article;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Content\MaterialLibraryService;
use App\Services\Contracts\SkillAgentRunner;
use App\Services\Contracts\SkillRegistry;
use App\Services\DTOs\SkillBrief;
use App\Services\DTOs\SkillExecutionResult;
use App\Services\Reports\ReportAssetService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GeoMvpManagementControlsTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_current_tenant_can_edit_article_without_mutating_source_fields(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('tenant-a');
        $project = $this->createProjectFor($tenant, $admin);
        $otherProject = $this->createProjectFor($tenant, $admin, 'Other Project');
        $execution = $this->createExecutionFor($tenant, $project, $admin);
        $article = $this->createArticleFor($tenant, $project, $admin, [
            'source_type' => 'skill_execution',
            'source_skill' => 'yao-geo-explainer-builder',
            'skill_execution_id' => $execution->id,
            'version' => 1,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $newBody = "# Updated report\n\nFresh body for editing.";
        $response = $this
            ->forTenant($tenant)
            ->patchJson("/api/v1/articles/{$article->id}", [
                'title' => 'Updated report',
                'summary' => 'A tighter summary',
                'body_markdown' => $newBody,
                'status' => 'draft',
                'project_id' => $otherProject->id,
                'tenant_id' => 999,
                'source_type' => 'manual',
                'source_skill' => 'tampered',
                'skill_execution_id' => null,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('title', 'Updated report')
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('version', 2);

        $article->refresh();
        $this->assertSame($project->id, $article->project_id);
        $this->assertSame($tenant->id, $article->tenant_id);
        $this->assertSame('skill_execution', $article->source_type);
        $this->assertSame('yao-geo-explainer-builder', $article->source_skill);
        $this->assertSame($execution->id, $article->skill_execution_id);
        $this->assertSame(2, $article->version);
        $this->assertSame(mb_strlen(strip_tags($newBody)), $article->word_count);
    }

    public function test_current_tenant_cannot_edit_another_tenants_article(): void
    {
        [$tenantA, $adminA] = $this->createTenantAdmin('tenant-a');
        [$tenantB, $adminB] = $this->createTenantAdmin('tenant-b');
        $projectB = $this->createProjectFor($tenantB, $adminB);
        $articleB = $this->createArticleFor($tenantB, $projectB, $adminB);

        Sanctum::actingAs($adminA, ['*']);

        $this
            ->forTenant($tenantA)
            ->patchJson("/api/v1/articles/{$articleB->id}", [
                'title' => 'Cross tenant edit',
            ])
            ->assertNotFound();

        $this->assertSame('Tenant B report', $articleB->fresh()->title);
    }

    public function test_project_assets_filter_and_search_stay_in_current_tenant(): void
    {
        [$tenantA, $adminA] = $this->createTenantAdmin('tenant-a');
        [$tenantB, $adminB] = $this->createTenantAdmin('tenant-b');
        $projectA = $this->createProjectFor($tenantA, $adminA);
        $projectB = $this->createProjectFor($tenantB, $adminB);
        $skillArticle = $this->createArticleFor($tenantA, $projectA, $adminA, [
            'title' => 'Skill report',
            'body_markdown' => 'Contains private-search-needle for tenant A.',
            'source_type' => 'skill_execution',
        ]);
        $this->createArticleFor($tenantA, $projectA, $adminA, [
            'title' => 'Analysis report',
            'body_markdown' => 'A GEO analysis result.',
            'source_type' => 'geo_analysis',
        ]);
        $this->createArticleFor($tenantB, $projectB, $adminB, [
            'title' => 'Tenant B private result',
            'body_markdown' => 'Contains private-search-needle for tenant B.',
            'source_type' => 'skill_execution',
        ]);

        Sanctum::actingAs($adminA, ['*']);

        $this
            ->forTenant($tenantA)
            ->getJson("/api/v1/projects/{$projectA->id}/articles?source_type=skill_execution")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $skillArticle->id)
            ->assertJsonPath('data.0.source_type', 'skill_execution');

        $this
            ->forTenant($tenantA)
            ->getJson("/api/v1/projects/{$projectA->id}/articles?search=private-search-needle")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $skillArticle->id);
    }

    public function test_async_skill_execution_endpoint_returns_normalized_payload_and_status(): void
    {
        $this->useRegistryFixture();
        $this->fakeAsyncRunner();
        [$tenant, $admin] = $this->createTenantAdmin('tenant-a');
        $project = $this->createProjectFor($tenant, $admin);
        Sanctum::actingAs($admin, ['*']);

        $response = $this
            ->forTenant($tenant)
            ->postJson('/api/v1/skills/execute-async', [
                'skill_id' => 'yao-geo-explainer-builder',
                'project_id' => $project->id,
                'fields' => ['topic' => 'Acme GEO'],
            ]);

        $response
            ->assertAccepted()
            ->assertJsonPath('message', 'Skill execution has been queued.')
            ->assertJsonPath('execution.status', 'pending')
            ->assertJsonStructure(['execution' => ['id', 'project_id', 'skill_id', 'status', 'created_at']]);

        $executionId = $response->json('execution.id');

        $this
            ->forTenant($tenant)
            ->getJson("/api/v1/executions/{$executionId}/status")
            ->assertOk()
            ->assertJsonPath('execution.id', $executionId)
            ->assertJsonPath('execution.status', 'pending')
            ->assertJsonPath('is_terminal', false);
    }

    public function test_sync_skill_execution_merges_project_profile_and_latest_panorama_context(): void
    {
        $this->useRegistryFixture();
        $runner = new class implements SkillAgentRunner {
            public ?SkillBrief $brief = null;

            public function execute(SkillBrief $brief): SkillExecutionResult
            {
                $this->brief = $brief;

                return new SkillExecutionResult(
                    executionId: 0,
                    skillId: $brief->skillId,
                    success: true,
                    outputPaths: [],
                    intermediateData: [
                        'raw_output' => "# {$brief->fields['brand_name']} 技能结果\n\n围绕项目资料生成。",
                        'structured' => ['summary' => 'Project-aware skill result.'],
                    ],
                    qualityReport: ['passed' => true, 'score' => 80],
                    durationSeconds: 1.0,
                    llmCallCount: 1,
                    tokenUsage: 18,
                );
            }

            public function executeAsync(SkillBrief $brief): int
            {
                return 0;
            }

            public function getExecutionStatus(int $executionId): SkillExecutionResult
            {
                return SkillExecutionResult::failure('', 'Not used.');
            }

            public function cancel(int $executionId): bool
            {
                return false;
            }
        };
        $this->app->instance(SkillAgentRunner::class, $runner);

        [$tenant, $admin] = $this->createTenantAdmin('tenant-context');
        $project = $this->createProjectFor($tenant, $admin, '孟德智达 GEO 测试');
        TenantContext::set($tenant);
        try {
            $project->update([
                'brand_name' => '孟德智达',
                'brand_url' => 'https://example.test/',
                'settings' => [
                    'product_description' => '面向医学科研和少儿编程团队的 AI 工具平台',
                    'target_market' => '医学科研、少儿编程、AI 工具使用者',
                    'target_keywords' => ['医学科研 AI', '少儿编程 AI'],
                    'competitors' => ['Dify', '扣子'],
                    'goals' => '提升 AI 搜索中的品牌可见性。',
                ],
            ]);
        } finally {
            TenantContext::forget();
        }

        $panorama = $this->createExecutionFor($tenant, $project, $admin, [
            'skill_id' => Project::GEO_ANALYSIS_SKILL_ID,
            'skill_name' => 'GEO Analysis',
            'category' => 'geo-strategy',
            'stage_index' => 1,
            'output_data' => [
                'result_text' => '全景诊断结论：先补品牌定义、FAQ 和案例证据。',
                'structured' => [
                    'brand_name' => '孟德智达',
                    'conclusion' => '先补可被 AI 直接引用的事实证据。',
                    'opportunities' => [['内容', 'FAQ']],
                ],
            ],
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this
            ->forTenant($tenant)
            ->postJson('/api/v1/skills/execute', [
                'skill_id' => 'yao-geo-explainer-builder',
                'project_id' => $project->id,
                'fields' => ['topic' => 'AI 搜索可见性'],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Skill execution completed.');

        $this->assertSame('孟德智达 GEO 测试', $runner->brief?->fields['project_name']);
        $this->assertSame('孟德智达', $runner->brief?->fields['brand_name']);
        $this->assertSame('https://example.test/', $runner->brief?->fields['brand_url']);
        $this->assertSame('医学科研、少儿编程、AI 工具使用者', $runner->brief?->fields['target_market']);
        $this->assertSame(['医学科研 AI', '少儿编程 AI'], $runner->brief?->fields['target_keywords']);
        $this->assertSame($panorama->id, $runner->brief?->contextFromPreviousStage['source_execution_id']);
        $this->assertStringContainsString('品牌定义、FAQ 和案例证据', $runner->brief?->contextFromPreviousStage['diagnosis_text']);

        $execution = SkillExecution::query()
            ->where('skill_id', 'yao-geo-explainer-builder')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('孟德智达', $execution->input_data['brand_name']);
        $this->assertSame(['Dify', '扣子'], $execution->input_data['competitors']);
        $this->assertSame(80, $execution->article()->value('quality_score'));
    }

    public function test_execution_roadmap_allows_missing_website_and_normalizes_negative_timeline(): void
    {
        $this->useRegistryFixture();
        $runner = new class implements SkillAgentRunner {
            public ?SkillBrief $brief = null;

            public function execute(SkillBrief $brief): SkillExecutionResult
            {
                $this->brief = $brief;

                return new SkillExecutionResult(
                    executionId: 0,
                    skillId: $brief->skillId,
                    success: true,
                    outputPaths: [],
                    intermediateData: ['raw_output' => '# 执行路线图'],
                    qualityReport: ['passed' => true, 'score' => 80],
                    durationSeconds: 1.0,
                    llmCallCount: 1,
                    tokenUsage: 12,
                );
            }

            public function executeAsync(SkillBrief $brief): int
            {
                return 0;
            }

            public function getExecutionStatus(int $executionId): SkillExecutionResult
            {
                return SkillExecutionResult::failure('', 'Not used.');
            }

            public function cancel(int $executionId): bool
            {
                return false;
            }
        };
        $this->app->instance(SkillAgentRunner::class, $runner);

        [$tenant, $admin] = $this->createTenantAdmin('tenant-roadmap');
        $project = $this->createProjectFor($tenant, $admin, '米娜咖啡');
        TenantContext::set($tenant);
        try {
            $project->update(['brand_name' => '米娜咖啡', 'brand_url' => null]);
        } finally {
            TenantContext::forget();
        }

        Sanctum::actingAs($admin, ['*']);

        $this
            ->forTenant($tenant)
            ->postJson('/api/v1/skills/execute', [
                'skill_id' => 'yao-geo-execution-roadmap',
                'project_id' => $project->id,
                'fields' => [
                    'brand_name' => '米娜咖啡',
                    'brand_url' => '',
                    'timeline_days' => -6,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Skill execution completed.');

        $this->assertSame('米娜咖啡', $runner->brief?->fields['brand_name']);
        $this->assertSame('(未提供)', $runner->brief?->fields['brand_url']);
        $this->assertSame(90, $runner->brief?->fields['timeline_days']);

        $execution = SkillExecution::query()
            ->where('skill_id', 'yao-geo-execution-roadmap')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('(未提供)', $execution->input_data['brand_url']);
        $this->assertSame(90, $execution->input_data['timeline_days']);
    }

    public function test_tenant_cannot_inspect_or_cancel_other_tenant_skill_execution(): void
    {
        [$tenantA, $adminA] = $this->createTenantAdmin('tenant-a');
        [$tenantB, $adminB] = $this->createTenantAdmin('tenant-b');
        $projectB = $this->createProjectFor($tenantB, $adminB);
        $executionB = $this->createExecutionFor($tenantB, $projectB, $adminB, [
            'output_data' => ['markdown' => 'tenant-b/result.md'],
        ]);

        Sanctum::actingAs($adminA, ['*']);

        $this
            ->forTenant($tenantA)
            ->getJson("/api/v1/executions/{$executionB->id}")
            ->assertNotFound();

        $this
            ->forTenant($tenantA)
            ->getJson("/api/v1/executions/{$executionB->id}/status")
            ->assertNotFound();

        $this
            ->forTenant($tenantA)
            ->getJson("/api/v1/executions/{$executionB->id}/download/markdown")
            ->assertNotFound();

        $this
            ->forTenant($tenantA)
            ->postJson("/api/v1/executions/{$executionB->id}/cancel")
            ->assertNotFound();
    }

    public function test_async_skill_job_success_creates_article_asset(): void
    {
        $this->fakeRunner(success: true);
        [$tenant, $admin] = $this->createTenantAdmin('tenant-a');
        $project = $this->createProjectFor($tenant, $admin);
        $execution = $this->createExecutionFor($tenant, $project, $admin, ['status' => 'pending']);
        $brief = new SkillBrief(
            skillId: $execution->skill_id,
            projectId: $project->id,
            userId: $admin->id,
            fields: ['topic' => 'Acme GEO'],
        );

        (new ExecuteSkillJob($execution->id, $brief->toArray(), $tenant->id))
            ->handle(
                $this->app->make(SkillAgentRunner::class),
                $this->app->make(ReportAssetService::class),
                $this->app->make(MaterialLibraryService::class),
            );

        $execution->refresh();
        $article = Article::query()->firstOrFail();

        $this->assertSame('completed', $execution->status);
        $this->assertSame($article->id, $execution->article_id);
        $this->assertSame($execution->id, $article->skill_execution_id);
        $this->assertSame('skill_execution', $article->source_type);
        $this->assertSame(90, $article->quality_score);
        $this->assertStringContainsString('Async Skill result', $article->body_markdown);
    }

    public function test_async_skill_job_failure_does_not_create_article_and_sanitizes_error(): void
    {
        $this->fakeRunner(success: false);
        [$tenant, $admin] = $this->createTenantAdmin('tenant-a');
        $project = $this->createProjectFor($tenant, $admin);
        $execution = $this->createExecutionFor($tenant, $project, $admin, ['status' => 'pending']);
        $brief = new SkillBrief(
            skillId: $execution->skill_id,
            projectId: $project->id,
            userId: $admin->id,
            fields: ['topic' => 'Acme GEO'],
        );

        (new ExecuteSkillJob($execution->id, $brief->toArray(), $tenant->id))
            ->handle(
                $this->app->make(SkillAgentRunner::class),
                $this->app->make(ReportAssetService::class),
                $this->app->make(MaterialLibraryService::class),
            );

        $execution->refresh();

        $this->assertSame('failed', $execution->status);
        $this->assertNull($execution->article_id);
        $this->assertSame(0, Article::query()->count());
        $this->assertSame('Skill execution failed. Please review the brief and try again.', $execution->error_message);
    }

    public function test_async_skill_job_does_not_run_when_tenant_is_paused(): void
    {
        $runner = $this->fakeCountingRunner();
        [$tenant, $admin] = $this->createTenantAdmin('tenant-a');
        $project = $this->createProjectFor($tenant, $admin);
        $execution = $this->createExecutionFor($tenant, $project, $admin, ['status' => 'pending']);
        $tenant->forceFill(['status' => 'paused'])->save();
        $brief = new SkillBrief(
            skillId: $execution->skill_id,
            projectId: $project->id,
            userId: $admin->id,
            fields: ['topic' => 'Acme GEO'],
        );

        (new ExecuteSkillJob($execution->id, $brief->toArray(), $tenant->id))
            ->handle(
                $this->app->make(SkillAgentRunner::class),
                $this->app->make(ReportAssetService::class),
                $this->app->make(MaterialLibraryService::class),
            );

        $execution->refresh();

        $this->assertSame(0, $runner->syncCalls);
        $this->assertSame(0, $runner->asyncCalls);
        $this->assertSame('failed', $execution->status);
        $this->assertSame('Tenant is paused; queued Skill execution was not started.', $execution->error_message);
        $this->assertSame(0, Article::query()->count());
    }

    public function test_skill_monthly_limit_config_no_longer_blocks_execution(): void
    {
        config(['geo.monthly_skill_execution_limit' => 1]);
        $this->useRegistryFixture();
        $runner = $this->fakeCountingRunner();
        [$tenant, $admin] = $this->createTenantAdmin('tenant-a');
        $project = $this->createProjectFor($tenant, $admin);
        $this->createExecutionFor($tenant, $project, $admin, [
            'skill_id' => 'yao-geo-explainer-builder',
            'status' => 'completed',
        ]);
        Sanctum::actingAs($admin, ['*']);

        $this
            ->forTenant($tenant)
            ->postJson('/api/v1/skills/execute', [
                'skill_id' => 'yao-geo-explainer-builder',
                'project_id' => $project->id,
                'fields' => ['topic' => 'Acme'],
            ])
            ->assertStatus(502)
            ->assertJsonPath('action', 'skill_execution_failed');

        $this
            ->forTenant($tenant)
            ->postJson('/api/v1/skills/execute-async', [
                'skill_id' => 'yao-geo-explainer-builder',
                'project_id' => $project->id,
                'fields' => ['topic' => 'Acme'],
            ])
            ->assertStatus(202)
            ->assertJsonPath('message', 'Skill execution has been queued.');

        $this->assertSame(1, $runner->syncCalls);
        $this->assertSame(1, $runner->asyncCalls);
        $this->assertSame(3, SkillExecution::query()->count());
        $this->assertSame(1, SkillExecution::query()->where('status', 'pending')->count());
    }

    public function test_project_show_includes_registry_backed_workflow_recommendations(): void
    {
        $this->useRegistryFixture();
        [$tenant, $admin] = $this->createTenantAdmin('tenant-a');
        $project = $this->createProjectFor($tenant, $admin);
        Sanctum::actingAs($admin, ['*']);

        $this
            ->forTenant($tenant)
            ->getJson("/api/v1/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('workflow_recommendations.0.stage_key', 'research')
            ->assertJsonPath('workflow_recommendations.0.stage_name', '研究')
            ->assertJsonPath('workflow_recommendations.0.description', '挖掘 AI 搜索意图、种子话题和竞品信号。')
            ->assertJsonPath('workflow_recommendations.0.next_action', '先挖掘客户问题和关键词意图。')
            ->assertJsonPath('workflow_recommendations.0.recommended_skill.id', 'yao-geo-intent-miner')
            ->assertJsonPath('workflow_recommendations.1.stage_name', '策略')
            ->assertJsonPath('workflow_recommendations.2.stage_name', '内容生产')
            ->assertJsonPath('workflow_recommendations.3.stage_name', '渠道发布')
            ->assertJsonPath('workflow_recommendations.4.stage_name', '效果监测')
            ->assertJsonPath('workflow_recommendations.5.stage_name', '迭代优化')
            ->assertJsonPath('workflow_recommendations.1.recommended_skill.id', Project::GEO_ANALYSIS_SKILL_ID);
    }

    private function useRegistryFixture(): void
    {
        $path = storage_path('framework/testing/management-skill-registry.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'skills' => [
                [
                    'id' => 'yao-geo-intent-miner',
                    'family' => 'geo-research',
                    'maturity' => 'beta',
                    'requires_web' => false,
                    'primary_outputs' => ['intent-map'],
                    'status' => 'active',
                    'path' => 'skills/yao-geo-intent-miner',
                    'summary' => 'Mine AI-search intent.',
                ],
                [
                    'id' => Project::GEO_ANALYSIS_SKILL_ID,
                    'family' => 'geo-strategy',
                    'maturity' => 'beta',
                    'requires_web' => true,
                    'primary_outputs' => ['geo-panorama-audit-report'],
                    'status' => 'active',
                    'path' => 'skills/yao-geo-panorama-audit',
                    'summary' => 'Run a GEO panorama audit.',
                ],
                [
                    'id' => 'yao-geo-execution-roadmap',
                    'family' => 'geo-strategy',
                    'maturity' => 'beta',
                    'requires_web' => false,
                    'primary_outputs' => ['execution-roadmap'],
                    'status' => 'active',
                    'path' => 'skills/yao-geo-execution-roadmap',
                    'summary' => 'Build a GEO execution roadmap.',
                ],
                [
                    'id' => 'yao-geo-explainer-builder',
                    'family' => 'geo-content-production',
                    'maturity' => 'beta',
                    'requires_web' => true,
                    'primary_outputs' => ['article'],
                    'status' => 'active',
                    'path' => 'skills/yao-geo-explainer-builder',
                    'summary' => 'Build an explainer article.',
                ],
            ],
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
                    return SkillExecutionResult::failure($brief->skillId, 'C:\\internal\\runner.php failed');
                }

                return new SkillExecutionResult(
                    executionId: 0,
                    skillId: $brief->skillId,
                    success: true,
                    outputPaths: [],
                    intermediateData: [
                        'raw_output' => "# Async Skill result\n\nReady.",
                        'structured' => ['summary' => 'Ready'],
                    ],
                    qualityReport: ['passed' => true, 'score' => 90],
                    durationSeconds: 1.0,
                    llmCallCount: 1,
                    tokenUsage: 21,
                );
            }

            public function executeAsync(SkillBrief $brief): int
            {
                return 0;
            }

            public function getExecutionStatus(int $executionId): SkillExecutionResult
            {
                return SkillExecutionResult::failure('', 'Not used.');
            }

            public function cancel(int $executionId): bool
            {
                return false;
            }
        });
    }

    private function fakeAsyncRunner(): void
    {
        $this->app->instance(SkillAgentRunner::class, new class implements SkillAgentRunner {
            public function execute(SkillBrief $brief): SkillExecutionResult
            {
                return SkillExecutionResult::failure($brief->skillId, 'Not used.');
            }

            public function executeAsync(SkillBrief $brief): int
            {
                return SkillExecution::query()->create([
                    'project_id' => $brief->projectId,
                    'user_id' => $brief->userId,
                    'skill_id' => $brief->skillId,
                    'skill_name' => 'Explainer Builder',
                    'category' => 'geo-content-production',
                    'stage_index' => 2,
                    'status' => 'pending',
                    'input_data' => $brief->fields,
                ])->id;
            }

            public function getExecutionStatus(int $executionId): SkillExecutionResult
            {
                return SkillExecutionResult::failure('', 'Not used.');
            }

            public function cancel(int $executionId): bool
            {
                return false;
            }
        });
    }

    private function fakeCountingRunner(): object
    {
        $runner = new class implements SkillAgentRunner {
            public int $syncCalls = 0;
            public int $asyncCalls = 0;

            public function execute(SkillBrief $brief): SkillExecutionResult
            {
                $this->syncCalls++;

                return SkillExecutionResult::failure($brief->skillId, 'Should not run.');
            }

            public function executeAsync(SkillBrief $brief): int
            {
                $this->asyncCalls++;

                // The endpoint verifies the returned execution belongs to
                // the authenticated project/user before acknowledging queueing.
                return SkillExecution::query()->create([
                    'project_id' => $brief->projectId,
                    'user_id' => $brief->userId,
                    'skill_id' => $brief->skillId,
                    'skill_name' => 'Explainer Builder',
                    'category' => 'geo-content-production',
                    'stage_index' => 2,
                    'status' => 'pending',
                    'input_data' => $brief->fields,
                ])->id;
            }

            public function getExecutionStatus(int $executionId): SkillExecutionResult
            {
                return SkillExecutionResult::failure('', 'Not used.');
            }

            public function cancel(int $executionId): bool
            {
                return false;
            }
        };

        $this->app->instance(SkillAgentRunner::class, $runner);

        return $runner;
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

    private function createProjectFor(Tenant $tenant, User $owner, ?string $name = null): Project
    {
        TenantContext::set($tenant);

        try {
            return Project::query()->create([
                'owner_id' => $owner->id,
                'name' => $name ?? $tenant->name.' Project',
                'brand_name' => $tenant->name,
                'brand_url' => 'https://'.$tenant->subdomain.'.example.test',
                'settings' => [],
            ]);
        } finally {
            TenantContext::forget();
        }
    }

    private function createExecutionFor(Tenant $tenant, Project $project, User $user, array $overrides = []): SkillExecution
    {
        TenantContext::set($tenant);

        try {
            return SkillExecution::query()->create(array_merge([
                'project_id' => $project->id,
                'user_id' => $user->id,
                'skill_id' => 'yao-geo-explainer-builder',
                'skill_name' => 'Explainer Builder',
                'category' => 'geo-content-production',
                'stage_index' => 2,
                'status' => 'completed',
                'input_data' => ['topic' => 'Acme GEO'],
            ], $overrides));
        } finally {
            TenantContext::forget();
        }
    }

    private function createArticleFor(Tenant $tenant, Project $project, User $author, array $overrides = []): Article
    {
        TenantContext::set($tenant);

        try {
            return Article::query()->create(array_merge([
                'project_id' => $project->id,
                'author_id' => $author->id,
                'title' => 'Tenant B report',
                'body_markdown' => '# Tenant B report',
                'summary' => 'Private report',
                'content_type' => 'general',
                'status' => 'approved',
                'source_type' => 'geo_analysis',
                'source_skill' => Project::GEO_ANALYSIS_SKILL_ID,
                'version' => 1,
                'word_count' => 16,
                'metadata' => ['structured' => ['summary' => 'Private report']],
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
