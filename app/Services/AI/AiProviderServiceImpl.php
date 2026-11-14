<?php

namespace App\Services\AI;

use App\Models\Tenant;
use App\Models\TenantAiKey;
use App\Services\Contracts\AiProvider as AiProviderContract;
use App\Services\DTOs\AiProviderCallResult;
use App\Support\TenantContext;
use Illuminate\Support\Str;

/** Workspace-owned models using the provider's public API. */
class AiProviderServiceImpl implements AiProviderContract
{
    // Internal slot selectors, never sent as provider model names.
    public const GEO_TEXT_MODEL = 'text';
    public const GEO_VISION_MODEL = 'vision';
    public const GEO_EMBED_MODEL = 'embedding';

    public function __construct(private readonly ModelConfiguration $configuration, private readonly ModelEndpointClient $client) {}

    public function generate(string $prompt, ?string $system = null, array $options = []): AiProviderCallResult
    {
        $images = is_array($options['images'] ?? null) ? $options['images'] : [];
        $slot = $images !== [] || ($options['slot'] ?? '') === 'vision' ? 'vision' : 'text';
        $key = $this->configuration->active($slot);
        if (! $key) {
            return AiProviderCallResult::failure('', '', $slot === 'vision' ? '未配置视觉模型' : '未配置文本模型',
                ['pre_send_rejection' => true, 'status' => 'failed', 'request_sent' => false]);
        }
        $data = $this->connection($key);
        $content = $prompt;
        if ($images !== []) {
            $content = [];
            foreach ($images as $image) {
                if (is_string($image) && $image !== '') {
                    $content[] = ['type' => 'image_url', 'image_url' => ['url' => $image]];
                }
            }
        }
        if ($images !== []) { $content[] = ['type' => 'text', 'text' => $prompt]; }
        $messages = $system ? [['role' => 'system', 'content' => $system]] : [];
        $messages[] = ['role' => 'user', 'content' => $content];
        $payload = ['model' => $data['model'], 'messages' => $messages,
            'max_tokens' => (int) ($options['max_tokens'] ?? 4096)];
        if (isset($options['temperature'])) {
            $payload['temperature'] = $options['temperature'];
        }
        if (! empty($options['json_object']) && ($key->metadata['json_mode'] ?? false)) {
            $payload['response_format'] = ['type' => 'json_object'];
        }
        if (! empty($options['_json_schema']) && $data['provider'] === 'openai' && config('geo.native_json_schema', false)) {
            $payload['response_format'] = ['type' => 'json_schema', 'json_schema' =>
                ['name' => 'workspace_output', 'strict' => true, 'schema' => $options['_json_schema']]];
        }
        // These providers' JSON mode requires non-thinking decoding.
        if (isset($payload['response_format']) && $data['provider'] === 'dashscope') {
            $payload['enable_thinking'] = false;
        }
        if (isset($payload['response_format']) && $data['provider'] === 'volces') {
            $payload['thinking'] = ['type' => 'disabled'];
        }
        $response = $this->client->post($data, '/chat/completions', $payload, (int) config('geo.model_http_timeout', 280));
        $body = $response['body'];
        $text = $body['choices'][0]['message']['content'] ?? null;
        if ($response['success'] && ! is_string($text)) {
            $response = array_replace($response, ['success' => false, 'status' => 'failed', 'error' => '模型没有返回可用内容。']);
        }

        return new AiProviderCallResult(
            success: $response['success'], provider: $data['provider'], model: $data['model'],
            content: is_string($text) ? $text : null,
            promptTokens: $response['usage']['prompt_tokens'] ?? 0,
            completionTokens: $response['usage']['completion_tokens'] ?? 0,
            totalTokens: $response['usage']['total_tokens'] ?? 0,
            durationSeconds: $response['duration_seconds'], errorMessage: $response['error'],
            finishReason: is_string($body['choices'][0]['finish_reason'] ?? null) ? $body['choices'][0]['finish_reason'] : null,
            gateway: $this->receipt($key, $response, $options),
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
            $key = $this->configuration->active($slot, $tenant->id);
            if ($key) {
                $providers[$key->provider]['name'] = config("ai-providers.{$key->provider}.name", $key->provider);
                $providers[$key->provider]['models'][] = $key->metadata['model'];
            }
        }

        return $providers;
    }

    public function pickBestModel(Tenant $tenant, ?string $preferred = null): array
    {
        $key = $this->configuration->active('text', $tenant->id);

        return $key ? ['provider' => $key->provider, 'model' => $key->metadata['model']] : ['provider' => '', 'model' => ''];
    }

    public function getTenantApiKey(Tenant $tenant, string $provider): ?string
    {
        $key = $this->configuration->active('text', $tenant->id);

        return $key?->provider === $provider ? $key->getDecryptedKey() : null;
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
        $key = $this->configuration->active('embedding');
        if (! $key) {
            return ['embeddings' => [], 'tokens' => 0, 'error' => '未配置向量模型', 'attempt_sent' => false,
                'gateway' => ['pre_send_rejection' => true, 'request_sent' => false, 'status' => 'failed']];
        }
        $data = $this->connection($key);
        if ($texts === [] || (config("ai-providers.{$key->provider}.embedding_protocol") === 'ark_multimodal' && count($texts) !== 1)) {
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
            'gateway' => $this->receipt($key, $response, $options),
            'provider' => $data['provider'], 'model' => $data['model']];
    }

    private function connection(TenantAiKey $key): array
    {
        return ['slot' => $key->slot, 'provider' => $key->provider, 'model' => $key->metadata['model'],
            'base_url' => $key->metadata['base_url'], 'api_key' => $key->getDecryptedKey()];
    }

    /** Legacy DTO field name retained for saved local delivery checkpoints, not wire protocol. */
    private function receipt(TenantAiKey $key, array $response, array $options): array
    {
        return [
            // Local attempt identity cannot collide across vendors returning the same ID.
            'request_id' => 'model:'.Str::uuid(), 'provider_request_id' => $response['request_id'],
            'provider' => $key->provider, 'model' => $key->metadata['model'],
            'credential_fingerprint' => $this->configuration->fingerprint($key->tenant_id, $this->connection($key)),
            'status' => $response['status'], 'http_status' => $response['http_status'] ?? null,
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
