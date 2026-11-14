<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ArticlePublication;
use App\Models\Article;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Services\Contracts\ProjectWorkflow;
use App\Services\Contracts\SkillRegistry;
use App\Services\Enums\WorkflowStage;
use App\Services\Distribution\ExternalTakedownService;
use App\Services\Content\ArticleTrashService;
use App\Services\Projects\ProjectTrashService;
use App\Services\Workflow\ProjectCompletionEvidence;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use App\Support\Concerns\RecordsSensitiveAction;
class ProjectController extends Controller
{
    use RecordsSensitiveAction;

    public function __construct(
        private ProjectWorkflow $workflow,
        private SkillRegistry $skillRegistry,
        private ProjectCompletionEvidence $completionEvidence,
        private ProjectTrashService $trash,
        private ExternalTakedownService $takedown,
        private ArticleTrashService $articleTrash,
    ) {}
    /**
     * GET /api/v1/projects
     * 获取当前租户的项目列表
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));
        $projects = Project::with('owner')
            ->withCount('stages')
            ->whereIn('status', ['active', 'completed'])
            ->latest('updated_at')
            ->paginate($perPage);

        return response()->json($projects);
    }

    /**
     * POST /api/v1/projects
     * 创建新项目，自动初始化六个阶段
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'brand_name' => 'nullable|string|max:255',
            'brand_url' => 'nullable|url|max:500',
            'product_description' => 'nullable|string|max:2000',
            'target_market' => 'nullable|string|max:255',
            'target_keywords' => 'nullable|array',
            'target_keywords.*' => 'string|max:120',
            'competitors' => 'nullable|array',
            'competitors.*' => 'string|max:255',
            'goals' => 'nullable|string|max:2000',
            'settings' => 'nullable|array',
        ]);

        $validated = $this->mergeProfileFieldsIntoSettings($validated);
        $validated['owner_id'] = $request->user()->id;
        $validated['current_stage'] = 0;
        $validated['progress'] = 0;

        $project = DB::transaction(function () use ($validated) {
            $project = Project::create($validated);

            // 自动创建六个阶段记录
            foreach (Project::STAGES as $stage) {
                ProjectStage::create([
                    'project_id' => $project->id,
                    'stage_index' => $stage['index'],
                    'stage_key' => $stage['key'],
                    'status' => $stage['index'] === 0 ? 'active' : 'pending',
                ]);
            }

            return $project;
        });

        return response()->json($project->load('stages'), 201);
    }

    /**
     * GET /api/v1/projects/{project}
     * 获取项目详情（含阶段）
     */
    public function show(Project $project): JsonResponse
    {
        $this->ensureCurrentTenantProject($project);

        $project->load(['stages', 'owner']);
        $project->setAttribute('workflow_recommendations', $this->workflowRecommendations($project));
        $project->setAttribute('current_stage_requirements', $this->workflow->getStageRequirements(
            $project,
            WorkflowStage::from($project->current_stage),
        ));

        return response()->json($project);
    }

