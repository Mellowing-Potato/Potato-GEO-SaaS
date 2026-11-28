<?php

namespace App\Services\Projects;

use App\Models\Article;
use App\Models\ModelCallLog;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\SkillExecution;
use App\Models\Task;
use App\Models\User;
use App\Services\Admin\ActivityLogService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 项目垃圾桶（计划 §9.5；用户口径 2026-09-21）。
 *
 * 用户原话：「客户删项目之后可以自动保留 7 天，7 天之后无法恢复，这里是不是应该设计垃圾桶」。
 * 所以"删除项目"分三步，这个类就是这三步的唯一实现：
 *
 *   1. trash()  —— 进垃圾桶：项目**软删**（deleted_at 有值、行还在），
 *                  文章 / 分析报告 / 阶段**原地保留**，项目下的自动化任务**暂停**；
 *                  调用流水照旧不删（跟钱有关的记录，2026-09-04 拍板）。
 *   2. restore()—— 7 天内恢复：把软删撤掉，并只把"进桶时被我们暂停的那几条"任务放回 active
 *                  （客户自己暂停过的任务不背锅复活）。
 *   3. purge()  —— 期满清理：执行原来的连坐硬删（文章 → 发布记录/版本/图片随外键级联，
 *                  技能执行、阶段、任务一起走），只剩 model_call_logs 与它的归属快照。
 *
 * 为什么必须暂停任务：tasks:tick 是"扫到点就派发"，不看项目死活。项目进桶后如果任务还在跑，
 * 客户会为一个"已经删掉的项目"持续扣积分 —— 那是钱的问题，不是体验问题。
 *
 * 为什么文章不就地删：它决定"能不能恢复"。真删了就只能恢复一个空壳项目。
 * 代价是进桶期间这些文章仍在本租户库里 —— 对外可见性由三处收口（文章列表 whereHas('project')、
 * 项目详情路由绑定、旧公读页路由统一返回 410）各自挡住，见各自的注释。
 */
