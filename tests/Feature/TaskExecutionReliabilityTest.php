<?php

namespace Tests\Feature;

use App\Jobs\RunTaskJob;
use App\Jobs\ResumeTaskEmbeddingJob;
use App\Models\Article;
use App\Models\ModelCallLog;
use App\Models\Project;
use App\Models\PublishingChannel;
use App\Models\SkillExecution;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Contracts\SkillAgentRunner;
use App\Services\Billing\PreparedEmbeddingAttempt;
use App\Services\Billing\PendingEmbeddingAttemptInspector;
use App\Services\Billing\UnresolvedEmbeddingAttempt;
use App\Services\AI\AiProviderServiceImpl;
use App\Services\Contracts\AiProvider;
use App\Services\Knowledge\KnowledgeRetrievalServiceImpl;
use App\Services\Contracts\SkillRegistry;
use App\Services\DTOs\SkillBrief;
use App\Services\DTOs\SkillDefinition;
use App\Services\DTOs\SkillExecutionResult;
use App\Services\Enums\ExecutionStatus;
use App\Services\Enums\SkillCategory;
use App\Services\Workflow\ProgressBroadcastService;
use App\Services\Workflow\TaskOrchestrationService;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** No real model, debit, rendering or publication requests are made by these probes. */
class TaskExecutionReliabilityTest extends TestCase
{
    use \Tests\Support\UsesWorkspaceModels;
    use RefreshDatabase;

    private Tenant $tenant;

    private Task $task;

