<?php

namespace Tests\Feature;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Models\ModelCallLog;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AI\MockAiProvider;
use App\Services\Billing\AssistUsageRecorder;
use App\Services\Contracts\AiProvider;
use App\Services\DTOs\AiProviderCallResult;
use App\Services\Enums\SkillCategory;
use App\Services\Knowledge\DocumentIngestionService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * B7 + §7：知识库多模态扣费归属（老板 2026-08-20 拍板方案乙：上传时选项目）。
 *
 * 验收点：
 *   1. 两个上传接口 project_id 必填：缺了 422；给了别家租户的项目也 422
 *   2. 上传成功后归属落在文档行（project_id + uploaded_by=当前用户）
 *   3. 文档缺归属时（存量行 / 直造行），GEO_KNOWLEDGE_VISION 开着也绝不跑多模态
 *      —— 绝不允许「读不到归属就免费跑一次」（§7.3），一次 generate 都不发
 *   4. 多模态费用只在全部页跑完后扣一次（不是每页一次），聚合 token 只含 ok 页，
 *      kind='ai-assist-kb-vision'，metadata 带 document_id + pages；
 *      charge() 返回的 points_deducted（积分）按 1 分=100 积分换算后写进
 *      metadata.extraction.cost_fen（分），原始积分另存 points_deducted
 */
class KnowledgeDocumentAttributionTest extends TestCase
{
    use \Tests\Support\UsesWorkspaceModels;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.tenant_domain' => 'geo.example.test',
            'geo.async_ingest' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('knowledge');
        TenantContext::forget();

