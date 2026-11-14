<?php

namespace Tests\Feature;

use App\Models\PublishingChannel;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WordPressTaxonomySyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.tenant_domain' => 'geo.example.test']);
    }

    /**
     * 拦截 WordPress REST 调用：GET 搜索返回空（未命中）→ POST 创建返回固定 id。
     */
    private function fakeWordPressCreate(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/wp/v2/categories')) {
                return $request->method() === 'GET'
                    ? Http::response([])
                    : Http::response(['id' => 11]);
            }
            if (str_contains($url, '/wp/v2/tags')) {
                return $request->method() === 'GET'
                    ? Http::response([])
                    : Http::response(['id' => 21]);
            }

            return Http::response([], 404);
        });
    }

    public function test_presync_categories_and_tags_to_wordpress(): void
    {
        $this->fakeWordPressCreate();

        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');

        TenantContext::set($tenant);
        $channel = PublishingChannel::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'WP 站点',
            'type' => PublishingChannel::TYPE_WORDPRESS_REST,
            'endpoint_url' => 'https://wp.example.test',
            'auth_type' => 'wordpress_application_password',
            'username' => 'admin',
            'is_active' => true,
            'settings' => ['timeout' => 10],
        ]);
        TenantContext::forget();

        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->postJson('/api/v1/publishing/channels/' . $channel->id . '/sync-taxonomy', [
                'categories' => ['科技', '生活'],
                'tags' => ['AI', 'GEO'],
            ])
            ->assertStatus(200)
            ->assertJsonPath('synced_categories', 2)
            ->assertJsonPath('synced_tags', 2)
            ->assertJsonPath('categories.科技', 11)
            ->assertJsonPath('tags.AI', 21);
    }

    public function test_find_or_create_returns_existing_term_on_search_hit(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');

        TenantContext::set($tenant);
        $channel = PublishingChannel::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'WP 站点',
            'type' => PublishingChannel::TYPE_WORDPRESS_REST,
            'endpoint_url' => 'https://wp.example.test',
            'auth_type' => 'wordpress_application_password',
            'username' => 'admin',
            'is_active' => true,
            'settings' => ['timeout' => 10],
        ]);
        TenantContext::forget();

        // 搜索命中已存在术语（name 精确匹配）→ 不应再 POST 创建
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/wp/v2/categories') && $request->method() === 'GET') {
                return Http::response([['id' => 99, 'name' => '科技']]);
            }

            return Http::response(['id' => 11]);
        });

        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->postJson('/api/v1/publishing/channels/' . $channel->id . '/sync-taxonomy', [
                'categories' => ['科技'],
            ])
            ->assertStatus(200)
            ->assertJsonPath('categories.科技', 99);
    }

    public function test_member_cannot_sync_taxonomy(): void
    {
        [$tenant, $member] = $this->makeTenantUser('tenant-a', 'member');

        TenantContext::set($tenant);
        $channel = PublishingChannel::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'WP 站点',
            'type' => PublishingChannel::TYPE_WORDPRESS_REST,
            'endpoint_url' => 'https://wp.example.test',
            'is_active' => true,
        ]);
        TenantContext::forget();

        Sanctum::actingAs($member, ['*']);

        $this->forTenant($tenant)
            ->postJson('/api/v1/publishing/channels/' . $channel->id . '/sync-taxonomy', ['categories' => ['x']])
            ->assertStatus(403);
    }

    private function makeTenantUser(string $subdomain, string $role): array
    {
        $tenant = Tenant::query()->create([
            'subdomain' => $subdomain,
            'name' => strtoupper($subdomain),
            'plan' => 'basic',
            'status' => 'active',
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain . $role),
            'role' => $role,
        ]);

        return [$tenant, $user];
    }

    private function forTenant(Tenant $tenant): self
    {
        return $this
            ->withServerVariables(['HTTP_HOST' => $tenant->subdomain . '.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }
}
