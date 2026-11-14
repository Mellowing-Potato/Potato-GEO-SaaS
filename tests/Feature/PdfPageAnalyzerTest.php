<?php

namespace Tests\Feature;

use App\Services\Knowledge\PdfPageAnalyzer;
use Tests\TestCase;

/**
 * B2：PdfPageAnalyzer 的判定口径测试。
 *
 * 判定口径（§1.6）：needs_vision = (image_coverage >= 0.5) || (chars < 50)。
 *
 * fixtures 由 scripts/fixtures/generate-pdfs.py 生成，仅含虚构文字和几何图形。
 * 数字规格保留原有测试的页框、字符数和图片面积输入，断言仍以 Poppler 实测为准。
 *
 * fixtures 覆盖矩阵：
 *   doc7_crop.pdf   纯文字 PDF（0 触发）—— 有图但覆盖率低（p4=0.2362）也不触发
 *   doc8_crop.pdf   混合型（4/5 触发）—— p5 是关键判别页：chars=98≥50、cov=0.5236≥0.5，
 *                   证明「仅靠覆盖率」也能触发；p1 双条件都不满足证明不误伤
 *   doc9_re.pdf     全触发（重叠图形与透明遮罩使累计覆盖率 >1，判定不变）
 *   doc10_crop.pdf  纯文字（0 触发）
 *   doc11_p1_re.pdf 覆盖率单条件触发（cov=0.8891，chars=0）
 *   doc11_p3_re.pdf 字符数单条件触发（cov=0.0137，chars=0）—— 覆盖率极低但纯图页
 *
 * CI 容器由 B1 装了 poppler-utils；本地 Mac 走 homebrew poppler。谁都没有就跳过，
 * 不让缺工具把整条测试线炸红（analyzer 本身失败也是降级不抛，见 test_missing_file_degrades）。
 */
class PdfPageAnalyzerTest extends TestCase
{
    private function fixture(string $name): string
    {
        return __DIR__ . '/../fixtures/pdf/' . $name;
    }

    private function analyzer(): PdfPageAnalyzer
    {
        return new PdfPageAnalyzer();
    }

    /** poppler 二进制不可用时整类跳过（工具缺失 ≠ 代码坏了）。 */
    private function requirePoppler(): void
    {
        $missing = array_filter(['pdfinfo', 'pdfimages', 'pdftotext'], fn ($cmd) => $this->commandExists($cmd) === false);
        if ($missing !== []) {
            $this->markTestSkipped('poppler-utils 不可用（缺 ' . implode(', ', $missing) . '），跳过 B2 判定测试。');
        }
    }

    private function commandExists(string $command): bool
    {
        $check = PHP_OS_FAMILY === 'Windows'
            ? "where.exe {$command} 2>NUL"
            : "command -v {$command} 2>/dev/null";
        exec($check, $output, $code);

        return $code === 0;
    }

    /** @param array<int, array{page:int,image_coverage:float,chars:int,needs_vision:bool,text:string}> $analysis */
    private function triggerPages(array $analysis): array
    {
        return array_values(array_map(
            fn (array $p) => $p['page'],
            array_filter($analysis, fn (array $p) => $p['needs_vision']),
        ));
    }

    public function test_doc7_text_pdf_triggers_nothing(): void
    {
        $this->requirePoppler();
        $analysis = $this->analyzer()->analyze($this->fixture('doc7_crop.pdf'));

        $this->assertCount(4, $analysis);
        $this->assertSame([], $this->triggerPages($analysis), '纯文字 PDF 一页都不该送 vision');

        // p4 有图但覆盖率 0.2362 < 0.5、chars=293 ≥ 50 → 双条件都不满足（防误伤）。
        $this->assertEqualsWithDelta(0.2362, $analysis[4]['image_coverage'], 0.0001);
        $this->assertSame(293, $analysis[4]['chars']);
        $this->assertFalse($analysis[4]['needs_vision']);
    }

    public function test_doc8_mixed_pdf_triggers_pages_2_to_5(): void
    {
        $this->requirePoppler();
        $analysis = $this->analyzer()->analyze($this->fixture('doc8_crop.pdf'));

        $this->assertCount(5, $analysis);
        $this->assertSame([2, 3, 4, 5], $this->triggerPages($analysis));

        // p1：cov=0.2395 < 0.5 且 chars=286 ≥ 50 → 不触发。
        $this->assertEqualsWithDelta(0.2395, $analysis[1]['image_coverage'], 0.0001);
        $this->assertSame(286, $analysis[1]['chars']);
        $this->assertFalse($analysis[1]['needs_vision']);

        // p5 是关键判别页：chars=98 ≥ 50（字符条件不成立）、cov=0.5236 ≥ 0.5（覆盖率单条件成立）。
        // 若只实现 chars 规则漏了覆盖率规则，这页会漏判。
        $this->assertSame(98, $analysis[5]['chars']);
        $this->assertEqualsWithDelta(0.5236, $analysis[5]['image_coverage'], 0.0001);
        $this->assertTrue($analysis[5]['needs_vision'], 'chars≥50 但覆盖率过半的页必须送 vision');
    }

