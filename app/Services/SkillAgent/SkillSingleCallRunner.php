<?php

namespace App\Services\SkillAgent;

use App\Models\SkillExecution;
use App\Services\Billing\ModelUsageRecorder;
use App\Services\Billing\PreparedModelAttempt;
use App\Services\Billing\UnresolvedModelAttempt;
use App\Services\Billing\UnresolvedEmbeddingAttempt;
use App\Services\Billing\UnrecordedModelResult;
use App\Services\Contracts\AiProvider;
use App\Services\DTOs\AiProviderCallResult;
use App\Services\DTOs\SkillBrief;
use App\Services\DTOs\SkillExecutionResult;
use App\Services\DTOs\SkillDefinition;
use App\Services\Reports\ReportStorage;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 单次调用那条路 = **扣费边界之内**的完整一单（计划 §7 第 3 步：把 `SkillAgentRunnerService`
 * 按职责拆小，最后动的那块「扣费」）。
 *
 * ⚠️ 前提由调用方负责：进来之前已经认领了计费主体（`BillingContext::scoped`）并设好执行时限。
 * 本类只做"在已认领的上下文里把这一单跑完"，**自己不去开计费上下文** —— 这是刻意分开的：
 * 计费主体的认领点在服务入口（同步控制器 / 队列任务两条路各一次），一单绝不能认领两次。
 *
 * 这一单的完整动作：准备（`SkillRunPreparer`）→ 页面预检（抓不到就零扣费判失败）→
 * 组装 prompt → 调模型（每次尝试都记账）→ 升级/恢复 → 解析 → 渲染 → 建文章 → 出结果。
 * 分段技能在这里被挡去 `SkillChunkRunner`（同进程版）。
 *
 * 判据（都是集成级，因为这条路上每一步都真的落库/花钱）：
 *   - PageAuditRunnerTest：抓不到页面 = 0 次调用 0 词元，绝不"让模型凭印象编"；
 *   - SkillExecutionReliabilityTest：重复投递不重复调用；
 *   - SkillWorkspaceFlowTest / ReportAssetFlowTest / SkillOutputGuardTest：报告、文章、质量闸；
 *   - PanoramaChunkedAnalysisTest：分段技能被正确分流到分节执行器。
 */
