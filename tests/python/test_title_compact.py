import importlib.util
import copy
import json
import sys
import tempfile
import unittest
import zipfile
from pathlib import Path
from xml.etree import ElementTree as ET

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / 'skill-overlays/yao-geo-title-optimizer/scripts/render_yao_geo_title_optimizer.py'
EXAMPLE = ROOT / 'skill-overlays/yao-geo-title-optimizer/examples/compact/report_input.json'
spec = importlib.util.spec_from_file_location('title_renderer', SCRIPT)
renderer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(renderer)


class CompactTitleTest(unittest.TestCase):
    def fixture(self):
        return json.loads(EXAMPLE.read_text(encoding='utf-8'))

    def test_candidate_bounds_ids_recommendation_and_optional_reviews(self):
        for count in (5, 8):
            data = self.fixture()
            source = data['title_candidates']
            data['title_candidates'] = [copy.deepcopy(source[i % len(source)]) for i in range(count)]
            for i, item in enumerate(data['title_candidates']):
                item['id'] = None
                item['title'] = f'标题{i + 1}'
            data['recommended_id'] = 'T3'
            data.pop('compliance_checks')
            renderer.validate_report_input(data)
            self.assertEqual([f'T{i}' for i in range(1, count + 1)], [x['id'] for x in data['title_candidates']])
            self.assertIn('T3 · 标题3', renderer.render_markdown(data))
        for count in (0, 4, 9):
            data = self.fixture()
            data['title_candidates'] = (data['title_candidates'] * 2)[:count]
            with self.assertRaises(ValueError):
                renderer.validate_report_input(data)
        for change in (
            lambda d: d['title_candidates'][1].update(id='T1'),
            lambda d: d.update(recommended_id='T9'),
            lambda d: d['title_candidates'][0]['scores'].update(compliance=6),
            lambda d: d['title_candidates'][0].pop('title'),
            lambda d: d.update(output_stem='../outside'),
        ):
            data = self.fixture()
            change(data)
            with self.assertRaises(ValueError):
                renderer.validate_report_input(data)

    def test_all_formats_share_ids_and_escape_html(self):
        data = self.fixture()
        data['title_candidates'][2]['title'] = '库存 <script>alert(1)</script> 阈值'
        with tempfile.TemporaryDirectory() as tmp:
            input_path = Path(tmp) / 'input.json'
            output_dir = Path(tmp) / 'output'
            input_path.write_text(json.dumps(data, ensure_ascii=False), encoding='utf-8')
            old_argv = sys.argv
            try:
                sys.argv = [str(SCRIPT), '--input', str(input_path), '--output-dir', str(output_dir)]
                renderer.main()
            finally:
                sys.argv = old_argv
            markdown = (output_dir / 'title-report-example.md').read_text(encoding='utf-8')
            page = (output_dir / 'title-report-example.html').read_text(encoding='utf-8')
            quality = json.loads((output_dir / 'quality-report.json').read_text(encoding='utf-8'))
            self.assertTrue(quality['passed'])
            self.assertEqual(3, page.count('<h2>'))
            self.assertIn('T3 · 库存 <script>alert(1)</script> 阈值', markdown)
            self.assertIn('T3 · 库存 &lt;script&gt;alert(1)&lt;/script&gt; 阈值', page)
            self.assertNotIn('<script>', page)
            self.assertNotIn('来源账本', markdown)
            with zipfile.ZipFile(output_dir / 'title-report-example.docx') as z:
                xml = z.read('word/document.xml').decode('utf-8')
                ET.fromstring(xml)
                self.assertIn('T3 · 库存', xml)
            from docx import Document
            document = Document(output_dir / 'title-report-example.docx')
            self.assertEqual(6, len(document.tables[0].rows))
            self.assertIn('T3 · 库存', document.tables[0].cell(3, 0).text)
            from pypdf import PdfReader
            pdf_text = ''.join(page.extract_text() or '' for page in PdfReader(output_dir / 'title-report-example.pdf').pages)
            self.assertIn('T3', pdf_text)


if __name__ == '__main__':
    unittest.main()
