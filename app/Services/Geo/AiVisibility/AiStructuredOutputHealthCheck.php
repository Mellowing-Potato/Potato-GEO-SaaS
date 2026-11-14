<?php

namespace App\Services\Geo\AiVisibility;

use Illuminate\Support\Facades\Log;

/**
 * 结构化输出健康检查（批次二 · 单 3）。
 *
 * 一次「成功」的调用不等于一次「有价值」的调用：信源可能为 0、搜索词可能为空、
 * usage 可能缺失（→ 真实成本记 0，审计就断了）。这里把结构完整度显式判定并落日志，
 * 让「静默退化」在日志里有据可查，而不是等客户问「怎么没有信源」。
 */
final class AiStructuredOutputHealthCheck
{
    /**
     * @return array{
     *     healthy: bool,
     *     answer_present: bool,
     *     source_count: int,
     *     answer_citations_present: bool,
     *     search_query_count: int,
     *     usage_present: bool,
     *     warnings: list<string>,
     * }
     */
    public function evaluate(AiVisibilityResult $result): array
    {
        $warnings = [];

        $answerPresent = $result->answerText !== null && trim($result->answerText) !== '';
        if (! $answerPresent) {
            $warnings[] = 'answer_missing';
        }

        $sourceCount = count($result->sources);
        if ($sourceCount === 0) {
            $warnings[] = 'sources_empty';
        }

        $answerCitationsPresent = $answerPresent
            && preg_match('/\[\d+\]/u', (string) $result->answerText) === 1;
        if ($sourceCount > 0 && ! $answerCitationsPresent) {
            // 这是可见性监测结果的一部分，不把已完成运行判失败；但必须明确记录，
            // 避免把上游返回的检索参考误称为正文已经采用的引注。
            $warnings[] = 'answer_citation_markers_missing';
        }

        $searchQueryCount = count($result->searchQueries);
        if ($searchQueryCount === 0) {
            $warnings[] = 'search_queries_missing';
        }

        $usagePresent = $result->promptTokens > 0 || $result->completionTokens > 0;
        if (! $usagePresent) {
            $warnings[] = 'usage_missing';
        }

        $searchCountPositive = $result->searchCallCount > 0;
        if (! $searchCountPositive) {
            $warnings[] = 'search_call_count_zero';
        }

        // 豆包正常应同时给出：正文 + 信源 + 搜索词 + usage。缺任何一项都算不健康。
        $healthy = $answerPresent && $sourceCount > 0 && $searchQueryCount > 0 && $usagePresent;

        $report = [
            'healthy' => $healthy,
            'answer_present' => $answerPresent,
            'source_count' => $sourceCount,
            'answer_citations_present' => $answerCitationsPresent,
            'search_query_count' => $searchQueryCount,
            'usage_present' => $usagePresent,
            'warnings' => $warnings,
        ];

        if ($warnings !== []) {
            Log::warning('AiVisibility: structured output health check warnings.', [
                'source_count' => $sourceCount,
                'search_query_count' => $searchQueryCount,
                'usage_present' => $usagePresent,
                'search_call_count' => $result->searchCallCount,
                'warnings' => $warnings,
            ]);
        }

        return $report;
    }
}
