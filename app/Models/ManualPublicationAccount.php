<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted application interfaces, workspace ownership and execution behavior.
 * Upstream: app/Models/ManualPublicationAccount.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ManualPublicationAccount extends Model
{
    use BelongsToTenant;

    public const PLATFORM_ZHIHU = 'zhihu';

    public const PLATFORM_XIAOHONGSHU = 'xiaohongshu';

    public const PLATFORM_WEIBO = 'weibo';

    public const PLATFORM_WECHAT = 'wechat';

    public const PLATFORM_DOUYIN = 'douyin';

    public const PLATFORM_BILIBILI = 'bilibili';

    public const PLATFORM_REDDIT = 'reddit';

    public const PLATFORM_X = 'x';

    public const PLATFORM_LINKEDIN = 'linkedin';

    public const PLATFORM_CUSTOM = 'custom';

    public const PLATFORMS = [
        self::PLATFORM_ZHIHU, self::PLATFORM_XIAOHONGSHU,
        self::PLATFORM_WEIBO, self::PLATFORM_WECHAT,
        self::PLATFORM_DOUYIN, self::PLATFORM_BILIBILI,
        self::PLATFORM_REDDIT, self::PLATFORM_X,
        self::PLATFORM_LINKEDIN, self::PLATFORM_CUSTOM,
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        // tenant_id 显式传入时须可填（服务层无租户上下文直调场景），缺了会被静默丢弃
        'tenant_id',
        'persona_id',
        'platform',
        'custom_platform',
        'account_name',
        'profile_url',
        'notes',
        'is_active',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'persona_id' => 'integer',
            'is_active' => 'boolean',
            'created_by_user_id' => 'integer',
        ];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(ManualPublicationPersona::class, 'persona_id');
    }

    public function publications(): HasMany
    {
        return $this->hasMany(ManualPublication::class, 'account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function platformLabelKey(): string
    {
        return 'admin.manual_publications.platform.'.$this->platform;
    }
}
