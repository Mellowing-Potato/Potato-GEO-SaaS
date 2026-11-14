<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * 租户全局作用域
 *
 * 自动为所有租户相关 Model 的查询追加 WHERE tenant_id = ? 条件。
 * 通过 TenantContext::id() 获取当前租户 ID。
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = TenantContext::id();

        // 如果没有设置租户上下文（如平台管理员 bypass），不追加条件
        if ($tenantId === null) {
            return;
        }

        $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
    }

    /**
     * 扩展 Builder：添加 withoutTenant 方法，允许单次查询绕过作用域
     */
    public function extend(Builder $builder): void
    {
        $builder->macro('withoutTenant', function (Builder $builder) {
            return $builder->withoutGlobalScope($this);
        });
    }
}
