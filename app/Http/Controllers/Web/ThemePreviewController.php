<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\SiteThemeReplication;
use App\Services\ThemePackaging\SiteThemeReplicationService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * 主题包预览（单 10 · 红线 3 + 不可信内容渲染）。
 *
 * /theme-preview/{replicationId}/{page} 渲染一页「固定占位内容 + 这一版真实生成的
 * 配色与字体」的示例页（见 resources/views/theme-package/preview.blade.php）。
 * 草稿里的 .blade.php 一行都不执行、也不回显 —— 那些模板是给客户自己的站用的，
 * 本仓渲染不了，原样返回等于给客户看一屏源码。
 *
 * 归属与防护：
 *   - 必须登录（auth:sanctum）；
 *   - 显式校验 $replication->tenant_id === 登录用户租户（web 路由默认无租户上下文，
 *     TenantScope fail-open，不能只靠 URL 里的 id 判归属——用户能把别人的 id 填进 URL）；
 *   - 响应头带 CSP（default-src 'none'，只放行内联样式，script-src 'none'）
 *     + X-Frame-Options: DENY。
 *     ⚠️ 前端是把这段 HTML 塞进 iframe.srcdoc 的，srcdoc 不走响应头 → 这几个头对
 *        面板内的预览不生效；面板那边靠 iframe 的 sandbox="" 兜底（无脚本、无同源）。
 *        这里的头是给「直接打开这个 URL」的场景用的，两条路都得堵。
 */
class ThemePreviewController extends Controller
{
    public function __construct(private readonly SiteThemeReplicationService $service) {}

    public function show(Request $request, int $replicationId, string $page): SymfonyResponse
    {
        $replication = SiteThemeReplication::query()->find($replicationId);
        if (! $replication) {
            abort(404);
        }

        $viewerTenantId = $request->user()?->tenant_id;
        if ($viewerTenantId === null || (int) $viewerTenantId !== (int) $replication->tenant_id) {
            abort(403);
        }

        $preview = $this->service->previewPage($replication, $page);
        if (! $preview) {
            abort(404, __('theme_package.error.preview_unavailable'));
        }

        return response()
            ->view('theme-package.preview', [
                'page' => $preview['page'],
                'version' => $preview['version'],
                'themeCss' => $preview['css'],
                'themeName' => (string) $replication->name,
                'pageLabel' => __('theme_package.preview.'.$preview['page']),
                'title' => __('theme_package.safe_preview.'.$preview['page'].'_title'),
                'description' => __('theme_package.safe_preview.'.$preview['page'].'_description'),
                'cards' => $this->cards($preview['page']),
            ])
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; script-src 'none'; frame-ancestors 'none'")
            ->header('X-Frame-Options', 'DENY')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * @return list<array{title:string,description:string}>
     */
    private function cards(string $page): array
    {
        $cards = [];
        foreach (['one', 'two', 'three'] as $slot) {
            $cards[] = [
                'title' => __('theme_package.safe_preview.'.$page.'_card_'.$slot.'_title'),
                'description' => __('theme_package.safe_preview.'.$page.'_card_'.$slot.'_description'),
            ];
        }

        return $cards;
    }
}
