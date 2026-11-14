<?php

namespace App\Services\Geo;

use App\Services\DTOs\SkillExecutionResult;

class GeoAnalysisSummaryExtractor
{
    private const EXPECTED_SCORE_MAX = 5.0;
    private const TARGET_SCALE = 100;

    public function extract(?array $structured, SkillExecutionResult $result): array
    {
        if (! $structured) {
            $intermediateData = is_array($result->intermediateData) ? $result->intermediateData : [];

            return [
                'summary' => mb_substr($this->stringItem($intermediateData['raw_output'] ?? ''), 0, 500),
                'key_findings' => [],
                'recommendations' => [],
                'next_actions' => [],
                'overall_score' => null,
            ];
        }

        return [
            'summary' => $this->extractSummary($structured),
            'key_findings' => $this->extractFindings($structured),
            'recommendations' => $this->extractRecommendations($structured),
            'next_actions' => $this->extractActions($structured),
            'overall_score' => $this->extractOverallScore($structured),
        ];
    }

    private function extractSummary(array $structured): string
    {
        foreach (['summary_text', 'executive_summary', 'conclusion', 'notice'] as $key) {
            $text = $this->stringItem($structured[$key] ?? null);
            if ($text !== '') {
                return mb_substr($text, 0, 800);
            }
        }

        $summary = $structured['summary'] ?? null;
        if (is_string($summary)) {
            return mb_substr(trim($summary), 0, 800);
        }

        if (is_array($summary)) {
            $lines = [];
            foreach (array_slice($summary, 0, 4) as $row) {
                $line = $this->rowText($row);
                if ($line !== '') {
                    $lines[] = $line;
                }
            }

            if ($lines !== []) {
                return mb_substr(implode('；', $lines), 0, 800);
            }
        }

        $brandName = $this->stringItem($structured['brand_name'] ?? '');

        return $brandName !== ''
            ? "{$brandName} 的综合诊断已完成，建议优先查看关键发现和行动建议。"
            : '综合诊断已完成，建议优先查看关键发现和行动建议。';
    }

    /**
     * @return list<string>
     */
    private function extractFindings(array $structured): array
    {
        return $this->collectRows($structured, [
            'question_coverage',
            'baseline',
            'technical_audit',
            'competitors',
            'risk_register',
        ], 6);
    }

    /**
     * @return list<string>
     */
    private function extractRecommendations(array $structured): array
    {
        return $this->collectRows($structured, [
            'opportunities',
            'repairs',
            'offsite_plan',
        ], 6);
    }

    /**
     * @return list<string>
     */
    private function extractActions(array $structured): array
    {
        $actions = $this->collectRows($structured, [
            'repairs',
            'opportunities',
            'offsite_plan',
        ], 5);

        if ($actions !== []) {
            return $actions;
        }

        $conclusion = $this->stringItem($structured['conclusion'] ?? '');

        return $conclusion !== '' ? [$conclusion] : [];
    }

    private function extractOverallScore(array $structured): ?int
    {
        $direct = $structured['overall_score'] ?? $structured['score'] ?? null;
        if (is_numeric($direct)) {
            return max(0, min(100, (int) round((float) $direct)));
        }

        if (empty($structured['scores']) || ! is_array($structured['scores'])) {
            return null;
        }

        $numericScores = [];
        foreach ($structured['scores'] as $row) {
            $scoreValue = is_array($row)
                ? ($row[1] ?? $row['score'] ?? null)
                : $row;

            if (preg_match('/(\d+(?:\.\d+)?)/', (string) $scoreValue, $matches)) {
                $numericScores[] = (float) $matches[1];
            }
        }

        if ($numericScores === []) {
            return null;
        }

        $average = array_sum($numericScores) / count($numericScores);
        $maxObserved = max($numericScores);
        $scaleFactor = $maxObserved > self::EXPECTED_SCORE_MAX
            ? self::TARGET_SCALE / $maxObserved
            : self::TARGET_SCALE / self::EXPECTED_SCORE_MAX;

        return max(0, min(100, (int) round($average * $scaleFactor)));
    }

    /**
     * @param  list<string> $keys
     * @return list<string>
     */
    private function collectRows(array $structured, array $keys, int $limit): array
    {
        $items = [];

        foreach ($keys as $key) {
            $rows = $structured[$key] ?? [];
            if (! is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                $text = $this->rowText($row);
                if ($text !== '') {
                    $items[] = $text;
                }

                if (count($items) >= $limit) {
                    return array_values(array_unique($items));
                }
            }
        }

        return array_values(array_unique($items));
    }

    private function rowText(mixed $row): string
    {
        if (is_string($row) || is_numeric($row)) {
            return trim((string) $row);
        }

        if (! is_array($row)) {
            return '';
        }

        $values = array_is_list($row) ? $row : array_values($row);
        $parts = array_values(array_filter(array_map(
            fn (mixed $value): string => $this->stringItem($value),
            $values,
        ), fn (string $value): bool => $value !== ''));

        return implode('；', array_slice($parts, 0, 4));
    }

    private function stringItem(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value) || is_numeric($value) || is_bool($value)) {
            return trim((string) $value);
        }

        if (! is_array($value)) {
            return '';
        }

        if (array_is_list($value)) {
            return implode('；', array_values(array_filter(array_map(
                fn (mixed $item): string => $this->stringItem($item),
                $value,
            ), fn (string $item): bool => $item !== '')));
        }

        return implode('；', array_values(array_filter(array_map(
            fn (string|int $key, mixed $item): string => $this->stringItem($item),
            array_keys($value),
            $value,
        ), fn (string $item): bool => $item !== '')));
    }
}
