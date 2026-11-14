<?php

namespace Tests\Feature;

use App\Models\AiVisibilityRun;
use App\Models\Article;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class YanjiDashboardEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    public function test_article_count_matches_live_library_scope_and_reports_are_separate(): void
    {
        [$tenant, $user, $project] = $this->context('dashboard-a');
        [$otherTenant, $otherUser, $otherProject] = $this->context('dashboard-b');
        $this->article($tenant, $user, $project, ['title' => 'Manual article', 'content_type' => 'general']);
        $this->article($tenant, $user, $project, [
            'title' => 'Analysis report', 'source_type' => 'geo_analysis',
            'metadata' => ['structured' => ['analysis' => ['test' => true]]],
        ]);
        $this->article($tenant, $user, $project, [
            'title' => 'Skill report', 'source_type' => 'skill_execution', 'source_skill' => 'intent-miner',
            'content_type' => 'general',
        ]);
        $this->article($tenant, $user, $project, [
            'title' => 'Skill explainer', 'source_type' => 'skill_execution',
            'source_skill' => 'geo-explainer-builder', 'content_type' => 'explainer',
        ]);
        TenantContext::set($tenant);
        $fallbackExecution = SkillExecution::create([
            'project_id' => $project->id, 'user_id' => $user->id,
            'skill_id' => 'article-friendly-writer', 'skill_name' => 'Article writer',
            'category' => 'test', 'status' => 'completed',
        ]);
        TenantContext::forget();
        $this->article($tenant, $user, $project, [
            'title' => 'Linked article', 'source_type' => 'skill_execution',
            'source_skill' => null, 'content_type' => 'general',
            'skill_execution_id' => $fallbackExecution->id,
        ]);
        $old = $this->article($tenant, $user, $project, ['title' => 'Older article']);
        $old->forceFill(['created_at' => now()->subDays(60)])->saveQuietly();
        $this->article($tenant, $user, $project, ['title' => 'Recycled', 'status' => Article::STATUS_RECYCLED]);
        $deleted = $this->article($tenant, $user, $project, ['title' => 'Deleted']);
        $deleted->delete();
        $this->article($otherTenant, $otherUser, $otherProject, ['title' => 'Other tenant article']);
        Sanctum::actingAs($user, ['*']);

        $this->forTenant($tenant)->getJson("/api/v1/analytics/summary?project_id={$project->id}&days=30")
            ->assertOk()
            ->assertJsonPath('metrics.articles_total', 4)
            ->assertJsonPath('metrics.articles_period', 3)
            ->assertJsonPath('metrics.report_assets_total', 2)
            ->assertJsonPath('article_scope.project_id', $project->id)
            ->assertJsonPath('article_scope.time', 'all_time')
            ->assertJsonPath('ai_visibility.state', 'not_monitored');
        $this->forTenant($tenant)->getJson('/api/v1/analytics/by-project?days=30')
            ->assertOk()->assertJsonPath('projects.0.articles.total', 4)
            ->assertJsonPath('projects.0.articles.period', 3);
        $this->forTenant($tenant)->getJson("/api/v1/analytics/summary?project_id={$otherProject->id}")
            ->assertNotFound();
    }

    public function test_sampling_states_and_mention_basis_use_only_completed_answer_text(): void
    {
        [$tenant, $user, $project] = $this->context('sample-a');
        Sanctum::actingAs($user, ['*']);
        $url = "/api/v1/analytics/summary?project_id={$project->id}";
        $this->forTenant($tenant)->getJson($url)->assertOk()->assertJsonPath('ai_visibility.state', 'not_monitored');

        $this->visibilityRun($tenant, $user, $project, 'failed', null);
        $this->forTenant($tenant)->getJson($url)->assertOk()
            ->assertJsonPath('ai_visibility.state', 'failed')
            ->assertJsonPath('ai_visibility.sampled_answers', 0);

        $this->visibilityRun($tenant, $user, $project, 'completed', '这里没有出现品牌名字，也没有引用标记。');
        $this->forTenant($tenant)->getJson($url)->assertOk()
            ->assertJsonPath('ai_visibility.state', 'sampled')
            ->assertJsonPath('ai_visibility.sampled_answers', 1)
            ->assertJsonPath('ai_visibility.mentions', 0)
            ->assertJsonPath('ai_visibility.citations', 0);

        $this->visibilityRun($tenant, $user, $project, 'completed', 'sample-a 提供的资料可查看 [1]。');
        $this->forTenant($tenant)->getJson($url)->assertOk()
            ->assertJsonPath('ai_visibility.sampled_answers', 2)
            ->assertJsonPath('ai_visibility.mentions', 1)
            ->assertJsonPath('ai_visibility.citations', 1)
            ->assertJsonPath('ai_visibility.platforms.0', 'doubao')
            ->assertJsonPath('ai_visibility.batch_count', 3);
    }

    public function test_either_chinese_or_english_brand_name_counts_as_a_mention(): void
    {
        [$tenant, $user, $project] = $this->context('brand-aliases');
        TenantContext::set($tenant);
        $project->update(['brand_name' => '清禾可研 / ScholarClue']);
        TenantContext::forget();
        Sanctum::actingAs($user, ['*']);

        $this->visibilityRun($tenant, $user, $project, 'completed', '清禾可研是一个医学科研工作台。');
        $this->visibilityRun($tenant, $user, $project, 'completed', 'ScholarClue is the same product.');
        $this->visibilityRun($tenant, $user, $project, 'completed', '没有提到这个品牌。');

        $this->forTenant($tenant)->getJson("/api/v1/analytics/summary?project_id={$project->id}")
            ->assertOk()
            ->assertJsonPath('ai_visibility.sampled_answers', 3)
            ->assertJsonPath('ai_visibility.mentions', 2);
    }

    private function context(string $slug): array
    {
        $tenant = Tenant::create(['subdomain' => $slug, 'name' => $slug, 'plan' => 'points', 'status' => 'active']);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => $slug.'@example.com', 'phone' => \Tests\Support\FixtureIdentity::phone($slug),
            'role' => 'admin',
        ]);
        TenantContext::set($tenant);
        $project = Project::create([
            'owner_id' => $user->id, 'name' => $slug, 'brand_name' => $slug,
            'brand_url' => 'https://example.test', 'settings' => [],
        ]);
        TenantContext::forget();
        return [$tenant, $user, $project];
    }

    private function article(Tenant $tenant, User $user, Project $project, array $values): Article
    {
        TenantContext::set($tenant);
        $article = Article::create(array_merge([
            'project_id' => $project->id, 'author_id' => $user->id,
            'title' => 'Article', 'body_markdown' => '# Article', 'summary' => 'Summary',
            'content_type' => 'general', 'source_type' => 'manual', 'status' => 'draft', 'metadata' => [],
        ], $values));
        TenantContext::forget();
        return $article;
    }

    private function visibilityRun(Tenant $tenant, User $user, Project $project, string $status, ?string $answer): void
    {
        TenantContext::set($tenant);
        AiVisibilityRun::create([
            'project_id' => $project->id, 'user_id' => $user->id,
            'batch_id' => (string) \Illuminate\Support\Str::uuid(), 'question' => '言及是什么？',
            'status' => $status, 'provider' => 'doubao', 'answer_text' => $answer,
            'completed_at' => now(),
        ]);
        TenantContext::forget();
    }

    private function forTenant(Tenant $tenant): self
    {
        return $this->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }
}
