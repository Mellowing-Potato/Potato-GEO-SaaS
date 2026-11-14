<?php

namespace App\Services\Knowledge;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Contracts\AiProvider;
use App\Services\Contracts\KnowledgeRetrieval as KnowledgeRetrievalContract;
use App\Services\DTOs\RetrievedChunk;
use App\Services\Billing\PreparedEmbeddingAttempt;
use App\Services\Billing\EmbeddingUsageRecorder;
use App\Services\Billing\UnresolvedEmbeddingAttempt;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 知识库检索服务实现（RAG）
 *
 * 基于 GEOFlow 的 KnowledgeRetrievalService 封装，
 * 增加租户隔离（Global Scope 自动按 tenant_id 过滤）。
 *
 * 生产环境必须使用 PostgreSQL + pgvector；SQLite/PHP 相似度回退只用于本地和测试。
 */
class KnowledgeRetrievalServiceImpl implements KnowledgeRetrievalContract
{
    public function __construct(
        private readonly AiProvider $aiProvider,
    ) {}

    /**
     * 最近一次 retrieve() 实际 embed 出的查询向量维度（诊断用，见契约注释）。
     */
    private ?int $lastEmbeddingDim = null;

    public function retrieve(string $query, int $topK = 5, float $threshold = 0.0, array $filters = []): array
    {
        $tenantId = TenantContext::has() ? TenantContext::id() : null;
        if (! $tenantId) {
            return [];
        }

        $projectId = (int) ($filters['project_id'] ?? 0);
        if ($projectId < 1 || !\App\Models\Project::where('tenant_id', $tenantId)->whereKey($projectId)->exists()) {
            return [];
        }

        $embeddingKey = app(\App\Services\AI\ModelConfiguration::class)->active('embedding', $tenantId);
        if (! $embeddingKey) {
            if (KnowledgeDocument::where('tenant_id', $tenantId)->where('project_id', $projectId)->exists()) {
                throw new \RuntimeException('未配置向量模型');
            }
            return [];
        }

        Log::info('Knowledge retrieval', [
            'query_sha256' => hash('sha256', $query),
            'query_chars' => mb_strlen($query),
            'top_k' => $topK,
            'threshold' => $threshold,
            'tenant_id' => $tenantId,
            'filters' => \Illuminate\Support\Arr::except($filters, ['brief_data']),
        ]);

        // 1. Vectorize the query
        $executionId = (int) ($filters['execution_id'] ?? 0);
        $clientId = null;
        if ($executionId > 0) {
            $clientId = PreparedEmbeddingAttempt::save(
                $executionId, $projectId, (int) ($filters['user_id'] ?? 0),
                (string) ($filters['feature_id'] ?? ''), (string) ($filters['task_id'] ?? ''),
                $query, is_array($filters['brief_data'] ?? null) ? $filters['brief_data'] : [],
            );
        }
        $recordedVector = $clientId !== null
            ? PreparedEmbeddingAttempt::recordedVector($executionId, $clientId) : null;
        $embedResult = $recordedVector !== null ? ['embedding' => $recordedVector]
            : $this->aiProvider->embed($query, 'embedding', [
                'feature_id' => $filters['feature_id'] ?? 'knowledge-query',
                'task_id' => $filters['task_id'] ?? null, 'step_id' => 'knowledge-query',
                ...($clientId !== null ? ['client_request_id' => $clientId] : []),
            ]);
        // 把这次向量化的回执挂到执行上留证据。收编归档分支 3217d75 时修了两处：
        //   1. 判据加上 request_ids —— 重试过的失败回执里可能**只有** request_ids（每次尝试一条），
        //      只看 request_id 会把"明明有凭据"的失败当没发生过；
        //   2. 必须连 project_id 一起校验 —— 只按 tenant_id 找执行的话，同一个租户下用
        //      别的项目 id 检索，也会把回执写进不相干的执行里（跨项目污染成本证据）。
        // 顺手加事务 + 行锁：同一次执行可能被概览与检索并发写，后写会覆盖前写。
        if ($executionId > 0 && (!empty($embedResult['gateway']['request_id'])
            || !empty($embedResult['gateway']['request_ids'])
            || isset($embedResult['gateway']['http_status']))) {
            DB::transaction(function () use ($filters, $embedResult, $tenantId, $projectId): void {
                $execution = \App\Models\SkillExecution::where('tenant_id', $tenantId)
                    ->where('project_id', $projectId)
                    ->lockForUpdate()
                    ->find($filters['execution_id']);
                if ($execution) {
                    $meta = $execution->gateway_requests ?? [];
                    $meta[] = $embedResult['gateway'];
                    $execution->update(['gateway_requests' => $meta]);
                }
            });
        }
        $receipt = is_array($embedResult['gateway'] ?? null) ? $embedResult['gateway'] : [];
        if ($executionId === 0 && (int) ($filters['user_id'] ?? 0) > 0
            && isset($embedResult['provider'], $embedResult['model'])) {
            $usage = $receipt['usage'] ?? [];
            app(\App\Services\Billing\AssistUsageRecorder::class)->record($projectId, (int) $filters['user_id'],
                new \App\Services\DTOs\AiProviderCallResult(success: ! empty($embedResult['embedding']),
                    provider: $embedResult['provider'], model: $embedResult['model'],
                    promptTokens: $usage['prompt_tokens'] ?? 0, completionTokens: $usage['completion_tokens'] ?? 0,
                    totalTokens: $usage['total_tokens'] ?? 0, gateway: $receipt),
                'ai-assist-knowledge-query', '知识库检索');
        }
        if (empty($embedResult['embedding']) && ($receipt['status'] ?? '') === 'failed') {
            if ($clientId !== null) {
                app(\App\Services\Billing\ModelUsageRecorder::class)->record(
                    \App\Models\SkillExecution::findOrFail($executionId),
                    new \App\Services\DTOs\AiProviderCallResult(success: false,
                        provider: $embedResult['provider'], model: $embedResult['model'],
                        promptTokens: $receipt['usage']['prompt_tokens'] ?? 0,
                        completionTokens: $receipt['usage']['completion_tokens'] ?? 0,
                        totalTokens: $receipt['usage']['total_tokens'] ?? 0, gateway: $receipt), ['usage_kind' => 'embedding']);
                PreparedEmbeddingAttempt::clearRejected($executionId, $clientId);
            }
            throw new \RuntimeException($embedResult['error'] ?? '向量请求失败，未执行检索。');
        }
        if (empty($embedResult['embedding'])) {
            if ($clientId !== null) {
                if (($embedResult['attempt_sent'] ?? null) === false) {
                    PreparedEmbeddingAttempt::clearRejected($executionId, $clientId);
                    return [];
                }
                $gateway = is_array($embedResult['gateway'] ?? null) ? $embedResult['gateway'] : [];
                $gateway['attribution'] = array_merge([
                    'client_request_id' => $clientId, 'task_id' => $filters['task_id'],
                    'feature_id' => $filters['feature_id'], 'step_id' => 'knowledge-query',
                ], is_array($gateway['attribution'] ?? null) ? $gateway['attribution'] : []);
                throw new UnresolvedEmbeddingAttempt($gateway);
            }
            if (($receipt['status'] ?? '') === 'unknown') {
                throw new \RuntimeException('结果未知，可手动重新执行');
            }
            return [];
        }
        if ($clientId !== null && $recordedVector === null) {
            $receipt = is_array($embedResult['gateway'] ?? null) ? $embedResult['gateway'] : [];
            if ($receipt !== []) {
                $receipt['usage']['total_tokens'] = (int) ($embedResult['tokens'] ?? 0);
            }
            PreparedEmbeddingAttempt::recordResult($executionId, $clientId,
                $embedResult['embedding'], $receipt);
        }
        $savedExecution = $clientId !== null
            ? \App\Models\SkillExecution::query()->findOrFail($executionId) : null;
        $savedReceipt = $savedExecution !== null
            ? data_get($savedExecution->billing_pending_result, 'gateway') : null;
        if ($savedExecution !== null && is_array($savedReceipt) && ! empty($savedReceipt['request_id'])) {
            app(EmbeddingUsageRecorder::class)->record($savedExecution, $savedReceipt,
                (int) data_get($savedReceipt, 'usage.total_tokens', 0));
        }
        $queryVector = $embedResult['embedding'];
        if (! \App\Services\AI\EmbeddingModelPolicy::validVector($queryVector)) {
            throw new \RuntimeException('查询向量维度不匹配，已停止检索。');
        }
        $this->lastEmbeddingDim = count($queryVector);
        $embeddingIdentity = \App\Services\AI\EmbeddingModelPolicy::identity(
            $embedResult['provider'] ?? $savedReceipt['provider'] ?? $embeddingKey->provider,
            $embedResult['model'] ?? $savedReceipt['model'] ?? $embeddingKey->metadata['model'],
        );

        // 2. Find nearest neighbors using pgvector
        $baseQuery = KnowledgeChunk::query()
            ->where('tenant_id', $tenantId)
            ->whereExists(function ($q) use ($tenantId, $projectId, $embeddingIdentity) {
                $q->selectRaw('1')->from('knowledge_documents')
                    ->join('knowledge_bases', 'knowledge_bases.id', '=', 'knowledge_documents.knowledge_base_id')
                    ->whereColumn('knowledge_documents.id', 'knowledge_chunks.document_id')
                    ->where('knowledge_documents.tenant_id', $tenantId)
                    // A model can change while an old query is in flight after materials
                    // are deleted/reimported. Never compare its vector with the new model.
                    ->where('knowledge_documents.metadata->embedding_identity->provider', $embeddingIdentity['provider'])
                    ->where('knowledge_documents.metadata->embedding_identity->model', $embeddingIdentity['model'])
                    ->where('knowledge_bases.tenant_id', $tenantId)
                    ->where('knowledge_bases.project_id', $projectId)
                    ->where('knowledge_bases.status', 'active')
                    ->where('knowledge_documents.project_id', $projectId)
                    ->where('knowledge_documents.status', 'ready')
                    ->where('knowledge_documents.review_status', '!=', 'archived');
            });

        // Apply knowledge base filters if provided
        if (! empty($filters['knowledge_base_ids'])) {
            $baseQuery->whereExists(function ($q) use ($filters) {
                $q->select(DB::raw(1))
                    ->from('knowledge_documents')
                    ->whereColumn('knowledge_documents.id', 'knowledge_chunks.document_id')
                    ->whereIn('knowledge_documents.knowledge_base_id', $filters['knowledge_base_ids'])
                    ->where('knowledge_documents.status', 'ready');
            });
        }

        $chunks = $this->retrieveNearestChunks($baseQuery, $queryVector, $topK);

        // 3. Build RetrievedChunk DTOs with hybrid scoring
        $results = [];
        foreach ($chunks as $chunk) {
            $similarity = $chunk->similarity ?? 0;
            if ($similarity < $threshold) {
                continue;
            }

            // Simple hybrid score: vector similarity is the primary signal
            $relevanceScore = $similarity;

            // Keyword matching bonus: if query terms appear in section title, boost slightly
            if (! empty($chunk->section_title)) {
                $sectionLower = mb_strtolower($chunk->section_title);
                $queryLower = mb_strtolower($query);

                // 空间分词匹配（适用于英文等）
                $queryTerms = array_filter(explode(' ', $queryLower), fn($t) => mb_strlen($t) >= 2);
                foreach ($queryTerms as $term) {
                    if (str_contains($sectionLower, $term)) {
                        $relevanceScore += 0.05;
                        break;
                    }
                }

                // CJK 子串匹配：若查询整体或 2-char n-gram 命中 section title
                if (mb_strlen($query) >= 2 && str_contains($sectionLower, $queryLower)) {
                    $relevanceScore += 0.03;
                } else {
                    // 2-char sliding window n-gram 匹配
                    $queryLen = mb_strlen($queryLower);
                    $matched = false;
                    for ($i = 0; $i < $queryLen - 1 && ! $matched; $i++) {
                        $ngram = mb_substr($queryLower, $i, 2);
                        if (str_contains($sectionLower, $ngram)) {
                            $relevanceScore += 0.02;
                            $matched = true;
                        }
                    }
                }
            }

            // Recency bonus: documents created more recently get a small boost
            if ($chunk->document?->created_at) {
                $daysAgo = now()->diffInDays($chunk->document->created_at);
                if ($daysAgo < 30) {
                    $relevanceScore += 0.03;
                } elseif ($daysAgo < 90) {
                    $relevanceScore += 0.01;
                }
            }

            $results[] = new RetrievedChunk(
                documentId: $chunk->document_id,
                chunkId: $chunk->id,
                content: $chunk->content,
                vectorScore: $similarity,
                relevanceScore: min(1.0, $relevanceScore),
                documentTitle: $chunk->document?->title ?? '',
                section: $chunk->section_title ?? '',
                sourceUrl: $chunk->document?->source_url,
                reviewStatus: $chunk->document?->review_status,
                documentCreatedAt: $chunk->document?->created_at?->toISOString(),
            );
        }

        // 4. Sort by relevance score descending, take topK
        usort($results, fn ($a, $b) => $b->relevanceScore <=> $a->relevanceScore);

        return array_slice($results, 0, $topK);
    }

