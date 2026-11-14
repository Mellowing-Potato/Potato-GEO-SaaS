<?php

namespace App\Services\Contracts;

use App\Models\Project;
use App\Services\Enums\WorkflowStage;

/**
 * 项目工作流服务契约
 *
 * 管理项目的 6 阶段 DAG 状态机：
 *   research → strategy → production → distribution → monitoring → iteration
 *
 * 支持正向推进和合法回退（架构文档 3.2 节定义）。
 */
interface ProjectWorkflow
{
    /**
     * 推进到下一阶段
     *
     * @return array{stage: int, progress: int, completed: bool}
     * @throws \DomainException 当前阶段未完成或项目已结束
     */
    public function advance(Project $project): array;

    /**
     * 回退到指定阶段（DAG 合法路径内）
     *
     * @throws \DomainException 目标阶段不在合法回退范围内
     */
    public function rollback(Project $project, WorkflowStage $targetStage, string $reason = ''): void;

    /**
     * 检查是否可以前进到下一阶段
     */
    public function canAdvance(Project $project): bool;

    /**
     * 检查是否可以回退到指定阶段
     */
    public function canRollback(Project $project, WorkflowStage $targetStage): bool;

    /**
     * 获取当前阶段的前置条件清单
     * （如：策略阶段需要研究阶段的 intent-miner 输出）
     *
     * @return array{met: bool, requirements: string[]}
     */
    public function getStageRequirements(Project $project, WorkflowStage $stage): array;

    /**
     * 获取项目的完整阶段时间线（含历史记录）
     *
     * @return array 每个元素包含 stage_key, status, started_at, completed_at
     */
    public function getTimeline(Project $project): array;
}
