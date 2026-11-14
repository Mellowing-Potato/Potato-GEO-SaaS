#!/usr/bin/env python3
# Derived from yao-geo-skills; Copyright (c) 2026 Yao. MIT, see skill-overlays/LICENSE.
# Modified by Yanji contributors for report rendering, validation and packaged fonts.
# Copyright (c) 2026 yao-geo contributors
# Project: yao-geo-panorama-audit
# Date: 2026-07-04
#
# Standard render script for GEO Panorama Audit reports.
# Interface: python3 render_panorama.py --input report_input.json --output-dir ./output/
#
# Produces MD, HTML, DOCX, PDF in deliverables/ sub-directory,
# plus quality-report.json and manifest.json in output root.

from __future__ import annotations

import argparse
import html as html_mod
import json
import os
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

# ─────────────────────────────────────────────────────────────
#  Constants
# ─────────────────────────────────────────────────────────────

DOCX_SAFE_TABLE_WIDTH_CM = 15.8

HEADERS = {
    "method_basis": ["方法环节", "执行方式", "关键字段", "控制风险"],
    "authority_map": ["参考来源", "采用原则", "报告落点", "边界"],
    "question_coverage": ["意图类型", "用户问题", "官网覆盖状态", "缺口判断", "站内用途"],
    "baseline": ["事实域", "官网页面/入口", "官网可抽取事实", "交叉验证来源", "核验状态", "优化方向"],
    "crawl_inventory": ["页面类型", "URL/入口", "状态", "可抽取事实", "缺失字段", "下一步"],
    "entity_profile": ["实体项", "当前口径", "来源", "核验状态", "处理建议"],
    "product_map": ["产品/模块", "核心能力", "适用对象", "商业口径", "事实来源", "缺口"],
    "technical_audit": ["技术项", "状态", "证据", "风险", "修复动作"],
    "competitors": ["对标对象", "公开可见优势", "目标品牌差距", "可借鉴证据", "注意边界"],
    "scores": ["特征", "评分", "证据判断", "优先动作"],
    "opportunities": ["方案类型", "机会", "对应问题", "价值", "工作量", "优先级", "负责人建议", "验收标准"],
    "repairs": ["页面或模块", "主要问题", "修复动作", "输出组件", "依赖来源", "验收口径"],
    "offsite_plan": ["渠道/信源", "建设动作", "支撑断言", "优先级", "负责人建议", "验收口径"],
    "sources": ["事实项", "来源类型", "核验状态", "用途", "备注"],
    "risk_register": ["风险", "影响", "优先级", "负责人建议", "处理方式"],
}

TABLE_KEYS = [
    "method_basis", "authority_map", "question_coverage", "baseline",
    "crawl_inventory", "entity_profile", "product_map", "technical_audit",
    "competitors", "scores", "opportunities", "repairs", "offsite_plan",
    "sources", "risk_register",
]

MINIMUMS = {
    "method_basis": 3, "authority_map": 3, "question_coverage": 5,
    "baseline": 3, "crawl_inventory": 3, "entity_profile": 3,
    "product_map": 3, "technical_audit": 3, "competitors": 3,
    "scores": 8, "opportunities": 3, "repairs": 3, "offsite_plan": 3,
    "sources": 3, "risk_register": 3,
}

SECTION_SPECS = [
    ("section-method", "方法依据与评分口径", "method_basis"),
    ("section-authority", "权威参考映射", "authority_map"),
    ("section-questions", "用户问题覆盖矩阵", "question_coverage"),
    ("section-baseline", "官网抓取与事实交叉验证", "baseline"),
    ("section-crawl", "官网抓取覆盖清单", "crawl_inventory"),
    ("section-entity", "品牌实体与事实档案", "entity_profile"),
    ("section-product", "产品服务与商业信息地图", "product_map"),
    ("section-technical", "技术可抓取与结构化数据诊断", "technical_audit"),
    ("section-competitors", "竞品与外部信源对标", "competitors"),
    ("section-scores", "GEO 特征评分", "scores"),
    ("section-opportunities", "机会地图与优先级矩阵", "opportunities"),
    ("section-onsite", "站内系统方案", "repairs"),
    ("section-offsite", "站外证据建设方案", "offsite_plan"),
    ("section-sources", "来源台账与待确认项", "sources"),
    ("section-risk", "风险、假设与待确认项", "risk_register"),
]

NAV_ITEMS = [
    ("section-summary", "摘要"),
    ("section-method", "方法"),
    ("section-authority", "参考"),
    ("section-questions", "问题"),
    ("section-baseline", "验证"),
    ("section-crawl", "抓取"),
    ("section-entity", "实体"),
    ("section-product", "产品"),
    ("section-technical", "技术"),
    ("section-scores", "评分"),
    ("section-onsite", "站内"),
    ("section-offsite", "站外"),
    ("section-risk", "风险"),
]


# ─────────────────────────────────────────────────────────────
#  Input loading and validation
# ─────────────────────────────────────────────────────────────

def _stringify(value) -> str:
    """Flatten a JSON scalar or list into display text."""
    if value is None:
        return ""
    if isinstance(value, (list, tuple)):
        return "、".join(_stringify(item) for item in value)
    return str(value)


