<?php

namespace App\Services\Distribution;

use App\Models\Article;
use App\Models\ArticlePublication;
use App\Models\Project;
use App\Services\Admin\ActivityLogService;
use Illuminate\Support\Collection;

/**
 * 外站撤稿（计划 §9.5；口径由用户 2026-09-21 拍板：「做吧」= 删项目必须先把外站内容撤下来）。
 *
 * 为什么这件事必须和"删项目"绑在一起：
 *   article_publications 是**本地**的发布记录；客户 WordPress / Agent 频道上那篇是**外站**的实体。
 *   项目一进垃圾桶再到期清理，本地记录随文章级联消失、连 remote_url 都找不回 ——
 *   而外站那篇还在线。客户问"我项目都删了怎么文章还在"，我们连撤回地址都得靠猜。
 *   所以删除流程的第一步就是撤：先撤、再进桶。
 *
 * 两条设计取舍（都写在这里，免得后人以为漏了）：
 *   1. **撤稿失败不阻断删除**。客户要的是"这个项目从我这儿消失"，而 WordPress 掉线、应用密码过期、
 *      目标站限流都会让撤稿失败；把删除卡在一个第三方故障后面，等于让客户替别人的可用性背锅。
 *      失败会：写 last_error + 记审计 + 在回收站列表里亮出来 + 可用命令/接口重试。
 *   2. **撤稿成功 = 本地记录转 takedown 状态，remote_url 保留**。保留地址是为了事后核查
 *      （客户问"撤了没"，我们要答得出撤的是哪一篇）；不保留正文，正文随项目清理一起走。
 *
 * 历史站内托管页已统一返回 410；这里仅处理仍可用的外站发布目标。
 */
final class ExternalTakedownService
{
    public function __construct(
        private readonly DistributionPublisherManager $publishers,
        private readonly ActivityLogService $activityLog,
    ) {}

    /** 项目下"还在线"的发布记录（published 状态）。 */
    public function pendingForProject(Project $project): Collection
    {
        return $this->publicationsForProject($project)
            ->where('status', ArticlePublication::STATUS_PUBLISHED)
            ->values();
    }

    /**
     * 撤一个项目下的全部在线发布记录。
     *
     * @return array{attempted:int,succeeded:int,failed:list<array{id:int,channel:string,remote_url:string,error:string}>,kept:int}
     */
    public function takeDownForProject(Project $project): array
    {
        $failed = [];
        $succeeded = 0;
        $pending = $this->pendingForProject($project);

        foreach ($pending as $publication) {
            $result = $this->takeDown($publication);
            if ($result['ok']) {
                $succeeded++;

                continue;
            }

            $failed[] = [
                'id' => (int) $publication->id,
                'channel' => (string) ($publication->channel?->name ?? '未知渠道'),
                'remote_url' => (string) ($publication->remote_url ?? ''),
                'error' => $result['message'],
            ];
        }

        return [
            'attempted' => $pending->count(),
            'succeeded' => $succeeded,
            'failed' => $failed,
            // 本地还留着多少条"在线"记录（撤稿失败的那些），给回收站列表与命令当待办口径。
            'kept' => count($failed),
        ];
    }

    /** 撤下一篇文章的所有在线外站发布记录。 */
    public function takeDownForArticle(Article $article): array
    {
        $failed = [];
        $succeeded = 0;
        $pending = $this->publicationsForArticle($article)
            ->where('status', ArticlePublication::STATUS_PUBLISHED)
            ->values();

        foreach ($pending as $publication) {
            $result = $this->takeDown($publication);
            if ($result['ok']) {
                $succeeded++;
                continue;
            }
            $failed[] = [
                'id' => (int) $publication->id,
                'channel' => (string) ($publication->channel?->name ?? '未知渠道'),
                'remote_url' => (string) ($publication->remote_url ?? ''),
                'error' => $result['message'],
            ];
        }

        return ['attempted' => $pending->count(), 'succeeded' => $succeeded, 'failed' => $failed, 'kept' => count($failed)];
    }

    /**
     * 撤一条发布记录。
     *
     * @return array{ok:bool,message:string}
     */
    public function takeDown(ArticlePublication $publication): array
    {
        $channel = $publication->channel;
        if ($channel === null) {
            return $this->recordFailure($publication, '发布渠道已被删除，无法撤稿（需要人工去外站处理）。');
        }

        try {
            $result = $this->publishers->publisherFor($channel)->delete($publication);
        } catch (\Throwable $e) {
            return $this->recordFailure($publication, '撤稿异常：'.$e->getMessage());
        }

        if (! ($result['ok'] ?? false)) {
            return $this->recordFailure($publication, (string) ($result['message'] ?? '撤稿失败'));
        }

        $metadata = $publication->metadata ?? [];
        $metadata['takedown'] = [
            'at' => now()->toISOString(),
            'channel' => (string) $channel->name,
            'remote_url' => (string) ($publication->remote_url ?? ''),
        ];

        $publication->forceFill([
            'status' => ArticlePublication::STATUS_TAKEDOWN,
            'last_error' => null,
            'last_attempted_at' => now(),
            'metadata' => $metadata,
        ])->save();

        $this->activityLog->record('publication.takedown_succeeded', [
            'tenant_id' => $publication->tenant_id,
            'actor_type' => 'system',
            'actor_name' => 'takedown',
            'target_type' => 'article_publication',
            'target_id' => $publication->id,
            'details' => [
                'channel' => (string) $channel->name,
                'remote_url' => (string) ($publication->remote_url ?? ''),
            ],
        ]);

        return ['ok' => true, 'message' => '已撤下外站内容'];
    }

    /** 项目下所有发布记录（含已撤/失败的），回收站列表与命令共用。 */
    public function publicationsForProject(Project $project): Collection
    {
        $articleIds = Article::withoutGlobalScopes()
            ->where('tenant_id', $project->tenant_id)
            ->where('project_id', $project->id)
            ->pluck('id');

        if ($articleIds->isEmpty()) {
            return collect();
        }

        return ArticlePublication::withoutGlobalScopes()
            // ⚠️ 渠道必须整行读出来：撤稿要走 publisher，而 publisher 需要 endpoint_url /
            //    凭据相关字段。只 select(:id,name,type) 会让 URL 变成空串，
            //    报出来的是 "The scheme "" is not allowed"（实测踩过），看着像配置错、其实是字段被裁掉了。
            ->with('channel')
            ->whereIn('article_id', $articleIds)
            ->orderBy('id')
            ->get();
    }

    /** 一篇文章下所有发布记录，包含撤稿失败项供回收站继续展示与重试。 */
    public function publicationsForArticle(Article $article): Collection
    {
        return ArticlePublication::withoutGlobalScopes()
            ->with('channel')
            ->where('tenant_id', $article->tenant_id)
            ->where('article_id', $article->id)
            ->orderBy('id')
            ->get();
    }

    /** @return array{ok:false,message:string} */
    private function recordFailure(ArticlePublication $publication, string $message): array
    {
        $publication->forceFill([
            'last_error' => $message,
            'last_attempted_at' => now(),
        ])->save();

        $this->activityLog->record('publication.takedown_failed', [
            'tenant_id' => $publication->tenant_id,
            'actor_type' => 'system',
            'actor_name' => 'takedown',
            'target_type' => 'article_publication',
            'target_id' => $publication->id,
            'details' => [
                'channel' => (string) ($publication->channel?->name ?? '未知渠道'),
                'remote_url' => (string) ($publication->remote_url ?? ''),
                'error' => $message,
            ],
        ]);

        return ['ok' => false, 'message' => $message];
    }
}
