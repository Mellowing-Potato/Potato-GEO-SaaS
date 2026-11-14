<?php

namespace Tests\Feature;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AI\MockAiProvider;
use App\Services\Contracts\AiProvider;
use App\Services\DTOs\AiProviderCallResult;
use App\Services\Knowledge\DocumentIngestionService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * B5：process() 视觉誊抄接线。
 *
 * 关键断言（执行书 B8-1 / B5）：
 *   - 开关关闭时一次模型调用都不发（防「没开开关也在花钱」）
 *   - 纯图 PDF 从「提取不到正文直接 failed」变成 vision 成功入库
 *   - 混合 PDF method=hybrid、按页回退文字层
 *   - metadata.extraction 字段齐全（vision_pages / failed_pages / unverified_terms / chunk_pages）
 */
class DocumentIngestionVisionTest extends TestCase
{
    use \Tests\Support\UsesWorkspaceModels;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('knowledge');
        TenantContext::forget();

        parent::tearDown();
    }

    /** 带 generate 调用计数的 fake；embed 委托给 MockAiProvider（切块向量化的真实路径）。 */
    private function countingProvider(array $script = []): object
    {
        return new class($script) implements AiProvider
        {
            public array $generateCalls = [];

            public function __construct(private readonly array $script) {}

            public function generate(string $prompt, ?string $system = null, array $options = []): AiProviderCallResult
            {
                $this->generateCalls[] = $options;

                if ($this->script === []) {
                    return (new MockAiProvider())->generate($prompt, $system, $options);
                }

                $step = $this->script[min(count($this->generateCalls) - 1, count($this->script) - 1)];

                return new AiProviderCallResult(
                    success: $step['success'] ?? true,
                    provider: 'scripted',
                    model: $options['model'] ?? 'vision',
                    content: $step['content'] ?? '',
                    promptTokens: $step['prompt_tokens'] ?? 100,
                    completionTokens: $step['completion_tokens'] ?? 50,
                    durationSeconds: 0.01,
                    errorMessage: $step['error'] ?? null,
                    finishReason: $step['finish'] ?? 'stop',
                );
            }

            public function generateStructured(string $prompt, string $schema, ?string $system = null, array $options = []): AiProviderCallResult
            {
                return AiProviderCallResult::failure('scripted', 'vision', 'not supported');
            }

            public function availableProviders(Tenant $tenant): array
            {
                return [];
            }

            public function pickBestModel(Tenant $tenant, ?string $preferred = null): array
            {
                return ['provider' => 'scripted', 'model' => 'vision'];
            }

            public function getTenantApiKey(Tenant $tenant, string $provider): ?string
            {
                return null;
            }

            public function embed(string $text, string $model = 'embedding', array $options = []): array
            {
                return (new MockAiProvider())->embed($text, $model);
            }

            public function embedBatch(array $texts, string $model = 'embedding', array $options = []): array
            {
                return (new MockAiProvider())->embedBatch($texts, $model);
            }
        };
    }

    private function upload(string $fixtureName): KnowledgeDocument
    {
        $tenant = Tenant::query()->create([
            'subdomain' => 'vision-b5-' . bin2hex(random_bytes(3)),
            'name' => 'Vision B5',
            'plan' => 'basic',
            'status' => 'active',
        ]);
        TenantContext::set($tenant);
        $this->configureWorkspaceModelSlots($tenant);

        $kb = KnowledgeBase::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'B5 Base',
            'status' => 'active',
        ]);

        // B7/§7：多模态归属闸门 —— 文档没有 project_id/uploaded_by 时 vision 会被
        // 跳过（绝不免费跑），所以 vision 用例必须带上完整归属。
        $this->configureWorkspaceModelSlots($tenant);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $project = Project::query()->create([
            'owner_id' => $user->id,
            'name' => 'B5 归属项目',
            'status' => 'active',
        ]);

        $source = __DIR__ . '/../fixtures/pdf/' . $fixtureName;
        $this->assertFileExists($source);

        $file = new UploadedFile($source, $fixtureName, 'application/pdf', null, true);

        return (new DocumentIngestionService(new MockAiProvider()))
            ->stageFile($kb->id, $file, $fixtureName, $project->id, $user->id);
    }

    private function service(AiProvider $provider): DocumentIngestionService
    {
        return new DocumentIngestionService($provider);
    }

    public function test_vision_disabled_emits_zero_model_calls(): void
    {
        config(['geo.knowledge.vision_enabled' => false]);
        $document = $this->upload('doc8_crop.pdf');

        $provider = $this->countingProvider();
        $result = $this->service($provider)->process($document->fresh());

        $this->assertSame('ready', $result->status);
        $this->assertSame('text_layer', $result->metadata['extraction']['method']);
        $this->assertSame([], $provider->generateCalls, '开关关闭时一次 generate 都不许发');
        $this->assertArrayNotHasKey('vision_pages', $result->metadata['extraction']);
    }

    public function test_pure_image_pdf_ingests_via_vision(): void
    {
        config(['geo.knowledge.vision_enabled' => true]);
        $document = $this->upload('doc11_p1_re.pdf');

        $provider = $this->countingProvider();
        $result = $this->service($provider)->process($document->fresh());

        // 纯图 PDF 此前在这里直接 failed（提取不到正文）—— 现在必须 ready。
        $this->assertSame('ready', $result->status);
        $extraction = $result->metadata['extraction'];
        $this->assertSame('vision', $extraction['method']);
        $this->assertSame([1], $extraction['vision_pages']);
        $this->assertSame([], $extraction['failed_pages']);
        $this->assertSame(1, $extraction['pages']);
        $this->assertGreaterThan(0, $extraction['chars']);
        $this->assertArrayHasKey('chunk_pages', $extraction);
        $this->assertSame(1, $extraction['chunk_pages'][0], '单页文档的切片必须映射到第 1 页');
        $this->assertArrayHasKey('vision_tokens', $extraction);

        // 页图落盘（B6 图文对照依赖）。
        $jpg = 'knowledge/' . $result->tenant_id . '/' . $result->knowledge_base_id
            . '/' . $result->id . '/pages/p1.jpg';
        $this->assertTrue(Storage::disk('local')->exists($jpg));
    }

    public function test_hybrid_pdf_records_vision_pages_and_falls_back(): void
    {
        config(['geo.knowledge.vision_enabled' => true]);
        $document = $this->upload('doc8_crop.pdf');

        $provider = $this->countingProvider();
        $result = $this->service($provider)->process($document->fresh());

        $this->assertSame('ready', $result->status);
        $extraction = $result->metadata['extraction'];

        // doc8：p1 纯文字（不送），p2-p5 触发 → hybrid。
        $this->assertSame('hybrid', $extraction['method']);
        $this->assertSame([2, 3, 4, 5], $extraction['vision_pages']);
        $this->assertSame(5, $extraction['pages']);

        // 失败页只可能出自送了 vision 的页。
        $this->assertEmpty(array_diff($extraction['failed_pages'], [2, 3, 4, 5]));

        // 每页恰好一次调用（一页一次请求）。
        $this->assertCount(4, $provider->generateCalls);
        foreach ($provider->generateCalls as $options) {
            $this->assertSame('vision', $options['model']);
            $this->assertSame(0, $options['temperature']);
        }

        // 切片都映射到了 1..5 之间的某页（或 null），不许越界。
        $this->assertNotEmpty($extraction['chunk_pages']);
        foreach ($extraction['chunk_pages'] as $page) {
            $this->assertTrue($page === null || ($page >= 1 && $page <= 5));
        }
    }

    /**
     * chunk_pages 映射的精确性（B6-1：宁可 null 不要猜）。
     * 直接打 mapChunksToPages：2400 字三页正文 + overlap 切块，起点单调递增。
     */
    public function test_chunk_page_mapping_is_precise(): void
    {
        $text = str_repeat('甲', 2400) . "\n\n" . str_repeat('乙', 2400) . "\n\n" . str_repeat('丙', 2400);
        $pageStarts = [1 => 0, 2 => 2402, 3 => 4804];

        $chunkText = new \ReflectionMethod(DocumentIngestionService::class, 'chunkText');
        $mapChunks = new \ReflectionMethod(DocumentIngestionService::class, 'mapChunksToPages');
        $service = $this->service(new MockAiProvider());

        $chunks = $chunkText->invoke($service, $text);
        $this->assertGreaterThan(2, count($chunks), '7200 字正文至少切 3 段');

        $map = $mapChunks->invoke($service, $chunks, $text, $pageStarts);

        // 切片起点单调递增 → 页号也必须单调不减，且落在 1..3。
        $pages = array_values($map);
        $previous = 0;
        foreach ($pages as $page) {
            $this->assertNotNull($page, '连续子串必须能定位到页');
            $this->assertGreaterThanOrEqual($previous, $page);
            $this->assertLessThanOrEqual(3, $page);
            $previous = $page;
        }
        $this->assertSame(1, $pages[0], '第一段从第 1 页开头起');
        $this->assertSame(3, $pages[count($pages) - 1], '最后一段落在第 3 页');
    }

    public function test_non_pdf_paths_stay_on_text_layer(): void
    {
        // text 入库不受视觉接线影响（回归保护）。
        config(['geo.knowledge.vision_enabled' => true]);

        $tenant = Tenant::query()->create([
            'subdomain' => 'vision-b5-text',
            'name' => 'Vision B5 Text',
            'plan' => 'basic',
            'status' => 'active',
        ]);
        TenantContext::set($tenant);
        $this->configureWorkspaceModelSlots($tenant);
        $kb = KnowledgeBase::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'B5 Text Base',
            'status' => 'active',
        ]);

        $provider = $this->countingProvider();
        $result = $this->service($provider)->ingestText(
            $kb->id,
            '纯文本文档',
            str_repeat('这是一段用于验证的中文正文。', 100),
        );

        $this->assertSame('ready', $result->status);
        $this->assertSame('text_layer', $result->metadata['extraction']['method']);
        $this->assertSame([], $provider->generateCalls);
    }
}
