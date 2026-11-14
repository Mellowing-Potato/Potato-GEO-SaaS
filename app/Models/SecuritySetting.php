<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SecuritySetting extends Model
{
    use HasFactory;

    protected $table = 'security_settings';

    protected $fillable = [
        'tenant_id',
        'settings',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'tenant_id' => 'integer',
            'updated_by_user_id' => 'integer',
        ];
    }

    /**
     * 平台级默认安全设置（tenant_id 为 null 的那一行）。
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'password_min_length' => 8,
            'require_special_char' => false,
            'api_rate_limit_per_min' => 60,
            'sensitive_words' => [],
        ];
    }
}
