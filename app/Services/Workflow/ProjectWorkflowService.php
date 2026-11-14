<?php

namespace App\Services\Workflow;

use App\Models\Project;
use App\Models\ProjectStage;
use App\Services\Contracts\ProjectWorkflow as ProjectWorkflowContract;
use App\Services\Contracts\SkillRegistry;
use App\Services\Enums\StageStatus;
use App\Services\Enums\WorkflowStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 项目工作流服务实现
 *
 * 管理 6 阶段 DAG 状态机的推进与回退。
 *
 * 架构文档 3.2 节定义的合法流转：
 *   正向：研究 → 策略 → 内容 → 发布 → 监测 → 迭代
 *   回退：策略 → 研究，内容 → 策略，发布 → 内容，迭代 → 研究/策略/内容
 */
class ProjectWorkflowService implements ProjectWorkflowContract
{
    public function __construct(
        private readonly SkillRegistry $skillRegistry,
        private readonly ProjectCompletionEvidence $completionEvidence,
    ) {}

    public function advance(Project $project): array
    {
        $currentStageEnum = WorkflowStage::from($project->current_stage);

        if (! $currentStageEnum->hasNext()) {
            // 已在最后一个阶段（迭代优化），标记项目完成
            return $this->completeProject($project);
        }

        return DB::transaction(function () use ($project, $currentStageEnum) {
            // 加锁防止并发推进（双击、重复请求）
            $currentStageModel = $project->stages()
                ->where('stage_index', $currentStageEnum->value)
                ->lockForUpdate()
                ->first();

            if (! $currentStageModel) {
                throw new \DomainException('当前阶段记录不存在');
            }

            // 在事务内重新校验前置条件（避免 TOCTOU 竞态）
            $requirements = $this->getStageRequirements($project, $currentStageEnum);
            if (! $requirements['met']) {
                throw new \DomainException(
                    '当前阶段未满足完成条件: ' . implode(', ', $requirements['requirements'])
                );
            }

            // 1. 完成当前阶段
            $currentStageModel->update([
                'status' => StageStatus::Completed->value,
                'progress' => 100,
                'completed_at' => now(),
            ]);

            // 2. 推进到下一阶段
            $nextStage = $currentStageEnum->next();
            $nextStageModel = $project->stages()
                ->where('stage_index', $nextStage->value)
                ->first();

            if (! $nextStageModel) {
                throw new \DomainException("下一阶段「{$nextStage->label()}」记录不存在，请检查项目初始化数据");
            }

            $nextStageModel->update([
                'status' => StageStatus::Active->value,
                'started_at' => now(),
            ]);

            // 3. 更新项目状态
            $totalStages = count(WorkflowStage::cases());
            $progress = (int) round((($nextStage->value) / $totalStages) * 100);

            $project->update([
                'current_stage' => $nextStage->value,
                'progress' => $progress,
            ]);

            Log::info('Project stage advanced', [
                'project_id' => $project->id,
                'from' => $currentStageEnum->label(),
                'to' => $nextStage->label(),
                'progress' => $progress,
            ]);

            return [
                'stage' => $nextStage->value,
                'stage_key' => $nextStage->key(),
                'stage_name' => $nextStage->label(),
                'progress' => $progress,
                'completed' => false,
            ];
        });
    }

