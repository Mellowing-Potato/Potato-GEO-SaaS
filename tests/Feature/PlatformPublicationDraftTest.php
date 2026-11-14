<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ManualPublication;
use App\Models\ManualPublicationAccount;
use App\Models\ManualPublicationPersona;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Publishing\ManualPublicationService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformPublicationDraftTest extends TestCase
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

    public function test_wechat_prepares_rich_text_with_links_and_images_without_publishing(): void
    {
        [$tenant, $user, $article, $account, $publication] = $this->fixture('wx-prepare');
        $prepared = $this->prepare($tenant, $publication)->assertOk()->json('data');
        $this->assertSame('微信公众号', $prepared['platform_label']);
        $this->assertSame('已审核文章', $prepared['title']);
        $this->assertStringContainsString('<strong', $prepared['html']);
        $this->assertStringNotContainsString('<h1', $prepared['html']);
        $this->assertStringContainsString('https://reference.example.test/guide', $prepared['plain_text']);
        $this->assertStringContainsString('【图片：示例图，请在平台补充】', $prepared['plain_text']);
        $this->assertStringContainsString('src="https://images.example.test/example.png"', $prepared['html']);
        $this->assertSame(1, $prepared['image_count']);
        $this->assertFalse($prepared['automatic_remote_save']);
        $this->assertSame('https://mp.weixin.qq.com/', $prepared['editor_url']);
        $this->assertSame($account->account_name, $prepared['account_name']);
        $this->assertSame('in_progress', $publication->fresh()->status);
        $this->assertNull($publication->fresh()->draft_receipt);
        $this->assertNull($publication->fresh()->completion_url);
        $this->assertSame('approved', $article->fresh()->status);
    }

    public function test_weibo_and_xiaohongshu_use_plain_text_and_keep_source_urls(): void
    {
        foreach (['weibo', 'xiaohongshu'] as $platform) {
            [$tenant, , , , $publication] = $this->fixture('plain-'.$platform, $platform);
            $prepared = $this->prepare($tenant, $publication)->assertOk()->json('data');
            if ($platform === 'weibo') $this->assertStringContainsString('<strong', $prepared['html']);
            else $this->assertNull($prepared['html']);
            $this->assertStringContainsString('加粗正文', $prepared['plain_text']);
            $this->assertStringContainsString('参考（https://reference.example.test/guide）', $prepared['plain_text']);
            $this->assertStringNotContainsString('**', $prepared['plain_text']);
            $this->assertSame($platform, $prepared['platform']);
            if ($platform === 'weibo') {
                $this->assertSame('https://card.weibo.com/article/v5/editor#/draft', $prepared['editor_url']);
            }
        }
    }

    public function test_manual_confirmation_never_becomes_an_automatic_save_or_publication(): void
    {
        [$tenant, $user, $article, , $publication] = $this->fixture('wx-confirm');
        $prepared = $this->prepare($tenant, $publication)->assertOk()->json('data');
        $saved = $this->confirm($tenant, $publication, $prepared)->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.draft_receipt.method', 'manual_confirmation')
            ->assertJsonPath('data.draft_receipt.platform', 'wechat')
            ->assertJsonPath('data.draft_receipt.confirmed_by', $user->id)
            ->assertJsonPath('data.draft_receipt.draft_label', '草稿箱：已审核文章')
            ->assertJsonPath('data.revision', 4);
        $this->assertNotNull($saved->json('data.draft_receipt.confirmed_at'));
        $this->assertNull($saved->json('data.draft_synced_at'));
        $this->assertNull($saved->json('data.draft_url'));
        $this->assertNull($saved->json('data.completion_url'));
        $this->assertNull($saved->json('data.completed_at'));
        $this->assertSame('approved', $article->fresh()->status);
        $this->assertDatabaseHas('manual_publication_transitions', [
            'manual_publication_id' => $publication->id,
            'changed_by_user_id' => $user->id,
            'from_status' => 'in_progress',
            'to_status' => 'in_progress',
        ]);
        $this->api($tenant)->getJson('/api/v1/manual-publications/'.$publication->id)
            ->assertOk()->assertJsonPath('data.article.version', 1);
        $this->confirm($tenant, $publication, $prepared)->assertConflict();
    }

    public function test_ready_can_prepare_but_only_in_progress_can_confirm(): void
    {
        [$tenant, , , , $publication] = $this->fixture('wx-ready', 'wechat', 'ready');
        $prepared = $this->prepare($tenant, $publication)->assertOk()->json('data');
        $this->confirm($tenant, $publication, $prepared)->assertUnprocessable();
        $publication->update(['status' => 'draft']);
        $this->prepare($tenant, $publication)->assertUnprocessable();
    }

    public function test_reconfirmation_preserves_previous_audit_evidence(): void
    {
        [$tenant, , , , $publication] = $this->fixture('wx-history');
        $prepared = $this->prepare($tenant, $publication)->assertOk()->json('data');
        $this->confirm($tenant, $publication, $prepared)->assertOk();
        $publication->refresh();
        $prepared = $this->prepare($tenant, $publication)->assertOk()->json('data');
        $this->confirm($tenant, $publication, $prepared, ['draft_label' => '再次核对的草稿位置'])->assertOk();
        $notes = $publication->transitions()->where('from_status', 'in_progress')->pluck('result_note');
        $this->assertCount(2, $notes);
        $this->assertStringContainsString('草稿箱：已审核文章', $notes[0]);
        $this->assertStringContainsString('再次核对的草稿位置', $notes[1]);
        $this->assertSame('in_progress', $publication->fresh()->status);
    }

    public function test_receipt_migration_is_reversible_without_changing_existing_publication_state(): void
    {
        [, , , , $publication] = $this->fixture('wx-migration');
        $migration = require database_path('migrations/2026_10_25_220000_add_platform_draft_receipt_to_manual_publications.php');
        $this->assertTrue(Schema::hasColumn('manual_publications', 'draft_receipt'));
        $migration->down();
        $this->assertFalse(Schema::hasColumn('manual_publications', 'draft_receipt'));
        $this->assertSame('in_progress', $publication->fresh()->status);
        $migration->up();
        $this->assertTrue(Schema::hasColumn('manual_publications', 'draft_receipt'));
        $this->assertNull($publication->fresh()->draft_receipt);
    }

    public function test_expired_tampered_and_unconfirmed_receipts_are_rejected(): void
    {
        [$tenant, , , , $publication] = $this->fixture('wx-invalid');
        $prepared = $this->prepare($tenant, $publication)->assertOk()->json('data');
        $this->confirm($tenant, $publication, $prepared, ['saved' => false])->assertUnprocessable();
        $this->confirm($tenant, $publication, $prepared, ['account_checked' => false])->assertUnprocessable();
        $this->confirm($tenant, $publication, $prepared, ['content_checked' => false])->assertUnprocessable();
        $this->confirm($tenant, $publication, $prepared, ['preparation_token' => 'tampered'])->assertUnprocessable();
        $this->travel(16)->minutes();
        $this->confirm($tenant, $publication, $prepared)->assertUnprocessable();
        $this->assertNull($publication->fresh()->draft_receipt);
    }

    public function test_login_urls_and_credentials_are_not_saved_as_draft_references(): void
    {
        [$tenant, , , , $publication] = $this->fixture('wx-reference');
        $prepared = $this->prepare($tenant, $publication)->assertOk()->json('data');
        foreach (['https://mp.weixin.qq.com/cgi-bin/appmsg?token=secret', 'token=secret', 'access_token=secret', ' '] as $label) {
            $this->confirm($tenant, $publication, $prepared, ['draft_label' => $label])->assertUnprocessable();
        }
        $this->assertNull($publication->fresh()->draft_receipt);
    }

    public function test_review_version_and_account_changes_invalidate_preparation(): void
    {
        [$tenant, , $article, $account, $publication] = $this->fixture('wx-stale');
        $prepared = $this->prepare($tenant, $publication)->assertOk()->json('data');
        $article->update(['version' => 2]);
        $this->prepare($tenant, $publication)->assertUnprocessable();
        $this->confirm($tenant, $publication, $prepared)->assertUnprocessable();
        $article->update(['version' => 1, 'status' => 'editing']);
        $this->prepare($tenant, $publication)->assertUnprocessable();
        $article->update(['status' => 'approved']);
        $account->update(['is_active' => false]);
        $this->prepare($tenant, $publication)->assertUnprocessable();
        $this->confirm($tenant, $publication, $prepared)->assertUnprocessable();
    }

    public function test_risk_block_and_revision_change_stop_preparation_and_confirmation(): void
    {
        [$tenant, , , , $publication] = $this->fixture('wx-risk');
        $prepared = $this->prepare($tenant, $publication)->assertOk()->json('data');
        $publication->update(['risk_status' => 'blocked']);
        $this->prepare($tenant, $publication)->assertUnprocessable();
        $this->confirm($tenant, $publication, $prepared)->assertUnprocessable();
        $publication->update(['risk_status' => 'clean', 'revision' => 4]);
        $this->api($tenant)->postJson('/api/v1/manual-publications/'.$publication->id.'/draft-preparation', ['revision' => 3])->assertConflict();
        $this->confirm($tenant, $publication, $prepared)->assertConflict();
    }

    public function test_changed_platform_identity_invalidates_an_existing_preparation_token(): void
    {
        [$tenant, , , $account, $publication] = $this->fixture('wx-identity');
        $prepared = $this->prepare($tenant, $publication)->assertOk()->json('data');
        $account->update(['account_name' => '不同账号名称']);
        $this->confirm($tenant, $publication, $prepared)->assertConflict();
        $this->assertNull($publication->fresh()->draft_receipt);
    }

    public function test_cross_tenant_requests_and_other_actor_tokens_are_rejected(): void
    {
        [$tenant, $user, , , $publication] = $this->fixture('wx-owner');
        $prepared = $this->prepare($tenant, $publication)->assertOk()->json('data');
        [$other, $otherUser] = $this->fixture('wx-foreign');
        $this->prepare($other, $publication)->assertNotFound();
        $this->confirm($other, $publication, $prepared)->assertNotFound();
        $editor = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'editor']);
        Sanctum::actingAs($editor, ['*']);
        $this->confirm($tenant, $publication, $prepared)->assertConflict();
        $member = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'member']);
        Sanctum::actingAs($member, ['*']);
        $this->prepare($tenant, $publication)->assertForbidden();
        $this->confirm($tenant, $publication, $prepared)->assertForbidden();
        $this->assertNull($publication->fresh()->draft_receipt);
    }

    public function test_another_publication_cannot_reuse_a_preparation_token(): void
    {
        [$tenant, $user, $article, $account, $publication] = $this->fixture('wx-swap');
        $prepared = $this->prepare($tenant, $publication)->assertOk()->json('data');
        $copy = $publication->replicate();
        $copy->save();
        $this->confirm($tenant, $copy, $prepared)->assertConflict();
    }

    public function test_wechat_console_is_not_a_public_completion_url(): void
    {
        [$tenant, , , , $publication] = $this->fixture('wx-public');
        foreach (['https://mp.weixin.qq.com/cgi-bin/home?t=home/index&token=123', 'https://mp.weixin.qq.com/cgi-bin/appmsg?action=edit&appmsgid=123'] as $url) {
            $this->api($tenant)->postJson('/api/v1/manual-publications/'.$publication->id.'/transition', [
                'target_status' => 'completed', 'completion_url' => $url, 'revision' => 3,
            ])->assertUnprocessable();
        }
        $this->api($tenant)->postJson('/api/v1/manual-publications/'.$publication->id.'/transition', [
            'target_status' => 'completed', 'completion_url' => 'https://mp.weixin.qq.com/s/Example_Public_Article', 'revision' => 3,
        ])->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_new_publications_bind_the_version_read_from_the_article_across_builtin_platforms(): void
    {
        foreach (['wechat', 'zhihu', 'xiaohongshu', 'weibo', 'douyin', 'bilibili', 'reddit', 'x', 'linkedin'] as $platform) {
            [$tenant, $user, $article, $account] = $this->fixture('article-source-'.$platform, $platform);
            $source = $this->api($tenant)->getJson('/api/v1/articles/'.$article->id)->assertOk()->json();
            $this->api($tenant)->postJson('/api/v1/manual-publications', [
                'type' => 'post', 'article_id' => $source['id'], 'article_version' => $source['version'],
                'persona_id' => $account->persona_id, 'account_id' => $account->id, 'platform' => $platform,
                'assigned_user_id' => $user->id, 'content' => $source['body_markdown'],
            ])->assertCreated()->assertJsonPath('data.source_snapshot.article_version', 1)
                ->assertJsonPath('data.content', $source['body_markdown']);
        }
    }

    public function test_article_changes_between_reading_and_creation_reject_stale_content(): void
    {
        [$tenant, $user, $article, $account] = $this->fixture('article-source-stale');
        $payload = [
            'type' => 'post', 'article_id' => $article->id, 'article_version' => 1,
            'persona_id' => $account->persona_id, 'account_id' => $account->id, 'platform' => 'wechat',
            'assigned_user_id' => $user->id, 'content' => $article->body_markdown,
        ];
        $article->update(['version' => 2]);
        $this->api($tenant)->postJson('/api/v1/manual-publications', $payload)->assertUnprocessable();
        $this->assertSame(1, ManualPublication::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $payload['article_version'] = 2;
        $article->update(['status' => 'editing']);
        $this->api($tenant)->postJson('/api/v1/manual-publications', $payload)->assertUnprocessable();
        $this->assertSame(1, ManualPublication::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_channel_title_is_bound_to_the_receipt_without_rewriting_the_article(): void
    {
        foreach (['wechat', 'xiaohongshu', 'weibo'] as $platform) {
            [$tenant, , $article, , $publication] = $this->fixture('title-'.$platform, $platform);
            $before = $publication->only(['content', 'content_fingerprint', 'source_snapshot']);
            $prepared = $this->api($tenant)->postJson('/api/v1/manual-publications/'.$publication->id.'/draft-preparation', [
                'revision' => $publication->revision, 'publication_title' => '本渠道的人工发布标题',
            ])->assertOk()->assertJsonPath('data.title', '本渠道的人工发布标题')
                ->assertJsonPath('data.source_title', '已审核文章')->json('data');
            $this->assertStringNotContainsString('已审核文章', $prepared['plain_text']);
            $this->confirm($tenant, $publication, $prepared)->assertOk()
                ->assertJsonPath('data.draft_receipt.publication_title', '本渠道的人工发布标题');
            $this->assertSame($before, $publication->fresh()->only(array_keys($before)));
            $this->assertSame('已审核文章', $article->fresh()->title);
            $this->assertSame(1, $article->fresh()->version);
            $this->assertStringContainsString('发布标题：本渠道的人工发布标题', $publication->transitions()->latest('id')->value('result_note'));
        }
    }

    public function test_weibo_titles_are_validated_without_truncation_or_confirmation_side_effects(): void
    {
        [$tenant, , , , $publication] = $this->fixture('weibo-title-limits', 'weibo');
        foreach ([str_repeat('字', 33), str_repeat('😀', 17), '', "标题\n第二行", 123] as $title) {
            $this->api($tenant)->postJson('/api/v1/manual-publications/'.$publication->id.'/draft-preparation', [
                'revision' => $publication->revision, 'publication_title' => $title,
            ])->assertUnprocessable();
        }
        $title = str_repeat('字', 32);
        $this->api($tenant)->postJson('/api/v1/manual-publications/'.$publication->id.'/draft-preparation', [
            'revision' => $publication->revision, 'publication_title' => $title,
        ])->assertOk()->assertJsonPath('data.title', $title);
        $this->assertNull($publication->fresh()->draft_receipt);
        $this->assertSame(3, $publication->fresh()->revision);
        $snapshot = $publication->source_snapshot;
        $snapshot['title'] = str_repeat('字', 45);
        $publication->update(['source_snapshot' => $snapshot]);
        $this->prepare($tenant, $publication)->assertUnprocessable()->assertJsonPath('message', '微博头条文章标题最多 32 个字符，请在发布标题中缩短后重新准备；原文章不会被修改。');
    }

    public function test_xiaohongshu_long_form_limits_and_browser_local_storage_are_explicit(): void
    {
        [$tenant, , $article, , $publication] = $this->fixture('xhs-long-rules', 'xiaohongshu');
        $this->api($tenant)->postJson('/api/v1/manual-publications/'.$publication->id.'/draft-preparation', [
            'revision' => $publication->revision, 'publication_title' => str_repeat('字', 65),
        ])->assertUnprocessable()->assertJsonPath('message', '小红书长文标题最多 64 个字符，请在发布标题中缩短后重新准备；原文章不会被修改。');
        $title = str_repeat('字', 64);
        $prepared = $this->api($tenant)->postJson('/api/v1/manual-publications/'.$publication->id.'/draft-preparation', [
            'revision' => $publication->revision, 'publication_title' => $title,
        ])->assertOk()->assertJsonPath('data.title', $title)
            ->assertJsonPath('data.draft_storage_scope', 'browser_local')
            ->assertJsonPath('data.editor_url', 'https://creator.xiaohongshu.com/publish/publish?from=menu&target=article')->json('data');
        $this->assertStringContainsString('当前浏览器', $prepared['instructions'][1]);
        $this->confirm($tenant, $publication, $prepared)->assertOk()
            ->assertJsonPath('data.draft_receipt.storage_scope', 'browser_local')
            ->assertJsonPath('data.status', 'in_progress');
        $this->assertSame('已审核文章', $article->fresh()->title);
    }

    public function test_wechat_title_limit_matches_the_real_editor_without_modifying_the_original(): void
    {
        [$tenant, , $article, , $publication] = $this->fixture('wechat-title-limits');
        $this->api($tenant)->postJson('/api/v1/manual-publications/'.$publication->id.'/draft-preparation', [
            'revision' => $publication->revision, 'publication_title' => str_repeat('字', 65),
        ])->assertUnprocessable()->assertJsonPath('message', '微信公众号标题最多 64 个字符，请在发布标题中缩短后重新准备；原文章不会被修改。');
        $title = str_repeat('字', 64);
        $this->api($tenant)->postJson('/api/v1/manual-publications/'.$publication->id.'/draft-preparation', [
            'revision' => $publication->revision, 'publication_title' => $title,
        ])->assertOk()->assertJsonPath('data.title', $title);
        $this->assertSame('已审核文章', $article->fresh()->title);
        $this->assertNull($publication->fresh()->draft_receipt);
    }

    private function fixture(string $name, string $platform = 'wechat', string $status = 'in_progress'): array
    {
        $tenant = Tenant::create(['subdomain' => $name, 'name' => $name, 'plan' => 'points', 'status' => 'active']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        TenantContext::set($tenant);
        $project = Project::create(['owner_id' => $user->id, 'name' => $name]);
        $content = "# 已审核文章\n\n**加粗正文**\n\n[参考](https://reference.example.test/guide)\n\n![示例图](https://images.example.test/example.png)";
        $article = Article::create(['project_id' => $project->id, 'author_id' => $user->id, 'title' => '已审核文章', 'body_markdown' => $content, 'status' => 'approved', 'version' => 1]);
        $persona = ManualPublicationPersona::create(['name' => $name, 'created_by_user_id' => $user->id]);
        $account = ManualPublicationAccount::create(['persona_id' => $persona->id, 'platform' => $platform, 'account_name' => '账号'.$name, 'created_by_user_id' => $user->id]);
        $publication = app(ManualPublicationService::class)->create([
            'type' => 'post', 'article_id' => $article->id, 'persona_id' => $persona->id,
            'account_id' => $account->id, 'assigned_user_id' => $user->id, 'platform' => $platform, 'content' => $content,
        ], $user);
        $publication->update(['status' => $status, 'revision' => $status === 'ready' ? 2 : 3]);
        TenantContext::forget();
        Sanctum::actingAs($user, ['*']);

        return [$tenant, $user, $article, $account, $publication];
    }

    private function api(Tenant $tenant): self
    {
        return $this->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])->withHeader('X-Tenant', $tenant->subdomain);
    }

    private function prepare(Tenant $tenant, ManualPublication $publication)
    {
        return $this->api($tenant)->postJson('/api/v1/manual-publications/'.$publication->id.'/draft-preparation', ['revision' => $publication->revision]);
    }

    private function confirm(Tenant $tenant, ManualPublication $publication, array $prepared, array $overrides = [])
    {
        return $this->api($tenant)->postJson('/api/v1/manual-publications/'.$publication->id.'/platform-draft', array_merge([
            'revision' => $publication->revision, 'preparation_token' => $prepared['preparation_token'],
            'draft_label' => '草稿箱：已审核文章', 'account_checked' => true, 'content_checked' => true, 'saved' => true,
        ], $overrides));
    }
}
