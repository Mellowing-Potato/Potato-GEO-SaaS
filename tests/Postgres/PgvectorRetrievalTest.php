<?php

namespace Tests\Postgres;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Tenant;
use App\Services\Contracts\AiProvider;
use App\Services\Knowledge\KnowledgeRetrievalServiceImpl;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PgvectorRetrievalTest extends TestCase
{
    use \Tests\Support\UsesWorkspaceModels;
    use RefreshDatabase;
    use \Tests\Support\KnowledgeProjectFixtures;

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    public function test_migrations_install_the_production_vector_dimension_and_index(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $column = DB::selectOne("SELECT format_type(atttypid, atttypmod) AS type FROM pg_attribute
            WHERE attrelid = 'knowledge_chunks'::regclass AND attname = 'embedding'");
        $this->assertSame('vector(1024)', $column->type);
        $index = DB::selectOne("SELECT indexdef FROM pg_indexes
            WHERE tablename = 'knowledge_chunks' AND indexdef ILIKE '%USING hnsw%'");
        $this->assertNotNull($index, 'The production HNSW vector index must be created by migrations.');
        $this->assertStringContainsString('vector_cosine_ops', $index->indexdef);
    }

    public function test_real_vector_sql_ranks_results_and_excludes_another_tenants_identical_vector(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        config(['geo.rag.sqlite_fallback_enabled' => false]);
        $query = 'AI search visibility';
        $match = array_fill(0, 1024, 0.0);
        $match[0] = 1.0;
        $provider = \Mockery::mock(AiProvider::class);
        // 检索调用 embed($query, 'embedding', [归属与幂等选项])；这里只钉住查询文本和模型。
        $provider->shouldReceive('embed')->once()->with($query, 'embedding', \Mockery::type('array'))->andReturn(['embedding' => $match, 'tokens' => 0]);

        $foreign = Tenant::create(['subdomain' => 'pg-foreign', 'name' => 'Foreign', 'plan' => 'basic', 'status' => 'active']);
        $this->chunk($foreign, $match, 'Foreign identical vector');
        $tenant = Tenant::create(['subdomain' => 'pg-owned', 'name' => 'Owned', 'plan' => 'basic', 'status' => 'active']);
        $expected = $this->chunk($tenant, $match, 'Owned matching vector');
        $this->chunk($tenant, array_map(fn ($value) => -$value, $match), 'Owned opposite vector');
        TenantContext::set($tenant);
        $this->configureWorkspaceModelSlots($tenant);

        $results = (new KnowledgeRetrievalServiceImpl($provider))->retrieve($query, topK: 5, threshold: 0.99, filters: ['project_id' => $this->knowledgeProjectId($tenant->id)]);

        $this->assertCount(1, $results);
        $this->assertSame($expected->id, $results[0]->chunkId);
        $this->assertSame('Owned matching vector', $results[0]->content);
        $this->assertGreaterThan(0.99, $results[0]->vectorScore);
        $stored = DB::selectOne('SELECT vector_dims(embedding) AS dimensions FROM knowledge_chunks WHERE id = ?', [$expected->id]);
        $this->assertSame(1024, $stored->dimensions);
    }

    private function chunk(Tenant $tenant, array $embedding, string $content): KnowledgeChunk
    {
        TenantContext::set($tenant);
        $this->configureWorkspaceModelSlots($tenant);
        $projectId = $this->knowledgeProjectId($tenant->id);
        $base = KnowledgeBase::create(['tenant_id' => $tenant->id, 'project_id' => $projectId, 'name' => $content, 'status' => 'active']);
        $document = app(\App\Services\AI\ModelConfiguration::class)->createDocument([
            'tenant_id' => $tenant->id, 'knowledge_base_id' => $base->id,
            'project_id' => $projectId,
            'title' => $content, 'source_type' => 'manual', 'status' => 'ready',
            'review_status' => 'approved', 'chunk_count' => 1,
        ]);

        return KnowledgeChunk::create([
            'tenant_id' => $tenant->id, 'document_id' => $document->id,
            'chunk_index' => 0, 'content' => $content, 'embedding' => $embedding,
        ]);
    }
}
