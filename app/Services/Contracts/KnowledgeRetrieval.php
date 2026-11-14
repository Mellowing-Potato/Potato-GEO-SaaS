<?php

namespace App\Services\Contracts;

use App\Services\DTOs\RetrievedChunk;

/**
 * 知识库检索服务契约（RAG）
 *
 * 基于 GEOFlow 的 KnowledgeRetrievalService 封装，
 * 增加租户隔离（Global Scope 自动按 tenant_id 过滤）。
 *
 * 检索策略（架构文档 2.1 节描述）：
 *   - 混合检索：向量分数 + 关键词匹配 + 标题/章节匹配 + 来源元数据
 *   - 支持多知识库合并查询（最多 5 个库同时检索）
 *   - 审核状态加权、资料时效性加权
 *   - pgvector 的 HNSW 索引按 tenant_id 分区
 */
interface KnowledgeRetrieval
{
    /**
     * 检索与查询最相关的知识片段
     *
     * @param  string   $query      查询文本
     * @param  int      $topK       返回前 K 个结果
     * @param  float    $threshold  最低相关性阈值（0-1）
     * @param  array    $filters    必须包含 project_id；缺失时返回空结果。knowledge_base_ids 只能进一步缩小范围。
     *
     * @return RetrievedChunk[]
     */
    public function retrieve(string $query, int $topK = 5, float $threshold = 0.0, array $filters = []): array;

    /**
     * 最近一次 retrieve() 实际使用的查询向量维度（生产环境应为 1024）。
     *
     * 仅作诊断展示用，不属于检索逻辑；retrieve() 内部 embed 后顺手记录，
     * 调用方（检索测试框端点）读取它回填到响应里，避免为拿维度多调一次 embed。
     * retrieve() 尚未成功 embed 时返回 null。
     */
    public function lastEmbeddingDimension(): ?int;

    /**
     * 构建上下文字符串（注入到 Agent prompt 中）
     *
     * @param  RetrievedChunk[] $chunks 检索结果
     * @return string 格式化后的上下文文本
     */
    public function buildContextString(array $chunks): string;

    /**
     * 获取当前租户的知识库列表
     *
     * @return array<int, string> [id => name]
     */
    public function tenantKnowledgeBases(): array;

    /**
     * 统计当前租户的知识库使用情况
     *
     * @return array{total_documents: int, total_chunks: int, storage_mb: float}
     */
    public function tenantStats(): array;
}
