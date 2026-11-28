<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

$application = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn (Request $request) => null);

        /* 安全响应头（计划 §9.1，2026-09-21）：web 与 api 两组都挂。
           为什么应用层发而不是交给边缘 Caddy：容器只 expose 8000、唯一入口是 Caddy，
           在 Caddy 里加头没有版本控制、回滚不了；应用层发则随镜像走，可测可回滚。
           中间件本身不覆盖业务已设的头（theme-preview 的 DENY 要保留）。 */
        $middleware->web(append: [
            \App\Http\Middleware\SecurityHeaders::class,
        ]);

        /* 信任反向代理（2026-08-28 补，此前从未配过）。
         *
         * 生产拓扑：公网 → 边缘 Caddy（终止 TLS）→ 明文 HTTP → geo-saas 容器（FrankenPHP）。
         * 容器只 expose 8000、不对外发布端口，唯一入口就是边缘 Caddy。
         * 没配这个之前，一个缺口同时造成三类故障，而且测试环境永远测不出来（测试直连没有代理）：
         *   ① $request->ip() 对所有访客都返回边缘 Caddy 在 docker 网里的地址（容器内部地址）
         *      → 任何按 IP 的限流都退化成「全平台共用一个桶」；
         *   ② 协议被认成 http → asset() / url() 在 https 页面上吐 http:// 绝对地址，
         *      被浏览器当混合内容拦掉；
         *   ③ 访问统计与管理员操作审计日志记的 IP 全是代理地址，
         *      滥用取证和安全审计都用不了。
         *
         * 只信内网段：边缘 Caddy 与本容器同在 yanji-local（docker 网，172.16/12），
         * 公网客户端到不了这里，所以「来自内网 = 是我们自己的代理」这个前提成立。
         * 伪造防护：客户端自己塞的 X-Forwarded-For 会被 Caddy 追加真实地址在其后，
         * Symfony 从右往左取第一个非可信地址，拿到的仍是真实客户端。
         *
         * ⚠️ 只信 FOR 与 PROTO，**不信 X-Forwarded-Host**：Host 头本来就由 Caddy 原样透传，
         *    多信一个可被伪造的域名来源只会开出 host header 注入面（影响密码重置链接这类东西）。
         *    PREFIX / AWS_ELB 同理不信，本项目没有那种拓扑。
         *    端口不用单独信：Host 头没带端口时 Symfony 按协议推出 443，URL 不会多出 :8000。
         */
        $middleware->trustProxies(
            at: [
                '10.0.0.0/8',
                '172.16.0.0/12',
                '192.168.0.0/16',
                '127.0.0.1',
                '::1',
                'fc00::/7',
            ],
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
        );

        // AttachRequestId 排第一：请求编号要早于一切逻辑生成，这样连 locale 阶段
        // 出的错也带得上编号。随后是阶段 3.3 的后端多语言，确保 __() 在控制器内
        // 取到正确 locale。
        $middleware->api(prepend: [
            \App\Http\Middleware\AttachRequestId::class,
            \App\Http\Middleware\LocaleMiddleware::class,
        ]);

        // 浏览器令牌校验 Cookie 指纹及账户中心会话有效性；机器令牌要求显式 scope。
        // 两道闸门自行解析 Bearer，不依赖 auth:sanctum 的执行顺序。
        $middleware->api(append: [
            \App\Http\Middleware\EnforceAccountSessionBinding::class,
            \App\Http\Middleware\EnforceMachineTokenScope::class,
        ]);

        // 隐式路由模型绑定必须在租户上下文之后解析：SubstituteBindings 在框架
        // 优先级表里排得很靠前，默认会跑在 IdentifyTenant 之前；那时 TenantScope
        // 取不到租户、fail-open 直接放行，绑定出来的模型是跨租户的。
        // 回归守卫：CrossTenantProjectAccessTest::test_implicit_route_binding_cannot_reach_another_tenants_record
        $middleware->prependToPriorityList(
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\IdentifyTenant::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['app_secret']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return null;
        });

        // 隐式绑定现在在租户上下文内解析：跨租户的 id 会在绑定阶段就查不到，
        // 抛 ModelNotFoundException（框架包装成 NotFoundHttpException）。
        // 那条消息是框架默认的英文原文，会把模型类名漏给调用方，也不跟随语言。
        // 这里统一换成本地化文案，按模型取 messages.<model>.not_found，取不到
        // 落到 messages.common.resource_not_found。
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $previous = $e->getPrevious();
            if (! $previous instanceof ModelNotFoundException) {
                return null;
            }

            $key = 'messages.'.Str::snake(class_basename($previous->getModel())).'.not_found';
            $message = __($key);

            return response()->json([
                'message' => $message === $key ? __('messages.common.resource_not_found') : $message,
            ], 404);
        });

        // 业务规则不允许的操作（阶段推进/回退等）用 \DomainException 表达。
        // 此前没有映射 → Laravel 当未捕获异常渲染成 500，前端只能显示「服务器错误」，
        // 而「当前阶段未满足完成条件: …」这种话本来就是给用户看的、也是可操作的。
        // 422 = 请求本身合法但当前状态不允许，正是这类错误的语义。
        $exceptions->render(function (\DomainException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return null;
        });

        // 整个请求体超过 post_max_size 时，Laravel 默认回一句英文的
        // "The POST data is too large."，用户看不出是自己文件太大。
        // 这是最后一道兜底：10M~20M 的文件由 KnowledgeBaseController 给出带具体
        // 大小的提示，只有超过 20M（信封本身都装不下）才会落到这里。
        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => '上传内容过大，请压缩或拆分后再传。单个文档上限 10 MB。',
                ], 413);
            }

            return null;
        });
    })->create();

// Docker keeps the encryption key in a shared persistent volume. Configuration
// supplied by Compose still takes precedence over the saved environment file.
if (PHP_OS_FAMILY === 'Windows') {
    foreach (range('A', 'Z') as $drive) {
        $application->addAbsoluteCachePathPrefix($drive.':\\');
        $application->addAbsoluteCachePathPrefix($drive.':/');
        $application->addAbsoluteCachePathPrefix(strtolower($drive).':\\');
        $application->addAbsoluteCachePathPrefix(strtolower($drive).':/');
    }
}

if ($environmentPath = getenv('YANJI_ENV_PATH')) {
    $application->useEnvironmentPath($environmentPath);
}

if ($publicPath = getenv('YANJI_PUBLIC_PATH')) {
    $application->usePublicPath($publicPath);
}

return $application;
