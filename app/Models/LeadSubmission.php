<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted application interfaces, workspace ownership and execution behavior.
 * Upstream: app/Models/LeadSubmission.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadSubmission extends Model
{
    use BelongsToTenant;

    public const STATUS_NEW = 'new';

    public const STATUS_CONTACTED = 'contacted';

    public const STATUS_QUALIFIED = 'qualified';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_CONVERTED = 'converted';

    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_CONTACTED,
        self::STATUS_QUALIFIED,
        self::STATUS_INVALID,
        self::STATUS_CONVERTED,
    ];

    /** 表单没配通知地址，本条不需要通知 */
    public const NOTIFY_SKIPPED = 'skipped';

    /** 已入队，尚未出结果 */
    public const NOTIFY_QUEUED = 'queued';

    public const NOTIFY_SENT = 'sent';

    public const NOTIFY_FAILED = 'failed';

    protected $fillable = [
        'tenant_id',
        'lead_form_id',
        'status',
        'payload',
        'source_url',
        'ip_address',
        'user_agent',
        'note',
        'handled_by',
        'handled_at',
        'notify_status',
        'notify_error',
        'notified_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_NEW,
        'ip_address' => '',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'handled_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(LeadForm::class, 'lead_form_id');
    }

    /**
     * 处理人。本仓用 users 表（上游是 admins，本仓无此表，见执行书 §二-2）。
     */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
