<?php

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * 提示词覆盖（阶段 1.2）
 *
 * 每个租户对每个技能（skill_id 字符串 slug，如 yao-geo-panorama-audit）最多一条覆盖。
 * 技能执行时优先使用 prompt_text，无覆盖则回退 yao-geo-skills 资产。
 */
class Prompt extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'skill_prompts';

    protected $fillable = [
        'tenant_id',
        'skill_id',
        'prompt_text',
        'is_active',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }
}
