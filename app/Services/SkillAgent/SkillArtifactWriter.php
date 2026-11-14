<?php

namespace App\Services\SkillAgent;

use App\Models\Article;
use App\Models\SkillExecution;
use App\Services\DTOs\SkillBrief;
use App\Services\Enums\SkillCategory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 交付落库（计划 §7 第 3 步：把 `SkillAgentRunnerService` 按职责拆小，「渲染」里的最后两步）。
 *
 * 三条路（单次调用 / 同进程分段 / 接力）走到最后都要做同样的两件事：
 *   1. 内容生产类技能 → 建一篇文章（标题取正文 H1、摘要取正文前 200 字、类型按技能名分流）；
 *   2. 把最终质检报告写回下载文件里，别让客户下到一份"渲染阶段旧结论"。
 *
 * 为什么单独成类：「哪些技能算内容生产类」与「文章字段怎么填」是**同一份口径**，
 * 单次调用与分段收尾各写一遍迟早分叉（分叉的表现是同一技能同步出文章、异步不出）。
 *
 * 判据：tests/Feature/SkillArtifactWriterTest.php 冻结构成与字段；
 * 端到端由 SkillWorkspaceFlowTest / PanoramaChunkedAnalysisTest / ReportAssetFlowTest 兜底。
 */
class SkillArtifactWriter
{
    public function __construct(private readonly SkillQualityReporter $qualityReporter) {}
    public function isContentSkill(SkillCategory $category): bool
    {
        return in_array($category, [
            SkillCategory::GeoContentProduction,
            SkillCategory::GeoKnowledgeAssets,
        ]);
    }

    /** 下载的质检文件与最后落库的检查结果保持一致，不留下渲染阶段的旧通过结论。 */
    public function storeFinalQualityReport(array $report, array &$outputPaths): void
    {
        $path = $outputPaths['quality-report'] ?? null;
        if (! is_string($path) || $path === '') {
            return;
        }

        try {
            if (! Storage::disk('reports')->put($path, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR))) {
                throw new \RuntimeException('Final quality report could not be stored.');
            }
        } catch (\Throwable $e) {
            unset($outputPaths['quality-report']);
            Log::warning('Final quality report unavailable', ['error' => $e->getMessage()]);
        }
    }

    public function createArticleFromOutput(SkillBrief $brief, string $content, string $skillId, ?int $executionId = null): ?Article
    {
        try {
            $title = 'Skill 生成内容 — '.now()->format('Y-m-d H:i');
            if (preg_match('/^#\s+(.+)$/m', $content, $m)) {
                $title = trim($m[1]);
            }

            $contentType = match (true) {
                str_contains($skillId, 'comparison') => 'comparison',
                str_contains($skillId, 'ranking') => 'ranking',
                str_contains($skillId, 'explainer') => 'explainer',
                default => 'general',
            };

            $plainText = strip_tags(preg_replace('/[#*`\[\]]+/', '', $content));
            $summary = mb_substr(trim($plainText), 0, 200);

            return Article::create([
                'project_id' => $brief->projectId,
                'author_id' => $brief->userId,
                'title' => $title,
                'body_markdown' => $content,
                'summary' => $summary,
                'content_type' => $contentType,
                'status' => 'editing',
                'source_type' => $executionId ? 'skill_execution' : 'manual',
                'source_skill' => $skillId,
                'skill_execution_id' => $executionId,
                'quality_score' => $this->qualityReporter->evaluateQuality($content, SkillCategory::GeoContentProduction),
                'word_count' => mb_strlen($plainText),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to create article from skill output', [
                'skill_id' => $skillId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
