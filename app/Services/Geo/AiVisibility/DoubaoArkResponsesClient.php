<?php

namespace App\Services\Geo\AiVisibility;

use App\Services\Outbound\OutboundRequestBlockedException;
use App\Services\Outbound\OutboundRequestFailedException;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Services\Outbound\SecureHttpFactory;

/** Direct Ark Responses API. Unknown outcomes are queried by ID, never reposted. */
final class DoubaoArkResponsesClient
{
    public function __construct(
        private readonly SafeOutboundHttpClient $outbound,
        private readonly SecureHttpFactory $http,
        private readonly AiVisibilityConfigurationResolver $config,
        private readonly AiProviderEndpointPolicy $endpointPolicy,
        private readonly AiVisibilityResultNormalizer $normalizer,
    ) {}

    public function ask(string $question, ?string $taskId = null, ?string $clientRequestId = null, ?callable $checkpoint = null, ?array $connectionSnapshot = null): AiVisibilityResult
    {
        if ($connectionSnapshot === null) {
            if (! $this->config->connection()) {
                return AiVisibilityResult::failure($question, $this->config->providerName(), '', '未配置豆包监测');
            }
            $connectionSnapshot = $this->config->snapshot();
        }
        $connection = $this->config->pinnedConnection($connectionSnapshot);
        $provider = $connection['provider'];
        $model = $connection['model'];
        if ($taskId === null || ! preg_match('/^[A-Za-z0-9_.:-]{1,140}$/D', $taskId)
            || $clientRequestId === null || ! preg_match('/^[A-Za-z0-9_.:-]{1,160}$/D', $clientRequestId)) {
            return AiVisibilityResult::failure($question, $provider, $model, '监测任务缺少持久编号，未发送请求。');
        }
        $endpoint = $connection['base_url'].'/responses';
        try {
            $this->endpointPolicy->assertAllowed($endpoint);
            $this->outbound->withEnforcement()->resolveTarget($endpoint);
        } catch (\InvalidArgumentException|OutboundRequestBlockedException|OutboundRequestFailedException) {
            return AiVisibilityResult::failure($question, $provider, $model, '监测地址未通过出站安全检查。');
        }
        $receipt = ['request_id' => 'visibility:'.hash('sha256', $clientRequestId), 'endpoint' => $endpoint,
            'credential_fingerprint' => $connection['credential_fingerprint'], 'provider' => $provider, 'model' => $model,
            'attribution' => ['task_id' => $taskId, 'client_request_id' => $clientRequestId, 'feature_id' => 'ai-visibility']];
        if ($checkpoint) { $checkpoint($receipt); }
        // Check again after durable preparation: configuration may have changed
        // while the receipt was being saved. Only this matching connection can send.
        $connection = $this->config->pinnedConnection($connectionSnapshot);
        $stream = new ArkResponseStream($this->config->maxBytes(), function (string $id) use (&$receipt, $checkpoint): void {
            $receipt['provider_request_id'] = $id;
            if ($checkpoint) { $checkpoint($receipt); }
        });
        $startedAt = microtime(true);
        $status = null;
        try {
            // https://docs.volcengine.com/docs/ark/create-model-responses-api?lang=zh
            // store=true enables GET recovery. The bounded sink parses SSE as it arrives.
            $response = $this->outbound->withEnforcement()->post(
                $this->http->withToken($connection['api_key'])->accept('text/event-stream')->asJson()
                    ->connectTimeout(10)->timeout($this->config->httpTimeoutSeconds())
                    ->withOptions(['sink' => $stream, 'on_headers' => function ($response) use (&$status): void {
                        $status = $response->getStatusCode();
                    }]),
                $endpoint, ['model' => $model, 'input' => $question, 'tools' => [['type' => 'web_search']],
                    'reasoning' => ['effort' => 'low'], 'stream' => true, 'store' => true,
                    'max_output_tokens' => (int) config('geo.visibility.max_output_tokens', 8192)],
                $this->config->maxBytes(),
            );
            $status = $response->status();
            if ($status !== 200) {
                return $status >= 500 || $status === 202
                    ? $this->pending($question, $provider, $model, $receipt)
                    : AiVisibilityResult::failure($question, $provider, $model, '监测服务拒绝了请求，请检查方舟 Key、模型及联网搜索权限。');
            }
        } catch (OutboundRequestFailedException $e) {
            if ($e->beforeSend) {
                return AiVisibilityResult::failure($question, $provider, $model, '连接未建立，请检查方舟地址或网络。');
            }
            if ($status !== 200 || $stream->response() === null) {
                return $this->pending($question, $provider, $model, $receipt);
            }
        } catch (OutboundRequestBlockedException) {
            return $this->pending($question, $provider, $model, $receipt);
        }
        $payload = $stream->response();
        if (! is_array($payload)) {
            return $this->pending($question, $provider, $model, $receipt);
        }

        return $this->normalize($question, $provider, $model, $payload, microtime(true) - $startedAt, $receipt);
    }

