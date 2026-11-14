<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\KnowledgeBase;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CrossTenantProjectAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.tenant_domain' => 'geo.example.test', 'geo.skill_execution_claim_required' => false]);
    }

    public function test_article_creation_cannot_reference_another_tenants_project(): void
    {
        [$tenantA, $adminA] = $this->createTenantAdmin('tenant-a');
        [$tenantB, $adminB] = $this->createTenantAdmin('tenant-b');
        $projectB = $this->createProjectFor($tenantB, $adminB);

        Sanctum::actingAs($adminA, ['*']);

        $this
            ->forTenant($tenantA)
            ->postJson('/api/v1/articles', [
                'project_id' => $projectB->id,
                'title' => 'Cross tenant article',
                'body_markdown' => 'This should not be created.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['project_id']);

        $this->assertSame(0, Article::query()->count());
    }

    public function test_sync_skill_execution_cannot_reference_another_tenants_project(): void
    {
        [$tenantA, $adminA] = $this->createTenantAdmin('tenant-a');
        [$tenantB, $adminB] = $this->createTenantAdmin('tenant-b');
        $projectB = $this->createProjectFor($tenantB, $adminB);

        Sanctum::actingAs($adminA, ['*']);

        $this
            ->forTenant($tenantA)
            ->postJson('/api/v1/skills/execute', [
                'skill_id' => 'yao-geo-panorama-audit',
                'project_id' => $projectB->id,
                'fields' => ['brand_name' => 'Tenant A Brand'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['project_id']);

        $this->assertSame(0, SkillExecution::query()->count());
    }

    public function test_async_skill_execution_cannot_reference_another_tenants_project(): void
    {
        [$tenantA, $adminA] = $this->createTenantAdmin('tenant-a');
        [$tenantB, $adminB] = $this->createTenantAdmin('tenant-b');
        $projectB = $this->createProjectFor($tenantB, $adminB);

        Sanctum::actingAs($adminA, ['*']);

        $this
            ->forTenant($tenantA)
            ->postJson('/api/v1/skills/execute-async', [
                'skill_id' => 'yao-geo-panorama-audit',
                'project_id' => $projectB->id,
                'fields' => ['brand_name' => 'Tenant A Brand'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['project_id']);

        $this->assertSame(0, SkillExecution::query()->count());
    }

    /**
     * 隐式路由模型绑定必须在租户上下文之内解析。
     *
     * 曾经不是：SubstituteBindings 在框架优先级表里排在 IdentifyTenant 之前，
     * 绑定时 TenantContext::id() 是 null，TenantScope 直接 return（fail-open），
     * 于是 {knowledgeBase} 绑出来的是别家租户的记录。控制器里再没有补一次
     * 归属校验的路由就真泄漏了——A 租户能读到 B 的知识库，也能把它删掉。
     *
     * 修法是 bootstrap/app.php 里 prependToPriorityList 把 IdentifyTenant 提到
     * SubstituteBindings 之前。这条用例守的就是那个顺序：知识库控制器至今没有
     * 控制器层的租户校验，一旦顺序回退，这里立刻红。
     */
    public function test_implicit_route_binding_cannot_reach_another_tenants_record(): void
    {
        [$tenantA, $adminA] = $this->createTenantAdmin('tenant-a');
        [$tenantB, $adminB] = $this->createTenantAdmin('tenant-b');

        TenantContext::set($tenantB);
        $knowledgeBaseB = KnowledgeBase::query()->create([
            'name' => 'B 家的知识库',
            'description' => '不该被 A 看到',
            'status' => 'active',
            'settings' => [],
        ]);
        TenantContext::forget();

        Sanctum::actingAs($adminA, ['*']);

        $this->forTenant($tenantA)
            ->getJson('/api/v1/knowledge-bases/'.$knowledgeBaseB->id)
            ->assertStatus(404);

        $this->forTenant($tenantA)
            ->getJson('/api/v1/knowledge-bases/'.$knowledgeBaseB->id.'/documents')
            ->assertStatus(404);

        $this->forTenant($tenantA)
            ->deleteJson('/api/v1/knowledge-bases/'.$knowledgeBaseB->id)
            ->assertStatus(404);

        $this->assertNotNull(
            KnowledgeBase::withoutGlobalScopes()->find($knowledgeBaseB->id),
            'B 家的知识库必须还在——曾经这一刀真能删掉别家的数据',
        );
    }

    private function createTenantAdmin(string $subdomain): array
    {
        $tenant = Tenant::query()->create([
            'subdomain' => $subdomain,
            'name' => strtoupper($subdomain),
            'plan' => 'basic',
            'status' => 'active',
        ]);

        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain),
            'role' => 'admin',
        ]);

        return [$tenant, $admin];
    }

    private function createProjectFor(Tenant $tenant, User $owner): Project
    {
        TenantContext::set($tenant);

        try {
            return Project::query()->create([
                'owner_id' => $owner->id,
                'name' => $tenant->name.' Project',
                'brand_name' => $tenant->name,
                'brand_url' => 'https://'.$tenant->subdomain.'.example.test',
                'settings' => [],
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
