<?php

namespace Tests\Feature;

use App\Exceptions\IngestionFailure;
use App\Exceptions\KnowledgeDocumentUnavailable;
use App\Jobs\IngestKnowledgeDocumentJob;
use App\Models\{KnowledgeBase, KnowledgeChunk, KnowledgeDocument, Tenant, User};
use App\Services\AI\ModelConfiguration;
use App\Services\Contracts\AiProvider;
use App\Services\Knowledge\{DocumentIngestionService, PendingEmbeddingResponse};
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Http, Queue, Storage};
use Laravel\Sanctum\Sanctum;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class KnowledgeDeletionLifecycleTest extends TestCase
{
    use RefreshDatabase, \Tests\Support\UsesWorkspaceModels, \Tests\Support\KnowledgeProjectFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        Http::preventStrayRequests();
        config(['app.tenant_domain' => 'geo.example.test']);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    private function workspace(string $slug = 'delete-material'): KnowledgeBase
    {
        $tenant = Tenant::create(['name' => $slug, 'subdomain' => $slug, 'status' => 'active']);
        $this->configureWorkspaceModelSlots($tenant);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        $base = KnowledgeBase::create(['tenant_id' => $tenant->id, 'project_id' => self::knowledgeProjectId($tenant->id),
            'name' => '删除测试知识库', 'status' => 'active']);
        Sanctum::actingAs($user, ['*']);
        $this->withHeader('X-Tenant', $slug)->withHeader('X-Project-Id', (string) $base->project_id);
        TenantContext::set($tenant);

        return $base;
    }

    private function document(KnowledgeBase $base, string $status = 'ready', string $source = 'upload'): KnowledgeDocument
    {
        TenantContext::set($base->tenant);
        $document = app(ModelConfiguration::class)->createDocument([
            'tenant_id' => $base->tenant_id, 'knowledge_base_id' => $base->id, 'project_id' => $base->project_id,
            'title' => '私有材料', 'source_type' => $source, 'source_url' => $source === 'url' ? 'https://example.com/material' : null,
            'mime_type' => 'text/plain', 'status' => $status,
        ]);
        if ($source !== 'url') {
            $document->update(['file_path' => $document->storageDirectory().'/source.txt']);
            Storage::disk('local')->put($document->file_path, str_repeat('可核对的私有材料正文。', 20));
        }
        Storage::disk('local')->put($document->storageDirectory().'/pages/p1.jpg', 'test-private-page');
        (new PendingEmbeddingResponse($document, 'test-batch'))->save(['embeddings' => [[1]], 'model' => 'test-embedding']);

        return $document;
    }

    private function path(KnowledgeBase $base, ?KnowledgeDocument $document = null): string
    {
        return '/api/v1/knowledge-bases/'.$base->id.($document ? '/documents/'.$document->id : '');
    }

    private function chunks(KnowledgeDocument $document): void
    {
        KnowledgeChunk::create(['tenant_id' => $document->tenant_id, 'document_id' => $document->id,
            'chunk_index' => 0, 'content' => '私有正文', 'token_count' => 4, 'embedding' => array_pad([1], 1024, 0)]);
    }

    public function test_http_single_delete_removes_source_pages_and_receipts_but_preserves_other_workspace(): void
    {
        $otherBase = $this->workspace('delete-other');
        $other = $this->document($otherBase);
        $base = $this->workspace();
        $document = $this->document($base);
        $this->chunks($document);
        $directory = $document->storageDirectory();
        $this->deleteJson($this->path($base, $document))->assertOk();
        $this->assertFalse(Storage::disk('local')->directoryExists($directory), 'The entire private material directory must be gone.');
        $this->assertDatabaseMissing('knowledge_documents', ['id' => $document->id]);
        $this->assertDatabaseMissing('knowledge_chunks', ['document_id' => $document->id]);
        Storage::disk('local')->assertExists($other->file_path);
        Storage::disk('local')->assertExists($other->storageDirectory().'/pages/p1.jpg');
        $this->assertDatabaseHas('knowledge_documents', ['id' => $other->id, 'status' => 'ready']);
        Http::assertNothingSent();
    }

    public function test_url_without_file_path_and_reassigned_material_remove_their_historical_directories(): void
    {
        $base = $this->workspace();
        $newBase = KnowledgeBase::create(['tenant_id' => $base->tenant_id, 'project_id' => $base->project_id,
            'name' => '已迁移资料', 'status' => 'active']);
        foreach (['url', 'upload'] as $source) {
            $document = $this->document($base, source: $source);
            $oldDirectory = $document->storageDirectory();
            $document->update(['knowledge_base_id' => $newBase->id]);
            Storage::disk('local')->put("knowledge/{$base->tenant_id}/{$newBase->id}/{$document->id}/pages/p2.jpg", 'test-current-page');
            $this->deleteJson($this->path($newBase, $document))->assertOk();
            $this->assertFalse(Storage::disk('local')->directoryExists($oldDirectory));
            $this->assertFalse(Storage::disk('local')->directoryExists("knowledge/{$base->tenant_id}/{$newBase->id}/{$document->id}"));
        }
    }

    public function test_foreign_or_parent_directory_is_never_recursively_deleted(): void
    {
        $otherBase = $this->workspace('guard-other');
        $other = $this->document($otherBase);
        $base = $this->workspace();
        $document = $this->document($base);
        foreach ([$other->file_path, 'knowledge/source.txt', "knowledge/{$base->tenant_id}/{$base->id}/../source.txt"] as $badPath) {
            $document->update(['file_path' => $badPath]);
            $this->deleteJson($this->path($base, $document))->assertConflict();
            Storage::disk('local')->assertExists($other->file_path);
            $this->assertDatabaseHas('knowledge_documents', ['id' => $document->id]);
        }
    }

    public function test_cross_workspace_http_delete_leaves_all_material_files_untouched(): void
    {
        $otherBase = $this->workspace('forbidden-other');
        $other = $this->document($otherBase);
        $this->workspace();
        $this->deleteJson($this->path($otherBase, $other))->assertNotFound();
        Storage::disk('local')->assertExists($other->storageDirectory().'/pages/p1.jpg');
        $this->assertDatabaseHas('knowledge_documents', ['id' => $other->id, 'status' => 'ready']);
    }

    public function test_disk_false_or_exception_keeps_a_retryable_non_ingestible_record(): void
    {
        $base = $this->workspace();
        foreach (['false', 'throw'] as $failure) {
            $document = $this->document($base);
            $this->chunks($document);
            $disk = Storage::disk('local');
            $failing = Mockery::mock($disk);
            $deleteCalls = 0;
            $failing->shouldReceive('deleteDirectory')->andReturnUsing(function () use ($failure, &$deleteCalls) {
                $deleteCalls++;
                if ($failure === 'throw') throw new \RuntimeException('test storage failure');
                return false;
            });
            Storage::set('local', $failing);
            try {
                $this->deleteJson($this->path($base, $document))->assertStatus(503);
            } finally {
                Storage::set('local', $disk);
            }
            $this->assertSame(1, $deleteCalls);
            $this->assertDatabaseHas('knowledge_documents', ['id' => $document->id, 'status' => 'deleting']);
            $this->assertDatabaseHas('knowledge_chunks', ['document_id' => $document->id]);
            $provider = $this->createMock(AiProvider::class);
            $provider->expects($this->never())->method('embedBatch');
            $job = new IngestKnowledgeDocumentJob($base->tenant_id, $document->id);
            $job->handle(new DocumentIngestionService($provider));
            $job->failed(new \RuntimeException('test late worker failure'));
            $this->assertDatabaseHas('knowledge_documents', ['id' => $document->id, 'status' => 'deleting']);
            $this->deleteJson($this->path($base, $document))->assertOk();
            $this->assertFalse($disk->directoryExists($document->storageDirectory()));
            $this->assertDatabaseMissing('knowledge_documents', ['id' => $document->id]);
        }
    }

    public function test_processing_status_without_a_live_worker_does_not_permanently_block_deletion(): void
    {
        $base = $this->workspace();
        $document = $this->document($base, 'processing');
        $this->deleteJson($this->path($base, $document))->assertOk();
        $this->assertFalse(Storage::disk('local')->directoryExists($document->storageDirectory()));
    }

    public function test_native_worker_lock_blocks_delete_until_the_worker_exits(): void
    {
        $base = $this->workspace();
        $document = $this->document($base, 'processing');
        Storage::disk('local')->makeDirectory('.knowledge-locks');
        $lockPath = Storage::disk('local')->path('.knowledge-locks/'.$base->tenant_id.'-document-'.$document->id.'.lock');
        $script = <<<'PHP'
$lock = fopen($argv[1], 'c+b');
if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) exit(2);
fwrite(STDOUT, "locked\n");
fflush(STDOUT);
while (true) usleep(10000);
PHP;
        $worker = new Process([PHP_BINARY, '-r', $script, $lockPath]);
        $worker->setTimeout(10);
        $worker->start();
        try {
            $this->assertTrue($worker->waitUntil(fn ($type, $output) => str_contains($output, "locked\n")), 'The native worker must hold the real lifecycle lock.');
            $this->deleteJson($this->path($base, $document))->assertConflict();
            Storage::disk('local')->assertExists($document->storageDirectory().'/pages/p1.jpg');
            $this->assertDatabaseHas('knowledge_documents', ['id' => $document->id, 'status' => 'processing']);
        } finally {
            // OS process exit releases the lock even without an application cleanup callback.
            $worker->stop(0.1);
        }
        $this->deleteJson($this->path($base, $document))->assertOk();
        $this->assertFalse(Storage::disk('local')->directoryExists($document->storageDirectory()));
    }

    public function test_live_ingestion_rejects_single_and_base_delete_then_finishes_without_resending(): void
    {
        $base = $this->workspace();
        $document = $this->document($base, 'pending');
        $provider = $this->createMock(AiProvider::class);
        $provider->expects($this->once())->method('embedBatch')->willReturnCallback(function ($texts) use ($base, $document) {
            $this->deleteJson($this->path($base, $document))->assertConflict();
            $this->deleteJson($this->path($base))->assertConflict();
            $this->assertDatabaseHas('knowledge_documents', ['id' => $document->id, 'status' => 'processing']);
            $this->assertDatabaseHas('knowledge_bases', ['id' => $base->id, 'status' => 'active']);
            TenantContext::set($base->tenant);
            Storage::disk('local')->put($document->storageDirectory().'/pages/late.jpg', 'test-late-page');
            return ['embeddings' => array_fill(0, count($texts), array_pad([1], 1024, 0))];
        });
        $service = new DocumentIngestionService($provider);
        $this->assertSame('ready', $service->process($document)->status);
        $this->deleteJson($this->path($base, $document))->assertOk();
        (new IngestKnowledgeDocumentJob($base->tenant_id, $document->id))->handle($service);
        $this->assertFalse(Storage::disk('local')->directoryExists($document->storageDirectory()));
        $this->assertDatabaseMissing('knowledge_chunks', ['document_id' => $document->id]);
    }

    public function test_a_queued_job_after_delete_never_calls_a_model_or_recreates_files(): void
    {
        $base = $this->workspace();
        $document = $this->document($base, 'pending');
        $job = new IngestKnowledgeDocumentJob($base->tenant_id, $document->id);
        $this->deleteJson($this->path($base, $document))->assertOk();
        $provider = $this->createMock(AiProvider::class);
        $provider->expects($this->never())->method('embedBatch');
        $job->handle(new DocumentIngestionService($provider));
        $job->failed(new \RuntimeException('test late failure'));
        $this->assertFalse(Storage::disk('local')->directoryExists($document->storageDirectory()));
        $this->assertDatabaseMissing('knowledge_documents', ['id' => $document->id]);
    }

    public function test_whole_base_disk_failure_preserves_all_rows_and_blocks_registration_until_retry(): void
    {
        $base = $this->workspace();
        $first = $this->document($base);
        $second = $this->document($base, source: 'url');
        $disk = Storage::disk('local');
        $failing = Mockery::mock($disk);
        $failing->shouldReceive('deleteDirectory')->with($first->storageDirectory())->andReturnUsing(fn ($path) => $disk->deleteDirectory($path));
        $failing->shouldReceive('deleteDirectory')->with($second->storageDirectory())->andReturnFalse();
        Storage::set('local', $failing);
        try {
            $this->deleteJson($this->path($base))->assertStatus(503);
        } finally {
            Storage::set('local', $disk);
        }
        $this->assertDatabaseHas('knowledge_bases', ['id' => $base->id, 'status' => 'deleting']);
        foreach ([$first, $second] as $document) {
            $this->assertDatabaseHas('knowledge_documents', ['id' => $document->id, 'status' => 'deleting']);
        }
        $provider = $this->createMock(AiProvider::class);
        $provider->expects($this->never())->method('embedBatch');
        TenantContext::set($base->tenant);
        try {
            (new DocumentIngestionService($provider))->stageText($base->id, '新材料', '不能在删除中的库创建材料。');
            $this->fail('Registration must stop while deletion is incomplete.');
        } catch (KnowledgeDocumentUnavailable) {
            $this->assertDatabaseCount('knowledge_documents', 2);
        }
        $this->deleteJson($this->path($base))->assertOk();
        $this->assertDatabaseMissing('knowledge_bases', ['id' => $base->id]);
        foreach ([$first, $second] as $document) {
            $this->assertFalse($disk->directoryExists($document->storageDirectory()));
            $this->assertDatabaseMissing('knowledge_documents', ['id' => $document->id]);
        }
    }

    public function test_registration_and_delete_share_the_base_lock_before_the_source_file_is_written(): void
    {
        $base = $this->workspace();
        $disk = Storage::disk('local');
        $interleaved = Mockery::mock($disk);
        $interleaved->shouldReceive('put')->once()->andReturnUsing(function ($path, $contents) use ($base, $disk) {
            $this->deleteJson($this->path($base))->assertConflict();
            TenantContext::set($base->tenant);
            return $disk->put($path, $contents);
        });
        Storage::set('local', $interleaved);
        try {
            $provider = $this->createMock(AiProvider::class);
            $provider->expects($this->never())->method('embedBatch');
            $document = (new DocumentIngestionService($provider))
                ->stageFile($base->id, UploadedFile::fake()->createWithContent('source.txt', 'test source'), projectId: $base->project_id);
        } finally {
            Storage::set('local', $disk);
        }
        Storage::disk('local')->assertExists($document->file_path);
        $this->deleteJson($this->path($base))->assertOk();
        $this->assertFalse($disk->directoryExists($document->storageDirectory()));
    }

    public function test_deleting_url_cannot_be_recrawled_or_archived_and_failed_callback_does_not_revive_it(): void
    {
        $base = $this->workspace();
        $this->app->instance(\App\Contracts\Outbound\HostResolver::class, new class implements \App\Contracts\Outbound\HostResolver {
            public function resolve(string $host): array { return ['93.184.216.34']; }
        });
        $document = $this->document($base, 'deleting', 'url');
        $this->postJson($this->path($base, $document).'/recrawl', [], ['X-Idempotency-Key' => 'test-deleting-recrawl'])->assertConflict();
        $this->postJson($this->path($base, $document).'/archive')->assertConflict();
        Queue::assertNothingPushed();
        (new IngestKnowledgeDocumentJob($base->tenant_id, $document->id))->failed(new \RuntimeException('test delayed failure'));
        $this->assertDatabaseHas('knowledge_documents', ['id' => $document->id, 'status' => 'deleting']);
        $this->assertDatabaseCount('knowledge_documents', 1);
    }

    public function test_unknown_model_result_is_not_resent_before_or_after_deletion(): void
    {
        $base = $this->workspace();
        $document = $this->document($base, 'pending');
        $provider = $this->createMock(AiProvider::class);
        $provider->expects($this->once())->method('embedBatch')->willThrowException(new IngestionFailure('测试模型结果未知'));
        $service = new DocumentIngestionService($provider);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $service->process($document->fresh());
                $this->fail('An unknown result cannot be reported as ready.');
            } catch (IngestionFailure $e) {
                $this->assertStringContainsString('结果未知', $e->getMessage());
            }
        }
        $this->deleteJson($this->path($base, $document))->assertOk();
        (new IngestKnowledgeDocumentJob($base->tenant_id, $document->id))->handle($service);
        $this->assertFalse(Storage::disk('local')->directoryExists($document->storageDirectory()));
        $this->assertDatabaseMissing('knowledge_documents', ['id' => $document->id]);
    }
}
