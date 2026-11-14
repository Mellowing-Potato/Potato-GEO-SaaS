<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReadingPageTest extends TestCase
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

    public function test_published_article_public_page_is_gone(): void
    {
        [$tenant, $article] = $this->publishedArticle('read-a', 'my-post', "# 你好世界\n\n这是公开内容。");

        $this->get("/read/{$tenant->subdomain}/{$article->slug}")
            ->assertStatus(410)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertDontSee($article->title)
            ->assertDontSee('你好世界');
    }

    public function test_retired_page_exposes_no_project_brand_or_article_body(): void
    {
        [$tenant, $article] = $this->publishedArticle('read-brand', 'brand-guide', "# 公开文章 public\n\n正文内容。");

        $response = $this->get("/read/{$tenant->subdomain}/{$article->slug}")
            ->assertStatus(410)
            ->assertDontSee('read-brand')
            ->assertDontSee('正文内容。');
    }

    public function test_unpublished_article_returns_410(): void
    {
        [$tenant, $article] = $this->draftArticle('read-d', 'draft-post');

        $this->get("/read/{$tenant->subdomain}/{$article->slug}")
            ->assertStatus(410);
    }

    public function test_unknown_tenant_returns_410(): void
    {
        [$tenant, $article] = $this->publishedArticle('read-u', 'post-x');

        $this->get("/read/nope-tenant/{$article->slug}")
            ->assertStatus(410);
    }

    public function test_private_article_returns_410(): void
    {
        [$tenant, $article] = $this->publishedArticle('read-p', 'private-post', "# 私有\n\n内容", ['visibility' => 'private']);

        $this->get("/read/{$tenant->subdomain}/{$article->slug}")
            ->assertStatus(410);
    }

    // ─── helpers ───

    private function tenant(string $subdomain): Tenant
    {
        return Tenant::create([
            'subdomain' => $subdomain,
            'name' => '租户-'.$subdomain,
            'plan' => 'points',
            'status' => 'active',
        ]);
    }

    private function publishedArticle(string $subdomain, string $slug, string $body = '# 标题', array $metadata = []): array
    {
        return $this->article($subdomain, $slug, $body, 'published', $metadata);
    }

    private function draftArticle(string $subdomain, string $slug): array
    {
        return $this->article($subdomain, $slug, '# 草稿', 'draft', []);
    }

    private function article(string $subdomain, string $slug, string $body, string $status, array $metadata): array
    {
        $tenant = $this->tenant($subdomain);
        $owner = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);

        TenantContext::set($tenant);
        try {
            $project = Project::query()->create([
                'owner_id' => $owner->id,
                'name' => 'Proj '.$subdomain,
                'brand_name' => $subdomain,
                'brand_url' => 'https://'.$subdomain.'.example.test',
                'settings' => [],
            ]);

            $article = Article::query()->create([
                'project_id' => $project->id,
                'author_id' => $owner->id,
                'title' => '公开文章 '.($metadata['visibility'] ?? 'public'),
                'slug' => $slug,
                'body_markdown' => $body,
                'summary' => '摘要内容',
                'seo_description' => 'SEO 描述',
                'content_type' => 'general',
                'status' => $status,
                'source_type' => 'manual',
                'metadata' => $metadata,
            ]);

            return [$tenant, $article];
        } finally {
            TenantContext::forget();
        }
    }
}
