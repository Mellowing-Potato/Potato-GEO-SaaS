<?php

namespace Tests\Feature;

use App\Jobs\DryRunPlatformSensitiveWordsJob;
use App\Models\Article;
use App\Models\AdminActivityLog;
use App\Models\PlatformSensitiveWordDryRun;
use App\Models\Project;
use App\Models\SensitiveWordHit;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Publishing\ArticleRiskScanner;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformWorkspaceContentIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    public function test_hits_and_summary_never_include_other_workspace_even_with_explicit_filter(): void
    {
        [$tenantA, $adminA] = $this->workspace('a');
        [$tenantB] = $this->workspace('b');
        foreach ([[$tenantA, 'own-word', 'own-context'], [$tenantB, 'foreign-word', 'foreign-private-context']] as [$tenant, $word, $context]) {
            SensitiveWordHit::query()->create([
                'tenant_id' => $tenant->id, 'article_id' => null, 'word' => $word,
                'severity' => 'blocked', 'origin' => SensitiveWordHit::ORIGIN_PLATFORM,
                'surface' => 'body_markdown', 'context' => $context, 'trigger' => 'create',
            ]);
        }
        Sanctum::actingAs($adminA, ['*']);
        $response = $this->getJson('/api/v1/platform/sensitive-word-hits')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.context', 'own-context');
        $this->assertStringNotContainsString('foreign-', $response->getContent());
        $this->getJson('/api/v1/platform/sensitive-word-hits?tenant_id='.$tenantB->id)
            ->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/platform/sensitive-word-hits/summary')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.word', 'own-word')
            ->assertJsonPath('data.0.tenant_count', 1);
    }

    public function test_dry_run_scans_only_current_workspace_and_excludes_soft_deleted_content(): void
    {
        Queue::fake([DryRunPlatformSensitiveWordsJob::class]);
        [$tenantA, $adminA] = $this->workspace('a');
        [$tenantB, $adminB] = $this->workspace('b');
        $this->article($tenantA, $adminA, 'candidate own-active');
        $deleted = $this->article($tenantA, $adminA, 'candidate own-deleted-private');
        $deleted->delete();
        $this->article($tenantB, $adminB, 'candidate foreign-private');
        Sanctum::actingAs($adminA, ['*']);
        $id = $this->postJson('/api/v1/platform/sensitive-words/dry-run', ['words' => 'candidate'])
            ->assertStatus(202)->json('data.id');
        $run = PlatformSensitiveWordDryRun::query()->findOrFail($id);
        $this->assertSame($tenantA->id, $run->tenant_id);
        $this->assertSame($adminA->id, $run->requested_by);

        // An inherited worker context must not select the previous job's workspace.
        TenantContext::set($tenantB);
        (new DryRunPlatformSensitiveWordsJob($id, $tenantA->id))->handle(app(ArticleRiskScanner::class));
        $response = $this->getJson('/api/v1/platform/sensitive-words/dry-run/'.$id)->assertOk()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.articles_scanned', 1)
            ->assertJsonPath('data.hit_articles', 1)->assertJsonPath('data.by_tenant.0.tenant_id', $tenantA->id)
            ->assertJsonPath('data.by_word.0.samples.0.snippet', 'candidate own-active');
        $this->assertStringNotContainsString('foreign-private', $response->getContent());
        $this->assertStringNotContainsString('own-deleted-private', $response->getContent());
        $this->assertNull(TenantContext::get());
        Queue::assertPushed(DryRunPlatformSensitiveWordsJob::class, fn ($job) => $job->dryRunId === $id && $job->tenantId === $tenantA->id);
    }

    public function test_other_platform_admin_and_legacy_mixed_results_are_not_readable(): void
    {
        [$tenantA, $adminA] = $this->workspace('a');
        [$tenantB, $adminB] = $this->workspace('b');
        $foreign = $this->makeRun($tenantB, $adminB);
        $legacy = PlatformSensitiveWordDryRun::query()->create([
            'requested_by' => $adminA->id, 'candidates' => ['legacy'], 'status' => 'completed',
            'by_word' => [['word' => 'legacy', 'samples' => [['tenant_id' => $tenantB->id, 'snippet' => 'legacy-foreign-private']]]],
        ]);
        Sanctum::actingAs($adminA, ['*']);
        $this->getJson('/api/v1/platform/sensitive-words/dry-run/'.$foreign->id)->assertNotFound();
        $this->getJson('/api/v1/platform/sensitive-words/dry-run/'.$legacy->id)->assertNotFound();
        $own = $this->makeRun($tenantA, $adminA);
        $this->getJson('/api/v1/platform/sensitive-words/dry-run/'.$own->id)->assertOk();
    }

    public function test_job_and_failed_callback_cannot_update_another_workspace_run(): void
    {
        [$tenantA] = $this->workspace('a');
        [$tenantB, $adminB] = $this->workspace('b');
        $run = $this->makeRun($tenantB, $adminB);
        $job = new DryRunPlatformSensitiveWordsJob($run->id, $tenantA->id);
        $job->handle(app(ArticleRiskScanner::class));
        $job->failed(new \RuntimeException('synthetic failure'));
        $this->assertSame('pending', $run->fresh()->status);
        $this->assertNull($run->fresh()->started_at);
    }

    public function test_failed_callback_cannot_change_foreign_run_without_handle(): void
    {
        [$tenantA] = $this->workspace('a');
        [$tenantB, $adminB] = $this->workspace('b');
        $run = $this->makeRun($tenantB, $adminB);
        (new DryRunPlatformSensitiveWordsJob($run->id, $tenantA->id))->failed(null);
        $this->assertSame('pending', $run->fresh()->status);
        $this->assertNull($run->fresh()->finished_at);
    }

    public function test_legacy_job_without_workspace_cannot_scan_or_overwrite_results(): void
    {
        [$tenant, $admin] = $this->workspace('a');
        $this->article($tenant, $admin, 'candidate private');
        $run = PlatformSensitiveWordDryRun::query()->create([
            'requested_by' => $admin->id, 'candidates' => ['candidate'], 'status' => 'pending',
        ]);
        $job = new DryRunPlatformSensitiveWordsJob($run->id);
        $job->handle(app(ArticleRiskScanner::class));
        $job->failed(null);
        $this->assertSame('pending', $run->fresh()->status);
        $this->assertNull($run->fresh()->started_at);
        $this->assertSame(0, $run->fresh()->articles_scanned);
    }

    public function test_job_stops_when_requester_no_longer_owns_original_workspace(): void
    {
        [$tenantA, $adminA] = $this->workspace('a');
        [$tenantB] = $this->workspace('b');
        $this->article($tenantA, $adminA, 'candidate private');
        $run = $this->makeRun($tenantA, $adminA);
        $adminA->forceFill(['tenant_id' => $tenantB->id])->save();
        (new DryRunPlatformSensitiveWordsJob($run->id, $tenantA->id))->handle(app(ArticleRiskScanner::class));
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame(0, $run->fresh()->articles_scanned);
        $this->assertNull($run->fresh()->by_word);
    }

    public function test_platform_content_endpoints_fail_closed_without_active_workspace(): void
    {
        $admin = User::factory()->create(['tenant_id' => null, 'role' => 'platform_admin']);
        Sanctum::actingAs($admin, ['*']);
        foreach (['operations', 'overview', 'tenants', 'audit', 'sensitive-word-hits', 'sensitive-word-hits/summary', 'sensitive-words/dry-run/1'] as $path) {
            $this->getJson('/api/v1/platform/'.$path)->assertForbidden();
        }
        $this->postJson('/api/v1/platform/sensitive-words/dry-run', ['words' => 'candidate'])->assertForbidden();
        [$tenant, $admin] = $this->workspace('inactive');
        $tenant->forceFill(['status' => 'paused'])->save();
        Sanctum::actingAs($admin, ['*']);
        $this->getJson('/api/v1/platform/operations')->assertNotFound();
        $this->getJson('/api/v1/platform/sensitive-word-hits')->assertNotFound();
        $this->postJson('/api/v1/platform/sensitive-words/dry-run', ['words' => 'candidate'])->assertNotFound();
    }

    public function test_ordinary_workspace_admin_is_still_forbidden_from_content_operations(): void
    {
        [, $admin] = $this->workspace('a');
        $admin->forceFill(['role' => 'admin'])->save();
        Sanctum::actingAs($admin, ['*']);
        foreach (['operations', 'overview', 'tenants', 'audit', 'sensitive-word-hits', 'sensitive-word-hits/summary', 'sensitive-words/dry-run/1'] as $path) {
            $this->getJson('/api/v1/platform/'.$path)->assertForbidden();
        }
        $this->postJson('/api/v1/platform/sensitive-words/dry-run', ['words' => 'candidate'])->assertForbidden();
    }

    public function test_audit_hides_foreign_identity_details_and_unowned_legacy_records(): void
    {
        [$tenantA, $adminA] = $this->workspace('a');
        [$tenantB, $adminB] = $this->workspace('b');
        foreach ([[$tenantA->id, $adminA, 'own-audit'], [$tenantB->id, $adminB, 'foreign-private-audit'], [null, $adminA, 'legacy-unowned-private']] as [$tenantId, $actor, $text]) {
            AdminActivityLog::query()->create([
                'tenant_id' => $tenantId, 'actor_id' => $actor->id, 'actor_name' => $text,
                'action' => 'fixture.audit', 'request_method' => 'POST', 'ip_address' => '192.0.2.2',
                'details' => json_encode(['title' => $text]), 'created_at' => now(),
            ]);
        }
        Sanctum::actingAs($adminA, ['*']);
        $response = $this->getJson('/api/v1/platform/audit')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.actor_name', 'own-audit');
        $this->assertStringNotContainsString('foreign-private-audit', $response->getContent());
        $this->assertStringNotContainsString('legacy-unowned-private', $response->getContent());
        $this->getJson('/api/v1/platform/audit?actor_id='.$adminB->id.'&tenant_id='.$tenantB->id)
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_tenant_list_and_business_overview_only_describe_own_workspace(): void
    {
        [$tenantA, $adminA] = $this->workspace('a');
        [$tenantB, $adminB] = $this->workspace('b');
        $tenantB->update(['name' => 'Foreign private customer']);
        $this->article($tenantA, $adminA, 'Own article');
        $this->article($tenantB, $adminB, 'Foreign private content');
        $this->article($tenantB, $adminB, 'Foreign second content');
        Sanctum::actingAs($adminA, ['*']);
        $response = $this->getJson('/api/v1/platform/tenants?tenant_id='.$tenantB->id)->assertOk()
            ->assertJsonPath('pagination.total', 1)->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $tenantA->id)->assertJsonPath('data.0.articles_total', 1);
        $this->assertStringNotContainsString('Foreign private customer', $response->getContent());
        $this->getJson('/api/v1/platform/overview')->assertOk()
            ->assertJsonPath('platform.tenants_total', 1)->assertJsonPath('platform.users_total', 1)
            ->assertJsonPath('platform.projects_total', 1)->assertJsonPath('content.articles_period', 1);
    }

    public function test_pause_and_recover_return_only_status_receipt_not_foreign_settings(): void
    {
        [, $adminA] = $this->workspace('a');
        [$tenantB, $adminB] = $this->workspace('b');
        $adminB->update(['role' => 'admin']);
        $tenantB->update(['name' => 'Foreign private customer', 'settings' => ['fixture_private' => 'foreign-private-setting']]);
        Sanctum::actingAs($adminA, ['*']);
        foreach ([['pause', 'paused'], ['recover', 'active']] as [$action, $status]) {
            $response = $this->postJson('/api/v1/platform/tenants/'.$tenantB->id.'/'.$action, ['reason' => 'fixture-operation'])
                ->assertOk()->assertJsonPath('tenant.id', $tenantB->id)->assertJsonPath('tenant.status', $status);
            $this->assertSame(['id', 'status'], array_keys($response->json('tenant')));
            $this->assertStringNotContainsString('foreign-private-setting', $response->getContent());
            $this->assertStringNotContainsString('Foreign private customer', $response->getContent());
        }
        $this->assertSame('foreign-private-setting', $tenantB->fresh()->settings['fixture_private']);
    }

    public function test_anonymous_requests_cannot_access_platform_content_endpoints(): void
    {
        foreach (['operations', 'overview', 'tenants', 'audit', 'sensitive-word-hits', 'sensitive-word-hits/summary', 'sensitive-words/dry-run/1'] as $path) {
            $this->getJson('/api/v1/platform/'.$path)->assertUnauthorized();
        }
        $this->postJson('/api/v1/platform/sensitive-words/dry-run', ['words' => 'candidate'])->assertUnauthorized();
    }

    private function workspace(string $name): array
    {
        $tenant = Tenant::query()->create(['name' => 'Workspace '.$name, 'subdomain' => 'isolation-'.$name, 'status' => 'active', 'plan' => 'basic']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'platform_admin']);
        return [$tenant, $user];
    }

    private function article(Tenant $tenant, User $user, string $text): Article
    {
        $project = Project::withoutGlobalScopes()->forceCreate(['tenant_id' => $tenant->id, 'owner_id' => $user->id, 'name' => 'Fixture project']);
        return Article::withoutEvents(fn () => Article::withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenant->id, 'project_id' => $project->id, 'author_id' => $user->id,
            'title' => 'Fixture article', 'body_markdown' => $text, 'status' => 'draft',
            'source_type' => 'manual', 'content_type' => 'general', 'metadata' => [],
        ]));
    }

    private function makeRun(Tenant $tenant, User $user): PlatformSensitiveWordDryRun
    {
        return PlatformSensitiveWordDryRun::query()->create([
            'tenant_id' => $tenant->id, 'requested_by' => $user->id,
            'candidates' => ['candidate'], 'status' => 'pending',
        ]);
    }
}
