<?php

namespace Tests\Concerns;

use App\Contracts\Outbound\HostResolver;
use App\Contracts\Outbound\OutboundTransport;
use App\Services\Outbound\OutboundRequestBlockedException;
use App\Services\Outbound\ResolvedOutboundTarget;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Services\Outbound\SecurePendingRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * 出站安全链的测试打桩。
 *
 * 为什么不能直接用 Http::fake()：
 * 接入安全链后，请求不再走 Http facade 背后那个全局 Factory，而是走
 * SecureHttpFactory 造出的 SecurePendingRequest + 自有 transport。
 * Http::fake() 的 stub 挂在全局 Factory 实例上，对安全链完全不生效 ——
 * 测试会真的去连外网（或直接失败），而不是命中假响应。
 *
 * 这里把 SafeOutboundHttpClient 整个换成「假 DNS + 假传输」的实例：
 * - 假 DNS：不依赖真实网络，测试可自由指定域名解析到哪个 IP（含内网 IP，
 *   用来验证拦截确实发生）。
 * - 假传输：返回预设响应，不发真包。
 *
 * 注意：地址判定逻辑本身**没有**被打桩，仍是真的 resolveTarget 在跑，
 * 所以「该拦的有没有拦住」在 Feature 测试里依然是真判定。
 */
trait FakesSafeOutbound
{
    /** @var list<array{method: string, url: string, maxBytes: int}> */
    protected array $recordedOutboundRequests = [];

    /**
     * @param  list<string>  $resolvesTo  假 DNS 的解析结果，默认一个真实公网 IP。
     *                                    ⚠️ 不要用 RFC 5737 文档段（203.0.113.x 等），
     *                                    那几段被安全链显式封禁，会被判为内网。
     * @param  array<string, string>  $headers
     */
    protected function fakeSafeOutbound(
        string $body = '',
        int $status = 200,
        array $resolvesTo = ['93.184.216.34'],
        array $headers = [],
        ?bool $enforce = true,
    ): void {
        $this->fakeSafeOutboundSequence(
            [['status' => $status, 'body' => $body, 'headers' => $headers]],
            $resolvesTo,
            $enforce,
        );
    }

    /**
     * 同上，但可以按顺序给出多个响应 —— 用来测跳转（301 → 200）。
     *
     * 响应用完后最后一条会一直重复，免得测试里为了凑数写一堆重复项。
     *
     * @param  list<array{status?: int, body?: string, headers?: array<string, string>}>  $responses
     * @param  list<string>  $resolvesTo
     */
    protected function fakeSafeOutboundSequence(
        array $responses,
        array $resolvesTo = ['93.184.216.34'],
        ?bool $enforce = true,
    ): void {
        $this->recordedOutboundRequests = [];

        $resolver = new class($resolvesTo) implements HostResolver
        {
            /** @param list<string> $ips */
            public function __construct(private readonly array $ips) {}

            public function resolve(string $host): array
            {
                return $this->ips;
            }
        };

        $recorder = function (string $method, string $url, int $maxBytes): void {
            $this->recordedOutboundRequests[] = [
                'method' => $method,
                'url' => $url,
                'maxBytes' => $maxBytes,
            ];
        };

        $transport = new class($responses, $recorder) implements OutboundTransport
        {
            private int $calls = 0;

            /** @param list<array{status?: int, body?: string, headers?: array<string, string>}> $responses */
            public function __construct(
                private readonly array $responses,
                private readonly \Closure $recorder,
            ) {}

            public function send(
                PendingRequest $request,
                string $method,
                ResolvedOutboundTarget $target,
                array $data,
                int $maxBytes,
                bool $crossOrigin = false,
            ): Response {
                // ⚠️ 真传输 LaravelPinnedOutboundTransport 的第一件事就是
                // `if (! $request instanceof SecurePendingRequest) throw ...`。
                // 假传输必须照抄这条，否则「调用方用错了 HTTP 工厂」在测试里
                // 一路绿灯、上了生产 100% 发不出去 —— 单 8 的线索通知就是这么漏的
                // （用了 Http facade，生产每次都是 secure_pending_request_required）。
                if (! $request instanceof SecurePendingRequest) {
                    throw new OutboundRequestBlockedException('secure_pending_request_required');
                }

                ($this->recorder)($method, $target->url, $maxBytes);

                $index = min($this->calls, count($this->responses) - 1);
                $this->calls++;
                $spec = $this->responses[$index] ?? [];

                return new Response(new PsrResponse(
                    $spec['status'] ?? 200,
                    $spec['headers'] ?? [],
                    $spec['body'] ?? '',
                ));
            }
        };

        $this->app->instance(HostResolver::class, $resolver);
        $this->app->instance(OutboundTransport::class, $transport);
        $this->app->instance(
            SafeOutboundHttpClient::class,
            new SafeOutboundHttpClient($resolver, $transport, $enforce),
        );
    }

    /** 第 $index 次出站请求打的是哪个 URL（跳转有没有跟对，看这个）。 */
    protected function recordedOutboundUrl(int $index = 0): string
    {
        $this->assertArrayHasKey($index, $this->recordedOutboundRequests, '没有记录到第 ' . $index . ' 次出站请求。');

        return $this->recordedOutboundRequests[$index]['url'];
    }

    /** 断言安全链实际发出了几次请求（0 次即代表被拦在发包之前）。 */
    protected function assertOutboundRequestCount(int $expected): void
    {
        $this->assertCount(
            $expected,
            $this->recordedOutboundRequests,
            '出站请求次数不符：实际 ' . count($this->recordedOutboundRequests) . ' 次。',
        );
    }

    protected function assertOutboundMaxBytes(int $expected, int $index = 0): void
    {
        $this->assertArrayHasKey($index, $this->recordedOutboundRequests, '没有记录到第 ' . $index . ' 次出站请求。');
        $this->assertSame($expected, $this->recordedOutboundRequests[$index]['maxBytes']);
    }
}
