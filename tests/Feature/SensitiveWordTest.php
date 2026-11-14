<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Project;
use App\Models\SensitiveWord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Content\SensitiveWordChecker;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SensitiveWordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.tenant_domain' => 'geo.example.test']);
        // 清空静态请求级缓存（避免不同用例复用同一租户 ID 时缓存泄漏）
        SensitiveWordChecker::clearCache();
    }

    public function test_admin_can_manage_sensitive_words(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');
        TenantContext::set($tenant);

        SensitiveWord::query()->create(['tenant_id' => $tenant->id, 'word' => '已有词']);
        TenantContext::forget();

        Sanctum::actingAs($admin, ['*']);

        // 批量添加（换行/逗号混合），已有词应被跳过
        $this->forTenant($tenant)
            ->postJson('/api/v1/sensitive-words', ['words' => "违禁A\n违禁B, 违禁C\n已有词"])
            ->assertStatus(201)
            ->assertJsonPath('inserted', 3)
            ->assertJsonPath('skipped_duplicates', 1);

        // 列表
        $this->forTenant($tenant)
            ->getJson('/api/v1/sensitive-words')
            ->assertStatus(200)
            ->assertJsonCount(4, 'data');

        // 删除
        $id = SensitiveWord::query()->where('tenant_id', $tenant->id)->where('word', '违禁A')->first()->id;
        $this->forTenant($tenant)
            ->deleteJson('/api/v1/sensitive-words/' . $id)
            ->assertStatus(200);

        TenantContext::set($tenant);
        $this->assertSame(3, SensitiveWord::query()->where('tenant_id', $tenant->id)->count());
        TenantContext::forget();
    }

    public function test_sensitive_words_are_tenant_isolated(): void
    {
        [$tenantA, $adminA] = $this->makeTenantUser('tenant-a', 'admin');
        [$tenantB, $adminB] = $this->makeTenantUser('tenant-b', 'admin');

        TenantContext::set($tenantA);
        SensitiveWord::query()->create(['tenant_id' => $tenantA->id, 'word' => '仅A']);
        TenantContext::forget();

        Sanctum::actingAs($adminB, ['*']);
        $this->forTenant($tenantB)
            ->getJson('/api/v1/sensitive-words')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_article_with_sensitive_word_is_flagged(): void
    {
        [$tenant, $editor] = $this->makeTenantUser('tenant-a', 'editor');

        TenantContext::set($tenant);
        SensitiveWord::query()->create(['tenant_id' => $tenant->id, 'word' => '违禁']);
        $project = Project::query()->create([
            'tenant_id' => $tenant->id,
            'owner_id' => $editor->id,
            'name' => 'P',
            'brand_name' => 'P',
            'brand_url' => 'https://p.example.test',
            'settings' => [],
        ]);
        TenantContext::forget();

        Sanctum::actingAs($editor, ['*']);

        // 标题含敏感词 → flagged_sensitive = true
        $this->forTenant($tenant)
            ->postJson('/api/v1/articles', [
                'project_id' => $project->id,
                'title' => '这是一篇含违禁词的标题',
                'body_markdown' => '正文正常',
            ])
            ->assertStatus(201)
            ->assertJsonPath('flagged_sensitive', true);

        // 不含敏感词 → false
        $this->forTenant($tenant)
            ->postJson('/api/v1/articles', [
                'project_id' => $project->id,
                'title' => '合规标题',
                'body_markdown' => '正文正常',
            ])
            ->assertStatus(201)
            ->assertJsonPath('flagged_sensitive', false);

        TenantContext::set($tenant);
        $this->assertSame(1, Article::query()->where('tenant_id', $tenant->id)->where('flagged_sensitive', true)->count());
        TenantContext::forget();
    }

    public function test_member_cannot_manage_sensitive_words(): void
    {
        [$tenant, $member] = $this->makeTenantUser('tenant-a', 'member');

        Sanctum::actingAs($member, ['*']);
        $this->forTenant($tenant)
            ->postJson('/api/v1/sensitive-words', ['word' => 'x'])
            ->assertStatus(403);
    }

    /* ─────────── 等级（2026-08-30：此前 store 不收 severity，拦截级加不出来） ─────────── */

    public function test_store_accepts_blocked_severity_and_returns_it_in_index(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('sw-sev', 'admin');
        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->postJson('/api/v1/sensitive-words', [
                'words' => '禁词一、禁词二',
                'severity' => 'blocked',
                'suggestion' => '换成中性表述',
            ])
            ->assertStatus(201)
            ->assertJsonPath('inserted', 2);

        TenantContext::set($tenant);
        try {
            $words = SensitiveWord::query()->orderBy('word')->get();
            $this->assertSame(['blocked', 'blocked'], $words->pluck('severity')->all());
            $this->assertSame(['换成中性表述', '换成中性表述'], $words->pluck('suggestion')->all());
        } finally {
            TenantContext::forget();
        }

        // 列表必须把等级带回来，否则界面上分不清哪个词拦得住
        $this->forTenant($tenant)
            ->getJson('/api/v1/sensitive-words')
            ->assertStatus(200)
            ->assertJsonPath('data.0.severity', 'blocked');
    }

    public function test_resubmitting_an_existing_word_upgrades_its_severity(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('sw-upgrade', 'admin');
        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->postJson('/api/v1/sensitive-words', ['word' => '待提级词'])
            ->assertStatus(201)
            ->assertJsonPath('inserted', 1);

        // 重复提交同一个词是唯一的提级入口；静默跳过等于这个操作永远无效
        $this->forTenant($tenant)
            ->postJson('/api/v1/sensitive-words', ['word' => '待提级词', 'severity' => 'blocked'])
            ->assertStatus(201)
            ->assertJsonPath('inserted', 0)
            ->assertJsonPath('updated', 1)
            ->assertJsonPath('skipped_duplicates', 0);

        TenantContext::set($tenant);
        try {
            $this->assertSame('blocked', (string) SensitiveWord::query()->where('word', '待提级词')->firstOrFail()->severity);
            $this->assertSame(1, SensitiveWord::query()->where('word', '待提级词')->count());
        } finally {
            TenantContext::forget();
        }

        // 完全没变化的重复提交仍算跳过
        $this->forTenant($tenant)
            ->postJson('/api/v1/sensitive-words', ['word' => '待提级词', 'severity' => 'blocked'])
            ->assertStatus(201)
            ->assertJsonPath('updated', 0)
            ->assertJsonPath('skipped_duplicates', 1);
    }

    public function test_store_rejects_unknown_severity(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('sw-bad-sev', 'admin');
        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->postJson('/api/v1/sensitive-words', ['word' => '词', 'severity' => 'fatal'])
            ->assertStatus(422);
    }

    private function makeTenantUser(string $subdomain, string $role): array
    {
        $tenant = Tenant::query()->create([
            'subdomain' => $subdomain,
            'name' => strtoupper($subdomain),
            'plan' => 'basic',
            'status' => 'active',
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain . $role),
            'role' => $role,
        ]);

        return [$tenant, $user];
    }

    private function forTenant(Tenant $tenant): self
    {
        return $this
            ->withServerVariables(['HTTP_HOST' => $tenant->subdomain . '.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }
}
