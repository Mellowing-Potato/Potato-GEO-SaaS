<?php

namespace App\Services\Geo\AiVisibility;

use App\Jobs\RunAiVisibilityJob;
use App\Models\AiVisibilityRun;
use App\Models\AiVisibilitySource;
use App\Models\ModelCallLog;
use App\Models\User;
use App\Services\Billing\AssistUsageRecorder;
use App\Services\DTOs\AiProviderCallResult;
use App\Services\Enums\SkillCategory;
use App\Support\OperationalTextRedactor;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Monitor execution, usage attribution, saved results and report delivery. */
final class AiVisibilityService
{
    public function __construct(
        private readonly DoubaoArkResponsesClient $client,
        private readonly AiVisibilityConfigurationResolver $config,
        private readonly AiStructuredOutputHealthCheck $healthCheck,
        private readonly AssistUsageRecorder $assistRecorder,
    ) {}

    /**
     * 创建一轮监测（N 个问题 = N 行 run，共享 batch_id）并派发 Job。
     * 调用前提：已在租户中间件上下文（HTTP）或 TenantContext（Job）中。
     *
     * @param  list<string>  $questions  已校验（1..max、每条非空）
     * @return array{batch_id: string, runs: list<AiVisibilityRun>}
     */
    public function createRuns(int $userId, int $projectId, array $questions): array
    {
        $batchId = (string) Str::uuid();

        $runs = [];
        foreach (array_values($questions) as $index => $question) {
            $runs[] = AiVisibilityRun::query()->create([
                'project_id' => $projectId,
                'user_id' => $userId,
                'batch_id' => $batchId,
                'question_index' => $index,
                'question' => $question,
                'status' => AiVisibilityRun::STATUS_PENDING,
                'provider' => $this->config->providerName(),
                'model' => $this->config->model(),
            ]);
        }

        foreach ($runs as $run) {
            RunAiVisibilityJob::dispatch($run->id, $run->tenant_id);
        }

        return ['batch_id' => $batchId, 'runs' => $runs];
    }

