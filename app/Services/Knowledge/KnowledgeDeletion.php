<?php

namespace App\Services\Knowledge;

use App\Exceptions\KnowledgeDocumentUnavailable;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

final class KnowledgeDeletion
{
    public function __construct(private readonly KnowledgeLifecycle $lifecycle) {}

    public function document(KnowledgeBase $base, KnowledgeDocument $document): void
    {
        $tenantId = TenantContext::id();
        abort_unless((int) $base->tenant_id === $tenantId && (int) $document->tenant_id === $tenantId, 404);
        $this->lifecycle->base($tenantId, (int) $base->id, function () use ($base, $document, $tenantId) {
            $this->lifecycle->documents($tenantId, [(int) $document->id], function () use ($base, $document, $tenantId) {
                $current = KnowledgeDocument::query()->where('tenant_id', $tenantId)
                    ->where('knowledge_base_id', $base->id)->findOrFail($document->id);
                $current->update(['status' => 'deleting', 'error_message' => '材料正在删除；若清理未完成，请重试删除。']);
                $this->removeFiles($current);
                DB::transaction(function () use ($current) {
                    $current->chunks()->delete();
                    $current->delete();
                });
            });
        });
    }

    public function base(KnowledgeBase $base): void
    {
        $tenantId = TenantContext::id();
        abort_unless((int) $base->tenant_id === $tenantId, 404);
        $this->lifecycle->base($tenantId, (int) $base->id, function () use ($base, $tenantId) {
            [$current, $documents] = DB::transaction(function () use ($base, $tenantId) {
                // stageText may have returned inside an outer publish transaction.
                // Wait for that writer before enumerating its newly registered rows.
                // SQLite ignores FOR UPDATE, so acquire its write lock before reading.
                if (DB::connection()->getDriverName() === 'sqlite') {
                    DB::table('knowledge_bases')->where('tenant_id', $tenantId)->where('id', $base->id)
                        ->update(['updated_at' => DB::raw('updated_at')]);
                }
                $current = KnowledgeBase::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($base->id);

                return [$current, $current->documents()->orderBy('id')->get()];
            }, attempts: 3);
            // The base file lock now prevents new registrations. SQL locks are
            // released before disk cleanup; a busy document still aborts unchanged.
            // All locks are acquired before the first state/file change. A live
            // ingestion therefore rejects the whole deletion without partial work.
            $this->lifecycle->documents($tenantId, $documents->modelKeys(), function () use ($current, $documents) {
                DB::transaction(function () use ($current, $documents) {
                    $current->update(['status' => 'deleting']);
                    foreach ($documents as $document) {
                        $document->update(['status' => 'deleting', 'error_message' => '知识库正在删除；若清理未完成，请重试删除。']);
                    }
                });
                foreach ($documents as $document) {
                    $this->removeFiles($document);
                }
                DB::transaction(function () use ($current, $documents) {
                    foreach ($documents as $document) {
                        $document->chunks()->delete();
                        $document->delete();
                    }
                    $current->delete();
                });
            });
        });
    }

    private function removeFiles(KnowledgeDocument $document): void
    {
        $disk = Storage::disk('local');
        $directory = $document->storageDirectory();
        $this->assertDirectory($document, $directory);
        try {
            $directories = [$directory];
            // URL documents have no file_path. Historical reassignment can move
            // their DB base without moving page/receipt directories. IDs are
            // global, so only this document's exact child in this tenant is used.
            foreach ($disk->directories('knowledge/'.$document->tenant_id) as $baseDirectory) {
                if (preg_match('#^knowledge/'.(int) $document->tenant_id.'/[1-9][0-9]*$#D', $baseDirectory)) {
                    $directories[] = $baseDirectory.'/'.$document->id;
                }
            }
            foreach (array_unique($directories) as $path) {
                $this->assertDirectory($document, $path);
                if (! $disk->deleteDirectory($path) || $disk->directoryExists($path)) {
                    throw new \RuntimeException('Material directory was not removed');
                }
            }
        } catch (KnowledgeDocumentUnavailable $e) {
            throw $e;
        } catch (Throwable) {
            // Keep the deleting row and model-call receipts until a later delete
            // finishes. No queue replay may resume ingestion from this state.
            throw new ServiceUnavailableHttpException(null, '材料文件尚未清理完成，记录已保留；请稍后重试删除。');
        }
    }

    private function assertDirectory(KnowledgeDocument $document, string $path): void
    {
        $pattern = '#^knowledge/'.(int) $document->tenant_id.'/[1-9][0-9]*/'.(int) $document->id.'$#D';
        if (! preg_match($pattern, $path)) {
            throw new KnowledgeDocumentUnavailable('材料存储位置无法安全确认，尚未删除文件；请先核对该材料。');
        }
        $current = rtrim(Storage::disk('local')->path(''), '/\\');
        foreach (explode('/', $path) as $part) {
            $current .= DIRECTORY_SEPARATOR.$part;
            if (is_link($current)) {
                throw new KnowledgeDocumentUnavailable('材料存储位置无法安全确认，尚未删除文件；请先核对该材料。');
            }
        }
    }
}
