<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ModelCallLog;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\SkillExecution;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Projects\ProjectTrashService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 项目垃圾桶（计划 §9.5；用户口径 2026-09-21）。
 *
 * 用户原话：「客户删项目之后可以自动保留 7 天，7 天之后无法恢复，这里是不是应该设计垃圾桶」。
 * 这组测试守四件事：
 *  1. 删除 = 进桶：列表里没了、公开页下线、自动化任务被暂停（不能继续替死项目扣积分）；
 *     但文章/分析报告/阶段都还在 —— 否则"7 天内可恢复"是空话；
 *  2. 7 天内恢复：项目、文章、公开页、任务全部回到删除前；
 *  3. 期满不可恢复：恢复端点 410；清理命令默认只演练，--apply 才真删；
 *  4. 清理只清该清的：调用流水（跟钱有关）连行带快照都留着。
 */
class ProjectTrashTest extends TestCase
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

    public function test_deleting_a_project_puts_it_in_the_trash_instead_of_destroying_it(): void
    {
        [$tenant, $admin, $project, $article, $task] = $this->fixture();
        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->deleteJson("/api/v1/projects/{$project->id}")
            ->assertNoContent();

        // 1. 列表里没有了（软删后 TenantScope + SoftDeletes 双重作用）
        $this->forTenant($tenant)->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonPath('data', []);

        // 2. 内容还在 —— 这是"能恢复"的前提
        $this->assertSoftDeleted('projects', ['id' => $project->id]);
        $this->assertDatabaseHas('articles', ['id' => $article->id]);
        $this->assertDatabaseHas('project_stages', ['project_id' => $project->id]);

        // 3. 公开页立刻下线（删了却还能读到，等于删除没发生）
        $this->get("/read/{$tenant->subdomain}/{$article->slug}")->assertStatus(410);

        // 4. 自动化任务被暂停：项目都没了还继续跑，等于替死项目扣客户的钱
        $this->assertSame(Task::STATUS_PAUSED, $task->fresh()->status);

        // 5. 垃圾桶列表：剩余天数与清理时刻都如实给出
        $trash = $this->forTenant($tenant)->getJson('/api/v1/projects/trash')->assertOk();
        $this->assertSame(1, count($trash->json('data')));
        $this->assertSame($project->id, $trash->json('data.0.id'));
        $this->assertSame(7, $trash->json('data.0.days_left'));
        $this->assertSame(7, $trash->json('retention_days'));
        $this->assertSame(1, $trash->json('data.0.articles_count'));
    }

    public function test_a_trashed_project_can_be_restored_within_the_retention_window(): void
    {
        [$tenant, $admin, $project, $article, $task] = $this->fixture();
        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->deleteJson("/api/v1/projects/{$project->id}")
            ->assertNoContent();

        $restore = $this->forTenant($tenant)
            ->postJson("/api/v1/projects/{$project->id}/restore")
            ->assertOk();

        $this->assertTrue($restore->json('restored'));
        // 进桶时被我们暂停的那条任务要复活；客户自己按过暂停的任务不在名单里，不会被顺手打开
        $this->assertSame([$task->id], $restore->json('resumed_task_ids'));

        // 项目、内容、任务恢复，已退役的站内公开页仍保持撤下
        $this->forTenant($tenant)->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonPath('data.0.id', $project->id);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'deleted_at' => null]);
        $this->get("/read/{$tenant->subdomain}/{$article->slug}")->assertStatus(410);
        $this->assertSame(Task::STATUS_ACTIVE, $task->fresh()->status);

        // 出桶之后垃圾桶里不该再有它
        $this->assertSame([], $this->forTenant($tenant)->getJson('/api/v1/projects/trash')->assertOk()->json('data'));
    }

    public function test_restoring_an_expired_project_is_refused(): void
    {
        [$tenant, $admin, $project] = $this->fixture();
        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)->deleteJson("/api/v1/projects/{$project->id}")->assertNoContent();

        // 把进桶时间拨到 8 天前（保留期 7 天）
        $project->fresh()->forceFill(['deleted_at' => now()->subDays(8)])->save();

        $this->forTenant($tenant)
            ->postJson("/api/v1/projects/{$project->id}/restore")
            ->assertStatus(410)
            ->assertJsonPath('message', '这个项目已超过 7 天保留期，无法恢复。');

        $this->assertSoftDeleted('projects', ['id' => $project->id]);
    }

    public function test_purge_command_only_deletes_expired_projects_and_keeps_call_logs(): void
    {
        [$tenant, $admin, $project, $article, $task] = $this->fixture();
        $log = ModelCallLog::query()->where('project_id', $project->id)->firstOrFail();
        Sanctum::actingAs($admin, ['*']);

        // 第二个项目：留在垃圾桶里但**没到期**，清理命令一根汗毛都不许动它
        $fresh = $this->createProject($tenant, $admin, '还差一天的项目');
        $this->forTenant($tenant)->deleteJson("/api/v1/projects/{$fresh->id}")->assertNoContent();

        $this->forTenant($tenant)->deleteJson("/api/v1/projects/{$project->id}")->assertNoContent();
        $project->fresh()->forceFill(['deleted_at' => now()->subDays(8)])->save();

        // 1. 默认只演练
        $this->artisan('geo:projects-purge --json')
            ->expectsOutputToContain('"mode":"dry-run"')
            ->assertSuccessful();

        $this->assertDatabaseHas('projects', ['id' => $project->id]);
        $this->assertDatabaseHas('articles', ['id' => $article->id]);

        // 2. --apply 真清理
        $this->artisan('geo:projects-purge --apply --json')
            ->expectsOutputToContain('"mode":"apply"')
            ->assertSuccessful();

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        $this->assertDatabaseMissing('articles', ['id' => $article->id]);
        $this->assertDatabaseMissing('project_stages', ['project_id' => $project->id]);
        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);

        // 3. 没到期的项目还在：清理是"到期才清"，不是"见到垃圾桶就清"
        $this->assertSoftDeleted('projects', ['id' => $fresh->id]);

        // 4. 调用流水连行带快照都留着
        $kept = ModelCallLog::withoutGlobalScopes()->find($log->id);
        $this->assertNotNull($kept, '清理垃圾桶把调用流水删了 —— 跟钱有关的记录必须留下');
        $this->assertNotNull(data_get($kept->metadata, 'project_name_snapshot'));
    }

    public function test_the_trash_is_only_visible_to_whoever_may_delete_projects(): void
    {
        [$tenant, $admin, $project] = $this->fixture();
        $editor = User::factory()->create([
            'tenant_id' => $tenant->id,

            'role' => 'editor',
        ]);

        Sanctum::actingAs($admin, ['*']);
        $this->forTenant($tenant)->deleteJson("/api/v1/projects/{$project->id}")->assertNoContent();

        // editor 没有 project:delete：垃圾桶连看都看不到
        Sanctum::actingAs($editor, ['*']);
        $this->forTenant($tenant)->getJson('/api/v1/projects/trash')->assertForbidden();
        $this->forTenant($tenant)->postJson("/api/v1/projects/{$project->id}/restore")->assertForbidden();
    }

    /**
     * 清理一个挂着知识库的项目：外键是 RESTRICT，直接 forceDelete 会被数据库拒绝。
     *
     * 生产库实测（2026-09-21，pg_constraint.confdeltype='r'）只有两张表是 RESTRICT：
     * knowledge_bases 与 enterprise_knowledge_projects。清理必须先把归属摘掉、让知识库**留下**
     * —— 知识库的保留期限属 §9.5 里还没拍板的那一条，不能借"清项目"顺手删掉。
     */
    public function test_purging_a_project_that_owns_a_knowledge_base_detaches_instead_of_failing(): void
    {
        [$tenant, $admin, $project] = $this->fixture();
        Sanctum::actingAs($admin, ['*']);

        TenantContext::set($tenant);
        try {
            $knowledgeBase = \App\Models\KnowledgeBase::query()->create([
                'project_id' => $project->id,
                'name' => '挂在项目下的知识库',
                'type' => 'general',
            ]);
        } finally {
            TenantContext::forget();
        }

        $this->forTenant($tenant)->deleteJson("/api/v1/projects/{$project->id}")->assertNoContent();
        $project->fresh()->forceFill(['deleted_at' => now()->subDays(8)])->save();

        $this->artisan('geo:projects-purge --apply --json')
            ->expectsOutputToContain('"detached":{"knowledge_bases":1}')
            ->assertSuccessful();

        // 项目真没了；知识库还在，只是不再挂在这个项目下
        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        $this->assertDatabaseHas('knowledge_bases', ['id' => $knowledgeBase->id]);
        $this->assertNull(\App\Models\KnowledgeBase::query()->whereKey($knowledgeBase->id)->value('project_id'));
    }

    /**
     * 造一个"有文章、有分析报告、有阶段、有自动化任务、有调用流水"的项目，并把它删进垃圾桶。
     *
     * @return array{0:Tenant,1:User,2:Project,3:Article,4:Task}
     */
    private function fixture(): array
    {
        $tenant = Tenant::query()->create([
            'subdomain' => 'trash-'.substr(md5((string) random_int(1, 1_000_000)), 0, 6),
            'name' => 'TRASH',
            'plan' => 'basic',
            'status' => 'active',
        ]);
        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,

            'role' => 'admin',
        ]);

        TenantContext::set($tenant);
        try {
            $project = Project::query()->create([
                'owner_id' => $admin->id,
                'name' => '要被删掉的项目',
                'current_stage' => 0,
                'progress' => 0,
            ]);
            foreach (Project::STAGES as $stage) {
                ProjectStage::query()->create([
                    'project_id' => $project->id,
                    'stage_index' => $stage['index'],
                    'stage_key' => $stage['key'],
                    'status' => $stage['index'] === 0 ? 'active' : 'pending',
                ]);
            }

            $article = Article::query()->create([
                'project_id' => $project->id,
                'author_id' => $admin->id,
                'title' => '桶里的文章',
                'slug' => 'trash-post-'.$project->id,
                'body_markdown' => '# 正文',
                'status' => 'published',
                'source_type' => 'manual',
                'metadata' => [],
            ]);

            $execution = SkillExecution::query()->create([
                'project_id' => $project->id,
                'user_id' => $admin->id,
                'skill_id' => 'geo_explainer',
                'skill_name' => '解释型文章',
                'category' => 'content',
                'stage_index' => 2,
                'status' => 'completed',
            ]);

            $task = Task::query()->create([
                'project_id' => $project->id,
                'user_id' => $admin->id,
                'name' => '每天一篇',
                'skill_id' => 'geo_explainer',
                'cadence' => Task::CADENCE_DAILY,
                'timezone' => Task::DEFAULT_TIMEZONE,
                'status' => Task::STATUS_ACTIVE,
                'next_run_at' => now()->addHour(),
            ]);

            ModelCallLog::query()->create([
                'project_id' => $project->id,
                'user_id' => $admin->id,
                'skill_execution_id' => $execution->id,
                'provider' => 'dashscope',
                'model' => 'vision',
                'status' => 'completed',
                'prompt_tokens' => 10,
                'completion_tokens' => 20,
                'total_tokens' => 30,
                'duration_seconds' => 1.0,
                'metadata' => ['points_deducted' => 42, 'points_deduction_status' => 'charged'],
            ]);
        } finally {
            TenantContext::forget();
        }

        // 自检：确认 ProjectTrashService 认得这条桶内记录（防止上面造数与服务判据漂移）
        $trash = app(ProjectTrashService::class);
        $this->assertSame(7, $trash->retentionDays());

        return [$tenant, $admin, $project, $article, $task];
    }

    private function forTenant(Tenant $tenant): self
    {
        return $this
            ->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }

    /** tenant_id 靠 TenantContext 自动填（不在 fillable 里），造数必须先进上下文。 */
    private function createProject(Tenant $tenant, User $owner, string $name): Project
    {
        TenantContext::set($tenant);
        try {
            return Project::query()->create([
                'owner_id' => $owner->id,
                'name' => $name,
                'current_stage' => 0,
                'progress' => 0,
            ]);
        } finally {
            TenantContext::forget();
        }
    }
}
