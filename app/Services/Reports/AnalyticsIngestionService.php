<?php

namespace App\Services\Reports;

use App\Models\AnalyticsCrawlerHit;
use App\Models\AnalyticsVisitLog;
use App\Support\Analytics\TrafficClassifier;
use App\Support\TenantContext;
use Throwable;

/**
 * 访问日志接入服务（阶段 3.1）。
 *
 * 对标 GEOFlow AnalyticsLogQueryService 的写入侧：将一次访问写入
 * analytics_visit_logs，并按流量类型识别 AI/搜索/其他爬虫，另写
 * analytics_crawler_hits 供「AI 爬虫命中」报表聚合。
 */
final class AnalyticsIngestionService
{
    /**
     * @param  array{
     *   tenant_id?: int|null,
     *   project_id?: int|null,
     *   article_id?: int|null,
     *   publishing_channel_id?: int|null,
     *   ip_address?: string,
     *   user_agent?: string|null,
     *   referer?: string|null,
     *   path?: string|null,
     *   method?: string,
     *   status_code?: int|null,
     * }  $data
     */
    public function recordVisit(array $data): AnalyticsVisitLog
    {
        $tenantId = (int) ($data['tenant_id'] ?? TenantContext::id());
        $userAgent = $data['user_agent'] ?? null;

        ['type' => $type, 'name' => $name] = TrafficClassifier::classifyDetailed($userAgent);

        $log = AnalyticsVisitLog::query()->create([
            'tenant_id' => $tenantId,
            'project_id' => $data['project_id'] ?? null,
            'article_id' => $data['article_id'] ?? null,
            'publishing_channel_id' => $data['publishing_channel_id'] ?? null,
            'ip_address' => (string) ($data['ip_address'] ?? ''),
            'user_agent' => (string) ($userAgent ?? ''),
            'bot_name' => $name,
            'bot_family' => $type === TrafficClassifier::HUMAN || $type === TrafficClassifier::UNKNOWN ? null : $type,
            'referer' => $data['referer'] ?? null,
            'path' => $data['path'] ?? null,
            'method' => strtoupper((string) ($data['method'] ?? 'GET')),
            'status_code' => $data['status_code'] ?? null,
            'traffic_type' => $type,
            'created_at' => now(),
        ]);

        if ($type !== TrafficClassifier::HUMAN && $type !== TrafficClassifier::UNKNOWN) {
            $this->recordCrawlerHit($log, $name, $type);
        }

        return $log;
    }

    private function recordCrawlerHit(AnalyticsVisitLog $log, ?string $name, string $family): void
    {
        try {
            AnalyticsCrawlerHit::query()->create([
                'tenant_id' => $log->tenant_id,
                'project_id' => $log->project_id,
                'article_id' => $log->article_id,
                'publishing_channel_id' => $log->publishing_channel_id,
                'bot_name' => $name ?? $family,
                'bot_family' => $family,
                'path' => $log->path,
                'ip_address' => $log->ip_address,
                'hit_at' => $log->created_at?->toDateString() ?? now()->toDateString(),
            ]);
        } catch (Throwable) {
            // 爬虫命中记录失败不影响主流程。
        }
    }
}
