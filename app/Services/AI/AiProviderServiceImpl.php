<?php

namespace App\Services\AI;

use App\Models\Tenant;
use App\Services\Contracts\AiProvider as AiProviderContract;
use App\Services\DTOs\AiProviderCallResult;
use Illuminate\Support\Str;

/** Workspace-owned models using the provider's public API. */
class AiProviderServiceImpl implements AiProviderContract
{
    // Internal slot selectors, never sent as provider model names.
    public const GEO_TEXT_MODEL = 'text';
    public const GEO_VISION_MODEL = 'vision';
    public const GEO_EMBED_MODEL = 'embedding';

    public function __construct(
        private readonly ModelConnectionResolver $connections,
        private readonly ModelEndpointClient $client,
    ) {}

    public function generate(string $prompt, ?string $system = null, array $options = []): AiProviderCallResult
    {
        $images = is_array($options['images'] ?? null) ? $options['images'] : [];
        $slot = $images !== [] || ($options['slot'] ?? '') === 'vision' ? 'vision' : 'text';
        $connection = null;
        try {
            $snapshot = isset($options['execution_id']) ? $this->connections->executionSnapshot($options['execution_id'])
                : ($options['connection_snapshot'] ?? null);
            $connection = $this->connections->resolve($slot, snapshot: $snapshot);
            $capabilities = $this->connections->capabilities($connection, $images !== []);
            if ($connection->profile !== null && ! empty($options['json_object'])
                && ($capabilities['json_object'] ?? null) !== true && empty($options['_json_schema'])) {
                $prompt .= "\n\n请只输出有效的 JSON 对象。";
            }
            $content = $prompt;
            if ($images !== []) {
                $content = [];
                foreach ($images as $image) {
                    if (is_string($image) && $image !== '') {
                        if ($connection->profile !== null) {
                            $image = $this->client->inlineImage($image);
                        }
                        $content[] = ['type' => 'image_url', 'image_url' => ['url' => $image]];
                    }
                }
                $content[] = ['type' => 'text', 'text' => $prompt];
            }
        } catch (ModelConnectionUnavailable|MagpieException $e) {
            return AiProviderCallResult::failure($connection?->provider ?? '', $connection?->model ?? '', $e->getMessage(),
                ['pre_send_rejection' => true, 'status' => 'failed', 'request_sent' => false, 'usage_available' => false]);
        }
        $messages = $system ? [['role' => 'system', 'content' => $system]] : [];
        $messages[] = ['role' => 'user', 'content' => $content];
        $payload = ['model' => $connection->model, 'messages' => $messages,
            'max_tokens' => (int) ($options['max_tokens'] ?? 4096)];
        if (isset($options['temperature'])) {
            $payload['temperature'] = $options['temperature'];
        }
        if (! empty($options['json_object']) && ($capabilities['json_object'] ?? null) === true) {
            $payload['response_format'] = ['type' => 'json_object'];
        }
        $nativeSchema = $connection->profile === null
            ? $connection->provider === 'openai' && config('geo.native_json_schema', false)
            : ($capabilities['json_schema'] ?? null) === true;
        if (! empty($options['_json_schema']) && $nativeSchema) {
            $payload['response_format'] = ['type' => 'json_schema', 'json_schema' =>
                ['name' => 'workspace_output', 'strict' => true, 'schema' => $options['_json_schema']]];
        }
        // These providers' JSON mode requires non-thinking decoding.
        if (isset($payload['response_format']) && $connection->provider === 'dashscope') {
            $payload['enable_thinking'] = false;
        }
        if (isset($payload['response_format']) && $connection->provider === 'volces') {
            $payload['thinking'] = ['type' => 'disabled'];
        }
        $response = $this->client->chat($connection, $payload, (int) config('geo.model_http_timeout', 280));
        $body = $response['body'];
        $text = $body['choices'][0]['message']['content'] ?? null;
        if ($response['success'] && ! is_string($text)) {
            $response = array_replace($response, ['success' => false, 'status' => 'failed', 'error' => '模型没有返回可用内容。']);
        }

        return new AiProviderCallResult(
            success: $response['success'], provider: $connection->provider, model: $connection->model,
            content: is_string($text) ? $text : null,
            promptTokens: $response['usage']['prompt_tokens'] ?? 0,
            completionTokens: $response['usage']['completion_tokens'] ?? 0,
            totalTokens: $response['usage']['total_tokens'] ?? 0,
            durationSeconds: $response['duration_seconds'], errorMessage: $response['error'],
            finishReason: is_string($body['choices'][0]['finish_reason'] ?? null) ? $body['choices'][0]['finish_reason'] : null,
            gateway: $this->receipt($connection, $response, $options),
        );
    }

