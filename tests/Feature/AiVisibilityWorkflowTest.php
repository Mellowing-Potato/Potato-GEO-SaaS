<?php

namespace Tests\Feature;

use App\Models\AiVisibilityRun;
use App\Models\Article;
use App\Models\ArticlePublication;
use App\Models\ManualPublication;
use App\Models\ManualPublicationPersona;
use App\Models\Project;
use App\Models\PublishingChannel;
use App\Models\SkillExecution;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Contracts\ProjectWorkflow;
use App\Services\Enums\WorkflowStage;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AiVisibilityWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private Project $project;

    private Article $article;

    private ArticlePublication $publication;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.tenant_domain' => 'geo.example.test']);
        Http::preventStrayRequests();
        $this->tenant = Tenant::query()->create([
            'subdomain' => 'visibility-workflow', 'name' => 'Visibility workflow',
            'plan' => 'basic', 'status' => 'active',
        ]);
        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'admin']);
        TenantContext::set($this->tenant);
        $this->project = Project::query()->create([
            'owner_id' => $this->admin->id,
            'name' => 'Monitoring project',
            'current_stage' => WorkflowStage::Monitoring->value,
            'status' => 'active',
        ]);
        foreach (WorkflowStage::cases() as $stage) {
            $this->project->stages()->create([
                'stage_index' => $stage->value,
                'stage_key' => $stage->key(),
                'status' => $stage->value < WorkflowStage::Monitoring->value
                    ? 'completed' : ($stage === WorkflowStage::Monitoring ? 'active' : 'pending'),
            ]);
        }
        $this->article = Article::query()->create([
            'project_id' => $this->project->id,
            'author_id' => $this->admin->id,
            'title' => 'Published research article',
            'body_markdown' => 'Verified project content.',
            'status' => Article::STATUS_APPROVED,
        ]);
        $channel = PublishingChannel::query()->create([
            'name' => 'External site',
            'type' => PublishingChannel::TYPE_HTTP_WEBHOOK,
            'endpoint_url' => 'https://publisher.example.test/api',
        ]);
        $this->publication = ArticlePublication::query()->create([
            'article_id' => $this->article->id,
            'publishing_channel_id' => $channel->id,
            'status' => ArticlePublication::STATUS_PUBLISHED,
            'remote_url' => 'https://publisher.example.test/verified-article',
            'published_at' => now()->subHour(),
        ]);
        Sanctum::actingAs($this->admin, ['*']);
        $this->withServerVariables(['HTTP_HOST' => 'visibility-workflow.geo.example.test'])
            ->withHeader('X-Tenant', $this->tenant->subdomain);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    public function test_completed_real_monitor_updates_recommendations_and_allows_stage_advance(): void
    {
        $this->createRun(AiVisibilityRun::STATUS_COMPLETED);

        $this->assertMonitoringRecommendation(['yao-geo-effect-monitor']);
        $this->postJson($this->advanceUrl())->assertOk()->assertJsonPath('current_stage', 5);

        $this->assertDatabaseHas('project_stages', [
            'project_id' => $this->project->id, 'stage_index' => 4, 'status' => 'completed',
        ]);
        $this->assertDatabaseHas('project_stages', [
            'project_id' => $this->project->id, 'stage_index' => 5, 'status' => 'active',
        ]);
        // 读取完成证据不会伪造技能执行，也不会重复记账。
        $this->assertDatabaseCount('skill_executions', 0);
        $this->assertDatabaseCount('model_call_logs', 0);
    }

    public function test_prepublication_baseline_and_draft_do_not_complete_monitoring(): void
    {
        $this->createRun(AiVisibilityRun::STATUS_COMPLETED, ['completed_at' => now()->subHours(2)]);

        $this->assertMonitoringRecommendation([]);
        $this->postJson($this->advanceUrl())->assertStatus(422);

        $this->publication->update(['status' => ArticlePublication::STATUS_ACCEPTED]);
        $this->createRun(AiVisibilityRun::STATUS_COMPLETED);

        $this->assertMonitoringRecommendation([]);
        $this->postJson($this->advanceUrl())->assertStatus(422);
    }

    public function test_page_audit_alone_does_not_complete_distribution_without_a_live_link(): void
    {
        $this->project->stages()->where('stage_index', 3)->update(['status' => 'active']);
        $this->project->stages()->where('stage_index', 4)->update(['status' => 'pending']);
        $this->project->update(['current_stage' => 3, 'progress' => 50]);
        $this->createExecution('yao-geo-page-audit', 'completed');
        $this->publication->update(['status' => ArticlePublication::STATUS_ACCEPTED]);
        $stageId = $this->project->stages()->where('stage_index', 3)->value('id');
        $url = '/api/v1/projects/'.$this->project->id.'/stages/'.$stageId.'/advance';

        $this->getJson('/api/v1/projects/'.$this->project->id)
            ->assertOk()
            ->assertJsonPath('current_stage_requirements.met', false);
        $response = $this->postJson($url)->assertStatus(422);
        $this->assertStringContainsString('外部发布记录', (string) $response->json('message'));

        $this->publication->update(['status' => ArticlePublication::STATUS_PUBLISHED, 'remote_url' => null]);
        $this->postJson($url)->assertStatus(422);
        $this->getJson('/api/v1/projects/'.$this->project->id)->assertOk()
            ->assertJsonPath('workflow_recommendations.3.recommended_skill.id', 'yao-geo-channel-publishing')
            ->assertJsonPath('workflow_recommendations.3.recommended_skill.requires_web', false);

        $this->publication->update(['remote_url' => 'https://publisher.example.test/verified-article']);
        $this->getJson('/api/v1/projects/'.$this->project->id)
            ->assertOk()->assertJsonPath('current_stage_requirements.met', true);
        $this->postJson($url)->assertOk()->assertJsonPath('current_stage', 4);
    }

    public function test_completed_manual_post_with_link_counts_but_draft_does_not(): void
    {
        $this->publication->update(['status' => ArticlePublication::STATUS_ACCEPTED]);
        $this->project->stages()->where('stage_index', 3)->update(['status' => 'active']);
        $this->project->stages()->where('stage_index', 4)->update(['status' => 'pending']);
        $this->project->update(['current_stage' => 3, 'progress' => 50]);
        $persona = ManualPublicationPersona::query()->create([
            'name' => 'Official editor',
            'created_by_user_id' => $this->admin->id,
        ]);
        $manual = ManualPublication::query()->create([
            'article_id' => $this->article->id,
            'persona_id' => $persona->id,
            'created_by_user_id' => $this->admin->id,
            'platform' => 'custom',
            'content' => 'Approved article for external posting.',
            'content_fingerprint' => hash('sha256', 'Approved article for external posting.'),
            'status' => ManualPublication::STATUS_DRAFT,
        ]);
        $stageId = $this->project->stages()->where('stage_index', 3)->value('id');
        $url = '/api/v1/projects/'.$this->project->id.'/stages/'.$stageId.'/advance';

        $this->postJson($url)->assertStatus(422);
        $manual->update([
            'status' => ManualPublication::STATUS_COMPLETED,
            'completion_url' => 'https://publisher.example.test/manual-post',
            'completed_at' => now(),
        ]);
        $this->postJson($url)->assertOk()->assertJsonPath('current_stage', 4);
        $this->assertDatabaseCount('skill_executions', 0);
        $this->assertDatabaseCount('model_call_logs', 0);
    }

    public function test_incomplete_or_failed_monitors_and_assist_billing_rows_do_not_complete_stage(): void
    {
        foreach ([AiVisibilityRun::STATUS_PENDING, AiVisibilityRun::STATUS_RUNNING, AiVisibilityRun::STATUS_FAILED] as $status) {
            $this->createRun($status);
        }
        $this->createExecution('ai-visibility', 'completed', 'assist');

        $this->assertMonitoringRecommendation([]);
        $this->postJson($this->advanceUrl())->assertStatus(422);
        $this->assertSame(4, $this->project->fresh()->current_stage);
    }

    public function test_other_projects_or_tenants_cannot_supply_monitoring_completion(): void
    {
        $otherProject = Project::query()->create([
            'owner_id' => $this->admin->id, 'name' => 'Another project',
        ]);
        $this->createRun(AiVisibilityRun::STATUS_COMPLETED, ['project_id' => $otherProject->id]);
        $otherTenant = Tenant::query()->create([
            'subdomain' => 'visibility-other', 'name' => 'Other tenant',
            'plan' => 'basic', 'status' => 'active',
        ]);
        // 即使某行项目 ID 相同，租户归属不同也不能作为证据。
        $this->createRun(AiVisibilityRun::STATUS_COMPLETED, ['tenant_id' => $otherTenant->id]);

        $this->assertMonitoringRecommendation([]);
        $this->postJson($this->advanceUrl())->assertStatus(422);
    }

    public function test_completed_legacy_monitor_remains_compatible(): void
    {
        $this->createExecution('yao-geo-effect-monitor', 'completed');

        $this->assertMonitoringRecommendation(['yao-geo-effect-monitor']);
        $this->postJson($this->advanceUrl())->assertOk()->assertJsonPath('current_stage', 5);
    }

    public function test_failed_legacy_monitor_does_not_complete_stage(): void
    {
        $this->createExecution('yao-geo-effect-monitor', 'failed');

        $this->assertMonitoringRecommendation([]);
        $this->postJson($this->advanceUrl())->assertStatus(422);
    }

    #[DataProvider('unmetStageConditions')]
    public function test_completed_monitor_does_not_bypass_stage_preconditions(string $condition): void
    {
        $this->createRun(AiVisibilityRun::STATUS_COMPLETED);
        if ($condition === 'monitoring_pending') {
            $this->project->stages()->where('stage_index', 4)->update(['status' => 'pending']);
        } elseif ($condition === 'distribution_missing') {
            $this->project->stages()->where('stage_index', 3)->delete();
        } else {
            $this->project->stages()->where('stage_index', 3)->update(['status' => 'active']);
        }

        $this->assertFalse(app(ProjectWorkflow::class)->canAdvance($this->project));
        $this->postJson($this->advanceUrl())->assertStatus(422);
        $this->assertSame(4, $this->project->fresh()->current_stage);
    }

    public static function unmetStageConditions(): array
    {
        return [
            '监测尚未开始' => ['monitoring_pending'],
            '发布尚未完成' => ['distribution_active'],
            '发布阶段缺失' => ['distribution_missing'],
        ];
    }

    public function test_completed_monitor_does_not_satisfy_a_different_stage(): void
    {
        $this->createRun(AiVisibilityRun::STATUS_COMPLETED);
        $this->project->update(['current_stage' => WorkflowStage::Strategy->value]);
        $this->project->stages()->where('stage_index', 1)->update(['status' => 'active']);

        $requirements = app(ProjectWorkflow::class)->getStageRequirements($this->project, WorkflowStage::Strategy);
        $this->assertFalse($requirements['met']);
        $this->assertFalse(app(ProjectWorkflow::class)->canAdvance($this->project));
    }

    public function test_successful_monitor_cannot_bypass_project_or_tenant_route_ownership(): void
    {
        $this->createRun(AiVisibilityRun::STATUS_COMPLETED);
        $otherProject = Project::query()->create(['owner_id' => $this->admin->id, 'name' => 'Other']);
        $otherStage = $otherProject->stages()->create([
            'stage_index' => 4, 'stage_key' => 'monitoring', 'status' => 'active',
        ]);
        $this->postJson('/api/v1/projects/'.$this->project->id.'/stages/'.$otherStage->id.'/advance')
            ->assertNotFound();

        $otherTenant = Tenant::query()->create([
            'subdomain' => 'visibility-foreign', 'name' => 'Foreign tenant',
            'plan' => 'basic', 'status' => 'active',
        ]);
        $foreignAdmin = User::factory()->create(['tenant_id' => $otherTenant->id, 'role' => 'admin']);
        Sanctum::actingAs($foreignAdmin, ['*']);
        $this->withServerVariables(['HTTP_HOST' => 'visibility-foreign.geo.example.test'])
            ->withHeader('X-Tenant', $otherTenant->subdomain)
            ->postJson($this->advanceUrl())->assertNotFound();
    }

    private function createRun(string $status, array $attributes = []): AiVisibilityRun
    {
        return AiVisibilityRun::query()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'user_id' => $this->admin->id,
            'batch_id' => (string) Str::uuid(),
            'question' => '品牌有哪些适用场景？',
            'status' => $status,
            'answer_text' => $status === AiVisibilityRun::STATUS_COMPLETED ? '已完成的真实监测回答。' : null,
            'completed_at' => $status === AiVisibilityRun::STATUS_COMPLETED ? now() : null,
        ], $attributes));
    }

    private function createExecution(string $skillId, string $status, string $mode = 'sync'): void
    {
        SkillExecution::query()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'user_id' => $this->admin->id,
            'skill_id' => $skillId,
            'skill_name' => '监测',
            'category' => 'geo-measurement',
            'status' => $status,
            'execution_mode' => $mode,
        ]);
    }

    private function assertMonitoringRecommendation(array $completedIds): void
    {
        $this->getJson('/api/v1/projects/'.$this->project->id)
            ->assertOk()
            ->assertJsonPath('workflow_recommendations.4.completed_skill_ids', $completedIds);
    }

    private function advanceUrl(): string
    {
        $stageId = $this->project->stages()->where('stage_index', 4)->value('id');

        return '/api/v1/projects/'.$this->project->id.'/stages/'.$stageId.'/advance';
    }
}
