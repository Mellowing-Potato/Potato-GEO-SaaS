<?php

namespace App\Services\SkillAgent;

use App\Jobs\ExecuteSkillJob;
use App\Models\Article;
use App\Models\SkillExecution;
use App\Models\SkillExecutionRelay;
use App\Services\Billing\ModelUsageRecorder;
use App\Services\Content\PromptTemplateService;
use App\Services\Contracts\AiProvider;
use App\Services\Contracts\KnowledgeRetrieval;
use App\Services\Contracts\SkillAgentRunner as SkillAgentRunnerContract;
use App\Services\Contracts\SkillRegistry;
use App\Services\DTOs\AiProviderCallResult;
use App\Services\DTOs\RelayProgress;
use App\Services\DTOs\SkillBrief;
use App\Services\DTOs\SkillDefinition;
use App\Services\DTOs\SkillExecutionResult;
use App\Services\Enums\ExecutionStatus;
use App\Services\Enums\SkillCategory;
use App\Services\Reports\ReportStorage;
use App\Support\BillingContext;
use App\Support\ExecutionTimeLimit;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Skill Agent 执行引擎实现
 *
 * 完整执行管线：
 *   1. 加载 Skill 资产（SKILL.md + references + render script + example JSON）
 *   2. 构建 Agent 上下文（system = SKILL.md 指令，user = 品牌信息 + 输出要求）
 *   3. 调用 LLM 生成结构化 JSON（有渲染脚本时）或 Markdown（无脚本时）
 *   4. 调用 Python 渲染脚本生成 MD / HTML / DOCX / PDF
 *   5. 质量门检查 + 自动创建文章
 *
 * 降级策略：
 *   - Skill 资产不存在 → 使用内置 prompt 模板
 *   - JSON 解析失败 → 回退保存原始 Markdown
 *   - 渲染脚本失败 → 保存 JSON 原文 + 错误信息
 */
class SkillAgentRunnerService implements SkillAgentRunnerContract
{
    private readonly PanoramaReportInputBuilder $panoramaReportInputBuilder;

    private readonly RenderSchemaRegistry $renderSchemaRegistry;

    /**
     * 提示词构造器（计划 §7 第 3 步拆出）。
     *
     * 分节 prompt / 单次调用 prompt / 兜底 prompt 那 500 行纯字符串构造搬去了
     * App\Services\SkillAgent\SkillPromptBuilder，本服务只保留三个转发方法
     * （见下方「提示词构造」小节），调用点与既有测试的调用方式一字未改。
     */
    private readonly SkillPromptBuilder $promptBuilder;

    /** 产出质量报告（计划 §7 第 3 步拆出，见下方同名转发方法） */
    private readonly SkillQualityReporter $qualityReporter;

    /** 分节续写摘要（计划 §7 第 3 步拆出，见下方同名转发方法） */
    private readonly SkillContinuityDigest $continuityDigest;

    /** 分节谱与初始状态（计划 §7 第 3 步拆出，见下方同名转发方法） */
    private readonly SkillChunkPlan $chunkPlan;

    /** 分节数据装配（计划 §7 第 3 步拆出，见下方同名转发方法） */
    private readonly SkillChunkAssembly $chunkAssembly;

    /** 交付内容组装（计划 §7 第 3 步拆出，见下方同名转发方法） */
    private readonly SkillDeliveryAssembler $deliveryAssembler;

    /** 模型调用与用量登记（计划 §7 第 3 步拆出，见下方同名转发方法） */
    private readonly SkillModelCaller $modelCaller;

    /** 执行前页面预检（计划 §7 第 3 步拆出，见下方同名转发方法） */
    private readonly SkillPagePreflight $pagePreflight;

    /** 分节收尾（计划 §7 第 3 步拆出，见下方同名转发方法） */
    private readonly SkillChunkFinalizer $chunkFinalizer;

    /** 分节执行循环（计划 §7 第 3 步拆出，见下方同名转发方法） */
    private readonly SkillChunkRunner $chunkRunner;

    /** 执行前准备（计划 §7 第 3 步拆出，见下方同名转发方法） */
    private readonly SkillRunPreparer $runPreparer;

    /** 单次调用那条路（计划 §7 第 3 步拆出，见下方同名转发方法） */
    private readonly SkillSingleCallRunner $singleCallRunner;

    /** 接力执行（计划 §7 第 3 步拆出，见下方三个公开转发方法） */
    private readonly SkillRelayCoordinator $relayCoordinator;