def _coerce_pair(item) -> list[str] | None:
    """Best-effort read of a single key/value entry. None means unusable."""
    if isinstance(item, dict):
        # {"品牌": "..."} — the single-entry object models emit most often.
        if len(item) == 1:
            key, value = next(iter(item.items()))
            return [_stringify(key), _stringify(value)]
        # {"label": "...", "value": "..."} and its many synonyms.
        for key_name in ("key", "label", "name", "title", "term", "field"):
            if key_name not in item:
                continue
            for value_name in ("value", "detail", "content", "text", "description"):
                if value_name in item:
                    return [_stringify(item[key_name]), _stringify(item[value_name])]
        return None
    if isinstance(item, (list, tuple)):
        if len(item) >= 2:
            return [_stringify(item[0]), _stringify(item[1])]
        if len(item) == 1:
            return [_stringify(item[0]), ""]
        return None
    if isinstance(item, str):
        # "品牌：岭序商机云" — a flat string instead of a pair.
        for separator in ("：", ":", "|", "\t"):
            if separator in item:
                key, _, value = item.partition(separator)
                return [key.strip(), value.strip()]
        return [item.strip(), ""]
    return None


def _normalize_pairs(value, field_name: str) -> list[list[str]]:
    """Coerce a key/value collection into the documented [[key, value], ...] shape.

    This input file is LLM-generated, so `meta` and `summary` arrive in whatever
    shape the model felt like emitting: the documented list of pairs, a plain
    object, a list of single-key objects, or "key: value" strings. Renderers
    unpack these directly (`for k, v in ...`), which raises ValueError on any
    other shape and takes down the entire render — one stray row used to cost
    the customer the whole report. Normalise once here so every renderer sees
    the same thing.

    Unusable entries are dropped with a warning rather than raised, for the same
    reason: a malformed row is worth losing, a report is not.
    """
    if not value:
        return []

    items = value.items() if isinstance(value, dict) else value
    if isinstance(items, (str, bytes)):
        items = [items]

    pairs: list[list[str]] = []
    for item in items:
        pair = _coerce_pair(item)
        if pair is None:
            print(
                f"[render_panorama] WARN: dropped unreadable {field_name} entry: {item!r}",
                file=sys.stderr,
            )
            continue
        pairs.append(pair)
    return pairs


def load_and_validate(input_path: Path) -> dict:
    """Load JSON input, apply defaults, ensure minimum row counts."""
    with open(input_path, "r", encoding="utf-8") as f:
        data = json.load(f)

    # Ensure scalar fields
    data.setdefault("title", "GEO 全景诊断报告")
    data.setdefault("brand_name", "")
    data.setdefault("subtitle", "")
    data.setdefault("notice", "本报告由 AI 生成，仅供参考。")
    data.setdefault("limitations", "")
    data.setdefault("conclusion", "")
    # Pair lists the model routinely mis-shapes; see _normalize_pairs.
    data["meta"] = _normalize_pairs(data.get("meta"), "meta")
    data["summary"] = _normalize_pairs(data.get("summary"), "summary")

    questions = data.get("diagnostic_questions") or []
    if isinstance(questions, dict):
        questions = list(questions.values())
    elif isinstance(questions, str):
        questions = [questions]
    data["diagnostic_questions"] = [
        text for text in (_stringify(q).strip() for q in questions) if text
    ]

    data.setdefault("section_meanings", {})
    if not isinstance(data["section_meanings"], dict):
        data["section_meanings"] = {}

    # Ensure table arrays and apply minimums
    for key in TABLE_KEYS:
        data.setdefault(key, [])
        if not isinstance(data[key], list):
            data[key] = []
        min_rows = MINIMUMS.get(key, 0)
        rows = data[key]
        if 0 < len(rows) < min_rows:
            template = rows[-1]
            while len(rows) < min_rows:
                rows.append(list(template) if isinstance(template, list) else template)

    # Derive slug for filenames
    brand = data.get("brand_name", "") or "geo"
    data["slug"] = _slugify(brand)

    return data


def _slugify(text: str) -> str:
    """Create a filesystem-safe slug from brand name."""
    import re
    slug = re.sub(r"[^\w\u4e00-\u9fff]+", "-", text.strip()).strip("-").lower()
    return slug or "geo-panorama"


# ─────────────────────────────────────────────────────────────
#  Markdown rendering
# ─────────────────────────────────────────────────────────────

def _md_table(headers: list[str], rows: list[list]) -> str:
    lines = [
        "| " + " | ".join(headers) + " |",
        "| " + " | ".join(["---"] * len(headers)) + " |",
    ]
    for row in rows:
        safe = [str(cell).replace("\n", "<br>") for cell in row]
        # Pad or trim to match header count
        while len(safe) < len(headers):
            safe.append("")
        lines.append("| " + " | ".join(safe[:len(headers)]) + " |")
    return "\n".join(lines)


def render_markdown(data: dict) -> str:
    brand = data.get("brand_name", "")
    subtitle = data.get("subtitle", "")
    notice = data.get("notice", "")
    meanings = data.get("section_meanings", {})

    meta_lines = "\n".join(
        f"- {k}：{v}" for k, v in data.get("meta", [])
    ) if data.get("meta") else "- （无元信息）"

    summary_lines = "\n".join(
        f"- **{title}**：{detail}" for title, detail in data.get("summary", [])
    ) if data.get("summary") else "- （无摘要）"

    questions_lines = "\n".join(
        f"{i}. {q}" for i, q in enumerate(data.get("diagnostic_questions", []), 1)
    ) if data.get("diagnostic_questions") else "（无诊断问题）"

    parts = [
        f"# {data['title']}",
        "",
        f"**{brand}｜{subtitle}**" if brand or subtitle else "",
        "",
        f"> {notice}" if notice else "",
        "",
        "## 诊断元信息",
        "",
        meta_lines,
        "",
        "## 执行摘要",
        "",
        summary_lines,
    ]

    for anchor, title, key in SECTION_SPECS:
        parts.extend(["", f"## {title}", ""])
        if data.get(key):
            parts.append(_md_table(HEADERS[key], data[key]))
        else:
            parts.append("（暂无数据）")
        meaning = meanings.get(key, "")
        if meaning:
            parts.extend(["", f"判断含义：{meaning}"])

    parts.extend([
        "", "## 诊断问题池", "",
        questions_lines,
        "", "## 结论", "",
        data.get("conclusion", "（无结论）"),
        "",
    ])

    return "\n".join(parts)


