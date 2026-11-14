<?php

namespace Tests\Feature;

use App\Contracts\Outbound\HostResolver;
use App\Contracts\Outbound\OutboundTransport;
use App\Services\Outbound\OutboundRequestBlockedException;
use App\Services\Outbound\ResolvedOutboundTarget;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Services\Outbound\SecureHttpFactory;
use App\Services\Outbound\SecurePendingRequest;
use App\Services\SkillAgent\PageFactExtractor;
use App\Services\SkillAgent\PageFetchService;
use App\Services\SkillAgent\PageObservation;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Mockery;
use ReflectionClass;
use Tests\TestCase;

/**
 * 页面审计的抓取层：它必须**真的把页面抓下来**，抓不到就说清楚为什么。
 *
 * 背景（2026-09-06 生产 execution 52）：这个技能此前从不发请求。客户填了
 * http://example.test —— 一个 HTTP 200、24KB、带 canonical 和 JSON-LD 的活站点 ——
 * 报告却通篇结论「目标站点没有可用页面，官网事实源缺失」，还扣了 ¥0.13。
 * 模型没有幻觉，它明确写了「本次未取得可解析 HTML」；错的是我们没给它页面。
 */
class PageAuditFetchTest extends TestCase
{
    use RefreshDatabase;

    /** 伪造传输层收到的请求，按顺序 */
    private array $sent = [];

    /**
     * 抓一个正常页面：观察清单里必须出现能核验的事实。
     * 这些字段就是模型写「观察到的」结论时唯一的依据。
     */
    public function test_it_extracts_verifiable_facts_from_a_real_looking_page(): void
    {
        $observation = $this->fetch('https://example.com/guide', [
            'https://example.com/guide' => $this->html(),
            'https://example.com/robots.txt' => "User-agent: *\nDisallow: /admin\n",
            'https://example.com/sitemap.xml' => '<urlset><url><loc>https://example.com/</loc></url></urlset>',
        ]);

        $this->assertTrue($observation->ok, (string) $observation->failureMessage);
        $this->assertSame(200, $observation->statusCode);

        $facts = $observation->facts;
        $this->assertSame('孟德智达 — AI 搜索优化', $facts['head']['title']);
        $this->assertSame('https://example.com/guide', $facts['head']['canonical']);
        $this->assertSame('一句话描述', $facts['head']['meta_description']);
        $this->assertSame('zh-CN', $facts['lang']);

        // H 层级：唯一 H1 + 跳级要被点出来（H1 之后直接 H3）
        $this->assertSame(1, $facts['heading_outline']['h1_count']);
        $this->assertCount(1, $facts['heading_outline']['level_skips']);

        $this->assertTrue($facts['landmarks']['has_main']);
        $this->assertFalse($facts['landmarks']['has_article']);

        // JSON-LD 的 @type 藏在 @graph 里，必须递归找出来
        $this->assertEqualsCanonicalizing(
            ['Organization', 'FAQPage'],
            $facts['structured_data']['json_ld_types'],
        );

        $this->assertSame(2, $facts['links']['internal_count']);
        $this->assertSame(1, $facts['links']['external_count']);

        $this->assertTrue($facts['robots_txt']['exists']);
        $this->assertFalse($facts['robots_txt']['disallows_target']);
        $this->assertTrue($facts['sitemap']['exists']);
        $this->assertSame(1, $facts['sitemap']['url_count']);

        // 首屏正文字数：判断「是不是纯 JS 渲染」的唯一依据，脚本内容不能算进去
        $this->assertGreaterThan(20, $facts['prose']['first_paint_char_count']);
        $this->assertStringNotContainsString('console.log', $facts['prose']['first_paint_sample']);
    }

    /**
     * execution 52 的原样输入：客户填 http、站点 301 到 https。
     * 最终地址必须跟到，否则 robots/sitemap 会去错的源站上找。
     */
    public function test_it_follows_redirects_and_reports_the_final_url(): void
    {
        $observation = $this->fetch('http://example.test', [
            'http://example.test' => ['status' => 301, 'headers' => ['Location' => 'https://example.test/']],
            'https://example.test/' => $this->html(),
            'https://example.test/robots.txt' => "User-agent: *\nAllow: /\n",
            'https://example.test/sitemap.xml' => ['status' => 404, 'body' => ''],
        ]);

        $this->assertTrue($observation->ok, (string) $observation->failureMessage);
        $this->assertSame('https://example.test/', $observation->finalUrl);
        $this->assertTrue($observation->facts['response']['redirected']);
        // sitemap 缺失不算失败 —— 大量站点就是没有
        $this->assertFalse($observation->facts['sitemap']['exists']);
    }

