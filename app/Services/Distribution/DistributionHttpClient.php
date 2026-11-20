<?php

namespace App\Services\Distribution;

use App\Models\DistributionChannelSecret;
use App\Models\PublishingChannel;
use App\Services\Outbound\PublicationHttpClient;
use Illuminate\Support\Facades\Log;

class DistributionHttpClient
{
    public function __construct(
        private readonly DistributionSigningService $signingService,
    ) {}

    /**
     * Send a signed request to a GEOFlow Agent target site.
     */
    public function send(
        PublishingChannel $channel,
        string $method,
        string $path,
        array $payload = [],
        ?string $idempotencyKey = null,
        ?string $event = null,
    ): array {
        $secret = $this->resolveActiveSecret($channel);
        if ($secret === null) {
            return ['ok' => false, 'message' => 'No active secret configured for channel'];
        }

        $baseUrl = rtrim($channel->endpoint_url, '/');
        $url = $baseUrl . '/' . ltrim($path, '/');
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $headers = $this->signingService->signRequest(
            method: $method,
            url: $url,
            body: $body,
            keyId: $secret->key_id,
            secret: $secret->decryptSecret(),
            idempotencyKey: $idempotencyKey,
            event: $event,
        );

        $timeout = (int) data_get($channel->settings, 'timeout', 30);

        try {
            $response = app(PublicationHttpClient::class)->timeout($timeout)
                ->withHeaders(array_merge($headers, [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ]))
                ->withBody($body, 'application/json')
                ->send(strtoupper($method), $url);
        } catch (\Throwable $e) {
            Log::warning('Distribution HTTP request failed', [
                'channel_id' => $channel->id,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'message' => 'Connection failed: ' . $e->getMessage()];
        }

        $secret->markUsed();

        if (! $response->successful()) {
            return [
                'ok' => false,
                'http_status' => $response->status(),
                'message' => 'Remote returned HTTP ' . $response->status(),
                'body' => $response->json() ?? $response->body(),
            ];
        }

        $data = $response->json() ?? [];

        return [
            'ok' => true,
            'http_status' => $response->status(),
            'remote_id' => data_get($data, 'id') ?? data_get($data, 'article_id') ?? data_get($data, 'data.id'),
            'remote_url' => data_get($data, 'url') ?? data_get($data, 'link') ?? data_get($data, 'data.url'),
            'data' => $data,
        ];
    }

    /**
     * Send a publish request to a GEOFlow Agent target site.
     */
    public function publishArticle(PublishingChannel $channel, array $payload, string $idempotencyKey): array
    {
        return $this->send($channel, 'POST', '/geoflow-agent/v1/articles', $payload, $idempotencyKey, 'article.publish');
    }

    /**
     * Send an update request for a previously published article.
     */
    public function updateArticle(PublishingChannel $channel, string $remoteId, array $payload): array
    {
        return $this->send($channel, 'PUT', "/geoflow-agent/v1/articles/{$remoteId}", $payload, event: 'article.update');
    }

    /**
     * Send a delete request for a previously published article.
     */
    public function deleteArticle(PublishingChannel $channel, string $remoteId): array
    {
        return $this->send($channel, 'DELETE', "/geoflow-agent/v1/articles/{$remoteId}", event: 'article.delete');
    }

    /**
     * Check target site health.
     */
    public function health(PublishingChannel $channel): array
    {
        $baseUrl = rtrim($channel->endpoint_url, '/');
        $url = $baseUrl . '/geoflow-agent/v1/health';
        $timeout = (int) data_get($channel->settings, 'timeout', 10);

        $start = microtime(true);
        try {
            $response = app(PublicationHttpClient::class)->timeout($timeout)
                ->withHeaders(['Accept' => 'application/json'])
                ->get($url);
        } catch (\Throwable $e) {
            $latency = round((microtime(true) - $start) * 1000, 2);
            return ['ok' => false, 'latency_ms' => $latency, 'message' => 'Health check failed: ' . $e->getMessage()];
        }
        $latency = round((microtime(true) - $start) * 1000, 2);

        if (! $response->successful()) {
            return ['ok' => false, 'latency_ms' => $latency, 'http_status' => $response->status(), 'message' => 'Health check returned HTTP ' . $response->status()];
        }

        $data = $response->json() ?? [];
        return [
            'ok' => true,
            'latency_ms' => $latency,
            'status' => data_get($data, 'status', 'healthy'),
            'version' => data_get($data, 'version'),
            'data' => $data,
        ];
    }

    private function resolveActiveSecret(PublishingChannel $channel): ?DistributionChannelSecret
    {
        return $channel->activeSecret;
    }
}
