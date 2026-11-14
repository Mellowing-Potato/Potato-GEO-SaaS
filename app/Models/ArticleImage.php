<?php

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 文章编辑器素材图片引用（对标 GEOFlow ArticleImage，桥接 Article ↔ ImageLibrary）
 *
 * 编辑器侧栏从素材库插入图片时写入一条引用；与 1.1 图片库打通。
 */
class ArticleImage extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'article_id',
        'image_library_id',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'article_id' => 'integer',
            'image_library_id' => 'integer',
            'position' => 'integer',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_id');
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(ImageLibrary::class, 'image_library_id');
    }
}
