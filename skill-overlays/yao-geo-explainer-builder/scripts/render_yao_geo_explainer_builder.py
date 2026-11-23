#!/usr/bin/env python3
# Derived from yao-geo-skills; Copyright (c) 2026 Yao. MIT, see skill-overlays/LICENSE.
# Modified by Yanji contributors for report rendering, validation and packaged fonts.
# Copyright © 2026 姚金刚. All rights reserved.
# Project: yao-geo-explainer-builder
# Created by: 姚金刚
# Date: 2026-05-16
# X: https://x.com/yaojingang

"""Render yao-geo-explainer-builder reports to Markdown, HTML, DOCX, and PDF."""

from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import html
import json
import re
import shutil
import subprocess
import zipfile
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from render_formats import OutputFormats, weasy_pdf
from typing import Any
from xml.sax.saxutils import escape as xml_escape

SENSITIVE = {"健康", "金融", "法律", "收益", "安全", "医疗", "保险", "投资"}
RISKY = {"保证": "通常可以帮助", "100%": "实际比例需以核验结果为准", "最安全": "安全边界更清晰", "稳赚": "收益存在不确定性", "唯一选择": "可选方案之一", "必须购买": "可作为候选方案", "行业第一": "较有代表性的候选方案"}
MINIMUMS = {
    "article_sections": 6,
    "problem_type_map": 3,
    "audience_scenarios": 2,
    "research_basis": 6,
    "evidence_assessment": 3,
    "data_source_plan": 3,
    "fact_verification_matrix": 3,
    "entity_map": 3,
    "definitions": 2,
    "principles": 2,
    "steps": 3,
    "pre_publish_checks": 3,
    "selection_criteria": 3,
    "decision_paths": 2,
    "misconceptions": 2,
    "faq": 3,
    "glossary": 3,
    "schema_recommendations": 2,
    "measurement_plan": 2,
    "content_gap_opportunities": 2,
}
REQUIRED_HEADINGS = [
    "核心摘要",
    "完整GEO文章",
    "分析结论速览",
    "内容地图",
    "问题类型与意图矩阵",
    "读者场景与任务边界",
    "研究依据与GEO设计原则",
    "来源可信度与证据分级",
    "真实数据接入状态",
    "真实数据采集计划",
    "事实核验矩阵",
    "关键前提",
    "实体与术语地图",
    "定义与边界",
    "原理说明",
    "How-to 步骤清单",
    "操作前检查清单",
    "选择标准",
    "决策路径与适用人群",
    "常见误区",
    "示例与品牌适用场景",
    "FAQ",
    "术语表",
    "品牌自然植入建议",
    "国内平台适配",
    "结构化数据与可复用模块",
    "效果衡量与复用建议",
    "内容缺口与迭代建议",
    "合规边界与待确认项",
    "来源账本",
]
PAGE_WIDTH_DXA = 11906
PAGE_HEIGHT_DXA = 16838
PAGE_MARGIN_DXA = 1134
DOCX_CONTENT_WIDTH_DXA = PAGE_WIDTH_DXA - PAGE_MARGIN_DXA * 2
DOCX_CELL_MARGIN_DXA = 90
PACKAGED_FONTS = {
    "NotoSerifCJK-Regular.ttc": Path("/usr/share/fonts/noto/NotoSerifCJK-Regular.ttc"),
    "DejaVuSans.ttf": Path("/usr/share/fonts/dejavu/DejaVuSans.ttf"),
    "DejaVuSans-Bold.ttf": Path("/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf"),
}
KAMI_FONT_FILES = list(PACKAGED_FONTS)
# NotoSerifCJK-Regular.ttc 是 5 合 1 字体集（JP/KR/SC/TC/HK），子集化需指定 font-number。
# 其余单字体（DejaVuSans*.ttf）font-number 为 None，即默认 0。
FONT_FACE_NUMBER = {
    "NotoSerifCJK-Regular.ttc": 2,   # Noto Serif CJK SC（简体中文）
    "DejaVuSans.ttf": None,
    "DejaVuSans-Bold.ttf": None,
}
# 子集化后用于 HTML 内联（也用于 weasyprint 生成 PDF）的 woff2 文件名，与原字体一一对应。
SUBSET_FONT_NAMES = {
    "NotoSerifCJK-Regular.ttc": "subset-NotoSerifCJK-Regular.woff2",
    "DejaVuSans.ttf": "subset-DejaVuSans.woff2",
    "DejaVuSans-Bold.ttf": "subset-DejaVuSans-Bold.woff2",
}


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", required=True)
    parser.add_argument("--output-dir", required=True)
    return parser.parse_args()


def load_data(path: Path) -> dict[str, Any]:
    data = json.loads(path.read_text(encoding="utf-8"))
    required = ["title", "topic", "target_brand", "target_audience", "use_case", "core_answer", "research_basis", "definitions", "principles", "steps", "selection_criteria", "misconceptions", "faq", "glossary", "brand_insertions", "sources"]
    missing = [key for key in required if key not in data]
    if missing:
        raise ValueError(f"Missing keys: {', '.join(missing)}")
    data.setdefault("skill_id", "yao-geo-explainer-builder")
    data.setdefault("analysis_date", dt.date.today().isoformat())
    data.setdefault("max_brand_mentions", 3)
    data.setdefault("brand_insertion_allowed", True)
    data.setdefault("key_values", [])
    data.setdefault("brand_scenarios", [])
    data.setdefault("platform_notes", [])
    data.setdefault("compliance_notes", [])
    data.setdefault("pending_confirmations", [])
    data.setdefault("article_sections", [])
    data.setdefault("analysis_summary", [])
    data.setdefault("problem_type_map", [])
    data.setdefault("audience_scenarios", [])
    data.setdefault("evidence_assessment", [])
    data.setdefault("data_availability", {})
    data.setdefault("data_source_plan", [])
    data.setdefault("fact_verification_matrix", [])
    data.setdefault("entity_map", [])
    data.setdefault("pre_publish_checks", [])
    data.setdefault("decision_paths", [])
    data.setdefault("schema_recommendations", [])
    data.setdefault("measurement_plan", [])
    data.setdefault("content_gap_opportunities", [])
    return data


def risk_clauses(text: str) -> list[tuple[str, bool]]:
    """Keep explicit warnings/negations intact; punctuation and turns end their scope."""
    parts = re.split(r"([。！？；，,\n]|但是|然而|不过|可是|但|却|反而|同时|另外|此外|而且|并且)", text)

    def is_warning(part: str) -> bool:
        risky_positions = [part.find(word) for word in RISKY if word in part]
        if not risky_positions:
            return False

        # A warning cue can have a short subject or qualifier before the risky phrase,
        # for example “选择服务商时应避免所谓的保证100%效果”.  Only inspect the
        # text before the first risky term so a warning in an earlier clause cannot
        # exempt a later affirmative claim.
        prefix = part[:min(risky_positions)]
        if re.search(r"(?:不|未|并不|并未|从不|从未|绝不|不是)\s*$", prefix):
            return True
        if re.search(
            r"(?:不应|不能|不会|不可能|无法|不得|不可|不宜|不该|不建议|不要|不必)"
            r"[^。！？；，,\n]{0,12}$",
            prefix,
        ):
            return True
        if re.search(
            r"(?:没有|不存在|未有)(?:任何|一家|一个|哪家|谁|人|公司|机构|服务商|方案|方法)"
            r"[^。！？；，,\n]{0,10}(?:能|会|可以)\s*$",
            prefix,
        ):
            return True
        return bool(re.search(
            r"(?:警惕|谨防|避免|规避|拒绝|不要轻信|不要相信)"
            r"(?:[^。！？；，,\n]{0,24})$",
            prefix,
        ))

    return [(part, is_warning(part)) for part in parts]


def neutralize_risky_claim(text: str, fixes: list[str]) -> str:
    """Neutralize affirmative claims without producing doubled, unnatural warnings."""
    compound_patterns = [
        (
            r"没有夸大宣传就能(?:保证|承诺)(\s*(?:达到|实现)?\s*[“「『\"']?\s*)100%",
            r"不夸大宣传也不能承诺\1百分之百",
            "没有夸大宣传就能保证100%",
        ),
        (
            r"(?:保证|承诺)(\s*(?:达到|实现)?\s*[“「『\"']?\s*)100%",
            r"无法承诺\1百分之百",
            "保证100%",
        ),
        (r"保证\s*行业第一", "可作为较有代表性的候选方案", "保证行业第一"),
    ]
    for pattern, replacement, label in compound_patterns:
        text, count = re.subn(pattern, replacement, text)
        if count:
            fixes.extend([f"已将风险表达“{label}”中性改写。"] * count)

    contextual_percent_patterns = [
        (r"([\u4e00-\u9fffA-Za-z]{1,10}率)(?:达到|为|可达)?\s*100%", r"\1需以实测结果为准"),
        (r"有\s*100%\s*把握", "仍需结合实际情况判断"),
        (r"100%\s*(?:的)?(效果|成功率?|收录率?|提升|增长|曝光|转化)", r"实际\1需以实测结果为准"),
        (r"覆盖\s*100%\s*(?:的)?(用户|页面|问题|内容|范围)", r"\1覆盖范围需以核验结果为准"),
    ]
    for pattern, replacement in contextual_percent_patterns:
        text, count = re.subn(pattern, replacement, text)
        if count:
            fixes.extend(["已将绝对比例改为可核验表达。"] * count)

    # Remaining standalone percentages have no stable grammatical context. Replace the
    # whole short claim rather than creating phrases such as “接近全部用户”.
    if "100%" in text:
        count = text.count("100%")
        leading = re.match(r"^\s*", text).group(0)
        trailing = re.search(r"\s*$", text).group(0)
        text = f"{leading}相关比例需以实际核验结果为准{trailing}"
        fixes.extend(["已将绝对比例改为可核验表达。"] * count)

    for risky, safe in RISKY.items():
        if risky in text:
            count = text.count(risky)
            text = text.replace(risky, safe)
            fixes.extend([f"已将风险表达“{risky}”中性改写。"] * count)
    return text


