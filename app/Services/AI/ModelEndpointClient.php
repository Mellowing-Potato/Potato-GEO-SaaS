<?php

namespace App\Services\AI;

use App\Services\Outbound\OutboundRequestBlockedException;
use App\Services\Outbound\OutboundRequestFailedException;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Services\Outbound\SecureHttpFactory;
use Illuminate\Http\Client\Response;

/** One bounded outbound request per call. No retries, redirects or private protocol. */
final class ModelEndpointClient
{
    public function __construct(private readonly SafeOutboundHttpClient $outbound, private readonly SecureHttpFactory $http) {}

    public function post(array $configuration, string $path, array $payload, int $timeout = 120): array
    {
        return $this->request(
            fn () => $this->outbound->withEnforcement()->post(
                $this->http->withToken($configuration['api_key'])->acceptJson()->asJson()
                    ->connectTimeout(10)->timeout($timeout),
                $configuration['base_url'].$path, $payload, (int) config('geo.outbound_ai_max_bytes', 8 * 1024 * 1024),
            ),
            embedding: str_starts_with($path, '/embeddings'),
        );
    }

    public function chat(ResolvedModelConnection $connection, array $payload, int $timeout = 120): array
    {
        if ($connection->profile === null) {
            return $this->post($connection->legacyConfiguration(), '/chat/completions', $payload, $timeout);
        }

        return $this->request(
            fn () => app(MagpieGateway::class)->post($connection->profile, $payload + ['stream' => false], $timeout),
            magpie: true,
        );
    }

    /** Inline URL images behind GEO's own outbound checks before passing them to a local gateway. */
    public function inlineImage(string $url): string
    {
        if (preg_match('~^data:image/(?:png|jpeg|webp|gif);base64,~', $url) === 1) {
            return $url;
        }

        try {
            $response = $this->outbound->withEnforcement()->get(
                $this->http->connectTimeout(5)->timeout(15), $url, 4 * 1024 * 1024,
            );
            $image = $response->body();
            $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($image);
            if ($response->ok() && in_array($type, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
                return 'data:'.$type.';base64,'.base64_encode($image);
            }
        } catch (OutboundRequestFailedException|OutboundRequestBlockedException) {
            // Do not give the model gateway an unchecked image URL to resolve.
        }

        throw new ModelConnectionUnavailable('图片无法安全读取，请改用 PNG、JPEG、WebP 或 GIF 文件。');
    }

    /** @param callable(): Response $send */
    private function request(callable $send, bool $embedding = false, bool $magpie = false): array
    {
        $start = microtime(true);
        try {
            $response = $send();
            $body = $response->json();
            $malformed = ! is_array($body) || ($magpie && ! isset($body['error'])
                && ! is_string($body['choices'][0]['message']['content'] ?? null));
            $success = $response->status() === 200 && ! $malformed && ! isset($body['error']);
            $unknown = $response->serverError() || $response->status() === 202 || ($response->status() === 200 && $malformed);
            $rejected = $magpie && in_array($response->status(), [401, 403], true)
                ? 'Magpie 拒绝了模型鉴权，请检查 Gateway Key 或模型访问权限。'
                : '模型服务拒绝了请求，请检查模型配置和服务状态。';
            $result = ['success' => $success, 'status' => $success ? 'completed' : ($unknown ? 'unknown' : 'failed'),
                'body' => is_array($body) ? $body : [], 'http_status' => $response->status(),
                'error' => $success ? null : ($unknown ? '结果未知，可手动重新执行' : $rejected)];
        } catch (MagpieException $e) {
            $result = ['success' => false, 'status' => 'failed', 'body' => [], 'before_send' => true, 'error' => $e->getMessage()];
        } catch (OutboundRequestFailedException $e) {
            $result = ['success' => false, 'status' => $e->beforeSend ? 'failed' : 'unknown', 'body' => [],
                'before_send' => $e->beforeSend,
                'error' => $e->beforeSend ? '连接未建立，请检查模型地址或网络后再试。' : '结果未知，可手动重新执行'];
        } catch (OutboundRequestBlockedException $e) {
            $unknown = in_array($e->reasonCode, ['response_too_large', 'encoded_response_forbidden'], true)
                || ($magpie && $e->reasonCode === 'redirect_limit_exceeded');
            $message = $magpie ? match ($e->reasonCode) {
                'magpie_target_unauthorized', 'magpie_address_not_authorized' => (new MagpieException('target_unauthorized'))->getMessage(),
                'magpie_connection_changed', 'magpie_address_changed' => (new MagpieException('connection_changed'))->getMessage(),
                'magpie_connection_disabled' => (new MagpieException('connection_disabled'))->getMessage(),
                default => null,
            } : null;
            $result = ['success' => false, 'status' => $unknown ? 'unknown' : 'failed', 'body' => [],
                'before_send' => ! $unknown,
                'error' => $unknown ? '响应无法安全读取，结果未知，可手动重新执行' : ($message ?? '模型地址未通过出站安全检查。')];
        }
        $result['duration_seconds'] = microtime(true) - $start;
        $usage = $result['body']['usage'] ?? null;
        $result['usage'] = $this->usage($usage, $embedding);
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
