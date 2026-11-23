<?php

namespace Tests\Feature;

use App\Services\SkillAgent\RenderBridgeService;
use App\Services\SkillAgent\SkillAssets;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PortableRenderDeliveryTest extends TestCase
{
    public function test_a_downloadable_html_draft_with_missing_fonts_can_be_replaced_without_overwriting_the_original(): void
    {
        Storage::fake('reports');
        $fixture = storage_path('framework/testing/render-feedback-'.uniqid());
        File::ensureDirectoryExists($fixture);
        $script = $fixture.'/render_feedback.py';
        file_put_contents($script, <<<'PY'
import argparse, json, zipfile
from pathlib import Path
parser = argparse.ArgumentParser()
parser.add_argument('--input', type=Path)
parser.add_argument('--output-dir', type=Path)
args = parser.parse_args()
out = args.output_dir
out.mkdir(parents=True)
fixed = json.loads(args.input.read_text())['fonts_installed']
(out/'source.md').write_text('Editorial text')
(out/'source.html').write_text('<p>'+('Repaired' if fixed else 'Original')+'</p>')
(out/'source.pdf').write_bytes(b'%PDF-1.4')
with zipfile.ZipFile(out/'source.docx','w') as doc:
    doc.writestr('word/document.xml','<document/>')
(out/'format-status.json').write_text(json.dumps({} if fixed else {'html': {'status': 'failed', 'reason':'html_fonts'}}))
(out/'quality-report.json').write_text(json.dumps({'passed':fixed,'checks':[{'name':'html_kami_fonts_packaged','passed':fixed}]}))
PY);
        try {
            $assets = new SkillAssets('yao-geo-explainer-builder', '', [], $script, null, true);
            $bridge = new RenderBridgeService;
            $original = $bridge->render(['fonts_installed' => false], $assets, 841);
            $repaired = $bridge->render(['fonts_installed' => true], $assets, 841, isolatedOutput: true);

            foreach (['markdown', 'html', 'docx', 'pdf'] as $format) {
                $this->assertArrayHasKey($format, $original->renderedPaths);
                $this->assertSame('available', $repaired->formatStatus[$format]['status']);
            }
            $this->assertSame(['status' => 'failed', 'reason' => 'html_fonts'], $original->formatStatus['html']);
            $this->assertFalse($original->qualityReport['passed']);
            $this->assertTrue($repaired->qualityReport['passed']);
            $this->assertNotSame($original->renderedPaths['quality-report'], $repaired->renderedPaths['quality-report']);
            $this->assertSame('<p>Original</p>', Storage::disk('reports')->get($original->renderedPaths['html']));
            $this->assertSame('<p>Repaired</p>', Storage::disk('reports')->get($repaired->renderedPaths['html']));
        } finally {
            File::deleteDirectory($fixture);
        }
    }

    public function test_missing_python_marks_only_the_formats_the_skill_actually_provides(): void
    {
        config(['geo.python_bin' => storage_path('framework/testing/python-not-installed')]);
        $assets = new SkillAssets('yao-geo-tracking', '', [], __FILE__, null, true);
        $result = (new RenderBridgeService)->render([], $assets, 842);

        $this->assertFalse($result->success);
        $this->assertSame([], $result->renderedPaths);
        $this->assertSame(['status' => 'unavailable', 'reason' => 'python'], $result->formatStatus['docx']);
        $this->assertSame($result->formatStatus['docx'], $result->formatStatus['html']);
        $this->assertSame(['status' => 'unsupported'], $result->formatStatus['pdf']);
        $this->assertSame($result->formatStatus['pdf'], $result->formatStatus['markdown']);
    }
}
