<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Workspace-owned sensitive-word trial results.
 * Legacy rows without tenant_id contain cross-workspace results and must not be exposed.
 */
class PlatformSensitiveWordDryRun extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /** 单次试算最多接受的候选词条数：运营一次粘贴上千条会把队列打满。 */
    public const MAX_CANDIDATES = 200;

    /** 每条词最多附几条命中上下文（执行书：前 3 条）。 */
    public const MAX_SAMPLES_PER_WORD = 3;

    protected $table = 'platform_sensitive_word_dry_runs';

    protected $fillable = [
        'tenant_id',
        'requested_by',
        'candidates',
        'status',
        'articles_scanned',
        'articles_skipped',
        'hit_articles',
        'by_tenant',
        'by_word',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'candidates' => 'array',
            'by_tenant' => 'array',
            'by_word' => 'array',
            'requested_by' => 'integer',
            'tenant_id' => 'integer',
            'articles_scanned' => 'integer',
            'articles_skipped' => 'integer',
            'hit_articles' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }
}
