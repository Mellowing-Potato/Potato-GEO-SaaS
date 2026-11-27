<?php

namespace Tests\Feature;

use App\Jobs\IngestKnowledgeDocumentJob;
use App\Models\{KnowledgeBase, KnowledgeDocument, Tenant, User};
use App\Services\AI\MockAiProvider;
use App\Services\Contracts\AiProvider;
use App\Services\Knowledge\DocumentIngestionService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Queue, Storage};
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\Concerns\FakesSafeOutbound;
use Tests\TestCase;

class KnowledgeImportIdempotencyTest extends TestCase
{
    use RefreshDatabase, FakesSafeOutbound;
    use \Tests\Support\UsesWorkspaceModels, \Tests\Support\KnowledgeProjectFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.tenant_domain' => 'geo.example.test', 'geo.async_ingest' => true]);
        Storage::fake('local');
        Queue::fake();
        $this->fakeSafeOutbound();
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    private function workspace(string $name = 'import-idempotency'): KnowledgeBase
    {
        $tenant = Tenant::create(['subdomain' => $name, 'name' => $name, 'status' => 'active', 'plan' => 'basic']);
        TenantContext::set($tenant);
        $this->configureWorkspaceModelSlots($tenant);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        Sanctum::actingAs($user, ['*']);
        $projectId = self::knowledgeProjectId($tenant->id);
        $this->withServerVariables(['HTTP_HOST' => $name.'.geo.example.test'])
            ->withHeader('X-Tenant', $name)->withHeader('X-Project-Id', (string) $projectId);

        return KnowledgeBase::create(['tenant_id' => $tenant->id, 'project_id' => $projectId,
            'name' => 'Import Fixture', 'status' => 'active']);
    }

    private function upload(KnowledgeBase $base, ?string $key, string $text = 'same material body')
    {
        // 真 multipart：同名、同大小也必须比较文件字节，不能把 UploadedFile JSON 化为 {}。
        return $this->post('/api/v1/knowledge-bases/'.$base->id.'/documents', [
            'file' => UploadedFile::fake()->createWithContent('material.txt', $text),
            'project_id' => $base->project_id,
        ], ['Accept' => 'application/json'] + ($key === null ? [] : ['X-Idempotency-Key' => $key]));
    }

    private function url(KnowledgeBase $base, ?string $key, string $url = 'https://example.com/material')
    {
        return $this->postJson('/api/v1/knowledge-bases/'.$base->id.'/ingest-url', [
            'url' => $url, 'project_id' => $base->project_id,
        ], $key === null ? [] : ['X-Idempotency-Key' => $key]);
    }

    public function test_file_without_key_is_rejected_before_document_or_job(): void
    {
        $base = $this->workspace();
        $this->upload($base, null)->assertConflict()->assertJsonPath('action', 'idempotency_key_required');
        $this->assertDatabaseCount('knowledge_documents', 0);
        Queue::assertNothingPushed();
    }

    public function test_url_without_key_is_rejected_before_document_or_job(): void
    {
        $base = $this->workspace();
        $this->url($base, null)->assertConflict()->assertJsonPath('action', 'idempotency_key_required');
        $this->assertDatabaseCount('knowledge_documents', 0);
        Queue::assertNothingPushed();
        $this->assertOutboundRequestCount(0);
    }

    public function test_recrawl_without_key_creates_no_replacement_or_job(): void
    {
        $base = $this->workspace();
        $original = $this->url($base, 'original')->assertAccepted()->json('data.id');
        Queue::fake();
        $this->postJson("/api/v1/knowledge-bases/{$base->id}/documents/{$original}/recrawl")
            ->assertConflict()->assertJsonPath('action', 'idempotency_key_required');
        $this->assertDatabaseCount('knowledge_documents', 1);
        Queue::assertNothingPushed();
    }

