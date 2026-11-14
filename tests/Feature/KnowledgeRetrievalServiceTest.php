<?php

namespace Tests\Feature;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Tenant;
use App\Services\AI\MockAiProvider;
use App\Services\Knowledge\KnowledgeRetrievalServiceImpl;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeRetrievalServiceTest extends TestCase
{
    use \Tests\Support\UsesWorkspaceModels;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TenantContext::forget();

        parent::tearDown();
    }

    public function test_sqlite_retrieval_uses_php_similarity_instead_of_pgvector_sql(): void
    {
        config([
            'geo.rag.require_pgvector_in_production' => true,
            'geo.rag.sqlite_fallback_enabled' => true,
        ]);

        $tenant = Tenant::query()->create([
            'subdomain' => 'retrieval-sqlite',
            'name' => 'Retrieval SQLite',
            'plan' => 'basic',
            'status' => 'active',
        ]);
        TenantContext::set($tenant);
        $this->configureWorkspaceModelSlots($tenant);

        $provider = new MockAiProvider();
        $service = new KnowledgeRetrievalServiceImpl($provider);
        $query = 'AI search visibility';

        $owner = \App\Models\User::factory()->create(['tenant_id' => $tenant->id]);
        $project = new \App\Models\Project();
        $project->forceFill(['tenant_id' => $tenant->id, 'owner_id' => $owner->id, 'name' => '业务项目', 'status' => 'active'])->save();
        $knowledgeBase = KnowledgeBase::query()->create([
            'project_id' => $project->id,
            'tenant_id' => $tenant->id,
            'name' => 'Test Base',
            'status' => 'active',
        ]);
        $document = app(\App\Services\AI\ModelConfiguration::class)->createDocument([
            'tenant_id' => $tenant->id,
            'knowledge_base_id' => $knowledgeBase->id,
            'project_id' => $project->id,
            'title' => 'Visibility Guide',
            'source_type' => 'manual',
            'status' => 'ready',
            'review_status' => 'approved',
            'chunk_count' => 2,
        ]);

        KnowledgeChunk::query()->create([
            'tenant_id' => $tenant->id,
            'document_id' => $document->id,
            'chunk_index' => 0,
            'content' => 'Matching AI search visibility guidance.',
            'section_title' => 'AI search visibility',
            'embedding' => $provider->embed($query)['embedding'],
        ]);
        KnowledgeChunk::query()->create([
            'tenant_id' => $tenant->id,
            'document_id' => $document->id,
            'chunk_index' => 1,
            'content' => 'Unrelated billing notes.',
            'section_title' => 'Billing',
            'embedding' => $provider->embed('billing invoices and orders')['embedding'],
        ]);

        $results = $service->retrieve($query, topK: 1, threshold: 0.0, filters: ['project_id' => $project->id]);

        $this->assertCount(1, $results);
        $this->assertSame('Matching AI search visibility guidance.', $results[0]->content);
        $this->assertGreaterThan(0.9, $results[0]->vectorScore);

        // Archiving preserves the source and chunks while removing it from default recall.
        $document->update(['review_status' => 'archived']);
        $this->assertSame([], $service->retrieve($query, topK: 5, threshold: 0.0,
            filters: ['project_id' => $project->id]));
        $this->assertDatabaseHas('knowledge_chunks', ['document_id' => $document->id]);
    }

    public function test_production_sqlite_retrieval_is_disabled_when_pgvector_is_required(): void
    {
        config([
            'app.env' => 'production',
            'geo.rag.require_pgvector_in_production' => true,
            'geo.rag.sqlite_fallback_enabled' => true,
        ]);

        $tenant = Tenant::query()->create([
            'subdomain' => 'retrieval-production',
            'name' => 'Retrieval Production',
            'plan' => 'basic',
            'status' => 'active',
        ]);
        TenantContext::set($tenant);
        $this->configureWorkspaceModelSlots($tenant);

        $provider = new MockAiProvider();
        $service = new KnowledgeRetrievalServiceImpl($provider);
        $query = 'AI search visibility';

        $owner = \App\Models\User::factory()->create(['tenant_id' => $tenant->id]);
        $project = new \App\Models\Project();
        $project->forceFill(['tenant_id' => $tenant->id, 'owner_id' => $owner->id, 'name' => '业务项目', 'status' => 'active'])->save();
        $knowledgeBase = KnowledgeBase::query()->create([
            'project_id' => $project->id,
            'tenant_id' => $tenant->id,
            'name' => 'Production Base',
            'status' => 'active',
        ]);
        $document = app(\App\Services\AI\ModelConfiguration::class)->createDocument([
            'tenant_id' => $tenant->id,
            'knowledge_base_id' => $knowledgeBase->id,
            'project_id' => $project->id,
            'title' => 'Production Visibility Guide',
            'source_type' => 'manual',
            'status' => 'ready',
            'review_status' => 'approved',
            'chunk_count' => 1,
        ]);

        KnowledgeChunk::query()->create([
            'tenant_id' => $tenant->id,
            'document_id' => $document->id,
            'chunk_index' => 0,
            'content' => 'Matching AI search visibility guidance.',
            'section_title' => 'AI search visibility',
            'embedding' => $provider->embed($query)['embedding'],
        ]);

        $this->assertSame([], $service->retrieve($query, topK: 1, threshold: 0.0, filters: ['project_id' => $project->id]));
    }
}
