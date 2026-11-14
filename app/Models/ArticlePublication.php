<?php

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticlePublication extends Model
{
    use BelongsToTenant, HasFactory;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_PUBLISHING = 'publishing';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_FAILED = 'failed';
    /**
     * 已撤稿（计划 §9.5）：外站那篇已经下线。与 failed 的区别是——
     * failed 是"没发出去/发失败了"，takedown 是"曾经发出去、现在主动撤了"，
     * 本地远程地址仍然保留，供事后核查。
     */
    public const STATUS_TAKEDOWN = 'takedown';

    protected $fillable = [
        'tenant_id',
        'article_id',
        'publishing_channel_id',
        'requested_by',
        'status',
        'attempt_count',
        'remote_id',
        'remote_url',
        'last_error',
        'last_attempted_at',
        'published_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer',
            'last_attempted_at' => 'datetime',
            'published_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(PublishingChannel::class, 'publishing_channel_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
