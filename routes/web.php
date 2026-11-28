<?php

use Illuminate\Support\Facades\Route;

Route::get('/login', function () {
    if (\App\Support\PortableDesktopAccess::enabled()) {
        return redirect('/local-start');
    }

    return view('login', ['registrationEnabled' => \Illuminate\Support\Facades\Schema::hasTable('instance_settings')
        && \App\Services\Auth\LocalAccounts::registrationEnabled()]);
});
Route::get('/local-start', function () {
    abort_unless(\App\Support\PortableDesktopAccess::enabled(), 404);

    return response()->view('local-start')->withHeaders([
        'Cache-Control' => 'no-store, max-age=0',
        'Referrer-Policy' => 'no-referrer',
        'X-Frame-Options' => 'DENY',
        'X-Robots-Tag' => 'noindex, nofollow',
        'Content-Security-Policy' => "default-src 'self'; base-uri 'none'; object-src 'none'; frame-ancestors 'none'; form-action 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; font-src 'self'; img-src 'self' data:",
    ]);
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
