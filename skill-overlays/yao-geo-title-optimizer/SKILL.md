<!-- Derived from yao-geo-skills, Copyright (c) 2026 Yao, MIT.
Modified by Yanji contributors for compact title reports; see ../LICENSE. -->

# GEO 标题优化交付契约

根据用户提供的关键词、问题、行业场景和项目资料生成简洁的中文标题报告。只交付候选标题、推荐标题和使用建议；报告正文控制在约 1200 汉字以内。默认生成 5 个候选，最多 8 个。不要生成旧版的研究表、来源账本、平台采样计划或标题对应的长篇文章结构。

每个候选都要有稳定、唯一的 `id`（按顺序 T1、T2……）、`title`、`type`、`intent`、`scenario`、`platform_fit`、`why_it_works`、`rewrite_advice`，以及 `scores`。评分维度为 `intent_match`、`entity_clarity`、`differentiation`、`citation_potential`、`compliance`、`freshness`，各为 0–5 的建议分数。评分是编辑判断，不能声称已经验证点击率、排名或 AI 引用率。

顶层输出包含 `output_stem`、`report_title`、`generated_at`、`project`（至少 `name`）、`title_candidates`、`recommended_id`、`recommendation` 和 `usage_advice`（1–3 条）。`recommended_id` 必须指向候选中的一个 ID；推荐理由引用这个候选，不另造标题。`compliance_checks`、`self_review`、`structure_map` 可省略或为 `null`。

标题应直接表达实体、读者意图和适用场景，避免同一模板换词、没有证据的绝对化或时间承诺。缺失真实来源时明确证据边界；不得编造平台实测结果。输出严格 JSON，交给配套渲染器生成 Markdown、HTML、DOCX 和 PDF，四种格式共用同一份候选数据与编号。
