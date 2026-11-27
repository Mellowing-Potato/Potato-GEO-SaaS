<?php

namespace Tests\Feature;

use App\Models\{KnowledgeBase, KnowledgeChunk, KnowledgeDocument, Project, Tenant, User};
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Preserve the real Laravel paginator wire shape consumed by the workbench. */
class KnowledgePaginationContractTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Project $project;
    private KnowledgeBase $base;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.tenant_domain' => 'geo.example.test']);
        $this->tenant = Tenant::create(['name' => '分页测试工作区', 'subdomain' => 'pagination', 'status' => 'active']);
        TenantContext::set($this->tenant);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'admin']);
        $this->project = Project::create(['owner_id' => $this->user->id, 'name' => '分页测试项目']);
        $this->base = KnowledgeBase::create(['project_id' => $this->project->id, 'name' => '分页测试知识库', 'status' => 'active']);
        Sanctum::actingAs($this->user, ['knowledge:read', 'knowledge:write']);
        $this->withServerVariables(['HTTP_HOST' => 'pagination.geo.example.test'])
            ->withHeader('X-Tenant', $this->tenant->subdomain)->withHeader('X-Project-Id', (string) $this->project->id);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    public static function documentCounts(): array
    {
        return ['second page' => [21], 'third page' => [41]];
    }

    #[DataProvider('documentCounts')]
    public function test_document_pages_use_top_level_totals_and_preserve_every_id(int $count): void
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->document($i)->id;
        }
        $actual = [];
        $lastPage = (int) ceil($count / 20);
        for ($page = 1; $page <= $lastPage; $page++) {
            $response = $this->getJson($this->documentsUrl().'?page='.$page)->assertOk()
                ->assertJsonPath('current_page', $page)->assertJsonPath('last_page', $lastPage)
                ->assertJsonPath('total', $count)->assertJsonPath('per_page', 20)->assertJsonMissingPath('meta');
            $actual = array_merge($actual, array_column($response->json('data'), 'id'));
        }
        $this->assertSame($ids, $actual, 'Every document must remain accessible across the default 20-row boundary.');
        $this->assertSame($count, count(array_unique($actual)));
    }

    public function test_real_http_paginators_drive_document_text_and_image_frontend_handlers(): void
    {
        $documents = [];
        for ($i = 0; $i < 21; $i++) {
            $documents[] = $this->document($i);
        }
        $text = $documents[0];
        $vision = $documents[1];
        $this->chunks($text, 21, false);
        $this->chunks($vision, 101, true);
        $documentPages = [
            $this->getJson($this->documentsUrl().'?page=1')->assertOk()->assertJsonPath('total', 21)->json(),
            $this->getJson($this->documentsUrl().'?page=2')->assertOk()->assertJsonPath('last_page', 2)->assertJsonCount(1, 'data')->json(),
        ];
        $textPages = $this->chunkPages($text, 20, 21);
        $visionPages = $this->chunkPages($vision, 100, 101);
        $this->assertSame(21, $textPages[1]['data'][0]['chunk_index'] + 1);
        $this->assertSame(101, $visionPages[1]['data'][0]['chunk_index'] + 1);
        $this->assertSame(2, $visionPages[1]['data'][0]['page']);

        // Pass TestResponse JSON straight to the real JS handlers. No hand-built
        // `meta` fixture can hide a mismatch with Laravel's top-level paginator.
        $runner = new Process(['node', base_path('tests/js/knowledge-chunk-pagination.test.mjs'), '--http-contract'], base_path());
        $runner->setInput(json_encode([
            'base_id' => $this->base->id, 'document_pages' => $documentPages,
            'text_document' => $text->fresh()->toArray(), 'text_pages' => $textPages,
            'vision_document' => $vision->fresh()->toArray(), 'vision_pages' => $visionPages,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $runner->setTimeout(20);
        $runner->run();
        $this->assertSame(0, $runner->getExitCode(), $runner->getOutput().$runner->getErrorOutput());
    }

    public function test_paginated_documents_and_chunks_keep_workspace_project_and_parent_boundaries(): void
    {
        $document = $this->document(0);
        $this->chunks($document, 21, false);
        $otherProject = Project::create(['owner_id' => $this->user->id, 'name' => '另一个项目']);
        $otherBase = KnowledgeBase::create(['tenant_id' => $this->tenant->id, 'project_id' => $otherProject->id, 'name' => '其他项目知识库', 'status' => 'active']);

        $this->getJson('/api/v1/knowledge-bases/'.$otherBase->id.'/documents?page=2')->assertNotFound();
        $this->getJson('/api/v1/knowledge-bases/'.$otherBase->id.'/documents/'.$document->id.'/chunks?page=2')->assertNotFound();
        $this->withHeader('X-Project-Id', (string) $otherProject->id);
        $this->getJson($this->documentsUrl().'?page=2')->assertNotFound();
        $this->getJson($this->chunksUrl($document).'?page=2')->assertNotFound();
        $this->withHeader('X-Project-Id', (string) $this->project->id);
        $sameProjectBase = KnowledgeBase::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => '同项目其他知识库', 'status' => 'active']);
        $this->getJson('/api/v1/knowledge-bases/'.$sameProjectBase->id.'/documents/'.$document->id.'/chunks?page=2')->assertNotFound();

        $otherTenant = Tenant::create(['name' => '另一个工作区', 'subdomain' => 'pagination-other', 'status' => 'active']);
        $foreignUser = User::factory()->create(['tenant_id' => $otherTenant->id, 'role' => 'admin']);
        Sanctum::actingAs($foreignUser, ['knowledge:read']);
        $this->withServerVariables(['HTTP_HOST' => 'pagination-other.geo.example.test'])->withHeader('X-Tenant', $otherTenant->subdomain);
        $this->getJson($this->documentsUrl().'?page=2')->assertNotFound();
        $this->getJson($this->chunksUrl($document).'?page=2')->assertNotFound();
    }

    private function document(int $index): KnowledgeDocument
    {
        $document = KnowledgeDocument::create(['tenant_id' => $this->tenant->id, 'knowledge_base_id' => $this->base->id, 'project_id' => $this->project->id,
            'title' => '材料 '.($index + 1), 'source_type' => 'upload', 'mime_type' => 'text/plain', 'status' => 'ready',
            'metadata' => ['extraction' => ['method' => 'text_layer']]]);
        $document->forceFill(['created_at' => now()->subSeconds($index)])->save();

        return $document;
    }

    private function chunks(KnowledgeDocument $document, int $count, bool $vision): void
    {
        $pages = [];
        for ($i = 0; $i < $count; $i++) {
            $pages[$i] = $vision ? ($i < 100 ? 1 : 2) : null;
            KnowledgeChunk::create(['document_id' => $document->id, 'chunk_index' => $i,
                'content' => ($vision ? '图文' : '纯文字').'切片正文 '.($i + 1), 'token_count' => 4]);
        }
        $document->update(['chunk_count' => $count, 'metadata' => ['extraction' => [
            'method' => $vision ? 'vision' : 'text_layer', 'pages' => $vision ? 2 : null, 'chunk_pages' => $pages,
        ]]]);
    }

    private function chunkPages(KnowledgeDocument $document, int $perPage, int $total): array
    {
        $pages = [];
        for ($page = 1; $page <= 2; $page++) {
            $response = $this->getJson($this->chunksUrl($document).'?per_page='.$perPage.'&page='.$page)->assertOk()
                ->assertJsonPath('current_page', $page)->assertJsonPath('last_page', 2)
                ->assertJsonPath('total', $total)->assertJsonPath('per_page', $perPage)->assertJsonMissingPath('meta');
            $this->assertStringNotContainsString('"embedding"', $response->getContent());
            $pages[] = $response->json();
        }

        return $pages;
    }

    private function documentsUrl(): string
    {
        return '/api/v1/knowledge-bases/'.$this->base->id.'/documents';
    }

    private function chunksUrl(KnowledgeDocument $document): string
    {
        return $this->documentsUrl().'/'.$document->id.'/chunks';
    }
}
