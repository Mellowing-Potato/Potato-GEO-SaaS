<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class WorkspaceShellRenderTest extends TestCase
{
    private const PAGES = ['/', '/visibility', '/analytics', '/manual-publish'];

    private const PAGE_FLAGS = [
        'visibility' => 'visibility_panel_enabled',
        'analytics' => 'analytics_panel_enabled',
        'manual-publish' => 'manual_publish_enabled',
    ];

    public function createApplication()
    {
        // Public page routes are registered at boot; restore all process env values immediately afterwards.
        $previous = [];
        foreach (self::PAGE_FLAGS as $flag) {
            $key = 'GEO_'.strtoupper($flag);
            $previous[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
            putenv($key.'=true');
            $_ENV[$key] = $_SERVER[$key] = 'true';
        }
        try {
            return parent::createApplication();
        } finally {
            foreach ($previous as $key => $values) {
                putenv($values[0] === false ? $key : $key.'='.$values[0]);
                foreach (['_ENV' => 1, '_SERVER' => 2] as $global => $index) {
                    if ($values[$index] === null) {
                        unset($GLOBALS[$global][$key]);
                    } else {
                        $GLOBALS[$global][$key] = $values[$index];
                    }
                }
            }
        }
    }

    private function enablePageLinks(): void
    {
        foreach (self::PAGE_FLAGS as $flag) {
            config()->set('geo.'.$flag, true);
        }
    }

    private function renderedPage(string $page): DOMXPath
    {
        $html = $this->get($page)->assertOk()->getContent();
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($dom);
    }

    public function test_all_five_pages_render_four_external_links_without_spa_targets(): void
    {
        $this->enablePageLinks();
        foreach (self::PAGES as $page) {
            $xpath = $this->renderedPage($page);
            foreach (self::PAGE_FLAGS as $destination => $flag) {
                $links = $xpath->query("//*[@id='workspaceMenu']//a[@href='/{$destination}']");
                $this->assertSame(1, $links->length, "{$page} 应当有唯一的 /{$destination} 外链");
                $this->assertFalse($links->item(0)->hasAttribute('data-view-target'), "{$page} 的 /{$destination} 不能被 SPA 接管");
            }
        }
    }

    public function test_each_disabled_feature_removes_only_its_external_link_on_all_five_pages(): void
    {
        foreach (self::PAGE_FLAGS as $disabledPage => $disabledFlag) {
            $this->enablePageLinks();
            config()->set('geo.'.$disabledFlag, false);
            foreach (self::PAGES as $page) {
                $xpath = $this->renderedPage($page);
                foreach (self::PAGE_FLAGS as $destination => $flag) {
                    $this->assertSame(
                        $destination === $disabledPage ? 0 : 1,
                        $xpath->query("//*[@id='workspaceMenu']//a[@href='/{$destination}']")->length,
                        "{$page} 的 /{$destination} 外链必须遵守 geo.{$disabledFlag} 开关",
                    );
                }
            }
        }
    }

    public function test_frozen_team_entry_is_unique_and_hidden_in_the_workspace_settings_menu(): void
    {
        $this->enablePageLinks();
        $xpath = $this->renderedPage('/');
        $team = $xpath->query("//*[@id='teamMenuLink']");
        $this->assertSame(1, $team->length, '工作台必须保留唯一的 teamMenuLink');
        $this->assertTrue($team->item(0)->hasAttribute('hidden'), '工作台的团队入口必须保持 hidden');
        $this->assertSame(1, $xpath->query("//*[@id='settingsMenu']//*[@id='teamMenuLink' and @data-view-target='team']")->length);
    }

    public function test_work_and_settings_menus_have_no_duplicate_spa_view_targets(): void
    {
        $this->enablePageLinks();
        foreach (self::PAGES as $page) {
            $xpath = $this->renderedPage($page);
            $this->assertSame(1, $xpath->query("//*[@id='workspaceMenu']")->length, $page);
            $targets = [];
            foreach ($xpath->query("//*[@id='workspaceMenu' or @id='settingsMenu']//*[@data-view-target]") as $entry) {
                $target = $entry->getAttribute('data-view-target');
                $this->assertNotSame('', $target, "{$page} 的视图目标不能为空");
                $this->assertArrayNotHasKey($target, $targets, "{$page} 的 {$target} 入口重复");
                $targets[$target] = true;
            }
            if ($page === '/') {
                $this->assertSame(1, $xpath->query("//*[@id='settingsMenu']")->length);
                $this->assertGreaterThan(10, count($targets), 'SPA 菜单不能因入口丢失而假通过去重检查');
            } else {
                $this->assertSame([], $targets, "{$page} 应使用整页导航");
                $this->assertGreaterThan(5, $xpath->query("//*[@id='workspaceMenu']//*[@data-workspace-view]")->length);
            }
        }
    }

    public function test_credential_markers_are_absent_from_all_five_rendered_pages(): void
    {
        $this->enablePageLinks();
        $forbiddenIds = [
            'apiTokensPanel', 'apiTokenScopes', 'channelSecretButton', 'channelSecretResult',
            'channelAgentButton', 'channelAgentPackage', 'channelBeaconButton', 'channelBeaconResult',
        ];
        foreach (self::PAGES as $page) {
            $xpath = $this->renderedPage($page);
            foreach ($forbiddenIds as $id) {
                $this->assertSame(0, $xpath->query("//*[@id='{$id}']")->length, "{$page} 不允许出现凭据标记 {$id}");
            }
            $this->assertSame(0, $xpath->query("//*[@data-view-target='api-tokens' or @data-workspace-view='api-tokens']")->length, $page);
            foreach (['#api-tokens', '/api/v1/api-tokens', '/secrets', '/agent-package', '/beacon-plugin'] as $marker) {
                $this->assertSame(0, $xpath->query("//a[contains(@href, '{$marker}')]")->length, "{$page} 不允许出现凭据链接 {$marker}");
            }
        }
    }

    public function test_retired_theme_routes_are_unregistered_and_return_404(): void
    {
        // 明确保留退役路由 404 回归契约：已移除的主题包页面、预览与 API 端点均不得注册。
        $registered = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route): string => $route->uri())
            ->all();
        $leaked = array_values(array_filter(
            $registered,
            fn (string $uri): bool => str_starts_with($uri, 'api/v1/theme-packages')
                || str_starts_with($uri, 'theme-package')
                || str_starts_with($uri, 'theme-preview'),
        ));
        $this->assertSame([], $leaked);

        $this->get('/theme-package')->assertStatus(404);
        $this->get('/theme-preview/1/home')->assertStatus(404);
        $this->getJson('/api/v1/theme-packages')->assertStatus(404);
        $this->postJson('/api/v1/theme-packages', ['name' => 'x'])->assertStatus(404);
        $this->getJson('/api/v1/theme-packages/1')->assertStatus(404);
        $this->deleteJson('/api/v1/theme-packages/1')->assertStatus(404);
    }
}