    /**
     * PATCH /api/v1/projects/{project}
     * 更新项目基本信息
     */
    public function update(Request $request, Project $project): JsonResponse
    {
        $this->ensureCurrentTenantProject($project);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'brand_name' => 'nullable|string|max:255',
            'brand_url' => 'nullable|url|max:500',
            'product_description' => 'nullable|string|max:2000',
            'target_market' => 'nullable|string|max:255',
            'target_keywords' => 'nullable|array',
            'target_keywords.*' => 'string|max:120',
            'competitors' => 'nullable|array',
            'competitors.*' => 'string|max:255',
            'goals' => 'nullable|string|max:2000',
            'settings' => 'nullable|array',
        ]);

        $validated = $this->mergeProfileFieldsIntoSettings($validated, $project->settings ?? []);
        $project->update($validated);

        return response()->json($project);
    }

    /**
     * DELETE /api/v1/projects/{project}
     * 删除项目 = **进垃圾桶**（用户口径 2026-09-21，见 ProjectTrashService 头部注释）。
     *
     * 7 天内可恢复（POST /api/v1/projects/{project}/restore），期满由 geo:projects-purge
     * 执行连坐硬删；垃圾桶列表在 GET /api/v1/projects/trash。
     *
     * ⚠️ 调用流水（model_call_logs）**不删** —— 老板 2026-09-04 拍板：
     *    「不能因为客户删项目就连调用记录都查不到，这个是跟钱有关系的，必须有据可查。」
     *    真凶就是原来这里那句 `ModelCallLog::...->delete()`（硬删）。
     *    配套迁移 2026_09_04_000001 另外拆掉了 project_id 的级联外键，
     *    那是第二道闸：将来任何 forceDelete 或清理软删数据的任务都不会再连坐。
     *    （建表时那个级联其实从没生效过 —— Project 是 SoftDeletes，
     *      $project->delete() 不发 SQL DELETE。）
     */
    public function destroy(Project $project): JsonResponse
    {
        $this->ensureCurrentTenantProject($project);

        // 敏感操作审计（计划 §9.7）：删数据类动作必须留痕。
        // 进桶就记一条 project.trashed；「真删」在 ProjectTrashService 里另记 project.purged ——
        // 两条合起来才能回答"谁在什么时候把哪个项目删了、什么时候真的没了"。
        $this->auditSensitive('project.trashed', $project, [
            'name' => (string) $project->name,
            'articles' => (int) $project->articles()->count(),
        ]);

        // 口径（用户 2026-09-21 拍板）：**删项目必须先把外站内容撤下来**。
        // 顺序是硬要求 —— 撤稿要读 article_publications 里的 remote_id / remote_url，
        // 而进桶之后这些行就随文章一起被清掉了（到期清理）或至少进了不可见状态。
        // ⚠️ 外站调用放在事务之外：第三方慢/超时不该锁住本地库。
        // 撤稿失败的**不阻断删除**（理由见 ExternalTakedownService 头部），
        // 失败会留在回收站列表与审计里，可用 POST /projects/{id}/takedown 或命令重试。
        $this->takedown->takeDownForProject($project);

        $this->trash->trash($project);

        return response()->json(null, 204);
    }

    /**
     * GET /api/v1/projects/trash
     * 全局回收站当前聚合项目。到期但清理任务尚未处理的条目仍显示，恢复会被禁用。
     */
    public function trash(): JsonResponse
    {
        $projects = Project::onlyTrashed()
            ->withCount(['articles', 'skillExecutions'])
            ->orderBy('deleted_at')
            ->get()
            ->map(fn (Project $project): array => [
                'id' => $project->id,
                'key' => 'project:'.$project->id,
                'name' => $project->name,
                'brand_name' => $project->brand_name,
                'deleted_at' => optional($project->deleted_at)->toISOString(),
                'purge_at' => $this->trash->purgeAt($project)->toISOString(),
                'days_left' => $this->trash->daysLeft($project),
                'type' => 'project',
                'type_label' => '项目',
                'expired' => $this->trash->isExpired($project),
                'restorable' => ! $this->trash->isExpired($project),
                'articles_count' => (int) ($project->articles_count ?? 0),
                'skill_executions_count' => (int) ($project->skill_executions_count ?? 0),
                // 外站撤稿待办（§9.5）：客户删了项目、外站却没撤下来的，必须在他看得到的地方亮着，
                // 否则"删了"和"真的下线了"在他眼里是同一件事。
                'takedown' => $this->takedownSummary($project),
                'restore_url' => '/api/v1/projects/'.$project->id.'/restore',
            ]);

        $articles = Article::onlyTrashed()
            ->whereHas('project')
            ->with('project:id,name')
            ->orderBy('deleted_at')
            ->get()
            ->map(function (Article $article): array {
                $publications = $this->takedown->publicationsForArticle($article);
                $online = $publications->where('status', ArticlePublication::STATUS_PUBLISHED);
                $expired = $this->articleTrash->isExpired($article);

                return [
                    'id' => $article->id,
                    'key' => 'article:'.$article->id,
                    'type' => 'article',
                    'type_label' => '文章',
                    'name' => $article->title,
                    'project_name' => $article->project?->name,
                    'original_status' => $article->status,
                    'status_label' => $article->status_label,
                    'deleted_at' => $article->deleted_at?->toISOString(),
                    'purge_at' => $this->articleTrash->purgeAt($article)->toISOString(),
                    'days_left' => max(0, (int) ceil(now()->diffInSeconds($this->articleTrash->purgeAt($article), false) / 86400)),
                    'expired' => $expired,
                    'restorable' => ! $expired,
                    'restore_url' => '/api/v1/articles/'.$article->id.'/undelete',
                    'takedown' => [
                        'online' => $online->count(),
                        'failed' => $online->filter(fn (ArticlePublication $publication): bool => filled($publication->last_error))->count(),
                        'last_error' => (string) (optional($online->first(fn (ArticlePublication $publication): bool => filled($publication->last_error)))->last_error ?? ''),
                        'remote_urls' => $online->pluck('remote_url')->filter()->values()->all(),
                    ],
                ];
            });

        $rows = $projects->concat($articles)->sortBy('purge_at')->values();

        return response()->json([
            'data' => $rows,
            'retention_days' => $this->trash->retentionDays(),
        ]);
    }

    /**
     * POST /api/v1/projects/{project}/restore
     * 从垃圾桶恢复（保留期内）。过了保留期一律 410：恢复不了一个已经被清掉的项目。
     */
    public function restore(Project $project): JsonResponse
    {
        $this->ensureCurrentTenantProject($project);

        if (! $project->trashed()) {
            return response()->json([
                'restored' => false,
                'message' => '这个项目不在垃圾桶里。',
                'data' => $project->load('stages'),
            ]);
        }

        if ($this->trash->isExpired($project)) {
            return response()->json([
                'message' => sprintf('这个项目已超过 %d 天保留期，无法恢复。', $this->trash->retentionDays()),
            ], 410);
        }

        try {
            $result = $this->trash->restore($project);
        } catch (\DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 410);
        }

        return response()->json([
            'restored' => true,
            'resumed_task_ids' => $result['resumed_task_ids'],
            'message' => '项目已恢复。',
            'data' => $project->fresh()->load('stages'),
        ]);
    }

    /**
     * POST /api/v1/projects/{project}/takedown
     * 重试外站撤稿（用于"删项目时目标站正好掉线"这类情况）。
     */
    public function takedown(Project $project): JsonResponse
    {
        $this->ensureCurrentTenantProject($project);

        $result = $this->takedown->takeDownForProject($project);

        return response()->json([
            'message' => $result['attempted'] === 0
                ? '这个项目没有需要撤回的外站内容。'
                : sprintf(
                    '撤稿完成：成功 %d 条，仍失败 %d 条。',
                    $result['succeeded'],
                    count($result['failed']),
                ),
            'data' => $result,
        ]);
    }

    /**
     * 回收站行上的撤稿待办摘要。
     *
     * @return array{online:int,failed:int,last_error:?string,remote_urls:list<string>}
     */
    private function takedownSummary(Project $project): array
    {
        $publications = $this->takedown->publicationsForProject($project);
        $online = $publications->where('status', ArticlePublication::STATUS_PUBLISHED);

        return [
            'online' => $online->count(),
            'failed' => $online->filter(fn (ArticlePublication $row): bool => filled($row->last_error))->count(),
            'last_error' => (string) (optional($online->first(fn (ArticlePublication $row): bool => filled($row->last_error)))->last_error ?? ''),
            'remote_urls' => $online->pluck('remote_url')->filter()->values()->all(),
        ];
    }

    /**
     * GET /api/v1/projects/{project}/stages
     * 获取项目的所有阶段
     */
    public function stages(Project $project): JsonResponse
    {
        $this->ensureCurrentTenantProject($project);

        return response()->json($project->stages);
    }

    /**
     * POST /api/v1/projects/{project}/stages/{stage}/advance
     * 推进到下一阶段（委托给工作流服务，含 Skill 校验 + 事务 + 行锁）
     */
    public function advanceStage(Project $project, ProjectStage $stage): JsonResponse
    {
        $this->ensureCurrentTenantProject($project);

        // 验证 stage 归属当前 project（防止跨项目 / 跨租户访问）
        if ($stage->project_id !== $project->id) {
            abort(404);
        }

        if ($stage->stage_index !== $project->current_stage) {
            throw new \DomainException('只能推进当前阶段');
        }

        $result = $this->workflow->advance($project);

        return response()->json($project->fresh()->load('stages'));
    }

    /**
     * POST /api/v1/projects/{project}/stages/rollback
     * 回退到指定阶段（DAG 合法路径内）
     * Body: { "target_stage": 0-5, "reason": "可选原因" }
     */
    public function rollbackStage(Request $request, Project $project): JsonResponse
    {
        $this->ensureCurrentTenantProject($project);

        $validated = $request->validate([
            'target_stage' => 'required|integer|min:0|max:5',
            'reason' => 'nullable|string|max:500',
        ]);

        $targetStage = WorkflowStage::tryFrom($validated['target_stage']);

        if (!$targetStage) {
            return response()->json(['message' => '无效的目标阶段'], 422);
        }

        if (!$this->workflow->canRollback($project, $targetStage)) {
            return response()->json([
                'message' => '无法回退到该阶段，目标阶段不在合法回退路径内',
            ], 422);
        }

        $this->workflow->rollback($project, $targetStage, $validated['reason'] ?? '');

        return response()->json($project->fresh()->load('stages'));
    }

    /**
     * GET /api/v1/projects/{project}/timeline
     * 获取项目完整阶段时间线
     */
    public function timeline(Project $project): JsonResponse
    {
        $this->ensureCurrentTenantProject($project);

        return response()->json($this->workflow->getTimeline($project));
    }

    private function ensureCurrentTenantProject(Project $project): void
    {
        if ((int) $project->tenant_id !== (int) TenantContext::id()) {
            abort(404);
        }
    }

    private function mergeProfileFieldsIntoSettings(array $validated, array $existingSettings = []): array
    {
        $settings = array_merge($existingSettings, $validated['settings'] ?? []);
        $profileFields = [
            'product_description',
            'target_market',
            'target_keywords',
            'competitors',
            'goals',
        ];

        foreach ($profileFields as $field) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }

            $value = $validated[$field];
            if (is_array($value)) {
                $value = array_values(array_filter(array_map(
                    fn ($item) => trim((string) $item),
                    $value,
                ), fn ($item) => $item !== ''));
            } elseif (is_string($value)) {
                $value = trim($value);
            }

            $settings[$field] = $value;
            unset($validated[$field]);
        }

        $validated['settings'] = $settings;

        return $validated;
    }

    private function workflowRecommendations(Project $project): array
    {
        $completedSkillIds = $this->completionEvidence->completedSkillIds($project);

        return array_map(function (array $stage) use ($completedSkillIds) {
            $stageEnum = WorkflowStage::from($stage['index']);
            $skills = array_values(array_filter(array_map(
                fn (string $skillId) => $this->skillRegistry->findOrNull($skillId),
                $stageEnum->skillIds(),
            )));
            $recommended = $skills[0] ?? null;

            return [
                'stage_index' => $stage['index'],
                'stage_key' => $stage['key'],
                'stage_name' => $this->stageName($stageEnum),
                'description' => $this->stageDescription($stageEnum),
                'next_action' => $this->stageNextAction($stageEnum),
                'recommended_skill' => $recommended ? [
                    'id' => $recommended->id,
                    'name' => $recommended->name,
                    'category' => $recommended->category->value,
                    'category_label' => $recommended->category->label(),
                    'maturity' => $recommended->maturity,
                    'requires_web' => $recommended->requiresWeb,
                    'stage_index' => $recommended->stageIndex,
                    'description' => $recommended->description,
                    'scene_description' => $recommended->sceneDescription,
                    'effect_description' => $recommended->effectDescription,
                    'brief_fields' => $recommended->briefFields,
                ] : null,
                'completed_skill_ids' => array_values(array_intersect(
                    $completedSkillIds,
                    array_map(fn ($skill) => $skill->id, $skills),
                )),
            ];
        }, Project::STAGES);
    }

    private function stageName(WorkflowStage $stage): string
    {
        return match ($stage) {
            WorkflowStage::Research => '研究',
            WorkflowStage::Strategy => '策略',
            WorkflowStage::Production => '内容生产',
            WorkflowStage::Distribution => '渠道发布',
            WorkflowStage::Monitoring => '效果监测',
            WorkflowStage::Iteration => '迭代优化',
        };
    }

    private function stageDescription(WorkflowStage $stage): string
    {
        return match ($stage) {
            WorkflowStage::Research => '挖掘 AI 搜索意图、种子话题和竞品信号。',
            WorkflowStage::Strategy => '诊断 GEO 准备度，把发现转成可执行计划。',
            WorkflowStage::Production => '创建可被 AI 引用的内容和知识资产。',
            WorkflowStage::Distribution => '绑定本项目的平台账号或网站接口，发布审核通过的文章并保留公开链接。',
            WorkflowStage::Monitoring => '发布后追踪可见度、引用率和竞品变化；发布前结果仅作基线。',
            WorkflowStage::Iteration => '刷新薄弱内容，重复最高影响的工作。',
        };
    }

    private function stageNextAction(WorkflowStage $stage): string
    {
        return match ($stage) {
            WorkflowStage::Research => '先挖掘客户问题和关键词意图。',
            WorkflowStage::Strategy => '先做一次综合诊断，再生成执行路线图。',
            WorkflowStage::Production => '根据计划生成或优化一篇文章。',
            WorkflowStage::Distribution => '进入渠道发布绑定账号，从已审核文章准备发布单；没有官网也可使用第三方平台。',
            WorkflowStage::Monitoring => '发布后重新运行一次 AI 可见性监测。',
            WorkflowStage::Iteration => '优化最薄弱的已有内容，再重新跑一轮。',
        };
    }
}
