#!/usr/bin/env python3
# Derived from yao-geo-skills; Copyright (c) 2026 Yao. MIT, see skill-overlays/LICENSE.
# Modified by Yanji contributors for report rendering, validation and packaged fonts.
"""Render the compact title report to Markdown, HTML, DOCX and PDF."""

from __future__ import annotations

import argparse
import datetime as dt
import html
import json
import re
import zipfile
from pathlib import Path
from xml.etree import ElementTree as ET
from xml.sax.saxutils import escape as xml_escape

SCORE_FIELDS = ('intent_match', 'entity_clarity', 'differentiation', 'citation_potential', 'compliance', 'freshness')
TITLE_FIELDS = ('title', 'type', 'intent', 'scenario', 'platform_fit', 'why_it_works', 'rewrite_advice')
W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'


def validate_report_input(data: dict) -> dict:
    for key in ('output_stem', 'report_title', 'generated_at', 'recommendation'):
        if not isinstance(data.get(key), str) or not data[key].strip():
            raise ValueError(f'{key} 必须是非空文字')
    if not re.fullmatch(r'[A-Za-z0-9_-]+', data['output_stem']):
        raise ValueError('output_stem 只能包含字母、数字、下划线和连字符')
    if not isinstance(data.get('project'), dict) or not isinstance(data['project'].get('name'), str):
        raise ValueError('project.name 必须是文字')
    candidates = data.get('title_candidates')
    if not isinstance(candidates, list) or not 5 <= len(candidates) <= 8:
        raise ValueError('title_candidates 必须为 5–8 个')
    ids = set()
    for index, item in enumerate(candidates, 1):
        if not isinstance(item, dict):
            raise ValueError('候选标题必须是对象')
        raw_id = item.get('id')
        item['id'] = str(raw_id).strip() if raw_id is not None else ''
        if not item['id']:
            item['id'] = f'T{index}'
        if not item['id'] or item['id'] in ids:
            raise ValueError('候选标题 ID 为空或重复')
        ids.add(item['id'])
        if any(not isinstance(item.get(key), str) or not item[key].strip() for key in TITLE_FIELDS):
            raise ValueError(f'候选 {item["id"]} 缺少必需文字字段')
        scores = item.get('scores')
        if not isinstance(scores, dict) or any(key not in scores or isinstance(scores[key], bool)
                or not isinstance(scores[key], (int, float)) or not 0 <= scores[key] <= 5 for key in SCORE_FIELDS):
            raise ValueError(f'候选 {item["id"]} 评分须为 0–5 的数字')
    if not isinstance(data.get('recommended_id'), str) or data['recommended_id'].strip() not in ids:
        raise ValueError('recommended_id 必须指向候选标题')
    data['recommended_id'] = data['recommended_id'].strip()
    advice = data.get('usage_advice')
    if not isinstance(advice, list) or not 1 <= len(advice) <= 3 or any(not isinstance(x, str) or not x.strip() for x in advice):
        raise ValueError('usage_advice 必须为 1–3 条非空建议')
    for key in ('compliance_checks', 'self_review', 'structure_map'):
        if data.get(key) is not None and not isinstance(data[key], list):
            raise ValueError(f'{key} 必须为列表或 null')
    return data


def score(item: dict) -> int:
    return round(sum(item['scores'][key] for key in SCORE_FIELDS) / (len(SCORE_FIELDS) * 5) * 100)


def rows(data: dict) -> list[list[str]]:
    return [[item['id'] + ' · ' + item['title'], item['intent'], str(score(item)) + ' / 100', item['why_it_works']]
            for item in data['title_candidates']]


def recommendation(data: dict) -> str:
    item = next(item for item in data['title_candidates'] if item['id'] == data['recommended_id'])
    return item['id'] + ' · ' + item['title'] + '：' + data['recommendation']


def advice(data: dict) -> list[str]:
    result = ['评分为模型建议，尚未经过实际点击率或排名验证。', *data['usage_advice']]
    for row in data.get('compliance_checks') or []:
        if isinstance(row, list):
            result.append('；'.join(str(cell) for cell in row))
    return result


