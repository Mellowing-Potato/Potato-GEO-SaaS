<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ManualPublicationConflictException;
use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ManualPublication;
use App\Models\ManualPublicationAccount;
use App\Models\Project;
use App\Services\Publishing\BrowserExtensionPackage;
use App\Services\Publishing\ManualPublicationService;
use App\Services\Publishing\PlatformPublicationDraftService;
use App\Services\Publishing\ProjectPublicationAccounts;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * 手动发布 · 发布单 API（单 7）。
 *
 * 路由组已挂 auth:sanctum + tenant + can:manual_publish:manage（见 routes/api.php）。
 * 记录级权限（view/update/transition/reopen）在服务层显式校验（ManualPublicationService::can），
 * 不依赖 Gate / Policy（本仓没有 Policy，见执行书 §一-2）。
 */
class ManualPublicationController extends Controller
{
    public function __construct(private readonly ManualPublicationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = ManualPublication::query()->visibleTo($user);
        if ($request->filled('project_id')) {
            $project = Project::query()->findOrFail((int) $request->query('project_id'));
            $query->whereHas('article', fn ($articles) => $articles->where('project_id', $project->id));
        }

        $status = trim((string) $request->query('status'));
        $type = trim((string) $request->query('type'));
        $platform = trim((string) $request->query('platform'));

        if (in_array($status, ManualPublication::STATUSES, true)) {
            $query->where('status', $status);
        }
        if (in_array($type, ManualPublication::TYPES, true)) {
            $query->where('type', $type);
        }
        if (in_array($platform, ManualPublicationAccount::PLATFORMS, true)) {
            $query->where('platform', $platform);
        }
        $assigneeId = (int) $request->query('assigned_user_id');
        if ($user->isAdmin() && $assigneeId > 0) {
            $query->where('assigned_user_id', $assigneeId);
        }
        $articleId = (int) $request->query('article_id');
        if ($articleId > 0) {
            $query->where('article_id', $articleId);
        }

        $publications = (clone $query)
            ->with(['persona:id,name', 'account:id,account_name,platform,custom_platform', 'assignee:id,name'])
            ->latest('id')
            ->paginate(20);

        $stats = [
            'total' => (clone $query)->count(),
            'ready' => (clone $query)->where('status', ManualPublication::STATUS_READY)->count(),
            'in_progress' => (clone $query)->where('status', ManualPublication::STATUS_IN_PROGRESS)->count(),
            'completed' => (clone $query)->where('status', ManualPublication::STATUS_COMPLETED)->count(),
        ];

        return response()->json([
            'data' => $publications,
            'stats' => $stats,
            'platforms' => ManualPublicationAccount::PLATFORMS,
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $publication = ManualPublication::query()->whereKey($id)->firstOrFail();
        if ($request->filled('project_id')) {
            $project = Project::query()->findOrFail((int) $request->query('project_id'));
            abort_unless($publication->article?->project_id === $project->id, 404);
        }
        $this->service->assertCan($user, $publication, 'view');

        $publication->load([
            'article:id,title,status,version',
            'persona:id,name,disclosure_text,is_active',
            'account:id,persona_id,platform,custom_platform,account_name,profile_url,is_active',
            'assignee:id,name',
            'creator:id,name',
            'transitions.actor:id,name',
        ]);

        return response()->json([
            'data' => $publication,
            'can_update' => $this->service->can($user, $publication, 'update'),
            'can_transition' => $this->service->can($user, $publication, 'transition'),
            'can_reopen' => $this->service->can($user, $publication, 'reopen'),
            'allowed_next' => ManualPublication::allowedNextStatuses((string) $publication->status),
            'duplicates' => $this->service->duplicatesFor($publication)
                ->filter(fn (ManualPublication $d): bool => $this->service->can($user, $d, 'view'))
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', ManualPublication::TYPES)],
            'article_id' => ['nullable', 'integer'],
            'article_version' => ['nullable', 'integer', 'min:1'],
            'persona_id' => ['required', 'integer'],
            'account_id' => ['nullable', 'integer'],
            'assigned_user_id' => ['nullable', 'integer'],
            'platform' => ['required', 'string', 'in:'.implode(',', ManualPublicationAccount::PLATFORMS)],
            'custom_platform' => ['nullable', 'string', 'max:64'],
            'target_url' => ['nullable', 'string', 'max:2000'],
            'target_context' => ['nullable', 'string', 'max:2000'],
            'content' => ['required', 'string', 'max:'.ManualPublication::MAX_POST_CONTENT_CHARACTERS],
            'scheduled_at' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:draft,ready'],
        ]);

        try {
            $publication = $this->service->create($validated, $user);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ManualPublicationConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'data' => $publication,
            'message' => __('admin.manual_publications.message.created'),
        ], Response::HTTP_CREATED);
    }

    public function fromArticle(Request $request, int $articleId, ProjectPublicationAccounts $accounts): JsonResponse
    {
        $validated = $request->validate([
            'account_id' => ['required', 'integer', 'min:1'],
            'article_version' => ['required', 'integer', 'min:1'],
        ]);
        $article = Article::query()->whereHas('project')->findOrFail($articleId);
        try {
            $result = $accounts->start($article, (int) $validated['account_id'], (int) $validated['article_version'], $request->user());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => $result['publication'],
            'created' => $result['created'],
            'message' => '发布单已准备好，请在目标平台确认发布并回填公开链接。',
        ], $result['created'] ? 201 : 200);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $publication = ManualPublication::query()->whereKey($id)->firstOrFail();

        $validated = $request->validate([
            'type' => ['sometimes', 'required', 'string', 'in:'.implode(',', ManualPublication::TYPES)],
            'article_id' => ['nullable', 'integer'],
            'persona_id' => ['sometimes', 'required', 'integer'],
            'account_id' => ['nullable', 'integer'],
            'assigned_user_id' => ['nullable', 'integer'],
            'platform' => ['sometimes', 'required', 'string', 'in:'.implode(',', ManualPublicationAccount::PLATFORMS)],
            'custom_platform' => ['nullable', 'string', 'max:64'],
            'target_url' => ['nullable', 'string', 'max:2000'],
            'target_context' => ['nullable', 'string', 'max:2000'],
            'content' => ['sometimes', 'required', 'string', 'max:'.ManualPublication::MAX_POST_CONTENT_CHARACTERS],
            'scheduled_at' => ['nullable', 'string'],
            'revision' => ['required', 'integer'],
        ]);

        try {
            $publication = $this->service->update(
                $publication,
                $request->except('revision'),
                (int) $validated['revision'],
                $user,
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ManualPublicationConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'data' => $publication,
            'message' => __('admin.manual_publications.message.updated'),
        ]);
    }