    /** 交付落库：建文章与最终质检报告（计划 §7 第 3 步拆出，见下方同名转发方法） */
    private readonly SkillArtifactWriter $artifactWriter;

    public function __construct(
        private readonly SkillRegistry $registry,
        private readonly AiProvider $aiProvider,
        private readonly KnowledgeRetrieval $knowledgeRetrieval,
        private readonly SkillAssetLoader $assetLoader,
        private readonly RenderBridgeService $renderBridge,
        private readonly PromptTemplateService $prompts,
        private readonly ModelUsageRecorder $usageRecorder,
        ?PanoramaReportInputBuilder $panoramaReportInputBuilder = null,
        ?RenderSchemaRegistry $renderSchemaRegistry = null,
        private readonly ?PageFetchService $pageFetch = null,
        ?SkillPromptBuilder $promptBuilder = null,
    ) {
        $this->panoramaReportInputBuilder = $panoramaReportInputBuilder ?? new PanoramaReportInputBuilder;
        $this->renderSchemaRegistry = $renderSchemaRegistry ?? new RenderSchemaRegistry;
        // 共用同一个 RenderSchemaRegistry 实例：构造提示词与执行期规范化必须是同一套谱。
        $this->promptBuilder = $promptBuilder ?? new SkillPromptBuilder($this->renderSchemaRegistry);
        // 质量报告是纯函数集合（无依赖），单独一个实例即可。
        $this->qualityReporter = new SkillQualityReporter;
        $this->continuityDigest = new SkillContinuityDigest;
        // 分节谱是纯常量 + 纯函数，无依赖。
        $this->chunkPlan = new SkillChunkPlan;
        // 装配器只读 RenderSchemaRegistry（拿 minimums），共用同一个实例。
        $this->chunkAssembly = new SkillChunkAssembly($this->renderSchemaRegistry);
        // 交付组装共用同一个全景输入补齐器与表头唯一权威。
        $this->deliveryAssembler = new SkillDeliveryAssembler($this->renderSchemaRegistry, $this->panoramaReportInputBuilder);
        // 模型调用器共用同一个 JSON 提取器（升级判定要用它区分"短但合法"与"真没内容"）。
        $this->modelCaller = new SkillModelCaller($this->aiProvider, $this->usageRecorder, $this->chunkAssembly);
        // 预检器共用同一个抓取服务替身（测试注入的那一个），不另起一份。
        $this->pagePreflight = new SkillPagePreflight($this->pageFetch);
        // 交付落库（建文章 / 最终质检报告）与收尾共用同一个质量评分器。
        $this->artifactWriter = new SkillArtifactWriter($this->qualityReporter);
        $this->chunkFinalizer = new SkillChunkFinalizer(
            $this->renderBridge, $this->deliveryAssembler, $this->qualityReporter,
            $this->modelCaller, $this->chunkAssembly, $this->artifactWriter,
        );
        // 执行器与收尾器共用同一批协作者（提示词/续写摘要/分节谱/分节装配/模型调用）。
        $this->chunkRunner = new SkillChunkRunner(
            $this->chunkPlan, $this->chunkAssembly, $this->promptBuilder, $this->continuityDigest,
            $this->modelCaller, $this->renderSchemaRegistry, $this->chunkFinalizer,
        );
        // 准备器由单次调用与接力共用 —— 接力每一步都是新进程，都会走一遍准备。
        $this->runPreparer = new SkillRunPreparer(
            $this->registry, $this->assetLoader, $this->knowledgeRetrieval, $this->renderBridge,
            $this->promptBuilder, $this->chunkPlan, $this->modelCaller, $this->pagePreflight,
        );
        $this->singleCallRunner = new SkillSingleCallRunner(
            $this->aiProvider, $this->usageRecorder, $this->modelCaller, $this->chunkAssembly,
            $this->deliveryAssembler, $this->qualityReporter, $this->artifactWriter,
            $this->renderBridge, $this->chunkRunner, $this->pagePreflight, $this->runPreparer,
        );
        $this->relayCoordinator = new SkillRelayCoordinator(
            $this->chunkPlan, $this->chunkRunner, $this->chunkFinalizer, $this->modelCaller, $this->runPreparer,
        );
    }

