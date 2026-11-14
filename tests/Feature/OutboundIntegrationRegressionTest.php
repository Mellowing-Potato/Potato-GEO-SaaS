<?php

namespace Tests\Feature;

use App\Contracts\Outbound\HostResolver;
use App\Contracts\Outbound\OutboundTransport;
use App\Exceptions\IngestionFailure;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AI\MockAiProvider;
use App\Services\Contracts\AiProvider;
use App\Services\Knowledge\DocumentIngestionService;
use App\Services\Outbound\OutboundRequestBlockedException;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Services\Outbound\SecureHttpFactory;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesSafeOutbound;
use Tests\TestCase;

/**
 * 单 2 验收回归：R1 / R2 / R3。
 *
 * 覆盖的是「两个既有出站爬虫接入安全链后，行为有没有退化」：
 *   R1 零误杀   —— 正常公网站点该抓的照样抓（含带端口、带 query、IPv6、多 A 记录）
 *   R2 仍拦得住 —— 内网 / 回环 / 云元数据 / CGNAT / DNS 重绑定照样拒
 *   R3 白名单   —— 确有需要时能定向放行内网目标，且放行是精确到 host:port 的
 *
 * ⚠️ R1 的完整验收还需要拿生产库历史成功导入的真实 URL 跑一遍干解析
 *    （见交付报告「R1 未完项」），本文件只覆盖可在 CI 内稳定复现的形态。
 */
class OutboundIntegrationRegressionTest extends TestCase
{
    use \Tests\Support\UsesWorkspaceModels;
    use FakesSafeOutbound;
    use RefreshDatabase;

    private const PAGE = '<!DOCTYPE html><html><head><title>公开文章</title></head><body><article>'
        . '这是一篇足够长的正文内容，用来越过入库时那道「正文少于 50 字就当抓取失败」的闸门，'
        . '所以这里必须多写一些字，确保提取出来的可读文本长度达标，不会被误判成空页面。'
        . '</article></body></html>';

    // ── R1：零误杀 ────────────────────────────────────────────────────────

    #[DataProvider('legitimatePublicUrls')]
    public function test_r1_legitimate_public_urls_are_not_blocked(string $url, array $ips): void
    {
        $this->fakeSafeOutbound(self::PAGE, 200, $ips);

        $document = $this->stagedDocument($url);
        $this->app->make(DocumentIngestionService::class)->process($document->fresh());

        $this->assertSame('ready', $document->fresh()->status, "正常公网地址被误杀了：{$url}");
        $this->assertOutboundRequestCount(1);
    }

    /** @return array<string, array{string, list<string>}> */
    public static function legitimatePublicUrls(): array
    {
        return [
            '普通 https' => ['https://example.com/blog/post-1', ['93.184.216.34']],
            '带非标端口' => ['https://example.com:8443/post', ['93.184.216.34']],
            '带 query 与锚点' => ['https://example.com/p?utm_source=x&id=9#top', ['93.184.216.34']],
            '多条 A 记录（全公网）' => ['https://example.com/', ['93.184.216.34', '8.8.8.8', '1.1.1.1']],
            '公网 IPv6' => ['https://example.com/', ['2001:4860:4860::8888']],
            '明文 http' => ['http://example.com/plain', ['93.184.216.34']],
            '长路径带中文' => ['https://example.com/文章/2026/深度报告', ['93.184.216.34']],
        ];
    }

    public function test_r1_max_bytes_uses_import_budget_not_unlimited(): void
    {
        $this->fakeSafeOutbound(self::PAGE, 200);

        $document = $this->stagedDocument('https://example.com/a');
        $this->app->make(DocumentIngestionService::class)->process($document->fresh());

        // 接入前这条路径没有任何响应体上限（超大页面 → worker OOM → FatalError
        // → catch(\Throwable) 接不住 → 文档永远卡 pending）。现在必须带上预算。
        $this->assertOutboundMaxBytes((int) config('geo.outbound_import_max_bytes'));
    }

    // ── R2：该拦的仍然拦得住 ───────────────────────────────────────────────

