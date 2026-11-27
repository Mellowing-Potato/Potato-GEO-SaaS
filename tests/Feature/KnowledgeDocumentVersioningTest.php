<?php

namespace Tests\Feature;

use App\Jobs\IngestKnowledgeDocumentJob;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AI\MockAiProvider;
use App\Services\Contracts\AiProvider;
use App\Services\Knowledge\DocumentIngestionService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesSafeOutbound;
use Tests\TestCase;

class KnowledgeDocumentVersioningTest extends TestCase
{
    use \Tests\Support\UsesWorkspaceModels;
    use FakesSafeOutbound, RefreshDatabase;

    public function test_recrawl_reuses_pending_replacement_then_archives_old_document_after_success(): void
    {
        config(['app.tenant_domain' => 'geo.example.test']);
        $this->app->bind(AiProvider::class, fn () => new MockAiProvider());
        $this->fakeSafeOutbound('<html><main><article>新版服务说明</article><article>新版交付流程</article></main></html>');
        Queue::fake();
        $tenant = Tenant::create(['subdomain' => 'version-test', 'name' => 'Version Test', 'plan' => 'basic', 'status' => 'active']);
        TenantContext::set($tenant);
        $this->configureWorkspaceModelSlots($tenant);
        $this->configureWorkspaceModelSlots($tenant);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        $project = new Project();
        $project->forceFill(['tenant_id' => $tenant->id, 'owner_id' => $user->id,
            'name' => '项目', 'status' => 'active'])->save();
        $base = KnowledgeBase::create(['tenant_id' => $tenant->id, 'project_id' => $project->id,
            'name' => '资料库', 'status' => 'active']);
        $old = app(\App\Services\AI\ModelConfiguration::class)->createDocument([
            'tenant_id' => $tenant->id, 'project_id' => $project->id, 'knowledge_base_id' => $base->id,
            'title' => '官网', 'source_type' => 'url', 'source_url' => 'https://example.com/geo',
            'status' => 'ready', 'review_status' => 'unreviewed',
        ]);
        Sanctum::actingAs($user, ['*']);
        $this->withServerVariables(['HTTP_HOST' => 'version-test.geo.example.test'])
            ->withHeader('X-Tenant', 'version-test')->withHeader('X-Project-Id', $project->id);
        $path = "/api/v1/knowledge-bases/{$base->id}/documents/{$old->id}/recrawl";
        $first = $this->postJson($path, [], ['X-Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()])->assertStatus(202);
        $newId = $first->json('data.id');
        $this->postJson($path, [], ['X-Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()])->assertStatus(202)->assertJsonPath('data.id', $newId);
        $this->assertSame('ready', $old->fresh()->status);
        $this->assertSame('unreviewed', $old->fresh()->review_status);
        $this->assertSame($old->id, KnowledgeDocument::findOrFail($newId)->metadata['supersedes_document_id']);
        Queue::assertPushed(IngestKnowledgeDocumentJob::class, 1);

        $job = collect(Queue::pushed(IngestKnowledgeDocumentJob::class))->first();
        $job->handle($this->app->make(DocumentIngestionService::class));
        $this->assertSame('ready', KnowledgeDocument::findOrFail($newId)->status);
        $this->assertGreaterThan(10, KnowledgeDocument::findOrFail($newId)->metadata['extraction_quality']['chars']);
        $this->assertStringContainsString('新版交付流程',
            KnowledgeDocument::findOrFail($newId)->chunks()->firstOrFail()->content);
        $this->assertSame('archived', $old->fresh()->review_status);
        $this->assertSame('https://example.com/geo', $old->fresh()->source_url);
    }
}
