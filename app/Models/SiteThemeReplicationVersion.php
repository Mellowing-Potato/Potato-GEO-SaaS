<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted application interfaces, workspace ownership and execution behavior.
 * Upstream: app/Models/SiteThemeReplicationVersion.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteThemeReplicationVersion extends Model
{
    use BelongsToTenant;

    /**
     * ⚠️ tenant_id 故意不在 fillable 里（防批量赋值改租户），
     * 创建时由 BelongsToTenant 的 creating hook 自动填。
     *
     * bytes / package_path 是 geo-saas 新增的（上游没有）：
     * - bytes：单租户配额要按字节统计，每次生成记下来，清理任务才知道该回收多少
     * - package_path：zip 的相对路径，下载端点与清理任务都要用它定位文件
     */
    protected $fillable = [
        'replication_id',
        'version',
        'prompt_hash',
        'feedback',
        'blueprint_json',
        'files_json',
        'compliance_report_json',
        'draft_views_path',
        'draft_assets_path',
        'bytes',
        'package_path',
    ];

    protected function casts(): array
    {
        return [
            'replication_id' => 'integer',
            'version' => 'integer',
            'blueprint_json' => 'array',
            'files_json' => 'array',
            'compliance_report_json' => 'array',
            'bytes' => 'integer',
        ];
    }

    public function replication(): BelongsTo
    {
        return $this->belongsTo(SiteThemeReplication::class, 'replication_id');
    }
}
