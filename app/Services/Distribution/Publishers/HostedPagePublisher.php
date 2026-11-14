<?php

namespace App\Services\Distribution\Publishers;

use App\Models\Article;
use App\Models\ArticlePublication;
use App\Models\PublishingChannel;
use App\Models\Tenant;
use App\Services\Distribution\DistributionPublisherInterface;
use Illuminate\Support\Str;

/**
 * Publish an article to the SaaS-hosted public reading page.
 *
 * No content leaves this application. The normal review, compliance and
 * publication-record workflow still applies, making this suitable for demos.
 */
final class HostedPagePublisher implements DistributionPublisherInterface
{
    public function health(PublishingChannel $channel): array
    {
        return [
            'ok' => true,
            'status' => 'healthy',
            'latency_ms' => 0,
            'message' => '站内托管服务可用。',
        ];
    }

    public function publish(ArticlePublication $publication): array
    {
        $article = $publication->article;

        if (! $article) {
            return ['ok' => false, 'message' => '关联文章不存在。'];
        }

        $url = $this->articleUrl($article);
        if ($url === null) {
            return ['ok' => false, 'message' => '无法生成站内托管文章链接。'];
        }

        return [
            'ok' => true,
            'public' => true,
            'http_status' => 200,
            'remote_status' => 'published',
            'remote_id' => 'hosted-'.$article->tenant_id.'-'.$article->id,
            'remote_url' => $url,
            'message' => '文章已发布到站内托管页。',
        ];
    }

    public function update(ArticlePublication $publication): array
    {
        return $this->publish($publication);
    }

    public function delete(ArticlePublication $publication): array
    {
        return ['ok' => true, 'message' => '站内托管页由文章发布状态控制。'];
    }

    public function syncSiteSettings(PublishingChannel $channel, array $settings): array
    {
        return ['ok' => true, 'message' => '站内托管页使用当前租户设置。'];
    }

    private function articleUrl(Article $article): ?string
    {
        $tenant = Tenant::query()->find($article->tenant_id);
        if (! $tenant) {
            return null;
        }

        if (! is_string($article->slug) || trim($article->slug) === '') {
            $baseSlug = Str::slug((string) $article->title) ?: 'article';
            $article->forceFill(['slug' => $baseSlug.'-'.$article->id])->saveQuietly();
        }

        return route('reading.article', [
            'tenantSlug' => $tenant->subdomain,
            'slug' => $article->slug,
        ]);
    }
}
