<?php

namespace App\Services\Distribution\Publishers;

use App\Models\ArticlePublication;
use App\Models\PublishingChannel;
use App\Services\Distribution\DistributionPublisherInterface;
use App\Services\Distribution\WordPressMediaSyncService;
use App\Services\Distribution\WordPressTaxonomySyncService;
use App\Support\OperationalTextRedactor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WordPressRestPublisher implements DistributionPublisherInterface
{
    public function __construct(
        private readonly WordPressTaxonomySyncService $taxonomy,
    ) {}
    public function health(PublishingChannel $channel): array
    {
        $baseUrl = rtrim($channel->endpoint_url, '/');
        $url = $baseUrl . '/wp-json';
        $timeout = (int) data_get($channel->settings, 'timeout', 10);

        // ─── Step 1: 可达性检查 ───
        $start = microtime(true);
        try {
            $response = Http::timeout($timeout)
                ->withHeaders(['Accept' => 'application/json'])
                ->get($url);
        } catch (\Throwable $e) {
            $latency = round((microtime(true) - $start) * 1000, 2);
            return ['ok' => false, 'latency_ms' => $latency, 'message' => 'WordPress health check failed: ' . $e->getMessage()];
        }
        $latency = round((microtime(true) - $start) * 1000, 2);

        if (! $response->successful()) {
            return ['ok' => false, 'latency_ms' => $latency, 'http_status' => $response->status(), 'message' => 'WP returned HTTP ' . $response->status()];
        }

        $data = $response->json() ?? [];
        $name = data_get($data, 'name', '');

        // Check for required endpoints
        $namespaces = data_get($data, 'namespaces', []);
        $hasWpV2 = in_array('wp/v2', $namespaces, true);

        if (! $hasWpV2) {
            return [
                'ok' => false,
                'latency_ms' => $latency,
                'site_name' => $name,
                'wp_version' => data_get($data, 'generator', ''),
                'has_wp_v2' => false,
                'message' => 'wp/v2 namespace not found',
            ];
        }

        // ─── D2: 认证探测 — 验证发布/上传权限 ───
        $canPublish = false;
        $canUpload = false;
        $authMessage = '';

        try {
            $meResponse = $this->requestFor($channel)
                ->get($baseUrl . '/wp-json/wp/v2/users/me', ['context' => 'edit']);

            if ($meResponse->status() === 401 || $meResponse->status() === 403) {
                return [
                    'ok' => false,
                    'site_name' => $name,
                    'has_wp_v2' => true,
                    'can_publish' => false,
                    'can_upload' => false,
                    'message' => '应用密码无效或账号无权访问（HTTP ' . $meResponse->status() . '）',
                ];
            }

            if ($meResponse->successful()) {
                $meData = $meResponse->json() ?? [];
                $capabilities = data_get($meData, 'capabilities', []);

                // 优先检查 capabilities 字段
                if (is_array($capabilities) && ! empty($capabilities)) {
                    $canPublish = ! empty($capabilities['publish_posts']) || ! empty($capabilities['edit_posts']);
                    $canUpload = ! empty($capabilities['upload_files']);
                } else {
                    // 退而验：users/me 200 + 能 GET posts?context=edit
                    $canPublish = true; // users/me context=edit 成功即有基本权限
                    $canUpload = true;
                    $postsCheck = $this->requestFor($channel)
                        ->get($baseUrl . '/wp-json/wp/v2/posts', ['context' => 'edit', 'per_page' => 1]);
                    if (! $postsCheck->successful()) {
                        $canPublish = false;
                    }
                }
            } else {
                $authMessage = 'users/me 返回 HTTP ' . $meResponse->status();
            }
        } catch (\Throwable $e) {
            $authMessage = '认证探测失败: ' . $e->getMessage();
        }

        // 构建结果
        $ok = $canPublish && $canUpload && empty($authMessage);
        $message = match (true) {
            ! $ok && ! empty($authMessage) => $authMessage,
            ! $canPublish && ! $canUpload => '应用密码有效但账号无发布权限（publish_posts）且无上传权限（upload_files）',
            ! $canPublish => '应用密码有效但账号无发布权限（publish_posts）',
            ! $canUpload => '应用密码有效但账号无上传权限（upload_files）',
            default => "WordPress site: {$name}",
        };

        return [
            'ok' => $ok,
            'latency_ms' => $latency,
            'site_name' => $name,
            'wp_version' => data_get($data, 'generator', ''),
            'has_wp_v2' => true,
            'can_publish' => $canPublish,
            'can_upload' => $canUpload,
            'message' => $message,
        ];
    }

    public function publish(ArticlePublication $publication): array
    {
        return $this->sendToWordPress($publication, 'POST');
    }

    public function update(ArticlePublication $publication): array
    {
        if (empty($publication->remote_id)) {
            return ['ok' => false, 'message' => 'No remote_id for WordPress update'];
        }

        return $this->sendToWordPress($publication, 'PUT');
    }

    public function delete(ArticlePublication $publication): array
    {
        if (empty($publication->remote_id)) {
            return ['ok' => false, 'message' => 'No remote_id for WordPress delete'];
        }

        $channel = $publication->channel;
        $baseUrl = rtrim($channel->endpoint_url, '/');
        $url = $baseUrl . '/wp-json/wp/v2/posts/' . $publication->remote_id;

        try {
            $response = $this->requestFor($channel)
                ->delete($url);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'WP delete failed: ' . $e->getMessage()];
        }

        if ($response->successful()) {
            return ['ok' => true, 'message' => 'Article deleted from WordPress'];
        }

        return [
            'ok' => false,
            'http_status' => $response->status(),
            'message' => 'WP delete returned HTTP ' . $response->status(),
        ];
    }

    public function syncSiteSettings(PublishingChannel $channel, array $settings): array
    {
        // WordPress doesn't support remote site settings sync
        return ['ok' => false, 'message' => 'Site settings sync not supported for WordPress channels'];
    }

    private function sendToWordPress(ArticlePublication $publication, string $method): array
    {
        $channel = $publication->channel;
        $article = $publication->article;

        $baseUrl = rtrim($channel->endpoint_url, '/');
        $postsUrl = $baseUrl . '/wp-json/wp/v2/posts';
        if ($method === 'PUT' && $publication->remote_id) {
            $postsUrl .= '/' . $publication->remote_id;
        }

        $channelConfig = $channel->resolvedChannelConfig();
        $postStatus = data_get($channelConfig, 'post_status', 'publish');

        $payload = [
            'title' => $article->title,
            'excerpt' => $article->summary ?? '',
            'status' => $postStatus,
            'slug' => $article->slug ?? 'article-' . $article->id,
        ];

        // D1: 先同步内嵌图到 WP 媒体库，替换 markdown 中的本站图 URL
        $bodyMarkdown = $article->body_markdown ?? '';
        $mediaSync = app(WordPressMediaSyncService::class);
        $syncResult = $mediaSync->syncContentImages($channel, $bodyMarkdown);
        $payload['content'] = $this->convertMarkdownToHtml($syncResult['content']);

        // Taxonomy: categories
        $categoryIds = $this->resolveCategories($channel, $channelConfig, $article);
        if (! empty($categoryIds)) {
            $payload['categories'] = $categoryIds;
        }

        // Taxonomy: tags
        $tagIds = $this->resolveTags($channel, $channelConfig, $article);
        if (! empty($tagIds)) {
            $payload['tags'] = $tagIds;
        }

        // Featured image (if hero image URL in metadata)
        $heroImageUrl = data_get($article->metadata, 'hero_image_url');
        if ($heroImageUrl) {
            $mediaId = $this->uploadMediaToWordPress($channel, $heroImageUrl, $article->title);
            if ($mediaId) {
                $payload['featured_media'] = $mediaId;
            }
        }

        try {
            $response = $method === 'POST'
                ? $this->requestFor($channel)->post($postsUrl, $payload)
                : $this->requestFor($channel)->put($postsUrl, $payload);
        } catch (\Throwable $e) {
            Log::warning('WordPress publish/update failed', [
                'publication_id' => $publication->id,
                'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'message' => 'WP request failed: ' . $e->getMessage()];
        }

        if (! $response->successful()) {
            $body = $response->json() ?? [];
            $message = data_get($body, 'message', 'WordPress returned HTTP ' . $response->status());
            return [
                'ok' => false,
                'http_status' => $response->status(),
                'message' => OperationalTextRedactor::redact($message),
            ];
        }

        $data = $response->json();

        // ⚠️ 2xx 不等于"发出去了"（2026-09-21 真靶站 E2E 实测的假成功）：
        // 目标站如果没配固定链接 / REST 路由没生效，`/wp-json/wp/v2/posts` 会被当成普通页面，
        // 返回 **200 + HTML**。旧代码只看 successful()，于是 remote_id / remote_url 取到空串，
        // publication 照样被记成 published、last_error 也是空 —— 客户看到「已发布」，
        // 实际什么都没发出去，事后也没有任何线索。
        // 判据：2xx 必须带 id + link（WordPress 的 post payload 一定有），否则算失败并说清原因。
        $remoteId = is_array($data) ? data_get($data, 'id') : null;
        $remoteLink = is_array($data) ? data_get($data, 'link') : null;
        if ((! is_int($remoteId) && ! is_string($remoteId)) || $remoteId === '' || ! is_string($remoteLink) || $remoteLink === '') {
            Log::warning('WordPress publish returned 2xx without a post payload', [
                'publication_id' => $publication->id,
                'http_status' => $response->status(),
                'content_type' => $response->header('Content-Type'),
            ]);

            return [
                'ok' => false,
                'http_status' => $response->status(),
                'message' => 'WordPress 返回了 2xx，但响应体不是文章 JSON（缺 id/link）：'
                    . '多半是站点固定链接没配置、REST 路由未生效，或请求被重定向到了首页。'
                    . '请在该站点设置「固定链接」后重试。',
            ];
        }

        return [
            'ok' => true,
            'http_status' => $response->status(),
            'remote_id' => (string) $remoteId,
            'remote_url' => $remoteLink,
            'wp_status' => data_get($data, 'status'),
        ];
    }

    public function requestFor(PublishingChannel $channel): \Illuminate\Http\Client\PendingRequest
    {
        $timeout = (int) data_get($channel->settings, 'timeout', 30);
        $request = Http::timeout($timeout)->withHeaders(['Accept' => 'application/json']);

        $authType = $channel->auth_type ?? 'none';
        match ($authType) {
            'bearer' => $request = $request->withToken($channel->secret() ?? ''),
            'basic', 'wordpress_application_password' => $request = $request->withBasicAuth(
                $channel->username ?? '',
                $channel->secret() ?? '',
            ),
            default => null,
        };

        return $request;
    }

    private function convertMarkdownToHtml(string $markdown): string
    {
        if (empty(trim($markdown))) {
            return '';
        }

        // Use Laravel's Str::markdown for basic conversion
        return (string) \Illuminate\Support\Str::markdown($markdown);
    }

    private function resolveCategories(PublishingChannel $channel, array $config, $article): array
    {
        return $this->taxonomy->resolveCategories($channel, $config, $article);
    }

    private function resolveTags(PublishingChannel $channel, array $config, $article): array
    {
        return $this->taxonomy->resolveTags($channel, $config, $article);
    }

    /**
     * 上传图片到 WP 媒体库并返回媒体 URL（供 WordPressMediaSyncService 调用）
     */
    public function uploadImageToWordPress(PublishingChannel $channel, string $imageUrl, string $title = ''): ?string
    {
        $result = $this->uploadMediaCore($channel, $imageUrl, $title);

        return $result['url'];
    }

    private function uploadMediaToWordPress(PublishingChannel $channel, string $imageUrl, string $title): ?int
    {
        $result = $this->uploadMediaCore($channel, $imageUrl, $title);

        return $result['id'];
    }

    /**
     * @return array{id: ?int, url: ?string}
     */
    private function uploadMediaCore(PublishingChannel $channel, string $imageUrl, string $title): array
    {
        $baseUrl = rtrim($channel->endpoint_url, '/');
        $url = $baseUrl . '/wp-json/wp/v2/media';

        // 相对路径（如 /storage/xxx.jpg）拼上 app.url 才能正确抓取
        $fetchUrl = $imageUrl;
        if (str_starts_with($fetchUrl, '/')) {
            $appUrl = rtrim((string) config('app.url'), '/');
            if ($appUrl === '') {
                Log::warning('WordPress media upload: relative image URL but app.url is not configured', [
                    'channel_id' => $channel->id,
                    'image_url' => $imageUrl,
                ]);

                return ['id' => null, 'url' => null];
            }
            $fetchUrl = $appUrl . $fetchUrl;
        }

        try {
            $imageContent = Http::timeout(15)->get($fetchUrl);
            if (! $imageContent->successful()) {
                return ['id' => null, 'url' => null];
            }

            $filename = basename(parse_url($imageUrl, PHP_URL_PATH) ?: 'image.jpg');
            $contentType = $imageContent->header('Content-Type') ?: 'image/jpeg';

            $response = $this->requestFor($channel)
                ->withHeaders([
                    'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                    'Content-Type' => $contentType,
                ])
                ->withBody($imageContent->body(), $contentType)
                ->post($url);

            if ($response->successful()) {
                $data = $response->json() ?? [];
                $mediaId = (int) data_get($data, 'id', 0);
                $mediaUrl = (string) data_get($data, 'source_url', data_get($data, 'guid.rendered', ''));

                // Update alt text
                if ($mediaId > 0) {
                    $this->requestFor($channel)->put($url . '/' . $mediaId, [
                        'alt_text' => $title,
                    ]);
                }

                return [
                    'id' => $mediaId > 0 ? $mediaId : null,
                    'url' => $mediaUrl ?: null,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('WordPress media upload failed', [
                'channel_id' => $channel->id,
                'image_url' => $imageUrl,
                'error' => $e->getMessage(),
            ]);
        }

        return ['id' => null, 'url' => null];
    }
}
