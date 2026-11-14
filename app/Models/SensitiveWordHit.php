<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * 敏感词命中日志（执行书 T7）。
 *
 * ⚠️ **故意不用 BelongsToTenant / TenantScope**，虽然本表有 tenant_id 列。
 * 理由：这是平台侧的排查台账，运营台必须跨租户聚合（哪条词在哪些租户身上命中最多）。
 * 挂上租户作用域会变成两种都不对的行为——有租户上下文时把运营要看的行过滤掉，
 * 没有上下文时靠 TenantScope 的 fail-open 侥幸读全。
 * 不挂作用域 + 写入时显式带 tenant_id，行为是确定的。
 * 租户侧的隔离靠「不给任何租户接口/页面/导出读这张表」实现，不靠作用域。
 *
 * 清理：`php artisan sensitive-word-hits:prune`（routes/console.php，每天调度一次），
 * 删 RETENTION_DAYS 天前的记录。别让这张表只进不出。
 */
class SensitiveWordHit extends Model
{
    use HasFactory;

    public const ORIGIN_PLATFORM = 'platform';

    public const ORIGIN_TENANT = 'tenant';

    public const TRIGGER_CREATE = 'create';

    public const TRIGGER_UPDATE = 'update';

    public const TRIGGER_MANUAL_PUBLISH = 'manual_publish';

    public const TRIGGER_DISTRIBUTE = 'distribute';

    /**
     * 保留天数，超过即由清理命令删除。
     */
    public const RETENTION_DAYS = 90;

    protected $table = 'sensitive_word_hits';

    protected $fillable = [
        'tenant_id',
        'article_id',
        'word',
        'severity',
        'origin',
        'surface',
        'context',
        'trigger',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'article_id' => 'integer',
        ];
    }

    /**
     * 写一条命中日志，失败只告警不抛。
     *
     * 所有写入点（T5 的内容产生钩子、T6 的出站闸门、手动发布单闸门）都走这里，
     * 免得每处各写一遍 try/catch —— 漏一处就可能把主流程带崩。
     *
     * @param  array{tenant_id: ?int, article_id: ?int, word: string, severity: string, origin: string, surface: string, context: ?string, trigger: string}  $attributes
     */
    public static function record(array $attributes): ?self
    {
        try {
            // tenant_id 是 NOT NULL；上下文缺失时不写日志，也不要因此把主流程搞挂。
            if (empty($attributes['tenant_id'])) {
                Log::warning('Sensitive word hit skipped: missing tenant context', [
                    'article_id' => $attributes['article_id'] ?? null,
                    'trigger' => $attributes['trigger'] ?? null,
                ]);

                return null;
            }

            return self::create([
                'tenant_id' => (int) $attributes['tenant_id'],
                'article_id' => $attributes['article_id'] ?? null,
                'word' => mb_substr((string) $attributes['word'], 0, 255),
                'severity' => (string) $attributes['severity'],
                'origin' => (string) $attributes['origin'],
                'surface' => (string) $attributes['surface'],
                'context' => $attributes['context'] ?? null,
                'trigger' => (string) $attributes['trigger'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Sensitive word hit log failed', [
                'article_id' => $attributes['article_id'] ?? null,
                'trigger' => $attributes['trigger'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
