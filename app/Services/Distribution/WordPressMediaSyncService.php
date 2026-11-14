<?php

namespace App\Services\Distribution;

use App\Models\PublishingChannel;
use App\Services\Distribution\Publishers\WordPressRestPublisher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WordPress 正文内嵌图上传 + URL 替换（复刻 GEOFlow WordPressMediaSyncService）
 *
 * D1: 分发到 WordPress 时，正文中的本站图片自动上传到 WP 媒体库，
 * URL 替换为 WP 媒体 URL。第三方外链图不搬运。单张失败不阻塞整篇发布。
 */
class WordPressMediaSyncService
{
    /**
     * 处理正文内嵌图：提取 → 上传 → 替换 URL
     *
     * @return array{content: string, uploaded: int, failed: int}
     */
    public function syncContentImages(PublishingChannel $channel, string $content): array
    {
        $imageUrls = $this->extractImageUrls($content);
        $localImageUrls = $this->filterLocalImages($imageUrls);

        if (empty($localImageUrls)) {
            return ['content' => $content, 'uploaded' => 0, 'failed' => 0];
        }

        $publisher = app(WordPressRestPublisher::class);
        $uploaded = 0;
        $failed = 0;

        foreach ($localImageUrls as $originalUrl) {
            $mediaUrl = $publisher->uploadImageToWordPress($channel, $originalUrl);

            if ($mediaUrl !== null) {
                $content = $this->replaceUrlInContent($content, $originalUrl, $mediaUrl);
                $uploaded++;
            } else {
                // D1.4: 单张失败 → 保留原 URL，不阻塞整篇发布
                Log::warning('WordPress media sync: single image upload failed, keeping original URL', [
                    'channel_id' => $channel->id,
                    'image_url' => $originalUrl,
                ]);
                $failed++;
            }
        }

        return ['content' => $content, 'uploaded' => $uploaded, 'failed' => $failed];
    }

    /**
     * 从 Markdown 和 HTML 中提取所有图片 URL（去重）
     *
     * @return string[]
     */
    private function extractImageUrls(string $content): array
    {
        $urls = [];

        // Markdown: ![alt](url)
        if (preg_match_all('/!\[[^\]]*\]\(([^)]+)\)/', $content, $matches)) {
            $urls = array_merge($urls, $matches[1]);
        }

        // HTML: <img src="url">
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/', $content, $matches)) {
            $urls = array_merge($urls, $matches[1]);
        }

        return array_values(array_unique($urls));
    }

    /**
     * 只保留本站图片（白名单：app.url / storage 路径）
     *
     * @param string[] $urls
     * @return string[]
     */
    private function filterLocalImages(array $urls): array
    {
        $baseUrl = rtrim((string) config('app.url'), '/');
        $storagePath = '/storage/';

        return array_values(array_filter($urls, function (string $url) use ($baseUrl, $storagePath) {
            // 绝对 URL 匹配 app.url
            if (str_starts_with($url, $baseUrl . '/')) {
                return true;
            }
            // 相对路径匹配 storage
            if (str_starts_with($url, $storagePath)) {
                return true;
            }

            return false;
        }));
    }

    /**
     * 在内容中替换单个 URL（处理 Markdown 和 HTML 语法）
     */
    private function replaceUrlInContent(string $content, string $originalUrl, string $newUrl): string
    {
        // Markdown: ![alt](originalUrl)
        $content = preg_replace(
            '/(!\[[^\]]*\]\()' . preg_quote($originalUrl, '/') . '\)/',
            '$1' . $newUrl . ')',
            $content
        );

        // HTML: <img src="originalUrl">
        $content = str_replace(
            ['src="' . $originalUrl . '"', "src='" . $originalUrl . "'"],
            ['src="' . $newUrl . '"', "src='" . $newUrl . "'"],
            $content
        );

        return $content;
    }
}
