<?php

namespace App\Services\AI;

use App\Services\Outbound\OutboundRequestBlockedException;
use App\Services\Outbound\OutboundRequestFailedException;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Services\Outbound\SecureHttpFactory;

/** One bounded outbound request per call. No retries, redirects or private protocol. */
final class ModelEndpointClient
{
    public function __construct(private readonly SafeOutboundHttpClient $outbound, private readonly SecureHttpFactory $http) {}

    public function post(array $configuration, string $path, array $payload, int $timeout = 120): array
    {
        $start = microtime(true);
        try {
            $response = $this->outbound->withEnforcement()->post(
                $this->http->withToken($configuration['api_key'])->acceptJson()->asJson()
                    ->connectTimeout(10)->timeout($timeout),
                $configuration['base_url'].$path, $payload, (int) config('geo.outbound_ai_max_bytes', 8 * 1024 * 1024),
            );
            $body = $response->json();
            $success = $response->status() === 200 && is_array($body) && ! isset($body['error']);
            $unknown = $response->serverError() || $response->status() === 202 || ($response->status() === 200 && ! is_array($body));
            $result = ['success' => $success, 'status' => $success ? 'completed' : ($unknown ? 'unknown' : 'failed'),
                'body' => is_array($body) ? $body : [], 'http_status' => $response->status(),
                'error' => $success ? null : ($unknown ? '结果未知，可手动重新执行' : '模型服务拒绝了请求，请检查模型配置和服务状态。')];
        } catch (OutboundRequestFailedException $e) {
            $result = ['success' => false, 'status' => $e->beforeSend ? 'failed' : 'unknown', 'body' => [],
                'before_send' => $e->beforeSend,
                'error' => $e->beforeSend ? '连接未建立，请检查模型地址或网络后再试。' : '结果未知，可手动重新执行'];
        } catch (OutboundRequestBlockedException $e) {
            $unknown = in_array($e->reasonCode, ['response_too_large', 'encoded_response_forbidden'], true);
            $result = ['success' => false, 'status' => $unknown ? 'unknown' : 'failed', 'body' => [],
                'before_send' => ! $unknown,
                'error' => $unknown ? '响应无法安全读取，结果未知，可手动重新执行' : '模型地址未通过出站安全检查。'];
        }
        $result['duration_seconds'] = microtime(true) - $start;
        $usage = $result['body']['usage'] ?? null;
        $result['usage'] = $this->usage($usage, str_starts_with($path, '/embeddings'));
        $id = $result['body']['id'] ?? null;
        $result['request_id'] = is_string($id) && preg_match('/^[A-Za-z0-9._:-]{1,160}$/D', $id) ? $id : null;

        return $result;
    }

    public function usage(mixed $usage, bool $embedding = false): ?array
    {
        if (! is_array($usage)) {
            return null;
        }
        $input = $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? null;
        $output = $usage['completion_tokens'] ?? $usage['output_tokens'] ?? ($embedding ? 0 : null);
        $total = $usage['total_tokens'] ?? null;
        if ($embedding && ! is_int($input) && is_int($total) && ! isset($usage['completion_tokens']) && ! isset($usage['output_tokens'])) {
            $input = $total; // Embedding APIs report input usage as total_tokens.
        }
        if (! is_int($input) || ! is_int($output) || $input < 0 || $output < 0) {
            return null;
        }

        return ['prompt_tokens' => $input, 'completion_tokens' => $output, 'total_tokens' => is_int($total) && $total >= 0 ? $total : $input + $output];
    }

    public function embeddingRequest(array $configuration, array $texts): array
    {
        $payload = ['model' => $configuration['model'], 'dimensions' => EmbeddingModelPolicy::DIMENSIONS, 'encoding_format' => 'float'];
        if (config("ai-providers.{$configuration['provider']}.embedding_protocol") === 'ark_multimodal') {
            if (count($texts) !== 1) {
                throw new \InvalidArgumentException('Ark multimodal embeddings require one material per request.');
            }

            return ['/embeddings/multimodal', $payload + ['input' => [['type' => 'text', 'text' => $texts[0]]]]];
        }

        return ['/embeddings', $payload + ['input' => array_values($texts)]];
    }

    public function vectors(array $configuration, array $body, int $count): ?array
    {
        if (config("ai-providers.{$configuration['provider']}.embedding_protocol") === 'ark_multimodal') {
            return $count === 1 && EmbeddingModelPolicy::validVector($body['data']['embedding'] ?? null) ? [$body['data']['embedding']] : null;
        }
        $rows = $body['data'] ?? null;
        if (! is_array($rows) || count($rows) !== $count) {
            return null;
        }
        $vectors = [];
        foreach ($rows as $row) {
            $index = $row['index'] ?? null;
            if (! is_int($index) || $index < 0 || $index >= $count || isset($vectors[$index]) || ! EmbeddingModelPolicy::validVector($row['embedding'] ?? null)) {
                return null;
            }
            $vectors[$index] = $row['embedding'];
        }
        ksort($vectors);

        return array_values($vectors);
    }
}
