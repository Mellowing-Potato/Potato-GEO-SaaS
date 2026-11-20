<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted outbound validation and transport for Yanji model and workspace requests.
 * Upstream: app/Services/Outbound/SafeOutboundHttpClient.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Services\Outbound;

use App\Contracts\Outbound\HostResolver;
use App\Contracts\Outbound\OutboundTransport;
use App\Services\AI\MagpieTargets;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

final class SafeOutboundHttpClient
{
    /** @var list<string> */
    private const BLOCKED_IPV4_CIDRS = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    /** @var list<string> */
    private const BLOCKED_IPV6_CIDRS = [
        '::/96',
        '::ffff:0:0/96',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '100::/64',
        '100:0:0:1::/64',
        '2001::/23',
        '2001:db8::/32',
        '2002::/16',
        '3fff::/20',
        '5f00::/16',
        'fc00::/7',
        'fe80::/10',
        'fec0::/10',
        'ff00::/8',
    ];

    /** @var list<string> */
    private const UNSAFE_TRANSITION_IPV6_CIDRS = [
        '::/96',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '2001::/32',
        '2002::/16',
    ];

    /** @var list<string> */
    private const MAGPIE_LOCAL_CIDRS = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', 'fc00::/7'];

    public function __construct(
        private readonly HostResolver $resolver,
        private readonly OutboundTransport $transport,
        private readonly ?bool $enforce = null,
    ) {}

    /** Model credentials always require enforcement, including in observation mode. */
    public function withEnforcement(): self
    {
        return new self($this->resolver, $this->transport, true);
    }

    /** @param array<string, mixed> $query */
    public function get(
        PendingRequest $request,
        string $url,
        int $maxBytes,
        int $maxRedirects = 0,
        array $query = [],
        ?callable $redirectValidator = null,
    ): Response {
        return $this->send($request, 'GET', $url, $query, $maxBytes, $maxRedirects, $redirectValidator);
    }

    /** @param array<string, mixed> $data */
    public function post(PendingRequest $request, string $url, array $data, int $maxBytes): Response
    {
        return $this->send($request, 'POST', $url, $data, $maxBytes);
    }

