<?php

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class ModelConnectionProfile extends Model
{
    use BelongsToTenant;

    public const MODEL_ID_LIMIT = 512;

    protected $fillable = [
        'tenant_id', 'slot', 'provider', 'label', 'target_alias', 'target_revision',
        'base_url', 'model', 'auth_mode', 'credential', 'options', 'revision', 'enabled',
    ];

    protected $hidden = ['credential', 'target_revision'];

    protected function casts(): array
    {
        return [
            'credential' => 'encrypted',
            'options' => 'array',
            'revision' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    public static function validModelId(mixed $id): bool
    {
        return is_string($id) && trim($id) === $id && mb_strlen($id) <= self::MODEL_ID_LIMIT
            && preg_match('/^[^\p{C}]+$/uD', $id) === 1;
    }
}
