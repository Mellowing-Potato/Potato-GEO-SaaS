<?php

namespace App\Services\Content;

use App\Models\Article;
use App\Services\Admin\ActivityLogService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ArticleTrashService
{
    public function __construct(private readonly ActivityLogService $activityLog) {}

    public function retentionDays(): int
    {
        return max(1, (int) config('geo.lifecycle.project_trash_retention_days', 7));
    }

    public function purgeAt(Article $article): CarbonImmutable
    {
        $deletedAt = $article->deleted_at
            ? CarbonImmutable::instance($article->deleted_at)
            : CarbonImmutable::now();

        return $deletedAt->addDays($this->retentionDays());
    }

    public function isExpired(Article $article): bool
    {
        return CarbonImmutable::now()->greaterThanOrEqualTo($this->purgeAt($article));
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int,Article> */
    public function expiredArticles(?CarbonImmutable $now = null): \Illuminate\Database\Eloquent\Collection
    {
        $cutoff = ($now ?? CarbonImmutable::now())->subDays($this->retentionDays());

        return Article::withoutGlobalScopes()
            ->onlyTrashed()
            ->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('projects')
                ->whereColumn('projects.id', 'articles.project_id')
                ->whereNull('projects.deleted_at'))
            ->where('deleted_at', '<=', $cutoff)
            ->orderBy('id')
            ->get();
    }

    /** Permanently remove an expired article and its cascade-owned history/publication rows. */
    public function purge(Article $article): array
    {
        return DB::transaction(function () use ($article): array {
            $project = DB::table('projects')
                ->where('id', $article->project_id)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first(['id']);
            if (! $project) {
                throw new \DomainException('文章所属项目正在回收站中，改由项目清理任务统一处理。');
            }

            $article = Article::withoutGlobalScopes()
                ->onlyTrashed()
                ->whereKey($article->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->isExpired($article)) {
                throw new \DomainException('文章尚未超过保留期，不能清理。');
            }
            $result = [
                'article_id' => (int) $article->id,
                'title' => (string) $article->title,
                'project_id' => (int) $article->project_id,
                'deleted_at' => $article->deleted_at?->toISOString(),
                'purge_at' => $this->purgeAt($article)->toISOString(),
            ];

            $this->activityLog->record('article.purged', [
                'tenant_id' => (int) $article->tenant_id,
                'actor_type' => 'system',
                'actor_name' => 'geo:projects-purge',
                'target_type' => 'article',
                'target_id' => (int) $article->id,
                'details' => ['title' => (string) $article->title, 'project_id' => (int) $article->project_id],
            ]);

            $article->forceDelete();

            return $result;
        });
    }

    public function restore(Article $article): Article
    {
        return DB::transaction(function () use ($article): Article {
            $project = DB::table('projects')
                ->where('id', $article->project_id)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first(['id']);
            if (! $project) {
                throw new \DomainException('文章所属项目已删除，无法单独恢复。');
            }

            $locked = Article::withoutGlobalScopes()
                ->onlyTrashed()
                ->whereKey($article->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($this->isExpired($locked)) {
                throw new \DomainException('这篇文章已超过保留期，无法恢复。');
            }
            $locked->restore();

            return $locked->fresh();
        });
    }
}
