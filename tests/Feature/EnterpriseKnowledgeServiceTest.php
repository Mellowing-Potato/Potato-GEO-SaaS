<?php

namespace Tests\Feature;

use App\Models\EnterpriseKnowledgeProject;
use App\Models\EnterpriseKnowledgeRevision;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Knowledge\EnterpriseKnowledgeDraftService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 阶段 1.5 企业知识库草稿服务单元测试（校验 / 修订幂等）
 */
class EnterpriseKnowledgeServiceTest extends TestCase
{
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

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain . $role),
            'role' => $role,
        ]);

        return [$tenant, $user];
    }

    public function test_validate_draft_flags_missing_sections_and_risky_words(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');

        $service = $this->app->make(EnterpriseKnowledgeDraftService::class);

        // 缺章节 + 含绝对化表述
        $items = $service->validateDraft("# 标题\n我们是国家唯一指定机构，保证 100% 有效。\n");
        $this->assertNotEmpty($items);

        $hasMissing = false;
        $hasRisky = false;
        foreach ($items as $item) {
            if ($item['level'] === 'warning' && ($item['section'] ?? '') !== '整体') {
                $hasMissing = true;
            }
            if ($item['level'] === 'danger') {
                $hasRisky = true;
            }
        }
        $this->assertTrue($hasMissing, '应包含缺章节告警');
        $this->assertTrue($hasRisky, '应包含绝对化表述告警');

        // 完整且安全的草稿应通过校验（无警告/危险项），且长度 > 500 字避免"过短"告警
        $full = implode("\n\n", array_map(
            fn (string $s): string => "## {$s}\n这是关于{$s}的安全描述内容，用于企业知识库沉淀，未使用任何绝对化表述，所有事实均可核验；该部分覆盖核心定义、适用对象、边界条件与可引用证据。",
            ['企业介绍', '业务信息摘要', '产品能力', '应用场景', '典型案例', 'FAQ', '禁用表述', '风险与冲突', '待人工确认']
        ));
        $cleanItems = $service->validateDraft($full);
        foreach ($cleanItems as $item) {
            $this->assertNotEquals('warning', $item['level']);
            $this->assertNotEquals('danger', $item['level']);
        }
    }

    public function test_record_revision_is_idempotent_on_same_content(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');
        TenantContext::set($tenant);

        $project = EnterpriseKnowledgeProject::create([
            'name' => '幂等修订测试',
            'status' => EnterpriseKnowledgeProject::STATUS_DRAFT,
            'created_by_user_id' => $admin->id,
        ]);

        $service = $this->app->make(EnterpriseKnowledgeDraftService::class);

        $first = $service->recordRevisionIfChanged($project, "草稿内容 A", 'manual', '保存', $admin->id);
        $this->assertNotNull($first);

        // 相同内容 → 不产生新修订
        $second = $service->recordRevisionIfChanged($project, "草稿内容 A", 'manual', '保存', $admin->id);
        $this->assertNull($second);

        // 不同内容 → 产生新修订
        $third = $service->recordRevisionIfChanged($project, "草稿内容 B", 'manual', '保存', $admin->id);
        $this->assertNotNull($third);

        $this->assertSame(
            2,
            EnterpriseKnowledgeRevision::query()
                ->where('tenant_id', $tenant->id)
                ->where('enterprise_knowledge_project_id', $project->id)
                ->count()
        );

        TenantContext::forget();
    }
}
