<?php

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * 自动化任务定义（阶段 1.4，对标 GEOFlow Task / TaskSchedule）
 *
 * 一个任务绑定「技能 + 项目 + 节奏 + 发布/分发开关」，到点后由 tasks:tick
 * 派发 RunTaskJob，串联：技能执行 → 生成草稿 →（可选）发布 →（可选）分发。
 */
class Task extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';

    public const CADENCE_HOURLY = 'hourly';
    public const CADENCE_DAILY = 'daily';
    public const CADENCE_WEEKLY = 'weekly';
    public const CADENCE_MONTHLY = 'monthly';

    public const DEFAULT_TIMEZONE = 'Asia/Shanghai';

    protected $fillable = [
        'tenant_id',
        'project_id',
        'user_id',
        'name',
        'skill_id',
        'cadence',
        'timezone',
        'query',
        'input_data',
        'auto_publish',
        'auto_distribute',
        'status',
        'last_run_at',
        'next_run_at',
    ];

    protected function casts(): array
    {
        return [
            'input_data' => 'array',
            'auto_publish' => 'boolean',
            'auto_distribute' => 'boolean',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    // ─── 关联 ───
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withDefault();
    }

    public function runs(): HasMany
    {
        return $this->hasMany(TaskRun::class)->orderByDesc('id');
    }

    // ─── 作用域 ───
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->active()
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now());
    }

    /**
     * 计算下一次运行时间（基于 cadence + timezone）。
     *
     * 纯函数，供「任务创建 / 恢复 / 调度」复用，避免重复逻辑。
     */
    public static function computeNextRunAtFor(string $cadence, ?string $timezone = null): Carbon
    {
        $base = now()->setTimezone($timezone ?: self::DEFAULT_TIMEZONE);

        return match ($cadence) {
            self::CADENCE_HOURLY => $base->copy()->addHour(),
            self::CADENCE_WEEKLY => $base->copy()->addWeek(),
            self::CADENCE_MONTHLY => $base->copy()->addMonth(),
            default => $base->copy()->addDay(),
        };
    }

    /**
     * 该任务当前是否到点（可被调度派发）。
     */
    public function isDue(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->next_run_at !== null
            && $this->next_run_at->lessThanOrEqualTo(now());
    }
}
