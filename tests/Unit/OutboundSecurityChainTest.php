<?php

namespace Tests\Unit;

use App\Contracts\Outbound\HostResolver;
use App\Contracts\Outbound\OutboundTransport;
use App\Services\Outbound\OutboundRequestBlockedException;
use App\Services\Outbound\ResolvedOutboundTarget;
use App\Services\Outbound\ResponseSizeLimitedStream;
use App\Services\Outbound\SafeOutboundHttpClient;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Stream;
use Illuminate\Http\Client\Factory as LaravelHttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 出站安全链（批次一 · 单 1）验收测试。
 *
 * 覆盖派单判据 O1–O5：
 *   O1  loopback（127.0.0.1:8792，即网关地址）被拒
 *   O2  云元数据地址（169.254.169.254）被拒
 *   O3  公网样貌域名解析到内网 IP（DNS 重绑定）被拒
 *   O4  超大响应体被 ResponseSizeLimitedStream 截断
 *   O5  outbound_enforce=false（观察模式）时地址类判定放行且有日志
 *
 * ⚠️ O5 与派单判据字面有一处**有意偏离**：观察模式只放开「地址类」判定，
 *    不放开「URL 结构非法」与「响应体超限」。理由见 config/geo.php 注释与交付报告。
 *    对应用例：test_o5_observation_mode_does_not_release_malformed_url
 *              test_o5_observation_mode_does_not_release_oversized_body
 */
class OutboundSecurityChainTest extends TestCase
{
    /** @param list<string> $ips */
    private function resolverReturning(array $ips): HostResolver
    {
        return new class($ips) implements HostResolver
        {
            /** @param list<string> $ips */
            public function __construct(private readonly array $ips) {}

            public function resolve(string $host): array
            {
                return $this->ips;
            }
        };
    }

    private function passThroughTransport(): OutboundTransport
    {
        return new class implements OutboundTransport
        {
            public function send(
                PendingRequest $request,
                string $method,
                ResolvedOutboundTarget $target,
                array $data,
                int $maxBytes,
                bool $crossOrigin = false,
            ): Response {
                return new Response(new PsrResponse(200, [], 'ok'));
            }
        };
    }

    /**
     * ⚠️ 公网样例 IP 不能用 RFC 5737 文档段（192.0.2.0/24、198.51.100.0/24、
     *    203.0.113.0/24）—— 那三段在 BLOCKED_IPV4_CIDRS 里被显式封禁，
     *    拿来当「正常公网目标」会被拒，测试就假失败。用真实公网段。
     *
     * @param  list<string>  $ips
     */
    private function client(bool $enforce, array $ips = ['93.184.216.34']): SafeOutboundHttpClient
    {
        return new SafeOutboundHttpClient(
            $this->resolverReturning($ips),
            $this->passThroughTransport(),
            $enforce,
        );
    }

    private function assertBlockedWithReason(string $expectedReason, callable $callback): void
    {
        try {
            $callback();
        } catch (OutboundRequestBlockedException $exception) {
            $this->assertSame($expectedReason, $exception->reasonCode);

            return;
        }

        $this->fail('预期抛出 OutboundRequestBlockedException('.$expectedReason.')，但请求被放行了。');
    }

    // ── O1 ────────────────────────────────────────────────────────────────

    public function test_o1_loopback_gateway_address_is_blocked(): void
    {
        // 本机服务地址仍须经过精确白名单校验：
        // 它被拦既证明防护生效，也正是「不能全局替换 HttpFactory」的原因。
        $this->assertBlockedWithReason(
            'unsafe_address',
            fn () => $this->client(true)->resolveTarget('http://127.0.0.1:8792/'),
        );
    }

    public function test_o1_ipv6_loopback_is_blocked(): void
    {
        $this->assertBlockedWithReason(
            'unsafe_address',
            fn () => $this->client(true)->resolveTarget('http://[::1]:8792/'),
        );
    }

    public function test_o1_localhost_hostname_is_blocked(): void
    {
        $this->assertBlockedWithReason(
            'unsafe_address',
            fn () => $this->client(true)->resolveTarget('http://localhost:8792/'),
        );
    }

    public function test_o1_obfuscated_loopback_literal_is_blocked(): void
    {
        // 2130706433 == 127.0.0.1 的十进制写法，绕过朴素字符串匹配的经典手法。
        $this->assertBlockedWithReason(
            'ambiguous_ip',
            fn () => $this->client(true)->resolveTarget('http://2130706433/'),
        );
    }

    // ── O2 ────────────────────────────────────────────────────────────────

    public function test_o2_cloud_metadata_address_is_blocked(): void
    {
        $this->assertBlockedWithReason(
            'unsafe_address',
            fn () => $this->client(true)->resolveTarget('http://169.254.169.254/latest/meta-data/'),
        );
    }

    public function test_o2_cloud_metadata_via_dns_is_blocked(): void
    {
        // 云厂商元数据也常有域名入口，域名一样要按解析结果判定。
        $this->assertBlockedWithReason(
            'unsafe_address',
            fn () => $this->client(true, ['169.254.169.254'])->resolveTarget('http://metadata.example.com/'),
        );
    }

