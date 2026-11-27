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
 * A3：GET /api/v1/knowledge-bases/{kb}/documents/{document}/chunks
 *
 * 验收点：
 *   1. ready 文档返回切片正文，且响应里不含 embedding（显式 select 拦掉）
 *   2. document 不属于这个 kb → 404（与 destroyDocument 同款校验）
 *   3. 跨租户拿别人的 document id → 404（BelongsToTenant 全局作用域）
 *   4. 分页 per_page 默认 20、上限 100
 */
class KnowledgeDocumentChunksEndpointTest extends TestCase
{
    use \Tests\Support\UsesWorkspaceModels;
    use RefreshDatabase, \Tests\Support\KnowledgeProjectFixtures;

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

        return KnowledgeBase::query()->create([
            'tenant_id' => $tenant->id,
            'project_id' => self::knowledgeProjectId($tenant->id),
            'name' => 'Chunks Base',
            'status' => 'active',
        ]);
    }

    private function ingest(KnowledgeBase $kb, string $content): KnowledgeDocument
    {
        Queue::fake();
        // B7/§7：上传必带 project_id（必填校验）；project 建在 kb 同租户名下。
        $owner = User::factory()->create(['tenant_id' => $kb->tenant_id]);
        $project = new \App\Models\Project();
        $project->forceFill([
            'tenant_id' => $kb->tenant_id,
            'owner_id' => $owner->id,
            'name' => '归属项目',
            'status' => 'active',
        ])->save();
        $this->withServerVariables([
            'HTTP_HOST' => $kb->tenant->subdomain . '.geo.example.test',
        ])->postJson("/api/v1/knowledge-bases/{$kb->id}/documents", [
            'file' => UploadedFile::fake()->createWithContent('note.txt', $content),
            'project_id' => $kb->project_id,
        ], ['X-Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()])->assertStatus(202);

        $docId = KnowledgeDocument::query()->where('knowledge_base_id', $kb->id)->sole()->id;

        $job = collect(Queue::pushed(IngestKnowledgeDocumentJob::class))->first();
        $this->app->forgetInstance(DocumentIngestionService::class);
        $job->handle($this->app->make(DocumentIngestionService::class));

        return KnowledgeDocument::findOrFail($docId);
    }

    public function test_ready_document_returns_chunks_without_embeddings(): void
    {
        $kb = $this->signIn('chunks-a');
        $doc = $this->ingest($kb, str_repeat('解析结果可见验收正文内容。', 200));

        $this->assertSame('ready', $doc->status);
        $this->assertGreaterThan(0, $doc->chunk_count);

        $response = $this->withServerVariables([
            'HTTP_HOST' => $kb->tenant->subdomain . '.geo.example.test',
        ])->getJson("/api/v1/knowledge-bases/{$kb->id}/documents/{$doc->id}/chunks");

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertGreaterThan(0, count($json['data']));
        $this->assertArrayHasKey('content', $json['data'][0]);
        $this->assertArrayHasKey('chunk_index', $json['data'][0]);
        // 关键：embedding 绝不能出现在响应里
        $this->assertArrayNotHasKey('embedding', $json['data'][0]);
        // 整段 JSON 序列化里也不许出现 embedding 键
        $this->assertStringNotContainsString('"embedding"', $response->getContent());
    }

    public function test_document_not_belonging_to_kb_returns_404(): void
    {
        $kbA = $this->signIn('chunks-b');
        $doc = $this->ingest($kbA, str_repeat('跨库校验验收正文内容。', 50));

        // 另一个（同租户的）知识库
        $kbB = KnowledgeBase::query()->create([
            'tenant_id' => $kbA->tenant_id,
            'project_id' => self::knowledgeProjectId($kbA->tenant_id),
            'name' => 'Other Base',
            'status' => 'active',
        ]);

        $response = $this->withServerVariables([
            'HTTP_HOST' => $kbA->tenant->subdomain . '.geo.example.test',
        ])->getJson("/api/v1/knowledge-bases/{$kbB->id}/documents/{$doc->id}/chunks");

        $response->assertStatus(404);
    }

    public function test_cross_tenant_document_returns_404(): void
    {
        $kbA = $this->signIn('chunks-c');
        $doc = $this->ingest($kbA, str_repeat('跨租户校验验收正文内容。', 50));

        // 另一租户
        $kbOther = $this->signIn('chunks-d');

        $response = $this->withServerVariables([
            'HTTP_HOST' => $kbOther->tenant->subdomain . '.geo.example.test',
        ])->getJson("/api/v1/knowledge-bases/{$kbOther->id}/documents/{$doc->id}/chunks");

        // BelongsToTenant 全局作用域让 document 在别的租户下根本查不到 → 路由模型绑定 404
        $response->assertStatus(404);
    }

    public function test_per_page_default_and_cap(): void
    {
        $kb = $this->signIn('chunks-e');
        $doc = $this->ingest($kb, str_repeat('分页上限验收正文内容。', 600));

        // 默认 per_page=20：返回条数应 ≤ 20
        $default = $this->withServerVariables([
            'HTTP_HOST' => $kb->tenant->subdomain . '.geo.example.test',
        ])->getJson("/api/v1/knowledge-bases/{$kb->id}/documents/{$doc->id}/chunks");
        $default->assertStatus(200);
        $this->assertLessThanOrEqual(20, count($default->json('data')));

        // 越界 per_page=9999 应被压到 100（Laravel 分页信息在顶层，不在 meta 下）
        $capped = $this->withServerVariables([
            'HTTP_HOST' => $kb->tenant->subdomain . '.geo.example.test',
        ])->getJson("/api/v1/knowledge-bases/{$kb->id}/documents/{$doc->id}/chunks?per_page=9999");
        $capped->assertStatus(200);
        $this->assertLessThanOrEqual(100, count($capped->json('data')));
        $this->assertSame(100, (int) $capped->json('per_page'));
    }
}
