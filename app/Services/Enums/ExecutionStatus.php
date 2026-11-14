<?php

namespace App\Services\Enums;

/**
 * Skill 执行状态枚举
 *
 * 对应 skill_executions 表的 status 字段。
 * 合法状态流转：pending → running → completed | failed | cancelled
 */
enum ExecutionStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case BillingPending = 'billing_pending';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => '等待中',
            self::Running => '执行中',
            self::BillingPending => '结果待核验',
            self::Completed => '已完成',
            self::Failed => '失败',
            self::Cancelled => '已取消',
        };
    }

    /**
     * 是否为终态（不可再变更）
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }

    /**
     * 是否可被取消
     */
    public function isCancellable(): bool
    {
        return in_array($this, [self::Pending, self::Running], true);
    }
}
