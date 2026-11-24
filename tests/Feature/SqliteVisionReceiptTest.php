<?php

namespace Tests\Feature;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Services\Contracts\AiProvider;
use App\Services\DTOs\AiProviderCallResult;
use App\Services\Knowledge\VisionPageExtractor;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\UsesFileSqlite;
use Tests\Support\UsesWorkspaceModels;
use Tests\TestCase;

final class SqliteVisionReceiptTest extends TestCase
{
    use UsesFileSqlite, UsesWorkspaceModels;

    public function test_vision_response_snapshot_conflict_retries_only_the_checkpoint_and_replays_locally(): void
    {
        Storage::fake('local');
        $tenant = $this->useWorkspaceModels();
        $base = KnowledgeBase::create(['tenant_id' => $tenant->id, 'name' => 'Vision checkpoint', 'status' => 'active']);
        $document = KnowledgeDocument::create(['tenant_id' => $tenant->id, 'knowledge_base_id' => $base->id,
            'title' => 'Vision fixture', 'source_type' => 'upload', 'mime_type' => 'application/pdf', 'status' => 'processing']);
        Storage::disk('local')->put('vision-fixture.jpg', 'synthetic-image-fixture');
        $image = Storage::disk('local')->path('vision-fixture.jpg');
        $paid = false;
        $conflict = false;
        $provider = $this->createMock(AiProvider::class);
        $provider->expects($this->once())->method('generate')->willReturnCallback(function () use (&$paid) {
            $paid = true;
            return new AiProviderCallResult(success: true, provider: 'custom', model: 'test-vision',
                content: 'Saved local transcription.', promptTokens: 10, completionTokens: 5, totalTokens: 15);
        });
        $writer = new \PDO('sqlite:'.$this->databaseFile);
        $this->assertSame('wal', $writer->query('PRAGMA journal_mode')->fetchColumn());
        DB::listen(function (QueryExecuted $query) use ($writer, &$paid, &$conflict) {
            if ($paid && ! $conflict && DB::transactionLevel() > 0 && str_contains($query->sql, 'from "knowledge_documents"')) {
                $conflict = true;
                $writer->exec("INSERT INTO cache (key,value,expiration) VALUES ('vision-other-writer','1',0)");
            }
        });
        $extractor = new VisionPageExtractor($provider);
        $method = new \ReflectionMethod($extractor, 'transcribeOnce');
        $first = $method->invoke($extractor, $document, $image, 4096);
        $this->assertTrue($conflict);
        $this->assertSame('Saved local transcription.', $first->content);
        $saved = array_values($document->fresh()->metadata['vision_attempts']);
        $this->assertSame(['recorded'], array_column($saved, 'status'));
        $this->assertSame('Saved local transcription.', $saved[0]['result']['content']);
        $second = $method->invoke($extractor, $document->fresh(), $image, 4096);
        $this->assertSame($first->content, $second->content);
        $this->assertSame(1, count($document->fresh()->metadata['vision_attempts']));
    }
}
