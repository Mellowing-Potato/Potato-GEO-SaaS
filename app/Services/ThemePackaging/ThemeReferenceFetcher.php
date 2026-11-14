<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted theme generation, packaging and storage for independent workspaces.
 * Upstream: app/Services/Admin/SiteThemeReplication/ThemeReferenceFetcher.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Services\ThemePackaging;

use App\Models\SiteThemeReplication;
use App\Services\Outbound\OutboundRequestBlockedException;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Services\Outbound\SecureHttpFactory;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ThemeReferenceFetcher
{
    private const MAX_HTML_BYTES = 2_097_152;

    private const MAX_CSS_BYTES = 262_144;

    private const MAX_CSS_FILES_PER_PAGE = 3;

    /**
     * 这些 reasonCode 是「我们自己装配错了」，不是客户填的 URL 有问题。
     * 分开的原因见 lang 里 error.outbound_misconfigured 的注释。
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

    /**
     * ⚠️ 必须是 SecureHttpFactory，不能是 Illuminate\Http\Client\Factory。
     *   LaravelPinnedOutboundTransport:21 第一件事就是 `instanceof SecurePendingRequest`，
     *   原厂 Factory 造出来的是普通 PendingRequest → 每一次抓取都被自己的出站安全链
     *   以 secure_pending_request_required 拦死（单 8 线索通知同款事故）。
     *   OutboundServiceProvider 只绑了 SecureHttpFactory::class，没绑原厂 Factory 接口。
     */
    public function __construct(
        private readonly SafeOutboundHttpClient $safeHttp,
        private readonly SecureHttpFactory $http,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function fetch(SiteThemeReplication $replication): array
    {
        $pages = [];
        foreach ([
            'home' => (string) $replication->home_url,
            'category' => (string) $replication->category_url,
            'article' => (string) $replication->article_url,
        ] as $type => $url) {
            $pages[$type] = $this->fetchPage($type, $url);
        }

        return [
            'pages' => $pages,
            'fetched_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchPage(string $type, string $url): array
    {
        $request = $this->http->timeout(20)
            ->connectTimeout(8)
            ->withHeaders([
                'User-Agent' => 'GEOFlow Theme Replication/2.0',
                'Accept' => 'text/html,application/xhtml+xml,text/plain;q=0.8,*/*;q=0.5',
            ]);
        try {
            $response = $this->safeHttp->get($request, $url, self::MAX_HTML_BYTES, 3);
        } catch (OutboundRequestBlockedException $exception) {
            throw $this->describeBlock($exception, $url);
        }

        if (! $response->successful()) {
            throw new RuntimeException(__('theme_package.error.fetch_failed', [
                'status' => $response->status(),
            ]));
        }

        if ((int) $response->header('Content-Length', 0) > self::MAX_HTML_BYTES) {
            throw new RuntimeException(__('theme_package.error.html_too_large'));
        }

        $html = (string) $response->body();
        if (strlen($html) > self::MAX_HTML_BYTES) {
            throw new RuntimeException(__('theme_package.error.html_too_large'));
        }

        $cssLinks = $this->extractStylesheetUrls($html, $url);
        $css = [];
        foreach (array_slice($cssLinks, 0, self::MAX_CSS_FILES_PER_PAGE) as $cssUrl) {
            $cssSample = $this->fetchCssSample($cssUrl);
            if ($cssSample !== null) {
                $css[] = $cssSample;
            }
        }

        return [
            'type' => $type,
            'url' => $url,
            'title' => $this->extractTitle($html),
            'description' => $this->extractMetaDescription($html),
            'html_size' => strlen($html),
            'text_sample' => $this->extractTextSample($html),
            'headings' => $this->extractHeadings($html),
            'components' => $this->extractComponentHints($html),
            'css' => $css,
        ];
    }

    /**
     * 把出站安全链的 reasonCode 翻译成对客文案。
     * 三类分开：体积超限 / 内部装配问题 / 客户填的地址不可达。
     */
    private function describeBlock(OutboundRequestBlockedException $exception, string $url): RuntimeException
    {
        if ($exception->reasonCode === 'response_too_large') {
            return new RuntimeException(__('theme_package.error.html_too_large'), 0, $exception);
        }

        if (in_array($exception->reasonCode, self::INTERNAL_REASON_CODES, true)) {
            Log::error('ThemeReferenceFetcher: outbound chain misconfigured', [
                'reason_code' => $exception->reasonCode,
                'url' => $url,
            ]);

            return new RuntimeException(__('theme_package.error.outbound_misconfigured'), 0, $exception);
        }

        return new RuntimeException(__('theme_package.validation.url_private'), 0, $exception);
    }

    /**
     * @return array<int, string>
     */
    private function extractStylesheetUrls(string $html, string $baseUrl): array
    {
        preg_match_all('/<link\b[^>]*>/i', $html, $matches);

        $urls = [];
        foreach ($matches[0] ?? [] as $tag) {
            if (! preg_match('/\brel=["\']?[^"\'>]*stylesheet/i', $tag)) {
                continue;
            }

            if (! preg_match('/\bhref=["\']([^"\']+)["\']/i', $tag, $hrefMatch)) {
                continue;
            }

            $url = $this->resolveUrl($hrefMatch[1], $baseUrl);
            if ($url === null) {
                continue;
            }

            $urls[] = $url;
        }

        return array_values(array_unique($urls));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchCssSample(string $url): ?array
    {
        try {
            $request = $this->http->timeout(10)
                ->connectTimeout(5)
                ->withHeaders([
                    'User-Agent' => 'GEOFlow Theme Replication/2.0',
                    'Accept' => 'text/css,*/*;q=0.5',
                ]);
            $response = $this->safeHttp->get($request, $url, self::MAX_CSS_BYTES, 3);
        } catch (OutboundRequestBlockedException $exception) {
            // CSS 取样失败不阻断主流程（少一份样本而已），但内部装配问题必须留痕，
            // 否则「配色全是默认值」这种症状会查不出根因。
            if (in_array($exception->reasonCode, self::INTERNAL_REASON_CODES, true)) {
                Log::error('ThemeReferenceFetcher: outbound chain misconfigured while sampling CSS', [
                    'reason_code' => $exception->reasonCode,
                    'url' => $url,
                ]);
            }

            return null;
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        if ((int) $response->header('Content-Length', 0) > self::MAX_CSS_BYTES) {
            return null;
        }

        $body = (string) $response->body();
        if ($body === '') {
            return null;
        }

        if (strlen($body) > self::MAX_CSS_BYTES) {
            return null;
        }

        return [
            'url' => $url,
            'size' => strlen($body),
            'sample' => $body,
        ];
    }

    private function extractTitle(string $html): string
    {
        if (preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $match)) {
            return $this->cleanText($match[1], 160);
        }

        return '';
    }

    private function extractMetaDescription(string $html): string
    {
        if (preg_match('/<meta\b[^>]*name=["\']description["\'][^>]*content=["\']([^"\']*)["\'][^>]*>/i', $html, $match)) {
            return $this->cleanText($match[1], 240);
        }

        if (preg_match('/<meta\b[^>]*content=["\']([^"\']*)["\'][^>]*name=["\']description["\'][^>]*>/i', $html, $match)) {
            return $this->cleanText($match[1], 240);
        }

        return '';
    }

    private function extractTextSample(string $html): string
    {
        $clean = preg_replace('/<(script|style|iframe|object|embed|form)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;

        return $this->cleanText(strip_tags($clean), 1800);
    }

    /**
     * @return list<array{level:int,text:string}>
     */
    private function extractHeadings(string $html): array
    {
        preg_match_all('/<h([1-3])\b[^>]*>(.*?)<\/h\1>/is', $html, $matches, PREG_SET_ORDER);

        $headings = [];
        foreach (array_slice($matches, 0, 12) as $match) {
            $text = $this->cleanText(strip_tags($match[2]), 120);
            if ($text !== '') {
                $headings[] = [
                    'level' => (int) $match[1],
                    'text' => $text,
                ];
            }
        }

        return $headings;
    }

    /**
     * @return array<string, int>
     */
    private function extractComponentHints(string $html): array
    {
        $hints = [];
        foreach (['header', 'nav', 'main', 'article', 'aside', 'section', 'footer', 'button', 'form'] as $tag) {
            preg_match_all('/<'.$tag.'\b/i', $html, $matches);
            $hints[$tag] = count($matches[0] ?? []);
        }

        preg_match_all('/class=["\'][^"\']*(card|grid|list|hero|banner|menu|sidebar|breadcrumb)[^"\']*["\']/i', $html, $matches);
        $hints['semantic_class_hits'] = count($matches[0] ?? []);

        return $hints;
    }

    private function cleanText(string $value, int $limit): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        return mb_substr($value, 0, $limit);
    }

    private function resolveUrl(string $url, string $baseUrl): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || str_starts_with($url, 'data:') || str_starts_with($url, 'javascript:')) {
            return null;
        }

        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }

        $base = parse_url($baseUrl);
        $scheme = isset($base['scheme']) ? strtolower((string) $base['scheme']) : 'https';
        $host = (string) ($base['host'] ?? '');
        if ($host === '') {
            return null;
        }

        if (str_starts_with($url, '//')) {
            return $scheme.':'.$url;
        }

        if (str_starts_with($url, '/')) {
            return $scheme.'://'.$host.$url;
        }

        $path = (string) ($base['path'] ?? '/');
        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');

        return $scheme.'://'.$host.($directory !== '' ? $directory.'/' : '/').$url;
    }
}
