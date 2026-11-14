<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 分段报告接力执行的中间状态。
 *
 * ⚠️ 故意不挂 TenantScope。本表只被队列任务按 skill_execution_id 精确查一行，
 *    而那个 id 是从已经过租户作用域的 skill_executions 里拿到的 —— 租户隔离在上游
 *    就已经做完了。挂上反而多一层「调度器里没有 TenantContext 就查不到」的坑
 *    （ReapStuckSkillExecutions 为此要处处 withoutGlobalScope）。
 *    tenant_id 仍然存，是为了排障时能直接按租户捞，以及租户删除时级联清干净。
 */
class SkillExecutionRelay extends Model
{
    protected $fillable = [
        'skill_execution_id',
        'tenant_id',
        'total_steps',
        'completed_steps',
        'state',
    ];

    protected $casts = [
        'total_steps' => 'integer',
        'completed_steps' => 'integer',
        'state' => 'array',
    ];

    public function execution(): BelongsTo
    {
        return $this->belongsTo(SkillExecution::class, 'skill_execution_id');
    }
}
