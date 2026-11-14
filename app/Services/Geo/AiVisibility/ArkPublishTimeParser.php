<?php

namespace App\Services\Geo\AiVisibility;

use Carbon\CarbonImmutable;

/**
 * 豆包 publish_time 中文日期解析器（批次二 · 单 3 硬要求 1）。
 *
 * 实测格式（spike 执行书 §10）：
 *   "2026年08月25日 00:00:00(CST) 星期二"
 *
 * 契约：
 *  - 解析失败一律返回 null（降级），**绝不让整条监测挂掉**；
 *  - 只接受明确的日期数字形态，不做「猜日期」（Carbon::parse 对垃圾输入的行为不可控）；
 *  - 兜底接受 ISO/常见写法（上游若改格式不至于全量降级）。
 */
final class ArkPublishTimeParser
{
    public function parse(?string $value): ?CarbonImmutable
    {
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        // 形态 1：中文格式「2026年08月25日 00:00:00(CST) 星期二」
        if (preg_match('/^(\d{4})年(\d{1,2})月(\d{1,2})日(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?/u', $trimmed, $m) === 1) {
            return $this->safeCreate(
                (int) $m[1],
                (int) $m[2],
                (int) $m[3],
                (int) ($m[4] ?? 0),
                (int) ($m[5] ?? 0),
                (int) ($m[6] ?? 0),
            );
        }

        // 形态 2：ISO / 常见西文写法「2026-08-25 00:00:00」「2026-08-25T00:00:00+08:00」
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})[T\s](\d{1,2}):(\d{2})(?::(\d{2}))?/', $trimmed, $m) === 1) {
            return $this->safeCreate(
                (int) $m[1],
                (int) $m[2],
                (int) $m[3],
                (int) $m[4],
                (int) $m[5],
                (int) ($m[6] ?? 0),
            );
        }

        return null;
    }

    /**
     * 用 checkdate + 范围校验创建；任何不合量纲的输入都返回 null，不抛异常。
     */
    private function safeCreate(int $year, int $month, int $day, int $hour, int $minute, int $second): ?CarbonImmutable
    {
        if ($year < 1900 || $year > 2100 || ! checkdate($month, $day, $year)) {
            return null;
        }
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 || $second < 0 || $second > 59) {
            return null;
        }

        try {
            return CarbonImmutable::create($year, $month, $day, $hour, $minute, $second) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
