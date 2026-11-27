<?php

namespace Tests\Feature;

use App\Jobs\GenerateEnterpriseKnowledgeDraftJob;
use App\Models\{EnterpriseKnowledgeProject, EnterpriseKnowledgeRevision, KnowledgeDocument, Tenant, User};
use App\Services\Knowledge\EnterpriseKnowledgeDraftService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Event, Queue, Storage};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EnterpriseDraftVersionTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Support\UsesWorkspaceModels, \Tests\Support\KnowledgeProjectFixtures;

    private Tenant $tenant;
    private User $user;
    private int $projectId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.tenant_domain' => 'geo.example.test', 'geo.async_ingest' => true]);
        Storage::fake('local'); Queue::fake();
        $this->tenant = Tenant::create(['subdomain' => 'draft-version', 'name' => 'Draft fixture', 'plan' => 'basic', 'status' => 'active']);
        TenantContext::set($this->tenant);
        $this->configureWorkspaceModelSlots($this->tenant);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'admin']);
        Sanctum::actingAs($this->user, ['*']);
        $this->projectId = self::knowledgeProjectId($this->tenant->id);
        $this->withServerVariables(['HTTP_HOST' => 'draft-version.geo.example.test'])
            ->withHeader('X-Tenant', 'draft-version')->withHeader('X-Project-Id', (string) $this->projectId);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    private function archive(): EnterpriseKnowledgeProject
    {
        return EnterpriseKnowledgeProject::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->projectId,
            'name' => 'Version fixture', 'status' => 'draft', 'draft_content' => 'original draft']);
    }

    private function revision(EnterpriseKnowledgeProject $archive): EnterpriseKnowledgeRevision
    {
        return $this->app->make(EnterpriseKnowledgeDraftService::class)->recordRevision($archive, 'historical draft', 'manual', 'fixture revision', $this->user->id);
    }

    public function test_late_autosave_cannot_replace_a_newer_draft_or_record_a_revision(): void
    {
        $a = $this->archive();
        $url = "/api/v1/enterprise-knowledge/{$a->id}/autosave";
        // Both tabs read version zero; the newer edit arrives first.
        $this->postJson($url, ['content' => 'newer edit', 'base_version' => 0])->assertOk();
        $this->postJson($url, ['content' => 'late older edit', 'base_version' => 0])->assertConflict()
            ->assertJsonPath('action', 'enterprise_draft_conflict')->assertJsonPath('current.draft_content', 'newer edit');
        $this->assertSame('newer edit', $a->fresh()->draft_content);
        $this->assertDatabaseCount('enterprise_knowledge_revisions', 1);
        $this->getJson("/api/v1/enterprise-knowledge/{$a->id}")->assertOk()->assertJsonPath('data.draft_version', 1);
    }

    public function test_all_draft_writes_require_a_version_before_side_effects(): void
    {
        $a = $this->archive(); $rev = $this->revision($a);
        foreach (['autosave', 'validate', 'restore/'.$rev->id, 'publish'] as $action) {
            $this->postJson("/api/v1/enterprise-knowledge/{$a->id}/{$action}", ['content' => 'unversioned edit', 'create_knowledge_base' => true])
                ->assertUnprocessable()->assertJsonValidationErrors('base_version');
        }
        $this->assertSame('original draft', $a->fresh()->draft_content);
        $this->assertDatabaseCount('enterprise_knowledge_revisions', 1);
        $this->assertDatabaseCount('knowledge_documents', 0); Queue::assertNothingPushed();
    }

    public function test_stale_validate_restore_and_publish_cannot_bypass_autosave_version(): void
    {
        $a = $this->archive(); $rev = $this->revision($a);
        $this->postJson("/api/v1/enterprise-knowledge/{$a->id}/autosave", ['content' => 'newer edit', 'base_version' => 0])->assertOk();
        foreach (['validate', 'restore/'.$rev->id, 'publish'] as $action) {
            $this->postJson("/api/v1/enterprise-knowledge/{$a->id}/{$action}", ['content' => 'stale edit', 'base_version' => 0, 'create_knowledge_base' => true])
                ->assertConflict();
        }
        $this->assertSame('newer edit', $a->fresh()->draft_content);
        $this->assertDatabaseCount('enterprise_knowledge_revisions', 2);
        $this->assertDatabaseCount('knowledge_documents', 0); Queue::assertNothingPushed();
    }

    public function test_validate_restore_and_publish_each_invalidate_an_older_autosave(): void
    {
        foreach (['validate', 'restore', 'publish'] as $action) {
            $a = $this->archive(); $rev = $this->revision($a);
            $route = $action === 'restore' ? 'restore/'.$rev->id : $action;
            $this->postJson("/api/v1/enterprise-knowledge/{$a->id}/{$route}", ['content' => 'explicit edit', 'base_version' => 0, 'create_knowledge_base' => true])->assertOk();
            $this->postJson("/api/v1/enterprise-knowledge/{$a->id}/autosave", ['content' => 'old editor', 'base_version' => 0])->assertConflict();
            $this->assertSame($action === 'restore' ? 'historical draft' : 'explicit edit', $a->fresh()->draft_content);
        }
    }

    public function test_explicit_conflict_recovery_still_requires_the_latest_version(): void
    {
        $a = $this->archive(); $url = "/api/v1/enterprise-knowledge/{$a->id}/autosave";
        $this->postJson($url, ['content' => 'other tab', 'base_version' => 0])->assertOk();
        $this->postJson($url, ['content' => 'local retained', 'base_version' => 0])->assertConflict();
        $this->postJson($url, ['content' => 'other tab again', 'base_version' => 1])->assertOk();
        $this->postJson($url, ['content' => 'local retained', 'base_version' => 1])->assertConflict();
        $this->postJson($url, ['content' => 'local retained', 'base_version' => 2])->assertOk()->assertJsonPath('draft_version', 3);
        $this->assertSame('local retained', $a->fresh()->draft_content);
        $this->assertDatabaseCount('enterprise_knowledge_revisions', 3);
    }

    public function test_revision_failure_rolls_back_content_and_version_together(): void
    {
        $a = $this->archive();
        Event::listen('eloquent.creating: '.EnterpriseKnowledgeRevision::class, static function () { throw new \RuntimeException('synthetic revision write failure'); });
        $this->postJson("/api/v1/enterprise-knowledge/{$a->id}/autosave", ['content' => 'must roll back', 'base_version' => 0])->assertStatus(500);
        $this->assertSame('original draft', $a->fresh()->draft_content);
        $this->assertSame(0, (int) $a->fresh()->draft_version);
        $this->assertDatabaseCount('enterprise_knowledge_revisions', 0);
    }

    public function test_foreign_workspace_and_project_return_no_draft_or_conflict_body(): void
    {
        $foreignTenant = Tenant::create(['subdomain' => 'other-draft', 'name' => 'Other fixture', 'plan' => 'basic', 'status' => 'active']);
        $otherProject = (new \App\Models\Project())->forceFill(['tenant_id' => $this->tenant->id, 'owner_id' => $this->user->id, 'name' => 'Other project', 'status' => 'active']);
        $otherProject->save();
        $otherProject = $otherProject->id;
        foreach ([[$foreignTenant->id, self::knowledgeProjectId($foreignTenant->id)], [$this->tenant->id, $otherProject]] as [$tenantId, $projectId]) {
            $a = EnterpriseKnowledgeProject::withoutGlobalScopes()->create(['tenant_id' => $tenantId, 'project_id' => $projectId, 'name' => 'Other draft', 'draft_content' => 'private foreign draft']);
            $this->postJson("/api/v1/enterprise-knowledge/{$a->id}/autosave", ['content' => 'wrong edit', 'base_version' => 0])->assertNotFound()->assertJsonMissingPath('current');
            $this->assertSame('private foreign draft', $a->fresh()->draft_content);
        }
    }

    public function test_generation_completed_after_manual_edit_preserves_new_draft_and_keeps_generated_revision(): void
    {
        $a = $this->archive();
        $interleaved = false;
        DB::listen(function ($query) use ($a, &$interleaved) {
            if (! $interleaved && str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'enterprise_knowledge_projects') && in_array('processing', $query->bindings, true)) {
                $interleaved = true;
                // Deterministic boundary: another writer commits after the Job captured its version.
                DB::table('enterprise_knowledge_projects')->where('id', $a->id)->update(['draft_version' => 1, 'draft_content' => 'manual during generation', 'status' => 'reviewing']);
            }
        });
        (new GenerateEnterpriseKnowledgeDraftJob($a->id, $this->user->id, 0))->handle($this->app->make(EnterpriseKnowledgeDraftService::class));
        $this->assertSame('manual during generation', $a->fresh()->draft_content);
        $this->assertSame(1, (int) $a->fresh()->draft_version);
        $this->assertDatabaseCount('enterprise_knowledge_revisions', 1);
        $revision = EnterpriseKnowledgeRevision::where('enterprise_knowledge_project_id', $a->id)->firstOrFail();
        $this->assertNotSame('manual during generation', $revision->content);
        $this->assertStringContainsString('仅保留为修订', $revision->summary);
    }

    public function test_late_generation_exception_and_failed_hook_cannot_mark_new_draft_failed(): void
    {
        $a = $this->archive();
        $interleaved = false;
        DB::listen(function ($query) use ($a, &$interleaved) {
            if (! $interleaved && str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'enterprise_knowledge_projects') && in_array('processing', $query->bindings, true)) {
                $interleaved = true;
                DB::table('enterprise_knowledge_projects')->where('id', $a->id)->update(['draft_version' => 1, 'draft_content' => 'new manual draft', 'status' => 'reviewing']);
                throw new \RuntimeException('synthetic late generation failure');
            }
        });
        $job = new GenerateEnterpriseKnowledgeDraftJob($a->id, $this->user->id, 0);
        $job->handle($this->app->make(EnterpriseKnowledgeDraftService::class));
        $this->assertSame('reviewing', $a->fresh()->status);
        $job->failed(new \RuntimeException('synthetic timeout'));
        $this->assertSame('reviewing', $a->fresh()->status);
        $this->assertSame('new manual draft', $a->fresh()->draft_content);
    }

    public function test_generation_queued_with_old_version_does_not_write_or_add_revision(): void
    {
        $a = $this->archive();
        DB::table('enterprise_knowledge_projects')->where('id', $a->id)->update(['draft_version' => 1, 'draft_content' => 'newer queued draft']);
        (new GenerateEnterpriseKnowledgeDraftJob($a->id, $this->user->id, 0))->handle($this->app->make(EnterpriseKnowledgeDraftService::class));
        $this->assertSame('newer queued draft', $a->fresh()->draft_content);
        $this->assertDatabaseCount('enterprise_knowledge_revisions', 0);
    }
    public function test_stale_or_unversioned_generate_cannot_queue_over_a_newer_edit(): void
    {
        $a = $this->archive();
        $a->sources()->create(['tenant_id' => $this->tenant->id, 'content' => 'Synthetic source', 'file_type' => 'text']);
        $this->postJson("/api/v1/enterprise-knowledge/{$a->id}/autosave", ['content' => 'newer manual draft', 'base_version' => 0])->assertOk();
        $this->postJson("/api/v1/enterprise-knowledge/{$a->id}/generate", ['base_version' => 0])->assertConflict();
        $this->postJson("/api/v1/enterprise-knowledge/{$a->id}/generate")->assertUnprocessable()->assertJsonValidationErrors('base_version');
        Queue::assertNothingPushed();
        $this->assertSame('newer manual draft', $a->fresh()->draft_content);
        $this->assertNotSame('queued', $a->fresh()->status);
    }

    public function test_generation_start_cannot_set_newer_manual_draft_to_processing(): void
    {
        $a = $this->archive(); $retrieved = 0;
        Event::listen('eloquent.retrieved: '.EnterpriseKnowledgeProject::class, function ($model) use ($a, &$retrieved) {
            // First read resolves the tenant; the second is the Job's scoped draft snapshot.
            if ($model->id === $a->id && ++$retrieved === 2) {
                DB::table('enterprise_knowledge_projects')->where('id', $a->id)->update(['draft_version' => 1, 'draft_content' => 'manual before processing', 'status' => 'reviewing']);
            }
        });
        (new GenerateEnterpriseKnowledgeDraftJob($a->id, $this->user->id, 0))->handle($this->app->make(EnterpriseKnowledgeDraftService::class));
        $this->assertSame('reviewing', $a->fresh()->status);
        $this->assertSame('manual before processing', $a->fresh()->draft_content);
        $this->assertDatabaseCount('enterprise_knowledge_revisions', 0);
    }

}
