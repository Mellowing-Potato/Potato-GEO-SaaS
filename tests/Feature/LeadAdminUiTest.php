<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 线索表单管理台 · 接线对账（单 8 第 5 步）。
 *
 * 这个仓在「前端接后端」上系统性翻过车（见 design-specs/prototypes/
 * 前端接后端-防走偏与纠偏规程-2026-07-15.md：工作台 v2 七个视图里六个接线错，
 * 还被 fixture 回落掩盖住了）。所以管理台不能只靠人点一遍就算数，
 * 得有机器盯着这两件事：
 *
 *  ① 前端调的每一个线索接口，路由表里真的有 —— 防「照原型脑补后端」；
 *  ② 前端 getElementById 绑的每一个 id，Blade 里真的有 —— 防「绑了个不存在的元素，
 *    功能静默失效」。这类 bug 页面看起来完全正常，人眼极难发现。
 *
 * 另加一条：开关关掉时菜单和面板必须一起消失（执行书 §七-8 的第一道回滚）。
 */
class LeadAdminUiTest extends TestCase
{
    use RefreshDatabase;

    private function workbenchHtml(): string
    {
        return $this->get('/')->assertStatus(200)->getContent();
    }

    private function entryScript(): string
    {
        // 2026-09-21 §7：前端入口已按区块拆成 `geo-entry.js` + `geo-entry-*.js`（第七刀把
        // 线索管理台整块搬去了 geo-entry-leads.js）。这里照 tests/js/helpers/entry-source.mjs
        // 的口径把"主文件 + 拆出块"拼成一份来扫 —— 否则每拆一块，这个测试就要跟着改一次路径，
        // 而它想守的是"前端接线与后端契约对得上"，跟代码住在哪个文件里无关。
        $files = glob(public_path('geo-entry*.js')) ?: [];
        $main = public_path('geo-entry.js');
        usort($files, static function ($a, $b) use ($main) {
            if ($a === $main) {
                return -1;
            }
            if ($b === $main) {
                return 1;
            }

            return strcmp($a, $b);
        });

        return implode("\n", array_map(static fn ($path) => (string) file_get_contents($path), $files));
    }

    /** 只取线索模块那一段，免得把整仓其它模块的 id 也扫进来。 */
    private function leadModuleSource(): string
    {
        $script = $this->entryScript();
        $start = strpos($script, '// 线索表单管理台（单 8');
        $this->assertNotFalse($start, 'geo-entry.js 里找不到线索管理台模块的起始注释——模块被删或改名了？');
        // 结束锚点：2026-09-21 §7 第七刀把线索模块整块搬进 geo-entry-leads.js，块尾紧接着
        // 该文件的「注册与回挂」段 —— 拿它当结束锚点，模块往后无论怎么搬都在同一份拼出来的
        // 源码里对得上。（第五刀那版拿「素材库视图事件绑定」当锚点，锚点被搬走就断了。）
        $end = strpos($script, '// 注册与回挂（拆分前这些都在 geo-entry.js 里）', $start);
        $this->assertNotFalse($end, '线索管理台模块的结束锚点不见了');

        return substr($script, $start, $end - $start);
    }

    public function test_workbench_renders_lead_panel_and_menu_when_switch_on(): void
    {
        config(['geo.lead_form_enabled' => true]);
        $html = $this->workbenchHtml();

        $this->assertStringContainsString('data-workspace-view="leads"', $html, '线索面板没有渲染');
        $this->assertStringContainsString('data-view-target="leads"', $html, '侧栏没有线索表单入口');
        // 三个页签都在
        foreach (['submissions', 'forms', 'analytics'] as $tab) {
            $this->assertStringContainsString('data-lead-tab="'.$tab.'"', $html, "缺页签 {$tab}");
            $this->assertStringContainsString('data-lead-pane="'.$tab.'"', $html, "缺页签内容区 {$tab}");
        }
    }

    public function test_lead_menu_and_panel_both_disappear_when_switch_off(): void
    {
        // 第一道回滚：只关公开页会留下一个「能建表单、但给的链接 404」的管理台，
        // 所以菜单和面板必须跟公开页共用同一个开关。
        config(['geo.lead_form_enabled' => false]);
        $html = $this->workbenchHtml();

        $this->assertStringNotContainsString('data-workspace-view="leads"', $html, '开关关掉后线索面板仍在渲染');
        $this->assertStringNotContainsString('data-view-target="leads"', $html, '开关关掉后侧栏仍有线索入口');
    }

