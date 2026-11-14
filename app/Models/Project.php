<?php

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    public const GEO_ANALYSIS_SKILL_ID = 'yao-geo-panorama-audit';

    protected $fillable = [
        'name',
        'brand_name',
        'brand_url',
        'current_stage',
        'progress',
        'status',
        'settings',
        'owner_id',
    ];

    protected function casts(): array
    {
        return [
            'current_stage' => 'integer',
            'progress' => 'integer',
            'settings' => 'array',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function stages(): HasMany
    {
        return $this->hasMany(ProjectStage::class)->orderBy('stage_index');
    }

    public function skillExecutions(): HasMany
    {
        return $this->hasMany(SkillExecution::class);
    }

    public function analyses(): HasMany
    {
        return $this->skillExecutions()
            ->where('skill_id', self::GEO_ANALYSIS_SKILL_ID)
            ->latest('id');
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class)->latest('id');
    }

    /**
     * 六阶段定义
     */
    public const STAGES = [
        ['key' => 'research',     'name' => '研究拓词', 'index' => 0],
        ['key' => 'strategy',     'name' => '策略诊断', 'index' => 1],
        ['key' => 'production',   'name' => '内容生产', 'index' => 2],
        ['key' => 'distribution', 'name' => '渠道发布', 'index' => 3],
        ['key' => 'monitoring',   'name' => '效果监测', 'index' => 4],
        ['key' => 'iteration',    'name' => '迭代优化', 'index' => 5],
    ];

    /**
     * 获取当前阶段名称
     */
    public function getCurrentStageNameAttribute(): string
    {
        return self::STAGES[$this->current_stage]['name'] ?? '未知';
    }

    /**
     * 获取当前阶段标识
     */
    public function getCurrentStageKeyAttribute(): string
    {
        return self::STAGES[$this->current_stage]['key'] ?? 'unknown';
    }
}