    public function transition(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $publication = ManualPublication::query()->whereKey($id)->firstOrFail();

        $validated = $request->validate([
            'target_status' => ['required', 'string', 'in:'.implode(',', ManualPublication::STATUSES)],
            'completion_url' => ['nullable', 'string', 'max:2000'],
            'result_note' => ['nullable', 'string', 'max:2000'],
            'revision' => ['required', 'integer'],
        ]);

        try {
            $publication = $this->service->transition(
                $publication,
                (string) $validated['target_status'],
                (int) $validated['revision'],
                $user,
                $validated['completion_url'] ?? null,
                $validated['result_note'] ?? null,
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ManualPublicationConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'data' => $publication,
            'message' => __('admin.manual_publications.message.transitioned'),
        ]);
    }

    public function duplicates(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $publication = ManualPublication::query()->whereKey($id)->firstOrFail();
        $this->service->assertCan($user, $publication, 'view');

        $duplicates = $this->service->duplicatesFor($publication)
            ->filter(fn (ManualPublication $d): bool => $this->service->can($user, $d, 'view'))
            ->values();

        return response()->json(['data' => $duplicates]);
    }

    public function recordZhihuDraft(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'draft_url' => ['required', 'string', 'max:2000'],
            'revision' => ['required', 'integer', 'min:1'],
        ]);

        $publication = ManualPublication::query()->whereKey($id)->firstOrFail();

        try {
            $publication = $this->service->recordZhihuDraft(
                $publication,
                (string) $validated['draft_url'],
                (int) $validated['revision'],
                $request->user(),
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ManualPublicationConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_CONFLICT);
        }

        return response()->json(['data' => $publication]);
    }

    public function draftPayload(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        $publication = ManualPublication::query()->findOrFail($id);
        try {
            $payload = $this->service->draftPayload($publication, (int) $validated['revision'], $request->user());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (ManualPublicationConflictException) {
            return response()->json(['message' => '发布单版本已变化，请刷新后重试。'], 409);
        }

        return response()->json(['data' => $payload]);
    }

    public function preparePlatformDraft(Request $request, int $id, PlatformPublicationDraftService $drafts): JsonResponse
    {
        $validated = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'publication_title' => ['sometimes', 'required', 'string', 'max:500'],
        ]);
        $publication = ManualPublication::query()->findOrFail($id);
        try {
            return response()->json(['data' => $drafts->prepare($publication, (int) $validated['revision'], $request->user(), $validated['publication_title'] ?? null)]);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (ManualPublicationConflictException) {
            return response()->json(['message' => '发布单版本已变化，请刷新后重试。'], 409);
        }
    }

    public function confirmPlatformDraft(Request $request, int $id, PlatformPublicationDraftService $drafts): JsonResponse
    {
        $validated = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'preparation_token' => ['required', 'string', 'max:4096'],
            'draft_label' => ['required', 'string', 'max:120'],
            'account_checked' => ['required', 'accepted'],
            'content_checked' => ['required', 'accepted'],
            'saved' => ['required', 'accepted'],
        ]);
        $publication = ManualPublication::query()->findOrFail($id);
        try {
            $saved = $drafts->confirm($publication, $validated, $request->user());

            return response()->json(['data' => $saved, 'message' => '已记录人工确认草稿，发布单仍为进行中；公开发布后请回填公开链接。']);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (ManualPublicationConflictException) {
            return response()->json(['message' => '发布单或账号已变化，请重新准备并核对。'], 409);
        }
    }

    public function browserExtension(BrowserExtensionPackage $package): Response
    {
        $bytes = $package->build();

        return response($bytes)->header('Content-Type', 'application/zip')
            ->header('Content-Disposition', 'attachment; filename="'.$package->filename().'"')
            ->header('X-Content-SHA256', hash('sha256', $bytes))->header('Cache-Control', 'private, no-store');
    }
}