        parent::tearDown();
    }

    /** @return array{0: Tenant, 1: User, 2: KnowledgeBase, 3: Project} */
    private function signIn(string $subdomain = 'attr-a'): array
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

        $kb = KnowledgeBase::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Attribution Base',
            'status' => 'active',
        ]);

        $project = new Project();
        $project->forceFill([
            'tenant_id' => $tenant->id,
            'owner_id' => $user->id,
            'name' => '归属项目',
            'status' => 'active',
        ])->save();

        $kb->update(['project_id' => $project->id]);
        return [$tenant, $user, $kb, $project];
    }

    // ── 1. project_id 必填 + 租户校验 ──────────────────────────────────────────

    public function test_upload_without_project_id_is_rejected(): void
    {
        [, , $kb] = $this->signIn();

        $this->withServerVariables(['HTTP_HOST' => 'attr-a.geo.example.test'])
            ->postJson("/api/v1/knowledge-bases/{$kb->id}/documents", [
                'file' => UploadedFile::fake()->createWithContent('note.txt', '正文内容'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['project_id']);

        $this->assertSame(0, KnowledgeDocument::query()->count(), '缺归属的文档不许落库');
    }

    public function test_upload_with_foreign_tenant_project_id_is_rejected(): void
    {
        [, , $kb] = $this->signIn('attr-b');

        // 另一个租户的项目 —— 落进去的话 charge() 会因租户校验失败被吞掉，
        // 多模态跑完却一分不扣，正是 §7.3 要堵的洞。
        $otherTenant = Tenant::query()->create([
            'subdomain' => 'attr-other',
            'name' => 'ATTR-OTHER',
            'plan' => 'basic',
            'status' => 'active',
        ]);
        $otherUser = User::factory()->create(['tenant_id' => $otherTenant->id]);
        $foreignProject = new Project();
        $foreignProject->forceFill([
            'tenant_id' => $otherTenant->id,
            'owner_id' => $otherUser->id,
            'name' => '别家项目',
            'status' => 'active',
        ])->save();

        $this->withServerVariables(['HTTP_HOST' => 'attr-b.geo.example.test'])
            ->postJson("/api/v1/knowledge-bases/{$kb->id}/documents", [
                'file' => UploadedFile::fake()->createWithContent('note.txt', '正文内容'),
                'project_id' => $foreignProject->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['project_id']);

        $this->assertSame(0, KnowledgeDocument::query()->count());
    }

    // ── 2. 归属落行 ───────────────────────────────────────────────────────────

    public function test_upload_stores_attribution_on_document(): void
    {
        [, $user, $kb, $project] = $this->signIn('attr-c');

        $this->withServerVariables(['HTTP_HOST' => 'attr-c.geo.example.test'])
            ->postJson("/api/v1/knowledge-bases/{$kb->id}/documents", [
                'file' => UploadedFile::fake()->createWithContent('note.txt', '正文内容'),
                'project_id' => $project->id,
            ])
            ->assertStatus(202);

        $document = KnowledgeDocument::query()->sole();
        $this->assertSame($project->id, $document->project_id);
        $this->assertSame($user->id, $document->uploaded_by);
    }

    public function test_ingest_url_requires_and_stores_attribution(): void
    {
        [, $user, $kb, $project] = $this->signIn('attr-d');

        $this->withServerVariables(['HTTP_HOST' => 'attr-d.geo.example.test'])
            ->postJson("/api/v1/knowledge-bases/{$kb->id}/ingest-url", [
                'url' => 'https://example.com/page',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['project_id']);

        // 注意：本机 DNS 被代理 fake-ip 劫持（example.com → 198.18.x.x 保留段），
        // 会把域名误判成内网地址。这里用字面公网 IP 绕开 DNS。
        // 队列 fake 掉：测试环境是 sync 队列，job 会内联跑（抓取/解析结果与
        // 本用例无关，202 只验证「登记成功 + 归属落行」）。
        Queue::fake();

        $response = $this->withServerVariables(['HTTP_HOST' => 'attr-d.geo.example.test'])
            ->postJson("/api/v1/knowledge-bases/{$kb->id}/ingest-url", [
                'url' => 'http://1.2.3.4/page',
                'project_id' => $project->id,
            ]);
        $response->assertStatus(202);

        $document = KnowledgeDocument::query()->sole();
        $this->assertSame($project->id, $document->project_id);
        $this->assertSame($user->id, $document->uploaded_by);
    }

    // ── 3. 缺归属 → 绝不免费跑多模态 ──────────────────────────────────────────

    public function test_missing_attribution_skips_vision_entirely(): void
    {
        $this->requirePoppler();
        config(['geo.knowledge.vision_enabled' => true]);

        $document = $this->stagedDocument('doc8_crop.pdf', null, null);

        $provider = $this->countingProvider();
        $charger = Mockery::mock(AssistUsageRecorder::class);
        $charger->shouldReceive('record')->never();

        $this->app->instance(AssistUsageRecorder::class, $charger);
        $result = (new DocumentIngestionService($provider))->process($document->fresh());

        // doc8 有文字层：归属缺失 → 走原路 text_layer，一次模型调用都不发。
        $this->assertSame('ready', $result->status);
        $this->assertSame('text_layer', $result->metadata['extraction']['method']);
        $this->assertSame([], $provider->generateCalls, '缺归属时一次 generate 都不许发（§7.3：绝不允许免费跑）');
        $this->assertArrayNotHasKey('cost_fen', $result->metadata['extraction']);
        $this->assertArrayNotHasKey('points_deducted', $result->metadata['extraction']);
    }

    // ── 4. 全部页跑完后只扣一次，聚合结果正确 ─────────────────────────────────

    public function test_vision_records_the_actual_page_call_once(): void
    {
        $this->requirePoppler();
        config(['geo.knowledge.vision_enabled' => true]);

        $document = $this->stagedDocument('doc11_p3_re.pdf', 901, 902);

        $provider = $this->countingProvider();
        $callLog = new ModelCallLog();
        $callLog->metadata = ['points_deduction_status' => 'deducted', 'points_deducted' => 123.38];

        $captured = null;
        /** @var MockInterface|AssistUsageRecorder $charger */
        $charger = Mockery::mock(AssistUsageRecorder::class);
        $charger->shouldReceive('record')->once()->andReturnUsing(
            function (int $projectId, int $userId, AiProviderCallResult $result, string $kind, string $label) use ($callLog, &$captured) {
                $captured = [
                    'project_id' => $projectId,
                    'user_id' => $userId,
                    'result' => $result,
                    'kind' => $kind,
                    'label' => $label,
                ];

                return $callLog;
            },
        );

        $this->app->instance(AssistUsageRecorder::class, $charger);
        $result = (new DocumentIngestionService($provider))->process($document->fresh());

        $this->assertSame('ready', $result->status);
        $this->assertSame('vision', $result->metadata['extraction']['method']);
        $this->assertCount(1, $provider->generateCalls, '单页文档恰好一次调用');

        $this->assertNotNull($captured, '实际识别调用必须记录用量');
        $this->assertSame(901, $captured['project_id']);
        $this->assertSame(902, $captured['user_id']);
        $this->assertSame('ai-assist-kb-vision', $captured['kind']);
        $this->assertSame('知识库图像识别', $captured['label']);
        $this->assertSame(100, $captured['result']->promptTokens);
        $this->assertSame(50, $captured['result']->completionTokens);
        $this->assertSame(150, $captured['result']->totalTokens);
        $this->assertTrue($captured['result']->success);

    }

    // ── 5. 历史脏行回填：把虚高 100 倍的 cost_fen 修回来 ───────────────────────

    public function test_backfill_migration_repairs_the_100x_cost_fen_and_is_repeatable(): void
    {
        [$tenant, $user, $kb, $project] = $this->signIn('attr-backfill');
        TenantContext::forget();

        // 历史脏行：cost_fen 记的是积分（生产 document id=17 就长这样：760 积分被当成 760 分）。
        $dirtyId = $this->seedDocument($tenant, $kb, '脏行', ['cost_fen' => 760]);
        // 新码写对的行：迁移必须原样放过，不能再除一次。
        $cleanId = $this->seedDocument($tenant, $kb, '已对行', ['cost_fen' => 7.6, 'points_deducted' => 760]);
        // 找不到扣费流水的行：不猜、不动。
        $orphanId = $this->seedDocument($tenant, $kb, '无流水行', ['cost_fen' => 500]);
        // 当时没扣成的行：本来就是 null，没有单位问题。
        $nullId = $this->seedDocument($tenant, $kb, '未扣费行', ['cost_fen' => null]);

        foreach ([$dirtyId, $cleanId] as $documentId) {
            ModelCallLog::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'project_id' => $project->id,
                'user_id' => $user->id,
                'provider' => 'dashscope',
                'model' => 'vision',
                'status' => 'completed',
                'total_tokens' => 150,
                'metadata' => [
                    'assist' => true,
                    'assist_kind' => 'ai-assist-kb-vision',
                    'document_id' => $documentId,
                    'points_deducted' => 760,
                    'points_deduction_status' => 'deducted',
                ],
            ]);
        }

        $migration = require database_path('migrations/2026_08_27_130000_fix_knowledge_extraction_cost_fen_unit.php');

        $migration->up();
        $this->assertBackfilledCosts($dirtyId, $cleanId, $orphanId, $nullId);

        // 可重复执行：再跑一次结果不变。
        $migration->up();
        $this->assertBackfilledCosts($dirtyId, $cleanId, $orphanId, $nullId);
    }

    private function assertBackfilledCosts(int $dirtyId, int $cleanId, int $orphanId, int $nullId): void
    {
        $extraction = fn (int $id): array => KnowledgeDocument::withoutGlobalScopes()
            ->findOrFail($id)->metadata['extraction'];

        $this->assertEqualsWithDelta(7.60, (float) $extraction($dirtyId)['cost_fen'], 0.001);
        $this->assertSame(760, $extraction($dirtyId)['points_deducted']);

        $this->assertEqualsWithDelta(7.60, (float) $extraction($cleanId)['cost_fen'], 0.001);
        $this->assertSame(760, $extraction($cleanId)['points_deducted']);

        $this->assertEqualsWithDelta(500.00, (float) $extraction($orphanId)['cost_fen'], 0.001);
        $this->assertArrayNotHasKey('points_deducted', $extraction($orphanId));

        $this->assertNull($extraction($nullId)['cost_fen']);
    }

    // ── 辅助 ──────────────────────────────────────────────────────────────────

    /** 直造一行入库完成的文档，只关心 metadata.extraction 那几个键。 */
    private function seedDocument(Tenant $tenant, KnowledgeBase $kb, string $title, array $extraction): int
    {
        $document = KnowledgeDocument::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'knowledge_base_id' => $kb->id,
            'title' => $title,
            'source_type' => 'upload',
            'status' => 'ready',
            'metadata' => ['extraction' => array_merge(['method' => 'vision'], $extraction)],
        ]);

        return (int) $document->id;
    }

    /** 直造文档行（绕过上传接口），归属参数可空 —— 用于「缺归属」与「正常扣费」两条路。 */
    private function stagedDocument(string $fixtureName, ?int $projectId, ?int $uploadedBy): KnowledgeDocument
    {
        $tenant = Tenant::query()->create([
            'subdomain' => 'attr-' . bin2hex(random_bytes(3)),
            'name' => 'Attribution Direct',
            'plan' => 'basic',
            'status' => 'active',
        ]);
        TenantContext::set($tenant);
        $this->configureWorkspaceModelSlots($tenant);

        $kb = KnowledgeBase::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Attribution Direct Base',
            'status' => 'active',
        ]);

        $source = __DIR__ . '/../fixtures/pdf/' . $fixtureName;
        $this->assertFileExists($source);

        return (new DocumentIngestionService(new MockAiProvider()))
            ->stageFile($kb->id, new UploadedFile($source, $fixtureName, 'application/pdf', null, true), $fixtureName, $projectId, $uploadedBy);
    }

    /** 带 generate 调用计数的 fake（与 DocumentIngestionVisionTest 同款，tokens 固定 100/50）。 */
    private function countingProvider(): object
    {
        return new class implements AiProvider
        {
            public array $generateCalls = [];

            public function generate(string $prompt, ?string $system = null, array $options = []): AiProviderCallResult
            {
                $this->generateCalls[] = $options;

                return new AiProviderCallResult(
                    success: true,
                    provider: 'scripted',
                    model: 'vision',
                    content: '誊抄正文内容',
                    promptTokens: 100,
                    completionTokens: 50,
                    totalTokens: 150,
                    durationSeconds: 0.01,
                    finishReason: 'stop',
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

    /** poppler 缺失时跳过需要真实渲染/分析的用例（与 PdfPageAnalyzerTest 同款守卫）。 */
    private function requirePoppler(): void
    {
        $missing = array_filter(
            ['pdfinfo', 'pdfimages', 'pdftotext', 'pdftoppm'],
            function (string $cmd): bool {
                $check = PHP_OS_FAMILY === 'Windows'
                    ? "where.exe {$cmd} 2>NUL"
                    : "command -v {$cmd} 2>/dev/null";
                exec($check, $output, $code);

                return $code !== 0;
            },
        );

        if ($missing !== []) {
            $this->markTestSkipped('poppler-utils 不可用（缺 ' . implode(', ', $missing) . '），跳过归属闸门/扣费用例。');
        }
    }
}
