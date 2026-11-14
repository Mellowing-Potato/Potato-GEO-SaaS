<?php

namespace Tests\Feature;

use App\Jobs\RunTaskJob;
use App\Models\Article;
use App\Models\ArticlePublication;
use App\Models\ModelCallLog;
use App\Models\Project;
use App\Models\PublishingChannel;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Distribution\TaskDistributionChannelSelector;
use App\Services\Contracts\AiProvider;
use App\Services\Contracts\SkillAgentRunner;
use App\Services\DTOs\SkillBrief;
use App\Services\DTOs\SkillExecutionResult;
use App\Services\Enums\ExecutionStatus;
use App\Services\Workflow\TaskOrchestrationService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Support\ValidExplainerAiProvider;

/**
 * 阶段 1.4 编排服务测试（服务级，直接驱动 TaskOrchestrationService::run）
 *
 * 关注点：技能执行 → 生成文章 → 自动发布/分发开关 → 运行记录 → 幂等。
 */
class TaskOrchestrationTest extends TestCase
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


    private function makeTask(Tenant $tenant, Project $project, User $user, array $overrides = []): Task
    {
        return Task::create(array_merge([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'name' => '每日科普',
            'skill_id' => self::CONTENT_SKILL,
            'cadence' => Task::CADENCE_DAILY,
            'timezone' => Task::DEFAULT_TIMEZONE,
            'auto_publish' => false,
            'auto_distribute' => true,
            'status' => Task::STATUS_ACTIVE,
            'next_run_at' => Task::computeNextRunAtFor(Task::CADENCE_DAILY, Task::DEFAULT_TIMEZONE),
        ], $overrides));
    }

    public function test_run_creates_draft_and_succeeded_run(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');
        $project = $this->makeProject($tenant, $admin);
        $task = $this->makeTask($tenant, $project, $admin);

        TenantContext::set($tenant);
        $service = $this->app->make(TaskOrchestrationService::class);

        $run = $service->run($task, TaskRun::TRIGGER_MANUAL);

        TenantContext::forget();

        $this->assertSame(TaskRun::STATUS_SUCCEEDED, $run->status, (string) $run->error_message);
        $this->assertNotNull($run->article_id);

        $article = Article::find($run->article_id);
        $this->assertNotNull($article);
        // 默认不自动发布 → 草稿
        $this->assertSame('editing', $article->status);

        // 运行记录存在且属于本租户
        $this->assertDatabaseHas('task_runs', [
            'id' => $run->id,
            'task_id' => $task->id,
            'tenant_id' => $tenant->id,
            'trigger' => TaskRun::TRIGGER_MANUAL,
            'status' => TaskRun::STATUS_SUCCEEDED,
        ]);

        // next_run_at 已推进
        $task->refresh();
        $this->assertNotNull($task->last_run_at);
        $this->assertTrue($task->next_run_at->greaterThan(now()->addHours(23)));
    }

    public function test_automation_delivers_and_publishes_without_billing_configuration(): void
    {

        [$tenant, $admin] = $this->makeTenantUser('task-wallet-pending', 'admin');
        $project = $this->makeProject($tenant, $admin);
        $task = $this->makeTask($tenant, $project, $admin, [
            'auto_publish' => true, 'auto_distribute' => false,
        ]);
        $runner = $this->createMock(SkillAgentRunner::class);
        $runner->expects($this->once())->method('execute')
            ->willReturnCallback(function (SkillBrief $brief) use ($tenant, $admin, $project): SkillExecutionResult {
                ModelCallLog::query()->create([
                    'tenant_id' => $tenant->id, 'project_id' => $project->id,
                    'user_id' => $admin->id, 'skill_execution_id' => $brief->executionId,
                    'provider' => 'gateway', 'model' => 'text', 'status' => 'completed',
                    'prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30,
                    'duration_seconds' => 1.0, 'metadata' => ['points_deduction_status' => 'pending'],
                    'gateway_request_id' => 'task-wallet-pending',
                ]);

                return new SkillExecutionResult(
                    executionId: $brief->executionId, skillId: $brief->skillId, success: true,
                    intermediateData: [
                        'raw_output' => 'PRIVATE_TASK_RESULT',
                        'billing_pending_article_content' => '# PRIVATE_TASK_ARTICLE',
                    ],
                    qualityReport: ['passed' => true], tokenUsage: 30,
                );
            });
        $this->app->instance(SkillAgentRunner::class, $runner);

        TenantContext::set($tenant);
        $run = app(TaskOrchestrationService::class)->run($task, TaskRun::TRIGGER_MANUAL, 'wallet-pending-queue-token');
        $this->assertSame(TaskRun::STATUS_SUCCEEDED, $run->status);
        app(TaskOrchestrationService::class)->failQueuedRun($tenant->id, $task->id, 'wallet-pending-queue-token', new \RuntimeException('late worker failure'));
        $this->assertSame(TaskRun::STATUS_SUCCEEDED, $run->fresh()->status);
        $this->assertSame('published', Article::query()->findOrFail($run->fresh()->article_id)->status);
        $this->assertSame('# PRIVATE_TASK_ARTICLE', Article::query()->findOrFail($run->fresh()->article_id)->body_markdown);
        TenantContext::forget();
    }

    public function test_failed_automation_closes_without_article_or_wallet_check(): void
    {

        [$tenant, $admin] = $this->makeTenantUser('task-failed-wallet', 'admin');
        $project = $this->makeProject($tenant, $admin);
        $task = $this->makeTask($tenant, $project, $admin);
        $runner = $this->createMock(SkillAgentRunner::class);
        $runner->method('execute')->willReturnCallback(function (SkillBrief $brief) use ($tenant, $admin, $project): SkillExecutionResult {
            ModelCallLog::query()->create([
                'tenant_id' => $tenant->id, 'project_id' => $project->id,
                'user_id' => $admin->id, 'skill_execution_id' => $brief->executionId,
                'provider' => 'gateway', 'model' => 'text', 'status' => 'completed',
                'prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30,
                'duration_seconds' => 1.0, 'metadata' => ['points_deduction_status' => 'pending'],
                'gateway_request_id' => 'task-failed-wallet-pending',
            ]);

            return new SkillExecutionResult(
                executionId: $brief->executionId, skillId: $brief->skillId, success: false,
                intermediateData: ['raw_output' => 'PRIVATE_FAILED_TASK_PARTIAL'],
                tokenUsage: 30, errorMessage: 'Quality gate rejected result.',
            );
        });
        $this->app->instance(SkillAgentRunner::class, $runner);

        TenantContext::set($tenant);
        $run = app(TaskOrchestrationService::class)->run($task);
        $this->assertSame(TaskRun::STATUS_FAILED, $run->fresh()->status);
        $this->assertNull($run->fresh()->article_id);
        $this->assertSame(0, Article::query()->count());
        TenantContext::forget();
    }

    public function test_auto_publish_publishes_but_respects_auto_distribute_off(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');
        $project = $this->makeProject($tenant, $admin);
        $task = $this->makeTask($tenant, $project, $admin, [
            'auto_publish' => true,
            'auto_distribute' => false,
        ]);

        TenantContext::set($tenant);
        $service = $this->app->make(TaskOrchestrationService::class);
        $run = $service->run($task);
        TenantContext::forget();

        $article = Article::find($run->article_id);
        $this->assertSame('published', $article->fresh()->status);
        // 关闭自动分发 → 不应产生任何分发记录
        $this->assertSame(0, ArticlePublication::query()->where('article_id', $article->id)->count());
        $this->assertFalse((bool) $run->metadata['auto_distributed']);
    }

    public function test_auto_publish_with_auto_distribute_enqueues_publications_idempotently(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');
        $project = $this->makeProject($tenant, $admin);

        // 绑定一个分发渠道，使 auto_distribute=true 实际产生分发记录
        $channel = PublishingChannel::create([
            'tenant_id' => $tenant->id,
            'name' => '测试站',
            'type' => PublishingChannel::TYPE_HTTP_WEBHOOK,
            'endpoint_url' => 'https://example.test/hook',
            'is_active' => true,
        ]);
        TenantContext::set($tenant);
        $this->app->make(TaskDistributionChannelSelector::class)
            ->bindChannels($project->id, [$channel->id]);
        TenantContext::forget();

        $task = $this->makeTask($tenant, $project, $admin, [
            'auto_publish' => true,
            'auto_distribute' => true,
        ]);

        TenantContext::set($tenant);
        $service = $this->app->make(TaskOrchestrationService::class);
        $run = $service->run($task);
        TenantContext::forget();

        $article = Article::find($run->article_id);
        $this->assertSame('published', $article->fresh()->status);
        $this->assertSame(1, ArticlePublication::query()->where('article_id', $article->id)->count());
        $this->assertTrue((bool) $run->metadata['auto_distributed']);

        // 对同一篇文章再次调用分发应幂等（已发布渠道不再入队）
        $this->app->make(\App\Services\Distribution\DistributionOrchestrator::class)
            ->enqueueForArticle($article->fresh());
        $this->assertSame(1, ArticlePublication::query()->where('article_id', $article->id)->count());
    }

    public function test_failed_skill_execution_records_failed_run(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');
        $project = $this->makeProject($tenant, $admin);
        // 使用一个不存在的 skill，制造执行失败路径
        $task = $this->makeTask($tenant, $project, $admin, [
            'skill_id' => 'yao-geo-does-not-exist',
        ]);

        TenantContext::set($tenant);
        $service = $this->app->make(TaskOrchestrationService::class);
        $run = $service->run($task);
        TenantContext::forget();

        $this->assertSame(TaskRun::STATUS_FAILED, $run->status);
        $this->assertNotNull($run->error_message);
        // 失败时仍推进 next_run_at（进入下一周期）
        $task->refresh();
        $this->assertNotNull($task->next_run_at);
    }

    public function test_run_task_job_restores_tenant_and_invokes_service(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');
        $project = $this->makeProject($tenant, $admin);
        $task = $this->makeTask($tenant, $project, $admin);

        // 退出当前租户上下文，模拟 Worker 无请求生命周期
        TenantContext::forget();

        $job = new RunTaskJob($task->tenant_id, $task->id, TaskRun::TRIGGER_SCHEDULE);
        $job->handle($this->app->make(TaskOrchestrationService::class));

        $this->assertDatabaseHas('task_runs', [
            'task_id' => $task->id,
            'trigger' => TaskRun::TRIGGER_SCHEDULE,
            'status' => TaskRun::STATUS_SUCCEEDED,
        ]);

        // Worker 执行后应清理租户上下文
        $this->assertFalse(TenantContext::has());
    }

    /**
     * X1 计费堵漏回归：自动化任务编排必须建立 SkillExecution 并回写 executionId，
     * 否则 runner 不记账、model_call_logs 零记录、积分零扣减。
     */
    public function test_x1_orchestrated_task_records_skill_execution_and_call_log(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-x1', 'admin');
        $project = $this->makeProject($tenant, $admin);
        $task = $this->makeTask($tenant, $project, $admin);

        TenantContext::set($tenant);
        $service = $this->app->make(TaskOrchestrationService::class);
        $run = $service->run($task);
        TenantContext::forget();

        // run 元数据必须带 execution_id（此前漏传 → 零扣费）
        $this->assertArrayHasKey('execution_id', $run->metadata);
        $executionId = $run->metadata['execution_id'];
        $this->assertNotNull($executionId);

        // SkillExecution 已建立，execution_mode=task，终态与 run 一致
        $this->assertDatabaseHas('skill_executions', [
            'id' => $executionId,
            'execution_mode' => 'task',
            'status' => $run->status === TaskRun::STATUS_SUCCEEDED
                ? ExecutionStatus::Completed->value
                : ExecutionStatus::Failed->value,
        ]);

        // X1①：model_call_logs 至少 1 行且绑定到本次 execution
        $this->assertGreaterThanOrEqual(
            1,
            ModelCallLog::query()->where('skill_execution_id', $executionId)->count()
        );

        $this->assertIsInt($run->metadata['token_usage']);
    }
}
