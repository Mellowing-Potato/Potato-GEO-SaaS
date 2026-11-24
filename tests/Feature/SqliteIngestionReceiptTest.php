<?php

namespace Tests\Feature;

use App\Exceptions\IngestionFailure;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\ModelCallLog;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\User;
use App\Services\Billing\AssistUsageRecorder;
use App\Services\Billing\ModelUsageRecorder;
use App\Services\Contracts\AiProvider;
use App\Services\Knowledge\DocumentIngestionService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\UsesFileSqlite;
use Tests\Support\UsesWorkspaceModels;
use Tests\TestCase;

class SqliteIngestionReceiptTest extends TestCase
{
    use UsesFileSqlite, UsesWorkspaceModels;

    public function test_concurrent_commit_after_paid_response_retries_only_the_database_work(): void
    {
        $paid = false;
        $conflict = false;
        $provider = $this->createMock(AiProvider::class);
        $provider->expects($this->once())->method('embedBatch')->willReturnCallback(function (array $texts) use (&$paid) {
            $paid = true;
            return $this->embeddingResponse($texts);
        });
        $service = new DocumentIngestionService($provider, app(AssistUsageRecorder::class));
        $document = $this->staged($service);
        $writer = new \PDO('sqlite:'.$this->databaseFile);
        $this->assertSame('wal', $writer->query('PRAGMA journal_mode')->fetchColumn());
        DB::listen(function (QueryExecuted $query) use ($writer, &$paid, &$conflict) {
            if ($paid && ! $conflict && DB::transactionLevel() && str_contains($query->sql, 'from "knowledge_documents"')) {
                $conflict = true;
                $writer->exec("INSERT INTO cache (key,value,expiration) VALUES ('other-writer','1',0)");
            }
        });
        $ready = $service->process($document);
        $this->assertTrue($conflict);
        $this->assertSame('ready', $ready->status);
        $this->assertSame([0], KnowledgeChunk::pluck('chunk_index')->all());
        $this->assertSame(['completed'], array_column($ready->metadata['embedding_batches'], 'status'));
        $this->assertSame(['test-ingestion-receipt'], array_column($ready->metadata['embedding_requests'], 'request_id'));
        $this->assertSame([12], ModelCallLog::pluck('total_tokens')->all());
    }

    public function test_snapshot_conflict_during_accounting_retries_the_outer_receipt_transaction(): void
    {
        $provider = $this->createMock(AiProvider::class);
        $provider->expects($this->once())->method('embedBatch')->willReturnCallback(fn ($texts) => $this->embeddingResponse($texts));
        $service = new DocumentIngestionService($provider, app(AssistUsageRecorder::class));
        $doc = $this->staged($service);
        $writer = new \PDO('sqlite:'.$this->databaseFile);
        $reads = 0;
        DB::listen(function (QueryExecuted $query) use (&$reads, $writer) {
            if (DB::transactionLevel() >= 2 && str_contains($query->sql, 'from "model_call_logs"')) {
                $reads++;
                if ($reads === 1) {
                    $writer->exec("INSERT INTO cache (key,value,expiration) VALUES ('accounting-writer','1',0)");
                }
            }
        });
        $this->assertSame('ready', $service->process($doc)->status);
        $this->assertGreaterThanOrEqual(2, $reads);
        $this->assertSame([12], ModelCallLog::pluck('total_tokens')->all());
        $this->assertSame(1, SkillExecution::where('execution_mode', 'assist')->count());
    }