    // ── O3 ────────────────────────────────────────────────────────────────

    public function test_o3_dns_rebinding_to_private_range_is_blocked(): void
    {
        $this->assertBlockedWithReason(
            'unsafe_address',
            fn () => $this->client(true, ['192.168.1.5'])->resolveTarget('http://innocent-looking.example.com/'),
        );
    }

    public function test_o3_dns_rebinding_blocked_when_any_address_is_private(): void
    {
        // 一个公网 + 一个内网：必须整体拒绝。
        // 只要放行，攻击者就能靠 DNS 轮询在两次解析之间切到内网地址。
        $this->assertBlockedWithReason(
            'unsafe_address',
            fn () => $this->client(true, ['93.184.216.34', '10.0.0.7'])->resolveTarget('http://mixed.example.com/'),
        );
    }

    public function test_o3_ipv4_mapped_ipv6_is_blocked(): void
    {
        $this->assertBlockedWithReason(
            'mapped_address',
            fn () => $this->client(true, ['::ffff:127.0.0.1'])->resolveTarget('http://mapped.example.com/'),
        );
    }

    public function test_o3_public_address_is_allowed(): void
    {
        // 反向对照：正常公网目标必须能过，否则「零误杀」无从谈起。
        $target = $this->client(true, ['93.184.216.34'])->resolveTarget('https://example.com/path?a=1');

        $this->assertSame('example.com', $target->host);
        $this->assertSame(443, $target->port);
        $this->assertSame('93.184.216.34', $target->selectedIp);
    }

    /**
     * 钉住一个静默数据损坏：URL 的 path/query 必须原样保留。
     *
     * PHP 8.5.7 的 parse_url() 会把 path 里 0x80–0x9F 的字节替换成 '_'，而那一段
     * 正是 UTF-8 汉字的续字节：「文章」(e6 96 87 e7 ab a0) → (e6 5f 5f e7 ab a0)。
     * 一旦 normalizeUrl 改回用 parse_url 的 path 重组 URL，抓中文路径的网页就会
     * 静默抓错（404 或别的页面），不抛异常、日志也看不出来。
     *
     * 所以这条测试不是测「URL 拼接」，是测「不许再踩回去」。
     */
    public function test_non_ascii_path_and_query_survive_normalization(): void
    {
        $client = $this->client(true, ['93.184.216.34']);

        $cases = [
            'https://example.com/文章/2026/深度报告',
            'https://example.com/搜索?关键词=出站安全&页=2',
            'https://example.com/%E6%96%87%E7%AB%A0/2026',   // 已百分号编码的不许被二次编码
            'https://example.com/a~b_c-d.e/f',                // RFC 3986 未保留字符
            'https://example.com/p?a[]=1&a[]=2',              // 方括号 query
        ];

        foreach ($cases as $url) {
            $this->assertSame($url, $client->resolveTarget($url)->url, "URL 被改写了：{$url}");
        }
    }

    // ── O4 ────────────────────────────────────────────────────────────────

    public function test_o4_oversized_body_is_capped_by_limited_stream(): void
    {
        $stream = new Stream(fopen('php://memory', 'r+b'));
        $stream->write(str_repeat('A', 5000));
        $stream->rewind();

        $limited = new ResponseSizeLimitedStream($stream, 100);

        // 读到上限是允许的。
        $this->assertSame(100, strlen($limited->read(100)));

        // 再读一个字节就超限 —— 截断语义：绝不让超过上限的字节流出去。
        $this->assertBlockedWithReason('response_too_large', fn () => $limited->read(100));
    }

    public function test_o4_body_within_limit_passes_through(): void
    {
        $stream = new Stream(fopen('php://memory', 'r+b'));
        $stream->write('small body');
        $stream->rewind();

        $limited = new ResponseSizeLimitedStream($stream, 1024);

        $this->assertSame('small body', $limited->read(1024));
    }

    // ── O5 ────────────────────────────────────────────────────────────────