    /** @param array<string, mixed> $data */
    public function delete(PendingRequest $request, string $url, array $data, int $maxBytes): Response
    {
        return $this->send($request, 'DELETE', $url, $data, $maxBytes);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function send(
        PendingRequest $request,
        string $method,
        string $url,
        array $data,
        int $maxBytes,
        int $maxRedirects = 0,
        ?callable $redirectValidator = null,
        ?MagpieRequestScope $magpie = null,
    ): Response {
        if ($magpie !== null && ($maxRedirects !== 0 || (strtoupper($method) === 'GET' && $data !== []))) {
            throw new OutboundRequestBlockedException('magpie_operation_forbidden');
        }
        if ($maxBytes < 1 || $maxRedirects < 0 || $maxRedirects > 3) {
            throw new OutboundRequestBlockedException('invalid_request_policy');
        }

        $method = strtoupper($method);
        $redirects = 0;
        $currentUrl = $url;
        $currentRequest = clone $request;
        $currentData = $data;
        $previousTarget = null;

        while (true) {
            $target = $this->resolveTarget($currentUrl, $magpie, $method);
            $crossOrigin = $previousTarget instanceof ResolvedOutboundTarget && ! $this->sameOrigin($previousTarget, $target);
            if ($crossOrigin) {
                $currentRequest = CrossOriginRequestSanitizer::pendingRequest($currentRequest, $method, $target->url);
                if (in_array($method, ['GET', 'HEAD'], true)) {
                    $currentData = [];
                }
            }

            try {
                $magpie?->assertAuthorized($method, $target->url);
                $response = $this->transport->send($currentRequest, $method, $target, $currentData, $maxBytes, $crossOrigin);
            } catch (OutboundRequestBlockedException|OutboundRequestFailedException $exception) {
                throw $exception;
            } catch (\Throwable) {
                throw new OutboundRequestFailedException;
            }
            $this->assertResponseSize($response, $maxBytes);

            if (! $this->isRedirect($response)) {
                return $response;
            }

            $location = trim((string) $response->header('Location', ''));
            if ($location === '') {
                return $response;
            }
            if ($redirects >= $maxRedirects) {
                throw new OutboundRequestBlockedException('redirect_limit_exceeded');
            }

            $redirectUrl = $this->redirectUrl($target->url, $location);
            if ($redirectValidator !== null) {
                $redirectValidator($redirectUrl);
            }

            $previousTarget = $target;
            $currentUrl = $redirectUrl;
            $redirects++;
            $status = $response->status();
            if ($status === 303 || (in_array($status, [301, 302], true) && $method === 'POST')) {
                $method = 'GET';
                $currentData = [];
                $currentRequest = $this->clearRequestEntity($currentRequest);
            }
        }
    }

    public function resolveTarget(string $url, ?MagpieRequestScope $magpie = null, string $method = 'GET'): ResolvedOutboundTarget
    {
        $magpie?->assertAuthorized($method, $url);
        [$normalizedUrl, $scheme, $host, $port] = $this->normalizeUrl($url, $magpie !== null);
        $reserved = $magpie === null ? (new MagpieTargets)->reservedHosts($port) : [];
        if ($reserved !== [] && (array_key_exists($host, $reserved) || $host === 'host.docker.internal'
                || (filter_var($host, FILTER_VALIDATE_IP) === false && $this->looksLikeAmbiguousIp($host)))) {
            throw new OutboundRequestBlockedException('magpie_target_reserved');
        }
        $ipLiteral = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if ($magpie === null && ! $ipLiteral && ! str_contains($host, '.') && ! $this->privateTargetAllowed($host, $port)) {
            // 无点主机名（如 intranet-box）只能解析到内网命名空间，等同内网直连。
            $this->blockOrObserve('unsafe_address', $host, $port, ['rule' => 'non_dotted_host']);
        }
        try {
            $addresses = $this->addresses($host);
        } catch (\Throwable) {
            throw new OutboundRequestFailedException(beforeSend: true);
        }
        // Explicit deployment pins also support targets provided through container
        // host mappings, which the DNS record resolver does not consult.
        if ($addresses === [] && $magpie !== null) {
            $addresses = $magpie->target->addresses;
        }
        $addresses = array_values(array_unique(array_map(static fn (mixed $ip): string => strtolower(trim((string) $ip)), $addresses)));
        if ($addresses === [] || in_array('', $addresses, true)) {
            // 解析不出地址就没有可连的目标，观察模式也无从「放行」，一律失败。
            throw new OutboundRequestBlockedException('dns_resolution_failed');
        }

        $this->assertNotReserved($reserved, $addresses);
        if ($magpie !== null) {
            $this->assertMagpieAddresses($magpie, $addresses);
            $selectedIp = $magpie->pinnedIp ?? $addresses[0];

            return new ResolvedOutboundTarget($normalizedUrl, $scheme, $host, $port, $addresses, $selectedIp, $magpie);
        }

        $allowPrivate = $this->privateTargetAllowed($host, $port);
        $blockedReasons = [];
        foreach ($addresses as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                throw new OutboundRequestBlockedException('invalid_address');
            }
            // 收集而非立即抛：观察模式下要把所有命中的原因一次性记全，
            // 否则排查时只能看到第一条，反复上线才能凑齐全貌。
            if ($this->isIpv4MappedAddress($ip)) {
                $blockedReasons[] = 'mapped_address';

                continue;
            }
            if ($this->isUnsafeTransitionAddress($ip)) {
                $blockedReasons[] = 'unsafe_address';

                continue;
            }
            if (! $allowPrivate && ! $this->isPublicAddress($ip)) {
                $blockedReasons[] = 'unsafe_address';

                continue;
            }
        }

        if ($blockedReasons !== []) {
            $this->blockOrObserve($blockedReasons[0], $host, $port, [
                'rule' => 'non_public_address',
                'reasons' => array_values(array_unique($blockedReasons)),
                'resolved_ip' => $addresses[0],
            ]);
        }

        return new ResolvedOutboundTarget($normalizedUrl, $scheme, $host, $port, $addresses, $addresses[0]);
    }

