<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\RunTaskJob;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskRun;
use App\Services\Workflow\TaskOrchestrationService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Validation\ValidationException;

/**
 * 任务自动化编排控制器（阶段 1.4）
 *
 * 路由前缀 /api/v1/tasks，所有写操作需 task:manage（admin / editor）。
 * 模型绑定在租户作用域为空时按 ID 全局解析，因此每个方法用 ensureOwns() 做租户归属校验（404）。
 */
class TaskController extends Controller
{
    public function __construct(
        private readonly TaskOrchestrationService $orchestration,
    ) {}

    private const CADENCES = [
        Task::CADENCE_HOURLY,
        Task::CADENCE_DAILY,
        Task::CADENCE_WEEKLY,
        Task::CADENCE_MONTHLY,
    ];

    // ─── 列表 ───
    public function index(): JsonResponse
    {
        $tasks = Task::query()->orderByDesc('id')->get();

        return response()->json([
            'object' => 'task_list',
            'data' => $tasks->map(fn (Task $t): array => $t->toArray())->values(),
        ]);
    }

    // ─── 创建 ───
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:191',
            'skill_id' => 'required|string|max:191',
            'project_id' => 'required|integer',
            'cadence' => 'nullable|in:' . implode(',', self::CADENCES),
            'timezone' => 'nullable|string|max:64',
            'query' => 'nullable|string',
            'input_data' => 'nullable|array',
            'auto_publish' => 'nullable|boolean',
            'auto_distribute' => 'nullable|boolean',
        ]);

        $project = $this->projectForCurrentTenant((int) $validated['project_id']);

        $cadence = $validated['cadence'] ?? Task::CADENCE_DAILY;
        $timezone = $validated['timezone'] ?? Task::DEFAULT_TIMEZONE;

        $task = Task::create([
            'project_id' => $project->id,
            'user_id' => $request->user()->id,
            'name' => $validated['name'],
            'skill_id' => $validated['skill_id'],
            'cadence' => $cadence,
            'timezone' => $timezone,
            'query' => $validated['query'] ?? null,
            'input_data' => $validated['input_data'] ?? null,
            'auto_publish' => (bool) ($validated['auto_publish'] ?? false),
            'auto_distribute' => (bool) ($validated['auto_distribute'] ?? true),
            'status' => Task::STATUS_ACTIVE,
            'next_run_at' => Task::computeNextRunAtFor($cadence, $timezone),
        ]);

        return response()->json([
            'object' => 'task',
            'data' => $task->toArray(),
        ], Response::HTTP_CREATED);
    }

    // ─── 详情 ───
    public function show(Task $task): JsonResponse
    {
        $this->ensureOwns($task);

        return response()->json([
            'object' => 'task',
            'data' => $task->load('runs')->toArray(),
        ]);
    }

    // ─── 更新 ───
    public function update(Request $request, Task $task): JsonResponse
    {
        $this->ensureOwns($task);

        $validated = $request->validate([
            'name' => 'nullable|string|max:191',
            'skill_id' => 'nullable|string|max:191',
            'cadence' => 'nullable|in:' . implode(',', self::CADENCES),
            'timezone' => 'nullable|string|max:64',
            'query' => 'nullable|string',
            'input_data' => 'nullable|array',
            'auto_publish' => 'nullable|boolean',
            'auto_distribute' => 'nullable|boolean',
        ]);

        $task->fill($validated);

        // 节奏 / 时区变化 → 重新计算下次运行时间
        if (array_key_exists('cadence', $validated) || array_key_exists('timezone', $validated)) {
            $task->next_run_at = Task::computeNextRunAtFor($task->cadence, $task->timezone);
        }

        $task->save();

        return response()->json([
            'object' => 'task',
            'data' => $task->toArray(),
        ]);
    }

    // ─── 删除（软删除）───
    public function destroy(Task $task): JsonResponse
    {
        $this->ensureOwns($task);

        $task->delete();

        return response()->json(['message' => '任务已删除。']);
    }

    // ─── 立即运行（入队）───
    public function runNow(Task $task): JsonResponse
    {
        $this->ensureOwns($task);

        RunTaskJob::dispatch($task->tenant_id, $task->id, TaskRun::TRIGGER_MANUAL);

        return response()->json(['message' => '任务已入队执行。']);
    }

    // ─── 暂停 ───
    public function pause(Task $task): JsonResponse
    {
        $this->ensureOwns($task);

        $task->update(['status' => Task::STATUS_PAUSED]);

        return response()->json([
            'object' => 'task',
            'data' => $task->toArray(),
        ]);
    }

    // ─── 恢复 ───
    public function resume(Task $task): JsonResponse
    {
        $this->ensureOwns($task);

        $task->update([
            'status' => Task::STATUS_ACTIVE,
            'next_run_at' => Task::computeNextRunAtFor($task->cadence, $task->timezone),
        ]);

        return response()->json([
            'object' => 'task',
            'data' => $task->toArray(),
        ]);
    }

    // ─── 运行记录列表 ───
    public function runs(Task $task): JsonResponse
    {
        $this->ensureOwns($task);

        $runs = $task->runs()->with('article:id,title')->orderByDesc('id')->get();

        return response()->json([
            'object' => 'task_run_list',
            'data' => $runs->map(fn (TaskRun $r): array => $r->toArray())->values(),
        ]);
    }

    // ─── 集合级运行流水（最近运行面板）───
    public function runsCollection(Request $request): JsonResponse
    {
        $runs = TaskRun::query()
            ->with('article:id,title')
            ->orderByDesc('id')
            ->limit((int) $request->integer('limit', 30))
            ->get();

        return response()->json([
            'object' => 'task_run_list',
            'data' => $runs->map(fn (TaskRun $r): array => $r->toArray())->values(),
        ]);
    }

    // ─── 内部辅助 ───
    private function ensureOwns(Task $task): void
    {
        if ((int) $task->tenant_id !== (int) TenantContext::id()) {
            abort(Response::HTTP_NOT_FOUND);
        }
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
}
