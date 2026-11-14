<?php

namespace App\Console\Commands;

use App\Models\ArticlePublication;
use App\Models\Project;
use App\Services\Distribution\ExternalTakedownService;
use Illuminate\Console\Command;

/**
 * 外站撤稿重试（计划 §9.5；口径：删项目先撤稿，失败的留待办）。
 *
 * 默认 **dry-run**：只列"现在还有哪些外站内容在线、上次失败的原因是什么"。
 * 真撤要显式 `--apply` —— 这条命令会去改**别人家的网站**（删除文章），默认必须是只读的。
 *
 * 两种用法：
 *   php artisan geo:takedown-publications --project=12            # 看这个项目（含桶内）还剩几篇在线
 *   php artisan geo:takedown-publications --project=12 --apply     # 重试撤下来
 *   php artisan geo:takedown-publications --all-pending --apply    # 扫全站：所有 published 且上次失败过的
 */
class GeoTakedownPublicationsCommand extends Command
{
    protected $signature = 'geo:takedown-publications
        {--project= : 只处理这个项目（可以是回收站里的项目）}
        {--all-pending : 扫全站：所有仍在线且上次撤稿失败过的发布记录}
        {--apply : 真的执行撤稿（默认只列清单，不碰外站）}
        {--json : 机读输出}';

    protected $description = '外站撤稿：列出/重试仍在线（尤其上次失败）的发布记录；默认只读';

    public function handle(ExternalTakedownService $takedown): int
    {
        $apply = (bool) $this->option('apply');
        $projectId = (int) ($this->option('project') ?: 0);

        if ($projectId === 0 && ! $this->option('all-pending')) {
            $this->error('请给 --project=<id>，或用 --all-pending 扫全站。默认不猜范围。');

            return self::INVALID;
        }

        $results = [];

        if ($projectId > 0) {
            $project = Project::withoutGlobalScopes()->withTrashed()->find($projectId);
            if ($project === null) {
                $this->error("找不到项目 #{$projectId}");

                return self::FAILURE;
            }

            $pending = $takedown->pendingForProject($project);
            if (! $apply) {
                foreach ($pending as $publication) {
                    $results[] = $this->describe($publication);
                }
            } else {
                $results[] = ['project_id' => $project->id, 'name' => (string) $project->name] + $takedown->takeDownForProject($project);
            }
        } else {
            $pending = ArticlePublication::withoutGlobalScopes()
                ->with('channel:id,name')
                ->where('status', ArticlePublication::STATUS_PUBLISHED)
                ->whereNotNull('last_error')
                ->orderBy('id')
                ->get();

            foreach ($pending as $publication) {
                $results[] = $apply
                    ? ['id' => (int) $publication->id] + $takedown->takeDown($publication)
                    : $this->describe($publication);
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'object' => 'geo_takedown_publications',
                'mode' => $apply ? 'apply' : 'dry-run',
                'count' => count($results),
                'items' => $results,
            ], JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info(sprintf('%s：命中 %d 条。', $apply ? '撤稿执行完成' : '演练（未碰外站）', count($results)));
        foreach ($results as $row) {
            $this->line('  '.json_encode($row, JSON_UNESCAPED_UNICODE));
        }
        if (! $apply && $results !== []) {
            $this->line('  真撤请加 --apply（会去目标站删文章）。');
        }

        return self::SUCCESS;
    }

    /** @return array<string,mixed> */
    private function describe(ArticlePublication $publication): array
    {
        return [
            'id' => (int) $publication->id,
            'article_id' => (int) $publication->article_id,
            'channel' => (string) ($publication->channel?->name ?? '未知渠道'),
            'remote_url' => (string) ($publication->remote_url ?? ''),
            'last_error' => (string) ($publication->last_error ?? ''),
        ];
    }
}