def md_table(items: list[list[str]]) -> str:
    def cell(value: str) -> str:
        return str(value).replace('|', '\\|').replace('\n', '<br>')
    return '\n'.join(['| 标题 | 适用意图 | 建议评分 | 理由 |', '| --- | --- | --- | --- |',
                      *('| ' + ' | '.join(cell(x) for x in row) + ' |' for row in items)])


def render_markdown(data: dict) -> str:
    return '\n\n'.join(['# ' + data['report_title'], '## 候选标题', md_table(rows(data)),
                        '## 推荐标题', recommendation(data), '## 使用建议',
                        '\n'.join('- ' + item for item in advice(data))]) + '\n'


def render_html(data: dict) -> str:
    tr = ''.join('<tr>' + ''.join('<td>' + html.escape(str(cell)) + '</td>' for cell in row) + '</tr>' for row in rows(data))
    tips = ''.join('<li>' + html.escape(item) + '</li>' for item in advice(data))
    return f'''<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{html.escape(data['report_title'])}</title><style>
:root{{--paper:#fff;--ink:#141413;--muted:#555;--brand:#1B365D;--border:#d8d3c6}}
body{{margin:0;background:var(--paper);color:var(--ink);font-family:"Source Han Sans SC","PingFang SC","Microsoft YaHei",sans-serif;line-height:1.55}}
main{{max-width:980px;margin:auto;padding:32px 24px 64px}}h1,h2{{font-family:"TsangerJinKai02","Source Han Serif SC","Noto Serif CJK SC",serif;font-weight:500}}
h1{{font-size:34px}}h2{{font-size:23px;border-bottom:1px solid var(--border);padding-bottom:8px}}.meta{{color:var(--muted)}}
table{{width:100%;border-collapse:collapse;table-layout:fixed}}th,td{{border:1px solid var(--border);padding:9px;text-align:left;overflow-wrap:anywhere}}
th{{background:#f5f4ed;color:var(--brand)}}.note{{padding:12px 16px;border-left:3px solid var(--brand);background:#faf9f5}}
@page{{size:A4;margin:20mm 18mm}}@media print{{main{{padding:0}}th,td{{font-size:10px}}tr{{break-inside:avoid}}}}
</style></head><body><main><header><h1>{html.escape(data['report_title'])}</h1><p class="meta">{html.escape(data['project']['name'])} · {html.escape(data['generated_at'])}</p></header>
<section><h2>候选标题</h2><table><thead><tr><th>标题</th><th>适用意图</th><th>建议评分</th><th>理由</th></tr></thead><tbody>{tr}</tbody></table></section>
<section><h2>推荐标题</h2><p class="note">{html.escape(recommendation(data))}</p></section>
<section><h2>使用建议</h2><ul>{tips}</ul></section></main></body></html>'''


def paragraph(value: str, style: str = '') -> str:
    pstyle = f'<w:pPr><w:pStyle w:val="{style}"/></w:pPr>' if style else ''
    return f'<w:p>{pstyle}<w:r><w:t xml:space="preserve">{xml_escape(str(value))}</w:t></w:r></w:p>'


def render_docx_xml(data: dict) -> str:
    table_rows = [('<w:tblHeader/>', ['标题', '适用意图', '建议评分', '理由'])] + [('', row) for row in rows(data)]
    table = '<w:tbl><w:tblPr><w:tblW w:w="9000" w:type="dxa"/></w:tblPr>'
    table += '<w:tblGrid>' + '<w:gridCol w:w="2250"/>' * 4 + '</w:tblGrid>'
    for header, row in table_rows:
        table += '<w:tr><w:trPr>' + header + '</w:trPr>'
        table += ''.join('<w:tc><w:tcPr><w:tcW w:w="2250" w:type="dxa"/></w:tcPr>' + paragraph(cell) + '</w:tc>' for cell in row)
        table += '</w:tr>'
    table += '</w:tbl>'
    body = paragraph(data['report_title'], 'Title') + paragraph('候选标题', 'Heading1') + table
    body += paragraph('推荐标题', 'Heading1') + paragraph(recommendation(data)) + paragraph('使用建议', 'Heading1')
    body += ''.join(paragraph(item) for item in advice(data))
    return f'<w:document xmlns:w="{W_NS}"><w:body>{body}<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134"/></w:sectPr></w:body></w:document>'


