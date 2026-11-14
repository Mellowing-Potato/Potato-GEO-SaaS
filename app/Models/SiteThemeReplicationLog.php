<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted application interfaces, workspace ownership and execution behavior.
 * Upstream: app/Models/SiteThemeReplicationLog.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteThemeReplicationLog extends Model
{
    use BelongsToTenant;

    /**
     * ⚠️ tenant_id 故意不在 fillable 里（防批量赋值改租户），
     * 创建时由 BelongsToTenant 的 creating hook 自动填。
     * Job 里创建日志时没有 HTTP 上下文，必须显式传入 —— 见 T4。
     */
    protected $fillable = [
        'replication_id',
        'level',
        'step',
        'message',
        'context_json',
    ];

    protected function casts(): array
    {
        return [
            'replication_id' => 'integer',
            'context_json' => 'array',
        ];
    }

    public function replication(): BelongsTo
    {
        return $this->belongsTo(SiteThemeReplication::class, 'replication_id');
    }
}