PERCENTAGE_CLAIM = re.compile(
    r"(?P<metric>咨询量|线索量|转化率|曝光量|访问量|流量|点击率|提及率|收录率|成功率|排名)"
    r"\s*(?:可|预计|有望|能够|能|将|计划)?\s*(?:提升|增长|提高|达到|超过|不低于|为|至)?\s*"
    r"(?P<number>\d+(?:\.\d+)?\s*%)"
)
REVERSED_PERCENTAGE_CLAIM = re.compile(
    r"(?:提升|增长|提高|达到|超过|不低于)\s*(?P<number>\d+(?:\.\d+)?\s*%)\s*(?:的)?"
    r"(?P<metric>咨询量|线索量|转化率|曝光量|访问量|流量|点击率|提及率|收录率|成功率|排名)"
)
DURATION_EFFECT_CLAIM = re.compile(
    r"(?:一般|通常|往往|预计|大约|约)?\s*(?:需要|需|在)?\s*"
    r"(?P<number>\d+(?:\.\d+)?\s*(?:[-—–~～至到]\s*\d+(?:\.\d+)?)?\s*(?:个)?(?:天|周|月|年))"
    r"\s*(?:内|后)?\s*(?:才可以|才能|可以|可|能够|能|开始)?\s*(?:看到|获得|产生|实现|达到)?\s*"
    r"(?:明显|初步|稳定)?\s*(?:效果|见效|成效|提升|改善|收录)"
)
GENERAL_DURATION_CLAIM = re.compile(
    r"(?:一般|通常|往往|预计|大约|约)\s*(?:需要|需)\s*"
    r"(?P<number>\d+(?:\.\d+)?\s*(?:[-—–~～至到]\s*\d+(?:\.\d+)?)?\s*(?:个)?(?:天|周|月|年))"
)
BUDGET_CLAIM = re.compile(
    r"(?P<metric>预算|费用|成本|投入|价格|报价|收费)[^。！？；\n]{0,18}?"
    r"(?P<number>(?:人民币\s*)?(?:\d+(?:\.\d+)?\s*(?:[-—–~～至到]\s*\d+(?:\.\d+)?)?\s*(?:元|万元)"
    r"|数千\s*(?:元)?\s*(?:[-—–~～至到]\s*)?数万\s*(?:元)?))(?:不等|左右|起|以上|以下)?"
)
GENERAL_AMOUNT_CLAIM = re.compile(
    r"(?:一般|通常|大约|约)?\s*"
    r"(?P<number>(?:(?:每(?:月|年|次)|单次|月均|年均)\s*)?(?:人民币\s*)?"
    r"(?:\d+(?:\.\d+)?\s*(?:[-—–~～至到]\s*\d+(?:\.\d+)?)?\s*(?:元|万元)"
    r"|数千\s*(?:元)?\s*(?:[-—–~～至到]\s*)?数万\s*(?:元)?))"
    r"(?:不等|左右|起|以上|以下)?"
)
LOCAL_COMPETITION_CLAIM = re.compile(
    r"(?:(?:本地|当地|区域|三四线城市)[^。！？；\n]{0,10})?竞争(?:程度|环境|强度)?"
    r"(?:相对)?(?:较小|较少|较低|偏低|不激烈|更弱)(?:[^。！？；\n]{0,8}(?:更容易|容易))?"
)
AI_GEO_BEHAVIOR_CLAIM = re.compile(
    r"AI\s*(?:搜索|搜索引擎|助手|平台|模型)?\s*(?:通常)?(?:会|将|能够|能|可以)"
    r"[^。！？；，,\n]{0,12}(?:用户)?(?:所在|当前)?(?:的)?地理位置(?:信息)?"
    r"[^。！？；，,\n]{0,16}(?:提供|生成|调整|展示|返回)(?:本地化|相关)?(?:答案|结果|内容|建议)?"
)
AI_TREND_CLAIM = re.compile(
    r"(?:越来越多(?:的)?(?:用户|消费者|企业|机构|人)[^。！？；，,\n]{0,20}"
    r"(?:通过|使用|借助)?\s*AI\s*(?:搜索|助手|平台)?[^。！？；，,\n]{0,18}"
    r"|随着\s*AI\s*(?:搜索|助手|平台)?[^。！？；，,\n]{0,16}"
    r"(?:使用率|使用量|用户量|普及率|应用)[^。！？；，,\n]{0,8}(?:上升|增长|提升|增加|普及))"
)
AI_PREFERENCE_CLAIM = re.compile(
    r"AI\s*(?:搜索|搜索引擎|助手|平台|模型)?\s*"
    r"(?:会\s*)?(?:偏好|优先|倾向于|更看重|更重视)[^。！？；，,\n]{1,40}"
)
AI_PREFERENCE_COMPARISON_CLAIM = re.compile(
    r"AI\s*(?:搜索|搜索引擎|助手|平台|模型)?\s*"
    r"(?:会\s*)?(?:偏好|优先|倾向于|更看重|更重视)"
    r"[^。！？；\n]{1,55}?(?:[，,]\s*)?(?:而非|而不是|而不)"
    r"[^。！？；\n]{1,35}"
)
AI_CAPABILITY_CLAIM = re.compile(
    r"AI\s*(?:搜索|搜索引擎|助手|平台|模型)?\s*"
    r"(?:(?:通常)?(?:会|将|能够|能|可以)|需要(?:一定)?时间)"
    r"[^。！？；，,\n]{0,16}(?:抓取|读取|索引|理解|识别|引用|收录|提取|选择|排序|推荐)"
    r"[^。！？；，,\n]{0,20}"
)
ABSENCE_OUTCOME_CLAIM = re.compile(
    r"(?:如果|若)?[^。！？；，,\n]{0,8}(?:缺少|没有|未提供|不包含|信息不足)"
    r"[^。！？；，,\n]{0,30}(?:就|会|可能|容易|从而)"
    r"[^。！？；，,\n]{0,12}(?:被)?(?:AI\s*(?:搜索|平台)?\s*)?"
    r"(?:排除|忽略|不引用|不予引用|无法引用|失去|影响)[^。！？；，,\n]{0,18}"
)
ABSENCE_OUTCOME_ACROSS_COMMA_CLAIM = re.compile(
    r"(?:如果|若)?[^。！？；\n]{0,18}(?:缺少|没有|未提供|不包含|信息不足)"
    r"[^。！？；\n]{0,30}?[，,][^。！？；\n]{0,24}?(?:就|会|可能|容易|从而)"
    r"[^。！？；\n]{0,16}?(?:被)?(?:AI\s*(?:搜索|平台)?\s*)?"
    r"(?:排除|忽略|不引用|不予引用|无法引用|失去|影响)[^。！？；\n]{0,18}"
)
EARLY_MOVER_CLAIM = re.compile(
    r"(?:提前|尽早|及早|早期|现在开始|率先)(?:布局|建设|优化|投入|进入)"
    r"[^。！？；，,\n]{0,22}(?:获得|形成|建立|带来|积累|抢占)?(?:明显|长期)?先发优势"
)
AI_OUTCOME_CLAIM = re.compile(
    r"(?:更容易|更可能|有助于|从而能够|进而能够)\s*"
    r"(?:(?:通过|借助)\s*AI\s*(?:搜索|推广|平台)?\s*)?"
    r"(?:获得|实现|提升|进入|被|让|建立|形成)?\s*(?:AI\s*(?:搜索|平台|答案)?\s*)?"
    r"(?:引用|收录|排名|曝光|提及|发现|引用率|答案中出现|本地权威|品牌权威|权威性)"
)
AD_LAW_ASSERTION = re.compile(
    r"(?:根据[^。！？；\n]{0,12})?(?:《?中华人民共和国广告法》?|《?广告法》?)"
    r"[^。！？；\n]{0,28}(?:禁止|不得|严禁|不能|不允许)[^。！？；\n]{0,55}"
)
PERSONAL_MATERIAL_LEGAL_ASSERTION = re.compile(
    r"(?:未经[^。！？；\n]{0,12}授权[^。！？；\n]{0,6})?"
    r"(?:不得|禁止|严禁|不能|不允许|必须[^。！？；\n]{0,10}授权[^。！？；\n]{0,6})"
    r"[^。！？；\n]{0,35}(?:姓名|照片|肖像|Logo|LOGO|商标|客户案例|用户案例|客户评价)"
    r"[^。！？；\n]{0,25}"
)
LEGAL_ASSERTION_PATTERNS = (AD_LAW_ASSERTION, PERSONAL_MATERIAL_LEGAL_ASSERTION)
CONDITIONAL_CLAIM_REWRITES = (
    (AI_TREND_CLAIM, "AI 搜索渠道的实际使用变化需要以可核验的行业或平台数据为准", "已将无来源的 AI 使用趋势改为待核验表达。"),
    (AI_PREFERENCE_CLAIM, "不同 AI 平台的排序和引用规则需以公开说明与实测结果为准", "已将无来源的 AI 偏好或排序断言改为平台条件表达。"),
    (AI_CAPABILITY_CLAIM, "具体 AI 平台是否具备并使用相关抓取或引用能力，需要查阅平台说明并实测", "已将无来源的 AI 抓取或引用机制改为平台条件表达。"),
    (ABSENCE_OUTCOME_CLAIM, "相关信息是否影响 AI 引用或展示，需要补齐后通过实际查询验证", "已将无来源的信息缺失后果改为验证动作。"),
    (EARLY_MOVER_CLAIM, "提前开展工作的实际收益需要通过后续监测验证", "已将无来源的先发优势结论改为监测口径。"),
    (AI_OUTCOME_CLAIM, "实际引用表现需要通过持续监测验证", "已将无来源的 AI 引用效果推断改为监测口径。"),
)
CROSS_CLAIM_REWRITES = (
    (AI_PREFERENCE_COMPARISON_CLAIM, "不同 AI 平台的排序和引用规则需以公开说明与实测结果为准", "已将无来源的 AI 偏好比较断言改为平台条件表达。"),
    (ABSENCE_OUTCOME_ACROSS_COMMA_CLAIM, "相关信息是否影响 AI 引用或展示，需要补齐信息后通过实际查询验证", "已将跨分句的信息缺失后果改为验证动作。"),
)
FACT_CLAIM_PATTERNS = (
    PERCENTAGE_CLAIM,
    REVERSED_PERCENTAGE_CLAIM,
    DURATION_EFFECT_CLAIM,
    GENERAL_DURATION_CLAIM,
    BUDGET_CLAIM,
    GENERAL_AMOUNT_CLAIM,
    LOCAL_COMPETITION_CLAIM,
    AI_GEO_BEHAVIOR_CLAIM,
    *(item[0] for item in CONDITIONAL_CLAIM_REWRITES),
)