    // ════════════════════════════════════════════════════════
    //  提示词构造（计划 §7 第 3 步拆出，实现见 SkillPromptBuilder）
    //
    //  这里保留三个转发方法而不是直接改调用点，有两个理由：
    //   1. 调用点（prepareRun / runChunkTask）与行为完全不变，代码审查看 diff 就知道没改语义；
    //   2. tests/Feature/SkillPromptCharacterizationTest.php 用反射调这三个私有方法，
    //      拆分前后走的是同一条路径 —— 拆分前冻结的 sha256 才能在拆分后继续作证。
    //
    //  真正的构造逻辑（分节 prompt、单次调用 prompt、兜底 prompt 及共用片段）在
    //  SkillPromptBuilder 里，共 15 个方法约 500 行。
    // ════════════════════════════════════════════════════════

    private function buildChunkUserPrompt(
        SkillAssets $assets,
        SkillBrief $brief,
        string $knowledgeContext,
        array $spec,
        string $continuityDigest,
        ?PageObservation $pageObservation = null,
    ): string {
        return $this->promptBuilder->chunkUserPrompt($assets, $brief, $knowledgeContext, $spec, $continuityDigest, $pageObservation);
    }

    /**
     * @return array{0:string,1:string} [systemPrompt, userPrompt]
     */
    private function buildPipelinePrompts(
        SkillAssets $assets,
        SkillBrief $brief,
        string $knowledgeContext,
        bool $hasRenderPipeline,
        ?PageObservation $pageObservation = null,
    ): array {
        return $this->promptBuilder->pipelinePrompts($assets, $brief, $knowledgeContext, $hasRenderPipeline, $pageObservation);
    }

    private function buildFallbackUserPrompt(string $skillId, SkillBrief $brief, string $knowledgeContext): string
    {
        return $this->promptBuilder->fallbackUserPrompt($skillId, $brief, $knowledgeContext);
    }

    // ─────────────────────────────────────────────────────────────
    //  产出质量报告（计划 §7 第 3 步拆出，实现见 SkillQualityReporter）
    //
    //  同样只保留转发方法：两个调用点（executeWithinBillingContext / finishChunked）
    //  与 createArticleFromOutput 里的评分调用一字不改。
    // ─────────────────────────────────────────────────────────────

    private function buildQualityReport(
        string $content,
        SkillCategory $category,
        bool $jsonParsed,
        bool $expectsJson,
    ): array {
        return $this->qualityReporter->report($content, $category, $jsonParsed, $expectsJson);
    }

    private function evaluateQuality(string $content, SkillCategory $category): int
    {
        return $this->qualityReporter->evaluateQuality($content, $category);
    }

    // ─────────────────────────────────────────────────────────────
    //  分节续写摘要（计划 §7 第 3 步拆出，实现见 SkillContinuityDigest）
    // ─────────────────────────────────────────────────────────────

    private function continuityDigestFor(string $skillId, array $merged, ?string $currentChunkKey = null): string
    {
        return $this->continuityDigest->forSkill($skillId, $merged, $currentChunkKey);
    }

    private function markdownContinuityDigest(array $state): string
    {
        return $this->continuityDigest->markdown($state);
    }

    // ─────────────────────────────────────────────────────────────
    //  分节谱与初始状态（计划 §7 第 3 步拆出，实现见 SkillChunkPlan）
    //
    //  转发方法同理保留：runChunkTask / finishChunked / executeWithinBillingContext 里的
    //  调用一行不改；`specsAreMarkdown` 与 `MIN_USABLE_OUTPUT_CHARS` 是对外静态入口，
    //  调用点改成 SkillChunkPlan:: 前缀（单一事实源，不在服务里留副本）。
    // ─────────────────────────────────────────────────────────────

    private function chunkSpecsFor(string $skillId, bool $hasRenderPipeline): ?array
    {
        return $this->chunkPlan->specsFor($skillId, $hasRenderPipeline);
    }

    private function newChunkState(
        array $specs,
        string $knowledgeContext,
        float $startTime,
        ?PageObservation $pageObservation = null,
    ): array {
        return $this->chunkPlan->newChunkState($specs, $knowledgeContext, $startTime, $pageObservation);
    }

    private function markdownChunkData(array $spec, AiProviderCallResult $llm): ?array
    {
        return $this->chunkPlan->markdownChunkData($spec, $llm);
    }

    // ─────────────────────────────────────────────────────────────
    //  分节数据装配（计划 §7 第 3 步拆出，实现见 SkillChunkAssembly）
    // ─────────────────────────────────────────────────────────────

    private function extractJsonFromLlmOutput(string $content): ?array
    {
        return $this->chunkAssembly->extractJson($content);
    }

    private function mergeChunk(array $merged, array $chunkData, array $fields): array
    {
        return $this->chunkAssembly->merge($merged, $chunkData, $fields);
    }

