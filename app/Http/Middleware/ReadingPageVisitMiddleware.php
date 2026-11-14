<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\Reports\AnalyticsIngestionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * X4 一期遗留：站内 /read 采集记真实 status_code。
 *
 * 一期在 controller 里把 status_code 硬编码 200，且 resolve 失败(404)不落任何记录。
 * 这里改用 terminable 中间件：响应发完后从 $response 取真实 getStatusCode()，
 * 404 也入库（爬虫撞死链是有价值信号）。tenant 由 slug 反查；article 由 controller
 * 成功渲染时写入 request attribute，404 时为 null。采集是旁路，异常一律吞掉。
 */
final class ReadingPageVisitMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $tenantSlug = (string) $request->route('tenantSlug');
        if ($tenantSlug === '') {
            return;
        }

        $tenant = Tenant::query()->where('subdomain', $tenantSlug)->first();
        if (! $tenant) {
            return;
        }

        $payload = [
            'tenant_id' => (int) $tenant->id,
            'project_id' => $request->attributes->get('reading_project_id'),
            'article_id' => $request->attributes->get('reading_article_id'),
            // 站内原生阅读，非渠道分发命中，合法留空。
            'publishing_channel_id' => null,
            'ip_address' => (string) $request->ip(),
            'user_agent' => $request->userAgent(),
            'referer' => $request->headers->get('referer'),
            'path' => $request->path(),
            'method' => $request->method(),
            'status_code' => $response->getStatusCode(),
        ];

        try {
            app(AnalyticsIngestionService::class)->recordVisit($payload);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
