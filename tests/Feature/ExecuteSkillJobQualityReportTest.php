<?php

namespace Tests\Feature;

use App\Jobs\ExecuteSkillJob;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Content\MaterialLibraryService;
use App\Services\Contracts\SkillAgentRunner;
use App\Services\DTOs\SkillBrief;
use App\Services\DTOs\SkillExecutionResult;
use App\Services\Reports\ReportAssetService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExecuteSkillJobQualityReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    #[DataProvider('qualityReports')]
    public function test_job_persists_the_quality_verdict_and_evidence_without_discarding_the_draft(
        ?array $report,
        ?bool $expectedPassed,
        ?int $expectedScore,
    ): void {
        $execution = $this->runJob($report);
        $stored = $execution->quality_report;

        if ($report === null) {
            $this->assertNull($stored);
        } else {
            $this->assertSame($expectedPassed, $stored['passed']);
            $this->assertSame($expectedScore, $stored['score']);
            $this->assertSame($report['checks'] ?? [], $stored['checks']);
            foreach ($report as $key => $value) {
                if (! in_array($key, ['score', 'passed', 'checks'], true)) {
                    $this->assertSame($value, $stored[$key], $key.' must survive job persistence');
                }
            }
        }

        $this->assertSame('completed', $execution->status);
        $this->assertNotNull($execution->article_id);
        $this->assertDatabaseHas('articles', [
            'id' => $execution->article_id,
            'status' => 'editing',
            'body_markdown' => "# 已生成文章\n\n保留供用户修改的正文。\n",
        ]);
        $this->assertDatabaseCount('model_call_logs', 0);
    }

    public static function qualityReports(): array
    {
        $passedCheck = ['name' => 'publishable_article_matches_review', 'passed' => true];
        $failedCheck = ['name' => 'publishable_article_matches_review', 'passed' => false];

        return [
            'Python成功报告没有分数' => [[
                'passed' => true,
                'checks' => [$passedCheck],
                'publishable_article_sha256' => str_repeat('a', 64),
                'issues' => [],
                'next_action' => '可交付',
                'auto_fixes' => ['已中性改写'],
                'reviewed_at' => '2026-09-08T09:00:00',
                'generated_files' => ['markdown' => 'article.md'],
            ], true, null],
            '显式失败不能被高分盖掉' => [[
                'score' => 90, 'passed' => false, 'checks' => [$passedCheck],
                'issues' => ['missing_review_evidence'], 'next_action' => '修复后重新检查',
            ], false, 90],
            '正文不匹配的失败证据保留' => [[
                'score' => 80, 'passed' => false, 'checks' => [$failedCheck],
                'publishable_article_sha256' => str_repeat('b', 64),
                'issues' => ['publishable_article_matches_review'], 'next_action' => '修复后重新检查',
            ], false, 80],
            '失败检查优先于顶层通过' => [[
                'score' => 80, 'passed' => true, 'checks' => [$failedCheck],
            ], false, 80],
            '失败检查优先于旧分数' => [['score' => 80, 'checks' => [$failedCheck]], false, 80],
            '旧报告仅分数通过' => [['score' => 60], true, 60],
            '旧报告仅分数未通过' => [['score' => 59], false, 59],
            '旧overall_score兼容' => [['overall_score' => 85], true, 85],
            '仅检查项无分数' => [['checks' => [$passedCheck]], true, null],
            '旧失败列表优先于分数' => [[
                'overall_score' => 85, 'failed_checks' => ['docx_report_exists'],
            ], false, 85],
            '没有报告' => [null, null, null],
        ];
    }

    public function test_job_normalizes_legacy_named_checks_and_preserves_failure_details(): void
    {
        $execution = $this->runJob([
            'overall_score' => 85,
            'checks' => ['markdown_report_exists' => true, 'docx_report_exists' => false],
            'failed_checks' => ['docx_report_exists'],
            'issues' => ['Word 文件未生成'],
            'next_action' => '修复后重新检查',
        ]);

        $this->assertSame(85, $execution->quality_report['score']);
        $this->assertFalse($execution->quality_report['passed']);
        $this->assertSame([
            ['name' => 'Markdown 报告', 'passed' => true],
            ['name' => 'Word 报告', 'passed' => false],
        ], $execution->quality_report['checks']);
        $this->assertSame(['Word 文件未生成'], $execution->quality_report['issues']);
        $this->assertSame(['docx_report_exists'], $execution->quality_report['failed_checks']);
    }

    private function runJob(?array $report): SkillExecution
    {
        $tenant = Tenant::query()->create([
            'subdomain' => 'job-quality', 'name' => 'Job quality', 'plan' => 'basic', 'status' => 'active',
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        TenantContext::set($tenant);
        $project = Project::query()->create(['owner_id' => $user->id, 'name' => 'Quality project']);
        $execution = SkillExecution::query()->create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'skill_id' => 'yao-geo-explainer-builder',
            'skill_name' => '解释型文章',
            'category' => 'geo-content-production',
            'status' => 'pending',
            'execution_mode' => 'async',
            'input_data' => [],
        ]);
        $brief = new SkillBrief($execution->skill_id, $project->id, $user->id, executionId: $execution->id);
        TenantContext::forget();
        $runner = $this->createMock(SkillAgentRunner::class);
        $runner->expects($this->once())->method('execute')->willReturn(new SkillExecutionResult(
            executionId: $execution->id,
            skillId: $execution->skill_id,
            success: true,
            intermediateData: ['raw_output' => "# 已生成文章\n\n保留供用户修改的正文。\n"],
            qualityReport: $report,
        ));

        (new ExecuteSkillJob($execution->id, $brief->toArray(), $tenant->id))->handle(
            $runner,
            app(ReportAssetService::class),
            app(MaterialLibraryService::class),
        );

        return $execution->fresh();
    }
}
