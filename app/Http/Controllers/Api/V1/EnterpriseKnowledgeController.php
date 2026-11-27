<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted application interfaces, workspace ownership and execution behavior.
 * Upstream: app/Http/Controllers/Admin/EnterpriseKnowledgeController.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateEnterpriseKnowledgeDraftJob;
use App\Models\EnterpriseKnowledgeProject;
use App\Models\EnterpriseKnowledgeRevision;
use App\Models\EnterpriseKnowledgeSource;
use App\Models\KnowledgeBase;
use App\Services\Knowledge\EnterpriseKnowledgeDraftService;
use App\Support\TenantContext;
use App\Support\ProjectKnowledge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnterpriseKnowledgeController extends Controller
{
    public function __construct(
        private readonly EnterpriseKnowledgeDraftService $draftService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $geoProjectId = ProjectKnowledge::requestedProject($request);
        $tenantId = TenantContext::id();
        $perPage = min((int) $request->query('per_page', 20), 100);

        $projects = EnterpriseKnowledgeProject::query()
            ->where('tenant_id', $tenantId)
            ->where('project_id', $geoProjectId)
            ->withCount(['sources', 'revisions'])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json($projects);
    }

    public function store(Request $request): JsonResponse
    {
        $geoProjectId = ProjectKnowledge::requestedProject($request);
        $payload = $request->validate([
            'name' => ['nullable', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:2000'],
            'content' => ['nullable', 'string'],
            'sources' => ['nullable', 'array', 'max:20'],
            'sources.*.original_name' => ['nullable', 'string', 'max:191'],
            'sources.*.content' => ['required', 'string'],
            'sources.*.file_type' => ['nullable', 'string', 'max:60'],
            'sources.*.source_url' => ['nullable', 'url:http,https', 'max:2000'],
        ]);
        if (! empty($payload['knowledge_base_id']) && ! empty($payload['create_knowledge_base'])) {
            return response()->json(['message' => '请只选择一个知识库目标。'], 422);
        }

        $manualContent = trim((string) ($payload['content'] ?? ''));
        $sources = $payload['sources'] ?? [];

        if ($manualContent === '' && $sources === []) {
            return response()->json(['message' => '请至少提供一段来源内容（content 或 sources）。'], 422);
        }

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            $name = $this->inferName($manualContent, $sources) ?: '未命名企业知识项目';
        }

        $tenantId = TenantContext::id();
        $userId = (int) $request->user()->id;

        $project = DB::transaction(function () use ($tenantId, $userId, $name, $payload, $manualContent, $sources, $geoProjectId) {
            $project = EnterpriseKnowledgeProject::create([
                'tenant_id' => $tenantId,
                'project_id' => $geoProjectId,
                'name' => $name,
                'description' => trim((string) ($payload['description'] ?? '')),
                'status' => EnterpriseKnowledgeProject::STATUS_DRAFT,
                'created_by_user_id' => $userId,
            ]);

            $sortOrder = 0;
            if ($manualContent !== '') {
                $this->createSource($project, '手动录入', 'markdown', $manualContent, $sortOrder++);
            }

            foreach ($sources as $source) {
                $content = trim((string) ($source['content'] ?? ''));
                if ($content === '') {
                    continue;
                }
                $this->createSource(
                    $project,
                    trim((string) ($source['original_name'] ?? '')) ?: '来源 '.($sortOrder + 1),
                    trim((string) ($source['file_type'] ?? '')) ?: 'text',
                    $content,
                    $sortOrder++,
                    $source['source_url'] ?? null
                );
            }

            return $project;
        });

        return response()->json([
            'object' => 'enterprise_knowledge_project',
            'data' => $project->loadCount(['sources', 'revisions']),
        ], 201);
    }

    public function show(int $projectId): JsonResponse
    {
        $project = $this->findProject($projectId);
        if (! $project) {
            return response()->json(['message' => '项目不存在'], 404);
        }

        $project->load(['sources', 'revisions.creator', 'publishedKnowledgeBase', 'publishedDocument']);

        return response()->json([
            'object' => 'enterprise_knowledge_project',
            'data' => $project,
            'validation_items' => $project->validationItems(),
        ]);
    }

    public function update(Request $request, int $projectId): JsonResponse
    {
        $project = $this->findProject($projectId);
        if (! $project) {
            return response()->json(['message' => '项目不存在'], 404);
        }

        $payload = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $project->update($payload);

        return response()->json([
            'object' => 'enterprise_knowledge_project',
            'data' => $project->load('sources', 'publishedKnowledgeBase', 'publishedDocument'),
        ]);
    }

    public function destroy(int $projectId): JsonResponse
    {
        $project = $this->findProject($projectId);
        if (! $project) {
            return response()->json(['message' => '项目不存在'], 404);
        }

        $project->delete();

        return response()->json(['message' => '已删除']);
    }

    /**
     * 新增一条来源。
     */
    public function addSource(Request $request, int $projectId): JsonResponse
    {
        $project = $this->findProject($projectId);
        if (! $project) {
            return response()->json(['message' => '项目不存在'], 404);
        }

        $payload = $request->validate([
            'original_name' => ['nullable', 'string', 'max:191'],
            'content' => ['required', 'string'],
            'file_type' => ['nullable', 'string', 'max:60'],
            'source_url' => ['nullable', 'url:http,https', 'max:2000'],
        ]);

        $content = trim((string) $payload['content']);
        $source = $this->createSource(
            $project,
            trim((string) ($payload['original_name'] ?? '')) ?: '来源',
            trim((string) ($payload['file_type'] ?? '')) ?: 'text',
            $content,
            $project->sources()->max('sort_order') + 1 ?? 0,
            $payload['source_url'] ?? null
        );

        return response()->json([
            'object' => 'enterprise_knowledge_source',
            'data' => $source,
        ], 201);
    }

    /**
     * 触发草稿生成（入队，阶段 1.5d 的 Job）。
     */
    public function generate(Request $request, int $projectId): JsonResponse
    {
        $project = $this->findProject($projectId);
        if (! $project) {
            return response()->json(['message' => '项目不存在'], 404);
        }

        if ($project->sources()->count() === 0) {
            return response()->json(['message' => '项目没有来源，无法生成草稿。'], 422);
        }

        DB::transaction(function () use ($request, $project) {
            $this->claimDraft($request, $project);
            $project->update(['status' => EnterpriseKnowledgeProject::STATUS_QUEUED]);
        });

        GenerateEnterpriseKnowledgeDraftJob::dispatch($project->id, (int) request()->user()->id, (int) $project->draft_version)
            ->onQueue('knowledge');

        return response()->json([
            'object' => 'enterprise_knowledge_project',
            'data' => $project->fresh(),
            'message' => '草稿生成任务已入队。',
        ], 202);
    }

    /**
     * 自动保存草稿内容（不发布），内容变化则记录修订。
     */
    public function autosave(Request $request, int $projectId): JsonResponse
    {
        $project = $this->findProject($projectId);
        if (! $project) {
            return response()->json(['message' => '项目不存在'], 404);
        }

        $payload = $request->validate([
            'content' => ['required', 'string'],
        ]);

        $content = trim((string) $payload['content']);
        $validationItems = $this->draftService->validateDraft($content);

        DB::transaction(function () use ($request, $project, $content, $validationItems) {
            $this->claimDraft($request, $project);
            $project->update([
                'draft_content' => $content,
                'validation_json' => json_encode($validationItems, JSON_UNESCAPED_UNICODE),
                'status' => in_array($project->status, [EnterpriseKnowledgeProject::STATUS_PUBLISHED, EnterpriseKnowledgeProject::STATUS_QUEUED, EnterpriseKnowledgeProject::STATUS_PROCESSING], true)
                    ? EnterpriseKnowledgeProject::STATUS_REVIEWING
                    : $project->status,
            ]);

            $this->draftService->recordRevisionIfChanged(
                $project,
                $content,
                EnterpriseKnowledgeRevision::SOURCE_MANUAL,
                '手动保存',
                (int) $request->user()->id
            );
        });

        return response()->json([
            'saved_at' => now()->toISOString(),
            'draft_version' => $project->draft_version,
            'validation_count' => count($validationItems),
            'validation_items' => $validationItems,
        ]);
    }

    /**
     * 校验草稿内容。
     */
    public function validateDraft(Request $request, int $projectId): JsonResponse
    {
        $project = $this->findProject($projectId);
        if (! $project) {
            return response()->json(['message' => '项目不存在'], 404);
        }

        $content = trim((string) $request->input('content', $project->draft_content ?? ''));
        $validationItems = $this->draftService->validateDraft($content);

        DB::transaction(function () use ($request, $project, $content, $validationItems) {
            $this->claimDraft($request, $project);
            $project->update([
                'draft_content' => $content,
                'validation_json' => json_encode($validationItems, JSON_UNESCAPED_UNICODE),
            ]);
            $this->draftService->recordRevisionIfChanged($project, $content, EnterpriseKnowledgeRevision::SOURCE_MANUAL, '校验并保存', (int) $request->user()->id);
        });

        return response()->json([
            'draft_version' => $project->draft_version,
            'validation_count' => count($validationItems),
            'validation_items' => $validationItems,
        ]);
    }

    /**
     * 恢复某个历史修订到当前草稿。
     */
    public function restoreRevision(Request $request, int $projectId, int $revisionId): JsonResponse
    {
        $project = $this->findProject($projectId);
        if (! $project) {
            return response()->json(['message' => '项目不存在'], 404);
        }

        $revision = EnterpriseKnowledgeRevision::query()
            ->where('enterprise_knowledge_project_id', $project->id)
            ->whereKey($revisionId)
            ->first();
        if (! $revision) {
            return response()->json(['message' => '修订不存在'], 404);
        }

        $content = (string) $revision->content;
        DB::transaction(function () use ($request, $project, $content, $revisionId) {
            $this->claimDraft($request, $project);
            $project->update([
                'draft_content' => $content,
                'validation_json' => json_encode($this->draftService->validateDraft($content), JSON_UNESCAPED_UNICODE),
                'status' => EnterpriseKnowledgeProject::STATUS_REVIEWING,
            ]);
            $this->draftService->recordRevision(
                $project,
                $content,
                EnterpriseKnowledgeRevision::SOURCE_RESTORE,
                '恢复修订 #'.$revisionId,
                (int) request()->user()->id
            );
        });

        return response()->json([
            'object' => 'enterprise_knowledge_project',
            'data' => $project->load('sources', 'publishedKnowledgeBase', 'publishedDocument'),
        ]);
    }

    /**
     * 发布草稿到知识库（复用 DocumentIngestionService 切片 + 向量化）。
     */
    public function publish(Request $request, int $projectId): JsonResponse
    {
        $project = $this->findProject($projectId);
        if (! $project) {
            return response()->json(['message' => '项目不存在'], 404);
        }

        $payload = $request->validate([
            'base_version' => ['required', 'integer', 'min:0'],
            'content' => ['nullable', 'string'],
            'knowledge_base_id' => ['nullable', 'integer', 'min:1'],
            'create_knowledge_base' => ['nullable', 'boolean'],
        ]);
        $content = trim((string) ($payload['content'] ?? $project->draft_content ?? ''));
        if ($content === '') {
            return response()->json(['message' => '草稿内容为空，无法发布。'], 422);
        }

        $target = null;
        if (! empty($payload['knowledge_base_id'])) {
            $target = KnowledgeBase::query()->where('tenant_id', TenantContext::id())
                ->where('project_id', $project->project_id)->where('status', 'active')
                ->find($payload['knowledge_base_id']);
            abort_unless($target, 422, '请选择当前项目的有效知识库。');
        } elseif (empty($payload['create_knowledge_base']) && ! $project->published_knowledge_base_id) {
            return response()->json(['message' => '请明确选择已有知识库或创建新知识库。'], 422);
        }

        $result = DB::transaction(function () use ($project, $content, $target, $payload, $request) {
            $this->claimDraft($request, $project);
            $locked = $project;
            $result = $this->draftService->publishToKnowledgeBase($locked, $content, $target, ! empty($payload['create_knowledge_base']));
            $knowledgeBase = $result['knowledge_base'];
            $document = $result['document'];
            if ($result['error'] !== null) {
                $locked->update(['published_knowledge_base_id' => $knowledgeBase->id, 'error_message' => $result['error']]);
            } else {
                $locked->update([
                    'status' => EnterpriseKnowledgeProject::STATUS_PUBLISHED,
                    'draft_content' => $content,
                    'published_knowledge_base_id' => $knowledgeBase->id,
                    'published_document_id' => $document?->id,
                    'validation_json' => json_encode($this->draftService->validateDraft($content), JSON_UNESCAPED_UNICODE),
                    'error_message' => null,
                ]);
                $this->draftService->recordRevisionIfChanged($locked, $content,
                    EnterpriseKnowledgeRevision::SOURCE_PUBLISH, '发布到知识库 #'.$knowledgeBase->id,
                    (int) $request->user()->id);
            }
            return $result;
        });
        $knowledgeBase = $result['knowledge_base'];
        $document = $result['document'];
        $error = $result['error'] ?? null;

        // 登记或入队失败 = 这次发布没成功。绝不能照样回 200 + 「已发布」：
        // 用户会以为进了知识库，实际上什么都没排上队。状态留在原地（不置 published），
        // 也不记发布修订；但知识库 ID 要存下来，否则下次重试会再建一个同名知识库。
        if ($error !== null) {
            return response()->json([
                'object' => 'enterprise_knowledge_project',
                'data' => $project->fresh()->load('sources', 'publishedKnowledgeBase', 'publishedDocument'),
                'message' => $error,
            ], 422);
        }

        return response()->json([
            'object' => 'enterprise_knowledge_project',
            'data' => $project->fresh()->load('sources', 'publishedKnowledgeBase', 'publishedDocument'),
            'message' => '已发布到知识库，正在后台建立索引。',
        ]);
    }

    /**
     * 列出修订版本。
     */
    public function revisions(int $projectId): JsonResponse
    {
        $project = $this->findProject($projectId);
        if (! $project) {
            return response()->json(['message' => '项目不存在'], 404);
        }

        $revisions = $project->revisions()
            ->with('creator')
            ->orderByDesc('id')
            ->paginate(min((int) request()->query('per_page', 50), 200));

        return response()->json($revisions);
    }

    private function claimDraft(Request $request, EnterpriseKnowledgeProject $project): void
    {
        $payload = $request->validate(['base_version' => ['required', 'integer', 'min:0']]);
        if ($project->claimDraftVersion((int) $payload['base_version'])) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => '草稿已在其他操作中更新，本地编辑已保留，请比较后再保存。',
            'action' => 'enterprise_draft_conflict',
            'current' => [
                'draft_version' => $project->draft_version,
                'draft_content' => $project->draft_content ?? '',
                'validation_items' => $project->validationItems(),
            ],
        ], 409));
    }

    private function findProject(int $projectId): ?EnterpriseKnowledgeProject
    {
        $project = EnterpriseKnowledgeProject::query()
            ->withCount(['sources', 'revisions'])
            ->whereKey($projectId)
            ->first();

        if (! $project) {
            return null;
        }

        if ((int) $project->tenant_id !== (int) TenantContext::id()) {
            return null;
        }

        ProjectKnowledge::assertOwnership($project);

        return $project;
    }

    private function createSource(
        EnterpriseKnowledgeProject $project,
        string $originalName,
        string $fileType,
        string $content,
        int $sortOrder,
        ?string $sourceUrl = null
    ): EnterpriseKnowledgeSource {
        return EnterpriseKnowledgeSource::create([
            'tenant_id' => $project->tenant_id,
            'enterprise_knowledge_project_id' => $project->id,
            'original_name' => $originalName,
            'file_type' => $fileType,
            'content' => $content,
            'character_count' => mb_strlen($content, 'UTF-8'),
            'sort_order' => $sortOrder,
            'source_url' => $sourceUrl,
        ]);
    }

    /**
     * @param  list<array{original_name?:string,content:string,file_type?:string}>  $sources
     */
    private function inferName(string $manualContent, array $sources): string
    {
        if ($manualContent !== '') {
            $firstLine = strtok($manualContent, "\n");
            $firstLine = trim((string) $firstLine);
            if ($firstLine !== '') {
                return mb_substr(preg_replace('/^#+\s*/u', '', $firstLine), 0, 60);
            }
        }

        foreach ($sources as $source) {
            $content = trim((string) ($source['content'] ?? ''));
            if ($content !== '') {
                $firstLine = strtok($content, "\n");
                $firstLine = trim((string) $firstLine);
                if ($firstLine !== '') {
                    return mb_substr(preg_replace('/^#+\s*/u', '', $firstLine), 0, 60);
                }
            }
        }

        return '';
    }
}
