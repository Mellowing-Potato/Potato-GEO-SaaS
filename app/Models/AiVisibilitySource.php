<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted application interfaces, workspace ownership and execution behavior.
 * Upstream: app/Models/AiVisibilitySource.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AI 可见性监测信源（上游 AiVisibilitySourceData 14 字段 1:1，见迁移注释）。
 */
class AiVisibilitySource extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'ai_visibility_sources';

    protected $fillable = [
        'tenant_id',
        'run_id',
        'source_type',
        'citation_key',
        'title',
        'url',
        'domain',
        'site_name',
        'snippet',
        'summary',
        'content_excerpt',
        'published_at',
        'rank',
        'rank_score',
        'authority_level',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'rank' => 'integer',
            'rank_score' => 'float',
            'published_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiVisibilityRun::class, 'run_id');
    }

    /**
     * logo_url（带 x-expires 的临时签名链接）只应出现在 metadata，不设永久列。
     * 这里给出访问入口仅为转存/展示时的显式选择点，调用方必须自知它在过期。
     */
    public function temporaryLogoUrl(): ?string
    {
        $logo = $this->metadata['logo_url'] ?? null;

        return is_string($logo) && $logo !== '' ? $logo : null;
    }
}