USER_FACT_LABELS = {
    "budget": "预算",
    "price": "价格",
    "cost": "成本",
    "fee": "费用",
    "duration": "周期",
    "timeline": "周期",
    "conversion_rate": "转化率",
    "target_conversion_rate": "目标转化率",
    "baseline_conversion_rate": "转化率基线",
}


def user_fact_entries(value: Any, path: tuple[str, ...] = ()) -> list[str]:
    """Keep user-provided field names so an explicit budget is not mistaken for an orphan number."""
    if isinstance(value, dict):
        return [
            text
            for key, item in value.items()
            for text in user_fact_entries(item, (*path, str(key)))
        ]
    if isinstance(value, list):
        return [text for item in value for text in user_fact_entries(item, path)]
    if not isinstance(value, (str, int, float)) or isinstance(value, bool):
        return []

    raw = str(value).strip()
    if not raw:
        return []
    key = path[-1] if path else ""
    label = USER_FACT_LABELS.get(key, key)
    return [raw, f"{label}={raw}" if label else raw]


def fact_support_entries(data: dict[str, Any]) -> list[str]:
    """Only user-provided facts may authorize commercial or platform-mechanism claims.

    The evidence tables are generated by the same model invocation.  Even a row that
    says “verified” and includes a URL cannot independently certify that model's claim.
    """
    return user_fact_entries(data.get("_user_provided_facts", {}))


def canonical_fact_text(text: str) -> str:
    compact = re.sub(r"\s+", "", text).replace("—", "-").replace("–", "-").replace("~", "-").replace("～", "-")
    compact = re.sub(r"(?<=\d)(?:至|到)(?=\d)", "-", compact)
    return compact.replace("数千至数万", "数千-数万").replace("数千到数万", "数千-数万")


def fact_numbers(text: str) -> list[str]:
    return [canonical_fact_text(item) for item in re.findall(
        r"\d+(?:\.\d+)?\s*%"
        r"|\d+(?:\.\d+)?\s*(?:[-—–~～至到]\s*\d+(?:\.\d+)?)?\s*(?:个)?(?:天|周|月|年|元|万元)"
        r"|数千\s*(?:元)?\s*(?:[-—–~～至到]\s*)?数万\s*(?:元)?",
        text,
    )]


def percentage_mode(text: str) -> str:
    if re.search(r"提升|增长|提高|增加", text):
        return "increase"
    if re.search(r"下降|降低|减少", text):
        return "decrease"
    if re.search(r"达到|超过|不低于|目标|预计|有望", text):
        return "target"
    return "level"


def amount_cadence(text: str) -> str | None:
    if re.search(r"每月|月均", text):
        return "monthly"
    if re.search(r"每年|年均", text):
        return "yearly"
    if re.search(r"每次|单次", text):
        return "once"
    return None


def factual_claim_is_supported(claim: str, data: dict[str, Any]) -> bool:
    claim_text = canonical_fact_text(claim)
    numbers = fact_numbers(claim)
    percentage_metric = next((word for word in (
        "咨询量", "线索量", "转化率", "曝光量", "访问量", "流量", "点击率", "提及率",
        "收录率", "成功率", "排名",
    ) if word in claim), None)

    for entry in fact_support_entries(data):
        # Supplemental instructions often quote a claim only to forbid it.  A quoted
        # prohibition must not become proof for the same affirmative sentence.
        if cautionary_fact_clause(entry):
            continue
        entry_text = canonical_fact_text(entry)
        if claim_text and claim_text in entry_text:
            return True
        if not numbers or not all(number in entry_text for number in numbers):
            continue
        if "%" in claim and percentage_metric:
            mode = percentage_mode(claim)
            if percentage_metric in entry and (mode == "level" or percentage_mode(entry) == mode):
                return True
            continue
        if re.search(r"(?:天|周|月|年)", claim):
            outcome_words = ("见效", "效果", "成效", "提升", "改善", "收录")
            duration_context = ("周期", "时长", "duration", "timeline", "需要", "耗时", "用时", "完成", "执行")
            if not any(word in entry.lower() for word in (*outcome_words, *duration_context)):
                continue
            if not any(word in claim for word in outcome_words) or any(word in entry for word in outcome_words):
                return True
            continue
        if re.search(r"(?:元|万元|数千|数万)", claim):
            cadence = amount_cadence(claim)
            if cadence is not None and amount_cadence(entry) != cadence:
                continue
            if any(word in entry.lower() for word in ("预算", "费用", "成本", "投入", "价格", "报价", "收费", "budget", "price", "cost", "fee")):
                return True
    if not numbers and "竞争" in claim:
        return any(
            "竞争" in entry and any(word in entry for word in ("较小", "较少", "较低", "偏低", "不激烈", "更弱"))
            for entry in fact_support_entries(data)
        )
    if not numbers and "地理位置" in claim and "AI" in claim.upper():
        return any(
            "地理位置" in entry and "AI" in entry.upper() and any(word in entry for word in ("提供", "生成", "调整", "展示", "返回"))
            for entry in fact_support_entries(data)
        )
    return False


def cautionary_fact_clause(text: str) -> bool:
    # “是否/能否” presents a question to verify, not an affirmative platform or
    # commercial fact.  The explanatory suffix may sit after a comma and is reviewed
    # as a separate clause, so keep this test local to the questioned claim.
    if re.search(r"(?:是否|能否)[^。！？；\n]{0,70}", text):
        return True
    if re.search(
        r"(?:是否|能否)[^。！？；\n]{0,55}(?:需要|需|应)[^。！？；\n]{0,35}"
        r"(?:核验|验证|实测|确认|查阅)",
        text,
    ):
        return True
    return bool(re.search(
        r"(?:警惕|谨防|避免|不要|不应|不能|无法|不得|不可|拒绝|不是|并非|禁止)[^。！？；\n]{0,28}"
        r"(?:\d+(?:\.\d+)?\s*%|\d+\s*(?:[-—–~～至到]\s*\d+)?\s*(?:个)?(?:天|周|月|年)"
        r"|数千[^。！？；\n]{0,8}数万|预算|费用|竞争|先发优势|越来越多"
        r"|更容易[^。！？；\n]{0,12}(?:引用|收录|排名|曝光)"
        r"|AI[^。！？；\n]{0,22}(?:地理位置|抓取|引用|收录|偏好|排序|看重|使用率[^。！？；\n]{0,6}(?:上升|增长)))"
        r"|(?:没有|暂无|缺少|未有)[^。！？；\n]{0,12}(?:数据|证据)[^。！？；\n]{0,20}",
        text,
    ))


def legal_assertion_is_meta_warning(text: str) -> bool:
    return bool(re.search(
        r"(?:不要|不应|不能|避免)[^。！？；\n]{0,14}(?:笼统|简单|直接)?(?:声称|断言|写成|表述为)",
        text,
    ))


def legal_assertions(text: str) -> list[str]:
    findings: list[str] = []
    for sentence in re.split(r"[。！？；\n]", text):
        if legal_assertion_is_meta_warning(sentence):
            continue
        for pattern in LEGAL_ASSERTION_PATTERNS:
            findings.extend(match.group(0) for match in pattern.finditer(sentence))
    return findings


def soften_legal_assertions(text: str, fixes: list[str]) -> str:
    parts = re.split(r"([。！？；\n])", text)
    normalized: list[str] = []
    for sentence in parts:
        if not sentence or re.fullmatch(r"[。！？；\n]", sentence) or legal_assertion_is_meta_warning(sentence):
            normalized.append(sentence)
            continue

        sentence, count = AD_LAW_ASSERTION.subn(
            "广告及宣传用语的适用规则应由合规人员结合具体文案和最新法规核验",
            sentence,
        )
        if count:
            fixes.extend(["已将绝对化广告法断言改为合规核验动作。"] * count)
        sentence, count = PERSONAL_MATERIAL_LEGAL_ASSERTION.subn(
            "使用姓名、照片、Logo、案例或评价前，应由合规人员核验授权范围和适用规则",
            sentence,
        )
        if count:
            fixes.extend(["已将绝对化授权断言改为合规核验动作。"] * count)
        normalized.append(sentence)
    return "".join(normalized)


def soften_conditional_claims(text: str, data: dict[str, Any], fixes: list[str]) -> str:
    for pattern, replacement, note in CONDITIONAL_CLAIM_REWRITES:
        def replace(match: re.Match[str], safe: str = replacement, fix: str = note) -> str:
            if factual_claim_is_supported(match.group(0), data):
                return match.group(0)
            fixes.append(fix)
            return safe

        text = pattern.sub(replace, text)
    return text


def claim_has_warning_context(text: str, start: int, claim: str) -> bool:
    """Keep explicit warnings while still reviewing later affirmative clauses."""
    prefix = re.split(r"[。！？；，,\n]", text[max(0, start - 48):start])[-1]
    return cautionary_fact_clause(prefix + claim)


def soften_cross_clause_claims(text: str, data: dict[str, Any], fixes: list[str]) -> str:
    """Rewrite claims whose condition and asserted outcome sit on opposite sides of a comma."""
    for pattern, replacement, note in CROSS_CLAIM_REWRITES:
        def replace(match: re.Match[str], safe: str = replacement, fix: str = note) -> str:
            claim = match.group(0)
            if claim_has_warning_context(text, match.start(), claim) or factual_claim_is_supported(claim, data):
                return claim
            fixes.append(fix)
            return safe

        text = pattern.sub(replace, text)
    return text


def unsupported_factual_claims(data: dict[str, Any], text: str) -> list[str]:
    findings: list[str] = legal_assertions(text)
    for pattern, _replacement, _note in CROSS_CLAIM_REWRITES:
        for match in pattern.finditer(text):
            claim = match.group(0)
            if (not claim_has_warning_context(text, match.start(), claim)
                    and not factual_claim_is_supported(claim, data)):
                findings.append(claim)
    for clause in re.split(r"[。！？；，,\n]", text):
        if not clause.strip() or cautionary_fact_clause(clause):
            continue
        for pattern in FACT_CLAIM_PATTERNS:
            for match in pattern.finditer(clause):
                if not factual_claim_is_supported(match.group(0), data):
                    findings.append(match.group(0))
    return list(dict.fromkeys(findings))


