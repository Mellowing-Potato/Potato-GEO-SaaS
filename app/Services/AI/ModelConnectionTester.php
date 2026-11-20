<?php

namespace App\Services\AI;

use App\Models\ModelCallLog;
use App\Models\ModelConnectionProfile;
use App\Services\Geo\AiVisibility\AiProviderEndpointPolicy;

final class ModelConnectionTester
{
    public function __construct(
        private readonly ModelEndpointClient $client,
        private readonly ModelConfiguration $configuration,
        private readonly ModelConnectionResolver $connections,
    ) {}

    public function testProfile(ModelConnectionProfile $profile, int $userId): array
    {
        try {
            $connection = $this->connections->fromProfile($profile);
            $this->connections->capabilities($connection, $connection->slot === 'vision');
        } catch (ModelConnectionUnavailable|MagpieException $e) {
            return ['success' => false, 'status' => 'failed', 'message' => $e->getMessage(), 'verification_token' => null];
        }

        return $this->run(
            ['slot' => $connection->slot, 'provider' => $connection->provider, 'model' => $connection->model],
            $connection->tenantId, $userId, $connection,
        );
    }

    public function test(array $data, int $tenantId, int $userId): array
    {
        return $this->run($data, $tenantId, $userId);
    }

    private function run(array $data, int $tenantId, int $userId, ?ResolvedModelConnection $connection = null): array
    {
        if ($data['slot'] === 'embedding') {
            [$path, $payload] = $this->client->embeddingRequest($data, ['言及模型连接测试']);
        } elseif ($data['slot'] === 'visibility') {
            // Same host policy as monitoring: never send its Key to arbitrary hosts.
            (new AiProviderEndpointPolicy(config('geo.visibility.allowed_hosts', ['ark.cn-beijing.volces.com'])))->assertAllowed($data['base_url']);
            $path = '/responses';
            // Search needs room for tool output and a cited answer. The 128-token
            // text probe budget truncates a valid Ark response after authentication.
            $payload = ['model' => $data['model'], 'input' => '请联网查找 GEO（生成式引擎优化）的定义，用一句话回答并附一个来源链接。',
                'tools' => [['type' => 'web_search']], 'thinking' => ['type' => 'disabled'], 'max_output_tokens' => 1024, 'stream' => false];
        } else {
            $path = '/chat/completions';
            $content = '请只回复 OK。';
            if ($data['slot'] === 'vision') {
                // One deterministic 1x1 test image, not a generated business result.
                $content = [['type' => 'text', 'text' => '这是一张测试图片，请只回复 OK。'], ['type' => 'image_url',
                    'image_url' => ['url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a0S8AAAAASUVORK5CYII=']]];
            }
            $payload = ['model' => $data['model'], 'messages' => [['role' => 'user', 'content' => $content]], 'max_tokens' => 128];
        }
        $result = $connection === null
            ? $this->client->post($data, $path, $payload)
            : $this->client->chat($connection, $payload);
        if ($result['success']) {
            if ($data['slot'] === 'embedding' && $this->client->vectors($data, $result['body'], 1) === null) {
                $result = array_replace($result, ['success' => false, 'status' => 'failed', 'error' => '模型未返回有效的 1024 维向量，不能保存为知识库模型。']);
            } elseif ($data['slot'] === 'visibility') {
                $visibility = app(\App\Services\Geo\AiVisibility\AiVisibilityResultNormalizer::class)
                    ->normalize('连接测试', $data['provider'], $data['model'], $result['body'], $result['duration_seconds']);
                if (! $visibility->success || ($result['body']['status'] ?? null) !== 'completed') {
                    $result = array_replace($result, ['success' => false, 'status' => 'failed', 'error' =>
                        ($result['body']['status'] ?? null) === 'incomplete'
                            ? '方舟已接受请求，但回答未完成（可能达到输出上限）。这不代表 API Key 无效；未自动重试。'
                            : '方舟未返回可读的完整回答，请检查模型返回状态。']);
                }
            } elseif (in_array($data['slot'], ['text', 'vision'], true) && ! is_string($result['body']['choices'][0]['message']['content'] ?? null)) {
                $result = array_replace($result, ['success' => false, 'status' => 'failed', 'error' => '模型没有返回可用内容，请检查模型配置。']);
            }
        }
        ModelCallLog::create(['tenant_id' => $tenantId, 'user_id' => $userId, 'project_id' => null,
            'provider' => $data['provider'], 'model' => $data['model'], 'status' => $result['status'],
            'prompt_tokens' => $result['usage']['prompt_tokens'] ?? null, 'completion_tokens' => $result['usage']['completion_tokens'] ?? null,
            'total_tokens' => $result['usage']['total_tokens'] ?? null, 'duration_seconds' => $result['duration_seconds'],
            'metadata' => ['feature' => 'model-connection-test', 'slot' => $data['slot'], 'usage_available' => $result['usage'] !== null,
                'provider_request_id' => $result['request_id'], 'request_sent' => ! ($result['before_send'] ?? false)]]);

        return ['success' => $result['success'], 'status' => $result['status'],
            'message' => $result['success'] ? '连接测试通过。' : $result['error'],
            'verification_token' => $result['success'] && $data['slot'] === 'embedding' ? $this->configuration->verificationToken($tenantId, $data) : null];
    }
}
