<?php

namespace App\Services\Geo\AiVisibility;

/**
 * AI 可见性配置解析（批次二 · 单 3）。
 *
 * 所有 env 读取集中在 config('geo.visibility.*')（config/geo.php 里统一声明），
 * 本类只做「现读 + 缺省」，不在注册期烧死值——与 OutboundServiceProvider 的
 * enforce 处理同理：观察/回滚要能靠改 env + 重启生效。
 */
final class AiVisibilityConfigurationResolver
{
    public function enabled(): bool
    {
        return (bool) config('geo.visibility_panel_enabled', false);
    }

    public function connection(): ?array
    {
        $configuration = app(\App\Services\AI\ModelConfiguration::class);
        $key = $configuration->active('visibility');
        if (! $key || $key->provider !== 'volces') {
            return null;
        }
        $data = ['slot' => 'visibility', 'provider' => $key->provider, 'model' => $key->metadata['model'],
            'base_url' => $key->metadata['base_url'], 'api_key' => $key->getDecryptedKey()];
        return $data + ['credential_fingerprint' => $configuration->fingerprint($key->tenant_id, $data)];
    }

    public function apiKey(): ?string
    {
        return $this->connection()['api_key'] ?? null;
    }

    public function endpoint(): string
    {
        return rtrim($this->connection()['base_url'] ?? (string) config('ai-providers.volces.base_url'), '/').'/responses';
    }

    public function model(): string
    {
        return $this->connection()['model'] ?? '';
    }

    /**
     * HTTP 超时(秒)。网关搜索预算240秒，客户端留10秒回传余量。
     * 必须小于 Job $timeout(280) < worker --timeout(300) < retry_after(600)。
     */
    public function httpTimeoutSeconds(): int
    {
        return max(250, min(260, (int) config('geo.visibility.http_timeout', 250)));
    }

    /** 响应体上限：AI 类端点统一走 geo.outbound_ai_max_bytes（默认 8MB）。 */
    public function maxBytes(): int
    {
        return (int) config('geo.outbound_ai_max_bytes', 8 * 1024 * 1024);
    }

    public function maxQuestions(): int
    {
        return max(1, (int) config('geo.visibility.max_questions', 10));
    }

    /** @return list<string> */
    public function allowedHosts(): array
    {
        $configured = config('geo.visibility.allowed_hosts', ['ark.cn-beijing.volces.com']);
        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            is_array($configured) ? $configured : [],
        ), static fn (string $item): bool => $item !== ''));
    }

    public function providerName(): string
    {
        return 'volces';
    }
}
