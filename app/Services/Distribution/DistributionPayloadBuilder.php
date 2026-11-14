<?php

namespace App\Services\Distribution;

use App\Models\Article;
use App\Models\ArticlePublication;

class DistributionPayloadBuilder
{
    /**
     * Build the full distribution payload for an article.
     */
    public function build(ArticlePublication $publication): array
    {
        $article = $publication->article;

        return [
            'object' => 'geo_article_distribution',
            'action' => 'publish',
            'article' => [
                'id' => $article->id,
                'title' => $article->title,
                'slug' => $article->slug ?? 'article-' . $article->id,
                'summary' => $article->summary ?? '',
                'body_markdown' => $article->body_markdown ?? '',
                'content_type' => $article->content_type ?? 'explainer',
                'version' => $article->version ?? 1,
                'word_count' => $article->word_count ?? 0,
                'seo' => [
                    'title' => data_get($article->metadata, 'seo.title', $article->title),
                    'description' => data_get($article->metadata, 'seo.description', $article->summary),
                    'canonical_url' => data_get($article->metadata, 'seo.canonical_url'),
                ],
                'published_at' => $article->published_at?->toISOString(),
                'metadata' => $article->metadata ?? [],
            ],
            'publication' => [
                'id' => $publication->id,
                'channel_id' => $publication->publishing_channel_id,
                'idempotency_key' => $this->idempotencyKey($publication),
            ],
        ];
    }

    /**
     * Generate a stable idempotency key for a publication.
     */
    public function idempotencyKey(ArticlePublication $publication, string $action = 'publish'): string
    {
        return "article-{$publication->article_id}-channel-{$publication->publishing_channel_id}-{$action}-v{$publication->attempt_count}";
    }
}
