<?php

namespace App\Services\Reports;

use App\Models\AiVisibilityRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * AI 可见性运行历史查询过滤器（批次二 · 单 4）。
 *
 * 只做白名单参数 → 查询条件的翻译；权限/租户隔离由路由中间件与
 * BelongsToTenant 全局作用域承担。
 */
final class AiVisibilityAnalyticsFilter
{
    /**
     * @return Builder<AiVisibilityRun>
     */
    public function apply(Request $request): Builder
    {
        $query = AiVisibilityRun::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($status = trim((string) $request->query('status', ''))) {
            if (in_array($status, [
                AiVisibilityRun::STATUS_PENDING,
                AiVisibilityRun::STATUS_RUNNING,
                AiVisibilityRun::STATUS_COMPLETED,
                AiVisibilityRun::STATUS_FAILED,
            ], true)) {
                $query->where('status', $status);
            }
        }

        if ($projectId = (int) $request->query('project_id', 0)) {
            $query->where('project_id', $projectId);
        }

        if ($batchId = trim((string) $request->query('batch_id', ''))) {
            $query->where('batch_id', $batchId);
        }

        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $query->where('question', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%');
        }

        return $query;
    }
}
