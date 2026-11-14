<?php

namespace Tests\Feature;

use App\Contracts\Outbound\HostResolver;
use App\Contracts\Outbound\OutboundTransport;
use App\Jobs\SendWeChatDraftJob;
use App\Models\Article;
use App\Models\ManualPublication;
use App\Models\ManualPublicationAccount;
use App\Models\ManualPublicationPersona;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WeChatDraftDelivery;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Services\Outbound\SecureHttpFactory;
use App\Services\Publishing\ManualPublicationService;
use App\Services\Publishing\WeChatDraftDeliveryService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WeChatOfficialDraftTest extends TestCase
{
    use RefreshDatabase;

    private const APP_ID = 'wx1234567890abcdef';

    private const SECRET = 'deadbeefdeadbeefdeadbeefdeadbeef';

    private array $sent = [];

    private array $article = [];

    private mixed $createError = null;

    private ?array $remoteOverride = null;

    private ?array $tokenError = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.tenant_domain' => 'geo.example.test']);
        Storage::fake('local');
        $resolver = new class implements HostResolver
        {
            public function resolve(string $host): array
            {
                return $host === 'private.example.test' ? ['127.0.0.1'] : ['93.184.216.34'];
            }
        };
        $this->app->instance(HostResolver::class, $resolver);
        $this->app->instance(SafeOutboundHttpClient::class, new SafeOutboundHttpClient($resolver, $this->app->make(OutboundTransport::class), true));
        $this->app->make(SecureHttpFactory::class)->fake(function ($request) {
            $this->sent[] = $request->url();
            if (str_contains($request->url(), 'stable_token')) {
                return Http::response($this->tokenError ?? ['access_token' => 'test-fake-dc95f0672f444fa', 'expires_in' => 7200]);
            }
            if (str_contains($request->url(), 'material/add_material')) {
                return Http::response(['media_id' => 'unique-cover']);
            }
            if (str_contains($request->url(), 'media/uploadimg')) {
                return Http::response(['url' => 'https://mmbiz.qpic.cn/saved-image.png']);
            }
            if (str_contains($request->url(), 'draft/add')) {
                $this->article = json_decode($request->body(), true)['articles'][0];
                if ($this->createError instanceof \Throwable) {
                    throw $this->createError;
                }

                return Http::response($this->createError ?? ['media_id' => 'draft-id']);
            }
            if (str_contains($request->url(), 'draft/get')) {
                return Http::response(['news_item' => [$this->remoteOverride ?? $this->article]]);
            }
            if (str_contains($request->url(), 'draft/batchget')) {
                return Http::response(['item' => [['media_id' => 'reconciled-draft', 'content' => ['news_item' => [$this->article]]]]]);
            }
            if (str_contains($request->url(), 'image.example.test')) {
                return Http::response($this->png(), 200, ['Content-Type' => 'image/png']);
            }
            throw new \RuntimeException('Unexpected request');
        });
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    public function test_connection_validates_and_encrypts_without_returning_credentials(): void
    {
        [$tenant, , , $account] = $this->fixture();
        $response = $this->api($tenant)->postJson($this->connectionUrl($account), $this->credentials());
        $this->assertSame(200, $response->status(), $response->getContent());
        $response->assertOk()
            ->assertJsonPath('data.app_id', self::APP_ID)->assertJsonMissingPath('data.app_secret');
        $this->assertStringNotContainsString(self::SECRET, $response->getContent());
        $stored = DB::table('wechat_official_account_connections')->where('account_id', $account->id)->value('app_secret');
        $this->assertStringNotContainsString(self::SECRET, $stored);
        $this->api($tenant)->getJson($this->connectionUrl($account))->assertOk()->assertJsonMissingPath('data.app_secret');
        $this->assertStringContainsString('stable_token', $this->sent[0]);
        $this->assertStringNotContainsString(self::SECRET, implode('', $this->sent));
    }

    public function test_credentials_require_admin_and_explicit_account_check(): void
    {
        [$tenant, $actor, , $account] = $this->fixture();
        $this->api($tenant)->postJson($this->connectionUrl($account), $this->credentials() + ['unused' => true])->assertOk();
        $data = $this->credentials();
        $data['account_checked'] = false;
        $this->api($tenant)->postJson($this->connectionUrl($account), $data)->assertUnprocessable();
        $actor->update(['role' => 'editor']);
        $this->api($tenant)->postJson($this->connectionUrl($account), $this->credentials())->assertForbidden();
        $this->api($tenant)->getJson($this->connectionUrl($account))->assertForbidden();
    }

    public function test_ip_errors_are_actionable_and_raw_secret_or_token_is_never_exposed(): void
    {
        [$tenant, , , $account] = $this->fixture();
        $this->tokenError = ['errcode' => 40164, 'errmsg' => 'invalid ip 93.184.216.34, not in whitelist token=SECRET appsecret='.self::SECRET];
        $response = $this->api($tenant)->postJson($this->connectionUrl($account), $this->credentials())->assertUnprocessable();
        $this->assertStringContainsString('93.184.216.34', $response->json('message'));
        $this->assertStringNotContainsString(self::SECRET, $response->getContent());
        $this->assertStringNotContainsString('token=', $response->getContent());
        $this->assertDatabaseCount('wechat_official_account_connections', 0);
    }

    public function test_draft_creation_reads_back_title_content_and_cover_without_public_publish(): void
    {
        [$tenant, , $source, $account, $publication] = $this->fixture();
        $this->connect($tenant, $account);
        $before = $publication->only(['content', 'source_snapshot', 'content_fingerprint', 'revision']);
        $this->send($tenant, $publication)->assertAccepted()->assertJsonPath('data.status', 'saved')
            ->assertJsonPath('data.media_id', 'draft-id')->assertJsonMissingPath('data.payload')->assertJsonMissingPath('data.cover_path');
        $this->assertSame($before, $publication->fresh()->only(array_keys($before)));
        $this->assertSame('in_progress', $publication->fresh()->status);
        $this->assertNull($publication->fresh()->completion_url);
        $this->assertNull($publication->fresh()->draft_receipt);
        $this->assertSame('approved', $source->fresh()->status);
        $this->assertSame('本次渠道标题', $this->article['title']);
        $this->assertSame('unique-cover', $this->article['thumb_media_id']);
        $this->assertStringContainsString('<strong', $this->article['content']);
        $this->assertStringNotContainsString('<h1', $this->article['content']);
        $this->assertStringNotContainsString('freepublish', implode('', $this->sent));
        $this->assertStringNotContainsString('mass/send', implode('', $this->sent));
        $this->assertCount(1, $publication->transitions()->where('result_note', 'like', '%微信官方接口%')->get());
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_second_click_and_duplicate_job_never_create_second_draft(): void
    {
        [$tenant, , , $account, $publication] = $this->fixture();
        $this->connect($tenant, $account);
        $this->send($tenant, $publication)->assertAccepted();
        $this->send($tenant, $publication)->assertUnprocessable();
        $delivery = WeChatDraftDelivery::withoutGlobalScopes()->firstOrFail();
        (new SendWeChatDraftJob($delivery->id, $tenant->id))->handle(app(WeChatDraftDeliveryService::class));
        $this->assertSame(1, $this->createCount());
        $this->api($tenant)->postJson('/api/v1/manual-publications/'.$publication->id.'/wechat-draft/check')->assertOk();
        $this->assertCount(1, $publication->transitions()->where('result_note', 'like', '%微信官方接口%')->get());
    }

    public function test_timeout_stays_unknown_blocks_resend_and_can_reconcile_by_unique_cover(): void
    {
        [$tenant, , , $account, $publication] = $this->fixture();
        $this->connect($tenant, $account);
        $this->createError = new ConnectionException('token=leak app_secret='.self::SECRET);
        $this->send($tenant, $publication)->assertAccepted()->assertJsonPath('data.status', 'unknown');
        $this->api($tenant)->postJson('/api/v1/manual-publications/'.$publication->id.'/wechat-draft/retry')->assertUnprocessable();
        $this->api($tenant)->postJson('/api/v1/manual-publications/'.$publication->id.'/wechat-draft/check')->assertOk()
            ->assertJsonPath('data.status', 'saved')->assertJsonPath('data.media_id', 'reconciled-draft');
        $this->assertSame(1, $this->createCount());
    }

    public function test_definite_rejection_can_retry_same_snapshot_once(): void
    {
        [$tenant, , , $account, $publication] = $this->fixture();
        $this->connect($tenant, $account);
        $this->createError = ['errcode' => 48001, 'errmsg' => 'secret-message'];
        $this->send($tenant, $publication)->assertAccepted()->assertJsonPath('data.status', 'failed');
        $this->createError = null;
        $this->api($tenant)->postJson('/api/v1/manual-publications/'.$publication->id.'/wechat-draft/retry')->assertAccepted()->assertJsonPath('data.status', 'saved');
        $this->assertSame(2, $this->createCount());
    }

    public function test_remote_content_or_link_mismatch_never_claims_verified(): void
    {
        [$tenant, , , $account, $publication] = $this->fixture();
        $this->connect($tenant, $account);
        $this->remoteOverride = ['title' => '本次渠道标题', 'content' => '<p>丢失正文</p>', 'thumb_media_id' => 'unique-cover'];
        $this->send($tenant, $publication)->assertAccepted()->assertJsonPath('data.status', 'unverified');
        $this->assertSame(1, $this->createCount());
        $this->assertCount(0, $publication->transitions()->where('result_note', 'like', '%微信官方接口%')->get());
        $this->remoteOverride = $this->article;
        $this->remoteOverride['content'] = str_replace('https://source.example.test/', 'https://changed.example.test/', $this->article['content']);
        $this->api($tenant)->postJson('/api/v1/manual-publications/'.$publication->id.'/wechat-draft/check')->assertOk()->assertJsonPath('data.status', 'unverified');
    }

    public function test_worker_revalidates_review_and_restores_previous_tenant(): void
    {
        [$tenant, , $source, $account, $publication] = $this->fixture();
        $this->connect($tenant, $account);
        Queue::fake();
        $this->send($tenant, $publication)->assertAccepted()->assertJsonPath('data.status', 'queued');
        $source->update(['version' => 2]);
        $delivery = WeChatDraftDelivery::withoutGlobalScopes()->firstOrFail();
        TenantContext::set($tenant);
        (new SendWeChatDraftJob($delivery->id, $tenant->id))->handle(app(WeChatDraftDeliveryService::class));
        $this->assertSame($tenant->id, TenantContext::id());
        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertSame(0, $this->createCount());
    }

    public function test_images_are_transferred_before_creating_draft_and_private_targets_fail_closed(): void
    {
        [$tenant, , , $account, $publication] = $this->fixture('images', 'https://image.example.test/picture.png');
        $this->connect($tenant, $account);
        $this->send($tenant, $publication)->assertAccepted()->assertJsonPath('data.status', 'saved');
        $this->assertStringContainsString('https://mmbiz.qpic.cn/saved-image.png', $this->article['content']);
        $this->assertStringNotContainsString('https://image.example.test/', $this->article['content']);
        [$tenant, , , $account, $publication] = $this->fixture('private-image', 'https://private.example.test/picture.png');
        $this->connect($tenant, $account);
        $this->send($tenant, $publication)->assertAccepted()->assertJsonPath('data.status', 'failed');
        $this->assertSame(1, $this->createCount());
        $this->assertFalse((bool) array_filter($this->sent, fn ($url) => str_contains($url, 'private.example.test')));
    }

    public function test_stale_revision_limits_missing_checks_and_existing_receipt_block_creation(): void
    {
        [$tenant, , , $account, $publication] = $this->fixture();
        $this->connect($tenant, $account);
        $this->send($tenant, $publication, ['revision' => 2])->assertConflict();
        $this->send($tenant, $publication, ['publication_title' => str_repeat('字', 33)])->assertUnprocessable();
        $this->send($tenant, $publication, ['create_new_draft' => false])->assertUnprocessable();
        $this->send($tenant, $publication, ['account_checked' => false])->assertUnprocessable();
        $publication->forceFill(['draft_receipt' => ['method' => 'manual_confirmation', 'draft_label' => '已有真实草稿']])->save();
        $this->send($tenant, $publication)->assertUnprocessable();
        $this->assertSame(0, $this->createCount());
        $this->assertDatabaseCount('wechat_draft_deliveries', 0);
    }

    public function test_other_tenant_cannot_read_connect_send_or_reconcile(): void
    {
        [$first, , , $account, $publication] = $this->fixture('first');
        $this->connect($first, $account);
        [$other] = $this->fixture('other');
        $this->api($other)->getJson($this->connectionUrl($account))->assertNotFound();
        $this->api($other)->postJson($this->connectionUrl($account), $this->credentials())->assertNotFound();
        $this->send($other, $publication)->assertNotFound();
        $this->api($other)->getJson('/api/v1/manual-publications/'.$publication->id.'/wechat-draft')->assertNotFound();
        $this->api($other)->postJson('/api/v1/manual-publications/'.$publication->id.'/wechat-draft/check')->assertNotFound();
    }

    public function test_changing_app_id_is_blocked_and_rotation_keeps_secret_hidden(): void
    {
        [$tenant, , , $account] = $this->fixture();
        $this->connect($tenant, $account);
        $data = $this->credentials();
        $data['app_id'] = 'wxabcdef1234567890';
        $this->api($tenant)->postJson($this->connectionUrl($account), $data)->assertUnprocessable();
        $this->api($tenant)->postJson($this->connectionUrl($account), $this->credentials())->assertOk()->assertJsonPath('data.revision', 2);
    }

    public function test_missing_unique_match_after_timeout_never_retries_creation(): void
    {
        [$tenant, , , $account, $publication] = $this->fixture();
        $this->connect($tenant, $account);
        $this->createError = new ConnectionException('timeout');
        $this->send($tenant, $publication)->assertAccepted()->assertJsonPath('data.status', 'unknown');
        $this->article['thumb_media_id'] = 'different-cover';
        $this->api($tenant)->postJson('/api/v1/manual-publications/'.$publication->id.'/wechat-draft/check')->assertOk()->assertJsonPath('data.status', 'unknown');
        $this->assertSame(1, $this->createCount());
    }

    public function test_known_media_id_survives_verification_failure_and_job_interruption(): void
    {
        [$tenant, , , $account, $publication] = $this->fixture();
        $this->connect($tenant, $account);
        $this->remoteOverride = ['title' => 'changed', 'content' => '', 'thumb_media_id' => 'unique-cover'];
        $this->send($tenant, $publication)->assertAccepted()->assertJsonPath('data.status', 'unverified');
        $delivery = WeChatDraftDelivery::withoutGlobalScopes()->firstOrFail();
        (new SendWeChatDraftJob($delivery->id, $tenant->id))->failed(new \RuntimeException('private error'));
        $this->assertSame('draft-id', $delivery->fresh()->media_id);
        $this->assertSame('unverified', $delivery->fresh()->status);
        $this->api($tenant)->postJson('/api/v1/manual-publications/'.$publication->id.'/wechat-draft/retry')->assertUnprocessable();
    }

    public function test_worker_with_wrong_tenant_never_reads_another_delivery(): void
    {
        [$tenant, , , $account, $publication] = $this->fixture('first-worker');
        $this->connect($tenant, $account);
        Queue::fake();
        $this->send($tenant, $publication)->assertAccepted();
        $delivery = WeChatDraftDelivery::withoutGlobalScopes()->firstOrFail();
        [$other] = $this->fixture('other-worker');
        (new SendWeChatDraftJob($delivery->id, $other->id))->handle(app(WeChatDraftDeliveryService::class));
        $this->assertSame('queued', WeChatDraftDelivery::withoutGlobalScopes()->findOrFail($delivery->id)->status);
        $this->assertSame(0, $this->createCount());
    }

    public function test_job_failure_before_and_after_write_has_distinct_safe_retry_policy(): void
    {
        [$tenant, , , $account, $publication] = $this->fixture();
        $this->connect($tenant, $account);
        Queue::fake();
        $this->send($tenant, $publication)->assertAccepted();
        $delivery = WeChatDraftDelivery::withoutGlobalScopes()->firstOrFail();
        $job = new SendWeChatDraftJob($delivery->id, $tenant->id);
        $job->failed(new \RuntimeException('private error'));
        $this->assertSame('failed', WeChatDraftDelivery::withoutGlobalScopes()->findOrFail($delivery->id)->status);
        TenantContext::set($tenant);
        $delivery->update(['status' => 'sending']);
        $job->failed(new \RuntimeException('private error'));
        $this->assertSame('unknown', $delivery->fresh()->status);
        $this->assertStringNotContainsString('private error', $delivery->fresh()->last_error);
    }

    private function fixture(string $name = 'wechat-direct', ?string $image = null): array
    {
        $tenant = Tenant::create(['subdomain' => $name, 'name' => $name, 'plan' => 'points', 'status' => 'active']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        TenantContext::set($tenant);
        $project = Project::create(['owner_id' => $user->id, 'name' => $name]);
        $content = "# 已审核文章\n\n**完整正文**\n\n[资料来源](https://source.example.test/)".($image ? "\n\n![插图]($image)" : '');
        $article = Article::create(['project_id' => $project->id, 'author_id' => $user->id, 'title' => '已审核文章', 'body_markdown' => $content, 'status' => 'approved', 'version' => 1]);
        $persona = ManualPublicationPersona::create(['name' => $name, 'created_by_user_id' => $user->id]);
        $account = ManualPublicationAccount::create(['persona_id' => $persona->id, 'platform' => 'wechat', 'account_name' => '测试公众号', 'created_by_user_id' => $user->id]);
        $publication = app(ManualPublicationService::class)->create([
            'type' => 'post', 'article_id' => $article->id, 'persona_id' => $persona->id, 'account_id' => $account->id,
            'assigned_user_id' => $user->id, 'platform' => 'wechat', 'content' => $content,
        ], $user);
        $publication->update(['status' => 'in_progress', 'revision' => 3]);
        TenantContext::forget();
        Sanctum::actingAs($user, ['*']);

        return [$tenant, $user, $article, $account, $publication];
    }

    private function api(Tenant $tenant): self
    {
        return $this->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])->withHeader('X-Tenant', $tenant->subdomain);
    }

    private function credentials(): array
    {
        return ['app_id' => self::APP_ID, 'app_secret' => self::SECRET, 'author' => '作者', 'account_checked' => true];
    }

    private function connectionUrl(ManualPublicationAccount $account): string
    {
        return '/api/v1/manual-publications/settings/accounts/'.$account->id.'/wechat-connection';
    }

    private function connect(Tenant $tenant, ManualPublicationAccount $account): void
    {
        $this->api($tenant)->postJson($this->connectionUrl($account), $this->credentials())->assertOk();
    }

    private function send(Tenant $tenant, ManualPublication $publication, array $overrides = [])
    {
        return $this->api($tenant)->post('/api/v1/manual-publications/'.$publication->id.'/wechat-draft', array_merge([
            'revision' => $publication->revision, 'publication_title' => '本次渠道标题', 'account_checked' => true, 'create_new_draft' => true,
            'cover' => UploadedFile::fake()->createWithContent('cover.png', $this->png()),
        ], $overrides), ['Accept' => 'application/json']);
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZbSAAAAAASUVORK5CYII=');
    }

    private function createCount(): int
    {
        return count(array_filter($this->sent, fn ($url) => str_contains($url, 'draft/add')));
    }
}
