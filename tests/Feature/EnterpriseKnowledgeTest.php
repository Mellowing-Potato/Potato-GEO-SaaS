<?php

namespace Tests\Feature;

use App\Models\EnterpriseKnowledgeProject;
use App\Models\EnterpriseKnowledgeRevision;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Tenant;
use App\Models\User;
use App\Jobs\GenerateEnterpriseKnowledgeDraftJob;
use App\Jobs\IngestKnowledgeDocumentJob;
use App\Services\Contracts\KnowledgeRetrieval;
use App\Services\Knowledge\DocumentIngestionService;
use App\Services\Knowledge\EnterpriseKnowledgeDraftService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 阶段 1.5 企业知识库 revision 流 HTTP 测试（路由 / 权限 / 租户隔离 / 生成 / 修订 / 发布 / 检索）
 */
class EnterpriseKnowledgeTest extends TestCase
{
    use \Tests\Support\UsesWorkspaceModels;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.tenant_domain' => 'geo.example.test']);
    }

    private function makeTenantUser(string $subdomain, string $role): array
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

            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain . $role),
            'role' => $role,
        ]);

        (new \App\Models\Project())->forceFill(['tenant_id' => $tenant->id, 'owner_id' => $user->id, 'name' => '业务项目', 'status' => 'active'])->save();
        return [$tenant, $user];
    }

    private function forTenant(Tenant $tenant): self
    {
        return $this
            ->withServerVariables(['HTTP_HOST' => $tenant->subdomain . '.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain)
            ->withHeader('X-Project-Id', \App\Models\Project::withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('id'));
    }

    public function test_admin_can_create_project_with_sources(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');

        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->postJson('/api/v1/enterprise-knowledge', [
                'name' => '星云智控知识库',
                'description' => '工业设备预测性维护企业',
                'content' => "# 星云智控\n星云智控提供工业设备预测性维护服务。\n",
                'sources' => [
                    ['original_name' => '产品手册', 'content' => "星云智控的振动监测模块可实时采集设备数据。\n适用场景：风机、泵站、压缩机。", 'file_type' => 'markdown'],
                ],
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.name', '星云智控知识库')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('object', 'enterprise_knowledge_project');

        $this->assertDatabaseHas('enterprise_knowledge_projects', [
            'tenant_id' => $tenant->id,
            'name' => '星云智控知识库',
        ]);
        $this->assertDatabaseHas('enterprise_knowledge_sources', [
            'tenant_id' => $tenant->id,
            'original_name' => '手动录入',
        ]);
        $this->assertDatabaseHas('enterprise_knowledge_sources', [
            'tenant_id' => $tenant->id,
            'original_name' => '产品手册',
        ]);
    }

    public function test_member_cannot_manage_knowledge(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');

        $member = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => \Tests\Support\FixtureIdentity::phone('tenant-a_member'),
            'role' => 'member',
        ]);

        Sanctum::actingAs($member, ['*']);

        $this->forTenant($tenant)
            ->postJson('/api/v1/enterprise-knowledge', [
                'name' => 'M 知识库',
                'content' => '一些来源内容',
            ])
            ->assertStatus(403);
    }

    public function test_cross_tenant_project_returns_404(): void
    {
        [$tenantA, $adminA] = $this->makeTenantUser('tenant-a', 'admin');
        [$tenantB, $adminB] = $this->makeTenantUser('tenant-b', 'admin');

        Sanctum::actingAs($adminA, ['*']);
        $response = $this->forTenant($tenantA)
            ->postJson('/api/v1/enterprise-knowledge', [
                'name' => 'A 的知识库',
                'content' => 'A 的来源内容',
            ])
            ->assertStatus(201);
        $projectId = $response->json('data.id');

        // B 访问 A 的项目 → 404（租户隔离）
        Sanctum::actingAs($adminB, ['*']);
        $this->forTenant($tenantB)
            ->getJson("/api/v1/enterprise-knowledge/{$projectId}")
            ->assertStatus(404);
    }

    public function test_draft_job_rejects_requester_from_another_tenant_before_generation(): void
    {
        [$tenantA, $adminA] = $this->makeTenantUser('tenant-a', 'admin');
        [, $adminB] = $this->makeTenantUser('tenant-b', 'admin');
        Sanctum::actingAs($adminA, ['*']);
        $projectId = $this->forTenant($tenantA)
            ->postJson('/api/v1/enterprise-knowledge', [
                'name' => 'Synthetic A knowledge', 'content' => 'Synthetic source',
            ])->assertCreated()->json('data.id');

        (new GenerateEnterpriseKnowledgeDraftJob($projectId, $adminB->id))
            ->handle($this->app->make(EnterpriseKnowledgeDraftService::class));

        $this->assertDatabaseHas('enterprise_knowledge_projects', [
            'id' => $projectId, 'tenant_id' => $tenantA->id,
            'status' => EnterpriseKnowledgeProject::STATUS_FAILED,
        ]);
        $this->assertDatabaseCount('enterprise_knowledge_revisions', 0);
    }

    public function test_generate_produces_structured_draft_and_revision(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');

        Sanctum::actingAs($admin, ['*']);
        $response = $this->forTenant($tenant)
            ->postJson('/api/v1/enterprise-knowledge', [
                'name' => '星云智控知识库',
                'content' => "# 星云智控\n星云智控提供工业设备预测性维护服务，成立于 2015 年。\n",
                'sources' => [
                    ['original_name' => '产品手册', 'content' => "星云智控的振动监测模块可实时采集设备数据。\n适用场景：风机、泵站、压缩机。\n典型案例：某风电场部署后故障率下降 30%。", 'file_type' => 'markdown'],
                ],
            ])
            ->assertStatus(201);
        $projectId = $response->json('data.id');

        // 触发生成（sync 队列，作业内联执行）
        $this->forTenant($tenant)
            ->postJson("/api/v1/enterprise-knowledge/{$projectId}/generate", ['base_version' => (int) EnterpriseKnowledgeProject::withoutGlobalScopes()->findOrFail($projectId)->draft_version])
            ->assertStatus(202);

        $project = EnterpriseKnowledgeProject::query()->where('tenant_id', $tenant->id)->findOrFail($projectId);
        $this->assertSame(EnterpriseKnowledgeProject::STATUS_REVIEWING, $project->status);
        $this->assertNotEmpty($project->draft_content);

        // 9 个必选章节齐全
        foreach (['企业介绍', '业务信息摘要', '产品能力', '应用场景', '典型案例', 'FAQ', '禁用表述', '风险与冲突', '待人工确认'] as $section) {
            $this->assertStringContainsString('## ' . $section, $project->draft_content);
        }

        // 来源事实被纳入草稿
        $this->assertStringContainsString('星云智控', $project->draft_content);
        $this->assertStringContainsString('振动监测', $project->draft_content);

        // 生成作业记录了一条 system 修订
        $this->assertDatabaseHas('enterprise_knowledge_revisions', [
            'tenant_id' => $tenant->id,
            'enterprise_knowledge_project_id' => $projectId,
            'source' => 'system',
        ]);
    }

    public function test_autosave_records_revision_when_content_changes(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');

        Sanctum::actingAs($admin, ['*']);
        $response = $this->forTenant($tenant)
            ->postJson('/api/v1/enterprise-knowledge', [
                'name' => '草稿修订测试',
                'content' => '初始来源内容',
            ])
            ->assertStatus(201);
        $projectId = $response->json('data.id');

        // 生成一次，产生 1 条 system 修订
        $this->forTenant($tenant)
            ->postJson("/api/v1/enterprise-knowledge/{$projectId}/generate", ['base_version' => (int) EnterpriseKnowledgeProject::withoutGlobalScopes()->findOrFail($projectId)->draft_version])
            ->assertStatus(202);

        // 手动保存两次不同内容 → 各产生 1 条 manual 修订
        $this->forTenant($tenant)
            ->postJson("/api/v1/enterprise-knowledge/{$projectId}/autosave", [
                'base_version' => (int) EnterpriseKnowledgeProject::withoutGlobalScopes()->findOrFail($projectId)->draft_version,
                'content' => "# 自定义草稿 v1\n这是第一次手动编辑的内容 MARKER_V1。",
            ])
            ->assertStatus(200);

        $this->forTenant($tenant)
            ->postJson("/api/v1/enterprise-knowledge/{$projectId}/autosave", [
                'base_version' => (int) EnterpriseKnowledgeProject::withoutGlobalScopes()->findOrFail($projectId)->draft_version,
                'content' => "# 自定义草稿 v2\n这是第二次手动编辑的内容 MARKER_V2。",
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('enterprise_knowledge_revisions', [
            'tenant_id' => $tenant->id,
            'enterprise_knowledge_project_id' => $projectId,
            'source' => 'manual',
        ]);
        $manualCount = EnterpriseKnowledgeRevision::query()
            ->where('tenant_id', $tenant->id)
            ->where('enterprise_knowledge_project_id', $projectId)
            ->where('source', 'manual')
            ->count();
        $this->assertSame(2, $manualCount);
    }

    public function test_restore_revision_reverts_draft(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');

        Sanctum::actingAs($admin, ['*']);
        $response = $this->forTenant($tenant)
            ->postJson('/api/v1/enterprise-knowledge', [
                'name' => '恢复修订测试',
                'content' => '初始来源内容',
            ])
            ->assertStatus(201);
        $projectId = $response->json('data.id');

        $this->forTenant($tenant)
            ->postJson("/api/v1/enterprise-knowledge/{$projectId}/generate", ['base_version' => (int) EnterpriseKnowledgeProject::withoutGlobalScopes()->findOrFail($projectId)->draft_version])
            ->assertStatus(202);

        // 记录一次手动编辑（成为最新草稿）
        $this->forTenant($tenant)
            ->postJson("/api/v1/enterprise-knowledge/{$projectId}/autosave", [
                'base_version' => (int) EnterpriseKnowledgeProject::withoutGlobalScopes()->findOrFail($projectId)->draft_version,
                'content' => "# 手动编辑版\n这是会被覆盖的内容 MARKER_LATEST。",
            ])
            ->assertStatus(200);

        // 取回 system 修订（生成时的草稿）
        $systemRevision = EnterpriseKnowledgeRevision::query()
            ->where('tenant_id', $tenant->id)
            ->where('enterprise_knowledge_project_id', $projectId)
            ->where('source', 'system')
            ->firstOrFail();
        $expectedContent = (string) $systemRevision->content;

        // 恢复到 system 修订
        $this->forTenant($tenant)
            ->postJson("/api/v1/enterprise-knowledge/{$projectId}/restore/{$systemRevision->id}", ['base_version' => (int) EnterpriseKnowledgeProject::withoutGlobalScopes()->findOrFail($projectId)->draft_version])
            ->assertStatus(200);

        $project = EnterpriseKnowledgeProject::query()->where('tenant_id', $tenant->id)->findOrFail($projectId);
        $this->assertSame($expectedContent, $project->draft_content);
        $this->assertSame(EnterpriseKnowledgeProject::STATUS_REVIEWING, $project->status);

        // 恢复操作本身产生一条 restore 修订
        $this->assertDatabaseHas('enterprise_knowledge_revisions', [
            'tenant_id' => $tenant->id,
            'enterprise_knowledge_project_id' => $projectId,
            'source' => 'restore',
        ]);
    }

    public function test_publish_creates_knowledge_base_and_is_retrievable(): void
    {
        Queue::fake();
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');

        Sanctum::actingAs($admin, ['*']);
        $response = $this->forTenant($tenant)
            ->postJson('/api/v1/enterprise-knowledge', [
                'name' => '星云智控知识库',
                'content' => "# 星云智控\n星云智控提供工业设备预测性维护服务。\n",
                'sources' => [
                    ['original_name' => '产品手册', 'content' => "星云智控的振动监测模块可实时采集设备数据。\n适用场景：风机、泵站、压缩机。\n典型案例：某风电场部署后故障率下降 30%。", 'file_type' => 'markdown'],
                ],
            ])
            ->assertStatus(201);
        $projectId = $response->json('data.id');

        $this->forTenant($tenant)
            ->postJson("/api/v1/enterprise-knowledge/{$projectId}/generate", ['base_version' => (int) EnterpriseKnowledgeProject::withoutGlobalScopes()->findOrFail($projectId)->draft_version])
            ->assertStatus(202);

        // generate 也是队列任务：手动跑完，publish 才有草稿可读
        $generateJob = collect(Queue::pushed(GenerateEnterpriseKnowledgeDraftJob::class))->first();
        $this->assertNotNull($generateJob, '期望有一条入队的草稿生成任务');
        $generateJob->handle($this->app->make(EnterpriseKnowledgeDraftService::class));

        $this->forTenant($tenant)
            ->postJson("/api/v1/enterprise-knowledge/{$projectId}/publish", ['base_version' => (int) EnterpriseKnowledgeProject::withoutGlobalScopes()->findOrFail($projectId)->draft_version, 'create_knowledge_base' => true])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('object', 'enterprise_knowledge_project');

        $project = EnterpriseKnowledgeProject::query()->where('tenant_id', $tenant->id)->findOrFail($projectId);
        $this->assertNotNull($project->published_knowledge_base_id);
        $this->assertNotNull($project->published_document_id);

        $this->assertDatabaseHas('knowledge_bases', [
            'tenant_id' => $tenant->id,
            'id' => $project->published_knowledge_base_id,
            'status' => 'active',
        ]);
        // 发布改为「登记 + 入队」：publish 请求返回时文档还是 pending
        $this->assertDatabaseHas('knowledge_documents', [
            'tenant_id' => $tenant->id,
            'id' => $project->published_document_id,
            'status' => 'pending',
        ]);

        // 手动把入库 job 跑完，再校验切片
        $ingestJob = collect(Queue::pushed(IngestKnowledgeDocumentJob::class))->first();
        $this->assertNotNull($ingestJob, '期望有一条入队的入库任务');
        $ingestJob->handle($this->app->make(DocumentIngestionService::class));

        $doc = KnowledgeDocument::query()->where('tenant_id', $tenant->id)->findOrFail($project->published_document_id);
        $this->assertSame('ready', $doc->status, '入库 job 跑完应落 ready');
        $chunkCount = KnowledgeChunk::query()
            ->where('tenant_id', $tenant->id)
            ->where('document_id', $project->published_document_id)
            ->count();
        $this->assertGreaterThan(0, $chunkCount);

        // 发布后可被检索命中
        TenantContext::set($tenant);
        $this->configureWorkspaceModelSlots($tenant);
        $results = $this->app->make(KnowledgeRetrieval::class)
            ->retrieve('星云智控 振动监测 预测性维护', 5, filters: ['project_id' => $project->project_id]);
        TenantContext::forget();

        $this->assertNotEmpty($results);
        $hit = false;
        foreach ($results as $chunk) {
            if (str_contains((string) $chunk->content, '星云智控') || str_contains((string) $chunk->content, '振动监测')) {
                $hit = true;
                break;
            }
        }
        $this->assertTrue($hit, '检索结果应包含已发布的企业知识内容');
    }

    public function test_publish_uses_selected_project_base_and_reuses_identical_document_with_sources(): void
    {
        Queue::fake();
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');
        Sanctum::actingAs($admin, ['*']);
        $projectId = \App\Models\Project::withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('id');
        $base = KnowledgeBase::create([
            'tenant_id' => $tenant->id, 'project_id' => $projectId,
            'name' => '已有资料库', 'status' => 'active',
        ]);
        $otherProject = (new \App\Models\Project())->forceFill([
            'tenant_id' => $tenant->id, 'owner_id' => $admin->id, 'name' => '其他项目', 'status' => 'active',
        ]);
        $otherProject->save();
        $otherBase = KnowledgeBase::create([
            'tenant_id' => $tenant->id, 'project_id' => $otherProject->id,
            'name' => '其他项目资料库', 'status' => 'active',
        ]);
        $ekId = $this->forTenant($tenant)->postJson('/api/v1/enterprise-knowledge', [
            'name' => '事实卡', 'content' => '可核实的手工事实',
            'sources' => [[
                'original_name' => '官网', 'content' => '官网公开服务说明',
                'source_url' => 'https://example.com/geo',
            ]],
        ])->assertCreated()->json('data.id');

        $url = "/api/v1/enterprise-knowledge/{$ekId}/publish";
        $this->forTenant($tenant)->postJson($url, ['base_version' => (int) EnterpriseKnowledgeProject::withoutGlobalScopes()->findOrFail($ekId)->draft_version, 'knowledge_base_id' => $otherBase->id, 'content' => '事实卡正文'])
            ->assertStatus(422);
        $this->forTenant($tenant)->postJson($url, ['base_version' => (int) EnterpriseKnowledgeProject::withoutGlobalScopes()->findOrFail($ekId)->draft_version, 'content' => '事实卡正文'])->assertStatus(422);
        $first = $this->forTenant($tenant)->postJson($url, ['base_version' => (int) EnterpriseKnowledgeProject::withoutGlobalScopes()->findOrFail($ekId)->draft_version,
            'knowledge_base_id' => $base->id, 'content' => '事实卡正文',
        ])->assertOk()->assertJsonPath('data.published_knowledge_base_id', $base->id)
            ->assertJsonPath('data.sources.1.source_url', 'https://example.com/geo');
        $docId = $first->json('data.published_document_id');
        $this->forTenant($tenant)->postJson($url, ['base_version' => (int) EnterpriseKnowledgeProject::withoutGlobalScopes()->findOrFail($ekId)->draft_version,
            'knowledge_base_id' => $base->id, 'content' => '事实卡正文',
        ])->assertOk()->assertJsonPath('data.published_document_id', $docId);
        $this->assertSame(1, KnowledgeDocument::query()->where('knowledge_base_id', $base->id)->count());
        $this->assertSame(["https://example.com/geo"], KnowledgeDocument::findOrFail($docId)->metadata['source_urls']);

        $new = $this->forTenant($tenant)->postJson($url, ['base_version' => (int) EnterpriseKnowledgeProject::withoutGlobalScopes()->findOrFail($ekId)->draft_version,
            'create_knowledge_base' => true, 'content' => '事实卡更新正文',
        ])->assertOk();
        $newBaseId = $new->json('data.published_knowledge_base_id');
        $this->assertNotSame($base->id, $newBaseId);
        $this->forTenant($tenant)->postJson($url, ['base_version' => (int) EnterpriseKnowledgeProject::withoutGlobalScopes()->findOrFail($ekId)->draft_version,
            'create_knowledge_base' => true, 'content' => '事实卡更新正文',
        ])->assertOk()->assertJsonPath('data.published_knowledge_base_id', $newBaseId);
        $this->assertSame(2, KnowledgeBase::query()->where('project_id', $projectId)->count());
    }
}
