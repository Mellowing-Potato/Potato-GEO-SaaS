<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * 工作台/公开页面的安全响应头（计划 §9.1，2026-09-21）。
 *
 * 为什么需要：此前应用层只有 theme-preview 一条路由和几个下载接口自己打了安全头，
 * 工作台（/）、公开阅读页、线索表单这些 HTML 页面完全没有 —— 点击劫持、MIME 嗅探、
 * 跨站引用来源泄漏这三类问题都还在。边界也在生产上明说了：容器只 expose 8000，
 * 唯一入口是边缘 Caddy，所以这些头必须在应用层发，否则就要靠运维在 Caddy 里补，
 * 而那条路没有版本控制、回滚不了。
 *
 * 三条铁律：
 *   1. **不覆盖**业务自己已经设过的头。theme-preview 用 X-Frame-Options: DENY
 *      （它渲染用户生成的 HTML，必须比全局更严），这里再写一次就把它放宽了。
 *   2. HSTS 只在请求确实是 https 时发。HTTP 下浏览器直接忽略，发了只是噪音；
 *      更重要的是避免在本地/测试的 http 上误导人以为已经启用。
 *   3. CSP 默认 **report-only**。工作台有内联脚本（window.GEO_* 配置）与内联样式，
 *      没做过浏览器逐页复核就 enforce 会直接白页。改 enforce 是显式的运维动作（GEO_CSP_MODE）。
 *
 * 2026-09-21 补：**nonce 改造（enforce 的前置条件）已经做完** —— 每次请求生成一个 nonce，
 * 注入视图（`$cspNonce`），内联 `<script>` / `<style>` 标签都带上它，策略里的 script-src
 * 也从 `'unsafe-inline'` 换成 `'nonce-…'`。这样切 enforce 只需要把 GEO_CSP_MODE 改成 enforce
 * （默认仍是 report-only，行为不变）。style-src 暂时保留 `'unsafe-inline'`：页面里有 43 处
 * 内联 style 属性（CSP3 规定只要 script/style-src 里出现 nonce，'unsafe-inline' 会被忽略，
 * 而 style 属性没法挂 nonce），要收紧得先把那些属性挪进类名，那是另一件事。
 */
class SecurityHeaders
{
    /** 视图里取 nonce 用：`nonce="{{ $cspNonce }}"`。 */
    public const NONCE_VIEW_KEY = 'cspNonce';

    /**
     * 默认策略：允许自身来源的资源与内联样式/脚本（当前前端形态需要），
     * 禁止外部对象、禁止被跨站嵌入、禁止跨站提交表单。
     * 它挡不住内联注入 —— 那是 enforce 之前必须先做完 nonce/hash 改造的部分，
     * 所以默认只 report-only，不假装已经解决。
     */
    public const DEFAULT_CSP = "default-src 'self'; "
        ."base-uri 'self'; "
        ."object-src 'none'; "
        ."frame-ancestors 'self'; "
        ."form-action 'self'; "
        ."img-src 'self' data: blob:; "
        ."font-src 'self' data:; "
        ."style-src 'self' 'unsafe-inline'; "
        ."script-src 'self' 'unsafe-inline'; " // 有 nonce 时会被忽略，见 DEFAULT_CSP_WITH_NONCE
        ."connect-src 'self'";

    /**
     * 带 nonce 的策略：script-src 只认 `'self'` + 本次请求的 nonce。
     *
     * ⚠️ CSP3：source list 里一旦出现 nonce，同一列表里的 `'unsafe-inline'` 会被**忽略** ——
     * 所以内联脚本必须都挂上 nonce，漏一个在 enforce 下就是一个白页。静态护栏
     * （tests/Feature/CspNonceCoverageTest.php）盯着这件事，别靠人眼复查。
     */
    public static function cspWithNonce(string $nonce): string
    {
        return str_replace(
            "script-src 'self' 'unsafe-inline';",
            "script-src 'self' 'nonce-".$nonce."';",
            self::DEFAULT_CSP,
        );
    }

    /** 每次请求一个 nonce（16 字节随机 → base64）。 */
    public static function generateNonce(): string
    {
        return base64_encode(random_bytes(16));
    }

    public function handle(Request $request, Closure $next): Response
    {
        // nonce 必须在**视图渲染之前**生成：视图里要用它给内联脚本/样式标签挂属性。
        $nonce = self::generateNonce();
        $request->attributes->set('csp_nonce', $nonce);
        try {
            View::share(self::NONCE_VIEW_KEY, $nonce);
        } catch (\Throwable) {
            // 没有视图工厂（纯 API 请求、单元测试直接 new 中间件）时不是错误。
        }

        $response = $next($request);

        if (! config('geo.security_headers.enabled', true)) {
            return $response;
        }

        $this->setIfMissing($response, 'X-Content-Type-Options', 'nosniff');
        $this->setIfMissing($response, 'X-Frame-Options', (string) config('geo.security_headers.frame_options', 'SAMEORIGIN'));
        $this->setIfMissing($response, 'Referrer-Policy', 'strict-origin-when-cross-origin');
        // 本产品不用摄像头/麦克风/定位，直接声明关闭，省得将来某个页面被塞进一段脚本静默申请权限。
        $this->setIfMissing($response, 'Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        $hstsMaxAge = (int) config('geo.security_headers.hsts_max_age', 15552000);
        if ($request->secure() && $hstsMaxAge > 0) {
            $value = 'max-age='.$hstsMaxAge;
            if (config('geo.security_headers.hsts_include_subdomains', true)) {
                $value .= '; includeSubDomains';
            }
            $this->setIfMissing($response, 'Strict-Transport-Security', $value);
        }

        $mode = strtolower(trim((string) config('geo.security_headers.csp_mode', 'report-only')));
        if ($mode !== 'off' && ! $response->headers->has('Content-Security-Policy')
            && ! $response->headers->has('Content-Security-Policy-Report-Only')) {
            $policy = trim((string) config('geo.security_headers.csp_policy', '')) ?: self::cspWithNonce($nonce);
            $reportUri = trim((string) config('geo.security_headers.csp_report_uri', ''));
            if ($reportUri !== '') {
                $policy .= "; report-uri ".$reportUri;
            }
            $header = $mode === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';
            $response->headers->set($header, $policy);
        }

        return $response;
    }

    private function setIfMissing(Response $response, string $name, string $value): void
    {
        if ($value === '' || $response->headers->has($name)) {
            return;
        }

        $response->headers->set($name, $value);
    }
}
