<?php

namespace Tests\Feature;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AI\ModelConfiguration;
use App\Services\Contracts\AiProvider;
use App\Services\Knowledge\KnowledgeRetrievalServiceImpl;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\UsesFileSqlite;
use Tests\Support\UsesWorkspaceModels;
use Tests\TestCase;

class SqliteKnowledgeRankingTest extends TestCase
{
    use UsesFileSqlite, UsesWorkspaceModels;

    public function test_exact_match_beyond_the_first_hundred_is_in_the_results_only_for_its_workspace_and_project(): void
    {
        $tenant = $this->useWorkspaceModels();
        $document = $this->document($tenant);
        $base = $document->knowledge_base_id;
        $query = array_pad([1], 1024, 0);
        for ($i = 0; $i < 100; $i++) {
            $this->chunk($document, array_pad([0, 1], 1024, 0), $i);
        }
        $wanted = $this->chunk($document, $query, 100);

        $archived = $this->document($tenant, $document->project_id);
        $archived->update(['review_status' => 'archived']);
        $this->chunk($archived, $query);
        $wrongModel = $this->document($tenant, $document->project_id);
        $wrongModel->update(['metadata' => ['embedding_identity' => ['provider' => 'custom', 'model' => 'another-model']]]);
        $this->chunk($wrongModel, $query);
        $inactive = $this->document($tenant, $document->project_id);
        KnowledgeBase::findOrFail($inactive->knowledge_base_id)->update(['status' => 'archived']);
        $this->chunk($inactive, $query);
        $pending = $this->document($tenant, $document->project_id);
        $pending->update(['status' => 'pending']);
        $this->chunk($pending, $query);
        $this->chunk($this->document($tenant), $query);
        $other = $this->useWorkspaceModels();
        $this->chunk($this->document($other), $query);
        TenantContext::set($tenant);
        $service = $this->retrieval($query, 2);
        $filters = ['project_id' => $document->project_id];
        $this->assertSame([$wanted->id], array_column($service->retrieve('查询', 5, 0.99, $filters), 'chunkId'));
        $this->assertSame([$wanted->id], array_column($service->retrieve('查询', 5, 0.99,
            $filters + ['knowledge_base_ids' => [$base]]), 'chunkId'));
    }

    public function test_invalid_legacy_vectors_are_excluded_even_at_zero_threshold(): void
    {
        $tenant = $this->useWorkspaceModels();
        $document = $this->document($tenant);
        $query = array_pad([1], 1024, 0);
        $valid = $this->chunk($document, $query);
        foreach (['[1]', 'not-json', json_encode(array_pad(['1'], 1024, 0)), '{"x":1}'] as $i => $bad) {
            DB::table('knowledge_chunks')->insert(['tenant_id' => $tenant->id, 'document_id' => $document->id,
                'chunk_index' => $i + 1, 'content' => '历史切片', 'embedding' => $bad]);
        }
        $hits = $this->retrieval($query, 1)->retrieve('查询', 5, 0, ['project_id' => $document->project_id]);
        $this->assertSame([$valid->id], array_column($hits, 'chunkId'));
    }

    private function document(Tenant $tenant, ?int $projectId = null): KnowledgeDocument
    {
        $projectId ??= Project::create(['tenant_id' => $tenant->id,
            'owner_id' => User::factory()->create(['tenant_id' => $tenant->id])->id, 'name' => '项目'])->id;
        $base = KnowledgeBase::create(['tenant_id' => $tenant->id, 'project_id' => $projectId,
            'name' => '知识库', 'status' => 'active']);

        return app(ModelConfiguration::class)->createDocument(['tenant_id' => $tenant->id, 'project_id' => $projectId,
            'knowledge_base_id' => $base->id, 'title' => '材料', 'source_type' => 'manual',
            'status' => 'ready', 'review_status' => 'approved']);
    }

    private function chunk(KnowledgeDocument $document, array $embedding, int $index = 0): KnowledgeChunk
    {
        return KnowledgeChunk::create(['tenant_id' => $document->tenant_id, 'document_id' => $document->id,
            'chunk_index' => $index, 'content' => '材料切片', 'embedding' => $embedding]);
    }

    private function retrieval(array $query, int $calls): KnowledgeRetrievalServiceImpl
    {
        $provider = $this->createMock(AiProvider::class);
        $provider->expects($this->exactly($calls))->method('embed')->willReturn(['embedding' => $query,
            'model' => 'test-embedding', 'provider' => 'custom']);

        return new KnowledgeRetrievalServiceImpl($provider);
    }
}
