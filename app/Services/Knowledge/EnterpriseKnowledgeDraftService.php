<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted application interfaces, workspace ownership and execution behavior.
 * Upstream: app/Services/GeoFlow/EnterpriseKnowledgeDraftService.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Services\Knowledge;

use App\Exceptions\IngestionFailure;
use App\Jobs\IngestKnowledgeDocumentJob;
use App\Models\EnterpriseKnowledgeProject;
use App\Models\EnterpriseKnowledgeRevision;
use App\Models\EnterpriseKnowledgeSource;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * 企业知识库草稿服务（阶段 1.5）。
 *
 * 对标 GEOFlow 的 EnterpriseKnowledgeDraftService，但在 geo-saas 中做 SaaS 化改造：
 *  - 项目 / 来源 / 修订均带 tenant_id（全局作用域自动按租户隔离）。
 *  - 草稿生成采用「确定性结构化整理」实现（对应 GEOFlow 的 buildFallbackDraft 回退路径），
 *    从上传来源中提取事实/能力/场景/案例/FAQ/风险并整理成标准企业知识稿；
 *    AI 生成路径可后续接入 AiProvider::generate()（geo-saas 当前 mock 供应商返回 JSON，
 *    不适合直接产出 Markdown 草稿，故本阶段以确定性整理为主实现，保证可测、可控）。
 *  - 发布为知识库复用既有 DocumentIngestionService::ingestText()（切片 + 向量化 + KnowledgeChunk）。
 */
final class EnterpriseKnowledgeDraftService
{
    /**
     * @var list<string>
     */
    private const REQUIRED_SECTIONS = [
        '企业介绍',
        '业务信息摘要',
        '产品能力',
        '应用场景',
        '典型案例',
        'FAQ',
        '禁用表述',
        '风险与冲突',
        '待人工确认',
    ];

    private const RISKY_WORDS = ['唯一', '第一', '保证', '100%', '永久', '无风险', '最权威', '最佳', '最强', '最高'];

    public function __construct(
        private readonly DocumentIngestionService $ingestion,
    ) {}

    /**
     * 生成企业知识草稿（确定性结构化整理）。
     *
     * @return array{content:string,source:string,model_id:?int,error:?string}
     */
    public function generateDraft(EnterpriseKnowledgeProject $project): array
    {
        $sourceBlocks = $this->sourceBlocks($project);
        $sourceText = $this->buildSourceText($sourceBlocks);
        $fallback = $this->buildFallbackDraft($project, $sourceText, $sourceBlocks);

        return [
            'content' => $fallback['content'],
            'source' => 'system',
            'model_id' => null,
            'error' => $fallback['error'],
        ];
    }

    /**
     * 校验草稿内容，返回问题清单（warning / danger / info）。
     *
     * @return list<array{level:string,message:string,section:string}>
     */
    public function validateDraft(string $content): array
    {
        $content = trim($content);
        $items = [];

        foreach (self::REQUIRED_SECTIONS as $section) {
            if (! preg_match('/^#{1,3}\s*'.preg_quote($section, '/').'/mu', $content)) {
                $items[] = [
                    'level' => 'warning',
                    'section' => $section,
                    'label' => $section.'章节',
                    'detail' => '结构检查：缺少必选章节；尚未核验正文事实。',
                    'ok' => false,
                    'message' => "缺少必选章节：{$section}",
                ];
            } else {
                $items[] = [
                    'level' => 'info', 'section' => $section, 'label' => $section.'章节',
                    'detail' => '结构检查：章节存在；内容事实仍需人工核对来源。', 'ok' => true,
                    'message' => "章节存在：{$section}",
                ];
            }
        }

        if (mb_strlen($content, 'UTF-8') < 500) {
            $items[] = [
                'level' => 'warning',
                'section' => '整体',
                'label' => '内容长度', 'detail' => '草稿少于 500 字，建议补充来源资料。', 'ok' => false,
                'message' => '草稿内容过短（少于 500 字），建议补充更多来源资料。',
            ];
        }

        foreach (self::RISKY_WORDS as $word) {
            if (str_contains($content, $word)) {
                $items[] = [
                    'level' => 'danger',
                    'section' => '风险',
                    'label' => '高风险表述', 'detail' => "发现需人工核实的表述：{$word}", 'ok' => false,
                    'message' => "草稿中包含绝对化/高风险表述：{$word}",
                ];
            }
        }

        $todoCount = substr_count($content, '待补充');
        if ($todoCount > 0) {
            $items[] = [
                'level' => 'info',
                'section' => '证据',
                'label' => '待补充项', 'detail' => "有 {$todoCount} 处尚待补充证据。", 'ok' => false,
                'message' => "草稿中有 {$todoCount} 处待补充项，发布前请人工补齐。",
            ];
        }

        return $items;
    }

