<?php

namespace Tests\Feature;

use App\Models\ManualPublication;
use App\Models\ManualPublicationPersona;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Publishing\ManualPublicationService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 单 7 · 手动发布「指派给」的后端契约回归。
 *
 * 为什么单独有这个文件
 * ────────────────────
 * 2026-08-31 派单二把「指派给」从手填数字 ID 的输入框换成下拉。第一版的下拉里，
 * 「我自己」这一项写的是 <option value="">，看着无害，实际是一条完整的杀伤链：
 *
 *   value="" → 提交送 assigned_user_id = null
 *            → mergeWithExisting() 用 array_key_exists 判断，**显式的 null 不会被回填**成现值
 *            → prepare() 的指派权限校验写的是 `$assignedUserId !== null && ...`，null 根本不进校验
 *            → null 直接落库
 *            → scopeVisibleTo() 对非 admin 是 where('assigned_user_id', 自己)
 *            → editor 保存一次改单，这张单就从他的列表里消失，而他无权再指派回来。
 *
 * 旧的数字输入框预填了现有 id、保存时原样送回，反而不会踩 —— 这是换下拉时**新引入的回归**。
 *
 * 🔴 这条链子横跨前端取值与后端三处语义，**前端静态测试够不到**
 * （tests/js/panel-session-reverify.test.mjs 只能守住「value 不是空串」这一头）。
 * 所以另一头必须在这里守：把前端会送出的载荷原样打进 API，断言库里和可见性的实际结果。
 *
 * 修法：「我自己」带当前用户的真实 id。下面四条就是修完之后必须成立的事实。
 */
class ManualPublicationAssigneeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.tenant_domain' => 'geo.example.test']);
    }

    /** @return array{0: Tenant, 1: User, 2: int, 3: ManualPublication} */
    private function scaffold(string $subdomain): array
    {
        $tenant = Tenant::query()->create([
            'subdomain' => $subdomain,
            'name' => strtoupper($subdomain),
            'plan' => 'basic',
            'status' => 'active',
        ]);

        $editor = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain.'e'),
            'role' => 'editor',
        ]);

        TenantContext::set($tenant);

        try {
            $persona = ManualPublicationPersona::query()->create([
                'name' => '人设'.uniqid(),
                'created_by_user_id' => $editor->id,
            ]);

            $publication = app(ManualPublicationService::class)->create([
                'type' => 'comment',
                'persona_id' => $persona->id,
                'platform' => 'zhihu',
                'content' => 'editor 自己的单子内容 '.uniqid(),
                'target_url' => 'https://example.com/q/'.uniqid(),
                'target_context' => '上下文',
                'assigned_user_id' => $editor->id,
            ], $editor);
        } finally {
            TenantContext::forget();
        }

        return [$tenant, $editor, (int) $persona->id, $publication];
    }

    private function forTenant(Tenant $tenant): self
    {
        return $this
            ->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }

    private function visibleTo(Tenant $tenant, User $user, int $id): bool
    {
        TenantContext::set($tenant);

        try {
            return ManualPublication::query()->visibleTo($user)->whereKey($id)->exists();
        } finally {
            TenantContext::forget();
        }
    }

    /**
     * 🔴 本文件的主角：editor 保存改单不能把自己从单上摘掉。
     *
     * 下拉送出的是自己的真实 id，prepare() 里 previous === 自己 → 不算「换人」→ 不校验 →
     * 指派原样保留，单子还在他的列表里。
     */
    public function test_editor_editing_own_publication_keeps_the_assignment(): void
    {
        [$tenant, $editor, , $publication] = $this->scaffold('mp-assign-edit');

        Sanctum::actingAs($editor, ['*']);

        $this
            ->forTenant($tenant)
            ->putJson('/api/v1/manual-publications/'.$publication->id, [
                'content' => '只改了正文，没碰指派',
                'assigned_user_id' => $editor->id,
                'revision' => (int) $publication->revision,
            ])
            ->assertStatus(200);

        $after = ManualPublication::query()->whereKey($publication->id)->firstOrFail();

        $this->assertSame($editor->id, (int) $after->assigned_user_id, '指派被抹掉了');
        $this->assertTrue(
            $this->visibleTo($tenant, $editor, (int) $publication->id),
            'editor 改完自己的单之后就看不见它了',
        );
    }

    /**
     * 反面：显式传 null 会真的清空指派。
     *
     * 这条不是「期望的用法」，是把杀伤链的后半段钉死：只要前端不小心送出 null，
     * 后端就会照单全收（mergeWithExisting 不回填、prepare 不校验）。
     * 所以前端那一头「我自己」必须带真实 id —— 责任在前端，这里只记录后端的事实。
     */
    public function test_explicit_null_really_clears_the_assignment(): void
    {
        [$tenant, $editor, , $publication] = $this->scaffold('mp-assign-null');

        Sanctum::actingAs($editor, ['*']);

        $this
            ->forTenant($tenant)
            ->putJson('/api/v1/manual-publications/'.$publication->id, [
                'content' => '正文照改，但显式送了 null',
                'assigned_user_id' => null,
                'revision' => (int) $publication->revision,
            ])
            ->assertStatus(200);

        $after = ManualPublication::query()->whereKey($publication->id)->firstOrFail();

        $this->assertNull($after->assigned_user_id);
        $this->assertFalse(
            $this->visibleTo($tenant, $editor, (int) $publication->id),
            '指派被清空之后，非 admin 就该看不见了（这正是不能让前端误送 null 的原因）',
        );
    }

    /** 字段整个不带（下拉禁用时的行为）→ mergeWithExisting 回填现值，指派不动。 */
    public function test_omitting_the_field_preserves_the_assignment(): void
    {
        [$tenant, $editor, , $publication] = $this->scaffold('mp-assign-omit');

        Sanctum::actingAs($editor, ['*']);

        $this
            ->forTenant($tenant)
            ->putJson('/api/v1/manual-publications/'.$publication->id, [
                'content' => '正文照改，字段整个不带',
                'revision' => (int) $publication->revision,
            ])
            ->assertStatus(200);

        $after = ManualPublication::query()->whereKey($publication->id)->firstOrFail();

        $this->assertSame($editor->id, (int) $after->assigned_user_id);
    }

    /**
     * 建单默认「我自己」：editor 指派给自己是放行的，不会被 manual_publish:assign 顶成 422，
     * 建完自己看得见，而且能直接推到 ready（ensureReadyRequirements 要求有指派人）。
     */
    public function test_editor_can_create_assigned_to_self_and_push_to_ready(): void
    {
        [$tenant, $editor, $personaId] = $this->scaffold('mp-assign-create');

        Sanctum::actingAs($editor, ['*']);

        $created = $this
            ->forTenant($tenant)
            ->postJson('/api/v1/manual-publications', [
                'type' => 'comment',
                'persona_id' => $personaId,
                'platform' => 'weibo',
                'content' => 'editor 新建的单子内容。',
                'target_url' => 'https://example.com/weibo/1',
                'target_context' => '某话题讨论',
                'assigned_user_id' => $editor->id,
            ])
            ->assertStatus(201);

        $id = (int) $created->json('data.id');

        $this->assertSame($editor->id, (int) $created->json('data.assigned_user_id'));
        $this->assertTrue($this->visibleTo($tenant, $editor, $id), 'editor 建完单自己就找不到它了');

        $this
            ->forTenant($tenant)
            ->postJson('/api/v1/manual-publications/'.$id.'/transition', [
                'target_status' => 'ready',
                'revision' => (int) $created->json('data.revision'),
            ])
            ->assertStatus(200);
    }

    /** admin 的下拉里那一项「未指派」仍然要能真的把指派清掉。 */
    public function test_admin_can_still_unassign(): void
    {
        [$tenant, , , $publication] = $this->scaffold('mp-assign-admin');

        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => '13800000022',
            'role' => 'admin',
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this
            ->forTenant($tenant)
            ->putJson('/api/v1/manual-publications/'.$publication->id, [
                'content' => 'admin 把指派清掉',
                'assigned_user_id' => null,
                'revision' => (int) $publication->revision,
            ])
            ->assertStatus(200);

        $after = ManualPublication::query()->whereKey($publication->id)->firstOrFail();

        $this->assertNull($after->assigned_user_id);
    }
}
