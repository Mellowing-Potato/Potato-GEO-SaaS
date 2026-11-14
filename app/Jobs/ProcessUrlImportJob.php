<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\UrlImport;
use App\Services\Content\UrlImportProcessingService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * URL 导入异步处理任务（阶段 1.3，对标 GEOFlow UrlImportJob）
 *
 * 复用项目既有队列约定：进入独立队列 url-import，处理前重设 TenantContext，
 * finally 中 forget，确保租户隔离与后续任务不被污染。
 */
class ProcessUrlImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 120;

    /**
     * @param  int  $tenantId     租户 ID（用于队列 worker 重设租户上下文）
     * @param  int  $urlImportId  url_imports 记录 ID
     */
    public function __construct(
        public int $tenantId,
        public int $urlImportId,
    ) {
        $this->onQueue('url-import');
    }

    public function handle(UrlImportProcessingService $service): void
    {
        $tenant = Tenant::find($this->tenantId);

        if (! $tenant) {
            Log::error('ProcessUrlImportJob: tenant not found', [
                'tenant_id' => $this->tenantId,
                'url_import_id' => $this->urlImportId,
            ]);

            return;
        }

        try {
            TenantContext::set($tenant);

            $import = UrlImport::find($this->urlImportId);

            if (! $import) {
                Log::error('ProcessUrlImportJob: import not found', [
                    'url_import_id' => $this->urlImportId,
                ]);

                return;
            }

            $service->process($import);
        } finally {
            TenantContext::forget();
        }
    }
}
