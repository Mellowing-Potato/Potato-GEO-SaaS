<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticlePublication;
use App\Models\ModelCallLog;
use App\Models\Project;
use App\Models\PublishingChannel;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformOperationsDashboardTest extends TestCase
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

    public function test_platform_admin_can_only_view_own_workspace_operations_with_redaction(): void
    {
        [$tenantA, $adminA, $projectA] = $this->tenantProject('platform-a');
        [$tenantB, $adminB, $projectB] = $this->tenantProject('platform-b');
        $platformAdmin = $adminA;
        $platformAdmin->forceFill(['role' => 'platform_admin'])->save();

        $channelA = $this->channel($tenantA, 'Tenant A Webhook');
        $channelB = $this->channel($tenantB, 'Tenant B Webhook');
        $articleA = $this->article($tenantA, $projectA, $adminA, 'Tenant A Article');
        $articleB = $this->article($tenantB, $projectB, $adminB, 'Tenant B Article');
        $this->publication($tenantA, $articleA, $channelA, [
            'status' => ArticlePublication::STATUS_PUBLISHED,
            'attempt_count' => 1,
            'remote_url' => 'https://target.example.test/a',
            'published_at' => now(),
            'last_error' => 'publish token=sk-test-platform-secret-123456 failed for fixture-user-18@example.com phone 13800000027',
        ]);
        $this->publication($tenantB, $articleB, $channelB, [
            'status' => ArticlePublication::STATUS_FAILED,
            'attempt_count' => 2,
            'last_error' => 'publish token=platform-secret-123456 failed for fixture-user-18@example.com phone 13800000027',
        ]);
        ModelCallLog::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id,
            'project_id' => $projectB->id,
            'user_id' => $adminB->id,
            'provider' => 'gateway',
            'model' => 'text',
            'status' => 'completed',
            'prompt_tokens' => 10,
            'completion_tokens' => 20,
            'total_tokens' => 30,
            'duration_seconds' => 1.2,
            'metadata' => [
                'points_deduction_status' => 'failed',
                'points_deduction_failure_reason' => 'Debit token=debit-secret-123456 failed for fixture-user-18@example.com phone 13800000027',
            ],
        ]);

        Sanctum::actingAs($platformAdmin, ['*']);

        $response = $this
            ->getJson('/api/v1/platform/operations?days=30&recent=5')
            ->assertOk()
            ->assertJsonPath('object', 'geo_platform_operations_dashboard')
            ->assertJsonPath('metrics.tenants_total', 1)
            ->assertJsonPath('metrics.tenants_active', 1)
            ->assertJsonPath('metrics.publishing_channels_total', 1)
            ->assertJsonPath('metrics.publications_total', 1)
            ->assertJsonPath('metrics.publications_published', 1)
            ->assertJsonPath('metrics.publications_failed', 0)
            ->assertJsonPath('recent_publications.0.last_error', 'publish token=[redacted_secret] failed for [redacted_email] phone [redacted_phone]');

        $response->assertJsonCount(1, 'tenants')->assertJsonCount(1, 'recent_publications')
            ->assertJsonPath('recent_publications.0.article_title', 'Tenant A Article')
            ->assertJsonPath('metrics.model_calls_total', 0);
        $this->assertNotContains('platform-b', array_column(array_column($response->json('tenants'), 'tenant'), 'subdomain'));
        $this->assertStringNotContainsString('Tenant B', $response->getContent());
        $this->assertStringNotContainsString('PLATFORM-B', $response->getContent());

        $content = $response->getContent();
        $this->assertStringNotContainsString('platform-secret-123456', $content);
        $this->assertStringNotContainsString('webhook-secret-123456', $content);
        $this->assertStringNotContainsString('invoice-secret-123456', $content);
        $this->assertStringNotContainsString('debit-secret-123456', $content);
        $this->assertStringNotContainsString('fixture-user-18@example.com', $content);
        $this->assertStringNotContainsString('13800000027', $content);
        $this->assertStringNotContainsString('qh_order_platform_b_secret', $content);
        $this->assertStringNotContainsString('qh_invoice_platform_b_secret', $content);
    }

    public function test_tenant_admin_cannot_access_platform_operations(): void
    {
        [$tenant, $admin] = $this->tenantProject('platform-denied');
        Sanctum::actingAs($admin, ['*']);

        $this
            ->withHeader('X-Tenant', $tenant->subdomain)
            ->getJson('/api/v1/platform/operations')
            ->assertForbidden()
            ->assertJsonPath('required_permission', 'platform:operations');
    }

    public function test_platform_role_is_not_a_tenant_team_role(): void
    {
        $this->assertFalse(Permissions::allows('admin', 'platform:operations'));
        $this->assertTrue(Permissions::allows('platform_admin', 'platform:operations'));
        $this->assertNotContains('platform_admin', Permissions::ROLES);
        $this->assertSame([...Permissions::getPermissionsForRole('admin'), 'platform:operations'], Permissions::getPermissionsForRole('platform_admin'));
    }

    private function tenantProject(string $subdomain): array
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

    private function channel(Tenant $tenant, string $name): PublishingChannel
    {
        return PublishingChannel::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'type' => PublishingChannel::TYPE_HTTP_WEBHOOK,
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
}
