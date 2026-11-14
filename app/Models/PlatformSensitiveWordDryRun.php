<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 加词前试算记录（执行书 T8 的安全阀）。
 *
 * ⚠️ 与 PlatformSensitiveWord 同理：**绝对不许 use BelongsToTenant**。
 * 试算本身就是跨租户全表扫描，挂上租户作用域会让查询带上 where tenant_id（列不存在
 * → SQL 报错），或在 null 上下文下 fail-open —— 两种都让运营捞不回自己的试算结果。
 *
 * 状态机：pending → running → completed | failed。
 * failed 也要留着：试算失败必须让运营看见「这次没扫成」，
 * 否则他会把「没有结果」误读成「没有命中」，然后放心把词加进去。
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
