<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminActivityLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'admin_activity_logs';

    protected $fillable = [
        'tenant_id',
        'actor_id',
        'actor_type',
        'actor_name',
        'action',
        'request_method',
        'page',
        'target_type',
        'target_id',
        'ip_address',
        'details',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'actor_id' => 'integer',
            'target_id' => 'integer',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
