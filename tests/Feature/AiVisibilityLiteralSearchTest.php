<?php

namespace Tests\Feature;

use App\Models\AiVisibilityRun;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The same HTTP/SQL cases run against SQLite and PostgreSQL. */
class AiVisibilityLiteralSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    public static function searches(): array
    {
        return [
            'ordinary substring' => ['GPT', '关于 GPT 模型的问题', '其他模型', true],
            'underscore' => ['GPT_4', '关于 GPT_4 模型的问题', '关于 GPTX4 模型的问题', true],
            'percent' => ['100%', '使用率 100% 的问题', '使用率 1000 的问题', true],
            'single percent is literal' => ['%', '使用率 100%', '使用率 1000', true],
            'single underscore is literal' => ['_', 'GPT_4', 'GPTX4', true],
            'backslash' => ['资料\\版本', '资料\\版本说明', '资料版本说明', true],
            'trailing backslash' => ['资料\\', '资料\\版本说明', '资料版本说明', true],
            'backslash before wildcard' => ['\\%_', '字面 \\%_ 的问题', '字面 \\XX 的问题', true],
            'escape character' => ['品牌!_100%', '品牌!_100% 可见性', '品牌!X1000 可见性', true],
            'repeated escape' => ['!!', '品牌!! 可见性', '品牌! 可见性', true],
            'Chinese' => ['品牌可见性', '如何检查品牌可见性？', '如何检查品牌声量？', true],
            'quotes stay data' => ["%' OR 1=1 --", "字面 %' OR 1=1 -- 示例", '其他问题', true],
            'no match' => ['没有匹配', '品牌可见性', '品牌声量', false],
            'trim whitespace' => ['  GPT_4  ', '关于 GPT_4 模型的问题', '关于 GPTX4 模型的问题', true],
        ];
    }

    #[DataProvider('searches')]
    public function test_index_search_is_literal_and_retains_project_status_and_tenant_scope(
        string $needle, string $question, string $nearMiss, bool $matches,
    ): void {
        config(['geo.visibility_panel_enabled' => true]);
        Http::preventStrayRequests();
        [$tenant, $user, $project] = $this->workspace('literal-search');
        $match = $this->createRun($project, $question);
        $this->createRun($project, $nearMiss);
        $this->createRun($project, $question, AiVisibilityRun::STATUS_FAILED);
        $otherProject = Project::create([
            'tenant_id' => $tenant->id, 'owner_id' => $user->id,
            'name' => '另一个测试项目', 'brand_name' => '测试品牌',
        ]);
        $this->createRun($otherProject, $question);
        [, , $foreignProject] = $this->workspace('foreign-literal-search');
        $this->createRun($foreignProject, $question);
        TenantContext::forget();

        Sanctum::actingAs($user, ['*']);
        $response = $this->withHeader('X-Tenant', $tenant->subdomain)
            ->getJson('/api/v1/visibility/runs?'.http_build_query([
                'project_id' => $project->id,
                'status' => AiVisibilityRun::STATUS_COMPLETED,
                'q' => $needle,
            ]))->assertOk();

        $response->assertJsonPath('meta.total', $matches ? 1 : 0)
            ->assertJsonPath('overview.total_questions', $matches ? 1 : 0)
            ->assertJsonCount($matches ? 1 : 0, 'data');
        $this->assertSame($matches ? [$match->id] : [], array_column($response->json('data'), 'id'));
        Http::assertNothingSent();
    }

    private function workspace(string $subdomain): array
    {
        $tenant = Tenant::create(['name' => '字面搜索测试', 'subdomain' => $subdomain, 'status' => 'active']);
        TenantContext::set($tenant);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        $project = Project::create(['owner_id' => $user->id, 'name' => '测试项目', 'brand_name' => '测试品牌']);

        return [$tenant, $user, $project];
    }

    private function createRun(Project $project, string $question, string $status = AiVisibilityRun::STATUS_COMPLETED): AiVisibilityRun
    {
        return AiVisibilityRun::create([
            'tenant_id' => $project->tenant_id, 'project_id' => $project->id,
            'user_id' => $project->owner_id, 'batch_id' => (string) Str::uuid(),
            'question' => $question, 'status' => $status,
        ]);
    }
}
