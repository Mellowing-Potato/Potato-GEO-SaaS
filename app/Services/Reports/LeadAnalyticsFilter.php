<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted application interfaces, workspace ownership and execution behavior.
 * Upstream: app/Services/Admin/Analytics/LeadAnalyticsFilter.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Services\Reports;

use App\Models\LeadSubmission;
use Illuminate\Support\Carbon;

/**
 * 线索转化统计筛选条件（单 8）。
 *
 * 照抄上游 LeadAnalyticsFilter，但去掉了对 AnalyticsDateRange 的依赖（本仓无该类）：
 * 日期归一在 resolveDates 内联完成（保证 from <= to，且都不晚于今天）。
 */
class LeadAnalyticsFilter
{
    /** @param array<string, mixed> $input */
    public static function fromRequest(array $input): self
    {
        $hasDateInput = trim((string) ($input['lead_date_from'] ?? '')) !== ''
            || trim((string) ($input['lead_date_to'] ?? '')) !== '';
        $requestedPreset = (string) ($input['lead_preset'] ?? ($hasDateInput ? 'custom' : '30d'));
        $preset = in_array($requestedPreset, ['7d', '30d', '90d', 'custom'], true)
            ? $requestedPreset
            : '30d';
        [$dateFrom, $dateTo] = self::resolveDates($input, $preset);
        $formId = filter_var($input['lead_form_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $status = (string) ($input['lead_status'] ?? 'all');
        if (! in_array($status, ['all', ...LeadSubmission::STATUSES], true)) {
            $status = 'all';
        }

        return new self($preset, $dateFrom, $dateTo, $formId === false ? null : (int) $formId, $status);
    }

    public function __construct(
        public readonly string $preset,
        public readonly Carbon $dateFrom,
        public readonly Carbon $dateTo,
        public readonly ?int $formId,
        public readonly string $status,
    ) {}

    public function start(): Carbon
    {
        return $this->dateFrom->copy()->startOfDay();
    }

    public function end(): Carbon
    {
        return $this->dateTo->copy()->endOfDay();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'lead_preset' => $this->preset,
            'lead_date_from' => $this->dateFrom->toDateString(),
            'lead_date_to' => $this->dateTo->toDateString(),
            'lead_form_id' => $this->formId,
            'lead_status' => $this->status,
        ];
    }

    /** @param array<string, mixed> $input @return array{0: Carbon, 1: Carbon} */
    private static function resolveDates(array $input, string &$preset): array
    {
        $today = Carbon::today();
        $presetDays = ['7d' => 7, '30d' => 30, '90d' => 90];
        if (isset($presetDays[$preset])) {
            return [$today->copy()->subDays($presetDays[$preset] - 1), $today->copy()];
        }

        $preset = 'custom';
        $from = self::parseDate((string) ($input['lead_date_from'] ?? '')) ?? $today->copy()->subDays(29);
        $to = self::parseDate((string) ($input['lead_date_to'] ?? '')) ?? $today->copy();

        // 本地归一：保证 from <= to，且都钳制到今天及之前（防前端传未来日期把区间撑爆）。
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        if ($to->greaterThan($today)) {
            $to = $today->copy();
        }

        if ($from->greaterThan($today)) {
            $from = $today->copy();
        }

        // 评审修复 H3：下界钳制。此前只钳上界没钳下界——lead_date_from=1900-01-01 会让
        // trend 按天生成 4 万+ 个点、响应 MB 级。自定义区间最长回看 366 天。
        $earliestFrom = $today->copy()->subDays(365);
        if ($from->lessThan($earliestFrom)) {
            $from = $earliestFrom;
        }

        return [$from, $to];
    }

    private static function parseDate(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);

            return $date->toDateString() === $value ? $date : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
