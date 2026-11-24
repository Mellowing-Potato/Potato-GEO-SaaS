<?php

namespace App\Jobs;

use App\Models\SkillExecution;
use App\Models\SkillExecutionRelay;
use App\Models\Tenant;
use App\Services\Billing\UnresolvedModelAttempt;
use App\Services\Billing\UnresolvedEmbeddingAttempt;
use App\Services\Billing\UnrecordedModelResult;
use App\Services\Content\MaterialLibraryService;
use App\Services\Contracts\SkillAgentRunner;
use App\Services\DTOs\SkillBrief;
use App\Services\DTOs\SkillExecutionResult;
use App\Services\Enums\ExecutionStatus;
use App\Services\Reports\ReportAssetService;
use App\Services\SkillAgent\SkillAgentRunnerService;
use App\Support\OperationalTextRedactor;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 异步 Skill 执行队列任务
 *
 * 从数据库队列消费，还原租户上下文后跑执行管线，完毕后更新 skill_executions 记录状态。
 *
 * 两条路：
 *   ① 单进程路——不分段的技能，一个任务从头跑到尾（历史行为，未改）。
 *   ② 接力路——分段报告。一段一个任务，本任务自己派发下一步：
 *          step 0    准备（资产 / RAG / prompt），不发模型请求
 *          step 1..N 每步跑一段，各约 150 秒
 *          step N+1  收尾（完整性闸 + 合并 + 渲染）
 *      为什么必须这样：一份完整报告要 35,000～40,000 词元，按实测 16.4 秒/千词元
 *      要跑十来分钟，而一个 PHP 进程只有 set_time_limit(420) 秒——一个进程装不下。
 *      详见 SkillAgentRunnerService 里「接力执行（relay）」那段。
 *
 * 取消机制：
 *   在 handle() 开头检查 execution 是否已被标记为 cancelled，若是则跳过执行。
 *   对于 running 中的任务无法中断单次 LLM 调用，但接力每一步开跑前都会再查一次，
 *   所以取消最多多花一段的钱，不会把剩下的段都跑完。
 */
class ExecuteSkillJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 超时时间（秒），与 SkillAgentRunnerService 里的 set_time_limit(420) 匹配，
     * 额外留 60 秒给前后处理。
     *
     * ⚠️ 这是**一步**的预算，不是一份报告的预算。接力之后一份报告的总时长
     *    分摊在 N+2 个任务上，谁也碰不到这个数——这正是改接力的目的。
     *    别因为「报告要跑十分钟」就来调大它：调大它就得连着调 worker --timeout
     *    和 retry_after，而 retry_after 超了会让队列把同一个任务派给第二个 worker，
     *    两份报告两次扣费。
     */
    public int $timeout = 480;

    /**
     * 最大尝试次数（LLM 调用开销大，不做自动重试）
     */
    public int $tries = 1;

    /**
     * @param  int    $executionId  skill_executions 记录 ID
     * @param  array  $briefData    SkillBrief::toArray() 序列化数据
     * @param  int    $tenantId     租户 ID（用于队列 Worker 还原 TenantContext）
     * @param  int    $step         接力第几步。0＝首次进入；>0＝接力续跑。
     */
    public function __construct(
        public readonly int $executionId,
        public readonly array $briefData,
        public readonly int $tenantId,
        public readonly int $step = 0,
    ) {
        $this->onQueue('skills');
    }

    public function handle(SkillAgentRunner $runner, ReportAssetService $reportAssets, MaterialLibraryService $materials): void
    {
        // The initial pending -> running CAS protects step 0. Later steps also
        // need a lock: two deliveries can otherwise read the same relay state
        // and issue the same paid call before completed_steps is updated.
        // Scope it to one step so the dispatched successor can start before
        // this worker finishes acknowledging its current queue message.
        $lock = Cache::lock(
            "skill-execution:{$this->executionId}:step:{$this->currentStep()}",
            $this->timeout + 60,
        );
        if (! $lock->get()) {
            Log::info('ExecuteSkillJob: duplicate step already running', [
                'execution_id' => $this->executionId,
                'step' => $this->currentStep(),
            ]);

            return;
        }

        try {
            $this->handleOnce($runner, $reportAssets, $materials);
        } finally {
            $lock->release();
        }
    }

    private function handleOnce(SkillAgentRunner $runner, ReportAssetService $reportAssets, MaterialLibraryService $materials): void
    {
        // ─── 1. 还原租户上下文（队列 Worker 无请求生命周期） ───
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            Log::error('ExecuteSkillJob: tenant not found', [
                'execution_id' => $this->executionId,
                'tenant_id' => $this->tenantId,
            ]);
            $this->fail_execution_without_scope('租户不存在');
            return;
        }

        if (! $tenant->isActive()) {
            Log::warning('ExecuteSkillJob: tenant inactive, skipping queued execution', [
                'execution_id' => $this->executionId,
                'tenant_id' => $this->tenantId,
                'tenant_status' => $tenant->status,
            ]);
            $this->fail_execution_without_scope('Tenant is paused; queued Skill execution was not started.');
            return;
        }

        TenantContext::set($tenant);

        // ─── 2. 加载执行记录 ───
        $execution = SkillExecution::find($this->executionId);
        if (! $execution) {
            Log::error('ExecuteSkillJob: execution record not found', [
                'execution_id' => $this->executionId,
            ]);
            TenantContext::forget();
            return;
        }

        // ─── 3. 检查是否已被取消（用户在排队期间点了取消） ───
        if ($execution->status === ExecutionStatus::Cancelled->value) {
            Log::info('ExecuteSkillJob: execution was cancelled before start, skipping', [
                'execution_id' => $this->executionId,
                'step' => $this->currentStep(),
            ]);
            // 接力跑到一半被取消：状态行必须在这里清掉。这条 return 排在下面那个
            // step 分支之前，漏了它，状态行就会永远留在库里——而它带着每一段的
            // 模型原文，一条就是十几万字符。
            $this->discardRelay();
            TenantContext::forget();
            return;
        }

        // ─── 4. 标记为 running（CAS 更新：仅在仍为 pending 时才转为 running，防止取消竞态） ───
        //     只有首次进入（step 0）需要做这个转换。接力后续每一步进来时状态本来就是
        //     running，再 CAS 一次只会自己把自己挡回去，整条接力当场断掉。
        if ($this->currentStep() === 0) {
            $rows = SkillExecution::where('id', $this->executionId)
                ->where('status', ExecutionStatus::Pending->value)
                ->update(['status' => ExecutionStatus::Running->value]);

            if ($rows === 0) {
                // 状态已不是 pending（可能被用户取消了），放弃执行
                Log::info('ExecuteSkillJob: execution no longer pending, aborting', [
                    'execution_id' => $this->executionId,
                ]);
                TenantContext::forget();
                return;
            }
        } elseif ($execution->status !== ExecutionStatus::Running->value) {
            // 接力中途状态被别人改掉了（取消 / 被清道夫判死）：不再往下接，也把状态行清掉。
            Log::info('ExecuteSkillJob: relay aborted, execution no longer running', [
                'execution_id' => $this->executionId,
                'step' => $this->currentStep(),
                'status' => $execution->status,
            ]);
            $this->discardRelay();
            TenantContext::forget();
            return;
        }

        // ─── 5. 反序列化 SkillBrief 并执行 ───
        try {
            $brief = SkillBrief::fromArray($this->briefData);
            // ─── 5a. 接力路 ───
            // ⚠️ 只有真正的 SkillAgentRunnerService 会接力。测试里有十来个手写的
            //    SkillAgentRunner 匿名实现类，它们没有这几个方法——所以这里用
            //    instanceof 判断而不是往契约上加方法（加了那十几个测试会全部 fatal）。
            //    判不中就自动回落到下面 5b 的单进程老路，行为与改动前一模一样。
            $relayRunner = $runner instanceof SkillAgentRunnerService ? $runner : null;

            if ($relayRunner !== null && $this->currentStep() > 0) {
                $relay = SkillExecutionRelay::where('skill_execution_id', $this->executionId)->first();
                if ($relay !== null && $this->currentStep() !== $relay->completed_steps + 1) {
                    Log::info('ExecuteSkillJob: stale or out-of-order relay step ignored', [
                        'execution_id' => $this->executionId,
                        'step' => $this->currentStep(),
                        'completed_steps' => $relay->completed_steps,
                    ]);

                    return;
                }

                $progress = $relayRunner->advanceRelay($brief);

                if ($progress->ranChunk) {
                    // 本进程刚发过模型请求、烧掉一两分钟了，交棒给下一个任务。
                    $this->dispatchNextStep();

                    return;
                }

                // 没段可跑了 → 本进程是干净的，正好用来跑收尾（渲染要起 Python 进程）。
                $result = $relayRunner->finalizeRelay($brief);
                $this->persistResult($execution, $brief, $result, $reportAssets, $materials);

                return;
            }

            if ($relayRunner !== null && $relayRunner->beginRelay($brief) !== null) {
                // 这个技能要分段：准备工作已落库，把第一段交给下一个任务去跑。
                $this->dispatchNextStep();

                return;
            }

            // ─── 5b. 单进程老路（不分段的技能 / 测试替身） ───
            $result = $runner->execute($brief);
            $this->persistResult($execution, $brief, $result, $reportAssets, $materials);

        } catch (UnresolvedEmbeddingAttempt $e) {
            $e->hold($execution, $this->briefData);
            Log::warning('ExecuteSkillJob: gateway embedding awaits reconciliation', [
                'execution_id' => $this->executionId,
            ]);
        } catch (UnresolvedModelAttempt $e) {
            $e->hold($execution, $this->briefData);
            Log::warning('ExecuteSkillJob: gateway model attempt awaits reconciliation', [
                'execution_id' => $this->executionId,
            ]);
        } catch (UnrecordedModelResult $e) {
            $e->hold($execution, $this->briefData);
            Log::warning('ExecuteSkillJob: successful model result awaits local usage reconciliation', [
                'execution_id' => $this->executionId,
            ]);
        } catch (\Throwable $e) {
            // 接力半路炸了：状态行留着没用（这条执行已经判失败，不会再有人来接），
            // 而它带着每段的模型原文，很占地方。
            // 原模型尝试已发送但结果未核对时，状态行仍是续办该段的唯一依据。
            if (! $this->hasRecoverableModelCheckpoint()) {
                $this->discardRelay();
            }

            $this->fail_execution($e->getMessage());

            Log::error('ExecuteSkillJob: unhandled exception', [
                'execution_id' => $this->executionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        } finally {
            TenantContext::forget();
        }
    }

    /**
     * 把下一步接力推进队列。
     *
     * ⚠️ 必须在 handle() 里派发，不能挪到 finally 之后：Laravel 是先跑完 handle()
     *    才删掉当前 job 行的，在这里派发能保证「新任务行已存在」与「旧任务行还没删」
     *    有重叠——中间不出现「队列里一条相关 job 都没有」的空窗。清道夫判死的第二个
     *    条件正是「队列里没有对应 job 行」，出现空窗就可能被它当成卡死清掉。
     */
    /**
     * 当前是接力第几步。
     *
     * 🔴 必须走 `?? 0`，不能直接读 $this->step —— 上线那一刻队列里可能还压着
     *    部署前入队的任务，它们的序列化数据里**没有 step 这个属性**。
     *    readonly 类型属性未初始化时直接读会抛
     *    「Typed property must not be accessed before initialization」，
     *    整条执行当场判失败；而 `??` 走的是 isset 语义，安全返回 0，
     *    老任务就当作全新的第 0 步跑，行为与部署前一致。
     *    （2026-09-06 加接力时补的，PHP 8.4 实测过两种读法的差别。）
     */
    private function currentStep(): int
    {
        return $this->step ?? 0;
    }

    private function dispatchNextStep(): void
    {
        self::dispatch($this->executionId, $this->briefData, $this->tenantId, $this->currentStep() + 1);
    }

    /**
     * 丢弃接力中间状态。失败 / 取消 / 中途被判死时调用。
     */
    private function discardRelay(): void
    {
        try {
            SkillExecutionRelay::where('skill_execution_id', $this->executionId)->delete();
        } catch (\Throwable) {
            // 静默：清理失败不该盖掉真正的失败原因
        }
    }

    /**
     * 把执行结果落到 skill_executions（含文章、素材用量、质量报告）。
     *
     * 接力路和单进程老路共用这一段：两条路产出的都是同一个 SkillExecutionResult，
     * 落库口径必须一模一样，否则「同步报告能看、异步报告缺东西」这种问题会重演。
     */
    private function persistResult(
        SkillExecution $execution,
        SkillBrief $brief,
        SkillExecutionResult $result,
        ReportAssetService $reportAssets,
        MaterialLibraryService $materials,
    ): void {
        // Only the short database save is transactional; model and renderer
        // calls stay outside. The row lock serializes cancellation with article,
        // material and status writes, and rolls back all three on save failure.
        DB::transaction(function () use ($execution, $brief, $result, $reportAssets, $materials): void {
            $current = SkillExecution::whereKey($execution->id)->lockForUpdate()->first();
            if ($current === null || $current->status === ExecutionStatus::Completed->value) {
                return;
            }

            $this->persistLockedResult($current, $brief, $result, $reportAssets, $materials);
        });
    }

    private function persistLockedResult(
        SkillExecution $execution,
        SkillBrief $brief,
        SkillExecutionResult $result,
        ReportAssetService $reportAssets,
        MaterialLibraryService $materials,
    ): void {
        $terminal = in_array($execution->status, [
            ExecutionStatus::Cancelled->value,
            ExecutionStatus::Failed->value,
        ], true);

        // 组装输出数据（与同步 execute 控制器逻辑保持一致）
        $outputData = $this->normalizeRenderedOutputPaths($result->outputPaths);
        $rawOutput = $result->intermediateData['raw_output'] ?? null;
        if ($rawOutput) {
            $outputData['result_text'] = $rawOutput;
        }
        if (isset($result->intermediateData['structured'])) {
            $outputData['structured'] = $result->intermediateData['structured'];
        }
        $mode = $result->intermediateData['pipeline_mode'] ?? null;
        if ($mode) {
            $outputData['pipeline_mode'] = $mode;
        }
        if (isset($result->intermediateData['format_status'])) {
            $outputData['format_status'] = $result->intermediateData['format_status'];
        }
        if (isset($result->intermediateData['rendered_formats'])) {
            $outputData['rendered_formats'] = $this->normalizeRenderedFormats($result->intermediateData['rendered_formats']);
        }
        // 这份报告是一次写完的，还是分段/分节接力写出来的 —— 事后查一条执行记录时，
        // 光看正文分不出来，而这两条路的失败原因完全不同。只落这一个标记，
        // 逐段的明细（chunk_meta）留在日志里，不往执行记录里塞。
        if (isset($result->intermediateData['chunk_format'])) {
            $outputData['chunk_format'] = $result->intermediateData['chunk_format'];
        }

        $article = null;
        if ($result->success && ! $terminal) {
            $candidateArticleId = $result->intermediateData['article_id'] ?? $outputData['article_id'] ?? null;
            $article = $reportAssets->createOrLinkForSkillExecution(
                $execution,
                is_string($result->intermediateData['billing_pending_article_content'] ?? null)
                    ? $result->intermediateData['billing_pending_article_content']
                    : $this->resultText($outputData),
                is_numeric($candidateArticleId) ? (int) $candidateArticleId : null,
                is_array($outputData['structured'] ?? null) ? $outputData['structured'] : null,
                $result->qualityReport,
            );
            $outputData = $reportAssets->outputDataWithArticle($outputData, $article);

            // 子链 A 闭环（异步版）：执行成功才记录素材使用，让素材库「使用 N 次」变真实。
            if (! empty($brief->materialRefs)) {
                $materials->recordUsage($brief->materialRefs);
            }
        }

        $execution->update([
            'status' => $terminal ? $execution->status : ($result->success
                ? ExecutionStatus::Completed->value
                : ExecutionStatus::Failed->value),
            'output_data' => $outputData,
            'quality_report' => $this->normalizeQualityReport($result->qualityReport),
            'article_id' => $article?->id ?? $execution->article_id,
            'duration_seconds' => (int) $result->durationSeconds,
            'token_usage' => max((int) $execution->token_usage, $result->tokenUsage),
            'error_message' => $terminal ? $execution->error_message
                : ($result->success ? null : $this->friendlySkillError($result->errorMessage)),
            'billing_pending_result' => $terminal ? $execution->billing_pending_result : null,
        ]);

        Log::info('ExecuteSkillJob: completed', [
            'execution_id' => $this->executionId,
            'success' => $result->success,
            'duration' => $result->durationSeconds,
            'tokens' => $result->tokenUsage,
        ]);
    }

    /**
     * 队列任务彻底失败时的回调（超过最大重试次数或不可恢复异常）
     */
    public function failed(?\Throwable $exception): void
    {
        // failed() 在 handle() 的 try/finally 之外运行，
        // TenantContext 可能已被清理或从未设置，需要手动还原。
        $tenant = Tenant::find($this->tenantId);
        if ($tenant) {
            TenantContext::set($tenant);
        }

        // A late failure callback from an acknowledged predecessor must not
        // erase the state now owned by the next relay step.
        $relay = SkillExecutionRelay::where('skill_execution_id', $this->executionId)->first();
        if ($relay !== null && $this->currentStep() !== $relay->completed_steps + 1) {
            TenantContext::forget();

            return;
        }

        // 已预备的模型尝试可能仍在网关结算，保留接力状态供原段续办。
        if (! $this->hasRecoverableModelCheckpoint()) {
            $this->discardRelay();
        }

        $this->fail_execution(
            $exception?->getMessage() ?? '队列任务异常终止'
        );

        TenantContext::forget();
    }

    /**
     * 有 TenantContext 时更新执行记录为失败
     */
    private function fail_execution(string $message): void
    {
        try {
            DB::transaction(function () use ($message): void {
                $execution = SkillExecution::where('id', $this->executionId)->lockForUpdate()->first();
                if (! $execution || ! in_array($execution->status, [
                    ExecutionStatus::Pending->value, ExecutionStatus::Running->value,
                ], true)) {
                    return;
                }
                $kind = data_get($execution->billing_pending_result, 'kind');
                $prepared = in_array($kind,
                    ['model_attempt_prepared', 'embedding_attempt_prepared', 'embedding_result_recorded'], true);
                $execution->update([
                    'status' => $prepared ? ExecutionStatus::BillingPending->value : ExecutionStatus::Failed->value,
                    'error_message' => $prepared
                        ? (in_array($kind, ['embedding_attempt_prepared', 'embedding_result_recorded'], true)
                            ? '原向量请求待核对，执行已暂缓；请勿重新提交'
                            : '原模型请求待核对，执行已暂缓；请勿重新提交')
                        : $this->friendlySkillError($message),
                ]);
            });
        } catch (\Throwable) {
            // 静默——避免循环异常
        }
    }

    private function hasRecoverableModelCheckpoint(): bool
    {
        try {
            return in_array(data_get(SkillExecution::whereKey($this->executionId)->first()?->billing_pending_result,
                'kind'), ['embedding_attempt_prepared', 'embedding_attempt_unknown', 'embedding_result_recorded',
                    'model_attempt_prepared', 'model_attempt_unknown',
                    'model_result_unrecorded', 'model_result_recorded'], true);
        } catch (\Throwable) {
            // A failed lookup is not evidence that a paid attempt is absent.
            return true;
        }
    }

    /**
     * 无 TenantContext 时绕过租户作用域直接更新（租户已不存在的情况）
     */
    private function fail_execution_without_scope(string $message): void
    {
        try {
            SkillExecution::withoutGlobalScopes()
                ->where('id', $this->executionId)
                ->where('tenant_id', $this->tenantId)
                ->whereIn('status', [
                    ExecutionStatus::Pending->value,
                    ExecutionStatus::Running->value,
                ])
                ->update([
                    'status' => ExecutionStatus::Failed->value,
                    'error_message' => $this->friendlySkillError($message),
                ]);
        } catch (\Throwable) {
            // 静默
        }
    }

    /**
     * 规范化质量报告格式，确保前端能正确读取 score + checks 数组
     *
     * Python 渲染脚本可能输出:
     *   { overall_score: 85, checks: {markdown_report_exists: true, ...}, failed_checks: [...] }
     *
     * 前端期望:
     *   { score: 85, passed: true, checks: [{name: 'xxx', passed: true}, ...] }
     */
    private function resultText(array $outputData): string
    {
        $value = $outputData['result_text'] ?? $outputData['markdown_content'] ?? null;
        if (is_string($value)) {
            return $value;
        }

        if (isset($outputData['structured'])) {
            return json_encode($outputData['structured'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '';
        }

        return '';
    }

    private function friendlySkillError(?string $message): ?string
    {
        if (! $message) {
            return null;
        }

        if (str_contains($message, "\n") || str_contains($message, '\\') || str_contains($message, '/')) {
            return 'Skill execution failed. Please review the brief and try again.';
        }

        return OperationalTextRedactor::redact($message);
    }

    private function normalizeRenderedOutputPaths(array $paths): array
    {
        if (isset($paths['md']) && ! isset($paths['markdown'])) {
            $paths['markdown'] = $paths['md'];
        }
        unset($paths['md']);

        return $paths;
    }

    private function normalizeRenderedFormats(array $formats): array
    {
        $validFormats = ['markdown', 'html', 'docx', 'pdf'];
        $normalized = array_map(
            fn (string $format): string => $format === 'md' ? 'markdown' : $format,
            array_filter($formats, fn (mixed $format): bool => is_string($format)),
        );

        return array_values(array_intersect(
            $validFormats,
            array_values(array_unique($normalized)),
        ));
    }

    private function normalizeQualityReport(?array $report): ?array
    {
        if ($report === null) {
            return null;
        }

        // 统一 score 字段：overall_score → score
        $score = $report['score'] ?? $report['overall_score'] ?? null;

        // 统一 checks 字段：如果是关联数组（对象），转为 [{name, passed}] 数组
        $checks = is_array($report['checks'] ?? null) ? $report['checks'] : [];
        if (! empty($checks) && ! array_is_list($checks)) {
            $normalized = [];
            foreach ($checks as $key => $passed) {
                if (is_bool($passed)) {
                    $label = match ($key) {
                        'markdown_report_exists' => 'Markdown 报告',
                        'html_report_exists'     => 'HTML 报告',
                        'docx_report_exists'     => 'Word 报告',
                        'html_has_sticky_menu'   => 'HTML 导航菜单',
                        'html_has_toc_links'     => 'HTML 目录链接',
                        'html_wraps_long_text'   => 'HTML 长文换行',
                        'brand_name_present'     => '品牌名称',
                        'section_count_at_least_5' => '章节数量(≥5)',
                        'required_sections_present' => '必需章节完整',
                        'section_count_at_least_15' => '报告章节至少 15 章',
                        'question_bank_at_least_18' => '问题清单至少 18 条',
                        'follow_up_chains_at_least_8' => '追问链至少 8 条',
                        'conclusion_present'     => '结论段落',
                        default => str_replace('_', ' ', $key),
                    };
                    $normalized[] = ['name' => $label, 'passed' => $passed];
                }
            }
            $checks = $normalized;
        }

        // 显式质检结论优先；只有旧报告未给结论时才按分数（或检查项）推导。
        // Python 正常报告没有 score，不能把缺失分数当作 0；失败检查也不能被高分盖掉。
        $passed = is_bool($report['passed'] ?? null)
            ? $report['passed']
            : ($score !== null ? is_numeric($score) && (float) $score >= 60 : $checks !== []);
        $hasFailedCheck = ! empty(array_filter(
            $checks,
            static fn ($check): bool => ! is_array($check) || ($check['passed'] ?? false) !== true,
        ));

        // 保留 hash、issues、next_action、auto_fixes 等完整证据，只统一展示字段。
        return array_merge($report, [
            'score' => $score,
            'passed' => $passed && ! $hasFailedCheck && empty($report['failed_checks']),
            'checks' => $checks,
        ]);
    }
}
