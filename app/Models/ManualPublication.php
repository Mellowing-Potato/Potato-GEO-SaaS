<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted application interfaces, workspace ownership and execution behavior.
 * Upstream: app/Models/ManualPublication.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Models;

use App\Services\Publishing\ArticleRiskScanner;
use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ManualPublication extends Model
{
    use BelongsToTenant;

    public const TYPE_POST = 'post';

    public const TYPE_COMMENT = 'comment';

    public const TYPES = [self::TYPE_POST, self::TYPE_COMMENT];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_READY = 'ready';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_READY,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_SKIPPED,
        self::STATUS_CANCELLED,
    ];

    public const REOPENABLE_STATUSES = [
        self::STATUS_FAILED,
        self::STATUS_SKIPPED,
        self::STATUS_CANCELLED,
    ];

    public const MAX_CONTENT_CHARACTERS = 2000;

    public const MAX_POST_CONTENT_CHARACTERS = 20000;

    protected $attributes = [
        'type' => self::TYPE_POST,
        'status' => self::STATUS_DRAFT,
        'risk_status' => 'clean',
        'duplicate_warning_count' => 0,
        'revision' => 1,
    ];

    protected $fillable = [
        // tenant_id 必须可填：无租户上下文场景（如服务层直调）显式传入时，
        // 不在 fillable 会被批量赋值静默丢弃 → NOT NULL 约束失败（AiVisibilityRun 同款惯例）
        'tenant_id',
        'type',
        'article_id',
        'persona_id',
        'account_id',
        'assigned_user_id',
        'created_by_user_id',
        'platform',
        'custom_platform',
        'target_url',
        'target_url_hash',
        'target_context',
        'content',
        'content_fingerprint',
        'source_snapshot',
        'identity_snapshot',
        'disclosure_snapshot',
        'risk_status',
        'risk_result',
        'duplicate_warning_count',
        'scheduled_at',
        'status',
        'status_changed_at',
        'completion_url',
        'draft_url',
        'draft_synced_at',
        'draft_content_fingerprint',
        'result_note',
        'completed_at',
        'revision',
    ];

    protected function casts(): array
    {
        return [
            'article_id' => 'integer',
            'persona_id' => 'integer',
            'account_id' => 'integer',
            'assigned_user_id' => 'integer',
            'created_by_user_id' => 'integer',
            'source_snapshot' => 'array',
            'identity_snapshot' => 'array',
            'risk_result' => 'array',
            'duplicate_warning_count' => 'integer',
            'scheduled_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'completed_at' => 'datetime',
            'draft_synced_at' => 'datetime',
            'draft_receipt' => 'array',
            'revision' => 'integer',
        ];
    }

    /**
     * 租户侧序列化（toArray / JSON 响应）时剔除 origin=platform 的命中明细，
     * 平台红线词的 word / snippet / suggestion 绝不给租户看见（执行书：平台词不给租户看见）。
     *
     * 仅作用于序列化层——不动存储值，也不改 risk_result 访问器：
     * ManualPublicationService 在 transition 到 ready 时仍走访问器读原始 risk_result
     * 跑 assertRiskNotBlocked（hasBlockedPlatformMatch），若在这里用访问器过滤会静默废掉阻断判断。
     * 收在模型上而非控制器各出口，是为了覆盖所有序列化出口（含未来新增出口），不必逐处手写。
     */
    public function toArray(): array
    {
        $array = parent::toArray();

        if (isset($array['risk_result']['matches']) && is_array($array['risk_result']['matches'])) {
            $array['risk_result']['matches'] = array_values(array_filter(
                $array['risk_result']['matches'],
                static fn (array $match): bool => ($match['origin'] ?? 'tenant') !== ArticleRiskScanner::ORIGIN_PLATFORM,
            ));
        }

        return $array;
    }

    public function article(): BelongsTo
    {
        // 已删除文章不应通过发布单关联重新暴露；需要保留正文时从回收站恢复文章。
        return $this->belongsTo(Article::class, 'article_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(ManualPublicationPersona::class, 'persona_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ManualPublicationAccount::class, 'account_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(ManualPublicationTransition::class);
    }

    public function personaDisplayName(): ?string
    {
        $snapshotName = trim((string) data_get($this->identity_snapshot, 'persona.name'));

        return $snapshotName !== '' ? $snapshotName : $this->persona?->name;
    }

    public function accountDisplayName(): ?string
    {
        $snapshotName = trim((string) data_get($this->identity_snapshot, 'account.account_name'));

        return $snapshotName !== '' ? $snapshotName : $this->account?->account_name;
    }

    /**
     * 租户内可见性：admin 看全部，否则只看指派给自己的。
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role === 'admin') {
            return $query;
        }

        return $query->where('assigned_user_id', $user->getKey());
    }

    /** @return list<string> */
    public static function allowedNextStatuses(string $status): array
    {
        return match ($status) {
            self::STATUS_DRAFT => [self::STATUS_READY, self::STATUS_CANCELLED],
            self::STATUS_READY => [self::STATUS_IN_PROGRESS, self::STATUS_CANCELLED],
            self::STATUS_IN_PROGRESS => [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_SKIPPED, self::STATUS_CANCELLED],
            self::STATUS_FAILED, self::STATUS_SKIPPED, self::STATUS_CANCELLED => [self::STATUS_READY],
            default => [],
        };
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::allowedNextStatuses((string) $this->status), true);
    }

    public function isReopenTransition(string $status): bool
    {
        return in_array((string) $this->status, self::REOPENABLE_STATUSES, true)
            && $status === self::STATUS_READY;
    }

    public function platformDisplayName(): string
    {
        return $this->platform === ManualPublicationAccount::PLATFORM_CUSTOM
            ? (string) ($this->custom_platform ?: __('admin.manual_publications.platform.custom'))
            : (string) __('admin.manual_publications.platform.'.$this->platform);
    }

    public static function maxContentCharactersForType(string $type): int
    {
        return $type === self::TYPE_POST ? self::MAX_POST_CONTENT_CHARACTERS : self::MAX_CONTENT_CHARACTERS;
    }
}
