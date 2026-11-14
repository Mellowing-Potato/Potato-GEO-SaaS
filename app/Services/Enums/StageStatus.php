<?php

namespace App\Services\Enums;

/**
 * 阶段状态
 *
 * 对应 project_stages 表的 status 字段。
 */
enum StageStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Completed = 'completed';
    case Skipped = 'skipped';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => '待开始',
            self::Active => '进行中',
            self::Completed => '已完成',
            self::Skipped => '已跳过',
            self::Failed => '失败',
        };
    }

    /**
     * 是否为终态（不可再变更）
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Skipped], true);
    }
}
