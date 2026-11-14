<?php

namespace Tests\Feature;

use Tests\TestCase;

class TenantWorkspaceNavigationTest extends TestCase
{
    public function test_standalone_pages_render_the_same_workbench_navigation_with_their_active_page(): void
    {
        config()->set([
            'geo.visibility_panel_enabled' => true,
            'geo.analytics_panel_enabled' => true,
            'geo.manual_publish_enabled' => true,
            'geo.theme_package_enabled' => true,
        ]);

        foreach (['visibility', 'analytics', 'manual-publish', 'theme-package'] as $page) {
            $html = view($page, ['cspNonce' => 'test-nonce'])->render();
            $this->assertStringContainsString('aria-label="言及工作台一级菜单"', $html, $page);
            $this->assertStringContainsString('href="/#projects/list"', $html, $page);
            $this->assertStringContainsString('data-tenant-page="'.$page.'"', $html, $page);
            $this->assertMatchesRegularExpression(
                '/data-tenant-page="'.preg_quote($page, '/').'"[^>]*aria-current="page"/',
                $html,
                $page
            );
            $this->assertStringContainsString('id="geoPageMenuToggle"', $html, $page);
        }
    }

    public function test_disabled_module_links_are_absent_from_the_shared_navigation(): void
    {
        config()->set([
            'geo.visibility_panel_enabled' => true,
            'geo.analytics_panel_enabled' => false,
            'geo.manual_publish_enabled' => false,
            'geo.theme_package_enabled' => false,
        ]);

        $html = view('visibility', ['cspNonce' => 'test-nonce'])->render();
        $this->assertStringContainsString('data-tenant-page="visibility"', $html);
        $this->assertStringNotContainsString('data-tenant-page="analytics"', $html);
        $this->assertStringNotContainsString('data-tenant-page="manual-publish"', $html);
        $this->assertStringNotContainsString('data-tenant-page="theme-package"', $html);
    }
}
