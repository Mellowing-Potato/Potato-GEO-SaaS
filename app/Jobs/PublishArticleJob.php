<?php

namespace App\Jobs;

use App\Models\ArticlePublication;
use App\Models\PublishingChannel;
use App\Models\Tenant;
use App\Services\Publishing\ArticlePublicationService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 异步文章发布队列任务
 *
 * 从队列消费后还原租户上下文，调用 ArticlePublicationService::executePublish()
 * 完成实际的外部渠道发送，并更新 article_publications 记录状态。
 *
 * 模式参照 ExecuteSkillJob：
 *   1. 还原 TenantContext
 *   2. 检查 publication 是否已被取消
 *   3. CAS 更新状态 queued → publishing
 *   4. 调用 service 执行发送
 *   5. 更新最终状态
 */
class PublishArticleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 超时时间（秒），外部 HTTP 请求通常 15s，留足余量 */
    public int $timeout = 60;

    /** 最大尝试次数（发布失败后由用户手动重试，不自动重跑） */
    public int $tries = 1;

    /**
     * @param  int  $publicationId  article_publications 记录 ID
     * @param  int  $tenantId       租户 ID（用于队列 Worker 还原 TenantContext）
     */
    public function __construct(
        public readonly int $publicationId,
        public readonly int $tenantId,
    ) {
        $this->onQueue('publishing');
    }

    public function handle(ArticlePublicationService $publicationService): void
    {
        // ─── 1. 还原租户上下文 ───
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            Log::error('PublishArticleJob: tenant not found', [
                'publication_id' => $this->publicationId,
                'tenant_id' => $this->tenantId,
            ]);
            $this->failPublication('租户不存在');
            return;
        }

        TenantContext::set($tenant);

        // ─── 2. 加载 publication 记录 ───
        $publication = ArticlePublication::find($this->publicationId);
        if (! $publication) {
            Log::error('PublishArticleJob: publication not found', [
                'publication_id' => $this->publicationId,
            ]);
            return;
        }

        // ─── 3. 检查是否已被取消 ───
        if ($publication->status === ArticlePublication::STATUS_FAILED) {
            Log::info('PublishArticleJob: publication already failed, skipping', [
                'publication_id' => $this->publicationId,
            ]);
            return;
        }

        // CAS 更新：queued → publishing（防止重复消费）
        $rows = ArticlePublication::where('id', $this->publicationId)
            ->where('status', ArticlePublication::STATUS_QUEUED)
            ->update([
                'status' => ArticlePublication::STATUS_PUBLISHING,
                'last_attempted_at' => now(),
            ]);

        if ($rows === 0) {
            Log::info('PublishArticleJob: publication no longer in queued state', [
                'publication_id' => $this->publicationId,
                'current_status' => $publication->fresh()->status,
            ]);
            return;
        }

        // ─── 4. 执行发布 ───
        try {
            $publicationService->executePublish($publication);

            Log::info('PublishArticleJob: completed', [
                'publication_id' => $this->publicationId,
                'status' => $publication->fresh()->status,
            ]);
        } catch (\Throwable $e) {
            Log::error('PublishArticleJob: unexpected failure', [
                'publication_id' => $this->publicationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->failPublication($e->getMessage());
        } finally {
            TenantContext::forget();
        }
    }

    /**
     * Job 级别失败回调（框架级异常，非业务级失败）
     */
    public function failed(?\Throwable $exception): void
    {
        $tenant = Tenant::find($this->tenantId);
        if ($tenant) {
            TenantContext::set($tenant);
        }

        $this->failPublication($exception?->getMessage() ?? '队列任务异常终止');

        TenantContext::forget();
    }

    private function failPublication(string $message): void
    {
        $publication = ArticlePublication::find($this->publicationId);
        if (! $publication) {
            return;
        }

        $publication->forceFill([
            'status' => ArticlePublication::STATUS_FAILED,
            'last_error' => $message,
            'attempt_count' => (int) $publication->attempt_count + 1,
        ])->save();
    }
}
