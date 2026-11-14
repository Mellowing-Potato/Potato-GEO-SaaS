<?php

namespace App\Services\Geo\AiVisibility;

/**
 * AI 端点白名单（批次二 · 单 3）。
 *
 * 作用：把「带着 Bearer key 的请求只会发去谁」钉死在配置里。
 * SafeOutboundHttpClient 拦的是内网/重绑定/超限（通用威胁）；
 * 这里拦的是「端点配错/被改」——防止 ARK_API_KEY 被发到非方舟主机
 * （配置手滑、env 被注入、密钥收集型攻击都会走这条路）。
 *
 * 规则：
 *  - 只允许 https（明文 http 会把 key 直接暴露在网络路径上）；
 *  - host 必须精确命中白名单条目，或命中「*.suffix」子域条目；
 *  - 白名单来自 config('geo.visibility.allowed_hosts')，默认只有北京区方舟。
 */
final class AiProviderEndpointPolicy
{
    /** @param list<string> $allowedHosts */
    public function __construct(
        private readonly array $allowedHosts = ['ark.cn-beijing.volces.com'],
    ) {}

    /**
     * @throws \InvalidArgumentException 端点不允许（异常消息只含 host，不含完整 URL/query，防密钥泄漏进日志）
     */
    public function assertAllowed(string $endpoint): void
    {
        $parts = parse_url($endpoint);
        if (! is_array($parts) || ($parts['scheme'] ?? '') === '' || ($parts['host'] ?? '') === '') {
            throw new \InvalidArgumentException('AI endpoint is not a valid absolute URL.');
        }

        if (strtolower((string) $parts['scheme']) !== 'https') {
            throw new \InvalidArgumentException('AI endpoint must use https.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('AI endpoint must not carry credentials in the URL.');
        }

        $host = strtolower((string) $parts['host']);
        if (! $this->hostAllowed($host)) {
            // 只暴露 host，不暴露 path/query（query 可能带 token）。
            throw new \InvalidArgumentException("AI endpoint host is not allowed: {$host}");
        }
    }

    private function hostAllowed(string $host): bool
    {
        foreach ($this->allowedHosts as $entry) {
            $allowed = strtolower(trim($entry));
            if ($allowed === '') {
                continue;
            }
            if (str_starts_with($allowed, '*.')) {
                if (str_ends_with($host, substr($allowed, 1))) {
                    return true;
                }

                continue;
            }
            if ($host === $allowed) {
                return true;
            }
        }

        return false;
    }
}
