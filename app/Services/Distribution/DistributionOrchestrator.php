<?php

namespace App\Services\Distribution;

use App\Jobs\ProcessArticleDistributionJob;
use App\Models\Article;
use App\Models\ArticlePublication;
use App\Models\DistributionLog;
use App\Models\PublishingChannel;
use App\Models\SensitiveWordHit;
use App\Services\Publishing\ArticleRiskScanner;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Log;

class DistributionOrchestrator
{
    public function __construct(
        private readonly DistributionPublisherManager $manager,
        private readonly DistributionPayloadBuilder $payloadBuilder,
        private readonly DistributionRetryPolicy $retryPolicy,
        private readonly TaskDistributionChannelSelector $channelSelector,
    ) {}

    /**
     * Enqueue distribution for all active channels when an article is published.
     * This is the entry point from the article publish event.
     *
     * B2.4: Uses TaskDistributionChannelSelector to respect project-channel bindings.
     * If a project has bound channels, only those are used; otherwise full fan-out.
     *
     * ⚠️ **这是文章离开本平台、发到客户站点的唯一入口**，
     * 也是「平台承担过滤责任」这句话真正落地的地方（执行书 T6）。
     * 入队之前必须过合规闸门，见 complianceGate()。
     *
     * 返回结构是**结构化数组而不是裸列表**（2026-08-30 由 T6 改）：
     * 调用方 Article::triggerAutoDistribution 把整个调用包在 try/catch 里吞异常，
     * 所以「被合规拦住」只能靠返回值表达，不能靠抛异常。
     *
     * @return array{blocked: bool, reason: ?string, publications: array<int, ArticlePublication>}
     */
    public function enqueueForArticle(Article $article): array
    {
        $blocked = $this->complianceGate($article);
        if ($blocked !== null) {
            return $blocked;
        }

        // 已有成功发布记录的渠道不再重复入队
        $publishedChannelIds = ArticlePublication::query()
            ->where('article_id', $article->id)
            ->where('status', ArticlePublication::STATUS_PUBLISHED)
            ->pluck('publishing_channel_id');

        // B2.4: 按项目绑定筛选渠道，无绑定时回退到全部活跃渠道
        $projectId = $article->project_id;
        if ($projectId) {
            $channels = $this->channelSelector->channelsForProject($projectId)
                ->whereNotIn('id', $publishedChannelIds);
        } else {
            $channels = PublishingChannel::query()
                ->where('is_active', true)
                ->whereIn('type', [PublishingChannel::TYPE_WORDPRESS_REST, PublishingChannel::TYPE_HTTP_WEBHOOK])
                ->whereNotIn('id', $publishedChannelIds)
                ->get();
        }

        $enqueued = [];
        foreach ($channels as $channel) {
            $publication = ArticlePublication::query()->updateOrCreate(
                [
                    'article_id' => $article->id,
                    'publishing_channel_id' => $channel->id,
                ],
                [
                    'status' => ArticlePublication::STATUS_QUEUED,
                    'attempt_count' => 0,
                ],
            );

            $enqueued[] = $publication;
            $this->log('info', 'Distribution enqueued', $channel, $publication, $article, 'enqueued');

            // 投递到 distribution 队列由 Worker 消费
            ProcessArticleDistributionJob::dispatch(
                $publication->id,
                (int) ($article->tenant_id ?? TenantContext::id()),
            )->afterCommit();
        }

        return ['blocked' => false, 'reason' => null, 'publications' => $enqueued];
    }

