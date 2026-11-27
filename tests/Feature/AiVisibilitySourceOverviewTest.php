<?php

namespace Tests\Feature;

use App\Models\AiVisibilityRun;
use App\Models\AiVisibilitySource;
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

class AiVisibilitySourceOverviewTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Project $projectA;
    private Project $projectB;
    private array $runs;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.tenant_domain' => 'geo.example.test', 'geo.visibility_panel_enabled' => true]);
        Http::preventStrayRequests();
        $this->tenant = Tenant::create(['name' => '信源统计测试', 'subdomain' => 'source-overview', 'status' => 'active']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'admin']);
        TenantContext::set($this->tenant);
        $this->projectA = Project::create(['owner_id' => $this->user->id, 'name' => '测试项目 A']);
        $this->projectB = Project::create(['owner_id' => $this->user->id, 'name' => '测试项目 B']);
        $this->runs = [
            'a-completed' => $this->createRun($this->projectA, 'completed', '目标 品牌介绍', 2, 11, 7),
            'a-failed' => $this->createRun($this->projectA, 'failed', '其他问法', 3, 2, 0),
            'b-completed' => $this->createRun($this->projectB, 'completed', '目标 使用说明', 5, 13, 5),
            'a-pending' => $this->createRun($this->projectA, 'pending', '等待内容', 0, 0, 0),
            'b-failed' => $this->createRun($this->projectB, 'failed', '其他品牌', 7, 3, 0),
        ];
        Sanctum::actingAs($this->user, ['projects:read']);
        $this->withServerVariables(['HTTP_HOST' => 'source-overview.geo.example.test'])
            ->withHeader('X-Tenant', $this->tenant->subdomain);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    public static function filters(): array
    {
        return [
            'project A' => [['project_id' => 'a'], ['a-completed', 'a-failed', 'a-pending'], 5],
            'project B' => [['project_id' => 'b'], ['b-completed', 'b-failed'], 12],
            'completed status' => [['status' => 'completed'], ['a-completed', 'b-completed'], 7],
            'failed status' => [['status' => 'failed'], ['a-failed', 'b-failed'], 10],
            'pending without sources' => [['status' => 'pending'], ['a-pending'], 0],
            'question query' => [['q' => '目标'], ['a-completed', 'b-completed'], 7],
            'combined filters' => [['project_id' => 'a', 'status' => 'completed', 'q' => '目标'], ['a-completed'], 2],
            'empty filtered result' => [['q' => '没有匹配的问题'], [], 0],
        ];
    }

    #[DataProvider('filters')]
    public function test_index_overview_counts_sources_from_the_same_filtered_runs(array $filters, array $keys, int $sources): void
    {
        if (isset($filters['project_id'])) {
            $filters['project_id'] = $filters['project_id'] === 'a' ? $this->projectA->id : $this->projectB->id;
        }
        $runs = collect($keys)->map(fn (string $key) => $this->runs[$key]);
        $response = $this->getJson('/api/v1/visibility/runs?'.http_build_query($filters))->assertOk();
        $this->assertEqualsCanonicalizing($runs->pluck('id')->all(), array_column($response->json('data'), 'id'));
        $response->assertJsonPath('meta.total', count($keys))
            ->assertJsonPath('overview.total_questions', count($keys))
            ->assertJsonPath('overview.completed_questions', $runs->where('status', 'completed')->count())
            ->assertJsonPath('overview.failed_questions', $runs->where('status', 'failed')->count())
            ->assertJsonPath('overview.total_tokens', $runs->sum('prompt_tokens') + $runs->sum('completion_tokens'))
            ->assertJsonPath('overview.total_sources', $sources);
        Http::assertNothingSent();
    }

    public function test_list_page_limits_do_not_limit_the_filtered_source_overview(): void
    {
        $ids = [];
        for ($index = 0; $index < 21; $index++) {
            $ids[] = $this->createRun($this->projectA, 'completed', '分页范围 '.$index, 1)->id;
        }
        $filters = ['project_id' => $this->projectA->id, 'q' => '分页范围'];
        foreach ([1 => 10, 10 => 10, 20 => 20, 50 => 21, 200 => 21] as $limit => $shown) {
            $this->getJson('/api/v1/visibility/runs?'.http_build_query($filters + ['per_page' => $limit]))
                ->assertOk()->assertJsonCount($shown, 'data')->assertJsonPath('meta.total', 21)
                ->assertJsonPath('overview.total_questions', 21)->assertJsonPath('overview.total_sources', 21);
        }
        $seen = [];
        foreach ([1, 2, 3, 4] as $page) {
            $response = $this->getJson('/api/v1/visibility/runs?'.http_build_query($filters + ['per_page' => 10, 'page' => $page]))
                ->assertOk()->assertJsonPath('meta.current_page', $page)->assertJsonPath('meta.last_page', 3)
                ->assertJsonPath('overview.total_questions', 21)->assertJsonPath('overview.total_sources', 21);
            $seen = array_merge($seen, array_column($response->json('data'), 'id'));
        }
        $this->assertSame($ids, $seen, 'Each page belongs to one filtered set; an empty page does not mean an empty overview.');
        Http::assertNothingSent();
    }

    public function test_run_and_source_tenant_scopes_both_remain_in_the_aggregate(): void
    {
        $foreignTenant = Tenant::create(['name' => '其他测试工作区', 'subdomain' => 'source-overview-other', 'status' => 'active']);
        $foreignUser = User::factory()->create(['tenant_id' => $foreignTenant->id, 'role' => 'admin']);
        TenantContext::set($foreignTenant);
        $foreignProject = Project::create(['owner_id' => $foreignUser->id, 'name' => '外部测试项目']);
        $foreignRun = $this->createRun($foreignProject, 'completed', '目标 外部哨兵', 11);
        $this->assertSame($foreignTenant->id, $foreignRun->tenant_id);
        TenantContext::set($this->tenant);
        // Independent tenant sentinels also catch accidentally dropping either
        // table's scope when composing the run subquery with the source count.
        $this->source($this->runs['a-completed'], 99, $foreignTenant->id);
        $this->source($foreignRun, 99, $this->tenant->id);

        $response = $this->getJson('/api/v1/visibility/runs?status=completed&q='.urlencode('目标'))->assertOk();
        $this->assertEqualsCanonicalizing([$this->runs['a-completed']->id, $this->runs['b-completed']->id], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('overview.total_questions', 2)->assertJsonPath('overview.total_sources', 7);
        $this->assertStringNotContainsString('外部哨兵', $response->getContent());
        $this->getJson('/api/v1/visibility/runs?project_id='.$foreignProject->id)->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('overview.total_questions', 0)->assertJsonPath('overview.total_sources', 0);
        Http::assertNothingSent();
    }

    private function createRun(Project $project, string $status, string $question, int $sources, int $prompt = 0, int $completion = 0): AiVisibilityRun
    {
        $run = AiVisibilityRun::create([
            'tenant_id' => $project->tenant_id, 'project_id' => $project->id, 'user_id' => $project->owner_id,
            'batch_id' => (string) Str::uuid(), 'question_index' => 0, 'question' => $question, 'status' => $status,
            'prompt_tokens' => $prompt, 'completion_tokens' => $completion, 'metadata' => ['usage_available' => true],
        ]);
        $run->forceFill(['created_at' => now()->subSeconds($this->sequence++)])->save();
        for ($index = 1; $index <= $sources; $index++) {
            $this->source($run, $index);
        }

        return $run;
    }

    private function source(AiVisibilityRun $run, int $rank, ?int $tenantId = null): void
    {
        AiVisibilitySource::create([
            'tenant_id' => $tenantId ?? $run->tenant_id, 'run_id' => $run->id,
            'url' => 'https://example.com/source/'.$run->id.'/'.$rank, 'rank' => $rank,
        ]);
    }
}
