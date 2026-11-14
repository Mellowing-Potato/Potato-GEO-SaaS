<?php

namespace App\Support;

use App\Models\Article;

/** Mirrors the article/report split used by the workspace content library. */
final class ArticleAssetClassifier
{
    public static function isArticle(Article $article): bool
    {
        $source = strtolower((string) $article->source_type);
        if ($source === 'geo_analysis' || self::isStructuredReport($article)) {
            return false;
        }

        $type = strtolower((string) $article->content_type);
        $skill = strtolower((string) ($article->source_skill ?: $article->skillExecution?->skill_id));
        $managed = in_array($type, ['explainer', 'comparison', 'ranking'], true);
        foreach (['title-optimizer', 'explainer', 'comparison', 'ranking', 'content-refiner', 'article-friendly'] as $term) {
            $managed = $managed || str_contains($skill, $term);
        }
        if ($source === 'skill_execution') {
            return $managed;
        }
        if ($managed || $type === 'general') {
            return true;
        }
        $body = (string) $article->body_markdown;
        return (bool) (preg_match('/^#\s+.+/m', $body) && preg_match('/^##\s+.+/m', $body))
            || (mb_strlen($body) > 120 && preg_match('/文章|指南|实战|对比|排行榜/u', (string) $article->title));
    }

    private static function isStructuredReport(Article $article): bool
    {
        $structured = $article->metadata['structured'] ?? null;
        if (is_array($structured) && self::hasReportKeys($structured)) {
            return true;
        }
        foreach ([$article->summary, $article->body_markdown] as $text) {
            $decoded = json_decode(trim((string) $text), true);
            if (is_array($decoded) && self::hasReportKeys($decoded)) {
                return true;
            }
        }
        return false;
    }

    private static function hasReportKeys(array $value): bool
    {
        foreach (['analysis', 'key_findings', 'findings', 'next_steps', 'confidence', 'overall_score', 'dimensions', 'phases', 'intent_clusters', 'recommendations'] as $key) {
            if (array_key_exists($key, $value)) {
                return true;
            }
        }
        return false;
    }
}
