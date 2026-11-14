<?php

namespace App\Services\Content;

use App\Services\Contracts\AiProvider;
use App\Services\Contracts\ContentPipeline as ContentPipelineContract;
use App\Services\Contracts\KnowledgeRetrieval;
use App\Services\DTOs\ContentBrief;
use App\Services\DTOs\ContentDraft;
use Illuminate\Support\Facades\Log;

/**
 * 内容生产线实现
 *
 * 驱动 "内容生产" 阶段的核心流程：
 *   简报 → 标题推荐 → RAG 检索 → 构建 prompt → AI 结构化生成 → 质量评估
 *
 * 按内容类型路由到不同 Skill：
 *   - explainer   → yao-geo-explainer-builder（解释型文章）
 *   - comparison  → yao-geo-comparison-builder（对比型文章）
 *   - ranking     → yao-geo-ranking-article-builder（排名型文章）
 *   - general     → 通用生成（不绑定特定 Skill）
 *
 * 所有 AI 调用均使用 generateStructured() 的原生 JSON Schema 模式，
 * 避免正则解析的脆弱性。
 */
class ContentPipelineService implements ContentPipelineContract
{
    /**
     * 文章生成的 JSON Schema（用于 generateStructured 原生模式）
     */
    private const ARTICLE_OUTPUT_SCHEMA = <<<'JSON'
    {
        "type": "object",
        "properties": {
            "title": {
                "type": "string",
                "description": "文章标题"
            },
            "summary": {
                "type": "string",
                "description": "100字以内的文章摘要"
            },
            "body": {
                "type": "string",
                "description": "Markdown 格式的完整文章正文"
            }
        },
        "required": ["title", "summary", "body"]
    }
    JSON;

    /**
     * 标题推荐的 JSON Schema
     */
    private const TITLES_OUTPUT_SCHEMA = <<<'JSON'
    {
        "type": "object",
        "properties": {
            "titles": {
                "type": "array",
                "items": {
                    "type": "object",
                    "properties": {
                        "text": { "type": "string", "description": "标题文本" },
                        "score": { "type": "number", "description": "SEO 评分 0-100" }
                    },
                    "required": ["text", "score"]
                },
                "description": "5 个候选标题"
            }
        },
        "required": ["titles"]
    }
    JSON;

    /**
     * 内容优化的 JSON Schema
     */
    private const REFINE_OUTPUT_SCHEMA = <<<'JSON'
    {
        "type": "object",
        "properties": {
            "title": {
                "type": "string",
                "description": "优化后的标题（如无变化则留空）"
            },
            "body": {
                "type": "string",
                "description": "优化后的 Markdown 正文"
            },
            "changes_summary": {
                "type": "string",
                "description": "简要说明做了哪些优化"
            }
        },
        "required": ["body", "changes_summary"]
    }
    JSON;

    public function __construct(
        private readonly AiProvider $aiProvider,
        private readonly KnowledgeRetrieval $knowledgeRetrieval,
    ) {}

    public function generate(ContentBrief $brief): ContentDraft
    {
        $startTime = microtime(true);

        Log::info('Content generation started', [
            'content_type' => $brief->contentType,
            'project_id' => $brief->projectId,
            'keyword' => $brief->targetKeyword,
        ]);

        // ─── 1. RAG 知识检索 ───
        $knowledgeContext = '';
        $citedChunks = [];
        $queryText = $brief->targetKeyword . ' ' . $brief->brandName;

        $chunks = $this->knowledgeRetrieval->retrieve($queryText, topK: 5,
            filters: ['project_id' => $brief->projectId, 'user_id' => $brief->userId]);
        if (! empty($chunks)) {
            $knowledgeContext = $this->knowledgeRetrieval->buildContextString($chunks);
            $citedChunks = array_map(fn($c) => [
                'chunk_id' => $c->chunkId,
                'doc_title' => $c->documentTitle,
                'score' => $c->relevanceScore,
            ], $chunks);
        }

        // ─── 3. 构建生成 prompt ───
        $prompt = $this->buildGenerationPrompt($brief, $knowledgeContext);
        $systemPrompt = $this->buildSystemPrompt($brief->contentType);

        // ─── 4. 调用 AI 结构化生成（替代旧的 generate + regex 解析） ───
        $aiResult = $this->aiProvider->generateStructured(
            $prompt,
            self::ARTICLE_OUTPUT_SCHEMA,
            $systemPrompt,
            ['feature_id' => 'content-generation'],
        );

        if (! $aiResult->success) {
            Log::error('Content generation AI call failed', [
                'error' => $aiResult->errorMessage,
            ]);

            return new ContentDraft(
                title: '',
                bodyMarkdown: '',
                summary: '',
                contentType: $brief->contentType,
                suggestions: ['AI 生成失败: ' . ($aiResult->errorMessage ?? '未知错误')],
            );
        }

        // ─── 5. 从结构化输出中提取结果 ───
        $structured = $aiResult->structuredOutput ?? [];
        $parsed = [
            'title' => $structured['title'] ?? $brief->targetKeyword,
            'body' => $structured['body'] ?? $aiResult->content ?? '',
            'summary' => $structured['summary'] ?? '',
        ];

        $duration = round(microtime(true) - $startTime, 2);

        Log::info('Content generation completed', [
            'content_type' => $brief->contentType,
            'duration' => $duration,
            'tokens' => $aiResult->totalTokens,
        ]);

        return new ContentDraft(
            title: $parsed['title'],
            bodyMarkdown: $parsed['body'],
            summary: $parsed['summary'],
            contentType: $brief->contentType,
            citedChunks: $citedChunks,
            totalTokens: $aiResult->totalTokens,
            generationSeconds: $duration,
            provider: $aiResult->provider,
            model: $aiResult->model,
        );
    }

