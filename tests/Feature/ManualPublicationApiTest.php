<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ManualPublication;
use App\Models\ManualPublicationPersona;
use App\Models\ManualPublicationTransition;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Publishing\ManualPublicationService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 单 7 · 手动发布 API 集成验收（执行书 §四 M1–M6 / M8–M10）。
 *
 * M2 跨租户 404 / M3 全链路轨迹 / M4 completed 不可改 / M5 重开 / M6 判重 /
 * M8 editor 建单但不可指派 / M9 member 403 /
 * M10 editor 租户内通管（2026-08-28 改判，原「非指派人不可流转」是上游单人模型的照抄）/
 * M10b 重开仍是 admin 专属 / M10c 人设账号读放行写收归 admin。
 */
class ManualPublicationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.tenant_domain' => 'geo.example.test']);
    }

    /* ─────────────── M3：全链路 + 轨迹 ─────────────── */

    public function test_full_lifecycle_records_every_transition(): void
    {
        [$tenant, $admin] = $this->createTenantUser('mp-flow', 'admin');
        Sanctum::actingAs($admin, ['*']);
        $personaId = $this->createPersonaViaApi($tenant, '评测师');

        $created = $this
            ->forTenant($tenant)
            ->postJson('/api/v1/manual-publications', [
                'type' => 'comment',
                'persona_id' => $personaId,
                'platform' => 'zhihu',
                'content' => '第一条真实评测内容，来自人工发布流程。',
                'target_url' => 'https://example.com/question/1',
                'target_context' => '某品牌值得买吗？',
                'assigned_user_id' => $admin->id,
            ])
            ->assertStatus(201);

        $id = $created->json('data.id');
        $revision = 1;

        // draft → ready → in_progress → completed
        $revision = $this->transition($tenant, $id, 'ready', $revision);
        $revision = $this->transition($tenant, $id, 'in_progress', $revision);
        $revision = $this->transition($tenant, $id, 'completed', $revision, [
            'completion_url' => 'https://zhihu.com/answer/123',
        ]);

        $this->assertSame(4, $revision);

        $publication = ManualPublication::query()->whereKey($id)->firstOrFail();
        $this->assertSame('completed', $publication->status);

        // 轨迹：null→draft，draft→ready，ready→in_progress，in_progress→completed
        $transitions = ManualPublicationTransition::query()
            ->where('manual_publication_id', $id)
            ->orderBy('id')
            ->get();
        $this->assertCount(4, $transitions);
        $this->assertSame([null, 'draft', 'ready', 'in_progress'], $transitions->pluck('from_status')->all());
        $this->assertSame(['draft', 'ready', 'in_progress', 'completed'], $transitions->pluck('to_status')->all());
        $this->assertTrue($transitions->every(fn (ManualPublicationTransition $t): bool => (int) $t->changed_by_user_id === $admin->id));
        $this->assertSame('https://zhihu.com/answer/123', $transitions->last()->completion_url);

        // M1 顺带：两张表都带正确 tenant_id
        $this->assertSame($tenant->id, $publication->tenant_id);
        $this->assertTrue($transitions->every(fn (ManualPublicationTransition $t): bool => (int) $t->tenant_id === $tenant->id));
    }

    /* ─────────────── M2：跨租户拿不到 ─────────────── */

    public function test_cross_tenant_show_returns_404(): void
    {
        [$tenantA, $adminA] = $this->createTenantUser('mp-tenant-a', 'admin');
        [$tenantB, $adminB] = $this->createTenantUser('mp-tenant-b', 'admin');

        $publicationB = $this->createPublicationFor($tenantB, $adminB);

        Sanctum::actingAs($adminA, ['*']);
        $this
            ->forTenant($tenantA)
            ->getJson('/api/v1/manual-publications/'.$publicationB->id)
            ->assertStatus(404);

        // B 家的单必须还在
        $this->assertNotNull(
            ManualPublication::withoutGlobalScopes()->find($publicationB->id),
            'A 家的请求不能删掉 B 家的发布单',
        );

        // 列表也看不到 B 家的
        $response = $this->forTenant($tenantA)->getJson('/api/v1/manual-publications');
        $response->assertStatus(200);
        $this->assertSame(0, $response->json('stats.total'));
    }

    public function test_zhihu_draft_sync_keeps_publication_unpublished_and_requires_public_completion_url(): void
    {
        [$tenant, $admin] = $this->createTenantUser('mp-zhihu-draft', 'admin');
        Sanctum::actingAs($admin, ['*']);
        $article = $this->createArticleFor($tenant, $admin, 'approved');
        $personaId = $this->createPersonaViaApi($tenant, '知乎作者');
        $accountId = $this->forTenant($tenant)
            ->postJson('/api/v1/manual-publications/settings/accounts', [
                'persona_id' => $personaId,
                'platform' => 'zhihu',
                'account_name' => '测试知乎账号',
                'profile_url' => 'https://www.zhihu.com/people/test-author',
            ])->assertStatus(201)->json('data.id');

        $longBody = str_repeat('科研内容。', 600);
        $created = $this->forTenant($tenant)
            ->postJson('/api/v1/manual-publications', [
                'type' => 'post',
                'article_id' => $article->id,
                'persona_id' => $personaId,
                'account_id' => $accountId,
                'assigned_user_id' => $admin->id,
                'platform' => 'zhihu',
                'content' => $longBody,
            ])->assertStatus(201);
        $id = (int) $created->json('data.id');
        $revision = $this->transition($tenant, $id, 'ready', 1);
        $revision = $this->transition($tenant, $id, 'in_progress', $revision);

        $draftUrl = 'https://zhuanlan.zhihu.com/p/123456789/edit';
        $saved = $this->forTenant($tenant)
            ->postJson('/api/v1/manual-publications/'.$id.'/zhihu-draft', [
                'draft_url' => $draftUrl,
                'revision' => $revision,
            ])->assertStatus(200);
        $this->assertSame('in_progress', $saved->json('data.status'));
        $this->assertSame($draftUrl, $saved->json('data.draft_url'));
        $this->assertNull($saved->json('data.completion_url'));
        $this->assertNotNull($saved->json('data.draft_synced_at'));
        $this->assertSame($saved->json('data.content_fingerprint'), $saved->json('data.draft_content_fingerprint'));

        $this->forTenant($tenant)
            ->postJson('/api/v1/manual-publications/'.$id.'/zhihu-draft', [
                'draft_url' => $draftUrl,
                'revision' => $revision,
            ])->assertStatus(409);
        $revision = (int) $saved->json('data.revision');
        $this->forTenant($tenant)
            ->postJson('/api/v1/manual-publications/'.$id.'/transition', [
                'target_status' => 'completed',
                'completion_url' => $draftUrl,
                'revision' => $revision,
            ])->assertStatus(422);
        $this->forTenant($tenant)
            ->postJson('/api/v1/manual-publications/'.$id.'/transition', [
                'target_status' => 'completed',
                'completion_url' => 'https://www.zhihu.com/question/123456/answer/789012',
                'revision' => $revision,
            ])->assertStatus(200);
    }

    public function test_zhihu_draft_sync_rejects_foreign_tenant_and_wrong_url(): void
    {
        [$tenantA, $adminA] = $this->createTenantUser('mp-draft-a', 'admin');
        [$tenantB, $adminB] = $this->createTenantUser('mp-draft-b', 'admin');
        $publicationB = $this->createPublicationFor($tenantB, $adminB);
        Sanctum::actingAs($adminA, ['*']);

        $this->forTenant($tenantA)
            ->postJson('/api/v1/manual-publications/'.$publicationB->id.'/zhihu-draft', [
                'draft_url' => 'https://zhuanlan.zhihu.com/p/123/edit',
                'revision' => 1,
            ])->assertStatus(404);
        Sanctum::actingAs($adminB, ['*']);
        $this->forTenant($tenantB)
            ->postJson('/api/v1/manual-publications/'.$publicationB->id.'/zhihu-draft', [
                'draft_url' => 'https://evil.example.test/p/123/edit',
                'revision' => 1,
            ])->assertStatus(422);
    }

    /* ─────────────── M4：completed 改不动 ─────────────── */

    public function test_completed_publication_cannot_be_updated(): void
    {
        [$tenant, $admin] = $this->createTenantUser('mp-lock', 'admin');
        $publication = $this->driveToCompleted($tenant, $admin);

        Sanctum::actingAs($admin, ['*']);
        // 评审项 11：completed 不可改是业务规则（上游 422 completed_immutable），非权限错误
        $this
            ->forTenant($tenant)
            ->putJson('/api/v1/manual-publications/'.$publication->id, [
                'content' => '改一下试试',
                'revision' => 5,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', '已完成（completed）的发布单不可修改。');
    }

    /* ─────────────── 评审阻断项 3：PUT 部分更新必须成功且不抹空 ─────────────── */

    public function test_partial_update_merges_with_existing_values(): void
    {
        [$tenant, $admin] = $this->createTenantUser('mp-put', 'admin');
        $publication = $this->createPublicationFor($tenant, $admin, [
            'target_context' => '原始上下文',
        ]);
        $revision = (int) $publication->revision;

        Sanctum::actingAs($admin, ['*']);
        $this
            ->forTenant($tenant)
            ->putJson('/api/v1/manual-publications/'.$publication->id, [
                'content' => '只改正文的部分更新。',
                'revision' => $revision,
            ])
            ->assertStatus(200);

        $updated = $publication->refresh();
        $this->assertSame('只改正文的部分更新。', (string) $updated->content);
        // 未传字段必须保留现值——旧实现会 500（缺 key）或抹空（assignee/schedule）
        $this->assertSame('comment', (string) $updated->type);
        $this->assertSame('zhihu', (string) $updated->platform);
        $this->assertSame((int) $publication->persona_id, (int) $updated->persona_id);
        $this->assertSame((int) $publication->assigned_user_id, (int) $updated->assigned_user_id);
        $this->assertSame((string) $publication->target_url, (string) $updated->target_url);
        $this->assertSame('原始上下文', (string) $updated->target_context);
        $this->assertSame($revision + 1, (int) $updated->revision);
    }

    /* ─────────────── M5：重开规则 ─────────────── */

    public function test_failed_skipped_cancelled_can_reopen_but_completed_cannot(): void
    {
        [$tenant, $admin] = $this->createTenantUser('mp-reopen', 'admin');

        foreach (['failed', 'skipped', 'cancelled'] as $terminalStatus) {
            $publication = $this->createPublicationFor($tenant, $admin, ['status' => 'draft']);

            // draft → ready → in_progress → {failed|skipped}
            $this->transitionModel($publication, 'ready', $admin);
            $this->transitionModel($publication, 'in_progress', $admin);
            $this->transitionModel($publication, $terminalStatus, $admin);

            // 重开 → ready 必须 OK（admin）
            $this->transitionModel($publication, 'ready', $admin);
            $this->assertSame('ready', $publication->refresh()->status, "[{$terminalStatus}] 必须能重开");
        }

        // cancelled 可从 draft 直达，也必须能重开
        $direct = $this->createPublicationFor($tenant, $admin);
        $this->transitionModel($direct, 'cancelled', $admin);
        $this->transitionModel($direct, 'ready', $admin);

        // completed 不能重开
        $completed = $this->driveToCompleted($tenant, $admin);
        $revision = (int) $completed->revision;
        Sanctum::actingAs($admin, ['*']);
        $this
            ->forTenant($tenant)
            ->postJson('/api/v1/manual-publications/'.$completed->id.'/transition', [
                'target_status' => 'ready',
                'revision' => $revision,
            ])
            ->assertStatus(422);
    }

    /* ─────────────── M11：开关门控 web 页面 + API（路由注册一次性，各用例 putenv+重启 app） ─────────────── */

    public function test_m11_switch_disabled_returns_404_for_web_and_api(): void
    {
        // phpunit.xml 已固定 GEO_MANUAL_PUBLISH_ENABLED=true（M1–M10 需要路由在），
        // 关态必须以 env 重启应用验证（测试内改 config 来不及——路由在 boot 时注册）
        putenv('GEO_MANUAL_PUBLISH_ENABLED=false');
        $_ENV['GEO_MANUAL_PUBLISH_ENABLED'] = 'false';

        try {
            $this->refreshApplication();

            // web：404 不进 blade 渲染，「渲染成本高」不是不写测试的理由（评审项 9）
            $this->get('/manual-publish')->assertStatus(404);

            // API 同闸（评审收尾）：路由未注册 → 404，而不是 401/403——
            // 403 等于告诉外面「这儿有个模块，只是没开」
            $this->getJson('/api/v1/manual-publications')->assertStatus(404);

            // settings 子组（personas/accounts 等 8 条）与列表同在一个 if 里，抽一条代表断言（评审项 6）
            $this->getJson('/api/v1/manual-publications/settings/personas')->assertStatus(404);
        } finally {
            // 恢复 phpunit.xml 的固定值（不要 unset——unset 会让后续用例 boot 时路由全没）
            putenv('GEO_MANUAL_PUBLISH_ENABLED=true');
            $_ENV['GEO_MANUAL_PUBLISH_ENABLED'] = 'true';
        }
    }

    public function test_m11_web_panel_renders_when_switch_enabled(): void
    {
        // phpunit.xml 固定开关为 true，路由在 boot 时已注册，直接断言渲染
        $this->get('/manual-publish')->assertStatus(200);
    }

    public function test_zhihu_draft_sync_is_hidden_until_extension_rollout_is_enabled(): void
    {
        putenv('GEO_ZHIHU_DRAFT_SYNC_ENABLED=false');
        $_ENV['GEO_ZHIHU_DRAFT_SYNC_ENABLED'] = 'false';

        try {
            $this->refreshApplication();
            $this->getJson('/api/v1/manual-publications/1/zhihu-draft')->assertStatus(404);
            $this->get('/manual-publish')->assertStatus(200)
                ->assertSee('"zhihuDraftSyncEnabled":false', false);
        } finally {
            putenv('GEO_ZHIHU_DRAFT_SYNC_ENABLED=true');
            $_ENV['GEO_ZHIHU_DRAFT_SYNC_ENABLED'] = 'true';
        }
    }

    /* ─────────────── M6：判重 ─────────────── */

    public function test_duplicate_submission_is_flagged(): void
    {
        [$tenant, $admin] = $this->createTenantUser('mp-dup', 'admin');

        $first = $this->createPublicationFor($tenant, $admin, [
            'content' => '完全相同的评测内容，用于判重验证。',
        ]);
        $this->assertSame(0, (int) $first->duplicate_warning_count);

        $second = $this->createPublicationFor($tenant, $admin, [
            'content' => '完全相同的评测内容，用于判重验证。',
        ]);
        $this->assertGreaterThanOrEqual(1, (int) $second->duplicate_warning_count, '同平台同内容二次提交必须记重复警告');
    }

    /* ─────────────── M8：editor 建单 OK、指派他人 403 ─────────────── */

    public function test_editor_can_create_but_cannot_assign_to_others(): void
    {
        [$tenant, $editor] = $this->createTenantUser('mp-editor', 'editor');
        $otherMember = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => '13800000025',
            'role' => 'member',
        ]);
        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => '13800000026',
            'role' => 'admin',
        ]);

        // 人设是全租户共用的主数据，写已收归 admin（manual_publish:settings）
        Sanctum::actingAs($admin, ['*']);
        $personaId = $this->createPersonaViaApi($tenant, '编辑人设');

        Sanctum::actingAs($editor, ['*']);

        // editor 能进面板、能建单
        $this->forTenant($tenant)->getJson('/api/v1/manual-publications')->assertStatus(200);
        $this
            ->forTenant($tenant)
            ->postJson('/api/v1/manual-publications', [
                'type' => 'comment',
                'persona_id' => $personaId,
                'platform' => 'weibo',
                'content' => 'editor 自己发的内容。',
                'target_url' => 'https://example.com/weibo/1',
                'target_context' => '某话题讨论',
            ])
            ->assertStatus(201);

        // 指派给别人 → 拒绝（manual_publish:assign 仅 admin）
        $this
            ->forTenant($tenant)
            ->postJson('/api/v1/manual-publications', [
                'type' => 'comment',
                'persona_id' => $personaId,
                'platform' => 'weibo',
                'content' => '试图指派给别人的内容。',
                'target_url' => 'https://example.com/weibo/2',
                'target_context' => '某话题讨论',
                'assigned_user_id' => $otherMember->id,
            ])
            ->assertStatus(422)
            // 评审阻断项 2 的另一半：断言必须是真译文而非裸 key 的同义反复。
            // 固定字面值，翻译文件缺失（回退裸 key）时本断言会红。
            ->assertJsonPath('message', '仅管理员可把发布单指派给他人。');
    }

    /* ─────────────── M9：member 进不来 ─────────────── */

    public function test_member_gets_403(): void
    {
        [$tenant, $member] = $this->createTenantUser('mp-member', 'member');
        Sanctum::actingAs($member, ['*']);

        $this
            ->forTenant($tenant)
            ->getJson('/api/v1/manual-publications')
            ->assertStatus(403);
    }

    /* ─────────────── M10（2026-08-28 改判）：editor 不看归属，租户内通管 ───────────────
       原判据「非指派人不可流转 / 不可查看」照抄自上游 GEOFlow —— 那是作者自用的
       「超管 + 发帖执行工」模型。本仓是多租户 SaaS，editor 是内容团队成员：他能改本租户
       任何一篇文章（article:edit），就没有理由碰不了本租户的发布单。
       现判据：角色定能力、租户定边界、不看记录归属；留在 admin 手里的只有指派他人与重开。 */

    public function test_editor_can_manage_any_publication_in_the_same_tenant(): void
    {
        [$tenant, $admin] = $this->createTenantUser('mp-m10', 'admin');
        $editorA = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => '13800000016',
            'role' => 'editor',
        ]);
        $editorB = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => '13800000017',
            'role' => 'editor',
        ]);

        $publication = $this->createPublicationFor($tenant, $admin, [
            'assigned_user_id' => $editorA->id,
        ]);

        // editorB 不是被指派人，但同租户同角色 → 看得到
        Sanctum::actingAs($editorB, ['*']);
        $this
            ->forTenant($tenant)
            ->getJson('/api/v1/manual-publications/'.$publication->id)
            ->assertStatus(200);

        // 改单：本条就是「editor 建得了单却改不了单」的正题。
        // 未传 assigned_user_id 会被 mergeWithExisting 回填成 editorA——不得因此被判成「指派给别人」。
        $this
            ->forTenant($tenant)
            ->putJson('/api/v1/manual-publications/'.$publication->id, [
                'content' => 'editorB 改了同事的单。',
                'revision' => (int) $publication->revision,
            ])
            ->assertStatus(200);

        $afterUpdate = $publication->refresh();
        $this->assertSame('editorB 改了同事的单。', (string) $afterUpdate->content);
        $this->assertSame((int) $editorA->id, (int) $afterUpdate->assigned_user_id);

        // 流转：同样不看归属
        $this
            ->forTenant($tenant)
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/transition', [
                'target_status' => 'ready',
                'revision' => (int) $afterUpdate->revision,
            ])
            ->assertStatus(200);

        // 被指派人 editorA 当然也可以
        Sanctum::actingAs($editorA, ['*']);
        $this
            ->forTenant($tenant)
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/transition', [
                'target_status' => 'in_progress',
                'revision' => (int) $publication->refresh()->revision,
            ])
            ->assertStatus(200);
    }

    /* ─────────────── M10b：editor 通管，但重开仍是 admin 专属 ─────────────── */

    public function test_editor_cannot_reopen_terminal_publication(): void
    {
        [$tenant, $admin] = $this->createTenantUser('mp-reopen-editor', 'admin');
        $editor = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => '13800000018',
            'role' => 'editor',
        ]);

        $publication = $this->createPublicationFor($tenant, $admin);
        $this->transitionModel($publication, 'cancelled', $admin);
        $cancelled = $publication->refresh();

        // editor 把单取消得了（普通流转），但重开（cancelled → ready）只有 admin 能做
        Sanctum::actingAs($editor, ['*']);
        $this
            ->forTenant($tenant)
            ->postJson('/api/v1/manual-publications/'.$cancelled->id.'/transition', [
                'target_status' => 'ready',
                'revision' => (int) $cancelled->revision,
            ])
            ->assertStatus(403);

        // admin 重开 → 200
        Sanctum::actingAs($admin, ['*']);
        $this
            ->forTenant($tenant)
            ->postJson('/api/v1/manual-publications/'.$cancelled->id.'/transition', [
                'target_status' => 'ready',
                'revision' => (int) $cancelled->revision,
            ])
            ->assertStatus(200);
    }

    /* ─────────────── M10c：人设 / 账号「读」放给 editor、「写」收归 admin ─────────────── */

    public function test_settings_are_readable_by_editor_but_writable_only_by_admin(): void
    {
        [$tenant, $admin] = $this->createTenantUser('mp-settings', 'admin');
        $editor = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => '13800000019',
            'role' => 'editor',
        ]);

        Sanctum::actingAs($admin, ['*']);
        $personaId = $this->createPersonaViaApi($tenant, '设置权限人设');

        Sanctum::actingAs($editor, ['*']);

        // 读：必须放行——新建发布单的人设 / 账号下拉框就是拉这两个列表，收窄了 editor 就建不了单
        $this->forTenant($tenant)->getJson('/api/v1/manual-publications/settings/personas')->assertStatus(200);
        $this->forTenant($tenant)->getJson('/api/v1/manual-publications/settings/accounts')->assertStatus(200);

        // 写：全租户共用的主数据，仅 admin
        $this
            ->forTenant($tenant)
            ->postJson('/api/v1/manual-publications/settings/personas', ['name' => 'editor 想建的人设'])
            ->assertStatus(403);

        $this
            ->forTenant($tenant)
            ->putJson('/api/v1/manual-publications/settings/personas/'.$personaId, ['is_active' => false])
            ->assertStatus(403);

        $this
            ->forTenant($tenant)
            ->postJson('/api/v1/manual-publications/settings/accounts', [
                'persona_id' => $personaId,
                'platform' => 'weibo',
                'account_name' => 'editor 想建的账号',
            ])
            ->assertStatus(403);

        // admin 写 → 通
        Sanctum::actingAs($admin, ['*']);
        $this
            ->forTenant($tenant)
            ->putJson('/api/v1/manual-publications/settings/personas/'.$personaId, ['is_active' => false])
            ->assertStatus(200);
    }

    /* ─────────────── 附：乐观锁冲突 ─────────────── */

    public function test_stale_revision_returns_409(): void
    {
        [$tenant, $admin] = $this->createTenantUser('mp-conflict', 'admin');
        $publication = $this->createPublicationFor($tenant, $admin);

        Sanctum::actingAs($admin, ['*']);
        $this
            ->forTenant($tenant)
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/transition', [
                'target_status' => 'ready',
                'revision' => 999, // 过期版本号
            ])
            ->assertStatus(409);
    }

    /* ─────────────── B-1：type=post 后端防御（执行书 §四 B-1，硬要求） ─────────────── */

    /**
     * 执行书 §五 5-1 验收第 1 条：type=post 不带 article_id，必须 422，绝不能 500。
     * 复现路径：前端 submitNew() 的原样 payload 不含 article_id 键（public/geo-manual-publish.js:433-444）。
     */
    public function test_post_without_article_id_returns_422_not_500(): void
    {
        [$tenant, $admin] = $this->createTenantUser('mp-b1-missing', 'admin');
        Sanctum::actingAs($admin, ['*']);
        $personaId = $this->createPersonaViaApi($tenant, 'B1 人设');

        $this
            ->forTenant($tenant)
            ->postJson('/api/v1/manual-publications', [
                'type' => 'post',
                'persona_id' => $personaId,
                'platform' => 'zhihu',
                'content' => '选了文章类型却没传 article_id。',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', '请先选择文章。');
    }

    /**
     * 执行书 §五 5-1 验收第 2 条：type=post 带一篇 approved 文章 → 201，且 article_id 正确落库。
     * approved 与 published 都算审核通过（prepare 的白名单），两个状态都验。
     */
    public function test_post_with_approved_article_succeeds_and_persists_article_id(): void
    {
        [$tenant, $admin] = $this->createTenantUser('mp-b1-approved', 'admin');
        $approved = $this->createArticleFor($tenant, $admin, 'approved');
        Sanctum::actingAs($admin, ['*']);
        $personaId = $this->createPersonaViaApi($tenant, 'B1 人设2');

        $response = $this
            ->forTenant($tenant)
            ->postJson('/api/v1/manual-publications', [
                'type' => 'post',
                'persona_id' => $personaId,
                'platform' => 'zhihu',
                'content' => '带一篇已审核文章的发布单。',
                'article_id' => $approved->id,
            ])
            ->assertStatus(201);
        $this->assertSame((int) $approved->id, (int) $response->json('data.article_id'));

        // published 同样算审核通过
        Article::$autoDistributionEnabled = false;
        try {
            $published = $this->createArticleFor($tenant, $admin, 'published');
        } finally {
            Article::$autoDistributionEnabled = true;
        }
        $response2 = $this
            ->forTenant($tenant)
            ->postJson('/api/v1/manual-publications', [
                'type' => 'post',
                'persona_id' => $personaId,
                'platform' => 'zhihu',
                'content' => '带一篇已发布文章的发布单。',
                'article_id' => $published->id,
            ])
            ->assertStatus(201);
        $this->assertSame((int) $published->id, (int) $response2->json('data.article_id'));
    }

    /**
     * 执行书 §五 5-1 验收第 3 条：type=post 带一篇 draft 文章 → 422（未审核通过）。
     */
    public function test_post_with_draft_article_returns_422(): void
    {
        [$tenant, $admin] = $this->createTenantUser('mp-b1-draft', 'admin');
        $article = $this->createArticleFor($tenant, $admin, 'draft');
        Sanctum::actingAs($admin, ['*']);
        $personaId = $this->createPersonaViaApi($tenant, 'B1 人设3');

        $this
            ->forTenant($tenant)
            ->postJson('/api/v1/manual-publications', [
                'type' => 'post',
                'persona_id' => $personaId,
                'platform' => 'zhihu',
                'content' => '带一篇草稿文章。',
                'article_id' => $article->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', '关联文章未通过审核，无法发布。');
    }

    /* ═══════════════ helpers ═══════════════ */

    private function createTenantUser(string $subdomain, string $role): array
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
            'role' => $role,
        ]);

        return [$tenant, $user];
    }

    private function forTenant(Tenant $tenant): self
    {
        return $this
            ->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }

    /** 走 API 建人设（同时验 settings 端点），返回 persona id。 */
    private function createPersonaViaApi(Tenant $tenant, string $name): int
    {
        $response = $this
            ->forTenant($tenant)
            ->postJson('/api/v1/manual-publications/settings/personas', [
                'name' => $name,
                'tone' => '专业克制',
                'domain' => '消费测评',
                'disclosure_text' => '本文含商业合作。',
            ]);

        $response->assertStatus(201);

        return (int) $response->json('data.id');
    }

    /** 直连服务层建单（不依赖 HTTP），可覆盖字段。 */
    private function createPublicationFor(Tenant $tenant, User $creator, array $overrides = []): ManualPublication
    {
        TenantContext::set($tenant);

        try {
            $persona = ManualPublicationPersona::query()->create([
                'name' => '服务层人设 '.uniqid(),
                'created_by_user_id' => $creator->id,
            ]);

            $service = app(ManualPublicationService::class);

            return $service->create(array_merge([
                'type' => 'comment',
                'persona_id' => $persona->id,
                'platform' => 'zhihu',
                'content' => '服务层建的发布单内容：'.uniqid(),
                'target_url' => 'https://example.com/q/'.uniqid(),
                'target_context' => '上下文',
                'assigned_user_id' => $creator->id,
            ], $overrides), $creator);
        } finally {
            TenantContext::forget();
        }
    }

    /** 在指定租户下造一篇指定 status 的文章（含其所属 project），返回 Article。 */
    private function createArticleFor(Tenant $tenant, User $user, string $status): Article
    {
        TenantContext::set($tenant);
        try {
            $project = Project::query()->create([
                'owner_id' => $user->id,
                'name' => 'B1 Proj',
                'brand_name' => 'B1',
                'brand_url' => 'https://b1.example.test',
                'settings' => [],
            ]);

            return Article::query()->create([
                'project_id' => $project->id,
                'author_id' => $user->id,
                'title' => 'B1 文章',
                'body_markdown' => '# 正文',
                'content_type' => 'general',
                'status' => $status,
                'source_type' => 'manual',
                'metadata' => [],
            ]);
        } finally {
            TenantContext::forget();
        }
    }

    /** 服务层流转（服务层直调，跟 HTTP 无关）。 */
    private function transitionModel(ManualPublication $publication, string $target, User $actor): void
    {
        // T1 之后 rules() 在租户上下文为 null 时抛异常（fail-close）。
        // 这两个服务层直调绕过 HTTP 租户中间件，必须显式 set 上下文。
        $tenant = Tenant::findOrFail($publication->tenant_id);
        $service = app(ManualPublicationService::class);
        try {
            TenantContext::set($tenant);
            $service->transition($publication->refresh(), $target, (int) $publication->refresh()->revision, $actor);
        } finally {
            TenantContext::forget();
        }
    }

    /** HTTP 流转，返回流转后的 revision。 */
    private function transition(Tenant $tenant, int $id, string $target, int $revision, array $extra = []): int
    {
        $response = $this
            ->forTenant($tenant)
            ->postJson('/api/v1/manual-publications/'.$id.'/transition', array_merge([
                'target_status' => $target,
                'revision' => $revision,
            ], $extra));

        $response->assertStatus(200);

        return (int) $response->json('data.revision');
    }

    /** 一路推到 completed，返回终态模型。 */
    private function driveToCompleted(Tenant $tenant, User $admin): ManualPublication
    {
        $publication = $this->createPublicationFor($tenant, $admin);
        $this->transitionModel($publication, 'ready', $admin);
        $this->transitionModel($publication, 'in_progress', $admin);

        $service = app(ManualPublicationService::class);
        try {
            TenantContext::set($tenant);
            $service->transition(
                $publication->refresh(),
                'completed',
                (int) $publication->refresh()->revision,
                $admin,
                'https://example.com/done/'.uniqid(),
            );
        } finally {
            TenantContext::forget();
        }

        return $publication->refresh();
    }
}
