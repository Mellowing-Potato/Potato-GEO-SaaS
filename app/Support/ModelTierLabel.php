<?php

namespace App\Support;

/** Display labels for internal slots and model names in usage reports. */
final class ModelTierLabel
{
    private const LABELS = [
        'text' => 'GEO 文本',
        'vision' => 'GEO 视觉',
        'embedding' => 'GEO 知识检索',
    ];

    /**
     * 未登记的档位一律回「其他」，绝不把原始 slug 漏出去——
     * 新增档位忘了登记时，宁可显示模糊也不泄露内部命名。
     */
    public static function for(?string $model): string
    {
        $key = strtolower(trim((string) $model));

        return self::LABELS[$key] ?? match ($key) {
            'geo-write' => 'GEO 写作（历史记录）',
            'geo-reason' => 'GEO 分析（历史记录）',
            'geo-plus' => 'GEO 增强（历史记录）',
            'geo-max' => 'GEO 旗舰（历史记录）',
            default => trim((string) $model) !== '' ? trim((string) $model) : '未配置',
        };
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        return self::LABELS;
    }
}
