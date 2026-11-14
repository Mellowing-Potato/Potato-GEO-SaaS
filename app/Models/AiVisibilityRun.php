<?php

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AI 可见性监测运行（一行 = 一次提问，见迁移注释）。
 */
class AiVisibilityRun extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'ai_visibility_runs';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_RECONCILIATION_REQUIRED = 'reconciliation_required';

    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_RECONCILIATION_REQUIRED,
    ];

    protected $fillable = [
        'tenant_id',
        'project_id',
        'user_id',
        'batch_id',
        'question_index',
        'question',
        'status',
        'provider',
        'model',
        'billing_model',
        'answer_text',
        'upstream_cost_fen',
        'prompt_tokens',
        'completion_tokens',
        'cached_tokens',
        'search_call_count',
        'points_charged_fen',
        'duration_seconds',
        'error_message',
        'metadata',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'question_index' => 'integer',
            'upstream_cost_fen' => 'float',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'cached_tokens' => 'integer',
            'search_call_count' => 'integer',
            'points_charged_fen' => 'float',
            'duration_seconds' => 'float',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(AiVisibilitySource::class, 'run_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    /**
     * 本次「搜索次数」是不是兜底估算的。
     *
     * 上游没返回 usage.tool_usage.web_search 时，归一化会按每问均值填一个数并打上这个标记
     * （见 AiVisibilityResultNormalizer::SEARCH_CALLS_PER_QUESTION_ESTIMATE）。
     * 标记必须跟着数字一路出到界面——否则编出来的 16 和实测的 16 在面板上长得一模一样。
     */
    public function searchCountEstimated(): bool
    {
        return ($this->metadata['search_count_estimated'] ?? false) === true;
    }

    /** @return list<string> 本问题触发的实际搜索词（AI 怎么找到你的证据） */
    public function searchQueries(): array
    {
        $queries = $this->metadata['search_queries'] ?? [];

        return is_array($queries) ? array_values(array_filter($queries, 'is_string')) : [];
    }
}
