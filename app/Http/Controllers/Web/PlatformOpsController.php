<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * GEO 商业运营台（平台侧跨租户商业控制台）
 *
 * 与 /workbench（租户侧）完全隔离，仅 platform:operations 权限可见。
 * 无权限时返回 403 守卫页，不渲染任何平台数据（红线：平台数据不得对非运营角色泄露）。
 */
class PlatformOpsController extends Controller
{
    public function index(Request $request)
    {
        // 方案 B：壳页先渲染，前端 session-login 拿 Sanctum token 后做客户端守卫。
        // 浏览器首次 GET 页面永远不会带 Authorization 头，服务端 $request->user() 恒为 null，
        // 故服务端守卫分支是死码，已删除。真实权限门在 API 层（auth:sanctum + can:platform:operations）。
        // 壳中不含任何平台数据，仅注入登录地址（取自配置，不写死域名）供前端跳转。

        $cssVersion = file_exists(public_path('geo-platform-ops.css')) ? filemtime(public_path('geo-platform-ops.css')) : time();
        $jsVersion  = file_exists(public_path('geo-platform-ops.js'))  ? filemtime(public_path('geo-platform-ops.js'))  : time();

        $isProduction = config('app.env') === 'production';

        $geoOpsConfig = [
            'accountLoginUrl' => url('/login'),
            'accountUrl'      => url('/#personal-settings'),
        ];

        return view('platform-ops', [
            'isProduction' => $isProduction,
            'envLabel'     => $isProduction ? '生产环境' : '测试环境',
            'geoOpsConfig' => $geoOpsConfig,
            'cssVersion'   => $cssVersion,
            'jsVersion'    => $jsVersion,
        ]);
    }
}