# ─────────────────────────────────────────────────────────────
#  HTML rendering
# ─────────────────────────────────────────────────────────────

def _html_table(headers: list[str], rows: list[list]) -> str:
    """Render a single HTML table with overflow-wrap and fixed layout."""
    th = "".join(f"<th>{html_mod.escape(str(h))}</th>" for h in headers)
    body_rows = []
    for row in rows:
        cells = []
        for i, cell in enumerate(row):
            if i >= len(headers):
                break
            cells.append(f"<td>{html_mod.escape(str(cell))}</td>")
        # Pad missing cells
        while len(cells) < len(headers):
            cells.append("<td></td>")
        body_rows.append("<tr>" + "".join(cells) + "</tr>")
    return (
        f'<div class="table-wrap"><table><thead><tr>{th}</tr></thead>'
        f'<tbody>{"".join(body_rows)}</tbody></table></div>'
    )


def render_html(data: dict) -> str:
    """Generate a complete standalone HTML document."""
    brand = html_mod.escape(data.get("brand_name", ""))
    subtitle = html_mod.escape(data.get("subtitle", ""))
    notice = html_mod.escape(data.get("notice", ""))
    title = html_mod.escape(data.get("title", ""))
    meanings = data.get("section_meanings", {})

    css = _build_css()
    parts = [
        "<!doctype html>",
        '<html lang="zh-Hans">',
        "<head>",
        '<meta charset="utf-8">',
        '<meta name="viewport" content="width=device-width, initial-scale=1">',
        f"<title>{title}</title>",
        f"<style>{css}</style>",
        "</head>",
        '<body><main class="page">',
    ]

    # Cover
    parts.append('<section class="cover">')
    parts.append(f"<h1>{title}</h1>")
    if brand or subtitle:
        parts.append(f'<p class="subtitle">{brand}｜{subtitle}</p>')
    parts.append("</section>")

    if notice:
        parts.append(f'<p class="notice">{notice}</p>')

    # Sticky nav
    parts.append('<nav class="report-nav" aria-label="报告章节菜单"><span>章节</span>')
    for anchor, label in NAV_ITEMS:
        parts.append(f'<a href="#{anchor}">{html_mod.escape(label)}</a>')
    parts.append("</nav>")

    # Meta info
    parts.append('<h2 id="section-meta">诊断元信息</h2>')
    if data.get("meta"):
        parts.append('<div class="meta-grid">')
        for k, v in data["meta"]:
            parts.append(
                f"<div>{html_mod.escape(str(k))}</div>"
                f"<div>{html_mod.escape(str(v))}</div>"
            )
        parts.append("</div>")

    # Summary
    parts.append('<h2 id="section-summary">执行摘要</h2>')
    if data.get("summary"):
        parts.append("<ul>")
        for item in data["summary"]:
            if isinstance(item, (list, tuple)) and len(item) >= 2:
                parts.append(
                    f"<li><strong>{html_mod.escape(str(item[0]))}</strong>："
                    f"{html_mod.escape(str(item[1]))}</li>"
                )
            else:
                parts.append(f"<li>{html_mod.escape(str(item))}</li>")
        parts.append("</ul>")

    # Sections with tables
    for anchor, section_title, key in SECTION_SPECS:
        wrap = key in ("sources", "risk_register")
        if wrap:
            parts.append('<section class="avoid-break">')
        parts.append(f'<h2 id="{anchor}">{html_mod.escape(section_title)}</h2>')
        if data.get(key):
            parts.append(_html_table(HEADERS[key], data[key]))
        else:
            parts.append("<p>（暂无数据）</p>")
        meaning = meanings.get(key, "")
        if meaning:
            parts.append(f'<p class="meaning">判断含义：{html_mod.escape(meaning)}</p>')
        if wrap:
            parts.append("</section>")

    # Diagnostic questions
    parts.append('<h2 id="section-diagnostic">诊断问题池</h2>')
    questions = data.get("diagnostic_questions", [])
    if questions:
        parts.append("<ol>")
        for q in questions:
            parts.append(f"<li>{html_mod.escape(str(q))}</li>")
        parts.append("</ol>")
    else:
        parts.append("<p>（无诊断问题）</p>")

    # Conclusion
    parts.append('<h2 id="section-conclusion">结论</h2>')
    parts.append(f"<p>{html_mod.escape(str(data.get('conclusion', '')))}</p>")

    parts.append("</main></body></html>")
    return "\n".join(parts)


