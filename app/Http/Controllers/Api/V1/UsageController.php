<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\ModelCallLog;
use App\Support\ModelTierLabel;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Tenant-scoped usage with attribution snapshots and no monetary amounts. */
class UsageController extends Controller
{
    public function usage(Request $request): JsonResponse
    {
        $tenant = TenantContext::get() ?? $request->user()->tenant;
        $validated = $request->validate([
            // all = 不限时间。概览的「最近消耗」要的是"最近 N 条"，不该被限制在今天，
            // 否则会出现「本月调用 12 次」和「最近还没有消耗记录」同屏打架。
            'granularity' => ['nullable', 'string', 'in:all,day,week,month,year'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $granularity = $validated['granularity'] ?? 'day';
        $periodStart = $this->usagePeriodStart($granularity);

        $logs = ModelCallLog::query()
            ->where('tenant_id', $tenant->id)
            ->when($periodStart, fn ($query) => $query->where('created_at', '>=', $periodStart))
            ->with(['project:id,name', 'skillExecution:id,skill_id,skill_name,status'])
            ->latest('id')
            ->get();

        $total = $logs->count();
        $perPage = (int) ($validated['per_page'] ?? 20);
        $page = (int) ($validated['page'] ?? 1);

        $records = $logs
            ->slice(($page - 1) * $perPage, $perPage)
            ->values()
            ->map(fn (ModelCallLog $log): array => [
                'id' => $log->id,
                'project_id' => $log->project_id,
                'project_name' => $log->project?->name
                    ?? data_get($log->metadata, 'project_name_snapshot'),
                'project_deleted' => $log->project === null,
                'skill_execution_id' => $log->skill_execution_id,
                'skill_id' => $log->skillExecution?->skill_id,
                'skill_name' => $log->skillExecution?->skill_name
                    ?? data_get($log->metadata, 'skill_name_snapshot'),
                'model_tier' => ModelTierLabel::for($log->model),
                'status' => $log->status,
                'total_tokens' => $log->total_tokens,
                'duration_seconds' => $log->duration_seconds,
                'provider' => $log->provider,
                'model' => $log->model,
                'provider_request_id' => data_get($log->metadata, 'gateway.provider_request_id') ?? data_get($log->metadata, 'provider_request_id'),
                'created_at' => $log->created_at?->toISOString(),
            ])
            ->values();

        $daily = $logs
            ->groupBy(fn (ModelCallLog $log): string => $log->created_at->toDateString())
            ->sortKeys()
            ->map(function ($dayLogs, string $date): array {
                $bySkill = $dayLogs
                    ->groupBy(
                        // 快照要排在「其他」前面：删过项目的那几天，技能执行已经没了，
                        // 不回退的话那几天的消耗会整批并进「其他」，看不出花在什么上。
                        fn (ModelCallLog $log): string => $log->skillExecution?->skill_name
                            ?? $log->skillExecution?->skill_id
                            ?? data_get($log->metadata, 'skill_name_snapshot')
                            ?? '其他'
                    )
                    ->map(fn ($group) => (int) $group->sum('total_tokens'))
                    ->all();

                return [
                    'date' => $date,

                    'total_tokens' => (int) $dayLogs->sum('total_tokens'),
                    'calls' => $dayLogs->count(),
                    'by_skill' => $bySkill,
                ];
            })
            ->values();

        return response()->json([
            'object' => 'model_usage',
            'period' => [
                'granularity' => $granularity,
                'from' => $periodStart?->toDateString(),
                'to' => now()->toDateString(),
                'as_of' => now()->toDateString(),
            ],
            'summary' => [

                'total_tokens' => (int) $logs->sum('total_tokens'),
                'total_calls' => $total,
                'unknown_usage_calls' => $logs->whereNull('total_tokens')->count(),
            ],
            'records' => [
                'data' => $records,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
            'daily_usage' => $daily,
        ]);
    }

    /** null = 不限时间（granularity=all），调用方需跳过 created_at 过滤。 */
    private function usagePeriodStart(string $granularity): ?Carbon
    {
        return match ($granularity) {
            'all' => null,
            'week' => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
            'year' => now()->startOfYear(),
            default => now()->startOfDay(),
        };
    }
}
