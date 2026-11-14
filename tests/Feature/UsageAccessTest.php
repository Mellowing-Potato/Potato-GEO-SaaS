<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ModelCallLog;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\SkillExecution;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\ModelUsageRecorder;
use App\Services\Contracts\SkillAgentRunner;
use App\Services\Contracts\SkillRegistry;
use App\Services\DTOs\AiProviderCallResult;
use App\Services\DTOs\SkillBrief;
use App\Services\DTOs\SkillExecutionResult;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UsageAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.tenant_domain' => 'geo.example.test',
            'geo.skill_execution_claim_required' => false]);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();

        parent::tearDown();
    }

    public function test_all_skills_are_available_without_plan_gate_sync_or_async(): void
    {
        $this->useRegistryFixture();
        $runner = $this->fakeCountingRunner(success: true);
        [$tenant, $admin] = $this->createTenantAdmin('tenant-a', 'basic');
        $project = $this->createProjectFor($tenant, $admin);
        Sanctum::actingAs($admin, ['*']);

        $response = $this
            ->forTenant($tenant)
            ->getJson('/api/v1/skills')
            ->assertOk()
            ->assertJsonPath('data.2.id', 'yao-geo-title-optimizer');

        $this->assertArrayNotHasKey('plan', $response->json('data.2'));
        $this->assertArrayNotHasKey('available_on_current_plan', $response->json('data.2'));
        $this->assertArrayNotHasKey('plan_restriction', $response->json('data.2'));

        $this
            ->forTenant($tenant)
            ->postJson('/api/v1/skills/execute', [
                'skill_id' => 'yao-geo-title-optimizer',
                'project_id' => $project->id,
                'fields' => ['topic' => '测试文章', 'target_keyword' => '可见性'],
            ])
            ->assertOk()
            ->assertJsonPath('execution.status', 'completed')
            ->assertJsonPath('execution.skill_id', 'yao-geo-title-optimizer');

        $this
            ->forTenant($tenant)
            ->postJson('/api/v1/skills/execute-async', [
                'skill_id' => 'yao-geo-title-optimizer',
                'project_id' => $project->id,
                'fields' => ['topic' => '测试文章', 'target_keyword' => '可见性'],
            ])
            ->assertStatus(202)
            ->assertJsonPath('execution.status', 'pending')
            ->assertJsonPath('execution.skill_id', 'yao-geo-title-optimizer');

        $this->assertSame(1, $runner->syncCalls);
        $this->assertSame(1, $runner->asyncCalls);
        $this->assertSame(2, SkillExecution::withoutGlobalScopes()->count());
    }

    public function test_legacy_pro_plan_label_does_not_gate_skill_execution(): void
    {
        $this->useRegistryFixture();
        $runner = $this->fakeCountingRunner(success: true);
        [$tenant, $admin] = $this->createTenantAdmin('tenant-a', 'pro');
        $project = $this->createProjectFor($tenant, $admin);
        Sanctum::actingAs($admin, ['*']);

        $this
            ->forTenant($tenant)
            ->postJson('/api/v1/skills/execute', [
                'skill_id' => 'yao-geo-title-optimizer',
                'project_id' => $project->id,
                'fields' => ['topic' => '测试文章', 'target_keyword' => '可见性'],
            ])
            ->assertOk()
            ->assertJsonPath('execution.status', 'completed')
            ->assertJsonPath('execution.skill_id', 'yao-geo-title-optimizer');

        $this->assertSame(1, $runner->syncCalls);
        $this->assertDatabaseHas('skill_executions', [
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'skill_id' => 'yao-geo-title-optimizer',
            'status' => 'completed',
        ]);
    }

    public function test_legacy_enterprise_plan_label_does_not_gate_async_skill_execution(): void
    {
        $this->useRegistryFixture();
        $runner = $this->fakeCountingRunner(success: true);
        [$tenant, $admin] = $this->createTenantAdmin('tenant-a', 'enterprise');
        $project = $this->createProjectFor($tenant, $admin);
        Sanctum::actingAs($admin, ['*']);

        $this
            ->forTenant($tenant)
            ->postJson('/api/v1/skills/execute-async', [
                'skill_id' => 'yao-geo-title-optimizer',
                'project_id' => $project->id,
                'fields' => ['topic' => '测试文章', 'target_keyword' => '可见性'],
            ])
            ->assertStatus(202)
            ->assertJsonPath('execution.status', 'pending')
            ->assertJsonPath('execution.skill_id', 'yao-geo-title-optimizer');

        $this->assertSame(0, $runner->syncCalls);
        $this->assertSame(1, $runner->asyncCalls);
        $this->assertDatabaseHas('skill_executions', [
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'skill_id' => 'yao-geo-title-optimizer',
            'status' => 'pending',
        ]);
    }

    public function test_project_creation_is_not_limited_by_plan_version(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('tenant-a', 'basic');
        $this->createProjectFor($tenant, $admin, ['name' => 'Project One']);
        $this->createProjectFor($tenant, $admin, ['name' => 'Project Two']);
        Sanctum::actingAs($admin, ['*']);

        $this
            ->forTenant($tenant)
            ->postJson('/api/v1/projects', [
                'name' => 'Project Three',
                'brand_name' => 'Tenant A',
            ])
            ->assertCreated()
            ->assertJsonPath('name', 'Project Three')
            ->assertJsonPath('brand_name', 'Tenant A');

        $this->assertSame(3, Project::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $this->assertGreaterThan(0, ProjectStage::query()->count());
    }

    public function test_usage_api_is_current_tenant_scoped_and_redacts_errors(): void
    {
        [$tenantA, $adminA] = $this->createTenantAdmin('tenant-a', 'basic');
        [$tenantB, $adminB] = $this->createTenantAdmin('tenant-b', 'enterprise');
        $projectA = $this->createProjectFor($tenantA, $adminA, ['name' => 'Tenant A Project']);
        $projectB = $this->createProjectFor($tenantB, $adminB, ['name' => 'Tenant B Secret Project']);

        $this->createArticleFor($tenantA, $projectA, $adminA, ['title' => 'Tenant A Article']);
        $this->createArticleFor($tenantB, $projectB, $adminB, ['title' => 'Tenant B Secret Article']);

        $analysisA = $this->createExecutionFor($tenantA, $projectA, $adminA, [
            'skill_id' => Project::GEO_ANALYSIS_SKILL_ID,
            'skill_name' => 'GEO MVP Analysis',
            'category' => 'geo-strategy',
            'token_usage' => 42,
        ]);
        $this->createExecutionFor($tenantA, $projectA, $adminA, [
            'skill_id' => 'yao-geo-explainer-builder',
            'skill_name' => 'Explainer Builder',
            'category' => 'geo-content-production',
            'token_usage' => 11,
        ]);
        $this->createExecutionFor($tenantA, $projectA, $adminA, [
            'skill_id' => 'yao-geo-title-optimizer',
            'skill_name' => 'Effect Monitor',
            'category' => 'geo-measurement',
            'status' => 'failed',
            'error_message' => 'Provider token=sk-test-13cb6a530ab4753495a658 failed for fixture-user-7@example.com phone 13800000027',
        ]);
        $this->createExecutionFor($tenantB, $projectB, $adminB, [
            'skill_id' => 'yao-geo-title-optimizer',
            'skill_name' => 'Tenant B Monitor',
            'category' => 'geo-measurement',
            'status' => 'failed',
            'error_message' => 'Tenant B private failure',
            'token_usage' => 999,
        ]);

        $this->createModelCallFor($tenantA, $projectA, $adminA, $analysisA, [
            'total_tokens' => 42,
            'metadata' => [
                'points_deduction_status' => 'not_integrated',
                'points_deduction_failure_reason' => 'Debit api_key=qh-dashboard-secret-1234567890 failed for fixture-user-7@example.com phone 13800000027',
            ],
        ]);
        $this->createModelCallFor($tenantB, $projectB, $adminB, null, [
            'total_tokens' => 999,
            'metadata' => ['points_deduction_status' => 'deducted'],
        ]);

        Sanctum::actingAs($adminA, ['*']);

        $response = $this
            ->forTenant($tenantA)
            ->getJson('/api/v1/dashboard/usage')
            ->assertOk()
            ->assertJsonPath('quotas.projects.current', 1)
            ->assertJsonPath('quotas.articles.current', 1)
            ->assertJsonPath('quotas.geo_analysis.current', 1)
            ->assertJsonPath('quotas.skill_executions.current', 2)
            ->assertJsonPath('quotas.tokens.current', 53)
            // P3：套餐版权限下线后各 *Limit() 返回 0（= 无上限），出参必须归一成 null，
            // 否则前端会把 0 当分母渲染成 "x / 0"
            ->assertJsonPath('quotas.projects.limit', null)
            ->assertJsonPath('quotas.projects.unlimited', true)
            ->assertJsonPath('quotas.tokens.limit', null)
            ->assertJsonPath('quotas.geo_analysis.limit', null)
            ->assertJsonPath('quotas.geo_analysis.percent', null)
            ->assertJsonPath('quotas.geo_analysis.remaining', null)
            ->assertJsonPath('model_usage.calls', 1)
            ->assertJsonPath('model_usage.total_tokens', 42)
            ->assertJsonPath('operations.recent_model_calls.0.total_tokens', 42)
            ->assertJsonPath('operations.recent_failed_executions.0.error_message', 'Provider token=[redacted_secret] failed for [redacted_email] phone [redacted_phone]');

        $content = $response->getContent();
        $this->assertStringNotContainsString('sk-test-13cb6a530ab4753495a658', $content);
        $this->assertStringNotContainsString('qh-dashboard-secret-1234567890', $content);
        $this->assertStringNotContainsString('fixture-user-7@example.com', $content);
        $this->assertStringNotContainsString('13800000027', $content);
        $this->assertStringNotContainsString('Tenant B Secret Project', $content);
        $this->assertStringNotContainsString('Tenant B Secret Article', $content);
        $this->assertStringNotContainsString('Tenant B private failure', $content);
    }

    public function test_usage_period_covers_whole_month_and_never_collapses_to_one_day(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('tenant-period', 'basic');
        Sanctum::actingAs($admin, ['*']);

        // 每月 1 号：原实现 to 取"今天"，起止同日看着像零长度周期
        $this->travelTo(now()->startOfMonth());

        $payload = $this
            ->forTenant($tenant)
            ->getJson('/api/v1/dashboard/usage')
            ->assertOk()
            ->json();

        $from = $payload['period']['from'];
        $to = $payload['period']['to'];

        $this->assertSame(now()->startOfMonth()->toDateString(), $from);
        $this->assertSame(now()->endOfMonth()->toDateString(), $to);
        $this->assertNotSame($from, $to, '计费周期起止不应同日');
        $this->assertSame(now()->toDateString(), $payload['period']['as_of']);

        $this->travelBack();
    }

    private function useRegistryFixture(): void
    {
        $path = storage_path('framework/testing/commercial-skill-registry.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'skills' => [
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
                    'id' => 'yao-geo-explainer-builder',
                    'family' => 'geo-content-production',
                    'maturity' => 'beta',
                    'requires_web' => true,
                    'primary_outputs' => ['article'],
                    'status' => 'active',
                    'path' => 'skills/yao-geo-explainer-builder',
                    'summary' => 'Build an explainer article.',
                ],
                [
                    'id' => 'yao-geo-title-optimizer',
                    'family' => 'geo-content-production',
                    'maturity' => 'beta',
                    'requires_web' => true,
                    'primary_outputs' => ['title-options'],
                    'status' => 'active',
                    'path' => 'skills/yao-geo-title-optimizer',
                    'summary' => 'Optimize article titles.',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // This fixture explicitly approves its own IDs; production remains capped at 17.
        config(['geo.skill_registry_path' => $path, 'geo.skill_allowed_ids' => array_column(array_filter(json_decode(file_get_contents($path), true)['skills'], fn ($row) => ($row['status'] ?? null) === 'active'), 'id')]);
        $this->app->forgetInstance(SkillRegistry::class);
    }

    private function fakeCountingRunner(bool $success = false): object
    {
        $runner = new class($success) implements SkillAgentRunner {
            public int $syncCalls = 0;
            public int $asyncCalls = 0;

            public function __construct(private readonly bool $success) {}

            public function execute(SkillBrief $brief): SkillExecutionResult
            {
                $this->syncCalls++;

                if (! $this->success) {
                    return SkillExecutionResult::failure($brief->skillId, 'Should not run.');
                }

                return new SkillExecutionResult(
                    executionId: 0,
                    skillId: $brief->skillId,
                    success: true,
                    outputPaths: [],
                    intermediateData: ['raw_output' => '# Commercial Skill result'],
                    qualityReport: ['passed' => true, 'score' => 90],
                    durationSeconds: 1.0,
                    llmCallCount: 1,
                    tokenUsage: 17,
                );
            }

            public function executeAsync(SkillBrief $brief): int
            {
                $this->asyncCalls++;

                return SkillExecution::query()->create([
                    'project_id' => $brief->projectId,
                    'user_id' => $brief->userId,
                    'skill_id' => $brief->skillId,
                    'skill_name' => 'Async Skill',
                    'category' => 'geo-measurement',
                    'stage_index' => 4,
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

    private function createTenantAdmin(string $subdomain, string $plan): array
    {
        $tenant = Tenant::query()->create([
            'subdomain' => $subdomain,
            'name' => strtoupper($subdomain),
            'plan' => $plan,
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
                'output_data' => [],
                'token_usage' => 0,
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
                'title' => $tenant->name.' Article',
                'body_markdown' => '# Article',
                'summary' => 'Summary',
                'content_type' => 'general',
                'status' => 'approved',
                'source_type' => 'manual',
                'version' => 1,
                'word_count' => 8,
                'metadata' => [],
            ], $overrides));
        } finally {
            TenantContext::forget();
        }
    }

    private function createModelCallFor(Tenant $tenant, Project $project, User $user, ?SkillExecution $execution, array $overrides = []): ModelCallLog
    {
        TenantContext::set($tenant);

        try {
            return ModelCallLog::query()->create(array_merge([
                'tenant_id' => $tenant->id,
                'project_id' => $project->id,
                'user_id' => $user->id,
                'skill_execution_id' => $execution?->id,
                'provider' => 'gateway',
                'model' => 'text',
                'status' => 'completed',
                'prompt_tokens' => 10,
                'completion_tokens' => 20,
                'total_tokens' => 30,
                'duration_seconds' => 1.0,
                'metadata' => ['points_deduction_status' => 'not_integrated'],
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
    public function test_usage_granularity_all_returns_records_outside_today(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('usage-all', 'basic');
        Sanctum::actingAs($admin, ['*']);

        TenantContext::set($tenant);
        $project = \App\Models\Project::query()->create([
            'owner_id' => $admin->id,
            'name' => 'Proj usage-all',
            'brand_name' => 'usage-all',
            'brand_url' => 'https://usage-all.example.test',
            'settings' => [],
        ]);
        TenantContext::forget();

        $log = \App\Models\ModelCallLog::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'user_id' => $admin->id,
            'provider' => 'dashscope',
            'model' => 'text',
            'status' => 'completed',
            'total_tokens' => 1200,
            'metadata' => ['points_deducted' => 3300.1234],
        ]);
        // 落在今天之外（上个月），day 粒度必须查不到、all 必须查得到
        $log->forceFill(['created_at' => now()->subMonth()->startOfMonth()->addDays(2)])->save();

        $this->forTenant($tenant)
            ->getJson('/api/v1/usage?granularity=day')
            ->assertOk()
            ->assertJsonPath('records.total', 0);

        $this->forTenant($tenant)
            ->getJson('/api/v1/usage?granularity=all&per_page=5')
            ->assertOk()
            ->assertJsonPath('records.total', 1)
            ->assertJsonPath('summary.total_tokens', 1200)
            ->assertJsonPath('records.data.0.total_tokens', 1200)
            ->assertJsonPath('daily_usage.0.total_tokens', 1200)
            ->assertJsonPath('period.granularity', 'all')
            // 不限时间时没有起始日，必须是 null 而不是编一个日期出来
            ->assertJsonPath('period.from', null);
    }
}