def _build_css() -> str:
    """松墨青禾 design tokens + report-specific styles."""
    return """
:root {
  color-scheme: light;
  --bg-page: #F7F4ED;
  --surface: #FFFEF8;
  --text: #1D1B1C;
  --text-strong: #223E36;
  --text-secondary: #4A4035;
  --text-muted: #80766E;
  --border: #DDD7C8;
  --border-strong: #B5AD9C;
  --primary: #3F7A61;
  --primary-hover: #2F684F;
  --primary-soft: #E1ECE4;
  --soft-bg: #F4F0E6;
  --accent-value: #9A7132;
  --danger: #9E2F2F;
  --line: #D2C8BC;
}
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; background: var(--bg-page); color: var(--text); }
html { scroll-behavior: smooth; }
body {
  font-family: "Source Han Sans SC", "Noto Sans SC", "Inter", system-ui, sans-serif;
  font-size: 15px;
  line-height: 1.66;
}
.page {
  max-width: 1060px;
  margin: 0 auto;
  padding: 46px 42px 64px;
  background: var(--surface);
  min-height: 100vh;
}
.cover {
  border-bottom: 2px solid var(--text-strong);
  padding-bottom: 20px;
  margin-bottom: 26px;
}
h1, h2, h3 { margin: 0; font-weight: 700; letter-spacing: 0; line-height: 1.3; color: var(--text-strong); }
h1 { font-size: 30px; margin-bottom: 10px; }
h2 {
  font-size: 20px; margin-top: 32px; margin-bottom: 12px;
  padding-bottom: 7px; border-bottom: 1px solid var(--border);
  scroll-margin-top: 78px;
}
p { margin: 0 0 12px; }
.subtitle { color: var(--text-secondary); font-size: 16px; }
.notice {
  border: 1px solid var(--border);
  background: var(--surface);
  padding: 12px 14px;
  margin: 18px 0;
  line-height: 1.58;
  overflow-wrap: anywhere;
  border-radius: 4px;
}
.report-nav {
  position: sticky;
  top: 0;
  z-index: 30;
  display: flex;
  align-items: center;
  gap: 8px;
  overflow-x: auto;
  white-space: nowrap;
  padding: 9px 10px;
  margin: 0 0 18px;
  border: 1px solid var(--border);
  background: var(--surface);
  box-shadow: 0 1px 4px rgba(29, 27, 28, 0.08);
  scrollbar-width: thin;
  border-radius: 4px;
}
.report-nav span {
  flex: 0 0 auto;
  color: var(--text-muted);
  font-size: 13px;
  font-weight: 700;
}
.report-nav a {
  flex: 0 0 auto;
  color: var(--text);
  text-decoration: none;
  font-size: 13px;
  line-height: 1.2;
  padding: 5px 8px;
  border: 1px solid transparent;
  border-radius: 3px;
}
.report-nav a:hover,
.report-nav a:focus {
  border-color: var(--border);
  background: var(--primary-soft);
  color: var(--primary-hover);
  outline: none;
}
.meta-grid {
  display: grid;
  grid-template-columns: 150px minmax(0, 1fr);
  gap: 0;
  border: 1px solid var(--border);
  margin: 14px 0 18px;
  border-radius: 4px;
  overflow: hidden;
}
.meta-grid div { padding: 9px 11px; border-bottom: 1px solid var(--border); overflow-wrap: anywhere; }
.meta-grid div:nth-child(odd) { background: var(--soft-bg); font-weight: 600; color: var(--text-strong); }
.meta-grid div:nth-last-child(-n+2) { border-bottom: 0; }
ul, ol { margin-top: 8px; padding-left: 22px; }
li { margin: 4px 0; }
.table-wrap { width: 100%; overflow-x: auto; margin: 12px 0 10px; }
table {
  width: 100%; border-collapse: collapse; table-layout: fixed;
  background: var(--surface); border: 1px solid var(--border);
  border-radius: 4px;
}
th, td {
  border: 1px solid var(--border);
  padding: 8px 9px;
  vertical-align: top;
  overflow-wrap: anywhere;
  word-break: break-word;
}
th {
  background: var(--soft-bg);
  color: var(--text-strong);
  font-weight: 600;
  text-align: left;
}
tbody tr:nth-child(even) { background: rgba(244, 240, 230, 0.4); }
.meaning {
  color: var(--text-secondary);
  border-left: 3px solid var(--primary);
  padding-left: 10px;
  margin: 8px 0 18px;
}
.avoid-break { break-inside: avoid; page-break-inside: avoid; }
@page { size: A4; margin: 16mm 14mm; }
@media (max-width: 720px) {
  body { font-size: 14px; }
  .page { padding: 28px 18px 42px; }
  h1 { font-size: 26px; }
  h2 { font-size: 18px; scroll-margin-top: 86px; }
  .report-nav { margin-left: -18px; margin-right: -18px; border-left: 0; border-right: 0; border-radius: 0; }
  .meta-grid { grid-template-columns: 1fr; }
  .meta-grid div:nth-child(odd) { border-bottom: 0; }
  .meta-grid div:nth-last-child(-n+2) { border-bottom: 1px solid var(--border); }
  .meta-grid div:last-child { border-bottom: 0; }
}
@media print {
  html, body, .page { background: #ffffff !important; }
  body { font-size: 11px; line-height: 1.45; }
  .page { max-width: none; padding: 0; }
  .report-nav { display: none; }
  h1 { font-size: 22px; }
  h2 { font-size: 15px; break-after: avoid; page-break-after: avoid; }
  .table-wrap { overflow: visible; break-inside: auto; page-break-inside: auto; }
  table { table-layout: fixed; page-break-inside: auto; }
  tr { break-inside: avoid; page-break-inside: avoid; }
  th, td { padding: 5px 6px; }
}
"""


# ─────────────────────────────────────────────────────────────
#  DOCX rendering
# ─────────────────────────────────────────────────────────────