    public function refine(string $markdown, array $feedback = []): ContentDraft
    {
        $prompt = "请优化以下文章内容，使其更适合被 AI 搜索引擎引用。\n";
        if (! empty($feedback)) {
            $prompt .= "\n优化要求：\n" . implode("\n", $feedback) . "\n";
        }
        $prompt .= "\n原始文章：\n{$markdown}";

        $aiResult = $this->aiProvider->generateStructured(
            $prompt,
            self::REFINE_OUTPUT_SCHEMA,
            '你是一个 GEO（生成式引擎优化）内容优化专家。请保留原文的核心信息，优化结构和表述以提高 AI 引用率。',
            ['feature_id' => 'content-refinement'],
        );

        if (! $aiResult->success) {
            return new ContentDraft(
                title: '',
                bodyMarkdown: $markdown,
                summary: '优化失败: ' . ($aiResult->errorMessage ?? '未知错误'),
                contentType: 'refined',
            );
        }

        $structured = $aiResult->structuredOutput ?? [];

        return new ContentDraft(
            title: $structured['title'] ?? '',
            bodyMarkdown: $structured['body'] ?? $markdown,
            summary: $structured['changes_summary'] ?? '',
            contentType: 'refined',
            totalTokens: $aiResult->totalTokens,
            provider: $aiResult->provider,
            model: $aiResult->model,
        );
    }

    public function contentTypes(): array
    {
        return [
            'explainer' => '解释型文章（什么是 X）',
            'comparison' => '对比评测（X vs Y）',
            'ranking' => '排名推荐（最佳 X）',
            'general' => '通用文章',
        ];
    }

    public function suggestTitles(ContentBrief $brief): array
    {
        $prompt = "为以下主题生成 5 个 SEO 优化的文章标题。\n";
        $prompt .= "主题：{$brief->targetKeyword}\n";
        $prompt .= "品牌：{$brief->brandName}\n";
        $prompt .= "内容类型：{$brief->contentType}\n";

        $aiResult = $this->aiProvider->generateStructured(
            $prompt,
            self::TITLES_OUTPUT_SCHEMA,
            '你是一个 SEO 标题优化专家。生成的标题需要吸引 AI 搜索引擎引用，同时保持自然可读。',
            ['feature_id' => 'title-recommendation'],
        );

        if (! $aiResult->success) {
            return [$brief->targetKeyword];
        }

        $structured = $aiResult->structuredOutput ?? [];
        $titles = $structured['titles'] ?? [];

        // 按评分降序排列，提取标题文本
        usort($titles, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        $result = array_values(array_filter(
            array_map(fn($t) => trim($t['text'] ?? ''), $titles),
            fn(string $t) => mb_strlen($t) > 5,
        ));

        return ! empty($result) ? $result : [$brief->targetKeyword];
    }

    /**
     * 构建文章生成的 system prompt
     */
    private function buildSystemPrompt(string $contentType): string
    {
        $base = '你是一个专业的 GEO（生成式引擎优化）内容创作专家。你的文章需要被 AI 搜索引擎引用和推荐。';

        return match ($contentType) {
            'explainer' => $base . "\n请写一篇权威的解释型长文，使用清晰的结构化格式，包含定义、原理、示例、常见问题。",
            'comparison' => $base . "\n请写一篇客观的产品对比文章，包含对比表格、优劣势分析、适用场景推荐。",
            'ranking' => $base . "\n请写一篇排名推荐文章，包含产品列表、评分维度、读者指南。",
            default => $base . "\n请写一篇高质量的 SEO 优化文章，结构清晰、信息丰富。",
        };
    }

    /**
     * 构建文章生成的 user prompt
     */
    private function buildGenerationPrompt(ContentBrief $brief, string $knowledgeContext): string
    {
        $parts = [];

        $parts[] = "## 写作任务";
        $parts[] = "- 目标关键词：{$brief->targetKeyword}";
        $parts[] = "- 品牌名称：{$brief->brandName}";
        $parts[] = "- 内容类型：{$brief->contentType}";

        if ($brief->brandUrl) {
            $parts[] = "- 品牌官网：{$brief->brandUrl}";
        }

        if (! empty($brief->instructions)) {
            $parts[] = "\n## 额外要求";
            foreach ($brief->instructions as $key => $value) {
                $parts[] = "- {$key}: {$value}";
            }
        }

        if ($knowledgeContext) {
            $parts[] = "\n## 参考资料（来自品牌知识库）";
            $parts[] = $knowledgeContext;
        }

        $parts[] = "\n## 输出要求";
        $parts[] = "请输出 JSON 格式，包含 title（文章标题）、summary（100字以内摘要）、body（Markdown 格式完整正文）三个字段。";

        return implode("\n", $parts);
    }
}
