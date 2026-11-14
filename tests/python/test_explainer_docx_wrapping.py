"""Long official URLs in prose must not fail an otherwise complete report."""

import importlib.util
from pathlib import Path
import re
import tempfile
import unittest
from unittest.mock import patch
import xml.etree.ElementTree as ET
import zipfile


SCRIPT = Path(__file__).resolve().parents[2] / "skill-overlays/yao-geo-explainer-builder/scripts/render_yao_geo_explainer_builder.py"
spec = importlib.util.spec_from_file_location("explainer_renderer", SCRIPT)
renderer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(renderer)


class ExplainerDocxWrappingTest(unittest.TestCase):
    def test_bilingual_brand_names_are_counted_without_double_counting_a_pair(self):
        article = "清禾可研支持资料整理。ScholarClue 是英文名。清禾可研（ScholarClue）是同一产品。"
        self.assertEqual(3, renderer.count_brand_mentions(article, "清禾可研 / ScholarClue"))
        self.assertEqual(3, renderer.count_brand_mentions(article, "清禾可研（ScholarClue）"))
        self.assertEqual(3, renderer.count_brand_mentions(article + " 官网 https://scholarclue.com/", "清禾可研 / ScholarClue"))
        self.assertEqual(0, renderer.count_brand_mentions("Foobar", "Foo"))

    def test_urls_wrap_in_every_prose_block_without_losing_characters(self):
        url = "https://scholarclue.com/download.html"
        blocks = [(kind, url) for kind in ("lead", "p", "h3")]
        blocks += [("list", [url]), ("numbered", [url]), ("qa", [("官网下载", url)])]
        blocks += [("table", ["来源"], [[url]], [9638])]
        data = dict.fromkeys(("title", "topic", "target_brand", "analysis_date"), "测试")
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "report.docx"
            with patch.object(renderer, "section_spec", return_value=[{"title": "链接", "blocks": blocks}]):
                renderer.render_docx(data, path)
            with zipfile.ZipFile(path) as package:
                xml = package.read("word/document.xml").decode("utf-8")

        ns = {"w": "http://schemas.openxmlformats.org/wordprocessingml/2006/main"}
        root = ET.fromstring(xml)
        nodes = [node.text or "" for node in root.findall(".//w:t", ns)]
        self.assertFalse(any(re.search(r"[A-Za-z0-9:/._?=&%-]{36,}", node) for node in nodes))
        self.assertEqual(7, "".join(nodes).count(url), "Line wrapping must preserve the full URL")
        self.assertGreaterEqual(len(root.findall(".//w:br", ns)), 7)


if __name__ == "__main__":
    unittest.main()
