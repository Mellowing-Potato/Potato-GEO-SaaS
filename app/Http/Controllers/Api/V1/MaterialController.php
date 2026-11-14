<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Author;
use App\Models\ImageLibrary;
use App\Models\KeywordLibrary;
use App\Models\Project;
use App\Models\TitleLibrary;
use App\Services\Billing\AssistUsageRecorder;
use App\Services\Api\IdempotencyService;
use App\Services\Content\MaterialLibraryService;
use App\Services\Content\TitleAiGenerationService;
use App\Services\Enums\SkillCategory;
use App\Support\TenantContext;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 素材库控制器（阶段 1.1）
 *
 * 路由前缀 /api/v1/materials，四类资源各自 CRUD：titles / keywords / images / authors。
 * 查看需 project:view，写入需 material:manage（admin / editor）。
 *
 * 注：路由模型绑定发生在 tenant 中间件之前（SubstituteBindings 在 api 组内先于路由中间件），
 * 故 TenantScope 在绑定时 TenantContext 尚未设置。所有按 id 的读/改/删均再手动校验租户归属，
 * 与项目内 ensureCurrentTenant* 守卫保持一致。
 */
class MaterialController extends Controller
{
    public function __construct(
        private readonly MaterialLibraryService $materials,
        private readonly TitleAiGenerationService $titleAi,
        private readonly AssistUsageRecorder $assistRecorder,
    ) {}

    private const RULES = [
        'title' => [
            'title' => 'required|string|max:500',
            'note' => 'nullable|string|max:2000',
            'category' => 'nullable|string|max:120',
        ],
        'keyword' => [
            'keyword' => 'required|string|max:255',
            'note' => 'nullable|string|max:2000',
            'category' => 'nullable|string|max:120',
            'search_volume' => 'nullable|integer|min:0',
        ],
        'image' => [
            'url' => 'required|url|max:2000',
            'title' => 'nullable|string|max:255',
            'alt' => 'nullable|string|max:255',
            'source' => 'nullable|string|max:120',
            'storage' => 'nullable|string|max:60',
        ],
        'author' => [
            'name' => 'required|string|max:255',
            'bio' => 'nullable|string|max:5000',
            'avatar_url' => 'nullable|url|max:2000',
            'external_link' => 'nullable|url|max:2000',
        ],
    ];

    // ─── 标题库 ───
    public function indexTitles(Request $request): JsonResponse
    {
        return $this->listType('title', $request);
    }

    public function storeTitle(Request $request): JsonResponse
    {
        return $this->createType('title', $request);
    }

    public function showTitle(TitleLibrary $title): JsonResponse
    {
        $this->ensureOwns($title);

        return $this->respondItem('title', $title);
    }

    public function updateTitle(Request $request, TitleLibrary $title): JsonResponse
    {
        return $this->updateType('title', $title, $request);
    }

    public function destroyTitle(TitleLibrary $title): JsonResponse
    {
        $this->ensureOwns($title);
        $this->materials->delete('title', $title->id);

        return response()->json(['message' => '标题素材已删除。']);
    }