    /**
     * 发布草稿到知识库：复用既有 KnowledgeBase + DocumentIngestionService。
     *
     * 默认走异步：stageText 落盘登记 + 入队，发布请求立即返回「正在建立索引」。
     * 回退开关（config geo.async_ingest）关闭时退回同步 ingestText。
     *
     * @return array{knowledge_base:KnowledgeBase,document:?KnowledgeDocument,error:?string}
     */
    public function publishToKnowledgeBase(EnterpriseKnowledgeProject $project, string $content, ?KnowledgeBase $target = null, bool $createNew = false): array
    {
        \App\Support\ProjectKnowledge::assertOwnership($project);
        $content = trim($content);

        $previous = $project->publishedDocument;
        $samePublishedContent = $previous
            && ($previous->metadata['content_hash'] ?? null) === hash('sha256', $content)
            && in_array($previous->status, ['pending', 'processing', 'ready'], true);
        $knowledgeBase = $target ?? ($createNew && ! $samePublishedContent ? null : $project->publishedKnowledgeBase);
        if (! $knowledgeBase) {
            $knowledgeBase = KnowledgeBase::create([
                'tenant_id' => $project->tenant_id,
                'project_id' => $project->project_id,
                'name' => (string) $project->name,
                'description' => (string) $project->description,
                'status' => 'active',
            ]);
        }

        abort_unless((int) $knowledgeBase->tenant_id === (int) $project->tenant_id
            && (int) $knowledgeBase->project_id === (int) $project->project_id
            && $knowledgeBase->status === 'active', 409, '目标知识库不属于当前项目或已停用。');
        $document = null;
        $error = null;
        try {
            $metadata = [
                'source_type' => 'enterprise_knowledge',
                'enterprise_knowledge_project_id' => $project->id,
                'content_hash' => hash('sha256', $content),
                'source_ids' => $project->sources()->pluck('id')->all(),
                'source_urls' => $project->sources()->whereNotNull('source_url')->pluck('source_url')->all(),
                'supersedes_document_id' => $project->published_document_id,
            ];

            if ($previous && (int) $previous->knowledge_base_id === (int) $knowledgeBase->id
                && ($previous->metadata['content_hash'] ?? null) === $metadata['content_hash']
                && in_array($previous->status, ['pending', 'processing', 'ready'], true)) {
                return ['knowledge_base' => $knowledgeBase, 'document' => $previous, 'error' => null];
            }

            if (config('geo.async_ingest', true)) {
                $document = $this->ingestion->stageText(
                    (int) $knowledgeBase->id,
                    (string) $project->name,
                    $content,
                    $metadata
                );
                IngestKnowledgeDocumentJob::dispatch(TenantContext::id(), (int) $document->id);
            } else {
                $document = $this->ingestion->ingestText(
                    (int) $knowledgeBase->id,
                    (string) $project->name,
                    $content,
                    $metadata
                );
            }
        } catch (Throwable $exception) {
            // 同入库那条链路：只有 IngestionFailure 的原话能给客户看，原文进日志。
            $error = IngestionFailure::messageFor($exception, '发布到知识库失败，请重试；如反复出现请联系我们。');
            $document = $project->publishedDocument ?? null;

            Log::error('Enterprise knowledge publish failed', [
                'project_id' => $project->id,
                'knowledge_base_id' => $knowledgeBase->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return [
            'knowledge_base' => $knowledgeBase,
            'document' => $document,
            'error' => $error,
        ];
    }

    /**
     * 记录一条修订版本。
     */
    public function recordRevision(
        EnterpriseKnowledgeProject $project,
        string $content,
        string $source,
        string $summary,
        ?int $userId = null
    ): EnterpriseKnowledgeRevision {
        return EnterpriseKnowledgeRevision::create([
            'tenant_id' => $project->tenant_id,
            'enterprise_knowledge_project_id' => $project->id,
            'content' => $content,
            'summary' => $summary,
            'source' => $source,
            'created_by_user_id' => $userId,
            'content_hash' => hash('sha256', $content),
        ]);
    }

    /**
     * 仅当内容与最新修订不一致时才记录。
     */
    public function recordRevisionIfChanged(
        EnterpriseKnowledgeProject $project,
        string $content,
        string $source,
        string $summary,
        ?int $userId = null
    ): ?EnterpriseKnowledgeRevision {
        $hash = hash('sha256', $content);
        $latestHash = (string) EnterpriseKnowledgeRevision::query()
            ->where('enterprise_knowledge_project_id', $project->id)
            ->latest()
            ->value('content_hash');

        if ($latestHash === $hash) {
            return null;
        }

        return $this->recordRevision($project, $content, $source, $summary, $userId);
    }

    /**
     * 来源块：从项目来源中抽取可解析文本。
     *
     * @return list<array{name:string,type:string,content:string,characters:int}>
     */
    private function sourceBlocks(EnterpriseKnowledgeProject $project): array
    {
        $project->loadMissing('sources');

        return $project->sources
            ->sortBy([
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->map(function (EnterpriseKnowledgeSource $source): array {
                $content = trim((string) $source->content);

                return [
                    'name' => trim((string) $source->original_name) ?: '来源',
                    'type' => trim((string) $source->file_type) ?: 'text',
                    'content' => $content,
                    'characters' => mb_strlen($content, 'UTF-8'),
                ];
            })
            ->filter(static fn (array $source): bool => $source['content'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param  list<array{name:string,type:string,content:string,characters:int}>  $sourceBlocks
     */
    private function buildSourceText(array $sourceBlocks): string
    {
        $blocks = [];
        foreach ($sourceBlocks as $source) {
            $blocks[] = '# 来源：'.trim($source['name'])."\n\n".trim($source['content']);
        }

        return trim(implode("\n\n---\n\n", $blocks));
    }

    /**
     * 确定性结构化草稿：从来源中提取事实并套入标准知识稿骨架。
     *
     * @param  list<array{name:string,type:string,content:string,characters:int}>  $sourceBlocks
     * @return array{content:string,source:string,model_id:?int,error:?string}
     */
    private function buildFallbackDraft(
        EnterpriseKnowledgeProject $project,
        string $sourceText,
        array $sourceBlocks
    ): array {
        $intro = $this->candidateLines($sourceText, ['公司', '企业', '品牌', '团队', '成立', '总部', '定位', '专注', '提供', '服务', '客户', '官网', '使命', '愿景'], 10);
        $capabilities = $this->candidateLines($sourceText, ['产品', '能力', '功能', '服务', '平台', '系统', '支持', '方案', '技术', '模型', '监测', '分析', '诊断', 'API', '设备', '告警', '维保', '工单', '传感器', '接入', '数据'], 12);
        $scenarios = $this->candidateLines($sourceText, ['场景', '适用', '用于', '面向', '帮助', '解决', '痛点', '需求', '业务', '运营', '营销', '内容'], 10);
        $cases = $this->candidateLines($sourceText, ['案例', '客户', '项目', '交付', '合作', '上线', '部署', '使用', '成效', '数据', '结果', '实践'], 8);
        $questions = $this->extractQuestions($sourceText, 8);
        $riskLines = $this->candidateLines($sourceText, ['唯一', '第一', '保证', '100%', '永久', '无风险', '最权威', '最佳', '最强', '最高', '价格', '资质', '承诺', '法律'], 8);
        $highSignalLines = $this->highSignalLines($sourceText, 18);
        $signalList = $this->bulletListOrEmpty($highSignalLines, '资料较少，暂未提取到更多高价值信息。');

        $emptyIntro = '资料中未发现明确企业介绍信息，建议人工补充公司定位、服务对象、业务边界和核心优势。';
        $emptyCapability = '资料中未发现明确产品能力信息，建议人工补充产品、服务、交付能力、技术能力和适用边界。';
        $emptyScenario = '资料中未发现明确应用场景信息，建议人工补充使用对象、业务目标、适用条件和限制。';
        $emptyCase = '资料中未发现可核验案例信息；如需案例，请补传客户、项目、交付结果或可公开证明材料。';
        $emptyFaq = '资料中未发现明确 FAQ；建议人工补充用户常问问题和可核验回答。';
        $introList = $this->bulletListOrEmpty($intro, $emptyIntro);
        $capabilityList = $this->bulletListOrEmpty($capabilities, $emptyCapability);
        $scenarioList = $this->bulletListOrEmpty($scenarios, $emptyScenario);
        $caseList = $this->bulletListOrEmpty($cases, $emptyCase);
        $faqList = $this->bulletListOrEmpty($questions, $emptyFaq);
        $riskList = $this->bulletListOrEmpty($riskLines, '不使用"唯一""第一""100%保证""永久有效""无风险"等绝对化表述，除非有明确证据。');

        $content = <<<MARKDOWN
# {$project->name} 企业标准知识稿

## 企业介绍
{$introList}

## 业务信息摘要
{$signalList}

## 产品能力
{$capabilityList}

## 应用场景
{$scenarioList}

## 典型案例
{$caseList}

## FAQ
{$faqList}

## 禁用表述
{$riskList}
- 不编造资料中没有出现的客户案例、合作品牌、认证资质、价格、排名或效果数据。

## 风险与冲突
- 当前草稿由系统基于上传资料自动整理；请人工复核事实、术语、案例、价格、资质、效果承诺和法律边界。
- 如果不同来源出现新旧信息冲突，优先以最新、最正式、最可核验的资料为准。

## 待人工确认
- 未在资料中找到明确证据的内容应保持空缺，不应在正式知识库中扩写为事实。
- 发布前建议补齐关键来源、确认可公开信息范围，并删除内部敏感内容。
MARKDOWN;

        return [
            'content' => trim($content),
            'source' => 'system',
            'model_id' => null,
            'error' => null,
        ];
    }

    /**
     * @return list<string>
     */
    private function candidateLines(string $text, array $keywords, int $limit = 10): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];

        return collect($lines)
            ->map(fn (string $line): string => $this->normalizeCandidateLine($line))
            ->filter(fn (string $line): bool => $line !== '')
            ->filter(fn (string $line): bool => $this->containsAny($line, $keywords))
            ->filter(fn (string $line): bool => mb_strlen($line, 'UTF-8') >= 3)
            ->sortByDesc(fn (string $line): int => $this->signalScore($line))
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function highSignalLines(string $text, int $limit = 20): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];

        return collect($lines)
            ->map(fn (string $line): string => $this->normalizeCandidateLine($line))
            ->filter(fn (string $line): bool => mb_strlen($line, 'UTF-8') >= 16)
            ->filter(fn (string $line): bool => ! preg_match('/^#{1,6}\s*/u', $line))
            ->sortByDesc(fn (string $line): int => $this->signalScore($line))
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function extractQuestions(string $text, int $limit = 8): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $questions = [];

        foreach ($lines as $index => $line) {
            $line = $this->normalizeCandidateLine($line);
            if ($line === '' || ! preg_match('/(？|\?|^Q[:：]|^问[:：]|FAQ)/iu', $line)) {
                continue;
            }

            $next = $this->normalizeCandidateLine((string) ($lines[$index + 1] ?? ''));
            if ($next !== '' && preg_match('/^(A[:：]|答[:：])/iu', $next)) {
                $line .= ' '.$next;
            }

            $questions[] = $line;
            if (count($questions) >= $limit) {
                break;
            }
        }

        return collect($questions)->unique()->values()->all();
    }

    /**
     * @param  list<string>  $lines
     */
    private function bulletListOrEmpty(array $lines, string $empty): string
    {
        if ($lines === []) {
            return '- '.$empty;
        }

        return collect($lines)
            ->map(static fn (string $line): string => '- '.$line)
            ->implode("\n");
    }

    private function normalizeCandidateLine(string $line): string
    {
        $line = preg_replace('/\s+/u', ' ', trim($line)) ?: '';
        $line = trim($line, "- \t\n\r\0\x0B");
        $line = preg_replace('/^\d+[.、]\s*/u', '', $line) ?: $line;

        return trim(Str::limit($line, 220, '...'));
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && mb_stripos($haystack, $needle, 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }

    private function signalScore(string $line): int
    {
        $score = 0;
        foreach (['产品', '服务', '能力', '场景', '客户', '案例', '数据', '支持', '系统', '平台', '方案', 'FAQ', '问题', '风险'] as $keyword) {
            if (mb_stripos($line, $keyword, 0, 'UTF-8') !== false) {
                $score += 4;
            }
        }

        if (preg_match('/[：:]/u', $line)) {
            $score += 2;
        }

        $length = mb_strlen($line, 'UTF-8');
        if ($length >= 20 && $length <= 140) {
            $score += 3;
        }

        return $score;
    }
}