    /**
     * 出站合规闸门（执行书 T6）。放行返回 null，拦下返回 enqueueForArticle 的拦截结果。
     *
     * 三条分支：
     *   - status = blocked  → **不入队**，写命中日志（T7）+ 分发日志 + 作者可见落点（T10）
     *   - status = warning  → 照常入队（warning 是提醒复核，不是红线）
     *   - 扫描本身抛异常    → **也不入队**（fail-close）
     *
     * fail-close 的理由跟 T1 一样：**读不到规则不等于内容干净。**
     * 词表读失败时放行，等于故障期间平台的过滤责任全部落空，
     * 而这一刻内容正要发到客户站点上去——代价不可逆。
     *
     * @return array{blocked: bool, reason: ?string, publications: array<int, ArticlePublication>}|null
     */
    public function complianceGate(Article $article): ?array
    {
        try {
            // 用 record() 而不是裸 scan()：顺手在 article_risk_scans 里留一条
            // trigger=distribute 的扫描记录，运营台按文章 ID 回溯时看得到。
            $scan = app(ArticleRiskScanner::class)->record($article, 'distribute');
        } catch (\Throwable $e) {
            $this->log(
                'error',
                'Distribution blocked: compliance scan failed',
                article: $article,
                event: 'compliance_scan_failed',
                context: ['error' => $e->getMessage()],
            );

            // 这条日志跟下面的 compliance_blocked 是两个不同的 event，
            // 运营能一眼分出「扫描挂了」和「真的命中了红线词」。
            return ['blocked' => true, 'reason' => 'scan_failed', 'publications' => []];
        }

        if ($scan->status !== 'blocked') {
            return null;
        }

        $matches = is_array($scan->matches) ? $scan->matches : [];
        $blockedMatches = array_values(array_filter(
            $matches,
            static fn (array $match): bool => ($match['severity'] ?? '') === 'blocked',
        ));

        $this->recordHits($article, $blockedMatches);

        $this->log(
            'warning',
            'Distribution blocked by sensitive word gate',
            article: $article,
            event: 'compliance_blocked',
            context: [
                'risk_scan_id' => $scan->getKey(),
                'match_count' => count($blockedMatches),
                // ⚠️ 这里**只记来源与类别，不记命中的词本身**。
                // distribution_logs 是租户侧能看到的分发日志，回显平台词等于泄露红线清单。
                // 要查具体是哪条词，运营台走 sensitive_word_hits（T7/T8）。
                'origins' => array_values(array_unique(array_map(
                    static fn (array $match): string => (string) ($match['origin'] ?? 'tenant'),
                    $blockedMatches,
                ))),
            ],
        );

        $this->markArticleBlocked($article);

        return ['blocked' => true, 'reason' => 'sensitive_word', 'publications' => []];
    }

    /**
     * 把出站命中写进 sensitive_word_hits（T7）。
     *
     * @param  array<int, array<string, mixed>>  $matches
     */
    private function recordHits(Article $article, array $matches): void
    {
        foreach ($matches as $match) {
            SensitiveWordHit::record([
                'tenant_id' => $article->tenant_id ?: TenantContext::id(),
                'article_id' => $article->getKey(),
                'word' => (string) ($match['word'] ?? ''),
                'severity' => (string) ($match['severity'] ?? 'blocked'),
                'origin' => (string) ($match['origin'] ?? SensitiveWordHit::ORIGIN_TENANT),
                'surface' => (string) ($match['field'] ?? 'content'),
                'context' => isset($match['snippet']) ? (string) $match['snippet'] : null,
                'trigger' => SensitiveWordHit::TRIGGER_DISTRIBUTE,
            ]);
        }
    }