def write_docx(data: dict, path: Path) -> None:
    with zipfile.ZipFile(path, 'w', zipfile.ZIP_DEFLATED) as z:
        z.writestr('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>')
        z.writestr('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>')
        z.writestr('word/document.xml', render_docx_xml(data))


def write_pdf(page: str, data: dict, path: Path, base_url: Path) -> None:
    try:
        from weasyprint import HTML
    except ImportError:
        # Local verification runtime may only have ReportLab. Keep the same
        # candidate rows and recommendation when rendering the fallback PDF.
        from reportlab.lib import colors
        from reportlab.lib.enums import TA_LEFT
        from reportlab.lib.styles import ParagraphStyle
        from reportlab.lib.pagesizes import A4
        from reportlab.pdfbase import pdfmetrics
        from reportlab.pdfbase.cidfonts import UnicodeCIDFont
        from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle
        pdfmetrics.registerFont(UnicodeCIDFont('STSong-Light'))
        style = ParagraphStyle('Chinese', fontName='STSong-Light', fontSize=10, leading=16, alignment=TA_LEFT)
        heading = ParagraphStyle('ChineseHeading', parent=style, fontSize=15, leading=22, spaceBefore=16)
        story = [Paragraph(html.escape(data['report_title']), heading), Paragraph('候选标题', heading)]
        table_data = [['标题', '适用意图', '建议评分', '理由'], *rows(data)]
        table = Table([[Paragraph(html.escape(str(cell)), style) for cell in row] for row in table_data],
                      colWidths=[175, 90, 70, 170], repeatRows=1)
        table.setStyle(TableStyle([('GRID', (0, 0), (-1, -1), .4, colors.HexColor('#d8d3c6')),
                                   ('BACKGROUND', (0, 0), (-1, 0), colors.HexColor('#f5f4ed')),
                                   ('VALIGN', (0, 0), (-1, -1), 'TOP'),
                                   ('LEFTPADDING', (0, 0), (-1, -1), 5),
                                   ('RIGHTPADDING', (0, 0), (-1, -1), 5)]))
        story += [table, Paragraph('推荐标题', heading), Paragraph(html.escape(recommendation(data)), style),
                  Paragraph('使用建议', heading)]
        story += [Paragraph(html.escape(item), style) for item in advice(data)]
        SimpleDocTemplate(str(path), pagesize=A4, leftMargin=42, rightMargin=42).build(story)
    else:
        HTML(string=page, base_url=str(base_url)).write_pdf(str(path))


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--input', required=True)
    parser.add_argument('--output-dir', required=True)
    args = parser.parse_args()
    data = validate_report_input(json.loads(Path(args.input).read_text(encoding='utf-8')))
    out = Path(args.output_dir)
    out.mkdir(parents=True, exist_ok=True)
    stem = data['output_stem']
    paths = {'markdown': out / f'{stem}.md', 'html': out / f'{stem}.html',
             'docx': out / f'{stem}.docx', 'pdf': out / f'{stem}.pdf'}
    markdown = render_markdown(data)
    page = render_html(data)
    paths['markdown'].write_text(markdown, encoding='utf-8')
    paths['html'].write_text(page, encoding='utf-8')
    write_docx(data, paths['docx'])
    write_pdf(page, data, paths['pdf'], out)
    with zipfile.ZipFile(paths['docx']) as z:
        ET.fromstring(z.read('word/document.xml'))
    if paths['pdf'].read_bytes()[:4] != b'%PDF':
        raise ValueError('PDF 格式无效')
    quality = {'skill_id': 'yao-geo-title-optimizer', 'checked_at': dt.datetime.now().isoformat(timespec='seconds'),
               'passed': True, 'blocking': False, 'issues': [],
               'files': {key: {'name': path.name, 'exists': path.exists(), 'size': path.stat().st_size}
                         for key, path in paths.items()}}
    (out / 'quality-report.json').write_text(json.dumps(quality, ensure_ascii=False, indent=2), encoding='utf-8')
    for key, path in paths.items():
        print(f'Rendered {key}: {path}')


if __name__ == '__main__':
    main()
