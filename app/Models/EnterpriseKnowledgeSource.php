<?php

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseKnowledgeSource extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'enterprise_knowledge_project_id',
        'original_name',
        'file_path',
        'file_type',
        'source_url',
        'content',
        'character_count',
        'sort_order',
    ];

    protected $casts = [
        'enterprise_knowledge_project_id' => 'integer',
        'character_count' => 'integer',
        'sort_order' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(EnterpriseKnowledgeProject::class, 'enterprise_knowledge_project_id');
    }
}
