<?php

namespace App\Services\Contracts;

use App\Models\Tenant;
use App\Services\DTOs\AiProviderCallResult;

/** Workspace-owned model calls; no shared credentials or implicit fallback. */
interface AiProvider
{
    /**
     * 调用 LLM 生成文本
     *
     * @param  string      $prompt    用户 prompt
     * @param  string|null $system    系统 prompt（可选）
     * @param  array       $options   额外参数（temperature, max_tokens 等）
     *
     * $options['json_object'] = true 时要求上游只输出合法 JSON（语法层约束，不校验字段）。
     * 凡是要把回复喂给 json_decode 的调用方都该打开它 —— 详见实现里的说明。
     */
    public function generate(string $prompt, ?string $system = null, array $options = []): AiProviderCallResult;

    /**
     * 结构化输出（JSON 模式）
     *
     * @param  string $prompt  用户 prompt
     * @param  string $schema  期望的 JSON Schema
     */
    public function generateStructured(string $prompt, string $schema, ?string $system = null, array $options = []): AiProviderCallResult;

    /**
     * 获取当前租户可用的供应商列表
     *
     * @return array<string, array{name: string, models: string[]}>
     */
    public function availableProviders(Tenant $tenant): array;

    /**
     * 选择最优可用模型
     *
     * 返回当前工作区文本模型配置；未配置则为空。
     */
    public function pickBestModel(Tenant $tenant, ?string $preferred = null): array;

    /**
     * 获取租户的 API Key 配置。
     * 未配置当前工作区的文本 Key 时返回 null。
     */
    public function getTenantApiKey(Tenant $tenant, string $provider): ?string;

    /**
     * Generate text embedding vectors
     *
     * @param string $text Input text to embed
     * @param string $model Model to use (default: embedding)
     * @return array{embedding: float[], tokens: int}
     */
    public function embed(string $text, string $model = 'embedding', array $options = []): array;

    /**
     * Generate embeddings for multiple texts
     *
     * @param string[] $texts
     * @param string $model
     * @return array{embeddings: array<int, array<int, float>>, tokens: int, error: ?string}
     *
     * 失败时 embeddings 为空数组、error 是给人看的原因。调用方**必须**看 error ——
     * 只看 embeddings 是否为空，会把「余额不足」当成「这批文本没内容」。
     *
     * ⚠️ error 是**给客户看的**：它会被写进 knowledge_documents.error_message 并显示在页面上。
     * 实现里不许把上游返回体、异常原文、内部地址拼进去（那些进 Log::error）。
     */
    public function embedBatch(array $texts, string $model = 'embedding', array $options = []): array;
}
