<?php

namespace Tests\Feature;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * B6-1：图文对照后端。
 *
 * 验收点（执行书 B6-1 / B8）：
 *   1. 页图接口正常出图（image/jpeg）
 *   2. 跨租户请求别人的 document → 404（私有 disk，租户校验是安全底线）
 *   3. 文件不存在 / 页号不合法 → 404，不返回占位图
 *   4. chunks 接口给切片补 page 字段：有 chunk_pages 映射 → 页号；没有 → null
 */
class KnowledgeDocumentPageImageTest extends TestCase
{
    use RefreshDatabase, \Tests\Support\KnowledgeProjectFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.tenant_domain' => 'geo.example.test']);
    }

    private function signIn(string $subdomain): KnowledgeBase
    {
        $tenant = Tenant::query()->create([
            'subdomain' => $subdomain,
            'name' => strtoupper($subdomain),
            'plan' => 'basic',
            'status' => 'active',
        ]);

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
            'name' => 'Page Image Base',
            'status' => 'active',
        ]);
    }

    private function makeDocument(KnowledgeBase $kb, array $metadata = []): KnowledgeDocument
    {
        return KnowledgeDocument::create([
            'tenant_id' => $kb->tenant_id,
            'knowledge_base_id' => $kb->id,
            'title' => '图文对照测试文档',
            'source_type' => 'upload',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'status' => 'ready',
            'metadata' => $metadata,
        ]);
    }

    private function putPageImage(KnowledgeDocument $doc, int $page, string $bytes): void
    {
        Storage::disk('local')->put(
            "knowledge/{$doc->tenant_id}/{$doc->knowledge_base_id}/{$doc->id}/pages/p{$page}.jpg",
            $bytes,
        );
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('knowledge');

        parent::tearDown();
    }

    private function imageUrl(KnowledgeBase $kb, KnowledgeDocument $doc, int $page): string
    {
        return "/api/v1/knowledge-bases/{$kb->id}/documents/{$doc->id}/pages/{$page}/image";
    }

    public function test_page_image_is_served_as_jpeg(): void
    {
        $kb = $this->signIn('pageimg-a');
        $doc = $this->makeDocument($kb, ['extraction' => ['method' => 'hybrid']]);

        $jpeg = "\xFF\xD8\xFF" . str_repeat('fake-jpeg-data', 100);
        $this->putPageImage($doc, 3, $jpeg);

        // 二进制响应不能用 getJson（Accept: application/json），用普通 GET。
        // response()->file() 返回 BinaryFileResponse（发送时才流式输出），
        // 测试里断言底层文件而不是 body。
        $response = $this->get($this->imageUrl($kb, $doc, 3));

        $response->assertStatus(200);
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));

        $base = $response->baseResponse;
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, $base);
        $served = (string) file_get_contents($base->getFile()->getPathname());
        $this->assertSame($jpeg, $served, '返回的必须是盘上那张图的原始字节');
    }

    public function test_cross_tenant_page_image_returns_404(): void
    {
        $kbA = $this->signIn('pageimg-b');
        $docA = $this->makeDocument($kbA);
        $this->putPageImage($docA, 1, "\xFF\xD8\xFFfake");

        // 换到另一个租户的会话，拿 A 的 document id 去要页图。
        $kbB = $this->signIn('pageimg-c');

        $response = $this->getJson($this->imageUrl($kbB, $docA, 1));

        // BelongsToTenant 全局作用域：B 的租户下查不到这份 document → 路由模型绑定 404。
        $response->assertStatus(404);
    }

    public function test_missing_image_and_invalid_page_return_404(): void
    {
        $kb = $this->signIn('pageimg-d');
        $doc = $this->makeDocument($kb);

        // 没渲染过任何页图。
        $this->getJson($this->imageUrl($kb, $doc, 1))->assertStatus(404);

        // 页号不合法：0 / 负数 / 超大。路由 whereNumber 先拦住非数字。
        $this->getJson($this->imageUrl($kb, $doc, 0))->assertStatus(404);
        $this->getJson($this->imageUrl($kb, $doc, 10001))->assertStatus(404);
    }

    public function test_document_from_other_kb_returns_404(): void
    {
        $kbA = $this->signIn('pageimg-e');
        $doc = $this->makeDocument($kbA);
        $this->putPageImage($doc, 1, "\xFF\xD8\xFFfake");

        $kbB = KnowledgeBase::query()->create([
            'tenant_id' => $kbA->tenant_id,
            'project_id' => self::knowledgeProjectId($kbA->tenant_id),
            'name' => 'Other Base',
            'status' => 'active',
        ]);

        $this->getJson($this->imageUrl($kbB, $doc, 1))->assertStatus(404);
    }

    public function test_chunks_endpoint_carries_page_field(): void
    {
        $kb = $this->signIn('pageimg-f');
        $doc = $this->makeDocument($kb, [
            'extraction' => [
                'method' => 'hybrid',
                'chunk_pages' => [0 => 2, 1 => 2, 2 => 5],
            ],
        ]);

        foreach ([
            [0, '第二页的第一段内容'],
            [1, '第二页的第二段内容'],
            [2, '第五页的内容'],
        ] as [$index, $content]) {
            KnowledgeChunk::create([
                'tenant_id' => $doc->tenant_id,
                'document_id' => $doc->id,
                'chunk_index' => $index,
                'content' => $content,
                'token_count' => 100,
            ]);
        }

        $response = $this->getJson("/api/v1/knowledge-bases/{$kb->id}/documents/{$doc->id}/chunks");
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertSame(2, $data[0]['page']);
        $this->assertSame(2, $data[1]['page']);
        $this->assertSame(5, $data[2]['page']);
    }

    public function test_chunks_page_field_is_null_without_mapping(): void
    {
        // text_layer 文档没有 chunk_pages → page 必须是 null，不许编一个数字。
        $kb = $this->signIn('pageimg-g');
        $doc = $this->makeDocument($kb, [
            'extraction' => ['method' => 'text_layer'],
        ]);

        KnowledgeChunk::create([
            'tenant_id' => $doc->tenant_id,
            'document_id' => $doc->id,
            'chunk_index' => 0,
            'content' => '纯文字层文档的切片',
            'token_count' => 100,
        ]);

        $response = $this->getJson("/api/v1/knowledge-bases/{$kb->id}/documents/{$doc->id}/chunks");

        $response->assertStatus(200);
        $this->assertNull($response->json('data.0.page'));
    }
}
