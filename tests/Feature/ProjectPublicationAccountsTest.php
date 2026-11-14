<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ManualPublication;
use App\Models\ManualPublicationAccount;
use App\Models\ManualPublicationPersona;
use App\Models\Project;
use App\Models\PublishingChannel;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Distribution\TaskDistributionChannelSelector;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

class ProjectPublicationAccountsTest extends TestCase
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

    public function test_no_website_user_binds_zhihu_and_prepares_one_versioned_draft(): void
    {
        [$tenant, $admin, $project, $article, $account] = $this->fixture('no-website');
        $this->bind($tenant, $project, [$account->id]);
        $this->api($tenant)->getJson('/api/v1/publishing/project-channels?project_id='.$project->id)
            ->assertOk()->assertJsonCount(0, 'channels')->assertJsonCount(1, 'manual_accounts')
            ->assertJsonPath('has_explicit_bindings', true)
            ->assertJsonPath('manual_accounts.0.delivery_mode', 'manual');
        $created = $this->start($tenant, $article, $account)->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.assigned_user_id', $admin->id)
            ->assertJsonPath('data.source_snapshot.article_version', 1);
        $this->start($tenant, $article, $account)->assertOk()->assertJsonPath('created', false)
            ->assertJsonPath('data.id', $created->json('data.id'));
        $this->assertDatabaseCount('manual_publications', 1);
        $this->assertSame('approved', $article->fresh()->status);
        $this->assertNull($project->brand_url);
    }

    public function test_unbound_project_never_uses_all_tenant_website_channels(): void
    {
        [$tenant, , $project, $article, $account] = $this->fixture('no-fanout');
        PublishingChannel::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => 'Other project site', 'type' => 'http_webhook',
            'endpoint_url' => 'https://hooks.example.test/', 'is_active' => true,
        ]);
        $this->api($tenant)->getJson('/api/v1/publishing/project-channels?project_id='.$project->id)
            ->assertOk()->assertJsonPath('mode', 'none')->assertJsonCount(0, 'channels');
        TenantContext::set($tenant);
        $this->assertCount(0, app(TaskDistributionChannelSelector::class)->channelsForProject($project->id));
        $this->start($tenant, $article, $account)->assertUnprocessable();
    }

    public function test_foreign_account_cannot_be_bound_and_existing_choices_survive_failure(): void
    {
        [$tenant, $admin, $project, , $account] = $this->fixture('owner');
        $this->bind($tenant, $project, [$account->id]);
        [, , $foreignProject, $foreignArticle, $foreignAccount] = $this->fixture('foreign');
        Sanctum::actingAs($admin, ['*']);
        $this->api($tenant)->postJson('/api/v1/publishing/project-channels/bind', [
            'project_id' => $project->id, 'channel_ids' => [], 'manual_account_ids' => [$foreignAccount->id],
        ])->assertUnprocessable();
        $this->assertDatabaseHas('project_publication_accounts', ['tenant_id' => $tenant->id, 'project_id' => $project->id, 'account_id' => $account->id]);
        $this->api($tenant)->getJson('/api/v1/publishing/project-channels?project_id='.$foreignProject->id)->assertNotFound();
        $this->start($tenant, $foreignArticle, $foreignAccount)->assertNotFound();
        $this->assertDatabaseCount('manual_publications', 0);
    }

    public function test_empty_selection_disables_both_paths_and_disabled_accounts_are_unavailable(): void
    {
        [$tenant, , $project, $article, $account] = $this->fixture('disabled-account');
        $this->bind($tenant, $project, [$account->id]);
        $account->update(['is_active' => false]);
        $this->api($tenant)->getJson('/api/v1/publishing/project-channels?project_id='.$project->id)
            ->assertOk()->assertJsonCount(0, 'manual_accounts');
        $this->start($tenant, $article, $account)->assertNotFound();
        $this->bind($tenant, $project, []);
        $this->assertDatabaseCount('project_publication_accounts', 0);
    }

    public function test_only_admin_binds_accounts_but_editor_can_prepare_bound_article(): void
    {
        [$tenant, , $project, $article, $account] = $this->fixture('roles');
        $this->bind($tenant, $project, [$account->id]);
        $editor = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'editor']);
        Sanctum::actingAs($editor, ['*']);
        $this->api($tenant)->postJson('/api/v1/publishing/project-channels/bind', [
            'project_id' => $project->id, 'channel_ids' => [], 'manual_account_ids' => [],
        ])->assertForbidden();
        $this->start($tenant, $article, $account)->assertCreated()->assertJsonPath('data.assigned_user_id', $editor->id);
        $member = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'member']);
        Sanctum::actingAs($member, ['*']);
        $this->start($tenant, $article, $account)->assertForbidden();
    }

    public function test_unreviewed_or_stale_article_cannot_create_or_advance_a_publication(): void
    {
        [$tenant, , $project, $article, $account] = $this->fixture('stale');
        $this->bind($tenant, $project, [$account->id]);
        $created = $this->start($tenant, $article, $account)->assertCreated();
        $article->update(['status' => 'draft', 'version' => 2]);
        $this->start($tenant, $article, $account)->assertUnprocessable();
        $this->api($tenant)->postJson('/api/v1/manual-publications/'.$created->json('data.id').'/transition', [
            'target_status' => 'ready', 'revision' => 1,
        ])->assertUnprocessable();
    }

    public function test_platform_result_requires_public_url_and_updates_only_the_reviewed_version(): void
    {
        [$tenant, , $project, $article, $account] = $this->fixture('receipts');
        $this->bind($tenant, $project, [$account->id]);
        $id = $this->start($tenant, $article, $account)->assertCreated()->json('data.id');
        foreach (['ready', 'in_progress'] as $i => $status) {
            $this->api($tenant)->postJson('/api/v1/manual-publications/'.$id.'/transition', [
                'target_status' => $status, 'revision' => $i + 1,
            ])->assertOk();
        }
        foreach (['https://example.test/post/1', 'https://zhuanlan.zhihu.com/p/123/edit', 'https://www.zhihu.com/people/owner'] as $url) {
            $this->api($tenant)->postJson('/api/v1/manual-publications/'.$id.'/transition', [
                'target_status' => 'completed', 'revision' => 3, 'completion_url' => $url,
            ])->assertUnprocessable();
        }
        $this->api($tenant)->postJson('/api/v1/manual-publications/'.$id.'/draft-payload', ['revision' => 3])
            ->assertOk()->assertJsonPath('data.workspaceId', $tenant->id)->assertJsonPath('data.title', $article->title);
        $this->api($tenant)->postJson('/api/v1/manual-publications/'.$id.'/transition', [
            'target_status' => 'completed', 'revision' => 3, 'completion_url' => 'https://zhuanlan.zhihu.com/p/123',
        ])->assertOk();
        $this->assertSame('published', $article->fresh()->status);
        $this->assertNotNull($article->fresh()->published_at);
    }

    public function test_project_filter_does_not_display_another_project_publication(): void
    {
        [$tenant, $admin, $project, $article, $account] = $this->fixture('filtered');
        $this->bind($tenant, $project, [$account->id]);
        $id = $this->start($tenant, $article, $account)->assertCreated()->json('data.id');
        TenantContext::set($tenant);
        $other = Project::create(['owner_id' => $admin->id, 'name' => 'Other']);
        TenantContext::forget();
        $this->api($tenant)->getJson('/api/v1/manual-publications?project_id='.$other->id)->assertOk()->assertJsonCount(0, 'data.data');
        $this->api($tenant)->getJson('/api/v1/manual-publications/'.$id.'?project_id='.$other->id)->assertNotFound();
    }

    public function test_account_settings_reject_foreign_persona_and_unsafe_or_wrong_platform_links(): void
    {
        [$tenant, $admin, , , $account] = $this->fixture('account-settings');
        [, , , , $foreign] = $this->fixture('other-settings');
        Sanctum::actingAs($admin, ['*']);
        $this->api($tenant)->putJson('/api/v1/manual-publications/settings/accounts/'.$account->id, ['persona_id' => $foreign->persona_id])->assertUnprocessable();
        foreach (['javascript:alert(1)', 'https://example.test/people/wrong', 'https://fixture-user-28@example.com/people/wrong'] as $url) {
            $this->api($tenant)->putJson('/api/v1/manual-publications/settings/accounts/'.$account->id, ['profile_url' => $url])->assertUnprocessable();
        }
        $this->api($tenant)->putJson('/api/v1/manual-publications/settings/accounts/'.$account->id, ['profile_url' => 'https://www.zhihu.com/people/updated'])->assertOk();
    }

    public function test_installation_package_contains_only_reviewed_extension_files(): void
    {
        [$tenant] = $this->fixture('helper-download');
        $response = $this->api($tenant)->get('/api/v1/publishing/browser-extension')->assertOk();
        $this->assertSame(hash('sha256', $response->getContent()), $response->headers->get('X-Content-SHA256'));
        $path = tempnam(sys_get_temp_dir(), 'helper-test-');
        try {
            file_put_contents($path, $response->getContent());
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path));
            $this->assertSame(13, $zip->numFiles);
            $this->assertSame(file_get_contents(base_path('LICENSE')), $zip->getFromName('LICENSE'));
            $this->assertSame(file_get_contents(base_path('NOTICE')), $zip->getFromName('NOTICE'));
            $manifest = json_decode($zip->getFromName('manifest.json'), true);
            $this->assertSame('0.4.0', $manifest['version']);
            foreach (['src/instance-config.js', 'src/instance-runtime.js', 'src/publishing-worker.js', 'src/weibo-worker.js', 'src/weibo-bridge.js', 'src/weibo-article.js'] as $file) {
                $this->assertNotFalse($zip->locateName($file));
            }
            $this->assertFalse($zip->locateName('.env'));
            $zip->close();
        } finally {
            unlink($path);
        }
    }

    public function test_completed_history_backfill_uses_only_matching_tenant_project_and_account(): void
    {
        [$tenant, , $project, $article, $account] = $this->fixture('legacy-choice');
        $this->bind($tenant, $project, [$account->id]);
        $id = $this->start($tenant, $article, $account)->assertCreated()->json('data.id');
        $publication = ManualPublication::withoutGlobalScopes()->findOrFail($id);
        $publication->update(['status' => 'completed', 'completion_url' => 'https://zhuanlan.zhihu.com/p/123', 'completed_at' => now()]);
        $publication->replicate()->save();
        [, , , , $foreign] = $this->fixture('legacy-other');
        $poisoned = $publication->replicate();
        $poisoned->account_id = $foreign->id;
        $poisoned->save();

        Schema::drop('project_publication_accounts');
        $migration = require database_path('migrations/2026_10_17_100000_create_project_publication_accounts_table.php');
        $migration->up();
        $this->assertDatabaseCount('project_publication_accounts', 1);
        $this->assertDatabaseHas('project_publication_accounts', ['tenant_id' => $tenant->id, 'project_id' => $project->id, 'account_id' => $account->id]);
    }

    public function test_receipt_for_an_older_version_does_not_publish_a_new_edit(): void
    {
        [$tenant, , $project, $article, $account] = $this->fixture('old-version-receipt');
        $this->bind($tenant, $project, [$account->id]);
        $id = $this->start($tenant, $article, $account)->assertCreated()->json('data.id');
        foreach (['ready', 'in_progress'] as $index => $status) {
            $this->api($tenant)->postJson('/api/v1/manual-publications/'.$id.'/transition', ['target_status' => $status, 'revision' => $index + 1])->assertOk();
        }
        $this->api($tenant)->postJson('/api/v1/manual-publications/'.$id.'/draft-payload', ['revision' => 2])->assertConflict();
        $article->update(['version' => 2, 'status' => 'draft']);
        $this->api($tenant)->postJson('/api/v1/manual-publications/'.$id.'/draft-payload', ['revision' => 3])->assertUnprocessable();
        $this->api($tenant)->postJson('/api/v1/manual-publications/'.$id.'/transition', [
            'target_status' => 'completed', 'revision' => 3, 'completion_url' => 'https://zhuanlan.zhihu.com/p/123',
        ])->assertOk();
        $this->assertSame('draft', $article->fresh()->status);
        $this->assertSame(2, $article->fresh()->version);
    }

    private function fixture(string $name): array
    {
        $tenant = Tenant::create(['subdomain' => $name, 'name' => $name, 'plan' => 'points', 'status' => 'active']);
        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        TenantContext::set($tenant);
        $project = Project::create(['owner_id' => $admin->id, 'name' => $name, 'brand_url' => null]);
        $article = Article::create(['project_id' => $project->id, 'author_id' => $admin->id, 'title' => 'A reviewed article', 'body_markdown' => '# Safe factual content', 'status' => 'approved', 'version' => 1]);
        $persona = ManualPublicationPersona::create(['name' => $name, 'created_by_user_id' => $admin->id]);
        $account = ManualPublicationAccount::create(['persona_id' => $persona->id, 'platform' => 'zhihu', 'account_name' => $name, 'profile_url' => 'https://www.zhihu.com/people/'.$name, 'created_by_user_id' => $admin->id]);
        TenantContext::forget();
        Sanctum::actingAs($admin, ['*']);

        return [$tenant, $admin, $project, $article, $account];
    }

    private function api(Tenant $tenant): self
    {
        return $this->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])->withHeader('X-Tenant', $tenant->subdomain);
    }

    private function bind(Tenant $tenant, Project $project, array $ids): void
    {
        $this->api($tenant)->postJson('/api/v1/publishing/project-channels/bind', ['project_id' => $project->id, 'channel_ids' => [], 'manual_account_ids' => $ids])->assertOk();
    }

    private function start(Tenant $tenant, Article $article, ManualPublicationAccount $account): TestResponse
    {
        return $this->api($tenant)->postJson('/api/v1/articles/'.$article->id.'/manual-publications', ['account_id' => $account->id, 'article_version' => 1]);
    }
}
