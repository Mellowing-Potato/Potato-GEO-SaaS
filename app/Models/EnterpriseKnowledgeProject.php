<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted application interfaces, workspace ownership and execution behavior.
 * Upstream: app/Models/EnterpriseKnowledgeProject.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnterpriseKnowledgeProject extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'project_id',
        'name',
        'description',
        'status',
        'draft_content',
        'validation_json',
        'structured_json',
        'published_knowledge_base_id',
        'published_document_id',
        'created_by_user_id',
        'error_message',
    ];

    protected $casts = [
        'validation_json' => 'array',
        'project_id' => 'integer',
        'structured_json' => 'array',
        'published_knowledge_base_id' => 'integer',
        'published_document_id' => 'integer',
        'created_by_user_id' => 'integer',
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_REVIEWING = 'reviewing';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_FAILED = 'failed';

    public function sources(): HasMany
    {
        return $this->hasMany(EnterpriseKnowledgeSource::class)->orderBy('sort_order')->orderBy('id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(EnterpriseKnowledgeRevision::class)->latest();
    }

    public function publishedKnowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class, 'published_knowledge_base_id');
    }

    public function publishedDocument(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'published_document_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function validationItems(): array
    {
        $decoded = json_decode((string) ($this->validation_json ?? ''), true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /**
     * @return array<string,mixed>
     */
    public function structuredData(): array
    {
        $decoded = json_decode((string) ($this->structured_json ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string,mixed>
     */
    public function draftGenerationProgress(): array
    {
        $progress = $this->structuredData()['draft_generation'] ?? [];

        return is_array($progress) ? $progress : [];
    }
}
