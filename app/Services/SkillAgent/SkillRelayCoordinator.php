<?php

namespace App\Services\SkillAgent;

use App\Models\SkillExecution;
use App\Models\SkillExecutionRelay;
use App\Services\DTOs\RelayProgress;
use App\Services\DTOs\SkillBrief;
use App\Services\DTOs\SkillExecutionResult;
use App\Services\Enums\ExecutionStatus;
use App\Support\BillingContext;
use App\Support\ExecutionTimeLimit;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 接力执行（计划 §7 第 3 步：把 `SkillAgentRunnerService` 按职责拆小，「执行」里的异步那条路）。
 *
 * 为什么存在：分段报告原本是一个 PHP 进程从第一段跑到最后一段，而这条路上有
 * 一架从里到外递增的超时梯子，第二根横档就是 set_time_limit(420)：
 *     280s 一次网关调用 < 420s 一个 PHP 进程 < 480s 一个队列任务 < 600s 队列重新派发
 * 梯子顶上那根（retry_after=600）是硬的——超过它，队列会把同一个任务交给第二个
 * worker 重跑，两份报告两次扣费。所以一个进程最多约 420 秒，按 deepseek-v4-pro
 * 实测 16.4 秒/千词元折算，**一个进程顶多产出约 25,600 词元**；
 * 而一份完整的意图挖掘报告要 35,000～40,000 词元。差 40%~60%，怎么调参数都不够。
 * 2026-09-06 两次生产实跑把这个死结的两端都撞了一遍：
 *     id=43 每段上限 8000 → 塞得进 420 秒，但内容被截断 → 完整性闸判失败
 *     id=44 每段上限 16384 → 内容完整了，但第 4 段还没跑完进程就被 420 秒打死
 *
 * 接力把「一份报告 = 一个进程」这个前提拆掉：一段一个队列任务，首尾相接。
 *     第 0 步  准备（资产 / RAG / prompt），不发模型请求，很快
 *     第 1..N 步 每步跑一段，各约 150 秒，永远待在梯子最底下
 *     第 N+1 步 收尾（完整性闸 + 合并 + 渲染），独占一个干净进程
 * 总时长因此不再有上限，report 要写多久就写多久。
 *
 * ⚠️ 只有异步技能执行（ExecuteSkillJob）走接力。同步控制器路（含全景诊断）
 *    仍走 executeChunked 同进程版——那些报告短，够跑，不值得为它们担改动风险。
 *
 * 本类只负责"接力怎么走"：开接力（准备 + 落初始状态）、推进一步（一段一个队列任务）、
 * 收尾（完整性闸 + 合并 + 渲染）。每一步的计费上下文由本类在入口认领（BillingContext::scoped）——
 * 接力每一步都是独立进程，各自认领一次，绝不共享。
 *
 * 判据：SkillRelayExecutionTest（每个队列任务恰好一段、恰好一次模型调用）、
 * PageAuditSectionRelayTest（分节谱接力：标题只在第一节、后节只带前文开头）、
 * IntentChunkRecoveryTest（接力版的补跑预算）。
 */
