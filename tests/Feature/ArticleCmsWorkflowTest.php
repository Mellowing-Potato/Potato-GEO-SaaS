<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Project;
use App\Models\PublishingChannel;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ArticleCmsWorkflowTest extends TestCase
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

    public function test_article_can_store_seo_category_and_slug_fields(): void
    {
        [$tenant, $admin, $project] = $this->tenantProject('cms-seo');
        Sanctum::actingAs($admin, ['*']);

        $response = $this
            ->forTenant($tenant)
            ->postJson('/api/v1/articles', [
                'project_id' => $project->id,
                'title' => 'GEO SaaS Content Guide',
                'slug' => 'GEO-SaaS-Guide',
                'category' => 'SaaS',
                'body_markdown' => '# GEO SaaS Content Guide',
                'summary' => 'How to structure content for AI search.',
                'seo_title' => 'GEO SaaS 内容指南',
                'seo_description' => '面向 AI 搜索的 SaaS 内容结构化指南。',
                'canonical_url' => 'https://example.test/geo-saas-guide',
            ])
            ->assertCreated()
            ->assertJsonPath('slug', 'geo-saas-guide')
            ->assertJsonPath('category', 'SaaS')
            ->assertJsonPath('seo.title', 'GEO SaaS 内容指南')
            ->assertJsonPath('seo.description', '面向 AI 搜索的 SaaS 内容结构化指南。')
            ->assertJsonPath('seo.canonical_url', 'https://example.test/geo-saas-guide');

        $articleId = $response->json('id');

        $this
            ->forTenant($tenant)
            ->getJson('/api/v1/articles?category=SaaS')
            ->assertOk()
            ->assertJsonPath('data.0.id', $articleId)
            ->assertJsonPath('data.0.slug', 'geo-saas-guide');
    }

    public function test_project_article_type_filter_applies_before_pagination_and_to_recycle_bin(): void
    {
        [$tenant, $admin, $project] = $this->tenantProject('cms-type-filter');
        Sanctum::actingAs($admin, ['*']);
        $explainer = $this->article($tenant, $project, $admin, ['content_type' => 'explainer', 'status' => 'approved']);
        $this->article($tenant, $project, $admin, ['content_type' => 'comparison', 'status' => 'approved']);
        $recycled = $this->article($tenant, $project, $admin, ['content_type' => 'explainer', 'status' => 'recycled']);
        $this->article($tenant, $project, $admin, ['content_type' => 'ranking', 'status' => 'recycled']);

        $this->forTenant($tenant)->getJson("/api/v1/projects/{$project->id}/articles?content_type=explainer&per_page=1")
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $explainer->id);
        $this->forTenant($tenant)->getJson("/api/v1/projects/{$project->id}/articles?content_type=explainer&status=recycled")
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $recycled->id);
        $this->forTenant($tenant)->getJson("/api/v1/projects/{$project->id}/articles?content_type=general")
            ->assertOk()->assertJsonPath('total', 0);
    }

    public function test_article_review_flow_approves_then_external_publish_marks_article_published(): void
    {
        [$tenant, $admin, $project] = $this->tenantProject('cms-review');
        $article = $this->article($tenant, $project, $admin, [
            'status' => 'draft',
            'slug' => 'review-ready',
            'category' => '案例',
            'seo_title' => 'Review Ready SEO',
            'seo_description' => 'Review ready description.',
            'canonical_url' => 'https://example.test/review-ready',
        ]);
        $channel = $this->channel($tenant);
        Sanctum::actingAs($admin, ['*']);

        $this
            ->forTenant($tenant)
            ->postJson("/api/v1/articles/{$article->id}/submit-review", [
                'review_notes' => 'Ready for editorial review.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('review_notes', 'Ready for editorial review.');

        $this
            ->forTenant($tenant)
            ->postJson("/api/v1/articles/{$article->id}/approve", [
                'review_notes' => 'Approved for distribution.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'approved')
            ->assertJsonPath('reviewed_by', $admin->id)
            ->assertJsonPath('reviewer.name', $admin->name)
            ->assertJsonPath('review_notes', 'Approved for distribution.');

        Http::fake([
            'https://hooks.example.test/cms-review' => Http::response([
                'remote_id' => 'cms-remote-001',
                'remote_url' => 'https://target.example.test/cms-remote-001',
                'status' => 'published',
            ], 200),
        ]);

        $this
            ->forTenant($tenant)
            ->postJson("/api/v1/articles/{$article->id}/publish", [
                'channel_id' => $channel->id,
            ])
            ->assertCreated()
            ->assertJsonPath('publication.status', 'published')
            ->assertJsonPath('publication.remote_id', 'cms-remote-001');

        $article->refresh();
        $this->assertSame('published', $article->status);
        $this->assertNotNull($article->published_at);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://hooks.example.test/cms-review'
                && $request['article']['slug'] === 'review-ready'
                && $request['article']['category'] === '案例'
                && $request['article']['seo']['title'] === 'Review Ready SEO'
                && $request['article']['seo']['canonical_url'] === 'https://example.test/review-ready';
        });
    }

    public function test_request_changes_blocks_external_publish_until_reapproved(): void
    {
        [$tenant, $admin, $project] = $this->tenantProject('cms-changes');
        $article = $this->article($tenant, $project, $admin, ['status' => 'pending']);
        $channel = $this->channel($tenant, ['endpoint_url' => 'https://hooks.example.test/cms-changes']);
        Sanctum::actingAs($admin, ['*']);
        Http::fake();

        $this
            ->forTenant($tenant)
            ->postJson("/api/v1/articles/{$article->id}/request-changes", [
                'review_notes' => '补充作者资质和来源链接。',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'editing')
            ->assertJsonPath('review_notes', '补充作者资质和来源链接。');

        $this
            ->forTenant($tenant)
            ->postJson("/api/v1/articles/{$article->id}/publish", [
                'channel_id' => $channel->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['article_id']);

        Http::assertNothingSent();
    }

    public function test_review_actions_are_tenant_scoped(): void
    {
        [$tenantA, $adminA] = $this->tenantProject('cms-tenant-a');
        [$tenantB, $adminB, $projectB] = $this->tenantProject('cms-tenant-b');
        $articleB = $this->article($tenantB, $projectB, $adminB, ['status' => 'pending']);
        Sanctum::actingAs($adminA, ['*']);

        $this
            ->forTenant($tenantA)
            ->postJson("/api/v1/articles/{$articleB->id}/approve", [
                'review_notes' => 'Should not cross tenant boundary.',
            ])
            ->assertNotFound();

        $this->assertSame('pending', $articleB->fresh()->status);
    }

    /**
     * 回收站活门回归守卫（2026-09-03）。
     *
     * 在此之前 index() / projectArticles() 都在读 status 参数**之前**就无条件
     * 排掉 recycled，所以传 status=recycled 得到的查询等价于
     * status != 'recycled' AND status = 'recycled' —— 恒为空。
     * 而「移入回收站」按钮一直开着、restore 端点也一直在，只是拿不到 id 调不到，
     * 等于客户点一下文章就永久消失。这条测试守的就是那个洞。
     */
    public function test_recycled_articles_are_reachable_only_when_explicitly_requested(): void
    {
        [$tenant, $admin, $project] = $this->tenantProject('cms-recycle');
        Sanctum::actingAs($admin, ['*']);

        TenantContext::set($tenant);
        try {
            $live = Article::query()->create([
                'tenant_id' => $tenant->id,
                'project_id' => $project->id,
                'author_id' => $admin->id,
                'title' => '在架文章',
                'body_markdown' => '# live',
                'status' => Article::STATUS_DRAFT,
            ]);
            $binned = Article::query()->create([
                'tenant_id' => $tenant->id,
                'project_id' => $project->id,
                'author_id' => $admin->id,
                'title' => '回收站里的文章',
                'body_markdown' => '# binned',
                'status' => Article::STATUS_RECYCLED,
            ]);
        } finally {
            TenantContext::forget();
        }

        // 默认（不带 status）仍然看不到回收站里的 —— 这条不许放宽。
        $this->forTenant($tenant)
            ->getJson("/api/v1/projects/{$project->id}/articles")
            ->assertOk()
            ->assertJsonPath('data.0.id', $live->id)
            ->assertJsonCount(1, 'data');

        $this->forTenant($tenant)
            ->getJson('/api/v1/articles')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // 显式要 recycled 才给，且只给回收站里的。
        $this->forTenant($tenant)
            ->getJson("/api/v1/projects/{$project->id}/articles?status=recycled")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $binned->id);

        $this->forTenant($tenant)
            ->getJson('/api/v1/articles?status=recycled')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $binned->id);

        // 拿得到 id 才调得动 restore；restore 固定回 draft（所以界面文案是「恢复为草稿」）。
        $this->forTenant($tenant)
            ->postJson("/api/v1/articles/{$binned->id}/restore")
            ->assertOk()
            ->assertJsonPath('status', Article::STATUS_DRAFT);

        $this->forTenant($tenant)
            ->getJson("/api/v1/projects/{$project->id}/articles?status=recycled")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * 前端「文章」页靠翻页把整个项目的文章拉全（排序和 tab 角标都在前端算，
     * 只拿第一页 = 老文章永远排不上、角标是错的）。这条守的是它依赖的三件事：
     * ① 不带 per_page 时是 20；② page= 真的翻得动、两页不重叠；
     * ③ per_page 超过 100 会被夹到 100（前端按 100 一页算上限，夹错了翻页次数就不对）。
     */
    public function test_article_list_pagination_contract_the_frontend_relies_on(): void
    {
        [$tenant, $admin, $project] = $this->tenantProject('cms-page');
        Sanctum::actingAs($admin, ['*']);

        TenantContext::set($tenant);
        try {
            for ($i = 1; $i <= 150; $i++) {
                Article::query()->create([
                    'tenant_id' => $tenant->id,
                    'project_id' => $project->id,
                    'author_id' => $admin->id,
                    'title' => sprintf('翻页文章 %03d', $i),
                    'body_markdown' => '# page',
                    'status' => Article::STATUS_DRAFT,
                ]);
            }
        } finally {
            TenantContext::forget();
        }

        $this->forTenant($tenant)
            ->getJson("/api/v1/projects/{$project->id}/articles")
            ->assertOk()
            ->assertJsonPath('total', 150)
            ->assertJsonPath('per_page', 20)
            ->assertJsonCount(20, 'data');

        $first = $this->forTenant($tenant)
            ->getJson("/api/v1/projects/{$project->id}/articles?per_page=100")
            ->assertOk()
            ->assertJsonPath('total', 150)
            ->assertJsonPath('last_page', 2)
            ->assertJsonCount(100, 'data')
            ->json('data');

        $second = $this->forTenant($tenant)
            ->getJson("/api/v1/projects/{$project->id}/articles?per_page=100&page=2")
            ->assertOk()
            ->assertJsonCount(50, 'data')
            ->json('data');

        $firstIds = array_column($first, 'id');
        $secondIds = array_column($second, 'id');
        $this->assertSame([], array_intersect($firstIds, $secondIds), '两页不能有重叠');
        $this->assertCount(150, array_unique(array_merge($firstIds, $secondIds)));

        // 上限就是 100，要更多只能翻页 —— 前端的 CL_PAGE_SIZE 是照这个定的。
        $this->forTenant($tenant)
            ->getJson("/api/v1/projects/{$project->id}/articles?per_page=500")
            ->assertOk()
            ->assertJsonPath('per_page', 100)
            ->assertJsonCount(100, 'data');

        // /articles（不带 project 前缀）那处也是同一份分页参数，一起守住。
        $this->forTenant($tenant)
            ->getJson('/api/v1/articles?per_page=500')
            ->assertOk()
            ->assertJsonPath('per_page', 100)
            ->assertJsonCount(100, 'data');
    }

    private function tenantProject(string $subdomain): array
    {
        [$tenant, $admin] = $this->tenantAdmin($subdomain);

        TenantContext::set($tenant);
        try {
            $project = Project::query()->create([
                'owner_id' => $admin->id,
                'name' => strtoupper($subdomain).' Project',
                'brand_name' => strtoupper($subdomain),
                'brand_url' => 'https://'.$subdomain.'.example.test',
                'settings' => [],
            ]);
        } finally {
            TenantContext::forget();
        }

        return [$tenant, $admin, $project];
    }

    private function tenantAdmin(string $subdomain): array
    {
        $tenant = Tenant::query()->create([
            'subdomain' => $subdomain,
            'name' => strtoupper($subdomain),
            'plan' => 'points',
            'status' => 'active',
        ]);

        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,

            'email' => $subdomain.'@example.com',
            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain),
            'role' => 'admin',
        ]);

        return [$tenant, $admin];
    }

    private function article(Tenant $tenant, Project $project, User $author, array $overrides = []): Article
    {
        TenantContext::set($tenant);

        try {
            return Article::query()->create(array_merge([
                'project_id' => $project->id,
                'author_id' => $author->id,
                'title' => 'CMS Workflow Article',
                'body_markdown' => '# CMS Workflow Article',
                'summary' => 'CMS workflow summary',
                'content_type' => 'general',
                'status' => 'draft',
                'source_type' => 'manual',
                'metadata' => [],
            ], $overrides));
        } finally {
            TenantContext::forget();
        }
    }

    private function channel(Tenant $tenant, array $overrides = []): PublishingChannel
    {
        return PublishingChannel::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $tenant->id,
            'name' => 'CMS Webhook',
            'type' => PublishingChannel::TYPE_HTTP_WEBHOOK,
            'endpoint_url' => 'https://hooks.example.test/cms-review',
            'auth_type' => 'none',
            'is_active' => true,
        ], $overrides));
    }

    private function forTenant(Tenant $tenant): self
    {
        return $this
            ->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }
}
