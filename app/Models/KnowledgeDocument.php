<?php

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeDocument extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'knowledge_base_id',
        // B7/§7 扣费归属：上传时选定，挂在文档上（不挂知识库）
        'project_id',
        'uploaded_by',
        'title',
        'source_type',
        'source_url',
        'mime_type',
        'file_size',
        'file_path',
        'chunk_count',
        'status',
        'review_status',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'file_size' => 'integer',
        'chunk_count' => 'integer',
    ];

    // Relationships
    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class, 'document_id');
    }

    // Scopes
    public function scopeReady($query)
    {
        return $query->where('status', 'ready');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('review_status', 'approved');
    }

    // Methods
    public function storageDirectory(): string
    {
        // A historical document can be reassigned without moving its private
        // files or changing document IDs, citations and billing records.
        return $this->file_path ? dirname($this->file_path)
            : "knowledge/{$this->tenant_id}/{$this->knowledge_base_id}/{$this->id}";
    }

    public function markProcessing(): void
    {
        $this->update([
            'status' => 'processing',
            'error_message' => null,
        ]);
    }

    public function markReady(int $chunkCount): void
    {
        $this->update([
            'status' => 'ready',
            'chunk_count' => $chunkCount,
            'error_message' => null,
        ]);
    }

    public function markFailed(string $error): void
    {
        // A late queue failure or reaper must not make a partially deleted
        // document ingestible again. The delete operation owns that state.
        $changes = [
            'status' => 'failed',
            'error_message' => $error,
        ];
        if (self::query()->where('tenant_id', $this->tenant_id)->whereKey($this->id)
            ->where('status', '!=', 'deleting')->update($changes)) {
            $this->forceFill($changes);
        }
    }
}
