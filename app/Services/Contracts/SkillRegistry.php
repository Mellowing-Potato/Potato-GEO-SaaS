<?php

namespace App\Services\Contracts;

use App\Services\DTOs\SkillDefinition;
use App\Services\Enums\SkillCategory;

/**
 * Skill 注册表契约
 *
 * 负责加载、查询 Skill 定义。Skill 资产是平台级公共资源，
 * 不按租户区分（与租户业务数据隔离不同）。
 *
 * 数据源：
 *   MVP 阶段从硬编码的 Skill 定义数组加载；
 *   后续可切换为从 yao-geo-skills 的 registry/skills.json 加载。
 */
interface SkillRegistry
{
    /**
     * 获取所有已注册的 Skill
     *
     * @return SkillDefinition[]
     */
    public function all(): array;

    /**
     * 根据 Skill ID 获取定义
     *
     * @throws \InvalidArgumentException 如果 Skill 不存在
     */
    public function find(string $skillId): SkillDefinition;

    /**
     * 根据 Skill ID 查找，不存在返回 null
     */
    public function findOrNull(string $skillId): ?SkillDefinition;

    /**
     * 按分类筛选
     *
     * @return SkillDefinition[]
     */
    public function findByCategory(SkillCategory $category): array;

    /**
     * 按工作流阶段筛选（返回该阶段关联的所有 Skill）
     *
     * @return SkillDefinition[]
     */
    public function findByStage(int $stageIndex): array;

    /**
     * 获取所有可用的 Skill 分类
     *
     * @return SkillCategory[]
     */
    public function categories(): array;

    /**
     * 检查某 Skill 是否已注册
     */
    public function exists(string $skillId): bool;

    /**
     * 获取生产可用的 Skill 列表（排除 draft）
     *
     * @return SkillDefinition[]
     */
    public function productionReady(): array;
}