    public function test_every_lead_endpoint_the_frontend_calls_is_a_registered_route(): void
    {
        $module = $this->leadModuleSource();

        // 字符类必须含大写：插值里是 ${leadSubmissionQuery()} 这种驼峰函数名，
        // 漏了 A-Z 会把 URL 截成半截，测出来的是假失败。
        preg_match_all('#/api/v1/lead[A-Za-z0-9\-/${}().,\x27"\s_]*#u', $module, $matches);
        $this->assertNotEmpty($matches[0], '线索模块里一个 /api/v1/lead 接口都没有？');

        $registered = collect(Route::getRoutes())
            ->map(fn ($route): string => $route->uri())
            ->filter(fn (string $uri): bool => str_starts_with($uri, 'api/v1/lead'))
            ->unique()
            ->values();
        $this->assertNotEmpty($registered, '路由表里没有任何线索接口');

        $checked = [];
        foreach ($matches[0] as $raw) {
            // 顺序要紧：先把 ${...} 整体换成占位符，再按分隔符切。
            // 反过来做会被插值内部的右括号（如 encodeURIComponent(id)）从中间截断。
            $path = preg_replace('/\$\{[^}]*\}/', "\x01", ltrim(trim($raw), '/'));
            $path = preg_split('/[`\'"\s,)]/', (string) $path)[0];
            $path = preg_split('/\?/', (string) $path)[0];
            if ($path === '' || isset($checked[$path])) {
                continue;
            }
            $checked[$path] = true;

            // 插值有两种用途：一种是路径段（/leads/${id}），一种是拼查询串
            // （/leads${query()}）。两种都试，任一命中即算这条 URL 对得上路由。
            $candidates = [
                rtrim(str_replace("\x01", '1', $path), '/'),
                rtrim(str_replace("\x01", '', $path), '/'),
            ];

            $hit = false;
            foreach ($candidates as $candidate) {
                if ($registered->contains(function (string $uri) use ($candidate): bool {
                    $pattern = '#^'.preg_replace('/\\\{[^}]*\\\}/', '[^/]+', preg_quote($uri, '#')).'$#';

                    return preg_match($pattern, $candidate) === 1;
                })) {
                    $hit = true;
                    break;
                }
            }

            $this->assertTrue($hit, '前端调了 /'.str_replace("\x01", '${…}', $path).'（已注册：'.$registered->implode(', ').'）');
        }

        // 别让这条测试因为正则没抓到东西而空跑
        $this->assertGreaterThanOrEqual(5, count($checked), '抓到的接口太少，正则大概率失效了');
    }

    public function test_every_element_id_the_frontend_binds_is_present_in_the_blade(): void
    {
        config(['geo.lead_form_enabled' => true]);
        $html = $this->workbenchHtml();
        $module = $this->leadModuleSource();

        // getElementById("x") 与 leadSetState("xHost", ...) 两种拿元素的写法都要对账
        preg_match_all('/getElementById\("([A-Za-z0-9_]+)"\)/', $module, $byId);
        preg_match_all('/leadSetState\("([A-Za-z0-9_]+)"/', $module, $byHost);
        $ids = array_values(array_unique(array_merge($byId[1], $byHost[1])));

        $this->assertGreaterThanOrEqual(15, count($ids), '抓到的 id 太少，正则大概率失效了');

        $missing = [];
        foreach ($ids as $id) {
            if (! str_contains($html, 'id="'.$id.'"')) {
                $missing[] = $id;
            }
        }

        $this->assertSame([], $missing, '前端绑了这些 id，但工作台页面里没有：'.implode(', ', $missing));
    }

    public function test_frontend_binds_every_lead_tab_and_state_host_the_markup_declares(): void
    {
        config(['geo.lead_form_enabled' => true]);
        $html = $this->workbenchHtml();
        $module = $this->leadModuleSource();

        // 反向对账：页面上声明了的状态机宿主，脚本里必须真的会去切它，
        // 否则就是一个永远停在「加载中」骨架屏上的死区。
        preg_match_all('/data-state-host id="([A-Za-z0-9_]+)"/', $html, $hosts);
        $this->assertNotEmpty($hosts[1]);

        foreach ($hosts[1] as $host) {
            if (! str_starts_with($host, 'lead')) {
                continue;
            }
            $this->assertStringContainsString('leadSetState("'.$host.'"', $module, "页面上有 {$host} 这个状态机宿主，但脚本从不切换它");
        }
    }
}
