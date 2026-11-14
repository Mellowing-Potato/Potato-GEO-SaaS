<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted application interfaces, workspace ownership and execution behavior.
 * Upstream: app/Models/WorkerHeartbeat.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 平台级 worker 心跳表（单 9）。
 *
 * ⚠️ 同 PlatformSensitiveWord：绝对不许 use BelongsToTenant，也不许挂 TenantScope。
 * 这是平台运维数据，没有 tenant_id 列；一旦挂上租户作用域，TenantScope 会往查询里塞
 * `where tenant_id = ?`，字段不存在 → 直接 SQL 报错；而 tenant_id 为 null 时又 fail-open
 * 静默放行——两种结果都等于「心跳读不出来」，看板变空白。本模型存在的前提就是它全平台共享。
 *
 * 字段名与上游 reference/.../app/Models/WorkerHeartbeat.php 保持一致，便于日后对照。
 */
class WorkerHeartbeat extends Model
{
    protected $table = 'worker_heartbeats';

    protected $primaryKey = 'worker_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'worker_id',
        'status',
        'last_seen_at',
        'meta',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'meta' => 'array',
    ];
}
