"""Exercise real renderer entry points with an unavailable native PDF engine."""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from zipfile import ZipFile


ROOT = Path(__file__).resolve().parents[2]
OVERLAYS = ROOT / "skill-overlays"


class OptionalSkillFormatsTest(unittest.TestCase):
    def test_ranking_preserves_the_markdown_and_native_word_table_without_pandoc_or_weasyprint(self):
        with tempfile.TemporaryDirectory() as tmp:
            folder = Path(tmp)
            (folder / "weasyprint.py").write_text('raise OSError("cannot load library libpango")')
            article = "# 排名测试\n\n原来的正文与[来源](https://example.com/source)。\n\n| 名称 | 数量 |\n| --- | --- |\n| 样本甲 | 12 |\n"
            source = folder / "report.md"
            source.write_text(article, encoding="utf-8")
            out = folder / "out"
            script = OVERLAYS / "yao-geo-ranking-article-builder/scripts/render_ranking_article_pack.py"
            process = subprocess.run(
                [sys.executable, str(script), "--source", str(source), "--output-dir", str(out), "--slug", "article"],
                env={**os.environ, "PATH": str(folder), "PYTHONPATH": str(folder)},
                capture_output=True, text=True, timeout=40,
            )
            self.assertEqual((out / "article.md").read_text(encoding="utf-8"), article, process.stderr)
            self.assertIn("样本甲", (out / "article.html").read_text(encoding="utf-8"))
            with ZipFile(out / "article.docx") as archive:
                xml = archive.read("word/document.xml").decode()
            for token in ["原来的正文", "样本甲", "https://example.com/source", "<w:tbl>", "<w:tblBorders>"]:
                self.assertIn(token, xml)
            self.assertFalse((out / "article.pdf").exists())
            status = json.loads((out / "format-status.json").read_text())
            self.assertEqual({"status": "unavailable", "reason": "native_library"}, status["pdf"])
            self.assertFalse(json.loads((out / "quality-report.json").read_text())["passed"])
            self.assertNotEqual(0, process.returncode)

    def test_eager_pdf_import_no_longer_blocks_argument_parsing_for_the_three_affected_skills(self):
        with tempfile.TemporaryDirectory() as tmp:
            (Path(tmp) / "weasyprint.py").write_text('raise OSError("cannot load library libpango")')
            for skill in ["page-blueprint", "ranking-article-builder", "execution-roadmap"]:
                script = next((OVERLAYS / ("yao-geo-" + skill) / "scripts").glob("render_*.py"))
                with self.subTest(skill=skill):
                    result = subprocess.run(
                        [sys.executable, str(script), "--help"], env={**os.environ, "PYTHONPATH": tmp},
                        capture_output=True, text=True, timeout=10,
                    )
                    self.assertEqual(0, result.returncode, result.stderr)


if __name__ == "__main__":
    unittest.main()