    /** 404 要说清楚是「页面不存在」，别只说失败 */
    public function test_a_404_fails_with_an_actionable_message(): void
    {
        $observation = $this->fetch('https://example.com/gone', [
            'https://example.com/gone' => ['status' => 404, 'body' => 'not found'],
        ]);

        $this->assertFalse($observation->ok);
        $this->assertSame('http_error', $observation->failureCode);
        $this->assertStringContainsString('404', (string) $observation->failureMessage);
        $this->assertStringContainsString('地址是否正确', (string) $observation->failureMessage);
    }

    /** robots 禁止就是禁止，我们不绕过 */
    public function test_it_honours_robots_disallow_instead_of_bypassing_it(): void
    {
        $observation = $this->fetch('https://example.com/private/page', [
            'https://example.com/private/page' => $this->html(),
            'https://example.com/robots.txt' => "User-agent: *\nDisallow: /private\n",
        ]);

        $this->assertFalse($observation->ok);
        $this->assertSame('robots_disallowed', $observation->failureCode);
        $this->assertStringContainsString('不会绕过', (string) $observation->failureMessage);
    }

    /**
     * robots 的最长匹配优先：Disallow: /private 但 Allow: /private/public 更长 → 放行。
     * 写错这条的后果是把客户允许抓的页面拒掉，客户完全不知道该怎么办。
     */
    public function test_a_longer_allow_rule_wins_over_a_shorter_disallow(): void
    {
        $observation = $this->fetch('https://example.com/private/public', [
            'https://example.com/private/public' => $this->html(),
            'https://example.com/robots.txt' => "User-agent: *\nDisallow: /private\nAllow: /private/public\n",
        ]);

        $this->assertTrue($observation->ok, (string) $observation->failureMessage);
    }

    /** 针对别家爬虫写的规则不是给我们的，不该拿来拦自己 */
    public function test_rules_written_for_other_crawlers_do_not_apply_to_us(): void
    {
        $observation = $this->fetch('https://example.com/page', [
            'https://example.com/page' => $this->html(),
            'https://example.com/robots.txt' => "User-agent: Googlebot\nDisallow: /page\n",
        ]);

        $this->assertTrue($observation->ok, (string) $observation->failureMessage);
    }

    /** PDF / 图片 / 接口地址不是网页，早点说清楚比抓回来一堆二进制强 */
    public function test_a_non_html_response_is_rejected_with_its_content_type(): void
    {
        $observation = $this->fetch('https://example.com/report.pdf', [
            'https://example.com/report.pdf' => [
                'status' => 200,
                'headers' => ['Content-Type' => 'application/pdf'],
                'body' => '%PDF-1.7',
            ],
        ]);

        $this->assertFalse($observation->ok);
        $this->assertSame('not_html', $observation->failureCode);
        $this->assertStringContainsString('application/pdf', (string) $observation->failureMessage);
    }

    /**
     * 内网地址必须拒。URL 是客户随便填的，这是 SSRF 的入口。
     *
     * 拦截本身在 SafeOutboundHttpClient（按 CIDR 判、且每跳跳转都重新解析重新校验），
     * 这里验的是「页面审计确实走了那条链路，而且把拒绝翻译成了客户看得懂的话」。
     */
    public function test_a_private_address_is_refused(): void
    {
        $observation = $this->fetch('http://192.168.1.1/admin', []);

        $this->assertFalse($observation->ok);
        $this->assertSame('private_address', $observation->failureCode);
        $this->assertStringContainsString('内网', (string) $observation->failureMessage);
        $this->assertSame([], $this->sent, '内网地址不该真的发出请求');
    }

    /** 客户经常只填域名，补协议比让他回去改一遍强 */
    public function test_a_bare_domain_gets_a_scheme_instead_of_being_rejected(): void
    {
        $observation = $this->fetch('example.com', [
            'https://example.com' => $this->html(),
        ]);

        $this->assertTrue($observation->ok, (string) $observation->failureMessage);
    }

