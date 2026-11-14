<?php

namespace App\Http\Middleware;

use App\Services\Admin\ActivityLogService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 平台管理员操作审计中间件（阶段 3.4，对标 GEOFlow LogAdminActivity）。
 *
 * 仅对平台后台（v1/platform）的变更型请求（写操作）落审计日志；
 * 读操作与静态资源不记录。写入失败不影响主流程。
 */
final class LogAdminActivity
{
    /**
     * 不记录审计日志的读方法。
     *
     * @var array<int,string>
     */
    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (in_array(strtoupper((string) $request->method()), self::READ_METHODS, true)) {
            return $response;
        }

        // 仅在平台后台上下文记录（路由组已限定前缀）。
        if (! $request->is('api/v1/platform/*')) {
            return $response;
        }

        $action = strtolower((string) $request->method()).' '.($request->route()?->getName() ?? $request->path());

        $log = $this->activityLog->recordFromRequest($request, $action);

        // 写端点回包注入审计编号，供前端生成 AUD-{YYYYMMDD}-{id} 横幅。
        if ($log !== null && $response instanceof JsonResponse) {
            $data = $response->getData(true);
            if (is_array($data)) {
                $data['audit_id'] = $log->id;
                $response->setData($data);
            }
        }

        return $response;
    }
}
