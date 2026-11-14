<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Contracts\SkillAgentRunner;
use App\Services\Contracts\SkillRegistry;
use App\Services\DTOs\SkillBrief;
use App\Services\DTOs\SkillExecutionResult;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportAssetFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.tenant_domain' => 'geo.example.test']);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();

        parent::tearDown();
    }

    public function test_successful_skill_execution_creates_project_report_asset(): void
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
                'fields' => ['brand_name' => 'Acme'],
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('execution.status', 'completed')
            ->assertJsonPath('execution.result_text', "# Skill result\n\nReady to publish.")
            ->assertJsonStructure(['execution' => ['article_id', 'article_url', 'article_download_url']]);

        $execution = SkillExecution::query()->firstOrFail();
        $article = Article::query()->firstOrFail();

        $this->assertSame($article->id, $execution->article_id);
        $this->assertSame($execution->id, $article->skill_execution_id);
        $this->assertSame('skill_execution', $article->source_type);
        $this->assertSame('yao-geo-panorama-audit', $article->source_skill);
        $this->assertSame($project->id, $article->project_id);
        $this->assertSame('Ready', $article->metadata['structured']['summary']);
        $this->assertSame('Skill result', $article->title);

        $this
            ->forTenant($tenant)
            ->getJson("/api/v1/projects/{$project->id}/articles")
            ->assertOk()
            ->assertJsonPath('data.0.id', $article->id)
            ->assertJsonPath('data.0.source_type', 'skill_execution')
            ->assertJsonPath('data.0.source_execution.id', $execution->id);

        $this
            ->forTenant($tenant)
            ->getJson("/api/v1/articles/{$article->id}")
            ->assertOk()
            ->assertJsonPath('id', $article->id)
            ->assertJsonPath('structured.summary', 'Ready')
            ->assertJsonPath('download_url', "/api/v1/articles/{$article->id}/download");

        $download = $this
            ->forTenant($tenant)
            ->get("/api/v1/articles/{$article->id}/download");

        $download->assertOk();
        $this->assertStringContainsString('# Skill result', $download->getContent());
    }

    public function test_tenant_cannot_view_or_download_other_tenant_report_assets(): void
    {
        [$tenantA, $adminA] = $this->createTenantAdmin('tenant-a');
        [$tenantB, $adminB] = $this->createTenantAdmin('tenant-b');
        $projectB = $this->createProjectFor($tenantB, $adminB);
        $articleB = $this->createArticleFor($tenantB, $projectB, $adminB);

        Sanctum::actingAs($adminA, ['*']);

        $this
            ->forTenant($tenantA)
            ->getJson("/api/v1/articles/{$articleB->id}")
            ->assertNotFound();

        $this
            ->forTenant($tenantA)
            ->get("/api/v1/articles/{$articleB->id}/download")
            ->assertNotFound();

        $this
            ->forTenant($tenantA)
            ->getJson("/api/v1/projects/{$projectB->id}/articles")
            ->assertNotFound();

        $this
            ->forTenant($tenantA)
            ->getJson("/api/v1/articles?project_id={$projectB->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['project_id']);
    }

    public function test_articles_from_deleted_projects_are_hidden_from_asset_library(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('tenant-deleted-project-assets');
        $project = $this->createProjectFor($tenant, $admin);
        $article = $this->createArticleFor($tenant, $project, $admin, [
            'title' => 'Report from deleted project',
        ]);

        TenantContext::set($tenant);
        try {
            $project->delete();
        } finally {
            TenantContext::forget();
        }

        Sanctum::actingAs($admin, ['*']);

        $this
            ->forTenant($tenant)
            ->getJson('/api/v1/articles')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this
            ->forTenant($tenant)
            ->getJson("/api/v1/articles/{$article->id}")
            ->assertNotFound();

        $this
            ->forTenant($tenant)
            ->get("/api/v1/articles/{$article->id}/download")
            ->assertNotFound();
    }

    public function test_failed_skill_execution_does_not_create_report_asset(): void
    {
        $this->useRegistryFixture();
        $this->fakeRunner(success: false);
        [$tenant, $admin] = $this->createTenantAdmin('tenant-a');
        $project = $this->createProjectFor($tenant, $admin);
        Sanctum::actingAs($admin, ['*']);

        $this
            ->forTenant($tenant)
            ->postJson('/api/v1/skills/execute', [
                'skill_id' => 'yao-geo-panorama-audit',
                'project_id' => $project->id,
                'fields' => ['brand_name' => 'Acme'],
            ])
            ->assertStatus(502)
            ->assertJsonPath('execution.status', 'failed')
            ->assertJsonPath('message', 'Skill execution failed. Please review the brief and try again.');

        $execution = SkillExecution::query()->firstOrFail();
        $this->assertSame('failed', $execution->status);
        $this->assertNull($execution->article_id);
        $this->assertSame(0, Article::query()->count());
    }

    public function test_skill_report_uses_structured_title_when_rendered_text_has_no_heading(): void
    {
        $this->useRegistryFixture();
        $this->app->instance(SkillAgentRunner::class, new class implements SkillAgentRunner {
            public function execute(SkillBrief $brief): SkillExecutionResult
            {
                return new SkillExecutionResult(
                    executionId: 0,
                    skillId: $brief->skillId,
                    success: true,
                    outputPaths: [],
                    intermediateData: [
                        'raw_output' => '正文已生成，但没有 Markdown 一级标题。',
                        'structured' => ['title' => '示例文旅AI搜索意图挖掘与GEO传播优化报告'],
                    ],
                    qualityReport: ['passed' => true, 'score' => 100],
                    durationSeconds: 1,
                    llmCallCount: 1,
                    tokenUsage: 20,
                );
            }

            public function executeAsync(SkillBrief $brief): int { return 0; }

            public function getExecutionStatus(int $executionId): SkillExecutionResult
            {
                return SkillExecutionResult::failure('', 'Not implemented in test.');
            }

            public function cancel(int $executionId): bool { return false; }
        });

        [$tenant, $admin] = $this->createTenantAdmin('tenant-title');
        $project = $this->createProjectFor($tenant, $admin);
        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)->postJson('/api/v1/skills/execute', [
            'skill_id' => 'yao-geo-panorama-audit',
            'project_id' => $project->id,
            'fields' => ['brand_name' => '示例文旅'],
        ])->assertOk();

        $this->assertSame(
            '示例文旅AI搜索意图挖掘与GEO传播优化报告',
            Article::query()->firstOrFail()->title,
        );
    }

    private function useRegistryFixture(): void
    {
        $path = storage_path('framework/testing/report-asset-skill-registry.json');
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
                    return SkillExecutionResult::failure($brief->skillId, 'C:\\secret\\runner.php failed');
                }

                return new SkillExecutionResult(
                    executionId: 0,
                    skillId: $brief->skillId,
                    success: true,
                    outputPaths: [],
                    intermediateData: [
                        'raw_output' => "# Skill result\n\nReady to publish.",
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

    private function createArticleFor(Tenant $tenant, Project $project, User $author): Article
    {
        TenantContext::set($tenant);

        try {
            return Article::query()->create([
                'project_id' => $project->id,
                'author_id' => $author->id,
                'title' => 'Tenant B report',
                'body_markdown' => '# Tenant B report',
                'summary' => 'Private report',
                'content_type' => 'general',
                'status' => 'approved',
                'source_type' => 'geo_analysis',
                'source_skill' => Project::GEO_ANALYSIS_SKILL_ID,
                'metadata' => ['structured' => ['summary' => 'Private report']],
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