    /**
     * ⚠️ 必须依赖 SecureHttpFactory，不能是原厂 Factory。
     * LaravelPinnedOutboundTransport 第一件事就是 instanceof SecurePendingRequest，
     * 用错工厂 → 生产上每一次抓取都被自己的安全链拦死，而单测因为伪造了传输层看不出来。
     * 单 8、单 10 已经各踩过一次，这条断言是专门用来挡第三次的。
     */
    public function test_it_must_depend_on_the_secure_http_factory(): void
    {
        $parameters = (new ReflectionClass(PageFetchService::class))
            ->getConstructor()?->getParameters() ?? [];

        $types = array_map(
            static fn (\ReflectionParameter $p): ?string => $p->getType()?->getName(),
            $parameters,
        );

        $this->assertContains(
            SecureHttpFactory::class,
            $types,
            '抓取用的不是 SecureHttpFactory —— 生产上 100% 抓不到任何页面',
        );
    }

    // ── 装配 ─────────────────────────────────────────────────

    /**
     * @param  array<string, array<string, mixed>|string>  $routes  URL => 响应（字符串＝ 200 HTML）
     */
    private function fetch(string $url, array $routes): PageObservation
    {
        // 沙箱无外网。把 DNS 桩成公网 IP，只让 URL 结构与 CIDR 校验照常跑；
        // IP 字面量（192.168 等）不经过 resolver，仍走真实的内网拦截逻辑。
        $resolver = Mockery::mock(HostResolver::class);
        $resolver->shouldReceive('resolve')->andReturn(['93.184.216.34']);
        app()->instance(HostResolver::class, $resolver);

        app()->instance(OutboundTransport::class, $this->fakeTransport($routes));
        // SafeOutboundHttpClient 是 singleton，会缓存建好时的 resolver 和 transport，
        // 不清掉上面两个替身都不生效。
        app()->forgetInstance(SafeOutboundHttpClient::class);
        app()->forgetInstance(SecureHttpFactory::class);

        // 不用 `new X(...)->fetch()`：那是 PHP 8.4 才有的写法，composer.json 声明的下限是 ^8.3。
        $service = new PageFetchService(
            app(SafeOutboundHttpClient::class),
            app(SecureHttpFactory::class),
            new PageFactExtractor,
        );

        return $service->fetch($url);
    }

    /** @param array<string, array<string, mixed>|string> $routes */
    private function fakeTransport(array $routes): OutboundTransport
    {
        $sent = &$this->sent;

        return new class($routes, $sent) implements OutboundTransport
        {
            public function __construct(private array $routes, private array &$sent) {}

            public function send(
                PendingRequest $request,
                string $method,
                ResolvedOutboundTarget $target,
                array $data,
                int $maxBytes,
                bool $crossOrigin = false,
            ): Response {
                // 伪造传输层也要守住真契约：生产的 transport 第一件事就是这个判断，
                // 这里放水的话「用错工厂」这类事故单测就永远发现不了。
                if (! $request instanceof SecurePendingRequest) {
                    throw new OutboundRequestBlockedException(
                        'secure_pending_request_required'
                    );
                }

                $this->sent[] = $target->url;
                $route = $this->routes[$target->url] ?? ['status' => 404, 'body' => ''];
                if (is_string($route)) {
                    $route = ['status' => 200, 'body' => $route];
                }

                return new Response(new PsrResponse(
                    $route['status'] ?? 200,
                    ($route['headers'] ?? []) + ['Content-Type' => 'text/html; charset=utf-8'],
                    $route['body'] ?? '',
                ));
            }
        };
    }

    private function html(): string
    {
        return <<<'HTML'
        <!DOCTYPE html>
        <html lang="zh-CN">
        <head>
          <title>孟德智达 — AI 搜索优化</title>
          <meta name="Description" content="一句话描述">
          <link rel="Canonical" href="https://example.com/guide">
          <meta property="og:title" content="孟德智达">
          <script type="application/ld+json">
          {"@context":"https://schema.org","@graph":[
            {"@type":"Organization","name":"孟德智达"},
            {"@type":"FAQPage","mainEntity":[]}
          ]}
          </script>
          <script>console.log('这段脚本不算正文');</script>
        </head>
        <body>
          <main>
            <h1>客户问 AI 的时候，让它答出你的名字。</h1>
            <h3>这里从 H1 直接跳到了 H3</h3>
            <p>这是一段真正的正文，用来验证首屏正文字数统计能把脚本排除掉。</p>
            <a href="/about">关于我们</a>
            <a href="https://example.com/pricing">价格</a>
            <a href="https://other.example.org/x">站外链接</a>
            <a href="#top">锚点不算</a>
          </main>
        </body>
        </html>
        HTML;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
