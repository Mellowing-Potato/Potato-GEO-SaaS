"""Optional brand scenario fields must not abort a paid explainer render."""

from collections import defaultdict
import importlib.util
from pathlib import Path
import unittest


SCRIPT = Path(__file__).resolve().parents[2] / "skill-overlays/yao-geo-explainer-builder/scripts/render_yao_geo_explainer_builder.py"
spec = importlib.util.spec_from_file_location("explainer_renderer", SCRIPT)
renderer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(renderer)


class ExplainerOptionalScenarioTest(unittest.TestCase):
    def test_scenario_without_optional_boundary_renders_with_empty_cell(self):
        data = defaultdict(list)
        data["core_answer"] = "清禾可研是桌面科研工作台。"
        data["data_availability"] = {}
        data["brand_scenarios"] = [{"scenario": "医学文献整理", "fit": "适合按项目整理资料"}]

        sections = renderer.section_spec(data)
        example = next(section for section in sections if section["title"] == "示例与品牌适用场景")
        table = next(block for block in example["blocks"] if block[0] == "table")
        self.assertEqual(["医学文献整理", "适合按项目整理资料", ""], table[2][0])


if __name__ == "__main__":
    unittest.main()
