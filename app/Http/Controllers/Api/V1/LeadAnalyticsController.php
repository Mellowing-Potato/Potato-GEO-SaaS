<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Reports\LeadAnalyticsFilter;
use App\Services\Reports\LeadAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 线索转化统计（单 8，租户侧 API）。
 * 路由挂 can:lead:manage（admin + editor）。查询受 TenantScope 约束。
 */
class LeadAnalyticsController extends Controller
{
    public function __construct(private readonly LeadAnalyticsService $analytics) {}

    public function __invoke(Request $request): JsonResponse
    {
        $filter = LeadAnalyticsFilter::fromRequest($request->query());

        return response()->json([
            'summary' => $this->analytics->summary($filter),
            'filter' => $filter->toArray(),
        ]);
    }
}
