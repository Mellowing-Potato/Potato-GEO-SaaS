<?php

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModelCallLog extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'project_id',
        'user_id',
        'skill_execution_id',
        'provider',
        'model',
        'status',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'duration_seconds',
        'metadata',
        'gateway_request_id',
    ];

    protected $casts = [
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'total_tokens' => 'integer',
        'duration_seconds' => 'float',
        'metadata' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function skillExecution(): BelongsTo
    {
        return $this->belongsTo(SkillExecution::class);
    }
}
