<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticlePublication;
use App\Models\Project;
use App\Models\PublishingChannel;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GeoDistributionAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.tenant_domain' => 'geo.example.test']);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();

        parent::tearDown();
    }

    public function test_distribution_analytics_summarizes_channels_projects_and_recent_publications(): void
    {
        [$tenant, $admin, $projectA] = $this->tenantProject('dist-analytics');
        $projectB = $this->project($tenant, $admin, 'Second Project');
        $wordpress = $this->channel($tenant, 'WordPress Site', PublishingChannel::TYPE_WORDPRESS_REST);
        $webhook = $this->channel($tenant, 'Webhook Site', PublishingChannel::TYPE_HTTP_WEBHOOK);
        $articleA = $this->article($tenant, $projectA, $admin, 'Published A');
        $articleB = $this->article($tenant, $projectB, $admin, 'Failed B');

        $this->publication($tenant, $articleA, $wordpress, [
            'status' => ArticlePublication::STATUS_PUBLISHED,
            'attempt_count' => 1,
            'remote_url' => 'https://wp.example.test/published-a',
            'published_at' => now(),
        ]);
        $this->publication($tenant, $articleB, $webhook, [
            'status' => ArticlePublication::STATUS_FAILED,
            'attempt_count' => 3,
            'last_error' => 'publish token=analytics-secret-123456 failed for fixture-user-20@example.com phone 13800000027',
        ]);

        Sanctum::actingAs($admin, ['*']);

        $response = $this
            ->forTenant($tenant)
            ->getJson('/api/v1/analytics/distribution?days=30&recent=5')
            ->assertOk()
            ->assertJsonPath('object', 'geo_distribution_analytics')
            ->assertJsonPath('metrics.channels_total', 2)
            ->assertJsonPath('metrics.channels_active', 2)
            ->assertJsonPath('metrics.publications_total', 2)
            ->assertJsonPath('metrics.publications_published', 1)
            ->assertJsonPath('metrics.publications_failed', 1)
            ->assertJsonPath('metrics.retry_attempts', 2)
            ->assertJsonPath('breakdowns.by_status.published', 1)
            ->assertJsonPath('breakdowns.by_status.failed', 1)
            ->assertJsonCount(2, 'breakdowns.by_channel')
            ->assertJsonCount(2, 'breakdowns.by_project')
            ->assertJsonPath('recent_publications.0.status', 'failed')
            ->assertJsonPath('recent_publications.0.last_error', 'publish token=[redacted_secret] failed for [redacted_email] phone [redacted_phone]');

        $content = $response->getContent();
        $this->assertStringNotContainsString('analytics-secret-123456', $content);
        $this->assertStringNotContainsString('fixture-user-20@example.com', $content);
        $this->assertStringNotContainsString('13800000027', $content);

        $this
            ->forTenant($tenant)
            ->getJson("/api/v1/analytics/distribution?project_id={$projectA->id}&channel_id={$wordpress->id}")
            ->assertOk()
            ->assertJsonPath('filters.project_id', $projectA->id)
            ->assertJsonPath('filters.channel_id', $wordpress->id)
            ->assertJsonPath('metrics.publications_total', 1)
            ->assertJsonPath('metrics.publications_published', 1)
            ->assertJsonPath('metrics.publications_failed', 0)
            ->assertJsonPath('breakdowns.by_channel.0.channel_name', 'WordPress Site')
            ->assertJsonPath('breakdowns.by_project.0.project_name', $projectA->name);
    }

    public function test_summary_and_trends_include_publication_metrics(): void
    {
        [$tenant, $admin, $project] = $this->tenantProject('dist-summary');
        $channel = $this->channel($tenant, 'Summary Webhook', PublishingChannel::TYPE_HTTP_WEBHOOK);
        $article = $this->article($tenant, $project, $admin, 'Summary Article');
        $this->publication($tenant, $article, $channel, [
            'status' => ArticlePublication::STATUS_PUBLISHED,
            'attempt_count' => 1,
            'published_at' => now(),
        ]);
        Sanctum::actingAs($admin, ['*']);

        $this
            ->forTenant($tenant)
            ->getJson('/api/v1/analytics/summary?days=30')
            ->assertOk()
            ->assertJsonPath('metrics.publications_total', 1)
            ->assertJsonPath('metrics.publications_published', 1)
            ->assertJsonPath('metrics.publications_failed', 0)
            ->assertJsonPath('breakdowns.publications_by_status.published', 1);

        $this
            ->forTenant($tenant)
            ->getJson('/api/v1/analytics/trends?days=30')
            ->assertOk()
            ->assertJsonPath('trends.publications.0.count', 1)
            ->assertJsonPath('trends.publications.0.published_count', 1)
            ->assertJsonPath('trends.publications.0.failed_count', 0);
    }

    public function test_distribution_analytics_is_tenant_scoped(): void
    {
        [$tenantA, $adminA, $projectA] = $this->tenantProject('dist-tenant-a');
        [$tenantB, $adminB, $projectB] = $this->tenantProject('dist-tenant-b');
        $channelA = $this->channel($tenantA, 'Tenant A Webhook', PublishingChannel::TYPE_HTTP_WEBHOOK);
        $channelB = $this->channel($tenantB, 'Tenant B Secret Webhook', PublishingChannel::TYPE_HTTP_WEBHOOK);
        $this->publication($tenantA, $this->article($tenantA, $projectA, $adminA, 'Tenant A Article'), $channelA, [
            'status' => ArticlePublication::STATUS_PUBLISHED,
            'attempt_count' => 1,
        ]);
        $this->publication($tenantB, $this->article($tenantB, $projectB, $adminB, 'Tenant B Secret Article'), $channelB, [
            'status' => ArticlePublication::STATUS_FAILED,
            'attempt_count' => 2,
        ]);

        Sanctum::actingAs($adminA, ['*']);

        $response = $this
            ->forTenant($tenantA)
            ->getJson('/api/v1/analytics/distribution')
            ->assertOk()
            ->assertJsonPath('metrics.channels_total', 1)
            ->assertJsonPath('metrics.publications_total', 1)
            ->assertJsonPath('metrics.publications_published', 1)
            ->assertJsonPath('metrics.publications_failed', 0);

        $content = $response->getContent();
        $this->assertStringNotContainsString('Tenant B Secret Webhook', $content);
        $this->assertStringNotContainsString('Tenant B Secret Article', $content);

        $this
            ->forTenant($tenantA)
            ->getJson("/api/v1/analytics/distribution?channel_id={$channelB->id}")
            ->assertNotFound();
    }

    private function tenantProject(string $subdomain): array
    {
        [$tenant, $admin] = $this->tenantAdmin($subdomain);

        return [$tenant, $admin, $this->project($tenant, $admin, strtoupper($subdomain).' Project')];
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

    private function project(Tenant $tenant, User $owner, string $name): Project
    {
        TenantContext::set($tenant);

        try {
            return Project::query()->create([
                'owner_id' => $owner->id,
                'name' => $name,
                'brand_name' => $name,
                'brand_url' => 'https://'.$tenant->subdomain.'.example.test',
                'settings' => [],
            ]);
        } finally {
            TenantContext::forget();
        }
    }

    private function article(Tenant $tenant, Project $project, User $author, string $title): Article
    {
        TenantContext::set($tenant);

        try {
            $article = Article::query()->create([
                'project_id' => $project->id,
                'author_id' => $author->id,
                'title' => $title,
                'slug' => strtolower(str_replace(' ', '-', $title)),
                'body_markdown' => '# '.$title,
                'summary' => $title.' summary',
                'content_type' => 'general',
                'status' => 'draft',
                'source_type' => 'manual',
                'metadata' => [],
            ]);
            Article::withoutEvents(function () use ($article) {
                $article->forceFill(['status' => 'published', 'published_at' => now()])->save();
            });

            return $article;
        } finally {
            TenantContext::forget();
        }
    }

    private function channel(Tenant $tenant, string $name, string $type): PublishingChannel
    {
        return PublishingChannel::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'type' => $type,
            'endpoint_url' => 'https://hooks.example.test/'.$tenant->subdomain,
            'auth_type' => 'none',
            'is_active' => true,
        ]);
    }

    private function publication(Tenant $tenant, Article $article, PublishingChannel $channel, array $overrides): ArticlePublication
    {
        return ArticlePublication::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $tenant->id,
            'article_id' => $article->id,
            'publishing_channel_id' => $channel->id,
            'status' => ArticlePublication::STATUS_PUBLISHED,
            'attempt_count' => 1,
            'last_attempted_at' => now(),
            'metadata' => [],
        ], $overrides));
    }

    private function forTenant(Tenant $tenant): self
    {
        return $this
            ->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }
}
