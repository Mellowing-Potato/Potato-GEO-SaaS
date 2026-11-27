<?php

namespace Tests\Feature;

use App\Exceptions\IngestionFailure;
use App\Jobs\IngestKnowledgeDocumentJob;
use App\Models\EnterpriseKnowledgeProject;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use App\Services\AI\MockAiProvider;
use App\Services\Contracts\AiProvider;
use App\Services\Knowledge\DocumentIngestionService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 2026-08-20 评审后修复的回归测试（六条）。
 *
 * 每条对应一个「上线后会真实伤到客户」的缺陷，不是补覆盖率：
 *  1/2. 失败原因回显：内部异常原文不许进浏览器，客户看得懂的原话必须留住
 *  3.   网址被安全策略拦下时，不许回一句「请重试」（重试一百次也不会成功）
 *  4/5. 清道夫：processing 15 分钟该清，pending 20 分钟绝不能清（会让客户多付一次钱）
 *  6.   企业发布失败时不许回「已发布」
 */
class KnowledgeIngestReviewFixesTest extends TestCase
{
    use \Tests\Support\UsesWorkspaceModels;
    use RefreshDatabase, \Tests\Support\KnowledgeProjectFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.tenant_domain' => 'geo.example.test']);
        $this->app->bind(AiProvider::class, fn () => new MockAiProvider());
    }

    private function makeTenant(string $subdomain): Tenant
    {
        return Tenant::query()->create([
            'subdomain' => $subdomain,
            'name' => strtoupper($subdomain),
            'plan' => 'basic',
            'status' => 'active',
        ]);
    }

    private function signIn(string $subdomain): KnowledgeBase
    {
        $tenant = $this->makeTenant($subdomain);

        $this->configureWorkspaceModelSlots($tenant);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain),
            'role' => 'admin',
        ]);
        Sanctum::actingAs($user, ['knowledge:read', 'knowledge:write']);
        $this->withServerVariables(['HTTP_HOST' => $subdomain.'.geo.example.test'])
            ->withHeader('X-Tenant', $subdomain);

        return KnowledgeBase::query()->create([
            'tenant_id' => $tenant->id,
            'project_id' => self::knowledgeProjectId($tenant->id),
            'name' => 'Review Fixes Base',
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

    private function upload(KnowledgeBase $kb, UploadedFile $file)
    {
        return $this->withServerVariables(['HTTP_HOST' => $kb->tenant->subdomain.'.geo.example.test'])
            ->postJson("/api/v1/knowledge-bases/{$kb->id}/documents", [
                'file' => $file,
                'project_id' => $this->attributionProjectId($kb),
            ], ['X-Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()]);
    }

    private function runFirstIngestJob(): void
    {
        $job = collect(Queue::pushed(IngestKnowledgeDocumentJob::class))->first();
        $this->assertNotNull($job, '期望有一条入队的 IngestKnowledgeDocumentJob');
        $this->app->forgetInstance(DocumentIngestionService::class);
        try {
            $job->handle($this->app->make(DocumentIngestionService::class));
        } catch (\Throwable) {
            // 队列 worker 会把它转成 failed()，测试里只关心落库结果
        }
    }

    // ── 1. 内部异常原文绝不能出现在客户看到的失败原因里 ────────────────────────
    public function test_internal_exception_text_never_reaches_the_customer(): void
    {
        Queue::fake();
        $kb = $this->signIn('fix-1');

        $response = $this->upload($kb, UploadedFile::fake()->createWithContent(
            'note.txt',
            str_repeat('这是一段正常的中文正文，用来触发向量化。', 60),
        ));
        $docId = $response->json('data.id');

        // 复刻生产最可能的一种失败：向量化网关连不上。异常原文里带着内网服务名和端口。
        $this->app->instance(AiProvider::class, new class extends MockAiProvider
        {
            public function embedBatch(array $texts, string $model = 'embedding', array $options = []): array
            {
                throw new \RuntimeException(
                    'cURL error 6: Could not resolve host: model-provider.example.test for http://model-provider.example.test:8792/v1/embeddings'
                );
            }
        });

        $this->runFirstIngestJob();

        $doc = KnowledgeDocument::findOrFail($docId);
        $this->assertSame('failed', $doc->status);

        $shown = (string) $doc->error_message;
        $this->assertStringNotContainsString('model-provider.example.test', $shown, '内网服务名不许显示给客户');
        $this->assertStringNotContainsString('8792', $shown, '内部端口不许显示给客户');
        $this->assertStringNotContainsString('cURL', $shown, '异常原文不许显示给客户');
        $this->assertSame(IngestionFailure::GENERIC, $shown);
    }

    // ── 2. 客户看得懂、且对他有用的原因必须留住（别一刀切成通用提示）───────────
    public function test_customer_facing_reason_is_kept(): void
    {
        Queue::fake();
        $kb = $this->signIn('fix-2');

        // 只有空白字符的文件：提取不出正文，这是客户自己能处理的情况（换一份文件）
        $response = $this->upload($kb, UploadedFile::fake()->createWithContent('blank.txt', "   \n  \n "));
        $docId = $response->json('data.id');

        $this->runFirstIngestJob();

        $doc = KnowledgeDocument::findOrFail($docId);
        $this->assertSame('failed', $doc->status);
        $this->assertStringContainsString('没能从这份文件里提取到正文', (string) $doc->error_message);
        $this->assertNotSame(IngestionFailure::GENERIC, (string) $doc->error_message);
    }

    // ── 3. 内网地址被拦下时，要说清楚为什么，不许回「请重试」────────────────────
    public function test_blocked_url_gets_an_actionable_message(): void
    {
        Queue::fake();
        $kb = $this->signIn('fix-3');

        $response = $this->withServerVariables(['HTTP_HOST' => $kb->tenant->subdomain.'.geo.example.test'])
            ->postJson("/api/v1/knowledge-bases/{$kb->id}/ingest-url", [
                'url' => 'http://127.0.0.1/admin',
                // B7/§7：project_id 必填，先过归属校验，才轮到 SSRF 拦截那条 422
                'project_id' => $this->attributionProjectId($kb),
            ], ['X-Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()]);

        $response->assertStatus(422);
        $message = (string) $response->json('message');
        $this->assertStringContainsString('内网地址', $message);
        $this->assertStringNotContainsString('文档保存失败，请重试', $message,
            '这种地址重试一百次也不会成功，不许回「请重试」');

        $this->assertSame(0, KnowledgeDocument::query()->count(), '被拦下的网址不许留 pending 行');
    }

    // ── 4. 清道夫：processing 超时该清（这才是它的本职：容器被 OOM kill）────────
    public function test_reaper_marks_stuck_processing_as_failed(): void
    {
        $tenant = $this->makeTenant('fix-4');
        $docId = $this->makeDocument($tenant, 'processing', 20);

        Artisan::call('knowledge:reap-stuck');

        $doc = KnowledgeDocument::withoutGlobalScope(TenantScope::class)->findOrFail($docId);
        $this->assertSame('failed', $doc->status);
        $this->assertStringContainsString('解析中断未完成', (string) $doc->error_message);
    }

    // ── 5. 清道夫：还在排队的文档绝不能按 15 分钟清 ───────────────────────────
    //
    // 生产 worker 的 --queue 是严格优先级，knowledge 排在 skills 后面；
    // 前面两个 480 秒的任务就 16 分钟。按 15 分钟砍 pending，等于把正常排队的文档判死：
    // 用户看到「失败，请重新上传」→ 前端因为没有 pending/processing 停止轮询 →
    // 任务其实随后成功了页面也不更新 → 用户重传 → 多一份文档 **且多花一次向量化的钱**。
    public function test_reaper_leaves_queued_documents_alone(): void
    {
        $tenant = $this->makeTenant('fix-5');
        $waiting = $this->makeDocument($tenant, 'pending', 20);   // 排了 20 分钟，正常
        $abandoned = $this->makeDocument($tenant, 'pending', 200); // 排了 3 个多小时，确实死了

        Artisan::call('knowledge:reap-stuck');

        $this->assertSame(
            'pending',
            KnowledgeDocument::withoutGlobalScope(TenantScope::class)->findOrFail($waiting)->status,
            '排队 20 分钟是正常现象，不许判死'
        );

        $dead = KnowledgeDocument::withoutGlobalScope(TenantScope::class)->findOrFail($abandoned);
        $this->assertSame('failed', $dead->status, '排了 3 小时才是真卡住');
        $this->assertStringContainsString('排队超过', (string) $dead->error_message);
    }

    private function makeDocument(Tenant $tenant, string $status, int $ageMinutes): int
    {
        TenantContext::set($tenant);
        $this->configureWorkspaceModelSlots($tenant);
        $kb = KnowledgeBase::query()->create([
            'tenant_id' => $tenant->id,
            'project_id' => self::knowledgeProjectId($tenant->id),
            'name' => 'Reaper Base '.$status.$ageMinutes,
            'status' => 'active',
        ]);
        $doc = app(\App\Services\AI\ModelConfiguration::class)->createDocument([
            'tenant_id' => $tenant->id,
            'knowledge_base_id' => $kb->id,
            'title' => 'doc-'.$status.'-'.$ageMinutes,
            'source_type' => 'upload',
            'mime_type' => 'text/plain',
            'file_size' => 10,
            'status' => $status,
        ]);
        TenantContext::forget();

        // 直接改 updated_at：用 update() 走模型会把时间戳又刷成现在
        KnowledgeDocument::withoutGlobalScope(TenantScope::class)
            ->whereKey($doc->id)
            ->update(['updated_at' => now()->subMinutes($ageMinutes)]);

        return (int) $doc->id;
    }

    // ── 7. 删知识库要把磁盘上的文件也清掉（此前只清了数据库）─────────────────
    public function test_deleting_a_knowledge_base_also_removes_its_files(): void
    {
        Queue::fake();
        $kb = $this->signIn('fix-7');

        $response = $this->upload($kb, UploadedFile::fake()->createWithContent(
            'note.txt',
            str_repeat('会被删掉的正文。', 30),
        ));
        $docId = $response->json('data.id');

        $path = (string) KnowledgeDocument::findOrFail($docId)->file_path;
        $this->assertTrue(\Illuminate\Support\Facades\Storage::disk('local')->exists($path),
            '前置条件：登记阶段应当已经落盘');

        $this->withServerVariables(['HTTP_HOST' => $kb->tenant->subdomain.'.geo.example.test'])
            ->deleteJson("/api/v1/knowledge-bases/{$kb->id}")
            ->assertStatus(200);

        $this->assertFalse(\Illuminate\Support\Facades\Storage::disk('local')->exists($path),
            '删了知识库，磁盘上的文件不该还在');
    }

    // ── 8. 上游 402 必须说成「余额不足」，不能说成「连接失败」──────────────────
    //
    // Http::retry() 重试耗尽后抛 RequestException，不是返回失败响应，所以按状态码
    // 分类的分支在真实环境里走不到（2026-08-20 本地生产镜像实测）。后果是客户
    // 明明该去充值，却被告知「连接失败」。同时异常原文里带着内网地址，一个字都不能带出去。
    public function test_upstream_rejection_is_reported_without_private_details(): void
    {
        $this->useWorkspaceModels();
        \Illuminate\Support\Facades\Http::fake([
            '*/embeddings' => \Illuminate\Support\Facades\Http::response(
                ['error' => ['message' => 'insufficient balance: tenant wallet 0 fen '
                    .'(upstream http://model-provider.example.test:8792/v1/embeddings, pod geo-gw-7d9f)']],
                402
            ),
        ]);


        $result = $this->app->make(\App\Services\AI\AiProviderServiceImpl::class)
            ->embedBatch(['一段需要向量化的正文']);

        $this->assertSame([], $result['embeddings']);
        $shown = (string) $result['error'];
        $this->assertStringContainsString('模型服务拒绝了请求', $shown);
        $this->assertSame('failed', $result['gateway']['status']);
        \Illuminate\Support\Facades\Http::assertSentCount(1);
        $this->assertStringNotContainsString('model-provider.example.test', $shown, '内网地址不许带给客户');
        $this->assertStringNotContainsString('8792', $shown);
        $this->assertStringNotContainsString('geo-gw-7d9f', $shown);
    }

    // ── 6. 企业发布失败时不许回「已发布」───────────────────────────────────────
    public function test_enterprise_publish_failure_is_not_reported_as_success(): void
    {
        Queue::fake();
        $tenant = $this->makeTenant('fix-6');
        $this->configureWorkspaceModelSlots($tenant);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => \Tests\Support\FixtureIdentity::phone('fix-6'),
            'role' => 'admin',
        ]);
        Sanctum::actingAs($user, ['*']);

        TenantContext::set($tenant);
        $this->configureWorkspaceModelSlots($tenant);
        $project = EnterpriseKnowledgeProject::query()->create([
            'tenant_id' => $tenant->id,
            'project_id' => self::knowledgeProjectId($tenant->id),
            'name' => '发布会失败的档案',
            'status' => EnterpriseKnowledgeProject::STATUS_REVIEWING,
            'draft_content' => "# 标题\n正文内容足够长，用来发布。\n",
        ]);
        TenantContext::forget();

        // 让登记这一步炸掉（数据库连不上是最典型的一种），异常原文里带内部信息
        $this->app->instance(DocumentIngestionService::class, new class(new MockAiProvider) extends DocumentIngestionService
        {
            public function stageText(int $knowledgeBaseId, string $title, string $content, array $metadata = []): KnowledgeDocument
            {
                throw new \RuntimeException('SQLSTATE[08006] connection to server at "postgres" (172.18.0.4), port 5432 failed');
            }
        });

        $response = $this->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain)
            ->postJson("/api/v1/enterprise-knowledge/{$project->id}/publish", ['create_knowledge_base' => true]);

        $response->assertStatus(422);
        $message = (string) $response->json('message');
        $this->assertStringNotContainsString('已发布', $message, '没发布成功就不许说「已发布」');
        $this->assertStringNotContainsString('SQLSTATE', $message);
        $this->assertStringNotContainsString('172.18.0.4', $message);

        TenantContext::set($tenant);
        $this->configureWorkspaceModelSlots($tenant);
        $fresh = EnterpriseKnowledgeProject::query()->findOrFail($project->id);
        TenantContext::forget();
        $this->assertNotSame(EnterpriseKnowledgeProject::STATUS_PUBLISHED, $fresh->status,
            '发布失败时状态不许变成 published');
        $this->assertNotNull($fresh->published_knowledge_base_id,
            '知识库 ID 要留着，否则重试会再建一个同名知识库');
    }
}
