<?php

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeBase extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'project_id',
        'name',
        'description',
        'status',
        'settings',
    ];

    protected $casts = [
        'settings' => 'array',
        'project_id' => 'integer',
    ];

    // Relationships
    public function documents(): HasMany
    {
        return $this->hasMany(KnowledgeDocument::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    // Methods
    public function documentCount(): int
    {
        return $this->documents()->count();
    }
}
