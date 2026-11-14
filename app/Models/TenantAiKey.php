<?php

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class TenantAiKey extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'provider',
        'slot',
        'encrypted_key',
        'label',
        'is_active',
        'metadata',
    ];

    protected $hidden = ['encrypted_key'];

    protected function casts(): array
    {
        return [
            'encrypted_key' => 'encrypted',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /**
     * 获取解密后的 API Key（encrypted cast 已自动解密）
     */
    public function getDecryptedKey(): string
    {
        return $this->encrypted_key;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }
}
