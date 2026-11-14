<?php

namespace App\Services\DTOs;

use App\Services\Enums\SkillCategory;

/**
 * Skill 定义（从 registry 加载）
 *
 * 对应一个 Skill 的元数据，不包含执行逻辑。
 */
final readonly class SkillDefinition
{
    public function __construct(
        /** Skill ID，如 'yao-geo-intent-miner' */
        public string $id,

        /** 显示名称 */
        public string $name,

        /** 分类 */
        public SkillCategory $category,

        /** 成熟度：stable / beta / draft */
        public string $maturity,

        /** 是否需要联网 */
        public bool $requiresWeb,

        /** 所属工作流阶段索引（0-5） */
        public int $stageIndex,

        /** 简短描述（一句话） */
        public string $description,

        /** 场景化描述（面向用户的说明，出现在 Skill 卡片上） */
        public string $sceneDescription = '',

        /** 效果描述（执行后能得到什么） */
        public string $effectDescription = '',

        /** 简报模板字段定义（Skill 需要用户填写的输入字段） */
        public array $briefFields = [],

        /** 渲染脚本路径（相对于 Skill 根目录） */
        public ?string $renderScriptPath = null,

        /** 参考文档路径列表 */
        public array $referencePaths = [],

        /**
         * 独立业务流程的站内入口。
         *
         * 非空时，这个注册项只负责把用户带到真实能力页面，不进入通用模型技能执行器。
         */
        public ?string $actionPath = null,

        /** 独立入口的按钮/状态文案。 */
        public ?string $actionLabel = null,

        /**
         * 非空 = 该技能当前不可用，值就是给客户看的原因。
         *
         * 不要用 maturity 来做这件事：17 个技能里 16 个是 beta 或 draft，
         * 按成熟度过滤会把整个产品清空。可用与否和成熟度是两回事。
         */
        public ?string $unavailableReason = null,
    ) {}

    /** 客户现在能不能点它。 */
    public function isAvailable(): bool
    {
        return $this->unavailableReason === null;
    }

    /**
     * 是否为稳定版本
     */
    public function isStable(): bool
    {
        return $this->maturity === 'stable';
    }

    /**
     * 是否可用于生产环境
     */
    public function isProductionReady(): bool
    {
        return in_array($this->maturity, ['stable', 'beta'], true);
    }
}
