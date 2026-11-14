<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectDashboardActivityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-24T10:00:00+00:00'));
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        $this->travelBack();
        parent::tearDown();
    }

    public function test_without_project_parameter_preserves_activity_shape_scope_and_descending_merge_order(): void
    {
        [$tenant, $user, $project] = $this->context('activity-all');
        $second = $this->project($tenant, $user, 'Second project');
        [$foreignTenant, $foreignUser, $foreignProject] = $this->context('activity-other');
        $oldExecution = $this->execution($tenant, $user, $project, 'First execution', -10);
        $newExecution = $this->execution($tenant, $user, $second, 'Second execution', -2);
        $editing = $this->article($tenant, $user, $project, 'Editing article', 'editing', -4);
        $pending = $this->article($tenant, $user, $second, 'Pending article', 'pending', -1);
        $this->article($tenant, $user, $project, 'Published is not an activity item', 'published', 1);
        $this->execution($foreignTenant, $foreignUser, $foreignProject, 'Foreign execution', 2);
        $this->article($foreignTenant, $foreignUser, $foreignProject, 'Foreign article', 'pending', 3);
        Sanctum::actingAs($user, ['*']);

        $this->forTenant($tenant)->getJson('/api/v1/dashboard/activity')->assertOk()->assertExactJson([
            'data' => [
                $this->articleRow($pending, $second),
                $this->executionRow($newExecution, $second),
                $this->articleRow($editing, $project),
                $this->executionRow($oldExecution, $project),
            ],
        ]);
    }

    public function test_project_filter_applies_to_both_sources_before_their_limits(): void
    {
        [$tenant, $user, $project] = $this->context('activity-filter');
        $other = $this->project($tenant, $user, 'Other project');
        $execution = $this->execution($tenant, $user, $project, 'Requested execution', -20);
        $article = $this->article($tenant, $user, $project, 'Requested article', 'pending', -19);
        foreach ([-3, -2, -1] as $minute) {
            $this->execution($tenant, $user, $other, 'Newer other execution '.$minute, $minute);
            $this->article($tenant, $user, $other, 'Newer other article '.$minute, 'editing', $minute);
        }
        Sanctum::actingAs($user, ['*']);

        $this->forTenant($tenant)->getJson('/api/v1/dashboard/activity?project_id='.$project->id.'&limit=2')
            ->assertOk()->assertExactJson([
                'data' => [$this->articleRow($article, $project), $this->executionRow($execution, $project)],
            ]);
    }

    public function test_foreign_tenant_and_unknown_positive_project_ids_are_not_found(): void
    {
        [$tenant, $user] = $this->context('activity-boundary');
        [, , $foreignProject] = $this->context('activity-foreign');
        Sanctum::actingAs($user, ['*']);

        $this->forTenant($tenant)->getJson('/api/v1/dashboard/activity?project_id='.$foreignProject->id)
            ->assertNotFound();
        $this->forTenant($tenant)->getJson('/api/v1/dashboard/activity?project_id='.($foreignProject->id + 1000))
            ->assertNotFound();
    }

    #[DataProvider('invalidProjectIds')]
    public function test_supplied_project_parameter_must_be_a_positive_integer(mixed $value): void
    {
        [$tenant, $user] = $this->context('activity-invalid');
        Sanctum::actingAs($user, ['*']);

        $this->forTenant($tenant)->getJson('/api/v1/dashboard/activity?'.http_build_query(['project_id' => $value]))
            ->assertUnprocessable()->assertJsonValidationErrors('project_id');
    }

    public static function invalidProjectIds(): array
    {
        return [
            'empty' => [''],
            'zero' => ['0'],
            'negative' => ['-1'],
            'decimal' => ['1.5'],
            'text' => ['not-a-project'],
            'array' => [['1']],
            'overflow' => ['999999999999999999999999999999'],
        ];
    }

    public function test_default_limit_and_thirty_item_cap_are_preserved_with_and_without_project_filter(): void
    {
        [$tenant, $user, $project] = $this->context('activity-limit');
        foreach (range(1, 18) as $index) {
            $this->execution($tenant, $user, $project, 'Execution '.$index, -($index * 2));
            $this->article($tenant, $user, $project, 'Article '.$index, 'pending', -($index * 2 - 1));
        }
        Sanctum::actingAs($user, ['*']);

        $this->forTenant($tenant)->getJson('/api/v1/dashboard/activity')->assertOk()->assertJsonCount(10, 'data');
        foreach (['/api/v1/dashboard/activity?limit=1000', '/api/v1/dashboard/activity?project_id='.$project->id.'&limit=1000'] as $url) {
            $response = $this->forTenant($tenant)->getJson($url)->assertOk()->assertJsonCount(30, 'data');
            $rows = $response->json('data');
            $this->assertSame(['review', 'execution'], array_values(array_unique(array_column($rows, 'type'))));
            $this->assertSame([$project->id], array_values(array_unique(array_column($rows, 'project_id'))));
            $this->assertSame('Article 1', $rows[0]['title']);
            $this->assertSame('Execution 15', $rows[29]['skill_name']);
            $timestamps = array_map(fn ($row) => $row['updated_at'] ?? $row['created_at'], $rows);
            $sorted = $timestamps;
            rsort($sorted);
            $this->assertSame($sorted, $timestamps);
        }
    }

    private function context(string $slug): array
    {
        $tenant = Tenant::create(['subdomain' => $slug, 'name' => $slug, 'plan' => 'points', 'status' => 'active']);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => $slug.'@example.com', 'phone' => \Tests\Support\FixtureIdentity::phone($slug),
            'role' => 'admin',
        ]);

        return [$tenant, $user, $this->project($tenant, $user, $slug)];
    }

    private function project(Tenant $tenant, User $user, string $name): Project
    {
        TenantContext::set($tenant);
        $project = Project::create(['owner_id' => $user->id, 'name' => $name, 'settings' => []]);
        TenantContext::forget();

        return $project;
    }

    private function execution(Tenant $tenant, User $user, Project $project, string $name, int $minute): SkillExecution
    {
        TenantContext::set($tenant);
        $execution = SkillExecution::create([
            'project_id' => $project->id, 'user_id' => $user->id, 'skill_id' => 'activity-fixture',
            'skill_name' => $name, 'category' => 'test', 'status' => 'completed', 'duration_seconds' => 12,
        ]);
        $execution->forceFill(['created_at' => now()->addMinutes($minute), 'updated_at' => now()->addMinutes($minute)])->saveQuietly();
        TenantContext::forget();

        return $execution;
    }

    private function article(Tenant $tenant, User $user, Project $project, string $title, string $status, int $minute): Article
    {
        TenantContext::set($tenant);
        $article = Article::create([
            'project_id' => $project->id, 'author_id' => $user->id, 'title' => $title,
            'body_markdown' => '# Fixture', 'summary' => 'Fixture', 'content_type' => 'general',
            'source_type' => 'manual', 'status' => $status, 'metadata' => [],
        ]);
        $article->forceFill(['created_at' => now()->addMinutes($minute), 'updated_at' => now()->addMinutes($minute)])->saveQuietly();
        TenantContext::forget();

        return $article;
    }

    private function executionRow(SkillExecution $execution, Project $project): array
    {
        return [
            'id' => $execution->id, 'type' => 'execution', 'skill_name' => $execution->skill_name,
            'status' => $execution->status, 'project_name' => $project->name, 'project_id' => $project->id,
            'duration_seconds' => 12, 'created_at' => $execution->created_at->toIso8601String(),
        ];
    }

    private function articleRow(Article $article, Project $project): array
    {
        return [
            'id' => $article->id, 'type' => $article->status === 'pending' ? 'review' : 'editing',
            'title' => $article->title, 'status' => $article->status, 'project_name' => $project->name,
            'project_id' => $project->id, 'updated_at' => $article->updated_at->toIso8601String(),
        ];
    }

    private function forTenant(Tenant $tenant): self
    {
        return $this->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }
}
