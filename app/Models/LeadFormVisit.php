<?php

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 线索表单公开页访问记录（LeadFormVisitMiddleware 的落点）。
 * 旁路采集，不参与业务事务；404（表单不存在/未发布）时 lead_form_id 为 null。
 */
class LeadFormVisit extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'lead_form_id',
        'ip_address',
        'user_agent',
        'referer',
        'path',
        'method',
        'status_code',
    ];

    protected $attributes = [
        'ip_address' => '',
        'status_code' => 200,
    ];

    public function form(): BelongsTo
    {
        return $this->belongsTo(LeadForm::class, 'lead_form_id');
    }
}