    /**
     * 这里模拟的是最难防的那种：**登记时域名解析到公网、真正抓取时已被换成内网**。
     *
     * 登记与抓取是两个时刻（中间还隔着一次排队），攻击者控制自己的 DNS 就能在这个
     * 窗口里换 IP。所以「登记时校验过了」不能当作抓取时的通行证 —— 真正生效的必须是
     * 紧挨着发包那一次校验。这个测试就是钉住这条：登记放行 ≠ 抓取放行。
     */
    #[DataProvider('hostileTargets')]
    public function test_r2_hostile_targets_stay_blocked(string $label, array $ips): void
    {
        // 第一步：登记时域名看起来完全正常（解析到公网），顺利入库排队。
        $this->fakeSafeOutbound(self::PAGE, 200, ['93.184.216.34']);
        $document = $this->stagedDocument('https://looks-legit.example.com/');
        $this->assertSame('pending', $document->fresh()->status);

        // 第二步：worker 真正开抓时，同一个域名已经指向内网了。
        $this->fakeSafeOutbound(self::PAGE, 200, $ips);

        // process() 的约定是「先 markFailed 再抛出去，让 job 能记账」，所以这里要接住。
        try {
            $this->app->make(DocumentIngestionService::class)->process($document->fresh());
            $this->fail("{$label} 没被拦住：process() 正常返回了");
        } catch (IngestionFailure $e) {
            $this->assertStringContainsString('内网地址', $e->getMessage());
        }

        $this->assertSame('failed', $document->fresh()->status, "{$label} 没被拦住");
        $this->assertOutboundRequestCount(0);  // 关键：拦在发包之前，不是发出去再看
        $this->assertStringContainsString('内网地址', (string) $document->fresh()->error_message);
    }

    /** @return array<string, array{string, list<string>}> */
    public static function hostileTargets(): array
    {
        return [
            '回环' => ['loopback', ['127.0.0.1']],
            '网关自身端口' => ['gateway', ['127.0.0.1']],
            '私有 A 段' => ['private-a', ['10.0.0.7']],
            '私有 B 段' => ['private-b', ['172.16.0.9']],
            '私有 C 段' => ['private-c', ['192.168.1.1']],
            '云元数据' => ['metadata', ['169.254.169.254']],
            'CGNAT（原实现漏放）' => ['cgnat', ['100.64.0.1']],
            'IPv6 回环' => ['v6-loopback', ['::1']],
            'IPv6 ULA' => ['v6-ula', ['fd00::1']],
            'IPv4-mapped IPv6' => ['mapped', ['::ffff:127.0.0.1']],
            'DNS 重绑定（公网+内网混合）' => ['rebinding', ['93.184.216.34', '10.0.0.7']],
        ];
    }

    public function test_r2_staging_rejects_hostile_url_without_leaving_pending_row(): void
    {
        $this->fakeSafeOutbound(self::PAGE, 200, ['127.0.0.1']);
        $kb = $this->makeKnowledgeBase();

        // 登记阶段就该拒 —— 让用户当场看到原因，而不是排队半天才 failed。
        $this->expectException(IngestionFailure::class);

        try {
            $this->app->make(DocumentIngestionService::class)
                ->stageUrl($kb->id, 'http://127.0.0.1/admin', null, $this->projectId($kb), $this->userId);
        } finally {
            $this->assertSame(0, KnowledgeDocument::query()->count(), '被拦下的网址不许留 pending 行');
            $this->assertOutboundRequestCount(0);
        }
    }

    // ── R4：跳转（301/302）要跟，且跟了不等于把防护放开 ────────────────────
    //
    // 为什么单列一节：接入安全链后 maxRedirects 默认 0，两个爬虫都没显式传值，
    // 于是 http→https、裸域→www 这类最常见的跳转会抛 redirect_limit_exceeded，
    // 再被文案兜底翻译成「这个网址指向的是内网地址」—— 网站好好的，用户被告知是内网。
    // 这三条钉住修复：常见跳转要跟得上、跟不动要说实话、跟进内网仍然要拦。

    public function test_r4_common_redirect_is_followed(): void
    {
        $this->fakeSafeOutboundSequence([
            ['status' => 301, 'headers' => ['Location' => 'https://cdn.example.org/final']],
            ['status' => 200, 'body' => self::PAGE],
        ]);

        $document = $this->stagedDocument('http://looks-legit.example.com/old');
        $this->app->make(DocumentIngestionService::class)->process($document->fresh());

        $this->assertSame('ready', $document->fresh()->status, '普通 301 跳转被当成抓取失败了');
        $this->assertOutboundRequestCount(2);
        $this->assertSame('https://cdn.example.org/final', $this->recordedOutboundUrl(1));
    }

    public function test_r4_too_many_redirects_says_so_instead_of_blaming_the_intranet(): void
    {
        // 一直 301 到同一个地方：超过上限后要说「跳转次数过多」，
        // 不许说成「内网地址」—— 那句话会让用户对着一个完全正常的网址查一整天。
        $this->fakeSafeOutboundSequence([
            ['status' => 301, 'headers' => ['Location' => 'https://looks-legit.example.com/loop']],
        ]);

        $document = $this->stagedDocument('http://looks-legit.example.com/start');

        try {
            $this->app->make(DocumentIngestionService::class)->process($document->fresh());
            $this->fail('无限跳转竟然正常返回了');
        } catch (IngestionFailure $e) {
            $this->assertStringContainsString('跳转', $e->getMessage());
            $this->assertStringNotContainsString('内网地址', $e->getMessage(), '跳转问题不许说成内网地址');
        }

        // 1 次原始请求 + 3 次跳转 = 4 次，第 4 次仍是 301 时才放弃。
        $this->assertOutboundRequestCount(4);
    }

