<?php

namespace App\Services\SkillAgent;

use App\Models\SkillExecution;
use App\Services\DTOs\AiProviderCallResult;
use App\Services\DTOs\SkillBrief;
use App\Services\DTOs\SkillDefinition;
use App\Services\DTOs\SkillExecutionResult;
use App\Services\Reports\ReportStorage;
use Illuminate\Support\Facades\Log;

/**
 * 分节收尾（计划 §7 第 3 步：把 `SkillAgentRunnerService` 按职责拆小，「渲染」这块）。
 *
 * 所有段跑完之后那一段路：完整性闸 → 合并各段 → 补齐 → 渲染 → 建文章 → 出结果。
 * 同进程版跑完 while 循环直接调它；接力版由最后一个队列任务调它（那个任务是干净的，
 * 渲染要起的 Python 能独占一个新进程）。
 *
 * 三道闸的顺序是有意的，搬动时一个字都不能改：
 *   1. **完整性闸在最前面** —— 缺任何一段就整单判失败，绝不进渲染管线；
 *      否则缺的字段会被补齐逻辑填成空数组，客户拿到一份"看着完整、实则整章缺内容"的报告；
 *   2. 渲染脚本判内容不合格（blocking）时先失败收口，**不能先建文章**；
 *   3. 最后才把质检报告写回下载文件（`SkillArtifactWriter`），保证下载到的就是这次落库的结论。
 *
 * 判据：tests/Feature/PanoramaChunkedAnalysisTest.php（每段只计费一次、缺段判失败）、
 * RoadmapChunkRecoveryTest / IntentChunkRecoveryTest（补跑与完整性闸）、
 * SkillArtifactWriterTest（建文章与质检报告落库）。
 */
class SkillChunkFinalizer
{
    public function __construct(
        private readonly RenderBridgeService $renderBridge,
        private readonly SkillDeliveryAssembler $deliveryAssembler,
        private readonly SkillQualityReporter $qualityReporter,
        private readonly SkillModelCaller $modelCaller,
        private readonly SkillChunkAssembly $chunkAssembly,
        private readonly SkillArtifactWriter $artifactWriter,
    ) {}

