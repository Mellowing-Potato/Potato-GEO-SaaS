"""The release matrix must reject missing bundled PDF support at the real renderer entry points."""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]


class RenderMatrixAcceptanceTest(unittest.TestCase):
    def test_missing_reportlab_rejects_each_bundled_pdf_renderer(self):
        php = json.loads(os.environ.get('GEO_TEST_PHP_COMMAND', '["php"]'))
        python = os.environ.get('GEO_TEST_PYTHON', sys.executable)
        skills = os.environ.get('GEO_TEST_SKILLS', str(ROOT / 'yao-geo-skills/skills'))
        for skill, script in (
            ('yao-geo-content-refiner', 'render_content_refiner_bundle.py'),
            ('yao-geo-title-optimizer', 'render_yao_geo_title_optimizer.py'),
        ):
            with self.subTest(skill=skill), tempfile.TemporaryDirectory(prefix='yanji-missing-reportlab-') as tmp:
                hook = Path(tmp) / 'sitecustomize.py'
                # Match the bundled runtime, which has no WeasyPrint native stack.
                # Force the title renderer through its ReportLab fallback even on full CI images.
                hook.write_text(
                    'import importlib.abc, sys\n'
                    'class MissingBundledPdfDependencies(importlib.abc.MetaPathFinder):\n'
                    '    def find_spec(self, fullname, path=None, target=None):\n'
                    '        if fullname.split(".")[0] in ("reportlab", "weasyprint"):\n'
                    '            raise ModuleNotFoundError("Bundled PDF dependency intentionally unavailable")\n'
                    f'if sys.argv[0].endswith({script!r}):\n'
                    '    sys.meta_path.insert(0, MissingBundledPdfDependencies())\n', encoding='utf-8',
                )
                result = subprocess.run(
                    [*php, str(ROOT / 'scripts/test-skill-render-matrix.php'),
                     '--skills=' + skills, '--python=' + python, '--mode=optional'],
                    cwd=ROOT, env={**os.environ, 'PYTHONPATH': tmp, 'PYTHONDONTWRITEBYTECODE': '1'},
                    capture_output=True, text=True, encoding='utf-8', timeout=180,
                )
                self.assertNotEqual(0, result.returncode, f'{skill} passed release acceptance without ReportLab')
                self.assertIn(skill + ' lost required pdf', result.stderr, result.stdout + result.stderr)


if __name__ == '__main__':
    unittest.main()
