<?php

namespace Tests\Feature;

use App\Models\ModelCallLog;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\User;
use App\Services\Billing\UnresolvedEmbeddingAttempt;
use App\Services\Contracts\AiProvider;
use App\Services\Knowledge\KnowledgeRetrievalServiceImpl;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\UsesFileSqlite;
use Tests\Support\UsesWorkspaceModels;
use Tests\TestCase;

final class SqliteRetrievalReceiptTest extends TestCase
{
    use UsesFileSqlite, UsesWorkspaceModels;

    public static function paidReadWindows(): array
    {
        return [
            'gateway evidence' => [true, false], 'saved vector' => [false, false],
            'unknown gateway receipt' => [true, true],
        ];
    }

    #[DataProvider('paidReadWindows')]
    public function test_snapshot_conflict_retries_paid_retrieval_bookkeeping_without_resending(bool $gatewayWindow, bool $unknown): void
    {
        $tenant = $this->useWorkspaceModels();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $project = Project::create(['owner_id' => $user->id, 'name' => '检索回执']);
        $execution = SkillExecution::create(['project_id' => $project->id, 'user_id' => $user->id,
            'skill_id' => 'yao-geo-intent-miner', 'skill_name' => '合成技能', 'category' => 'geo-research', 'status' => 'running']);
        $filters = ['project_id' => $project->id, 'user_id' => $user->id, 'execution_id' => $execution->id,
            'feature_id' => $execution->skill_id, 'task_id' => 'skill:'.$execution->id];
        $paid = false;
        $conflict = false;
        $provider = $this->createMock(AiProvider::class);
        $provider->expects($this->once())->method('embed')->willReturnCallback(function () use (&$paid, $unknown): array {
            $this->assertSame(0, DB::transactionLevel());
            $paid = true;

            return [
                'embedding' => $unknown ? [] : array_pad([0.125], 1024, 0.0),
                'tokens' => $unknown ? 0 : 3, 'provider' => 'custom', 'model' => 'test-embedding',
                'gateway' => ['request_id' => 'fixture-query-receipt', 'provider' => 'custom', 'model' => 'test-embedding',
                    'status' => $unknown ? 'unknown' : 'completed', 'http_status' => $unknown ? 202 : 200],
            ];
        });
        $writer = new \PDO('sqlite:'.$this->databaseFile);
        $this->assertSame('wal', $writer->query('pragma journal_mode')->fetchColumn());
        $extendedCodes = PHP_VERSION_ID >= 80400 ? \Pdo\Sqlite::ATTR_EXTENDED_RESULT_CODES : \PDO::SQLITE_ATTR_EXTENDED_RESULT_CODES;
        DB::connection()->getPdo()->setAttribute($extendedCodes, true);
        DB::listen(function (QueryExecuted $event) use ($writer, $gatewayWindow, &$paid, &$conflict): void {
            if ($paid && ! $conflict && DB::transactionLevel() > 0 && str_contains($event->sql, 'from "skill_executions"')
                && str_contains($event->sql, '"project_id"') === $gatewayWindow) {
                $writer->exec("INSERT INTO cache (key,value,expiration) VALUES ('fixture-query-writer','1',0)");
                $conflict = true;
            }
        });
        $retrieval = new KnowledgeRetrievalServiceImpl($provider);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $this->assertSame([], $retrieval->retrieve('同一个已发出的检索', filters: $filters));
                $this->assertFalse($unknown);
            } catch (UnresolvedEmbeddingAttempt $e) {
                $this->assertTrue($unknown);
            }
        }
        $this->assertTrue($conflict);
        $execution->refresh();
        $this->assertCount(1, $execution->gateway_requests);
        $this->assertSame('fixture-query-receipt', $execution->gateway_requests[0]['request_id']);
        $this->assertSame($unknown ? 'embedding_attempt_prepared' : 'embedding_result_recorded', $execution->billing_pending_result['kind']);
        $this->assertSame($unknown ? [] : array_pad([0.125], 1024, 0), $execution->billing_pending_result['vector'] ?? []);
        $this->assertSame($unknown ? 0 : 1, ModelCallLog::where('gateway_request_id', 'fixture-query-receipt')->count());
    }
}
