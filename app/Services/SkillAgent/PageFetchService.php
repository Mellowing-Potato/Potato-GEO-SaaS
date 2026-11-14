<?php

namespace App\Services\SkillAgent;

use App\Services\Outbound\OutboundRequestBlockedException;
use App\Services\Outbound\OutboundRequestFailedException;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Services\Outbound\SecureHttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 页面审计的抓取层：把客户填的 URL 真的抓下来，交出一份可核验的观察清单。
 *
 * ⚠️ 必须注入 SecureHttpFactory，不能用 Http facade 或原厂 Factory。
 *    LaravelPinnedOutboundTransport 第一件事就是 `instanceof SecurePendingRequest`，
 *    普通 PendingRequest 会被我们自己的出站安全链以 secure_pending_request_required
 *    拦死。单 8（线索通知）和单 10（主题包）已经各踩过一次，这是第三次记这条。
 *
 * SSRF 防护不在这个类里，在 SafeOutboundHttpClient：它按 CIDR 拒内网/回环/云元数据、
 * 拒非 http(s) 协议、拒 userinfo、拒歧义 IP 写法，而且**每一跳跳转都重新解析重新校验**
 * （send() 的 while 循环里每轮都调 resolveTarget）。这里不重复实现，只负责用对它。
 */
class PageFetchService
{
    /** 单页 HTML 上限。超过这个体量的页面，多出来的部分对 GEO 审计没有增量信息 */
    private const MAX_HTML_BYTES = 2_097_152;

    private const MAX_ROBOTS_BYTES = 262_144;

    private const MAX_SITEMAP_BYTES = 1_048_576;

    /**
     * ⚠️ 上限是 3，不是执行书里写的 5。
     *    SafeOutboundHttpClient::send() 开头就断言 `$maxRedirects > 3` → invalid_request_policy，
     *    填 5 会在**每一次抓取**上直接抛「装配错误」。这是全局出站策略定的，不该为页面审计放宽。
     */
    private const MAX_REDIRECTS = 3;

    private const TIMEOUT_SECONDS = 15;

    private const CONNECT_TIMEOUT_SECONDS = 8;


    /**
     * 这些 reasonCode 是「我们自己装配错了」，不是客户填的 URL 有问题。
     * 必须和客户侧原因分开报，否则客户会去改一个本来没错的地址。
     *
     * @var list<string>
     */
    private const INTERNAL_REASON_CODES = [
        'secure_pending_request_required',
        'generic_http_middleware_forbidden',
        'fixed_context_unauthorized',
        'invalid_request_policy',
        'pinning_unavailable',
    ];

    public function __construct(
        private readonly SafeOutboundHttpClient $safeHttp,
        private readonly SecureHttpFactory $http,
        private readonly PageFactExtractor $extractor,
    ) {}

    /**
     * 抓一个页面。**永远不抛异常** —— 抓不到就返回一个 ok=false 的观察，
     * 由调用方决定怎么处理。抓取失败是业务上的正常分支，不是程序错误。
     */
    public function fetch(string $url): PageObservation
    {
        $url = trim($url);
        if ($url === '') {
            return PageObservation::failure($url, 'invalid_url', __('page_audit.fetch.invalid_url'));
        }

        // 客户经常只填域名（example.com）。补上协议再抓，比让他自己回去改一遍强。
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.ltrim($url, '/');
        }

        // 跟完跳转之后的真实地址。
        //
        // ⚠️ 不能用 $response->effectiveUri()：它读的是 transferStats，而这条加固链路
        //    在 Guzzle 上设了 allow_redirects=false（跳转是 SafeOutboundHttpClient 自己
        //    在循环里走的），transferStats 不保证有值，拿到 null 就只能退回请求地址。
        //    改用它的 redirectValidator 回调 —— 每跳一次都会带着新地址调一次，
        //    最后一次调用的值就是最终地址。
        $finalUrl = $url;
        $captureRedirect = static function (string $redirectUrl) use (&$finalUrl): void {
            $finalUrl = $redirectUrl;
        };

