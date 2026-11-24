<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\User;
use App\Services\Auth\LocalAccounts;
use App\Services\Contracts\AiProvider;
use App\Services\Knowledge\KnowledgeRetrievalServiceImpl;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\UsesFileSqlite;
use Tests\Support\UsesWorkspaceModels;
use Tests\TestCase;

final class PortableSqliteLoginTest extends TestCase
{
    use UsesFileSqlite, UsesWorkspaceModels;

    public function test_portable_login_and_a_simultaneous_scheduler_heartbeat_both_commit(): void
    {
        Http::preventStrayRequests();
        app(LocalAccounts::class)->create('login@example.com', 'test-password-1', '本地用户', 'platform_admin');
        $this->usePortableTransactionMode();

        $writer = null;
        $pipes = [];
        $event = null;
        DB::listen(function (QueryExecuted $query) use (&$writer, &$pipes, &$event): void {
            if ($writer !== null || DB::transactionLevel() < 1 || ! str_contains(strtolower($query->sql), 'lower(email)')) {
                return;
            }
            // The first write reports whether the login already owns the writer lock.
            $script = <<<'WORKER'
$db = new PDO('sqlite:'.$argv[1]);
$sql = "INSERT INTO worker_heartbeats (worker_id, last_seen_at) VALUES ('fixture-scheduler', CURRENT_TIMESTAMP)";
$db->exec('PRAGMA busy_timeout=0');
try {
    $db->exec($sql);
    echo "committed\n";
} catch (PDOException $e) {
    if (((int) $e->errorInfo[1] & 255) !== 5) {
        throw $e;
    }
    echo "waiting\n";
    flush();
    $db->exec('PRAGMA busy_timeout=5000');
    $db->exec($sql);
}
WORKER;
            $writer = proc_open([PHP_BINARY, '-r', $script, $this->databaseFile], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($writer);
            stream_set_timeout($pipes[1], 8);
            $event = trim((string) fgets($pipes[1]));
        });
        try {
            $response = $this->postJson('/api/v1/account/login', ['email' => 'login@example.com', 'password' => 'test-password-1']);
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            if (is_resource($writer)) {
                $exitCode = proc_close($writer);
            }
        }
        $response->assertOk();
        $this->assertSame('waiting', $event);
        $this->assertSame(0, $exitCode ?? null);
        $this->assertDatabaseCount('local_browser_sessions', 1);
        $this->assertDatabaseHas('worker_heartbeats', ['worker_id' => 'fixture-scheduler']);
        Http::assertNothingSent();
    }

    public function test_paid_embedding_can_run_alongside_an_independent_sqlite_writer(): void
    {
        $this->usePortableTransactionMode();
        $tenant = $this->useWorkspaceModels();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $project = Project::create(['name' => '检索', 'owner_id' => $user->id]);
        $execution = SkillExecution::create(['project_id' => $project->id, 'user_id' => $user->id,
            'skill_id' => 'yao-geo-intent-miner', 'skill_name' => '测试', 'category' => 'geo-research', 'status' => 'running']);
        $provider = $this->createMock(AiProvider::class);
        $provider->expects($this->once())->method('embed')->willReturnCallback(function (): array {
            $this->assertSame(0, DB::transactionLevel());
            $writer = new \PDO('sqlite:'.$this->databaseFile);
            $writer->exec('PRAGMA busy_timeout=0');
            $writer->exec("INSERT INTO worker_heartbeats (worker_id) VALUES ('fixture-paid-window')");

            return ['embedding' => array_pad([1], 1024, 0)];
        });
        $filters = ['project_id' => $project->id, 'user_id' => $user->id, 'execution_id' => $execution->id,
            'feature_id' => $execution->skill_id, 'task_id' => 'skill:'.$execution->id];
        $this->assertSame([], (new KnowledgeRetrievalServiceImpl($provider))->retrieve('合成问题', filters: $filters));
        $this->assertSame('embedding_result_recorded', $execution->fresh()->billing_pending_result['kind']);
        $this->assertDatabaseHas('worker_heartbeats', ['worker_id' => 'fixture-paid-window']);
        Http::assertNothingSent();
    }

    private function usePortableTransactionMode(): void
    {
        $template = parse_ini_file(base_path('portable/instance.env.example'), scanner_mode: INI_SCANNER_RAW);
        $key = 'DB_SQLITE_TRANSACTION_MODE';
        $previous = $_ENV[$key] ?? null;
        try {
            $_ENV[$key] = $template[$key] ?? 'DEFERRED';
            $deployment = require config_path('database.php');
        } finally {
            if ($previous === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $previous;
            }
        }
        config(['database.connections.sqlite.transaction_mode' => $deployment['connections']['sqlite']['transaction_mode']]);
        DB::purge('sqlite');
        $codes = PHP_VERSION_ID >= 80400 ? \Pdo\Sqlite::ATTR_EXTENDED_RESULT_CODES : \PDO::SQLITE_ATTR_EXTENDED_RESULT_CODES;
        DB::connection()->getPdo()->setAttribute($codes, true);
    }
}
