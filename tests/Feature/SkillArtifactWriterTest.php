<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ModelCallLog;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DTOs\SkillBrief;
use App\Services\Enums\SkillCategory;
use App\Services\SkillAgent\SkillArtifactWriter;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 交付落库 · 特征化测试（计划 §7 第 3 步：`SkillAgentRunnerService` 按职责拆小的第九桶）。
 *
 * 三条执行路（单次调用 / 同进程分段 / 接力）最后都要做这两件事：建文章、把最终质检报告
 * 写回下载文件。搬进 `SkillArtifactWriter` 的正是这两件 —— 因为它们是**同一份口径**：
 *   - 哪些技能算"内容生产类"（决定要不要建文章）；
 *   - 文章字段怎么填（标题取正文 H1、摘要取正文前 200 字、类型按技能名分流、评分走同一个评分器）；
 *   - 质检报告写盘失败时必须把 `quality-report` 从输出路径里摘掉（不能让客户下到旧结论）。
 *
 * 这些口径以前只有"跑一次真技能看看"才能验，现在可以逐条钉住。
 */
class SkillArtifactWriterTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()->create([
            'subdomain' => 'artifact-writer',
            'name' => 'WRITER',
            'plan' => 'basic',
            'status' => 'active',
        ]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'admin']);

        TenantContext::set($this->tenant);
        $this->project = Project::query()->create([
            'owner_id' => $this->user->id,
            'name' => '落库项目',
            'brand_name' => 'Acme',
            'brand_url' => 'https://acme.example.test',
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();

        parent::tearDown();
    }

    private function writer(): SkillArtifactWriter
    {
        return app(SkillArtifactWriter::class);
    }

    private function brief(string $skillId = 'yao-geo-explainer-builder'): SkillBrief
    {
        return new SkillBrief(
            skillId: $skillId,
            projectId: $this->project->id,
            userId: $this->user->id,
            fields: [],
            query: null,
            executionId: null,
        );
    }

    /** 只有内容生产类才建文章：诊断类技能由控制器建，这里建了就是重复。 */
    public function test_only_content_skills_produce_an_article(): void
    {
        $writer = $this->writer();

        $this->assertTrue($writer->isContentSkill(SkillCategory::GeoContentProduction));
        $this->assertTrue($writer->isContentSkill(SkillCategory::GeoKnowledgeAssets));
        $this->assertFalse($writer->isContentSkill(SkillCategory::GeoStrategy));
        $this->assertFalse($writer->isContentSkill(SkillCategory::GeoResearch));
        $this->assertFalse($writer->isContentSkill(SkillCategory::GeoMeasurement));
        $this->assertFalse($writer->isContentSkill(SkillCategory::GeoPageTechnical));
        $this->assertFalse($writer->isContentSkill(SkillCategory::GeoOperations));
    }

    public function test_content_article_is_created_without_wallet_confirmation(): void
    {

        $execution = SkillExecution::query()->create([
            'project_id' => $this->project->id, 'user_id' => $this->user->id,
            'skill_id' => 'yao-geo-explainer-builder', 'skill_name' => 'Explainer',
            'category' => SkillCategory::GeoContentProduction->value,
            'status' => 'running', 'execution_mode' => 'sync', 'input_data' => [],
        ]);
        $log = ModelCallLog::query()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'user_id' => $this->user->id, 'skill_execution_id' => $execution->id,
            'provider' => 'gateway', 'model' => 'text', 'status' => 'completed',
            'prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30,
            'duration_seconds' => 1.0, 'metadata' => ['points_deduction_status' => 'pending'],
        ]);
        $brief = new SkillBrief('yao-geo-explainer-builder', $this->project->id, $this->user->id,
            executionId: $execution->id);

        $this->assertNotNull($this->writer()->createArticleFromOutput($brief, '# PRIVATE_ARTICLE',
            $brief->skillId, $execution->id));
    }

    /**
     * 文章字段是冻结口径：标题取正文里的一级标题；摘要取去标记后的前 200 字；
     * 类型按技能名分流；状态是 editing（要人工过一眼才发）。
     */
    public function test_article_fields_are_frozen(): void
    {
        $content = "# 品牌如何被 AI 搜索引擎看见\n\n这是一段" . str_repeat('正文内容', 80) . "。";

        $article = $this->writer()->createArticleFromOutput($this->brief(), $content, 'yao-geo-explainer-builder');

        $this->assertInstanceOf(Article::class, $article);
        $this->assertSame('品牌如何被 AI 搜索引擎看见', $article->title, '标题取正文 H1');
        $this->assertSame($content, $article->body_markdown, '正文原样进库');
        $this->assertSame('explainer', $article->content_type, '技能名含 explainer → explainer 类型');
        $this->assertSame('editing', $article->status);
        $this->assertSame($this->project->id, $article->project_id);
        $this->assertSame($this->user->id, $article->author_id);
        $this->assertSame('manual', $article->source_type, '没有 executionId 就是人工来源');
        $this->assertNull($article->skill_execution_id);
        $this->assertSame(mb_strlen(preg_replace('/[#*`\[\]]+/', '', $content)), $article->word_count);
        $this->assertGreaterThan(0, $article->quality_score, '评分走的是同一个质量评分器');
        $this->assertLessThanOrEqual(200, mb_strlen((string) $article->summary), '摘要最多 200 字');
        $this->assertStringNotContainsString('#', (string) $article->summary, '摘要里不该留 Markdown 记号');
    }

    /** 没写成 H1 就退回时间戳标题：不能拿正文第一行硬当标题。 */
    public function test_article_without_h1_falls_back_to_a_timestamp_title(): void
    {
        $article = $this->writer()->createArticleFromOutput($this->brief(), '一段没有标题的正文。', 'yao-geo-intent-miner');

        $this->assertInstanceOf(Article::class, $article);
        $this->assertStringContainsString('Skill 生成内容', $article->title);
        $this->assertSame('general', $article->content_type);
    }

    public function test_skill_id_controls_the_content_type(): void
    {
        $writer = $this->writer();

        $this->assertSame('comparison', $writer->createArticleFromOutput($this->brief(), '# t', 'yao-geo-comparison-x')->content_type);
        $this->assertSame('ranking', $writer->createArticleFromOutput($this->brief(), '# t', 'yao-geo-ranking-x')->content_type);
        $this->assertSame('general', $writer->createArticleFromOutput($this->brief(), '# t', 'yao-geo-other-x')->content_type);
    }

    /** 带 executionId 的文章要挂回执行记录（来源与归属都不能丢）。 */
    public function test_article_keeps_its_execution_link(): void
    {
        // 真建一条执行记录：skill_execution_id 有外键，编个号会被约束挡掉（返回 null）。
        TenantContext::set($this->tenant);
        $execution = SkillExecution::query()->create([
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'skill_id' => 'yao-geo-explainer-builder',
            'skill_name' => '解释型文章',
            'category' => SkillCategory::GeoContentProduction->value,
            'stage_index' => 1,
            'status' => 'completed',
            'execution_mode' => 'sync',
            'input_data' => [],
        ]);

        $brief = new SkillBrief(
            skillId: 'yao-geo-explainer-builder',
            projectId: $this->project->id,
            userId: $this->user->id,
            fields: [],
            query: null,
            executionId: $execution->id,
        );

        $article = $this->writer()->createArticleFromOutput($brief, '# 标题', 'yao-geo-explainer-builder', $execution->id);

        $this->assertSame('skill_execution', $article->source_type);
        $this->assertSame($execution->id, $article->skill_execution_id);
        $this->assertSame('yao-geo-explainer-builder', $article->source_skill);
    }

    /** 落最终质检报告：写盘成功就留着路径；失败就把路径摘掉（不能留着指不存在的文件）。 */
    public function test_final_quality_report_is_written_or_the_path_is_removed(): void
    {
        $disk = Storage::disk('reports');
        $path = 'executions/9001/quality-report.json';
        $report = ['passed' => false, 'score' => 62, 'checks' => [['name' => '内容长度', 'passed' => false]]];

        $paths = ['markdown' => 'executions/9001/report.md', 'quality-report' => $path];
        $this->writer()->storeFinalQualityReport($report, $paths);

        $this->assertSame($path, $paths['quality-report'], '写成功时路径要留着');
        $this->assertSame($report, json_decode((string) $disk->get($path), true), '写进去的就是落库的那份结论');

        // 没有 quality-report 路径 → 什么都不做（不能凭空造一个）
        $without = ['markdown' => 'executions/9001/report.md'];
        $this->writer()->storeFinalQualityReport($report, $without);
        $this->assertSame(['markdown' => 'executions/9001/report.md'], $without);

        // 写盘失败（路径指向一个目录）→ 摘掉路径，且不抛异常
        $broken = ['quality-report' => 'executions/9001'];
        $this->writer()->storeFinalQualityReport($report, $broken);
        $this->assertArrayNotHasKey('quality-report', $broken, '写不进去就不能留一个指不存在的下载入口');

        $disk->deleteDirectory('executions/9001');
    }
}
