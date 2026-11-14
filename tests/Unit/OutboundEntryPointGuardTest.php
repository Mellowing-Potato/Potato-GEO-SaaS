<?php

namespace Tests\Unit;

use App\Contracts\Outbound\HostResolver;
use App\Contracts\Outbound\OutboundTransport;
use App\Http\Controllers\Api\V1\SkillExecutionController;
use App\Services\Content\UrlImportProcessingService;
use App\Services\Knowledge\DocumentIngestionService;
use App\Services\Outbound\FinalOutboundSecurityPolicy;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Services\Outbound\SecureHttpFactory;
use App\Services\Outbound\SecurePendingRequest;
use Illuminate\Http\Client\Factory as LaravelHttpFactory;
use Tests\TestCase;

/**
 * 出站安全链的「装配守护」测试（批次一 · 单 1）。
 *
 * 这个文件存在的唯一理由：**阻止有人日后照搬上游 GEOFlow 的全局绑定把生产打挂。**
 *
 * 上游把 Illuminate\Http\Client\Factory 全局换成 SecureHttpFactory。
 * geo-saas 不能这么做 —— 网关跑在 127.0.0.1:8792（loopback），
 * 全局替换会让安全链把自家网关拦死：AI 调用全挂、扣费全挂。
 *
 * 如果 test_global_http_factory_is_not_replaced 变红，说明有人动了装配方式，
 * 上线前必须停下来看 app/Providers/OutboundServiceProvider.php 的类注释。
 *
 * 它同时守第二件事：**已知的对外抓取入口必须依赖安全链**。
 * 这条不是锦上添花 —— 正因为我们没有替换全局 Factory，「漏接」就不会有任何报错：
 * 谁在爬虫里写一行 Http::get($url) 都能跑通，SSRF 缺口就这么悄悄回来了。
 * 定向注入的唯一补偿手段就是这里的逐个点名。新增对外抓取入口时，
 * 请把它加进 CRAWLER_ENTRY_POINTS。
 */
class OutboundEntryPointGuardTest extends TestCase
{
    /**
     * 已知的「拿用户填的 URL 去抓公网」的入口。
     *
     * @var array<string, class-string>
     */
    private const CRAWLER_ENTRY_POINTS = [
        'URL 文章导入' => UrlImportProcessingService::class,
        '知识库网页摄入' => DocumentIngestionService::class,
        // 竞品推荐会拿用户填的 brand_url 去抓官网，抓回来的正文进提示词。
        // 它一度是全仓库防护最弱的出站口（零地址校验 + 不校验证书 + 自动降明文），
        // 所以尤其要被这两条守着。
        '竞品推荐官网抓取' => SkillExecutionController::class,
    ];

    public function test_global_http_factory_is_not_replaced(): void
    {
        $factory = $this->app->make(LaravelHttpFactory::class);

        $this->assertNotInstanceOf(
            SecureHttpFactory::class,
            $factory,
            '全局 HttpFactory 被替换成了 SecureHttpFactory。这会让 geo-saas 对网关'
            .'（127.0.0.1:8792，loopback）的所有调用被自家 SSRF 防护拦死 —— '
            .'AI 与扣费会全线瘫掉。安全链必须走定向注入，见 OutboundServiceProvider 类注释。'
        );
    }

    public function test_secure_http_factory_produces_secure_pending_request(): void
    {
        $factory = $this->app->make(SecureHttpFactory::class);

        // LaravelPinnedOutboundTransport 会拒绝非 SecurePendingRequest，
        // 所以这条断言是「安全链真的能用」的前提，不是形式主义。
        $this->assertInstanceOf(SecurePendingRequest::class, $factory->timeout(5));
    }

    public function test_outbound_chain_is_resolvable_from_container(): void
    {
        // 15 个文件迁过来但没人装配 = 一堆死码。逐个 make 一遍确认能构造。
        $this->assertInstanceOf(SafeOutboundHttpClient::class, $this->app->make(SafeOutboundHttpClient::class));
        $this->assertInstanceOf(HostResolver::class, $this->app->make(HostResolver::class));
        $this->assertInstanceOf(OutboundTransport::class, $this->app->make(OutboundTransport::class));
        $this->assertInstanceOf(FinalOutboundSecurityPolicy::class, $this->app->make(FinalOutboundSecurityPolicy::class));
    }

    public function test_known_crawler_entry_points_depend_on_the_safe_outbound_chain(): void
    {
        foreach (self::CRAWLER_ENTRY_POINTS as $label => $class) {
            $constructor = (new \ReflectionClass($class))->getConstructor();
            $this->assertNotNull($constructor, "{$label}（{$class}）没有构造函数，无法注入安全链。");

            $types = [];
            foreach ($constructor->getParameters() as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof \ReflectionNamedType) {
                    $types[] = $type->getName();

                    continue;
                }
                if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
                    foreach ($type->getTypes() as $inner) {
                        if ($inner instanceof \ReflectionNamedType) {
                            $types[] = $inner->getName();
                        }
                    }
                }
            }

            $this->assertContains(
                SafeOutboundHttpClient::class,
                $types,
                "{$label}（{$class}）的构造函数里已经没有 SafeOutboundHttpClient 了。"
                .'对外抓取入口一旦脱离安全链，SSRF 缺口会立刻回来，而且不会有任何报错。'
            );
        }
    }

    public function test_known_crawler_entry_points_do_not_use_the_global_http_facade(): void
    {
        foreach (self::CRAWLER_ENTRY_POINTS as $label => $class) {
            $file = (new \ReflectionClass($class))->getFileName();
            $this->assertIsString($file);

            // 只看真代码，注释里提到 Http::get 不算（那些是在讲历史缺口）。
            $code = '';
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (is_array($token)) {
                    if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $code .= $token[1];

                    continue;
                }
                $code .= $token;
            }

            // 用 assertFalse 而不是 assertDoesNotMatchRegularExpression：后者失败时会把
            // 整个源文件打进报告，真正要看的那句话反而被淹掉。
            $this->assertFalse(
                (bool) preg_match('/(?<![A-Za-z0-9_])Http::/', $code),
                "{$label}（{$class}）里出现了 Http 门面调用。对外抓取必须走 SafeOutboundHttpClient —— "
                .'全局 Factory 没有被替换，Http:: 发出去的请求不带任何 SSRF 防护。'
            );
        }
    }

    public function test_gateway_loopback_stays_reachable_through_plain_http(): void
    {
        // 反向验证：原生 Http 门面不受安全链影响，网关地址仍可正常构造请求。
        // 这条守的是「接入安全链没有顺手改坏现有的网关调用路径」。
        $plain = $this->app->make(LaravelHttpFactory::class)->timeout(5);

        $this->assertNotInstanceOf(SecurePendingRequest::class, $plain);
    }
}