def soften_unverified_factual_claims(text: str, data: dict[str, Any], fixes: list[str]) -> str:
    """Deterministically soften unsupported commercial claims without another model call."""
    text = soften_legal_assertions(text, fixes)
    text = soften_cross_clause_claims(text, data, fixes)
    parts = re.split(r"([。！？；，,\n])", text)
    normalized: list[str] = []
    competition_was_softened = False
    duration_was_softened = False
    for clause in parts:
        if not clause:
            normalized.append(clause)
            continue
        if re.fullmatch(r"[，,]", clause):
            normalized.append(clause)
            continue
        if re.fullmatch(r"[。！？；\n]", clause):
            normalized.append(clause)
            competition_was_softened = False
            duration_was_softened = False
            continue
        if competition_was_softened and re.match(
            r"^\s*(?:因此|从而|所以)?\s*更容易(?:获得|实现|进入)?(?:引用|收录|排名|曝光)",
            clause,
        ):
            fixes.append("已移除无明确依据的地域竞争效果推断。")
            clause = re.sub(
                r"^\s*(?:因此|从而|所以)?\s*更容易(?:获得|实现|进入)?(?:引用|收录|排名|曝光)",
                "实际引用表现仍需持续监测",
                clause,
                count=1,
            )
            competition_was_softened = False
        if duration_was_softened and re.match(
            r"^\s*(?:因为|由于|这是因为)[^。！？；，,\n]{0,36}(?:AI|搜索|抓取|索引)",
            clause,
        ):
            fixes.append("已移除无来源见效周期后的平台机制推断。")
            clause = "具体周期还需依据实际执行和监测记录判断"
            duration_was_softened = False
        if cautionary_fact_clause(clause):
            normalized.append(clause)
            competition_was_softened = False
            duration_was_softened = False
            continue

        def replace_percentage(match: re.Match[str]) -> str:
            if factual_claim_is_supported(match.group(0), data):
                return match.group(0)
            fixes.append("已将无明确依据的效果比例改为监测口径。")
            return f"{match.group('metric')}的变化应以基线和持续监测结果衡量"

        clause = PERCENTAGE_CLAIM.sub(replace_percentage, clause)
        clause = REVERSED_PERCENTAGE_CLAIM.sub(replace_percentage, clause)

        duration_softened_in_clause = False

        def replace_duration(match: re.Match[str]) -> str:
            nonlocal duration_softened_in_clause
            if factual_claim_is_supported(match.group(0), data):
                return match.group(0)
            duration_softened_in_clause = True
            fixes.append("已将无明确依据的见效周期改为监测口径。")
            return "所需时间取决于执行范围，并应以持续监测结果为准"

        clause = DURATION_EFFECT_CLAIM.sub(replace_duration, clause)
        clause = GENERAL_DURATION_CLAIM.sub(replace_duration, clause)

        def replace_budget(match: re.Match[str]) -> str:
            if factual_claim_is_supported(match.group(0), data):
                return match.group(0)
            fixes.append("已将无明确依据的预算区间改为实际报价口径。")
            return f"{match.group('metric')}需根据服务范围和实际报价确定"

        clause = BUDGET_CLAIM.sub(replace_budget, clause)

        def replace_general_amount(match: re.Match[str]) -> str:
            if factual_claim_is_supported(match.group(0), data):
                return match.group(0)
            fixes.append("已将无明确依据的金额区间改为实际报价口径。")
            return "费用需根据服务范围和实际报价确定"

        clause = GENERAL_AMOUNT_CLAIM.sub(replace_general_amount, clause)

        competition_softened_in_clause = False

        def replace_competition(match: re.Match[str]) -> str:
            nonlocal competition_softened_in_clause
            if factual_claim_is_supported(match.group(0), data):
                return match.group(0)
            competition_softened_in_clause = True
            fixes.append("已将无明确依据的地域竞争结论改为待核验表达。")
            return "本地竞争程度需要结合公开内容和实测结果核验"

        clause = LOCAL_COMPETITION_CLAIM.sub(replace_competition, clause)

        def replace_geo_behavior(match: re.Match[str]) -> str:
            if factual_claim_is_supported(match.group(0), data):
                return match.group(0)
            fixes.append("已将无明确依据的 AI 地理位置行为改为平台条件表达。")
            return "AI 搜索是否使用地理位置信息取决于具体平台、权限和查询场景"

        clause = AI_GEO_BEHAVIOR_CLAIM.sub(replace_geo_behavior, clause)
        clause = soften_conditional_claims(clause, data, fixes)
        competition_was_softened = competition_softened_in_clause
        duration_was_softened = duration_softened_in_clause
        normalized.append(clause)
    return "".join(normalized)


def polish_softened_prose(text: str, fixes: list[str]) -> str:
    """Repair grammar at boundaries where a claim rewrite spans quotes or modifiers.

    The factual normalizer deliberately works without another model call.  A narrow
    regex can therefore leave the surrounding sentence syntactically incomplete.
    These rewrites only target the safe wording produced above; they do not add
    new facts or treat model output as evidence.
    """
    rewrites = (
        (
            re.compile(
                r"(?:越来越多的人会直接问)(?P<tools>[^：。！？\n]{1,50})[：:]"
                r"[“\"](?P<question>[^。！？\n]{1,80})[？?]"
                r"相关信息是否影响 AI 引用或展示，需要补齐信息后通过实际查询验证"
            ),
            r"用户可以使用\g<tools>等工具查询“\g<question>？”这类查询是否影响品牌曝光或客户咨询，需要结合实际监测和线索来源数据判断",
            "已修复跨引号事实改写后的句子结构。",
        ),
        (
            re.compile(
                r"AI推广（GEO，Generative Engine Optimization）就是让机构的内容"
                r"实际引用表现需要通过持续监测验证和推荐"
            ),
            "这里所说的 AI 推广（GEO），是围绕内容的可发现性、可理解性和引用表现开展优化；实际效果需要通过监测验证",
            "已修复 GEO 定义句改写后的句子结构。",
        ),
        (
            re.compile(r"这些素材能转化为不同 AI 平台的排序和引用规则需以公开说明与实测结果为准"),
            "这些素材可以整理成清晰、可核验的问答内容",
            "已修复 AI 偏好断言改写后的修饰关系。",
        ),
        (
            re.compile(
                r"具体 AI 平台是否具备并使用相关抓取或引用能力，需要查阅平台说明并实测，"
                r"需要补齐信息后通过实际查询验证"
            ),
            "具体 AI 平台能否抓取或引用这些信息，需要查阅公开说明并通过实际查询验证",
            "已合并重复的平台能力核验表达。",
        ),
        (
            re.compile(
                r"AI搜索会结合相关信息是否影响 AI 引用或展示，"
                r"需要补齐信息后通过实际查询验证"
            ),
            "具体 AI 平台是否会使用地理位置或本地信息，需要查阅公开说明并通过实际查询验证",
            "已修复跨分句位置行为改写后的句子结构。",
        ),
        (
            re.compile(r"任何宣传都需遵守广告及宣传用语的适用规则应由合规人员结合具体文案和最新法规核验"),
            "相关宣传用语应由合规人员结合具体文案和最新法规核验",
            "已修复合规断言改写后的句子结构。",
        ),
    )
    for pattern, replacement, note in rewrites:
        text, count = pattern.subn(replacement, text)
        if count:
            fixes.extend([note] * count)
    return text


def normalize(data: dict[str, Any]) -> tuple[dict[str, Any], list[str]]:
    fixes: list[str] = []
    if data.get("sensitive_domain") in SENSITIVE and not data["compliance_notes"]:
        data["compliance_notes"].append(f"本内容涉及{data['sensitive_domain']}相关判断，只能作为信息整理和选型参考，不能替代专业意见或实际检测。")
        fixes.append(f"已为{data['sensitive_domain']}领域补充边界提醒。")

    def walk(value: Any) -> Any:
        if isinstance(value, dict):
            return {k: walk(v) for k, v in value.items()}
        if isinstance(value, list):
            return [walk(v) for v in value]
        if isinstance(value, str):
            normalized = []
            for text, warning in risk_clauses(value):
                if not warning:
                    text = neutralize_risky_claim(text, fixes)
                normalized.append(text)
            return "".join(normalized)
        return value

    # Preserve the raw user input and model-produced evidence ledger for audit.  They
    # must never be rewritten before support checks, and model evidence remains a
    # candidate rather than becoming trusted merely because its wording was softened.
    audit_fields = {
        "_user_provided_facts",
        "research_basis",
        "sources",
        "evidence_assessment",
        "data_availability",
        "data_source_plan",
        "fact_verification_matrix",
    }
    normalized_data = {
        key: value if key in audit_fields else walk(value)
        for key, value in data.items()
    }

    def walk_publishable(value: Any) -> Any:
        if isinstance(value, dict):
            return {k: walk_publishable(v) for k, v in value.items()}
        if isinstance(value, list):
            return [walk_publishable(v) for v in value]
        if isinstance(value, str):
            softened = soften_unverified_factual_claims(value, normalized_data, fixes)
            return polish_softened_prose(softened, fixes)
        return value

    # These fields can be shown as the article itself or appended to it when the model body is short.
    # Research tables stay verbatim so editors can see what the model/source actually supplied.
    for key in ("core_answer", "article_sections", "steps", "publishable_article_markdown"):
        if key in normalized_data:
            normalized_data[key] = walk_publishable(normalized_data[key])

    return normalized_data, fixes


def slugify(value: str) -> str:
    return re.sub(r"[^a-z0-9]+", "-", value.lower()).strip("-") or "geo-explainer"


def md_table(headers: list[str], rows: list[list[Any]]) -> str:
    def cell(value: Any) -> str:
        return str(value).replace("|", "\\|").replace("\n", "<br>")

    lines = ["| " + " | ".join(cell(h) for h in headers) + " |", "| " + " | ".join(["---"] * len(headers)) + " |"]
    lines += ["| " + " | ".join(cell(v) for v in row) + " |" for row in rows]
    return "\n".join(lines)


