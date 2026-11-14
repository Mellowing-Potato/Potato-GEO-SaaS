<?php

namespace Tests\Feature;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Models\Tenant;
use App\Services\Contracts\AiProvider;
use App\Services\DTOs\AiProviderCallResult;
use App\Services\Knowledge\VisionPageExtractor;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * B4：VisionPageExtractor（渲染 + 誊抄 + 双向校验）。
 *
 * 网络层全部用脚本化 fake（按调用次序返回预设 finish_reason），
 * 只有渲染用真实 fixture（doc11_p1_re.pdf，poppler 缺失时跳过渲染相关用例）。
 */
class VisionPageExtractorTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // 渲染测试会在 storage/app/knowledge/ 下写真文件，清干净。
        Storage::disk('local')->deleteDirectory('knowledge');
        TenantContext::forget();

        parent::tearDown();
    }

    /**
     * 脚本化 fake：generate() 按调用次序弹出预设步骤。
     * 每步：['success'=>bool, 'finish'=>'stop'|'length'|null, 'content'=>string, 'error'=>?string]
     * 步骤耗尽后重复最后一步（多页文档不想逐页写脚本时用）。
     */
    private function scriptedProvider(array $script): object
    {
        return new class($script) implements AiProvider
        {
            public array $calls = [];

            public function __construct(private readonly array $script) {}

            public function generate(string $prompt, ?string $system = null, array $options = []): AiProviderCallResult
            {
                $i = count($this->calls);
                $this->calls[] = ['prompt' => $prompt, 'system' => $system, 'options' => $options];

                $step = $this->script[min($i, count($this->script) - 1)];

                return new AiProviderCallResult(
                    success: $step['success'] ?? true,
                    provider: 'scripted',
                    model: $options['model'] ?? 'vision',
                    content: $step['content'] ?? '',
                    promptTokens: $step['prompt_tokens'] ?? 100,
                    completionTokens: $step['completion_tokens'] ?? 50,
                    totalTokens: ($step['prompt_tokens'] ?? 100) + ($step['completion_tokens'] ?? 50),
                    durationSeconds: 0.01,
                    errorMessage: $step['error'] ?? null,
                    finishReason: $step['finish'] ?? 'stop',
                );
            }

            public function generateStructured(string $prompt, string $schema, ?string $system = null, array $options = []): AiProviderCallResult
            {
                return AiProviderCallResult::failure('scripted', 'vision', 'not supported');
            }

            public function availableProviders(\App\Models\Tenant $tenant): array
            {
                return [];
            }

            public function pickBestModel(\App\Models\Tenant $tenant, ?string $preferred = null): array
            {
                return ['provider' => 'scripted', 'model' => 'vision'];
            }

            public function getTenantApiKey(\App\Models\Tenant $tenant, string $provider): ?string
            {
                return null;
            }

            public function embed(string $text, string $model = 'embedding', array $options = []): array
            {
                return ['embedding' => [], 'tokens' => 0];
            }

            public function embedBatch(array $texts, string $model = 'embedding', array $options = []): array
            {
                return ['embeddings' => [], 'tokens' => 0, 'error' => null];
            }
        };
    }

    private function document(): KnowledgeDocument
    {
        $tenant = Tenant::query()->create([
            'subdomain' => 'vision-ext',
            'name' => 'Vision Ext',
            'plan' => 'basic',
            'status' => 'active',
        ]);
        TenantContext::set($tenant);

        $kb = KnowledgeBase::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Vision Base',
            'status' => 'active',
        ]);

        return KnowledgeDocument::create([
            'tenant_id' => $tenant->id,
            'knowledge_base_id' => $kb->id,
            'title' => '视觉测试文档',
            'source_type' => 'upload',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'status' => 'pending',
        ]);
    }

    /** 单页纯图分析结果（needs_vision、无文字层）。 */
    private function analysis(int $page = 1, string $text = '', bool $needsVision = true): array
    {
        return [$page => [
            'page' => $page,
            'image_coverage' => 0.9,
            'chars' => mb_strlen(preg_replace('/\s+/u', '', $text) ?? ''),
            'needs_vision' => $needsVision,
            'text' => $text,
        ]];
    }

    public function test_call_parameters_and_verbatim_prompts(): void
    {
        $pdf = $this->copyFixtureToDocPath('doc11_p1_re.pdf');
        $doc = $this->document();
        $pdf = $this->absolutePath($doc, 'doc11_p1_re.pdf');

        $provider = $this->scriptedProvider([['content' => '# 转录结果']]);
        $result = (new VisionPageExtractor($provider))->extract($doc, $pdf, $this->analysis());

        $this->assertSame('ok', $result['pages'][1]['status']);

        // B4-2 提示词逐字（防手滑改写）；B4-3 参数。
        $call = $provider->calls[0];
        $this->assertSame(VisionPageExtractor::SYSTEM_PROMPT, $call['system']);
        $this->assertSame(VisionPageExtractor::USER_PROMPT, $call['prompt']);
        $this->assertSame('vision', $call['options']['model']);
        $this->assertSame(0, $call['options']['temperature']);
        $this->assertSame(4096, $call['options']['max_tokens']);

        // 一页一次调用、单图 data URL。
        $this->assertCount(1, $call['options']['images']);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $call['options']['images'][0]);
        $this->assertCount(1, $provider->calls);
    }

    public function test_truncation_retries_once_at_8192_then_succeeds(): void
    {
        $doc = $this->document();
        $pdf = $this->absolutePath($doc, 'doc11_p1_re.pdf');

        // 第 1 次撞上限 → 第 2 次（8192）正常收尾。
        $provider = $this->scriptedProvider([
            ['finish' => 'length', 'content' => '半截内容…'],
            ['finish' => 'stop', 'content' => '完整转录内容'],
        ]);

        $result = (new VisionPageExtractor($provider))->extract($doc, $pdf, $this->analysis());

        $this->assertSame('ok', $result['pages'][1]['status']);
        $this->assertSame('完整转录内容', $result['pages'][1]['content']);
        $this->assertCount(2, $provider->calls);
        $this->assertSame(4096, $provider->calls[0]['options']['max_tokens']);
        $this->assertSame(8192, $provider->calls[1]['options']['max_tokens']);
    }

    public function test_double_truncation_marks_page_truncated_not_success(): void
    {
        $doc = $this->document();
        $pdf = $this->absolutePath($doc, 'doc11_p1_re.pdf');

        // 两次都 length → truncated，半截内容绝不入库。
        $provider = $this->scriptedProvider([
            ['finish' => 'length', 'content' => '半截…'],
            ['finish' => 'length', 'content' => '还是半截…'],
        ]);

        $result = (new VisionPageExtractor($provider))->extract($doc, $pdf, $this->analysis());

        $this->assertSame('truncated', $result['pages'][1]['status']);
        $this->assertNull($result['pages'][1]['content']);
        // 失败页 token 不计入合计（B7 口径）。
        $this->assertSame(0, $result['prompt_tokens']);
        $this->assertSame(0, $result['completion_tokens']);
    }

    public function test_forward_validation_catches_missing_amount(): void
    {
        $doc = $this->document();
        $pdf = $this->absolutePath($doc, 'doc11_p1_re.pdf');

        // 文字层有 ¥2,200，誊抄里没有 → 正向查漏判失败（B8 验收用例）。
        $provider = $this->scriptedProvider([
            ['content' => '# 价格表\n\n| 项目 | 金额 |\n|------|------|\n| 运费 | ¥300 |\n'],
        ]);

        $result = (new VisionPageExtractor($provider))->extract(
            $doc,
            $pdf,
            $this->analysis(1, '订单合计 ¥2,200，运费 ¥300。'),
        );

        $this->assertSame('failed', $result['pages'][1]['status']);
        $this->assertStringContainsString('forward_validation', (string) $result['pages'][1]['reason']);
        $this->assertStringContainsString('2,200', (string) $result['pages'][1]['reason']);
        $this->assertNull($result['pages'][1]['content']);
    }

    public function test_forward_validation_passes_when_amount_present(): void
    {
        $doc = $this->document();
        $pdf = $this->absolutePath($doc, 'doc11_p1_re.pdf');

        $provider = $this->scriptedProvider([
            ['content' => '# 价格表\n\n| 项目 | 金额 |\n|------|------|\n| 合计 | ¥2,200 |\n| 运费 | ¥300 |\n'],
        ]);

        $result = (new VisionPageExtractor($provider))->extract(
            $doc,
            $pdf,
            $this->analysis(1, '价格表。项目：金额。订单合计 ¥2,200，运费 ¥300。'),
        );

        $this->assertSame('ok', $result['pages'][1]['status']);
        $this->assertSame([], $result['unverified_terms']);
    }

    public function test_reverse_validation_flags_invented_terms_without_blocking(): void
    {
        $doc = $this->document();
        $pdf = $this->absolutePath($doc, 'doc11_p1_re.pdf');

        // §1.4 的「陈小聪乍得」：文字层只有陈小聪，模型把图标认成乍得拼进姓名。
        $provider = $this->scriptedProvider([
            ['content' => "客户经理：陈小聪乍得\n\n联系电话：138-0000-0000\n合计：¥2,200\n"],
        ]);

        $result = (new VisionPageExtractor($provider))->extract(
            $doc,
            $pdf,
            $this->analysis(1, '客户经理：陈小聪。联系电话 138-0000-0000。合计 ¥2,200。'),
        );

        // 不阻断：页仍 ok，但乍得必须进 unverified_terms（陈小聪不许进）。
        $this->assertSame('ok', $result['pages'][1]['status']);
        $this->assertContains('乍得', $result['unverified_terms'][1]);
        $this->assertNotContains('陈小聪', $result['unverified_terms'][1]);
    }

    public function test_non_vision_pages_never_call_the_model(): void
    {
        $doc = $this->document();

        $provider = $this->scriptedProvider([['content' => '不该被调用']]);
        $result = (new VisionPageExtractor($provider))->extract(
            $doc,
            '/nonexistent.pdf',
            $this->analysis(1, '这一页文字层完整', false),
        );

        $this->assertSame([], $result['pages']);
        $this->assertSame([], $result['unverified_terms']);
        $this->assertSame(0, $result['prompt_tokens']);
        $this->assertSame([], $provider->calls, 'needs_vision=false 的页一次调用都不许发');
    }

    public function test_rendered_page_image_lands_in_pages_directory(): void
    {
        if (! $this->popplerAvailable()) {
            $this->markTestSkipped('poppler 不可用，跳过渲染落盘用例。');
        }

        $doc = $this->document();
        $pdf = $this->absolutePath($doc, 'doc11_p1_re.pdf');

        $provider = $this->scriptedProvider([['content' => '转录内容']]);
        (new VisionPageExtractor($provider))->extract($doc, $pdf, $this->analysis());

        // B6 图文对照要复用同一批文件：p1.jpg 必须留在 pages/ 目录。
        $jpg = 'knowledge/' . $doc->tenant_id . '/' . $doc->knowledge_base_id . '/' . $doc->id . '/pages/p1.jpg';
        $this->assertTrue(Storage::disk('local')->exists($jpg));
        $this->assertLessThan(VisionPageExtractor::MAX_IMAGE_BYTES, Storage::disk('local')->size($jpg));
        // JPEG 魔数。
        $this->assertSame("\xFF\xD8\xFF", substr((string) Storage::disk('local')->get($jpg), 0, 3));
    }

    public function test_failed_call_falls_back_without_counting_tokens(): void
    {
        $doc = $this->document();
        $pdf = $this->absolutePath($doc, 'doc11_p1_re.pdf');

        $provider = $this->scriptedProvider([
            ['success' => false, 'error' => '网关 504', 'content' => null],
        ]);

        $result = (new VisionPageExtractor($provider))->extract($doc, $pdf, $this->analysis());

        $this->assertSame('failed', $result['pages'][1]['status']);
        $this->assertSame(0, $result['prompt_tokens']);
    }

    // ── helpers ──

    /** 把 fixture 拷到文档目录（extractor 只需要绝对路径，直接复用 fixture 原地也行，这里拷一份模拟真实布局）。 */
    private function copyFixtureToDocPath(string $name): string
    {
        return $name; // 保留给调用处语义，实际拷贝在 absolutePath() 完成
    }

    private function absolutePath(KnowledgeDocument $doc, string $fixtureName): string
    {
        $source = __DIR__ . '/../fixtures/pdf/' . $fixtureName;
        $this->assertFileExists($source);

        $dir = "knowledge/{$doc->tenant_id}/{$doc->knowledge_base_id}/{$doc->id}";
        Storage::disk('local')->makeDirectory($dir);
        Storage::disk('local')->put("{$dir}/{$fixtureName}", file_get_contents($source));

        return Storage::disk('local')->path($dir . '/' . $fixtureName);
    }

    private function popplerAvailable(): bool
    {
        exec(PHP_OS_FAMILY === 'Windows'
            ? 'where.exe pdftoppm 2>NUL'
            : 'command -v pdftoppm 2>/dev/null', $out, $code);

        return $code === 0;
    }
}
