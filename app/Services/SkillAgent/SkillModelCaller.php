<?php

namespace App\Services\SkillAgent;

use App\Models\SkillExecution;

use App\Services\Contracts\AiProvider;

use App\Services\DTOs\AiProviderCallResult;

use App\Services\DTOs\SkillBrief;

use App\Services\DTOs\SkillDefinition;

use App\Services\Enums\ExecutionStatus;

use App\Services\Billing\ModelUsageRecorder;
use App\Services\Billing\PreparedModelAttempt;
use App\Services\Billing\UnresolvedModelAttempt;
use App\Services\Billing\UnrecordedModelResult;

use Illuminate\Support\Facades\Log;

/**
 * 模型调用与用量登记（计划 §7 第 3 步：把 SkillAgentRunnerService 按职责拆小，「执行」这块）。
 *
 * 一次模型调用的完整动作：组装 options（含图片输入、步骤归属、是否 JSON 模式）、调用 provider、
 * **每次尝试都登记用量**（扣费依据，失败也不能漏）、按需升级兜底模型、以及判断这次执行是否已被取消。
 *
 * 两条铁律（原实现里写得很清楚，搬来时一字未改）：
 *   1. 每次尝试都要记用量：升级重试也是真花钱的调用，漏记等于少扣费；
 *   2. 推理吃光预算（finish_reason=length）时不升级：再问一次只会烧更多钱，通常还是同一份长文。
 *
 * 判据：纯函数部分（图片输入、模型选择、兜底系统提示词、状态里的页面事实、取消判定）由
 * tests/Feature/SkillModelCallerTest.php 冻结；调用模型那段由既有集成测试兜底 ——
 * PanoramaChunkedAnalysisTest（15 项，含"每段只计费一次"）、SkillRelayExecutionTest（7 项）、
 * SkillExecutionReliabilityTest（13 项，含重复投递只扣一次）。
 */
class SkillModelCaller
{
    public function __construct(
        private readonly AiProvider $aiProvider,
        private readonly ModelUsageRecorder $usageRecorder,
        private readonly SkillChunkAssembly $chunkAssembly,
    ) {}

    /** 接力状态里存的观察，取回来给下一步用。没存过（老状态行）就返回 null。 */
    public function observationFromState(array $state): ?PageObservation
    {
        $stored = $state['page_observation'] ?? null;

        return is_array($stored) ? PageObservation::fromArray($stored) : null;
    }

