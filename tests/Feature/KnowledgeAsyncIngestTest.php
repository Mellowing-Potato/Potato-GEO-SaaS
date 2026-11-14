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
 * §5.1 文档入库异步化后端单测。
 *
 * 关键约束：phpunit.xml 把 QUEUE_CONNECTION 设成 sync，所以 dispatch() 默认会在请求里
 * 把 job 同步跑完。这 10 条测试要断言的是「慢活真的搬到了队列」，所以凡是想看
 * pending（而不是 ready）的地方，都用 Queue::fake() 把 job 拦下来，再手动跑：
 *
 *   - 想看 pending / 队列名 / 请求不碰 AiProvider：只 fake，不跑 job
 *   - 想看 ready / 切片数 / 失败状态：fake 之后手动 $job->handle(...)
 *
 * AiProvider 默认绑 MockAiProvider（产得出向量），让 job 能稳定跑到 ready；
 * 假成功 / 抛异常两条专门 rebind 成会返回空向量 / 会抛异常的假实现。
 */
class KnowledgeAsyncIngestTest extends TestCase
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
        // 把租户身份固成默认 header / server var，后续 upload() 等请求自动带上
        // （KnowledgeUploadSizeLimitTest::signIn 也是这套写法；缺了 X-Tenant 路由模型
        //  绑定会 404）。
        $this->withServerVariables(['HTTP_HOST' => $subdomain . '.geo.example.test'])
            ->withHeader('X-Tenant', $subdomain);

        return KnowledgeBase::query()->create([
            'tenant_id' => $tenant->id,
            'project_id' => self::knowledgeProjectId($tenant->id),
            'name' => 'Async Ingest Base',
            'status' => 'active',
        ]);
    }

    /** B7/§7：给 kb 的租户造一个归属项目（project_id 必填校验的测试前置）。 */
    private function attributionProjectId(KnowledgeBase $kb): int
    {
        if ($kb->project_id) return $kb->project_id;
        $owner = User::factory()->create(['tenant_id' => $kb->tenant_id]);
        $project = new \App\Models\Project();
        $project->forceFill([
            'tenant_id' => $kb->tenant_id,
            'owner_id' => $owner->id,
            'name' => '归属项目',
            'status' => 'active',
        ])->save();

        return $project->id;
    }

    private function upload(KnowledgeBase $kb, UploadedFile $file, array $server = [])
    {
        return $this->withServerVariables($server + [
            'HTTP_HOST' => $kb->tenant->subdomain . '.geo.example.test',
        ])->postJson("/api/v1/knowledge-bases/{$kb->id}/documents", [
            'file' => $file,
            'project_id' => $this->attributionProjectId($kb),
        ]);
    }

    private function ingestUrl(KnowledgeBase $kb, string $url)
    {
        return $this->withServerVariables([
            'HTTP_HOST' => $kb->tenant->subdomain . '.geo.example.test',
        ])->postJson("/api/v1/knowledge-bases/{$kb->id}/ingest-url", [
            'url' => $url,
            'project_id' => $this->attributionProjectId($kb),
        ]);
    }

    /** 取出当前测试里唯一那条入队的入库 job 并手动跑完（queue 必须是 fake 状态）。 */
    private function runFirstIngestJob(): IngestKnowledgeDocumentJob
    {
        $job = collect(Queue::pushed(IngestKnowledgeDocumentJob::class))->first();
        $this->assertNotNull($job, '期望有一条入队的 IngestKnowledgeDocumentJob');
        // AiProvider 是单例，手动跑 job 前清掉缓存，保证吃到当前测试 rebind 的实现
        $this->app->forgetInstance(DocumentIngestionService::class);
        $job->handle($this->app->make(DocumentIngestionService::class));

        return $job;
    }

    // ── 1. POST 返回 202，库里 pending，切片 0 行 ──────────────────────────────
    public function test_upload_returns_202_and_stages_pending_with_no_chunks(): void
    {
        Queue::fake();
        $kb = $this->signIn('async-1');

        $response = $this->upload($kb, UploadedFile::fake()->createWithContent(
            'note.txt',
            str_repeat('这是一段用于验证异步入库的中文正文。', 50),
        ));

        $response->assertStatus(202);
        $docId = $response->json('data.id');

        $this->assertDatabaseHas('knowledge_documents', [
            'id' => $docId,
            'status' => 'pending',
        ]);
        $this->assertSame(0, KnowledgeChunk::query()->where('document_id', $docId)->count(),
            '登记阶段不许产生任何切片');
    }

    // ── 2. 队列里有这条 job，且队列名是 knowledge ─────────────────────────────
    public function test_upload_pushes_job_on_knowledge_queue(): void
    {
        Queue::fake();
        $kb = $this->signIn('async-2');

        $this->upload($kb, UploadedFile::fake()->createWithContent('note.txt', '正文内容。'));

        Queue::assertPushed(IngestKnowledgeDocumentJob::class, function (IngestKnowledgeDocumentJob $job) {
            return $job->queue === 'knowledge';
        });
    }

    // ── 3. 网页请求期间不许碰 AiProvider（慢活真的搬走了）──────────────────────
    public function test_request_does_not_touch_ai_provider(): void
    {
        Queue::fake();
        // 一被调用就抛异常的假实现：如果请求里真的去向量化了，会直接 500，
        // assertStatus(202) 就会红 —— 这条用「请求成功」反证「慢活没在请求里跑」。
        $this->app->bind(AiProvider::class, fn () => new class extends MockAiProvider {
            public function embedBatch(array $texts, string $model = 'embedding', array $options = []): array
            {
                throw new \RuntimeException('测试不该在请求里调用向量化');
            }
        });

        $kb = $this->signIn('async-3');

        $response = $this->upload($kb, UploadedFile::fake()->createWithContent('note.txt', '正文内容。'));

        $response->assertStatus(202);
        Queue::assertPushed(IngestKnowledgeDocumentJob::class);
    }

    // ── 4. 跑 job → ready，chunk_count 与实际切片行数一致 ──────────────────────
    public function test_job_marks_ready_and_chunk_count_matches_rows(): void
    {
        Queue::fake();
        $kb = $this->signIn('async-4');

        $response = $this->upload($kb, UploadedFile::fake()->createWithContent(
            'whitepaper.txt',
            str_repeat('地理信息治理与生成式引擎优化白皮书正文片段。', 400),
        ));
        $docId = $response->json('data.id');

        $this->runFirstIngestJob();

        $doc = KnowledgeDocument::findOrFail($docId);
        $this->assertSame('ready', $doc->status);
        $actualRows = KnowledgeChunk::query()->where('document_id', $docId)->count();
        $this->assertSame($doc->chunk_count, $actualRows, 'chunk_count 必须与实际切片行数一致');
        $this->assertGreaterThan(0, $actualRows);
    }

    // ── 5. 假成功回归：空向量 → failed，error_message 带「余额不足」──────────────
    public function test_empty_embeddings_marks_failed_with_balance_error(): void
    {
        Queue::fake();
        $kb = $this->signIn('async-5');

        $response = $this->upload($kb, UploadedFile::fake()->createWithContent(
            'note.txt',
            str_repeat('这是一段会被空向量结局的正文。', 60),
        ));
        $docId = $response->json('data.id');

        // 复刻生产那次 402 余额不足：embedBatch 返回空向量 + error。
        // AiProvider 是单例，用 instance() 直接替换缓存实例，保证 job 跑到的是这个实现。
        $this->app->instance(AiProvider::class, new class extends MockAiProvider {
            public function embedBatch(array $texts, string $model = 'embedding', array $options = []): array
            {
                return ['embeddings' => [], 'tokens' => 0, 'error' => '余额不足'];
            }
        });

        // handle() 会抛异常（由队列 worker 转 failed()），这里接住即可，
        // 文档应已被 process() 的 catch 标成 failed。
        try {
            $this->runFirstIngestJob();
        } catch (\Throwable) {
        }

        $doc = KnowledgeDocument::findOrFail($docId);
        $this->assertSame('failed', $doc->status, '空向量必须落 failed，不能假装 ready');
        $this->assertStringContainsString('余额不足', (string) $doc->error_message);
        $this->assertSame(0, $doc->chunk_count);
    }

    // ── 6. job 抛异常 → failed() 把文档标成 failed（不许停在 processing）─────────
    public function test_job_exception_marks_failed_not_processing(): void
    {
        Queue::fake();
        $kb = $this->signIn('async-6');

        $response = $this->upload($kb, UploadedFile::fake()->createWithContent('note.txt', '正文。'));
        $docId = $response->json('data.id');

        $this->app->instance(AiProvider::class, new class extends MockAiProvider {
            public function embedBatch(array $texts, string $model = 'embedding', array $options = []): array
            {
                throw new \RuntimeException('向量化服务不可用');
            }
        });

        // handle() 抛异常会冒泡到调用方（队列 worker 再转 failed()），这里接住即可。
        try {
            $this->runFirstIngestJob();
        } catch (\Throwable) {
        }

        $doc = KnowledgeDocument::findOrFail($docId);
        $this->assertSame('failed', $doc->status, '异常后必须停在 failed，不能卡在 processing');
        $this->assertNotSame('processing', $doc->status);
    }

    // ── 7. 重复跑同一个 job 两次 → 切片数不翻倍 ───────────────────────────────
    public function test_rerunning_job_does_not_double_chunks(): void
    {
        Queue::fake();
        $kb = $this->signIn('async-7');

        $response = $this->upload($kb, UploadedFile::fake()->createWithContent(
            'note.txt',
            str_repeat('重复跑任务不应让切片翻倍的验收正文。', 120),
        ));
        $docId = $response->json('data.id');

        $this->runFirstIngestJob();
        $firstCount = KnowledgeChunk::query()->where('document_id', $docId)->count();
        $this->assertGreaterThan(0, $firstCount);

        // 再跑一次（模拟 worker 重领取 / 手动重跑）
        $this->runFirstIngestJob();
        $secondCount = KnowledgeChunk::query()->where('document_id', $docId)->count();

        $this->assertSame($firstCount, $secondCount, 'process() 先清旧切片，重复跑不许翻倍');
    }

    // ── 8. 契约：job 的 timeout 必须严格小于 retry_after（防重复领取）───────────
    public function test_job_timeout_strictly_less_than_retry_after(): void
    {
        $job = new IngestKnowledgeDocumentJob(1, 1);
        $retryAfter = (int) config('queue.connections.database.retry_after');

        $this->assertGreaterThan(0, $retryAfter, 'retry_after 必须有值');
        $this->assertLessThan(
            $retryAfter,
            $job->timeout,
            "IngestKnowledgeDocumentJob::\$timeout({$job->timeout}) 必须 < retry_after({$retryAfter})，否则 worker 会重复领取",
        );
    }

    // ── 9. 租户隔离：A 租户文档跑完，切片 tenant_id 必须是 A ───────────────────
    public function test_job_chunks_belong_to_the_tenant(): void
    {
        Queue::fake();
        $kb = $this->signIn('async-9');

        $response = $this->upload($kb, UploadedFile::fake()->createWithContent(
            'note.txt',
            str_repeat('租户隔离验收正文内容。', 200),
        ));
        $docId = $response->json('data.id');
        $tenantId = $kb->tenant_id;

        $this->runFirstIngestJob();

        $doc = KnowledgeDocument::findOrFail($docId);
        $this->assertSame('ready', $doc->status);
        $this->assertSame($tenantId, $doc->tenant_id);

        $wrong = KnowledgeChunk::query()
            ->where('document_id', $docId)
            ->where('tenant_id', '<>', $tenantId)
            ->count();
        $this->assertSame(0, $wrong, '任一切片都不许落到别的租户');
        $this->assertGreaterThan(0, KnowledgeChunk::query()->where('document_id', $docId)->count());
    }

    // ── 10. 私网地址摄入 → 登记阶段就 422，库里不留 pending ───────────────────
    public function test_private_url_rejected_at_stage_with_no_pending_row(): void
    {
        Queue::fake();
        $kb = $this->signIn('async-10');

        $response = $this->ingestUrl($kb, 'http://10.0.0.1/secret');
        $response->assertStatus(422);

        $this->assertDatabaseMissing('knowledge_documents', [
            'tenant_id' => $kb->tenant_id,
            'status' => 'pending',
        ]);
        // 私网地址不应触发任何入库任务
        Queue::assertNothingPushed();
    }
}
