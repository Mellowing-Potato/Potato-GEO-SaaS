<?php

namespace App\Services\DTOs;

/**
 * 内容生产简报
 *
 * 用户在"内容生产"阶段创建文章时填写的简报。
 * ContentPipelineService 据此驱动标题选择 → RAG 检索 → AI 生成。
 */
final readonly class ContentBrief
{
    public function __construct(
        /** 项目 ID */
        public int $projectId,

        /** 触发用户 ID */
        public int $userId,

        /** 内容类型：explainer / comparison / ranking / general */
        public string $contentType,

        /** 目标关键词 */
        public string $targetKeyword,

        /** 品牌名称 */
        public string $brandName = '',

        /** 品牌 URL */
        public string $brandUrl = '',

        /** 额外指令（如语气、长度偏好、参考竞品） */
        public array $instructions = [],

        /** 引用的知识库文档 ID 列表 */
        public array $knowledgeDocIds = [],

        /** 指定的 AI 模型（null 则系统自动选择） */
        public ?string $preferredModel = null,
    ) {}
}
