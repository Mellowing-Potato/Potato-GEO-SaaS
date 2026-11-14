<?php

namespace Tests\Feature;

use App\Models\AnalyticsVisitLog;
use App\Models\Article;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 站内阅读页退役后不再采集访问日志。 */
class ReadingPageVisitTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Article $article;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()->create([
            'subdomain' => 'readtenant',
            'name' => 'READ',
            'plan' => 'basic',
            'status' => 'active',
        ]);
        TenantContext::set($this->tenant);
        $owner = User::factory()->create();
        $project = Project::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'R 项目',
            'brand_name' => 'R',
            'owner_id' => $owner->id,
            'current_stage' => 2,
            'status' => 'active',
        ]);
        $this->article = Article::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $project->id,
            'title' => '可读文章',
            'slug' => 'hello',
            'status' => 'published',
            'body_markdown' => '# hi',
        ]);
        TenantContext::forget();
    }

    public function test_retired_reading_pages_do_not_record_visits(): void
    {
        $this->get("/read/{$this->tenant->subdomain}/{$this->article->slug}")->assertStatus(410);
        $this->get("/read/{$this->tenant->subdomain}/does-not-exist")->assertStatus(410);
        $this->assertDatabaseCount('analytics_visit_logs', 0);
    }
}