    /**
     * 基于关键词批量 AI 生成标题并写入标题库（复刻 GEOFlow 标题 AI 生成）。
     *
     * 明确未发送或已回包但标题无效时使用模板；发送状态不明时暂缓交付。
     *
     * 扣费：真实模型调用走 AssistUsageRecorder 显式扣点。网关对 geo_saas 的 chat
     * completion 零扣款，此处不扣就是两头都不扣＝免费（2026-08-19 复盘：本接口自上线起
     * 一直漏扣）。project_id 因此是必填——扣费要挂到项目上，缺了就无处记账。
     * 模板兜底没调模型，不扣。
     */
    public function generateTitles(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => 'required|integer|min:1',
            'keywords' => 'required|array|min:1',
            'keywords.*' => 'required|string|max:120',
            'count' => 'nullable|integer|min:1|max:50',
            'style' => 'nullable|string|in:professional,attractive,seo,creative,question',
            'category' => 'nullable|string|max:120',
            'custom_prompt' => 'nullable|string|max:2000',
        ]);

        // Reject missing or cross-tenant projects before any supplier request.
        // The usage charger also checks ownership, but it runs after generation.
        $project = Project::query()->whereKey((int) $validated['project_id'])->first();
        if (! $project) {
            abort(404);
        }
        $this->ensureOwns($project);

        $attemptKey = $request->header('X-Idempotency-Key');
        if (! is_string($attemptKey) || ! preg_match('/^[A-Za-z0-9_.:-]{1,160}$/', $attemptKey)) {
            throw ValidationException::withMessages([
                'idempotency_key' => '批量标题生成需要有效的请求编号，重试请复用原编号。',
            ]);
        }
        $taskId = 'assist:'.hash('sha256', json_encode([
            TenantContext::id(), $request->user()->id, $project->id, 'batch-titles', $attemptKey,
        ], JSON_THROW_ON_ERROR));

        $result = app(\App\Services\Content\TitleGenerationCheckpoint::class)->run($request, fn () => $this->titleAi->generateTitles(
            $validated['keywords'],
            (int) ($validated['count'] ?? 5),
            $validated['style'] ?? 'professional',
            $validated['custom_prompt'] ?? '',
            attemptOptions: ['task_id' => $taskId, 'step_id' => 'generate'],
        ));

        if ($result['pending']) {
            if ($result['call_result'] !== null) {
                $this->assistRecorder->record($project->id, $request->user()->id, $result['call_result'],
                    'ai-assist-titles', '批量生成标题', SkillCategory::GeoContentProduction);
            }
            return response()->json([
                'message' => '结果未知，可手动重新执行；原请求未写入标题库，也不会自动重发。',
                'action' => 'idempotency_result_pending',
            ], (int) data_get($result['call_result']?->gateway, 'http_status') === 409 ? 409 : 503);
        }

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], 422);
        }

        if ($result['call_result'] !== null) {
            $charged = $this->assistRecorder->record(
                (int) $validated['project_id'],
                (int) $request->user()->id,
                $result['call_result'],
                'ai-assist-titles',
                '批量生成标题',
                SkillCategory::GeoContentProduction,
            );
            if ($charged === null) {
                return response()->json([
                    'message' => '模型结果已生成，用量记录待恢复；标题尚未写入。',
                    'action' => 'idempotency_result_pending',
                ], 503);
            }

        }

        $tenantId = TenantContext::id();
        $category = $validated['category'] ?? null;

        // The completed replay and title rows must commit together. If either
        // write fails, reentry uses the encrypted local result without another call.
        return DB::transaction(function () use ($result, $tenantId, $category, $request, $attemptKey): JsonResponse {
            $created = [];
            foreach ($result['titles'] as $title) {
                $created[] = TitleLibrary::query()->create([
                    'tenant_id' => $tenantId,
                    'title' => $title,
                    'category' => $category,
                ]);
            }

            $payload = [
                'object' => 'title_generation',
                'data' => array_map(static fn (TitleLibrary $t): array => $t->toArray(), $created),
                'fallback_used' => $result['fallback_used'],
                'fallback_reason' => $result['fallback_reason'],
                'count' => count($created),
            ];
            IdempotencyService::store(
                $attemptKey,
                $request->path(),
                IdempotencyService::requestHash($request->all()),
                $payload,
                201,
                IdempotencyService::currentTenantId(),
                IdempotencyService::currentUserId(),
            );

            return response()->json($payload, 201);
        });
    }

    // ─── 关键词库 ───
    public function indexKeywords(Request $request): JsonResponse
    {
        return $this->listType('keyword', $request);
    }

    public function storeKeyword(Request $request): JsonResponse
    {
        return $this->createType('keyword', $request);
    }

    public function showKeyword(KeywordLibrary $keyword): JsonResponse
    {
        $this->ensureOwns($keyword);

        return $this->respondItem('keyword', $keyword);
    }

    public function updateKeyword(Request $request, KeywordLibrary $keyword): JsonResponse
    {
        return $this->updateType('keyword', $keyword, $request);
    }

    public function destroyKeyword(KeywordLibrary $keyword): JsonResponse
    {
        $this->ensureOwns($keyword);
        $this->materials->delete('keyword', $keyword->id);

        return response()->json(['message' => '关键词素材已删除。']);
    }

    // ─── 图片库 ───
    public function indexImages(Request $request): JsonResponse
    {
        return $this->listType('image', $request);
    }

    public function storeImage(Request $request): JsonResponse
    {
        return $this->createType('image', $request);
    }

    public function showImage(ImageLibrary $image): JsonResponse
    {
        $this->ensureOwns($image);

        return $this->respondItem('image', $image);
    }

    public function updateImage(Request $request, ImageLibrary $image): JsonResponse
    {
        return $this->updateType('image', $image, $request);
    }

    public function destroyImage(ImageLibrary $image): JsonResponse
    {
        $this->ensureOwns($image);
        $this->materials->delete('image', $image->id);

        return response()->json(['message' => '图片素材已删除。']);
    }

    // ─── 作者库 ───
    public function indexAuthors(Request $request): JsonResponse
    {
        return $this->listType('author', $request);
    }

    public function storeAuthor(Request $request): JsonResponse
    {
        return $this->createType('author', $request);
    }

    public function showAuthor(Author $author): JsonResponse
    {
        $this->ensureOwns($author);

        return $this->respondItem('author', $author);
    }

    public function updateAuthor(Request $request, Author $author): JsonResponse
    {
        return $this->updateType('author', $author, $request);
    }

    public function destroyAuthor(Author $author): JsonResponse
    {
        $this->ensureOwns($author);
        $this->materials->delete('author', $author->id);

        return response()->json(['message' => '作者素材已删除。']);
    }

    // ─── 为技能 brief 抽取素材 ───
    public function pick(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'titles' => 'nullable|integer|min:0|max:50',
            'keywords' => 'nullable|integer|min:0|max:50',
            'authors' => 'nullable|integer|min:0|max:50',
            'category' => 'nullable|string|max:120',
        ]);

        $category = $validated['category'] ?? null;

        return response()->json([
            'object' => 'material_pick',
            'data' => [
                'titles' => $this->materials->pick('title', (int) ($validated['titles'] ?? 0), $category)->toArray(),
                'keywords' => $this->materials->pick('keyword', (int) ($validated['keywords'] ?? 0), $category)->toArray(),
                'authors' => $this->materials->pick('author', (int) ($validated['authors'] ?? 0), $category)->toArray(),
            ],
        ]);
    }

    // ─── 记录素材被使用（技能执行 / 文章引用后调用）───
    public function recordUsage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'refs' => 'required|array|min:1',
            'refs.*.type' => 'required|string|in:title,keyword,image,author',
            'refs.*.id' => 'required|integer|min:1',
        ]);

        $this->materials->recordUsage($validated['refs']);

        return response()->json(['message' => '已记录素材使用。']);
    }

    // ─── 内部辅助 ───
    private function listType(string $type, Request $request): JsonResponse
    {
        $items = $this->materials->list($type, $request->only(['category', 'q']));

        return response()->json([
            'object' => $type . '_list',
            'data' => $items->map(fn (Model $m): array => $m->toArray())->values(),
        ]);
    }

    private function createType(string $type, Request $request): JsonResponse
    {
        $data = $request->validate(self::RULES[$type]);
        $model = $this->materials->create($type, $data);

        return response()->json([
            'object' => $type,
            'data' => $model->toArray(),
        ], 201);
    }

    private function updateType(string $type, Model $model, Request $request): JsonResponse
    {
        $this->ensureOwns($model);

        $data = $request->validate($this->updateRules($type));
        $model = $this->materials->update($type, $model->id, $data);

        return response()->json([
            'object' => $type,
            'data' => $model->toArray(),
        ]);
    }

    private function respondItem(string $type, Model $model): JsonResponse
    {
        return response()->json([
            'object' => $type,
            'data' => $model->toArray(),
        ]);
    }

    /**
     * 手动校验租户归属（路由模型绑定时 TenantScope 尚未生效，与项目 ensureCurrentTenant* 一致）。
     */
    private function ensureOwns(Model $model): void
    {
        $tenantId = $model->tenant_id ?? null;

        if ($tenantId === null || (int) $tenantId !== (int) TenantContext::id()) {
            abort(404);
        }
    }

    private function updateRules(string $type): array
    {
        return collect(self::RULES[$type])
            ->map(fn (string $rule): string => str_replace('required|', 'sometimes|', $rule))
            ->all();
    }
}
