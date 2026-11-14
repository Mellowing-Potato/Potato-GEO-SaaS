<?php

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 文章版本快照（对标 GEOFlow 轻量版本，geo-saas 自管）
 *
 * 每次正文变更或发布时自动快照当前可编辑字段，支持回退到任意历史版本。
 * 与 Article.version 整数递增配合：快照行的 version 即当时的版本号。
 */
class ArticleVersion extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'article_id',
        'version',
        'title',
        'body_markdown',
        'summary',
        'content_type',
        'category',
        'category_id',
        'seo_title',
        'seo_description',
        'canonical_url',
        'og_title',
        'og_description',
        'og_image',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'article_id' => 'integer',
            'category_id' => 'integer',
            'created_by_user_id' => 'integer',
            'version' => 'integer',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
