<?php

namespace App\Jobs;

use App\Exceptions\IngestionFailure;
use App\Models\KnowledgeDocument;
use App\Models\Tenant;
use App\Services\Knowledge\DocumentIngestionService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 文档入库异步任务：接一个已登记的 knowledge_documents 行，解析 + 切块 + 向量化。
 *
 * 为什么 tries = 1：向量化走网关 /embeddings，按 token 真收费。重试一次
 * 就是再花一次钱；而失败点往往是余额不足或网关故障，重试也救不回来。
 *
 * 为什么 timeout = 540：worker 命令行给的是 --timeout=120，但 job 类上的
 * $timeout 会覆盖它（Worker::timeoutForJob）。上界是 config/queue.php 的
 * retry_after=600 —— 超过它，同一个 job 会被第二个 worker 领走，切片写两遍。
 * 540 秒够跑 1000 万字符闸门下大约一半的量；再长的文档本来就该被拆开传。
 */
class IngestKnowledgeDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 540;

    /**
     * @param  int  $tenantId   租户 ID（用于队列 Worker 还原 TenantContext）
     * @param  int  $documentId  knowledge_documents 记录 ID
     */
    public function __construct(
        public int $tenantId,
        public int $documentId,
    ) {
        $this->onQueue('knowledge');
    }

    public function handle(DocumentIngestionService $ingestion): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            Log::error('IngestKnowledgeDocumentJob: tenant not found', [
                'tenant_id' => $this->tenantId,
                'document_id' => $this->documentId,
            ]);
            return;
        }

        try {
            TenantContext::set($tenant);

            $document = KnowledgeDocument::find($this->documentId);
            if (! $document) {
                Log::error('IngestKnowledgeDocumentJob: document not found', [
                    'document_id' => $this->documentId,
                ]);
                return;
            }

            $ingestion->process($document);
        } finally {
            TenantContext::forget();
        }
    }

    /**
     * failed() 跑在 handle() 的 try/finally 之外，TenantContext 已经被清了，
     * 要自己重设一次 —— 否则 KnowledgeDocument 的租户全局作用域会让 find() 返回 null，
     * 文档就永远卡在 processing。写法照抄 ExecuteSkillJob::failed()。
     */
    public function failed(?\Throwable $exception): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            return;
        }

        try {
            TenantContext::set($tenant);
            // 同 process()：只有 IngestionFailure 的原话能给客户看，其余一律通用提示。
            // 这里最常见的两种异常是「任务超时」和「worker 被 kill」，原文都不该进浏览器。
            KnowledgeDocument::find($this->documentId)
                ?->markFailed(IngestionFailure::messageFor(
                    $exception,
                    '入库任务异常终止，请重新上传；如反复出现请联系我们。'
                ));
        } finally {
            TenantContext::forget();
        }
    }
}