    private function chunkFieldIssues(string $skillId, array $data, array $fields): array
    {
        return $this->chunkAssembly->fieldIssues($skillId, $data, $fields);
    }

    private function countClaimedFields(array $chunkData, array $fields): int
    {
        return $this->chunkAssembly->countClaimed($chunkData, $fields);
    }

    private function repairTasksFor(string $skillId, array $parent, string $key, array $issues, bool $truncated): array
    {
        return $this->chunkAssembly->repairTasks($skillId, $parent, $key, $issues, $truncated);
    }

    // ─────────────────────────────────────────────────────────────
    //  交付内容组装（计划 §7 第 3 步拆出，实现见 SkillDeliveryAssembler）
    //
    //  ⚠️ buildPublishableArticleContent 是**公开**方法（SkillAgentRunnerPromptTest
    //     直接调它验证"文章库只收可发布正文"），所以这里必须原样保留公开的转发方法。
    // ─────────────────────────────────────────────────────────────

    public function buildPublishableArticleContent(string $skillId, array $structured, string $fallback): string
    {
        return $this->deliveryAssembler->publishableArticleContent($skillId, $structured, $fallback);
    }

    private function withPublishableArticleChecks(array $report, string $skillId, string $content): array
    {
        return $this->deliveryAssembler->withPublishableChecks($report, $skillId, $content);
    }

    private function enrichJsonWithBrandInfo(array $data, SkillBrief $brief): array
    {
        return $this->deliveryAssembler->enrichWithBrandInfo($data, $brief);
    }

    private function prepareStructuredInput(array $data, SkillBrief $brief): array
    {
        return $this->deliveryAssembler->prepareStructuredInput($data, $brief);
    }

    private function assembleMarkdownReport(array $specs, array $merged, array $partials = []): string
    {
        return $this->deliveryAssembler->assembleMarkdownReport($specs, $merged, $partials);
    }

    // ─────────────────────────────────────────────────────────────
    //  模型调用与用量登记（计划 §7 第 3 步拆出，实现见 SkillModelCaller）
    // ─────────────────────────────────────────────────────────────

    private function generateAndRecord(
        string $userPrompt,
        string $systemPrompt,
        int $maxTokens,
        string $primaryModel,
        SkillBrief $brief,
        array $recordMetadata = [],
        bool $allowEscalation = true,
        bool $jsonObject = false,
    ): array {
        return $this->modelCaller->generateAndRecord(
            $userPrompt,
            $systemPrompt,
            $maxTokens,
            $primaryModel,
            $brief,
            $recordMetadata,
            $allowEscalation,
            $jsonObject,
        );
    }

    private function visualInputs(SkillBrief $brief): array
    {
        return $this->modelCaller->visualInputs($brief);
    }

    private function modelForSkill(SkillDefinition $skill, SkillBrief $brief): string
    {
        return $this->modelCaller->modelForSkill($skill, $brief);
    }

    private function buildFallbackSystemPrompt(): string
    {
        return $this->modelCaller->buildFallbackSystemPrompt();
    }

    private function observationFromState(array $state): ?PageObservation
    {
        return $this->modelCaller->observationFromState($state);
    }

    private function isCancelled(?int $executionId): bool
    {
        return $this->modelCaller->isCancelled($executionId);
    }

    private function fallbackModelFor(string $model): ?string
    {
        return $this->modelCaller->fallbackModelFor($model);
    }

    private function recoveryModelForReasoningStarvation(string $model, array $gateway = []): ?string
    {
        return $this->modelCaller->recoveryModelForReasoningStarvation($model, $gateway);
    }

    public function execute(SkillBrief $brief): SkillExecutionResult
    {
        // 认领计费主体：技能执行大多在队列里跑，那里 Auth 是空的，事前余额闸
        // 认不出该扣谁的钱。SkillBrief::userId 是这条路上唯一还知道付款人的地方。
        return BillingContext::scoped(
            $brief->userId,
            'skill:'.$brief->skillId,
            fn (): SkillExecutionResult => ExecutionTimeLimit::scoped(
                420,
                fn (): SkillExecutionResult => $this->executeWithinBillingContext($brief),
            ),
        );
    }

