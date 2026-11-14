<?php

namespace Tests\Feature;

use App\Models\ManualPublication;
use App\Models\ManualPublicationPersona;
use App\Models\SensitiveWord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Publishing\ManualPublicationService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 拦截级敏感词闸门（2026-08-30 拍板：风险扫描要真的拦得住）。
 *
 * 改动之前：risk_status 只在建单时写进库，全仓没有任何地方读它——扫出 blocked
 * 的单子照样能一路推到已完成。本用例锁的就是「读它、并且据此拒绝」。
 *
 * 闸门装在 ready 这一道。状态机里 draft 出去只有 ready 一条路，失败/跳过/取消
 * 重开也只能回到 ready，所以卡住 ready 就卡住了全部对外发布路径。
 */
class ManualPublicationRiskGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.tenant_domain' => 'geo.example.test']);
    }

    /* ─────────────── 拦截级：进不了待发 ─────────────── */

    public function test_blocked_word_blocks_ready_transition(): void
    {
        [$tenant, $admin] = $this->tenantAdmin('risk-block');
        $this->seedWord($tenant, '禁用词甲', 'blocked');

        $publication = $this->draftWith($tenant, $admin, '这条内容里带了禁用词甲，不该放出去。');
        $this->assertSame('blocked', $publication->risk_status);

        $response = $this
            ->forTenant($tenant)
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/transition', [
                'target_status' => 'ready',
                'revision' => $publication->revision,
            ]);

        $response->assertStatus(422);
        // 报错必须点名是哪个词，否则作者只知道被拦了、不知道改什么。
        $this->assertStringContainsString('禁用词甲', (string) $response->json('message'));

        $this->assertSame('draft', $publication->refresh()->status);
    }

    /* ─────────────── 警告级：照常放行 ─────────────── */

    public function test_warning_word_does_not_block_ready_transition(): void
    {
        [$tenant, $admin] = $this->tenantAdmin('risk-warn');
        $this->seedWord($tenant, '最佳', 'warning');

        $publication = $this->draftWith($tenant, $admin, '这是全网最佳的一条内容。');
        $this->assertSame('warning', $publication->risk_status);

        $this
            ->forTenant($tenant)
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/transition', [
                'target_status' => 'ready',
                'revision' => $publication->revision,
            ])
            ->assertStatus(200);

        $this->assertSame('ready', $publication->refresh()->status);
    }

    /* ─────────────── 建单即待发也要过闸 ─────────────── */

    public function test_creating_directly_as_ready_is_blocked(): void
    {
        [$tenant, $admin] = $this->tenantAdmin('risk-create');
        $this->seedWord($tenant, '禁用词乙', 'blocked');
        Sanctum::actingAs($admin, ['*']);

        $personaId = $this->personaFor($tenant, $admin);

        $response = $this
            ->forTenant($tenant)
            ->postJson('/api/v1/manual-publications', [
                'type' => 'comment',
                'persona_id' => $personaId,
                'platform' => 'zhihu',
                'content' => '开局就想直接待发，但内容里有禁用词乙。',
                'target_url' => 'https://example.com/q/1',
                'target_context' => '上下文',
                'assigned_user_id' => $admin->id,
                'status' => 'ready',
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('禁用词乙', (string) $response->json('message'));
        $this->assertSame(0, ManualPublication::query()->count());
    }

    /* ─────────────── 词库是活的：建单后新增的拦截词也要拦住 ─────────────── */

    public function test_word_added_after_creation_is_caught_at_ready(): void
    {
        [$tenant, $admin] = $this->tenantAdmin('risk-late');

        // 建单时词库还是空的 → 存进去是 clean
        $publication = $this->draftWith($tenant, $admin, '事后才被列为敏感的词：禁用词丙。');
        $this->assertSame('clean', $publication->risk_status);

        // 事后加词
        $this->seedWord($tenant, '禁用词丙', 'blocked');

        $this
            ->forTenant($tenant)
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/transition', [
                'target_status' => 'ready',
                'revision' => $publication->revision,
            ])
            ->assertStatus(422);

        // 重扫结果必须落库：否则界面刷新后仍显示「干净」，与被拒绝的事实自相矛盾。
        // 这条断言同时锁住「重扫写在事务外」这个实现选择——写在事务内会被回滚。
        $this->assertSame('blocked', $publication->refresh()->risk_status);
        $this->assertSame('draft', $publication->refresh()->status);
    }

    /* ─────────────── 已进待发的单子不能被「改」成违禁内容 ─────────────── */

    public function test_editing_a_ready_publication_into_blocked_content_is_refused(): void
    {
        [$tenant, $admin] = $this->tenantAdmin('risk-edit');
        $this->seedWord($tenant, '禁用词戊', 'blocked');

        // 先用干净内容进待发
        $publication = $this->draftWith($tenant, $admin, '一条干净的内容。');
        $this->assertSame('clean', $publication->risk_status);
        $this
            ->forTenant($tenant)
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/transition', [
                'target_status' => 'ready',
                'revision' => $publication->revision,
            ])
            ->assertStatus(200);

        // 再回头把违禁内容编辑进去——只在 ready 那一道设卡的话，这里就是绕过口
        $publication->refresh();
        $response = $this
            ->forTenant($tenant)
            ->putJson('/api/v1/manual-publications/'.$publication->id, [
                'content' => '改成带禁用词戊的内容。',
                'revision' => $publication->revision,
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('禁用词戊', (string) $response->json('message'));
        $this->assertSame('一条干净的内容。', $publication->refresh()->content);
    }

    public function test_editing_a_draft_into_blocked_content_is_allowed(): void
    {
        [$tenant, $admin] = $this->tenantAdmin('risk-draft-edit');
        $this->seedWord($tenant, '禁用词己', 'blocked');

        // 草稿故意不拦：作者得先把内容存下来、看见命中了哪个词，才改得动
        $publication = $this->draftWith($tenant, $admin, '一条干净的草稿。');
        $this
            ->forTenant($tenant)
            ->putJson('/api/v1/manual-publications/'.$publication->id, [
                'content' => '草稿里先写上禁用词己。',
                'revision' => $publication->revision,
            ])
            ->assertStatus(200);

        $this->assertSame('blocked', $publication->refresh()->risk_status);
        $this->assertSame('draft', $publication->refresh()->status);
    }

    /* ─────────────── 重开（failed → ready）走同一道闸 ─────────────── */

    public function test_reopen_to_ready_is_also_gated(): void
    {
        [$tenant, $admin] = $this->tenantAdmin('risk-reopen');

        $publication = $this->draftWith($tenant, $admin, '先正常发出去：禁用词丁。');
        $service = app(ManualPublicationService::class);

        TenantContext::set($tenant);
        try {
            $service->transition($publication->refresh(), 'ready', (int) $publication->refresh()->revision, $admin);
            $service->transition($publication->refresh(), 'in_progress', (int) $publication->refresh()->revision, $admin);
            $service->transition($publication->refresh(), 'failed', (int) $publication->refresh()->revision, $admin);
        } finally {
            TenantContext::forget();
        }
        $this->assertSame('failed', $publication->refresh()->status);

        $this->seedWord($tenant, '禁用词丁', 'blocked');

        $this
            ->forTenant($tenant)
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/transition', [
                'target_status' => 'ready',
                'revision' => $publication->refresh()->revision,
            ])
            ->assertStatus(422);

        $this->assertSame('failed', $publication->refresh()->status);
    }

    /* ─────────────── helpers ─────────────── */

    /** @return array{0: Tenant, 1: User} */
    private function tenantAdmin(string $subdomain): array
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

        Sanctum::actingAs($user, ['*']);

        return [$tenant, $user];
    }

    private function forTenant(Tenant $tenant): self
    {
        return $this
            ->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }

    private function seedWord(Tenant $tenant, string $word, string $severity): void
    {
        TenantContext::set($tenant);
        try {
            SensitiveWord::query()->create([
                'tenant_id' => $tenant->id,
                'word' => $word,
                'severity' => $severity,
            ]);
        } finally {
            TenantContext::forget();
        }

        // 词表缓存的失效挂在 DB::afterCommit 上；RefreshDatabase 把整条用例包在
        // 一个不提交的事务里，回调不会触发。生产没有这层包裹，正常失效。
        // 这里显式 bump，让用例只验闸门本身，不受测试事务语义干扰。
        SensitiveWord::bumpRuleCacheVersion();
    }

    private function personaFor(Tenant $tenant, User $creator): int
    {
        TenantContext::set($tenant);
        try {
            return (int) ManualPublicationPersona::query()->create([
                'name' => '闸门用例人设 '.uniqid(),
                'created_by_user_id' => $creator->id,
            ])->getKey();
        } finally {
            TenantContext::forget();
        }
    }

    private function draftWith(Tenant $tenant, User $admin, string $content): ManualPublication
    {
        TenantContext::set($tenant);
        try {
            // 人设在同一个 TenantContext 里就地建：调 personaFor() 会在它自己的
            // finally 里 forget()，把这里刚设好的租户上下文一并清掉（嵌套 set/forget
            // 不是栈），建单就会撞 tenant_id NOT NULL。
            $persona = ManualPublicationPersona::query()->create([
                'name' => '闸门用例人设 '.uniqid(),
                'created_by_user_id' => $admin->id,
            ]);

            return app(ManualPublicationService::class)->create([
                'type' => 'comment',
                'persona_id' => (int) $persona->getKey(),
                'platform' => 'zhihu',
                'content' => $content,
                'target_url' => 'https://example.com/q/'.uniqid(),
                'target_context' => '上下文',
                'assigned_user_id' => $admin->id,
            ], $admin);
        } finally {
            TenantContext::forget();
        }
    }
}