    public function lastEmbeddingDimension(): ?int
    {
        return $this->lastEmbeddingDim;
    }

    public function buildContextString(array $chunks): string
    {
        if (empty($chunks)) {
            return '';
        }

        $parts = [];

        foreach ($chunks as $i => $chunk) {
            $header = "### 参考资料 " . ($i + 1);
            if ($chunk->documentTitle) {
                $header .= " — {$chunk->documentTitle}";
            }
            if ($chunk->section) {
                $header .= " / {$chunk->section}";
            }

            $parts[] = $header;
            $parts[] = $chunk->content;

            if ($chunk->sourceUrl) {
                $parts[] = "来源: {$chunk->sourceUrl}";
            }

            $parts[] = ''; // 空行分隔
        }

        return implode("\n", $parts);
    }

    private function retrieveNearestChunks(Builder $baseQuery, array $queryVector, int $topK)
    {
        if ($this->supportsPgvector()) {
            $encoded = '[' . implode(',', $queryVector) . ']';

            return $baseQuery
                ->with('document')
                ->selectRaw('*, 1 - (embedding <=> ?::vector) AS similarity', [$encoded])
                ->orderByRaw('embedding <=> ?::vector', [$encoded])
                ->limit($topK * 2)
                ->get();
        }

        if (! $this->nonPgvectorFallbackEnabled()) {
            Log::warning('Knowledge retrieval skipped because pgvector is required for this environment.', [
                'driver' => DB::connection()->getDriverName(),
                'app_env' => config('app.env'),
                'require_pgvector_in_production' => config('geo.rag.require_pgvector_in_production'),
                'sqlite_fallback_enabled' => config('geo.rag.sqlite_fallback_enabled'),
            ]);

            return collect();
        }

        return $baseQuery
            ->with('document')
            ->whereNotNull('embedding')
            ->limit(max($topK * 20, 50))
            ->get()
            ->map(function (KnowledgeChunk $chunk) use ($queryVector) {
                $chunk->setAttribute('similarity', $this->cosineSimilarity(
                    $queryVector,
                    $this->normalizeEmbedding($chunk->embedding),
                ));

                return $chunk;
            })
            ->sortByDesc(fn (KnowledgeChunk $chunk) => (float) ($chunk->similarity ?? 0))
            ->take($topK * 2)
            ->values();
    }

