<?php

namespace App\Services\SkillAgent;

use App\Services\DTOs\AiProviderCallResult;
use App\Services\DTOs\SkillBrief;
use App\Services\DTOs\SkillDefinition;
use App\Services\DTOs\SkillExecutionResult;

/**
 * 分节执行（计划 §7 第 3 步：把 `SkillAgentRunnerService` 按职责拆小，「执行」这块）。
 *
 * 两个入口：
 *   - `execute()`：**同进程**版。把分段谱从头跑到尾再收尾（`SkillChunkFinalizer`）。
 *     它有一堵硬墙 —— `set_time_limit(420)`，报告一长必然撞死（2026-09-06 id=44 实证），
 *     所以只剩同步控制器路（含全景诊断）在用；异步技能走接力版，每段一个队列任务。
 *   - `runTask()`：**同进程版和接力版唯一的公共执行体**（取一段跑掉、结果并回 `$state`）。
 *     两边必须逐字走同一段逻辑，否则「同步能出报告、异步出不来」这种鬼问题迟早出现 ——
 *     接力版的每一步也是调它，只是 `$enforceRecoveryDeadline` 传 false。
 *
 * 钱这条路上最容易搞错的两处，注释都跟着搬过来了（一字未改）：
 *   1. 每段调用都走 `SkillModelCaller::generateAndRecord`（唯一记账人，"每段只计费一次"的落点）；
 *   2. `$enforceRecoveryDeadline` 的口径 —— 同进程版 true（补跑挤不进去就放弃）、
 *      接力版 false（补跑本身就是独立任务，有完整预算；写死成 true 会让补跑永远被跳过，
 *      2026-09-06 id=44 实证）。
 *
 * 判据：tests/Feature/PanoramaChunkedAnalysisTest.php（4 段合并 + 每段只计费一次）、
 * IntentChunkRecoveryTest / RoadmapChunkRecoveryTest（补跑与截断）、
 * SkillRelayExecutionTest（接力版走同一条 runTask）。
 */
class SkillChunkRunner
{
    public function __construct(
        private readonly SkillChunkPlan $chunkPlan,
        private readonly SkillChunkAssembly $chunkAssembly,
        private readonly SkillPromptBuilder $promptBuilder,
        private readonly SkillContinuityDigest $continuityDigest,
        private readonly SkillModelCaller $modelCaller,
        private readonly RenderSchemaRegistry $renderSchemaRegistry,
        private readonly SkillChunkFinalizer $chunkFinalizer,
    ) {}
    /**
     * 长输出技能分段执行：把大结构按分段谱拆成多段分别生成再合并，绕开单次 max_tokens 截断。
     * config('geo.analysis_chunked') 开启且 chunkSpecsFor() 命中的 skill 走此路径。
     * 计费：每段一次真实 LLM 调用 → runner 各记一条 ModelCallLog（唯一记账人），控制器聚合读取。
     *
     * ⚠️ 这是**同进程**版本：一个 PHP 进程从第一段跑到最后一段。它有一堵硬墙——
     *    set_time_limit(420)，报告一长就必然撞死（2026-09-06 id=44 实证）。
     *    异步技能执行走的是接力版（beginRelay/advanceRelay/finalizeRelay），不走这里；
     *    这里只剩同步控制器路（含全景诊断）在用，那些报告短，够跑。
     *
     * 📌 分节谱（Markdown 长报告）永远走不到这里：executeWithinBillingContext 在进来之前
     *    就把它挡掉了（七节十来分钟，一个进程装不下）。所以本方法只需处理字段谱。
     */
    public function execute(
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
        $state = $this->chunkPlan->newChunkState($specs, $knowledgeContext, $startTime, $pageObservation);
        $lastLlm = null;

        while ($state['pending'] !== [] && ! $state['cancelled']) {
            $lastLlm = $this->runTask(
                $state, $skill, $assets, $brief, $systemPrompt, enforceRecoveryDeadline: true,
            ) ?? $lastLlm;
        }

        return $this->chunkFinalizer->finish($state, $skill, $assets, $brief, $tenantId, $lastLlm);
    }

