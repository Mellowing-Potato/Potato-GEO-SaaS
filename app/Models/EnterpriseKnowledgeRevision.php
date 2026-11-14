<?php

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseKnowledgeRevision extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'enterprise_knowledge_project_id',
        'content',
        'summary',
        'source',
        'created_by_user_id',
        'content_hash',
    ];

    protected $casts = [
        'enterprise_knowledge_project_id' => 'integer',
        'created_by_user_id' => 'integer',
    ];

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_AI = 'ai';
    public const SOURCE_SYSTEM = 'system';
    public const SOURCE_RESTORE = 'restore';
    public const SOURCE_PUBLISH = 'publish';

    public function project(): BelongsTo
    {
        return $this->belongsTo(EnterpriseKnowledgeProject::class, 'enterprise_knowledge_project_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
