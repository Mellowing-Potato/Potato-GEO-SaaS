<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'subdomain',
        'name',
        'plan',
        'settings',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function aiKeys(): HasMany
    {
        return $this->hasMany(TenantAiKey::class);
    }

    /**
     * 获取租户的完整域名（如 workspace.example.test）
     */
    public function getDomainAttribute(): string
    {
        $base = config('app.tenant_domain');
        return "{$this->subdomain}.{$base}";
    }

    /**
     * 是否为活跃状态
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