    public function test_doc9_all_pages_trigger(): void
    {
        $this->requirePoppler();
        $analysis = $this->analyzer()->analyze($this->fixture('doc9_re.pdf'));

        $this->assertCount(3, $analysis);
        $this->assertSame([1, 2, 3], $this->triggerPages($analysis));

        // 重叠图形与透明遮罩保留累计覆盖率 >1 的边界（p1=5.7322）。
        // analyzer 的契约是照实累加图片面积，此处不得钳制数值。
        $this->assertEqualsWithDelta(5.7322, $analysis[1]['image_coverage'], 0.0001);
        // p2/p3 有文字层（358/352 字符 ≥ 50）但仍因覆盖率触发 —— 证明两条规则是「或」不是「与」。
        $this->assertSame(358, $analysis[2]['chars']);
        $this->assertSame(352, $analysis[3]['chars']);
    }

    public function test_doc10_text_pdf_triggers_nothing(): void
    {
        $this->requirePoppler();
        $analysis = $this->analyzer()->analyze($this->fixture('doc10_crop.pdf'));

        $this->assertCount(2, $analysis);
        $this->assertSame([], $this->triggerPages($analysis));

        // p1 cov=0.2456 chars=565：有图有字、双条件都不满足。
        $this->assertEqualsWithDelta(0.2456, $analysis[1]['image_coverage'], 0.0001);
        $this->assertSame(565, $analysis[1]['chars']);
    }

    public function test_doc11_page1_triggers_by_coverage(): void
    {
        $this->requirePoppler();
        $analysis = $this->analyzer()->analyze($this->fixture('doc11_p1_re.pdf'));

        $this->assertCount(1, $analysis);
        // 覆盖率单条件触发：cov=0.8891 ≥ 0.5（chars=0 其实也满足字符条件，但先看覆盖）。
        $this->assertEqualsWithDelta(0.8891, $analysis[1]['image_coverage'], 0.0001);
        $this->assertTrue($analysis[1]['needs_vision']);
    }

    public function test_doc11_page3_triggers_by_chars(): void
    {
        $this->requirePoppler();
        $analysis = $this->analyzer()->analyze($this->fixture('doc11_p3_re.pdf'));

        $this->assertCount(1, $analysis);
        // 字符数单条件触发：cov=0.0137 远低于 0.5、chars=0 < 50 → 纯图无字页必须送 vision。
        $this->assertEqualsWithDelta(0.0137, $analysis[1]['image_coverage'], 0.0001);
        $this->assertSame(0, $analysis[1]['chars']);
        $this->assertTrue($analysis[1]['needs_vision']);
    }

    public function test_result_structure_is_page_keyed_and_complete(): void
    {
        $this->requirePoppler();
        $analysis = $this->analyzer()->analyze($this->fixture('doc10_crop.pdf'));

        // key = 页号（1 起、连续、升序），每页结构五字段齐全 —— B5 的按页合并依赖这个契约。
        $this->assertSame([1, 2], array_keys($analysis));
        foreach ($analysis as $page => $row) {
            $this->assertSame($page, $row['page']);
            $this->assertIsFloat($row['image_coverage']);
            $this->assertIsInt($row['chars']);
            $this->assertIsBool($row['needs_vision']);
            $this->assertIsString($row['text']);
        }

        // chars 的口径是「去掉空白后计数」：p2 原文有 1020 个非空白字符。
        $this->assertSame(1020, $analysis[2]['chars']);
    }

    public function test_missing_file_degrades_to_empty_array_without_throwing(): void
    {
        // B2 的硬契约：分析工具挂了/文件不存在，绝不抛异常阻断入库，返回空数组（= 一页不送 vision）。
        $analysis = $this->analyzer()->analyze('/nonexistent/path/nope.pdf');

        $this->assertSame([], $analysis);
    }

    public function test_partial_poppler_failure_never_marks_pages_for_paid_vision(): void
    {
        $analyzer = new class extends PdfPageAnalyzer
        {
            public bool $failImages = false;

            public bool $failText = false;

            protected function pageSizes(string $pdfFullPath): array
            {
                return [1 => [100.0, 100.0]];
            }

            protected function imageAreasPerPage(string $pdfFullPath): ?array
            {
                return $this->failImages ? null : [];
            }

            protected function pageTexts(string $pdfFullPath, int $totalPages): ?array
            {
                return $this->failText ? null : [1 => ''];
            }
        };

        $analyzer->failImages = true;
        $this->assertSame([], $analyzer->analyze('fixture.pdf'));

        $analyzer->failImages = false;
        $analyzer->failText = true;
        $this->assertSame([], $analyzer->analyze('fixture.pdf'));

        // Successfully analyzed pages with genuinely no text still need vision.
        $analyzer->failText = false;
        $this->assertTrue($analyzer->analyze('fixture.pdf')[1]['needs_vision']);
    }
}
