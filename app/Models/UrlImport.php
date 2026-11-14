<?php

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * URL 文章导入任务（阶段 1.3）
 *
 * 一次「提交 URL → 抓取 → 转 Markdown → 生成草稿」的任务记录。
 * 状态：pending → processing → completed / failed。
 */
class UrlImport extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'project_id',
        'url',
        'title',
        'status',
        'article_id',
        'error_message',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'article_id' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(UrlImportLog::class);
    }
}
