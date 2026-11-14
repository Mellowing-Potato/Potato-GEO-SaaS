<?php

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualPublicationTransition extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = [
        // tenant_id 显式传入时须可填（服务层无租户上下文直调场景），缺了会被静默丢弃
        'tenant_id',
        'manual_publication_id',
        'changed_by_user_id',
        'from_status',
        'to_status',
        'completion_url',
        'result_note',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'manual_publication_id' => 'integer',
            'changed_by_user_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(ManualPublication::class, 'manual_publication_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
