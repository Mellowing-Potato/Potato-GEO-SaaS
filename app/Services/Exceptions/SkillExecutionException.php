<?php

namespace App\Services\Exceptions;

/**
 * Skill 执行异常
 *
 * 当 Skill 执行过程中出现可预见的错误时抛出。
 */
class SkillExecutionException extends \RuntimeException
{
    public function __construct(
        public readonly string $skillId,
        string $message,
        public readonly ?string $phase = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct("[{$skillId}] {$message}", $code, $previous);
    }

    /**
     * 从 AI 调用失败构建
     */
    public static function fromAiFailure(string $skillId, string $providerError): self
    {
        return new self(
            skillId: $skillId,
            message: "AI 调用失败: {$providerError}",
            phase: 'llm_call',
        );
    }

    /**
     * 从渲染失败构建
     */
    public static function fromRenderFailure(string $skillId, string $renderError): self
    {
        return new self(
            skillId: $skillId,
            message: "渲染失败: {$renderError}",
            phase: 'render',
        );
    }

    /**
     * 从质量门失败构建
     */
    public static function fromQualityGate(string $skillId, array $failedChecks): self
    {
        return new self(
            skillId: $skillId,
            message: '质量门未通过: ' . implode(', ', $failedChecks),
            phase: 'quality_gate',
        );
    }
}
