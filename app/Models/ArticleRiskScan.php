<?php

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 文章风险扫描落库结果（单 7 复刻 ArticleRiskScanner 的持久化依赖）。
 *
 * 上游 ArticleRiskScanner::record()/isFresh() 写入并读取此表。本仓没有 ArticleRiskScan，
 * 单 7 执行书 §一-1 要求把 ArticleRiskScanner「整个迁」，故一并补上。
 */
class ArticleRiskScan extends Model
{
    use BelongsToTenant;

    /**
     * 评审项 6：article_risk_scans 建表只有 scanned_at、没有 created_at/updated_at 列。
     * 关掉自动 timestamps，否则 record() 落库时 Eloquent 会尝试写 created_at 而 SQL 报
     * 「table article_risk_scans has no column named created_at」。扫描时间语义由 scanned_at 承担。
     */
    public $timestamps = false;

    public const UPDATED_AT = null;

    protected $fillable = [
        // tenant_id 显式传入时须可填（服务层无租户上下文直调场景），缺了会被静默丢弃
        'tenant_id',
        'article_id',
        'user_id',
        'trigger',
        'content_hash',
        'dictionary_hash',
        'status',
        'match_count',
        'matches',
        'scanned_at',
    ];

    protected function casts(): array
    {
        return [
            'article_id' => 'integer',
            'user_id' => 'integer',
            'match_count' => 'integer',
            'matches' => 'array',
            'scanned_at' => 'datetime',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
