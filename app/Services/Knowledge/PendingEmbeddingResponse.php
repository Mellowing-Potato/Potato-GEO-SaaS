<?php

namespace App\Services\Knowledge;

use App\Exceptions\IngestionFailure;
use App\Models\KnowledgeDocument;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/** Preserve a received SQLite batch when the writer lock outlasts the database retries. */
final class PendingEmbeddingResponse
{
    private readonly string $scope;
    private readonly string $path;

    public function __construct(KnowledgeDocument $document, string $batch)
    {
        $this->scope = implode(':', [$document->tenant_id, $document->project_id, $document->id, $batch]);
        $this->path = Storage::disk('local')->path($document->storageDirectory().'/.embedding-receipts/'
            .hash('sha256', $this->scope).'.receipt');
    }

    public function save(array $response): void
    {
        $data = ['scope' => $this->scope, 'response' => Arr::only($response,
            ['embeddings', 'gateway', 'provider', 'model', 'error'])];
        File::ensureDirectoryExists(dirname($this->path), 0700);
        // Serialization also keeps invalid non-finite coordinates for the normal validator to reject.
        File::replace($this->path, Crypt::encryptString(serialize($data)), 0600);
    }

    public function read(): ?array
    {
        if (! is_file($this->path)) {
            return null;
        }
        try {
            $encrypted = File::get($this->path);
        } catch (FileNotFoundException) {
            return null; // Another worker committed and removed the receipt.
        }
        $data = unserialize(Crypt::decryptString($encrypted), ['allowed_classes' => false]);
        if (($data['scope'] ?? null) !== $this->scope || ! is_array($data['response'] ?? null)) {
            throw new IngestionFailure('已保存的向量回执与当前材料不匹配。');
        }

        return $data['response'];
    }

    public function forget(): void
    {
        File::delete($this->path);
    }
}
