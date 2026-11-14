<?php

namespace App\Support\Concerns;

use App\Models\Tenant;
use App\Support\TenantContext;
use App\Support\TenantScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 租户归属 Trait
 *
 * 在需要租户隔离的 Model 上 use 此 trait，自动获得：
 * - tenant() 关联
 * - tenant_id 自动填充（创建时）
 * - TenantScope 全局作用域
 * - boot 时自动注册
 *
 * 用法：
 *   class Project extends Model
 *   {
 *       use BelongsToTenant;
 *   }
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        // 注册全局作用域
        static::addGlobalScope(new TenantScope());

        // 创建时自动填充 tenant_id
        static::creating(function ($model) {
            if (empty($model->tenant_id) && TenantContext::has()) {
                $model->tenant_id = TenantContext::id();
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
