<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * CSP nonce 覆盖（计划 §9.1 的 enforce 前置条件，2026-09-21）。
 *
 * 为什么必须有这条：CSP3 规定——只要 script-src 里出现 nonce，同一列表里的 `'unsafe-inline'`
 * 会被**忽略**。也就是说"内联脚本漏挂 nonce"这件事在 report-only 下完全看不出来（页面照常跑），
 * 一旦切 enforce 就是**白页**。人眼复查十个模板靠不住，所以：
 *
 *   1. 静态扫 `resources/views` 下所有 `*.blade.php`：任何内联 `<script>` / `<style>` 必须挂 nonce；
 *   2. 真发一次请求：策略里的 nonce 与页面里内联脚本挂的 nonce **必须是同一个值**；
 *   3. 默认仍是 report-only —— 切 enforce 是显式的运维动作（GEO_CSP_MODE），不能被代码顺手改掉。
 */
class CspNonceCoverageTest extends TestCase
{
    public function test_every_inline_script_and_style_tag_carries_a_nonce(): void
    {
        $offenders = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $source = File::get($file->getPathname());
            $relative = str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $file->getPathname());

            // 内联 = 没有 src= 的 <script>；<style> 一律内联。两者都必须带 nonce。
            preg_match_all('/<script\b[^>]*>/i', $source, $scripts);
            foreach ($scripts[0] as $tag) {
                if (preg_match('/\bsrc\s*=/i', $tag)) {
                    continue;
                }
                if (! preg_match('/\bnonce\s*=/i', $tag)) {
                    $offenders[] = $relative.' → '.trim($tag);
                }
            }
            preg_match_all('/<style\b[^>]*>/i', $source, $styles);
            foreach ($styles[0] as $tag) {
                if (! preg_match('/\bnonce\s*=/i', $tag)) {
                    $offenders[] = $relative.' → '.trim($tag);
                }
            }
        }

        $this->assertSame([], $offenders,
            "内联脚本/样式没挂 CSP nonce（enforce 之后这些页会白屏）：\n".implode("\n", $offenders));
    }

    public function test_policy_nonce_matches_the_inline_script_nonce_on_the_page(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $policy = (string) $response->headers->get('Content-Security-Policy-Report-Only');
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([^']+)'/", $policy);
        preg_match("/script-src 'self' 'nonce-([^']+)'/", $policy, $matches);
        $policyNonce = $matches[1];

        // 页面里的内联脚本必须挂**同一个** nonce，否则 enforce 时它就是被拦的那一个。
        $html = (string) $response->getContent();
        $this->assertMatchesRegularExpression('/<script nonce="'.preg_quote($policyNonce, '/').'"/', $html,
            '页面内联脚本的 nonce 与响应头里的 nonce 不一致');
    }

    public function test_script_src_no_longer_needs_unsafe_inline_in_the_shipped_policy(): void
    {
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", SecurityHeaders::cspWithNonce('abc123'));
        $this->assertStringContainsString("script-src 'self' 'nonce-abc123'", SecurityHeaders::cspWithNonce('abc123'));
        // style-src 仍保留 unsafe-inline：页面里有 43 处内联 style 属性，而 style 属性挂不了 nonce。
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline'", SecurityHeaders::cspWithNonce('abc123'));
    }

    public function test_csp_stays_report_only_by_default(): void
    {
        $this->assertSame('report-only', config('geo.security_headers.csp_mode'),
            'CSP 默认必须是 report-only：切 enforce 是运维显式动作（GEO_CSP_MODE），不能由代码默认打开');
    }
}
