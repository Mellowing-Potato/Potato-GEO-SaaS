<?php

namespace App\Jobs;

use App\Models\ArticlePublication;
use App\Models\Tenant;
use App\Services\Distribution\DistributionOrchestrator;
use App\Services\Distribution\DistributionRetryPolicy;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 分发引擎队列任务
 *
 * 消费后还原租户上下文，调用 DistributionOrchestrator::process()
 * 完成实际的多渠道分发（Agent / WordPress / Generic HTTP）。
 *
 * 与 PublishArticleJob 的区别：
 *   - PublishArticleJob 走 ArticlePublicationService（旧本地发布流）
 *   - 本 Job 走 DistributionOrchestrator（新分发引擎，含签名/重试/日志）
 */
class ProcessArticleDistributionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 超时时间（秒），外部 HTTP 请求 + 签名验证 */
    public int $timeout = 90;

    /** 由 DistributionRetryPolicy 管理重试，Job 本身不重跑 */
    public int $tries = 1;

    public function __construct(
        public readonly int $publicationId,
        public readonly int $tenantId,
    ) {
        $this->onQueue('distribution');
    }

    public function handle(DistributionOrchestrator $orchestrator): void
    {
        // ─── 1. 还原租户上下文 ───
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            Log::error('ProcessArticleDistributionJob: tenant not found', [
                'publication_id' => $this->publicationId,
                'tenant_id' => $this->tenantId,
            ]);
            return;
        }

        // 任何提前 return / 异常都必须清理租户上下文，避免污染队列 worker 的下一个任务
        try {
            TenantContext::set($tenant);
            $this->distribute($orchestrator);
        } finally {
            TenantContext::forget();
        }
    }

    private function distribute(DistributionOrchestrator $orchestrator): void
    {
        // ─── 2. 加载 publication 记录 ───
        $publication = ArticlePublication::find($this->publicationId);
        if (! $publication) {
            Log::error('ProcessArticleDistributionJob: publication not found', [
                'publication_id' => $this->publicationId,
            ]);
            return;
        }

        // ─── 3. 跳过已终态的记录 ───
        if (in_array($publication->status, [
            ArticlePublication::STATUS_PUBLISHED,
            ArticlePublication::STATUS_FAILED,
        ], true)) {
            Log::info('ProcessArticleDistributionJob: publication already terminal, skipping', [
                'publication_id' => $this->publicationId,
                'status' => $publication->status,
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
            Log::info('ProcessArticleDistributionJob: publication no longer queued', [
                'publication_id' => $this->publicationId,
                'current_status' => $publication->fresh()->status,
            ]);
            return;
        }

        // ─── 4. 执行分发 ───
        try {
            $result = $orchestrator->process($publication->fresh());

            $newStatus = $publication->fresh()->status;

            // 如果 orchestrator 标记为 queued（等待重试），重新投递 Job
            if ($newStatus === ArticlePublication::STATUS_QUEUED) {
                $retryPolicy = app(DistributionRetryPolicy::class);
                $delaySeconds = $retryPolicy->backoffSeconds($publication->attempt_count);

                self::dispatch($this->publicationId, $this->tenantId)
                    ->delay(now()->addSeconds($delaySeconds));

                Log::info('ProcessArticleDistributionJob: re-queued for retry', [
                    'publication_id' => $this->publicationId,
                    'attempt' => $publication->attempt_count,
                    'delay_seconds' => $delaySeconds,
                ]);
            } else {
                Log::info('ProcessArticleDistributionJob: completed', [
                    'publication_id' => $this->publicationId,
                    'status' => $newStatus,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('ProcessArticleDistributionJob: unexpected failure', [
                'publication_id' => $this->publicationId,
                'error' => $e->getMessage(),
            ]);
            $publication->update([
                'status' => ArticlePublication::STATUS_FAILED,
                'last_error' => mb_substr($e->getMessage(), 0, 1000),
            ]);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $tenant = Tenant::find($this->tenantId);
        if ($tenant) {
            TenantContext::set($tenant);
        }

        $publication = ArticlePublication::find($this->publicationId);
        if ($publication) {
            $publication->update([
                'status' => ArticlePublication::STATUS_FAILED,
                'last_error' => 'Queue job failed: ' . mb_substr($exception?->getMessage() ?? 'unknown', 0, 500),
            ]);
        }

        TenantContext::forget();
    }
}