    public function test_o5_observation_mode_releases_loopback_and_logs(): void
    {
        Log::spy();

        $target = $this->client(false, ['127.0.0.1'])->resolveTarget('http://127.0.0.1:8792/');

        $this->assertInstanceOf(ResolvedOutboundTarget::class, $target);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'outbound.observation_mode')
                && ($context['reason'] ?? null) === 'unsafe_address');
    }

    public function test_o5_observation_mode_releases_cloud_metadata_and_logs(): void
    {
        Log::spy();

        $target = $this->client(false)->resolveTarget('http://169.254.169.254/');

        $this->assertInstanceOf(ResolvedOutboundTarget::class, $target);
        Log::shouldHaveReceived('warning');
    }

    public function test_o5_observation_mode_releases_dns_rebinding_and_logs(): void
    {
        Log::spy();

        $target = $this->client(false, ['192.168.1.5'])->resolveTarget('http://innocent-looking.example.com/');

        $this->assertSame('192.168.1.5', $target->selectedIp);
        Log::shouldHaveReceived('warning');
    }

    public function test_o5_observation_mode_log_never_contains_query_string(): void
    {
        // 通用红线 6/7：URL query 可能带 API key，日志只许出现 host/port。
        Log::spy();

        $this->client(false, ['127.0.0.1'])->resolveTarget('http://127.0.0.1:8792/v1/chat?api_key=sk-test-ee01708');

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context): bool {
                $flat = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                return ! str_contains((string) $flat, 'sk-test-ee01708')
                    && ! str_contains((string) $flat, 'api_key');
            });
    }

    public function test_o5_observation_mode_end_to_end_send_passes_through(): void
    {
        Log::spy();

        $client = new SafeOutboundHttpClient(
            $this->resolverReturning(['127.0.0.1']),
            $this->passThroughTransport(),
            false,
        );

        $response = $client->send(
            new PendingRequest(new LaravelHttpFactory),
            'GET',
            'http://127.0.0.1:8792/',
            [],
            1_000_000,
        );

        $this->assertTrue($response->successful());
        Log::shouldHaveReceived('warning');
    }

    public function test_o5_falls_back_to_config_when_enforce_not_injected(): void
    {
        Log::spy();
        config(['geo.outbound_enforce' => false]);

        // enforce 不传 = 现读配置，这是生产的实际形态（provider 注入 null）。
        $client = new SafeOutboundHttpClient(
            $this->resolverReturning(['169.254.169.254']),
            $this->passThroughTransport(),
        );

        $this->assertInstanceOf(ResolvedOutboundTarget::class, $client->resolveTarget('http://169.254.169.254/'));
        Log::shouldHaveReceived('warning');
    }

    public function test_o5_config_default_is_enforcing(): void
    {
        // 默认必须是「拦」。默认放行的安全开关等于没有安全。
        $this->assertTrue((bool) config('geo.outbound_enforce'));
    }

    public function test_o5_observation_mode_does_not_release_malformed_url(): void
    {
        // 有意偏离判据字面：URL 结构非法不是误杀，观察模式也不放行。
        config(['geo.outbound_enforce' => false]);
        $client = new SafeOutboundHttpClient($this->resolverReturning(['93.184.216.34']), $this->passThroughTransport());

        $this->assertBlockedWithReason('invalid_scheme', fn () => $client->resolveTarget('file:///etc/passwd'));
        $this->assertBlockedWithReason('userinfo_forbidden', fn () => $client->resolveTarget('http://user:pw@example.com/'));
        $this->assertBlockedWithReason('control_character', fn () => $client->resolveTarget("http://example.com/\r\nX: 1"));
    }

    public function test_o5_observation_mode_does_not_release_oversized_body(): void
    {
        // 有意偏离判据字面：响应体超限是资源保护，放行会真的打爆 worker 内存。
        config(['geo.outbound_enforce' => false]);

        $stream = new Stream(fopen('php://memory', 'r+b'));
        $stream->write(str_repeat('A', 500));
        $stream->rewind();

        $limited = new ResponseSizeLimitedStream($stream, 10);

        $this->assertBlockedWithReason('response_too_large', fn () => $limited->read(50));
    }

    // ── 白名单（判据 R3 的单元层前置）────────────────────────────────────────

    public function test_private_target_whitelist_allows_exact_host_port(): void
    {
        config(['geo.outbound_private_targets' => ['127.0.0.1:8792']]);

        $target = $this->client(true, ['127.0.0.1'])->resolveTarget('http://127.0.0.1:8792/health');

        $this->assertSame('127.0.0.1', $target->selectedIp);
        $this->assertSame(8792, $target->port);
    }

    public function test_private_target_whitelist_is_port_specific(): void
    {
        // 白名单必须精确到端口：放行 8792 不等于放行 6379（Redis）。
        config(['geo.outbound_private_targets' => ['127.0.0.1:8792']]);

        $this->assertBlockedWithReason(
            'unsafe_address',
            fn () => $this->client(true, ['127.0.0.1'])->resolveTarget('http://127.0.0.1:6379/'),
        );
    }

    public function test_private_target_whitelist_rejects_wildcard_entries(): void
    {
        // 通配符白名单等于给 SSRF 开后门，必须不生效。
        config(['geo.outbound_private_targets' => ['127.0.0.1:*', '10.0.0.0/8']]);

        $this->assertBlockedWithReason(
            'unsafe_address',
            fn () => $this->client(true, ['127.0.0.1'])->resolveTarget('http://127.0.0.1:8792/'),
        );
    }

    public function test_private_target_config_parses_comma_separated_env(): void
    {
        // .env 只能给字符串，配置层必须把它归一化成数组，否则白名单静默失效。
        $parsed = array_values(array_filter(array_map('trim', explode(',', '127.0.0.1:8792, 10.0.0.5:80 ,'))));

        $this->assertSame(['127.0.0.1:8792', '10.0.0.5:80'], $parsed);
    }
}
