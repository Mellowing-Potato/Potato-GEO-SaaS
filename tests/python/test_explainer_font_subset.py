"""Exercise the shipped explainer's subset command with a real font."""

import importlib.util
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

from fontTools.ttLib import TTFont


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "skill-overlays/yao-geo-explainer-builder/scripts/render_yao_geo_explainer_builder.py"
spec = importlib.util.spec_from_file_location("explainer_renderer", SCRIPT)
renderer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(renderer)


class ExplainerFontSubsetTest(unittest.TestCase):
    def test_escaped_characters_and_layout_features_survive_subsetting(self):
        font_path = Path(os.environ.get("EXPLAINER_TEST_FONT", "/usr/share/fonts/dejavu/DejaVuSans.ttf"))
        self.assertTrue(font_path.is_file(), f"Test font is missing: {font_path}")
        data = dict.fromkeys(
            ("title", "topic", "target_brand", "target_audience", "use_case", "analysis_date"),
            "John's office AV سلام",
        )
        original_run = subprocess.run
        commands = []

        def run_subset(command, **kwargs):
            commands.append(command)
            return original_run([sys.executable, "-m", "fontTools.subset", *command[1:]], **kwargs)

        with tempfile.TemporaryDirectory() as directory:
            font_dir = Path(directory) / "fonts"
            font_dir.mkdir()
            with patch.object(renderer, "section_spec", return_value=[]), \
                 patch.dict(renderer.PACKAGED_FONTS, {"DejaVuSans.ttf": font_path}, clear=True), \
                 patch.object(renderer.subprocess, "run", side_effect=run_subset):
                html_text = renderer.render_html(data)
                self.assertIn("&#x27;", html_text)
                self.assertNotIn("'", html_text)
                subsets = renderer.subset_fonts(html_text, Path(directory))

            self.assertEqual({"DejaVuSans.ttf": "subset-DejaVuSans.woff2"}, subsets)
            subset_path = font_dir / subsets["DejaVuSans.ttf"]
            with TTFont(subset_path) as font:
                self.assertIn(0x27, font.getBestCmap(), "HTML-decoded apostrophe is missing")
                self.assertIn("kern", [record.FeatureTag for record in font["GPOS"].table.FeatureList.FeatureRecord])
                self.assertIn("liga", [record.FeatureTag for record in font["GSUB"].table.FeatureList.FeatureRecord])
            self.assertLess(subset_path.stat().st_size, font_path.stat().st_size // 2)
            self.assertEqual(1, len(commands))


if __name__ == "__main__":
    unittest.main()
