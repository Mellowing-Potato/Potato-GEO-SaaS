<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleReview;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ArticleReviewStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_uses_current_content_version_and_duplicate_actions_do_not_add_history(): void
    {
        config(['app.tenant_domain' => 'geo.example.test']);
        $tenant = Tenant::create(['subdomain' => 'article-review', 'name' => 'Review', 'plan' => 'basic', 'status' => 'active']);
        TenantContext::set($tenant);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        $project = Project::create(['owner_id' => $user->id, 'name' => '项目', 'brand_name' => '项目', 'settings' => []]);
        $article = Article::create([
            'project_id' => $project->id, 'author_id' => $user->id,
            'title' => '初稿', 'summary' => '旧摘要', 'body_markdown' => '旧正文',
            'status' => 'draft', 'source_type' => 'manual', 'content_type' => 'general',
        ]);
        $createdAt = $article->fresh()->created_at->toISOString();
        Sanctum::actingAs($user, ['*']);
        $this->withServerVariables(['HTTP_HOST' => 'article-review.geo.example.test'])
            ->withHeader('X-Tenant', 'article-review');
        $path = "/api/v1/articles/{$article->id}";

        $this->postJson('/api/v1/articles', [
            'project_id' => $project->id, 'title' => '不能跳过审核', 'status' => 'approved',
        ])->assertStatus(422);
        $this->patchJson($path, ['status' => 'approved'])->assertStatus(422);
        $this->postJson($path.'/approve')->assertStatus(409);
        $this->postJson($path.'/submit-review')->assertOk()->assertJsonPath('status', 'pending');
        $this->postJson($path.'/submit-review')->assertOk();
        $this->assertSame(1, ArticleReview::where('article_id', $article->id)->count());
        $this->postJson($path.'/approve')->assertOk()->assertJsonPath('status', 'approved');
        $this->postJson($path.'/approve')->assertOk();
        $this->assertSame(2, ArticleReview::where('article_id', $article->id)->count());
        $version = $article->fresh()->version;

        $edited = $this->patchJson($path, ['summary' => '新摘要'])->assertOk()
            ->assertJsonPath('status', 'draft')->assertJsonPath('version', $version + 1);
        $this->assertSame($createdAt, $article->fresh()->created_at->toISOString());
        $this->assertNull($edited->json('reviewed_at'));
        $this->postJson($path.'/approve')->assertStatus(409);
        $this->postJson($path.'/submit-review')->assertOk()->assertJsonPath('status', 'pending');
        $this->postJson($path.'/request-changes', ['review_notes' => '核对来源'])
            ->assertOk()->assertJsonPath('status', 'editing');
        $this->assertSame(4, ArticleReview::where('article_id', $article->id)->count());
        $savedVersion = $article->versions()->latest('id')->firstOrFail();
        $article->forceFill(['status' => 'approved'])->saveQuietly();
        $this->patchJson($path, ['body_markdown' => null])->assertOk()
            ->assertJsonPath('status', 'draft')->assertJsonPath('version', $version + 2);
        $article->forceFill(['status' => 'published'])->saveQuietly();
        $this->postJson($path.'/versions/'.$savedVersion->id.'/restore')->assertOk()
            ->assertJsonPath('status', 'draft')->assertJsonPath('reviewed_at', null);
        TenantContext::forget();
    }
}
