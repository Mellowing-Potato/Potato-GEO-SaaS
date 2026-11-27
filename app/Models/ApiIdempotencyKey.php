<?php

namespace App\Models;

use App\Casts\EncryptedReplayBody;
use Illuminate\Database\Eloquent\Model;

class ApiIdempotencyKey extends Model
{
    protected $hidden = ['model_checkpoint', 'response_body'];
    protected $table = 'api_idempotency_keys';

    protected $fillable = [
        'idempotency_key',
        'route_key',
        'request_hash',
        'response_body',
        'response_status',
        'tenant_id',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'response_body' => EncryptedReplayBody::class,
            'model_checkpoint' => 'encrypted:array',
            'response_status' => 'integer',
            'tenant_id' => 'integer',
            'user_id' => 'integer',
        ];
    }
}
