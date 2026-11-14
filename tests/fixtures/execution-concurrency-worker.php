<?php

// Launched only by ExecutionConcurrencyTest with an isolated, committed test DB.
// Never load .env or accept a caller-supplied connection string.
if (getenv('APP_ENV') !== 'testing' || getenv('AI_MOCK_MODE') !== 'true'
    || getenv('DB_HOST') !== 'postgres' || getenv('DB_DATABASE') !== 'geo_test'
    || getenv('DB_USERNAME') !== 'geo_test' || getenv('DB_URL') !== '') {
    fwrite(STDERR, "Refusing a non-disposable concurrency database.\n");
    exit(2);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Jobs\ExecuteSkillJob;
use App\Jobs\RunTaskJob;
use App\Models\Article;
use App\Models\SkillExecution;
use App\Models\SkillExecutionRelay;
use App\Services\Billing\ModelUsageRecorder;
use App\Services\Content\MaterialLibraryService;
use App\Services\Contracts\SkillAgentRunner;
use App\Services\Contracts\SkillRegistry;
use App\Services\DTOs\AiProviderCallResult;
use App\Services\DTOs\RelayProgress;
use App\Services\DTOs\SkillBrief;
use App\Services\DTOs\SkillDefinition;
use App\Services\DTOs\SkillExecutionResult;
use App\Services\Enums\SkillCategory;
use App\Services\Reports\ReportAssetService;
use App\Services\SkillAgent\SkillAgentRunnerService;
use App\Services\Workflow\ProgressBroadcastService;
use App\Services\Workflow\TaskOrchestrationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

$directory = $argv[1] ?? '';
$worker = $argv[2] ?? '';
if (! is_dir($directory) || ! in_array($worker, ['a', 'b'], true)
    || ! str_starts_with(basename($directory), 'execution-concurrency-')) {
    throw new RuntimeException('Invalid test worker arguments.');
}

function waitForBarrier(string $file): void
{
    $deadline = microtime(true) + 20;
    while (! is_file($file)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for test barrier: '.basename($file));
        }
        usleep(10000);
        clearstatcache(true, $file);
    }
}

function writeWorkerJson(string $file, array $data): void
{
    file_put_contents($file.'.tmp', json_encode($data, JSON_THROW_ON_ERROR));
    rename($file.'.tmp', $file);
}

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (is_file(base_path('.env')) || DB::connection()->getDriverName() !== 'pgsql'
        || DB::connection()->getDatabaseName() !== 'geo_test') {
        throw new RuntimeException('Concurrency workers require the isolated PostgreSQL test image.');
    }
    // The production lock uses a shared database store. An array cache would
    // silently turn this into two unrelated per-process locks.
    config(['cache.default' => 'database', 'queue.default' => 'sync']);
    Http::preventStrayRequests();
    Queue::fake();

    $recorder = new ModelUsageRecorder();
    $fakeModel = function (SkillBrief $brief) use ($directory, $worker, $recorder): void {
        file_put_contents($directory.'/calls.jsonl', json_encode(['worker' => $worker, 'execution_id' => $brief->executionId])."\n", FILE_APPEND | LOCK_EX);
        $recorder->record(SkillExecution::findOrFail($brief->executionId), new AiProviderCallResult(
            success: true, provider: 'fake', model: 'concurrency-test', content: '# Isolated result',
            promptTokens: 2, completionTokens: 3, totalTokens: 5,
        ));
        waitForBarrier($directory.'/release-model');
    };
    $job = unserialize(file_get_contents($directory.'/job.serialized'), [
        'allowed_classes' => [RunTaskJob::class, ExecuteSkillJob::class],
    ]);

    if ($job instanceof RunTaskJob) {
        $runner = Mockery::mock(SkillAgentRunner::class);
        $runner->shouldReceive('execute')->andReturnUsing(function (SkillBrief $brief) use ($fakeModel): SkillExecutionResult {
            $fakeModel($brief);
            $article = Article::create([
                'project_id' => $brief->projectId, 'author_id' => $brief->userId,
                'title' => 'Concurrency result', 'body_markdown' => '# Isolated result', 'status' => 'editing',
            ]);

            return new SkillExecutionResult(
                executionId: $brief->executionId, skillId: $brief->skillId, success: true,
                intermediateData: ['article_id' => $article->id, 'raw_output' => '# Isolated result'],
                llmCallCount: 1, tokenUsage: 5,
            );
        });
        $registry = Mockery::mock(SkillRegistry::class);
        $registry->shouldReceive('find')->andReturn(new SkillDefinition(
            id: 'concurrency-test', name: 'Concurrency test', category: SkillCategory::GeoContentProduction,
            maturity: 'stable', requiresWeb: false, stageIndex: 0, description: 'Fake test runner',
        ));
        $service = new TaskOrchestrationService($runner, new ProgressBroadcastService, $registry);
        $handle = fn () => $job->handle($service);
    } elseif ($job instanceof ExecuteSkillJob) {
        $runner = Mockery::mock(SkillAgentRunnerService::class);
        $runner->shouldNotReceive('execute');
        $runner->shouldNotReceive('beginRelay');
        $runner->shouldNotReceive('finalizeRelay');
        $runner->shouldReceive('advanceRelay')->andReturnUsing(function (SkillBrief $brief) use ($fakeModel): RelayProgress {
            $relay = SkillExecutionRelay::where('skill_execution_id', $brief->executionId)->firstOrFail();
            $fakeModel($brief);
            $relay->update([
                'completed_steps' => $relay->completed_steps + 1,
                'state' => ['pending' => [], 'token_sum' => 5],
            ]);

            return new RelayProgress(true, $relay->completed_steps, 1, 0);
        });
        $handle = fn () => $job->handle($runner, app(ReportAssetService::class), app(MaterialLibraryService::class));
    } else {
        throw new RuntimeException('Unexpected serialized test job.');
    }

    writeWorkerJson($directory.'/ready-'.$worker.'.json', [
        'pid' => getmypid(), 'backend_pid' => (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid,
    ]);
    waitForBarrier($directory.'/go');
    $handle();
    Mockery::close();
    writeWorkerJson($directory.'/result-'.$worker.'.json', [
        'ok' => true, 'successor_jobs' => Queue::pushed(ExecuteSkillJob::class)->count(),
    ]);
    exit(0);
} catch (Throwable $error) {
    writeWorkerJson($directory.'/result-'.$worker.'.json', ['ok' => false, 'error' => $error->getMessage()]);
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
