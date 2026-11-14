<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 反向代理信任配置（bootstrap/app.php 的 trustProxies，2026-08-28 补）。
 *
 * 生产是「公网 → 边缘 Caddy 终止 TLS → 明文 HTTP → geo-saas 容器」。没配这个之前，
 * $request->ip() 对所有访客都返回边缘 Caddy 的内网地址、协议被认成 http，
 * 于是「按 IP 限流」变成全平台共桶、asset() 在 https 页面上吐 http:// 被当混合内容拦掉、
 * 落库和审计日志记的 IP 全是代理地址。
 *
 * 这些测试就是那条配置的守卫：谁改动它，这里立刻变红。
 */
class TrustedProxyTest extends TestCase
{
    /** 内网代理转发过来的请求，客户端地址要从 X-Forwarded-For 取，不能是代理自己的地址。 */
    public function test_client_ip_comes_from_forwarded_header_behind_internal_proxy(): void
    {
        $this->withServerVariables([
            'REMOTE_ADDR' => '172.18.0.5',            // 边缘 Caddy 在 docker 网里的地址
            'HTTP_X_FORWARDED_FOR' => '203.0.113.77', // 真实访客
        ])->get('/up')->assertOk();

        $this->assertSame('203.0.113.77', request()->ip());
    }

    /** 边缘终止 TLS 后是明文到达容器，协议必须按 X-Forwarded-Proto 认成 https。 */
    public function test_scheme_comes_from_forwarded_proto(): void
    {
        $this->withServerVariables([
            'REMOTE_ADDR' => '172.18.0.5',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->get('/up')->assertOk();

        $this->assertTrue(request()->isSecure(), '协议没按 X-Forwarded-Proto 认成 https');
        $this->assertSame('https', request()->getScheme());
    }

    /**
     * X-Forwarded-Host **故意不信**：Host 头本来就由 Caddy 原样透传，
     * 多信一个可伪造的域名来源只会开出 host header 注入面。
     */
    public function test_forwarded_host_is_not_trusted(): void
    {
        // 用绝对地址发请求，让测试客户端真的以 app.example.test 建请求（否则 host 恒为 localhost）。
        $this->withServerVariables([
            'REMOTE_ADDR' => '172.18.0.5',
            'HTTP_X_FORWARDED_HOST' => 'evil.example.com',
        ])->get('http://app.example.test/up')->assertOk();

        $this->assertNotSame('evil.example.com', request()->getHost(), 'X-Forwarded-Host 被信了，存在 host 注入面');
        $this->assertSame('app.example.test', request()->getHost());
    }

    /**
     * 伪造防护：请求不是从可信内网地址来的（例如有人直连容器），
     * 它自己塞的 X-Forwarded-For 一律不作数。
     */
    public function test_forged_forwarded_for_from_untrusted_peer_is_ignored(): void
    {
        $this->withServerVariables([
            'REMOTE_ADDR' => '198.51.100.9',          // 非内网 = 不是我们的代理
            'HTTP_X_FORWARDED_FOR' => '203.0.113.77', // 自己塞的
        ])->get('/up')->assertOk();

        $this->assertSame('198.51.100.9', request()->ip(), '非可信来源的 X-Forwarded-For 被采信了');
    }

    /**
     * 代理链里客户端自己先塞了一条：Caddy 会把真实地址追加在其后，
     * Symfony 从右往左取第一个非可信地址 —— 拿到的仍应是真实客户端，不是伪造值。
     */
    public function test_spoofed_entry_prepended_by_client_does_not_win(): void
    {
        $this->withServerVariables([
            'REMOTE_ADDR' => '172.18.0.5',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 203.0.113.77', // 左：伪造；右：Caddy 追加的真实地址
        ])->get('/up')->assertOk();

        $this->assertSame('203.0.113.77', request()->ip(), '伪造的左侧条目胜出了');
    }
}
