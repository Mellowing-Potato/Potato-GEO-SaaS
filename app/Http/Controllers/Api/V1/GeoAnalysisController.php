<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\RunGeoAnalysisJob;
use App\Models\ModelCallLog;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\Tenant;
use App\Models\TenantAiKey;
use App\Services\AI\AiProviderServiceImpl;
use App\Services\Billing\ModelUsageRecorder;
use App\Services\Billing\PreparedModelAttempt;
use App\Services\Billing\UnrecordedModelResult;
use App\Services\Billing\UnresolvedModelAttempt;
use App\Services\Contracts\SkillAgentRunner;
use App\Services\DTOs\AiProviderCallResult;
use App\Services\DTOs\SkillBrief;
use App\Services\DTOs\SkillExecutionResult;
use App\Services\Enums\ExecutionStatus;
use App\Services\Geo\GeoAnalysisSummaryExtractor;
use App\Services\Reports\ReportAssetService;
use App\Services\Reports\ReportStorage;
use App\Support\ModelTierLabel;
use App\Support\OperationalTextRedactor;
use App\Support\BillingContext;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GeoAnalysisController extends Controller
{
    private const DOWNLOAD_FORMATS = ['markdown', 'html', 'docx', 'pdf'];

    /** 失败时留存的原始输出上限（字符）。够定位问题即可，别把执行记录撑爆。 */
    private const FAILURE_RAW_OUTPUT_CHARS = 20000;

    public function __construct(
        private readonly AiProviderServiceImpl $aiProvider,
        private readonly SkillAgentRunner $runner,
        private readonly GeoAnalysisSummaryExtractor $summaryExtractor,
        private readonly ModelUsageRecorder $usageRecorder,
        private readonly ReportAssetService $reportAssets,
    ) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        $this->ensureCurrentTenantProject($project);

        $perPage = min((int) $request->query('per_page', 20), 100);

        $analyses = $project->analyses()
            ->with('modelCallLogs')
            ->paginate($perPage)
            // 列表只用来渲染左边那栏历史记录（前端只读 id / 状态 / 时间）。失败排查线索
            // 里的原始输出动辄上万字，一页 20 条就是几百 KB 白跑一趟，这里剔掉；
            // 要看它走 show 端点或直接查库。
            ->through(function (SkillExecution $analysis): array {
                $row = $analysis->toArray();
                unset($row['output_data']['failure_diagnostics']);

                return $row;
            });

        return response()->json($analyses);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->ensureCurrentTenantProject($project);

        $validated = $request->validate([
            'notes' => 'nullable|string|max:2000',
            'fields' => 'nullable|array',
        ]);

        $input = $this->analysisInput($project, $validated);

        $lock = Cache::lock(
            "geo_analysis:project:{$project->id}",
            (int) config('geo.analysis_lock_seconds', 600),
        );
        if (! $lock->get()) {
            return response()->json([
                'message' => '这个项目已经有一次综合诊断在执行中，等它跑完再开始新的。',
                'action' => 'analysis_already_running',
            ], 409);
        }

        $async = (bool) config('geo.analysis_async', false);

        try {
            if ($this->hasActiveAnalysis($project)) {
                return response()->json([
                    'message' => '这个项目已经有一次综合诊断在执行中，等它跑完再开始新的。',
                    'action' => 'analysis_already_running',
                ], 409);
            }

            $execution = SkillExecution::query()->create([
                'project_id' => $project->id,
                'user_id' => $request->user()->id,
                'skill_id' => Project::GEO_ANALYSIS_SKILL_ID,
                'skill_name' => 'GEO MVP Analysis',
                'category' => 'geo-strategy',
                'stage_index' => 1,
                'status' => $async ? 'pending' : 'running',
                'input_data' => $input,
                'model_connection_snapshot' => app(\App\Services\AI\ModelConnectionResolver::class)->snapshot('text'),
            ]);

            if ($async) {
                // 立即入队返回 202，前端轮询 show 端点。correctness 已由网关超时修复兜底；
                // 此路径仅免去长时间挂浏览器。租户上下文由 Job 依 tenantId 还原。
                RunGeoAnalysisJob::dispatch(
                    $execution->id,
                    $project->id,
                    $request->user()->id,
                    $input,
                    (int) TenantContext::id(),
                );

                return response()->json([
                    'analysis' => $this->analysisPayload($execution->fresh()),
                ], 202);
            }

            $this->runAnalysisPipeline($execution, $project, $input, $request->user()->id);

            return $this->analysisResponse($execution->fresh());
        } finally {
            $lock->release();
        }
    }

    /**
     * 同步/异步共用的分析管线入口：按 pipeline_mode 选择 legacy 或 full，
     * 仅落库(status/output_data/报告资产)，不构造 HTTP 响应。异步 Job 复用此方法。
     */
    public function runAnalysisPipeline(SkillExecution $execution, Project $project, array $input, int $userId): void
    {
        // 认领计费主体：全景分析走队列（RunGeoAnalysisJob），那里 Auth 是空的，
        // 事前余额闸认不出该扣谁的钱。$userId 是这条路上的付款人。
        BillingContext::scoped($userId, 'geo-analysis', function () use ($execution, $project, $input, $userId): void {
            if (config('geo.analysis_pipeline_mode', 'legacy') === 'legacy') {
                $this->storeLegacyAnalysis($execution, $input);

                return;
            }

            $this->storeFullPipelineAnalysis($project, $execution, $input, $userId);
        });
    }

    /**
     * 由已落库的 execution 行构造同步响应：失败 502(区分 pipeline 归因)、成功 201。
     */
    private function analysisResponse(SkillExecution $execution): JsonResponse
    {
        if ($execution->status === ExecutionStatus::BillingPending->value) {
            return response()->json(['analysis' => $this->analysisPayload($execution)], 202);
        }
        if ($execution->status === 'failed') {
            // execution 原样序列化会把 output_data 整个带出去。失败排查线索（模型原始
            // 回复，可能几万字）只该留在库里给我们查，没必要塞进给客户的错误响应里，
            // 客户端也从来不读它（前端只看 message 和 action）。
            $executionPayload = $execution->toArray();
            unset($executionPayload['output_data']['failure_diagnostics']);

            return response()->json([
                'message' => OperationalTextRedactor::redact($execution->error_message) ?: 'GEO analysis failed.',
                'action' => config('geo.analysis_pipeline_mode', 'legacy') === 'legacy'
                    ? 'model_service_call_failed'
                    : 'skill_agent_pipeline_failed',
                'execution' => $executionPayload,
            ], 502);
        }

        return response()->json([
            'analysis' => $this->analysisPayload($execution),
        ], 201);
    }

    /**
     * 异步并发闸：存在未终态且未过期(lock_seconds 内)的分析即拒绝，避免同项目重复入队。
     * 过期兜底沿用 analysis_lock_seconds，防止 Worker 崩溃留下的僵尸 running 永久阻塞。
     */
    private function hasActiveAnalysis(Project $project): bool
    {
        return $project->analyses()
            ->where(function ($query): void {
                $query->where('status', ExecutionStatus::BillingPending->value)
                    ->orWhere(function ($active): void {
                        $active->whereIn('status', ['pending', 'running'])
                            ->where('created_at', '>=', now()->subSeconds((int) config('geo.analysis_lock_seconds', 600)));
                    });
            })
            ->exists();
    }

    public function show(Project $project, SkillExecution $analysis): JsonResponse
    {
        $this->ensureCurrentTenantProject($project);
        $this->ensureProjectAnalysis($project, $analysis);

        return response()->json([
            'analysis' => $this->analysisPayload($analysis),
        ]);
    }

    /**
     * 取消全景分析。与发起分析同一道门(can:project:create)。
     * 权限/归属校验必须先过：ensureProjectAnalysis 漏调 = 拿别的项目 analysis id 越权取消。
     * 返回体与 show 端点同构(analysisPayload)，前端轮询代码无需为取消另写解析。
     */
    public function cancel(Project $project, SkillExecution $analysis): JsonResponse
    {
        $this->ensureCurrentTenantProject($project);
        $this->ensureProjectAnalysis($project, $analysis);

        $cancelled = $this->runner->cancel($analysis->id);

        if (! $cancelled) {
            return response()->json(['message' => '无法取消该分析（可能已完成或已取消）'], 422);
        }

        return response()->json([
            'analysis' => $this->analysisPayload($analysis->fresh()),
            'message'  => '已取消分析',
        ]);
    }

    public function download(Project $project, SkillExecution $analysis, string $format = 'markdown'): Response|StreamedResponse
    {
        $this->ensureCurrentTenantProject($project);
        $this->ensureProjectAnalysis($project, $analysis);

        if ($analysis->status !== ExecutionStatus::Completed->value) {
            abort(409, 'Analysis is not ready for download.');
        }

        if (! in_array($format, self::DOWNLOAD_FORMATS, true)) {
            abort(400, 'Unsupported download format.');
        }

        $outputData = $analysis->output_data ?? [];
        if (isset($outputData[$format]) && (! is_string($outputData[$format])
            || ! ReportStorage::belongsToExecution((int) $analysis->id, $outputData[$format]))) {
            abort(404, 'File not found in storage.');
        }
        if (isset($outputData[$format]) && ReportStorage::readDisk($outputData[$format])->exists($outputData[$format])) {
            $filename = basename($outputData[$format]);
            $mimeType = match ($format) {
                'html' => 'text/html; charset=UTF-8',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'pdf' => 'application/pdf',
                default => 'text/markdown; charset=UTF-8',
            };

            return ReportStorage::readDisk($outputData[$format])->download($outputData[$format], $filename, [
                'Content-Type' => $mimeType,
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $filename = sprintf('geo-analysis-%d-%d.md', $project->id, $analysis->id);

        return response($this->analysisMarkdown($analysis), 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function storeLegacyAnalysis(SkillExecution $execution, array $input): void
    {
        $brief = new SkillBrief(
            skillId: Project::GEO_ANALYSIS_SKILL_ID,
            projectId: $execution->project_id,
            userId: $execution->user_id,
            executionId: $execution->id,
        );
        $metadata = ['source' => 'geo_mvp_analysis', 'attempt' => 1];
        $deliveryContext = ['path' => 'legacy_geo_analysis'];
        $clientId = PreparedModelAttempt::save($brief, 'legacy-analysis',
            AiProviderServiceImpl::GEO_TEXT_MODEL, $metadata, $deliveryContext, 'geo-analysis');
        $originalAttribution = [
            'client_request_id' => $clientId, 'task_id' => 'skill:'.$execution->id,
            'feature_id' => 'geo-analysis', 'step_id' => 'legacy-analysis',
        ];
        try {
            $result = $this->aiProvider->generateStructured(
                $this->analysisPrompt($input),
                $this->analysisSchema(),
                'You are a senior GEO and AI-search optimization strategist. Return strict JSON only.',
                ['feature_id' => 'geo-analysis', 'task_id' => 'skill:'.$execution->id,
                    'step_id' => 'legacy-analysis', 'client_request_id' => $clientId, 'execution_id' => $execution->id],
            );
        } catch (\Throwable) {
            (new UnresolvedModelAttempt(['attribution' => $originalAttribution],
                $metadata, AiProviderServiceImpl::GEO_TEXT_MODEL))
                ->withDeliveryContext($deliveryContext)->hold($execution, $brief->toArray());

            return;
        }

        // A legacy/test provider without transport evidence cannot prove a failed call was unsent.
        if (! $result->success && ! isset($result->gateway['status']) && empty($result->gateway['pre_send_rejection'])) {
            (new UnresolvedModelAttempt(array_replace_recursive(['attribution' => $originalAttribution], $result->gateway), $metadata, $result->model))
                ->withDeliveryContext($deliveryContext)->hold($execution, $brief->toArray());
            return;
        }
        if ($unknown = UnresolvedModelAttempt::fromFailure($result, $metadata)) {
            $unknown->withDeliveryContext($deliveryContext)->hold($execution, $brief->toArray());
            return;
        }
        PreparedModelAttempt::resolved($execution->id, $clientId, $result);
        if (! $result->success) {
            if (empty($result->gateway['pre_send_rejection'])) {
                $this->usageRecorder->record($execution->fresh(), $result, $metadata);
            }
            $safeError = OperationalTextRedactor::redact($result->errorMessage) ?: 'GEO analysis failed.';
            $this->updateRunningAnalysis($execution, [
                'status' => 'failed',
                'billing_pending_result' => null,
                'error_message' => $safeError,
                'duration_seconds' => (int) $result->durationSeconds,
                'token_usage' => $result->totalTokens,
            ]);

            return;
        }

        try {
            $modelCall = $this->usageRecorder->record($execution->fresh(), $result, $metadata);
        } catch (\Throwable $e) {
            (new UnrecordedModelResult($result, $metadata, $e))
                ->withDeliveryContext($deliveryContext)->hold($execution, $brief->toArray());

            return;
        }

        $completed = $this->legacyCompletedAttributes($result, $modelCall);

        $this->completeAnalysis($execution, $completed);
    }

    private function legacyCompletedAttributes(AiProviderCallResult $result, ModelCallLog $modelCall): array
    {
        $analysis = $this->normalizeAnalysis($result->structuredOutput ?? []);
        $modelUsage = $this->modelUsageFromResult($result, $modelCall);

        return [
            'status' => 'completed',
            'billing_pending_result' => null,
            'output_data' => [
                'analysis' => $analysis,
                'result_text' => $result->content,
                'model_call' => $modelUsage,
                'model_usage' => $modelUsage,
                'pipeline_mode' => 'legacy',
                'rendered_formats' => [],
            ],
            'quality_report' => [
                'passed' => true,
                'checks' => [
                    ['name' => 'summary', 'passed' => $analysis['summary'] !== ''],
                    ['name' => 'key_findings', 'passed' => count($analysis['key_findings']) > 0],
                    ['name' => 'recommendations', 'passed' => count($analysis['recommendations']) > 0],
                    ['name' => 'next_actions', 'passed' => count($analysis['next_actions']) > 0],
                ],
            ],
            'duration_seconds' => (int) $result->durationSeconds,
            'token_usage' => $result->totalTokens,
            'error_message' => null,
        ];
    }

    /** Stage an owner-checked original gateway result; the normal wallet gate releases it. */
    public function stageRecordedLegacyAnalysis(
        SkillExecution $execution, ModelCallLog $log, AiProviderCallResult $result,
    ): bool {
        $content = $result->content;
        if (! is_string($content)) {
            return false;
        }
        $structured = json_decode($content, true);
        if (! is_array($structured) && preg_match('/```(?:json|JSON)?\s*([\s\S]*?)\s*```/', $content, $matches)) {
            $structured = json_decode(trim($matches[1]), true);
        }
        if (! is_array($structured)) {
            return false;
        }
        $structuredResult = new AiProviderCallResult(...array_merge(get_object_vars($result), [
            'structuredOutput' => $structured,
        ]));

        return DB::transaction(function () use ($execution, $log, $structuredResult): bool {
            $current = SkillExecution::query()->whereKey($execution->id)->lockForUpdate()->first();
            $snapshot = $current?->billing_pending_result;
            if (! $current || $current->status !== ExecutionStatus::BillingPending->value
                || $current->skill_id !== Project::GEO_ANALYSIS_SKILL_ID
                || data_get($snapshot, 'kind') !== 'model_result_recorded'
                || data_get($snapshot, 'delivery_context.path') !== 'legacy_geo_analysis'
                || data_get($snapshot, 'model_call_log_id') !== $log->id
                || (int) $log->tenant_id !== (int) $current->tenant_id
                || (int) $log->project_id !== (int) $current->project_id
                || (int) $log->user_id !== (int) $current->user_id
                || (int) $log->skill_execution_id !== (int) $current->id
                || $log->status !== 'completed'
                || $log->gateway_request_id !== data_get($snapshot, 'gateway.request_id')
                || $log->model !== $structuredResult->model
                || $log->prompt_tokens !== $structuredResult->promptTokens
                || $log->completion_tokens !== $structuredResult->completionTokens
                || $log->total_tokens !== $structuredResult->totalTokens
                || $structuredResult->content !== data_get($snapshot, 'model_result.content')
                || $structuredResult->model !== data_get($snapshot, 'model_result.model')) {
                return false;
            }

            $current->update([
                'billing_pending_result' => [
                    'version' => 1, 'kind' => 'legacy_geo_analysis',
                    'attributes' => $this->legacyCompletedAttributes($structuredResult, $log),
                ],
                'error_message' => '原模型结果已恢复，正在完成交付。',
            ]);

            return true;
        });
    }

    /** Release an owner-checked saved report without another model call. */
    public function finalizePendingAnalysis(int $executionId): bool
    {
        $execution = SkillExecution::withoutGlobalScopes()->find($executionId);
        if (! $execution || $execution->skill_id !== Project::GEO_ANALYSIS_SKILL_ID
            || $execution->status !== ExecutionStatus::BillingPending->value) {
            return false;
        }
        $tenant = Tenant::find($execution->tenant_id);
        if (! $tenant) {
            return false;
        }

        $previousTenant = TenantContext::get();
        TenantContext::set($tenant);
        try {
            return DB::transaction(function () use ($execution): bool {
                $current = SkillExecution::query()->whereKey($execution->id)->lockForUpdate()->first();
                if (! $current || $current->status !== ExecutionStatus::BillingPending->value) {
                    return false;
                }
                $snapshot = $current->billing_pending_result;
                if (! is_array($snapshot) || ! in_array($snapshot['kind'] ?? null,
                    ['legacy_geo_analysis', 'full_geo_analysis', 'failed_geo_analysis'], true)
                    || ! is_array($snapshot['attributes'] ?? null)) {
                    return false;
                }
                $logs = $this->textDeliveryLogs($current);
                if ($logs->isEmpty()) {
                    return false;
                }

                $attributes = $snapshot['attributes'];
                if (($attributes['status'] ?? null) === ExecutionStatus::Failed->value) {
                    $current->update([
                        'status' => ExecutionStatus::Failed->value,
                        'output_data' => [],
                        'quality_report' => $attributes['quality_report'] ?? null,
                        'duration_seconds' => $attributes['duration_seconds'] ?? 0,
                        'token_usage' => $attributes['token_usage'] ?? 0,
                        'error_message' => $attributes['error_message'] ?? 'GEO analysis failed.',
                    ]);

                    return true;
                }
                $usage = $this->aggregateModelUsage($logs);
                $attributes['output_data']['model_call'] = $usage;
                $attributes['output_data']['model_usage'] = $usage;
                $current->fill($attributes);
                $current->billing_pending_result = null;
                $current->save();
                $this->attachReportAsset($current->fresh());

                return true;
            });
        } finally {
            $previousTenant ? TenantContext::set($previousTenant) : TenantContext::forget();
        }
    }

    private function storeFullPipelineAnalysis(
        Project $project,
        SkillExecution $execution,
        array $input,
        int $userId,
    ): void {
        $brief = new SkillBrief(
            skillId: Project::GEO_ANALYSIS_SKILL_ID,
            projectId: $project->id,
            userId: $userId,
            fields: $this->buildBriefFields($project, $input),
            query: $this->buildRagQuery($input),
            contextFromPreviousStage: [],
            executionId: $execution->id,
        );

        $result = $this->runner->execute($brief);

        // 取消保护：用户已取消（runner 段间检查命中后不再发起后续段），此处不得把状态
        // 覆盖成 failed/completed——保持 cancelled 由 cancel 接口写入。已完成段的账已由
        // runner 实时记好（每段一条 ModelCallLog + 扣费），这里只需直接返回。
        if ($execution->fresh()->status === ExecutionStatus::Cancelled->value) {
            return;
        }

        if (! $result->success) {
            $safeError = OperationalTextRedactor::redact($result->errorMessage) ?: 'GEO analysis failed.';
            $update = [
                'status' => 'failed',
                'error_message' => $safeError,
                'duration_seconds' => (int) $result->durationSeconds,
                'token_usage' => $result->tokenUsage,
            ];

            // 失败也把模型的原始回复留下来（见 failureDiagnostics 的说明）：
            // 以前这一支只写状态和错误，runner 带回来的半成品直接扔掉，事后想查
            // 「模型到底回了什么、哪一段没成」只能翻容器日志，而日志会轮转。
            $diagnostics = $this->failureDiagnostics($result);
            if ($diagnostics !== []) {
                $update['output_data'] = array_merge($execution->fresh()->output_data ?? [], $diagnostics);
            }

            $this->updateRunningAnalysis($execution, $update);

            return;
        }

        $outputData = $this->normalizeOutputPaths($result->outputPaths);
        $intermediateData = $result->intermediateData ?? [];
        $structured = $intermediateData['structured'] ?? null;
        $renderedFormats = $this->normalizeFormats(
            $intermediateData['rendered_formats'] ?? array_keys($outputData),
        );
        $pipelineMode = $intermediateData['pipeline_mode'] ?? 'unknown';

        // C 质量闸：full 管线却拿不到结构化数据（如单次输出被 max_tokens 截断 → JSON 无法解析）
        // → 绝不当作“已通过”交付。落 failed（计费仍由 runner 记录，此处只聚合读取），前端可重试。
        if ($pipelineMode === 'full' && ($structured === null || $structured === [])) {
            $modelUsage = $this->recordModelUsage($execution, $result);
            $failed = [
                'status' => 'failed',
                'error_message' => '报告结构化解析失败（可能因单次输出截断）。请重试；如反复出现请开启分段生成。',
                'output_data' => array_merge($outputData, [
                    'analysis' => $this->summaryExtractor->extract(null, $result),
                    'structured' => null,
                    'pipeline_mode' => $pipelineMode,
                    'rendered_formats' => $renderedFormats,
                    'model_call' => $modelUsage,
                    'model_usage' => $modelUsage,
                ], $this->failureDiagnostics($result)),
                'quality_report' => [
                    'passed' => false,
                    'checks' => [
                        ['name' => 'structured_present', 'passed' => false, 'note' => 'structured=NULL（解析失败/截断）'],
                    ],
                ],
                'duration_seconds' => (int) $result->durationSeconds,
                'token_usage' => $result->tokenUsage,
            ];
            $this->updateRunningAnalysis($execution, $failed);

            return;
        }

        $analysis = $this->summaryExtractor->extract($structured, $result);
        $modelUsage = $this->recordModelUsage($execution, $result);

        $completed = [
            'status' => 'completed',
            'output_data' => array_merge($outputData, [
                'analysis' => $analysis,
                'structured' => $structured,
                'pipeline_mode' => $intermediateData['pipeline_mode'] ?? 'unknown',
                'rendered_formats' => $renderedFormats,
                'model_call' => $modelUsage,
                'model_usage' => $modelUsage,
            ]),
            'quality_report' => $result->qualityReport ?? ['passed' => true, 'checks' => []],
            'duration_seconds' => (int) $result->durationSeconds,
            'token_usage' => $result->tokenUsage,
            'error_message' => null,
        ];
        $this->completeAnalysis($execution, $completed);

    }

    /** 模型和记账已结束；只把报告资产及执行关联纳入同一个短事务。 */
    private function completeAnalysis(SkillExecution $execution, array $attributes): void
    {
        try {
            DB::transaction(function () use ($execution, $attributes): void {
                if ($this->updateRunningAnalysis($execution, $attributes)) {
                    $this->attachReportAsset($execution->fresh());
                }
            });
        } catch (\Throwable $exception) {
            // 报告/关联写入回滚后仍保留已生成的正文、文件路径和用量，不能留下假 completed。
            // 用户若在回滚与此写入之间取消，running 条件保证不覆盖 cancelled。
            $this->updateRunningAnalysis($execution, array_replace($attributes, [
                'status' => ExecutionStatus::Failed->value,
                'error_message' => OperationalTextRedactor::redact($exception->getMessage()) ?: '报告资产保存失败。',
            ]));

            throw $exception;
        }
    }

    /**
     * 终态通过条件写入认领，避免模型返回后的取消被旧 execution 实例覆盖。
     * 先使用模型 casts 编码 JSON；批量条件更新不会替我们执行属性转换。
     */
    private function updateRunningAnalysis(SkillExecution $execution, array $attributes): bool
    {
        $values = (new SkillExecution())->forceFill($attributes)->getAttributes();

        return SkillExecution::query()
            ->whereKey($execution->id)
            ->where('tenant_id', $execution->tenant_id)
            ->where('status', ExecutionStatus::Running->value)
            ->update($values) === 1;
    }

    /**
     * 分析失败时留在执行记录里的排查线索。
     *
     * 客户看不到这份东西：前端对 failed / cancelled 有单独一支（geo-entry.js），
     * 只讲状态和这次花了多少钱，不读 output_data。
     *
     * 键名单开一个 failure_diagnostics 是刻意的——报告正文、下载、可用格式那几处
     * 只认 analysis / markdown / html / model_usage 这些键，多这一个键不会让一次
     * 失败的分析冒出「有报告可下载」的假象。
     *
     * @return array<string, mixed>  没有任何线索时返回空数组（此时不动 output_data）
     */
    private function failureDiagnostics(SkillExecutionResult $result): array
    {
        $intermediate = $result->intermediateData ?? [];
        $diagnostics = [];

        $rawOutput = $intermediate['raw_output'] ?? null;
        if (is_string($rawOutput) && trim($rawOutput) !== '') {
            $length = mb_strlen($rawOutput);
            $diagnostics['raw_output'] = $length > self::FAILURE_RAW_OUTPUT_CHARS
                ? mb_substr($rawOutput, 0, self::FAILURE_RAW_OUTPUT_CHARS)."\n…（原始输出共 {$length} 字，此处只留开头）"
                : $rawOutput;
            $diagnostics['raw_output_chars'] = $length;
        }

        // 分段执行的成败明细：哪几段没成、每段认领了几个字段、各花了多少 token
        foreach (['pipeline_mode', 'chunked', 'chunk_count', 'chunks_parsed', 'chunk_meta', 'failed_chunks'] as $key) {
            if (array_key_exists($key, $intermediate)) {
                $diagnostics[$key] = $intermediate[$key];
            }
        }

        return $diagnostics === [] ? [] : ['failure_diagnostics' => $diagnostics];
    }

    private function attachReportAsset(SkillExecution $execution): void
    {
        $execution->load('modelCallLogs');
        $article = $this->reportAssets->createForGeoAnalysis(
            $execution,
            $this->analysisMarkdown($execution),
            $this->reportPayload($execution),
            $this->modelUsagePayload($execution),
        );
        $execution->update([
            'article_id' => $article->id,
            'output_data' => $this->reportAssets->outputDataWithArticle($execution->output_data ?? [], $article),
        ]);
    }

    private function ensureCurrentTenantProject(Project $project): void
    {
        if ((int) $project->tenant_id !== (int) TenantContext::id()) {
            abort(404);
        }
    }

    private function ensureProjectAnalysis(Project $project, SkillExecution $analysis): void
    {
        if ($analysis->project_id !== $project->id || $analysis->skill_id !== Project::GEO_ANALYSIS_SKILL_ID) {
            abort(404);
        }

        if ((int) $analysis->tenant_id !== (int) TenantContext::id()) {
            abort(404);
        }
    }

    private function analysisInput(Project $project, array $validated): array
    {
        $settings = $project->settings ?? [];
        $extraFields = $validated['fields'] ?? [];

        return array_merge([
            'project_id' => $project->id,
            'project_name' => $project->name,
            'brand_name' => $project->brand_name,
            'brand_url' => $project->brand_url,
            'product_description' => $settings['product_description'] ?? null,
            'target_market' => $settings['target_market'] ?? null,
            'target_keywords' => $settings['target_keywords'] ?? [],
            'competitors' => $settings['competitors'] ?? [],
            'goals' => $settings['goals'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ], is_array($extraFields) ? $extraFields : []);
    }

    private function buildBriefFields(Project $project, array $input): array
    {
        $settings = $project->settings ?? [];

        return [
            'project_name' => $input['project_name'] ?? $project->name,
            'brand_name' => $input['brand_name'] ?? $project->brand_name,
            'brand_url' => ($input['brand_url'] ?? $project->brand_url) ?: '(未提供)',
            'product_description' => $input['product_description'] ?? $settings['product_description'] ?? '',
            'target_market' => $input['target_market'] ?? $settings['target_market'] ?? '',
            'target_keywords' => $input['target_keywords'] ?? $settings['target_keywords'] ?? [],
            'competitors' => $input['competitors'] ?? $settings['competitors'] ?? [],
            'goals' => $input['goals'] ?? $settings['goals'] ?? '',
            'notes' => $input['notes'] ?? '',
            'category' => $input['category'] ?? $settings['category'] ?? '',
            'language' => $input['language'] ?? $settings['language'] ?? 'zh-Hans',
            'primary_goal' => $input['goals'] ?? $settings['goals'] ?? 'GEO 全景诊断',
        ];
    }

    private function buildRagQuery(array $input): ?string
    {
        $parts = array_filter([
            $input['brand_name'] ?? null,
            $input['product_description'] ?? null,
            $input['target_market'] ?? null,
            is_array($input['target_keywords'] ?? null)
                ? implode(' ', $input['target_keywords'])
                : null,
        ]);

        $query = trim(implode(' ', $parts));

        return $query !== '' ? $query : null;
    }

    private function analysisPrompt(array $input): string
    {
        $projectJson = json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
Create a practical GEO SaaS MVP analysis for this project.

Project profile:
{$projectJson}

Requirements:
- Focus on AI-search visibility, citation readiness, content gaps, and next execution steps.
- Return concise Chinese-language business guidance.
- The JSON must contain: summary, key_findings, recommendations, next_actions.
- Include overall_score as a 0-100 integer if you can infer a practical readiness score.
- Each list should contain 3 to 6 useful items.
PROMPT;
    }

    private function analysisSchema(): string
    {
        return <<<'JSON'
{
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "summary": { "type": "string" },
    "key_findings": {
      "type": "array",
      "items": { "type": "string" }
    },
    "recommendations": {
      "type": "array",
      "items": { "type": "string" }
    },
    "next_actions": {
      "type": "array",
      "items": { "type": "string" }
    },
    "overall_score": { "type": ["integer", "number", "null"] }
  },
  "required": ["summary", "key_findings", "recommendations", "next_actions"]
}
JSON;
    }

    private function normalizeAnalysis(array $output): array
    {
        return [
            'summary' => $this->stringItem($output['summary'] ?? $output['analysis'] ?? ''),
            'key_findings' => $this->stringList($output['key_findings'] ?? $output['findings'] ?? []),
            'recommendations' => $this->stringList($output['recommendations'] ?? $output['suggestions'] ?? []),
            'next_actions' => $this->stringList($output['next_actions'] ?? $output['next_steps'] ?? []),
            'overall_score' => $this->nullableScore($output['overall_score'] ?? $output['score'] ?? null),
        ];
    }

    private function nullableScore(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0, min(100, (int) round((float) $value)));
    }

    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $this->stringList($decoded);
            }

            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $items = array_is_list($value) ? $value : [$value];

        return array_values(array_filter(array_map(
            fn ($item) => $this->stringItem($item),
            $items,
        ), fn ($item) => $item !== ''));
    }

    private function stringItem(mixed $item): string
    {
        if (is_string($item)) {
            $decoded = json_decode($item, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $this->stringItem($decoded);
            }

            return trim($item);
        }

        if (is_scalar($item)) {
            return trim((string) $item);
        }

        if (! is_array($item)) {
            return '';
        }

        if (array_is_list($item)) {
            return implode('；', array_filter(array_map(
                fn ($value) => $this->stringItem($value),
                $item,
            ), fn ($value) => $value !== ''));
        }

        return $this->structuredItemText($item);
    }

    private function structuredItemText(array $item): string
    {
        $headlineKeys = ['finding', 'recommendation', 'action', 'task', 'title', 'summary', 'phase', 'name'];
        $headlineKey = null;
        $parts = [];

        foreach ($headlineKeys as $key) {
            if (! array_key_exists($key, $item)) {
                continue;
            }

            $headline = $this->stringItem($item[$key]);
            if ($headline !== '') {
                $headlineKey = $key;
                $parts[] = $headline;
                break;
            }
        }

        foreach ($item as $key => $value) {
            if ($key === $headlineKey) {
                continue;
            }

            $text = $this->stringItem($value);
            if ($text === '') {
                continue;
            }

            $parts[] = $this->fieldLabel((string) $key).'：'.$text;
        }

        return implode('；', $parts);
    }

    private function fieldLabel(string $key): string
    {
        return [
            'detail' => '说明',
            'details' => '说明',
            'reason' => '原因',
            'expected_impact' => '预期影响',
            'impact' => '影响',
            'priority' => '优先级',
            'tasks' => '任务',
            'keywords' => '关键词',
            'source' => '来源',
            'evidence' => '依据',
        ][$key] ?? str_replace('_', ' ', $key);
    }

    private function analysisPayload(SkillExecution $execution): array
    {
        $execution->loadMissing('modelCallLogs');
        $report = $this->reportPayload($execution);
        $outputData = $execution->output_data ?? [];
        $availableFormats = $this->normalizeFormats(array_keys(array_intersect_key(
            $outputData,
            array_flip(self::DOWNLOAD_FORMATS),
        )));

        return [
            'id' => $execution->id,
            'project_id' => $execution->project_id,
            'status' => $execution->status,
            'input_data' => $execution->input_data,
            'output_data' => OperationalTextRedactor::redactOperationalFields($outputData),
            'report' => $report,
            'model_usage' => $report['model_usage'],
            'download_url' => $execution->status === ExecutionStatus::Completed->value
                ? "/api/v1/projects/{$execution->project_id}/analyses/{$execution->id}/download" : null,
            'available_formats' => $availableFormats,
            'rendered_formats' => $this->normalizeFormats($outputData['rendered_formats'] ?? $availableFormats),
            'pipeline_mode' => $outputData['pipeline_mode'] ?? 'unknown',
            'report_markdown' => $execution->status === ExecutionStatus::Completed->value
                ? $this->analysisMarkdown($execution) : '',
            'article_id' => $execution->article_id ?? data_get($outputData, 'article_id'),
            'article_url' => data_get($outputData, 'article_url'),
            'article_download_url' => data_get($outputData, 'article_download_url'),
            'token_usage' => $execution->token_usage,
            'duration_seconds' => $execution->duration_seconds,
            'error_message' => OperationalTextRedactor::redact($execution->error_message),
            'model_call_logs' => $execution->modelCallLogs
                ->map(fn ($log) => [
                    'id' => $log->id,
                    // 只回对客品牌名：provider 是配置命名空间、model 是内部档位 slug
                    'model_tier' => ModelTierLabel::for($log->model),
                    'status' => $log->status,
                    'total_tokens' => $log->total_tokens,
                    'created_at' => $log->created_at?->toISOString(),
                ])
                ->values(),
            'created_at' => $execution->created_at?->toISOString(),
            'updated_at' => $execution->updated_at?->toISOString(),
        ];
    }

    private function reportPayload(SkillExecution $execution): array
    {
        $analysis = $execution->output_data['analysis'] ?? [];
        if (! is_array($analysis)) {
            $analysis = [];
        }

        return [
            'summary' => $this->stringItem($analysis['summary'] ?? ''),
            'key_findings' => $this->stringList($analysis['key_findings'] ?? []),
            'recommendations' => $this->stringList($analysis['recommendations'] ?? []),
            'next_actions' => $this->stringList($analysis['next_actions'] ?? []),
            'overall_score' => $this->nullableScore($analysis['overall_score'] ?? null),
            'model_usage' => $this->modelUsagePayload($execution),
        ];
    }

    private function modelUsagePayload(SkillExecution $execution): array
    {
        $outputUsage = $execution->output_data['model_usage']
            ?? $execution->output_data['model_call']
            ?? [];
        $log = $execution->modelCallLogs->first();

        return [
            'model_tier' => ModelTierLabel::for($outputUsage['model'] ?? $log?->model),
            'prompt_tokens' => (int) ($outputUsage['prompt_tokens'] ?? $log?->prompt_tokens ?? 0),
            'completion_tokens' => (int) ($outputUsage['completion_tokens'] ?? $log?->completion_tokens ?? 0),
            'total_tokens' => (int) ($outputUsage['total_tokens'] ?? $execution->token_usage ?? $log?->total_tokens ?? 0),
            'duration_seconds' => (float) ($outputUsage['duration_seconds'] ?? $execution->duration_seconds ?? $log?->duration_seconds ?? 0),
        ];
    }

    /** Embedding rows keep their own cost record; only text calls prove report billing. */
    private function textDeliveryLogs(SkillExecution $execution): \Illuminate\Support\Collection
    {
        return ModelCallLog::query()->where('skill_execution_id', $execution->id)
            ->orderBy('id')->get()
            ->filter(fn (ModelCallLog $log): bool => $log->model !== 'embedding'
                && data_get($log->metadata, 'usage_kind') !== 'embedding')
            ->values();
    }

    private function recordModelUsage(SkillExecution $execution, SkillExecutionResult $result): array
    {
        // 扣费收口：runner 是唯一记账人（每次 LLM 调用记一条 ModelCallLog 并扣费，
        // 分段生成时为 N 条）。控制器不再重复 record，只聚合读取 runner 已持久化的日志，
        // 避免同一次分析被扣两次（见 2026-07-28 全景分析双重扣费复盘）。
        $logs = $this->textDeliveryLogs($execution);

        if ($logs->isNotEmpty()) {
            return $this->aggregateModelUsage($logs);
        }

        // 兜底：runner 未记账（executionId 缺失等非常规路径）→ 此处补记一次。
        if ($result->lastLlmResult) {
            $modelCall = $this->usageRecorder->record($execution->fresh(), $result->lastLlmResult, [
                'source' => 'geo_panorama_analysis',
            ]);

            return $this->modelUsageFromResult($result->lastLlmResult, $modelCall);
        }

        return [
            'model_tier' => ModelTierLabel::for(null),
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => $result->tokenUsage,
            'duration_seconds' => $result->durationSeconds,
        ];
    }

    private function modelUsageFromResult(AiProviderCallResult $result, ModelCallLog $modelCall): array
    {

        return [
            'id' => $modelCall->id,
            'model_tier' => ModelTierLabel::for($result->model),
            'prompt_tokens' => $result->promptTokens,
            'completion_tokens' => $result->completionTokens,
            'total_tokens' => $result->totalTokens,
            'duration_seconds' => $result->durationSeconds,
        ];
    }

    /**
     * 聚合 runner 已记录的一条或多条 ModelCallLog（分段生成为多条），得到本次分析的模型消耗汇总。
     * token / 扣点求和；未决优先暴露，全部确认时区分实扣与全额豁免。
     */
    private function aggregateModelUsage(\Illuminate\Support\Collection $logs): array
    {
        $primary = $logs->last();
        return [
            'id' => $primary->id,
            'model_tier' => ModelTierLabel::for($primary->model),
            'prompt_tokens' => (int) $logs->sum('prompt_tokens'),
            'completion_tokens' => (int) $logs->sum('completion_tokens'),
            'total_tokens' => (int) $logs->sum('total_tokens'),
            'duration_seconds' => (float) $logs->sum('duration_seconds'),
            'call_count' => $logs->count(),
        ];
    }

    private function normalizeFormats(array $formats): array
    {
        $normalized = array_map(
            fn (string $format): string => $format === 'md' ? 'markdown' : $format,
            array_filter($formats, fn (mixed $format): bool => is_string($format)),
        );

        return array_values(array_intersect(
            self::DOWNLOAD_FORMATS,
            array_values(array_unique($normalized)),
        ));
    }

    private function normalizeOutputPaths(array $paths): array
    {
        if (isset($paths['md']) && ! isset($paths['markdown'])) {
            $paths['markdown'] = $paths['md'];
            unset($paths['md']);
        }

        return array_intersect_key($paths, array_flip(self::DOWNLOAD_FORMATS));
    }

    private function analysisMarkdown(SkillExecution $execution): string
    {
        $outputData = $execution->output_data ?? [];
        if (isset($outputData['markdown']) && is_string($outputData['markdown'])
            && ReportStorage::belongsToExecution((int) $execution->id, $outputData['markdown'])) {
            try {
                $stored = ReportStorage::readDisk($outputData['markdown'])->get($outputData['markdown']);
                if (is_string($stored) && $stored !== '') {
                    return $stored;
                }
            } catch (\Throwable) {
                // Fall back to generated markdown below.
            }
        }

        $report = $this->reportPayload($execution);
        $usage = $report['model_usage'];
        $lines = [
            "# GEO Analysis Report #{$execution->id}",
            '',
            '## 综合结论',
            $report['summary'] !== '' ? $report['summary'] : '暂无综合结论。',
            '',
        ];

        if ($report['overall_score'] !== null) {
            $lines[] = '## 综合评分';
            $lines[] = (string) $report['overall_score'].' / 100';
            $lines[] = '';
        }

        $lines = array_merge(
            $lines,
            $this->markdownSection('关键发现', $report['key_findings']),
            $this->markdownSection('优化建议', $report['recommendations']),
            $this->markdownSection('下一步行动', $report['next_actions']),
            [
                '## 模型消耗',
                "- 模型档位: {$usage['model_tier']}",
                "- Prompt tokens: {$usage['prompt_tokens']}",
                "- Completion tokens: {$usage['completion_tokens']}",
                "- Total tokens: {$usage['total_tokens']}",
                "- Duration seconds: {$usage['duration_seconds']}",
                '',
            ],
        );

        return implode("\n", $lines);
    }

    private function markdownSection(string $title, array $items): array
    {
        $lines = ["## {$title}"];
        if ($items === []) {
            $lines[] = '暂无。';
        } else {
            foreach ($items as $item) {
                $lines[] = '- '.$item;
            }
        }

        $lines[] = '';

        return $lines;
    }
}