    /**
     * 在文章上留一个作者看得见的落点（T10）。
     *
     * 不给这个落点的话，作者看到的是「稿子发布了但站点上没有」，
     * 他不知道要改稿，也无从改起——这条闸门对客户就是静默失败。
     *
     * 两点纪律：
     *   - 只写那一句固定文案，**不写命中的词**（平台红线词不回显给作者）
     *   - 用 saveQuietly 绕开模型事件：这里在 saved / updated 钩子的调用链里，
     *     再触发一轮 save 会递归回 triggerAutoDistribution
     */
    private function markArticleBlocked(Article $article): void
    {
        try {
            $article->flagged_sensitive = true;
            $article->distribution_blocked_reason = ArticleRiskScanner::PLATFORM_BLOCK_NOTICE;
            $article->saveQuietly();
        } catch (\Throwable $e) {
            Log::warning('Failed to mark article as distribution-blocked', [
                'article_id' => $article->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Process a single publication (called from queue job).
     */
    public function process(ArticlePublication $publication): array
    {
        $channel = $publication->channel;
        $article = $publication->article;

        if (! $channel || ! $channel->is_active || ! in_array($channel->type, [
            PublishingChannel::TYPE_WORDPRESS_REST,
            PublishingChannel::TYPE_HTTP_WEBHOOK,
        ], true)) {
            $publication->update([
                'status' => ArticlePublication::STATUS_FAILED,
                'last_error' => 'Channel is inactive or deleted.',
            ]);

            return ['ok' => false, 'message' => 'Channel inactive'];
        }

        // 出站合规闸门：重试 / 存量 publication 也可能命中平台红线词，必须在真实发送前再卡一次。
        // 自动分发在 enqueueForArticle 已卡过，这里重卡是防御（fail-safe），不会放行被拦的稿子。
        $block = $this->complianceGate($article);
        if ($block !== null && ($block['blocked'] ?? false)) {
            $publication->update([
                'status' => ArticlePublication::STATUS_FAILED,
                'last_error' => ArticleRiskScanner::PLATFORM_BLOCK_NOTICE,
            ]);

            return ['ok' => false, 'message' => 'blocked_by_sensitive_word'];
        }

        $publication->update([
            'status' => ArticlePublication::STATUS_PUBLISHING,
            'attempt_count' => ($publication->attempt_count ?? 0) + 1,
            'last_attempted_at' => now(),
        ]);

        $publisher = $this->manager->publisherFor($channel);

        try {
            $result = $publisher->publish($publication);
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'message' => $e->getMessage()];
            Log::warning('Distribution publish exception', [
                'publication_id' => $publication->id,
                'error' => $e->getMessage(),
            ]);
        }

        if ($result['ok'] ?? false) {
            $publication->update([
                'status' => ArticlePublication::STATUS_PUBLISHED,
                'remote_id' => $result['remote_id'] ?? null,
                'remote_url' => $result['remote_url'] ?? null,
                'published_at' => now(),
                'last_error' => null,
                'metadata' => array_merge($publication->metadata ?? [], [
                    'distribution_result' => $result,
                ]),
            ]);
            $this->log('info', 'Distribution synced', $channel, $publication, $article, 'synced', $result);

            return $result;
        }

        // Failure path — check retry policy
        $httpStatus = $result['http_status'] ?? null;
        if ($this->retryPolicy->isRetryable($publication->attempt_count, $httpStatus)) {
            $retryAt = $this->retryPolicy->nextRetryAt($publication->attempt_count);
            $publication->update([
                'status' => ArticlePublication::STATUS_QUEUED,
                'last_error' => $result['message'] ?? 'Unknown error (will retry)',
                'metadata' => array_merge($publication->metadata ?? [], [
                    'next_retry_at' => $retryAt->format('c'),
                    'retry_count' => $publication->attempt_count,
                ]),
            ]);
            $this->log('warning', 'Distribution failed, will retry', $channel, $publication, $article, 'failed_retryable', $result);
        } else {
            $publication->update([
                'status' => ArticlePublication::STATUS_FAILED,
                'last_error' => $result['message'] ?? 'Unknown error',
            ]);
            $this->log('error', 'Distribution failed (terminal)', $channel, $publication, $article, 'failed_terminal', $result);
        }

        return $result;
    }

    /**
     * Check health of a distribution channel.
     */
    public function checkHealth(PublishingChannel $channel): array
    {
        $publisher = $this->manager->publisherFor($channel);

        try {
            $result = $publisher->health($channel);
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'message' => $e->getMessage()];
        }

        $isOk = $result['ok'] ?? false;
        $threshold = (int) config('geo.distribution.channel_failure_threshold', 5);

        if ($isOk) {
            // 健康 → 连续失败计数归零
            $channel->update([
                'last_health_status' => 'healthy',
                'last_health_checked_at' => now(),
                'last_error_message' => null,
                'consecutive_failures' => 0,
            ]);
        } else {
            // 不健康 → 计数+1，达阈值才禁用
            $newCount = ($channel->consecutive_failures ?? 0) + 1;
            $shouldDisable = $newCount >= $threshold;

            $channel->update([
                'last_health_status' => 'unhealthy',
                'last_health_checked_at' => now(),
                'last_error_message' => $result['message'] ?? 'Unknown error',
                'consecutive_failures' => $newCount,
                'is_active' => $shouldDisable ? false : $channel->is_active,
            ]);
        }

        $this->log(
            $isOk ? 'info' : 'warning',
            'Health check: '.($isOk ? 'healthy' : 'unhealthy'),
            $channel,
            event: 'health_check',
            context: $result,
        );

        return $result;
    }

    /**
     * Write a distribution log entry.
     */
    private function log(
        string $level,
        string $message,
        ?PublishingChannel $channel = null,
        ?ArticlePublication $publication = null,
        ?Article $article = null,
        ?string $event = null,
        ?array $context = null,
    ): void {
        try {
            DistributionLog::write($level, $message, $channel, $publication, $article, $event, $context);
        } catch (\Throwable $e) {
            Log::warning('Failed to write distribution log', ['error' => $e->getMessage()]);
        }
    }
}
