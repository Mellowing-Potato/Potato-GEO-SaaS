<?php

namespace App\Services\Reports;

use App\Models\Article;
use App\Models\SkillExecution;

class ReportAssetService
{
    public function createForGeoAnalysis(SkillExecution $execution, string $markdown, array $report, array $modelUsage): Article
    {
        $article = Article::query()
            ->where('skill_execution_id', $execution->id)
            ->where('source_type', 'geo_analysis')
            ->first();

        if (! $article) {
            $article = new Article();
            $article->project_id = $execution->project_id;
            $article->author_id = $execution->user_id;
            $article->version = 1;
        }

        $summary = trim((string) ($report['summary'] ?? ''));
        $score = $this->score($report['overall_score'] ?? null);

        $article->fill([
            'title' => $this->titleFromMarkdown($markdown)
                ?? 'GEO Analysis Report #'.$execution->id,
            'body_markdown' => $markdown,
            'summary' => $summary !== '' ? $summary : $this->summaryFromMarkdown($markdown),
            'content_type' => 'general',
            'status' => 'approved',
            'source_type' => 'geo_analysis',
            'source_skill' => $execution->skill_id,
            'skill_execution_id' => $execution->id,
            'quality_score' => $score ?? 0,
            'word_count' => $this->wordCount($markdown),
            'metadata' => $this->mergedMetadata($article, [
                'source_type' => 'geo_analysis',
                'source_execution_id' => $execution->id,
                'skill_id' => $execution->skill_id,
                'structured' => $report,
                'model_usage' => $modelUsage,
            ]),
        ]);

        $article->save();

        return $article;
    }

    public function createOrLinkForSkillExecution(
        SkillExecution $execution,
        string $markdown,
        ?int $candidateArticleId = null,
        ?array $structured = null,
        ?array $qualityReport = null,
    ): Article {
        $article = $this->findExistingSkillArticle($execution, $candidateArticleId);

        if (! $article) {
            $article = new Article();
            $article->project_id = $execution->project_id;
            $article->author_id = $execution->user_id;
            $article->version = 1;
        }

        $body = $markdown !== '' ? $markdown : $this->fallbackBody($execution, $structured);
        $qualityScore = $this->score(
            $qualityReport['score']
                ?? $qualityReport['overall_score']
                ?? $execution->quality_report['score']
                ?? $execution->quality_report['overall_score']
                ?? null,
        );

        $article->fill([
            'title' => $article->exists
                ? $article->title
                : $this->skillReportTitle($execution, $markdown, $structured, $qualityReport),
            'body_markdown' => $article->exists && $article->body_markdown
                ? $article->body_markdown
                : $body,
            'summary' => $article->summary ?: $this->summaryFromMarkdown($body),
            'content_type' => $article->content_type ?: $this->contentTypeFromSkill($execution->skill_id),
            'status' => $article->status ?: 'editing',
            'source_type' => 'skill_execution',
            'source_skill' => $execution->skill_id,
            'skill_execution_id' => $execution->id,
            'quality_score' => $qualityScore ?? $article->quality_score ?? 0,
            'word_count' => $article->word_count ?: $this->wordCount($body),
            'metadata' => $this->mergedMetadata($article, array_filter([
                'source_type' => 'skill_execution',
                'source_execution_id' => $execution->id,
                'skill_id' => $execution->skill_id,
                'structured' => $structured,
            ], fn ($value) => $value !== null)),
        ]);

        $article->save();

        return $article;
    }

    public function outputDataWithArticle(array $outputData, Article $article): array
    {
        return array_merge($outputData, [
            'article_id' => $article->id,
            'article_url' => "/api/v1/articles/{$article->id}",
            'article_download_url' => "/api/v1/articles/{$article->id}/download",
        ]);
    }

    private function findExistingSkillArticle(SkillExecution $execution, ?int $candidateArticleId): ?Article
    {
        if ($execution->article_id) {
            $article = Article::query()
                ->whereKey($execution->article_id)
                ->where('project_id', $execution->project_id)
                ->first();

            if ($article) {
                return $article;
            }
        }

        if ($candidateArticleId) {
            $article = Article::query()
                ->whereKey($candidateArticleId)
                ->where('project_id', $execution->project_id)
                ->first();

            if ($article) {
                return $article;
            }
        }

        return Article::query()
            ->where('skill_execution_id', $execution->id)
            ->where('source_type', 'skill_execution')
            ->first();
    }

    private function mergedMetadata(Article $article, array $metadata): array
    {
        return array_merge($article->metadata ?? [], $metadata);
    }

    private function titleFromMarkdown(string $markdown): ?string
    {
        if (preg_match('/^#\s+(.+)$/m', $markdown, $matches)) {
            return mb_substr(trim($matches[1]), 0, 500);
        }

        return null;
    }

    private function skillReportTitle(
        SkillExecution $execution,
        string $markdown,
        ?array $structured,
        ?array $qualityReport,
    ): string {
        $storedQualityReport = is_array($execution->quality_report) ? $execution->quality_report : [];
        $candidates = [
            $this->titleFromMarkdown($markdown),
            $structured['title'] ?? null,
            $qualityReport['report'] ?? null,
            $storedQualityReport['report'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return mb_substr(trim($candidate), 0, 500);
            }
        }

        return $execution->skill_name.' Report #'.$execution->id;
    }

    private function summaryFromMarkdown(string $markdown): string
    {
        $plain = trim(strip_tags(preg_replace('/[#*`\[\]\(\)>|_-]+/', ' ', $markdown) ?? $markdown));
        $plain = preg_replace('/\s+/', ' ', $plain) ?? $plain;

        return mb_substr($plain, 0, 500);
    }

    private function fallbackBody(SkillExecution $execution, ?array $structured): string
    {
        if ($structured) {
            return "# {$execution->skill_name} Report #{$execution->id}\n\n"
                .json_encode($structured, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        return "# {$execution->skill_name} Report #{$execution->id}\n\nNo text output is available yet.";
    }

    private function contentTypeFromSkill(string $skillId): string
    {
        return match (true) {
            str_contains($skillId, 'comparison') => 'comparison',
            str_contains($skillId, 'ranking') => 'ranking',
            str_contains($skillId, 'explainer') => 'explainer',
            default => 'general',
        };
    }

    private function score(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0, min(100, (int) round((float) $value)));
    }

    private function wordCount(string $markdown): int
    {
        $plain = trim(strip_tags(preg_replace('/[#*`\[\]\(\)>|_-]+/', ' ', $markdown) ?? $markdown));

        return mb_strlen($plain);
    }
}