    /**
     * 所有段跑完之后的收尾：完整性闸 → 合并 → 补齐 → 渲染 → 出结果。
     *
     * 同进程版跑完 while 循环后直接调它；接力版由最后一个队列任务调它，
     * 而且那个任务是干净的（没发过模型请求），渲染要起的 Python 独占一个新进程。
     */
    public function finish(
        array $state,
        SkillDefinition $skill,
        SkillAssets $assets,
        SkillBrief $brief,
        string $tenantId,
        ?AiProviderCallResult $lastLlm,
    ): SkillExecutionResult {
        $specs = $state['specs'];
        $chunkMeta = $state['chunk_meta'];
        $callCount = $state['call_count'];
        $tokenSum = $state['token_sum'];
        $startTime = $state['started_at'];
        $primaryModel = $this->modelCaller->modelForSkill($skill, $brief);
        $merged = $state['merged'];
        $parsedChunks = 0;

        foreach ($specs as $spec) {
            if ($chunkMeta[$spec['key']]['complete'] ?? false) {
                $parsedChunks++;
            }
            $output = $state['chunk_outputs'][$spec['key']] ?? [];
            $merged = str_contains($brief->skillId, 'intent-miner')
                ? array_replace($merged, $output) // 显式空来源/可选文案也须保留，与字段验收口径一致。
                : $this->chunkAssembly->merge($merged, $output, $spec['fields']);
        }
        $markdownSections = SkillChunkPlan::specsAreMarkdown($specs);

        // 🔴 分节谱的 raw_output 就是交付物本身：ExecuteSkillJob 与 SkillExecutionController
        //    都会无条件把它落进 output_data.result_text，客户在工作台上看到的就是这段字。
        //    所以必须是干净拼接的正文，不能带 raw_parts 那些 <!-- chunk: --> 诊断注释。
        //    字段谱那条路的 raw_output 只是诊断材料（真交付物是渲染脚本的产物），维持原样。
        $rawOutput = $markdownSections
            ? $this->deliveryAssembler->assembleMarkdownReport($specs, $merged, $state['chunk_partials'] ?? [])
            : implode("\n\n", $state['raw_parts']);

        // 用户取消：返回已完成段落的中间结果（success=true 语义）。状态由 cancel 接口保持
        // cancelled，controller 侧有取消保护不会覆盖；取消后没必要为不存在的终态跑渲染管线。
        if ($state['cancelled']) {
            $duration = round(microtime(true) - $startTime, 2);

            Log::info('Chunked execution cancelled by user', [
                'skill_id' => $brief->skillId,
                'chunks_completed' => $callCount,
                'chunks_parsed' => $parsedChunks,
                'tokens' => $tokenSum,
            ]);

            return new SkillExecutionResult(
                executionId: 0,
                skillId: $brief->skillId,
                success: true,
                outputPaths: [],
                intermediateData: [
                    'skill_id' => $brief->skillId,
                    'requested_model' => $primaryModel,
                    'raw_output' => $rawOutput,
                    'generated_at' => now()->toISOString(),
                    'tenant_id' => $tenantId,
                    'project_id' => $brief->projectId,
                    'pipeline_mode' => $markdownSections ? 'fallback' : 'full',
                    'chunked' => true,
                    'chunk_format' => $markdownSections ? 'markdown' : 'json',
                    'cancelled' => true,
                    'chunk_count' => count($specs),
                    'chunks_parsed' => $parsedChunks,
                    'chunk_meta' => $chunkMeta,
                ],
                qualityReport: null,
                durationSeconds: $duration,
                llmCallCount: $callCount,
                tokenUsage: $tokenSum,
                lastLlmResult: $lastLlm,
            );
        }

        // ─── 分段完整性闸 ───
        // 只要有一段没成功（调用失败 / 截断 / 无可用字段 / 意图挖掘漏字段或核心产物为空），
        // 这次产出就不是一份完整报告，整单判失败（status=failed），不进渲染管线。
        // 此前只要有一段成功就照常往下走：缺的字段在 prepareStructuredInput 里被补齐逻辑
        // 填成空数组或模板行，用户拿到一份看着完整、实则整章缺内容的报告，还被标成
        // completed（老板批 C 指名要堵的就是这两件事）。
        // 每次调用（含补跑和升级）的账已由 generateAndRecord 实时记好，
        // 这里把 tokens 与调用次数照实带回去，既不补扣也不退。
        $failedLabels = [];
        foreach ($specs as $spec) {
            $meta = $chunkMeta[$spec['key']] ?? null;
            if ($meta === null || ($meta['complete'] ?? false) !== true) {
                $failedLabels[] = $spec['label'] ?? $spec['key'];
            }
        }

        // 逃生阀（config/geo.php 的 chunk_strict，默认严格）：置 false 退回旧行为——
        // 只要还有一段有内容就照常出报告。留它是为了上线后失败率万一爆掉能改一行 env
        // 止血，不用重建镜像。日志两种模式都照记，频率数据不会断。
        $strictGate = (bool) config('geo.chunk_strict', true);
        $invalidCoreAnswer = $brief->skillId === 'yao-geo-explainer-builder'
            && ! $this->deliveryAssembler->coreAnswerCheck($merged)['passed'];
        if ($invalidCoreAnswer && $failedLabels === []) {
            $failedLabels[] = '核心回答';
        }

        if ($failedLabels !== []) {
            Log::warning('Chunked execution incomplete', [
                'skill_id' => $brief->skillId,
                'strict_gate' => $strictGate,
                'chunk_count' => count($specs),
                'chunks_parsed' => $parsedChunks,
                'failed_chunks' => $failedLabels,
                'chunk_meta' => $chunkMeta,
                'tokens' => $tokenSum,
            ]);
        }

        // 严格模式：缺任何一段都判失败。逃生阀开着时：只有一段内容都没有才判失败。
        if ($failedLabels !== [] && ($strictGate || $parsedChunks === 0 || $invalidCoreAnswer)) {
            $totalChunks = count($specs);
            $failedCount = count($failedLabels);

            // 文案不能带斜杠、反斜杠、换行：SkillExecutionController::friendlySkillError()
            // 见到这三样会把整句话换成一句英文通用错误，用户就看不到是哪几段没成。
            //
            // 量词跟着谱走：字段谱拆的是「段」（客户看不到的内部分片），
            // 分节谱拆的是「节」（报告目录上真有这一节）。说错了客户对不上号。
            $unit = $markdownSections ? '节' : '段';
            $message = $failedCount === $totalChunks
                ? "报告分 {$totalChunks} {$unit}生成，{$totalChunks} {$unit}都没有产出可用内容。本次不出报告，请重试。"
                : "报告分 {$totalChunks} {$unit}生成，其中 {$failedCount} {$unit}没有成功："
                    .implode('、', $failedLabels)
                    .'。为避免给出缺内容的报告，本次判为失败，请重试。';

            return new SkillExecutionResult(
                executionId: 0,
                skillId: $brief->skillId,
                success: false,
                outputPaths: [],
                intermediateData: [
                    'skill_id' => $brief->skillId,
                    'requested_model' => $primaryModel,
                    'raw_output' => $rawOutput,
                    'generated_at' => now()->toISOString(),
                    'tenant_id' => $tenantId,
                    'project_id' => $brief->projectId,
                    'pipeline_mode' => $markdownSections ? 'fallback' : 'full',
                    'chunked' => true,
                    'chunk_format' => $markdownSections ? 'markdown' : 'json',
                    'chunk_count' => count($specs),
                    'chunks_parsed' => $parsedChunks,
                    'chunk_meta' => $chunkMeta,
                    'failed_chunks' => $failedLabels,
                ],
                qualityReport: null,
                durationSeconds: round(microtime(true) - $startTime, 2),
                llmCallCount: $callCount,
                tokenUsage: $tokenSum,
                errorMessage: $message,
                lastLlmResult: $lastLlm,
            );
        }

        $intermediateData = [
            'skill_id' => $brief->skillId,
            'requested_model' => $primaryModel,
            'raw_output' => $rawOutput,
            'generated_at' => now()->toISOString(),
            'tenant_id' => $tenantId,
            'project_id' => $brief->projectId,
            // 分节谱走的是「直出 Markdown」那条管线，与单次调用无渲染脚本时同口径。
            'pipeline_mode' => $markdownSections ? 'fallback' : 'full',
            'chunked' => true,
            'chunk_format' => $markdownSections ? 'markdown' : 'json',
            'chunk_count' => count($specs),
            'chunks_parsed' => $parsedChunks,
            'chunk_meta' => $chunkMeta,
        ];

        // 逃生阀开着时被放行的残缺报告：把缺了哪几段记在执行记录上。
        // 只有这一种情况会出现这个键——事后要查「哪些报告是靠松闸出去的」就查它。
        if ($failedLabels !== []) {
            $intermediateData['incomplete_chunks'] = $failedLabels;
            $intermediateData['chunk_gate'] = 'loose';
        }

        $outputPaths = [];
        $renderResult = null;

        if ($markdownSections) {
            // 分节谱没有渲染脚本，各节正文拼起来就是交付物，不进 Python 排版管线。
            $outputPaths['result_text'] = $rawOutput;
            $markdownContent = $rawOutput;
        } else {
            // 合并后统一补齐（min-rows / 别名 / 缺省），得到完整 report_input
            $structured = $this->deliveryAssembler->prepareStructuredInput($merged, $brief);
            $intermediateData['structured'] = $structured;

            // ─── 渲染管线 ───
            $renderExecutionId = $brief->executionId ?? time();
            $renderResult = $this->renderBridge->render($structured, $assets, $renderExecutionId);
            $intermediateData['format_status'] = $renderResult->formatStatus;

            if ($renderResult->success) {
                $outputPaths = $renderResult->renderedPaths;
                $intermediateData['render_success'] = true;
                $intermediateData['rendered_formats'] = $renderResult->availableFormats();
            } else {
                Log::warning('Chunked render failed', ['skill_id' => $brief->skillId, 'error' => $renderResult->errorMessage]);
                $intermediateData['render_error'] = $renderResult->errorMessage;
                $outputPaths['result_text'] = $rawOutput;
            }

            // ─── Markdown 内容（渲染成功优先用渲染产物） ───
            $markdownContent = $rawOutput;
            if ($renderResult->success && isset($renderResult->renderedPaths['markdown'])) {
                try {
                    $stored = ReportStorage::readDisk($renderResult->renderedPaths['markdown'])->get($renderResult->renderedPaths['markdown']);
                    if ($stored) {
                        $markdownContent = $stored;
                    }
                } catch (\Throwable) {
                    // 保留原始拼接输出
                }
            }
        }

        // 与单次执行一致，渲染器判定内容不合格时先失败收口，不能先创建文章。
        if ($brief->skillId === 'yao-geo-title-optimizer') {
            $titleIssues = $this->deliveryAssembler->titleDeliveryIssues($intermediateData['structured'] ?? [], $renderResult);
            if ($titleIssues !== []) {
                $outputPaths['result_text'] ??= $rawOutput;
                return new SkillExecutionResult(
                    executionId: 0, skillId: $brief->skillId, success: false,
                    outputPaths: $outputPaths, intermediateData: $intermediateData,
                    qualityReport: ['passed' => false, 'blocking' => true, 'issues' => $titleIssues],
                    durationSeconds: round(microtime(true) - $startTime, 2),
                    llmCallCount: $callCount, tokenUsage: $tokenSum,
                    errorMessage: '标题报告未达到交付标准：'.implode('；', $titleIssues),
                    lastLlmResult: $lastLlm,
                );
            }
        }
        $blockingQualityReport = $renderResult?->qualityReport;
        if (($blockingQualityReport['blocking'] ?? false) === true) {
            $issues = array_values(array_filter(
                is_array($blockingQualityReport['issues'] ?? null) ? $blockingQualityReport['issues'] : [],
                'is_string',
            ));

            return new SkillExecutionResult(
                executionId: 0,
                skillId: $brief->skillId,
                success: false,
                outputPaths: $outputPaths,
                intermediateData: $intermediateData,
                qualityReport: $blockingQualityReport,
                durationSeconds: round(microtime(true) - $startTime, 2),
                llmCallCount: $callCount,
                tokenUsage: $tokenSum,
                errorMessage: $issues === []
                    ? '报告已经生成，但尚未达到正式交付标准，当前作为编辑稿保留。'
                    : '报告已经生成，但尚未达到正式交付标准：'.implode('；', array_slice($issues, 0, 5)).'。当前作为编辑稿保留。',
                lastLlmResult: $lastLlm,
            );
        }

        // panorama-audit 属 GeoStrategy，isContentSkill=false，由控制器建文章，此处不建
        if ($this->artifactWriter->isContentSkill($skill->category)) {
            $articleContent = $skill->id === 'yao-geo-content-refiner'
                ? $this->deliveryAssembler->publishableArticleContent($skill->id, $intermediateData['structured'] ?? [], '')
                : ($renderResult?->publishableArticle ?? $this->deliveryAssembler->publishableArticleContent(
                    $skill->id, $intermediateData['structured'] ?? [], $markdownContent,
                ));
            if ($skill->id === 'yao-geo-content-refiner' && $articleContent === '') {
                $intermediateData['delivery_incomplete'] = 'refined_article_missing_or_invalid';
                $outputPaths['result_text'] ??= $rawOutput;
                return new SkillExecutionResult(
                    executionId: 0,
                    skillId: $brief->skillId,
                    success: false,
                    outputPaths: $outputPaths,
                    intermediateData: $intermediateData,
                    qualityReport: ['blocking' => true, 'issues' => ['优化正文 refined_article 缺失或结构不合格']],
                    durationSeconds: round(microtime(true) - $startTime, 2),
                    llmCallCount: $callCount,
                    tokenUsage: $tokenSum,
                    errorMessage: '优化正文缺失或结构不合格，报告作为诊断结果保留，未创建文章。',
                    lastLlmResult: $lastLlm,
                );
            }
            $article = $this->artifactWriter->createArticleFromOutput($brief, $articleContent, $skill->id, $brief->executionId);
            if ($article) {
                $intermediateData['article_id'] = $article->id;
                $intermediateData['article_title'] = $article->title;
                $outputPaths['article'] = "/articles/{$article->id}";
            }
        }

        // jsonParsed：分节谱产的是 Markdown 正文，本来就没有 JSON 可解析，传 true 会让
        // 质量报告凭空多出一条「结构化解析通过」。
        $qualityReport = $renderResult?->qualityReport
            ?? $this->qualityReporter->report(
                $markdownContent,
                $skill->category,
                ! $markdownSections,
                ! $markdownSections,
            );
        if (isset($articleContent)) {
            $qualityReport = $this->deliveryAssembler->withPublishableChecks($qualityReport, $skill->id, $articleContent, $intermediateData['structured'] ?? []);
            $this->artifactWriter->storeFinalQualityReport($qualityReport, $outputPaths);
        }

        $duration = round(microtime(true) - $startTime, 2);

        Log::info('Chunked execution completed', [
            'skill_id' => $brief->skillId,
            'duration' => $duration,
            'calls' => $callCount,
            'chunks_parsed' => $parsedChunks,
            'tokens' => $tokenSum,
            'render_success' => $renderResult?->success ?? false,
        ]);

        return new SkillExecutionResult(
            executionId: 0,
            skillId: $brief->skillId,
            success: true,
            outputPaths: $outputPaths,
            intermediateData: $intermediateData,
            qualityReport: $qualityReport,
            durationSeconds: $duration,
            llmCallCount: $callCount,
            tokenUsage: $tokenSum,
            lastLlmResult: $lastLlm,
        );
    }
}