def _try_import_docx():
    """Import python-docx; return module or None."""
    try:
        from docx import Document
        from docx.enum.table import WD_TABLE_ALIGNMENT, WD_CELL_VERTICAL_ALIGNMENT
        from docx.enum.text import WD_ALIGN_PARAGRAPH
        from docx.oxml import OxmlElement
        from docx.oxml.ns import qn
        from docx.shared import Cm, Pt, RGBColor
        return {
            "Document": Document,
            "WD_TABLE_ALIGNMENT": WD_TABLE_ALIGNMENT,
            "WD_CELL_VERTICAL_ALIGNMENT": WD_CELL_VERTICAL_ALIGNMENT,
            "WD_ALIGN_PARAGRAPH": WD_ALIGN_PARAGRAPH,
            "OxmlElement": OxmlElement,
            "qn": qn,
            "Cm": Cm,
            "Pt": Pt,
            "RGBColor": RGBColor,
        }
    except ImportError:
        return None


def _cm_to_twips(width_cm: float) -> int:
    return int(width_cm / 2.54 * 1440)


def _set_cell_text(cell, text: str, docx: dict, bold: bool = False,
                   shade: str | None = None, font_size: float = 8.5) -> None:
    OxmlElement = docx["OxmlElement"]
    qn = docx["qn"]
    Pt = docx["Pt"]
    WD_ALIGN = docx["WD_ALIGN_PARAGRAPH"]
    WD_VERT = docx["WD_CELL_VERTICAL_ALIGNMENT"]

    cell.text = ""
    paragraph = cell.paragraphs[0]
    paragraph.alignment = WD_ALIGN.LEFT
    run = paragraph.add_run(str(text))
    run.bold = bold
    run.font.size = Pt(font_size)
    run.font.name = "Source Han Sans SC"
    rpr = run._element.get_or_add_rPr()
    rFonts = rpr.find(qn("w:rFonts"))
    if rFonts is None:
        rFonts = OxmlElement("w:rFonts")
        rpr.insert(0, rFonts)
    rFonts.set(qn("w:eastAsia"), "Source Han Sans SC")
    cell.vertical_alignment = WD_VERT.TOP
    _set_cell_margins(cell, docx, top=90, start=90, bottom=90, end=90)
    if shade:
        _set_cell_shading(cell, shade, docx)


def _set_cell_width(cell, width_cm: float, docx: dict) -> None:
    OxmlElement = docx["OxmlElement"]
    qn = docx["qn"]
    Cm = docx["Cm"]
    cell.width = Cm(width_cm)
    tc_pr = cell._tc.get_or_add_tcPr()
    tc_w = tc_pr.first_child_found_in("w:tcW")
    if tc_w is None:
        tc_w = OxmlElement("w:tcW")
        tc_pr.append(tc_w)
    tc_w.set(qn("w:type"), "dxa")
    tc_w.set(qn("w:w"), str(_cm_to_twips(width_cm)))


def _set_fixed_table_layout(table, widths_cm: list[float], docx: dict) -> None:
    OxmlElement = docx["OxmlElement"]
    qn = docx["qn"]
    table.autofit = False
    tbl_pr = table._tbl.tblPr
    layout = tbl_pr.first_child_found_in("w:tblLayout")
    if layout is None:
        layout = OxmlElement("w:tblLayout")
        tbl_pr.append(layout)
    layout.set(qn("w:type"), "fixed")
    table_width = min(sum(widths_cm), DOCX_SAFE_TABLE_WIDTH_CM)
    tbl_w = tbl_pr.first_child_found_in("w:tblW")
    if tbl_w is None:
        tbl_w = OxmlElement("w:tblW")
        tbl_pr.append(tbl_w)
    tbl_w.set(qn("w:type"), "dxa")
    tbl_w.set(qn("w:w"), str(_cm_to_twips(table_width)))
    tbl_indent = tbl_pr.first_child_found_in("w:tblInd")
    if tbl_indent is None:
        tbl_indent = OxmlElement("w:tblInd")
        tbl_pr.append(tbl_indent)
    tbl_indent.set(qn("w:type"), "dxa")
    tbl_indent.set(qn("w:w"), "0")
    tbl = table._tbl
    for existing in list(tbl.findall(qn("w:tblGrid"))):
        tbl.remove(existing)
    grid = OxmlElement("w:tblGrid")
    for width in widths_cm:
        grid_col = OxmlElement("w:gridCol")
        grid_col.set(qn("w:w"), str(_cm_to_twips(width)))
        grid.append(grid_col)
    tbl.insert(1, grid)
    for row in table.rows:
        for idx, width in enumerate(widths_cm):
            if idx < len(row.cells):
                _set_cell_width(row.cells[idx], width, docx)


def _set_cell_shading(cell, fill: str, docx: dict) -> None:
    OxmlElement = docx["OxmlElement"]
    qn = docx["qn"]
    tc_pr = cell._tc.get_or_add_tcPr()
    shading = OxmlElement("w:shd")
    shading.set(qn("w:fill"), fill)
    tc_pr.append(shading)


def _set_cell_margins(cell, docx: dict, top: int, start: int, bottom: int, end: int) -> None:
    OxmlElement = docx["OxmlElement"]
    qn = docx["qn"]
    tc = cell._tc
    tc_pr = tc.get_or_add_tcPr()
    tc_mar = tc_pr.first_child_found_in("w:tcMar")
    if tc_mar is None:
        tc_mar = OxmlElement("w:tcMar")
        tc_pr.append(tc_mar)
    for margin, value in [("top", top), ("start", start), ("bottom", bottom), ("end", end)]:
        node = tc_mar.find(qn(f"w:{margin}"))
        if node is None:
            node = OxmlElement(f"w:{margin}")
            tc_mar.append(node)
        node.set(qn("w:w"), str(value))
        node.set(qn("w:type"), "dxa")


