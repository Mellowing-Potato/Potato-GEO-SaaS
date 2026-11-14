<?php

namespace App\Services\Geo\AiVisibility;

/**
 * 一次提问的完整结果（豆包 Responses API 调用归一化后的形态）。
 *
 * 用量字段已做防御性兼容：豆包 Responses API 走 OpenAI 风格
 * （input_tokens / output_tokens / input_tokens_details.cached_tokens），
 * 归一化时同时兜底 prompt_tokens / completion_tokens 写法，这里只存已解好的值。
 */
final readonly class AiVisibilityResult
{
    /**
     * @param  list<AiVisibilitySourceData>  $sources
     * @param  list<string>  $searchQueries 本次实际搜索过的词（web_search_call.action.query）
     */
    public function __construct(
        public bool $success,
        public string $question,
        public string $provider,
        public string $model,
        public ?string $answerText = null,
        public array $sources = [],
        public array $searchQueries = [],
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public int $cachedTokens = 0,
        public int $searchCallCount = 0,
        public bool $searchCountEstimated = false,
        public float $durationSeconds = 0.0,
        public ?string $errorMessage = null,
        /** 上游返回 status（如 completed）；仅诊断用 */
        public ?string $finishReason = null,
        public array $gateway = [],
        public bool $pendingReconciliation = false,
    ) {}

    public static function failure(string $question, string $provider, string $model, string $error): self
    {
        return new self(
            success: false,
            question: $question,
            provider: $provider,
            model: $model,
            errorMessage: $error,
        );
    }

    public static function pending(string $question, string $provider, string $model, string $error): self
    {
        return new self(success: false, question: $question, provider: $provider,
            model: $model, errorMessage: $error, pendingReconciliation: true);
    }

}
