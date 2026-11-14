<?php

namespace App\Services\DTOs;

/**
 * 知识库检索片段
 *
 * KnowledgeRetrievalService 返回的单个检索结果。
 */
final readonly class RetrievedChunk
{
    public function __construct(
        /** 文档 ID */
        public int $documentId,

        /** 片段 ID */
        public int $chunkId,

        /** 片段文本内容 */
        public string $content,

        /** 向量相似度分数（0-1） */
        public float $vectorScore,

        /** 综合评分（向量 + 关键词 + 元数据加权） */
        public float $relevanceScore,

        /** 来源文档标题 */
        public string $documentTitle = '',

        /** 所属章节 */
        public string $section = '',

        /** 来源 URL */
        public ?string $sourceUrl = null,

        /** 文档审核状态 */
        public ?string $reviewStatus = null,

        /** 文档创建时间（用于时效性加权） */
        public ?string $documentCreatedAt = null,
    ) {}
}