    public function generateStructured(string $prompt, string $schema, ?string $system = null, array $options = []): AiProviderCallResult
    {
        $wrapped = $prompt."\n\n请严格按以下 JSON Schema 输出 JSON，不要附加说明：\n".$schema;
        $decodedSchema = json_decode($schema, true);
        $result = $this->generate($wrapped, $system, [...$options,
            'max_tokens' => $options['max_tokens'] ?? 16384, 'temperature' => $options['temperature'] ?? 0.3,
            'json_object' => true, '_json_schema' => is_array($decodedSchema) ? $decodedSchema : null]);
        if (! $result->success) {
            return $result;
        }
        $content = $result->content ?? '';
        if (preg_match('/```(?:json|JSON)?\s*([\s\S]*?)\s*```/', $content, $matches)) {
            $content = trim($matches[1]);
        }
        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            if ($result->provider === 'magpie' && ($result->finishReason === 'length'
                || ! is_array($decodedSchema) || ! StructuredModelOutput::matches(json_decode($content), $decodedSchema))) {
                return new AiProviderCallResult(...array_replace(get_object_vars($result), [
                    'success' => false, 'errorMessage' => 'Magpie 模型返回的内容未完整满足要求的数据结构。',
                ]));
            }

            return new AiProviderCallResult(...array_replace(get_object_vars($result), ['structuredOutput' => $decoded]));
        }
        // Keep the original content and usage. Parsing failure never triggers another call.
        return new AiProviderCallResult(...array_replace(get_object_vars($result), [
            'success' => false, 'errorMessage' => 'LLM 返回内容无法解析为有效 JSON',
        ]));
    }

    public function availableProviders(Tenant $tenant): array
    {
        $providers = [];
        foreach (['text', 'vision', 'embedding'] as $slot) {
            try {
                $connection = $this->connections->resolve($slot, $tenant->id);
                if ($connection->profile !== null) {
                    $this->connections->capabilities($connection, $slot === 'vision');
                }
                $provider = $connection->provider;
                $providers[$provider]['name'] = config("ai-providers.{$provider}.name", $provider === 'magpie' ? 'Magpie' : $provider);
                $providers[$provider]['models'][] = $connection->model;
            } catch (ModelConnectionUnavailable|MagpieException) {
                continue;
            }
        }

        return $providers;
    }

    public function pickBestModel(Tenant $tenant, ?string $preferred = null): array
    {
        try {
            $connection = $this->connections->resolve('text', $tenant->id);
            if ($connection->profile !== null) {
                $this->connections->capabilities($connection);
            }

            return ['provider' => $connection->provider, 'model' => $connection->model];
        } catch (ModelConnectionUnavailable|MagpieException) {
            return ['provider' => '', 'model' => ''];
        }
    }

    public function getTenantApiKey(Tenant $tenant, string $provider): ?string
    {
        try {
            $connection = $this->connections->resolve('text', $tenant->id);

            return $connection->profile === null && $connection->provider === $provider
                ? $connection->legacyConfiguration()['api_key'] : null;
        } catch (ModelConnectionUnavailable|MagpieException) {
            return null;
        }
    }

    public function embed(string $text, string $model = self::GEO_EMBED_MODEL, array $options = []): array
    {
        $result = $this->embedBatch([$text], $model, $options);
        $result['embedding'] = $result['embeddings'][0] ?? [];
        unset($result['embeddings']);

        return $result;
    }

    public function embedBatch(array $texts, string $model = self::GEO_EMBED_MODEL, array $options = []): array
    {
        try {
            $connection = $this->connections->resolve('embedding');
        } catch (ModelConnectionUnavailable $e) {
            return ['embeddings' => [], 'tokens' => 0, 'error' => $e->getMessage(), 'attempt_sent' => false,
                'gateway' => ['pre_send_rejection' => true, 'request_sent' => false, 'status' => 'failed']];
        }
        $data = $connection->legacyConfiguration();
        if ($texts === [] || (config("ai-providers.{$connection->provider}.embedding_protocol") === 'ark_multimodal' && count($texts) !== 1)) {
            return ['embeddings' => [], 'tokens' => 0, 'error' => '向量请求的材料数量不符合模型要求。', 'attempt_sent' => false];
        }
        [$path, $payload] = $this->client->embeddingRequest($data, $texts);
        $response = $this->client->post($data, $path, $payload);
        $vectors = $response['success'] ? $this->client->vectors($data, $response['body'], count($texts)) : null;
        if ($response['success'] && $vectors === null) {
            $response = array_replace($response, ['success' => false, 'status' => 'failed', 'error' => '模型返回的向量不是有效的 1024 维向量，已拒绝入库。']);
        }

        return ['embeddings' => $vectors ?? [], 'tokens' => $response['usage']['total_tokens'] ?? 0,
            'error' => $response['error'], 'attempt_sent' => ! ($response['before_send'] ?? false),
            'gateway' => $this->receipt($connection, $response, $options),
            'provider' => $data['provider'], 'model' => $data['model']];
    }

    /** Legacy DTO field name retained for saved local delivery checkpoints, not wire protocol. */
    private function receipt(ResolvedModelConnection $connection, array $response, array $options): array
    {
        return [
            // Local attempt identity cannot collide across vendors returning the same ID.
            'request_id' => 'model:'.Str::uuid(), 'provider_request_id' => $response['request_id'],
            'provider' => $connection->provider, 'model' => $connection->model,
            'credential_fingerprint' => $connection->fingerprint,
            'status' => $response['status'], 'http_status' => $response['http_status'] ?? null,
            'duration_seconds' => $response['duration_seconds'],
            'request_sent' => ! ($response['before_send'] ?? false),
            'pre_send_rejection' => (bool) ($response['before_send'] ?? false),
            'usage_available' => $response['usage'] !== null, 'usage' => $response['usage'],
            'attribution' => array_filter([
                'client_request_id' => $options['client_request_id'] ?? (string) Str::uuid(),
                'task_id' => $options['task_id'] ?? null, 'feature_id' => $options['feature_id'] ?? null,
                'step_id' => isset($options['step_id']) ? (string) $options['step_id'] : null,
            ], fn ($value) => $value !== null),
        ];
    }
}
