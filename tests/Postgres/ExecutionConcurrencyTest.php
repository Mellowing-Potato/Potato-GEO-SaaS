<?php

namespace Tests\Postgres;

use App\Jobs\ExecuteSkillJob;
use App\Jobs\RunTaskJob;
use App\Models\Article;
use App\Models\ModelCallLog;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\SkillExecutionRelay;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DTOs\SkillBrief;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Separate PHP bootstraps/PDO sessions, committed fixtures, shared PG locks. */
class ExecutionConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private array $processes = [];

    private ?string $directory = null;

    // DatabaseMigrations 默认在收尾时 migrate:rollback，但积分 v2 防护迁移禁止代码回滚
    // （只能整库恢复）。下一个测试会 migrate:fresh，tearDown 也会重置 RefreshDatabaseState，
    // 所以这里只迁移、不回滚；防护迁移本身保持不变。
    public function runDatabaseMigrations(): void
    {
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
    }

    // DatabaseMigrations runs during parent::setUp. Validate before migrate:fresh.
    protected function beforeRefreshingDatabase(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $this->assertFileDoesNotExist(base_path('.env'));
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('postgres', config('database.connections.pgsql.host'));
        $this->assertSame('geo_test', config('database.connections.pgsql.database'));
        $this->assertSame('geo_test', config('database.connections.pgsql.username'));
        $this->assertEmpty(config('database.connections.pgsql.url'));
    }

    protected function tearDown(): void
    {
        try {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($this->directory !== null) {
                touch($this->directory.'/release-model');
            }
            foreach ($this->processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            if ($this->directory !== null) {
                File::deleteDirectory($this->directory);
            }
            TenantContext::forget();
            parent::tearDown();
        } finally {
            // Our migrations remove the committed fixtures; the next ordinary
            // RefreshDatabase test must not assume those tables still exist.
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$lazilyRefreshed = false;
        }
    }

    public function test_two_processes_claiming_one_serialized_task_make_one_model_call(): void
    {
        [$tenant, $user, $project] = $this->fixtures();
        $task = Task::create([
            'project_id' => $project->id, 'user_id' => $user->id, 'name' => 'Concurrent task',
            'skill_id' => 'concurrency-test', 'status' => Task::STATUS_ACTIVE,
            'cadence' => Task::CADENCE_DAILY, 'timezone' => Task::DEFAULT_TIMEZONE,
            'auto_publish' => false, 'auto_distribute' => false,
        ]);
        TenantContext::forget();
        $ready = $this->startWorkers(new RunTaskJob($tenant->id, $task->id, TaskRun::TRIGGER_MANUAL));

        // Both children must actually reach the same PostgreSQL row lock. This
        // avoids a fast first child finishing its claim before the other boots.
        DB::beginTransaction();
        Task::whereKey($task->id)->lockForUpdate()->firstOrFail();
        touch($this->directory.'/go');
        $this->await(function () use ($ready): bool {
            $waiting = DB::selectOne("SELECT count(*) AS count FROM pg_stat_activity WHERE pid IN (?, ?) AND wait_event_type = 'Lock'", [
                $ready[0]['backend_pid'], $ready[1]['backend_pid'],
            ]);

            return (int) $waiting->count === 2;
        }, 'Both task workers must contend on the task row lock.');
        DB::commit();

        $this->finishRace();
        $this->assertSame(1, TaskRun::count());
        $this->assertSame(1, SkillExecution::count());
        $this->assertSame(1, Article::count());
        $this->assertSame(TaskRun::STATUS_SUCCEEDED, TaskRun::firstOrFail()->status);
        $this->assertSame('completed', SkillExecution::firstOrFail()->status);
        $this->assertUsageOnce();
    }

    public function test_two_processes_consuming_one_relay_step_make_one_model_call(): void
    {
        [$tenant, $user, $project] = $this->fixtures();
        $execution = SkillExecution::create([
            'project_id' => $project->id, 'user_id' => $user->id, 'skill_id' => 'concurrency-test',
            'skill_name' => 'Concurrent relay', 'category' => 'geo-research',
            'status' => 'running', 'execution_mode' => 'async', 'input_data' => [],
        ]);
        SkillExecutionRelay::create([
            'skill_execution_id' => $execution->id, 'tenant_id' => $tenant->id,
            'total_steps' => 1, 'completed_steps' => 0, 'state' => ['pending' => [1], 'token_sum' => 0],
        ]);
        $brief = new SkillBrief('concurrency-test', $project->id, $user->id, executionId: $execution->id);
        TenantContext::forget();
        $this->startWorkers(new ExecuteSkillJob($execution->id, $brief->toArray(), $tenant->id, 1));
        touch($this->directory.'/go');
        $results = $this->finishRace();

        $this->assertSame(1, SkillExecutionRelay::firstOrFail()->completed_steps);
        $this->assertSame(1, array_sum(array_column($results, 'successor_jobs')));
        $this->assertSame('running', $execution->fresh()->status);
        $this->assertSame(0, Article::count(), 'This race executes one chunk, not finalization.');
        $this->assertUsageOnce();
    }

    private function fixtures(): array
    {
        $tenant = Tenant::create(['subdomain' => 'concurrency', 'name' => 'Concurrency', 'plan' => 'basic', 'status' => 'active']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        TenantContext::set($tenant);
        $project = Project::create(['owner_id' => $user->id, 'name' => 'Concurrency']);

        return [$tenant, $user, $project];
    }

    private function startWorkers(RunTaskJob|ExecuteSkillJob $job): array
    {
        $this->assertSame(0, DB::transactionLevel(), 'Child processes must see committed fixtures.');
        $this->directory = storage_path('framework/testing/execution-concurrency-'.Str::uuid());
        File::makeDirectory($this->directory, 0700, true);
        file_put_contents($this->directory.'/job.serialized', serialize($job));
        foreach (['a', 'b'] as $worker) {
            $process = new Process([
                PHP_BINARY, base_path('tests/fixtures/execution-concurrency-worker.php'), $this->directory, $worker,
            ], base_path(), [
                'APP_ENV' => 'testing', 'AI_MOCK_MODE' => 'true',
                'APP_KEY' => config('app.key'), 'CACHE_STORE' => 'database', 'QUEUE_CONNECTION' => 'sync',
                'DB_CONNECTION' => 'pgsql', 'DB_HOST' => 'postgres', 'DB_PORT' => '5432',
                'DB_DATABASE' => 'geo_test', 'DB_USERNAME' => 'geo_test', 'DB_PASSWORD' => 'geo_test_disposable', 'DB_URL' => '',
                'BROADCAST_CONNECTION' => 'null', 'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array',
            ], timeout: 30);
            $process->start();
            $this->processes[] = $process;
        }
        $this->await(fn () => is_file($this->directory.'/ready-a.json') && is_file($this->directory.'/ready-b.json'), 'Both isolated workers must bootstrap.');
        $ready = array_map(fn ($worker) => json_decode(file_get_contents($this->directory.'/ready-'.$worker.'.json'), true, flags: JSON_THROW_ON_ERROR), ['a', 'b']);
        $this->assertNotSame($ready[0]['pid'], $ready[1]['pid']);
        $this->assertNotSame($ready[0]['backend_pid'], $ready[1]['backend_pid']);
        $parentPid = (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
        $this->assertNotContains($parentPid, array_column($ready, 'backend_pid'));

        return $ready;
    }

    private function finishRace(): array
    {
        // Keep the first fake model call in flight until the duplicate exits.
        // On broken code two calls enter instead; release them so failure still
        // cleans up promptly and reports duplicate calls rather than a timeout.
        $this->await(fn () => count($this->readLines('calls.jsonl')) >= 2
            || (count($this->readLines('calls.jsonl')) === 1
                && (is_file($this->directory.'/result-a.json') || is_file($this->directory.'/result-b.json'))),
            'A duplicate must finish while the first model call is still in flight.');
        $callsWhileRunning = count($this->readLines('calls.jsonl'));
        touch($this->directory.'/release-model');
        foreach ($this->processes as $process) {
            $this->assertSame(0, $process->wait(), $process->getErrorOutput().$process->getOutput());
        }
        $this->assertSame(1, $callsWhileRunning, 'Concurrent redelivery issued a second model call.');

        return array_map(fn ($worker) => json_decode(file_get_contents($this->directory.'/result-'.$worker.'.json'), true, flags: JSON_THROW_ON_ERROR), ['a', 'b']);
    }

    private function assertUsageOnce(): void
    {
        $this->assertCount(1, $this->readLines('calls.jsonl'));
        $this->assertSame(1, ModelCallLog::count());
        $this->assertSame(5, (int) ModelCallLog::sum('total_tokens'));
    }

    private function readLines(string $name): array
    {
        $path = $this->directory.'/'.$name;

        return is_file($path) ? file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    }

    private function await(callable $condition, string $message): void
    {
        $deadline = microtime(true) + 12;
        do {
            clearstatcache();
            if ($condition()) {
                return;
            }
            foreach ($this->processes as $process) {
                if (! $process->isRunning() && $process->getExitCode() !== 0) {
                    $this->fail($message.' '.$process->getErrorOutput().$process->getOutput());
                }
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail($message);
    }
}
