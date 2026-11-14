<?php

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 任务运行记录（阶段 1.4，对标 GEOFlow TaskRun）
 *
 * 每次任务触发写入一条，记录运行状态、技能、生成文章、触发来源与错误。
 * 状态机：pending → running → succeeded | failed。
 */
class TaskRun extends Model
{
    use BelongsToTenant, HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_BILLING_PENDING = 'billing_pending';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_SCHEDULE = 'schedule';

    protected $fillable = [
        'tenant_id',
        'task_id',
        'skill_id',
        'status',
        'trigger',
        'article_id',
        'error_message',
        'started_at',
        'finished_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'article_id' => 'integer',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected $appends = ['article_title'];

    // ─── 关联 ───
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class)->withDefault();
    }

    public function getArticleTitleAttribute(): ?string
    {
        return $this->article?->title;
    }

    // ─── 作用域 ───
    public function scopeSucceeded(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SUCCEEDED);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_SUCCEEDED, self::STATUS_FAILED], true);
    }
}
