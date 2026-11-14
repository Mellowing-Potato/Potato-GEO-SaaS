<?php

namespace App\Services\Publishing;

use App\Models\LeadForm;
use App\Models\Tenant;
use App\Support\TenantContext;

/**
 * 线索表单公开页交付服务（单 8）。
 *
 * ⚠️ 这是本单唯一的严重风险点（执行书 §一）。公开表单页没有登录、也没有租户中间件，
 * 此时 TenantContext::id() 返回 null，而租户全局作用域在拿到 null 时直接返回、
 * 一个过滤条件都不加（TenantScope.php:19-24，实查确认）。所以公开页里直接写
 * LeadForm::where('slug', $slug)->first() 会跨租户查全表 —— A 租户的链接能打开 B 租户的表单。
 *
 * 因此这里严格照抄 SiteDeliveryService 的三件套，一步都不能省：
 *  ① 先按 tenantSlug 解出租户；
 *  ② 设上下文；
 *  ③ 查询时仍然显式写 where('tenant_id', $tenant->id) —— 不靠全局作用域兜底；
 *  ④ finally 里 forget() —— 生产是 FrankenPHP 常驻进程（非每请求重启的 php-fpm），
 *     不清理会污染同一进程里的后续请求。
 *
 * resolve() 在 finally 里清理上下文，调用方拿到返回的 $tenant 后自行用 $tenant->id
 * 显式落库（提交端点也这么干），绝不依赖「我刚 set 过所以作用域会兜住我」这条隐式假设。
 *
 * @return array{form: LeadForm, tenant: Tenant}|null 不可读（租户/表单不存在/未发布）时返回 null，调用方转 404
 */
final class LeadFormDeliveryService
{
    public function resolve(string $tenantSlug, string $formSlug): ?array
    {
        // ① 先按 tenantSlug 解出租户（公开页按 URL 两段定位，不走 IdentifyTenant 的 host 机制）。
        $tenant = Tenant::query()->where('subdomain', strtolower($tenantSlug))->first();
        if (! $tenant) {
            return null;                       // 租户不存在 → 转 404
        }

        // ② 设上下文。
        TenantContext::set($tenant);

        try {
            // ③ 仍然显式写 where('tenant_id', ...) —— 哪怕第 ② 步已经设了上下文，
            // 也绝不依赖全局作用域：哪天有人改了中间件顺序、或哪个分支提前 forget()，
            // 这行就悄悄退化成查全表且不报任何错。显式 where 是明写的保险，成本一行。
            $form = LeadForm::query()
                ->where('tenant_id', $tenant->id)
                ->where('slug', $formSlug)
                ->where('status', LeadForm::STATUS_ACTIVE)
                ->first();

            if (! $form) {
                return null;                   // 表单不存在 / 不属于该租户 / 未发布 → 转 404
            }

            return ['form' => $form, 'tenant' => $tenant];
        } finally {
            // ④ 异常路径也要清理，避免污染后续请求（FrankenPHP 常驻进程）。
            TenantContext::forget();
        }
    }
}
