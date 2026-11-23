<?php

namespace App\Services\Contracts;

use App\Services\DTOs\SkillBrief;
use App\Services\DTOs\SkillExecutionResult;

/**
 * Skill Agent 执行引擎契约
 *
 * 核心执行链路（架构文档第七章定义）：
 *   1. 加载 SKILL.md（Agent 指令集）
 *   2. 加载 references/（方法论文档）
 *   3. 加载 templates/brief-template.md（输入简报模板）
 *   4. 构建 Agent 上下文 = SKILL.md + references + 用户输入
 *   5. 调用 LLM 逐步执行 SKILL.md 中定义的多步骤流程
 *   6. 生成结构化中间数据（report_input.json）
 *   7. 调用 Python 渲染脚本 → 输出四格式报告
 *   8. 运行质量门检查
 *
 * 租户感知：执行时通过 TenantContext 获取当前租户，
 *   知识库检索自动限定 tenant_id，输出存储到租户隔离目录。
 */
interface SkillAgentRunner
{
    /**
     * 同步执行一个 Skill
     *
     * @param  SkillBrief $brief 用户输入的简报
     * @return SkillExecutionResult 执行结果
     */
    public function execute(SkillBrief $brief): SkillExecutionResult;

    /**
     * 异步执行一个 Skill（推入后台队列）
     *
     * @return int execution ID，用于后续查询状态
     */
    public function executeAsync(SkillBrief $brief): int;

    /**
     * 查询异步执行的状态
     */
    public function getExecutionStatus(int $executionId): SkillExecutionResult;

    /**
     * 取消正在执行的 Skill
     */
    public function cancel(int $executionId): bool;
}
