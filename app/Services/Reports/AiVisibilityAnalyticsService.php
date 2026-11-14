<?php

namespace App\Services\Reports;

use App\Models\AiVisibilityRun;
use App\Models\AiVisibilitySource;
use App\Services\Geo\AiVisibility\AiVisibilityConfigurationResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * AI 可见性运行历史聚合（批次二 · 单 4）。
 *
 * 页面两层数据都在这：
 *  - 批次摘要（运行历史列表：问题数/完成数/耗时/积分/成本——「第 3/10 个问题」进度的数据源）；
 *  - 页头统计（总轮数/总信源/扣费合计）。
 *
 * ⚠️ 客户可见口径的纪律：成本对客户是「预估」（按 geo-reason 近似档扣点）。
 *    真实上游成本（付给火山方舟的进货价）是内部审计口径，**一律不进本服务的输出**——
 *    本服务唯一的调用方是租户侧的 AiVisibilityController::index，
 *    「仅管理员视角使用」这句注释曾经写在这里，但没有任何管理员出口在读它，
 *    结果是进货价直接摆到了客户面板上（2026-08-27 单 6 评审）。
 *    要看真实成本请直接查库：ai_visibility_runs.upstream_cost_fen / model_call_logs。
 */
final class AiVisibilityAnalyticsService
{
    public function __construct(
        private readonly AiVisibilityConfigurationResolver $config,
    ) {}

    /**
     * 把 run 行按 batch_id 折成批次摘要。
     *
     * @param  Collection<int, AiVisibilityRun>  $runs
     *
     * @return list<array<string, mixed>>
     */
    public function batchSummaries(Collection $runs): array
    {
        $batches = $runs->groupBy('batch_id');

        $summaries = [];
        foreach ($batches as $batchId => $batchRuns) {
            /** @var Collection<int, AiVisibilityRun> $batchRuns */
            $sorted = $batchRuns->sortBy('question_index')->values();
            $first = $sorted->first();

            $summaries[] = [
                'batch_id' => (string) $batchId,
                'created_at' => optional($first->created_at)->toIso8601String(),
                'project_id' => $first->project_id,
                'question_count' => $sorted->count(),
                'completed_count' => $sorted->where('status', AiVisibilityRun::STATUS_COMPLETED)->count(),
                'failed_count' => $sorted->where('status', AiVisibilityRun::STATUS_FAILED)->count(),
                'running_count' => $sorted->whereIn('status', [
                    AiVisibilityRun::STATUS_PENDING,
                    AiVisibilityRun::STATUS_RUNNING,
                ])->count(),
                'duration_seconds' => round((float) $sorted->sum('duration_seconds'), 2),
                'total_tokens' => (int) $sorted->sum('prompt_tokens') + (int) $sorted->sum('completion_tokens'),
                'questions' => $sorted->map(fn (AiVisibilityRun $run) => [
                    'run_id' => $run->id,
                    'question_index' => $run->question_index,
                    'question' => $run->question,
                    'status' => $run->status,
                ])->values()->all(),
            ];
        }

        return $summaries;
    }

    /**
     * 页头统计（当前租户全量，读 BelongsToTenant 作用域下的查询）。
     *
     * @return array<string, mixed>
     */
    public function overview(Builder $runsQuery): array
    {
        $runs = (clone $runsQuery)->select([
            'id',
            'status',
            'prompt_tokens',
            'completion_tokens',
            'metadata',
        ])->get();

        $sourceCount = AiVisibilitySource::query()->count();

        return [
            'total_questions' => $runs->count(),
            'completed_questions' => $runs->where('status', AiVisibilityRun::STATUS_COMPLETED)->count(),
            'failed_questions' => $runs->where('status', AiVisibilityRun::STATUS_FAILED)->count(),
            'total_sources' => $sourceCount,
            'total_tokens' => (int) $runs->sum('prompt_tokens') + (int) $runs->sum('completion_tokens'),
        ];
    }
}
