<?php

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SkillExecution extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'project_id',
        'user_id',
        'skill_id',
        'skill_name',
        'category',
        'stage_index',
        'status',
        'execution_mode',
        'client_request_key',
        'input_data',
        'model_connection_snapshot',
        'gateway_requests',
        'output_data',
        'billing_pending_result',
        'quality_report',
        'article_id',
        'duration_seconds',
        'token_usage',
        'error_message',
    ];

    protected $hidden = ['gateway_requests', 'billing_pending_result', 'model_connection_snapshot'];

    protected $casts = [
        'gateway_requests' => 'array',
        'input_data' => 'array',
        'model_connection_snapshot' => 'array',
        'output_data' => 'array',
        'billing_pending_result' => 'encrypted:array',
        'quality_report' => 'array',
        'duration_seconds' => 'integer',
        'token_usage' => 'integer',
        'stage_index' => 'integer',
    ];

    // Relationships
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function modelCallLogs(): HasMany
    {
        return $this->hasMany(ModelCallLog::class);
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    // Scopes
    public function scopeForProject($query, int $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    public function scopeOfSkill($query, string $skillId)
    {
        return $query->where('skill_id', $skillId);
    }

    public function scopeOfStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    // Computed
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }
}
