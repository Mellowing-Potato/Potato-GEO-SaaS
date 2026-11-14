<?php

namespace App\Console\Commands;

use App\Services\Projects\ProjectTrashService;
use App\Services\Content\ArticleTrashService;
use Illuminate\Console\Command;

/**
 * 项目与独立删除文章的垃圾桶期满清理（默认保留 7 天）。
 *
 * 默认 **dry-run**：不写库，只列出"下一秒会被清掉哪些项目、每个带走多少内容"。
 * 真清理要显式 `--apply` —— 这是一条不可逆命令，默认值必须是安全的那个。
 *
 * 与删除接口的分工：
 *   DELETE /api/v1/projects/{id}  → 进桶（软删、可恢复）
 *   本命令（每天 03:40）           → 出桶不可逆：项目内容与独立删除文章真删，
 *                                   model_call_logs 与归属快照照旧保留（跟钱有关）。
 *
 * 判据（tests/Feature/ProjectTrashTest.php）：
 *   dry-run 一行不动；--apply 后到期项目与其内容真删、未到期项目一根汗毛都不动。
 */
class GeoProjectsPurgeCommand extends Command
{
    protected $signature = 'geo:projects-purge
        {--apply : 真的清理（默认只演练，不写库）}
        {--json : 机读输出}';

    protected $description = '清理超过垃圾桶保留期（默认 7 天）的项目与独立删除的文章；调用流水保留';

    public function handle(ProjectTrashService $trash, ArticleTrashService $articleTrash): int
    {
        $apply = (bool) $this->option('apply');
        $expired = $trash->expiredProjects();

        $results = [];
        $articleResults = [];
        $failed = [];
        foreach ($expired as $project) {
            if (! $apply) {
                $results[] = [
                    'project_id' => (int) $project->id,
                    'name' => (string) $project->name,
                    'deleted_at' => optional($project->deleted_at)->toISOString(),
                    'purge_at' => $trash->purgeAt($project)->toISOString(),
                ];

                continue;
            }

            try {
                $results[] = $trash->purge($project);
            } catch (\Throwable $e) {
                // 一个项目清不掉（例如将来又多了一张 RESTRICT 外键的表）不该让整条清理停摆：
                // 记下来、继续清剩下的，最后以非零退出码让运维看得见。
                $failed[] = ['project_id' => (int) $project->id, 'name' => (string) $project->name, 'error' => $e->getMessage()];
            }
        }

        // Projects purge first; re-read standalone article trash afterward so an article
        // cascaded with its project is not counted again from a stale pre-purge snapshot.
        $expiredArticles = $articleTrash->expiredArticles();
        foreach ($expiredArticles as $article) {
            if (! $apply) {
                $articleResults[] = [
                    'article_id' => (int) $article->id,
                    'title' => (string) $article->title,
                    'deleted_at' => optional($article->deleted_at)->toISOString(),
                    'purge_at' => $articleTrash->purgeAt($article)->toISOString(),
                ];

                continue;
            }

            try {
                $articleResults[] = $articleTrash->purge($article);
            } catch (\Throwable $e) {
                $failed[] = ['type' => 'article', 'article_id' => (int) $article->id, 'title' => (string) $article->title, 'error' => $e->getMessage()];
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'object' => 'geo_projects_purge',
                'mode' => $apply ? 'apply' : 'dry-run',
                'retention_days' => $trash->retentionDays(),
                'count' => count($results),
                'total_count' => count($results) + count($articleResults),
                'projects' => $results,
                'article_count' => count($articleResults),
                'articles' => $articleResults,
                'failed' => $failed,
            ], JSON_UNESCAPED_UNICODE));

            return $failed === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->info(sprintf(
            '%s：保留期 %d 天，命中 %d 个项目、%d 篇独立删除的文章。',
            $apply ? '清理完成' : '演练（未写库）',
            $trash->retentionDays(),
            count($results),
            count($articleResults),
        ));
        foreach ($results as $row) {
            $this->line($apply
                ? sprintf('  ✗ #%d %s：文章 %d、分析报告 %d、阶段 %d、任务 %d', $row['project_id'], $row['name'], $row['articles'], $row['skill_executions'], $row['stages'], $row['tasks'])
                : sprintf('  · #%d %s（%s 到期）', $row['project_id'], $row['name'], $row['purge_at']));
            if ($apply && ($row['detached'] ?? []) !== []) {
                foreach ($row['detached'] as $table => $count) {
                    $this->line(sprintf('      摘掉归属（保留数据，规则未定）：%s ×%d', $table, $count));
                }
            }
        }
        foreach ($articleResults as $row) {
            $this->line($apply
                ? sprintf('  ✗ 文章 #%d %s：已永久删除', $row['article_id'], $row['title'])
                : sprintf('  · 文章 #%d %s（%s 到期）', $row['article_id'], $row['title'], $row['purge_at']));
        }
        foreach ($failed as $row) {
            $this->error(isset($row['project_id'])
                ? sprintf('  ✗ #%d %s 清理失败：%s', $row['project_id'], $row['name'], $row['error'])
                : sprintf('  ✗ 文章 #%d %s 清理失败：%s', $row['article_id'], $row['title'], $row['error']));
        }
        if (! $apply && ($results !== [] || $articleResults !== [])) {
            $this->line('  真的清理请加 --apply。');
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
