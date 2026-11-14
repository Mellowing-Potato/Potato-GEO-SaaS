<?php

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * URL 文章导入任务日志（阶段 1.3）
 *
 * 导入过程的步骤与失败原因，供前端追溯（非阻塞）。
 */
class UrlImportLog extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'url_import_job_logs';

    protected $fillable = [
        'tenant_id',
        'url_import_id',
        'level',
        'message',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }

    public function urlImport(): BelongsTo
    {
        return $this->belongsTo(UrlImport::class);
    }
}