    public function test_received_result_survives_accounting_failure_and_can_be_settled_without_resending(): void
    {
        $provider = $this->createMock(AiProvider::class);
        $provider->expects($this->once())->method('embedBatch')->willReturnCallback(fn ($texts) => $this->embeddingResponse($texts));
        $broken = $this->createMock(ModelUsageRecorder::class);
        $broken->method('record')->willThrowException(new \RuntimeException('fixture recorder unavailable'));
        $service = new DocumentIngestionService($provider, new AssistUsageRecorder($broken));
        $doc = $this->staged($service);
        try {
            $service->process($doc);
            $this->fail('An unrecorded paid result must not be marked ready.');
        } catch (IngestionFailure $e) {
            $this->assertStringContainsString('用量', $e->getMessage());
        }
        $this->assertSame(0, SkillExecution::where('execution_mode', 'assist')->count());
        $this->assertSame(0, ModelCallLog::count());
        $this->assertSame(['usage_pending'], array_column($doc->fresh()->metadata['embedding_batches'], 'status'));
        $recovered = (new DocumentIngestionService($provider, app(AssistUsageRecorder::class)))->process($doc->fresh());
        $this->assertSame('ready', $recovered->status);
        $this->assertSame(1, KnowledgeChunk::count());
        $this->assertSame(1, SkillExecution::where('execution_mode', 'assist')->count());
        $this->assertSame([12], ModelCallLog::pluck('total_tokens')->all());
    }

    public function test_unknown_supplier_response_is_recorded_once_across_queue_replay(): void
    {
        $provider = $this->createMock(AiProvider::class);
        $provider->expects($this->once())->method('embedBatch')->willReturn([
            'provider' => 'custom', 'model' => 'test-embedding', 'embeddings' => [], 'error' => '结果未知',
            'gateway' => ['request_id' => 'test-unknown', 'status' => 'unknown', 'usage_available' => false],
        ]);
        $service = new DocumentIngestionService($provider, app(AssistUsageRecorder::class));
        $doc = $this->staged($service);
        for ($i = 0; $i < 2; $i++) {
            try {
                $service->process($doc->fresh());
                $this->fail('An unknown result must remain visibly failed.');
            } catch (IngestionFailure $e) {
                $this->assertStringContainsString('结果未知', $e->getMessage());
            }
        }
        $this->assertSame(['test-unknown'], array_column($doc->fresh()->metadata['embedding_requests'], 'request_id'));
        $this->assertSame(['unknown'], ModelCallLog::pluck('status')->all());
        $this->assertNull(ModelCallLog::firstOrFail()->total_tokens);
        $this->assertSame(0, KnowledgeChunk::count());
    }

    public function test_a_received_result_survives_a_writer_that_outlasts_all_database_retries(): void
    {
        $writer = new \PDO('sqlite:'.$this->databaseFile);
        DB::statement('PRAGMA busy_timeout=15');
        $provider = $this->createMock(AiProvider::class);
        $provider->expects($this->once())->method('embedBatch')->willReturnCallback(function ($texts) use ($writer) {
            $writer->beginTransaction();
            $writer->exec("INSERT INTO cache (key,value,expiration) VALUES ('long-writer','1',0)");
            return $this->embeddingResponse($texts);
        });
        $service = new DocumentIngestionService($provider, app(AssistUsageRecorder::class));
        $doc = $this->staged($service);
        try {
            $service->process($doc);
            $this->fail('The locked database could not have committed the response.');
        } catch (\PDOException|IngestionFailure $e) {
            $this->assertTrue($writer->inTransaction());
        } finally {
            $writer->rollBack();
        }
        $this->assertSame('ready', $service->process($doc->fresh())->status);
        $this->assertSame(1, KnowledgeChunk::count());
        $this->assertSame([12], ModelCallLog::pluck('total_tokens')->all());
    }

    private function staged(DocumentIngestionService $service): KnowledgeDocument
    {
        Storage::fake('local');
        $tenant = $this->useWorkspaceModels();
        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $project = Project::create(['owner_id' => $owner->id, 'name' => '入库项目']);
        $base = KnowledgeBase::create(['project_id' => $project->id, 'name' => '知识库', 'status' => 'active']);
        $document = $service->stageText($base->id, '材料', str_repeat('可检索的测试正文。', 15));
        $document->update(['uploaded_by' => $owner->id]);

        return $document;
    }

    private function embeddingResponse(array $texts): array
    {
        return ['provider' => 'custom', 'model' => 'test-embedding',
            'embeddings' => array_fill(0, count($texts), array_pad([1], 1024, 0)),
            'gateway' => ['request_id' => 'test-ingestion-receipt', 'status' => 'completed',
                'usage' => ['prompt_tokens' => 12, 'total_tokens' => 12]]];
    }
}
