<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted application interfaces, workspace ownership and execution behavior.
 * Upstream: app/Models/SensitiveWord.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 敏感词（含风险扫描规则）。
 *
 * 单 7 复刻 ArticleRiskScanner 需要 richer 字典结构：severity / category /
 * is_enabled / suggestion / applies_to，以及 ruleCacheVersion() 控制缓存失效。
 * 原仓只有 word + tenant_id，已由迁移 2026_08_27_100005 补齐字段。
 */
class SensitiveWord extends Model
{
    use BelongsToTenant, HasFactory;

    public const RULE_CACHE_VERSION_KEY = 'geoflow.article-risk-scanner.rules.version';

    public const UPDATED_AT = null;

    protected $table = 'sensitive_words';

    protected $fillable = [
        'word',
        'severity',
        'category',
        'is_enabled',
        'suggestion',
        'applies_to',
    ];

    protected $attributes = [
        'severity' => 'warning',
        'category' => 'sensitive',
        'is_enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'applies_to' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saved(static function (): void {
            DB::afterCommit(static fn (): int => self::bumpRuleCacheVersion());
        });
        static::deleted(static function (): void {
            DB::afterCommit(static fn (): int => self::bumpRuleCacheVersion());
        });
    }

    public static function ruleCacheVersion(): int
    {
        Cache::add(self::RULE_CACHE_VERSION_KEY, 1);

        return max(1, (int) Cache::get(self::RULE_CACHE_VERSION_KEY, 1));
    }

    public static function bumpRuleCacheVersion(): int
    {
        Cache::add(self::RULE_CACHE_VERSION_KEY, 1);

        return max(1, (int) Cache::increment(self::RULE_CACHE_VERSION_KEY));
    }
}