        try {
            $response = $this->safeHttp->get(
                $this->request(self::TIMEOUT_SECONDS, 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5'),
                $url,
                self::MAX_HTML_BYTES,
                self::MAX_REDIRECTS,
                redirectValidator: $captureRedirect,
            );
        } catch (OutboundRequestBlockedException $exception) {
            return $this->describeBlock($url, $exception);
        } catch (OutboundRequestFailedException) {
            // 传输层没能拿到响应：DNS 解析不了、连不上、超时都归到这里。
            return PageObservation::failure($url, 'timeout', __('page_audit.fetch.timeout', [
                'seconds' => self::TIMEOUT_SECONDS,
            ]));
        } catch (Throwable $exception) {
            Log::error('PageFetchService: unexpected failure', [
                'host' => parse_url($url, PHP_URL_HOST),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return PageObservation::failure($url, 'unknown', __('page_audit.fetch.unknown'));
        }

        $status = $response->status();
        if ($status >= 400) {
            return PageObservation::failure($url, 'http_error', $this->describeStatus($status), $status);
        }

        $contentType = strtolower((string) $response->header('Content-Type'));
        if ($contentType !== '' && ! $this->looksLikeHtml($contentType)) {
            return PageObservation::failure($url, 'not_html', __('page_audit.fetch.not_html', [
                'type' => explode(';', $contentType)[0],
            ]), $status);
        }

        $html = (string) $response->body();
        if (trim($html) === '') {
            return PageObservation::failure($url, 'empty_body', __('page_audit.fetch.empty_body'), $status);
        }

        $robots = $this->robots($finalUrl);
        if (($robots['disallows_target'] ?? false) === true) {
            return PageObservation::failure($url, 'robots_disallowed', __('page_audit.fetch.robots_disallowed'), $status);
        }

        $facts = $this->extractor->extract($html, $finalUrl, [
            'response' => [
                'content_type' => $contentType !== '' ? $contentType : null,
                'x_robots_tag' => $response->header('X-Robots-Tag') ?: null,
                'redirected' => $finalUrl !== $url,
            ],
            'robots_txt' => $robots,
            'sitemap' => $this->sitemap($finalUrl),
            'fetched_at' => now()->toIso8601String(),
        ]);

        return PageObservation::success($url, $finalUrl, $status, $facts);
    }

    /**
     * robots.txt：抓不到不算失败（大量站点就是没有），但「有且禁止我们抓」必须挡住。
     *
     * @return array<string, mixed>
     */
    private function robots(string $pageUrl): array
    {
        $robotsUrl = $this->originOf($pageUrl).'/robots.txt';

        try {
            $response = $this->safeHttp->get(
                $this->request(10, 'text/plain,*/*;q=0.5'),
                $robotsUrl,
                self::MAX_ROBOTS_BYTES,
                self::MAX_REDIRECTS,
            );
        } catch (Throwable) {
            return ['exists' => false, 'reason' => 'unreachable', 'disallows_target' => false];
        }

        if (! $response->successful()) {
            return ['exists' => false, 'status' => $response->status(), 'disallows_target' => false];
        }

        $body = (string) $response->body();
        $path = (string) (parse_url($pageUrl, PHP_URL_PATH) ?: '/');
        $rules = $this->parseRobots($body);

        return [
            'exists' => true,
            'status' => $response->status(),
            'rules_for_all_agents' => $rules,
            'disallows_target' => $this->robotsDisallows($rules, $path),
        ];
    }

    /**
     * 只解析 `User-agent: *` 那一组。我们用的是通用 UA，不冒充别人，也不去匹配
     * 站点针对 Googlebot 之类写的专门规则 —— 那些规则不是给我们的。
     *
     * @return array{allow: list<string>, disallow: list<string>}
     */
    private function parseRobots(string $body): array
    {
        $allow = [];
        $disallow = [];
        $inWildcardGroup = false;
        // 连续的 User-agent 行属于同一组，中间插了规则行才算新组开始。
        $lastLineWasAgent = false;

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                if (! $lastLineWasAgent) {
                    $inWildcardGroup = false;
                }
                $inWildcardGroup = $inWildcardGroup || $value === '*';
                $lastLineWasAgent = true;

                continue;
            }

            $lastLineWasAgent = false;
            if (! $inWildcardGroup || $value === '') {
                continue;
            }
            if ($field === 'disallow') {
                $disallow[] = $value;
            } elseif ($field === 'allow') {
                $allow[] = $value;
            }
        }

        return ['allow' => $allow, 'disallow' => $disallow];
    }

