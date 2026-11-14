<?php

namespace Tests\Feature;

use App\Events\JobProgressUpdated;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\SkillExecutionRelay;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Contracts\SkillAgentRunner;
use App\Services\DTOs\SkillExecutionResult;
use App\Services\Workflow\ProgressBroadcastService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class RealtimeProgressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=',
            'app.tenant_domain' => 'geo.example.test',
        ]);
        $this->app->forgetInstance('encrypter');
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        Mockery::close();
        parent::tearDown();
    }

    public function test_broadcast_service_emits_progress_event(): void
    {
        $this->fakeEvents();

        $svc = $this->app->make(ProgressBroadcastService::class);
        $svc->skillProgress(42, 'running', 60, ['skill_name' => 'x']);

        $this->assertEventDispatched('skill.42', 'running', 60);
    }

    public function test_progress_endpoint_returns_snapshot_and_emits_event(): void
    {
        [$tenant, $admin] = $this->tenantAndUser('rt-a', 'admin');
        $execution = $this->execution($tenant, $admin, 'completed');

        $this->fakeEvents();
        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->getJson("/api/v1/executions/{$execution->id}/progress")
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('percent', 100)
            ->assertJsonPath('topic', "skill.{$execution->id}")
            ->assertJsonPath('is_terminal', true);

        $this->assertEventDispatched("skill.{$execution->id}", 'completed', 100);
    }

    public function test_task_orchestration_emits_start_and_finish_events(): void
    {
        [$tenant, $admin] = $this->tenantAndUser('rt-task', 'admin');
        $project = $this->project($tenant, $admin);

        $task = Task::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'user_id' => $admin->id,
            'name' => 'T',
            'skill_id' => 'test.skill',
            'cadence' => 'manual',
            'status' => 'active',
            'auto_publish' => false,
            'auto_distribute' => false,
            'input_data' => [],
        ]);

        // 用 mock runner 替换，避免真实调用 AI
        $this->app->bind(SkillAgentRunner::class, function () {
            return Mockery::mock(SkillAgentRunner::class)
                ->shouldReceive('execute')
                ->andReturn(new SkillExecutionResult(
                    executionId: 0,
                    skillId: 'test.skill',
                    success: true,
                    intermediateData: [],
                ))
                ->getMock();
        });

        $this->fakeEvents();

        /** @var \App\Services\Workflow\TaskOrchestrationService $orchestrator */
        $orchestrator = $this->app->make(\App\Services\Workflow\TaskOrchestrationService::class);
        $orchestrator->run($task);

        $this->assertEventDispatched("task.{$task->id}", 'running', 5);
        $this->assertEventDispatched("task.{$task->id}", 'succeeded', 100);
    }

    /**
     * 长报告是分多次写出来的，一份要十来分钟。界面上只有一个不动的「执行中」时，
     * 客户分不清是在写还是已经卡死 —— 状态接口必须把「写到第几步」说出来。
     */
    public function test_status_reports_relay_progress_while_a_long_report_is_written(): void
    {
        [$tenant, $admin] = $this->tenantAndUser('rt-relay', 'admin');
        $execution = $this->execution($tenant, $admin, 'running');

        SkillExecutionRelay::create([
            'skill_execution_id' => $execution->id,
            'tenant_id' => $tenant->id,
            'total_steps' => 7,
            'completed_steps' => 3,
            'state' => ['pending' => []],
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->getJson("/api/v1/executions/{$execution->id}/status")
            ->assertOk()
            ->assertJsonPath('relay.completed_steps', 3)
            ->assertJsonPath('relay.total_steps', 7);
    }

    /** 跑完了就别再报进度：「已完成」旁边挂个「进度 3/7」只会让人以为还没写完。 */
    public function test_status_stops_reporting_progress_once_the_run_is_over(): void
    {
        [$tenant, $admin] = $this->tenantAndUser('rt-relay-done', 'admin');
        $execution = $this->execution($tenant, $admin, 'completed');

        SkillExecutionRelay::create([
            'skill_execution_id' => $execution->id,
            'tenant_id' => $tenant->id,
            'total_steps' => 7,
            'completed_steps' => 3,
            'state' => ['pending' => []],
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->getJson("/api/v1/executions/{$execution->id}/status")
            ->assertOk()
            ->assertJsonPath('relay', null);
    }

    // ─── helpers ───

    private function fakeEvents(): void
    {
        Event::fake([JobProgressUpdated::class]);
    }

    private function assertEventDispatched(string $topic, string $status, int $percent): void
    {
        Event::assertDispatched(JobProgressUpdated::class, function (JobProgressUpdated $event) use ($topic, $status, $percent) {
            return $event->topic === $topic
                && ($event->payload['status'] ?? null) === $status
                && ($event->payload['percent'] ?? null) === $percent;
        });
    }

    private function tenantAndUser(string $subdomain, string $role): array
    {
        $tenant = Tenant::create([
            'subdomain' => $subdomain,
            'name' => $subdomain,
            'plan' => 'points',
            'status' => 'active',
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,

            'email' => $subdomain.'@example.com',
            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain),
            'role' => $role,
        ]);

        return [$tenant, $user];
    }

    private function project(Tenant $tenant, User $owner): Project
    {
        TenantContext::set($tenant);
        try {
            return Project::query()->create([
                'owner_id' => $owner->id,
                'name' => 'Proj '.$tenant->subdomain,
                'brand_name' => $tenant->subdomain,
                'brand_url' => 'https://'.$tenant->subdomain.'.example.test',
                'settings' => [],
            ]);
        } finally {
            TenantContext::forget();
        }
    }

    private function execution(Tenant $tenant, User $author, string $status): SkillExecution
    {
        TenantContext::set($tenant);
        try {
            $project = Project::query()->create([
                'owner_id' => $author->id,
                'name' => 'Proj '.$tenant->subdomain,
                'brand_name' => $tenant->subdomain,
                'brand_url' => 'https://'.$tenant->subdomain.'.example.test',
                'settings' => [],
            ]);

            return SkillExecution::query()->create([
                'tenant_id' => $tenant->id,
                'project_id' => $project->id,
                'user_id' => $author->id,
                'skill_id' => 'test.skill',
                'skill_name' => 'Test Skill',
                'category' => 'general',
                'status' => $status,
                'token_usage' => 0,
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
