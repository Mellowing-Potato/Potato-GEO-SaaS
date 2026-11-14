<?php

namespace App\Services\DTOs;

/**
 * 内容草稿
 *
 * ContentPipelineService 的输出 — AI 生成的文章草稿。
 * 后续经过人工审核或 content-refiner 优化后成为正式文章。
 */
final readonly class ContentDraft
{
    public function __construct(
        /** 生成的标题 */
        public string $title,

        /** 文章正文（Markdown 格式） */
        public string $bodyMarkdown,

        /** 文章摘要 / meta description */
        public string $summary,

        /** 使用的内容类型 */
        public string $contentType,

        /** 引用的知识库片段（用于溯源和引用标注） */
        public array $citedChunks = [],

        /** AI 生成度量 */
        public int $totalTokens = 0,
        public float $generationSeconds = 0.0,

        /** 使用的 AI 供应商 + 模型 */
        public string $provider = '',
        public string $model = '',

        /** 质量评分（0-100，由 content-refiner 或质量门填充） */
        public ?int $qualityScore = null,

        /** 优化建议列表 */
        public array $suggestions = [],
    ) {}
}
