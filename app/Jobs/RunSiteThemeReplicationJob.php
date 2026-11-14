<?php

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
 * 异步主题包生成任务（上游 RunSiteThemeReplicationJob 复刻 + 多租户化）。
 *
 * ⚠️ 队列 Worker 没有 HTTP 请求生命周期，TenantScope 是 fail-open 的
 * （TenantContext::id() 为 null 时查全表）。所以：
 *   - 构造必须显式带 $tenantId；
 *   - handle() 和 failed() 第一件事都是 resolveTenant() + TenantContext::set()，
 *     否则会在无上下文下跨租户读写（见执行书红线 2）。
 *   - 走 tasks 队列（compose 已含），不新开队列名。
 */
class RunSiteThemeReplicationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 抓外网 + 生成，180s 够；重试会重复抓外网，不划算，照上游 $tries=1。 */
    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public readonly int $replicationId,
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
            $pipeline->run($this->replicationId);
        } finally {
            // 必须清场：队列 worker 跨 Job 复用进程，不清理会让下一个 Job 继承本租户上下文
            // （TenantScope fail-open 下直接跨租户读写 —— 执行书红线 2）。
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
            Log::error('RunSiteThemeReplicationJob: tenant not found', [
                'replication_id' => $this->replicationId,
                'tenant_id' => $this->tenantId,
            ]);
        }

        return $tenant;
    }
}
