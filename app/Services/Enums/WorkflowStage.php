<?php

namespace App\Services\Enums;

/**
 * 工作流阶段枚举
 *
 * 与 Project::STAGES 对齐，增加行为元数据。
 */
enum WorkflowStage: int
{
    case Research = 0;
    case Strategy = 1;
    case Production = 2;
    case Distribution = 3;
    case Monitoring = 4;
    case Iteration = 5;

    public function key(): string
    {
        return match ($this) {
            self::Research => 'research',
            self::Strategy => 'strategy',
            self::Production => 'production',
            self::Distribution => 'distribution',
            self::Monitoring => 'monitoring',
            self::Iteration => 'iteration',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Research => '研究拓词',
            self::Strategy => '策略诊断',
            self::Production => '内容生产',
            self::Distribution => '渠道发布',
            self::Monitoring => '效果监测',
            self::Iteration => '迭代优化',
        };
    }

    /**
     * 该阶段关联的 Skill IDs
     */
    public function skillIds(): array
    {
        return match ($this) {
            self::Research => ['yao-geo-intent-miner'],
            self::Strategy => [
                'yao-geo-panorama-audit',
                'yao-geo-execution-roadmap',
                'yao-geo-tracking',
            ],
            self::Production => [
                'yao-geo-explainer-builder',
                'yao-geo-comparison-builder',
                'yao-geo-ranking-article-builder',
                'yao-geo-content-refiner',
                'yao-geo-title-optimizer',
                'yao-geo-page-blueprint',   // 生产前置规范
                'yao-geo-brand-graph',      // 知识资产辅助
                'yao-geo-knowledge-base-builder',
            ],
            self::Distribution => [
                'yao-geo-channel-publishing',
                'yao-geo-page-audit',       // 发布前校验
            ],
            self::Monitoring => ['yao-geo-effect-monitor'],
            self::Iteration => [
                'yao-geo-content-refiner',
                'yao-geoflow-cli',
            ],
        };
    }

    /**
     * DAG 合法回退目标
     *
     * 架构文档定义的正向流转 + 合法回退路径。
     */
    public function legalRollbackTargets(): array
    {
        return match ($this) {
            self::Research => [],           // 起点，无回退
            self::Strategy => [self::Research],
            self::Production => [self::Strategy],
            self::Distribution => [self::Production],
            self::Monitoring => [],         // 监测不回退，由迭代处理
            self::Iteration => [self::Research, self::Strategy, self::Production],
        };
    }

    /**
     * 是否可以前进到下一阶段
     */
    public function hasNext(): bool
    {
        return $this !== self::Iteration;
    }

    /**
     * 下一阶段
     */
    public function next(): ?self
    {
        $nextValue = $this->value + 1;

        return self::tryFrom($nextValue);
    }

    /**
     * 从 stage key 字符串解析
     */
    public static function fromKey(string $key): self
    {
        foreach (self::cases() as $stage) {
            if ($stage->key() === $key) {
                return $stage;
            }
        }

        throw new \InvalidArgumentException("Unknown workflow stage key: {$key}");
    }
}