    public function test_file_replay_after_lost_response_keeps_one_document_job_and_embedding_call(): void
    {
        $base = $this->workspace();
        $text = str_repeat('知识材料是可核对的事实证据。', 8);
        $first = $this->upload($base, 'lost-response', $text)->assertAccepted();
        // 服务端已受理，浏览器没看到响应；重新上传相同字节/新临时文件，原键必须回放。
        $this->upload($base, 'lost-response', $text)->assertAccepted()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertDatabaseCount('knowledge_documents', 1);
        Queue::assertPushed(IngestKnowledgeDocumentJob::class, 1);
        $provider = new class extends MockAiProvider {
            public int $batches = 0;
            public function embedBatch(array $texts, string $model = 'embedding', array $options = []): array
            {
                $this->batches++;
                return parent::embedBatch($texts, $model, $options);
            }
        };
        $this->app->instance(AiProvider::class, $provider);
        $this->app->forgetInstance(DocumentIngestionService::class);
        collect(Queue::pushed(IngestKnowledgeDocumentJob::class))->first()
            ->handle($this->app->make(DocumentIngestionService::class));
        $this->assertSame(1, $provider->batches);
        $this->assertDatabaseHas('knowledge_documents', ['id' => $first->json('data.id'), 'status' => 'ready']);
    }

    public function test_same_key_same_name_same_size_different_bytes_conflicts(): void
    {
        $base = $this->workspace();
        $this->upload($base, 'file-content', 'AAAA')->assertAccepted();
        $this->upload($base, 'file-content', 'BBBB')->assertConflict();
        $this->assertDatabaseCount('knowledge_documents', 1);
        Queue::assertPushed(IngestKnowledgeDocumentJob::class, 1);
    }

    public function test_url_replay_and_changed_url_conflict(): void
    {
        $base = $this->workspace();
        $id = $this->url($base, 'url-action')->assertAccepted()->json('data.id');
        $this->url($base, 'url-action')->assertAccepted()->assertJsonPath('data.id', $id);
        $this->url($base, 'url-action', 'https://example.com/other')->assertConflict();
        $this->assertDatabaseCount('knowledge_documents', 1);
        Queue::assertPushed(IngestKnowledgeDocumentJob::class, 1);
    }

    public function test_changed_project_header_cannot_replay_original_document(): void
    {
        $base = $this->workspace();
        $this->url($base, 'project-context')->assertAccepted();
        $this->withHeader('X-Project-Id', (string) ($base->project_id + 1));
        $this->url($base, 'project-context')->assertConflict();
        $this->assertDatabaseCount('knowledge_documents', 1);
        Queue::assertPushed(IngestKnowledgeDocumentJob::class, 1);
    }

    public function test_key_is_scoped_to_the_concrete_knowledge_base_and_workspace(): void
    {
        $baseA = $this->workspace('import-a');
        $firstId = $this->url($baseA, 'same-key')->assertAccepted()->json('data.id');
        $secondBase = KnowledgeBase::create(['tenant_id' => $baseA->tenant_id, 'project_id' => $baseA->project_id,
            'name' => 'Other Base', 'status' => 'active']);
        $secondId = $this->url($secondBase, 'same-key')->assertAccepted()->json('data.id');
        $baseB = $this->workspace('import-b');
        $thirdId = $this->url($baseB, 'same-key')->assertAccepted()->json('data.id');
        $this->assertCount(3, array_unique([$firstId, $secondId, $thirdId]));
        $this->assertDatabaseHas('knowledge_documents', ['id' => $thirdId, 'tenant_id' => $baseB->tenant_id]);
        $this->url($baseA, 'same-key')->assertNotFound();
        $this->assertDatabaseCount('knowledge_documents', 3);
        Queue::assertPushed(IngestKnowledgeDocumentJob::class, 3);
    }

    public function test_error_after_document_creation_is_marked_unknown_and_never_reenters_stage(): void
    {
        $base = $this->workspace();
        $disk = Mockery::mock();
        $disk->shouldReceive('put')->once()->andThrow(new \RuntimeException('simulated storage failure'));
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        $this->upload($base, 'partial-stage')->assertUnprocessable()
            ->assertJsonPath('action', 'knowledge_ingestion_result_unknown');
        $this->upload($base, 'partial-stage')->assertUnprocessable()
            ->assertJsonPath('action', 'knowledge_ingestion_result_unknown');
        $this->assertDatabaseCount('knowledge_documents', 1);
        Queue::assertNothingPushed();
    }
}