class SkillSingleCallRunner
{
    public function __construct(
        private readonly AiProvider $aiProvider,
        private readonly ModelUsageRecorder $usageRecorder,
        private readonly SkillModelCaller $modelCaller,
        private readonly SkillChunkAssembly $chunkAssembly,
        private readonly SkillDeliveryAssembler $deliveryAssembler,
        private readonly SkillQualityReporter $qualityReporter,
        private readonly SkillArtifactWriter $artifactWriter,
        private readonly RenderBridgeService $renderBridge,
        private readonly SkillChunkRunner $chunkRunner,
        private readonly SkillPagePreflight $pagePreflight,
        private readonly SkillRunPreparer $runPreparer,
    ) {}
    public function run(SkillBrief $brief): SkillExecutionResult
    {
        $startTime = microtime(true);
        $tenantId = TenantContext::id();
        $deliveryContext = [];
        $attemptPrepared = false;
        $usingChunked = false;

        Log::info('Skill execution started', [
            'skill_id' => $brief->skillId,
            'project_id' => $brief->projectId,
            'tenant_id' => $tenantId,
        ]);

        try {
            [
                'skill' => $skill,
                'assets' => $assets,
                'hasRenderPipeline' => $hasRenderPipeline,
                'knowledgeContext' => $knowledgeContext,
                'systemPrompt' => $systemPrompt,
                'userPrompt' => $userPrompt,
                'chunkSpecs' => $chunkSpecs,
                'pageObservation' => $pageObservation,
            ] = $this->runPreparer->prepare($brief);

            // 抓不到页面就到此为止，一次模型都不调。
            //
            // 🔴 绝不允许「抓不到就退回让模型凭印象编」—— 那正是 2026-09-06 修掉的那类
            //    假成功，而且更坏：报告读起来像真查过。这里提前返回，客户零扣费。
            if ($pageObservation !== null && ! $pageObservation->ok) {
                return $this->pagePreflight->failureResult($brief, $pageObservation, $tenantId, $startTime);
            }

            $useChunked = $chunkSpecs !== null;

            // 分节报告只走接力，不走同进程。
            //
            // execute() 这条路是一个 PHP 进程从头跑到尾，硬顶是 set_time_limit(420)。
            // 分节报告七节、每节一两分钟，十来分钟，装不进一个进程 —— 硬跑会被
            // set_time_limit 打死，执行记录停在 running 等清道夫来收，客户还付了钱。
            //
            // 这里退回单次调用（撞上限时由下面的截断闸如实报告），而不是跑两节再判失败：
            // 同样是失败，单次调用只收客户一次钱。真正能出完整报告的是异步接力路，
            // 工作台点的就是那条（/api/v1/skills/execute-async）。
            // ⚠️ 还在走这条同步路的是自动化任务（TaskOrchestrationService），
            //    它跑分节技能拿不到完整报告 —— 已知缺口，解法是把它也改成派队列任务。
            if ($useChunked && SkillChunkPlan::specsAreMarkdown($chunkSpecs)) {
                Log::info('Markdown section chunking skipped on the in-process path', [
                    'skill_id' => $brief->skillId,
                    'execution_id' => $brief->executionId,
                ]);
                $useChunked = false;
            }

            if ($useChunked) {
                $usingChunked = true;
                return $this->chunkRunner->execute(
                    $skill, $assets, $brief, $systemPrompt, $knowledgeContext, $startTime, $tenantId, $chunkSpecs,
                    $pageObservation,
                );
            }

            // ─── 5. 调用 LLM ───
            //
            // 2026-09-06：额度原本按「有没有渲染脚本」分两档，有脚本 16384、没脚本 4096。
            // 这个分法没有依据 —— 输出要多长取决于报告本身多长，和它是 JSON 还是 Markdown
            // 无关。而 4096 这一档在生产上直接把技能打死了：
            //
            //   execution 51（页面审计，无渲染脚本 → 4096）实测
            //   finish_reason=length、completion_tokens=4096、content_length=0。
            //   页面审计走 geo-reason 推理档，推理 token 按 DeepSeek 口径算进 max_tokens，
            //   4096 全被推理链吃光，正文一个字没写出来。客户被扣 ¥0.071，拿到一份
            //   只有一行「No text output is available yet.」的报告，界面还写着「已完成」。
            //
            // 统一给 16384。网关那头本来还会按剩余时间反算一次上限（约 12k），这里给足
            // 只是不再人为设一道更低的墙。推理档给足额度即可，不降档 —— 降档是拿客户
            // 买到的能力去补我们的实现缺陷。
            $maxTokens = 16384;
            $primaryModel = $this->modelCaller->modelForSkill($skill, $brief);
            $deliveryContext = [
                'path' => 'single', 'attempt' => 1,
                'asset_fingerprint' => $this->runPreparer->deliveryFingerprint($skill, $assets, $hasRenderPipeline),
                'primary_model' => $primaryModel,
                'prior_tokens' => 0,
            ];
            $primaryClientId = PreparedModelAttempt::save($brief, 'single:primary', $primaryModel, [
                'attempt' => 1, 'recovery' => false,
            ], $deliveryContext);
            $attemptPrepared = $primaryClientId !== null;
            $llmResult = $this->aiProvider->generate(
                prompt: $userPrompt,
                system: $systemPrompt,
                options: [
                    'feature_id' => $brief->skillId,
                    'skill_id' => $brief->skillId,
                    'task_id' => 'skill:'.($brief->executionId ?? \Illuminate\Support\Str::uuid()),
                    'step_id' => 'single:primary',
                    ...($primaryClientId === null ? [] : ['client_request_id' => $primaryClientId]),
                    'images' => $this->modelCaller->visualInputs($brief),
                    'max_tokens' => $maxTokens,
                    'temperature' => $hasRenderPipeline ? 0.3 : 0.7,
                    'model' => $primaryModel,
                    // 有渲染管线 = 回复要被 json_decode 后交给渲染脚本，语法必须由上游强制；
                    // 没有渲染管线的技能直接出 Markdown 正文，强制 JSON 会毁掉输出。
                    'json_object' => $hasRenderPipeline,
                ],
            );
            if ($unknown = UnresolvedModelAttempt::fromFailure($llmResult, ['attempt' => 1, 'recovery' => false])) {
                throw $unknown;
            }

            PreparedModelAttempt::resolved($brief->executionId, $primaryClientId, $llmResult);

            // 每一次真实上游调用都要留账。首轮成功结果拥有本次客户扣点；后续降档恢复
            // 属于平台为交付质量承担的成本，由 attempt/recovery 元数据明确豁免。
            $this->recordUsage($brief, $llmResult, [
                'attempt' => 1,
                'recovery' => false,
            ]);
            $totalTokenUsage = $llmResult->totalTokens;

            // ─── 回退/升级策略 ───
            $shouldFallback = false;
            $fallbackReason = '';
            $reasoningRecovery = false;

            if (! $llmResult->success && $llmResult->gateway === []) {
                $fallbackModel = $this->modelCaller->fallbackModelFor($primaryModel);
                if ($fallbackModel !== null) {
                    $shouldFallback = true;
                    $fallbackReason = "{$primaryModel} 调用失败: ".($llmResult->errorMessage ?? '未知');
                }
            } elseif ($llmResult->success && mb_strlen(trim($llmResult->content ?? '')) < SkillChunkPlan::MIN_USABLE_OUTPUT_CHARS) {
                // 2026-09-06：这道闸原本前置了 $hasRenderPipeline，等于「没有渲染脚本的技能
                // 交白卷不算问题」。execution 51 就是从这里漏过去的 —— 正文 0 字，这里不进、
                // 下面的截断闸也不进，一路走到「保存原始 Markdown」存了个空串，标记完成、
                // 照常扣费。空输出对哪种技能都是失败，与它出 JSON 还是 Markdown 无关。
                //
                // 「正文近乎为空」有两种成因，只有一种值得升级档位：
                //   ① finish_reason=length + 正文为空 = 模型把 max_tokens 全花在推理链上。
                //      DeepSeek 口径把推理 token 算进 max_tokens（2026-08-18 直连实测：
                //      max_tokens=100 → completion=100，其中推理 100、正文 0）。升级只会换到
                //      推理更重的档位，同样写不出正文，白扣第二次钱还更慢。此处不升级，
                //      交给下方截断闸给出对症提示。
                //   ② 其余 finish_reason + 正文为空 = 模型真的返回了空，换档位有意义。
                $reasoningStarved = $llmResult->finishReason === 'length';
                $fallbackModel = $reasoningStarved
                    ? $this->modelCaller->recoveryModelForReasoningStarvation($primaryModel, $llmResult->gateway)
                    : $this->modelCaller->fallbackModelFor($primaryModel);

                if ($reasoningStarved && $fallbackModel !== null) {
                    // 推理档把输出额度吃光时，不能再向更重的模型升级；降到写作档只补交
                    // 正文，并作为 recovery 由平台承担。这样用户无需手动重试再付一次钱。
                    $shouldFallback = true;
                    $reasoningRecovery = true;
                    $fallbackReason = sprintf(
                        '%s 的输出额度被推理耗尽，降档到 %s 恢复正文',
                        $primaryModel,
                        $fallbackModel,
                    );
                } elseif ($reasoningStarved) {
                    Log::warning('No lower-reasoning recovery model available', [
                        'skill_id' => $brief->skillId,
                        'model' => $primaryModel,
                        'completion_tokens' => $llmResult->completionTokens,
                        'content_chars' => mb_strlen(trim($llmResult->content ?? '')),
                    ]);
                } elseif ($fallbackModel !== null) {
                    // 软错误：仅当输出近乎为空（真实失败）才升级；合法短内容不再误触发升级与重复计费
                    $shouldFallback = true;
                    $fallbackReason = sprintf(
                        '%s 输出近乎为空（%d 字符），升级到 %s',
                        $primaryModel,
                        mb_strlen(trim($llmResult->content ?? '')),
                        $fallbackModel,
                    );
                }
            }

            if ($shouldFallback) {
                $fallbackModel ??= $this->modelCaller->fallbackModelFor($primaryModel);
                $deliveryContext['attempt'] = 2;
                $deliveryContext['prior_tokens'] = $totalTokenUsage;
                $deliveryContext['fallback_model'] = $fallbackModel;
                Log::info('Escalating GEO model tier', [
                    'from_model' => $primaryModel,
                    'to_model' => $fallbackModel,
                    'reason' => $fallbackReason,
                ]);

                $fallbackClientId = PreparedModelAttempt::save($brief, 'single:fallback', $fallbackModel, [
                    'attempt' => 2, 'recovery' => $reasoningRecovery,
                    'fallback_from_model' => $primaryModel,
                ], $deliveryContext);
                $attemptPrepared = $fallbackClientId !== null;

                $llmResult = $this->aiProvider->generate(
                    prompt: $userPrompt,
                    system: $systemPrompt,
                    options: [
                    'feature_id' => $brief->skillId,
                    'skill_id' => $brief->skillId,
                    'task_id' => 'skill:'.($brief->executionId ?? \Illuminate\Support\Str::uuid()),
                    'step_id' => 'single:fallback',
                    ...($fallbackClientId === null ? [] : ['client_request_id' => $fallbackClientId]),
                    'images' => $this->modelCaller->visualInputs($brief),
                        'max_tokens' => $maxTokens,
                        'temperature' => $hasRenderPipeline ? 0.3 : 0.7,
                        'model' => $fallbackModel,
                    'recovery_mode' => $reasoningRecovery ? 'nonthinking' : 'enhanced',
                        'json_object' => $hasRenderPipeline,
                    ],
                );
                if ($unknown = UnresolvedModelAttempt::fromFailure($llmResult, [
                    'attempt' => 2, 'recovery' => $reasoningRecovery,
                    'fallback_from_model' => $primaryModel,
                ])) {
                    throw $unknown;
                }
                PreparedModelAttempt::resolved($brief->executionId, $fallbackClientId, $llmResult);
                $totalTokenUsage += $llmResult->totalTokens;
                $this->recordUsage($brief, $llmResult, [
                    'attempt' => 2,
                    'recovery' => $reasoningRecovery,
                    'fallback_from_model' => $primaryModel,
                ]);
            }

            Log::info('LLM call resolved', [
                'model' => $llmResult->model,
                'success' => $llmResult->success,
                'content_length' => mb_strlen($llmResult->content ?? ''),
                'tokens' => $totalTokenUsage,
                'escalated' => $shouldFallback,
            ]);

            if (! $llmResult->success) {
                return SkillExecutionResult::failure(
                    $brief->skillId,
                    'AI 调用失败: '.($llmResult->errorMessage ?? '未知错误'),
                );
            }

            return $this->finishSingleCall(
                $brief, $skill, $assets, $hasRenderPipeline, $llmResult,
                $primaryModel, $shouldFallback, $fallbackModel ?? null,
                $totalTokenUsage, $maxTokens, $startTime, $tenantId,
            );

        } catch (UnresolvedEmbeddingAttempt $e) {
            throw $e;
        } catch (UnresolvedModelAttempt $e) {
            throw $deliveryContext === [] ? $e : $e->withDeliveryContext($deliveryContext);
        } catch (UnrecordedModelResult $e) {
            throw $deliveryContext === [] ? $e : $e->withDeliveryContext($deliveryContext);
        } catch (\Throwable $e) {
            $embedding = $brief->executionId && $brief->query
                ? SkillExecution::find($brief->executionId)?->billing_pending_result : null;
            if (in_array(data_get($embedding, 'kind'),
                ['embedding_attempt_prepared', 'embedding_result_recorded'], true)) {
                throw new UnresolvedEmbeddingAttempt($embedding['gateway']);
            }
            // An unexpected local error after the pre-send checkpoint cannot
            // prove the gateway never saw the request. Preserve the original
            // attempt instead of returning a retryable failure.
            $prepared = ($attemptPrepared || $usingChunked)
                ? SkillExecution::find($brief->executionId)?->billing_pending_result : null;
            if (data_get($prepared, 'kind') === 'model_attempt_prepared') {
                throw (new UnresolvedModelAttempt(
                    $prepared['gateway'], $prepared['record_metadata'], $prepared['requested_model'],
                ))->withDeliveryContext($prepared['delivery_context'] ?? []);
            }
            Log::error('Skill execution failed', [
                'skill_id' => $brief->skillId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return SkillExecutionResult::failure($brief->skillId, $e->getMessage());
        }
    }

    private function finishSingleCall(
        SkillBrief $brief,
        SkillDefinition $skill,
        SkillAssets $assets,
        bool $hasRenderPipeline,
        AiProviderCallResult $llmResult,
        string $primaryModel,
        bool $shouldFallback,
        ?string $fallbackModel,
        int $totalTokenUsage,
        int $maxTokens,
        float $startTime,
        ?int $tenantId,
        bool $deferArticle = false,
    ): SkillExecutionResult {
            // ─── 6. 解析输出 + 渲染管线 ───
            $intermediateData = [
                'skill_id' => $brief->skillId,
                'requested_model' => $primaryModel,
                'fallback_model' => $shouldFallback ? ($fallbackModel ?? null) : null,
                'raw_output' => $llmResult->content,
                'generated_at' => now()->toISOString(),
                'tenant_id' => $tenantId,
                'project_id' => $brief->projectId,
                'pipeline_mode' => $hasRenderPipeline ? 'full' : 'fallback',
            ];

            $outputPaths = [];
            $renderResult = null;
            $jsonParsed = false;

            // 尝试 JSON 解析（渲染管线模式）
            if ($hasRenderPipeline) {
                $jsonData = $this->chunkAssembly->extractJson($llmResult->content, $llmResult->finishReason === 'stop');

                if ($jsonData !== null) {
                    $jsonParsed = true;
                    $jsonData = $this->deliveryAssembler->prepareStructuredInput($jsonData, $brief);
                    $intermediateData['structured'] = $jsonData;

                    $renderExecutionId = $brief->executionId ?? time();
                    $renderResult = $this->renderBridge->render($jsonData, $assets, $renderExecutionId);

                    if ($renderResult->success) {
                        $outputPaths = $renderResult->renderedPaths;
                        $intermediateData['render_success'] = true;
                        $intermediateData['rendered_formats'] = $renderResult->availableFormats();
                        Log::info('Render pipeline succeeded', [
                            'formats' => $renderResult->availableFormats(),
                            'paths' => $renderResult->renderedPaths,
                        ]);
                    } else {
                        Log::warning('Render pipeline failed, falling back to raw output', [
                            'error' => $renderResult->errorMessage,
                        ]);
                        $intermediateData['render_error'] = $renderResult->errorMessage;
                    }
                } else {
                    Log::warning('Failed to parse JSON from LLM output, falling back to markdown');
                    $intermediateData['json_parse_failed'] = true;
                }
            }

            // ─── 截断质量闸 ───
            // 单次输出撞 provider max_tokens 上限（finish_reason=length）且没有成功的
            // 结构化渲染 = 交付物是残的。此前会静默落 markdown 兜底并标 completed，用户
            // 拿到半截报告却以为成功（老板 07-29 exec9 实证）。这里改为显式失败并给出
            // 可操作提示。长输出技能的根治是分段（见 chunkSpecsFor），尚未登记分段谱的
            // 技能先由此闸兜底、不再假成功。tokens 已真实消耗故照常计费（上方唯一记账点
            // 已扣）。两种成因给两种提示——早先一律说「请精简输入」是错的：正文 0 字那种
            // 是推理链吃光了输出预算，与用户输入多长毫无关系，照做也不会好转。
            //
            // 2026-09-06 去掉 $hasRenderPipeline 前置：无渲染脚本的技能被截断同样是半截
            // 交付物，只是残在 Markdown 而不是 JSON 上。它们原本连这道闸都不进（$renderResult
            // 恒为 null），execution 51 就是这么假成功的。
            if (
                $llmResult->finishReason === 'length'
                && ! ($renderResult?->success ?? false)
            ) {
                $contentChars = mb_strlen(trim($llmResult->content ?? ''));
                $starved = $contentChars < SkillChunkPlan::MIN_USABLE_OUTPUT_CHARS;

                // 撞的是「谁的」上限 —— 这决定了该跟客户说什么。
                //
                // 我们请求 16384，但网关会按「这次请求还剩多少秒」再反算一次更低的上限
                // （GEO_GATEWAY_HTTP_TIMEOUT=280 秒，实测约 17.9 毫秒/词元 → 天花板落在
                // 12k 词元上下）。所以完成词元明显低于我们请求的额度却仍然 finish=length，
                // 说明卡住它的是**网关的时间预算**，不是客户写了多长。
                //
                // 2026-09-07 生产 execution 53 实证：请求 16384、实得 12810、耗时 228.9 秒。
                // 这种情况下再叫客户「精简输入」是错的 —— 输入根本不是变量，照做重试会
                // 撞同一堵墙、再扣一次钱。客户为我们的架构上限付第二次费，这条不能踩。
                $requestedTokens = $maxTokens;
                $timeCapped = ! $starved
                    && $llmResult->completionTokens > 0
                    && $llmResult->completionTokens < $requestedTokens * 0.9;

                Log::warning('Output truncated at token cap', [
                    'skill_id' => $brief->skillId,
                    'has_render_pipeline' => $hasRenderPipeline,
                    'requested_max_tokens' => $requestedTokens,
                    'completion_tokens' => $llmResult->completionTokens,
                    'content_chars' => $contentChars,
                    'reasoning_starved' => $starved,
                    'gateway_time_capped' => $timeCapped,
                    'json_parsed' => $jsonParsed,
                ]);

                // 判失败也要把已经写出来的正文交回去：钱已经扣了，不能让客户既付了钱
                // 又一个字都拿不到。ExecuteSkillJob / SkillExecutionController 都是无条件
                // 把 raw_output 落进 output_data.result_text 的，成败都落。
                return new SkillExecutionResult(
                    executionId: 0,
                    skillId: $brief->skillId,
                    success: false,
                    outputPaths: [],
                    intermediateData: [
                        'skill_id' => $brief->skillId,
                        'requested_model' => $primaryModel,
                        'raw_output' => $llmResult->content ?? '',
                        'generated_at' => now()->toISOString(),
                        'tenant_id' => $tenantId,
                        'project_id' => $brief->projectId,
                        'pipeline_mode' => $hasRenderPipeline ? 'full' : 'fallback',
                        'truncated_at_token_cap' => true,
                        'reasoning_starved' => $starved,
                        'gateway_time_capped' => $timeCapped,
                        'requested_max_tokens' => $requestedTokens,
                        'completion_tokens' => $llmResult->completionTokens,
                    ],
                    qualityReport: null,
                    durationSeconds: round(microtime(true) - $startTime, 2),
                    llmCallCount: $shouldFallback ? 2 : 1,
                    tokenUsage: $totalTokenUsage,
                    errorMessage: match (true) {
                        $starved => '本次模型把输出额度全部用在了推理上，正文没有写出来。这是平台模型配置问题，与你填写的内容长短无关；请勿重复提交，我们会按原执行记录处理。',
                        // ⚠️ 这一支绝不能提「精简输入」：卡住它的是我们平台单次生成的时间上限，
                        //    客户改输入没有用，重试也会撞同一堵墙。说实话并且别让他白花第二次钱。
                        $timeCapped => '这份报告的篇幅超过了我们平台单次生成的上限，已经写出来的部分在下方，但内容不完整。这是我们这边的限制，不是你填写的内容有问题，重试通常也会停在同样的位置——我们已经记录，正在处理。',
                        default => '报告写到一半就到了单次输出上限，内容不完整。请精简输入信息或缩短业务描述后重试。',
                    },
                    lastLlmResult: $llmResult,
                );
            }

            // 渲染器的内容自检属于交付闸门，必须在创建文章之前处理。
            // 否则失败执行仍会留下可访问的文章，形成“状态失败、内容已发布”的脏数据。
            if ($brief->skillId === 'yao-geo-title-optimizer') {
                $titleIssues = $this->deliveryAssembler->titleDeliveryIssues($intermediateData['structured'] ?? [], $renderResult);
                if ($titleIssues !== []) {
                    $outputPaths['result_text'] ??= $llmResult->content;
                    return new SkillExecutionResult(
                        executionId: 0, skillId: $brief->skillId, success: false,
                        outputPaths: $outputPaths, intermediateData: $intermediateData,
                        qualityReport: ['passed' => false, 'blocking' => true, 'issues' => $titleIssues],
                        durationSeconds: round(microtime(true) - $startTime, 2),
                        llmCallCount: $shouldFallback ? 2 : 1, tokenUsage: $totalTokenUsage,
                        errorMessage: '标题报告未达到交付标准：'.implode('；', $titleIssues),
                        lastLlmResult: $llmResult,
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
                    llmCallCount: 1,
                    tokenUsage: $totalTokenUsage,
                    errorMessage: $issues === []
                        ? '报告已经生成，但尚未达到正式交付标准，当前作为编辑稿保留。'
                        : '报告已经生成，但尚未达到正式交付标准：'.implode('；', array_slice($issues, 0, 5)).'。当前作为编辑稿保留。',
                    lastLlmResult: $llmResult,
                );
            }

            // 降级：保存原始 Markdown 输出
            if (! $renderResult || ! $renderResult->success) {
                $outputPaths['result_text'] = $llmResult->content;

                // 尝试提取 JSON 结构化数据（即使没有渲染脚本）
                if (! $jsonParsed) {
                    $decoded = $this->chunkAssembly->extractJson($llmResult->content, $llmResult->finishReason === 'stop');
                    if ($decoded !== null) {
                        $intermediateData['structured'] = $this->deliveryAssembler->prepareStructuredInput($decoded, $brief);
                    }
                }
            }

            // ─── 7. 内容生产类 Skill → 自动创建文章 ───
            $createdArticleId = null;
            $markdownContent = $llmResult->content;

            // 如果渲染成功，优先使用渲染后的 Markdown
            if ($renderResult?->success && isset($renderResult->renderedPaths['markdown'])) {
                try {
                    $stored = ReportStorage::readDisk($renderResult->renderedPaths['markdown'])->get($renderResult->renderedPaths['markdown']);
                    if ($stored) {
                        $markdownContent = $stored;
                    }
                } catch (\Throwable) {
                    // 使用原始 LLM 输出
                }
            }

            if ($brief->skillId === 'yao-geo-explainer-builder'
                && ! $this->deliveryAssembler->coreAnswerCheck($intermediateData['structured'] ?? [])['passed']) {
                $check = $this->deliveryAssembler->coreAnswerCheck($intermediateData['structured'] ?? []);
                $outputPaths['result_text'] ??= $llmResult->content;
                return new SkillExecutionResult(
                    executionId: 0, skillId: $brief->skillId, success: false,
                    outputPaths: $outputPaths, intermediateData: $intermediateData,
                    qualityReport: ['passed' => false, 'blocking' => true, 'checks' => [$check], 'issues' => [$check['name']]],
                    durationSeconds: round(microtime(true) - $startTime, 2),
                    llmCallCount: $shouldFallback ? 2 : 1, tokenUsage: $totalTokenUsage,
                    errorMessage: '核心回答须为去除空白后 80–120 字，本次交付不完整。',
                    lastLlmResult: $llmResult,
                );
            }

            if ($this->artifactWriter->isContentSkill($skill->category)) {
                $articleContent = $skill->id === 'yao-geo-content-refiner'
                    ? $this->deliveryAssembler->publishableArticleContent($skill->id, $intermediateData['structured'] ?? [], '')
                    : ($renderResult?->publishableArticle ?? $this->deliveryAssembler->publishableArticleContent(
                        $skill->id, $intermediateData['structured'] ?? [], $markdownContent,
                    ));
                if ($skill->id === 'yao-geo-content-refiner' && $articleContent === '') {
                    $intermediateData['delivery_incomplete'] = 'refined_article_missing_or_invalid';
                    $outputPaths['result_text'] ??= $llmResult->content;
                    return new SkillExecutionResult(
                        executionId: 0,
                        skillId: $brief->skillId,
                        success: false,
                        outputPaths: $outputPaths,
                        intermediateData: $intermediateData,
                        qualityReport: ['blocking' => true, 'issues' => ['优化正文 refined_article 缺失或结构不合格']],
                        durationSeconds: round(microtime(true) - $startTime, 2),
                        llmCallCount: $shouldFallback ? 2 : 1,
                        tokenUsage: $totalTokenUsage,
                        errorMessage: '优化正文缺失或结构不合格，报告作为诊断结果保留，未创建文章。',
                        lastLlmResult: $llmResult,
                    );
                }
                if ($deferArticle) {
                    $intermediateData['billing_pending_article_content'] = $articleContent;
                }
                $article = $deferArticle ? null
                    : $this->artifactWriter->createArticleFromOutput($brief, $articleContent, $skill->id, $brief->executionId);
                if ($article) {
                    $createdArticleId = $article->id;
                    $intermediateData['article_id'] = $article->id;
                    $intermediateData['article_title'] = $article->title;
                    $outputPaths['article'] = "/articles/{$article->id}";
                }
            }

            // ─── 8. 质量门检查 ───
            $qualityReport = $renderResult?->qualityReport
                ?? $this->qualityReporter->report(
                    $markdownContent,
                    $skill->category,
                    $jsonParsed,
                    $hasRenderPipeline,
                );
            if (isset($articleContent)) {
                $qualityReport = $this->deliveryAssembler->withPublishableChecks($qualityReport, $skill->id, $articleContent, $intermediateData['structured'] ?? []);
                $this->artifactWriter->storeFinalQualityReport($qualityReport, $outputPaths);
            }

            $duration = round(microtime(true) - $startTime, 2);

            Log::info('Skill execution completed', [
                'skill_id' => $brief->skillId,
                'duration' => $duration,
                'tokens' => $llmResult->totalTokens,
                'pipeline_mode' => $hasRenderPipeline ? 'full' : 'fallback',
                'render_success' => $renderResult?->success ?? false,
                'article_created' => $createdArticleId,
            ]);

            return new SkillExecutionResult(
                executionId: 0,
                skillId: $brief->skillId,
                success: true,
                outputPaths: $outputPaths,
                intermediateData: $intermediateData,
                qualityReport: $qualityReport,
                durationSeconds: $duration,
                llmCallCount: 1,
                tokenUsage: $totalTokenUsage,
                lastLlmResult: $llmResult,
            );

    }

    /** Continue local delivery from a recorded response; this path never calls AiProvider. */
    public function resumeRecorded(SkillBrief $brief, AiProviderCallResult $result, array $context): SkillExecutionResult
    {
        if (($context['path'] ?? null) !== 'single'
            || ! in_array($context['attempt'] ?? null, [1, 2], true)
            || ! is_string($context['asset_fingerprint'] ?? null)
            || ! is_string($context['primary_model'] ?? null)
            || ! is_int($context['prior_tokens'] ?? null)
            || $context['prior_tokens'] < 0
            || ! $brief->executionId || ! $result->success) {
            throw new \RuntimeException('Recorded model delivery context is incomplete.');
        }

        $inputs = $this->runPreparer->deliveryInputs($brief);
        if (! hash_equals($context['asset_fingerprint'], $inputs['fingerprint'])) {
            throw new \RuntimeException('Skill delivery assets changed after the model call.');
        }
        $isFallback = $context['attempt'] === 2;
        if ($result->model !== ($isFallback ? ($context['fallback_model'] ?? null) : $context['primary_model'])) {
            throw new \RuntimeException('Recorded model does not match the saved attempt.');
        }
        // A short first answer may require a new fallback attempt. Do not issue one
        // during ledger recovery or mislabel an incomplete answer as delivered.
        if (! $isFallback && mb_strlen(trim($result->content ?? '')) < SkillChunkPlan::MIN_USABLE_OUTPUT_CHARS
            && $result->finishReason !== 'length') {
            throw new \RuntimeException('Recorded primary result requires a separate fallback decision.');
        }

        return $this->finishSingleCall(
            $brief, $inputs['skill'], $inputs['assets'], $inputs['hasRenderPipeline'],
            $result, $context['primary_model'], $isFallback,
            $isFallback ? $context['fallback_model'] : null,
            $context['prior_tokens'] + $result->totalTokens, 16384,
            microtime(true), TenantContext::id(), deferArticle: true,
        );
    }

    private function recordUsage(SkillBrief $brief, AiProviderCallResult $result, array $metadata): void
    {
        if (! $brief->executionId) {
            return;
        }

        try {
            $executionRecord = SkillExecution::find($brief->executionId);
            if (! $executionRecord) {
                throw new \RuntimeException('Model execution row is unavailable.');
            }
            $this->usageRecorder->record($executionRecord, $result, $metadata);
        } catch (\Throwable $e) {
            Log::warning('Model usage recording failed', [
                'skill_id' => $brief->skillId,
                'execution_id' => $brief->executionId,
                'error' => $e->getMessage(),
            ]);
            if ($result->success) {
                throw new UnrecordedModelResult($result, $metadata, $e);
            }
        }
    }
}
