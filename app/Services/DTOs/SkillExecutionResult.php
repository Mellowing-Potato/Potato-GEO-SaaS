<?php

namespace App\Services\DTOs;

/**
 * Skill 执行结果
 *
 * SkillAgentRunner 的输出，包含渲染产物、质量报告、执行度量。
 */
final readonly class SkillExecutionResult
{
    public function __construct(
        /** 执行记录 ID（skill_executions 表） */
        public int $executionId,

        /** Skill ID */
        public string $skillId,

        /** 是否执行成功 */
        public bool $success,

        /** 输出文件路径列表（相对于租户存储根） */
        public array $outputPaths = [],

        /** 结构化中间数据（report_input.json 内容） */
        public ?array $intermediateData = null,

        /** 质量门报告 */
        public ?array $qualityReport = null,

        /** 执行耗时（秒） */
        public float $durationSeconds = 0.0,

        /** AI 模型调用次数 */
        public int $llmCallCount = 0,

        /** 估算 Token 消耗 */
        public int $tokenUsage = 0,

        /** 错误信息（失败时） */
        public ?string $errorMessage = null,

        /** 最后一次 LLM 调用结果，用于计费和模型调用日志 */
        public ?AiProviderCallResult $lastLlmResult = null,
    ) {}

    /**
     * 快速构建失败结果
     */
    public static function failure(string $skillId, string $error): self
    {
        return new self(
            executionId: 0,
            skillId: $skillId,
            success: false,
            errorMessage: $error,
        );
    }

    /**
     * 是否通过质量门
     */
    public function passedQualityGate(): bool
    {
        return $this->success
            && ($this->qualityReport['passed'] ?? false);
    }
}
