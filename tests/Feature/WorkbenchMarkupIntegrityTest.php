<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Tests\TestCase;

/**
 * 工作台渲染后标记完整性（计划 §5 验收标准第一条的"看得见"那一半）。
 *
 * 现有的 JS 契约测试读的是**源码**：它能证明"面板在模板里"，不能证明"渲染出来的 HTML 是干净的"。
 * 这一批新增了三张表单面板（URL 导入 / 文章分类 / API 令牌）并改了自动化任务表单，
 * 最容易出的三类问题都只有渲染后才看得见：
 *
 *   1. 重复 id —— 同一个 id 出现两次时，`<label for>` / `aria-describedby` 会绑到第一个，
 *      另一个控件失去可访问名称（技能表单与自动化表单用的是同一套控件生成函数，
 *      所以我的动态输入用了独立 id 命名空间 `atBrief-*`，这条就是它的护栏）；
 *   2. 指向不存在 id 的 label / aria 关联 —— 屏幕阅读器读不出字段名；
 *   3. 嵌套 form —— HTML 解析器会直接丢弃内层 form，字段静默失效。
 *
 * 另外断言新面板的关键字段名与后端契约一致（改名会让接口 422，而单测不一定覆盖到）。
 */
class WorkbenchMarkupIntegrityTest extends TestCase
{
    private function xpath(): DOMXPath
    {
        $html = $this->get('/')->assertOk()->getContent();

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    public function test_rendered_workbench_has_no_duplicate_ids(): void
    {
        $xpath = $this->xpath();

        $seen = [];
        $duplicates = [];
        foreach ($xpath->query('//*[@id]') as $node) {
            $id = $node->getAttribute('id');
            if (isset($seen[$id])) {
                $duplicates[] = $id;
                continue;
            }
            $seen[$id] = true;
        }

        $this->assertSame([], array_values(array_unique($duplicates)), '页面里有重复 id：'.implode(', ', array_unique($duplicates)));
        $this->assertGreaterThan(50, count($seen), '渲染出的 id 太少，八成是模板没渲染出来');
    }

    public function test_rendered_workbench_has_no_dangling_label_or_aria_references(): void
    {
        $xpath = $this->xpath();
        $ids = [];
        foreach ($xpath->query('//*[@id]') as $node) {
            $ids[$node->getAttribute('id')] = true;
        }

        $dangling = [];
        foreach ($xpath->query('//label[@for]') as $label) {
            $target = $label->getAttribute('for');
            if ($target !== '' && ! isset($ids[$target])) {
                $dangling[] = "label[for={$target}]";
            }
        }
        foreach (['aria-describedby', 'aria-labelledby'] as $attribute) {
            foreach ($xpath->query("//*[@{$attribute}]") as $node) {
                foreach (preg_split('/\s+/', trim($node->getAttribute($attribute))) as $target) {
                    if ($target !== '' && ! isset($ids[$target])) {
                        $dangling[] = "{$attribute}={$target}";
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($dangling)), '有关联指向不存在的 id：'.implode(', ', array_unique($dangling)));
    }

    public function test_rendered_workbench_has_no_nested_forms(): void
    {
        $xpath = $this->xpath();

        $nested = [];
        foreach ($xpath->query('//form//form') as $form) {
            $nested[] = $form->getAttribute('id') ?: '(无 id 的 form)';
        }

        $this->assertSame([], $nested, '出现嵌套 form，内层会被解析器丢掉：'.implode(', ', $nested));
    }

    public function test_new_workbench_panels_render_with_their_contract_fields(): void
    {
        $xpath = $this->xpath();

        // 侧栏入口 + 面板本体都要在渲染结果里。
        // 注意：API 令牌面板 2026-09-22 按用户口径从租户侧撤下（租户只做积分展示，
        // 不提供凭据签发/吊销），这里只保留仍然开放的两页；"撤下"由
        // tests/js/tenant-credential-boundary.test.mjs 反向守着。
        foreach (['url-imports'] as $view) {
            $this->assertSame(
                1,
                $xpath->query("//a[@href='#{$view}']")->length,
                "侧栏 {$view} 入口应当有且只有一个",
            );
            $this->assertSame(
                1,
                $xpath->query("//*[@data-workspace-view='{$view}']")->length,
                "{$view} 面板应当有且只有一个",
            );
        }

        $this->assertSame(0, $xpath->query("//a[@href='#categories']")->length, '文章分类不再是侧栏入口');
        $categoryButton = $xpath->query("//*[@id='clManageCategoriesButton']");
        $this->assertSame(1, $categoryButton->length, '文章页应当有且只有一个管理分类按钮');
        $this->assertFalse($categoryButton->item(0)->hasAttribute('data-view-target'), '管理分类按钮不能被侧栏点击处理接管');
        $this->assertSame(
            1,
            $xpath->query("//*[@id='categoriesPanel' and @data-workspace-view='categories']")->length,
            'categories 面板应当有且只有一个，并保留原有 id 和视图 key',
        );

        // 字段名必须与后端校验一致（改名 = 接口 422）
        $expectations = [
            'urlImportForm' => ['url', 'project_id_display'],
            'categoryForm' => ['name', 'slug', 'description', 'sort_order'],
        ];
        foreach ($expectations as $formId => $fields) {
            $form = $xpath->query("//form[@id='{$formId}']")->item(0);
            $this->assertNotNull($form, "缺少表单 {$formId}");
            foreach ($fields as $field) {
                $this->assertGreaterThan(
                    0,
                    $xpath->query("//form[@id='{$formId}']//*[@name='{$field}']")->length,
                    "{$formId} 里缺少字段 {$field}",
                );
            }
        }

        // 撤下的凭据类入口不得在渲染结果里复活（与前端契约用例成对断言）
        foreach (['api-tokens', 'apiTokenScopes'] as $removed) {
            $this->assertSame(
                0,
                $xpath->query("//a[@href='#{$removed}']")->length + $xpath->query("//*[@id='{$removed}']")->length,
                "凭据类入口 {$removed} 已按口径撤下，不应再出现在渲染结果里",
            );
        }
    }

    public function test_dynamic_automation_fields_are_not_rendered_statically(): void
    {
        $xpath = $this->xpath();

        // 技能动态输入是**按所选技能在客户端生成**的，静态渲染时必须是空的 ——
        // 否则会出现"没选技能却有字段"的假象，也会与技能面板里的同名字段撞 id。
        $host = $xpath->query("//*[@id='atDynamicFields']")->item(0);
        $this->assertNotNull($host);
        $this->assertSame(0, $host->childNodes->length, '动态输入容器静态渲染时必须为空');
        $this->assertSame(
            0,
            $xpath->query("//*[@id='atDynamicFields']//*[@name]")->length,
        );
    }
}
