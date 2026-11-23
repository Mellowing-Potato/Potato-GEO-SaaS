<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DTOs\SkillBrief;
use App\Services\Enums\SkillCategory;
use App\Services\SkillAgent\RenderBridgeService;
use App\Services\SkillAgent\RenderResult;
use App\Services\SkillAgent\SkillAssetLoader;
use App\Services\SkillAgent\SkillAssets;
use App\Services\SkillAgent\SkillDeliveryAssembler;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TitleCompactDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_gate_rejects_bad_count_duplicate_ids_and_missing_files(): void
    {
        $data = $this->example();
        $assembler = app(SkillDeliveryAssembler::class);
        $brief = new SkillBrief('yao-geo-title-optimizer', 1, 2);
        unset($data['title_candidates'][0]['id']);
        $data = $assembler->prepareStructuredInput($data, $brief);
        $this->assertSame('T1', $data['title_candidates'][0]['id']);
        $rendered = new RenderResult(true, [
            'markdown' => 'a.md', 'html' => 'a.html', 'docx' => 'a.docx',
            'pdf' => 'a.pdf', 'quality-report' => 'quality-report.json',
        ], ['passed' => true]);
        $this->assertSame([], $assembler->titleDeliveryIssues($data, $rendered));
        $bad = $data;
        $bad['title_candidates'] = array_slice($bad['title_candidates'], 0, 4);
        $this->assertContains('候选标题必须为 5–8 个', $assembler->titleDeliveryIssues($bad, $rendered));
        $bad = $data;
        $bad['title_candidates'][1]['id'] = 'T1';
        $this->assertContains('候选标题 ID 重复', $assembler->titleDeliveryIssues($bad, $rendered));
        $bad = $data;
        $bad['recommended_id'] = 'T9';
        $this->assertContains('推荐 ID 未指向候选标题', $assembler->titleDeliveryIssues($bad, $rendered));
        $this->assertContains('缺少 pdf 文件', $assembler->titleDeliveryIssues($data, new RenderResult(true, ['markdown' => 'a.md'], ['passed' => true])));
    }

    public function test_overlay_runs_through_render_bridge_with_readable_formats(): void
    {
        if (! getenv('GEO_TEST_TITLE_PYTHON')) {
            $this->markTestSkipped('Set GEO_TEST_TITLE_PYTHON to an isolated Python with WeasyPrint or ReportLab.');
        }
        $previous = getenv('PYTHON_BIN');
        config(['geo.python_bin' => getenv('GEO_TEST_TITLE_PYTHON')]);
        putenv('PYTHON_BIN='.getenv('GEO_TEST_TITLE_PYTHON'));
        Storage::fake('reports');
        try {
            $script = app(SkillAssetLoader::class)->getRenderScript('yao-geo-title-optimizer');
            $this->assertStringContainsString('skill-overlays', str_replace('\\', '/', $script));
            $loaded = app(SkillAssetLoader::class)->loadAll('yao-geo-title-optimizer');
            $this->assertStringContainsString('只交付候选标题、推荐标题和使用建议', $loaded->skillMd);
            $this->assertStringNotContainsString('旧版研究表', $loaded->skillMd);
            $assets = new SkillAssets('yao-geo-title-optimizer', '# 标题', [], $script, null, true);
            $input = app(SkillDeliveryAssembler::class)->prepareStructuredInput($this->example(), new SkillBrief('yao-geo-title-optimizer', 1, 2));
            $result = (new RenderBridgeService)->render($input, $assets, 90123);
            $this->assertTrue($result->success, $result->errorMessage ?? 'render failed');
            $this->assertSame([], app(SkillDeliveryAssembler::class)->titleDeliveryIssues($input, $result));
            foreach (['markdown', 'html', 'docx', 'pdf', 'quality-report'] as $format) {
                $this->assertArrayHasKey($format, $result->renderedPaths);
                $this->assertTrue(Storage::disk('reports')->exists($result->renderedPaths[$format]));
            }
            $markdown = Storage::disk('reports')->get($result->renderedPaths['markdown']);
            $html = Storage::disk('reports')->get($result->renderedPaths['html']);
            $this->assertStringContainsString('T3 · 库存预警阈值怎么设置？', $markdown);
            $this->assertStringContainsString('T3 · 库存预警阈值怎么设置？', $html);
            $this->assertStringNotContainsString('来源账本', $markdown);
            $this->assertStringNotContainsString('storage/app', $html);
        } finally {
            $previous === false ? putenv('PYTHON_BIN') : putenv('PYTHON_BIN='.$previous);
        }
    }

    public function test_execution_result_api_preserves_recommended_candidate_id(): void
    {
        config(['app.tenant_domain' => 'geo.example.test']);
        $tenant = Tenant::create(['subdomain' => 'title-api', 'name' => 'Title API', 'plan' => 'basic', 'status' => 'active']);
        TenantContext::set($tenant);
        try {
            $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
            $project = Project::create(['owner_id' => $user->id, 'name' => 'Title API']);
            $structured = app(SkillDeliveryAssembler::class)->prepareStructuredInput(
                $this->example(), new SkillBrief('yao-geo-title-optimizer', $project->id, $user->id),
            );
            $execution = SkillExecution::create([
                'project_id' => $project->id, 'user_id' => $user->id,
                'skill_id' => 'yao-geo-title-optimizer', 'skill_name' => '标题优化',
                'category' => SkillCategory::GeoResearch->value, 'stage_index' => 2,
                'status' => 'completed', 'execution_mode' => 'async', 'input_data' => [],
                'output_data' => ['structured' => $structured, 'markdown' => 'executions/test/title.md'],
            ]);
            Sanctum::actingAs($user, ['*']);
            $response = $this->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])
                ->withHeader('X-Tenant', $tenant->subdomain)
                ->getJson('/api/v1/executions/'.$execution->id);
            $response->assertOk()->assertJsonPath('structured.recommended_id', 'T3')
                ->assertJsonPath('structured.title_candidates.2.id', 'T3')
                ->assertJsonPath('structured.title_candidates.2.title', '库存预警阈值怎么设置？')
                ->assertJsonPath('available_formats.0', 'markdown');
        } finally {
            TenantContext::forget();
        }
    }

    private function example(): array
    {
        return json_decode(file_get_contents(base_path('skill-overlays/yao-geo-title-optimizer/examples/compact/report_input.json')), true, flags: JSON_THROW_ON_ERROR);
    }
}