    public function recover(string $question, string $taskId, string $clientRequestId, array $receipt = []): AiVisibilityResult
    {
        $connection = $this->config->connection();
        $provider = $receipt['provider'] ?? $this->config->providerName();
        $model = $receipt['model'] ?? $this->config->model();
        $id = $receipt['provider_request_id'] ?? null;
        if (! $connection || ! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{1,160}$/D', $id)
            || ($receipt['attribution']['task_id'] ?? null) !== $taskId
            || ($receipt['attribution']['client_request_id'] ?? null) !== $clientRequestId
            || ($receipt['endpoint'] ?? null) !== $connection['base_url'].'/responses'
            || ! hash_equals($connection['credential_fingerprint'], (string) ($receipt['credential_fingerprint'] ?? ''))) {
            return $this->pending($question, $provider, $model, $receipt);
        }
        $startedAt = microtime(true);
        try {
            // https://docs.volcengine.com/docs/ark/list-model-responses-api?lang=zh
            $url = $receipt['endpoint'].'/'.rawurlencode($id);
            $this->endpointPolicy->assertAllowed($url);
            $response = $this->outbound->withEnforcement()->get(
                $this->http->withToken($connection['api_key'])->acceptJson()->connectTimeout(5)->timeout(15),
                $url, $this->config->maxBytes(),
            );
        } catch (\InvalidArgumentException|OutboundRequestBlockedException|OutboundRequestFailedException) {
            return $this->pending($question, $provider, $model, $receipt);
        }
        $payload = $response->json();
        if ($response->status() !== 200 || ! is_array($payload) || ($payload['id'] ?? null) !== $id) {
            return $this->pending($question, $provider, $model, $receipt);
        }

        return $this->normalize($question, $provider, $model, $payload, microtime(true) - $startedAt, $receipt);
    }

    private function normalize(string $question, string $provider, string $model, array $payload, float $duration, array $receipt): AiVisibilityResult
    {
        if (in_array($payload['status'] ?? null, ['queued', 'in_progress'], true)) {
            return $this->pending($question, $provider, $model, $receipt);
        }
        $result = $this->normalizer->normalize($question, $provider, $model, $payload, $duration);
        $receipt['status'] = $result->success ? 'completed' : 'failed';
        $receipt['usage_available'] = app(\App\Services\AI\ModelEndpointClient::class)->usage($payload['usage'] ?? null) !== null;

        return new AiVisibilityResult(...array_replace(get_object_vars($result), ['gateway' => $receipt]));
    }

    private function pending(string $question, string $provider, string $model, array $receipt): AiVisibilityResult
    {
        return new AiVisibilityResult(success: false, question: $question, provider: $provider, model: $model,
            errorMessage: '结果未知，可手动重新执行', gateway: $receipt + ['status' => 'unknown', 'usage_available' => false], pendingReconciliation: true);
    }
}
