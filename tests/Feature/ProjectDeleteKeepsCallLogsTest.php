<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ModelCallLog;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\SkillExecution;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Projects\ProjectTrashService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 删项目不能连调用流水一起删（老板 2026-09-04 拍板）。
 *
 * 原话：「不能因为客户删项目就连调用记录都查不到，这个是跟钱有关系的，必须有据可查。」
 *
 * 这组测试守三件事，缺一条「有据可查」就不成立：
 *  1. 流水行还在（数据库级联已在迁移 2026_09_04_000001 拆掉）
 *  2. 用量明细还查得到，金额没少
 *  3. 项目名和技能名回退到删除前的快照 —— 否则行还在、界面上却是空的
 *
 * 反过来也要守：文章和分析报告**跟着项目一起走** —— 但"什么时候走"在 2026-09-21 之后变了：
 * 用户口径「客户删项目之后可以自动保留 7 天，7 天之后无法恢复」→ 删除 = 进垃圾桶（软删、可恢复），
 * 内容**原地保留**；期满 geo:projects-purge（或 ProjectTrashService::purge）才真删。
 * 所以这里两条都断言：进桶时内容还在（可恢复），清理后内容真没了（不可恢复）、流水照留。
 */
class ProjectDeleteKeepsCallLogsTest extends TestCase
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

    public function test_deleting_project_keeps_call_logs_with_snapshotted_attribution(): void
    {
        [$tenant, $admin] = $this->tenantAdmin('keep-logs');
        Sanctum::actingAs($admin, ['*']);

        $project = $this->project($tenant, $admin, '要被删掉的项目');
        $execution = $this->execution($tenant, $project, $admin, 'geo_explainer', '解释型文章');
        $article = Article::query()->create([
            'project_id' => $project->id,
            'title' => '一篇会被删掉的文章',
            'content' => '正文',
            'status' => 'draft',
        ]);
        $log = $this->callLog($tenant, $project, $admin, $execution, 1234);

        $this->forTenant($tenant)
            ->deleteJson("/api/v1/projects/{$project->id}")
            ->assertNoContent();

        // ── 1. 删除 = 进垃圾桶：项目软删，文章与分析报告**原地保留**（7 天内可恢复）
        //    ⚠️ Project 用的是 SoftDeletes（行还在、只是 deleted_at 有值），
        //       Article / SkillExecution / ModelCallLog 都没有软删，只能靠"先不动它"来保命。
        $this->assertSoftDeleted('projects', ['id' => $project->id]);
        $this->assertDatabaseHas('articles', ['id' => $article->id]);
        $this->assertDatabaseHas('skill_executions', ['id' => $execution->id]);

        // ── 2. 流水行必须还在（这条一挂就说明数据库级联又回来了）
        $fresh = ModelCallLog::withoutGlobalScopes()->find($log->id);
        $this->assertNotNull($fresh, '删项目把调用流水也删了 —— 跟钱有关的记录必须留下');
        $this->assertSame($project->id, (int) $fresh->project_id, 'project_id 被抹了，查不出这笔钱花在哪个项目');
        $this->assertSame(1234, (int) data_get($fresh->metadata, 'points_deducted'), '扣费金额被改了');

        // ── 3. 归属快照：项目名和技能名都要留下
        $this->assertSame('要被删掉的项目', data_get($fresh->metadata, 'project_name_snapshot'));
        $this->assertSame('解释型文章', data_get($fresh->metadata, 'skill_name_snapshot'));
        $this->assertNotNull(data_get($fresh->metadata, 'project_deleted_at'));

        // ── 4. 期满清理（不可恢复的那一步）：内容才真删，流水依然在
        $this->travel(8)->days();
        app(ProjectTrashService::class)->purge($project->fresh());

        $this->assertDatabaseMissing('articles', ['id' => $article->id]);
        $this->assertDatabaseMissing('skill_executions', ['id' => $execution->id]);
        $this->assertDatabaseMissing('projects', ['id' => $project->id]);

        $afterPurge = ModelCallLog::withoutGlobalScopes()->find($log->id);
        $this->assertNotNull($afterPurge, '清理垃圾桶把调用流水也删了 —— 跟钱有关的记录必须留下');
        $this->assertSame(1234, (int) data_get($afterPurge->metadata, 'points_deducted'), '扣费金额被改了');
        $this->assertSame('要被删掉的项目', data_get($afterPurge->metadata, 'project_name_snapshot'));
    }

    public function test_usage_detail_still_shows_deleted_project_by_snapshot(): void
    {
        [$tenant, $admin] = $this->tenantAdmin('usage-snapshot');
        Sanctum::actingAs($admin, ['*']);

        $keep = $this->project($tenant, $admin, '留着的项目');
        $doomed = $this->project($tenant, $admin, '删掉的项目');
        $keepExec = $this->execution($tenant, $keep, $admin, 'geo_intent', '意图挖掘');
        $doomedExec = $this->execution($tenant, $doomed, $admin, 'geo_explainer', '解释型文章');
        $this->callLog($tenant, $keep, $admin, $keepExec, 300);
        $this->callLog($tenant, $doomed, $admin, $doomedExec, 700);

        $before = $this->forTenant($tenant)->getJson('/api/v1/usage?granularity=all')->assertOk();
        $this->assertSame(600, $before->json('summary.total_tokens'));
        $this->assertSame(2, $before->json('summary.total_calls'));

        $this->forTenant($tenant)
            ->deleteJson("/api/v1/projects/{$doomed->id}")
            ->assertNoContent();

        $after = $this->forTenant($tenant)->getJson('/api/v1/usage?granularity=all')->assertOk();

        // 删除项目后，tokens 和调用条数仍完整保留。
        $this->assertSame(600, $after->json('summary.total_tokens'), '删项目之后本期消耗变少了');
        $this->assertSame(2, $after->json('summary.total_calls'), '删项目之后调用条数变少了');

        $records = collect($after->json('records.data') ?? $after->json('records'));
        $orphan = $records->firstWhere('project_id', $doomed->id);
        $this->assertNotNull($orphan, '用量明细里找不到已删项目的那条流水');
        $this->assertSame('删掉的项目', $orphan['project_name'], '项目名没回退到快照，界面上会是空的');
        $this->assertSame('解释型文章', $orphan['skill_name'], '技能名没回退到快照');
        $this->assertTrue($orphan['project_deleted'], '没有标出这条流水的项目已被删除');

        // 按天分组也要用快照，否则删过项目的那几天会整批并进「其他」
        // ⚠️ 响应里的键是 daily_usage 不是 daily。
        $bySkill = collect($after->json('daily_usage'))->flatMap(fn (array $day): array => array_keys($day['by_skill']));
        $this->assertTrue($bySkill->contains('解释型文章'), '按天分组把已删项目的消耗并进了「其他」');
    }

    // ───────────────────────── 造数据 ─────────────────────────

    private function tenantAdmin(string $subdomain): array
    {
        $tenant = Tenant::query()->create([
            'subdomain' => $subdomain,
            'name' => strtoupper($subdomain),
            'plan' => 'basic',
            'status' => 'active',
        ]);
        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,

            'email' => $subdomain.'@example.com',
            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain),
            'phone_verified_at' => now(),
            'role' => 'admin',
        ]);

        return [$tenant, $admin];
    }

    private function project(Tenant $tenant, User $owner, string $name): Project
    {
        // ⚠️ tenant_id 不在各模型的 fillable 里 —— BelongsToTenant 靠 TenantContext 自动填。
        //    造数据前不设上下文，插入时 tenant_id 会是 NULL。
        TenantContext::set($tenant);

        $project = Project::query()->create([
            'owner_id' => $owner->id,
            'name' => $name,
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

        return $project;
    }

    private function execution(Tenant $tenant, Project $project, User $user, string $skillId, string $skillName): SkillExecution
    {
        TenantContext::set($tenant);

        return SkillExecution::query()->create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'skill_id' => $skillId,
            'skill_name' => $skillName,
            'category' => 'content',
            'stage_index' => 2,
            'status' => 'completed',
        ]);
    }

    private function callLog(Tenant $tenant, Project $project, User $user, SkillExecution $execution, int $points): ModelCallLog
    {
        TenantContext::set($tenant);

        return ModelCallLog::query()->create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'skill_execution_id' => $execution->id,
            'provider' => 'dashscope',
            'model' => 'vision',
            'status' => 'completed',
            'prompt_tokens' => 100,
            'completion_tokens' => 200,
            'total_tokens' => 300,
            'duration_seconds' => 1.5,
            'metadata' => ['points_deducted' => $points, 'points_deduction_status' => 'charged'],
        ]);
    }

    private function forTenant(Tenant $tenant): self
    {
        return $this
            ->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }
}