    /**
     * 从 pending 里取一段跑掉，结果并回 $state。
     *
     * 这是同进程版和接力版唯一的公共执行体——两边必须逐字走同一段逻辑，
     * 否则「同步能出报告、异步出不来」这种鬼问题迟早出现。
     *
     * $enforceRecoveryDeadline：补跑字段前要不要先看「这个进程还剩多少时间」。
     *   同进程版 true —— 它确实是一个进程从头跑到尾，补跑挤不进去就得放弃。
     *   接力版   false —— 补跑本身就是独立的一个队列任务，有完整预算，没有挤不挤的问题。
     *   ⚠️ 这个闸原先是无条件开的，而它的条件是「已用时 < 400-280 = 120 秒」，
     *      可意图挖掘光第一段就要 137 秒 —— 于是补跑永远跳过，写了等于没写
     *      （2026-09-06 查出，id=44 实证）。接力版关掉它，那套补跑才第一次真的能跑。
     *
     * @return AiProviderCallResult|null null＝这一步没发出模型请求（被取消 / 被时间闸跳过 / 没段可跑）
     */
    public function runTask(
        array &$state,
        SkillDefinition $skill,
        SkillAssets $assets,
        SkillBrief $brief,
        string $systemPrompt,
        bool $enforceRecoveryDeadline,
        array $deliveryContext = [],
        ?AiProviderCallResult $recordedResult = null,
    ): ?AiProviderCallResult {
        $task = array_shift($state['pending']);
        if ($task === null) {
            return null;
        }

        $spec = $task['spec'];
        $parent = $task['parent'];
        $key = $parent['key'];

        // 段间取消检查：每段调 LLM 之前查一次取消标记（轻量单值查询，勿在段内轮询）。
        // 命中即停止发起后续段——第 1 段跑完时取消，能省掉后面每一段的钱。
        // 已完成的段已实时记账（generateAndRecord 每段一条 ModelCallLog + 扣费），
        // 状态保持 cancelled（由 cancel 接口写入，这里不覆盖）。
        if ($this->modelCaller->isCancelled($brief->executionId)) {
            $state['cancelled'] = true;

            return null;
        }

        if ($enforceRecoveryDeadline && $task['recovery']) {
            $recoveryDeadline = min(400, (int) config('geo.chunk_recovery_deadline_seconds', 400));
            if (microtime(true) - $state['started_at']
                + (int) config('geo.model_http_timeout', 280) > $recoveryDeadline) {
                $state['chunk_meta'][$key]['recovery_skipped'] = 'insufficient_time';

                return null;
            }
        }

        $primaryModel = $this->modelCaller->modelForSkill($skill, $brief);
        $chunkMaxTokens = max(1, (int) config('geo.chunk_max_tokens', 16384));
        $source = str_contains($brief->skillId, 'panorama-audit') ? 'geo_panorama_analysis' : 'geo_chunked_analysis';
        $markdown = SkillPromptBuilder::isMarkdownSpec($spec);

        $userPrompt = $this->promptBuilder->chunkUserPrompt(
            $assets, $brief, $state['knowledge_context'], $spec,
            $markdown
                ? $this->continuityDigest->markdown($state)
                : $this->continuityDigest->forSkill($brief->skillId, $state['merged'], $spec['key'] ?? null),
            $this->modelCaller->observationFromState($state),
        );

        $attempts = $recordedResult !== null ? [$recordedResult] : $this->modelCaller->generateAndRecord(
            $userPrompt,
            $systemPrompt.($markdown
                ? "\n\n本次只写这份报告的其中一节：「{$spec['label']}」。直接输出这一节的 Markdown 正文，"
                    .'不要写其他章节，不要写开场白或结束语，不要把整节内容包进代码围栏或 JSON。'
                    .'前文的完整报告要求只用来确定这一节该写什么、写到什么深度。'
                : "\n\n本次只生成报告的一个片段，仅输出这些顶层字段：".implode('、', $spec['fields'])
                    .'。前文的完整报告要求只用于这些字段的内容与结构，不要输出其他章节。'),
            $chunkMaxTokens, $primaryModel, $brief,
            // A repair changes the prompt and must receive its own paid attempt
            // identity. Keep $key for merging into the parent chunk below.
            ['source' => $source, 'chunk' => $spec['key'], 'fields' => $spec['fields'], 'recovery' => $task['recovery']],
            allowEscalation: ! $task['recovery'],
            // 每段的回复都要 json_decode，语法必须由上游保证 —— 靠提示词求模型输出合法 JSON
            // 是不够的，见 AiProviderServiceImpl::generate() 里的 execution 46 实证。
            // 🔴 分节谱必须传 false：那条路要的是 Markdown 正文，强制 JSON 会把它整节毁掉
            //    （模型会把正文塞进一个字符串字段，或者干脆写出一坨转义文本）。
            jsonObject: ! $markdown,
            deliveryContext: $deliveryContext,
        );

        foreach ($attempts as $attempt) {
            $state['call_count']++;
            $state['token_sum'] += $attempt->totalTokens;
            $state['raw_parts'][] = "<!-- chunk: {$spec['key']}; call: {$state['call_count']} -->\n".($attempt->content ?? '');
            $state['chunk_meta'][$key]['attempts'][] = [
                'ok' => $attempt->success,
                'model' => $attempt->model,
                'fields' => $spec['fields'],
                'recovery' => $task['recovery'],
                'finish_reason' => $attempt->finishReason,
                'requested_max_tokens' => $chunkMaxTokens,
                'completion_tokens' => $attempt->completionTokens,
                'tokens' => $attempt->totalTokens,
            ];
            $state['chunk_meta'][$key]['tokens'] = ($state['chunk_meta'][$key]['tokens'] ?? 0) + $attempt->totalTokens;
        }

        $llm = $attempts[array_key_last($attempts)];
        // 分节谱收的是 Markdown 正文，不 json_decode：把整节文本装进本节那个伪字段就行。
        // 太短的一律不认（当成模型交白卷），口径与单次调用那条路的空输出闸一致。
        $chunkData = $markdown
            ? $this->chunkPlan->markdownChunkData($spec, $llm)
            : ($llm->success ? $this->chunkAssembly->extractJson($llm->content ?? '', $llm->finishReason === 'stop') : null);
        $parsed = is_array($chunkData);
        if ($parsed && str_contains($brief->skillId, 'explainer-builder')
            && in_array('core_answer', $spec['fields'], true)) {
            $chunkData = $this->chunkAssembly->useCompleteCoreAnswerSentence($chunkData);
        }
        // length 即使碰巧形成合法 JSON，也不代表报告写完；绝不修补括号后合并。
        $truncated = $llm->finishReason === 'length';
        if ($parsed && ! $truncated) {
            if (str_contains($brief->skillId, 'intent-miner')) {
                $chunkData = $this->renderSchemaRegistry->normalizeProvidedInput($brief->skillId, $chunkData);
            }
            $state['chunk_outputs'][$key] = array_replace(
                $state['chunk_outputs'][$key] ?? [],
                array_intersect_key($chunkData, array_flip($spec['fields'])),
            );
        }

        // 被截断的那一节不算数（不并进正文），但它写出来的字要留着。
        // 万一重写也没成、整单判失败，这些字是客户唯一能拿到的东西 —— 钱已经扣了，
        // 不能让他既付了钱又一个字都看不到。收尾时会加「本节没写完」的标记再附上。
        if ($markdown && $parsed && $truncated) {
            $state['chunk_partials'][$key] = $chunkData[$spec['fields'][0]];
        }

        $output = $state['chunk_outputs'][$key] ?? [];
        $state['merged'] = $this->chunkAssembly->merge($state['merged'], $output, $parent['fields']);
        $issues = $this->chunkAssembly->fieldIssues($brief->skillId, $output, $parent['fields']);
        $state['chunk_meta'][$key] = array_merge($state['chunk_meta'][$key], [
            'ok' => $llm->success,
            'parsed' => $parsed,
            'fields' => $this->chunkAssembly->countClaimed($output, $parent['fields']),
            'complete' => $issues === [],
            'missing_fields' => $issues,
        ]);

        // 只处理有确定结果的截断/漏字段，不对超时、网络错误盲目重放。成功的原段不重跑。
        //
        // 2026-09-06 第一批：非截断分支原本还要求 `$parsed && $output !== []`，也就是「解析
        // 得出、而且至少捞到一个字段」才肯补。结果 execution 46 的两段正好卡在这个条件外面
        // —— 模型 finish_reason=stop（写完了）、但回复是坏 JSON，一个字段都没解析出来，于是
        // 一次都没补就整单判失败，让客户去点「重试」重跑全部 7 段。那是拿我们的缺陷收客户
        // 第二次钱。现在只要「没被截断且调用成功」就按缺失内容补。
        //
        // 2026-09-06 第二批（老板拍板铺开）：这道闸原本还写死 `intent-miner`，另外三个分段
        // 技能（综合诊断／执行路线图／解释型文章）一段没交就整单失败、不自救。现在全部放开。
        // 补跑预算 (chunk_recovery_max_calls) 仍然封顶，坏得离谱的段不会无限烧。
        //
        // 2026-09-07 分节谱：被截断的一节也要给它一次重写机会。原来那个
        // `count($spec['fields']) > 1` 的条件是为字段谱写的 —— 单字段段被截断，
        // 拆开重来还是同一个字段、还是同样长，白花钱。分节谱不一样：重写会把篇幅
        // 目标压到七成（见 repairTasksFor），是有可能写完的，值这一次调用。
        $canRecover = ! $task['recovery'] && $llm->success && $issues !== []
            && (! $truncated
                || (mb_strlen(trim($llm->content ?? '')) >= SkillChunkPlan::MIN_USABLE_OUTPUT_CHARS
                    && ($markdown || count($spec['fields']) > 1)));
        $repairs = $canRecover ? $this->chunkAssembly->repairTasks($brief->skillId, $parent, $key, $issues, $truncated) : [];
        if (str_contains($brief->skillId, 'explainer-builder') && $issues === ['core_answer'] && isset($repairs[0])) {
            $repairs[0]['spec']['original_core_answer'] = is_string($output['core_answer'] ?? null)
                ? $output['core_answer'] : '';
        }
        if ($repairs !== [] && count($repairs) <= $state['recovery_calls_left']) {
            $state['recovery_calls_left'] -= count($repairs);
            // 先完成本段，后续评分/选题才能引用最终问题编号。
            array_splice($state['pending'], 0, 0, $repairs);
        } elseif ($repairs !== []) {
            $state['chunk_meta'][$key]['recovery_skipped'] = 'call_budget_exhausted';
        }

        return $llm;
    }
}