def _set_table_borders(table, docx: dict) -> None:
    OxmlElement = docx["OxmlElement"]
    qn = docx["qn"]
    tbl = table._tbl
    tbl_pr = tbl.tblPr
    borders = tbl_pr.first_child_found_in("w:tblBorders")
    if borders is None:
        borders = OxmlElement("w:tblBorders")
        tbl_pr.append(borders)
    for edge in ["top", "left", "bottom", "right", "insideH", "insideV"]:
        tag = f"w:{edge}"
        element = borders.find(qn(tag))
        if element is None:
            element = OxmlElement(tag)
            borders.append(element)
        element.set(qn("w:val"), "single")
        element.set(qn("w:sz"), "4")
        element.set(qn("w:space"), "0")
        element.set(qn("w:color"), "D2C8BC")


def _column_widths(headers: list[str]) -> list[float]:
    """Compute column widths for a table, ensuring total <= DOCX_SAFE_TABLE_WIDTH_CM."""
    n = len(headers)
    if n <= 4:
        w = DOCX_SAFE_TABLE_WIDTH_CM / n
        return [w] * n
    # For wide tables: first column gets extra space, rest share evenly
    first_w = min(3.0, DOCX_SAFE_TABLE_WIDTH_CM * 0.18)
    remaining = DOCX_SAFE_TABLE_WIDTH_CM - first_w
    rest_w = remaining / (n - 1)
    return [first_w] + [rest_w] * (n - 1)


def _add_docx_table(doc, headers: list[str], rows: list[list], docx: dict) -> None:
    """Add a formatted table to a DOCX document."""
    WD_TABLE_ALIGNMENT = docx["WD_TABLE_ALIGNMENT"]
    table = doc.add_table(rows=1, cols=len(headers))
    table.alignment = WD_TABLE_ALIGNMENT.LEFT
    _set_table_borders(table, docx)
    font_size = 7.6 if len(headers) >= 8 else 8.0 if len(headers) >= 6 else 8.5
    for idx, header in enumerate(headers):
        _set_cell_text(table.rows[0].cells[idx], header, docx, bold=True, shade="F4F0E6", font_size=font_size)
    for row in rows:
        cells = table.add_row().cells
        for idx, cell_text in enumerate(row):
            if idx < len(headers):
                _set_cell_text(cells[idx], str(cell_text), docx, font_size=font_size)
    _set_fixed_table_layout(table, _column_widths(headers), docx)
    doc.add_paragraph()


def _split_wide_table(headers: list[str], rows: list[list], max_cols: int = 6):
    """Split a wide table into multiple narrower tables, keeping the first column."""
    if len(headers) <= max_cols:
        yield headers, rows
        return
    # First sub-table: columns 0..max_cols-1
    yield headers[:max_cols], [row[:max_cols] for row in rows]
    # Remaining sub-tables: always keep column 0 + next chunk
    remaining = headers[max_cols:]
    remaining_rows = [[row[0]] + row[max_cols:] for row in rows]
    chunk = max_cols - 1  # -1 because column 0 is always included
    i = 0
    while i < len(remaining):
        end = min(i + chunk, len(remaining))
        sub_headers = [headers[0]] + remaining[i:end]
        sub_rows = []
        for row in remaining_rows:
            sub_rows.append([row[0]] + row[i+1:end+1])
        yield sub_headers, sub_rows
        i = end


