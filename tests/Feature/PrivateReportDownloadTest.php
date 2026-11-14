<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrivateReportDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('reports');
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    private function fixture(): array
    {
        $tenant = Tenant::create(['name' => 'Reports', 'subdomain' => 'reports', 'plan' => 'basic', 'status' => 'active']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        $project = Project::forceCreate(['tenant_id' => $tenant->id, 'owner_id' => $user->id, 'name' => 'Private report project']);
        $execution = SkillExecution::forceCreate([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'project_id' => $project->id,
            'skill_id' => Project::GEO_ANALYSIS_SKILL_ID, 'skill_name' => 'Private report', 'status' => 'completed',
            'category' => 'geo-strategy', 'input_data' => [],
            'output_data' => [],
        ]);
        $execution->update(['output_data' => [
            'markdown' => "executions/{$execution->id}/report.md",
            'html' => "executions/{$execution->id}/report.html",
            'pdf' => "executions/{$execution->id}/report.pdf",
            'docx' => "executions/{$execution->id}/report.docx",
        ]]);
        $this->withHeader('X-Tenant', 'reports')->withToken($user->createToken('reader', ['projects:read'])->plainTextToken);

        return [$execution, $project];
    }

    public function test_new_private_files_are_downloadable_through_both_authenticated_entries(): void
    {
        [$execution, $project] = $this->fixture();
        foreach (['markdown', 'html', 'pdf', 'docx'] as $format) {
            $path = $execution->output_data[$format];
            Storage::disk('reports')->put($path, 'private-'.$format);
            Storage::disk('public')->assertMissing($path);
            foreach (["/api/v1/executions/{$execution->id}/download/{$format}", "/api/v1/projects/{$project->id}/analyses/{$execution->id}/download/{$format}"] as $url) {
                $this->app['auth']->forgetGuards();
                $response = $this->get($url);
                $response->assertOk()->assertDownload(basename($path))->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
                $this->assertSame('private-'.$format, $response->streamedContent());
            }
        }
    }

    public function test_historical_files_remain_readable_and_private_replacement_takes_precedence(): void
    {
        [$execution, $project] = $this->fixture();
        $path = $execution->output_data['markdown'];
        Storage::disk('public')->put($path, 'legacy');
        foreach (["/api/v1/executions/{$execution->id}/download/markdown", "/api/v1/projects/{$project->id}/analyses/{$execution->id}/download/markdown"] as $url) {
            $this->app['auth']->forgetGuards();
            $response = $this->get($url)->assertOk();
            $this->assertSame('legacy', $response->streamedContent());
        }
        Storage::disk('reports')->put($path, 'updated-private');
        $this->app['auth']->forgetGuards();
        $this->assertSame('updated-private', $this->get("/api/v1/executions/{$execution->id}/download/markdown")->assertOk()->streamedContent());
    }

    public function test_anonymous_and_other_tenants_cannot_download_private_or_legacy_files(): void
    {
        [$execution, $project] = $this->fixture();
        Storage::disk('public')->put($execution->output_data['markdown'], 'legacy-secret');
        Storage::disk('reports')->put($execution->output_data['pdf'], 'private-secret');
        $other = Tenant::create(['name' => 'Other', 'subdomain' => 'other-reports', 'plan' => 'basic', 'status' => 'active']);
        $otherUser = User::factory()->create(['tenant_id' => $other->id, 'role' => 'admin']);
        foreach (['markdown', 'pdf'] as $format) {
            foreach (["/api/v1/executions/{$execution->id}/download/{$format}", "/api/v1/projects/{$project->id}/analyses/{$execution->id}/download/{$format}"] as $url) {
                TenantContext::forget();
                $this->app['auth']->forgetGuards();
                $this->withHeader('Authorization', '')->getJson($url)->assertUnauthorized();
                $this->app['auth']->forgetGuards();
                $this->withHeader('X-Tenant', 'other-reports')->withToken($otherUser->createToken('reader', ['projects:read'])->plainTextToken)->getJson($url)->assertNotFound();
            }
        }
    }

    public function test_execution_cannot_download_another_executions_legacy_file_even_with_forged_output_path(): void
    {
        [$executionA, $projectA] = $this->fixture();
        $tenantB = Tenant::create(['name' => 'Other', 'subdomain' => 'other-reports', 'status' => 'active']);
        $userB = User::factory()->create(['tenant_id' => $tenantB->id, 'role' => 'admin']);
        $projectB = Project::forceCreate(['tenant_id' => $tenantB->id, 'owner_id' => $userB->id, 'name' => 'B']);
        $executionB = SkillExecution::forceCreate([
            'tenant_id' => $tenantB->id, 'user_id' => $userB->id, 'project_id' => $projectB->id,
            'skill_id' => Project::GEO_ANALYSIS_SKILL_ID, 'skill_name' => 'B report',
            'category' => 'geo-strategy', 'status' => 'completed', 'input_data' => [], 'output_data' => [],
        ]);
        $pathB = "executions/{$executionB->id}/report.md";
        Storage::disk('public')->put($pathB, 'B-PRIVATE-MARKER');
        $executionA->update(['output_data' => ['markdown' => $pathB]]);

        foreach (["/api/v1/executions/{$executionA->id}/download/markdown",
            "/api/v1/projects/{$projectA->id}/analyses/{$executionA->id}/download/markdown"] as $url) {
            $this->app['auth']->forgetGuards();
            $this->getJson($url)->assertNotFound()->assertDontSee('B-PRIVATE-MARKER');
        }
        $this->assertFalse(\App\Services\Reports\ReportStorage::belongsToExecution(
            $executionA->id, "executions/{$executionA->id}/../{$executionB->id}/report.md",
        ));
    }
}
