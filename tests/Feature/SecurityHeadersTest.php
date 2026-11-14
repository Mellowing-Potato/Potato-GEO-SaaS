<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * 工作台安全响应头（计划 §9.1，2026-09-21）。
 *
 * 守四件事：
 *   1. HTML 页面必须带上 nosniff / X-Frame-Options / Referrer-Policy / Permissions-Policy；
 *   2. CSP 默认只 report-only（防白页），显式切 enforce 才真的拦；
 *   3. 业务自己设过的头**不被放宽**（theme-preview 用 DENY 渲染用户 HTML）；
 *   4. HSTS 只在请求确实是 https 时发（http 下浏览器本来就忽略，发了是误导）。
 */
class SecurityHeadersTest extends TestCase
{
    public function test_workbench_page_carries_baseline_security_headers(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    public function test_csp_defaults_to_report_only_and_can_be_enforced(): void
    {
        $reportOnly = $this->get('/');
        $this->assertTrue($reportOnly->headers->has('Content-Security-Policy-Report-Only'));
        $this->assertFalse($reportOnly->headers->has('Content-Security-Policy'));
        $this->assertStringContainsString(
            "frame-ancestors 'self'",
            (string) $reportOnly->headers->get('Content-Security-Policy-Report-Only'),
        );

        config(['geo.security_headers.csp_mode' => 'enforce']);
        $enforced = $this->get('/');
        $this->assertTrue($enforced->headers->has('Content-Security-Policy'));
        $this->assertFalse($enforced->headers->has('Content-Security-Policy-Report-Only'));
        $this->assertStringContainsString("object-src 'none'", (string) $enforced->headers->get('Content-Security-Policy'));
    }

    public function test_existing_headers_are_not_loosened(): void
    {
        $middleware = new SecurityHeaders;
        $request = Request::create('/theme-preview/1/home', 'GET');

        $response = $middleware->handle($request, fn () => response('x', 200, [
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "default-src 'none'",
        ]));

        // 逐页更严的策略必须原样保留 —— 中间件只补缺失项，不做"统一覆盖"。
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame("default-src 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertFalse($response->headers->has('Content-Security-Policy-Report-Only'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_hsts_only_on_https_requests(): void
    {
        // 明文 http：不发 HSTS（发了也会被浏览器忽略，只会让人误以为已启用）。
        $this->assertFalse($this->get('/')->headers->has('Strict-Transport-Security'));

        // 经可信代理转发的 https：发 HSTS。测试请求来自 127.0.0.1，在 trustedProxies 里。
        $https = $this->withHeader('X-Forwarded-Proto', 'https')->get('/');
        $this->assertTrue($https->headers->has('Strict-Transport-Security'));
        $this->assertStringContainsString('max-age=15552000', (string) $https->headers->get('Strict-Transport-Security'));
        $this->assertStringContainsString('includeSubDomains', (string) $https->headers->get('Strict-Transport-Security'));
    }

    public function test_switch_can_turn_the_whole_set_off(): void
    {
        config(['geo.security_headers.enabled' => false]);

        $response = $this->get('/');

        $response->assertOk();
        $this->assertFalse($response->headers->has('X-Content-Type-Options'));
        $this->assertFalse($response->headers->has('X-Frame-Options'));
        $this->assertFalse($response->headers->has('Content-Security-Policy-Report-Only'));
    }
}
