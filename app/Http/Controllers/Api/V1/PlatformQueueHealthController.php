<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Ops\WorkerHealthService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * 单 9 · 队列健康端点 GET /api/v1/platform/queue-health。
 *
 * - 权限：沿用 routes/api.php:740 平台组已有的 auth:sanctum + can:platform:operations + log-admin-activity（不另造）。
 * - 查询：照 PlatformAdminController::health 的 TenantContext::bypassScope 写法绕开租户作用域（平台表无 tenant_id）。
 * - 红线 8：面板开关 queue_health_panel_enabled 默认 false（第一道回滚）；关着时端点 404。
 */
class PlatformQueueHealthController extends Controller
{
    public function index(): JsonResponse
    {
        // 红线 8：开关默认 false → 404。照 visibility / manual-publish 门控写法。
        if (! config('geo.queue_health_panel_enabled')) {
            abort(404);
        }

        return TenantContext::bypassScope(function () {
            $snapshot = app(WorkerHealthService::class)->snapshot();

            return response()->json($snapshot);
        });
    }
}