class SkillRelayCoordinator
{
    public function __construct(
        private readonly SkillChunkPlan $chunkPlan,
        private readonly SkillChunkRunner $chunkRunner,
        private readonly SkillChunkFinalizer $chunkFinalizer,
        private readonly SkillModelCaller $modelCaller,
        private readonly SkillRunPreparer $runPreparer,
    ) {}
    // ─────────────────────────────────────────────────────────────────────────
    // 接力执行（relay）
    //
    // 为什么存在：分段报告原本是一个 PHP 进程从第一段跑到最后一段，而这条路上有
    // 一架从里到外递增的超时梯子，第二根横档就是 set_time_limit(420)：
    //     280s 一次网关调用 < 420s 一个 PHP 进程 < 480s 一个队列任务 < 600s 队列重新派发
    // 梯子顶上那根（retry_after=600）是硬的——超过它，队列会把同一个任务交给第二个
    // worker 重跑，两份报告两次扣费。所以一个进程最多约 420 秒，按 deepseek-v4-pro
    // 实测 16.4 秒/千词元折算，**一个进程顶多产出约 25,600 词元**；
    // 而一份完整的意图挖掘报告要 35,000～40,000 词元。差 40%~60%，怎么调参数都不够。
    // 2026-09-06 两次生产实跑把这个死结的两端都撞了一遍：
    //     id=43 每段上限 8000 → 塞得进 420 秒，但内容被截断 → 完整性闸判失败
    //     id=44 每段上限 16384 → 内容完整了，但第 4 段还没跑完进程就被 420 秒打死
    //
    // 接力把「一份报告 = 一个进程」这个前提拆掉：一段一个队列任务，首尾相接。
    //     第 0 步  准备（资产 / RAG / prompt），不发模型请求，很快
    //     第 1..N 步 每步跑一段，各约 150 秒，永远待在梯子最底下
    //     第 N+1 步 收尾（完整性闸 + 合并 + 渲染），独占一个干净进程
    // 总时长因此不再有上限，report 要写多久就写多久。
    //
    // ⚠️ 只有异步技能执行（ExecuteSkillJob）走接力。同步控制器路（含全景诊断）
    //    仍走 executeChunked 同进程版——那些报告短，够跑，不值得为它们担改动风险。
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 开一次接力：做准备工作、把初始状态落库，不发任何模型请求。
     *
     * @return RelayProgress|null null＝这个技能不该走接力（没登记分段谱 / 开关关着 /
     *                            没有渲染管线），调用方回退到 execute() 那条老路。
     */
    public function begin(SkillBrief $brief): ?RelayProgress
    {
        if (! $brief->executionId) {
            return null;
        }

        // relayProbe：这一趟只是探「要不要分段」。不分段就直接返回，让 ExecuteSkillJob
        // 走 execute()，抓取留到那时候做一次就够 —— 否则客户的站点会被抓两遍。
        $prep = $this->runPreparer->prepare($brief, relayProbe: true);
        if ($prep['chunkSpecs'] === null) {
            return null;
        }

        // 抓页面失败：不开接力，让 ExecuteSkillJob 回落到 execute()，由那里唯一的一处
        // 「抓不到就零扣费判失败」收场。
        //
        // 代价是这条错误路径上会对一个刚刚抓失败的站点多发一次 HTTP 请求；换来的是
        // 失败处理只有一份实现。写两份的话迟早分叉成「同步失败得对、异步失败得不对」，
        // 而这条路管的是「客户一分钱都不该扣」，分叉的代价是客户的钱。
        // ⚠️ 页面审计登记分段谱之后这一条才成为必需：在那之前它压根不走接力。
        if (($prep['pageObservation'] ?? null) !== null && ! $prep['pageObservation']->ok) {
            return null;
        }

        $state = $this->chunkPlan->newChunkState(
            $prep['chunkSpecs'], $prep['knowledgeContext'], microtime(true), $prep['pageObservation'],
        );
        $total = count($state['pending']);

        // 重跑同一条执行时先清掉上一次的残留（skill_execution_id 上有唯一约束）。
        SkillExecutionRelay::where('skill_execution_id', $brief->executionId)->delete();
        SkillExecutionRelay::create([
            'skill_execution_id' => $brief->executionId,
            'tenant_id' => TenantContext::id(),
            'total_steps' => $total,
            'completed_steps' => 0,
            'state' => $state,
        ]);

        Log::info('Relay started', [
            'execution_id' => $brief->executionId,
            'skill_id' => $brief->skillId,
            'total_steps' => $total,
        ]);

        return new RelayProgress(ranChunk: false, completedSteps: 0, totalSteps: $total, pendingCount: $total);
    }

    /**
     * 推进一步：跑掉一段，状态存回去。
     *
     * ranChunk=false 表示这一步没发出模型请求（没段可跑了，或者被取消了），
     * 调用方据此知道「本进程是干净的」，可以直接接着跑收尾。
     */
    public function advance(SkillBrief $brief): RelayProgress
    {
        return BillingContext::scoped(
            $brief->userId,
            'skill:'.$brief->skillId,
            fn (): RelayProgress => ExecutionTimeLimit::scoped(
                420,
                fn (): RelayProgress => $this->advanceRelayWithinBillingContext($brief),
            ),
        );
    }