    public function rollback(Project $project, WorkflowStage $targetStage, string $reason = ''): void
    {
        $currentStageEnum = WorkflowStage::from($project->current_stage);

        if (! $this->canRollback($project, $targetStage)) {
            throw new \DomainException(sprintf(
                '不能从 "%s" 回退到 "%s"，不在合法回退路径内',
                $currentStageEnum->label(),
                $targetStage->label(),
            ));
        }

        DB::transaction(function () use ($project, $currentStageEnum, $targetStage, $reason) {
            // 加锁防止并发回退 / 推进竞态
            $currentStageModel = $project->stages()
                ->where('stage_index', $currentStageEnum->value)
                ->lockForUpdate()
                ->first();

            if (! $currentStageModel) {
                throw new \DomainException('当前阶段记录不存在');
            }

            // 加锁后重新校验回退合法性（避免 TOCTOU 竞态）
            $freshCurrentStage = WorkflowStage::from($currentStageModel->stage_index);
            $legalTargets = $freshCurrentStage->legalRollbackTargets();
            if (! in_array($targetStage, $legalTargets, true)) {
                throw new \DomainException(sprintf(
                    '不能从 "%s" 回退到 "%s"，不在合法回退路径内',
                    $freshCurrentStage->label(),
                    $targetStage->label(),
                ));
            }

            // 1. 将当前阶段设为 pending（回退后需要重新执行）
            $currentStageModel->update([
                'status' => StageStatus::Pending->value,
                'progress' => 0,
                'started_at' => null,
                'completed_at' => null,
            ]);

            // 2. 将目标阶段激活（同样加锁）
            $targetStageModel = $project->stages()
                ->where('stage_index', $targetStage->value)
                ->lockForUpdate()
                ->first();

            if (! $targetStageModel) {
                throw new \DomainException('目标阶段记录不存在');
            }

            $targetStageModel->update([
                'status' => StageStatus::Active->value,
                'progress' => 0,
                'started_at' => now(),
                'completed_at' => null,
            ]);

            // 3. 中间阶段（如果有）也重置为 pending
            if ($currentStageEnum->value - $targetStage->value > 1) {
                $project->stages()
                    ->whereBetween('stage_index', [$targetStage->value + 1, $currentStageEnum->value - 1])
                    ->update([
                        'status' => StageStatus::Pending->value,
                        'progress' => 0,
                        'started_at' => null,
                        'completed_at' => null,
                    ]);
            }

            // 4. 更新项目状态
            $totalStages = count(WorkflowStage::cases());
            $progress = (int) round(($targetStage->value / $totalStages) * 100);

            $project->update([
                'current_stage' => $targetStage->value,
                'progress' => $progress,
                'status' => 'active', // 如果之前是 completed，重新激活
            ]);

            Log::info('Project stage rolled back', [
                'project_id' => $project->id,
                'from' => $currentStageEnum->label(),
                'to' => $targetStage->label(),
                'reason' => $reason,
            ]);
        });
    }

    public function canAdvance(Project $project): bool
    {
        if ($project->status === 'completed') {
            return false;
        }

        $currentStageEnum = WorkflowStage::from($project->current_stage);
        $requirements = $this->getStageRequirements($project, $currentStageEnum);

        return $requirements['met'];
    }

    public function canRollback(Project $project, WorkflowStage $targetStage): bool
    {
        $currentStageEnum = WorkflowStage::from($project->current_stage);

        // 不能回退到自己或更前的范围之外
        $legalTargets = $currentStageEnum->legalRollbackTargets();

        return in_array($targetStage, $legalTargets, true);
    }

