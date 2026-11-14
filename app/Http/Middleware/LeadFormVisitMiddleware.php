<?php

namespace App\Http\Middleware;

use App\Models\LeadFormVisit;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * 线索表单公开页访问采集（单 8，照抄 ReadingPageVisitMiddleware 的 X4 一期遗留手法）。
 *
 * - terminable：响应发完后从 $response 取真实 getStatusCode()，404 也入库
 *   （爬虫撞死链是有价值信号；lead_form_id 在 404 时为 null）。
 * - tenant 由 slug 反查（公开页按 URL 两段定位，不走 IdentifyTenant 的 host 机制）。
 * - 采集是旁路，异常一律吞掉（report），绝不能影响页面可用性。
 */
final class LeadFormVisitMiddleware
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

        // 评审修复（中低项）：与 LeadFormDeliveryService::resolve 同步 strtolower 归一。
        // resolve 侧对租户 slug 忽略大小写（/forms/TENANT-A/... 页面正常 200），
        // 此前这里用原始大小写查 subdomain 查不到 → 访问了页面却落 0 行 visit。
        $tenant = Tenant::query()->where('subdomain', strtolower($tenantSlug))->first();
        if (! $tenant) {
            return;
        }

        $payload = [
            'tenant_id' => (int) $tenant->id,
            'lead_form_id' => $request->attributes->get('lead_form_id'),
            'ip_address' => (string) $request->ip(),
            'user_agent' => $request->userAgent(),
            'referer' => $request->headers->get('referer'),
            'path' => $request->path(),
            'method' => $request->method(),
            'status_code' => $response->getStatusCode(),
        ];

        try {
            LeadFormVisit::query()->create($payload);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
