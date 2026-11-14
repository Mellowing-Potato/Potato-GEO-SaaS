<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 分发渠道地址 SSRF 护栏
 *
 * 服务端会对渠道 endpoint_url 发起 health / publish / 媒体下载等出站请求，
 * 因此必须拦截把地址指向本地 / 内网 / 云元数据的直接 SSRF 向量。
 *
 * 注意：这是第一层缓解，只挡「私网 IP 字面量 + 回环/元数据主机名」。
 * 完整防护（含 DNS rebinding：域名解析到内网 IP）仍需在出站层做 egress IP 过滤。
 */
class SafeChannelEndpoint implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $url = is_string($value) ? trim($value) : '';
        $parts = parse_url($url);

        if ($parts === false || empty($parts['host'])) {
            $fail('渠道地址不是合法的 URL。');

            return;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true)) {
            $fail('渠道地址必须使用 http 或 https。');

            return;
        }

        $host = strtolower($parts['host']);

        // 回环 / 元数据 / 内网保留域名
        $blockedHosts = ['localhost', 'metadata', 'metadata.google.internal'];
        if (in_array($host, $blockedHosts, true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.internal')
            || str_ends_with($host, '.local')) {
            $fail('渠道地址不能指向本地或内网主机。');

            return;
        }

        // IPv6 字面量 [::1] → 去括号后判定
        $ip = $host;
        if (str_starts_with($ip, '[') && str_ends_with($ip, ']')) {
            $ip = substr($ip, 1, -1);
        }

        // 若主机是 IP 字面量，必须是公网地址（拦私网 / 保留 / 回环 / 链路本地）
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            if (in_array($ip, ['::1', '::', '0.0.0.0'], true)
                || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                $fail('渠道地址不能指向内网 / 保留地址。');

                return;
            }
        }
    }
}