    public function test_r4_redirect_into_the_intranet_is_still_blocked(): void
    {
        // 跟跳转不能变成绕过防护的口子：跳转目标每一跳都要重新解析 + 重新判定。
        $this->fakeSafeOutboundSequence([
            ['status' => 302, 'headers' => ['Location' => 'http://127.0.0.1/admin']],
            ['status' => 200, 'body' => self::PAGE],
        ]);

        $document = $this->stagedDocument('http://looks-legit.example.com/redirect-me');

        try {
            $this->app->make(DocumentIngestionService::class)->process($document->fresh());
            $this->fail('跳进内网竟然放行了');
        } catch (IngestionFailure $e) {
            $this->assertStringContainsString('内网地址', $e->getMessage());
        }

        $this->assertSame('failed', $document->fresh()->status);
        // 只发出了第一跳；跳转目标在发包**之前**就被拦下。
        $this->assertOutboundRequestCount(1);
    }

    // ── R5：非 2xx 要说实话，不能被 retry 吞成「连不上」────────────────────
    //
    // 这一节故意**不用** fakeSafeOutbound()：那个桩把整个 transport 换掉了，
    // Laravel 的 retry 循环根本不会执行，也就永远测不出这个缺陷（它正是这么漏过
    // 622 项全绿的）。这里保留真 transport + 真 PendingRequest，只把最外层的
    // stub handler 换成假响应，让 retry 逻辑真的跑一遍。

    public function test_r5_http_404_is_reported_as_404_not_as_connection_failure(): void
    {
        $calls = $this->fakeSecureHttpStub(fn () => HttpFactory::response('not found', 404));

        $document = $this->stagedDocument('https://looks-legit.example.com/gone');

        try {
            $this->app->make(DocumentIngestionService::class)->process($document->fresh());
            $this->fail('404 竟然当成功处理了');
        } catch (IngestionFailure $e) {
            $this->assertStringContainsString('HTTP 404', $e->getMessage(),
                '网站连得上、只是 404，不许说成「连不上这个网址」');
            $this->assertStringNotContainsString('连不上', $e->getMessage());
        }

        $this->assertSame(1, $calls->value, '404 不该重试 —— 重试一百次它还是 404，白等 500ms');
    }

    public function test_r5_connection_failure_is_still_retried(): void
    {
        // 另一半：真的连不上时，「抖动重试 2 次」这个原有行为必须保住。
        // failedConnection() 返回的是「接收 request 的闭包」，要真的调一下才拿到 rejection。
        $calls = $this->fakeSecureHttpStub(
            fn ($request) => HttpFactory::failedConnection('connect timeout')($request),
        );

        $document = $this->stagedDocument('https://looks-legit.example.com/down');

        try {
            $this->app->make(DocumentIngestionService::class)->process($document->fresh());
            $this->fail('连不上竟然当成功处理了');
        } catch (IngestionFailure $e) {
            $this->assertStringContainsString('连不上', $e->getMessage());
        }

        $this->assertSame(2, $calls->value, '连接失败仍应重试到 2 次');
    }

    public function test_r5_redirect_survives_the_retry_policy(): void
    {
        // 这条是拿真发包换来的：retry 的 when 判据也会在 3xx 上被调用，而 Laravel
        // 对 301/302 传进来的是 **null**（Response::toException() 只对 4xx/5xx 造异常）。
        // 判据写成不可空的 \Throwable 就会 TypeError，被上层吞成「连不上这个网址」——
        // 跳转当场再次失效。R4 那几条用假 transport，跑不到 retry，测不出这个坑。
        $hop = 0;
        $calls = $this->fakeSecureHttpStub(function () use (&$hop) {
            $hop++;

            return $hop === 1
                ? HttpFactory::response('', 301, ['Location' => 'https://cdn.example.org/final'])
                : HttpFactory::response(self::PAGE, 200);
        });

        $document = $this->stagedDocument('http://looks-legit.example.com/old');
        $this->app->make(DocumentIngestionService::class)->process($document->fresh());

        $this->assertSame('ready', $document->fresh()->status, '跳转在真 retry 路径上又挂了');
        $this->assertSame(2, $calls->value, '应当只发两次：原始请求 + 跳转后的请求');
    }

    // ── R3：白名单可定向放行 ──────────────────────────────────────────────