    private function advanceRelayWithinBillingContext(SkillBrief $brief): RelayProgress
    {
        // 每一步在入口单独限时 420 秒，返回后恢复 worker 原设置。
        // 接力解决的是总时长，单步预算仍遵守 280 < 420 < 480 < 600。
        $relay = $this->relayFor($brief);
        $state = $relay->state;

        if ($state['pending'] === [] || ($state['cancelled'] ?? false)) {
            return new RelayProgress(
                ranChunk: false,
                completedSteps: $relay->completed_steps,
                totalSteps: $relay->total_steps,
                pendingCount: count($state['pending']),
                cancelled: (bool) ($state['cancelled'] ?? false),
            );
        }

        // knowledge_context 与 page_observation 原样传回：绝不重新做 RAG 检索（要花钱的
        // 向量调用），也绝不重新抓一遍客户的站点。
        $prep = $this->runPreparer->prepare(
            $brief,
            (string) ($state['knowledge_context'] ?? ''),
            $this->modelCaller->observationFromState($state),
        );

        $llm = $this->chunkRunner->runTask(
            $state, $prep['skill'], $prep['assets'], $brief, $prep['systemPrompt'],
            enforceRecoveryDeadline: false,
            deliveryContext: [
                'asset_fingerprint' => $this->runPreparer->deliveryFingerprint(
                    $prep['skill'], $prep['assets'], $prep['hasRenderPipeline'],
                ),
                'relay_completed_steps' => $relay->completed_steps,
                'relay_task_fingerprint' => hash('sha256', json_encode($state['pending'][0], JSON_THROW_ON_ERROR)),
            ],
        );

        $completed = $relay->completed_steps + 1;
        DB::transaction(function () use ($relay, $brief, $state, $completed, $llm): void {
            $relay->update([
                'state' => $state,
                'completed_steps' => $completed,
                // 补跑字段会往 pending 里插新任务，总步数会长。照实记，进度才不会倒退成假的。
                'total_steps' => max($relay->total_steps, $completed + count($state['pending'])),
            ]);

            // Commit the relay result and retire its pre-send intent together.
            // A kill on either side of this transaction now has one durable truth.
            $execution = SkillExecution::whereKey($brief->executionId)->lockForUpdate()->first();
            if ($execution) {
                $changes = ['updated_at' => now()];
                if ($llm !== null && $execution->status === ExecutionStatus::Running->value
                    && in_array(data_get($execution->billing_pending_result, 'kind'),
                        ['model_attempt_prepared', 'model_attempt_resolved', 'model_result_unrecorded'], true)) {
                    $changes['billing_pending_result'] = null;
                }
                $execution->update($changes);
            }
        });

        Log::info('Relay step done', [
            'execution_id' => $brief->executionId,
            'skill_id' => $brief->skillId,
            'step' => $completed,
            'pending' => count($state['pending']),
            'ran_chunk' => $llm !== null,
            'tokens_so_far' => $state['token_sum'],
        ]);

        return new RelayProgress(
            ranChunk: $llm !== null,
            completedSteps: $completed,
            totalSteps: $relay->total_steps,
            pendingCount: count($state['pending']),
            cancelled: (bool) $state['cancelled'],
        );
    }

    /**
     * 收尾：完整性闸 → 合并 → 渲染 → 出结果，然后把接力状态删掉。
     *
     * 状态行删掉是故意的：chunk_meta 与 raw_output 这时已经写进
     * skill_executions.output_data，留着只会白占地方（state 里带着每段的模型原文）。
     */
    public function finalize(SkillBrief $brief): SkillExecutionResult
    {
        return BillingContext::scoped(
            $brief->userId,
            'skill:'.$brief->skillId,
            fn (): SkillExecutionResult => ExecutionTimeLimit::scoped(
                420,
                fn (): SkillExecutionResult => $this->finalizeRelayWithinBillingContext($brief),
            ),
        );
    }

    private function finalizeRelayWithinBillingContext(SkillBrief $brief): SkillExecutionResult
    {
        $relay = $this->relayFor($brief);
        $state = $relay->state;
        $prep = $this->runPreparer->prepare(
            $brief,
            (string) ($state['knowledge_context'] ?? ''),
            $this->modelCaller->observationFromState($state),
        );

        // lastLlmResult 传 null：它是对象，过不了 json，接力存不下来。
        // 只有 GeoAnalysisController（同步全景路）读这个字段，接力路没人读。
        $result = $this->chunkFinalizer->finish(
            $state, $prep['skill'], $prep['assets'], $brief, (string) TenantContext::id(), null,
        );

        $relay->delete();

        Log::info('Relay finalized', [
            'execution_id' => $brief->executionId,
            'skill_id' => $brief->skillId,
            'success' => $result->success,
            'calls' => $result->llmCallCount,
            'tokens' => $result->tokenUsage,
            'duration' => $result->durationSeconds,
        ]);

        return $result;
    }

    private function relayFor(SkillBrief $brief): SkillExecutionRelay
    {
        $relay = SkillExecutionRelay::where('skill_execution_id', $brief->executionId)->first();

        if (! $relay) {
            // 状态行没了，接力没法接着跑。抛出去让 Job 把这条执行判失败，
            // 而不是默默出一份缺内容的报告。
            throw new \RuntimeException(
                "接力状态已丢失，无法继续（execution {$brief->executionId}）",
            );
        }

        return $relay;
    }
}
