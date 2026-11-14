<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\Article;
use App\Models\ArticlePublication;
use App\Models\Project;
use App\Models\PublishingChannel;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 外站撤稿（计划 §9.5；口径由用户 2026-09-21 拍板：「删项目必须先把外站内容撤下来」）。
 *
 * 这组测试守四件事：
 *  1. 删项目**先撤稿再进桶**：目标站真的收到 DELETE，本地记录转 takedown 且保留 remote_url；
 *  2. 撤稿失败**不阻断删除**，但失败必须留痕（last_error + 审计）并出现在回收站待办里；
 *  3. 重试接口能把它撤下来（目标站恢复后）；
 *  4. 命令默认只读，--apply 才碰外站。
 */
class ExternalTakedownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.tenant_domain' => 'geo.example.test']);
        Http::swap(new Factory);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    public function test_deleting_a_project_takes_external_posts_down_first(): void
    {
        [$tenant, $admin, $project, $publication] = $this->fixture();

        Http::fake(['customer.example.test/wp-json/wp/v2/posts/4321' => Http::response(['deleted' => true])]);

        Sanctum::actingAs($admin, ['*']);
        $this->forTenant($tenant)
            ->deleteJson("/api/v1/projects/{$project->id}")
            ->assertNoContent();

        // 1. 目标站真收到了删除请求
        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/wp-json/wp/v2/posts/4321'));

        // 2. 本地记录转 takedown，remote_url 留着（事后核查"撤的是哪一篇"）
        $fresh = $publication->fresh();
        $this->assertSame(ArticlePublication::STATUS_TAKEDOWN, $fresh->status);
        $this->assertSame('https://customer.example.test/blog/post-1', $fresh->remote_url);
        $this->assertNull($fresh->last_error);
        $this->assertNotNull(data_get($fresh->metadata, 'takedown.at'));

        // 3. 审计留痕
        $this->assertTrue(
            AdminActivityLog::query()->where('action', 'publication.takedown_succeeded')->where('target_id', $publication->id)->exists(),
            '撤稿成功没有写审计',
        );

        // 4. 项目照常进桶
        $this->assertSoftDeleted('projects', ['id' => $project->id]);
    }

    public function test_a_failed_takedown_does_not_block_deletion_but_stays_visible(): void
    {
        [$tenant, $admin, $project, $publication] = $this->fixture();

        Http::fake(['customer.example.test/*' => Http::response('boom', 500)]);

        Sanctum::actingAs($admin, ['*']);
        $this->forTenant($tenant)
            ->deleteJson("/api/v1/projects/{$project->id}")
            ->assertNoContent();

        // 删除照常发生（客户要的是"从我这儿消失"，不该被第三方故障卡住）
        $this->assertSoftDeleted('projects', ['id' => $project->id]);

        // 但失败要留痕：状态仍是 published、last_error 写明、审计有记录
        $fresh = $publication->fresh();
        $this->assertSame(ArticlePublication::STATUS_PUBLISHED, $fresh->status);
        $this->assertStringContainsString('500', (string) $fresh->last_error);
        $this->assertTrue(
            AdminActivityLog::query()->where('action', 'publication.takedown_failed')->where('target_id', $publication->id)->exists(),
            '撤稿失败没有写审计',
        );

        // 而且要在客户看得到的地方亮着：回收站列表带撤稿待办
        $trash = $this->forTenant($tenant)->getJson('/api/v1/projects/trash')->assertOk();
        $this->assertSame(1, $trash->json('data.0.takedown.online'));
        $this->assertSame(1, $trash->json('data.0.takedown.failed'));
        $this->assertSame('https://customer.example.test/blog/post-1', $trash->json('data.0.takedown.remote_urls.0'));
    }

    public function test_takedown_can_be_retried_from_the_trash(): void
    {
        [$tenant, $admin, $project, $publication] = $this->fixture();

        // 一次 fake 里排两次应答（第一次 500 = 目标站掉线，第二次 200 = 恢复后重试成功）。
        // ⚠️ 别指望"同一个测试里第二次 Http::fake 会顶掉第一次" —— 实测在这里不生效，
        //    重试拿到的一直是第一次那条 500（假红）。
        Http::fake([
            'customer.example.test/*' => Http::sequence()
                ->push('boom', 500)
                ->push(['deleted' => true], 200),
        ]);
        Sanctum::actingAs($admin, ['*']);
        $this->forTenant($tenant)->deleteJson("/api/v1/projects/{$project->id}")->assertNoContent();
        $this->assertSame(ArticlePublication::STATUS_PUBLISHED, $publication->fresh()->status);

        // 目标站恢复后重试（桶内项目也能重试 —— 多数情况下客户就是在桶里那条待办上点重试）
        $retry = $this->forTenant($tenant)
            ->postJson("/api/v1/projects/{$project->id}/takedown")
            ->assertOk();

        $this->assertSame(1, $retry->json('data.succeeded'));
        $this->assertSame([], $retry->json('data.failed'));
        $this->assertSame(ArticlePublication::STATUS_TAKEDOWN, $publication->fresh()->status);
        $this->assertNull($publication->fresh()->last_error);

        // 二次重试：已经没有在线内容了，如实回答"没有需要撤回的"
        $again = $this->forTenant($tenant)->postJson("/api/v1/projects/{$project->id}/takedown")->assertOk();
        $this->assertSame(0, $again->json('data.attempted'));
        $this->assertStringContainsString('没有需要撤回', $again->json('message'));
    }

    public function test_takedown_command_is_read_only_until_apply(): void
    {
        [$tenant, $admin, $project, $publication] = $this->fixture();

        Http::fake();

        // dry-run：只列清单，一条外站请求都不发
        $this->artisan('geo:takedown-publications', ['--project' => (string) $project->id, '--json' => true])
            ->expectsOutputToContain('"mode":"dry-run"')
            ->assertSuccessful();
        Http::assertNothingSent();
        $this->assertSame(ArticlePublication::STATUS_PUBLISHED, $publication->fresh()->status);

        // --apply 才真撤
        Http::fake(['customer.example.test/*' => Http::response(['deleted' => true])]);
        $this->artisan('geo:takedown-publications', ['--project' => (string) $project->id, '--apply' => true, '--json' => true])
            ->expectsOutputToContain('"mode":"apply"')
            ->assertSuccessful();
        $this->assertSame(ArticlePublication::STATUS_TAKEDOWN, $publication->fresh()->status);
    }

    /** @return array{0:Tenant,1:User,2:Project,3:ArticlePublication} */
    private function fixture(): array
    {
        $tenant = Tenant::query()->create([
            'subdomain' => 'takedown-'.substr(md5((string) random_int(1, 1_000_000)), 0, 6),
            'name' => 'TAKEDOWN',
            'plan' => 'basic',
            'status' => 'active',
        ]);
        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,

            'role' => 'admin',
            'password' => null,
        ]);

        TenantContext::set($tenant);
        try {
            $project = Project::query()->create([
                'owner_id' => $admin->id,
                'name' => '要删并撤稿的项目',
                'current_stage' => 0,
                'progress' => 0,
            ]);
            $channel = PublishingChannel::query()->create([
                'tenant_id' => $tenant->id,
                'name' => '客户 WordPress',
                'type' => PublishingChannel::TYPE_WORDPRESS_REST,
                'endpoint_url' => 'https://customer.example.test',
                'is_active' => true,
            ]);
            $article = Article::query()->create([
                'project_id' => $project->id,
                'author_id' => $admin->id,
                'title' => '已发到外站的文章',
                'body_markdown' => '# 正文',
                // approved 而不是 published：published 会被自动分发钩子抢先建一条 queued 记录
                'status' => 'approved',
                'source_type' => 'manual',
                'metadata' => [],
            ]);
            $publication = ArticlePublication::query()->create([
                'tenant_id' => $tenant->id,
                'article_id' => $article->id,
                'publishing_channel_id' => $channel->id,
                'status' => ArticlePublication::STATUS_PUBLISHED,
                'remote_id' => '4321',
                'remote_url' => 'https://customer.example.test/blog/post-1',
                'published_at' => now(),
            ]);
        } finally {
            TenantContext::forget();
        }

        return [$tenant, $admin, $project, $publication];
    }

    private function forTenant(Tenant $tenant): self
    {
        return $this
            ->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }
}
