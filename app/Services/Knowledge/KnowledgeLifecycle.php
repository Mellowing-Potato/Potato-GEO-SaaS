<?php

namespace App\Services\Knowledge;

use App\Exceptions\KnowledgeDocumentUnavailable;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Closure;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/** Locks live outside material directories and have no lease that can expire mid-call. */
final class KnowledgeLifecycle
{
    public function base(int $tenantId, int $baseId, Closure $operation): mixed
    {
        return $this->locked($tenantId, ['base-'.$baseId], $operation);
    }

    public function documents(int $tenantId, array $ids, Closure $operation): mixed
    {
        sort($ids, SORT_NUMERIC);

        return $this->locked($tenantId, array_map(fn ($id) => 'document-'.$id, array_unique($ids)), $operation);
    }

    /** Registration holds only the base lock; processing never acquires a base lock. */
    public function register(int $baseId, Closure $operation): mixed
    {
        $tenantId = TenantContext::id();

        return $this->base($tenantId, $baseId, function () use ($tenantId, $baseId, $operation) {
            $base = KnowledgeBase::query()->where('tenant_id', $tenantId)->findOrFail($baseId);
            if ($base->status === 'deleting') {
                throw new KnowledgeDocumentUnavailable('知识库正在删除，请先完成删除。');
            }

            return $operation();
        });
    }

    public function process(KnowledgeDocument $document, Closure $operation): KnowledgeDocument
    {
        abort_unless((int) $document->tenant_id === TenantContext::id(), 404);

        return $this->documents((int) $document->tenant_id, [(int) $document->id], function () use ($document, $operation) {
            $current = KnowledgeDocument::query()->where('tenant_id', $document->tenant_id)->find($document->id);
            if (! $current || $current->status === 'deleting') {
                throw new KnowledgeDocumentUnavailable('材料正在删除或已删除，本次未解析。');
            }

            return $operation($current);
        });
    }

    private function locked(int $tenantId, array $names, Closure $operation): mixed
    {
        if ($tenantId < 1) {
            throw new KnowledgeDocumentUnavailable('材料所属工作区未确认，本次未执行。');
        }
        $handles = [];
        try {
            foreach ($names as $name) {
                $handles[] = $this->acquire($tenantId, $name);
            }

            return $operation();
        } finally {
            foreach (array_reverse($handles) as $handle) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
            // Never unlink a lock: another process may already hold its inode.
        }
    }

    /** @return resource */
    private function acquire(int $tenantId, string $name)
    {
        $disk = Storage::disk('local');
        $directory = $disk->path('.knowledge-locks');
        if (is_link($directory) || (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory))) {
            throw new ServiceUnavailableHttpException(null, '材料操作保护暂不可用，本次未执行。');
        }
        $path = $directory.'/'.$tenantId.'-'.$name.'.lock';
        if (is_link($path) || ($handle = @fopen($path, 'c+b')) === false) {
            throw new ServiceUnavailableHttpException(null, '材料操作保护暂不可用，本次未执行。');
        }
        $stat = fstat($handle);
        $pathStat = @lstat($path);
        if (! $stat || ! $pathStat || ($pathStat['mode'] & 0170000) !== 0100000
            || $stat['dev'] !== $pathStat['dev'] || $stat['ino'] !== $pathStat['ino']) {
            fclose($handle);
            throw new ServiceUnavailableHttpException(null, '材料操作保护暂不可用，本次未执行。');
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new KnowledgeDocumentUnavailable('材料正在处理或删除，请等待当前操作完成后重试；本次未重复执行。');
        }

        return $handle;
    }
}