    public function test_r3_whitelist_releases_specific_internal_target(): void
    {
        config(['geo.outbound_private_targets' => ['internal-wiki.example.com:8080']]);
        $this->fakeSafeOutbound(self::PAGE, 200, ['10.0.0.7']);

        $target = $this->app->make(SafeOutboundHttpClient::class)
            ->resolveTarget('http://internal-wiki.example.com:8080/page');

        $this->assertSame('internal-wiki.example.com', $target->host);
        $this->assertSame(8080, $target->port);
        $this->assertSame('10.0.0.7', $target->selectedIp);
    }

    public function test_r3_whitelist_is_exact_host_and_port(): void
    {
        config(['geo.outbound_private_targets' => ['internal-wiki.example.com:8080']]);
        $this->fakeSafeOutbound(self::PAGE, 200, ['10.0.0.7']);
        $client = $this->app->make(SafeOutboundHttpClient::class);

        // 端口不同 → 不放行。白名单开的是一个门，不是一栋楼。
        $this->assertBlocked(fn () => $client->resolveTarget('http://internal-wiki.example.com:9090/page'));

        // 主机不同 → 不放行。
        $this->assertBlocked(fn () => $client->resolveTarget('http://other-internal.example.com:8080/page'));
    }

    public function test_r3_whitelist_rejects_wildcard_and_cidr_entries(): void
    {
        // 通配 / CIDR 写法一律当没配 —— 「*.internal」这种一笔放开整段内网，
        // 等于把 SSRF 防护关了还以为自己配了白名单。
        config(['geo.outbound_private_targets' => ['*.example.com:8080', '10.0.0.0/8']]);
        $this->fakeSafeOutbound(self::PAGE, 200, ['10.0.0.7']);
        $client = $this->app->make(SafeOutboundHttpClient::class);

        $this->assertBlocked(fn () => $client->resolveTarget('http://internal-wiki.example.com:8080/page'));
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private int $userId = 0;

    private int $tenantSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.tenant_domain' => 'geo.example.test']);
        // 入库流程末端会打向量化，测试里换成 Mock，避免真打网关。
        $this->app->bind(AiProvider::class, fn () => new MockAiProvider());
    }

    /**
     * 只换掉「最外层 stub handler」的打桩：DNS 用假的（不依赖网络），
     * transport / PendingRequest / retry 全是真的。
     *
     * @param  \Closure(Request): mixed  $respond  每次请求返回什么（响应或连接失败）
     * @return object{value: int} 实际发出的请求次数（含重试）
     */
    private function fakeSecureHttpStub(\Closure $respond): object
    {
        $resolver = new class implements HostResolver
        {
            public function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        };

        $this->app->instance(HostResolver::class, $resolver);
        $this->app->instance(SafeOutboundHttpClient::class, new SafeOutboundHttpClient(
            $resolver,
            $this->app->make(OutboundTransport::class),
            true,
        ));

        $calls = new class
        {
            public int $value = 0;
        };

        $this->app->make(SecureHttpFactory::class)->fake(function ($request) use ($respond, $calls) {
            $calls->value++;

            return $respond($request);
        });

        return $calls;
    }

    private function assertBlocked(callable $callback): void
    {
        try {
            $callback();
            $this->fail('本该被拦下，却放行了。');
        } catch (OutboundRequestBlockedException) {
            $this->addToAssertionCount(1);
        }
    }

    private function makeKnowledgeBase(): KnowledgeBase
    {
        $subdomain = 'ob-' . (++$this->tenantSeq);

        $tenant = Tenant::query()->create([
            'subdomain' => $subdomain,
            'name' => strtoupper($subdomain),
            'plan' => 'basic',
            'status' => 'active',
        ]);
        TenantContext::set($tenant);
        $this->configureWorkspaceModelSlots($tenant);

        $this->configureWorkspaceModelSlots($tenant);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain),
            'role' => 'admin',
        ]);
        $this->userId = (int) $user->id;

        $project = new Project;
        $project->forceFill([
            'tenant_id' => $tenant->id,
            'owner_id' => $user->id,
            'name' => '归属项目',
            'status' => 'active',
        ])->save();

        return KnowledgeBase::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Outbound Regression Base',
            'status' => 'active',
        ]);
    }

    private function projectId(KnowledgeBase $kb): int
    {
        return (int) Project::withoutGlobalScopes()
            ->where('tenant_id', $kb->tenant_id)
            ->value('id');
    }

    private function stagedDocument(string $url): KnowledgeDocument
    {
        $kb = $this->makeKnowledgeBase();

        return $this->app->make(DocumentIngestionService::class)
            ->stageUrl($kb->id, $url, null, $this->projectId($kb), $this->userId);
    }
}