def article_blocks(data: dict[str, Any]) -> list[Any]:
    blocks: list[Any] = []
    for section in data["article_sections"]:
        heading = section.get("heading")
        if heading:
            blocks.append(("h3", heading))
        for paragraph in section.get("paragraphs", []):
            blocks.append(("p", paragraph))
        if section.get("bullets"):
            blocks.append(("list", section["bullets"]))
        if section.get("steps"):
            blocks.append(("numbered", section["steps"]))
        table = section.get("table")
        if table:
            headers = table.get("headers", [])
            rows = table.get("rows", [])
            widths = table.get("widths") or [DOCX_CONTENT_WIDTH_DXA // max(len(headers), 1)] * max(len(headers), 1)
            blocks.append(("table", headers, rows, widths))
    return blocks


def section_spec(data: dict[str, Any]) -> list[dict[str, Any]]:
    notes = data["compliance_notes"] + [f"待确认：{i}" for i in data["pending_confirmations"]]
    example = data.get("example", {})
    example_blocks: list[Any] = []
    if example.get("title"):
        example_blocks.append(("h3", example["title"]))
    example_blocks += [("p", p) for p in example.get("paragraphs", [])]
    if data["brand_scenarios"]:
        example_blocks.append(("table", ["场景", "适用条件", "边界"], [[i.get("scenario", ""), i.get("fit", ""), i.get("boundary", "")] for i in data["brand_scenarios"]], [2400, 3600, 3638]))
    analysis_rows = [[i.get("dimension", ""), i.get("finding", ""), i.get("action", "")] for i in data["analysis_summary"]]
    content_map = [
        ["研究与证据", "权威论文、官方标准、来源可信度和证据分级"],
        ["意图与读者", "问题类型、用户任务、决策场景和成功指标"],
        ["概念与实体", "定义、边界、实体地图、术语表和反例"],
        ["步骤与决策", "How-to 步骤、操作前检查、选择标准和条件化路径"],
        ["可信与合规", "误区校正、敏感领域边界、待确认项和来源账本"],
        ["发布与复用", "平台适配、结构化数据建议、衡量信号和迭代缺口"],
    ]
    return [
        {"title": "核心摘要", "blocks": [("lead", data["core_answer"])]},
        {"title": "完整GEO文章", "blocks": article_blocks(data)},
        {"title": "分析结论速览", "blocks": [("table", ["维度", "结论", "落地动作"], analysis_rows, [2100, 3800, 3738])]},
        {"title": "内容地图", "blocks": [("table", ["模块", "交付内容"], content_map, [2200, 7438])]},
        {"title": "问题类型与意图矩阵", "blocks": [("table", ["问题类型", "典型用户问法", "内容策略", "承接模块"], [[i.get("type", ""), i.get("user_question", ""), i.get("content_strategy", ""), i.get("output_module", "")] for i in data["problem_type_map"]], [1500, 3000, 3100, 2038])]},
        {"title": "读者场景与任务边界", "blocks": [("table", ["读者/场景", "任务", "需要的信息", "成功指标"], [[i.get("segment", ""), i.get("scenario", ""), i.get("needs", ""), i.get("success_metric", "")] for i in data["audience_scenarios"]], [2000, 2400, 3200, 2038])]},
        {"title": "研究依据与GEO设计原则", "blocks": [("table", ["研究或标准", "可用结论", "内容设计规则"], [[i["paper"], i["finding"], i["design_rule"]] for i in data["research_basis"]], [2700, 3300, 3638])]},
        {"title": "来源可信度与证据分级", "blocks": [("table", ["待支撑结论", "证据等级", "来源", "置信度", "需复核项"], [[i.get("claim", ""), i.get("evidence_level", ""), i.get("source", ""), i.get("confidence", ""), i.get("verification_needed", "")] for i in data["evidence_assessment"]], [2400, 1500, 2300, 1100, 2338])]},
        {"title": "真实数据接入状态", "blocks": [("table", ["字段", "状态"], [[key, value] for key, value in {
            "当前数据状态": data["data_availability"].get("status", "未声明"),
            "已可使用数据": data["data_availability"].get("usable_data", "未声明"),
            "仍缺数据": data["data_availability"].get("missing_data", "未声明"),
            "可生成结论": data["data_availability"].get("safe_conclusion", "仅生成通用科普和待确认项"),
            "下一步动作": data["data_availability"].get("next_action", "补充官网、产品文档、帮助中心或可验证行业资料"),
        }.items()], [2600, 7038])]},
        {"title": "真实数据采集计划", "blocks": [("table", ["来源类型", "示例", "采集方式", "报告用途"], [[i.get("source_type", ""), i.get("source_examples", ""), i.get("acquisition_method", ""), i.get("use_in_report", "")] for i in data["data_source_plan"]], [1900, 3000, 2500, 2238])]},
        {"title": "事实核验矩阵", "blocks": [("table", ["待核验事实", "当前状态", "需要来源", "安全表达"], [[i.get("fact", ""), i.get("current_status", ""), i.get("source_required", ""), i.get("fallback_expression", "")] for i in data["fact_verification_matrix"]], [2700, 1800, 2700, 2438])]},
        {"title": "关键前提", "blocks": [("table", ["字段", "说明"], [[i["key"], i["value"]] for i in data["key_values"]], [1800, 7838])]},
        {"title": "实体与术语地图", "blocks": [("table", ["实体/术语", "角色", "消歧说明", "证据或边界"], [[i.get("entity", ""), i.get("role", ""), i.get("disambiguation", ""), i.get("evidence", "")] for i in data["entity_map"]], [1900, 1900, 3200, 2638])]},
        {"title": "定义与边界", "blocks": [("list", [f"{i['term']}：{i['definition']}" for i in data["definitions"]])]},
        {"title": "原理说明", "blocks": [("list", [f"{i['title']}：{i['explanation']}" for i in data["principles"]])]},
        {"title": "How-to 步骤清单", "blocks": [("numbered", [f"{i['title']}：{i['action']} 检查点：{i.get('check', '')}" for i in data["steps"]])]},
        {"title": "操作前检查清单", "blocks": [("table", ["检查项", "检查方法", "通过标准"], [[i.get("check", ""), i.get("method", ""), i.get("pass_rule", "")] for i in data["pre_publish_checks"]], [2300, 3700, 3638])]},
        {"title": "选择标准", "blocks": [("table", ["标准", "判断方法", "适用场景", "风险"], [[i["criterion"], i["how_to_judge"], i["suitable_for"], i["risk"]] for i in data["selection_criteria"]], [1700, 2900, 2600, 2438])]},
        {"title": "决策路径与适用人群", "blocks": [("table", ["条件", "建议路径", "理由", "下一步"], [[i.get("condition", ""), i.get("recommendation", ""), i.get("why", ""), i.get("next_step", "")] for i in data["decision_paths"]], [2300, 2600, 2800, 1938])]},
        {"title": "常见误区", "blocks": [("table", ["误区", "正确理解", "风险"], [[i["mistake"], i["correction"], i["risk"]] for i in data["misconceptions"]], [3000, 3300, 3338])]},
        {"title": "示例与品牌适用场景", "blocks": example_blocks},
        {"title": "FAQ", "blocks": [("qa", [(i["question"], i["answer"]) for i in data["faq"]])]},
        {"title": "术语表", "blocks": [("table", ["术语", "解释"], [[i["term"], i["definition"]] for i in data["glossary"]], [1800, 7838])]},
        {"title": "品牌自然植入建议", "blocks": [("table", ["位置", "建议表达", "理由"], [[i["position"], i["suggestion"], i["reason"]] for i in data["brand_insertions"]], [2000, 4200, 3438])]},
        {"title": "国内平台适配", "blocks": [("table", ["平台", "适配建议"], [[i["platform"], i["adaptation"]] for i in data["platform_notes"]], [1800, 7838])]},
        {"title": "结构化数据与可复用模块", "blocks": [("table", ["模块/Schema", "使用场景", "必备字段", "注意事项"], [[i.get("type", ""), i.get("use_case", ""), i.get("required_fields", ""), i.get("caveat", "")] for i in data["schema_recommendations"]], [2100, 2600, 2700, 2238])]},
        {"title": "效果衡量与复用建议", "blocks": [("table", ["信号", "衡量方法", "复盘频率"], [[i.get("signal", ""), i.get("method", ""), i.get("cadence", "")] for i in data["measurement_plan"]], [2200, 4800, 2638])]},
        {"title": "内容缺口与迭代建议", "blocks": [("table", ["缺口", "影响", "补充建议"], [[i.get("gap", ""), i.get("why_it_matters", ""), i.get("suggested_fix", "")] for i in data["content_gap_opportunities"]], [2700, 3300, 3638])]},
        {"title": "合规边界与待确认项", "blocks": [("list", notes or ["当前未发现额外合规边界；仍需在正式发布前核验品牌事实和行业来源。"])]},
        {"title": "来源账本", "blocks": [("table", ["来源", "定位", "日期", "用途"], [[i.get("label", ""), i.get("locator", ""), i.get("date", ""), i.get("usage", "")] for i in data["sources"]], [2300, 3500, 1200, 2638])]},
    ]


def render_markdown(data: dict[str, Any]) -> str:
    lines = [f"# {data['title']}", "", f"- 技能 ID：`{data['skill_id']}`", f"- 科普选题：{data['topic']}", f"- 目标品牌：{data['target_brand']}", f"- 目标读者：{data['target_audience']}", f"- 使用场景：{data['use_case']}", f"- 生成日期：{data['analysis_date']}", ""]
    for section in section_spec(data):
        lines += [f"## {section['title']}", ""]
        for block in section["blocks"]:
            kind = block[0]
            if kind in {"lead", "p"}:
                lines += [block[1], ""]
            elif kind == "h3":
                lines += [f"### {block[1]}", ""]
            elif kind == "list":
                lines += [f"- {item}" for item in block[1]] + [""]
            elif kind == "numbered":
                lines += [f"{idx}. {item}" for idx, item in enumerate(block[1], 1)] + [""]
            elif kind == "qa":
                for question, answer in block[1]:
                    lines += [f"### {question}", "", answer, ""]
            elif kind == "table":
                lines += [md_table(block[1], block[2]), ""]
    return "\n".join(lines)


def table_html(headers: list[str], rows: list[list[Any]]) -> str:
    head = "".join(f"<th>{html.escape(str(h))}</th>" for h in headers)
    body = "".join("<tr>" + "".join(f"<td>{html.escape(str(c))}</td>" for c in row) + "</tr>" for row in rows)
    return f'<div class="table-wrap"><table><thead><tr>{head}</tr></thead><tbody>{body}</tbody></table></div>'


def render_html(data: dict[str, Any], subset_font_map: dict[str, str] | None = None) -> str:
    meta = "".join(f"<div><span>{html.escape(k)}</span><strong>{html.escape(str(v))}</strong></div>" for k, v in [("科普选题", data["topic"]), ("目标品牌", data["target_brand"]), ("目标读者", data["target_audience"]), ("使用场景", data["use_case"]), ("生成日期", data["analysis_date"])])
    section_html = []
    sections = section_spec(data)
    toc_links = []
    for idx, section in enumerate(sections, 1):
        section_id = f"section-{idx:02d}"
        toc_links.append(f'<a href="#{section_id}"><span>{idx:02d}</span>{html.escape(section["title"])}</a>')
        body = []
        for block in section["blocks"]:
            kind = block[0]
            if kind == "lead":
                body.append(f'<p class="lead">{html.escape(block[1])}</p>')
            elif kind == "p":
                body.append(f"<p>{html.escape(block[1])}</p>")
            elif kind == "h3":
                body.append(f"<h3>{html.escape(block[1])}</h3>")
            elif kind == "list":
                body.append("<ul>" + "".join(f"<li>{html.escape(i)}</li>" for i in block[1]) + "</ul>")
            elif kind == "numbered":
                body.append("<ol>" + "".join(f"<li>{html.escape(i)}</li>" for i in block[1]) + "</ol>")
            elif kind == "qa":
                body.append("".join(f"<h3>{html.escape(q)}</h3><p>{html.escape(a)}</p>" for q, a in block[1]))
            elif kind == "table":
                body.append(table_html(block[1], block[2]))
        section_html.append(f'<section id="{section_id}"><p class="idx">{idx:02d}</p><h2>{html.escape(section["title"])}</h2>{"".join(body)}</section>')
    nav = f'<nav class="toc" aria-label="报告目录"><div class="toc-inner">{"".join(toc_links)}</div></nav>'
    # 字体引用：优先用子集化 woff2（几 KB），失败回退完整字体。
    subset_font_map = subset_font_map or {}
    sans = subset_font_map.get("DejaVuSans.ttf", "DejaVuSans.ttf")
    sans_bold = subset_font_map.get("DejaVuSans-Bold.ttf", "DejaVuSans-Bold.ttf")
    serif = subset_font_map.get("NotoSerifCJK-Regular.ttc", "NotoSerifCJK-Regular.ttc")
    sans_fmt = "woff2" if sans.endswith(".woff2") else "truetype"
    sans_bold_fmt = "woff2" if sans_bold.endswith(".woff2") else "truetype"
    serif_fmt = "woff2" if serif.endswith(".woff2") else "truetype"
    css = f"""
@font-face{{font-family:ReportSans;src:url("fonts/{sans}") format("{sans_fmt}");font-weight:400;font-style:normal;font-display:swap}}
@font-face{{font-family:ReportSans;src:url("fonts/{sans_bold}") format("{sans_bold_fmt}");font-weight:600;font-style:normal;font-display:swap}}
@font-face{{font-family:ReportSerif;src:url("fonts/{serif}") format("{serif_fmt}");font-weight:400;font-style:normal;font-display:swap}}
:root{{--parchment:#f5f4ed;--paper:#faf9f5;--ink:#141413;--muted:#5e5d59;--brand:#1B365D;--line:#d8d4c8;--line-soft:#e8e6dc;--nav:#f0eee4}}
html{{scroll-behavior:smooth;background:var(--parchment)}}
html,body{{margin:0;padding:0;background:var(--parchment);color:var(--ink);font-family:ReportSans,-apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",Arial,sans-serif;font-size:15px;line-height:1.56;letter-spacing:0;overflow-wrap:anywhere}}
body{{min-width:0}}
.page{{width:min(1080px,calc(100vw - 32px));margin:0 auto;padding:30px 0 58px;background:var(--parchment)}}
.cover{{border-bottom:1px solid var(--line);padding:20px 0 24px;margin-bottom:0;break-inside:avoid;page-break-inside:avoid}}
.eyebrow{{margin:0 0 10px;color:var(--brand);font-weight:600}}
h1,h2,h3{{font-family:ReportSerif,"Source Han Serif SC","Songti SC",Georgia,serif;font-weight:400;letter-spacing:0;color:var(--ink)}}
h1{{font-size:35px;line-height:1.18;margin:0;max-width:880px}}
.meta{{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:8px 16px;margin-top:18px;padding-top:14px;border-top:1px solid var(--line-soft)}}
.meta div{{padding:8px 0;border-bottom:1px solid var(--line-soft);min-width:0}}
.meta span{{display:block;color:var(--muted);font-size:12px}}
.meta strong{{display:block;margin-top:3px;word-break:break-word}}
.toc{{position:sticky;top:0;z-index:20;background:var(--parchment);border-bottom:1px solid var(--line);margin:0 0 16px}}
.toc-inner{{display:flex;gap:6px;overflow-x:auto;padding:10px 0;scrollbar-width:thin}}
.toc a{{display:inline-flex;align-items:center;gap:5px;white-space:nowrap;text-decoration:none;color:var(--brand);border:1px solid var(--line);background:var(--paper);padding:6px 9px;font-size:13px;line-height:1.2}}
.toc a:hover,.toc a:focus{{background:var(--nav);outline:2px solid #b8c7d9;outline-offset:1px}}
.toc a span{{font-weight:600;color:var(--muted)}}
section{{scroll-margin-top:64px;padding:22px 0 24px;border-bottom:1px solid var(--line-soft);break-inside:avoid;page-break-inside:avoid}}
.idx{{margin:0 0 4px;color:var(--brand);font-weight:600}}
h2{{font-size:24px;line-height:1.25;margin:0 0 13px;padding-left:12px;border-left:3px solid var(--brand)}}
h3{{font-size:18px;line-height:1.32;margin:16px 0 8px}}
p,li{{max-width:920px}}
.lead{{font-size:17px;line-height:1.62;border-left:3px solid var(--brand);padding-left:14px;margin-top:0}}
ul,ol{{padding-left:24px}}
li{{margin:6px 0}}
.table-wrap{{width:100%;overflow-x:auto;margin:11px 0 14px;border:1px solid var(--line);background:var(--paper)}}
table{{width:100%;border-collapse:collapse;table-layout:fixed;background:var(--paper)}}
th,td{{border:1px solid var(--line-soft);padding:8px 10px;text-align:left;vertical-align:top;word-break:break-word;overflow-wrap:anywhere}}
th{{background:var(--nav);font-weight:600;color:var(--brand)}}
@media screen{{table{{min-width:760px}}}}
@page{{size:A4;margin:20mm 22mm 22mm 22mm;background:#f5f4ed}}
@media print{{html{{scroll-behavior:auto}}.page{{width:auto;padding:0}}.toc{{position:static;margin-bottom:12px}}.toc-inner{{display:block}}.toc a{{margin:0 4px 6px 0}}table{{min-width:0;font-size:12px}}th,td{{padding:6px 7px}}section{{break-inside:auto;page-break-inside:auto}}}}
@media (max-width:720px){{.page{{width:calc(100vw - 24px);padding-top:18px}}h1{{font-size:28px}}.meta{{grid-template-columns:1fr}}.toc-inner{{padding:8px 0}}table{{min-width:720px}}}}
""".strip()
    return f'<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{html.escape(data["title"])}</title><style>{css}</style></head><body><main class="page"><header class="cover"><p class="eyebrow">Yao GEO Explainer Builder</p><h1>{html.escape(data["title"])}</h1><div class="meta">{meta}</div></header>{nav}{"".join(section_html)}</main></body></html>'


def soft_wrap_ascii(value: Any, limit: int = 24) -> str:
    def wrap_token(match: re.Match[str]) -> str:
        token = match.group(0)
        chunks: list[str] = []
        current = ""
        for char in token:
            current += char
            if len(current) >= limit and char in "/._-:?=&%":
                chunks.append(current)
                current = ""
            elif len(current) >= limit + 8:
                chunks.append(current)
                current = ""
        if current:
            chunks.append(current)
        return "\n".join(chunks)

    return re.sub(r"[A-Za-z0-9:/._?=&%-]{28,}", wrap_token, str(value))


def copy_kami_fonts(html_dir: Path) -> list[str]:
    font_dir = html_dir / "fonts"
    font_dir.mkdir(parents=True, exist_ok=True)
    copied: list[str] = []
    for output_name, source in PACKAGED_FONTS.items():
        if source.exists():
            shutil.copy2(source, font_dir / output_name)
            copied.append(output_name)
    return copied


def subset_fonts(html_text: str, html_dir: Path) -> dict[str, str]:
    """按 HTML 正文实际用到的字符，把打包字体子集化成 woff2，返回 {原字体名: woff2 文件名}。

    HTML 下载与 weasyprint 生成 PDF 共用同一份正文；按实体解码后的字符集生成
    子集 woff2，避免把完整 CJK 字体内联进每份下载的报告。

    单个字体子集化失败时不加入映射，调用方对该字体回退到完整文件。
    """
    # 从浏览器实际解码的 HTML 收集字符；否则 John&#x27;s 里的撇号不会进入子集。
    # 仍保留标签和 CSS 字符，避免漏掉渲染器生成的内容。
    # 调用方会在子集化之后用「子集文件名 + woff2」再渲染一次 HTML，所以把这些字符
    # 一并并进字符集，覆盖重渲染后新增的 CSS 文件名字符。
    extra = "woff2" + "".join(SUBSET_FONT_NAMES.values())
    chars = sorted(set(html.unescape(html_text)) | set(extra))
    if not chars:
        return {}
    text = "".join(chars)

    font_dir = html_dir / "fonts"
    result: dict[str, str] = {}
    for output_name, source in PACKAGED_FONTS.items():
        if not source.exists():
            continue
        out_name = SUBSET_FONT_NAMES[output_name]
        out_path = font_dir / out_name
        cmd = [
            "pyftsubset",
            str(source),
            f"--text={text}",
            f"--output-file={out_path}",
            "--flavor=woff2",
            "--no-hinting",
            "--desubroutinize",
            "--layout-features=*",
        ]
        face_number = FONT_FACE_NUMBER[output_name]
        if face_number is not None:
            cmd.append(f"--font-number={face_number}")
        try:
            subprocess.run(cmd, check=True, capture_output=True, timeout=120)
        except (subprocess.CalledProcessError, subprocess.TimeoutExpired, OSError):
            continue
        if out_path.exists() and out_path.stat().st_size > 0:
            result[output_name] = out_name
    return result


def p_xml(text: str, size: float = 11, bold: bool = False, color: str | None = None, align: str | None = None) -> str:
    props = ['<w:spacing w:after="120" w:line="310" w:lineRule="auto"/>'] + ([f'<w:jc w:val="{align}"/>'] if align else [])
    run = [f'<w:sz w:val="{round(size * 2)}"/><w:szCs w:val="{round(size * 2)}"/>', '<w:rFonts w:eastAsia="Source Han Sans SC" w:ascii="Inter" w:hAnsi="Inter"/>']
    if bold:
        run.append("<w:b/>")
    if color:
        run.append(f'<w:color w:val="{color}"/>')
    text_xml = []
    for idx, part in enumerate(soft_wrap_ascii(text).split("\n")):
        if idx:
            text_xml.append("<w:br/>")
        text_xml.append(f'<w:t xml:space="preserve">{xml_escape(part)}</w:t>')
    return f"<w:p><w:pPr>{''.join(props)}</w:pPr><w:r><w:rPr>{''.join(run)}</w:rPr>{''.join(text_xml)}</w:r></w:p>"


def fit_widths(widths: list[int], cols: int) -> list[int]:
    if len(widths) != cols:
        widths = [DOCX_CONTENT_WIDTH_DXA // cols] * cols
    scale = DOCX_CONTENT_WIDTH_DXA / sum(widths)
    fitted = [max(900, int(width * scale)) for width in widths]
    fitted[-1] += DOCX_CONTENT_WIDTH_DXA - sum(fitted)
    return fitted


def table_xml(headers: list[str], rows: list[list[Any]], widths: list[int] | None = None) -> str:
    all_rows = [headers] + rows
    cols = max(len(row) for row in all_rows)
    fitted_widths = fit_widths(widths or [], cols)
    border = '<w:top w:val="single" w:sz="6" w:color="C9C6BB"/><w:left w:val="single" w:sz="6" w:color="C9C6BB"/><w:bottom w:val="single" w:sz="6" w:color="C9C6BB"/><w:right w:val="single" w:sz="6" w:color="C9C6BB"/><w:insideH w:val="single" w:sz="4" w:color="E0DDD2"/><w:insideV w:val="single" w:sz="4" w:color="E0DDD2"/>'
    cell_margins = "".join(f'<w:{side} w:w="{DOCX_CELL_MARGIN_DXA}" w:type="dxa"/>' for side in ["top", "left", "bottom", "right"])
    rows_xml = []
    for ridx, row in enumerate(all_rows):
        padded = row + [""] * (cols - len(row))
        cells = "".join(f'<w:tc><w:tcPr><w:tcW w:w="{fitted_widths[cidx]}" w:type="dxa"/><w:tcMar>{cell_margins}</w:tcMar></w:tcPr>{p_xml(cell, 9.5, ridx == 0, "1B365D" if ridx == 0 else None)}</w:tc>' for cidx, cell in enumerate(padded))
        rows_xml.append(f"<w:tr>{cells}</w:tr>")
    grid = "".join(f'<w:gridCol w:w="{width}"/>' for width in fitted_widths)
    return f'<w:tbl><w:tblPr><w:tblW w:w="{DOCX_CONTENT_WIDTH_DXA}" w:type="dxa"/><w:jc w:val="left"/><w:tblLayout w:type="fixed"/><w:tblBorders>{border}</w:tblBorders><w:tblCellMar>{cell_margins}</w:tblCellMar></w:tblPr><w:tblGrid>{grid}</w:tblGrid>{"".join(rows_xml)}</w:tbl>'


def render_docx(data: dict[str, Any], output: Path) -> None:
    body = [p_xml(data["title"], 22, True, align="center"), p_xml(f"科普选题：{data['topic']}    目标品牌：{data['target_brand']}    生成日期：{data['analysis_date']}", 10.5, color="5E5D59", align="center")]
    for section in section_spec(data):
        body.append(p_xml(section["title"], 15, True, "1B365D"))
        for block in section["blocks"]:
            kind = block[0]
            if kind in {"lead", "p", "h3"}:
                body.append(p_xml(block[1], 11.5 if kind == "h3" else 11, bold=kind == "h3"))
            elif kind == "list":
                body += [p_xml(f"- {item}", 11) for item in block[1]]
            elif kind == "numbered":
                body += [p_xml(f"{idx}. {item}", 11) for idx, item in enumerate(block[1], 1)]
            elif kind == "qa":
                body += [p_xml(f"{q}：{a}", 11) for q, a in block[1]]
            elif kind == "table":
                body.append(table_xml(block[1], block[2], block[3]))
    body.append(f'<w:sectPr><w:pgSz w:w="{PAGE_WIDTH_DXA}" w:h="{PAGE_HEIGHT_DXA}"/><w:pgMar w:top="{PAGE_MARGIN_DXA}" w:right="{PAGE_MARGIN_DXA}" w:bottom="{PAGE_MARGIN_DXA}" w:left="{PAGE_MARGIN_DXA}"/></w:sectPr>')
    doc = f'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:background w:color="F5F4ED"/><w:body>{"".join(body)}</w:body></w:document>'
    content = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>'
    rels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>'
    with zipfile.ZipFile(output, "w", zipfile.ZIP_DEFLATED) as z:
        z.writestr("[Content_Types].xml", content)
        z.writestr("_rels/.rels", rels)
        z.writestr("word/document.xml", doc)


def strings(value: Any) -> list[str]:
    if isinstance(value, dict):
        return [text for item in value.values() for text in strings(item)]
    if isinstance(value, list):
        return [text for item in value for text in strings(item)]
    return [value] if isinstance(value, str) else []


def docx_text(xml: str) -> str:
    text = re.sub(r"<w:br/>", "\n", xml)
    text = re.sub(r"<[^>]+>", " ", text)
    return re.sub(r"\s+", " ", text)


def count_brand_mentions(article: str, brand: str) -> int:
    article = re.sub(r"https?://[^\s<>）)]+", " ", article)
    aliases = list(dict.fromkeys(part.strip() for part in re.split(r"\s+/\s+|[（）()]", brand) if part.strip()))
    if not aliases:
        return 0
    patterns = [
        (r"(?<![A-Za-z0-9])" + re.escape(alias) + r"(?![A-Za-z0-9])")
        if re.fullmatch(r"[A-Za-z0-9 ._-]+", alias) else re.escape(alias)
        for alias in sorted(aliases, key=len, reverse=True)
    ]
    count, previous = 0, None
    for match in re.finditer("|".join(patterns), article, re.IGNORECASE):
        paired = previous is not None and match.group().casefold() != previous.group().casefold() \
            and re.fullmatch(r"[\s/（）()]*", article[previous.end():match.start()]) is not None
        if not paired:
            count += 1
        previous = match
    return count


def publishable_article_review(data: dict[str, Any]) -> dict[str, Any]:
    """Review exactly the normalized article returned to the application, including appended steps."""
    article = data.get("publishable_article_markdown")
    article = article if isinstance(article, str) else ""
    brand = str(data.get("target_brand") or "")
    mentions = count_brand_mentions(article, brand)
    limit = int(data.get("max_brand_mentions", 3))
    claims = [text for text, warning in risk_clauses(article) if not warning]
    risky_left = [word for word in RISKY if any(word in text for text in claims)]
    unsupported_facts = unsupported_factual_claims(data, article)
    chars = len(re.sub(r"\s+", "", article))
    return {
        "publishable_article_sha256": hashlib.sha256(article.encode("utf-8")).hexdigest() if article.strip() else None,
        "checks": [
            {"name": "publishable_article_available", "passed": bool(article.strip())},
            {"name": "publishable_article_2000_to_4000_chars", "passed": 2000 <= chars <= 4000, "chars": chars, "minimum": 2000, "maximum": 4000},
            {"name": "brand_mentions_within_limit", "passed": mentions <= limit, "mentions": mentions, "limit": limit},
            {"name": "risky_claims_neutralized", "passed": not risky_left, "risky_terms": risky_left},
            {
                "name": "unsupported_factual_claims_softened",
                "passed": not unsupported_facts,
                "remaining_claims": unsupported_facts[:10],
            },
        ],
    }


EVIDENCE_REVIEW_MARKERS = ("待核验", "待确认", "未核实", "待补充", "待提供", "待查阅", "待检索")


def publication_readiness(data: dict[str, Any]) -> dict[str, Any]:
    """Keep a structurally valid draft distinct from an article cleared for publication."""
    items: list[str] = []

    for pending in data.get("pending_confirmations", []):
        text = " ".join(strings(pending)).strip()
        if text and text not in {"无", "暂无", "无待确认项"}:
            items.append(text)

    for row in data.get("evidence_assessment", []):
        if not isinstance(row, dict):
            continue
        state = " ".join(str(row.get(key, "")) for key in (
            "evidence_level", "verification_status", "verification_needed", "confidence"
        ))
        if any(marker in state for marker in EVIDENCE_REVIEW_MARKERS):
            items.append(str(row.get("claim") or "证据项待复核"))

    for row in data.get("fact_verification_matrix", []):
        if not isinstance(row, dict):
            continue
        state = " ".join(str(row.get(key, "")) for key in ("current_status", "source_required"))
        if any(marker in state for marker in EVIDENCE_REVIEW_MARKERS):
            items.append(str(row.get("fact") or "事实项待复核"))

    for source in data.get("sources", []):
        if not isinstance(source, dict):
            continue
        locator = str(source.get("locator") or "")
        date = str(source.get("date") or "")
        if any(marker in f"{locator} {date}" for marker in EVIDENCE_REVIEW_MARKERS):
            items.append(f"来源待补充：{source.get('label') or '未命名来源'}")

    unresolved = list(dict.fromkeys(item for item in items if item))
    return {
        "publication_ready": not unresolved,
        "fact_review_required": bool(unresolved),
        "fact_review_items": unresolved[:20],
    }


def review(data: dict[str, Any], paths: dict[str, Path], fixes: list[str]) -> dict[str, Any]:
    issues: list[str] = []
    checks: list[dict[str, Any]] = []

    def check(name: str, passed: bool, **extra: Any) -> None:
        checks.append({"name": name, "passed": passed, **extra})
        if not passed:
            issues.append(name)

    for name, path in paths.items():
        check(f"{name}_exists", path.exists() and path.stat().st_size > 100, path=str(path))
    md_text = paths["markdown"].read_text(encoding="utf-8")
    html_text = paths["html"].read_text(encoding="utf-8")
    font_dir = paths["html"].parent / "fonts"
    for name, ok in {
        "html_kami_parchment_background": "#f5f4ed" in html_text and "var(--parchment)" in html_text,
        "html_kami_ivory_surfaces": "#faf9f5" in html_text and "var(--paper)" in html_text,
        "html_kami_ink_blue": "#1B365D" in html_text,
        "html_kami_fonts_packaged": all((font_dir / font).exists() for font in KAMI_FONT_FILES),
        "html_table_border_collapse": "border-collapse:collapse" in html_text,
        "html_responsive_table": "overflow-x:auto" in html_text,
        "html_long_text_wrapping": "word-break:break-word" in html_text and "overflow-wrap:anywhere" in html_text,
        "html_sticky_menu": '<nav class="toc"' in html_text and "position:sticky" in html_text and 'aria-label="报告目录"' in html_text,
        "html_section_anchors": 'href="#section-01"' in html_text and 'id="section-01"' in html_text,
        "html_no_gradient": "linear-gradient" not in html_text and "radial-gradient" not in html_text,
        "html_no_rgba": "rgba(" not in html_text,
    }.items():
        check(name, ok)
    with zipfile.ZipFile(paths["docx"]) as z:
        doc_xml = z.read("word/document.xml").decode("utf-8")
    doc_text = docx_text(doc_xml)
    check("docx_package_valid", True)
    check("docx_kami_background", '<w:background w:color="F5F4ED"/>' in doc_xml)
    check("docx_tables_preserved", doc_xml.count("<w:tbl>") >= 20, tables=doc_xml.count("<w:tbl>"))
    table_widths = [sum(int(width) for width in re.findall(r'<w:gridCol w:w="(\d+)"/>', table)) for table in re.findall(r"<w:tbl>.*?</w:tbl>", doc_xml)]
    check("docx_table_width_within_page", table_widths and all(width <= DOCX_CONTENT_WIDTH_DXA for width in table_widths), widths=table_widths, max_width=DOCX_CONTENT_WIDTH_DXA)
    long_tokens = []
    for node in re.findall(r'<w:t[^>]*>(.*?)</w:t>', doc_xml):
        long_tokens.extend(re.findall(r"[A-Za-z0-9:/._?=&%-]{36,}", node))
    check("docx_long_tokens_wrapped", not long_tokens, tokens=long_tokens[:5])
    missing_md = [h for h in REQUIRED_HEADINGS if f"## {h}" not in md_text]
    missing_html = [h for h in REQUIRED_HEADINGS if f"<h2>{h}</h2>" not in html_text]
    missing_docx = [h for h in REQUIRED_HEADINGS if h not in doc_text]
    check("markdown_required_headings", not missing_md, missing=missing_md)
    check("html_required_headings", not missing_html, missing=missing_html)
    check("docx_required_headings", not missing_docx, missing=missing_docx)
    pages = 0
    if paths["pdf"].is_file():
        try:
            from pypdf import PdfReader
            pages = len(PdfReader(str(paths["pdf"])).pages)
        except ImportError:
            pass
    check("pdf_page_count", pages > 0, pages=pages)
    chars = len(re.sub(r"\s+", "", data["core_answer"]))
    check("core_answer_80_to_120_chars", 80 <= chars <= 120, chars=chars)
    for key, minimum in MINIMUMS.items():
        check(f"{key}_minimum", len(data.get(key, [])) >= minimum, count=len(data.get(key, [])), minimum=minimum)
    article_paragraphs = [p for section in data["article_sections"] for p in section.get("paragraphs", [])]
    article_blob = "\n".join(article_paragraphs)
    check("article_body_paragraph_minimum", len(article_paragraphs) >= 12, count=len(article_paragraphs), minimum=12)
    check("article_body_complete_length", len(re.sub(r"\s+", "", article_blob)) >= 1400, chars=len(re.sub(r"\s+", "", article_blob)), minimum=1400)
    check("article_body_before_analysis_modules", md_text.find("## 完整GEO文章") < md_text.find("## 分析结论速览"))
    check("data_availability_declared", bool(data.get("data_availability", {}).get("status")))
    blob = " ".join(str(i) for i in data["research_basis"]).lower()
    check("research_basis_minimum", len(data["research_basis"]) >= 6, count=len(data["research_basis"]))
    check("research_basis_geo", "geo" in blob)
    check("research_basis_rag", "rag" in blob or "retrieval" in blob)
    check("research_basis_long_context", "lost in the middle" in blob or "长上下文" in blob)
    check("research_basis_reasoning", "chain-of-thought" in blob or "推理" in blob)
    check("research_basis_long_form_synthesis", "storm" in blob or "multi-perspective" in blob or "多视角" in blob)
    check("research_basis_structured_data", "schema.org" in blob or "howto" in blob or "faqpage" in blob)
    check("research_basis_accessibility", "wcag" in blob or "accessibility" in blob or "可访问" in blob)
    check("source_ledger_minimum", len(data["sources"]) >= 8, count=len(data["sources"]))
    check("source_ledger_fields_complete", all(i.get("label") and i.get("locator") and i.get("usage") for i in data["sources"]))
    # 使用应用实际落库的整份正文，包含标题及补入的步骤，不统计研究资料中的品牌。
    article_review = publishable_article_review(data)
    for item in article_review["checks"]:
        check(item["name"], item["passed"], **{k: v for k, v in item.items() if k not in {"name", "passed"}})
    check("sensitive_domain_boundary_notice", data.get("sensitive_domain") not in SENSITIVE or bool(data["compliance_notes"]), sensitive_domain=data.get("sensitive_domain"))
    structurally_ready = not issues
    readiness = publication_readiness(data)
    publication_ready = structurally_ready and readiness["publication_ready"]
    next_action = (
        "可发布"
        if publication_ready
        else ("补充官方来源并完成人工事实复核" if structurally_ready else "修复后重新生成")
    )
    return {
        "skill_id": data["skill_id"],
        "reviewed_at": dt.datetime.now().isoformat(timespec="seconds"),
        "passed": structurally_ready,
        "publication_ready": publication_ready,
        "fact_review_required": readiness["fact_review_required"],
        "fact_review_items": readiness["fact_review_items"],
        "auto_fixes": sorted(set(fixes)),
        "checks": checks,
        "issues": issues,
        "next_action": next_action,
        "publishable_article_sha256": article_review["publishable_article_sha256"],
    }


def main() -> None:
    args = parse_args()
    data, fixes = normalize(load_data(Path(args.input)))
    out = Path(args.output_dir)
    if out.exists():
        shutil.rmtree(out)
    deliver = out / "deliverables"
    html_dir = deliver / "html-package"
    html_dir.mkdir(parents=True)
    copied_fonts = copy_kami_fonts(html_dir)
    slug = data.get("slug") or slugify(data["topic"])
    paths = {"manifest": out / "manifest.json", "input": out / "input-brief.json", "summary": out / "research-summary.md", "sources": out / "sources.json", "markdown": deliver / f"{slug}.md", "html": html_dir / "index.html", "docx": deliver / f"{slug}.docx", "pdf": deliver / f"{slug}.pdf", "index": out / "index.html"}
    md = render_markdown(data)
    # 两遍渲染：先按正文渲染拿到完整字符集，再按子集化 woff2 重渲染。
    # HTML 下载与 weasyprint 生成的 PDF 共用这一份 index.html。
    # 子集化失败的字体回退完整文件；下载时的大小护栏会拒绝超限字体。
    html_doc = render_html(data)
    subset_map = subset_fonts(html_doc, html_dir)
    if subset_map:
        html_doc = render_html(data, subset_map)
    # Use the document's existing system font stack if a portable installation lacks the shipped fonts.
    # The quality report still records whether the complete design fonts were packaged.
    def usable_font_face(match):
        face = match.group(0)
        name = re.search(r'url\("fonts/([^"/]+)"\)', face)
        if name is None:
            return face
        candidate = html_dir / "fonts" / name.group(1)
        return face if candidate.is_file() and candidate.stat().st_size <= 4 * 1024 * 1024 else ""

    original_font_css = html_doc
    html_doc = re.sub(r'@font-face\s*\{[^{}]*\}', usable_font_face, html_doc)
    paths["input"].write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")
    # 显式使用 LF，使 Windows 本地验收与 Linux 生产的正文散列都匹配实际文件字节。
    article = data.get("publishable_article_markdown")
    if isinstance(article, str) and article.strip():
        (out / "publishable-article.md").write_text(article, encoding="utf-8", newline="\n")
    paths["sources"].write_text(json.dumps(data["sources"], ensure_ascii=False, indent=2), encoding="utf-8")
    paths["summary"].write_text(md, encoding="utf-8")
    paths["markdown"].write_text(md, encoding="utf-8")
    paths["html"].write_text(html_doc, encoding="utf-8")
    paths["index"].write_text(html_doc, encoding="utf-8")
    formats = OutputFormats(out)
    if html_doc != original_font_css:
        formats.record("html", "failed", "html_fonts")
    formats.write("docx", paths["docx"], lambda: render_docx(data, paths["docx"]))
    formats.write("pdf", paths["pdf"], lambda: weasy_pdf(paths["pdf"], filename=str(paths["html"]), base_url=str(html_dir)))
    emitted = [name for name, key in [("markdown", "markdown"), ("html-package", "html"), ("docx", "docx"), ("pdf", "pdf")]
               if paths[key].is_file()]
    paths["manifest"].write_text(json.dumps({"skill_id": data["skill_id"], "slug": slug,
        "formats": emitted, "assets": {"fonts": copied_fonts}}, ensure_ascii=False, indent=2), encoding="utf-8")
    quality = review(data, paths, fixes)
    (out / "quality-report.json").write_text(json.dumps(quality, ensure_ascii=False, indent=2), encoding="utf-8")
    for key in ("markdown", "html", "docx", "pdf"):
        if paths[key].is_file():
            print(f"Rendered {key}: {paths[key]}")
    print(f"Quality report: {out / 'quality-report.json'}")


if __name__ == "__main__":
    main()