    // ─────────────────────────────────────────────────────────────
    //  执行前准备与单次调用（计划 §7 第 3 步拆出，实现见 SkillRunPreparer / SkillSingleCallRunner）
    //
    //  这一块是**扣费边界**：调用方（execute / 接力）先认领计费主体、再进来，
    //  所以它只负责"在已认领的计费上下文里把这一单跑完"。
    //
    //  prepareRun 的语义（含接力为什么要回传知识上下文/页面观察）已随实现搬去
    //  SkillRunPreparer::prepare()，服务侧只留转发 —— 契约没变，调用点一行未改。
    // ─────────────────────────────────────────────────────────────

    private function executeWithinBillingContext(SkillBrief $brief): SkillExecutionResult
    {
        return $this->singleCallRunner->run($brief);
    }

    private function prepareRun(
        SkillBrief $brief,
        ?string $knowledgeContextOverride = null,
        ?PageObservation $observationOverride = null,
        bool $relayProbe = false,
    ): array {
        return $this->runPreparer->prepare($brief, $knowledgeContextOverride, $observationOverride, $relayProbe);
    }

    // ─────────────────────────────────────────────────────────────
    //  执行前页面预检（计划 §7 第 3 步拆出，实现见 SkillPagePreflight）
    // ─────────────────────────────────────────────────────────────

    private function observePage(SkillBrief $brief): ?PageObservation
    {
        return $this->pagePreflight->observe($brief);
    }

    private function pageFetchFailureResult(
        SkillBrief $brief,
        PageObservation $observation,
        ?int $tenantId,
        float $startTime,
    ): SkillExecutionResult {
        return $this->pagePreflight->failureResult($brief, $observation, $tenantId, $startTime);
    }

    // ─────────────────────────────────────────────────────────────
    //  分节执行循环（计划 §7 第 3 步拆出，实现见 SkillChunkRunner）
    // ─────────────────────────────────────────────────────────────

    private function executeChunked(
        SkillDefinition $skill,
        SkillAssets $assets,
        SkillBrief $brief,
        string $systemPrompt,
        string $knowledgeContext,
        float $startTime,
        string $tenantId,
        array $specs,
        ?PageObservation $pageObservation = null,
    ): SkillExecutionResult {
        return $this->chunkRunner->execute(
            $skill, $assets, $brief, $systemPrompt, $knowledgeContext, $startTime, $tenantId, $specs, $pageObservation,
        );
    }

    private function runChunkTask(
        array &$state,
        SkillDefinition $skill,
        SkillAssets $assets,
        SkillBrief $brief,
        string $systemPrompt,
        bool $enforceRecoveryDeadline,
    ): ?AiProviderCallResult {
        return $this->chunkRunner->runTask($state, $skill, $assets, $brief, $systemPrompt, $enforceRecoveryDeadline);
    }

    // ─────────────────────────────────────────────────────────────
    //  分节收尾与交付落库（计划 §7 第 3 步拆出，实现见 SkillChunkFinalizer / SkillArtifactWriter）
    // ─────────────────────────────────────────────────────────────

    private function finishChunked(
        array $state,
        SkillDefinition $skill,
        SkillAssets $assets,
        SkillBrief $brief,
        string $tenantId,
        ?AiProviderCallResult $lastLlm,
    ): SkillExecutionResult {
        return $this->chunkFinalizer->finish($state, $skill, $assets, $brief, $tenantId, $lastLlm);
    }

    private function isContentSkill(SkillCategory $category): bool
    {
        return $this->artifactWriter->isContentSkill($category);
    }

    private function storeFinalQualityReport(array $report, array &$outputPaths): void
    {
        $this->artifactWriter->storeFinalQualityReport($report, $outputPaths);
    }