    private function supportsPgvector(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    private function nonPgvectorFallbackEnabled(): bool
    {
        $isProduction = strtolower(trim((string) config('app.env'))) === 'production';
        $requiresPgvector = filter_var(config('geo.rag.require_pgvector_in_production', true), FILTER_VALIDATE_BOOL);

        if ($isProduction && $requiresPgvector) {
            return false;
        }

        return filter_var(config('geo.rag.sqlite_fallback_enabled', true), FILTER_VALIDATE_BOOL);
    }

    /**
     * @param  mixed  $embedding
     * @return array<int, float>
     */
    private function normalizeEmbedding(mixed $embedding): array
    {
        if (is_string($embedding)) {
            $decoded = json_decode($embedding, true);
            $embedding = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($embedding)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($value) => is_numeric($value) ? (float) $value : null, $embedding),
            fn ($value) => $value !== null,
        ));
    }

    /**
     * @param  array<int, float>  $left
     * @param  array<int, float>  $right
     */
    private function cosineSimilarity(array $left, array $right): float
    {
        $limit = min(count($left), count($right));
        if ($limit === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $leftMagnitude = 0.0;
        $rightMagnitude = 0.0;

        for ($i = 0; $i < $limit; $i++) {
            $a = (float) $left[$i];
            $b = (float) $right[$i];
            $dot += $a * $b;
            $leftMagnitude += $a * $a;
            $rightMagnitude += $b * $b;
        }

        if ($leftMagnitude <= 0.0 || $rightMagnitude <= 0.0) {
            return 0.0;
        }

        return max(-1.0, min(1.0, $dot / (sqrt($leftMagnitude) * sqrt($rightMagnitude))));
    }

    public function tenantKnowledgeBases(): array
    {
        $tenantId = TenantContext::has() ? TenantContext::id() : null;
        if (! $tenantId) {
            return [];
        }

        return KnowledgeBase::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->pluck('name', 'id')
            ->toArray();
    }

    public function tenantStats(): array
    {
        $tenantId = TenantContext::has() ? TenantContext::id() : null;
        if (! $tenantId) {
            return ['total_documents' => 0, 'total_chunks' => 0, 'storage_mb' => 0.0];
        }

        $docs = KnowledgeDocument::where('tenant_id', $tenantId)->count();
        $chunks = KnowledgeChunk::where('tenant_id', $tenantId)->count();

        // 估算当前租户的存储占用（基于租户文档占比 × 全局表大小）
        $storageMb = 0.0;
        try {
            $totalBytes = DB::selectOne(
                "SELECT (COALESCE(pg_total_relation_size('knowledge_documents'), 0)
                       + COALESCE(pg_total_relation_size('knowledge_chunks'), 0)) AS total_bytes"
            )->total_bytes ?? 0;

            $totalChunks = KnowledgeChunk::withoutGlobalScopes()->count();
            if ($totalChunks > 0) {
                $tenantRatio = $chunks / $totalChunks;
                $storageMb = round(($totalBytes * $tenantRatio) / 1024 / 1024, 2);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to calculate storage size', ['error' => $e->getMessage()]);
        }

        return [
            'total_documents' => $docs,
            'total_chunks' => $chunks,
            'storage_mb' => $storageMb,
        ];
    }
}
