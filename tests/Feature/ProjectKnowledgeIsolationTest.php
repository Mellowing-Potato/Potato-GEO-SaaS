<?php

namespace Tests\Feature;

use App\Models\{EnterpriseKnowledgeProject, KnowledgeBase, KnowledgeChunk, KnowledgeDocument, Project, Tenant, User};
use App\Services\AI\MockAiProvider;
use App\Services\Knowledge\KnowledgeRetrievalServiceImpl;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectKnowledgeIsolationTest extends TestCase
{
    use \Tests\Support\UsesWorkspaceModels;
    use RefreshDatabase;

    private Tenant $tenant;
    private Project $a;
    private Project $b;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->tenant = Tenant::create(['name' => '知识隔离测试', 'subdomain' => 'knowledge-isolation', 'status' => 'active', 'plan' => 'basic']);
        TenantContext::set($this->tenant);
        $this->configureWorkspaceModelSlots($this->tenant);
        $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'admin']);
        foreach (['a', 'b'] as $name) {
            $this->$name = new Project();
            $this->$name->forceFill(['tenant_id' => $this->tenant->id, 'owner_id' => $user->id, 'name' => $name, 'status' => 'active'])->save();
        }
        Sanctum::actingAs($user, ['*']);
        $this->withHeader('X-Tenant', $this->tenant->subdomain)->withHeader('X-Project-Id', $this->a->id);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    private function base(?Project $project): KnowledgeBase
    {
        return KnowledgeBase::create(['tenant_id' => $this->tenant->id, 'project_id' => $project?->id, 'name' => $project?->name ?? '历史资料', 'status' => 'active']);
    }

    private function document(KnowledgeBase $base, ?Project $owner): KnowledgeDocument
    {
        $doc = app(\App\Services\AI\ModelConfiguration::class)->createDocument(['tenant_id' => $this->tenant->id, 'knowledge_base_id' => $base->id, 'project_id' => $owner?->id, 'title' => '同名产品说明', 'source_type' => 'text', 'status' => 'ready']);
        $doc->update(['file_path' => "knowledge/{$doc->tenant_id}/{$base->id}/{$doc->id}/source.txt"]);
        Storage::disk('local')->put($doc->file_path, '同名产品说明');
        KnowledgeChunk::create(['tenant_id' => $this->tenant->id, 'document_id' => $doc->id, 'chunk_index' => 0, 'content' => '同名产品说明', 'embedding' => (new MockAiProvider())->embed('同名产品说明')['embedding']]);
        return $doc;
    }

    public function test_generation_retrieval_requires_project_and_cannot_expand_to_other_projects(): void
    {
        $a = $this->base($this->a); $b = $this->base($this->b); $legacy = $this->base(null);
        $aDoc = $this->document($a, $this->a);
        $this->document($b, $this->b); $this->document($legacy, null);
        // Even malformed historical metadata must not cross the project boundary.
        $this->document($a, $this->b);
        $retrieval = new KnowledgeRetrievalServiceImpl(new MockAiProvider());
        $this->assertSame([], $retrieval->retrieve('同名产品说明'));
        $hits = $retrieval->retrieve('同名产品说明', filters: ['project_id' => $this->a->id]);
        $this->assertSame([$aDoc->id], array_map(fn ($hit) => $hit->documentId, $hits));
        $this->assertSame([], $retrieval->retrieve('同名产品说明', filters: ['project_id' => $this->a->id, 'knowledge_base_ids' => [$b->id, $legacy->id]]));
        $this->assertSame([], $retrieval->retrieve('同名产品说明', filters: ['project_id' => 999999]));
    }

    public function test_management_lists_stats_and_resource_actions_are_project_scoped(): void
    {
        $a = $this->base($this->a); $b = $this->base($this->b); $legacy = $this->base(null);
        $this->document($a, $this->a); $this->document($b, $this->b);
        $this->getJson('/api/v1/knowledge-bases')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->id);
        $this->getJson('/api/v1/knowledge-bases/stats')->assertOk()->assertJsonPath('total_documents', 1);
        $this->getJson("/api/v1/knowledge-bases/{$b->id}/documents")->assertNotFound();
        $this->deleteJson("/api/v1/knowledge-bases/{$b->id}")->assertNotFound();
        $this->getJson("/api/v1/knowledge-bases/{$legacy->id}")->assertStatus(409);
        $this->postJson("/api/v1/knowledge-bases/{$a->id}/ingest-url", ['url' => 'https://example.com', 'project_id' => $this->b->id], ['X-Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()])->assertUnprocessable();
        $this->postJson("/api/v1/knowledge-bases/{$a->id}/search-test", ['query' => '说明', 'knowledge_base_ids' => [$b->id]])->assertNotFound();
        $this->postJson('/api/v1/knowledge-bases', ['name' => '新库'])->assertCreated()->assertJsonPath('data.project_id', $this->a->id);
        $this->withHeader('X-Project-Id', '')->getJson('/api/v1/knowledge-bases')->assertUnprocessable();
    }

    public function test_enterprise_archives_follow_the_selected_project(): void
    {
        $this->postJson('/api/v1/enterprise-knowledge', ['name' => '企业介绍', 'content' => '本项目的企业资料'])->assertCreated()->assertJsonPath('data.project_id', $this->a->id);
        $foreign = EnterpriseKnowledgeProject::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->b->id, 'name' => '另一品牌', 'status' => 'draft']);
        $this->getJson('/api/v1/enterprise-knowledge')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/enterprise-knowledge/{$foreign->id}/publish")->assertNotFound();
        $this->deleteJson("/api/v1/enterprise-knowledge/{$foreign->id}")->assertNotFound();
    }

    public function test_legacy_assignment_preserves_document_identity_files_and_existing_project_attribution(): void
    {
        $legacy = $this->base(null);
        $unknown = $this->document($legacy, null); $bDoc = $this->document($legacy, $this->b);
        Storage::disk('local')->put($bDoc->storageDirectory().'/pages/p1.jpg', 'image');
        $this->getJson('/api/v1/knowledge-legacy')->assertOk()->assertJsonCount(1, 'bases');
        $this->postJson('/api/v1/knowledge-legacy/assign', ['kind' => 'base', 'id' => $legacy->id])->assertOk();
        $this->assertSame($this->a->id, $unknown->fresh()->project_id);
        $this->assertSame($this->b->id, $bDoc->fresh()->project_id);
        $bBaseId = $bDoc->fresh()->knowledge_base_id;
        $this->assertNotEquals($legacy->id, $bBaseId);
        $this->deleteJson("/api/v1/knowledge-bases/{$legacy->id}")->assertOk();
        Storage::disk('local')->assertExists($bDoc->file_path);
        $this->assertTrue(KnowledgeChunk::where('document_id', $bDoc->id)->exists());
        $this->withHeader('X-Project-Id', $this->b->id)->get("/api/v1/knowledge-bases/{$bBaseId}/documents/{$bDoc->id}/pages/1/image")->assertOk();
    }

    public function test_legacy_archives_cannot_be_assigned_separately_from_their_published_base(): void
    {
        $base = $this->base(null);
        $archive = EnterpriseKnowledgeProject::create(['tenant_id' => $this->tenant->id, 'name' => '历史档案', 'status' => 'published', 'published_knowledge_base_id' => $base->id]);
        $this->postJson('/api/v1/knowledge-legacy/assign', ['kind' => 'archive', 'id' => $archive->id])->assertStatus(409);
        $this->postJson('/api/v1/knowledge-legacy/assign', ['kind' => 'base', 'id' => $base->id])->assertOk();
        $this->assertSame($this->a->id, $archive->fresh()->project_id);
    }

    public function test_other_accounts_cannot_list_read_or_assign_this_accounts_knowledge(): void
    {
        $owned = $this->base($this->a); $legacy = $this->base(null);
        $this->document($owned, $this->a);
        $other = Tenant::create(['name' => '另一账户', 'subdomain' => 'other-knowledge', 'status' => 'active', 'plan' => 'basic']);
        TenantContext::set($other);
        $this->configureWorkspaceModelSlots($other);
        $user = User::factory()->create(['tenant_id' => $other->id, 'role' => 'admin']);
        $project = new Project();
        $project->forceFill(['tenant_id' => $other->id, 'owner_id' => $user->id, 'name' => '独立项目', 'status' => 'active'])->save();
        Sanctum::actingAs($user, ['*']);
        $this->withHeader('X-Tenant', $other->subdomain)->withHeader('X-Project-Id', $project->id);
        $this->getJson('/api/v1/knowledge-bases')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/knowledge-legacy')->assertOk()->assertJsonCount(0, 'bases');
        $this->getJson("/api/v1/knowledge-bases/{$owned->id}/documents")->assertNotFound();
        $this->postJson('/api/v1/knowledge-legacy/assign', ['kind' => 'base', 'id' => $legacy->id])->assertNotFound();
        $retrieval = new KnowledgeRetrievalServiceImpl(new MockAiProvider());
        $this->assertSame([], $retrieval->retrieve('同名产品说明', filters: ['project_id' => $this->a->id]));
    }
}
