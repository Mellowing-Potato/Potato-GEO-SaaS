<?php

namespace Tests\Feature;

use App\Jobs\RunTaskJob;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 阶段 1.4 任务生命周期 HTTP 测试（路由 / 权限 / 租户隔离 / 启停）
 */
class TaskLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const CONTENT_SKILL = 'yao-geo-explainer-builder';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.tenant_domain' => 'geo.example.test']);
        Queue::fake();
    }

    private function makeTenantUser(string $subdomain, string $role): array
    {
        $tenant = Tenant::query()->create([
            'subdomain' => $subdomain,
            'name' => strtoupper($subdomain),
            'plan' => 'basic',
            'status' => 'active',
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain . $role),
            'role' => $role,
        ]);

        return [$tenant, $user];
    }

    private function makeProject(Tenant $tenant, User $owner): Project
    {
        // Project 的 $fillable 不含 tenant_id，需依赖 BelongsToTenant 在创建时从 TenantContext 注入
        TenantContext::set($tenant);
        $project = Project::create([
            'name' => '测试项目',
            'brand_name' => '测试品牌',
            'owner_id' => $owner->id,
            'current_stage' => 2,
            'status' => 'active',
        ]);
        TenantContext::forget();

        return $project;
    }

    private function forTenant(Tenant $tenant): self
    {
        return $this
            ->withServerVariables(['HTTP_HOST' => $tenant->subdomain . '.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }

    public function test_admin_can_create_list_show_and_update_task(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');
        $project = $this->makeProject($tenant, $admin);

        Sanctum::actingAs($admin, ['*']);

        // 创建
        $this->forTenant($tenant)
            ->postJson('/api/v1/tasks', [
                'name' => '每日科普',
                'skill_id' => self::CONTENT_SKILL,
                'project_id' => $project->id,
                'cadence' => 'daily',
                'auto_publish' => true,
                'auto_distribute' => false,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.name', '每日科普')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.auto_publish', true)
            ->assertJsonPath('data.auto_distribute', false)
            ->assertJsonPath('object', 'task');

        $this->assertDatabaseHas('tasks', [
            'tenant_id' => $tenant->id,
            'skill_id' => self::CONTENT_SKILL,
            'status' => 'active',
        ]);

        $task = Task::query()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($task->next_run_at);

        // 列表
        Sanctum::actingAs($admin, ['*']);
        $this->forTenant($tenant)
            ->getJson('/api/v1/tasks')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        // 详情
        Sanctum::actingAs($admin, ['*']);
        $this->forTenant($tenant)
            ->getJson("/api/v1/tasks/{$task->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $task->id);

        // 更新（改节奏）
        Sanctum::actingAs($admin, ['*']);
        $this->forTenant($tenant)
            ->patchJson("/api/v1/tasks/{$task->id}", ['cadence' => 'weekly'])
            ->assertStatus(200)
            ->assertJsonPath('data.cadence', 'weekly');
    }

    public function test_member_cannot_manage_tasks(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');
        $project = $this->makeProject($tenant, $admin);

        $member = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => \Tests\Support\FixtureIdentity::phone('tenant-a_member'),
            'role' => 'member',
        ]);

        Sanctum::actingAs($member, ['*']);

        $this->forTenant($tenant)
            ->postJson('/api/v1/tasks', [
                'name' => 'M 任务',
                'skill_id' => self::CONTENT_SKILL,
                'project_id' => $project->id,
            ])
            ->assertStatus(403);
    }

    public function test_cross_tenant_task_returns_404(): void
    {
        [$tenantA, $adminA] = $this->makeTenantUser('tenant-a', 'admin');
        $projectA = $this->makeProject($tenantA, $adminA);

        [$tenantB, $adminB] = $this->makeTenantUser('tenant-b', 'admin');

        $task = Task::create([
            'tenant_id' => $tenantA->id,
            'project_id' => $projectA->id,
            'user_id' => $adminA->id,
            'name' => 'A 的任务',
            'skill_id' => self::CONTENT_SKILL,
            'status' => Task::STATUS_ACTIVE,
            'next_run_at' => Task::computeNextRunAtFor('daily'),
        ]);

        // B 访问 A 的任务 → 404（租户隔离）
        Sanctum::actingAs($adminB, ['*']);
        $this->forTenant($tenantB)
            ->getJson("/api/v1/tasks/{$task->id}")
            ->assertStatus(404);
    }

    public function test_creating_task_with_foreign_project_is_rejected(): void
    {
        [$tenantA, $adminA] = $this->makeTenantUser('tenant-a', 'admin');
        $projectA = $this->makeProject($tenantA, $adminA);

        [$tenantB, $adminB] = $this->makeTenantUser('tenant-b', 'admin');

        Sanctum::actingAs($adminB, ['*']);
        $this->forTenant($tenantB)
            ->postJson('/api/v1/tasks', [
                'name' => '越权任务',
                'skill_id' => self::CONTENT_SKILL,
                'project_id' => $projectA->id, // 属于 A
            ])
            ->assertStatus(422);
    }

    public function test_run_pause_resume_and_runs_list(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');
        $project = $this->makeProject($tenant, $admin);

        $task = Task::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'user_id' => $admin->id,
            'name' => '定时科普',
            'skill_id' => self::CONTENT_SKILL,
            'status' => Task::STATUS_ACTIVE,
            'next_run_at' => Task::computeNextRunAtFor('daily'),
        ]);

        Sanctum::actingAs($admin, ['*']);

        // 立即运行 → 入队
        $this->forTenant($tenant)
            ->postJson("/api/v1/tasks/{$task->id}/run")
            ->assertStatus(200);
        Queue::assertPushed(RunTaskJob::class, function (RunTaskJob $job) use ($task) {
            return $job->taskId === $task->id && $job->trigger === TaskRun::TRIGGER_MANUAL;
        });

        // 暂停
        Sanctum::actingAs($admin, ['*']);
        $this->forTenant($tenant)
            ->postJson("/api/v1/tasks/{$task->id}/pause")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'paused');

        $task->refresh();
        $this->assertSame(Task::STATUS_PAUSED, $task->status);

        // 恢复
        Sanctum::actingAs($admin, ['*']);
        $this->forTenant($tenant)
            ->postJson("/api/v1/tasks/{$task->id}/resume")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'active');

        $task->refresh();
        $this->assertSame(Task::STATUS_ACTIVE, $task->status);
        $this->assertNotNull($task->next_run_at);

        // 运行记录列表（此时尚无实际运行，空列表）
        Sanctum::actingAs($admin, ['*']);
        $this->forTenant($tenant)
            ->getJson("/api/v1/tasks/{$task->id}/runs")
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_delete_task_soft_deletes(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');
        $project = $this->makeProject($tenant, $admin);

        $task = Task::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'user_id' => $admin->id,
            'name' => '待删任务',
            'skill_id' => self::CONTENT_SKILL,
            'status' => Task::STATUS_ACTIVE,
            'next_run_at' => Task::computeNextRunAtFor('daily'),
        ]);

        Sanctum::actingAs($admin, ['*']);
        $this->forTenant($tenant)
            ->deleteJson("/api/v1/tasks/{$task->id}")
            ->assertStatus(200);

        $this->assertSoftDeleted('tasks', ['id' => $task->id]);
    }
}
