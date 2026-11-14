<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted application interfaces, workspace ownership and execution behavior.
 * Upstream: app/Models/ManualPublicationPersona.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ManualPublicationPersona extends Model
{
    use BelongsToTenant;

    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        // tenant_id 显式传入时须可填（服务层无租户上下文直调场景），缺了会被静默丢弃
        'tenant_id',
        'name',
        'bio',
        'tone',
        'domain',
        'disclosure_text',
        'is_active',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'created_by_user_id' => 'integer',
        ];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(ManualPublicationAccount::class, 'persona_id');
    }

    public function publications(): HasMany
    {
        return $this->hasMany(ManualPublication::class, 'persona_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
