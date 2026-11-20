<?php

namespace App\Services\Distribution\Publishers;

use App\Models\ArticlePublication;
use App\Models\PublishingChannel;
use App\Services\Distribution\DistributionPublisherInterface;
use App\Support\OperationalTextRedactor;
use App\Services\Outbound\PublicationHttpClient;
use Illuminate\Support\Facades\Log;

class GenericHttpPublisher implements DistributionPublisherInterface
{
    public function health(PublishingChannel $channel): array
    {
        $config = $channel->resolvedChannelConfig();
        $healthPath = data_get($config, 'health_path', '/health');
        $baseUrl = rtrim($channel->endpoint_url, '/');
        $url = $baseUrl . '/' . ltrim($healthPath, '/');
        $timeout = (int) data_get($channel->settings, 'timeout', 10);

        $start = microtime(true);
        try {
            $response = app(PublicationHttpClient::class)->timeout($timeout)
                ->withHeaders(['Accept' => 'application/json'])
                ->get($url);
        } catch (\Throwable $e) {
            $latency = round((microtime(true) - $start) * 1000, 2);
            return ['ok' => false, 'latency_ms' => $latency, 'message' => 'Generic HTTP health check failed: ' . $e->getMessage()];
        }
        $latency = round((microtime(true) - $start) * 1000, 2);

        return [
            'ok' => $response->successful(),
            'latency_ms' => $latency,
            'http_status' => $response->status(),
            'message' => $response->successful() ? 'Endpoint reachable' : 'Health check returned HTTP ' . $response->status(),
        ];
    }

    public function publish(ArticlePublication $publication): array
    {
        return $this->send($publication, 'POST', 'publish_path');
    }

    public function update(ArticlePublication $publication): array
    {
        if (empty($publication->remote_id)) {
            return ['ok' => false, 'message' => 'No remote_id for Generic HTTP update'];
        }

        return $this->send($publication, 'PUT', 'update_path');
    }

    public function delete(ArticlePublication $publication): array
    {
        if (empty($publication->remote_id)) {
            return ['ok' => false, 'message' => 'No remote_id for Generic HTTP delete'];
        }

        return $this->send($publication, 'DELETE', 'delete_path');
    }

    public function syncSiteSettings(PublishingChannel $channel, array $settings): array
    {
        return ['ok' => false, 'message' => 'Site settings sync not supported for Generic HTTP channels'];
    }

    private function send(ArticlePublication $publication, string $method, string $pathConfigKey): array
    {
        $channel = $publication->channel;
        $article = $publication->article;
        $config = $channel->resolvedChannelConfig();

        $baseUrl = rtrim($channel->endpoint_url, '/');
        $pathTemplate = data_get($config, $pathConfigKey, '/articles');

        // Resolve template variables in path
        $path = $this->resolvePathTemplate($pathTemplate, $article, $publication);
        $url = $baseUrl . '/' . ltrim($path, '/');

        $payload = $this->buildPayload($article, $publication, $config);

        // Optional payload wrapper
        $wrapper = data_get($config, 'payload_wrapper');
        if ($wrapper) {
            $payload = [$wrapper => $payload];
        }

        $timeout = (int) data_get($channel->settings, 'timeout', 30);

        try {
            $request = $this->requestFor($channel, $config);

            $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

            $response = $request
                ->withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json'])
                ->withBody($body, 'application/json')
                ->send(strtoupper($method), $url);
        } catch (\Throwable $e) {
            Log::warning('Generic HTTP publish failed', [
                'publication_id' => $publication->id,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'message' => 'HTTP request failed: ' . $e->getMessage()];
        }

        if (! $response->successful()) {
            // Check custom success status codes
            $successStatuses = data_get($config, 'success_statuses', []);
            if (is_array($successStatuses) && in_array($response->status(), $successStatuses, true)) {
                // Treat as success
            } else {
                $body = $response->json() ?? [];
                $message = data_get($body, 'message', data_get($body, 'error', 'HTTP ' . $response->status()));
                return [
                    'ok' => false,
                    'http_status' => $response->status(),
                    'message' => OperationalTextRedactor::redact((string) $message),
                ];
            }
        }

        $data = $response->json() ?? [];

        // Map response to remote_id and remote_url using dot-notation paths
        $remoteIdPath = data_get($config, 'remote_id_path', 'id');
        $remoteUrlPath = data_get($config, 'remote_url_path', 'url');

        return [
            'ok' => true,
            'http_status' => $response->status(),
            'remote_id' => (string) (data_get($data, $remoteIdPath) ?? ''),
            'remote_url' => (string) (data_get($data, $remoteUrlPath) ?? ''),
        ];
    }

    private function requestFor(PublishingChannel $channel, array $config): \Illuminate\Http\Client\PendingRequest
    {
        $timeout = (int) data_get($channel->settings, 'timeout', 30);
        $request = app(PublicationHttpClient::class)->timeout($timeout);

        $authType = data_get($config, 'auth_type', $channel->auth_type ?? 'none');

        match ($authType) {
            'bearer' => $request = $request->withToken($channel->secret() ?? ''),
            'basic' => $request = $request->withBasicAuth(
                $channel->username ?? '',
                $channel->secret() ?? '',
            ),
            'header_key' => $request = $request->withHeaders([
                data_get($config, 'auth_header_name', 'X-Api-Key') => $channel->secret() ?? '',
            ]),
            'hmac' => $request = $request->withHeaders([
                'X-Geo-Signature' => hash_hmac('sha256', (string) time(), $channel->secret() ?? ''),
            ]),
            default => null,
        };

        return $request;
    }

    private function resolvePathTemplate(string $path, $article, ArticlePublication $publication): string
    {
        $replacements = [
            '{article_id}' => (string) $article->id,
            '{slug}' => $article->slug ?? 'article-' . $article->id,
            '{remote_id}' => (string) ($publication->remote_id ?? ''),
            '{channel_id}' => (string) $publication->publishing_channel_id,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $path);
    }

    private function buildPayload($article, ArticlePublication $publication, array $config): array
    {
        return [
            'object' => 'geo_article_publication',
            'article' => [
                'id' => $article->id,
                'title' => $article->title,
                'slug' => $article->slug ?? 'article-' . $article->id,
                'summary' => $article->summary ?? '',
                'body_markdown' => $article->body_markdown ?? '',
                'content_type' => $article->content_type ?? 'explainer',
                'version' => $article->version ?? 1,
                'seo' => [
                    'title' => data_get($article->metadata, 'seo.title', $article->title),
                    'description' => data_get($article->metadata, 'seo.description', $article->summary),
                ],
                'published_at' => $article->published_at?->toISOString(),
            ],
            'channel' => [
                'id' => $publication->publishing_channel_id,
                'type' => $publication->channel?->type ?? 'generic',
            ],
        ];
    }
}
