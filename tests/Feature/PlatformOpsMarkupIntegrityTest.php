<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Tests\TestCase;

/**
 * 平台运营台渲染后标记完整性（与工作台那份对称，覆盖本批新接线的审计页与安全设置）。
 *
 * 为什么单独一份：运营台是与工作台**完全隔离**的另一张壳（独立 CSS/JS/守卫），
 * 工作台那份测试看不到它。而本批在运营台上新增了 #sec-audit 与 #sec-security 两个分区
 * 与一个安全设置表单 —— 重复 id、悬空 label/aria、嵌套 form、字段名与后端校验不一致
 * 这四类问题同样只有渲染后才看得见。
 *
 * 运营台壳页是公开渲染的（真实权限门在 API 层 auth:sanctum + can:platform:operations），
 * 所以这里可以直接 GET。
 */
class PlatformOpsMarkupIntegrityTest extends TestCase
{
    private function xpath(): DOMXPath
    {
        $html = $this->get('/platform/ops')->assertOk()->getContent();

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    public function test_rendered_ops_console_has_no_duplicate_ids_or_dangling_references(): void
    {
        $xpath = $this->xpath();

        $ids = [];
        $duplicates = [];
        foreach ($xpath->query('//*[@id]') as $node) {
            $id = $node->getAttribute('id');
            if (isset($ids[$id])) {
                $duplicates[] = $id;
                continue;
            }
            $ids[$id] = true;
        }
        $this->assertSame([], array_values(array_unique($duplicates)), '运营台有重复 id：'.implode(', ', array_unique($duplicates)));
        $this->assertGreaterThan(20, count($ids), '渲染出的 id 太少，八成是壳页没渲染出来');

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

        $nested = [];
        foreach ($xpath->query('//form//form') as $form) {
            $nested[] = $form->getAttribute('id') ?: '(无 id 的 form)';
        }
        $this->assertSame([], $nested, '出现嵌套 form：'.implode(', ', $nested));
    }

    public function test_audit_and_security_sections_render_with_their_contract_fields(): void
    {
        $xpath = $this->xpath();

        // 导航直达 + 分区本体
        foreach (['sec-audit' => '审计日志', 'sec-security' => '安全设置'] as $section => $label) {
            $this->assertSame(1, $xpath->query("//a[@href='#{$section}']")->length, "导航缺少 #{$section}（{$label}）");
            $this->assertSame(1, $xpath->query("//*[@id='{$section}']")->length, "缺少分区 #{$section}");
        }

        // 审计页的行容器与筛选控件（loadAudit 依赖这些 id）
        foreach (['auditBody', 'auditMeta', 'auditAction', 'auditTargetType', 'auditFrom', 'auditTo', 'auditPerPage', 'auditApplyBtn'] as $id) {
            $this->assertSame(1, $xpath->query("//*[@id='{$id}']")->length, "审计分区缺少 #{$id}");
        }
        $this->assertSame(
            1,
            $xpath->query("//*[@id='auditPerPage']//option[@value='100']")->length,
            '每页条数上限要与后端校验（per_page ≤ 100）对齐',
        );

        // 安全设置表单：字段名与后端 PUT /platform/security-settings 的校验一致
        foreach (['secPasswordMin', 'secRequireSpecial', 'secRateLimit', 'secSensitiveWords', 'secSaveBtn'] as $id) {
            $this->assertSame(1, $xpath->query("//*[@id='{$id}']")->length, "安全设置缺少 #{$id}");
        }
        $this->assertSame('6', $xpath->query("//*[@id='secPasswordMin']")->item(0)->getAttribute('min'));
        $this->assertSame('64', $xpath->query("//*[@id='secPasswordMin']")->item(0)->getAttribute('max'));
        $this->assertSame('1', $xpath->query("//*[@id='secRateLimit']")->item(0)->getAttribute('min'));
        $this->assertSame('10000', $xpath->query("//*[@id='secRateLimit']")->item(0)->getAttribute('max'));

        // 每个字段都要有可访问名称：label[for] 或 aria-label
        foreach (['secPasswordMin', 'secRequireSpecial', 'secRateLimit', 'secSensitiveWords'] as $id) {
            $hasLabel = $xpath->query("//label[@for='{$id}']")->length > 0
                || $xpath->query("//*[@id='{$id}'][@aria-label]")->length > 0;
            $this->assertTrue($hasLabel, "#{$id} 缺少可访问名称（label[for] 或 aria-label）");
        }
    }
}
