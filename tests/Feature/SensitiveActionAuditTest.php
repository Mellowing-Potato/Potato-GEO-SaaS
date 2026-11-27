<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\Article;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Models\Project;
use App\Models\PublishingChannel;
use App\Models\Tenant;
use App\Models\TenantAiKey;
use App\Models\User;
use App\Support\Concerns\RecordsSensitiveAction;
use App\Support\Permissions;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 敏感操作审计清单（计划 §9.7，用户 2026-09-21 拍板「可以，做吧」）。
 *
 * 口径一句话：**凡是"改权限、发/撤凭据、动钱、删数据、对外发布"的动作，都必须落一行审计。**
 *
 * 这组测试对清单上的每个动作真跑一次接口，然后断言审计表里出现了对应的 action ——
 * 光看代码里"有没有调 auditSensitive"证明不了接线对不对（漏传、写错 action 名、
 * 提前 return 都会让动作发生而审计缺失），必须端到端跑一遍。
 *
 * 同时把**权限矩阵**钉住（B4 的另一半）：矩阵本身维持现状（已是最小权限），
 * 这里断言 member / editor 的边界不因后续改动被悄悄放宽。
 */
class SensitiveActionAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.tenant_domain' => 'geo.example.test']);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    /** 清单 1/2：删数据类动作（文章删除、移入回收站、恢复）。 */
    public function test_article_data_actions_write_audit_rows(): void
    {
        [$tenant, $admin, $project] = $this->fixture();
        Sanctum::actingAs($admin, ['*']);

        $article = $this->article($tenant, $project, '要被删的文章');
        $this->forTenant($tenant)->deleteJson("/api/v1/articles/{$article->id}")->assertOk();
        $this->assertAudited('article.deleted', $article->id);

        $recycle = $this->article($tenant, $project, '要进回收站的文章');
        $this->forTenant($tenant)->postJson("/api/v1/articles/{$recycle->id}/recycle")->assertOk();
        $this->assertAudited('article.recycled', $recycle->id);

        $this->forTenant($tenant)->postJson("/api/v1/articles/{$recycle->id}/restore")->assertOk();
        $this->assertAudited('article.restored', $recycle->id);
    }

    /** 清单 3：删项目（进桶）—— 真删那一刻另记 project.purged（见 ProjectTrashService）。 */
    public function test_project_trash_writes_an_audit_row(): void
    {
        [$tenant, $admin, $project] = $this->fixture();
        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)->deleteJson("/api/v1/projects/{$project->id}")->assertNoContent();

        $this->assertAudited('project.trashed', $project->id);
    }

    /** 清单 4：发/撤凭据（渠道密钥）—— 明文绝不进审计。 */
    public function test_channel_secret_actions_write_audit_rows_without_the_secret(): void
    {
        [$tenant, $admin, $project] = $this->fixture();
        Sanctum::actingAs($admin, ['*']);

        $channel = PublishingChannel::query()->create([
            'tenant_id' => $tenant->id,
            'name' => '客户 WordPress',
            'type' => PublishingChannel::TYPE_WORDPRESS_REST,
            'endpoint_url' => 'https://customer.example.test',
            'is_active' => true,
        ]);

        $created = $this->forTenant($tenant)->postJson("/api/v1/publishing/channels/{$channel->id}/secrets")->assertStatus(201);
        $this->assertAudited('channel_secret.created', $channel->id);

        $secret = $created->json('secret');
        $this->assertNotEmpty($secret);

        // ⚠️ 明文绝不能出现在审计明细里（那等于把凭据抄进审计表）
        $log = AdminActivityLog::query()->where('action', 'channel_secret.created')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString((string) $secret, (string) $log->details);

        $keyId = (string) $created->json('key_id');
        $secretId = \App\Models\DistributionChannelSecret::query()->where('key_id', $keyId)->value('id');
        $this->forTenant($tenant)
            ->deleteJson("/api/v1/publishing/channels/{$channel->id}/secrets/{$secretId}")
            ->assertOk();
        $this->assertAudited('channel_secret.revoked', $channel->id);
    }

    /** 清单 5：API 令牌（凭据）创建与吊销。 */
    public function test_api_token_actions_write_audit_rows(): void
    {
        [$tenant, $admin] = $this->fixture();
        Sanctum::actingAs($admin, ['*']);

        $created = $this->forTenant($tenant)->postJson('/api/v1/api-tokens', [
            'name' => '自动化令牌',
            'scopes' => ['projects:read'],
        ])->assertStatus(201);

        $record = $created->json('record');
        $this->assertAudited('api_token.created', null, ['token_id' => (int) ($record['id'] ?? 0)]);

        $this->forTenant($tenant)->deleteJson('/api/v1/api-tokens/'.($record['id'] ?? 0))->assertOk();
        $this->assertAudited('api_token.revoked', null, ['token_id' => (int) ($record['id'] ?? 0)]);
    }

    /** 清单 6：客户自带模型凭据（AI Key）写/删/停用。 */
    public function test_tenant_ai_key_actions_write_audit_rows(): void
    {
        [$tenant, $admin] = $this->fixture();
        Sanctum::actingAs($admin, ['*']);

        $this->app->instance(\App\Contracts\Outbound\HostResolver::class, new class implements \App\Contracts\Outbound\HostResolver {
            public function resolve(string $host): array { return ['93.184.216.34']; }
        });
        $saved = $this->forTenant($tenant)->postJson('/api/v1/ai-keys', [
            'slot' => 'text', 'base_url' => 'https://models.example.test/v1', 'model' => 'test-model',
            'provider' => 'dashscope',
            'api_key' => 'sk-test-not-a-real-key',
            'label' => '测试 Key',
        ])->assertStatus(201);
        $keyId = (int) $saved->json('id');
        $this->assertAudited('tenant_ai_key.saved', $keyId);

        $this->forTenant($tenant)->patchJson("/api/v1/ai-keys/{$keyId}/toggle")->assertOk();
        $this->assertAudited('tenant_ai_key.toggled', $keyId);

        $this->forTenant($tenant)->deleteJson("/api/v1/ai-keys/{$keyId}")->assertOk();
        $this->assertAudited('tenant_ai_key.deleted', $keyId);
    }

    /** 清单 7：删数据类动作（知识库、知识库文档）。 */
    public function test_knowledge_deletions_write_audit_rows(): void
    {
        [$tenant, $admin, $project] = $this->fixture();
        Sanctum::actingAs($admin, ['*']);

        TenantContext::set($tenant);
        try {
            $base = KnowledgeBase::query()->create(['name' => '待删知识库', 'type' => 'general', 'project_id' => $project->id]);
            $document = KnowledgeDocument::query()->create([
                'knowledge_base_id' => $base->id,
                'title' => '待删文档',
                'file_name' => 'doc.txt',
                'source_type' => 'upload',
                'status' => 'ready',
            ]);
            $document->update(['file_path' => $document->storageDirectory().'/doc.txt']);
        } finally {
            TenantContext::forget();
        }

        $this->forTenant($tenant)
            ->deleteJson("/api/v1/knowledge-bases/{$base->id}/documents/{$document->id}")
            ->assertOk();
        $this->assertAudited('knowledge_document.deleted', $document->id);

        $this->forTenant($tenant)->deleteJson("/api/v1/knowledge-bases/{$base->id}")->assertOk();
        $this->assertAudited('knowledge_base.deleted', $base->id);
    }

    /**
     * 权限矩阵（§9.7 的另一半）：维持现状 = 最小权限。
     *
     * 把"谁能做什么"钉成判据，避免后续改动顺手放宽 member/editor 边界 ——
     * 尤其 member 不该能删数据、editor 不该能管团队与渠道凭据。
     */
    public function test_the_permission_matrix_stays_minimal(): void
    {
        // member：能看，不能改
        $this->assertTrue(Permissions::allows('member', 'project:view'));
        $this->assertTrue(Permissions::allows('member', 'article:view'));
        $this->assertFalse(Permissions::allows('member', 'project:create'));
        $this->assertFalse(Permissions::allows('member', 'project:delete'));
        $this->assertFalse(Permissions::allows('member', 'article:create'));
        $this->assertFalse(Permissions::allows('member', 'article:delete'));
        $this->assertFalse(Permissions::allows('member', 'team:manage'));
        $this->assertFalse(Permissions::allows('member', 'settings:update'));

        // editor：能写内容，不能管团队/租户设置，也不能删项目
        $this->assertTrue(Permissions::allows('editor', 'article:create'));
        $this->assertTrue(Permissions::allows('editor', 'project:create'));
        $this->assertFalse(Permissions::allows('editor', 'project:delete'));
        $this->assertFalse(Permissions::allows('editor', 'article:delete'));
        $this->assertFalse(Permissions::allows('editor', 'team:manage'));
        $this->assertFalse(Permissions::allows('editor', 'team:invite'));
        $this->assertFalse(Permissions::allows('editor', 'settings:update'));
        $this->assertFalse(Permissions::allows('editor', 'settings:view'));

        // admin：租户内的上限（平台能力另有一套，不在这里）
        $this->assertTrue(Permissions::allows('admin', 'project:delete'));
        $this->assertTrue(Permissions::allows('admin', 'article:delete'));
        $this->assertTrue(Permissions::allows('admin', 'team:manage'));
        $this->assertTrue(Permissions::allows('admin', 'settings:update'));
        $this->assertFalse(Permissions::allows('admin', 'platform:operations'), '租户 admin 不能碰平台运营能力');
    }

    /** 契约：审计只放公开辨识信息，凭据永不明文（这条在 RecordsSensitiveAction 的注释里，这里钉行为）。 */
    public function test_the_audit_trait_documents_its_own_contract(): void
    {
        $source = file_get_contents((new \ReflectionClass(RecordsSensitiveAction::class))->getFileName());
        $this->assertStringContainsString('**凡是"改权限、发/撤凭据、动钱、删数据、对外发布"的动作，都必须落一行审计。**', $source);
        $this->assertStringContainsString('明细里**不许放凭据本身**', $source);
    }

    /** @param array<string,mixed> $context */
    private function assertAudited(string $action, ?int $targetId, array $context = []): void
    {
        $query = AdminActivityLog::query()->where('action', $action);
        if ($targetId !== null) {
            $query->where('target_id', $targetId);
        }

        // ⚠️ 不能用 whereLike 匹配 details：ActivityLogService 会把标量统一转成字符串
        //    （`{"token_id":"1"}` 而不是 `{"token_id":1}`），直接 LIKE `"token_id":1` 会漏判。
        //    这里取回来在 PHP 里判，两种形态都认。
        $rows = $query->get(['details']);
        $this->assertTrue($rows->isNotEmpty(), "敏感动作 {$action} 没有写审计行");

        foreach ($context as $key => $value) {
            $hit = $rows->contains(function (AdminActivityLog $row) use ($key, $value): bool {
                $details = (string) ($row->details ?? '');

                return str_contains($details, '"'.$key.'":"'.$value.'"') || str_contains($details, '"'.$key.'":'.$value);
            });
            $this->assertTrue($hit, "敏感动作 {$action} 的审计明细里没有 {$key}={$value}");
        }
    }

    /** @return array{0:Tenant,1:User,2:Project} */
    private function fixture(): array
    {
        $tenant = Tenant::query()->create([
            'subdomain' => 'audit-'.bin2hex(random_bytes(3)),
            'name' => 'AUDIT',
            'plan' => 'basic',
            'status' => 'active',
        ]);
        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,

            'role' => 'admin',
            'password' => null,
        ]);

        TenantContext::set($tenant);
        try {
            $project = Project::query()->create([
                'owner_id' => $admin->id,
                'name' => '审计夹具项目',
                'current_stage' => 0,
                'progress' => 0,
            ]);
        } finally {
            TenantContext::forget();
        }

        return [$tenant, $admin, $project];
    }

    private function article(Tenant $tenant, Project $project, string $title): Article
    {
        TenantContext::set($tenant);
        try {
            return Article::query()->create([
                'project_id' => $project->id,
                'title' => $title,
                'body_markdown' => '# 正文',
                'status' => 'draft',
                'source_type' => 'manual',
                'metadata' => [],
            ]);
        } finally {
            TenantContext::forget();
        }
    }

    private function forTenant(Tenant $tenant): self
    {
        return $this
            ->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }
}
