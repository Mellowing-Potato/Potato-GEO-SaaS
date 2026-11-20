<?php

namespace App\Services\Publishing;

use App\Jobs\PublishArticleJob;
use App\Models\Article;
use App\Models\ArticlePublication;
use App\Models\PublishingChannel;
use App\Models\User;
use App\Services\Distribution\DistributionOrchestrator;
use App\Services\Publishing\ArticleRiskScanner;
use App\Services\Distribution\TaskDistributionChannelSelector;
use App\Support\OperationalTextRedactor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use App\Services\Outbound\PublicationHttpClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ArticlePublicationService
{
    public function __construct(
        private readonly DistributionOrchestrator $orchestrator,
        private readonly TaskDistributionChannelSelector $channelSelector,
    ) {}

    public function publish(Article $article, PublishingChannel $channel, ?User $user = null, bool $retryAccepted = false): ArticlePublication
    {
        $this->validateTarget($article, $channel);

        // 出站合规闸门（执行书 T6 / 评审阻断 2）：含平台红线词的稿子在此拦下，绝不发送到客户站点。
        // 与 enqueueForArticle 共用 DistributionOrchestrator::complianceGate，单一闸门口径。
        $block = $this->orchestrator->complianceGate($article);
        if ($block !== null && ($block['blocked'] ?? false)) {
            throw ValidationException::withMessages([
                'article_id' => ArticleRiskScanner::PLATFORM_BLOCK_NOTICE,
            ]);
        }

        [$publication, $claimed] = $this->prepare($article, $channel, $user, ArticlePublication::STATUS_PUBLISHING, $retryAccepted);
        if (! $claimed) {
            return $publication->fresh(['channel']);
        }

        try {
            $result = $this->send($article, $channel, $publication);
        } catch (ConnectionException) {
            return $this->markFailed($publication, '发布渠道暂不可用。');
        } catch (\Throwable $exception) {
            return $this->markFailed($publication, $exception->getMessage());
        }

        if (! ($result['ok'] ?? false)) {
            return $this->markFailed($publication, (string) ($result['failure_reason'] ?? '外部发布失败。'), [
                'http_status' => $result['http_status'] ?? null,
            ]);
        }

        return $this->complete($article, $publication, $result);
    }

    private function complete(Article $article, ArticlePublication $publication, array $result): ArticlePublication
    {
        $public = ($result['public'] ?? false) === true;

        $publishedAt = now();
        $publication->forceFill([
            'status' => $public ? ArticlePublication::STATUS_PUBLISHED : ArticlePublication::STATUS_ACCEPTED,
            'remote_id' => $result['remote_id'] ?? $publication->remote_id,
            'remote_url' => $result['remote_url'] ?? $publication->remote_url,
            'last_error' => null,
            'published_at' => $public ? $publishedAt : null,
            'metadata' => array_merge($publication->metadata ?? [], [
                'http_status' => $result['http_status'] ?? null,
                'remote_status' => $result['remote_status'] ?? null,
                'target_state' => $public ? 'public' : 'accepted',
            ]),
        ])->save();

        if ($public && $article->status !== 'published') {
            Article::query()->whereKey($article->id)->where('version', $article->version)
                ->where('status', 'approved')->update([
                'status' => 'published',
                'published_at' => $publishedAt,
            ]);
        }

        return $publication->fresh(['channel']);
    }

    /**
     * 异步发布：创建 publication 记录（queued 状态）并推入队列。
     *
     * 与同步 publish() 的区别：不等待外部 HTTP 响应，立即返回 queued 状态的 publication。
     * 适用于批量发布、后台发布等场景。
     */
    public function dispatchPublish(Article $article, PublishingChannel $channel, ?User $user = null): ArticlePublication
    {
        $this->validateTarget($article, $channel);

        // 出站合规闸门（执行书 T6 / 评审阻断 2）：与 publish() 共用同一道闸，防止手动异步发布绕过。
        $block = $this->orchestrator->complianceGate($article);
        if ($block !== null && ($block['blocked'] ?? false)) {
            throw ValidationException::withMessages([
                'article_id' => ArticleRiskScanner::PLATFORM_BLOCK_NOTICE,
            ]);
        }

        [$publication, $claimed] = $this->prepare($article, $channel, $user, ArticlePublication::STATUS_QUEUED);
        if (! $claimed) {
            return $publication->fresh(['channel']);
        }

        PublishArticleJob::dispatch(
            $publication->id,
            $article->tenant_id,
        );

        Log::info('Article publication dispatched to queue', [
            'publication_id' => $publication->id,
            'article_id' => $article->id,
            'channel_id' => $channel->id,
            'channel_type' => $channel->type,
        ]);

        return $publication->fresh(['channel']);
    }

    /** Reserve the existing article/channel row before any external request. */
    private function prepare(Article $article, PublishingChannel $channel, ?User $user, string $status, bool $retryAccepted = false): array
    {
        $publication = ArticlePublication::query()->firstOrCreate(
            ['article_id' => $article->id, 'publishing_channel_id' => $channel->id],
            ['tenant_id' => $article->tenant_id, 'status' => $status, 'attempt_count' => 0],
        );
        $created = $publication->wasRecentlyCreated;

        return DB::transaction(function () use ($publication, $created, $article, $channel, $user, $status, $retryAccepted): array {
            $row = ArticlePublication::query()->whereKey($publication->id)->lockForUpdate()->firstOrFail();
            $sameVersion = (int) data_get($row->metadata, 'article_version') === (int) $article->version;
            if (! $created && (
                in_array($row->status, [ArticlePublication::STATUS_QUEUED, ArticlePublication::STATUS_PUBLISHING], true)
                || ($sameVersion && ($row->status === ArticlePublication::STATUS_PUBLISHED
                    || ($row->status === ArticlePublication::STATUS_ACCEPTED
                        && (! $retryAccepted || $channel->type !== PublishingChannel::TYPE_WORDPRESS_REST))))
            )) {
                return [$row, false];
            }

            $row->forceFill([
                'tenant_id' => $article->tenant_id,
                'requested_by' => $user?->id,
                'status' => $status,
                'attempt_count' => (int) $row->attempt_count + 1,
                'last_error' => null,
                'last_attempted_at' => now(),
                'metadata' => array_merge($row->metadata ?? [], [
                    'channel_type' => $channel->type,
                    'article_version' => $article->version,
                    'source' => $status === ArticlePublication::STATUS_QUEUED ? 'geo_minimum_distribution_async' : 'geo_minimum_distribution',
                ]),
            ])->save();

            return [$row, true];
        });
    }

    /**
     * 队列任务回调：对已有的 publication 记录执行实际的外部渠道发送。
     *
     * 由 PublishArticleJob 调用，不直接从 Controller 调用。
     */
    public function executePublish(ArticlePublication $publication): ArticlePublication
    {
        $article = $publication->article;
        $channel = $publication->channel;

        if (! $article || ! $channel) {
            return $this->markFailed($publication, '关联的文章或渠道不存在。');
        }

        if (! $channel->is_active) {
            return $this->markFailed($publication, '发布渠道已停用。');
        }
        try {
            $this->validateTarget($article, $channel);
            if ((int) data_get($publication->metadata, 'article_version') !== (int) $article->version) {
                return $this->markFailed($publication, '文章版本已变化，请确认最新审核版本后重新发布。');
            }
        } catch (ValidationException $exception) {
            return $this->markFailed($publication, $exception->getMessage());
        }

        // 出站合规闸门：executePublish 是手动异步发布的真实发送点，必须卡。
        $block = $this->orchestrator->complianceGate($article);
        if ($block !== null && ($block['blocked'] ?? false)) {
            return $this->markFailed($publication, ArticleRiskScanner::PLATFORM_BLOCK_NOTICE);
        }

        try {
            $result = $this->send($article, $channel, $publication);
        } catch (ConnectionException) {
            return $this->markFailed($publication, '发布渠道暂不可用。');
        } catch (\Throwable $exception) {
            return $this->markFailed($publication, $exception->getMessage());
        }

        if (! ($result['ok'] ?? false)) {
            return $this->markFailed($publication, (string) ($result['failure_reason'] ?? '外部发布失败。'), [
                'http_status' => $result['http_status'] ?? null,
            ]);
        }

        return $this->complete($article, $publication, $result);
    }

    private function send(Article $article, PublishingChannel $channel, ArticlePublication $publication): array
    {
        return match ($channel->type) {
            PublishingChannel::TYPE_WORDPRESS_REST => $this->sendWordPress($article, $channel, $publication),
            PublishingChannel::TYPE_HTTP_WEBHOOK => $this->sendWebhook($article, $channel, $publication),
            default => [
                'ok' => false,
                'failure_reason' => '不支持的发布渠道类型。',
            ],
        };
    }

    private function sendWebhook(Article $article, PublishingChannel $channel, ArticlePublication $publication): array
    {
        $payload = [
            'object' => 'geo_article_publication',
            'article' => $this->articlePayload($article),
            'channel' => [
                'id' => $channel->id,
                'type' => $channel->type,
            ],
        ];

        $request = $this->requestFor($channel, $payload)->withHeaders([
            'Idempotency-Key' => 'geo-publication-'.$publication->id.'-v'.$article->version,
        ]);
        $response = $request->post($channel->endpoint_url, $payload);

        $result = $this->responsePayload($response);
        $result['public'] = $response->status() !== 202
            && in_array($result['remote_status'] ?? null, ['published', 'public', 'live'], true)
            && filter_var($result['remote_url'] ?? null, FILTER_VALIDATE_URL) !== false;

        return $result;
    }

    private function sendWordPress(Article $article, PublishingChannel $channel, ArticlePublication $publication): array
    {
        $payload = [
            'title' => $article->title,
            'content' => $article->body_markdown ?? '',
            'excerpt' => $article->summary ?? '',
            'status' => (string) data_get($channel->settings ?? [], 'wordpress_status', 'draft'),
            'slug' => $article->slug,
        ];

        $request = $this->requestFor($channel, $payload);
        $endpoint = $this->wordPressPostsEndpoint($channel);
        $method = $publication->remote_id ? 'put' : 'post';
        if ($publication->remote_id) {
            $endpoint .= '/'.rawurlencode($publication->remote_id);
        }
        $response = $request->{$method}($endpoint, array_filter(
            $payload,
            fn (mixed $value): bool => $value !== null && $value !== '',
        ));

        $result = $this->responsePayload($response);
        if (($result['ok'] ?? false) && ! $result['remote_id']) {
            return ['ok' => false, 'http_status' => $response->status(), 'failure_reason' => 'WordPress 响应缺少文章 ID，无法安全重试。'];
        }
        $result['public'] = ($result['remote_status'] ?? null) === 'publish'
            && filter_var($result['remote_url'] ?? null, FILTER_VALIDATE_URL) !== false;

        return $result;
    }

    private function requestFor(PublishingChannel $channel, array $payload): PendingRequest
    {
        $request = app(PublicationHttpClient::class)->timeout((int) data_get($channel->settings ?? [], 'timeout', 15))
            ->acceptJson()
            ->asJson();

        $headers = collect($channel->headers ?? [])
            ->filter(fn (mixed $value, mixed $key): bool => is_string($key) && is_scalar($value) && trim((string) $value) !== '')
            ->map(fn (mixed $value): string => trim((string) $value))
            ->all();

        if ($headers !== []) {
            $request = $request->withHeaders($headers);
        }

        $secret = $channel->secret();
        $authType = strtolower((string) $channel->auth_type);

        if ($authType === 'bearer' && $secret) {
            return $request->withToken($secret);
        }

        if (in_array($authType, ['basic', 'wordpress_application_password'], true) && $channel->username && $secret) {
            return $request->withBasicAuth($channel->username, $secret);
        }

        if ($authType === 'hmac' && $secret) {
            $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';

            return $request->withHeaders([
                'X-Geo-Signature' => 'sha256='.hash_hmac('sha256', $encoded, $secret),
            ]);
        }

        return $request;
    }

    private function responsePayload(Response $response): array
    {
        $body = $response->json();
        if (! is_array($body)) {
            $body = [];
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'http_status' => $response->status(),
                'failure_reason' => $this->safeFailureReason($body, $response),
            ];
        }

        return [
            'ok' => true,
            'http_status' => $response->status(),
            'remote_id' => $this->remoteValue($body, ['id', 'post_id', 'remote_id', 'data.id']),
            'remote_url' => $this->remoteValue($body, ['link', 'url', 'remote_url', 'data.url']),
            'remote_status' => $this->remoteValue($body, ['status', 'data.status']),
        ];
    }

    private function validateTarget(Article $article, PublishingChannel $channel): void
    {
        if ((int) $article->tenant_id !== (int) $channel->tenant_id) {
            throw ValidationException::withMessages(['channel_id' => '渠道不属于文章所在租户。']);
        }
        if ($issue = $this->channelIssue($channel)) {
            throw ValidationException::withMessages(['channel_id' => $issue]);
        }
        if (! in_array($article->status, ['approved', 'published'], true)) {
            throw ValidationException::withMessages(['article_id' => '文章需要先审核通过，才能发布到渠道。']);
        }
        if ($article->project_id === null || $article->project === null) {
            throw ValidationException::withMessages(['article_id' => '文章所在项目不可用。']);
        }
        $boundIds = $this->channelSelector->boundChannelIds((int) $article->project_id);
        if ($boundIds->isNotEmpty() && ! $boundIds->contains((int) $channel->id)) {
            throw ValidationException::withMessages(['channel_id' => '该渠道未绑定到文章所在项目。']);
        }
    }

    public function channelIssue(PublishingChannel $channel): ?string
    {
        if ($channel->type === PublishingChannel::TYPE_HOSTED_PAGE) {
            return '站内托管渠道已退役。';
        }
        if (! $channel->is_active) {
            return '发布渠道已停用。';
        }
        if (! in_array($channel->type, [PublishingChannel::TYPE_WORDPRESS_REST, PublishingChannel::TYPE_HTTP_WEBHOOK], true)) {
            return '此渠道尚不支持文章手动发布。';
        }
        if (! filter_var($channel->endpoint_url, FILTER_VALIDATE_URL)) {
            return '渠道缺少有效的发布地址。';
        }
        if ($channel->type === PublishingChannel::TYPE_WORDPRESS_REST
            && (! $channel->username || ! $channel->secret())) {
            return 'WordPress 渠道缺少用户名或应用密码。';
        }
        if (in_array($channel->auth_type, ['bearer', 'hmac', 'basic', 'wordpress_application_password'], true)
            && ! $channel->secret()) {
            return '渠道认证配置不完整。';
        }
        return null;
    }

    private function safeFailureReason(array $body, Response $response): string
    {
        $message = data_get($body, 'error.message')
            ?? data_get($body, 'message')
            ?? data_get($body, 'error')
            ?? $response->body()
            ?: '外部发布失败。';

        return OperationalTextRedactor::redact(is_scalar($message) ? (string) $message : '外部发布失败。') ?? '外部发布失败。';
    }

    private function markFailed(ArticlePublication $publication, string $message, array $metadata = []): ArticlePublication
    {
        $publication->forceFill([
            'status' => ArticlePublication::STATUS_FAILED,
            'last_error' => OperationalTextRedactor::redact($message) ?? '外部发布失败。',
            'metadata' => array_merge($publication->metadata ?? [], $metadata),
        ])->save();

        return $publication->fresh(['channel']);
    }

    private function articlePayload(Article $article): array
    {
        return [
            'id' => $article->id,
            'project_id' => $article->project_id,
            'title' => $article->title,
            'slug' => $article->slug,
            'summary' => $article->summary,
            'body_markdown' => $article->body_markdown,
            'content_type' => $article->content_type,
            'category' => $article->category,
            'seo' => [
                'title' => $article->seo_title,
                'description' => $article->seo_description,
                'canonical_url' => $article->canonical_url,
            ],
            'status' => $article->status,
            'version' => $article->version,
            'published_at' => $article->published_at?->toISOString(),
        ];
    }

    private function wordPressPostsEndpoint(PublishingChannel $channel): string
    {
        $url = rtrim($channel->endpoint_url, '/');

        if (str_contains($url, '/wp-json/wp/v2/posts')) {
            return $url;
        }

        return $url.'/wp-json/wp/v2/posts';
    }

    private function remoteValue(array $body, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = data_get($body, $key);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        return null;
    }
}
