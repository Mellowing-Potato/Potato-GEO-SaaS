<?php

namespace App\Services\Content;

use App\Models\Prompt;
use App\Services\SkillAgent\SkillAssets;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Collection;

/**
 * 提示词模板服务（阶段 1.2，对标 GEOFlow Prompt + AiPrompt/AiSpecialPrompt，按 D-3 不复发多 provider 管理台）
 *
 * 为每个技能维护「租户级提示词覆盖」。技能执行时优先使用覆盖提示词，
 * 未覆盖则回退 yao-geo-skills 资产（SKILL.md）。全部受 BelongsToTenant 作用域自动隔离。
 */
class PromptTemplateService
{
    /**
     * 取当前租户对某技能的生效覆盖提示词；无则返回 null（回退默认资产）。
     */
    public function getEffective(string $skillId): ?string
    {
        $override = Prompt::query()
            ->where('skill_id', $skillId)
            ->where('is_active', true)
            ->first();

        return $override?->prompt_text;
    }

    /**
     * 列出当前租户的全部提示词覆盖。
     */
    public function listForTenant(): Collection
    {
        return Prompt::query()->orderBy('skill_id')->get();
    }

    /**
     * 新建或更新某技能的提示词覆盖（按 tenant + skill 唯一）。更新时版本自增。
     */
    public function upsert(string $skillId, string $promptText, bool $isActive = true): Prompt
    {
        $tenantId = TenantContext::id();

        $prompt = Prompt::query()
            ->where('tenant_id', $tenantId)
            ->where('skill_id', $skillId)
            ->first();

        if ($prompt === null) {
            return Prompt::create([
                'tenant_id' => $tenantId,
                'skill_id' => $skillId,
                'prompt_text' => $promptText,
                'is_active' => $isActive,
                'version' => 1,
            ]);
        }

        $prompt->update([
            'prompt_text' => $promptText,
            'is_active' => $isActive,
            'version' => $prompt->version + 1,
        ]);

        return $prompt->fresh();
    }

    /**
     * 删除某技能的提示词覆盖（恢复默认资产）。
     */
    public function reset(string $skillId): void
    {
        Prompt::query()
            ->where('tenant_id', TenantContext::id())
            ->where('skill_id', $skillId)
            ->delete();
    }

    /**
     * 将租户覆盖应用到 SkillAssets：若存在生效覆盖，则以其替换 SKILL.md 主指令，
     * 保留 references / render script / schema。无覆盖时原样返回。
     */
    public function applyOverride(SkillAssets $assets, string $skillId): SkillAssets
    {
        $override = $this->getEffective($skillId);

        if ($override === null) {
            return $assets;
        }

        return new SkillAssets(
            skillId: $assets->skillId,
            skillMd: $override,
            references: $assets->references,
            renderScript: $assets->renderScript,
            exampleJsonSchema: $assets->exampleJsonSchema,
            hasAssets: $assets->hasAssets,
        );
    }
}
