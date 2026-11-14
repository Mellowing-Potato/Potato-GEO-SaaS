<?php

namespace App\Services\Workflow;

use App\Models\AiVisibilityRun;
use App\Models\ArticlePublication;
use App\Models\ManualPublication;
use App\Models\Project;
use App\Models\PublishingChannel;
use App\Models\SkillExecution;
use App\Services\Enums\WorkflowStage;
use Illuminate\Support\Carbon;

/** 项目阶段与下一步建议共用的完成证据，不生成额外执行或计费记录。 */
final class ProjectCompletionEvidence
{
    /** @return list<string> 已完成的工作流能力，兼容旧技能执行与专用监测入口。 */
    public function completedSkillIds(Project $project): array
    {
        $completedSkillIds = SkillExecution::query()
            ->where('tenant_id', $project->tenant_id)
            ->where('project_id', $project->id)
            ->where('status', 'completed')
            ->whereNotIn('skill_id', ['yao-geo-effect-monitor', 'yao-geo-channel-publishing'])
            ->distinct()
            ->pluck('skill_id')
            ->all();

        // 专用业务入口以实际发布证据完成，不生成模型执行或计费记录。
        if ($this->firstPublishedAt($project) !== null) {
            $completedSkillIds[] = 'yao-geo-channel-publishing';
        }

        // 发布前的监测是基线，不是发布后的效果监测。assist 行只负责记账。
        if ($this->hasPostPublicationMonitoring($project)) {
            $completedSkillIds[] = WorkflowStage::Monitoring->skillIds()[0];
        }

        return array_values(array_unique($completedSkillIds));
    }

    /** 项目文章至少有一条状态为已发布、带公开访问地址的发布记录。 */
    public function firstPublishedAt(Project $project): ?Carbon
    {
        $articleScope = fn ($query) => $query
            ->where('tenant_id', $project->tenant_id)
            ->where('project_id', $project->id);

        $automatic = ArticlePublication::query()
            ->where('tenant_id', $project->tenant_id)
            ->whereHas('article', $articleScope)
            ->where('status', ArticlePublication::STATUS_PUBLISHED)
            ->whereHas('channel', fn ($query) => $query->where('type', '!=', PublishingChannel::TYPE_HOSTED_PAGE))
            ->whereNotNull('remote_url')
            ->where('remote_url', '!=', '')
            ->whereNotNull('published_at')
            ->min('published_at');

        $manual = ManualPublication::query()
            ->where('tenant_id', $project->tenant_id)
            ->whereHas('article', $articleScope)
            ->where('type', ManualPublication::TYPE_POST)
            ->where('status', ManualPublication::STATUS_COMPLETED)
            ->whereNotNull('completion_url')
            ->where('completion_url', '!=', '')
            ->whereNotNull('completed_at')
            ->min('completed_at');

        $dates = array_filter([$automatic, $manual]);

        return $dates === [] ? null : Carbon::parse(min($dates));
    }

    public function hasPostPublicationMonitoring(Project $project): bool
    {
        $publishedAt = $this->firstPublishedAt($project);
        if ($publishedAt === null) {
            return false;
        }

        if (AiVisibilityRun::query()
            ->where('tenant_id', $project->tenant_id)
            ->where('project_id', $project->id)
            ->where('status', AiVisibilityRun::STATUS_COMPLETED)
            ->where('completed_at', '>', $publishedAt)
            ->exists()) {
            return true;
        }

        return SkillExecution::query()
            ->where('tenant_id', $project->tenant_id)
            ->where('project_id', $project->id)
            ->where('skill_id', 'yao-geo-effect-monitor')
            ->where('status', 'completed')
            ->where('updated_at', '>', $publishedAt)
            ->exists();
    }
}
