<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\ArticleReview;
use App\Models\ArticleVersion;
use App\Models\Author;
use App\Models\Category;
use App\Models\ImageLibrary;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ArticleCmsDeepeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=',
            'app.tenant_domain' => 'geo.example.test',
        ]);
        $this->app->forgetInstance('encrypter');
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    public function test_article_store_accepts_category_byline_and_og_fields(): void
    {
        [$tenant, $admin] = $this->tenantAndUser('deep-1', 'admin');
        $project = $this->project($tenant, $admin);
        $cat = Category::create(['tenant_id' => $tenant->id, 'name' => '行业洞察']);
        $author = Author::create(['tenant_id' => $tenant->id, 'name' => '李工']);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->forTenant($tenant)
            ->postJson('/api/v1/articles', [
                'project_id' => $project->id,
                'title' => 'GEO 趋势',
                'body_markdown' => '# GEO 趋势',
                'category_id' => $cat->id,
                'byline_author_id' => $author->id,
                'seo_title' => 'ST',
                'seo_description' => 'SD',
                'canonical_url' => 'https://c.test/x',
                'og_title' => 'OG',
                'og_description' => 'OGD',
                'og_image' => 'https://og.test/x.png',
            ])
            ->assertCreated()
            ->assertJsonPath('category_id', $cat->id)
            ->assertJsonPath('category_detail.name', '行业洞察')
            ->assertJsonPath('byline_author_id', $author->id)
            ->assertJsonPath('byline_author.name', '李工')
            ->assertJsonPath('seo.og_title', 'OG')
            ->assertJsonPath('seo.og_description', 'OGD')
            ->assertJsonPath('seo.og_image', 'https://og.test/x.png');

        // 初始版本快照已写入
        $this->assertDatabaseHas('article_versions', [
            'article_id' => $response->json('id'),
            'version' => 1,
        ]);
    }

    public function test_review_actions_write_history_and_endpoint_lists_them(): void
    {
        [$tenant, $admin] = $this->tenantAndUser('deep-2', 'admin');
        $project = $this->project($tenant, $admin);
        $article = $this->article($tenant, $project, $admin, ['status' => 'draft']);

        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->postJson("/api/v1/articles/{$article->id}/submit-review", ['review_notes' => '提交审核'])
            ->assertOk();

        $this->forTenant($tenant)
            ->postJson("/api/v1/articles/{$article->id}/approve", ['review_notes' => '通过'])
            ->assertOk();

        $this->forTenant($tenant)
            ->getJson("/api/v1/articles/{$article->id}/reviews")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['review_status' => ArticleReview::STATUS_SUBMITTED])
            ->assertJsonFragment(['review_status' => ArticleReview::STATUS_APPROVED]);
    }

    public function test_body_change_snapshots_version_and_restore_rolls_back(): void
    {
        [$tenant, $admin] = $this->tenantAndUser('deep-3', 'admin');
        $project = $this->project($tenant, $admin);
        $article = $this->article($tenant, $project, $admin, [
            'status' => 'draft',
            'body_markdown' => 'v1 body',
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->patchJson("/api/v1/articles/{$article->id}", ['body_markdown' => 'v2 body'])
            ->assertOk()
            ->assertJsonPath('version', 2);

        $this->forTenant($tenant)
            ->getJson("/api/v1/articles/{$article->id}/versions")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $v1 = ArticleVersion::where('article_id', $article->id)->where('version', 1)->firstOrFail();

        $this->forTenant($tenant)
            ->postJson("/api/v1/articles/{$article->id}/versions/{$v1->id}/restore")
            ->assertOk()
            ->assertJsonPath('body_markdown', 'v1 body')
            ->assertJsonPath('version', 3);

        $this->assertSame('v1 body', Article::find($article->id)->body_markdown);
    }

    public function test_recycle_excludes_from_index_and_restore_brings_back(): void
    {
        [$tenant, $admin] = $this->tenantAndUser('deep-4', 'admin');
        $project = $this->project($tenant, $admin);
        $article = $this->article($tenant, $project, $admin, ['status' => 'approved']);

        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->postJson("/api/v1/articles/{$article->id}/recycle")
            ->assertOk()
            ->assertJsonPath('status', 'recycled');

        $this->forTenant($tenant)
            ->getJson('/api/v1/articles')
            ->assertOk()
            ->assertJsonMissing(['id' => $article->id]);

        $this->forTenant($tenant)
            ->postJson("/api/v1/articles/{$article->id}/restore")
            ->assertOk()
            ->assertJsonPath('status', 'draft');

        $this->forTenant($tenant)
            ->getJson('/api/v1/articles')
            ->assertOk()
            ->assertJsonFragment(['id' => $article->id]);
    }

    public function test_editor_image_attach_list_detach_and_cross_tenant_rejected(): void
    {
        [$tenant, $admin] = $this->tenantAndUser('deep-5', 'admin');
        [$tenantB] = $this->tenantAndUser('deep-5b', 'admin');
        $project = $this->project($tenant, $admin);
        $article = $this->article($tenant, $project, $admin, ['status' => 'draft']);
        $img = ImageLibrary::create([
            'tenant_id' => $tenant->id,
            'url' => 'https://img.test/a.png',
            'title' => '图A',
        ]);
        $foreignImg = ImageLibrary::create([
            'tenant_id' => $tenantB->id,
            'url' => 'https://img.test/b.png',
            'title' => '图B',
        ]);

        Sanctum::actingAs($admin, ['*']);

        // 跨租户图片被拒
        $this->forTenant($tenant)
            ->postJson("/api/v1/articles/{$article->id}/images", ['image_library_id' => $foreignImg->id])
            ->assertJsonValidationErrors(['image_library_id']);

        // 正常插入
        $this->forTenant($tenant)
            ->postJson("/api/v1/articles/{$article->id}/images", ['image_library_id' => $img->id])
            ->assertCreated()
            ->assertJsonPath('url', 'https://img.test/a.png');

        $this->forTenant($tenant)
            ->getJson("/api/v1/articles/{$article->id}/images")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $link = ArticleImage::where('article_id', $article->id)->firstOrFail();

        $this->forTenant($tenant)
            ->deleteJson("/api/v1/articles/{$article->id}/images/{$link->id}")
            ->assertOk();

        $this->forTenant($tenant)
            ->getJson("/api/v1/articles/{$article->id}/images")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ─── helpers ───

    private function tenantAndUser(string $subdomain, string $role): array
    {
        $tenant = Tenant::create([
            'subdomain' => $subdomain,
            'name' => $subdomain,
            'plan' => 'points',
            'status' => 'active',
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,

            'email' => $subdomain.'@example.com',
            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain),
            'role' => $role,
        ]);

        return [$tenant, $user];
    }

    private function project(Tenant $tenant, User $owner): Project
    {
        TenantContext::set($tenant);
        try {
            return Project::query()->create([
                'owner_id' => $owner->id,
                'name' => 'Proj '.$tenant->subdomain,
                'brand_name' => $tenant->subdomain,
                'brand_url' => 'https://'.$tenant->subdomain.'.example.test',
                'settings' => [],
            ]);
        } finally {
            TenantContext::forget();
        }
    }

    private function article(Tenant $tenant, Project $project, User $author, array $overrides = []): Article
    {
        TenantContext::set($tenant);
        try {
            $article = Article::query()->create(array_merge([
                'project_id' => $project->id,
                'author_id' => $author->id,
                'title' => 'Deep Article',
                'body_markdown' => '# Deep Article',
                'summary' => 'deep summary',
                'content_type' => 'general',
                'status' => 'draft',
                'source_type' => 'manual',
                'metadata' => [],
            ], $overrides));

            // 与控制器 store 行为一致：创建即写初始版本快照
            $article->snapshotVersion($author->id);

            return $article;
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