    /**
     * 标准的最长匹配优先：Allow 和 Disallow 都命中时，规则长的那条说了算。
     *
     * @param  array{allow: list<string>, disallow: list<string>}  $rules
     */
    private function robotsDisallows(array $rules, string $path): bool
    {
        $longestAllow = $this->longestMatch($rules['allow'], $path);
        $longestDisallow = $this->longestMatch($rules['disallow'], $path);

        return $longestDisallow > $longestAllow;
    }

    /** @param list<string> $patterns */
    private function longestMatch(array $patterns, string $path): int
    {
        $best = -1;
        foreach ($patterns as $pattern) {
            // robots 的 * 和 $ 是通配语法，转成正则再比，别当字面量。
            $regex = '#^'.str_replace('\*', '.*', preg_quote($pattern, '#'));
            $regex = str_ends_with($pattern, '$')
                ? substr($regex, 0, -2).'$#'
                : $regex.'#';

            if (preg_match($regex, $path) === 1) {
                $best = max($best, strlen($pattern));
            }
        }

        return $best;
    }

    /**
     * sitemap.xml 只记「有没有、多少条」。抓不到不算失败。
     *
     * @return array<string, mixed>
     */
    private function sitemap(string $pageUrl): array
    {
        $sitemapUrl = $this->originOf($pageUrl).'/sitemap.xml';

        try {
            $response = $this->safeHttp->get(
                $this->request(10, 'application/xml,text/xml,*/*;q=0.5'),
                $sitemapUrl,
                self::MAX_SITEMAP_BYTES,
                self::MAX_REDIRECTS,
            );
        } catch (Throwable) {
            return ['exists' => false, 'reason' => 'unreachable'];
        }

        if (! $response->successful()) {
            return ['exists' => false, 'status' => $response->status()];
        }

        $body = (string) $response->body();

        return [
            'exists' => true,
            'status' => $response->status(),
            'is_sitemap_index' => str_contains($body, '<sitemapindex'),
            'url_count' => substr_count($body, '<loc>'),
        ];
    }

    private function request(int $timeout, string $accept): PendingRequest
    {
        return $this->http->timeout($timeout)
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->withHeaders([
                'User-Agent' => \App\Support\InstanceConfiguration::userAgent(),
                'Accept' => $accept,
                'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
                'Cache-Control' => 'no-cache',
            ]);
    }

    private function originOf(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';

        return $scheme.'://'.$host.$port;
    }

    private function looksLikeHtml(string $contentType): bool
    {
        return str_contains($contentType, 'text/html')
            || str_contains($contentType, 'application/xhtml');
    }

    private function describeStatus(int $status): string
    {
        return match (true) {
            in_array($status, [401, 403], true) => __('page_audit.fetch.unauthorized', ['status' => $status]),
            $status >= 500 => __('page_audit.fetch.server_error', ['status' => $status]),
            default => __('page_audit.fetch.not_found', ['status' => $status]),
        };
    }

    /** 把出站安全链的 reasonCode 翻译成对客文案，三类责任分开。 */
    private function describeBlock(string $url, OutboundRequestBlockedException $exception): PageObservation
    {
        $reason = $exception->reasonCode;

        if (in_array($reason, self::INTERNAL_REASON_CODES, true)) {
            Log::error('PageFetchService: outbound chain misconfigured', [
                'reason_code' => $reason,
                'host' => parse_url($url, PHP_URL_HOST),
            ]);

            return PageObservation::failure($url, $reason, __('page_audit.fetch.outbound_misconfigured'));
        }

        [$code, $message] = match ($reason) {
            'response_too_large' => ['too_large', __('page_audit.fetch.too_large', ['limit' => '2MB'])],
            'redirect_limit_exceeded', 'invalid_redirect' => ['redirect_loop', __('page_audit.fetch.redirect_loop', [
                'limit' => self::MAX_REDIRECTS,
            ])],
            'dns_resolution_failed' => ['dns_failed', __('page_audit.fetch.dns_failed')],
            'unsafe_address', 'ambiguous_ip', 'mapped_address' => ['private_address', __('page_audit.fetch.private_address')],
            default => ['invalid_url', __('page_audit.fetch.invalid_url')],
        };

        return PageObservation::failure($url, $code, $message);
    }
}