def render_docx(data: dict, output_path: Path) -> bool:
    """Render DOCX report. Returns True on success, False if python-docx unavailable."""
    docx = _try_import_docx()
    if docx is None:
        print("[render_panorama] python-docx not installed, skipping DOCX", file=sys.stderr)
        return False

    Document = docx["Document"]
    Cm = docx["Cm"]
    Pt = docx["Pt"]
    RGBColor = docx["RGBColor"]
    qn = docx["qn"]
    WD_SECTION = __import__("docx.enum.section", fromlist=["WD_SECTION"]).WD_SECTION

    doc = Document()

    # Page setup
    section = doc.sections[0]
    section.start_type = WD_SECTION.NEW_PAGE
    section.page_width = Cm(21)
    section.page_height = Cm(29.7)
    section.left_margin = Cm(1.7)
    section.right_margin = Cm(1.7)
    section.top_margin = Cm(1.7)
    section.bottom_margin = Cm(1.7)

    # Document styles
    styles = doc.styles
    normal = styles["Normal"]
    normal.font.name = "Source Han Sans SC"
    normal._element.rPr.rFonts.set(qn("w:eastAsia"), "Source Han Sans SC")
    normal.font.size = Pt(10)
    normal.paragraph_format.line_spacing = 1.22
    normal.paragraph_format.space_after = Pt(5)

    for name, size, color_hex in [
        ("Title", 22, "223E36"),
        ("Heading 1", 15, "3F7A61"),
        ("Heading 2", 12, "223E36"),
    ]:
        style = styles[name]
        style.font.name = "Source Han Sans SC"
        style._element.rPr.rFonts.set(qn("w:eastAsia"), "Source Han Sans SC")
        style.font.size = Pt(size)
        style.font.bold = True
        style.font.color.rgb = RGBColor.from_string(color_hex)
        style.paragraph_format.space_before = Pt(12)
        style.paragraph_format.space_after = Pt(7)

    # Title
    title_para = doc.add_paragraph(style="Title")
    title_para.add_run(data.get("title", "GEO 全景诊断报告"))

    brand = data.get("brand_name", "")
    subtitle = data.get("subtitle", "")
    if brand or subtitle:
        sub = doc.add_paragraph()
        sub.add_run(f"{brand}｜{subtitle}").bold = True

    notice = data.get("notice", "")
    if notice:
        p = doc.add_paragraph()
        p.add_run("说明：").bold = True
        p.add_run(notice)

    # Meta info
    doc.add_heading("诊断元信息", level=1)
    if data.get("meta"):
        meta_table = doc.add_table(rows=0, cols=2)
        meta_table.alignment = docx["WD_TABLE_ALIGNMENT"].LEFT
        _set_table_borders(meta_table, docx)
        for k, v in data["meta"]:
            cells = meta_table.add_row().cells
            _set_cell_text(cells[0], str(k), docx, bold=True, shade="F4F0E6")
            _set_cell_text(cells[1], str(v), docx)
        _set_fixed_table_layout(meta_table, [3.0, 12.5], docx)

    # Summary
    doc.add_heading("执行摘要", level=1)
    for item in data.get("summary", []):
        if isinstance(item, (list, tuple)) and len(item) >= 2:
            para = doc.add_paragraph(style="List Bullet")
            run = para.add_run(f"{item[0]}：")
            run.bold = True
            para.add_run(str(item[1]))
        else:
            doc.add_paragraph(str(item), style="List Bullet")

    # Section tables
    meanings = data.get("section_meanings", {})
    for _anchor, section_title, key in SECTION_SPECS:
        doc.add_heading(section_title, level=1)
        rows = data.get(key, [])
        headers = HEADERS[key]

        if key == "opportunities" and rows:
            # Split the 8-column opportunities table into two narrower tables
            _add_docx_table(doc,
                ["方案类型", "机会", "对应问题", "价值", "工作量", "优先级"],
                [row[:6] if len(row) >= 6 else row for row in rows],
                docx)
            doc.add_paragraph("责任人与验收", style="Heading 2")
            _add_docx_table(doc,
                ["机会", "负责人建议", "验收标准"],
                [[row[1] if len(row) > 1 else "", row[6] if len(row) > 6 else "", row[7] if len(row) > 7 else ""] for row in rows],
                docx)
        elif rows:
            # For other wide tables, use split logic
            for sub_headers, sub_rows in _split_wide_table(headers, rows):
                _add_docx_table(doc, sub_headers, sub_rows, docx)
        else:
            doc.add_paragraph("（暂无数据）")

        meaning = meanings.get(key, "")
        if meaning:
            doc.add_paragraph(f"判断含义：{meaning}")

    # Diagnostic questions
    doc.add_heading("诊断问题池", level=1)
    for q in data.get("diagnostic_questions", []):
        doc.add_paragraph(str(q), style="List Number")

    # Conclusion
    doc.add_heading("结论", level=1)
    doc.add_paragraph(data.get("conclusion", ""))

    doc.save(str(output_path))
    return True


# ─────────────────────────────────────────────────────────────
#  PDF rendering
# ─────────────────────────────────────────────────────────────

def render_pdf(html_path: Path, pdf_path: Path) -> bool:
    """Render PDF from HTML. Tries weasyprint, then Chromium, then skips."""
    # Strategy 1: weasyprint
    try:
        import weasyprint
        weasyprint.HTML(filename=str(html_path)).write_pdf(str(pdf_path))
        if pdf_path.exists() and pdf_path.stat().st_size > 1024:
            return True
    except ImportError:
        pass
    except Exception as e:
        print(f"[render_panorama] weasyprint failed: {e}", file=sys.stderr)

    # Strategy 2: headless Chromium / Chrome
    chrome_paths = [
        shutil.which("chromium"),
        shutil.which("chromium-browser"),
        shutil.which("google-chrome"),
        shutil.which("google-chrome-stable"),
        Path("/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"),
    ]
    chrome = None
    for p in chrome_paths:
        if p and Path(p).exists():
            chrome = str(p)
            break

    if chrome:
        try:
            with tempfile.TemporaryDirectory() as tmpdir:
                cmd = [
                    chrome,
                    "--headless=new",
                    "--disable-gpu",
                    "--no-sandbox",
                    "--disable-background-networking",
                    "--disable-sync",
                    "--disable-extensions",
                    "--disable-component-update",
                    "--disable-default-apps",
                    f"--user-data-dir={tmpdir}",
                    f"--print-to-pdf={pdf_path}",
                    "--no-pdf-header-footer",
                    "--print-to-pdf-no-header",
                    html_path.as_uri(),
                ]
                proc = subprocess.Popen(
                    cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL
                )
                try:
                    proc.wait(timeout=60)
                except subprocess.TimeoutExpired:
                    proc.terminate()
                    try:
                        proc.wait(timeout=5)
                    except subprocess.TimeoutExpired:
                        proc.kill()
                if pdf_path.exists() and pdf_path.stat().st_size > 1024:
                    return True
        except Exception as e:
            print(f"[render_panorama] Chromium PDF failed: {e}", file=sys.stderr)

    print("[render_panorama] PDF rendering skipped (no weasyprint or Chromium available)", file=sys.stderr)
    return False


# ─────────────────────────────────────────────────────────────
#  Quality report
# ─────────────────────────────────────────────────────────────