    /**
     * 每次真实调用都独立记账并返回，包含升级前的调用，避免漏记已消耗的 token。
     *
     * @return list<AiProviderCallResult>
     */
    public function generateAndRecord(
        string $userPrompt,
        string $systemPrompt,
        int $maxTokens,
        string $primaryModel,
        SkillBrief $brief,
        array $recordMetadata = [],
        bool $allowEscalation = true,
        bool $jsonObject = false,
        array $deliveryContext = [],
    ): array {
        $attempts = [];
        $model = $primaryModel;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $stepId = 'model:'.($recordMetadata['chunk'] ?? $recordMetadata['chunk_key'] ?? $recordMetadata['field'] ?? 'single').':attempt:'.($attempt + 1);
            $metadata = array_merge($recordMetadata, [
                'attempt' => $attempt + 1,
                'requested_max_tokens' => $maxTokens,
            ]);
            $chunkContext = array_merge($deliveryContext, [
                'path' => 'chunk', 'step_id' => $stepId,
                'attempt' => $attempt + 1,
            ]);
            $clientId = PreparedModelAttempt::save($brief, $stepId, $model, $metadata, $chunkContext);
            try {
                $llmResult = $this->aiProvider->generate(
                prompt: $userPrompt,
                system: $systemPrompt,
                options: [
                    'feature_id' => $brief->skillId,
                    'skill_id' => $brief->skillId,
                    'task_id' => 'skill:'.($brief->executionId ?? \Illuminate\Support\Str::uuid()),
                    'images' => $this->visualInputs($brief),
                    'execution_id' => $brief->executionId,
                    'max_tokens' => $maxTokens,
                    'temperature' => 0.3,
                    'model' => $model,
                    'recovery_mode' => !empty($recordMetadata['recovery']) ? 'nonthinking' : ($attempt > 0 ? 'enhanced' : null),
                    'step_id' => $stepId,
                    ...($clientId === null ? [] : ['client_request_id' => $clientId]),
                    'json_object' => $jsonObject,
                ],
            );
            } catch (\Throwable $e) {
                if ($clientId === null) {
                    throw $e;
                }
                throw (new UnresolvedModelAttempt(['attribution' => [
                    'client_request_id' => $clientId,
                    'task_id' => 'skill:'.$brief->executionId,
                    'feature_id' => $brief->skillId,
                    'step_id' => $stepId,
                ]], $metadata, $model))->withDeliveryContext($chunkContext);
            }
            if ($unknown = UnresolvedModelAttempt::fromFailure($llmResult, array_merge($recordMetadata, [
                'attempt' => $attempt + 1,
                'finish_reason' => $llmResult->finishReason,
                'requested_max_tokens' => $maxTokens,
            ]))) {
                throw $unknown->withDeliveryContext($chunkContext);
            }
            PreparedModelAttempt::resolved($brief->executionId, $clientId, $llmResult);
            $attempts[] = $llmResult;
            if ($brief->executionId && ($llmResult->gateway['request_sent'] ?? true) !== false) {
                try {
                    $executionRecord = SkillExecution::find($brief->executionId);
                    if (! $executionRecord) {
                        throw new \RuntimeException('Model execution row is unavailable.');
                    }
                    $this->usageRecorder->record($executionRecord, $llmResult, array_merge($recordMetadata, [
                        'attempt' => $attempt + 1,
                        'finish_reason' => $llmResult->finishReason,
                        'requested_max_tokens' => $maxTokens,
                    ]));
                } catch (\Throwable $e) {
                    Log::warning('Model usage recording failed (chunk)', [
                        'skill_id' => $brief->skillId,
                        'execution_id' => $brief->executionId,
                        'error' => $e->getMessage(),
                    ]);
                    if ($llmResult->success) {
                        throw (new UnrecordedModelResult($llmResult, array_merge($recordMetadata, [
                            'attempt' => $attempt + 1,
                            'finish_reason' => $llmResult->finishReason,
                            'requested_max_tokens' => $maxTokens,
                        ]), $e))->withDeliveryContext($chunkContext);
                    }
                }
            }

            // 推理吃光预算时不升级；合法的短 JSON 交给字段闸检查，不能因字符少重复扣费。
            $needEscalate = (! $llmResult->success && $llmResult->gateway === [])
                || ($llmResult->success
                    && mb_strlen(trim($llmResult->content ?? '')) < SkillChunkPlan::MIN_USABLE_OUTPUT_CHARS
                    && $this->chunkAssembly->extractJson($llmResult->content ?? '') === null);
            $fallbackModel = $this->fallbackModelFor($primaryModel);
            if (! $allowEscalation || $attempt > 0 || ! $needEscalate || $llmResult->finishReason === 'length'
                || $fallbackModel === null || $this->isCancelled($brief->executionId)) {
                break;
            }
            $model = $fallbackModel;
        }

        return $attempts;
    }

    /**
     * 段间取消检查：每段调 LLM 前查一次执行状态，命中 cancelled 即停止发起后续段。
     * 轻量单值查询（执行书：每段查一次即可，不要在段内轮询——那是往数据库灌无用查询）。
     */
    public function isCancelled(?int $executionId): bool
    {
        if (! $executionId) {
            return false;
        }

        return SkillExecution::where('id', $executionId)->value('status') === ExecutionStatus::Cancelled->value;
    }

    public function buildFallbackSystemPrompt(): string
    {
        return '你是一位资深 GEO（生成式引擎优化）内容策略师。你的任务是根据给定的 Skill 指令，为品牌生产高质量的、面向 AI 搜索引擎优化的内容。请直接输出最终结果，不要添加任何前言、解释或后缀。';
    }

    public function modelForSkill(SkillDefinition $skill, SkillBrief $brief): string
    {
        return $brief->modelSlot();
    }

    public function fallbackModelFor(string $model): ?string
    {
        return in_array($model, ['text', 'vision'], true) ? $model : null;
    }

    public function recoveryModelForReasoningStarvation(string $model, array $gateway = []): ?string
    {
        return data_get($gateway, 'routing.task.profile') === 'analysis' ? $model : null;
    }

    /** Only explicit image fields contain images; words such as “截图” do not trigger vision. */
    public function visualInputs(SkillBrief $brief): array
    {
        return SkillBrief::imageInputs($brief->fields);
    }
}
