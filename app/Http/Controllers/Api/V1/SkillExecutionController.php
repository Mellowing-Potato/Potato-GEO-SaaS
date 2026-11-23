<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SkillExecution;
use App\Models\SkillExecutionRelay;
use App\Models\Project;
use App\Models\TitleLibrary;
use App\Models\KeywordLibrary;
use App\Models\Author;
use App\Services\Api\IdempotencyService;
use App\Services\Billing\AssistUsageRecorder;
use App\Services\Billing\KnownChunkIdentityConflictRecovery;
use App\Services\Billing\UnresolvedModelAttempt;
use App\Services\Billing\UnresolvedEmbeddingAttempt;
use App\Services\Billing\UnrecordedModelResult;
use App\Services\Content\MaterialLibraryService;
use App\Services\Contracts\AiProvider;
use App\Services\Contracts\SkillAgentRunner;
use App\Services\Contracts\SkillRegistry;
use App\Services\DTOs\AiProviderCallResult;
use App\Services\DTOs\SkillBrief;
use App\Services\Enums\SkillCategory;
use App\Services\Enums\ExecutionStatus;
use App\Services\Outbound\OutboundRequestBlockedException;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Services\Outbound\SecureHttpFactory;
use App\Services\Reports\ReportAssetService;
use App\Services\Workflow\ProgressBroadcastService;
use App\Services\Reports\ReportStorage;
use App\Services\SkillAgent\SkillRenderRecovery;
use App\Support\OperationalTextRedactor;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SkillExecutionController extends Controller
{
    public function __construct(
        private SkillAgentRunner $runner,
        private SkillRegistry $registry,
        private AiProvider $aiProvider,
        private ReportAssetService $reportAssets,
        private ProgressBroadcastService $broadcaster,
        private MaterialLibraryService $materials,
        private AssistUsageRecorder $assistRecorder,
        private SafeOutboundHttpClient $outbound,
        private SecureHttpFactory $secureHttp,
    ) {}

    /**
     * GET /api/v1/skills
     * List all available skills, optionally filtered by category or stage.
     */
    public function skills(Request $request): JsonResponse
    {
        $category = $request->query('category');
        $stage = $request->query('stage');

        if ($stage !== null) {
            $skills = $this->registry->findByStage((int) $stage);
        } elseif ($category !== null) {
            $categoryEnum = SkillCategory::tryFrom($category);
            if (! $categoryEnum) {
                return response()->json(['message' => "Invalid category: {$category}"], 422);
            }
            $skills = $this->registry->findByCategory($categoryEnum);
        } else {
            $skills = $this->registry->all();
        }

        // Convert DTOs to arrays for JSON response
        $data = array_map(fn($s) => [
            'id' => $s->id,
            'name' => $s->name,
            'category' => $s->category->value,
            'category_label' => $s->category->label(),
            'maturity' => $s->maturity,
            'requires_web' => $s->requiresWeb,
            'stage_index' => $s->stageIndex,
            'description' => $s->description,
            'scene_description' => $s->sceneDescription,
            'effect_description' => $s->effectDescription,
            'brief_fields' => $s->briefFields,
            // 独立业务能力只从这里返回站内入口；前端据此跳转，不再伪装成通用模型表单。
            'action_path' => $s->actionPath,
            'action_label' => $s->actionLabel,
            // 前端据此置灰并显示原因。不在这里直接过滤掉：客户该知道有这个能力、
            // 只是暂时不能用，凭空少几个技能反而像产品缩水。
            'available' => $s->isAvailable(),
            'unavailable_reason' => $s->unavailableReason,
        ], $skills);

        return response()->json(['data' => $data]);
    }

    /**
     * POST /api/v1/skills/execute
     * Execute a skill synchronously.
     * Required: skill_id, project_id
     * Optional: fields (array), query (string)
     */
    public function execute(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'skill_id' => 'required|string',
            'project_id' => 'required|integer',
            'fields' => 'nullable|array',
            'query' => 'nullable|string|max:2000',
            'material_refs' => 'nullable|array',
            'material_refs.*.type' => 'required_with:material_refs|string|in:title,keyword,author',
            'material_refs.*.id' => 'required_with:material_refs|integer|min:1',
        ]);

        $project = $this->projectForCurrentTenant((int) $validated['project_id']);

        // Verify skill exists
        $skill = $this->registry->findOrNull($validated['skill_id']);
        if (!$skill) {
            return response()->json(['message' => 'Skill not found: ' . $validated['skill_id']], 404);
        }
        if (! $skill->isAvailable()) {
            // 前端已按 available 置灰，这里是第二道 —— 直接打接口一样要挡住，
            // 否则停用等于没停用。executeAsync() 里有同样一道。
            return response()->json(['message' => $skill->unavailableReason], 422);
        }
        if ($skill->actionPath !== null) {
            return response()->json([
                'message' => '该能力需要从专用页面发起。',
                'action' => 'open_skill_action',
                'action_path' => $skill->actionPath,
            ], 422);
        }

        $fields = $this->fieldsWithProjectContext($project, $validated['fields'] ?? []);
        $contextFromPreviousStage = $this->resolvePreviousStageContext(
            $validated['skill_id'],
            $project->id,
        );

        $query = $validated['query'] ?? '';

        // 子链 A：素材库注入。显式选择优先；仅当无显式选择且 query/topic 都为空时，才随机兜底。
        // $materialRefs 供执行成功后 recordUsage（同步内联 / 异步由 Job 记录）。
        [$fields, $materialRefs] = $this->enrichBriefFromMaterials(
            $fields,
            $query,
            $validated['material_refs'] ?? [],
        );

        $submittedBrief = new SkillBrief(
            skillId: $validated['skill_id'],
            projectId: $project->id,
            userId: $request->user()->id,
            fields: $fields,
            query: $query,
            contextFromPreviousStage: $contextFromPreviousStage,
            materialRefs: $materialRefs,
        );

        // Create execution record first so its id can bind the brief（计费收口：
        // runner 仅在 brief 带 executionId 时记 ModelCallLog 并显式扣点。网关自
        // 2026-07-29 起对 geo_saas completion 零扣款——同步路径若不带 executionId
        // 就会两头都不扣、变成免费。必须先建 execution 再带 id 进 brief，使同步
        // 与异步一致地走「唯一显式扣点」。见 GEO 双扣费根治 + 同步执行漏扣复盘。
        $execution = SkillExecution::create([
            'project_id' => $project->id,
            'user_id' => $request->user()->id,
            'skill_id' => $skill->id,
            'skill_name' => $skill->name,
            'category' => $skill->category->value,
            'stage_index' => $skill->stageIndex,
            'status' => 'running',
            'execution_mode' => 'sync',
            'input_data' => $submittedBrief->auditableInputData(),
            'model_connection_snapshot' => app(\App\Services\AI\ModelConnectionResolver::class)->snapshot($submittedBrief->modelSlot()),
        ]);

        $brief = new SkillBrief(
            skillId: $validated['skill_id'],
            projectId: $project->id,
            userId: $request->user()->id,
            fields: $fields,
            query: $query,
            contextFromPreviousStage: $contextFromPreviousStage,
            executionId: $execution->id,
            materialRefs: $materialRefs,
        );

        try {
            $result = $this->runner->execute($brief);

            // Merge raw AI output and rendered file paths into output_data
            $outputData = $this->normalizeRenderedOutputPaths($result->outputPaths);
            $rawOutput = $result->intermediateData['raw_output'] ?? null;
            if ($rawOutput) {
                $outputData['result_text'] = $rawOutput;
            }
            if (isset($result->intermediateData['structured'])) {
                $outputData['structured'] = $result->intermediateData['structured'];
            }
            // Track render pipeline metadata (safe access when intermediateData is null on failure)
            $mode = $result->intermediateData['pipeline_mode'] ?? null;
            if ($mode) {
                $outputData['pipeline_mode'] = $mode;
            }
            if (isset($result->intermediateData['rendered_formats'])) {
                $outputData['rendered_formats'] = $this->normalizeRenderedFormats($result->intermediateData['rendered_formats']);
            }

            $article = null;
            if ($result->success) {
                // 子链 A 闭环：执行成功才计数（失败不记），素材库「使用 N 次」变真实。
                if (! empty($materialRefs)) {
                    $this->materials->recordUsage($materialRefs);
                }

                $candidateArticleId = $result->intermediateData['article_id'] ?? $outputData['article_id'] ?? null;
                $article = $this->reportAssets->createOrLinkForSkillExecution(
                    $execution,
                    is_string($result->intermediateData['billing_pending_article_content'] ?? null)
                        ? $result->intermediateData['billing_pending_article_content']
                        : $this->resultText($outputData),
                    is_numeric($candidateArticleId) ? (int) $candidateArticleId : null,
                    is_array($outputData['structured'] ?? null) ? $outputData['structured'] : null,
                    $result->qualityReport,
                );
                $outputData = $this->reportAssets->outputDataWithArticle($outputData, $article);
            }

            $execution->update([
                'status' => $result->success ? 'completed' : 'failed',
                'output_data' => $outputData,
                'quality_report' => $result->qualityReport,
                'article_id' => $article?->id,
                'duration_seconds' => (int) $result->durationSeconds,
                'token_usage' => $result->tokenUsage,
                'error_message' => $result->success ? null : $this->friendlySkillError($result->errorMessage),
                'billing_pending_result' => null,
            ]);

            $execution = $execution->fresh();

            return response()->json([
                'execution' => $this->executionPayload($execution),
                'message' => $result->success ? 'Skill execution completed.' : $this->friendlySkillError($result->errorMessage),
                'action' => $result->success ? null : 'skill_execution_failed',
                'result' => [
                    'success' => $result->success,
                    'outputs' => $outputData,
                    'quality' => $result->qualityReport,
                    'duration' => $result->durationSeconds,
                    'tokens' => $result->tokenUsage,
                    'error' => $result->success ? null : $this->friendlySkillError($result->errorMessage),
                ],
            ], $result->success ? 200 : 502);
        } catch (UnresolvedEmbeddingAttempt $e) {
            $e->hold($execution, $brief->toArray());

            return response()->json([
                'execution' => $this->executionPayload($execution->fresh()),
                'message' => $e->getMessage(), 'action' => 'billing_pending',
            ], 202);
        } catch (UnresolvedModelAttempt $e) {
            $e->hold($execution, $brief->toArray());

            return response()->json([
                'execution' => $this->executionPayload($execution->fresh()),
                'message' => $e->getMessage(),
                'action' => 'billing_pending',
            ], 202);
        } catch (UnrecordedModelResult $e) {
            $e->hold($execution, $brief->toArray());

            return response()->json([
                'execution' => $this->executionPayload($execution->fresh()),
                'message' => $e->getMessage(),
                'action' => 'billing_pending',
            ], 202);
        } catch (\Throwable $e) {
            $prepared = $execution->fresh()?->billing_pending_result;
            if (in_array(data_get($prepared, 'kind'),
                ['embedding_attempt_prepared', 'embedding_result_recorded'], true)) {
                (new UnresolvedEmbeddingAttempt($prepared['gateway']))->hold($execution, $brief->toArray());

                return response()->json([
                    'execution' => $this->executionPayload($execution->fresh()),
                    'message' => '原向量请求待核对，执行已暂缓；请勿重新提交',
                    'action' => 'billing_pending',
                ], 202);
            }
            if (data_get($prepared, 'kind') === 'model_attempt_prepared') {
                (new UnresolvedModelAttempt(
                    $prepared['gateway'], $prepared['record_metadata'], $prepared['requested_model'],
                ))->hold($execution, $brief->toArray());

                return response()->json([
                    'execution' => $this->executionPayload($execution->fresh()),
                    'message' => '原模型请求待核对，执行已暂缓；请勿重新提交',
                    'action' => 'billing_pending',
                ], 202);
            }
            $execution->update([
                'status' => 'failed',
                'error_message' => $this->friendlySkillError($e->getMessage()),
            ]);

            return response()->json([
                'execution' => $this->executionPayload($execution->fresh()),
                'message' => 'Skill execution failed. Please try again or contact support.',
                'action' => 'skill_execution_failed',
            ], 500);
        }
    }

    /**
     * GET /api/v1/executions
     * List execution history, optionally filtered by project or skill.
     */
    public function history(Request $request): JsonResponse
    {
        $query = SkillExecution::with([
            'project:id,name',
            'user:id,name',
            'article:id,title,source_type,status',
            // executionPayload 要汇总每次执行的客户扣点。历史列表一次预载，避免
            // 每页 20 条执行再各查一次 model_call_logs。
            'modelCallLogs:id,skill_execution_id,status,metadata,total_tokens',
        ])
            // AI 辅助建议（推荐关键词/品名）以 assist 模式建轻量 execution 仅为扣费收口，
            // 不是用户发起的技能执行，从执行历史列表隐藏，避免污染「项目技能执行记录」。
            ->where('execution_mode', '!=', 'assist')
            ->orderByDesc('created_at');

        if ($projectId = $request->query('project_id')) {
            $project = $this->projectForCurrentTenant((int) $projectId);
            $query->forProject($project->id);
        }

        if ($skillId = $request->query('skill_id')) {
            $query->ofSkill($skillId);
        }

        if ($status = $request->query('status')) {
            $query->ofStatus($status);
        }

        $perPage = min((int) $request->query('per_page', 20), 100);

        $page = $query->paginate($perPage);
        $page->getCollection()->transform(fn (SkillExecution $execution) => $this->executionPayload($execution));

        return response()->json($page);
    }

    /**
     * GET /api/v1/executions/{execution}
     * Show a single execution with full input/output data.
     */
    public function show(SkillExecution $execution): JsonResponse
    {
        $this->ensureCurrentTenantExecution($execution);
        $execution->load(['project:id,name', 'user:id,name']);

        return response()->json($this->executionPayload($execution));
    }

    /**
     * GET /api/v1/executions/{execution}/download/{format}
     * Download a rendered output file (markdown, html, docx, pdf).
     *
     * @param  string $format  One of: markdown, html, docx, pdf
     */
    public function download(SkillExecution $execution, string $format): StreamedResponse|JsonResponse
    {
        $this->ensureCurrentTenantExecution($execution);
        if ($execution->status === ExecutionStatus::BillingPending->value) {
            return response()->json(['message' => '模型调用结果待核验，报告暂缓交付，请勿重复发起。'], 409);
        }

        $outputData = OperationalTextRedactor::redactOperationalFields($execution->output_data ?? []);
        $storagePath = $outputData[$format] ?? null;

        if (! $storagePath) {
            // 仅返回有效的格式名称，不泄露内部存储路径
            $validFormats = ['markdown', 'html', 'docx', 'pdf'];
            $available = array_keys(array_filter(
                $outputData,
                fn($v, $k) => in_array($k, $validFormats, true) && is_string($v),
                ARRAY_FILTER_USE_BOTH,
            ));

            return response()->json([
                'message' => "No {$format} file available for this execution.",
                'available_formats' => array_values($available),
            ], 404);
        }

        if (! is_string($storagePath) || ! ReportStorage::belongsToExecution((int) $execution->id, $storagePath)) {
            return response()->json(['message' => 'File not found in storage.'], 404);
        }

        if (! ReportStorage::readDisk($storagePath)->exists($storagePath)) {
            return response()->json(['message' => 'File not found in storage.'], 404);
        }

        $filename = basename($storagePath);
        $mimeType = match ($format) {
            'markdown' => 'text/markdown',
            'html' => 'text/html',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };

        return ReportStorage::readDisk($storagePath)->download($storagePath, $filename, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * POST /api/v1/skills/execute-async
     * Execute a skill asynchronously via queue. Returns execution ID for polling.
     */
    public function executeAsync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'skill_id' => 'required|string',
            'project_id' => 'required|integer',
            'fields' => 'nullable|array',
            'query' => 'nullable|string|max:2000',
            'material_refs' => 'nullable|array',
            'material_refs.*.type' => 'required_with:material_refs|string|in:title,keyword,author',
            'material_refs.*.id' => 'required_with:material_refs|integer|min:1',
        ]);

        $project = $this->projectForCurrentTenant((int) $validated['project_id']);

        $skill = $this->registry->findOrNull($validated['skill_id']);
        if (!$skill) {
            return response()->json(['message' => 'Skill not found: ' . $validated['skill_id']], 404);
        }
        if (! $skill->isAvailable()) {
            // 与 execute() 同一道闸门。异步这条更要拦：放进去就是排队跑一遍必然
            // 失败的渲染，白烧 token 还照常计费。
            return response()->json(['message' => $skill->unavailableReason], 422);
        }
        if ($skill->actionPath !== null) {
            return response()->json([
                'message' => '该能力需要从专用页面发起。',
                'action' => 'open_skill_action',
                'action_path' => $skill->actionPath,
            ], 422);
        }

        // ── 自动关联前置阶段输出（如：执行路线图 ← 全景诊断） ──
        $contextFromPreviousStage = $this->resolvePreviousStageContext(
            $validated['skill_id'],
            $project->id,
        );
        $fields = $this->fieldsWithProjectContext($project, $validated['fields'] ?? []);
        $query = $validated['query'] ?? '';

        // 子链 A（异步版）：与同步路径一致地注入素材，引用随 brief 序列化进队列，由 Job 在成功时 recordUsage。
        [$fields, $materialRefs] = $this->enrichBriefFromMaterials(
            $fields,
            $query,
            $validated['material_refs'] ?? [],
        );

        $brief = new SkillBrief(
            skillId: $validated['skill_id'],
            projectId: $project->id,
            userId: $request->user()->id,
            fields: $fields,
            query: $query,
            contextFromPreviousStage: $contextFromPreviousStage,
            materialRefs: $materialRefs,
            clientRequestKey: IdempotencyService::requestKey($request),
        );

        $requestTenant = TenantContext::get();
        try {
            try {
                $executionId = $this->runner->executeAsync($brief);
            } finally {
                // A sync queue driver runs the job inside this request. Its
                // cleanup clears TenantContext; restore the authenticated
                // owner before reading the newly created execution row.
                if ($requestTenant !== null) {
                    TenantContext::set($requestTenant);
                }
            }
            $execution = SkillExecution::query()->whereKey($executionId)
                ->where('user_id', $request->user()->id)
                ->where('project_id', $project->id)->where('skill_id', $skill->id)->first();

            if (! $execution) {
                return response()->json([
                    'message' => '原技能执行记录尚未核实，请用原请求编号查询。',
                    'action' => 'skill_execution_result_pending',
                ], 503);
            }

            return response()->json([
                'execution' => $this->executionPayload($execution),
                'message' => 'Skill execution has been queued.',
            ], 202);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Skill execution could not be queued. Please try again or contact support.',
                'action' => 'skill_execution_queue_failed',
            ], 500);
        }
    }

    /**
     * GET /api/v1/executions/{execution}/status
     * Lightweight status polling endpoint (returns status + progress only).
     */
    public function status(SkillExecution $execution): JsonResponse
    {
        $this->ensureCurrentTenantExecution($execution);
        $statusEnum = ExecutionStatus::tryFrom($execution->status);

        return response()->json([
            'id' => $execution->id,
            'skill_id' => $execution->skill_id,
            'skill_name' => $execution->skill_name,
            'status' => $execution->status,
            'status_label' => $statusEnum?->label() ?? $execution->status,
            'duration_seconds' => $execution->duration_seconds,
            'token_usage' => $execution->token_usage,
            'error_message' => $this->friendlySkillError($execution->error_message),
            'is_terminal' => $statusEnum?->isTerminal() ?? true,
            'relay' => $this->relayProgress($execution, $statusEnum),
            'created_at' => $execution->created_at?->toISOString(),
            'updated_at' => $execution->updated_at?->toISOString(),
            'execution' => $this->executionPayload($execution),
        ]);
    }

    /**
     * 接力进度：这份报告写到第几节了。
     *
     * 为什么要有它 —— 分节报告一份要写十来分钟，而界面上只有一个不动的「执行中」，
     * 客户分不清是在写还是已经卡死。没有别的地方能回答这个问题：接力状态行是唯一
     * 知道「总共几节、写完几节」的人。
     *
     * ⚠️ 只取那两个整数列。relays.state 里装着每一节已经写好的正文，一份报告到后段
     *    能有二十万字符；前端每 2.5 秒轮询一次，把整行捞回来纯属浪费。
     *
     * 不给量词（不说「节」也不说「段」）：走接力的既有按章节切的技能，也有按 JSON
     * 字段切的，后者那些「段」在报告目录上根本不存在，说出来客户对不上号。
     */
    private function relayProgress(SkillExecution $execution, ?ExecutionStatus $statusEnum): ?array
    {
        if ($statusEnum?->isTerminal() ?? true) {
            return null;
        }

        $relay = SkillExecutionRelay::where('skill_execution_id', $execution->id)
            ->first(['completed_steps', 'total_steps']);

        if (! $relay || $relay->total_steps < 1) {
            return null;
        }

        return [
            'completed_steps' => $relay->completed_steps,
            'total_steps' => $relay->total_steps,
        ];
    }

    /**
     * GET /api/v1/executions/{execution}/progress
     * 实时进度快照（阶段 3.2）：返回当前状态 + 百分比，并派发进度事件供前端订阅。
     * 轮询降级：前端若无 WebSocket 连接，可定时调用本端点获取最新进度。
     */
    public function progress(SkillExecution $execution): JsonResponse
    {
        $this->ensureCurrentTenantExecution($execution);
        $statusEnum = ExecutionStatus::tryFrom($execution->status);
        $percent = match (true) {
            $execution->status === 'completed' => 100,
            $execution->status === 'failed' => 100,
            $execution->status === 'running' => 60,
            $execution->status === 'pending' => 10,
            default => 0,
        };

        $this->broadcaster->skillProgress($execution->id, $execution->status, $percent, [
            'skill_name' => $execution->skill_name,
            'is_terminal' => $statusEnum?->isTerminal() ?? true,
        ]);

        return response()->json([
            'id' => $execution->id,
            'status' => $execution->status,
            'percent' => $percent,
            'is_terminal' => $statusEnum?->isTerminal() ?? true,
            'topic' => "skill.{$execution->id}",
        ]);
    }

    /**
     * POST /api/v1/executions/{execution}/cancel
     * Cancel a pending or running execution.
     */
    public function cancel(SkillExecution $execution): JsonResponse
    {
        $this->ensureCurrentTenantExecution($execution);
        $cancelled = $this->runner->cancel($execution->id);

        if (! $cancelled) {
            return response()->json([
                'message' => '无法取消该执行（可能已完成或已取消）',
            ], 422);
        }

        return response()->json([
            'execution' => $execution->fresh(),
            'message' => '已取消执行',
        ]);
    }

    public function rerender(SkillExecution $execution, SkillRenderRecovery $recovery): JsonResponse
    {
        $this->ensureCurrentTenantExecution($execution);
        $saved = $recovery->recover($execution);

        return response()->json([
            'execution' => $this->executionPayload($saved),
            'message' => data_get($saved->output_data, 'render_recovery.kind') === 'formats'
                ? '已从原报告补齐下载格式，本次未调用模型。'
                : ($saved->status === 'completed' ? '已重新排版并保存文章编辑稿，本次未调用模型。' : $saved->error_message),
        ]);
    }

    /**
     * POST /api/v1/skills/suggest-keywords
     * AI 辅助生成种子关键词推荐
     */
    public function suggestKeywords(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'domain' => 'required|string|max:500',
            'project_id' => 'required|integer',
        ]);

        $domain = $validated['domain'];
        $attemptOptions = $this->assistAttemptOptions($request, (int) $validated['project_id'], 'keyword-recommendation');
        $originalExecutionTime = $this->extendAssistExecutionTime();

        $schema = <<<'JSON'
{
    "type": "object",
    "properties": {
        "keywords": {
            "type": "array",
            "items": { "type": "string" },
            "description": "推荐的种子关键词列表，8-15 个"
        }
    },
    "required": ["keywords"]
}
JSON;

        $prompt = "你是一个 GEO（生成式引擎优化）关键词研究专家。\n"
            . "用户的业务领域是：{$domain}\n\n"
            . "请根据该业务领域，推荐 8-15 个用户在 AI 搜索引擎（如 ChatGPT、Perplexity、豆包等）中可能会使用的种子关键词。\n"
            . "要求：\n"
            . "1. 覆盖不同的搜索意图（信息型、对比型、推荐型）\n"
            . "2. 包含核心词和长尾词\n"
            . "3. 用自然语言，符合真实用户提问习惯\n"
            . "4. 每个关键词不超过 20 个字";

        try {
            $result = $this->aiProvider->generateStructured($prompt, $schema, '你是专业的 SEO/GEO 关键词分析师。', $attemptOptions);

            if (! $result->success) {
                if ((int) data_get($result->gateway, 'http_status') === 409) {
                    return response()->json(['message' => '请求编号与原输入不一致，请重新提交。'], 409);
                }
                return response()->json([
                    'message' => 'AI 生成失败，请稍后重试',
                    'action' => 'idempotency_result_pending',
                ], 502);
            }

            $keywords = $result->structuredOutput['keywords'] ?? [];

            // 扣费收口：AI 辅助建议也是真实模型调用，须显式扣点（网关已豁免 geo
            // completion 结算，若此处不扣则两头都不扣=免费。见 GEO 双扣费根治）。
            if (! $this->recordAssistUsage($validated['project_id'] ?? null, $request->user()->id, $result, 'ai-assist-keywords', '关键词推荐')) {
                return response()->json([
                    'message' => '原模型结果已生成，用量记录待恢复；请用原输入重试。',
                    'action' => 'idempotency_result_pending',
                ], 503);
            }

            return response()->json([
                'keywords' => array_slice($keywords, 0, 15),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Keyword suggestion failed', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => '关键词推荐服务暂时不可用，请稍后重试',
            ], 502);
        } finally {
            $this->restoreAssistExecutionTime($originalExecutionTime);
        }
    }

    /**
     * POST /api/v1/skills/suggest-competitors
     * AI 辅助推荐行业竞品
     *
     * 核心逻辑：
     *  1. 自动抓取 brand_url 网页内容（title + meta + 可见文本）作为首要依据
     *  2. 叠加前端传来的 description（项目 settings、表单字段）作为补充
     *  3. 无论租户填了多少信息，AI 都能基于**实际业务内容**推荐竞品
     */
    public function suggestCompetitors(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'brand_name'  => 'required|string|max:200',
            'brand_url'   => 'nullable|url|max:500',
            'description' => 'nullable|string|max:2000',
            'project_id'  => 'required|integer',
        ]);

        $attemptOptions = $this->assistAttemptOptions($request, (int) $validated['project_id'], 'competitor-recommendation');
        $brandName   = $validated['brand_name'];
        $brandUrl    = $validated['brand_url'] ?? '';
        $description = $validated['description'] ?? '';
        $originalExecutionTime = $this->extendAssistExecutionTime();

        // ── 如果前端没传 description，尝试从 project_id 自动获取项目上下文 ──
        if (empty($description) && ! empty($validated['project_id'])) {
            $project = Project::find($validated['project_id']);
            if ($project) {
                $parts = [];
                if ($project->name && $project->name !== $brandName) {
                    $parts[] = "项目名称：{$project->name}";
                }
                $settings = $project->settings ?? [];
                if (! empty($settings['target_market'])) {
                    $parts[] = "目标市场：{$settings['target_market']}";
                }
                if (! empty($settings['remark'])) {
                    $parts[] = "业务备注：{$settings['remark']}";
                }
                $description = implode("\n", $parts);
            }
        }

        // ── 自动抓取官网内容，获取真实的业务信息 ──
        $websiteContext = '';
        if ($brandUrl) {
            $websiteContext = $this->fetchWebsiteContext($brandUrl);
        }

        $schema = <<<'JSON'
{
    "type": "object",
    "properties": {
        "competitors": {
            "type": "array",
            "items": { "type": "string" },
            "description": "推荐的竞品名称列表，5-10 个"
        }
    },
    "required": ["competitors"]
}
JSON;

        // ── 构建上下文：官网实际内容 > 用户描述 > 品牌名称 ──
        $contextParts = ["品牌名称：{$brandName}"];
        if ($brandUrl) {
            $contextParts[] = "官网地址：{$brandUrl}";
        }
        if ($websiteContext) {
            $contextParts[] = "官网实际内容摘要：\n{$websiteContext}";
        }
        if ($description) {
            $contextParts[] = "补充业务信息：\n{$description}";
        }
        $context = implode("\n", $contextParts);

        $prompt = "你是一个竞品分析专家，擅长根据企业的**实际业务内容**精准识别同行竞品。\n\n"
            . "## 品牌信息\n{$context}\n\n"
            . "## 任务\n"
            . "根据上述品牌的**实际业务内容和目标市场**，推荐 5-10 个真正的同行竞品。\n\n"
            . "## 推荐原则（非常重要）\n"
            . "1. 竞品必须和该品牌**做同类业务、服务同类客户**，不是仅仅同属\"科技\"或\"互联网\"就算竞品\n"
            . "2. 优先推荐规模相当或略大的直接竞品，不要推荐体量完全不对等的大平台（如 BAT、华为）\n"
            . "3. 如果官网内容或业务描述中明确了具体细分领域，竞品必须来自该细分领域\n"
            . "4. 可包含 1-2 个差异化替代品，但必须有用户重叠\n"
            . "5. 只输出品牌/产品名称，不需要解释\n"
            . "6. 你必须直接输出 JSON，不要输出任何解释、前言或追问\n"
            . "7. 即使信息有限，也请基于已有信息给出最佳推荐";

        try {
            $result = $this->aiProvider->generateStructured($prompt, $schema, '你是专业的行业竞品分析师。', $attemptOptions);

            if (! $result->success) {
                if ((int) data_get($result->gateway, 'http_status') === 409) {
                    return response()->json(['message' => '请求编号与原输入不一致，请重新提交。'], 409);
                }
                return response()->json([
                    'message' => 'AI 生成失败，请稍后重试',
                    'action' => 'idempotency_result_pending',
                ], 502);
            }

            $output = $result->structuredOutput;

            // ── 从 AI 返回中提取竞品列表（兼容各种格式） ──
            // 格式1: {"competitors": [...]}  — 标准 schema
            $competitors = $output['competitors'] ?? [];

            // 格式2: AI 用中文 key 包裹，如 {"竞品分析结果": {"主要竞品": [...]}}
            if (empty($competitors)) {
                $competitors = $this->extractCompetitorsFromOutput($output);
            }

            // 兼容处理：AI 可能返回字符串数组或对象数组
            $names = array_map(function ($item) {
                if (is_string($item)) return $item;
                if (is_array($item)) {
                    return $item['name'] ?? $item['brand'] ?? $item['company']
                        ?? $item['竞品名称'] ?? $item['名称'] ?? $item['品牌'] ?? '';
                }
                return (string) $item;
            }, $competitors);
            $names = array_filter($names, fn($n) => $n !== '');

            // 扣费收口（同 suggestKeywords）：辅助建议的模型调用须显式扣点。
            if (! $this->recordAssistUsage($validated['project_id'] ?? null, $request->user()->id, $result, 'ai-assist-competitors', '竞品推荐')) {
                return response()->json([
                    'message' => '原模型结果已生成，用量记录待恢复；请用原输入重试。',
                    'action' => 'idempotency_result_pending',
                ], 503);
            }

            return response()->json([
                'competitors' => array_slice(array_values($names), 0, 10),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Competitor suggestion failed', [
                'brand_name' => $brandName,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => '竞品推荐服务暂时不可用，请稍后重试',
            ], 502);
        } finally {
            $this->restoreAssistExecutionTime($originalExecutionTime);
        }
    }

    /** Keep synchronous AI helpers alive beyond PHP's 30-second web default. */
    private function extendAssistExecutionTime(): int
    {
        $original = (int) ini_get('max_execution_time');
        // Structured output may retry without native schema; each gateway call
        // has its own timeout. The competitor helper may fetch the site first.
        $required = max(60, 2 * (int) config('geo.model_http_timeout', 280) + 40);
        if ($original > 0 && $original < $required) {
            set_time_limit($required);
        }

        return $original;
    }

    private function restoreAssistExecutionTime(int $original): void
    {
        if ($original > 0 && (int) ini_get('max_execution_time') !== $original) {
            set_time_limit($original);
        }
    }

    /**
     * 抓取网站页面并提取有意义的文本内容（title + meta + 可见文本）。
     * 超时/失败/被拦时返回空字符串，不影响后续流程（竞品推荐还能靠品牌名+描述给结果）。
     *
     * 出站统一交给 SafeOutboundHttpClient。原实现是本仓库防护最弱的一个对外抓取口，
     * 三处缺口叠在一起：
     *   1. 地址零校验 —— brand_url 的验证只有 `nullable|url`，而 Laravel 的 url 规则
     *      认 http://127.0.0.1:8792/（网关自己）、也认 http://169.254.169.254/（云元数据）。
     *      两个爬虫至少还有手写的内网判定，这里连手写的都没有。
     *   2. verify => false —— 证书不校验，中间人可以往提示词里灌任意内容。
     *   3. HTTPS 拿不到 2xx 就改成明文 http 再抓一次 —— 判据写的是「响应不成功」，
     *      所以一个 404 也会触发明文重抓；而真正该触发降级的 TLS 握手失败会抛异常、
     *      被外层 catch 掉，压根走不到降级分支。等于「该降的没降，不该降的降了」。
     *
     * 抓回来的正文会拼进提示词喂给模型，模型输出再返回给用户 —— 所以这不是纯盲打，
     * 内网页面的内容有渠道漏出去，必须按对外抓取的标准防。
     */
    private function fetchWebsiteContext(string $url): string
    {
        try {
            // fragment（#...）是纯浏览器端定位符，从不发给服务器。安全链对内部 API 调用
            // 保留「带 fragment 即拒」的严格策略，这里先剥掉，避免误杀用户从地址栏
            // 复制来的带锚点网址（与两个爬虫同样的处理）。
            $pos = strpos($url, '#');
            $url = $pos === false ? $url : substr($url, 0, $pos);

            $response = $this->fetchWebsiteResponse($url);

            // ── HTTPS 连不上时降级到明文 HTTP（中小企业官网没证书/证书过期是常态） ──
            // 判据改成「连不上」而不是原来的「响应不是 2xx」：404/500 恰恰说明 HTTPS
            // 通着，只是那一页没有，再用明文抓一遍毫无意义、还白打一次请求。
            // 顺带一提，去掉 verify => false 之后这条降级才真正开始起作用 ——
            // 证书过期的站点现在会在 TLS 阶段失败，正好落进这里。
            if ($response === null && str_starts_with($url, 'https://')) {
                $response = $this->fetchWebsiteResponse('http://' . substr($url, 8));
            }

            if ($response === null || ! $response->successful()) {
                return '';
            }

            $html = $response->body();

            // ── 过滤无效页面：宝塔默认页、占位页、错误页等 ──
            $invalidPatterns = [
                '没有找到站点',
                '您的请求在Web服务器中没有找到',
                'default web page',
                'website coming soon',
                'under construction',
                'this domain has no',
                'parked domain',
                'page not found',
                '404 not found',
            ];
            $lowerHtml = mb_strtolower(mb_substr($html, 0, 2000));
            foreach ($invalidPatterns as $pattern) {
                if (str_contains($lowerHtml, mb_strtolower($pattern))) {
                    return '';
                }
            }

            $parts = [];

            // 提取 <title>
            if (preg_match('/<title[^>]*>(.*?)<\/title>/si', $html, $m)) {
                $title = trim(strip_tags($m[1]));
                if ($title) {
                    $parts[] = "网站标题：{$title}";
                }
            }

            // 提取 <meta name="description">
            if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\'](.*?)["\']/si', $html, $m)) {
                $desc = trim(strip_tags($m[1]));
                if ($desc) {
                    $parts[] = "网站描述：{$desc}";
                }
            } elseif (preg_match('/<meta[^>]+content=["\'](.*?)["\'][^>]+name=["\']description["\']/si', $html, $m)) {
                $desc = trim(strip_tags($m[1]));
                if ($desc) {
                    $parts[] = "网站描述：{$desc}";
                }
            }

            // 提取 <meta name="keywords">
            if (preg_match('/<meta[^>]+name=["\']keywords["\'][^>]+content=["\'](.*?)["\']/si', $html, $m)) {
                $kw = trim(strip_tags($m[1]));
                if ($kw) {
                    $parts[] = "关键词：{$kw}";
                }
            }

            // 提取 H1 标签
            if (preg_match_all('/<h1[^>]*>(.*?)<\/h1>/si', $html, $m)) {
                $h1s = array_filter(array_map(fn($t) => trim(strip_tags($t)), $m[1]));
                if (! empty($h1s)) {
                    $parts[] = "页面标题：" . implode(' | ', array_slice($h1s, 0, 3));
                }
            }

            // 提取页面可见文本（去除 script/style 标签后取前 800 字符）
            $text = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $html);
            $text = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $text);
            $text = strip_tags($text);
            $text = preg_replace('/\s+/', ' ', $text);
            $text = trim($text);
            if (mb_strlen($text) > 20) {
                $parts[] = "页面内容：" . mb_substr($text, 0, 800);
            }

            // 总长度限制在 1500 字符以内，避免 prompt 过长
            $result = implode("\n", $parts);
            return mb_strlen($result) > 1500 ? mb_substr($result, 0, 1500) : $result;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Website fetch failed for competitor suggestion', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * 发一次出站抓取。连不上返回 null（交给调用方决定要不要降级重试）。
     *
     * 被出站安全链拦下也返回 null，但单独记一条日志：如果有租户拿这个接口探内网，
     * 这条日志是唯一能看见的信号 —— 接口本身对用户永远只是「没抓到内容」，
     * 不回显任何拦截细节，免得把内网探测结果当成错误消息送出去。
     */
    private function fetchWebsiteResponse(string $url): ?\Illuminate\Http\Client\Response
    {
        try {
            return $this->outbound->get(
                $this->secureHttp->timeout(8)->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; GeoBot/1.0)',
                    'Accept' => 'text/html,application/xhtml+xml',
                ]),
                $url,
                (int) config('geo.outbound_import_max_bytes', 5 * 1024 * 1024),
                // 跟跳转：官网 http→https、裸域→www 是常态，不跟等于大半个官网抓不到。
                // 跟跳转不放松防护 —— 安全链对每一跳都重新解析 + 重新校验地址
                // （见 SafeOutboundHttpClient::send 的循环），跳到内网仍然拦得住。
                maxRedirects: 3,
            );
        } catch (OutboundRequestBlockedException $e) {
            \Illuminate\Support\Facades\Log::warning('[outbound-blocked] 竞品推荐的官网抓取被出站安全链拦下', [
                'reason_code' => $e->reasonCode,
                'url' => $url,
            ]);

            return null;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Website fetch failed for competitor suggestion', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 根据当前 skill_id 自动查找前置阶段的输出上下文。
     *
     * 除全景诊断本身外，后续 Skill 都优先承接最新全景诊断，
     * 让意图、路线、文章、页面审计和监测结果围绕同一项目结论展开。
     * 返回空数组表示无前置上下文（不影响执行）。
     */
    private function resolvePreviousStageContext(string $skillId, int $projectId): array
    {
        $stageDependency = match (true) {
            str_contains($skillId, 'panorama-audit') => null,
            default => Project::GEO_ANALYSIS_SKILL_ID,
        };

        if (! $stageDependency) {
            return [];
        }

        // 查询该项目最新的已完成的依赖 skill 执行记录
        $latestExec = SkillExecution::where('project_id', $projectId)
            ->where('skill_id', $stageDependency)
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->first();

        if (! $latestExec) {
            \Illuminate\Support\Facades\Log::info('No previous stage execution found', [
                'skill_id' => $skillId,
                'project_id' => $projectId,
                'expected_dependency' => $stageDependency,
            ]);
            return [];
        }

        $context = [
            'source_skill_id' => $stageDependency,
            'source_execution_id' => $latestExec->id,
            'source_created_at' => $latestExec->created_at?->toISOString(),
        ];

        $outputData = $latestExec->output_data ?? [];

        // 从结构化数据中只提取关键摘要，避免上下文过大导致超时
        if (isset($outputData['structured'])) {
            $structured = $outputData['structured'];
            $context['diagnosis_summary'] = [
                'title' => $structured['title'] ?? '',
                'brand_name' => $structured['brand_name'] ?? '',
                'scores' => $structured['scores'] ?? $structured['geo_opportunity_map'] ?? [],
                'opportunities' => $structured['opportunities'] ?? $structured['onsite_system_plan'] ?? [],
                'repairs' => $structured['repairs'] ?? $structured['page_and_asset_fix_list'] ?? [],
                'risk_register' => $structured['risk_register'] ?? $structured['risks_and_assumptions'] ?? [],
                'conclusion' => $structured['conclusion'] ?? '',
            ];
        }
        if (isset($outputData['result_text'])) {
            $text = $outputData['result_text'];
            $context['diagnosis_text'] = mb_strlen($text) > 4000
                ? mb_substr($text, 0, 4000) . "\n[...诊断报告过长已截断...]"
                : $text;
        }

        \Illuminate\Support\Facades\Log::info('Previous stage context resolved', [
            'skill_id' => $skillId,
            'source_execution_id' => $latestExec->id,
            'has_structured' => isset($context['diagnosis_summary']),
            'text_length' => mb_strlen($context['diagnosis_text'] ?? ''),
        ]);

        return $context;
    }

    /**
     * 子链 A · 技能 pick：把素材库内容注入技能 brief，让"curate 进素材库"的内容真正被技能消费。
     *
     * 口径（P1 升级：显式选择优先、留空才兜底）：
     *  1) 显式选择优先：前端随 brief 提交 material_refs（用户从素材库挑的具体 id），
     *     直接注入对应字段并计入 $refs（供 recordUsage）：
     *       - 标题 → topic（覆盖，用户刻意挑选）
     *       - 关键词 → target_keywords（追加，逗号拼接）
     *       - 作者 → author_name（覆盖）
     *     显式选择始终生效，即便用户同时填了 query 也照常注入。
     *  2) 留空兜底：仅当「没有任何显式选择」且「query 与 topic 都为空」时，才从素材库随机 pick 补缺
     *     （标题 1 / 关键词 3 / 作者 1）。这与升级前行为完全一致，不破坏现有留空兜底。
     *
     * 租户隔离由 BelongsToTenant 全局作用域 + TenantContext 保证：显式引用会按 id 反查并校验
     * tenant_id，非本租户行直接跳过；pick / recordUsage 同样受作用域约束。
     *
     * @param  array<string, mixed>  $fields
     * @param  array<int, array{type: string, id: int}>  $explicitRefs
     * @return array{0: array<string, mixed>, 1: array<int, array{type: string, id: int}>}
     */
    private function enrichBriefFromMaterials(array $fields, string $query, array $explicitRefs = []): array
    {
        $refs = [];

        // ── 1) 显式选择优先 ──
        foreach ($explicitRefs as $ref) {
            $type = $ref['type'] ?? null;
            $id = (int) ($ref['id'] ?? 0);
            if (! in_array($type, ['title', 'keyword', 'author'], true) || $id <= 0) {
                continue;
            }

            $model = match ($type) {
                'title' => TitleLibrary::find($id),
                'keyword' => KeywordLibrary::find($id),
                'author' => Author::find($id),
            };

            // 反查命中且属于当前租户，才采用；否则跳过该引用（不静默伪造）。
            if (! $model || (int) ($model->tenant_id ?? null) !== (int) TenantContext::id()) {
                continue;
            }

            if ($type === 'title') {
                $fields['topic'] = $model->title;
            } elseif ($type === 'keyword') {
                $existing = $fields['target_keywords'] ?? '';
                $fields['target_keywords'] = $existing ? $existing . ', ' . $model->keyword : $model->keyword;
            } elseif ($type === 'author') {
                $fields['author_name'] = $model->name;
            }

            $refs[] = ['type' => $type, 'id' => $id];
        }

        // ── 2) 留空兜底：无显式选择且 query/topic 皆空时才随机补缺（升级前行为）──
        if (empty($refs) && ! $this->hasBriefValue($query) && ! $this->hasBriefValue($fields['topic'] ?? null)) {
            // 标题 → topic
            $title = $this->materials->pick('title', 1)->first();
            if ($title) {
                $fields['topic'] = $title->title;
                $refs[] = ['type' => 'title', 'id' => (int) $title->id];
            }

            // 关键词 → target_keywords（仅补空缺）
            if (! $this->hasBriefValue($fields['target_keywords'] ?? null)) {
                $keywords = $this->materials->pick('keyword', 3);
                if ($keywords->isNotEmpty()) {
                    $fields['target_keywords'] = $keywords->pluck('keyword')->implode(', ');
                    foreach ($keywords as $keyword) {
                        $refs[] = ['type' => 'keyword', 'id' => (int) $keyword->id];
                    }
                }
            }

            // 作者 → author_name（仅补空缺）
            if (! $this->hasBriefValue($fields['author_name'] ?? null)) {
                $author = $this->materials->pick('author', 1)->first();
                if ($author) {
                    $fields['author_name'] = $author->name;
                    $refs[] = ['type' => 'author', 'id' => (int) $author->id];
                }
            }
        }

        return [$fields, $refs];
    }

    private function fieldsWithProjectContext(Project $project, array $fields): array
    {
        $settings = $project->settings ?? [];
        $profile = [
            'project_name' => $project->name,
            'brand_name' => $project->brand_name ?: $project->name,
            'brand_url' => $project->brand_url,
            'product_description' => $settings['product_description'] ?? null,
            'target_market' => $settings['target_market'] ?? null,
            'target_keywords' => $settings['target_keywords'] ?? null,
            'competitors' => $settings['competitors'] ?? null,
            'goals' => $settings['goals'] ?? null,
        ];

        foreach ($profile as $key => $value) {
            if ($this->hasBriefValue($fields[$key] ?? null)) {
                continue;
            }

            if ($this->hasBriefValue($value)) {
                $fields[$key] = $value;
            }
        }

        $this->normalizeMissingBrandUrl($fields);
        $this->normalizeTimelineDays($fields);

        return $fields;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function normalizeMissingBrandUrl(array &$fields): void
    {
        if (array_key_exists('brand_url', $fields) && ! $this->hasBriefValue($fields['brand_url'])) {
            $fields['brand_url'] = '(未提供)';
        }
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function normalizeTimelineDays(array &$fields): void
    {
        if (! array_key_exists('timeline_days', $fields) || ! $this->hasBriefValue($fields['timeline_days'])) {
            return;
        }

        $days = (int) $fields['timeline_days'];
        if ($days < 1) {
            $fields['timeline_days'] = 90;

            return;
        }

        $fields['timeline_days'] = min($days, 365);
    }

    private function hasBriefValue(mixed $value): bool
    {
        if (is_array($value)) {
            return count(array_filter($value, fn (mixed $item): bool => $this->hasBriefValue($item))) > 0;
        }

        return $value !== null && trim((string) $value) !== '';
    }

    /**
     * 深度搜索 AI 返回的 JSON，找到竞品列表数组
     *
     * AI 可能用中文 key 包裹结果，如：
     *   {"竞品分析结果": {"主要竞品": [{"竞品名称": "xxx"}, ...]}}
     *
     * 本方法递归搜索包含 name-like key 的最大数组
     */
    private function extractCompetitorsFromOutput(array $data): array
    {
        $candidates = [];

        // 已知的竞品列表 key 名（中英文）
        $listKeys = ['competitors', '竞品', '主要竞品', '竞品列表', '推荐竞品', 'brands', 'companies'];
        $nameKeys = ['name', 'brand', 'company', '竞品名称', '名称', '品牌', '品牌名称', 'company_name', 'brand_name'];

        // 第一层：检查已知 key
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // 如果这个 key 本身就像竞品列表 key
                $lowerKey = mb_strtolower(is_string($key) ? $key : '');
                if (in_array($lowerKey, $listKeys) && ! empty($value) && array_is_list($value)) {
                    return $value;
                }

                // 如果是嵌套对象，搜索内部
                foreach ($value as $subKey => $subValue) {
                    if (is_array($subValue) && ! empty($subValue) && array_is_list($subValue)) {
                        $subLower = mb_strtolower(is_string($subKey) ? $subKey : '');
                        if (in_array($subLower, $listKeys)) {
                            return $subValue;
                        }
                        // 检查数组元素是否包含 name-like key
                        $firstItem = reset($subValue);
                        if (is_array($firstItem)) {
                            foreach ($nameKeys as $nk) {
                                if (array_key_exists($nk, $firstItem)) {
                                    $candidates[] = $subValue;
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }

        // 返回找到的最大候选数组
        if (! empty($candidates)) {
            usort($candidates, fn($a, $b) => count($b) - count($a));
            return $candidates[0];
        }

        return [];
    }

    private function assistAttemptOptions(Request $request, int $projectId, string $featureId): array
    {
        // Resolve the billing project before sending a paid supplier request.
        $this->projectForCurrentTenant($projectId);
        // The generic API idempotency middleware permanently replays a transient
        // 502. Keep this model attempt separate so a retry can recover the
        // gateway's original result with the same supplier request identity.
        $key = $request->header('X-Assist-Attempt-Key');
        if (! is_string($key) || ! preg_match('/^[A-Za-z0-9_.:-]{1,160}$/', $key)) {
            throw ValidationException::withMessages([
                'idempotency_key' => '此建议请求需要有效的重试编号。',
            ]);
        }

        return [
            'feature_id' => $featureId,
            'task_id' => 'assist:'.hash('sha256', json_encode([
                $request->user()->id, $projectId, $featureId, $key,
            ])),
            'step_id' => 'suggest',
        ];
    }

    /**
     * AI 辅助建议（推荐关键词/品名）扣费收口。
     * 这两个入口直接调 aiProvider，不经 runner 的唯一记账点；网关豁免 geo completion
     * 结算后，若此处不扣则两头都不扣=免费（07-29 复盘）。实现已抽到 AssistUsageRecorder，
     * 与素材库「批量生成标题」等同类入口共用同一条扣费路，避免各写各的漏掉一处。
     */
    private function recordAssistUsage(?int $projectId, int $userId, AiProviderCallResult $result, string $kind, string $label): bool
    {
        return $this->assistRecorder->record($projectId, $userId, $result, $kind, $label) !== null;
    }

    private function projectForCurrentTenant(int $projectId): Project
    {
        $project = Project::query()->whereKey($projectId)->first();

        if (! $project || (int) $project->tenant_id !== (int) TenantContext::id()) {
            throw ValidationException::withMessages([
                'project_id' => 'The selected project_id is invalid.',
            ]);
        }

        return $project;
    }

    private function ensureCurrentTenantExecution(SkillExecution $execution): void
    {
        if ((int) $execution->tenant_id !== (int) TenantContext::id()) {
            abort(404);
        }
    }

    private function executionPayload(SkillExecution $execution): array
    {
        $execution->loadMissing([
            'project:id,name',
            'user:id,name',
            'article:id,title,source_type,status',
            'modelCallLogs:id,skill_execution_id,status,metadata,total_tokens',
        ]);
        $outputData = OperationalTextRedactor::redactOperationalFields($execution->output_data ?? []);
        $validFormats = ['markdown', 'html', 'docx', 'pdf'];
        $availableFormats = array_values(array_filter(
            $validFormats,
            fn (string $format) => isset($outputData[$format]) && is_string($outputData[$format])
        ));

        return [
            'id' => $execution->id,
            'project_id' => $execution->project_id,
            'project' => $execution->project,
            'user_id' => $execution->user_id,
            'skill_id' => $execution->skill_id,
            'skill_name' => $execution->skill_name,
            'category' => $execution->category,
            'stage_index' => $execution->stage_index,
            'status' => $execution->status,
            'status_label' => ExecutionStatus::tryFrom($execution->status)?->label() ?? $execution->status,
            'input_data' => $execution->input_data ?? [],
            'output_data' => $outputData,
            'result_text' => $this->resultText($outputData),
            'structured' => $outputData['structured'] ?? null,
            'rendered_formats' => $this->normalizeRenderedFormats($outputData['rendered_formats'] ?? $availableFormats),
            'available_formats' => $availableFormats,
            'quality_report' => $execution->quality_report,
            'can_rerender' => SkillRenderRecovery::eligible($execution),
            'duration_seconds' => $execution->duration_seconds,
            'token_usage' => $execution->token_usage,
            // Failed executions may still contain completed model calls.
            'billing' => $this->executionUsagePayload($execution),
            'error_message' => $this->friendlySkillError($execution->error_message),
            'article_id' => $execution->article_id ?? data_get($outputData, 'article_id'),
            'article' => $execution->article,
            'article_url' => data_get($outputData, 'article_url') ?? data_get($outputData, 'article'),
            'article_download_url' => data_get($outputData, 'article_download_url'),
            'created_at' => $execution->created_at?->toISOString(),
            'updated_at' => $execution->updated_at?->toISOString(),
        ];
    }

    /** Usage and saved-result recovery state. */
    private function executionUsagePayload(SkillExecution $execution): array
    {
        $logs = $execution->modelCallLogs;
        $knownConflict = KnownChunkIdentityConflictRecovery::isLegacyRepairConflict($execution);
        return [
            'model_call_count' => $logs->count(),
            'total_tokens' => (int) $logs->sum('total_tokens'),
            'recovery_stage' => $knownConflict ? 'known_chunk_identity_conflict' : (in_array(data_get($execution->billing_pending_result, 'kind'),
                ['embedding_attempt_prepared', 'embedding_attempt_unknown', 'embedding_result_recorded',
                    'model_attempt_prepared', 'model_attempt_unknown', 'model_result_unrecorded', 'model_result_recorded'], true)
                ? data_get($execution->billing_pending_result, 'kind') : null),
            'recovery_action' => $knownConflict ? 'operator_preflight_required' : null,
        ];
    }

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
}
