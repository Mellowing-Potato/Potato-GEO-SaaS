<?php

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 文章审核历史（对标 GEOFlow ArticleReview）
 *
 * 每次提交审核 / 通过 / 退回都会写一条记录，形成可回溯的审核流水。
 * 注意：与 GEOFlow 用 admin_id 不同，geo-saas 的审核人即租户 User。
 */
class ArticleReview extends Model
{
    use BelongsToTenant, HasFactory;

    public const UPDATED_AT = null;

    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_CHANGES_REQUESTED = 'changes_requested';
    public const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'tenant_id',
        'article_id',
        'reviewer_id',
        'review_status',
        'review_note',
    ];

    protected function casts(): array
    {
        return [
            'reviewer_id' => 'integer',
            'article_id' => 'integer',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
