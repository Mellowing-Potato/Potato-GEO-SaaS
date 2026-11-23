<?php

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class KnowledgeChunk extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'document_id',
        'chunk_index',
        'content',
        'section_title',
        'token_count',
        'embedding',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'embedding' => 'array',
        'token_count' => 'integer',
        'chunk_index' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $chunk): void {
            if ($chunk->isDirty('embedding') && $chunk->embedding !== null
                && ! \App\Services\AI\EmbeddingModelPolicy::validVector($chunk->embedding)) {
                throw new \InvalidArgumentException('Knowledge vectors must contain exactly 1024 finite numbers and at least one nonzero coordinate.');
            }
        });
    }

    // Relationships
    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'document_id');
    }

    // Methods

    /**
     * Query nearest neighbors by cosine similarity on the embedding column.
     *
     * @param  array<float>  $queryVector  The query embedding vector.
     * @param  int           $limit        Maximum number of results.
     * @return Builder
     */
    public function nearestNeighbors(array $queryVector, int $limit = 5): Builder
    {
        if (! \App\Services\AI\EmbeddingModelPolicy::validVector($queryVector)) {
            throw new \InvalidArgumentException('Query vectors must contain exactly 1024 finite numbers and at least one nonzero coordinate.');
        }
        if (DB::connection()->getDriverName() !== 'pgsql') {
            if (! $this->nonPgvectorFallbackEnabled()) {
                return static::query()
                    ->selectRaw('knowledge_chunks.*, 0.0 AS similarity')
                    ->whereRaw('1 = 0');
            }

            return static::query()
                ->selectRaw('knowledge_chunks.*, 0.0 AS similarity')
                ->whereNotNull('embedding')
                ->limit($limit);
        }

        $encoded = $this->encodeVector($queryVector);

        return static::query()
            ->selectRaw('*, 1 - (embedding <=> ?::vector) AS similarity', [$encoded])
            ->orderByRaw('embedding <=> ?::vector', [$encoded])
            ->limit($limit);
    }

    /**
     * Encode a PHP float array into pgvector string format: "[0.1,0.2,0.3]".
     *
     * @param  array<float>  $vector
     * @return string
     */
    private function encodeVector(array $vector): string
    {
        return '[' . implode(',', array_map(fn ($v) => (string) $v, $vector)) . ']';
    }

    private function nonPgvectorFallbackEnabled(): bool
    {
        $isProduction = strtolower(trim((string) config('app.env'))) === 'production';
        $requiresPgvector = filter_var(config('geo.rag.require_pgvector_in_production', true), FILTER_VALIDATE_BOOL);

        if ($isProduction && $requiresPgvector) {
            return false;
        }

        return filter_var(config('geo.rag.sqlite_fallback_enabled', true), FILTER_VALIDATE_BOOL);
    }
}