    /** @return list<string> */
    private function addresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        if ($host === 'localhost') {
            return ['127.0.0.1', '::1'];
        }

        return $this->resolver->resolve($host);
    }

    /** @param array<string, list<string>> $reserved @param list<string> $addresses */
    private function assertNotReserved(array $reserved, array $addresses): void
    {
        foreach ($reserved as $host => $pins) {
            try {
                $resolved = $this->addresses($host);
            } catch (\Throwable) {
                $resolved = [];
            }
            foreach ($addresses as $ip) {
                if (MagpieTargets::isLoopback($ip) || $this->isIpv4MappedAddress($ip) || $this->isUnsafeTransitionAddress($ip)
                    || $this->containsAddress([...$resolved, ...$pins], $ip)
                    || ($resolved === [] && $pins === [] && ! $this->isPublicAddress($ip))) {
                    throw new OutboundRequestBlockedException('magpie_target_reserved');
                }
            }
        }
    }

    /** @param list<string> $addresses */
    private function assertMagpieAddresses(MagpieRequestScope $scope, array $addresses): void
    {
        $target = $scope->target;
        if ($scope->pinnedIp !== null && ! $this->containsAddress($addresses, $scope->pinnedIp)) {
            throw new OutboundRequestBlockedException('magpie_address_changed');
        }
        foreach ($addresses as $ip) {
            $loopback = MagpieTargets::isLoopback($ip);
            $local = $loopback;
            foreach (self::MAGPIE_LOCAL_CIDRS as $cidr) {
                $local = $local || $this->addressMatchesCidr($ip, $cidr);
            }

            if (! $local || ($target->authMode === 'loopback' && ! $loopback)
                || ($target->addresses !== [] && ! $this->containsAddress($target->addresses, $ip))) {
                throw new OutboundRequestBlockedException('magpie_address_not_authorized');
            }
        }
    }

    /** @param list<string> $addresses */
    private function containsAddress(array $addresses, string $ip): bool
    {
        $binary = @inet_pton($ip);
        if ($binary === false) {
            return false;
        }
        foreach ($addresses as $candidate) {
            if (is_string($candidate) && @inet_pton($candidate) === $binary) {
                return true;
            }
        }

        return false;
    }

    /**
     * 地址类判定的唯一出口：强制模式抛异常，观察模式只记日志放行。
     *
     * ⚠️ 只有「地址类」判定走这里（内网 / loopback / 云元数据 / DNS 重绑定 /
     *    localhost / 歧义 IP / 无点主机名）。URL 结构非法与响应体超限**不走这里**，
     *    因为那两类不是误杀，放行反而更糟（详见 config/geo.php 注释）。
     *
     * @param  array<string, mixed>  $context
     */
    private function blockOrObserve(string $reason, string $host, int $port, array $context = []): void
    {
        if ($this->enforced()) {
            throw new OutboundRequestBlockedException($reason);
        }

        // ⚠️ 只记 host:port，不记完整 URL —— query 里可能带 API key / token，
        //    完整 URL 进日志就是密钥泄漏（通用红线 6、7）。
        Log::warning('outbound.observation_mode: request allowed but would be blocked when enforcing', [
            'reason' => $reason,
            'host' => $host,
            'port' => $port,
            ...$context,
        ]);
    }

    private function enforced(): bool
    {
        return $this->enforce ?? (bool) config('geo.outbound_enforce', true);
    }

    /** @return array{string, string, string, int} */
    private function normalizeUrl(string $url, bool $magpie = false): array
    {
        if ($url === '' || $url !== trim($url)) {
            throw new OutboundRequestBlockedException('invalid_url');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            throw new OutboundRequestBlockedException('control_character');
        }
        if (! preg_match('~^([A-Za-z][A-Za-z0-9+.-]*)://([^/?#]*)~', $url, $authorityMatch)) {
            throw new OutboundRequestBlockedException('invalid_scheme');
        }
        $authority = (string) $authorityMatch[2];
        if (str_contains($authority, '%') || str_contains($authority, '\\')) {
            throw new OutboundRequestBlockedException('ambiguous_authority');
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            throw new OutboundRequestBlockedException('invalid_url');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new OutboundRequestBlockedException('invalid_scheme');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new OutboundRequestBlockedException('userinfo_forbidden');
        }
        if (array_key_exists('fragment', $parts)) {
            throw new OutboundRequestBlockedException('fragment_forbidden');
        }

        $rawHost = strtolower((string) ($parts['host'] ?? ''));
        if (str_starts_with($rawHost, '[') && str_ends_with($rawHost, ']')) {
            $rawHost = substr($rawHost, 1, -1);
        }
        $host = rtrim($rawHost, '.');
        if ($host === '' || ! mb_check_encoding($host, 'ASCII')) {
            throw new OutboundRequestBlockedException('invalid_host');
        }

        // 端口先解出来，地址类判定要带上它（白名单是 host:port 精确匹配）。
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($port < 1 || $port > 65535) {
            throw new OutboundRequestBlockedException('invalid_port');
        }

        if (! $magpie && ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local'))
            && ! $this->privateTargetAllowed($host, $port)) {
            $this->blockOrObserve('unsafe_address', $host, $port, ['rule' => 'localhost_suffix']);
        }
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            if ($this->looksLikeAmbiguousIp($host)) {
                // 0x7f000001 / 2130706433 这类混淆写法，本质是地址伪装。
                if ($magpie) {
                    throw new OutboundRequestBlockedException('ambiguous_ip');
                }
                $this->blockOrObserve('ambiguous_ip', $host, $port, ['rule' => 'ambiguous_ip_literal']);
            }
            if (preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host) !== 1) {
                throw new OutboundRequestBlockedException('invalid_host');
            }
        }

        $hostForUrl = str_contains($host, ':') ? '['.$host.']' : $host;
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $normalized = $scheme.'://'.$hostForUrl.($port !== $defaultPort ? ':'.$port : '');

        // ⚠️ path + query 必须从**原始字符串**里切，不能用 parse_url() 的结果重组。
        //
        // PHP 8.5.7 实测：parse_url() 会把 path 里 0x80–0x9F 的字节替换成 '_'。
        // 那一段正好是 UTF-8 汉字的续字节 ——「文章」(e6 96 87 e7 ab a0) 出来变成
        // (e6 5f 5f e7 ab a0)，URL 被**静默**改写。拿它去抓就是 404 或抓错页面，
        // 而且不报错，比直接失败更难查。中文站点带中文路径极常见（知乎专栏、
        // 维基、政府网站），这条路径专门用来抓用户粘贴的网页地址，必踩。
        //
        // authority 之前的部分已由上面的正则与 parse_url 校验过（scheme/host/port/
        // userinfo 都是 ASCII，不受这个行为影响），所以只有 path?query 需要保原样。
        $rest = substr($url, strlen((string) $authorityMatch[0]));
        $fragmentPos = strpos($rest, '#');
        if ($fragmentPos !== false) {
            // fragment 本身是否允许，已在上面按 parse_url 的结果判过。
            // 这里只保证它不会被带进真实请求（fragment 从不发给服务器）。
            $rest = substr($rest, 0, $fragmentPos);
        }
        $normalized .= $rest;

        return [$normalized, $scheme, $host, $port];
    }

    private function looksLikeAmbiguousIp(string $host): bool
    {
        return preg_match('/\A(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+))*\z/i', $host) === 1;
    }

    private function privateTargetAllowed(string $host, int $port): bool
    {
        $configured = config('geo.outbound_private_targets', []);
        $items = is_array($configured) ? $configured : explode(',', (string) $configured);
        $expected = strtolower($host).':'.$port;

        foreach ($items as $item) {
            $candidate = strtolower(trim((string) $item));
            if ($candidate !== '' && ! str_contains($candidate, '*') && ! str_contains($candidate, '/') && $candidate === $expected) {
                return true;
            }
        }

        return false;
    }

    private function isPublicAddress(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        $cidrs = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            ? self::BLOCKED_IPV4_CIDRS
            : self::BLOCKED_IPV6_CIDRS;

        foreach ($cidrs as $cidr) {
            if ($this->addressMatchesCidr($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private function isIpv4MappedAddress(string $ip): bool
    {
        $binary = @inet_pton($ip);

        return is_string($binary)
            && strlen($binary) === 16
            && substr($binary, 0, 10) === str_repeat("\0", 10)
            && substr($binary, 10, 2) === "\xff\xff";
    }

    private function isUnsafeTransitionAddress(string $ip): bool
    {
        foreach (self::UNSAFE_TRANSITION_IPV6_CIDRS as $cidr) {
            if ($this->addressMatchesCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private function addressMatchesCidr(string $ip, string $cidr): bool
    {
        [$network, $prefixText] = explode('/', $cidr, 2);
        $addressBytes = @inet_pton($ip);
        $networkBytes = @inet_pton($network);
        if (! is_string($addressBytes) || ! is_string($networkBytes) || strlen($addressBytes) !== strlen($networkBytes)) {
            return false;
        }

        $prefix = (int) $prefixText;
        $byteCount = intdiv($prefix, 8);
        if (substr($addressBytes, 0, $byteCount) !== substr($networkBytes, 0, $byteCount)) {
            return false;
        }

        $remainingBits = $prefix % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($addressBytes[$byteCount]) & $mask) === (ord($networkBytes[$byteCount]) & $mask);
    }

    private function isRedirect(Response $response): bool
    {
        return in_array($response->status(), [301, 302, 303, 307, 308], true);
    }

    private function assertResponseSize(Response $response, int $maxBytes): void
    {
        $declared = $response->header('Content-Length');
        if ((is_numeric($declared) && (int) $declared > $maxBytes) || strlen($response->body()) > $maxBytes) {
            throw new OutboundRequestBlockedException('response_too_large');
        }
    }

    private function sameOrigin(ResolvedOutboundTarget $first, ResolvedOutboundTarget $second): bool
    {
        return $first->scheme === $second->scheme && $first->host === $second->host && $first->port === $second->port;
    }

    private function clearRequestEntity(PendingRequest $request): PendingRequest
    {
        $headers = $request->getOptions()['headers'] ?? [];
        $cleared = [
            'Content-Length' => '',
            'Expect' => '',
            'Transfer-Encoding' => '',
        ];
        foreach (is_array($headers) ? $headers : [] as $name => $value) {
            if (str_starts_with(strtolower((string) $name), 'content-')) {
                $cleared[(string) $name] = '';
            }
        }

        return $request
            ->withBody('', '')
            ->withOptions([
                'form_params' => null,
                'json' => null,
                'multipart' => null,
            ])
            ->replaceHeaders($cleared);
    }

    private function redirectUrl(string $baseUrl, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $base = parse_url($baseUrl);
        if (! is_array($base)) {
            throw new OutboundRequestBlockedException('invalid_redirect');
        }
        $origin = (string) $base['scheme'].'://'.(string) $base['host'];
        if (isset($base['port'])) {
            $origin .= ':'.(int) $base['port'];
        }
        if (str_starts_with($location, '//')) {
            return (string) $base['scheme'].':'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $path = (string) ($base['path'] ?? '/');
        $directory = str_ends_with($path, '/') ? $path : dirname($path).'/';

        return $origin.$directory.$location;
    }
}