final class ProjectTrashService
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * 对外键是 **RESTRICT**（而不是 CASCADE）的子表：清理项目前必须先把归属摘掉，否则
     * `forceDelete()` 会被数据库直接拒绝（FK violation），项目永远清不掉、定时任务天天报错。
     *
     * 为什么是"摘归属"而不是"跟着删"：这两张表（知识库、企业知识库项目）正是 §9.5 里
     * **保留期限还没拍板**的那一类数据。摘掉 project_id 之后知识库本身原样留着（客户还能看到），
     * 只是不再挂在那个已经不可恢复的项目下 —— 既让"7 天后无法恢复"真的成立，
     * 又没有替产品顺手决定"知识库该不该清"。
     *
     * 口径来源：2026-09-21 在**生产库**上按 `pg_constraint.confrelid='projects'` 实测，
     * 只有这两张是 RESTRICT（confdeltype='r'），其余（articles / skill_executions /
     * project_stages / tasks / url_imports / project_generation_preferences）都是 CASCADE。
     * 再验证一次：
     *   select c.conrelid::regclass, c.confdeltype from pg_constraint c
     *   where c.confrelid = 'projects'::regclass order by 1;
     *
     * @var list<string>
     */
    private const DETACH_BEFORE_PURGE = ['knowledge_bases', 'enterprise_knowledge_projects'];

    /** 垃圾桶保留天数（用户口径 7 天，可由 GEO_PROJECT_TRASH_RETENTION_DAYS 覆盖）。 */
    public function retentionDays(): int
    {
        return max(1, (int) config('geo.lifecycle.project_trash_retention_days', 7));
    }

    /** 项目进桶后的清理时刻（从 deleted_at 起算，与定时命令的判据同一个函数）。 */
    public function purgeAt(Project $project): CarbonImmutable
    {
        $deletedAt = $project->deleted_at ? CarbonImmutable::instance($project->deleted_at) : CarbonImmutable::now();

        return $deletedAt->addDays($this->retentionDays());
    }

    /** 还剩几天（向上取整，界面用；已过期返回 0）。 */
    public function daysLeft(Project $project): int
    {
        $left = CarbonImmutable::now()->diffInSeconds($this->purgeAt($project), false);

        return $left <= 0 ? 0 : (int) ceil($left / 86400);
    }

    public function isExpired(Project $project): bool
    {
        return CarbonImmutable::now()->greaterThanOrEqualTo($this->purgeAt($project));
    }

    /**
     * 删除 = 进垃圾桶。
     *
     * @return array{deleted_at:string,purge_at:string,paused_task_ids:array<int,int>,retention_days:int}
     */
    public function trash(Project $project, ?string $reason = null): array
    {
        return DB::transaction(function () use ($project, $reason): array {
            // Keep project deletion ordered with standalone article restore/purge operations.
            $project = Project::withoutGlobalScopes()->whereKey($project->id)->whereNull('deleted_at')->lockForUpdate()->firstOrFail();

            // 顺序红线（沿用 destroy 时期的注释）：快照必须排在技能执行被删除之前。
            // 进桶阶段不删技能执行，但"删项目要留得住这笔钱花在哪儿"这条不因垃圾桶而放松 ——
            // 快照在进桶时就写好，哪怕客户随后 7 天里把项目恢复又删一次，流水上也一直有归属。
            $this->snapshotCallLogAttribution($project);

            $pausedTaskIds = $this->pauseActiveTasks($project);

            $deletedAt = CarbonImmutable::now();
            $settings = $project->settings ?? [];
            $settings['trash'] = [
                'deleted_at' => $deletedAt->toISOString(),
                'purge_at' => $deletedAt->addDays($this->retentionDays())->toISOString(),
                'paused_task_ids' => $pausedTaskIds,
                'reason' => $reason,
            ];

            $project->forceFill(['settings' => $settings])->save();
            $project->delete();

            return [
                'deleted_at' => $deletedAt->toISOString(),
                'purge_at' => $deletedAt->addDays($this->retentionDays())->toISOString(),
                'paused_task_ids' => $pausedTaskIds,
                'retention_days' => $this->retentionDays(),
            ];
        });
    }

    /**
     * 出桶恢复。$project 必须是 withTrashed() 取到的实例（普通查询取不到）。
     *
     * @return array{paused_task_ids:array<int,int>,resumed_task_ids:array<int,int>}
     */
    public function restore(Project $project): array
    {
        return DB::transaction(function () use ($project): array {
            // 与期满清理共用行锁，避免清理任务和恢复请求同时操作同一项目。
            $project = Project::withoutGlobalScopes()->onlyTrashed()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            if ($this->isExpired($project)) {
                throw new \DomainException('这个项目已超过保留期，无法恢复。');
            }

            $settings = $project->settings ?? [];
            $pausedTaskIds = array_values(array_filter(array_map('intval', (array) data_get($settings, 'trash.paused_task_ids', []))));

            $resumed = [];
            if ($pausedTaskIds !== []) {
                // 只复活"进桶时由我们暂停的"那几条：客户自己按暂停的任务不进这个名单。
                $resumed = Task::withoutGlobalScopes()
                    ->where('tenant_id', $project->tenant_id)
                    ->where('project_id', $project->id)
                    ->whereIn('id', $pausedTaskIds)
                    ->whereNull('deleted_at')
                    ->where('status', Task::STATUS_PAUSED)
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->all();

                if ($resumed !== []) {
                    Task::withoutGlobalScopes()->whereIn('id', $resumed)->update(['status' => Task::STATUS_ACTIVE]);
                }
            }

            unset($settings['trash']);
            $project->forceFill(['settings' => $settings === [] ? null : $settings]);
            $project->restore();

            return ['paused_task_ids' => $pausedTaskIds, 'resumed_task_ids' => $resumed];
        });
    }

    /**
     * 期满清理：把项目连同它的内容真正删掉（调用流水与其归属快照保留）。
     *
     * @return array{project_id:int,name:string,articles:int,skill_executions:int,stages:int,tasks:int,detached:array<string,int>}
     */
    public function purge(Project $project): array
    {
        return $this->purgeInternal($project, false);
    }

    /** Account deletion has its own 15-day waiting period and must remove active projects too. */
    public function purgeForAccountDeletion(Project $project, User $user): array
    {
        if ((int) $project->tenant_id !== (int) $user->tenant_id
            || $user->deletion_purge_at === null
            || $user->deletion_purge_at->isFuture()) {
            throw new \DomainException('账户尚未到清理期，或项目不属于该账户租户。');
        }

        return $this->purgeInternal($project, true);
    }

    private function purgeInternal(Project $project, bool $accountDeletion): array
    {
        return DB::transaction(function () use ($project, $accountDeletion): array {
            // 防止过期判定后项目被并发恢复，再由旧实例继续硬删。
            $query = Project::withoutGlobalScopes()->withTrashed()->whereKey($project->id)->lockForUpdate();
            $project = $query->firstOrFail();
            if (! $accountDeletion && (! $project->trashed() || ! $this->isExpired($project))) {
                throw new \DomainException('项目尚未超过保留期，不能清理。');
            }

            // 快照重写一次：进桶时写过的那些行是同一个值（幂等），但直接 forceDelete 进桶于更早版本、
            // 快照字段为空的老数据时，这一步是唯一机会。
            $this->snapshotCallLogAttribution($project);

            $tenantId = (int) $project->tenant_id;
            $projectId = (int) $project->id;

            $articles = Article::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('project_id', $projectId)->count();
            $executions = SkillExecution::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('project_id', $projectId)->count();
            $stages = ProjectStage::query()->where('project_id', $projectId)->count();
            $tasks = Task::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('project_id', $projectId)->count();
            $detached = $this->detachRestrictingChildren($projectId);

            // 先断开关联指针，再删：外键顺序敏感（文章与技能执行互相引用）。
            SkillExecution::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('project_id', $projectId)->update(['article_id' => null]);
            Article::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('project_id', $projectId)->update(['skill_execution_id' => null]);

            Article::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('project_id', $projectId)->forceDelete();
            SkillExecution::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('project_id', $projectId)->delete();
            ProjectStage::query()->where('project_id', $projectId)->delete();

            // 项目本身硬删；tasks.project_id 是 cascadeOnDelete，任务行随这一步一起走
            // （进桶时它们已被暂停，所以不存在"清理期间又跑了一轮"）。
            $project->forceDelete();

            // 记一条"这个项目在什么时候被真删了"。
            // 为什么必须留：geo:privacy-purge 要按**删除时间**而不是行自己的 created_at 计时 ——
            // 一个三年前建的项目昨天刚删，按 created_at 判会当场被清掉（那等于删除当天就丢数据）。
            // 审计表本来就是"谁删了什么"的证据，这里顺带把它当保留期的计时起点。
            $this->activityLog->record('project.purged', [
                'tenant_id' => $tenantId,
                'actor_type' => 'system',
                'actor_name' => 'geo:projects-purge',
                'target_type' => 'project',
                'target_id' => $projectId,
                'details' => [
                    'name' => (string) $project->name,
                    'articles' => $articles,
                    'skill_executions' => $executions,
                    'stages' => $stages,
                    'tasks' => $tasks,
                    'detached' => $detached,
                ],
            ]);

            return [
                'project_id' => $projectId,
                'name' => (string) $project->name,
                'articles' => $articles,
                'skill_executions' => $executions,
                'stages' => $stages,
                'tasks' => $tasks,
                'detached' => $detached,
            ];
        });
    }

    /**
     * 摘掉 RESTRICT 子表的归属（见 DETACH_BEFORE_PURGE 的注释）。返回 {表名: 行数}。
     *
     * @return array<string,int>
     */
    private function detachRestrictingChildren(int $projectId): array
    {
        $detached = [];
        foreach (self::DETACH_BEFORE_PURGE as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'project_id')) {
                continue;
            }
            $rows = DB::table($table)->where('project_id', $projectId)->count();
            if ($rows === 0) {
                continue;
            }
            DB::table($table)->where('project_id', $projectId)->update(['project_id' => null]);
            $detached[$table] = $rows;
        }

        return $detached;
    }

    /**
     * 扫出所有已过期的桶内项目（跨租户：定时命令里没有租户上下文）。
     *
     * @return \Illuminate\Support\Collection<int,Project>
     */
    public function expiredProjects(?CarbonImmutable $now = null): \Illuminate\Support\Collection
    {
        $now ??= CarbonImmutable::now();
        $cutoff = $now->subDays($this->retentionDays());

        return Project::withoutGlobalScopes()
            ->onlyTrashed()
            ->whereNotNull('deleted_at')
            ->where('deleted_at', '<=', $cutoff)
            ->orderBy('id')
            ->get();
    }

    /**
     * 进桶时把项目下的自动化任务暂停掉。
     *
     * @return array<int,int> 被本方法暂停的任务 id（恢复时只复活这些）
     */
    private function pauseActiveTasks(Project $project): array
    {
        $ids = Task::withoutGlobalScopes()
            ->where('tenant_id', $project->tenant_id)
            ->where('project_id', $project->id)
            ->where('status', Task::STATUS_ACTIVE)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($ids !== []) {
            Task::withoutGlobalScopes()->whereIn('id', $ids)->update([
                'status' => Task::STATUS_PAUSED,
                'next_run_at' => null,
            ]);
        }

        return $ids;
    }

    /**
     * 把项目名与技能名快照进调用流水的 metadata。
     *
     * 流水本身留着，但用量明细读的是**关联对象**：`$log->project?->name` /
     * `$log->skillExecution?->skill_name`。项目一进桶（软删）这两个字段就查不到了 ——
     * 记录还在、界面上却看不出这笔钱是哪个项目、做什么花的。那不叫有据可查。
     *
     * 读取侧的回退在 BillingController::usage()，两处必须同时在。
     */
    private function snapshotCallLogAttribution(Project $project): void
    {
        $deletedAt = now()->toISOString();

        ModelCallLog::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->with(['skillExecution' => fn ($query) => $query->withoutGlobalScopes()->select('id', 'skill_id', 'skill_name')])
            ->chunkById(200, function ($logs) use ($project, $deletedAt): void {
                foreach ($logs as $log) {
                    $metadata = $log->metadata ?? [];
                    $metadata['project_name_snapshot'] = $project->name;
                    $metadata['skill_name_snapshot'] = $log->skillExecution?->skill_name
                        ?? $log->skillExecution?->skill_id;
                    $metadata['project_deleted_at'] = $deletedAt;
                    $log->forceFill(['metadata' => $metadata])->save();
                }
            });
    }
}