    public function getStageRequirements(Project $project, WorkflowStage $stage): array
    {
        // 当前阶段的 stage model
        $stageModel = $project->stages()
            ->where('stage_index', $stage->value)
            ->first();

        $requirements = [];
        $met = true;

        // 检查 1: 阶段记录必须存在，且状态不能是 pending
        if (! $stageModel) {
            $requirements[] = '阶段记录不存在';
            $met = false;
        } elseif ($stageModel->status === StageStatus::Pending->value) {
            $requirements[] = '阶段尚未开始';
            $met = false;
        }

        // 检查 2: 如果有前置阶段，前置阶段必须已完成
        if ($stage->value > 0) {
            $previousStage = WorkflowStage::from($stage->value - 1);
            $previousModel = $project->stages()
                ->where('stage_index', $previousStage->value)
                ->first();

            if (! $previousModel || $previousModel->status !== StageStatus::Completed->value) {
                $requirements[] = "前置阶段「{$previousStage->label()}」尚未完成";
                $met = false;
            }
        }

        // 检查 3: 阶段关联的 Skill 是否有成功执行记录
        // 设计说明：MVP 阶段要求「至少一个」关联 Skill 执行成功即可推进，
        // 后续可改为「全部」Skill 必须执行。
        $skillIds = $stage->skillIds();
        if (!empty($skillIds)) {
            // 缓存 Skill 定义，避免重复查询 registry
            $registeredSkills = [];
            foreach ($skillIds as $id) {
                $skill = $this->skillRegistry->findOrNull($id);
                if ($skill !== null) {
                    $registeredSkills[$id] = $skill;
                }
            }

            if (empty($registeredSkills)) {
                // 所有 Skill ID 都未注册——可能是配置错误，记录警告但不阻塞
                Log::warning('All skill IDs unregistered for stage', [
                    'stage' => $stage->label(),
                    'skill_ids' => $skillIds,
                ]);
            } else {
                $registeredIds = array_keys($registeredSkills);

                $completedSkillIds = array_intersect(
                    $registeredIds,
                    $this->completionEvidence->completedSkillIds($project),
                );

                if (empty($completedSkillIds)) {
                    $skillNames = array_map(fn($skill) => $skill->name, array_values($registeredSkills));
                    $requirements[] = '需要完成该阶段的一项能力（' . implode('、', $skillNames) . '）';
                    $met = false;
                }
            }
        }

        if ($stage === WorkflowStage::Distribution && $this->completionEvidence->firstPublishedAt($project) === null) {
            $requirements[] = '需要一条关联本项目文章、带公开访问链接的外部发布记录（自动发布成功或手动发布单已完成）';
            $met = false;
        }

        if ($stage === WorkflowStage::Monitoring && ! $this->completionEvidence->hasPostPublicationMonitoring($project)) {
            $requirements[] = '需要在实际发布后完成一次 AI 可见性监测；发布前基线不计入效果监测';
            $met = false;
        }

        if ($stage === WorkflowStage::Iteration && ! $this->completionEvidence->hasPostPublicationMonitoring($project)) {
            $requirements[] = '项目完成前需要实际发布及发布后的 AI 可见性监测';
            $met = false;
        }

        return [
            'met' => $met,
            'requirements' => $requirements,
            'stage' => $stage->label(),
            'skills' => $stage->skillIds(),
        ];
    }

    public function getTimeline(Project $project): array
    {
        // 预加载所有 Skill 定义（避免 N+1 查询）
        $allSkills = collect($this->skillRegistry->all())->keyBy(fn($s) => $s->id);

        return $project->stages()
            ->orderBy('stage_index')
            ->get()
            ->map(function (ProjectStage $stage) use ($allSkills) {
                $stageEnum = WorkflowStage::from($stage->stage_index);

                return [
                    'stage_index' => $stage->stage_index,
                    'stage_key' => $stage->stage_key,
                    'stage_name' => $stageEnum->label(),
                    'status' => $stage->status,
                    'status_label' => StageStatus::from($stage->status)->label(),
                    'progress' => $stage->progress,
                    'started_at' => $stage->started_at?->toISOString(),
                    'completed_at' => $stage->completed_at?->toISOString(),
                    'skills' => array_map(
                        fn(string $id) => $allSkills->get($id)?->name ?? $id,
                        $stageEnum->skillIds(),
                    ),
                ];
            })
            ->toArray();
    }

    /**
     * 标记项目完成（所有阶段结束）
     */
    private function completeProject(Project $project): array
    {
        DB::transaction(function () use ($project) {
            $locked = Project::where('id', $project->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === 'completed') {
                throw new \DomainException('项目已完成');
            }

            $finalStage = $locked->stages()
                ->where('stage_index', WorkflowStage::Iteration->value)
                ->lockForUpdate()
                ->first();

            $requirements = $this->getStageRequirements($locked, WorkflowStage::Iteration);
            if (! $finalStage || $finalStage->status !== StageStatus::Active->value || ! $requirements['met']) {
                throw new \DomainException(
                    '当前阶段未满足完成条件: ' . implode(', ', $requirements['requirements'] ?: ['迭代优化阶段未开始'])
                );
            }

            $finalStage->update([
                'status' => StageStatus::Completed->value,
                'progress' => 100,
                'completed_at' => now(),
            ]);

            $locked->update([
                'status' => 'completed',
                'progress' => 100,
            ]);
        });

        Log::info('Project completed', ['project_id' => $project->id]);

        return [
            'stage' => WorkflowStage::Iteration->value,
            'stage_key' => 'iteration',
            'stage_name' => '迭代优化',
            'progress' => 100,
            'completed' => true,
        ];
    }
}
