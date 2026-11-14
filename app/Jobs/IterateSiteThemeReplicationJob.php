<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted application interfaces, workspace ownership and execution behavior.
 * Upstream: app/Jobs/IterateSiteThemeReplicationJob.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Jobs;

use App\Models\SiteThemeReplication;
use App\Models\Tenant;
use App\Services\ThemePackaging\SiteThemeReplicationService;
use App\Services\ThemePackaging\ThemeReplicationPipelineService;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 异步主题包迭代任务（上游 IterateSiteThemeReplicationJob 复刻 + 多租户化）。
 *
 * 与 RunSiteThemeReplicationJob 同构：必须带 $tenantId，handle() 和 failed()
 * 都先还原租户上下文（队列 Worker 无请求生命周期，TenantScope 默认 fail-open）。
 * 走 tasks 队列，不新开队列名。
 */
class IterateSiteThemeReplicationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public readonly int $replicationId,
        public readonly string $feedback,
        public readonly int $tenantId,
    ) {
        $this->onQueue('tasks');
    }

    public function handle(ThemeReplicationPipelineService $pipeline): void
    {
        $tenant = $this->resolveTenant();
        if (! $tenant) {
            return;
        }

        TenantContext::set($tenant);
        try {
            $pipeline->iterate($this->replicationId, $this->feedback);
        } finally {
            // 必须清场：队列 worker 跨 Job 复用进程，不清理会让下一个 Job 继承本租户上下文
            // （TenantScope fail-open 下直接跨租户读写 —— 执行书红线 2）。
            // 与 RunSiteThemeReplicationJob 同一处理，两个 Job 一个都不能漏。
            TenantContext::forget();
        }
    }

    public function failed(?Throwable $exception = null): void
    {
        $tenant = $this->resolveTenant();
        if (! $tenant) {
            return;
        }

        TenantContext::set($tenant);
        try {
            $replication = SiteThemeReplication::query()->whereKey($this->replicationId)->first();
            if (! $replication || (string) $replication->status === SiteThemeReplication::STATUS_FAILED) {
                return;
            }

            $message = $exception?->getMessage() ?: __('theme_package.error.job_failed');
            $replication->forceFill([
                'status' => SiteThemeReplication::STATUS_FAILED,
                'error_message' => mb_substr($message, 0, 2000),
            ])->save();

            app(SiteThemeReplicationService::class)->log($replication, 'error', 'failed', $message);
        } finally {
            TenantContext::forget();
        }
    }

    private function resolveTenant(): ?Tenant
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            Log::error('IterateSiteThemeReplicationJob: tenant not found', [
                'replication_id' => $this->replicationId,
                'tenant_id' => $this->tenantId,
            ]);
        }

        return $tenant;
    }
}
