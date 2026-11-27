<?php

namespace Tests\Feature;

use App\Jobs\IngestKnowledgeDocumentJob;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AI\MockAiProvider;
use App\Services\Contracts\AiProvider;
use App\Services\Knowledge\DocumentIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 第三层（检索测试框）：POST /api/v1/knowledge-bases/{kb}/search-test
 *
 * 验收点（执行书 §3.1）：
 *   1. 缺 query → 422
 *   2. top_k 越界（99）→ 钳到 10，不 422
 *   3. 跨租户 knowledge_base_ids → 404（租户隔离）
 *   4. 命中切片结构正确（chunk_id / document_id / document_title / vector_score /
 *      relevance_score / page / section / content 截断）
 *   5. 空结果 hits:[] + 明确 meta，不报错
 *   6. 不传 knowledge_base_ids 时只在当前知识库内检索
 *   7. page 从 document metadata 的 extraction.chunk_pages 映射取出
 *
 * 铁律（执行书 §0.6 / §3.1.7）：端点层不 mock 检索，直接跑真实
 * KnowledgeRetrievalServiceImpl（SQLite 相似度回退，测试环境可用）。
 */
class KnowledgeSearchTest extends TestCase
{
    use \Tests\Support\UsesWorkspaceModels;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.tenant_domain' => 'geo.example.test']);
        $this->app->bind(AiProvider::class, fn () => new MockAiProvider());
    }

    private function signIn(string $subdomain): KnowledgeBase
    {
        $tenant = Tenant::query()->create([
            'subdomain' => $subdomain,
            'name' => strtoupper($subdomain),
            'plan' => 'basic',
            'status' => 'active',
        ]);

        $this->configureWorkspaceModelSlots($tenant);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain),
            'role' => 'admin',
        ]);
        Sanctum::actingAs($user, ['knowledge:read', 'knowledge:write']);
        $this->withServerVariables(['HTTP_HOST' => $subdomain . '.geo.example.test'])
            ->withHeader('X-Tenant', $subdomain);

        $project = new \App\Models\Project();
        $project->forceFill(['tenant_id' => $tenant->id, 'owner_id' => $user->id, 'name' => '业务项目', 'status' => 'active'])->save();
        return KnowledgeBase::query()->create([
            'project_id' => $project->id,
            'tenant_id' => $tenant->id,
            'name' => 'Search Base',
            'status' => 'active',
        ]);
    }

    private function ingest(KnowledgeBase $kb, string $content): KnowledgeDocument
    {
        Queue::fake();
        $this->withServerVariables([
            'HTTP_HOST' => $kb->tenant->subdomain . '.geo.example.test',
        ])->postJson("/api/v1/knowledge-bases/{$kb->id}/documents", [
            'file' => UploadedFile::fake()->createWithContent('note.txt', $content),
            'project_id' => $kb->project_id,
        ], ['X-Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()])->assertStatus(202);

        $docId = KnowledgeDocument::query()->where('knowledge_base_id', $kb->id)->orderByDesc('id')->first()->id;

        $job = collect(Queue::pushed(IngestKnowledgeDocumentJob::class))->first();
        $this->app->forgetInstance(DocumentIngestionService::class);
        $job->handle($this->app->make(DocumentIngestionService::class));

        return KnowledgeDocument::findOrFail($docId);
    }

    /**
     * 直接造一篇 ready 文档 + 一个切片，切片 embedding 用真实 MockAiProvider
     * 算「文本」本身的向量。这样用「文本」本身做 query 时余弦相似度 = 1.0，
     * 检索结果确定（不依赖 MockAiProvider 哈希向量的随机相似度），适合验证
     * 默认范围 / 跨库过滤等「范围」类断言。检索服务仍是真实实现（SQLite 回退）。
     */
    private function makeReadyChunk(KnowledgeBase $kb, string $text, array $pages = []): KnowledgeDocument
    {
        $doc = app(\App\Services\AI\ModelConfiguration::class)->createDocument([
            'tenant_id' => $kb->tenant_id,
            'knowledge_base_id' => $kb->id,
            'project_id' => $kb->project_id,
            'title' => 'doc-' . uniqid(),
            'filename' => 'doc.txt',
            'source_type' => 'text',
            'status' => 'ready',
            'mime_type' => 'text/plain',
            'file_size' => mb_strlen($text),
            'chunk_count' => 1,
            'metadata' => $pages ? ['extraction' => ['chunk_pages' => $pages]] : null,
        ]);

        $embedding = (new MockAiProvider())->embed($text)['embedding'];
        KnowledgeChunk::create([
            'tenant_id' => $kb->tenant_id,
            'document_id' => $doc->id,
            'chunk_index' => 0,
            'content' => $text,
            'section_title' => '',
            'token_count' => 10,
            'embedding' => $embedding,
        ]);

        return $doc;
    }

    private function search(KnowledgeBase $kb, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->withServerVariables([
            'HTTP_HOST' => $kb->tenant->subdomain . '.geo.example.test',
        ])->withHeader('Idempotency-Key', 'test-search-'.\Illuminate\Support\Str::uuid())
            ->postJson("/api/v1/knowledge-bases/{$kb->id}/search-test", $body);
    }

    public function test_search_requires_query(): void
    {
        $kb = $this->signIn('search-req');

        $this->search($kb, ['top_k' => 5])->assertStatus(422);
        $this->search($kb, ['query' => '   '])->assertStatus(422);
    }

    public function test_search_requires_a_reusable_operation_key_before_embedding(): void
    {
        $kb = $this->signIn('search-key');
        $this->withServerVariables([
            'HTTP_HOST' => $kb->tenant->subdomain . '.geo.example.test',
        ])->postJson("/api/v1/knowledge-bases/{$kb->id}/search-test", [
            'query' => '检索测试',
        ])->assertStatus(422);
    }

    public function test_search_accepts_the_same_prefixed_key_used_by_api_idempotency_middleware(): void
    {
        $kb = $this->signIn('search-prefixed-key');
        $this->withServerVariables([
            'HTTP_HOST' => $kb->tenant->subdomain . '.geo.example.test',
        ])->withHeader('X-Idempotency-Key', 'search-test-prefixed-key-1')
            ->postJson("/api/v1/knowledge-bases/{$kb->id}/search-test", [
                'query' => '言及的 GEO 服务是什么？',
            ])->assertStatus(200);
    }

    public function test_search_top_k_capped(): void
    {
        $kb = $this->signIn('search-cap');
        // 大文档 → 多切片，足以触发 top_k 钳制
        $this->ingest($kb, str_repeat('检索测试上限验收正文内容。', 600));

        // top_k=99 应被钳到 10，且不应 422
        $response = $this->search($kb, ['query' => '检索测试上限验收', 'top_k' => 99]);
        $response->assertStatus(200);
        $hits = $response->json('data.hits');
        $this->assertIsArray($hits);
        $this->assertLessThanOrEqual(10, count($hits), 'top_k 必须被钳到 10');
        $this->assertGreaterThanOrEqual(1, count($hits), '应有命中');

        // top_k=5 不应超 5
        $capped5 = $this->search($kb, ['query' => '检索测试上限验收', 'top_k' => 5]);
        $capped5->assertStatus(200);
        $this->assertLessThanOrEqual(5, count($capped5->json('data.hits')));
    }

    public function test_search_tenant_isolation(): void
    {
        $kbA = $this->signIn('search-iso-a');
        $this->ingest($kbA, str_repeat('租户隔离验收正文内容。', 50));

        // 另一租户 + 它的知识库（仅作数据准备，不需要以该租户身份登录）
        $tenantB = Tenant::query()->create([
            'subdomain' => 'search-iso-b',
            'name' => 'SEARCH-ISO-B',
            'plan' => 'basic',
            'status' => 'active',
        ]);
        $foreignKb = KnowledgeBase::query()->create([
            'tenant_id' => $tenantB->id,
            'name' => 'Foreign Base',
            'status' => 'active',
        ]);

        // 以租户 A 身份请求，但 body 里塞租户 B 的 kb id → 404
        $response = $this->search($kbA, [
            'query' => '租户隔离验收',
            'knowledge_base_ids' => [$foreignKb->id],
        ]);
        $response->assertStatus(404);
    }

    public function test_search_returns_scored_chunks(): void
    {
        $kb = $this->signIn('search-struct');
        $doc1 = $this->ingest($kb, str_repeat('陈小聪是文档九里的关键人物，其姓名只在图像里出现。', 40));
        $doc2 = $this->ingest($kb, str_repeat('另一份资料讨论的是完全不同的种植体价格话题。', 40));
        $this->assertSame('ready', $doc1->status);

        $response = $this->search($kb, ['query' => '陈小聪', 'top_k' => 5]);
        $response->assertStatus(200);

        $data = $response->json('data');
        // embedding_dim 来自真实 MockAiProvider（1536 维）
        $this->assertSame(1024, $data['embedding_dim']);

        $hits = $data['hits'];
        $this->assertNotEmpty($hits, '应有召回切片');

        foreach ($hits as $hit) {
            $this->assertArrayHasKey('chunk_id', $hit);
            $this->assertArrayHasKey('document_id', $hit);
            $this->assertArrayHasKey('document_title', $hit);
            $this->assertArrayHasKey('vector_score', $hit);
            $this->assertArrayHasKey('relevance_score', $hit);
            $this->assertArrayHasKey('page', $hit);
            $this->assertArrayHasKey('section', $hit);
            $this->assertArrayHasKey('content', $hit);

            $this->assertIsNumeric($hit['vector_score']);
            $this->assertGreaterThanOrEqual(0, $hit['vector_score']);
            $this->assertLessThanOrEqual(1, $hit['vector_score']);
            $this->assertIsNumeric($hit['relevance_score']);
            $this->assertLessThanOrEqual(1, $hit['relevance_score']);

            // 正文截断到 200 字
            $this->assertLessThanOrEqual(200, mb_strlen($hit['content']));
            // document_title 非空
            $this->assertNotEmpty($hit['document_title']);
        }

        $this->assertSame(count($hits), $data['meta']['total_hits']);
    }

    public function test_search_empty_result(): void
    {
        $kb = $this->signIn('search-empty');
        // 知识库里没有任何已入库文档 → 召回为空，但不报错
        $response = $this->search($kb, ['query' => '知识库里根本没有这个词', 'top_k' => 5]);
        $response->assertStatus(200);
        $this->assertSame([], $response->json('data.hits'));
        $this->assertSame(0, $response->json('data.meta.total_hits'));
    }

    public function test_search_defaults_to_current_kb(): void
    {
        $kbA = $this->signIn('search-scope-a');
        $kbB = KnowledgeBase::query()->create([
            'tenant_id' => $kbA->tenant_id,
            'project_id' => $kbA->project_id,
            'name' => 'Other Base',
            'status' => 'active',
        ]);

        // 只在 kbB 里造一个切片，且 query 与切片正文一致（相似度 = 1.0，结果确定）
        $this->makeReadyChunk($kbB, '专属内容只存在于第二知识库');

        // 默认（不传 knowledge_base_ids）→ 只在当前知识库 kbA 内检索 → 空
        $response = $this->search($kbA, ['query' => '专属内容只存在于第二知识库']);
        $response->assertStatus(200);
        $this->assertSame([], $response->json('data.hits'));

        // 显式指定 kbB → 能召回
        $scoped = $this->search($kbA, [
            'query' => '专属内容只存在于第二知识库',
            'knowledge_base_ids' => [$kbB->id],
        ]);
        $scoped->assertStatus(200);
        $this->assertNotEmpty($scoped->json('data.hits'));
    }

    public function test_search_page_maps_from_document_metadata(): void
    {
        $kb = $this->signIn('search-page');
        $doc = $this->ingest($kb, str_repeat('页码映射验收正文内容，用于验证 page 字段。', 120));

        // 给每一页切片补上 chunk_pages 映射（index → index+1）
        $pages = [];
        foreach ($doc->chunks as $chunk) {
            $pages[$chunk->chunk_index] = $chunk->chunk_index + 1;
        }
        $doc->update([
            'metadata' => array_merge($doc->metadata ?? [], [
                'extraction' => array_merge($doc->metadata['extraction'] ?? [], [
                    'chunk_pages' => $pages,
                ]),
            ]),
        ]);

        $response = $this->search($kb, ['query' => '页码映射验收', 'top_k' => 5]);
        $response->assertStatus(200);
        $hits = $response->json('data.hits');
        $this->assertNotEmpty($hits);

        // 至少有一条命中的 page 来自 metadata 映射（非 null、整数）
        $withPage = array_filter($hits, fn ($h) => $h['page'] !== null);
        $this->assertNotEmpty($withPage, '命中的切片应能取到 page');
        foreach ($withPage as $h) {
            $this->assertIsInt($h['page']);
        }
    }
}
