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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WeiboBrowserDraftTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $actor;

    private Article $article;

    private ManualPublicationAccount $account;

    private ManualPublication $publication;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.tenant_domain' => 'geo.example.test']);
        $this->tenant = Tenant::create(['subdomain' => 'weibo-drafts', 'name' => '微博草稿', 'plan' => 'points', 'status' => 'active']);
        TenantContext::set($this->tenant);
        $this->actor = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'admin']);
        $project = Project::create(['owner_id' => $this->actor->id, 'name' => '示例项目']);
        $content = "# 原审核标题\n\n**完整正文**与[来源](https://example.test/reference)";
        $this->article = Article::create(['project_id' => $project->id, 'author_id' => $this->actor->id,
            'title' => '原审核标题', 'body_markdown' => $content, 'status' => 'approved', 'version' => 1]);
        $persona = ManualPublicationPersona::create(['name' => '测试人设', 'created_by_user_id' => $this->actor->id]);
        $this->account = ManualPublicationAccount::create(['persona_id' => $persona->id, 'platform' => 'weibo',
            'account_name' => '示例账号', 'profile_url' => 'https://weibo.com/u/1234567890', 'created_by_user_id' => $this->actor->id]);
        $this->publication = app(ManualPublicationService::class)->create(['type' => 'post', 'article_id' => $this->article->id,
            'persona_id' => $persona->id, 'account_id' => $this->account->id, 'assigned_user_id' => $this->actor->id,
            'platform' => 'weibo', 'content' => $content], $this->actor);
        $this->publication->update(['status' => 'in_progress', 'revision' => 3]);
        TenantContext::forget();
        Sanctum::actingAs($this->actor, ['*']);
        $this->withServerVariables(['HTTP_HOST' => 'weibo-drafts.geo.example.test'])->withHeader('X-Tenant', 'weibo-drafts');
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    private function path(string $suffix = ''): string
    {
        return '/api/v1/manual-publications/'.$this->publication->id.'/weibo-draft'.$suffix;
    }

    private function start(array $override = [])
    {
        return $this->postJson($this->path('/start'), $override + ['revision' => 3, 'publication_title' => '本渠道短标题']);
    }

    private function stage(array $job, string $stage, array $extra = [])
    {
        return $this->postJson($this->path('/progress'), $extra + ['delivery_id' => $job['delivery']['id'], 'token' => $job['request_token'],
            'stage' => $stage, 'uid' => '1234567890', 'draft_id' => '987654']);
    }

    private function sent(): array
    {
        $job = $this->start()->assertOk()->json('data');
        foreach (['creating', 'created', 'saving'] as $stage) {
            $this->stage($job, $stage)->assertOk();
        }
        $this->stage($job, 'verifying', ['images' => []])->assertOk();

        return $job;
    }

    private function proof(array $job): array
    {
        return ['title' => $job['payload']['title'], 'text_hash' => $job['payload']['text_hash'],
            'links' => $job['payload']['links'], 'image_count' => 0, 'helper_version' => '0.3.1'];
    }

    public function test_preparation_preserves_original_manual_receipt_and_only_full_readback_counts_as_saved(): void
    {
        $old = ['method' => 'manual_confirmation', 'draft_label' => '以前的草稿'];
        $this->publication->forceFill(['draft_receipt' => $old])->save();
        $job = $this->sent();
        $this->assertStringContainsString('<strong', $job['payload']['html']);
        $this->assertStringNotContainsString('原审核标题', $job['payload']['html']);
        $this->stage($job, 'saved', $this->proof($job))->assertOk()->assertJsonPath('data.status', 'saved');
        $this->stage($job, 'saved', $this->proof($job))->assertOk();
        $p = $this->publication->fresh();
        $this->assertSame('in_progress', $p->status);
        $this->assertSame(3, $p->revision);
        $this->assertSame($old, $p->draft_receipt);
        $this->assertNull($p->completion_url);
        $this->assertNull($p->draft_url);
        $this->assertSame('原审核标题', $this->article->fresh()->title);
        $this->assertSame(1, $p->transitions()->where('result_note', 'like', '浏览器助手%')->count());
        $r = $this->getJson($this->path())->assertOk()->assertJsonPath('data.stale', false)->json('data.delivery');
        $this->assertArrayNotHasKey('payload', $r);
        $this->assertArrayNotHasKey('request_hash', $r);
        $this->assertSame('https://card.weibo.com/article/v5/editor#/draft/987654', $r['draft_url']);
    }

    public function test_duplicate_starts_invalidate_old_tokens_and_never_recreate_after_creation_begins(): void
    {
        $a = $this->start()->assertOk()->json('data');
        $b = $this->start()->assertOk()->json('data');
        $this->assertSame($a['delivery']['id'], $b['delivery']['id']);
        $this->stage($a, 'creating')->assertUnprocessable();
        $this->stage($b, 'creating')->assertOk();
        $this->start()->assertUnprocessable();
        $this->stage($b, 'created')->assertOk();
        $retry = $this->start()->assertOk()->json('data');
        $this->assertSame('verify', $retry['payload']['mode']);
        $this->assertSame('987654', $retry['payload']['draftId']);
        $this->stage($retry, 'creating')->assertUnprocessable();
    }

    public function test_unknown_create_blocks_retry_and_safe_prewrite_failure_can_restart(): void
    {
        $a = $this->start()->assertOk()->json('data');
        $this->stage($a, 'error', ['code' => 'wrong_account'])->assertOk()->assertJsonPath('data.status', 'failed');
        $b = $this->start()->assertOk()->json('data');
        $this->stage($b, 'creating')->assertOk();
        $this->stage($b, 'error', ['code' => 'connection_interrupted'])->assertOk()->assertJsonPath('data.status', 'unknown');
        $this->start()->assertUnprocessable();
    }

    public function test_wrong_id_account_text_links_images_or_version_cannot_attest_success(): void
    {
        $job = $this->sent();
        foreach ([['uid' => '9999999999'], ['draft_id' => '111'], ['title' => '另一个标题'], ['text_hash' => str_repeat('0', 64)],
            ['links' => []], ['image_count' => 1], ['helper_version' => '0.2.7']] as $bad) {
            $this->stage($job, 'saved', $bad + $this->proof($job))->assertStatus(in_array('9999999999', $bad, true) ? 409 : 422);
        }
        $this->getJson($this->path())->assertJsonPath('data.delivery.status', 'verifying');
        $this->assertNull($this->publication->fresh()->completion_url);
    }

    public function test_all_prewrite_stages_recheck_review_account_revision_and_expiry(): void
    {
        $job = $this->start()->assertOk()->json('data');
        $this->article->update(['version' => 2]);
        $this->stage($job, 'creating')->assertUnprocessable();
        $this->article->update(['version' => 1]);
        $this->account->update(['account_name' => '已改名']);
        $this->stage($job, 'creating')->assertConflict();
        $this->account->update(['account_name' => '示例账号', 'is_active' => false]);
        $this->stage($job, 'creating')->assertUnprocessable();
        $this->account->update(['is_active' => true]);
        $this->publication->update(['revision' => 4]);
        $this->stage($job, 'creating')->assertConflict();
        $this->publication->update(['revision' => 3]);
        $this->travel(11)->minutes();
        $this->stage($job, 'creating')->assertUnprocessable();
    }

    public function test_invalid_title_profile_platform_and_wrong_tenant_cannot_start(): void
    {
        $this->start(['publication_title' => str_repeat('字', 33)])->assertUnprocessable();
        $this->start(['revision' => 2])->assertConflict();
        $this->account->update(['profile_url' => 'https://weibo.com/nickname']);
        $this->start()->assertUnprocessable();
        $this->account->update(['profile_url' => 'https://weibo.com/u/1234567890']);
        $this->publication->update(['status' => 'ready']);
        $this->start()->assertUnprocessable();
        $other = Tenant::create(['subdomain' => 'weibo-other', 'name' => 'other', 'plan' => 'points', 'status' => 'active']);
        $otherUser = User::factory()->create(['tenant_id' => $other->id, 'role' => 'admin']);
        Sanctum::actingAs($otherUser, ['*']);
        $this->withServerVariables(['HTTP_HOST' => 'weibo-other.geo.example.test'])->withHeader('X-Tenant', 'weibo-other');
        $this->getJson($this->path())->assertNotFound();
        $this->start()->assertNotFound();
    }

    public function test_empty_link_lists_are_valid_and_incomplete_image_transfers_are_rejected(): void
    {
        $this->publication->update(['content' => "# 原审核标题\n\n纯文字"]);
        $job = $this->sent();
        $this->stage($job, 'saved', $this->proof($job))->assertOk();
    }
}
