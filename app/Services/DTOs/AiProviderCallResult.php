<?php

namespace App\Services\DTOs;

/**
 * AI 供应商调用结果
 *
 * AiProviderService 的输出，封装一次 LLM 调用的结果和成本。
 */
final readonly class AiProviderCallResult
{
    public function __construct(
        /** 是否调用成功 */
        public bool $success,

        /** 供应商名称（如 'openai', 'deepseek'） */
        public string $provider,

        /** 模型标识（如 'gpt-4o', 'deepseek-chat'） */
        public string $model,

        /** 生成文本内容 */
        public ?string $content = null,

        /** 结构化输出（JSON 模式时） */
        public ?array $structuredOutput = null,

        /** prompt tokens */
        public int $promptTokens = 0,

        /** completion tokens */
        public int $completionTokens = 0,

        /** 总 token 消耗 */
        public int $totalTokens = 0,

        /** 耗时（秒） */
        public float $durationSeconds = 0.0,

        /** 错误信息 */
        public ?string $errorMessage = null,

        /**
         * 供应商返回的结束原因（'stop' / 'length' / 'unknown' 等）。
         * 'length' 表示输出撞上 max_tokens 上限被截断——用于截断质量闸。
         */
        public ?string $finishReason = null,

        public array $gateway = [],
    ) {}

    /**
     * 快速构建失败结果
     */
    public static function failure(string $provider, string $model, string $error, array $gateway = []): self
    {
        return new self(
            success: false,
            provider: $provider,
            model: $model,
            errorMessage: $error,
            gateway: $gateway,
        );
    }
}
