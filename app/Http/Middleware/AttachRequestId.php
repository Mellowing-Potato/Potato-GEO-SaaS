<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * 给每个 API 请求发一个可查的请求编号。
 *
 * 背景（2026-08-17）：界面上「错误编号 AT-260817-3497」原本是前端 Math.random()
 * 现编的，服务端查无此号，「联系支持并附上编号」是句空话。现在编号由这里生成，
 * 经 X-Request-Id 回给前端显示，同时 shareContext 进日志——本次请求写的每一条
 * 日志都会自动带上它，拿到用户报的编号 grep 日志即可定位。
 *
 * 响应头由 StampRequestId 监听器统一贴（那里才能覆盖异常渲染出来的 500），
 * 本中间件只负责生成与登记。
 */
class AttachRequestId
{
    public const HEADER = 'X-Request-Id';

    public const ATTRIBUTE = 'request_id';

    public function handle(Request $request, Closure $next): Response
    {
        $id = self::generate();

        $request->attributes->set(self::ATTRIBUTE, $id);
        Log::shareContext([self::ATTRIBUTE => $id]);

        return $next($request);
    }

    /**
     * 形如 GEO-260817-4F2A19：前缀 + 日期 + 6 位十六进制。
     * 带日期是为了让支持侧一眼看出该翻哪天的日志。
     */
    public static function generate(): string
    {
        return sprintf('GEO-%s-%s', now()->format('ymd'), strtoupper(bin2hex(random_bytes(3))));
    }

    /**
     * 取本次请求的编号；中间件没跑到（例如路由未匹配前就出错）时返回空串。
     */
    public static function of(Request $request): string
    {
        $id = $request->attributes->get(self::ATTRIBUTE);

        return is_string($id) && $id !== '' ? $id : '';
    }
}