    private function createArticleFromOutput(SkillBrief $brief, string $content, string $skillId, ?int $executionId = null): ?Article
    {
        return $this->artifactWriter->createArticleFromOutput($brief, $content, $skillId, $executionId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  接力执行（relay，计划 §7 第 3 步拆出，实现见 SkillRelayCoordinator）
    //
    //  为什么需要接力、超时梯子怎么算、哪些路径走接力 —— 那段完整说明已随实现
    //  搬进 SkillRelayCoordinator 的类注释。这里只保留契约上的三个公开入口。
    // ─────────────────────────────────────────────────────────────────────────

    public function beginRelay(SkillBrief $brief): ?RelayProgress
    {
        return $this->relayCoordinator->begin($brief);
    }

    public function advanceRelay(SkillBrief $brief): RelayProgress
    {
        return $this->relayCoordinator->advance($brief);
    }

    public function finalizeRelay(SkillBrief $brief): SkillExecutionResult
    {
        return $this->relayCoordinator->finalize($brief);
    }

    public function executeAsync(SkillBrief $brief): int
    {
        $tenantId = TenantContext::id();
        if (! $tenantId) {
            throw new \RuntimeException('异步执行需要租户上下文');
        }

        // 查询 Skill 定义以填充冗余字段
        $skill = $this->registry->find($brief->skillId);

        // 创建 pending 状态的执行记录
        $execution = SkillExecution::create([
            'project_id' => $brief->projectId,
            'user_id' => $brief->userId,
            'skill_id' => $skill->id,
            'skill_name' => $skill->name,
            'category' => $skill->category->value,
            'stage_index' => $skill->stageIndex,
            'status' => ExecutionStatus::Pending->value,
            'execution_mode' => 'async',
            'client_request_key' => $brief->clientRequestKey,
            'input_data' => $brief->auditableInputData(),
        ]);

        $queuedBrief = new SkillBrief(
            skillId: $brief->skillId,
            projectId: $brief->projectId,
            userId: $brief->userId,
            fields: $brief->fields,
            query: $brief->query,
            contextFromPreviousStage: $brief->contextFromPreviousStage,
            executionId: $execution->id,
            materialRefs: $brief->materialRefs,
            clientRequestKey: $brief->clientRequestKey,
        );

        // 推入队列
        ExecuteSkillJob::dispatch(
            $execution->id,
            $queuedBrief->toArray(),
            $tenantId,
        );

        Log::info('Skill execution queued', [
            'execution_id' => $execution->id,
            'skill_id' => $brief->skillId,
            'project_id' => $brief->projectId,
            'tenant_id' => $tenantId,
        ]);

        return $execution->id;
    }

    public function getExecutionStatus(int $executionId): SkillExecutionResult
    {
        $execution = SkillExecution::find($executionId);
        if (! $execution) {
            return SkillExecutionResult::failure('', '执行记录不存在');
        }

        $status = ExecutionStatus::tryFrom($execution->status);
        $outputData = $execution->output_data ?? [];

        return new SkillExecutionResult(
            executionId: $execution->id,
            skillId: $execution->skill_id,
            success: $status === ExecutionStatus::Completed,
            outputPaths: array_filter($outputData, fn ($v) => is_string($v) && ! str_contains($v, "\n")),
            intermediateData: $outputData,
            qualityReport: $execution->quality_report,
            durationSeconds: (float) ($execution->duration_seconds ?? 0),
            tokenUsage: $execution->token_usage ?? 0,
            errorMessage: $execution->error_message,
        );
    }

    public function cancel(int $executionId): bool
    {
        $execution = SkillExecution::find($executionId);
        if (! $execution) {
            return false;
        }

        $status = ExecutionStatus::tryFrom($execution->status);

        if (! $status || ! $status->isCancellable()) {
            return false;
        }

        // 使用条件更新防止竞态：只有当状态仍为 pending/running 时才取消
        $rows = SkillExecution::where('id', $executionId)
            ->whereIn('status', [
                ExecutionStatus::Pending->value,
                ExecutionStatus::Running->value,
            ])
            ->update([
                'status' => ExecutionStatus::Cancelled->value,
                'error_message' => '用户手动取消',
            ]);

        if ($rows === 0) {
            return false;
        }

        // 对于 pending 的任务，尝试从队列中移除
        if ($status === ExecutionStatus::Pending) {
            // 使用精确 JSON key 匹配，避免子串碰撞（如 ID 42 误匹配 420）
            $exactPayload = '"executionId":'.$executionId.',';
            DB::table('jobs')
                ->where('payload', 'LIKE', '%'.$exactPayload.'%')
                ->delete();
        }

        Log::info('Skill execution cancelled', [
            'execution_id' => $executionId,
            'previous_status' => $status->value,
        ]);

        return true;
    }

    // ════════════════════════════════════════════════════════
    //  完整管线模式 Prompt 构建
    // ════════════════════════════════════════════════════════

    // ════════════════════════════════════════════════════════
    //  降级模式（无 SKILL.md 资产时）
    // ════════════════════════════════════════════════════════

    // ════════════════════════════════════════════════════════
    //  JSON 提取 & 品牌信息注入
    // ════════════════════════════════════════════════════════

    // ════════════════════════════════════════════════════════
    //  质量门
    // ════════════════════════════════════════════════════════

    // ════════════════════════════════════════════════════════
    //  辅助方法
    // ════════════════════════════════════════════════════════

    /** 记录每次真实模型调用；记账故障不能吞掉已经生成的报告。 */
}
