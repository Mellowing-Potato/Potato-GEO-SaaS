<?php

use App\Http\Controllers\Web\ThemePreviewController;
use Illuminate\Support\Facades\Route;

Route::get('/login', function () {
    return view('login', ['registrationEnabled' => \Illuminate\Support\Facades\Schema::hasTable('instance_settings')
        && \App\Services\Auth\LocalAccounts::registrationEnabled()]);
});
Route::redirect('/account', '/#personal-settings');

Route::get('/', function () {
    return view('welcome');
});

/* 重构版工作台（独立模块，未并入 welcome.blade.php，待后端面板清单对齐后合并）
   放量前收尾（T1）：默认隐藏，仅当 GEO_WORKBENCH_V2_ENABLED=true 才注册这两条路由，
   防止客户直达 /workbench 看到 v2 未完成的假数据。关时路由不存在 → 匿名访问 404。
   红线：不改动 v1 主路径 /（welcome/geo-entry.js）与 /platform/ops 运营台。 */
if (config('geo.workbench_v2_enabled', false)) {
    Route::get('/workbench', function () {
        return view('workbench');
    });

}

/* 站内文章托管已退役。旧链接返回 410，明确告知爬虫移除索引。 */
$retiredPublicPage = static fn () => response('此页面已撤下。', 410, [
    'Cache-Control' => 'no-store, max-age=0',
    'X-Robots-Tag' => 'noindex, nofollow',
]);
Route::get('/read/{tenantSlug}/{slug}', $retiredPublicPage)->name('reading.article');
Route::get('/sitemap.xml', $retiredPublicPage)->name('sitemap.index');
Route::get('/sitemaps/{tenantSlug}.xml', $retiredPublicPage)->name('sitemap.tenant');
Route::get('/sitemaps/{tenantSlug}/projects/{projectId}.xml', $retiredPublicPage)
    ->whereNumber('projectId')->name('sitemap.project');

/* GEO 商业运营台（平台侧跨租户商业控制台，与 /workbench 完全隔离）
   页面本身公开渲染空壳（与 /workbench 同构）：先渲染，前端 session-login 拿 Sanctum token 后客户端守卫。
   真实权限门在 API 层：routes/api.php 的 /v1/platform/* 已挂 auth:sanctum + can:platform:operations + log-admin-activity。 */
Route::get('/platform/ops', [App\Http\Controllers\Web\PlatformOpsController::class, 'index']);

/* Phase 1：AI 爬虫可见性面板（租户侧，与 /workbench 同构的净新增看板）
   默认隐藏，仅当 GEO_ANALYTICS_PANEL_ENABLED=true 才注册 /analytics 路由（防未接线前对客户露出）。
   红线：真实权限门在 API 层（routes/api.php 的 /v1/analytics/* 已挂 auth:sanctum + can:analytics:view）。 */
if (config('geo.analytics_panel_enabled', false)) {
    Route::get('/analytics', function () {
        return view('analytics');
    });
}

/* Phase 1：AI 可见性监测面板（租户侧，与 /analytics 同构净新增看板）
   默认隐藏，仅当 geo.visibility_panel_enabled=true 才注册 /visibility（防未接线前对客户露出）。
   红线：真实权限门在 API 层（routes/api.php 的 /v1/visibility/* 已挂 auth:sanctum）。 */
if (config('geo.visibility_panel_enabled', false)) {
    Route::get('/visibility', function () {
        return view('visibility');
    });
}

/* 单 7：手动发布模块（租户侧，与 /visibility 同构净新增看板）
   默认隐藏，仅当 GEO_MANUAL_PUBLISH_ENABLED=true 才注册 /manual-publish（防未接线前对客户露出）。
   真实权限门在 API 层（routes/api.php 的 /v1/manual-publications/* 已挂 auth:sanctum + can:manual_publish:manage）。 */
if (config('geo.manual_publish_enabled', false)) {
    Route::get('/manual-publish', function () {
        return view('manual-publish');
    });
}

/* 单 10：主题包生成模块（租户侧，与 /manual-publish 同构净新增看板）
   默认隐藏，仅当 GEO_THEME_PACKAGE_ENABLED=true 才注册 /theme-package（防未接线前对客户露出）。
   真实权限门在 API 层（routes/api.php 的 /v1/theme-packages/* 已挂 auth:sanctum + can:theme_package:manage）。
   预览路由 /theme-preview/{replicationId}/{page} 直接渲染用户生成 HTML（不可信内容），
   必须登录 + 显式校验归属（不能只靠路径里有 tenant_id），并带 CSP / X-Frame-Options。 */
if (config('geo.theme_package_enabled', false)) {
    Route::get('/theme-package', function () {
        return view('theme-package');
    });

    Route::get('/theme-preview/{replicationId}/{page}', [ThemePreviewController::class, 'show'])
        ->middleware([
            'auth:sanctum',
            \App\Http\Middleware\EnforceAccountSessionBinding::class,
            \App\Http\Middleware\EnforceMachineTokenScope::class,
            'can:theme_package:manage',
        ])
        ->whereNumber('replicationId')
        ->where('page', 'home|category|article');
}

/* 单 8：线索表单公开页（租户侧，无登录、无租户中间件）
   默认隐藏，仅当 GEO_LEAD_FORM_ENABLED=true 才注册 /forms/{tenantSlug}/{slug} 两条路由
   （防未接线前对客户露出，且是回滚开关：关掉路由即不存在 → 匿名访问 404，L10）。
   红线（执行书 §一 / §二-4）：
   - GET 公开页 + POST 提交都经 LeadFormDeliveryService 显式按 tenant_id 定位，绝不靠
     全局作用域 fail-open（否则 A 租户链接能开 B 租户表单，L2）；
   - POST 挂 throttle:lead-form-submit（双维度 IP + 表单，限流计数走共享 cache，多容器生效）；
   - 不挂 tenant 中间件（按 URL slug 判租户）、不挂 idempotency（访客不带幂等键）。 */
if (config('geo.lead_form_enabled', false)) {
    Route::get('/forms/{tenantSlug}/{slug}', [App\Http\Controllers\Web\LeadFormPageController::class, 'show'])
        // 评审修复 H2：GET 也挂限流（此前零限流但每次访问都落 visit 行，匿名刷 GET = 无限写库）。
        ->middleware(['throttle:lead-form-view', App\Http\Middleware\LeadFormVisitMiddleware::class]) // terminable 记真实 status_code（含 404）
        ->name('lead-form.show');

    Route::post('/forms/{tenantSlug}/{slug}', [App\Http\Controllers\Web\LeadFormPageController::class, 'submit'])
        ->middleware('throttle:lead-form-submit')
        ->name('lead-form.submit');
}