    private QueueManager $realQueue;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->realQueue = Queue::getFacadeRoot();
        Queue::fake();
        $this->tenant = Tenant::create(['subdomain' => 'task-reliability', 'name' => 'Task reliability', 'plan' => 'basic', 'status' => 'active']);
        TenantContext::set($this->tenant);
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $project = Project::create(['name' => 'Task reliability', 'brand_name' => 'Test', 'owner_id' => $user->id, 'status' => 'active', 'current_stage' => 2]);
        $this->task = Task::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'user_id' => $user->id,
            'name' => 'Task reliability', 'skill_id' => 'task-test-skill', 'status' => Task::STATUS_ACTIVE,
            'cadence' => Task::CADENCE_DAILY, 'timezone' => Task::DEFAULT_TIMEZONE,
            'auto_publish' => false, 'auto_distribute' => false, 'next_run_at' => now()->subMinute(),
        ]);
        TenantContext::forget();
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        Article::$autoDistributionEnabled = true;
        parent::tearDown();
    }

    private function service(callable $execute, int $calls = 1): TaskOrchestrationService
    {
        $runner = Mockery::mock(SkillAgentRunner::class);
        $runner->shouldReceive('execute')->times($calls)->andReturnUsing($execute);
        $registry = Mockery::mock(SkillRegistry::class);
        $registry->shouldReceive('find')->andReturn(new SkillDefinition(
            id: 'task-test-skill', name: 'Test skill', category: SkillCategory::GeoContentProduction,
            maturity: 'stable', requiresWeb: false, stageIndex: 2, description: 'Isolated fake runner',
        ));

        return new TaskOrchestrationService($runner, new ProgressBroadcastService, $registry);
    }

    private function fakeResult(SkillBrief $brief, bool $success = true, int|float $points = 7): SkillExecutionResult
    {
        // A fake runner stores one durable article and one already-settled call log.
        // No gateway is called; the orchestration must report, never repeat, this debit.
        $article = Article::create([
            'project_id' => $brief->projectId, 'user_id' => $brief->userId, 'title' => 'Stored result',
            'body_markdown' => 'A durable test draft.', 'status' => 'editing',
        ]);
        ModelCallLog::create([
            'project_id' => $brief->projectId, 'user_id' => $brief->userId,
            'skill_execution_id' => $brief->executionId, 'provider' => 'test', 'model' => 'test',
            'status' => 'success', 'prompt_tokens' => 2, 'completion_tokens' => 3, 'total_tokens' => 5,
            'metadata' => ['points_deducted' => $points],
        ]);

        return new SkillExecutionResult(
            executionId: $brief->executionId, skillId: $brief->skillId, success: $success,
            outputPaths: ['markdown' => 'test/report.md'],
            intermediateData: ['article_id' => $article->id, 'raw_output' => 'Already generated text', 'structured' => ['sections' => [['title' => 'One']]], 'pipeline_mode' => 'full'],
            qualityReport: ['passed' => $success], durationSeconds: 3, llmCallCount: 1, tokenUsage: 5,
            errorMessage: $success ? null : 'Incomplete report',
        );
    }

    public function test_default_trigger_is_schedule(): void
    {
        $job = new RunTaskJob($this->tenant->id, $this->task->id);
        $this->assertSame(TaskRun::TRIGGER_SCHEDULE, $job->trigger);
        $this->assertTrue($job->failOnTimeout);
    }


    public function test_task_execution_persists_the_supplemental_query_used_by_the_runner(): void
    {
        TenantContext::set($this->tenant);
        $this->task->update([
            'input_data' => ['topic' => '四川 GEO 服务'],
            'query' => '企业位于示例，请按当地场景说明。',
        ]);
        TenantContext::forget();

        $service = $this->service(fn (SkillBrief $brief) => $this->fakeResult($brief));
        (new RunTaskJob($this->tenant->id, $this->task->id, TaskRun::TRIGGER_MANUAL))->handle($service);

        $execution = SkillExecution::firstOrFail();
        $this->assertSame('四川 GEO 服务', $execution->input_data['topic'] ?? null);
        $this->assertSame('企业位于示例，请按当地场景说明。', $execution->input_data['supplemental_query'] ?? null);
    }

    public function test_original_task_run_resumes_with_recorded_vector_once(): void
    {
        TenantContext::set($this->tenant);
        $query = 'original task RAG query';
        $execution = SkillExecution::create([
            'project_id' => $this->task->project_id, 'user_id' => $this->task->user_id,
            'skill_id' => $this->task->skill_id, 'skill_name' => 'Test skill',
            'category' => 'geo-content-production', 'status' => 'running',
            'execution_mode' => 'task', 'input_data' => [],
        ]);
        $run = TaskRun::create([
            'tenant_id' => $this->tenant->id, 'task_id' => $this->task->id,
            'skill_id' => $this->task->skill_id, 'trigger' => TaskRun::TRIGGER_SCHEDULE,
            'status' => TaskRun::STATUS_BILLING_PENDING, 'started_at' => now()->subMinute(),
            'metadata' => ['execution_id' => $execution->id, 'queue_token' => 'original-run'],
        ]);
        $brief = new SkillBrief($this->task->skill_id, $execution->project_id,
            $execution->user_id, [], $query, [], $execution->id);
        $clientId = PreparedEmbeddingAttempt::save($execution->id, $execution->project_id,
            $execution->user_id, $execution->skill_id, 'skill:'.$execution->id,
            $query, $brief->toArray());
        $this->configureWorkspaceModelSlots($this->tenant);
        PreparedEmbeddingAttempt::recordResult($execution->id, $clientId, array_pad([0.125, 0.25], 1024, 0.0), [
            'status' => 'completed', 'provider' => 'custom', 'model' => 'test-embedding',
            'request_id' => 'task-vector', 'usage' => ['total_tokens' => 3]]);
        $execution->update(['status' => 'billing_pending']);
        config(['queue.default' => 'database', 'queue.connections.database.after_commit' => false]);
        $this->assertSame('original_task_run_queued',
            app(PendingEmbeddingAttemptInspector::class)->recover($execution->id)['status']);
        $job = null;
        Queue::assertPushed(ResumeTaskEmbeddingJob::class, function ($queued) use (&$job, $run, $execution) {
            $job = $queued;
            return $queued->runId === $run->id && $queued->executionId === $execution->id;
        });
        $this->assertSame('pending', $execution->fresh()->status);
        $this->assertSame(TaskRun::STATUS_BILLING_PENDING, $run->fresh()->status);
        $this->task->update(['auto_publish' => true]);
        $noEmbeddingPost = $this->createMock(AiProvider::class);
        $noEmbeddingPost->expects($this->never())->method('embed');
        $service = $this->service(function (SkillBrief $received) use ($execution, $query, $noEmbeddingPost) {
            $this->assertSame($execution->id, $received->executionId);
            $this->assertSame([], (new KnowledgeRetrievalServiceImpl($noEmbeddingPost))
                ->retrieve($query, filters: [
                    'project_id' => $received->projectId, 'user_id' => $received->userId,
                    'feature_id' => $received->skillId, 'task_id' => 'skill:'.$received->executionId,
                    'execution_id' => $received->executionId,
                ]));
            return $this->fakeResult($received);
        });
        $job->handle($service);
        $job->handle($service);
        $this->assertSame(TaskRun::STATUS_SUCCEEDED, $run->fresh()->status);
        $this->assertSame('completed', $execution->fresh()->status);
        $this->assertNull($execution->fresh()->billing_pending_result);
        $this->assertSame(1, TaskRun::count());
        $this->assertSame(1, Article::count());
        $this->assertSame('editing', Article::firstOrFail()->status);
    }

    public function test_killed_resumed_task_keeps_original_run_and_vector_visible(): void
    {
        TenantContext::set($this->tenant);
        config(['queue.default' => 'database']);
        $execution = SkillExecution::create([
            'project_id' => $this->task->project_id, 'user_id' => $this->task->user_id,
            'skill_id' => $this->task->skill_id, 'skill_name' => 'Test skill',
            'category' => 'geo-content-production', 'status' => 'running',
            'execution_mode' => 'task', 'input_data' => [],
            'billing_pending_result' => [
                'kind' => 'embedding_result_recorded', 'query' => 'original',
                'vector' => [0.125, 0.25],
            ],
        ]);
        $run = TaskRun::create([
            'tenant_id' => $this->tenant->id, 'task_id' => $this->task->id,
            'skill_id' => $this->task->skill_id, 'trigger' => TaskRun::TRIGGER_SCHEDULE,
            'status' => TaskRun::STATUS_RUNNING, 'started_at' => now()->subMinutes(40),
            'metadata' => ['execution_id' => $execution->id],
        ]);
        SkillExecution::query()->whereKey($execution->id)
            ->update(['updated_at' => now()->subMinutes(40)]);
        Artisan::call('geo:reap-stuck-executions', ['--minutes' => 15]);
        $this->assertSame('billing_pending', $execution->fresh()->status);
        $this->assertSame(TaskRun::STATUS_BILLING_PENDING, $run->fresh()->status);
        $this->assertSame([0.125, 0.25], data_get($execution->fresh()->billing_pending_result, 'vector'));
        $this->assertSame(1, TaskRun::count());
    }

    public function test_resume_job_failure_before_claim_returns_recorded_vector_to_review(): void
    {
        TenantContext::set($this->tenant);
        $execution = SkillExecution::create([
            'project_id' => $this->task->project_id, 'user_id' => $this->task->user_id,
            'skill_id' => $this->task->skill_id, 'skill_name' => 'Test skill',
            'category' => 'geo-content-production', 'status' => 'pending',
            'execution_mode' => 'task', 'input_data' => [],
            'billing_pending_result' => [
                'kind' => 'embedding_result_recorded', 'query' => 'original',
                'vector' => [0.125, 0.25],
            ],
        ]);
        $run = TaskRun::create([
            'tenant_id' => $this->tenant->id, 'task_id' => $this->task->id,
            'skill_id' => $this->task->skill_id, 'trigger' => TaskRun::TRIGGER_SCHEDULE,
            'status' => TaskRun::STATUS_BILLING_PENDING, 'started_at' => now(),
            'metadata' => ['execution_id' => $execution->id],
        ]);
        (new ResumeTaskEmbeddingJob($this->tenant->id, $this->task->id,
            $run->id, $execution->id, []))->failed(new \RuntimeException('worker stopped'));
        $this->assertSame('billing_pending', $execution->fresh()->status);
        $this->assertSame(TaskRun::STATUS_BILLING_PENDING, $run->fresh()->status);
        $this->assertSame([0.125, 0.25], data_get($execution->fresh()->billing_pending_result, 'vector'));
    }

    public function test_task_pending_embedding_keeps_run_and_execution_unpublished(): void
    {
        $service = $this->service(function (SkillBrief $brief) {
            $clientId = PreparedEmbeddingAttempt::save(
                $brief->executionId, $brief->projectId, $brief->userId,
                $brief->skillId, 'skill:'.$brief->executionId, 'task query', $brief->toArray(),
            );
            throw new UnresolvedEmbeddingAttempt([
                'http_status' => 202, 'attribution' => ['client_request_id' => $clientId],
            ]);
        });

        (new RunTaskJob($this->tenant->id, $this->task->id, TaskRun::TRIGGER_MANUAL))->handle($service);
        $run = TaskRun::firstOrFail();
        $execution = SkillExecution::findOrFail($run->metadata['execution_id']);
        $this->assertSame(TaskRun::STATUS_BILLING_PENDING, $run->status);
        $this->assertSame('billing_pending', $execution->status);
        $this->assertSame('embedding_attempt_unknown', data_get($execution->billing_pending_result, 'kind'));
        $this->assertDatabaseCount('articles', 0);
        $this->assertDatabaseCount('model_call_logs', 0);
    }

    public function test_serialized_duplicate_consumption_does_not_repeat_model_article_or_debit(): void
    {
        $job = new RunTaskJob($this->tenant->id, $this->task->id, TaskRun::TRIGGER_MANUAL);
        $duplicate = unserialize(serialize($job));
        $service = $this->service(fn (SkillBrief $brief) => $this->fakeResult($brief));
        $job->handle($service);
        $duplicate->handle($service);

        $this->assertDatabaseCount('task_runs', 1);
        $this->assertDatabaseCount('skill_executions', 1);
        $this->assertDatabaseCount('articles', 1);
        $this->assertDatabaseCount('model_call_logs', 1);
        $run = TaskRun::firstOrFail();
        $this->assertSame(TaskRun::STATUS_SUCCEEDED, $run->status);
        $execution = SkillExecution::findOrFail($run->metadata['execution_id']);
        $this->assertSame($run->article_id, $execution->article_id);
        $this->assertSame('Already generated text', $execution->output_data['result_text']);
        $this->assertSame(['passed' => true], $execution->quality_report);
        $this->assertFalse(TenantContext::has());
    }

    public function test_two_distinct_manual_dispatches_still_run_twice(): void
    {
        $service = $this->service(fn (SkillBrief $brief) => $this->fakeResult($brief), 2);
        (new RunTaskJob($this->tenant->id, $this->task->id, TaskRun::TRIGGER_MANUAL))->handle($service);
        (new RunTaskJob($this->tenant->id, $this->task->id, TaskRun::TRIGGER_MANUAL))->handle($service);
        $this->assertDatabaseCount('task_runs', 2);
        $this->assertDatabaseCount('articles', 2);
        $this->assertDatabaseCount('model_call_logs', 2);
    }

    public function test_duplicate_while_running_returns_existing_run_without_changing_it(): void
    {
        $service = null;
        $service = $this->service(function (SkillBrief $brief) use (&$service) {
            $existing = $service->run($this->task, TaskRun::TRIGGER_MANUAL, 'one-message');
            $this->assertSame(TaskRun::STATUS_RUNNING, $existing->status);
            $this->assertNull($existing->finished_at);
            $this->assertSame($this->tenant->id, TenantContext::id());

            return $this->fakeResult($brief);
        });
        $run = $service->run($this->task, TaskRun::TRIGGER_MANUAL, 'one-message');
        $this->assertSame(TaskRun::STATUS_SUCCEEDED, $run->status);
        $this->assertDatabaseCount('task_runs', 1);
        $this->assertDatabaseCount('model_call_logs', 1);
    }

    public function test_old_queue_payload_without_new_property_uses_laravel_payload_uuid(): void
    {
        $job = new RunTaskJob($this->tenant->id, $this->task->id, TaskRun::TRIGGER_MANUAL);
        unset($job->runToken);
        $duplicate = unserialize(serialize($job));
        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('uuid')->andReturn('old-queue-payload');
        $job->setJob($queueJob);
        $duplicate->setJob($queueJob);
        $service = $this->service(fn (SkillBrief $brief) => $this->fakeResult($brief));
        $job->handle($service);
        $duplicate->handle($service);
        $this->assertDatabaseCount('task_runs', 1);
        $this->assertSame('old-queue-payload', TaskRun::firstOrFail()->metadata['queue_token']);
    }

    public function test_worker_failed_callback_closes_only_its_own_run_and_never_repeats_billing(): void
    {
        $job = new RunTaskJob($this->tenant->id, $this->task->id, TaskRun::TRIGGER_MANUAL);
        $callbackJob = unserialize(serialize($job));
        $service = $this->service(function (SkillBrief $brief) use ($callbackJob) {
            $result = $this->fakeResult($brief);
            // Emulate Laravel invoking failed() on the serialized copy after timeout.
            $callbackJob->failed(new \RuntimeException('Queue execution timed out'));

            return $result;
        });
        $this->app->instance(TaskOrchestrationService::class, $service);
        $job->handle($service);
        $run = TaskRun::firstOrFail();
        $this->assertSame(TaskRun::STATUS_FAILED, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(ExecutionStatus::Failed->value, SkillExecution::findOrFail($run->metadata['execution_id'])->status);
        $callbackJob->failed(new \RuntimeException('Duplicate failed callback'));
        $this->assertSame('Queue execution timed out', $run->fresh()->error_message);
        $job->handle($service);
        $this->assertDatabaseCount('task_runs', 1);
        $this->assertDatabaseCount('model_call_logs', 1);
    }

    public function test_late_failed_callback_cannot_change_success_or_another_delivery(): void
    {
        $job = new RunTaskJob($this->tenant->id, $this->task->id, TaskRun::TRIGGER_MANUAL);
        $service = $this->service(fn (SkillBrief $brief) => $this->fakeResult($brief));
        $this->app->instance(TaskOrchestrationService::class, $service);
        $job->handle($service);
        $successful = TaskRun::firstOrFail();
        $otherRun = TaskRun::create([
            'tenant_id' => $this->tenant->id, 'task_id' => $this->task->id, 'skill_id' => $this->task->skill_id,
            'trigger' => TaskRun::TRIGGER_MANUAL, 'status' => TaskRun::STATUS_RUNNING,
            'metadata' => ['queue_token' => 'different-delivery'],
        ]);
        $job->failed(new \RuntimeException('Late error'));
        $this->assertSame(TaskRun::STATUS_SUCCEEDED, $successful->fresh()->status);
        $this->assertNull($successful->fresh()->error_message);
        $this->assertSame(TaskRun::STATUS_RUNNING, $otherRun->fresh()->status);
        $this->assertNull($otherRun->fresh()->finished_at);
        $this->assertFalse(TenantContext::has());
    }

    #[DataProvider('publicationTransactions')]
    public function test_task_publication_dispatch_waits_for_commit_and_is_discarded_on_rollback(bool $commit): void
    {
        // Real DatabaseQueue: Queue::fake() bypasses its after-commit scheduling.
        config(['queue.default' => 'database', 'queue.connections.database.connection' => config('database.default')]);
        Queue::swap($this->realQueue);
        $this->task->update(['auto_publish' => true, 'auto_distribute' => true]);
        $channel = PublishingChannel::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Queued-only test channel',
            'type' => PublishingChannel::TYPE_HTTP_WEBHOOK, 'endpoint_url' => 'https://example.test/never-called', 'is_active' => true,
        ]);
        DB::table('project_publishing_channels')->insert([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->task->project_id,
            'publishing_channel_id' => $channel->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $service = $this->service(fn (SkillBrief $brief) => $this->fakeResult($brief));
        DB::beginTransaction();
        $run = $service->run($this->task);
        $this->assertSame(TaskRun::STATUS_SUCCEEDED, $run->status);
        $this->assertTrue($run->metadata['auto_published']);
        $this->assertSame('published', Article::findOrFail($run->article_id)->status);
        $this->assertDatabaseCount('article_publications', 1);
        $this->assertSame(0, DB::table('jobs')->where('queue', 'distribution')->count());
        if ($commit) {
            DB::commit();
            $this->assertSame(1, DB::table('jobs')->where('queue', 'distribution')->count());
            $this->assertDatabaseCount('article_publications', 1);
        } else {
            DB::rollBack();
            $this->assertSame(0, DB::table('jobs')->where('queue', 'distribution')->count());
            $this->assertDatabaseCount('article_publications', 0);
            $this->assertDatabaseCount('task_runs', 0);
        }
    }

    public static function publicationTransactions(): array
    {
        return [['commit' => true], ['commit' => false]];
    }

    public function test_failed_callback_with_foreign_tenant_cannot_modify_run(): void
    {
        $job = new RunTaskJob($this->tenant->id, $this->task->id, TaskRun::TRIGGER_MANUAL);
        $run = TaskRun::create([
            'tenant_id' => $this->tenant->id, 'task_id' => $this->task->id, 'skill_id' => $this->task->skill_id,
            'status' => TaskRun::STATUS_RUNNING, 'metadata' => ['queue_token' => $job->runToken],
        ]);
        $other = Tenant::create(['subdomain' => 'other-callback', 'name' => 'Other', 'plan' => 'basic', 'status' => 'active']);
        $job->tenantId = $other->id;
        $job->failed(new \RuntimeException('Foreign callback'));
        $this->assertSame(TaskRun::STATUS_RUNNING, $run->fresh()->status);
        $this->assertNull($run->fresh()->finished_at);
    }

    public function test_task_paused_before_consumption_is_not_run(): void
    {
        $job = new RunTaskJob($this->tenant->id, $this->task->id, TaskRun::TRIGGER_MANUAL);
        $this->task->update(['status' => Task::STATUS_PAUSED]);
        $job->handle($this->service(fn () => throw new \LogicException('must not run'), 0));
        $this->assertDatabaseCount('task_runs', 0);
    }

    public function test_task_deleted_before_consumption_is_not_run(): void
    {
        $job = new RunTaskJob($this->tenant->id, $this->task->id, TaskRun::TRIGGER_MANUAL);
        $this->task->delete();
        $job->handle($this->service(fn () => throw new \LogicException('must not run'), 0));
        $this->assertDatabaseCount('task_runs', 0);
    }

    public function test_tenant_paused_before_consumption_is_not_run(): void
    {
        $this->tenant->update(['status' => 'paused']);
        (new RunTaskJob($this->tenant->id, $this->task->id, TaskRun::TRIGGER_MANUAL))
            ->handle($this->service(fn () => throw new \LogicException('must not run'), 0));
        $this->assertDatabaseCount('task_runs', 0);
    }

    public function test_foreign_tenant_cannot_consume_task(): void
    {
        $other = Tenant::create(['subdomain' => 'other-task', 'name' => 'Other', 'plan' => 'basic', 'status' => 'active']);
        (new RunTaskJob($other->id, $this->task->id, TaskRun::TRIGGER_MANUAL))
            ->handle($this->service(fn () => throw new \LogicException('must not run'), 0));
        $this->assertDatabaseCount('task_runs', 0);
    }

    public function test_partial_failure_preserves_generated_output_and_already_deducted_points(): void
    {
        $run = $this->service(fn (SkillBrief $brief) => $this->fakeResult($brief, false))->run($this->task);
        $execution = SkillExecution::findOrFail($run->metadata['execution_id']);
        $this->assertSame(TaskRun::STATUS_FAILED, $run->status);
        $this->assertNotNull($run->article_id);
        $this->assertSame($run->article_id, $execution->article_id);
        $this->assertSame('Already generated text', $execution->output_data['result_text']);
        $this->assertSame('test/report.md', $execution->output_data['markdown']);
        $this->assertSame(['passed' => false], $execution->quality_report);
        $this->assertSame('editing', Article::findOrFail($run->article_id)->status);
    }

    public function test_partial_failure_retains_checkpoint_written_by_runner(): void
    {
        $run = $this->service(function (SkillBrief $brief) {
            SkillExecution::findOrFail($brief->executionId)->update(['output_data' => ['checkpoint' => ['section' => 'Durable progress']]]);

            return $this->fakeResult($brief, false);
        })->run($this->task);
        $output = SkillExecution::findOrFail($run->metadata['execution_id'])->output_data;
        $this->assertSame(['section' => 'Durable progress'], $output['checkpoint']);
        $this->assertSame('Already generated text', $output['result_text']);
        $this->assertSame(TaskRun::STATUS_FAILED, $run->status);
    }

    public function test_exception_after_model_call_keeps_execution_link_cost_and_terminal_states(): void
    {
        $service = $this->service(function (SkillBrief $brief) {
            $this->fakeResult($brief);
            throw new \RuntimeException('Worker step failed');
        });
        $run = $service->run($this->task);
        $this->assertSame(TaskRun::STATUS_FAILED, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertNotNull($run->metadata['execution_id'] ?? null);
        $this->assertSame(ExecutionStatus::Failed->value, SkillExecution::findOrFail($run->metadata['execution_id'])->status);
        $this->assertNotNull($this->task->fresh()->last_run_at);
        $this->assertTrue($this->task->fresh()->next_run_at->isFuture());
        $this->assertDatabaseCount('model_call_logs', 1);
        $this->assertFalse(TenantContext::has());
    }

    public function test_failed_result_is_not_reexecuted_by_duplicate_consumption(): void
    {
        $job = new RunTaskJob($this->tenant->id, $this->task->id, TaskRun::TRIGGER_MANUAL);
        $duplicate = unserialize(serialize($job));
        $service = $this->service(fn (SkillBrief $brief) => $this->fakeResult($brief, false));
        $job->handle($service);
        $duplicate->handle($service);
        $this->assertSame(TaskRun::STATUS_FAILED, TaskRun::firstOrFail()->status);
        $this->assertDatabaseCount('task_runs', 1);
        $this->assertDatabaseCount('skill_executions', 1);
        $this->assertDatabaseCount('model_call_logs', 1);
        $this->assertDatabaseCount('articles', 1);
    }

    public function test_exception_after_cancellation_preserves_cancelled_execution(): void
    {
        $run = $this->service(function (SkillBrief $brief) {
            $this->fakeResult($brief);
            SkillExecution::findOrFail($brief->executionId)->update(['status' => ExecutionStatus::Cancelled->value, 'error_message' => '用户手动取消']);
            throw new \RuntimeException('Stopped after cancellation');
        })->run($this->task);
        $execution = SkillExecution::findOrFail($run->metadata['execution_id']);
        $this->assertSame(ExecutionStatus::Cancelled->value, $execution->status);
        $this->assertSame('用户手动取消', $execution->error_message);
        $this->assertSame(TaskRun::STATUS_FAILED, $run->status);
    }

    #[DataProvider('terminalExecutionStatuses')]
    public function test_late_success_cannot_revive_or_publish_a_terminal_execution(string $status): void
    {
        $this->task->update(['auto_publish' => true]);
        $snapshot = null;
        $run = $this->service(function (SkillBrief $brief) use ($status, &$snapshot) {
            $result = $this->fakeResult($brief);
            $execution = SkillExecution::findOrFail($brief->executionId);
            $execution->update(['status' => $status, 'error_message' => 'Original terminal outcome', 'output_data' => ['checkpoint' => 'Original evidence']]);
            $snapshot = $execution->fresh()->getAttributes();

            return $result;
        })->run($this->task);
        $this->assertSame($snapshot, SkillExecution::findOrFail($run->metadata['execution_id'])->getAttributes());
        $this->assertSame(TaskRun::STATUS_FAILED, $run->status);
        $this->assertSame('Original terminal outcome', $run->error_message);
        $this->assertSame('editing', Article::findOrFail($run->article_id)->status);
        $this->assertFalse($run->metadata['auto_published']);
    }

    public static function terminalExecutionStatuses(): array
    {
        return [['failed'], ['cancelled'], ['completed']];
    }

    public function test_cancellation_during_execution_is_not_overwritten_or_auto_published(): void
    {
        $this->task->update(['auto_publish' => true]);
        $run = $this->service(function (SkillBrief $brief) {
            $result = $this->fakeResult($brief);
            SkillExecution::findOrFail($brief->executionId)->update(['status' => ExecutionStatus::Cancelled->value, 'error_message' => '用户手动取消']);

            return $result;
        })->run($this->task);
        $execution = SkillExecution::findOrFail($run->metadata['execution_id']);
        $this->assertSame(ExecutionStatus::Cancelled->value, $execution->status);
        $this->assertSame(TaskRun::STATUS_FAILED, $run->status);
        $this->assertSame('editing', Article::findOrFail($run->article_id)->status);
        $this->assertFalse($run->metadata['auto_published']);
    }
}
