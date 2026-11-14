<?php

namespace Tests\Feature;

use App\Jobs\RunTaskJob;
use App\Models\ModelCallLog;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Workflow\TaskOrchestrationService;
use App\Services\Contracts\AiProvider;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Support\ValidExplainerAiProvider;

/**
 * 自动化任务「技能动态输入」端到端（计划 §5 验收标准第三条）。
 *
 * 背景：正式工作台的新建任务表单此前只提交 name / skill_id / project_id / cadence /
 * query / auto_publish / auto_distribute —— 技能的必填输入（主题、关键词、地区…）根本
 * 传不进去，任务到点只能拿默认值跑。2026-09-21 工作台按技能 brief_fields 生成输入项
 * 并随 `input_data` 提交；本测试证明这条链真的通：
 *
 *   创建（带 input_data）→ 触发立即运行 → 任务编排建立 SkillExecution
 *   → 简报字段进入 SkillExecution.input_data（即交给 runner 的那份）
 *   → 产出文章与用量记录
 *
 * 守四件事：
 *   1. `input_data` 原样落库并能从详情接口读回（含后端不认识的键，R28：未知字段不丢弃）；
 *   2. 立即运行入口（POST /tasks/{id}/run）真的把 RunTaskJob 入队；
 *   3. 编排把任务输入当作简报字段交给 runner（不是只存库不用）；
 *   4. 老表单（不带 input_data）仍然能创建并跑通 —— 向后兼容。
 */
class AutomationDynamicInputTest extends TestCase
{
    use RefreshDatabase;

    private const CONTENT_SKILL = 'yao-geo-explainer-builder';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.tenant_domain' => 'geo.example.test']);
        Queue::fake();
        $this->app->bind(AiProvider::class, fn () => new ValidExplainerAiProvider);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();

