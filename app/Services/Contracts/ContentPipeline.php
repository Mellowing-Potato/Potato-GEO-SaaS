<?php

namespace App\Services\Contracts;

use App\Services\DTOs\ContentBrief;
use App\Services\DTOs\ContentDraft;

/**
 * 内容生产线契约
 *
 * 驱动 "内容生产" 阶段的核心流程：
 *   简报 → 标题选择 → RAG 知识库检索 → AI 文章生成 → 质量评估 → 草稿
 *
 * 底层依赖：
 *   - AiProviderService：调用 LLM 生成内容
 *   - KnowledgeRetrievalService：RAG 检索为文章提供素材
 *   - SkillAgentRunner：调用具体 Skill（explainer-builder 等）
 */
interface ContentPipeline
{
    /**
     * 根据简报生成文章草稿
     *
     * 完整流程：
     *   1. 根据 contentType 选择对应 Skill
     *   2. 调用 KnowledgeRetrievalService 检索相关知识
     *   3. 构建 prompt（标题 + 关键词 + 知识片段 + 品牌信息）
     *   4. 调用 AiProviderService 生成文章
     *   5. 运行质量评估
     *
     * @return ContentDraft AI 生成的文章草稿
     */
    public function generate(ContentBrief $brief): ContentDraft;

    /**
     * 优化已有内容（由 content-refiner 驱动）
     *
     * @param  string $markdown 原始 Markdown 内容
     * @param  array  $feedback 优化指令（如监测数据、人工反馈）
     * @return ContentDraft 优化后的草稿
     */
    public function refine(string $markdown, array $feedback = []): ContentDraft;

    /**
     * 获取可用的内容类型列表
     *
     * @return array<string, string> [type => 中文描述]
     */
    public function contentTypes(): array;

    /**
     * 为简报推荐标题（生成多个候选标题供用户选择）
     *
     * @return string[] 候选标题列表
     */
    public function suggestTitles(ContentBrief $brief): array;
}