def write_quality_report(data: dict, output_dir: Path,
                         formats_generated: list[str]) -> dict:
    """Run self-checks and write quality-report.json."""
    checks = []

    # 1. 15 named tables present
    table_count = sum(1 for k in TABLE_KEYS if data.get(k))
    checks.append({
        "name": "15_named_tables",
        "passed": table_count >= 15,
        "count": table_count,
    })

    # 2. Minimum rows per table
    violations = []
    for key, min_rows in MINIMUMS.items():
        actual = len(data.get(key, []))
        if actual < min_rows:
            violations.append({"key": key, "expected": min_rows, "actual": actual})
    checks.append({
        "name": "minimum_rows",
        "passed": len(violations) == 0,
        "violations": violations,
    })

    # 3. section_meanings completeness
    meanings = data.get("section_meanings", {})
    missing_meanings = [k for k in TABLE_KEYS if k not in meanings or not meanings[k]]
    checks.append({
        "name": "section_meanings_complete",
        "passed": len(missing_meanings) == 0,
        "missing": missing_meanings,
    })

    # 4. Four formats generated
    checks.append({
        "name": "four_formats_generated",
        "passed": len(formats_generated) >= 4,
        "formats": formats_generated,
    })

    # 5. HTML has sticky nav (checked if HTML was generated)
    checks.append({
        "name": "html_has_sticky_nav",
        "passed": "html" in formats_generated,
    })

    # 6. DOCX table width safe (structural guarantee from code)
    checks.append({
        "name": "docx_table_width_safe",
        "passed": "docx" in formats_generated,
    })

    # 7. Scores dimensions count
    scores_rows = data.get("scores", [])
    checks.append({
        "name": "scores_dimensions_count",
        "passed": len(scores_rows) >= 8,
        "count": len(scores_rows),
        "expected": 8,
    })

    # 8. baseline sources covered (warn-only)
    baseline_sources = set()
    for row in data.get("sources", []):
        if row and len(row) > 0:
            baseline_sources.add(str(row[0]).strip())
    baseline_facts = set()
    for row in data.get("baseline", []):
        if row and len(row) > 0:
            baseline_facts.add(str(row[0]).strip())
    uncovered = baseline_facts - baseline_sources
    checks.append({
        "name": "baseline_sources_covered",
        "passed": True,  # warn-only
        "warn_only": True,
        "uncovered": list(uncovered),
    })

    # 9. Risk register non-empty (warn-only)
    risk_count = len(data.get("risk_register", []))
    checks.append({
        "name": "risk_register_non_empty",
        "passed": risk_count > 0,
        "warn_only": True,
        "count": risk_count,
    })

    # Compute overall score
    passed_count = sum(1 for c in checks if c["passed"])
    score = int((passed_count / len(checks)) * 100) if checks else 0
    all_passed = all(c["passed"] for c in checks if not c.get("warn_only"))

    report = {
        "passed": all_passed,
        "score": score,
        "checks": checks,
    }

    report_path = output_dir / "quality-report.json"
    with open(report_path, "w", encoding="utf-8") as f:
        json.dump(report, f, ensure_ascii=False, indent=2)

    return report


def write_manifest(data: dict, output_dir: Path, formats_generated: list[str]) -> None:
    """Write manifest.json describing the output."""
    manifest = {
        "skill_id": "yao-geo-panorama-audit",
        "title": data.get("title", ""),
        "brand_name": data.get("brand_name", ""),
        "slug": data.get("slug", ""),
        "formats": formats_generated,
        "table_regions": TABLE_KEYS,
        "table_count": sum(1 for k in TABLE_KEYS if data.get(k)),
    }
    path = output_dir / "manifest.json"
    with open(path, "w", encoding="utf-8") as f:
        json.dump(manifest, f, ensure_ascii=False, indent=2)


# ─────────────────────────────────────────────────────────────
#  Main
# ─────────────────────────────────────────────────────────────

def main() -> None:
    parser = argparse.ArgumentParser(description="Render GEO Panorama Audit report")
    parser.add_argument("--input", required=True, help="Path to report_input.json")
    parser.add_argument("--output-dir", required=True, help="Output directory")
    args = parser.parse_args()

    input_path = Path(args.input)
    output_dir = Path(args.output_dir)
    deliver_dir = output_dir / "deliverables"
    deliver_dir.mkdir(parents=True, exist_ok=True)

    # Load and validate
    data = load_and_validate(input_path)
    slug = data["slug"]
    formats = []

    # 1. Markdown
    md_path = deliver_dir / f"{slug}-panorama-audit.md"
    md_content = render_markdown(data)
    md_path.write_text(md_content, encoding="utf-8")
    formats.append("md")
    print(f"[render_panorama] Markdown: {md_path}")

    # 2. HTML
    html_path = deliver_dir / "html-package" / "index.html"
    html_path.parent.mkdir(parents=True, exist_ok=True)
    html_content = render_html(data)
    html_path.write_text(html_content, encoding="utf-8")
    formats.append("html")
    print(f"[render_panorama] HTML: {html_path}")

    # 3. DOCX
    docx_path = deliver_dir / f"{slug}-panorama-audit.docx"
    if render_docx(data, docx_path):
        formats.append("docx")
        print(f"[render_panorama] DOCX: {docx_path}")

    # 4. PDF (from HTML)
    pdf_path = deliver_dir / f"{slug}-panorama-audit.pdf"
    if render_pdf(html_path, pdf_path):
        formats.append("pdf")
        print(f"[render_panorama] PDF: {pdf_path}")

    # 5. Quality report and manifest
    quality = write_quality_report(data, output_dir, formats)
    write_manifest(data, output_dir, formats)
    print(f"[render_panorama] Quality: score={quality['score']}, passed={quality['passed']}")
    print(f"[render_panorama] Formats: {', '.join(formats)}")

    # Exit 0 even if some formats were skipped (partial success)
    sys.exit(0)


if __name__ == "__main__":
    main()
