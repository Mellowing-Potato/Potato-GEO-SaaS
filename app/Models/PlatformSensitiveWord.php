<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted application interfaces, workspace ownership and execution behavior.
 * Upstream: app/Models/SensitiveWord.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * 平台级敏感词基线库（执行书 T3）。
 *
 * ⚠️⚠️ 绝对不许 use BelongsToTenant，也不许挂 TenantScope。
 * 这张表是全平台共享的红线词库，没有 tenant_id 列；一旦挂上租户作用域，
 * TenantScope 会往查询里塞 `where tenant_id = ?`，字段不存在 → 直接 SQL 报错，
 * 而在租户上下文为 null 时又 fail-open 静默放行——两种结果都等于「基线库读不出来」，
 * 也就是闸门形同不存在。这一条是本模型存在的前提，改动前先想清楚。
 *
 * 与租户自己的 SensitiveWord 的分工：
 *   - SensitiveWord（sensitive_words）        ：租户自己加的词，租户面板可读写，可自行停用
 *   - PlatformSensitiveWord（本表）           ：平台合规红线，仅运营台（platform:operations）可读写，
 *                                              租户侧任何接口/页面/导出都读不到
 *
 * 永远不要物理删除平台词：下线一条词用 is_enabled = false。
 * 「这条词曾经存在、什么时候下掉的、谁下的、为什么」在合规问题上必须答得出来，
 * 物理删除之后这些全没了。运营台的「删除」按钮实际执行停用。
 */
class PlatformSensitiveWord extends Model
{
    use HasFactory;

    /**
     * 独立于 SensitiveWord::RULE_CACHE_VERSION_KEY 的版本号。
     *
     * 必须独立：两张表的写入频率完全不同（租户随时加词 vs 运营偶尔调基线），
     * 共用一个号会让任一侧的写入把另一侧的缓存也冲掉，白白重建。
     */
    public const RULE_CACHE_VERSION_KEY = 'geoflow.platform-sensitive-word.rule_version';

    public const SEVERITY_BLOCKED = 'blocked';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITIES = [self::SEVERITY_WARNING, self::SEVERITY_BLOCKED];

    public const CATEGORY_POLITICS = 'politics';

    public const CATEGORY_ILLEGAL = 'illegal';

    public const CATEGORIES = [self::CATEGORY_POLITICS, self::CATEGORY_ILLEGAL];

    protected $table = 'platform_sensitive_words';

    protected $fillable = [
        'word',
        'severity',
        'category',
        'is_enabled',
        'suggestion',
        'note',
        'created_by',
        'updated_by',
    ];

    protected $attributes = [
        'severity' => self::SEVERITY_BLOCKED,
        'category' => self::CATEGORY_POLITICS,
        'is_enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // ⚠️ 故意**不用** DB::afterCommit（SensitiveWord 那边用了）。
        // 理由：afterCommit 在 RefreshDatabase 的包裹事务下永远不触发，
        // 「改完立刻生效」这条老板硬要求就测不到，只能写一条恒绿断言糊弄过去。
        // 直接 bump 的代价是：事务回滚时版本号已经加过了 → 多一次缓存重建。
        // 词表读一次是一条 SQL，重建的成本远低于「运营改了词却不生效」的代价。
        static::saved(static fn (): int => self::bumpRuleCacheVersion());
        static::deleted(static fn (): int => self::bumpRuleCacheVersion());
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