        parent::tearDown();
    }

    public function test_dynamic_input_round_trips_and_reaches_the_execution_brief(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-input', 'admin');
        $project = $this->makeProject($tenant, $admin);

        // 与工作台新表单提交的形状一致：文本字段是字符串、列表字段是数组、数字字段是数字，
        // 另加一个后端不认识的键，用来验证"未知字段也保留"。
        $inputData = [
            'topic' => '种植牙集采后的价格变化',
            'target_keywords' => ['种植牙价格', '集采政策'],
            'region' => '成都',
            'article_length' => 1200,
            'custom_note_from_skill_brief' => '别用绝对化用语',
        ];

        Sanctum::actingAs($admin, ['*']);
        $this->forTenant($tenant)
            ->postJson('/api/v1/tasks', [
                'name' => '每周种植牙科普',
                'skill_id' => self::CONTENT_SKILL,
                'project_id' => $project->id,
                'cadence' => 'weekly',
                'auto_publish' => false,
                'auto_distribute' => false,
                'input_data' => $inputData,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.input_data.topic', '种植牙集采后的价格变化')
            ->assertJsonPath('data.input_data.target_keywords.1', '集采政策')
            ->assertJsonPath('data.input_data.article_length', 1200);

        $task = Task::query()->where('tenant_id', $tenant->id)->firstOrFail();
        // ① 落库不丢字段（含后端不认识的键）
        $this->assertSame($inputData, $task->input_data);

        // 详情接口也要能读回（前端刷新后不会看到"空表单"）
        Sanctum::actingAs($admin, ['*']);
        $this->forTenant($tenant)
            ->getJson("/api/v1/tasks/{$task->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.input_data.custom_note_from_skill_brief', '别用绝对化用语');

        // ③ 真的交给 runner：SkillExecution.input_data 就是 SkillBrief::auditableInputData()
        $run = $this->app->make(TaskOrchestrationService::class)->run($task, TaskRun::TRIGGER_MANUAL);

        $executionId = $run->metadata['execution_id'] ?? null;
        $this->assertNotNull($executionId, '自动化编排必须建立 SkillExecution（否则零记账零扣费）');

        $execution = SkillExecution::withoutGlobalScopes()->findOrFail($executionId);
        $this->assertSame($inputData['topic'], $execution->input_data['topic'] ?? null);
        $this->assertSame($inputData['target_keywords'], $execution->input_data['target_keywords'] ?? null);
        $this->assertSame($inputData['region'], $execution->input_data['region'] ?? null);
        $this->assertSame(
            $inputData['custom_note_from_skill_brief'],
            $execution->input_data['custom_note_from_skill_brief'] ?? null,
            'R28：简报里后端不认识的字段也不能被丢掉',
        );

        // ④ 产物 + 用量
        $this->assertSame(TaskRun::STATUS_SUCCEEDED, $run->status, (string) $run->error_message);
        $this->assertNotNull($run->article_id, '任务跑通必须产出文章');
        $this->assertGreaterThanOrEqual(
            1,
            ModelCallLog::withoutGlobalScopes()->where('skill_execution_id', $executionId)->count(),
            '自动化任务必须留下模型调用记录（用量可对账）',
        );
    }

    public function test_run_now_endpoint_enqueues_the_task_run(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-run-now', 'admin');
        $project = $this->makeProject($tenant, $admin);
        $task = $this->makeTask($tenant, $project, $admin, [
            'input_data' => ['topic' => '立即运行测试'],
        ]);

        Sanctum::actingAs($admin, ['*']);
        $this->forTenant($tenant)
            ->postJson("/api/v1/tasks/{$task->id}/run")
            ->assertStatus(200)
            ->assertJsonPath('message', '任务已入队执行。');

        Queue::assertPushed(RunTaskJob::class, function (RunTaskJob $job) use ($tenant, $task): bool {
            // 入队的必须是这条任务、这个租户，且触发源是 manual（"立即运行"）。
            return $job->tenantId === (int) $tenant->id
                && $job->taskId === (int) $task->id
                && $job->trigger === TaskRun::TRIGGER_MANUAL;
        });
    }

    public function test_task_without_dynamic_input_still_creates_and_runs(): void
    {
        // 老表单（不带 input_data）不能因为这次改动而坏掉：任务仍可创建、仍可跑通。
        [$tenant, $admin] = $this->makeTenantUser('tenant-legacy', 'admin');
        $project = $this->makeProject($tenant, $admin);

        Sanctum::actingAs($admin, ['*']);
        $this->forTenant($tenant)
            ->postJson('/api/v1/tasks', [
                'name' => '老表单任务',
                'skill_id' => self::CONTENT_SKILL,
                'project_id' => $project->id,
                'cadence' => 'daily',
                'query' => '写一篇种植牙科普',
            ])
            ->assertStatus(201);

        $task = Task::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertNull($task->input_data);

        $run = $this->app->make(TaskOrchestrationService::class)->run($task, TaskRun::TRIGGER_MANUAL);
        $this->assertSame(TaskRun::STATUS_SUCCEEDED, $run->status, (string) $run->error_message);
        $this->assertNotNull($run->article_id);

        // 只有 query 时，简报里补的是 supplemental_query（原有契约不变）
        $execution = SkillExecution::withoutGlobalScopes()->findOrFail($run->metadata['execution_id']);
        $this->assertSame('写一篇种植牙科普', $execution->input_data['supplemental_query'] ?? null);
    }

    // ── Helpers ──

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

            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain.$role),
            'role' => $role,
        ]);

        return [$tenant, $user];
    }

    private function makeProject(Tenant $tenant, User $owner): Project
    {
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

    private function makeTask(Tenant $tenant, Project $project, User $user, array $overrides = []): Task
    {
        return Task::create(array_merge([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'name' => '立即运行任务',
            'skill_id' => self::CONTENT_SKILL,
            'cadence' => Task::CADENCE_DAILY,
            'timezone' => Task::DEFAULT_TIMEZONE,
            'auto_publish' => false,
            'auto_distribute' => true,
            'status' => Task::STATUS_ACTIVE,
            'next_run_at' => Task::computeNextRunAtFor(Task::CADENCE_DAILY, Task::DEFAULT_TIMEZONE),
        ], $overrides));
    }

    private function forTenant(Tenant $tenant): self
    {
        return $this
            ->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }
}
