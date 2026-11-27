<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\IngestionFailure;
use App\Http\Controllers\Controller;
use App\Jobs\IngestKnowledgeDocumentJob;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Contracts\KnowledgeRetrieval;
use App\Services\DTOs\RetrievedChunk;
use App\Services\Knowledge\DocumentIngestionService;
use App\Support\TenantContext;
use App\Support\ProjectKnowledge;
use App\Support\KnowledgeUploadValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

use App\Support\Concerns\RecordsSensitiveAction;
class KnowledgeBaseController extends Controller
{
    use RecordsSensitiveAction;

    /**
     * B7/§7 扣费归属校验规则：project_id 必填且必须是**当前租户**的项目。
     *
     * 执行书 §7.3 要求 required|integer|min:1；这里再加 exists 租户约束 ——
     * 否则一个不属于本租户（或不存在）的 project_id 会先落进文档行，等到
     * process() 里 charge() 的 projectForCurrentTenant 抛异常被吞掉，多模态
     * 已经跑完却一分不扣，正是 §7.3「绝不允许免费跑一次」要堵的洞。
     * 在上传这一步就 422，让用户当场改。
     */
    private function projectAttributionRules(): array
    {
        return [
            'project_id' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('projects', 'id')->where('tenant_id', TenantContext::id()),
            ],
        ];
    }

    public function __construct(
        private readonly DocumentIngestionService $ingestion,
        private readonly KnowledgeRetrieval $retrieval,
    ) {}

    /**
     * GET /api/v1/knowledge-bases
     * List all knowledge bases for the current tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $projectId = ProjectKnowledge::requestedProject($request);
        $tenantId = TenantContext::id();

        $perPage = min((int) $request->query('per_page', 20), 100);

        $bases = KnowledgeBase::where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->withCount('documents')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $models = app(\App\Services\AI\ModelConfiguration::class);
        $vision = app(\App\Services\AI\ModelConnectionResolver::class)->availability('vision');
        return response()->json($bases->toArray() + ['model_configuration' => [
            'embedding_configured' => $models->active('embedding') !== null,
            'vision_configured' => $vision['available'],
            'vision_message' => $vision['message'],
        ]]);
    }

    /**
     * POST /api/v1/knowledge-bases
     * Create a new knowledge base.
     */
    public function store(Request $request): JsonResponse
    {
        $projectId = ProjectKnowledge::requestedProject($request);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
        ]);

        $tenantId = TenantContext::id();

        $base = KnowledgeBase::create([
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'status' => 'active',
        ]);

        Log::info('Knowledge base created', [
            'id' => $base->id,
            'tenant_id' => $tenantId,
            'name' => $base->name,
        ]);

        return response()->json(['data' => $base], 201);
    }

    /**
     * GET /api/v1/knowledge-bases/stats
     * Tenant-level knowledge base statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        $projectId = ProjectKnowledge::requestedProject($request);
        $baseIds = KnowledgeBase::where('project_id', $projectId)->pluck('id');
        $documents = KnowledgeDocument::whereIn('knowledge_base_id', $baseIds);
        return response()->json([
            'knowledge_bases_count' => $baseIds->count(),
            'total_documents' => (clone $documents)->count(),
            'ready_documents' => (clone $documents)->where('status', 'ready')->count(),
            'processing_documents' => (clone $documents)->whereIn('status', ['pending', 'processing'])->count(),
        ]);
    }

    /**
     * GET /api/v1/knowledge-bases/{knowledgeBase}
     * Show a knowledge base with document count and chunk count.
     */
    public function show(KnowledgeBase $knowledgeBase): JsonResponse
    {
        ProjectKnowledge::assertOwnership($knowledgeBase);
        $knowledgeBase->loadCount('documents');

        // Also count total chunks across all documents in this knowledge base
        $documentIds = $knowledgeBase->documents()->pluck('id');
        $chunkCount = \App\Models\KnowledgeChunk::whereIn('document_id', $documentIds)->count();

        $data = $knowledgeBase->toArray();
        $data['total_chunks'] = $chunkCount;

        return response()->json(['data' => $data]);
    }

    /**
     * DELETE /api/v1/knowledge-bases/{knowledgeBase}
     * Delete a knowledge base and cascade-delete all documents and chunks.
     */
    public function destroy(KnowledgeBase $knowledgeBase): JsonResponse
    {
        ProjectKnowledge::assertOwnership($knowledgeBase);
        // 敏感操作审计（计划 §9.7）：知识库连同文档一起删，事后必须查得到删了什么。
        $this->auditSensitive('knowledge_base.deleted', $knowledgeBase, [
            'name' => (string) $knowledgeBase->name,
            'documents' => (int) $knowledgeBase->documents()->count(),
        ]);
        $directories = $knowledgeBase->documents()->get()->map->storageDirectory()->unique();
        \Illuminate\Support\Facades\DB::transaction(function () use ($knowledgeBase) {
            $documentIds = $knowledgeBase->documents()->pluck('id');

            // Delete all chunks belonging to documents in this knowledge base
            \App\Models\KnowledgeChunk::whereIn('document_id', $documentIds)->delete();

            // Delete all documents
            $knowledgeBase->documents()->delete();

            // Delete the knowledge base itself
            $knowledgeBase->delete();

            Log::info('Knowledge base deleted', [
                'id' => $knowledgeBase->id,
                'documents_deleted' => count($documentIds),
            ]);
        });

        // 磁盘上的文件也要清掉。删单个文档时是清的（destroyDocument），
        // 但整库删除此前只清了数据库，文件永远留在 storage 里没人认领 ——
        // 企业知识库发布现在也会往这里写 content.md，不清会越积越多。
        // 放在事务提交之后：事务回滚了就不该动文件。
        foreach ($directories as $directory) Storage::disk('local')->deleteDirectory($directory);

        return response()->json(['message' => '知识库已删除。']);
    }

    /**
     * POST /api/v1/knowledge-bases/{knowledgeBase}/documents
     * Upload a document file for ingestion.
     */
    /**
     * 单个上传文档的体积上限（KB）。老板 2026-08-07 拍板 10MB。
     *
     * 改这个数必须同时改 Dockerfile 里的 php.ini（upload_max_filesize / post_max_size），
     * 只改这里不生效 —— PHP 在接收阶段就把超限文件拒了，请求根本进不到这个方法。
     * post_max_size 必须严格大于 upload_max_filesize，给表单其余字段留空间。
     */
    private const MAX_UPLOAD_KB = KnowledgeUploadValidation::MAX_UPLOAD_KB;

    public function uploadDocument(Request $request, KnowledgeBase $knowledgeBase): JsonResponse
    {
        ProjectKnowledge::assertOwnership($knowledgeBase);
        abort_unless(app(\App\Services\AI\ModelConfiguration::class)->active('embedding'), 422, '未配置向量模型');
        // 超限要先自己拦，不能交给 validate()：PHP 在接收阶段就拒掉的文件，
        // Laravel 只会报 uploaded 规则那句「The file failed to upload.」——英文、
        // 且完全不说为什么。2026-08-07 老板传一份 7.8MB 的 PDF 就是撞这句，
        // 只能看出"失败了"，看不出是超了当时 5MB 的上限。
        if ($message = $this->uploadSizeError($request)) {
            return response()->json([
                'message' => $message,
                'errors' => ['file' => [$message]],
            ], 422);
        }

        $validated = $request->validate(array_merge([
            'file' => 'required|file|max:' . self::MAX_UPLOAD_KB,
            'title' => 'nullable|string|max:500',
        ], $this->projectAttributionRules()), [
            'file.max' => sprintf('文件超过 %d MB 上限，请压缩或拆分后再传。', intdiv(self::MAX_UPLOAD_KB, 1024)),
            'file.uploaded' => sprintf('文件上传失败，可能超过 %d MB 上限或传输中断，请重试。', intdiv(self::MAX_UPLOAD_KB, 1024)),
            'file.required' => '请选择要上传的文件。',
            'project_id.required' => '请选择这份文档归属的项目（解析费用记到该项目）。',
        ]);

        abort_unless((int) $validated['project_id'] === (int) $knowledgeBase->project_id, 422, '资料只能上传到当前知识库所属的项目。');

        try {
            if (config('geo.async_ingest', true)) {
                $document = $this->ingestion->stageFile(
                    $knowledgeBase->id,
                    $request->file('file'),
                    $validated['title'] ?? null,
                    (int) $validated['project_id'],
                    (int) $request->user()->id,
                );
                IngestKnowledgeDocumentJob::dispatch(TenantContext::id(), (int) $document->id);

                return response()->json([
                    'data' => $document,
                    'message' => '文档已上传，正在后台解析。',
                ], 202);
            }

            $document = $this->ingestion->ingestFile(
                $knowledgeBase->id,
                $request->file('file'),
                $validated['title'] ?? null,
                (int) $validated['project_id'],
                (int) $request->user()->id,
            );

            return response()->json([
                'data' => $document,
                'message' => '文档已入库。',
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Document upload failed', [
                'knowledge_base_id' => $knowledgeBase->id,
                'error' => $e->getMessage(),
            ]);

            // IngestionFailure 的原话是写给客户的（体积超限之类），原样带出；
            // 其它异常一律通用提示，原文只进上面的日志。
            return response()->json([
                'message' => IngestionFailure::messageFor($e, '文档保存失败，请重试。'),
                // 登记/派发可能已发生；客户端须保留原键，不能把 422 当作零副作用。
                'action' => 'knowledge_ingestion_result_unknown',
            ], 422);
        }
    }

    /**
     * 体积超限时给一句说人话的提示；没超限返回 null。
     *
     * 为什么不能只靠 validate()：文件一旦超过 php.ini 的上限，PHP 在**接收阶段**就
     * 处理掉了，Laravel 拿到的东西已经残缺，两种超限各自会退化成一句误导人的话：
     *
     *   超过 upload_max_filesize → 文件对象在但带错误码 → 「The file failed to upload.」
     *   超过 post_max_size       → $_POST 和 $_FILES 一起被丢空 → 「请选择要上传的文件」
     *
     * 后一句尤其糟：用户明明选了文件，系统却说他没选。
     */
    private function uploadSizeError(Request $request): ?string
    {
        return KnowledgeUploadValidation::sizeError($request);
    }

    /**
     * GET /api/v1/knowledge-bases/{knowledgeBase}/documents
     * List documents in a knowledge base.
     */
    public function listDocuments(Request $request, KnowledgeBase $knowledgeBase): JsonResponse
    {
        ProjectKnowledge::assertOwnership($knowledgeBase);
        $perPage = min((int) $request->query('per_page', 20), 100);

        $documents = $knowledgeBase->documents()
            ->withCount('chunks')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json($documents);
    }

    public function recrawlDocument(Request $request, KnowledgeBase $knowledgeBase, KnowledgeDocument $document): JsonResponse
    {
        ProjectKnowledge::assertOwnership($knowledgeBase);
        abort_unless((int) $document->knowledge_base_id === (int) $knowledgeBase->id
            && $document->source_type === 'url' && $document->source_url, 404);

        [$replacement, $created] = DB::transaction(function () use ($document, $knowledgeBase, $request) {
            $original = KnowledgeDocument::query()->lockForUpdate()->findOrFail($document->id);
            $existingId = (int) (($original->metadata ?? [])['replacement_document_id'] ?? 0);
            $existing = $existingId ? KnowledgeDocument::query()->find($existingId) : null;
            if ($existing && in_array($existing->status, ['pending', 'processing', 'ready'], true)) {
                return [$existing, false];
            }
            $new = $this->ingestion->stageUrl($knowledgeBase->id, $original->source_url,
                $original->title, $original->project_id, (int) $request->user()->id);
            $new->update(['metadata' => array_merge($new->metadata ?? [], ['supersedes_document_id' => $original->id])]);
            $original->update(['metadata' => array_merge($original->metadata ?? [], ['replacement_document_id' => $new->id])]);
            return [$new, true];
        });

        if ($created) {
            IngestKnowledgeDocumentJob::dispatch(TenantContext::id(), (int) $replacement->id);
        }

        return response()->json(['data' => $replacement, 'message' => '已保留原文档，正在重抓并建立新版本。'], 202);
    }

    public function archiveDocument(KnowledgeBase $knowledgeBase, KnowledgeDocument $document): JsonResponse
    {
        ProjectKnowledge::assertOwnership($knowledgeBase);
        abort_unless((int) $document->knowledge_base_id === (int) $knowledgeBase->id, 404);
        $document->update(['review_status' => 'archived']);
        return response()->json(['data' => $document->fresh(), 'message' => '文档已归档，历史仍可查看。']);
    }

    /**
     * DELETE /api/v1/knowledge-bases/{knowledgeBase}/documents/{document}
     * Delete a document and its associated chunks.
     */
    public function destroyDocument(KnowledgeBase $knowledgeBase, KnowledgeDocument $document): JsonResponse
    {
        ProjectKnowledge::assertOwnership($knowledgeBase);
        // Verify the document belongs to this knowledge base
        if ($document->knowledge_base_id !== $knowledgeBase->id) {
            return response()->json([
                'message' => '该文档不属于这个知识库。',
            ], 404);
        }

        // Delete chunks first
        $document->chunks()->delete();

        // 敏感操作审计（计划 §9.7）：删数据类动作留痕（只记文件名，不记正文/切片内容）。
        $this->auditSensitive('knowledge_document.deleted', $document, [
            'name' => (string) ($document->title ?? $document->file_name ?? ''),
            'knowledge_base_id' => (int) $knowledgeBase->id,
        ]);

        // Delete stored file if it exists
        if ($document->file_path) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($document->file_path);
        }

        $document->delete();

        Log::info('Document deleted', [
            'document_id' => $document->id,
            'knowledge_base_id' => $knowledgeBase->id,
        ]);

        return response()->json(['message' => '文档已删除。']);
    }

    /**
     * GET /api/v1/knowledge-bases/{knowledgeBase}/documents/{document}/chunks
     * 查看一篇文档入库后的切片正文（A3：解析结果可见）。
     *
     * 租户隔离靠 BelongsToTenant 全局作用域（document 模型已 use），
     * 这里只额外校验 document 确实属于这个 knowledge_base，否则 404。
     */
    public function showDocumentChunks(Request $request, KnowledgeBase $knowledgeBase, KnowledgeDocument $document): JsonResponse
    {
        ProjectKnowledge::assertOwnership($knowledgeBase);
        if ($document->knowledge_base_id !== $knowledgeBase->id) {
            return response()->json([
                'message' => '该文档不属于这个知识库。',
            ], 404);
        }

        $perPage = min((int) $request->query('per_page', 20), 100);

        // B6-1：切片来自哪一页（仅多模态文档有）。映射在入库时算好存在
        // metadata.extraction.chunk_pages（key = chunk_index，与这里 select 的
        // chunk_index 同源）。没有映射（text_layer 文档）就 null —— 宁缺毋猜。
        $chunkPages = $document->metadata['extraction']['chunk_pages'] ?? null;
        $chunkPages = is_array($chunkPages) ? $chunkPages : null;

        // 显式 select：KnowledgeChunk 没有 $hidden，embedding 是 1024 维浮点数组，
        // 一旦带出去一页 20 条就是 2 万个数字、响应撑成几 MB（§3.A3）。
        $chunks = $document->chunks()
            ->select('id', 'chunk_index', 'section_title', 'content', 'token_count')
            ->orderBy('chunk_index')
            ->paginate($perPage);

        $chunks->getCollection()->transform(function (KnowledgeChunk $chunk) use ($chunkPages) {
            $chunk->page = $chunkPages !== null
                ? ($chunkPages[$chunk->chunk_index] ?? null)
                : null;

            return $chunk;
        });

        return response()->json($chunks);
    }

    /**
     * GET /api/v1/knowledge-bases/{knowledgeBase}/documents/{document}/pages/{page}/image
     * B6-1：图文对照 —— 返回某页的原始渲染图（VisionPageExtractor 落盘的 p{n}.jpg）。
     *
     * 租户隔离同 chunks 接口：BelongsToTenant 全局作用域保证拿不到别家的 document，
     * 这里再校验 document 属于这个 kb。**这是私有 disk，漏了校验就是任意租户读别人的文档图**。
     * 文件不存在一律 404，不返回占位图（执行书 B6-1）。
     */
    public function showDocumentPageImage(Request $request, KnowledgeBase $knowledgeBase, KnowledgeDocument $document, int $page)
    {
        ProjectKnowledge::assertOwnership($knowledgeBase);
        if ($document->knowledge_base_id !== $knowledgeBase->id) {
            return response()->json([
                'message' => '该文档不属于这个知识库。',
            ], 404);
        }

        if ($page < 1 || $page > 10000) {
            return response()->json([
                'message' => '页号不合法。',
            ], 404);
        }

        $relativePath = $document->storageDirectory() . '/pages/p' . $page . '.jpg';

        $absolutePath = \Illuminate\Support\Facades\Storage::disk('local')->path($relativePath);

        if (! is_file($absolutePath)) {
            return response()->json([
                'message' => '该页没有渲染图（文档未走多模态提取，或该页渲染失败）。',
            ], 404);
        }

        return response()->file($absolutePath, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * POST /api/v1/knowledge-bases/{knowledgeBase}/ingest-url
     * Ingest content from a URL into the knowledge base.
     */
    public function ingestUrl(Request $request, KnowledgeBase $knowledgeBase): JsonResponse
    {
        ProjectKnowledge::assertOwnership($knowledgeBase);
        abort_unless(app(\App\Services\AI\ModelConfiguration::class)->active('embedding'), 422, '未配置向量模型');
        $validated = $request->validate(array_merge([
            'url' => 'required|url|max:2000',
            'title' => 'nullable|string|max:500',
        ], $this->projectAttributionRules()), [
            'project_id.required' => '请选择这份文档归属的项目。',
        ]);

        abort_unless((int) $validated['project_id'] === (int) $knowledgeBase->project_id, 422, '资料只能上传到当前知识库所属的项目。');

        try {
            if (config('geo.async_ingest', true)) {
                $document = $this->ingestion->stageUrl(
                    $knowledgeBase->id,
                    $validated['url'],
                    $validated['title'] ?? null,
                    (int) $validated['project_id'],
                    (int) $request->user()->id,
                );
                IngestKnowledgeDocumentJob::dispatch(TenantContext::id(), (int) $document->id);

                return response()->json([
                    'data' => $document,
                    'message' => '网页已收下，正在后台抓取解析。',
                ], 202);
            }

            $document = $this->ingestion->ingestUrl(
                $knowledgeBase->id,
                $validated['url'],
                $validated['title'] ?? null,
                (int) $validated['project_id'],
                (int) $request->user()->id,
            );

            return response()->json([
                'data' => $document,
                'message' => '网页已入库。',
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('URL ingestion failed', [
                'knowledge_base_id' => $knowledgeBase->id,
                'url' => $validated['url'],
                'error' => $e->getMessage(),
            ]);

            // 网址被安全策略拦下（内网地址 / 域名解析不到）时，必须把具体原因带给用户 ——
            // 回一句「请重试」是骗人：这种地址重试一百次也不会成功。
        return response()->json([
            'message' => IngestionFailure::messageFor($e, '网页入库失败，请重试。'),
            'action' => 'knowledge_ingestion_result_unknown',
        ], 422);
    }
}

    /**
     * 第三层（检索测试框）：可视化现有 RAG 检索链路，不重写任何检索逻辑。
     *
     * POST /api/v1/knowledge-bases/{knowledgeBase}/search-test
     *
     * 铁律（执行书 §1.1）：端点层一行检索逻辑都不新写，只调用
     * KnowledgeRetrievalServiceImpl::retrieve()（复用其现有 filters 参数）。
     * 端点只做：入参校验 → 租户隔离 → hits 结构裁剪（page / 200 字截断）
     * → 计时 → 出参包装。检索失败 / 跨租户 / 无知识库都返回明确 4xx/5xx，
     * 禁止 fixture / mock 兜底（执行书 §0.6）。
     */
    public function searchTest(Request $request, KnowledgeBase $knowledgeBase): JsonResponse
    {
        ProjectKnowledge::assertOwnership($knowledgeBase);
        $validated = $request->validate([
            'query' => 'required|string|max:500',
            'top_k' => 'nullable|integer|min:1',
            'knowledge_base_ids' => 'nullable|array',
            'knowledge_base_ids.*' => 'integer',
        ], [
            'query.required' => '请输入要检索的问题。',
            'query.max' => '问题不能超过 500 字。',
            'top_k.integer' => '返回条数必须是整数。',
            'top_k.min' => '返回条数至少为 1。',
            'knowledge_base_ids.array' => '知识库过滤必须是数组。',
            'knowledge_base_ids.*.integer' => '知识库 ID 必须是整数。',
        ]);

        $tenantId = TenantContext::id();
        $query = $validated['query'];
        // top_k 钳到 [1, 10]（执行书 §1.1：上限 10）
        $topK = min(max((int) ($validated['top_k'] ?? 5), 1), 10);

        // D3：默认当前知识库；传了 knowledge_base_ids 则以之为过滤集，
        // 但每个 id 都必须属于当前租户，否则 404（跨租户知识库不可见）。
        if (! empty($validated['knowledge_base_ids'])) {
            $requestedIds = array_map('intval', $validated['knowledge_base_ids']);
            $allowedIds = KnowledgeBase::where('tenant_id', $tenantId)
                ->where('project_id', $knowledgeBase->project_id)
                ->whereIn('id', $requestedIds)
                ->pluck('id')
                ->all();
            $allowedSet = array_flip($allowedIds);
            $foreign = array_filter($requestedIds, fn ($id) => ! isset($allowedSet[$id]));
            if ($foreign !== []) {
                return response()->json([
                    'message' => '指定的知识库不存在或不属于当前租户。',
                ], 404);
            }
            $targetIds = $allowedIds;
        } else {
            $targetIds = [$knowledgeBase->id];
        }
        $targetNames = KnowledgeBase::whereIn('id', $targetIds)
            ->pluck('name', 'id')
            ->all();

        // The API middleware uses X-Idempotency-Key. Keep the older unprefixed
        // header for existing clients, but prefer the same key as the middleware.
        $clientOperationId = trim((string) ($request->header('X-Idempotency-Key')
            ?: $request->header('Idempotency-Key', '')));
        if (! preg_match('/^[A-Za-z0-9_.:-]{1,120}$/', $clientOperationId)) {
            return response()->json(['message' => '检索测试需要有效的 Idempotency-Key，重试请复用同一编号。'], 422);
        }
        $filters = ['knowledge_base_ids' => $targetIds, 'project_id' => $knowledgeBase->project_id,
            'user_id' => (int) $request->user()->id,
            'feature_id' => 'knowledge-query', 'task_id' => 'knowledge-search:'.$clientOperationId];

        $start = microtime(true);
        try {
            // 唯一一处检索调用：复用现有服务，不新写逻辑
            $hits = $this->retrieval->retrieve($query, $topK, 0.0, $filters);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($e instanceof \DomainException && $e->getCode() === 409) {
                return response()->json(['message' => $e->getMessage()], 409);
            }
            Log::error('Knowledge search-test failed', [
                'knowledge_base_id' => $knowledgeBase->id,
                'error' => $e->getMessage(),
            ]);

            // 检索服务（embed / pgvector）不可用 → 明确 5xx，不静默返回空
            return response()->json([
                'message' => '检索服务暂不可用：' . $e->getMessage(),
            ], 502);
        }
        $durationMs = (int) round((microtime(true) - $start) * 1000);

        // 命中切片批量取 page（从 document metadata 的 extraction.chunk_pages 映射推算）。
        // 这是出参裁剪，不是检索逻辑；租户隔离由 KnowledgeChunk 全局作用域保证。
        $chunkIds = array_map(fn (RetrievedChunk $h) => $h->chunkId, $hits);
        $chunkModels = [];
        if ($chunkIds !== []) {
            $chunkModels = KnowledgeChunk::query()
                ->with('document')
                ->whereIn('id', $chunkIds)
                ->get()
                ->keyBy('id');
        }

        $hitData = array_map(function (RetrievedChunk $h) use ($chunkModels) {
            $page = null;
            $model = $chunkModels->get($h->chunkId);
            if ($model && $model->document) {
                $chunkPages = $model->document->metadata['extraction']['chunk_pages'] ?? null;
                if (is_array($chunkPages)) {
                    $page = $chunkPages[$model->chunk_index] ?? null;
                }
            }

            return [
                'chunk_id' => $h->chunkId,
                'document_id' => $h->documentId,
                'document_title' => $h->documentTitle,
                'vector_score' => round($h->vectorScore, 2),
                'relevance_score' => round($h->relevanceScore, 2),
                'page' => $page,
                'section' => $h->section ?: null,
                'content' => mb_substr($h->content, 0, 200),
            ];
        }, $hits);

        return response()->json([
            'data' => [
                'query' => $query,
                'embedding_dim' => $this->retrieval->lastEmbeddingDimension(),
                'hits' => $hitData,
                'meta' => [
                    'total_hits' => count($hitData),
                    'knowledge_base' => [
                        'ids' => $targetIds,
                        'names' => array_values($targetNames),
                    ],
                    'duration_ms' => $durationMs,
                ],
            ],
        ]);
    }
}
