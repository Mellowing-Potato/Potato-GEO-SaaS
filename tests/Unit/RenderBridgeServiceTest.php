<?php

namespace Tests\Unit;

use App\Services\SkillAgent\RenderBridgeService;
use App\Services\SkillAgent\RenderResult;
use App\Services\SkillAgent\SkillAssets;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RenderBridgeServiceTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureRoot = storage_path('app/render-bridge-fixture-'.uniqid());
        File::ensureDirectoryExists($this->fixtureRoot);
        // A private disk prevents concurrent test processes from clearing each
        // other's Storage::fake('reports') directory.
        config(['filesystems.disks.reports' => [
            'driver' => 'local',
            'root' => $this->fixtureRoot.'/public',
            'throw' => true,
        ]]);
        Storage::forgetDisk('reports');
    }

    protected function tearDown(): void
    {
        Storage::forgetDisk('reports');
        File::deleteDirectory($this->fixtureRoot);
        parent::tearDown();
    }

    public function test_render_substitutes_invalid_utf8_in_json_input(): void
    {
        $tempDir = storage_path('app/render-bridge-test-'.uniqid());
        File::ensureDirectoryExists($tempDir);
        $scriptPath = $tempDir.'/render_fake.py';
        file_put_contents($scriptPath, <<<'PY'
import argparse
import json
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument('--input', required=True)
parser.add_argument('--output-dir', required=True)
args = parser.parse_args()

with open(args.input, encoding='utf-8') as f:
    data = json.load(f)

out = Path(args.output_dir)
deliverables = out / 'deliverables'
deliverables.mkdir(parents=True, exist_ok=True)
(deliverables / 'report.md').write_text('# Report\n\n' + data.get('bad_text', ''), encoding='utf-8')
(out / 'quality-report.json').write_text('{"score": 88, "checks": []}', encoding='utf-8')
PY);

        try {
            $assets = new SkillAssets(
                skillId: 'test-render-skill',
                skillMd: '',
                references: [],
                renderScript: $scriptPath,
                exampleJsonSchema: null,
                hasAssets: true,
            );

            $result = (new RenderBridgeService)->render([
                'bad_text' => "before \xB1 after",
            ], $assets, 9001);

            $this->assertTrue($result->success, $result->errorMessage ?? '');
            $this->assertArrayHasKey('markdown', $result->renderedPaths);
            $this->assertStringContainsString('after', Storage::disk('reports')->get($result->renderedPaths['markdown']));
            $this->assertTrue($result->qualityReport['passed']);
            $this->assertFalse($result->qualityReport['blocking']);
        } finally {
            File::deleteDirectory($tempDir);
        }
    }

    public function test_renderer_check_matrix_with_no_failures_is_a_passing_verdict(): void
    {
        $tempDir = storage_path('app/render-bridge-passing-quality-'.uniqid());
        File::ensureDirectoryExists($tempDir);
        $scriptPath = $tempDir.'/render_passing_quality.py';
        file_put_contents($scriptPath, <<<'PY'
import argparse
import json
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument('--input', required=True)
parser.add_argument('--output-dir', required=True)
args = parser.parse_args()
out = Path(args.output_dir)
out.mkdir(parents=True, exist_ok=True)
(out / 'report.md').write_text('# 报告\n\n' + ('正文' * 200), encoding='utf-8')
(out / 'quality-report.json').write_text(json.dumps({
    'checks': {
        'required_sections_present': True,
        'question_bank_at_least_18': True,
        'follow_up_chains_at_least_8': True,
    },
    'failed_checks': [],
    'overall_score': 100,
}, ensure_ascii=False), encoding='utf-8')
PY);

        try {
            $assets = new SkillAssets('test-passing-quality-skill', '', [], $scriptPath, null, true);
            $result = (new RenderBridgeService)->render(['title' => '报告'], $assets, 9006);

            $this->assertTrue($result->success);
            $this->assertTrue($result->qualityReport['passed']);
            $this->assertFalse($result->qualityReport['blocking']);
            $this->assertSame([], $result->qualityReport['failed_checks']);
        } finally {
            File::deleteDirectory($tempDir);
        }
    }

    public function test_overall_pass_and_per_check_pass_fields_are_honored(): void
    {
        foreach ([true, false] as $checkPass) {
            $tempDir = storage_path('app/render-bridge-overall-pass-'.uniqid());
            File::ensureDirectoryExists($tempDir);
            $scriptPath = $tempDir.'/render_quality.py';
            $checkPassJson = $checkPass ? 'true' : 'false';
            file_put_contents($scriptPath, <<<PY
import argparse
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument('--input', required=True)
parser.add_argument('--output-dir', required=True)
args = parser.parse_args()
out = Path(args.output_dir)
out.mkdir(parents=True, exist_ok=True)
(out / 'report.md').write_text('# 报告\\n\\n' + ('正文' * 200), encoding='utf-8')
(out / 'report.html').write_text('<html><body><h1>报告</h1></body></html>', encoding='utf-8')
(out / 'quality-report.json').write_text('''{"overall_pass": true, "checks": [{"name": "required_sections", "pass": $checkPassJson}]}''', encoding='utf-8')
PY);

            try {
                $assets = new SkillAssets('test-overall-pass-skill', '', [], $scriptPath, null, true);
                $result = (new RenderBridgeService)->render(['title' => '报告'], $assets, $checkPass ? 9010 : 9011);

                $this->assertTrue($result->success);
                $this->assertSame($checkPass, $result->qualityReport['passed']);
                $this->assertSame(! $checkPass, $result->qualityReport['blocking']);
            } finally {
                File::deleteDirectory($tempDir);
            }
        }
    }

    /**
     * 脚本写完前几种格式才崩 —— 已经落盘的产物要交给客户，不能整单作废。
     *
     * 这是「排名型文章」的真实形状：render_ranking_article_pack.py 顺序写
     * .md → .html → .pdf → .docx，最后一步 write_docx() 因为镜像里没有 pandoc
     * 抛 RuntimeError、整个脚本退出码 1。三种格式其实都好好地躺在输出目录里。
     *
     * 2026-08-07 就因为只看退出码，把这个技能整个停用了 13 天，客户连他要的
     * 三种格式都拿不到。这条钉住相反的行为：退出码非零 + 有产物 = 部分成功。
     */
    public function test_partial_output_survives_a_non_zero_exit(): void
    {
        $tempDir = storage_path('app/render-bridge-partial-'.uniqid());
        File::ensureDirectoryExists($tempDir);
        $scriptPath = $tempDir.'/render_partial.py';
        // 刻意复刻 render_ranking_article_pack.py 的形状：先写三种格式，
        // 到 Word 那一步才抛异常。
        file_put_contents($scriptPath, <<<'PY'
import argparse
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument('--input', required=True)
parser.add_argument('--output-dir', required=True)
args = parser.parse_args()

out = Path(args.output_dir)
out.mkdir(parents=True, exist_ok=True)
(out / 'report.md').write_text('# 榜单正文\n\n三种格式都在。', encoding='utf-8')
(out / 'report.html').write_text('<h1>榜单正文</h1>', encoding='utf-8')
(out / 'report.pdf').write_bytes(b'%PDF-1.4 fake')

raise RuntimeError('pandoc is required to create Word output')
PY);

        try {
            $assets = new SkillAssets(
                skillId: 'test-partial-skill',
                skillMd: '',
                references: [],
                renderScript: $scriptPath,
                exampleJsonSchema: null,
                hasAssets: true,
            );

            $result = (new RenderBridgeService)->render(['title' => '部分成功'], $assets, 9002);

            $this->assertTrue($result->success, '写出来的三种格式不该因为第四种失败就丢掉');
            $this->assertSame(
                ['html', 'markdown', 'pdf'],
                collect(['markdown', 'html', 'pdf'])->filter(fn ($f) => isset($result->renderedPaths[$f]))->sort()->values()->all(),
                'Markdown / 网页 / PDF 三种都要收下来',
            );
            // Word 没产出就不该假装有 —— 前端的下载按钮是照这个列表画的。
            $this->assertArrayNotHasKey('docx', $result->renderedPaths);
            $this->assertNull($result->qualityReport, 'A font-only check must not replace the runner\'s fallback content checks.');
            $this->assertStringContainsString(
                '三种格式都在',
                Storage::disk('reports')->get($result->renderedPaths['markdown']),
            );
        } finally {
            File::deleteDirectory($tempDir);
        }
    }

    public function test_named_renderer_quality_failure_is_preserved_as_blocking(): void
    {
        $tempDir = storage_path('app/render-bridge-quality-'.uniqid());
        File::ensureDirectoryExists($tempDir);
        $scriptPath = $tempDir.'/render_quality_failure.py';
        file_put_contents($scriptPath, <<<'PY'
import argparse
import json
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument('--input', required=True)
parser.add_argument('--output-dir', required=True)
args = parser.parse_args()
out = Path(args.output_dir)
out.mkdir(parents=True, exist_ok=True)
(out / 'report.md').write_text('# 报告\n\n' + ('正文' * 200), encoding='utf-8')
(out / 'report-quality-review.json').write_text(json.dumps({
    'passed': False,
    'issues': ['缺少关键词：页面模块与信息架构图'],
}, ensure_ascii=False), encoding='utf-8')
raise SystemExit('quality review failed')
PY);

        try {
            $assets = new SkillAssets('test-quality-skill', '', [], $scriptPath, null, true);
            $result = (new RenderBridgeService)->render(['title' => '报告'], $assets, 9004);

            $this->assertTrue($result->success, '已生成文件仍应保留给用户查看');
            $this->assertFalse($result->qualityReport['passed']);
            $this->assertTrue($result->qualityReport['blocking']);
            $this->assertSame(1, $result->qualityReport['renderer_exit_code']);
            $this->assertArrayHasKey('quality-report', $result->renderedPaths);
            $this->assertStringContainsString('quality review failed', $result->errorMessage);
        } finally {
            File::deleteDirectory($tempDir);
        }
    }

    public function test_status_fail_without_passed_cannot_be_overwritten_by_successful_html_delivery_check(): void
    {
        $tempDir = storage_path('app/render-bridge-contradictory-quality-'.uniqid());
        File::ensureDirectoryExists($tempDir);
        $scriptPath = $tempDir.'/render_status_failure.py';
        file_put_contents($scriptPath, <<<'PY'
import argparse
import json
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument('--input', required=True)
parser.add_argument('--output-dir', required=True)
args = parser.parse_args()
out = Path(args.output_dir)
out.mkdir(parents=True, exist_ok=True)
(out / 'report.md').write_text('# 报告\n\n' + ('正文' * 200), encoding='utf-8')
(out / 'report.html').write_text('<html><body><h1>报告</h1></body></html>', encoding='utf-8')
(out / 'quality-report.json').write_text(json.dumps({
    'status': 'fail',
    'issues': ['关系缺少证据'],
    'checks': {'relation_evidence': False},
}, ensure_ascii=False), encoding='utf-8')
PY);

        try {
            $assets = new SkillAssets('test-status-quality-skill', '', [], $scriptPath, null, true);
            $result = (new RenderBridgeService)->render(['title' => '报告'], $assets, 9005);

            $this->assertTrue($result->success, '文件应保留供诊断，但不得当作合格交付');
            $this->assertFalse($result->qualityReport['passed']);
            $this->assertTrue($result->qualityReport['blocking']);
            $this->assertSame('fail', $result->qualityReport['status']);
            $this->assertContains('关系缺少证据', $result->qualityReport['issues']);
            $this->assertTrue(collect($result->qualityReport['checks'])->contains(
                fn (mixed $check): bool => is_array($check)
                    && ($check['name'] ?? null) === 'html_download_fonts_embedded'
                    && ($check['passed'] ?? null) === true,
            ));

            $stored = json_decode(Storage::disk('reports')->get($result->renderedPaths['quality-report']), true);
            $this->assertFalse($stored['passed']);
            $this->assertTrue($stored['blocking']);
        } finally {
            File::deleteDirectory($tempDir);
        }
    }

    public function test_downloaded_html_contains_its_fonts_after_temporary_package_is_removed(): void
    {
        $fonts = [
            'fonts/Regular.ttf' => "\x00\x01\x00\x00regular-font-bytes",
            'fonts/Bold.ttf' => "\x00\x01\x00\x00bold-font-bytes",
            'fonts/Chinese.ttc' => 'ttcfchinese-collection-bytes',
        ];
        $result = $this->renderHtmlFixture(array_keys($fonts), $fonts);

        $this->assertTrue($result->success, $result->errorMessage ?? 'Render returned no error message.');
        $this->assertSame('executions/9003/report.html', $result->renderedPaths['html']);
        $html = Storage::disk('reports')->get($result->renderedPaths['html']);
        preg_match_all('~url\("data:font/([^;]+);base64,([A-Za-z0-9+/=]+)"\)~', $html, $matches);
        $this->assertSame(['ttf', 'ttf', 'collection'], $matches[1]);
        $this->assertSame(array_values($fonts), array_map('base64_decode', $matches[2]));
        $this->assertStringNotContainsString('url("fonts/', $html);
        $this->assertStringContainsString('正文保持完整', $html);
        $this->assertTrue($result->qualityReport['passed']);
        $check = collect($result->qualityReport['checks'])->firstWhere('name', 'html_download_fonts_embedded');
        $this->assertTrue($check['passed']);
        $this->assertSame(3, $check['embedded_fonts']);
        $this->assertSame(array_sum(array_map('strlen', $fonts)), $check['embedded_font_bytes']);
        $this->assertSame($result->qualityReport, json_decode(Storage::disk('reports')->get($result->renderedPaths['quality-report']), true));
        $this->assertDirectoryDoesNotExist($this->renderedTemporaryDirectory());
    }

    public function test_subset_woff2_fonts_are_embedded_as_woff2(): void
    {
        // 渲染脚本会把内联用的字体子集化成 woff2（subset-*.woff2），下载接口内联的
        // 就是这些文件 —— 这是当前生产路径，必须和内联 ttf/ttc 一样被支持。
        $fonts = [
            'fonts/subset-NotoSerifCJK-Regular.woff2' => 'wOF2subset-chinese-bytes',
            'fonts/subset-DejaVuSans.woff2' => 'wOF2subset-latin-bytes',
        ];
        $result = $this->renderHtmlFixture(array_keys($fonts), $fonts);

        $this->assertTrue($result->success);
        $html = Storage::disk('reports')->get($result->renderedPaths['html']);
        preg_match_all('~url\("data:font/([^;]+);base64,([A-Za-z0-9+/=]+)"\)~', $html, $matches);
        $this->assertSame(['woff2', 'woff2'], $matches[1]);
        $this->assertSame(array_values($fonts), array_map('base64_decode', $matches[2]));
        $this->assertStringNotContainsString('url("fonts/', $html);
        $check = collect($result->qualityReport['checks'])->firstWhere('name', 'html_download_fonts_embedded');
        $this->assertTrue($check['passed']);
        $this->assertSame(2, $check['embedded_fonts']);
    }

    public function test_missing_font_withholds_only_html_and_marks_final_delivery_check_failed(): void
    {
        $result = $this->renderHtmlFixture(['fonts/missing.ttf']);

        $this->assertIncompleteHtmlDelivery($result);
        $this->assertStringContainsString('保留 Markdown', Storage::disk('reports')->get($result->renderedPaths['markdown']));
        $storedQuality = json_decode(Storage::disk('reports')->get($result->renderedPaths['quality-report']), true);
        $this->assertFalse($storedQuality['passed']);
        $this->assertContains('html_download_fonts_embedded', $storedQuality['issues']);
    }

    public function test_html_font_sources_cannot_read_outside_the_package_or_fetch_a_remote_url(): void
    {
        foreach ([
            '../outside.ttf',
            '%2e%2e/outside.ttf',
            '/outside.ttf',
            'C:/outside.ttf',
            'file:///outside.ttf',
            'https://example.invalid/font.ttf',
            '//example.invalid/font.ttf',
        ] as $source) {
            $result = $this->renderHtmlFixture([$source], ['../outside.ttf' => "\x00\x01\x00\x00private-font-content"]);
            $this->assertIncompleteHtmlDelivery($result);
            $this->assertStringNotContainsString('private-font-content', json_encode($result->qualityReport));
        }
    }

    public function test_font_file_format_must_match_its_extension(): void
    {
        $result = $this->renderHtmlFixture(['fonts/wrong.ttf'], ['fonts/wrong.ttf' => 'plain text must not be embedded']);
        $this->assertIncompleteHtmlDelivery($result);
    }

    public function test_oversized_font_does_not_make_the_html_download_unbounded(): void
    {
        $result = $this->renderHtmlFixture(['fonts/huge.ttf'], ['fonts/huge.ttf' => "\x00\x01\x00\x00"], 5 * 1024 * 1024);
        $this->assertIncompleteHtmlDelivery($result);
    }

    public function test_combined_font_size_is_limited_even_when_each_file_is_under_the_individual_limit(): void
    {
        $fonts = ['fonts/first.ttc' => 'ttcf', 'fonts/second.ttc' => 'ttcf', 'fonts/third.ttc' => 'ttcf'];
        $result = $this->renderHtmlFixture(array_keys($fonts), $fonts, 3 * 1024 * 1024);
        $this->assertIncompleteHtmlDelivery($result);
    }

    public function test_standalone_html_keeps_already_embedded_fonts_and_unrelated_urls(): void
    {
        $font = "\x00\x01\x00\x00already-embedded";
        $source = 'data:font/ttf;base64,'.base64_encode($font);
        $result = $this->renderHtmlFixture([$source], standalone: true);
        $html = Storage::disk('reports')->get($result->renderedPaths['html']);

        $this->assertTrue($result->qualityReport['passed']);
        $this->assertStringContainsString('url("'.$source.'")', $html);
        $this->assertStringContainsString('background-image:url("cover.png")', $html);
        $this->assertStringContainsString('href="https://example.invalid/research"', $html);
    }

    public function test_final_explainer_body_is_returned_in_normal_and_partial_success_without_becoming_a_report_download(): void
    {
        foreach ([false, true] as $partialFailure) {
            $result = $this->renderHtmlFixture([], publishableArticle: "# 可发布正文\n\n只包含正文。\n", explainer: true, partialFailure: $partialFailure);
            $this->assertTrue($result->success);
            $this->assertSame("# 可发布正文\n\n只包含正文。\n", $result->publishableArticle);
            $this->assertStringEndsWith('/report.md', $result->renderedPaths['markdown']);
            $this->assertStringContainsString('保留 Markdown', Storage::disk('reports')->get($result->renderedPaths['markdown']));
            $this->assertNotContains('publishable-article.md', array_map('basename', $result->renderedPaths));
        }
    }

    public function test_final_article_handoff_is_bounded_and_only_enabled_for_explainer(): void
    {
        foreach ([null, '', '   ', str_repeat('x', 1024 * 1024 + 1)] as $body) {
            $result = $this->renderHtmlFixture([], publishableArticle: $body, explainer: true);
            $this->assertTrue($result->success);
            $this->assertNull($result->publishableArticle);
        }
        $result = $this->renderHtmlFixture([], publishableArticle: '# Not an explainer');
        $this->assertNull($result->publishableArticle);
    }

    public function test_internal_article_body_is_not_misclassified_as_markdown_when_no_report_was_rendered(): void
    {
        $result = $this->renderHtmlFixture([], publishableArticle: '# 正文', explainer: true, skipReport: true);
        $this->assertTrue($result->success);
        $this->assertSame('# 正文', $result->publishableArticle);
        $this->assertArrayNotHasKey('markdown', $result->renderedPaths);
    }

    private function assertIncompleteHtmlDelivery(RenderResult $result): void
    {
        $this->assertTrue($result->success, 'Other generated formats must remain available.');
        $this->assertArrayNotHasKey('html', $result->renderedPaths);
        $this->assertArrayHasKey('markdown', $result->renderedPaths);
        $this->assertFalse($result->qualityReport['passed']);
        $check = collect($result->qualityReport['checks'])->firstWhere('name', 'html_download_fonts_embedded');
        $this->assertFalse($check['passed']);
    }

    private function renderedTemporaryDirectory(): string
    {
        return storage_path('app/temp/'.trim(file_get_contents($this->fixtureRoot.'/last-output-dir.txt')));
    }

    private function renderHtmlFixture(
        array $sources,
        array $fonts = [],
        ?int $sparseSize = null,
        bool $standalone = false,
        ?string $publishableArticle = null,
        bool $explainer = false,
        bool $partialFailure = false,
        bool $skipReport = false,
    ): RenderResult {
        $scriptPath = $this->fixtureRoot.'/render_html_fonts.py';
        file_put_contents($scriptPath, <<<'PY'
import argparse
import base64
import json
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument('--input', required=True)
parser.add_argument('--output-dir', required=True)
args = parser.parse_args()
data = json.loads(Path(args.input).read_text(encoding='utf-8'))
out = Path(args.output_dir)
deliver = out / 'deliverables'
package = out if data['standalone'] else deliver / 'html-package'
package.mkdir(parents=True, exist_ok=True)
deliver.mkdir(parents=True, exist_ok=True)
(Path(__file__).parent / 'last-output-dir.txt').write_text(out.parent.name, encoding='utf-8')
for name, encoded in data['font_files'].items():
    path = package / name
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(base64.b64decode(encoded))
    if data['sparse_size']:
        with path.open('r+b') as font:
            font.seek(data['sparse_size'] - 1)
            font.write(b'\0')
styles = ''.join('@font-face{font-family:Font' + str(i) + ';src:url("' + source + '") format("truetype")}' for i, source in enumerate(data['sources']))
html = '<!doctype html><style>' + styles + 'body{background-image:url("cover.png")}</style><p>正文保持完整</p><a href="https://example.invalid/research">研究链接</a>'
(package / 'index.html').write_text(html, encoding='utf-8')
if not data['skip_report']:
    (deliver / 'report.md').write_text('# 保留 Markdown\n\n正文保持完整', encoding='utf-8')
if data['publishable_article'] is not None:
    (out / 'publishable-article.md').write_bytes(data['publishable_article'].encode('utf-8'))
(out / 'quality-report.json').write_text(json.dumps({'passed': True, 'checks': [{'name': 'html_kami_fonts_packaged', 'passed': True}]}), encoding='utf-8')
if data['partial_failure']:
    raise RuntimeError('An optional format failed after writing the article.')
PY);

        $assets = new SkillAssets($explainer ? 'yao-geo-explainer-builder' : 'test-html-font-delivery', '', [], $scriptPath, null, true);

        return (new RenderBridgeService)->render([
            'sources' => $sources,
            'font_files' => (object) array_map('base64_encode', $fonts),
            'sparse_size' => $sparseSize,
            'standalone' => $standalone,
            'publishable_article' => $publishableArticle,
            'partial_failure' => $partialFailure,
            'skip_report' => $skipReport,
        ], $assets, 9003);
    }
}
