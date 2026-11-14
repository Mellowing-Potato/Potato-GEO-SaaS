<?php

namespace App\Providers;

use App\Contracts\Outbound\HostResolver;
use App\Contracts\Outbound\OutboundTransport;
use App\Services\Outbound\FinalOutboundSecurityPolicy;
use App\Services\Outbound\LaravelPinnedOutboundTransport;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Services\Outbound\SecureHttpFactory;
use App\Services\Outbound\SystemHostResolver;
use Closure;
use GuzzleHttp\Utils;
use Illuminate\Support\ServiceProvider;

/**
 * Bind the secure HTTP client explicitly; preserve Laravel's global factory.
 * All model and content requests use SafeOutboundHttpClient + SecureHttpFactory.
 * OutboundEntryPointGuardTest guards these call sites against accidental bypasses.
 */
class OutboundServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 能力令牌：只有持有这个对象实例的调用方才能设置「固定安全上下文」。
        // 用对象身份（===）而不是布尔/字符串做凭据，外部无法伪造，
        // 从而保证 SecurePendingRequest 的目标锁定不会被绕过。
        $fixedContextCapability = new \stdClass;

        // 终端 handler 在注册期一次性选定并冻结。
        // 不能让调用方替换 handler，否则 IP 锁定（CURLOPT_RESOLVE）会被架空。
        $trustedTerminal = Closure::fromCallable(Utils::chooseHandler());

        $this->app->bind(HostResolver::class, SystemHostResolver::class);

        $this->app->singleton(FinalOutboundSecurityPolicy::class);

        $this->app->bind(
            OutboundTransport::class,
            static fn (): LaravelPinnedOutboundTransport => new LaravelPinnedOutboundTransport($fixedContextCapability),
        );

        // enforce 传 null，让它每次现读 config('geo.outbound_enforce')。
        // 不在这里固化成布尔值：观察模式要能靠改 env + 重启容器生效，
        // 若在容器注册期就把值烧死，配置缓存下会出现「改了 env 不生效」的鬼故事。
        $this->app->singleton(SafeOutboundHttpClient::class, static fn ($app): SafeOutboundHttpClient => new SafeOutboundHttpClient(
            $app->make(HostResolver::class),
            $app->make(OutboundTransport::class),
            null,
        ));

        // ⚠️ 注意绑定的键是 SecureHttpFactory::class 本身，
        //    **不是** Illuminate\Http\Client\Factory::class（见类注释）。
        $this->app->singleton(SecureHttpFactory::class, static function ($app) use ($fixedContextCapability, $trustedTerminal): SecureHttpFactory {
            $resolveTarget = Closure::fromCallable(
                static fn (string $url) => $app->make(SafeOutboundHttpClient::class)->resolveTarget($url)
            );

            return new SecureHttpFactory(
                $app->make('events'),
                $app->make(FinalOutboundSecurityPolicy::class),
                $resolveTarget,
                $trustedTerminal,
                $fixedContextCapability,
            );
        });
    }
}
