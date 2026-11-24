<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Contracts\AiProvider;
use App\Services\SkillAgent\RenderBridgeService;
use App\Services\SkillAgent\RenderResult;
use App\Services\SkillAgent\SkillAssetLoader;
use App\Services\SkillAgent\SkillAssets;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SkillRenderRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.tenant_domain' => 'geo.example.test', 'cache.default' => 'array']);
        Http::preventStrayRequests();
        $ai = $this->createMock(AiProvider::class);
        $ai->expects($this->never())->method('generate');
        $ai->expects($this->never())->method('generateStructured');
        $ai->expects($this->never())->method('embed');
        $ai->expects($this->never())->method('embedBatch');
        $this->app->instance(AiProvider::class, $ai);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    public function test_rerender_recovers_one_article_without_new_model_usage_and_is_idempotent(): void
    {
        [$tenant, $user, $execution] = $this->fixture('recovery');
        $body = "# 医学科研工作流\n\n".str_repeat('文献整理与分析应保留证据和过程，研究者核对后再形成草稿。', 80);
        $this->mockRenderer(new RenderResult(true, ['docx' => 'executions/1/report.docx'], [
            'passed' => true, 'checks' => [['name' => 'docx_long_tokens_wrapped', 'passed' => true]],
            'publishable_article_sha256' => hash('sha256', $body), 'publication_ready' => false,
        ], publishableArticle: $body));
        Sanctum::actingAs($user, ['*']);

        $this->forTenant($tenant)->getJson('/api/v1/executions/'.$execution->id)->assertOk()->assertJsonPath('can_rerender', true);
        foreach ([1, 2] as $_) {
            $this->forTenant($tenant)->postJson('/api/v1/executions/'.$execution->id.'/rerender')
                ->assertOk()->assertJsonPath('execution.status', 'completed')
                ->assertJsonPath('execution.can_rerender', false)
                ->assertJsonPath('execution.token_usage', 66047);
        }
        $this->assertDatabaseCount('articles', 1);
        $this->assertDatabaseCount('model_call_logs', 0);
        $this->assertSame($body, Article::withoutGlobalScopes()->first()->body_markdown);
        $this->assertNotSame('approved', Article::withoutGlobalScopes()->first()->status);
        $this->assertSame(0, data_get($execution->fresh()->output_data, 'render_recovery.additional_points'));
        Http::assertNothingSent();
    }

    public static function savedTrackingReports(): array
    {
        return ['with format status' => [false], 'before format status existed' => [true]];
    }

    #[DataProvider('savedTrackingReports')]
    public function test_missing_format_is_recovered_from_saved_tracking_report_without_an_article_or_new_cost(bool $legacy): void
    {
        [$tenant, $user, $execution] = $this->fixture('tracking');
        $disk = Storage::fake('reports');
        $prefix = "executions/{$execution->id}";
        $disk->put("$prefix/report.html", '<p>初版</p>');
        $quality = ['passed' => true, 'checks' => [['name' => 'research', 'passed' => true]]];
        $structured = ['title' => '合成跟踪报告'];
        $statuses = [
            'markdown' => ['status' => 'unsupported'], 'html' => ['status' => 'available'],
            'docx' => ['status' => 'unavailable', 'reason' => 'pandoc'], 'pdf' => ['status' => 'unsupported'],
        ];
        $execution->update([
            'skill_id' => 'yao-geo-tracking', 'status' => 'completed', 'quality_report' => $quality,
            'output_data' => ['structured' => $structured, 'html' => "$prefix/report.html"] + ($legacy
                ? ['pipeline_mode' => 'full', 'render_success' => true] : ['format_status' => $statuses]),
        ]);
        $loader = $this->createMock(SkillAssetLoader::class);
        $assets = new SkillAssets($execution->skill_id, '', [], '/mock/render.py', null, true);
        $loader->expects($this->once())->method('loadAll')->with($execution->skill_id)->willReturn($assets);
        $this->app->instance(SkillAssetLoader::class, $loader);
        $renderer = $this->createMock(RenderBridgeService::class);
        $renderer->expects($this->once())->method('render')->with($structured, $assets, $execution->id, true)
            ->willReturnCallback(function () use ($disk, $prefix, $statuses): RenderResult {
                $disk->put("$prefix/rerender-0123456789abcdef01234567/new.docx", '合成 Word');
                $disk->put("$prefix/rerender-0123456789abcdef01234567/new.html", '<p>第二版</p>');
                $statuses['docx'] = ['status' => 'available'];

                return new RenderResult(true, [
                    'html' => "$prefix/rerender-0123456789abcdef01234567/new.html", 'docx' => "$prefix/rerender-0123456789abcdef01234567/new.docx",
                ], formatStatus: $statuses);
            });
        $this->app->instance(RenderBridgeService::class, $renderer);
        Sanctum::actingAs($user, ['*']);
        $endpoint = "/api/v1/executions/{$execution->id}";
        $this->forTenant($tenant)->getJson($endpoint)->assertOk()->assertJsonPath('can_rerender', true);
        foreach ([1, 2] as $_) {
            $this->forTenant($tenant)->postJson($endpoint.'/rerender')->assertOk()
                ->assertJsonPath('execution.can_rerender', false)
                ->assertJsonPath('execution.available_formats', ['html', 'docx'])
                ->assertJsonPath('execution.format_status.docx.status', 'available')
                ->assertJsonPath('execution.article_id', null)
                ->assertJsonPath('execution.token_usage', 66047);
        }
        $saved = $execution->fresh();
        $this->assertSame($quality, $saved->quality_report);
        $this->assertSame("$prefix/report.html", $saved->output_data['html']);
        $this->assertSame(['status' => 'available'], $saved->output_data['format_status']['docx']);
        $disk->assertMissing("$prefix/rerender-0123456789abcdef01234567/new.html");
        $this->assertSame(0, $saved->output_data['render_recovery']['model_calls']);
        $this->assertSame('合成 Word', $this->forTenant($tenant)->get($endpoint.'/download/docx')->assertOk()->streamedContent());
        $this->assertSame('<p>初版</p>', $this->forTenant($tenant)->get($endpoint.'/download/html')->assertOk()->streamedContent());
        $this->assertDatabaseCount('articles', 0);
        $this->assertDatabaseCount('model_call_logs', 0);
        Http::assertNothingSent();
    }

    public function test_a_blocking_content_verdict_and_existing_article_survive_optional_format_recovery(): void
    {
        [$tenant, $user, $execution] = $this->fixture('content-verdict');
        TenantContext::set($tenant);
        $article = Article::create([
            'project_id' => $execution->project_id, 'title' => '原编辑稿', 'body_markdown' => '用户已编辑的正文。',
        ]);
        TenantContext::forget();
        $disk = Storage::fake('reports');
        $quality = ['passed' => false, 'blocking' => true, 'issues' => ['research_evidence']];
        $path = "executions/{$execution->id}/rerender-0123456789abcdef01234567/report.html";
        $oldPath = "executions/{$execution->id}/existing.html";
        $disk->put($oldPath, '<p>原报告</p>');
        $disk->put($path, '<p>含字体的新报告</p>');
        $execution->update([
            'skill_id' => 'yao-geo-execution-roadmap', 'article_id' => $article->id, 'quality_report' => $quality,
            'output_data' => [
                'structured' => ['title' => '合成路线图'], 'html' => $oldPath,
                'format_status' => ['html' => ['status' => 'failed', 'reason' => 'html_fonts']],
            ],
        ]);
        $this->mockRenderer(new RenderResult(true, ['html' => $path], formatStatus: ['html' => ['status' => 'available']]));
        Sanctum::actingAs($user, ['*']);
        $this->forTenant($tenant)->postJson("/api/v1/executions/{$execution->id}/rerender")->assertOk()
            ->assertJsonPath('execution.status', 'failed')->assertJsonPath('execution.article_id', $article->id)
            ->assertJsonPath('execution.available_formats', ['html'])->assertJsonPath('execution.quality_report', $quality);
        $this->assertSame($path, $execution->fresh()->output_data['html']);
        $this->assertSame('<p>原报告</p>', $disk->get($oldPath));
        $this->assertSame($article->body_markdown, $article->fresh()->body_markdown);
        $this->assertDatabaseCount('articles', 1);
        $this->assertDatabaseCount('model_call_logs', 0);
        Http::assertNothingSent();
    }

    public function test_pending_billing_and_foreign_replacement_files_are_never_delivered(): void
    {
        [$tenant, $user, $execution] = $this->fixture('pending');
        $disk = Storage::fake('reports');
        $path = 'executions/'.($execution->id + 1).'/rerender-0123456789abcdef01234567/report.pdf';
        $disk->put($path, '其他工作区文件');
        $original = ['structured' => ['title' => '合成报告'], 'format_status' => ['pdf' => ['status' => 'unavailable']]];
        $execution->update(['status' => 'completed', 'output_data' => $original,
            'billing_pending_result' => ['kind' => 'model_attempt_prepared']]);
        $this->mockRenderer(new RenderResult(true, ['pdf' => $path], formatStatus: ['pdf' => ['status' => 'available']]));
        Sanctum::actingAs($user, ['*']);
        $url = "/api/v1/executions/{$execution->id}";
        $this->forTenant($tenant)->postJson($url.'/rerender')->assertUnprocessable();
        $execution->update(['billing_pending_result' => null]);
        $this->forTenant($tenant)->postJson($url.'/rerender')->assertUnprocessable();
        $this->forTenant($tenant)->get($url.'/download/pdf')->assertNotFound();
        $this->assertSame($original, $execution->fresh()->output_data);
        Http::assertNothingSent();
    }

    public function test_rerender_still_enforces_content_quality_before_creating_an_article(): void
    {
        [$tenant, $user, $execution] = $this->fixture('quality');
        $this->mockRenderer(new RenderResult(true, [], ['passed' => true, 'checks' => [], 'publishable_article_sha256' => hash('sha256', '太短')], publishableArticle: '太短'));
        Sanctum::actingAs($user, ['*']);
        $this->forTenant($tenant)->postJson('/api/v1/executions/'.$execution->id.'/rerender')
            ->assertOk()->assertJsonPath('execution.status', 'failed')
            ->assertJsonPath('execution.quality_report.passed', false);
        $this->assertDatabaseCount('articles', 0);
        $this->assertSame(66047, $execution->fresh()->token_usage);
    }

    public function test_non_format_failures_cannot_rerender(): void
    {
        [$tenant, $user, $execution] = $this->fixture('billing');
        $renderer = $this->createMock(RenderBridgeService::class);
        $renderer->expects($this->never())->method('render');
        $this->app->instance(RenderBridgeService::class, $renderer);
        Sanctum::actingAs($user, ['*']);

        $execution->update(['quality_report' => ['issues' => ['core_answer_80_to_120_chars']]]);
        $this->forTenant($tenant)->postJson('/api/v1/executions/'.$execution->id.'/rerender')->assertUnprocessable();
        $this->assertDatabaseCount('articles', 0);
    }

    public function test_rerender_rejects_another_tenant_and_concurrent_request(): void
    {
        [$tenant, $user, $execution] = $this->fixture('owner');
        [$otherTenant, $otherUser] = $this->fixture('other');
        $renderer = $this->createMock(RenderBridgeService::class);
        $renderer->expects($this->never())->method('render');
        $this->app->instance(RenderBridgeService::class, $renderer);
        Sanctum::actingAs($otherUser, ['*']);
        $this->forTenant($otherTenant)->postJson('/api/v1/executions/'.$execution->id.'/rerender')->assertNotFound();
        Sanctum::actingAs($user, ['*']);
        $lock = Cache::lock('skill-render-recovery:'.$tenant->id.':'.$execution->id, 240);
        $this->assertTrue($lock->get());
        try {
            $this->forTenant($tenant)->postJson('/api/v1/executions/'.$execution->id.'/rerender')->assertConflict();
        } finally {
            $lock->release();
        }
    }

    private function mockRenderer(RenderResult $result): void
    {
        $renderer = $this->createMock(RenderBridgeService::class);
        $renderer->expects($this->once())->method('render')->willReturn($result);
        $this->app->instance(RenderBridgeService::class, $renderer);
        $loader = $this->createMock(SkillAssetLoader::class);
        $loader->method('loadAll')->willReturn(new SkillAssets('yao-geo-explainer-builder', '', [], '/mock/render.py', null, true));
        $this->app->instance(SkillAssetLoader::class, $loader);
    }

    private function fixture(string $name): array
    {
        $tenant = Tenant::create(['name' => $name, 'subdomain' => $name, 'plan' => 'points', 'status' => 'active']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        TenantContext::set($tenant);
        $project = Project::create(['name' => $name, 'owner_id' => $user->id]);
        $execution = SkillExecution::create([
            'project_id' => $project->id, 'user_id' => $user->id, 'skill_id' => 'yao-geo-explainer-builder',
            'skill_name' => '解释型文章', 'category' => 'geo-content-production', 'stage_index' => 2,
            'status' => 'failed', 'token_usage' => 66047,
            'quality_report' => ['passed' => false, 'blocking' => true, 'issues' => ['docx_long_tokens_wrapped']],
            'output_data' => ['structured' => ['core_answer' => str_repeat('字', 100)]],
        ]);
        TenantContext::forget();

        return [$tenant, $user, $execution];
    }

    private function forTenant(Tenant $tenant): self
    {
        return $this->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])->withHeader('X-Tenant', $tenant->subdomain);
    }
}