    /**
     * 执行一个问题（Job 调用；TenantContext 已由 Job 还原）。
     * 六步模式见 RunGeoAnalysisJob / 计划书 §十四——本方法承担其中的「业务 + 落终态」。
     */
    public function executeQuestion(int $runId, bool $recoveryOnly = false): void
    {
        $run = AiVisibilityRun::query()->find($runId);
        if (! $run) {
            Log::error('AiVisibilityService: run not found.', ['run_id' => $runId]);

            return;
        }

        if ($run->status === AiVisibilityRun::STATUS_COMPLETED) {
            return;
        }

        // CAS claim one pending run, or one explicitly unresolved old attempt.
        $claimStatus = $recoveryOnly ? AiVisibilityRun::STATUS_RECONCILIATION_REQUIRED : AiVisibilityRun::STATUS_PENDING;
        $claimed = AiVisibilityRun::query()
            ->where('id', $runId)
            ->where('status', $claimStatus)
            ->update([
                'status' => AiVisibilityRun::STATUS_RUNNING,
                'started_at' => now(),
                'completed_at' => null,
            ]);
        if ($claimed === 0) {
            // running=另一个进程在做；completed/failed=终态直接跳过。
            Log::info('AiVisibilityService: run no longer pending, aborting.', [
                'run_id' => $runId,
                'status' => $run->status,
            ]);

            return;
        }

        $run = $run->fresh();

        // 缺 project_id ＝ 扣不了钱 ＝ 不能白跑（spike 执行书「不再静默跳过」约定）。
        if (! $run->project_id) {
            $this->finalizeFailure($run, '缺少项目归属，任务未执行。');

            return;
        }

        // Store the logical supplier attempt on the durable question row before
        // sending. A job restart must reuse it, including when the first HTTP
        // response was lost after the gateway had already paid its supplier.
        $attemptMetadata = $run->metadata ?? [];
        $attemptMetadata['model_client_request_id'] ??= 'visibility:'.$run->id.':supplier:1';
        $run->update(['metadata' => $attemptMetadata]);
        $result = $recoveryOnly
            ? $this->client->recover($run->question, 'visibility:'.$run->id,
                $attemptMetadata['model_client_request_id'], $attemptMetadata['provider_attempt'] ?? [])
            : $this->client->ask($run->question, 'visibility:'.$run->id,
                $attemptMetadata['model_client_request_id'], function (array $receipt) use ($run): void {
                    DB::transaction(function () use ($run, $receipt): void {
                        $current = AiVisibilityRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
                        if ($current->status !== AiVisibilityRun::STATUS_RUNNING) {
                            throw new \RuntimeException('Monitoring run is no longer owned by this request.');
                        }
                        $current->update(['metadata' => array_merge($current->metadata ?? [], ['provider_attempt' => $receipt])]);
                    });
                });
        $run = $run->fresh();

        if ($result->pendingReconciliation) {
            $this->recordUsage($run, $result);
            AiVisibilityRun::query()->whereKey($run->id)->update([
                'status' => AiVisibilityRun::STATUS_RECONCILIATION_REQUIRED,
                'error_message' => $result->errorMessage ?? '供应商请求结果待核对。',
                'completed_at' => now(),
            ]);

            return;
        }

        $health = $this->healthCheck->evaluate($result);

        if (! $result->success) {
            $this->persistFailedAttemptUsage($run, $result, $health);
            $this->finalizeFailure($run, $result->errorMessage ?? 'AI 上游调用失败。');

            return;
        }

        // Record the original model result and its usage before completing the run.
        $usageSnapshot = $this->recordUsage($run, $result);


        $this->persistSources($run, $result->sources);

        $metadata = array_merge($run->metadata ?? [], [
            'usage_available' => ($result->gateway['usage_available'] ?? true) !== false,
            'search_queries' => $result->searchQueries,
            'search_count_estimated' => $result->searchCountEstimated,
            'health' => $health,
            'upstream_status' => $result->finishReason,
        ], $usageSnapshot);

        AiVisibilityRun::query()->where('id', $run->id)->update([
            'answer_text' => $result->answerText,
            'prompt_tokens' => $result->promptTokens,
            'completion_tokens' => $result->completionTokens,
            'cached_tokens' => $result->cachedTokens,
            'search_call_count' => $result->searchCallCount,
            'duration_seconds' => $result->durationSeconds,
            'metadata' => $metadata,
            'status' => AiVisibilityRun::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        Log::info('AiVisibilityService: run completed.', [
            'run_id' => $run->id,
            'batch_id' => $run->batch_id,
            'sources' => count($result->sources),
            'search_calls' => $result->searchCallCount,
        ]);
    }

    /**
     * 无租户上下文时直接落失败（Job 的 failed() / 租户不存在分支使用）。
     * 显式 where tenant_id，绕过 fail-open 的 TenantScope。
     */
    public function markFailedWithoutScope(int $runId, int $tenantId, string $message): void
    {
        try {
            AiVisibilityRun::query()
                ->withoutGlobalScopes()
                ->where('id', $runId)
                ->where('tenant_id', $tenantId)
                ->whereNotIn('status', AiVisibilityRun::TERMINAL_STATUSES)
                ->update([
                    'status' => AiVisibilityRun::STATUS_FAILED,
                    'error_message' => OperationalTextRedactor::redact($message) ?: '队列任务异常终止。',
                ]);
        } catch (Throwable) {
            // 静默——避免循环异常
        }
    }

    // ── 报告生成（单 6 download 复用）────────────────────────────────────

    public function buildMarkdownReport(AiVisibilityRun $run): string
    {
        $lines = [];
        $lines[] = '# AI 可见性监测报告 #'.$run->id;
        $lines[] = '';
        $lines[] = '- 监测问题：'.$run->question;
        $lines[] = '- 执行状态：'.$run->status;
        if ($run->completed_at) {
            $lines[] = '- 完成时间：'.$run->completed_at->copy()->timezone('Asia/Shanghai')->format('Y-m-d H:i:s').'（北京时间，UTC+8）';
        }
        $lines[] = '- 模型：'.$run->model;
        $lines[] = '';

        $queries = $run->searchQueries();
        if ($queries !== []) {
            $lines[] = '## AI 实际搜索的词';
            $lines[] = '';
            foreach ($queries as $query) {
                $lines[] = '- '.$query;
            }
            $lines[] = '';
        }

        $lines[] = '## AI 原始回答';
        $lines[] = '';
        $lines[] = (string) $run->answer_text;
        $lines[] = '';

        // 历史运行可能保存了重复 annotation；下载时也按 URL 去重。
        $sources = $run->sources()->orderBy('rank')->get()
            ->unique(fn ($source) => trim((string) $source->url))
            ->values();
        if ($sources->isNotEmpty()) {
            $lines[] = '## 检索参考（'.$sources->count().' 条）';
            $lines[] = '';
            if (preg_match('/\[\d+\]/u', (string) $run->answer_text) !== 1) {
                $lines[] = '> 上游回答未标出正文引用。下列内容是本次联网检索返回的参考结果，不代表正文逐条采用。';
                $lines[] = '';
            }
            foreach ($sources as $source) {
                $lines[] = '### '.$source->citation_key.' '.($source->title ?? $source->domain ?? $source->url);
                $meta = [];
                if ($source->site_name) {
                    $meta[] = '站点：'.$source->site_name;
                }
                if ($source->published_at) {
                    $meta[] = '发布时间：'.$source->published_at->format('Y-m-d');
                }
                if ($meta !== []) {
                    $lines[] = '';
                    $lines[] = implode(' · ', $meta);
                }
                $lines[] = '';
                $lines[] = '原文：'.$source->url;
                if ($source->snippet) {
                    $lines[] = '';
                    $lines[] = '> '.mb_substr($source->snippet, 0, 200);
                }
                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }

    public function buildHtmlReport(AiVisibilityRun $run): string
    {
        $markdown = $this->buildMarkdownReport($run);

        $body = Str::of($markdown)
            ->markdown([
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ]);

        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
            .'<title>AI 可见性监测报告 #'.$run->id.'</title>'
            .'<style>body{font-family:system-ui,-apple-system,"PingFang SC",sans-serif;max-width:860px;margin:40px auto;padding:0 20px;line-height:1.7;color:#1f2937}blockquote{border-left:3px solid #d1d5db;margin:0;padding-left:12px;color:#4b5563}</style>'
            .'</head><body>'.$body.'</body></html>';
    }

    // ── 私有 ─────────────────────────────────────────────────────────────

    /** Record model identity and usage; reuse an already recorded monitoring result. */
    private function recordUsage(AiVisibilityRun $run, AiVisibilityResult $result): array
    {
        $priorLog = ModelCallLog::query()->where('metadata->run_id', $run->id)
            ->where('project_id', $run->project_id)->where('user_id', $run->user_id)
            ->orderByDesc('id')->first();
        if ($priorLog) {
            if ($priorLog->status === 'unknown' && ! $result->pendingReconciliation
                && $priorLog->provider === $result->provider && $priorLog->model === $result->model
                && data_get($priorLog->metadata, 'gateway.request_id') === ($result->gateway['request_id'] ?? null)) {
                $knownUsage = ($result->gateway['usage_available'] ?? true) !== false;
                $priorLog->update(['status' => $result->success ? 'completed' : 'failed',
                    'prompt_tokens' => $knownUsage ? $result->promptTokens : null,
                    'completion_tokens' => $knownUsage ? $result->completionTokens : null,
                    'total_tokens' => $knownUsage ? $result->promptTokens + $result->completionTokens : null,
                    'metadata' => array_merge($priorLog->metadata ?? [], ['gateway' => $result->gateway])]);
                \App\Models\SkillExecution::query()->whereKey($priorLog->skill_execution_id)
                    ->where('project_id', $run->project_id)->where('user_id', $run->user_id)
                    ->where('status', 'billing_pending')->update([
                        'status' => $result->success ? 'completed' : 'failed',
                        'error_message' => $result->success ? null : $result->errorMessage,
                    ]);
            }
            return ['model_call_log_id' => $priorLog->id];
        }
        $callResult = new AiProviderCallResult(
            success: $result->success,
            provider: $result->provider,
            model: $result->model,
            content: $result->answerText,
            promptTokens: $result->promptTokens,
            completionTokens: $result->completionTokens,
            totalTokens: $result->promptTokens + $result->completionTokens,
            durationSeconds: $result->durationSeconds,
            gateway: $result->gateway ?: ['attribution' => ['feature_id' => 'ai-visibility', 'task_id' => 'visibility:'.$run->id]],
        );
        $log = $this->assistRecorder->record(
            projectId: $run->project_id, userId: $run->user_id, result: $callResult,
            kind: 'ai-visibility', label: 'AI 模型可见性监测', category: SkillCategory::GeoMeasurement,
            metadata: ['run_id' => $run->id, 'batch_id' => $run->batch_id,
                'question_index' => $run->question_index, 'search_call_count' => $result->searchCallCount],
        );
        return ['model_call_log_id' => $log?->id];
    }

    /**
     * @param  list<AiVisibilitySourceData>  $sources
     */
    private function persistSources(AiVisibilityRun $run, array $sources): void
    {
        if ($sources === []) {
            return;
        }

        $rows = array_map(
            static fn (AiVisibilitySourceData $source): array => array_merge($source->toRow(), [
                'tenant_id' => $run->tenant_id,
                'run_id' => $run->id,
                // 批量 insert 走 Query\Builder，不走模型 casts → metadata 必须手工 JSON 化
                // （Connection::prepareBindings 只处理 DateTime/bool，数组会绑定失败）。
                'metadata' => $source->metadata === []
                    ? null
                    : json_encode($source->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]),
            $sources,
        );

        try {
            // 同 run 重跑（理论只有 CAS 失败前的极端窗口）不重复插入信源：先清后插。
            AiVisibilitySource::query()->where('run_id', $run->id)->delete();
            DB::table('ai_visibility_sources')->insert($rows);
        } catch (Throwable $e) {
            Log::error('AiVisibilityService: failed to persist sources.', [
                'run_id' => $run->id,
                'count' => count($rows),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function finalizeFailure(AiVisibilityRun $run, string $message): void
    {
        AiVisibilityRun::query()->where('id', $run->id)->update([
            'status' => AiVisibilityRun::STATUS_FAILED,
            'error_message' => OperationalTextRedactor::redact($message) ?: 'AI 上游调用失败。',
            'completed_at' => now(),
        ]);

        Log::warning('AiVisibilityService: run failed.', [
            'run_id' => $run->id,
            'batch_id' => $run->batch_id,
        ]);
    }

    private function persistFailedAttemptUsage(AiVisibilityRun $run, AiVisibilityResult $result, array $health): void
    {
        $this->recordUsage($run, $result);

        AiVisibilityRun::query()->whereKey($run->id)->update([
            'prompt_tokens' => $result->promptTokens,
            'completion_tokens' => $result->completionTokens,
            'cached_tokens' => $result->cachedTokens,
            'search_call_count' => $result->searchCallCount,
            'duration_seconds' => $result->durationSeconds,
            'metadata' => array_merge($run->metadata ?? [], [
                'search_queries' => $result->searchQueries,
                'search_count_estimated' => $result->searchCountEstimated,
                    'health' => $health,
                'upstream_status' => $result->finishReason,
                'usage_available' => ($result->gateway['usage_available'] ?? false) === true,
            ]),
        ]);
    }
}
