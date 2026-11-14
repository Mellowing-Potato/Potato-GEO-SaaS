<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted application interfaces, workspace ownership and execution behavior.
 * Upstream: app/Services/Admin/Analytics/LeadAnalyticsService.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Services\Reports;

use App\Models\LeadFormVisit;
use App\Models\LeadSubmission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * 线索转化统计（单 8）。
 *
 * 照抄上游 LeadAnalyticsService：聚合提交数 / 新 / 待跟进 / 转化 + 转化率，
 * 趋势、来源、最近提交。所有查询受 TenantScope 约束（管理台在租户上下文内调用）。
 * 本仓无 AnalyticsDateRange，日期归一已内联进 LeadAnalyticsFilter::resolveDates。
 */
class LeadAnalyticsService
{
    /** @return array<string, mixed> */
    public function summary(LeadAnalyticsFilter $filter): array
    {
        if (! Schema::hasTable('lead_submissions')) {
            return $this->emptySummary($filter);
        }

        $row = $this->filtered($filter)
            ->selectRaw('COUNT(*) AS submissions')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS new_count', [LeadSubmission::STATUS_NEW])
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) AS pending_count', [LeadSubmission::STATUS_NEW, LeadSubmission::STATUS_CONTACTED])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS converted_count', [LeadSubmission::STATUS_CONVERTED])
            ->first();
        $submissions = (int) ($row->submissions ?? 0);
        $converted = (int) ($row->converted_count ?? 0);

        return [
            'ready' => true,
            'kpis' => [
                'submissions' => $submissions,
                'new' => (int) ($row->new_count ?? 0),
                'pending' => (int) ($row->pending_count ?? 0),
                'converted' => $converted,
                'conversion_rate' => $submissions > 0 ? round(($converted * 100) / $submissions, 1) : 0.0,
            ],
            'trend' => $this->trend($filter),
            'sources' => $this->sources($filter),
            'recent' => $this->recent($filter),
            'funnel' => $this->funnel($filter),
        ];
    }

    /**
     * 评审修复 H1：浏览 → 提交 → 转化 全漏斗。
     *
     * 此前 lead_form_visits 是一张只写不读的表 —— 中间件每次访问都落行，但全仓没有任何
     * 代码读它，统计只查 lead_submissions。这里把它接进来，中间件采的量才有去处。
     *
     * 两条口径写在这里，免得以后对不上账：
     *  - 浏览量只算 status_code < 400 的成功打开；404（表单不存在/未发布/爬虫撞死链）
     *    照旧落库当死链信号，但不进漏斗分母，否则转化率会被爬虫压到失真。
     *  - 漏斗的「提交」不套 status 筛选（只按日期 + 表单），否则筛「新线索」时
     *    分子分母不同源：浏览 100、提交 3（只数 new 的）会算出错误的转化率。
     *    KPI 区那个 submissions 仍按筛选走，是两个不同的问题，别互相搬。
     *
     * @return array<string, mixed>
     */
    private function funnel(LeadAnalyticsFilter $filter): array
    {
        $tracked = Schema::hasTable('lead_form_visits');
        $views = $tracked ? (int) $this->visits($filter)->count() : 0;

        $row = $this->funnelSubmissions($filter)
            ->selectRaw('COUNT(*) AS submissions')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS converted_count', [LeadSubmission::STATUS_CONVERTED])
            ->first();
        $submissions = (int) ($row->submissions ?? 0);
        $converted = (int) ($row->converted_count ?? 0);

        return [
            // 采集表不存在（迁移未跑）时明确回 false，不拿 0 冒充「没人访问」。
            'views_tracked' => $tracked,
            'views' => $views,
            'submissions' => $submissions,
            'converted' => $converted,
            'view_to_submission_rate' => $views > 0 ? round(($submissions * 100) / $views, 1) : 0.0,
            'submission_to_converted_rate' => $submissions > 0 ? round(($converted * 100) / $submissions, 1) : 0.0,
        ];
    }

    /**
     * 漏斗分子：只按日期 + 表单筛，不套 status（理由见 funnel() 注释）。
     *
     * @return Builder<LeadSubmission>
     */
    private function funnelSubmissions(LeadAnalyticsFilter $filter): Builder
    {
        return LeadSubmission::query()
            ->whereBetween('lead_submissions.created_at', [$filter->start(), $filter->end()])
            ->when($filter->formId !== null, fn (Builder $query) => $query->where('lead_form_id', $filter->formId));
    }

    /**
     * 漏斗分母：公开页成功打开的访问（受 TenantScope 约束，与提交同租户）。
     *
     * @return Builder<LeadFormVisit>
     */
    private function visits(LeadAnalyticsFilter $filter): Builder
    {
        return LeadFormVisit::query()
            ->whereBetween('lead_form_visits.created_at', [$filter->start(), $filter->end()])
            ->where('status_code', '<', 400)
            ->when($filter->formId !== null, fn (Builder $query) => $query->where('lead_form_id', $filter->formId));
    }

    /** @return Builder<LeadSubmission> */
    private function filtered(LeadAnalyticsFilter $filter): Builder
    {
        return LeadSubmission::query()
            ->whereBetween('lead_submissions.created_at', [$filter->start(), $filter->end()])
            ->when($filter->formId !== null, fn (Builder $query) => $query->where('lead_form_id', $filter->formId))
            ->when($filter->status !== 'all', fn (Builder $query) => $query->where('status', $filter->status));
    }

    /** @return list<array{date: string, views: int, submissions: int, converted: int}> */
    private function trend(LeadAnalyticsFilter $filter): array
    {
        $rows = $this->filtered($filter)
            ->selectRaw('DATE(created_at) AS trend_date, COUNT(*) AS submissions')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS converted', [LeadSubmission::STATUS_CONVERTED])
            ->groupByRaw('DATE(created_at)')
            ->get()
            ->keyBy(fn (object $row): string => Carbon::parse((string) $row->trend_date)->toDateString());

        // 评审修复 H1：趋势线补上浏览量，跟漏斗同口径（只数 status_code < 400）。
        $visitRows = Schema::hasTable('lead_form_visits')
            ? $this->visits($filter)
                ->selectRaw('DATE(created_at) AS trend_date, COUNT(*) AS views')
                ->groupByRaw('DATE(created_at)')
                ->get()
                ->keyBy(fn (object $row): string => Carbon::parse((string) $row->trend_date)->toDateString())
            : collect();

        return array_map(function (Carbon $day) use ($rows, $visitRows): array {
            $date = $day->toDateString();
            $row = $rows->get($date);
            $visitRow = $visitRows->get($date);

            return [
                'date' => $date,
                'views' => (int) ($visitRow->views ?? 0),
                'submissions' => (int) ($row->submissions ?? 0),
                'converted' => (int) ($row->converted ?? 0),
            ];
        }, $this->days($filter));
    }

    /** @return list<array{source: string, submissions: int, converted: int}> */
    private function sources(LeadAnalyticsFilter $filter): array
    {
        return $this->filtered($filter)
            ->selectRaw("COALESCE(NULLIF(TRIM(source_url), ''), '') AS source")
            ->selectRaw('COUNT(*) AS submissions')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS converted', [LeadSubmission::STATUS_CONVERTED])
            ->groupByRaw("COALESCE(NULLIF(TRIM(source_url), ''), '')")
            ->orderByDesc('submissions')
            ->limit(10)
            ->get()
            ->map(fn (LeadSubmission $row): array => [
                'source' => (string) $row->source,
                'submissions' => (int) $row->submissions,
                'converted' => (int) $row->converted,
            ])
            ->all();
    }

    /** @return list<LeadSubmission> */
    private function recent(LeadAnalyticsFilter $filter): array
    {
        $query = $this->filtered($filter)
            ->select('id', 'lead_form_id', 'status', 'payload', 'source_url', 'created_at')
            ->orderByDesc('created_at')
            ->limit(10);
        $hasFormsTable = Schema::hasTable('lead_forms');
        if ($hasFormsTable) {
            $query->with('form:id,name,slug');
        }

        $leads = $query->get();
        if (! $hasFormsTable) {
            $leads->each(fn (LeadSubmission $lead) => $lead->setRelation('form', null));
        }

        return $leads->all();
    }

    /** @return array<string, mixed> */
    private function emptySummary(LeadAnalyticsFilter $filter): array
    {
        return [
            'ready' => false,
            'kpis' => ['submissions' => 0, 'new' => 0, 'pending' => 0, 'converted' => 0, 'conversion_rate' => 0.0],
            'trend' => array_map(fn (Carbon $day): array => ['date' => $day->toDateString(), 'views' => 0, 'submissions' => 0, 'converted' => 0], $this->days($filter)),
            'sources' => [],
            'recent' => [],
            // 提交表都不存在时不去读 visits，整体回 not ready，形状与 summary() 一致。
            'funnel' => [
                'views_tracked' => false,
                'views' => 0,
                'submissions' => 0,
                'converted' => 0,
                'view_to_submission_rate' => 0.0,
                'submission_to_converted_rate' => 0.0,
            ],
        ];
    }

    /** @return list<Carbon> */
    private function days(LeadAnalyticsFilter $filter): array
    {
        $days = [];
        $cursor = $filter->dateFrom->copy();
        while ($cursor->lessThanOrEqualTo($filter->dateTo)) {
            $days[] = $cursor->copy();
            $cursor->addDay();
        }

        return $days;
    }
}
