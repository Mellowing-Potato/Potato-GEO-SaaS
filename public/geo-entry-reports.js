/* ============================================================================
   GEO 工作台 · 结果页三页签 + 资产视图（geo-entry-reports.js）

   从 public/geo-entry.js 搬出来的第十块（计划 §7 前端大文件拆分，"技能/报告"域的报告侧）。
   搬走的是两段紧挨着的报告代码：

     ① 结果页把一份报告拆成三个页签（概览 / 问题清单 / 完整报告）的渲染：
        分区切分、长报告滑轨与常开节、表格/键值/有序列表/代码块等各形状渲染器、
        执行结果卡（用量 / 质量 / 产物）。
     ② 资产视图：历史报告与文章列表（筛选、分组、分页、详情、下载、下一步动作）。

   ⚠️ 报告分区常量（SECTION_LABELS / RESULT_ZONES / REPORT_*）**留在主文件**
   —— 它们是渲染器的"配置表"，是对象不是函数，主文件没法用薄转发替；由本文件按名解构更简单。

   ⚠️ 加载顺序：本文件排在 geo-entry-skills.js **之前**（技能块要用它的 renderExecutionGlance /
   skillExecutionText / copySkillExecution / downloadSkillExecution / downloadSkillFormat）；
   反过来本文件要用的技能侧函数（结果卡重绘与状态文案）走**运行期** globalThis.GEO 取值，
   不吃加载顺序 —— 两个文件互相需要，靠一前一后是排不出拓扑序的。

   红线（与拆分前逐字一致）：
     - 同样的 payload 必须渲染出同样的 HTML（判据是逐视图 DOM + 文本指纹）；
     - 三页签是同一份数据的三个视角，内容重复是设计；任何字段都能在「完整报告」里找到；
     - 下载/导出路径、文件名规则、限长口径一个都不动。

   判据：tests/fixtures/ui/dom-fingerprint.json + scripts/verify-ui-panels.mjs +
   npm run test:js（含 report-history-layout 契约测试）。
   ============================================================================ */
(() => {
  const GEO = window.GEO;
  if (!GEO) {
    console.error('[geo-entry-reports] window.GEO 未就绪：请确认本文件在 geo-entry.js 之后加载');
    return;
  }
  const {
    state, elements, api, escapeHtml, formatDate, formatNumber, setMessage, validationMessage, confirmWorkspaceAction, openWorkspaceView, withBusyButton, showWorkspaceNotice, errorNoText, fillErrorNo, clToast, formatMoneyFen, copyText, downloadBlob, readableText, readableList, firstReadableList, fieldLabel, tryParseJsonValue, buildReportMarkdown, normalizeReport, parseStructuredResultFromText, downloadText, setFormValue, statusLabel, formatLabel, articleDisplayTitle, articleBodyBlock, articleReadableBody, articleSummaryText, clIsArticle, authHeaders, recentProjectStorageKey, runAnalysis, startAnalysisFromProject, openResultPage, renderArticlePublish, shouldUseReadableArticleBody, reportOneLineSummary, glanceFallbackLine, SECTION_LABELS, REPORT_SKIP_KEYS, REPORT_PAIR_KEYS, REPORT_POINT_KEYS, OPEN_SECTION_COUNT, LONG_REPORT_SECTION_THRESHOLD, REPORT_READING_ORDER, REPORT_DL_KEYS, REPORT_TABLE_KEYS, REPORT_OL_KEYS, REPORT_NOTE_KEYS, REPORT_CODE_KEYS, SECONDARY_REPORT_KEYS, REPORT_ZONE_OF, REPORT_ZONE_CARRY, REPORT_ZONE_CHROME_KEYS, RESULT_ZONES,
  } = GEO;

  // 资产视图的请求序号：用来丢弃过期响应（只被这一块使用，随代码一起搬）。
  let articleHistoryRequest = 0;
  let articleHistoryLoadedProjectId = 0;
  let articleHistoryLoading = false;
  let articleHistoryLoadingProjectId = 0;
  let articleHistoryError = false;

  // 表头上下文（渲染器一边读一边改写）：只被这一块使用，随代码搬过来自己声明。
  // ⚠️ 不能从 GEO 解构 —— 解构出来的是 const，而这里是**赋值**（会 `Assignment to constant variable`）。
  let activeTableHeaders = {};

  // The complete question bank is the authority for report counts. Keep the
  // model's original prose in the stored article; correct only presentation.
  function intentReportFacts(data) {
    if (!data || !Object.prototype.hasOwnProperty.call(data, "question_bank")) return null;
    if (!Array.isArray(data.question_bank) || !data.question_bank.every(row => row && typeof row === "object" && !Array.isArray(row))) return { verified: false, total: null, p0: null };
    return {
      verified: true,
      total: data.question_bank.length,
      p0: data.question_bank.filter(row => String(row?.priority || "").trim().toUpperCase() === "P0").length,
    };
  }

  function normalizeIntentReport(data) {
    const facts = intentReportFacts(data);
    if (!facts) return data;
    const copy = { ...data };
    if (!data.sampling_date) copy.analysis_date = "";
    const countText = (label) => !facts.verified ? "待校验" : /P0/i.test(label) ? `${facts.p0} 条` : `${facts.total} 条`;
    const correctSummary = paragraph => String(paragraph)
      .replace(/(?:共|总计|累计|形成|生成|产出|覆盖)\s*\d+\s*条(?:用户)?问题/g,
        facts.verified ? `共 ${facts.total} 条问题（按完整清单核对）` : "问题总数待校验")
      .replace(/P0\s*(?:优先(?:级|意图)?)?\s*[：:]?\s*\d+\s*条/gi,
        facts.verified ? `P0 ${facts.p0} 条（按完整清单核对）` : "P0 数量待校验")
      .replace(/研究覆盖/g, "计划监测");
    ["analysis_summary", "summary", "executive_summary", "conclusion", "subtitle"].forEach(key => {
      if (typeof data[key] === "string") copy[key] = correctSummary(data[key]);
    });
    if (Array.isArray(data.summary_cards)) {
      copy.summary_cards = data.summary_cards.map(card => {
        if (!card || typeof card !== "object") return card;
        const label = String(card.label || card.key || "");
        return /(?:问题|意图).*(?:总数|数量|清单|规模)|P0/i.test(label)
          ? { ...card, value: countText(label), note: facts.verified ? "按完整问题清单核对" : "完整问题清单待校验" } : card;
      });
    }
    // Historic summaries can contain model-written totals. Preserve those in
    // body_markdown; present a traceable correction only in summary paragraphs.
    if (Array.isArray(data.sections)) {
      copy.sections = data.sections.map(section => {
        if (!/摘要|速览|概览|结论/.test(String(section?.title || "")) || !Array.isArray(section.paragraphs)) return section;
        return { ...section, paragraphs: section.paragraphs.map(correctSummary) };
      });
    }
    return copy;
  }

  // ═══════════════════════════════════════════════
  // 结果页把一份报告拆成三个页签：概览 / 问题清单 / 完整报告。
  //
  // 🔴 分区表是**白名单加法**，不是黑名单：只登记进「概览」和「问题清单」的字段，
  //    其余一律落进默认区「完整报告」。新技能加了没登记的新字段，最坏结果只是
  //    「它只出现在完整报告页」—— 绝不会因为漏登记而整个消失。这条是故意的，
  //    别把它改成「必须登记才显示」。
  //
  // 🔴 「完整报告」页签渲染的是**整份未过滤的 payload**（就是今天历史报告页那一份），
  //    所以三个页签是同一份数据的三个视角，内容重复是设计，不是 bug。
  //    这也意味着：任何字段无论怎么分区，客户永远能在完整报告里找到它。

  function reportZoneOf(key) {
    return REPORT_ZONE_OF[key] || "full";
  }

  // 取某个分区的字段切片。渲染上下文字段无条件带上。
  function reportZoneSlice(data, zone) {
    const slice = {};
    REPORT_ZONE_CARRY.forEach((key) => {
      if (data[key] !== undefined) slice[key] = data[key];
    });
    Object.keys(data).forEach((key) => {
      if (REPORT_ZONE_CARRY.includes(key)) return;
      if (reportZoneOf(key) !== zone) return;
      slice[key] = data[key];
    });
    return slice;
  }

  function reportZoneHasContent(data, zone) {
    if (zone === "full") {
      return Object.keys(data).some((key) =>
        !REPORT_SKIP_KEYS.has(key) && hasReportFieldValue(data[key]));
    }
    return Object.keys(data).some((key) =>
      !REPORT_SKIP_KEYS.has(key)
      && !REPORT_ZONE_CHROME_KEYS.has(key)
      && reportZoneOf(key) === zone
      && hasReportFieldValue(data[key]));
  }

  function reportAnchorId(key) {
    return `report-sec-${String(key).replace(/[^A-Za-z0-9_-]/g, "_")}`;
  }

  function isSecondaryReportKey(key) {
    if (SECONDARY_REPORT_KEYS.has(key)) return true;
    return !Object.prototype.hasOwnProperty.call(SECTION_LABELS, key);
  }

  // Inject the anchor id onto the section wrapper without touching renderReportField's branches.
  function withReportSectionId(sectionHtml, anchorId) {
    const marker = `<div class="report-section">`;
    if (!sectionHtml || sectionHtml.slice(0, marker.length) !== marker) return sectionHtml;
    return `<div class="report-section" id="${anchorId}">${sectionHtml.slice(marker.length)}`;
  }

  // options.keyOrder（可选）：把这些字段提到最前面按给定顺序排，其余仍按 SECTION_LABELS
  // 顺序。不传就是原行为，一个像素不变 —— 结果页的「概览」页签要用它把
  // 关键发现 / 优化建议 / 下一步 排在结论前面（SECTION_LABELS 的顺序是登记顺序，
  // 不是阅读顺序，照它排会让概览先说结论、再说依据）。
  // ═══════════════════════════════════════════════
  // 结构化报告渲染器（形状驱动：表格 / 键值 / 有序列表 / 代码块，永不 JSON.stringify）
  // ═══════════════════════════════════════════════

  function isCompactTitleReport(data) {
    return Array.isArray(data.title_candidates) && typeof data.recommended_id === "string"
      && typeof data.recommendation === "string" && Array.isArray(data.usage_advice)
      && data.title_candidates.every((item) => item && typeof item.id === "string" && item.id.trim() && typeof item.title === "string");
  }

  function renderCompactTitleReport(data, options = {}) {
    const rows = data.title_candidates.map((candidate) => {
      const scores = candidate.scores || {};
      const keys = ["intent_match", "entity_clarity", "differentiation", "citation_potential", "compliance", "freshness"];
      const valid = keys.every((key) => typeof scores[key] === "number" && Number.isFinite(scores[key]) && scores[key] >= 0 && scores[key] <= 5);
      const score = valid ? `${Math.round(keys.reduce((sum, key) => sum + scores[key], 0) / 30 * 100)} / 100` : "待评估";
      return [`${candidate.id} · ${candidate.title}`, candidate.intent, score, candidate.why_it_works];
    });
    const selected = data.title_candidates.find((candidate) => candidate.id === data.recommended_id);
    const selectedText = selected ? `${selected.id} · ${selected.title}：${data.recommendation}` : "推荐候选未找到，请检查报告。";
    const advice = ["评分为模型建议，尚未经过实际点击率或排名验证。", ...data.usage_advice];
    (data.compliance_checks || []).forEach((row) => advice.push(Array.isArray(row) ? row.join("；") : readableText(row)));
    const title = options.omitTitle ? "" : `<h2 class="report-title">${escapeHtml(data.report_title || "标题优化")}</h2>`;
    return `<div class="report-doc">${title}<div class="report-section"><h4>候选标题</h4>${renderTableObject({ headers: ["标题", "适用意图", "建议评分", "理由"], rows })}</div><div class="report-section"><h4>推荐标题</h4><p>${escapeHtml(selectedText)}</p></div><div class="report-section"><h4>使用建议</h4><ul>${advice.map((item) => `<li>${escapeHtml(readableText(item))}</li>`).join("")}</ul></div></div>`;
  }

  function renderStructuredReportHtml(data, options = {}) {
    const parsed = normalizeIntentReport(tryParseJsonValue(data) || data);
    if (parsed === null || parsed === undefined || parsed === "") {
      return `<div class="report-doc"><p class="muted">暂无结构化内容。</p></div>`;
    }
    if (typeof parsed !== "object" || Array.isArray(parsed)) {
      return `<div class="report-doc"><p>${escapeHtml(readableText(parsed))}</p></div>`;
    }
    if (isCompactTitleReport(parsed)) {
      return renderCompactTitleReport(parsed, options);
    }

  const html = [];
  const usedKeys = {};

  // EXEC-07 F1: table header authority comes from the backend-injected payload field.
  activeTableHeaders = (parsed.table_headers && typeof parsed.table_headers === "object" && !Array.isArray(parsed.table_headers)) ? parsed.table_headers : {};
  // EXEC-07 F3: section_meanings → section intros; pull it out and never render it as its own section.
  const sectionMeanings = (parsed.section_meanings && typeof parsed.section_meanings === "object" && !Array.isArray(parsed.section_meanings)) ? parsed.section_meanings : {};
  if (Object.keys(sectionMeanings).length) usedKeys.section_meanings = true;

  // --- Top zone: title ---
    // options.omitTitle：调用方自己的页头已经写了这个标题时传 true，别再印一遍。
    // 独立结果页就是这种情况（页头 = 报告标题）。三个 key 照旧标成已用，
    // 否则它们会掉进下面的 remaining 里，变成一个叫「标题」的小节。
    const title = readableText(parsed.title || parsed.topic || parsed.report_title || "");
    if (title) {
      if (!options.omitTitle) html.push(`<h2 class="report-title">${escapeHtml(title)}</h2>`);
      usedKeys.title = true; usedKeys.topic = true; usedKeys.report_title = true;
    }

    // --- Meta line ---
    const metaParts = [];
    const metaFields = ["subtitle", "brand_name", "company_name", "target_brand", "target_audience", "use_case", "analysis_date"];
    metaFields.forEach((f) => {
      const v = readableText(parsed[f]);
      if (v) { metaParts.push(escapeHtml(v)); usedKeys[f] = true; }
    });
    if (metaParts.length) {
      html.push(`<p class="report-meta">${metaParts.join(" · ")}</p>`);
    }

  // --- Lead paragraph (first available of core_answer / analysis_summary / summary) ---
  // EXEC-07 F2: only accept a string. Array-shaped summary is rendered as a point list below,
  // never flattened into the lead (that produced the "一大坨分号串").
  const leadFields = ["core_answer", "analysis_summary", "summary"];
  for (const lf of leadFields) {
    const val = parsed[lf];
    if (typeof val !== "string" || !val.trim()) continue;
    html.push(`<p class="report-lead">${escapeHtml(readableText(val))}</p>`);
    usedKeys[lf] = true;
    break;
  }

    // --- Overall score (inline text, not pill) ---
    if (parsed.overall_score !== undefined && parsed.overall_score !== null) {
      html.push(`<p class="report-score">就绪评分 <span class="report-score-num">${escapeHtml(parsed.overall_score)}</span> / 100</p>`);
      usedKeys.overall_score = true;
    }

    // --- Summary cards (top metrics bar — only allowed "block") ---
    const questionFacts = intentReportFacts(parsed);
    if (questionFacts) {
      html.push(`<p class="report-meta">${questionFacts.verified
        ? `完整问题清单 ${questionFacts.total} 条 · P0 ${questionFacts.p0} 条（按清单核对）`
        : "问题统计待校验：无法解析完整问题清单"}</p>`);
    }
    if (Array.isArray(parsed.ai_sampling_plan) && parsed.ai_sampling_plan.length
      && !parsed.ai_sampling_plan.some(row => row?.observed_at && row?.observed_result)) {
      html.push('<p class="report-meta">M0 · 计划监测平台；尚未采样，暂无平台观测结论。</p>');
    }
    if (Array.isArray(parsed.summary_cards) && parsed.summary_cards.length) {
      html.push(renderMetricsBar(parsed.summary_cards, Boolean(options.reader)));
      usedKeys.summary_cards = true;
    }

    // --- Report sections (class A: sections array) ---
    if (Array.isArray(parsed.sections) && parsed.sections.length) {
      html.push(renderReportSections(parsed.sections));
      usedKeys.sections = true;
    }

    // --- Article sections (class B: article body) ---
    if (Array.isArray(parsed.article_sections) && parsed.article_sections.length) {
      const artHtml = [`<div class="report-section"><h4>文章正文</h4>`];
      parsed.article_sections.forEach((sec) => {
        const t = readableText(sec);
        if (t) artHtml.push(`<p>${escapeHtml(t)}</p>`);
      });
      artHtml.push(`</div>`);
      html.push(artHtml.join(""));
      usedKeys.article_sections = true;
    }

  // --- Remaining fields: keyOrder first, then SECTION_LABELS order, then unknown keys ---
  const remaining = [];
  const taken = new Set();
  const takeKey = (key) => {
    if (taken.has(key) || usedKeys[key] || REPORT_SKIP_KEYS.has(key)) return;
    if (typeof parsed[key] === "function") return;
    if (!hasReportFieldValue(parsed[key])) return;
    taken.add(key);
    remaining.push(key);
  };
  if (Array.isArray(options.keyOrder)) options.keyOrder.forEach(takeKey);
  Object.keys(SECTION_LABELS).forEach(takeKey);
  Object.keys(parsed).forEach((key) => {
    if (SECTION_LABELS.hasOwnProperty(key)) return;
    takeKey(key);
  });

  // 长报告按长度切换形态：短报告保持原来的黑名单折叠（零回归）；长报告改成
  // 「前 N 节常开 + 其余进滑轨」，并关掉顶部目录（折叠区的标签条本身就是目录）。
  //
  // remaining 已经排好序（keyOrder 在前，其余按 SECTION_LABELS 顺序），
  // 所以这里直接切前 N 个就是「最该先看的那几节」。不再判字段名。
  const longMode = !options.reader && remaining.length >= LONG_REPORT_SECTION_THRESHOLD;
  const alwaysOpen = longMode ? remaining.slice(0, OPEN_SECTION_COUNT) : [];
  const folded = longMode ? remaining.slice(OPEN_SECTION_COUNT) : [];
  const openKeys = new Set(alwaysOpen);
  const ordered = longMode
    ? alwaysOpen.concat(folded.length ? [null] : []).concat(folded)
    : remaining;

  // --- Build sections first, then prepend the TOC so entries map 1:1 to real sections ---
  const tocEntries = [];
  const sectionBlocks = [];
  // B 部分：长报告模式下，折叠区小节收进横向滑轨面板（一屏一节，内容默认全展开）。
  // 常开区仍走原竖排平铺（一个像素不动）；短报告模式一个字不改（零回归）。
  const sliderPanels = [];
  ordered.forEach((key) => {
    if (key === null) {
      sectionBlocks.push(`<h3 class="report-group">详细内容</h3>`);
      return;
    }
    const sectionHtml = renderReportField(key, parsed[key], Boolean(options.reader));
    if (!sectionHtml) return;
    const anchorId = reportAnchorId(key);
    const label = SECTION_LABELS[key] || fieldLabel(key);
    tocEntries.push(`<button type="button" data-report-anchor="${anchorId}">${escapeHtml(label)}</button>`);
    const meaning = sectionMeanings[key];
    const meaningHtml = meaning ? `<p class="report-section-note">${escapeHtml(readableText(meaning))}</p>` : "";
    if (!options.reader && (longMode ? !openKeys.has(key) : isSecondaryReportKey(key))) {
      if (longMode) {
        // 长报告：折叠区 → 滑轨面板。内容默认全部展开（不再有「点开才看得见」这一步），
        // 小节说明与常开区同款注入（标题下）。面板 id 由 withReportSectionId 注入到
        // .report-section 上，标签条经 data-report-anchor 指向它。
        const withNote = meaningHtml ? sectionHtml.replace(/(<\/h4>)/, `$1${meaningHtml}`) : sectionHtml;
        sliderPanels.push({ anchorId, label, html: withReportSectionId(withNote, anchorId) });
        return;
      }
      // 短报告：保持原折叠条行为（零回归）
      const summaryInner = meaning
        ? `<span class="report-fold-title">${escapeHtml(label)}</span><span class="report-fold-note">${escapeHtml(readableText(meaning))}</span>`
        : escapeHtml(label);
      sectionBlocks.push(`<details class="report-fold" id="${anchorId}"><summary>${summaryInner}</summary>${sectionHtml}</details>`);
    } else {
      // always-open: inject the note right after the <h4> when a section_meaning exists
      const withNote = meaningHtml ? sectionHtml.replace(/(<\/h4>)/, `$1${meaningHtml}`) : sectionHtml;
      sectionBlocks.push(withReportSectionId(withNote, anchorId));
    }
  });

  // options.hideToc：调用方明确说「这一页短、不要目录」。
  // 结果页的「概览」和「问题清单」是按分区挑出来的几节，一两屏就看完了，
  // 顶上再放一行小节目录纯属重复；「完整报告」不传这个，目录照旧。
  if (!options.hideToc && !longMode && remaining.length >= 5 && tocEntries.length) {
    html.push(`<nav class="report-toc" aria-label="报告小节目录">${tocEntries.join("")}</nav>`);
  }
  sectionBlocks.forEach((block) => html.push(block));
  // B 部分：长报告详细内容区收尾——面板数 ≥2 才做滑轨（单页轮播是噪音），1 节直接平铺。
  if (longMode && sliderPanels.length) {
    html.push(sliderPanels.length === 1
      ? sliderPanels[0].html
      : renderReportSlider(sliderPanels));
  }

    if (html.length === 0) {
      return `<div class="report-doc"><p class="muted">暂无结构化内容。</p></div>`;
    }
    // EXEC-07 F4: mark long mode so CSS can tighten the block rhythm without
    // touching short / article reports.
    const docClass = longMode ? "report-doc report-doc--long" : "report-doc";
    return `<div class="${docClass}">${html.join("")}</div>`;
  }

  function hasReportFieldValue(val) {
    if (val === undefined || val === null || val === "") return false;
    if (Array.isArray(val)) return val.length > 0;
    if (typeof val === "object") return Object.keys(val).length > 0;
    return true;
  }

  // B 部分：长报告「详细内容」区横向滑轨。一屏一节、内容默认全展开；
  // 标签条 = 目录复活（data-report-anchor 指向面板内小节），键盘/读屏可达，
  // 打印时由 CSS 解除 snap 全部平铺。不引入轮播库——触控板横滑、触屏手势、
  // 左右箭头都靠原生滚动，JS 只负责同步标签高亮与键盘。
  // ═══════════════════════════════════════════════
  // 长报告滑轨与常开节（短报告保持零回归的折叠形态）
  // ═══════════════════════════════════════════════

  function renderReportSlider(panels) {
    const tabs = panels.map((panel, i) => `
      <button type="button" class="report-slider-tab" role="tab" id="report-tab-${i}"
        aria-controls="report-panel-${i}" aria-selected="${i === 0 ? "true" : "false"}"
        data-report-anchor="${panel.anchorId}" data-slider-tab="${i}" data-slider-index="${i}">
        ${escapeHtml(panel.label)}
      </button>`).join("");
    const dots = panels.map((_, i) => `
      <button type="button" class="report-slider-dot" aria-label="第 ${i + 1} 节，共 ${panels.length} 节"
        aria-selected="${i === 0 ? "true" : "false"}" data-slider-dot="${i}"></button>`).join("");
    const panelsHtml = panels.map((panel, i) => `
      <section class="report-slider-panel" role="tabpanel" id="report-panel-${i}"
        aria-labelledby="report-tab-${i}" aria-label="第 ${i + 1} 节，共 ${panels.length} 节"
        data-slider-panel="${i}">
        ${panel.html}
      </section>`).join("");
    return `
      <div class="report-slider" data-report-slider>
        <div class="report-slider-tabs" role="tablist" aria-label="详细内容章节">${tabs}</div>
        <div class="report-slider-viewport">
          <div class="report-slider-track" data-slider-track>${panelsHtml}</div>
        </div>
        <div class="report-slider-nav">
          <button type="button" class="report-slider-arrow" data-slider-prev aria-label="上一节">‹</button>
          <div class="report-slider-dots" role="tablist" aria-label="章节指示">${dots}</div>
          <button type="button" class="report-slider-arrow" data-slider-next aria-label="下一节">›</button>
        </div>
      </div>`;
  }

  // B 部分：把滑轨切到第 index 个面板（index 越界则夹紧），返回实际落定的索引。
  // 原生滚动到面板 + 同步标签/页点高亮。用 block:"nearest" 避免页面整体跳动
  // （滑轨已在视口内时不动页面）。
  function slideReportTo(slider, index) {
    const panels = Array.from(slider.querySelectorAll("[data-slider-panel]"));
    if (!panels.length) return 0;
    const clamped = Math.max(0, Math.min(panels.length - 1, Number.isFinite(index) ? index : 0));
    panels[clamped].scrollIntoView({ behavior: "smooth", block: "nearest", inline: "start" });
    syncReportSliderState(slider, clamped);
    return clamped;
  }

  // B 部分：当前可见面板索引（按 scroll-snap 停靠位置，视口中线落在哪个面板算哪个）。
  //
  // 2026-08-24 修：原来拿 track.scrollLeft 去比 panel.offsetLeft，是两套坐标系混着比。
  // offsetLeft 是相对 offsetParent 的，而从 body 到滑轨这条链上没有任何祖先设过 position，
  // 所以 offsetParent 就是 <body>——offsetLeft 里带着左导航 + 面板内边距 + 历史栏那 600 多
  // 像素的页面偏移，比半个面板还宽，于是每个索引都算小一格：「下一节」箭头算出来的「当前」
  // 永远比实际少 1，下一个又正好等于当前，箭头和 ←/→ 就卡死在第 2 节，标签高亮也整体错一位。
  // getBoundingClientRect 两边都是视口坐标，不受祖先定位影响，这类几何 bug 也不会随布局复发。
  function currentSliderIndex(slider) {
    const track = slider.querySelector("[data-slider-track]");
    const panels = Array.from(slider.querySelectorAll("[data-slider-panel]"));
    if (!track || !panels.length) return 0;
    const trackRect = track.getBoundingClientRect();
    const mid = trackRect.left + trackRect.width / 2;
    for (let i = 0; i < panels.length; i++) {
      const rect = panels[i].getBoundingClientRect();
      if (mid >= rect.left && mid <= rect.right) return i;
    }
    return 0;
  }

  // B 部分：同步标签条与页点高亮。index 不传时按当前滚动位置算（手动横滑后调用）。
  function syncReportSliderState(slider, activeIndex) {
    const panels = Array.from(slider.querySelectorAll("[data-slider-panel]"));
    const count = panels.length;
    if (!count) return;
    const current = Number.isFinite(activeIndex) ? activeIndex : currentSliderIndex(slider);
    const clamp = Math.max(0, Math.min(count - 1, current));
    slider.querySelectorAll("[data-slider-tab]").forEach((tab, i) => {
      tab.setAttribute("aria-selected", String(i === clamp));
    });
    slider.querySelectorAll("[data-slider-dot]").forEach((dot, i) => {
      dot.setAttribute("aria-selected", String(i === clamp));
    });
  }

  function renderMetricsBar(cards, grouped = false) {
    if (!Array.isArray(cards) || !cards.length) return "";
    const primary = [];
    const context = [];
    const ordered = [];
    cards.forEach((card) => {
      if (typeof card !== "object" || card === null) {
        const v = escapeHtml(readableText(card));
        if (v) { const item = `<div class="report-metric"><span class="report-metric-value">${v}</span></div>`; context.push(item); ordered.push(item); }
        return;
      }
      const label = escapeHtml(readableText(card.label || card.key || ""));
      const value = escapeHtml(readableText(card.value ?? card.score ?? ""));
      const note = card.note ? `<span class="report-metric-note">${escapeHtml(readableText(card.note))}</span>` : "";
      if (!value && !label) return;
      const explanatory = /模式|风险|边界|竞品|方向|状态/.test(readableText(card.label || card.key || ""));
      const item = `<div class="report-metric${explanatory ? " report-summary-explain" : ""}">` +
        (label ? `<span class="report-metric-label">${label}</span>` : "") +
        `<span class="report-metric-value">${value}</span>` +
        note +
        `</div>`;
      ordered.push(item);
      (explanatory || !/\d/.test(readableText(card.value ?? card.score ?? "")) ? context : primary).push(item);
    });
    if (!primary.length && !context.length) return "";
    if (!grouped) return `<div class="report-metrics">${ordered.join("")}</div>`;
    return `<div class="report-metrics">${primary.length ? `<div class="report-metrics-primary">${primary.join("")}</div>` : ""}${context.length ? `<div class="report-metrics-context">${context.join("")}</div>` : ""}</div>`;
  }

  function renderReportSections(sections) {
    if (!Array.isArray(sections) || !sections.length) return "";
    const html = [];
    sections.forEach((sec) => {
      if (!sec || typeof sec !== "object") {
        const t = readableText(sec);
        if (t) html.push(`<p>${escapeHtml(t)}</p>`);
        return;
      }
      const title = readableText(sec.title || "");
      html.push(`<div class="report-section">`);
      if (title) html.push(`<h4>${escapeHtml(title)}</h4>`);

      if (Array.isArray(sec.paragraphs)) {
        sec.paragraphs.forEach((p) => {
          const t = readableText(p);
          if (t) html.push(`<p>${escapeHtml(t)}</p>`);
        });
      }

      if (Array.isArray(sec.bullets) && sec.bullets.length) {
        const bullets = sec.bullets.map((b) => {
          const t = readableText(b);
          return t ? `<li>${escapeHtml(t)}</li>` : "";
        }).filter(Boolean);
        if (bullets.length) html.push(`<ul>${bullets.join("")}</ul>`);
      }

      if (sec.table && typeof sec.table === "object") {
        const tableHtml = renderTableObject(sec.table);
        if (tableHtml) html.push(tableHtml);
      }

      const callout = readableText(sec.callout || "");
      if (callout) {
        html.push(`<div class="report-note">${escapeHtml(callout)}</div>`);
      }

      ["flow", "allocation"].forEach((f) => {
        if (sec[f] !== undefined && sec[f] !== null && sec[f] !== "") {
          const t = readableText(sec[f]);
          if (t) html.push(`<p>${escapeHtml(fieldLabel(f) + "：" + t)}</p>`);
        }
      });

      html.push(`</div>`);
    });
    return html.join("");
  }

  function renderTableObject(table) {
    if (!table || typeof table !== "object") return "";
    const headers = Array.isArray(table.headers) ? table.headers : [];
    const rows = Array.isArray(table.rows) ? table.rows : [];
    if (!headers.length && !rows.length) return "";

    const html = [`<div class="report-table-wrap"><table class="report-table">`];

    if (headers.length) {
      html.push(`<thead><tr>`);
      headers.forEach((h) => { html.push(`<th data-column-kind="${reportColumnKind(readableText(h))}">${escapeHtml(fieldLabel(readableText(h)))}</th>`); });
      html.push(`</tr></thead>`);
    }

    if (rows.length) {
      html.push(`<tbody>`);
      rows.forEach((row) => {
        if (!Array.isArray(row)) {
          html.push(`<tr><td>${escapeHtml(readableText(row))}</td></tr>`);
          return;
        }
        html.push(`<tr>`);
        row.forEach((cell, index) => { html.push(`<td data-column-kind="${reportColumnKind(readableText(headers[index] || ""))}">${escapeHtml(readableText(cell))}</td>`); });
        html.push(`</tr>`);
      });
      html.push(`</tbody>`);
    }

    html.push(`</table></div>`);
    return html.join("");
  }

  function renderReportField(key, value, reader = false) {
    const label = SECTION_LABELS[key] || fieldLabel(key);

    if (REPORT_CODE_KEYS.has(key)) {
      const code = typeof value === "string" ? value : JSON.stringify(value, null, 2);
      if (!code || code === "{}" || code === "[]") return "";
      return `<div class="report-section"><h4>${escapeHtml(label)}</h4><pre class="execution-output">${escapeHtml(code)}</pre></div>`;
    }

    if (typeof value === "string" || typeof value === "number" || typeof value === "boolean") {
      const text = String(value);
      if (!text) return "";
      return `<div class="report-section"><h4>${escapeHtml(label)}</h4><p>${escapeHtml(text)}</p></div>`;
    }

    if (Array.isArray(value)) {
      if (!value.length) return "";

      // Array of primitives → ul
      if (value.every((v) => typeof v === "string" || typeof v === "number" || typeof v === "boolean")) {
        const items = value.map((v) => `<li>${escapeHtml(String(v))}</li>`).join("");
        return `<div class="report-section"><h4>${escapeHtml(label)}</h4><ul>${items}</ul></div>`;
      }

      // Array of arrays (2D data)
      if (value.length && Array.isArray(value[0])) {
        // EXEC-07 F2: meta → definition list; summary/… → point list (never guessed header table)
        if (REPORT_PAIR_KEYS.has(key)) return renderPairList(key, label, value);
        if (REPORT_POINT_KEYS.has(key)) return renderPointList(key, label, value);
        // EXEC-07 F1: read authoritative headers instead of guessing (guessing ate the first data row)
        return render2DArraySection(key, label, value, activeTableHeaders[key]);
      }

      // Array of objects → semantic routing
      if (value.length && typeof value[0] === "object" && value[0] !== null) {
        return renderObjectArraySection(key, label, value, reader);
      }

      // Mixed array
      const mixedItems = value.map((v) => {
        const t = readableText(v);
        return t ? `<li>${escapeHtml(t)}</li>` : "";
      }).filter(Boolean);
      if (!mixedItems.length) return "";
      return `<div class="report-section"><h4>${escapeHtml(label)}</h4><ul>${mixedItems.join("")}</ul></div>`;
    }

    // Single object → dl
    if (typeof value === "object" && value !== null) {
      const entries = Object.keys(value).filter((k) => hasReportFieldValue(value[k]));
      if (!entries.length) return "";

      const dlHtml = [`<div class="report-section"><h4>${escapeHtml(label)}</h4><dl class="report-dl">`];
      entries.forEach((k) => {
        const dt = escapeHtml(fieldLabel(k));
        const dd = escapeHtml(readableText(value[k]));
        if (dd) dlHtml.push(`<dt>${dt}</dt><dd>${dd}</dd>`);
      });
      dlHtml.push(`</dl></div>`);
      return dlHtml.join("");
    }

    return "";
  }

  // EXEC-07 F2: meta is a 2D array of [label, value] pairs → definition list.
  // `report-dl-pairs` puts label and value side by side; the shared `report-dl`
  // stays stacked for FAQ answers and generic object dumps.
  function renderPairList(key, label, rows) {
    if (!Array.isArray(rows) || !rows.length) return "";
    const dl = [`<div class="report-section"><h4>${escapeHtml(label)}</h4><dl class="report-dl report-dl-pairs">`];
    rows.forEach((row) => {
      if (!Array.isArray(row) || row.length < 2) return;
      const dt = escapeHtml(readableText(row[0]));
      const dd = escapeHtml(readableText(row[1]));
      if (dt && dd) dl.push(`<dt>${dt}</dt><dd>${dd}</dd>`);
    });
    dl.push(`</dl></div>`);
    return dl.join("");
  }

  // EXEC-07 F2: summary / key_findings / … are 2D arrays → point list (strong head + note).
  function renderPointList(key, label, rows) {
    if (!Array.isArray(rows) || !rows.length) return "";
    const items = rows.map((row) => {
      if (!Array.isArray(row)) {
        const t = readableText(row);
        return t ? `<li>${escapeHtml(t)}</li>` : "";
      }
      const head = readableText(row[0]);
      const rest = row.slice(1).map((c) => readableText(c)).filter(Boolean).join("；");
      if (!head && !rest) return "";
      return `<li>${head ? `<strong>${escapeHtml(head)}</strong>` : ""}${rest ? `<span>${escapeHtml(rest)}</span>` : ""}</li>`;
    }).filter(Boolean);
    if (!items.length) return "";
    return `<div class="report-section"><h4>${escapeHtml(label)}</h4><ul class="report-points">${items.join("")}</ul></div>`;
  }

  // EXEC-07 F1: authoritative headers only. Guessing is gone — every data row goes to tbody,
  // so no real row is ever promoted to a header. Column mismatch is padded, never truncated.
  function render2DArraySection(key, label, data, headers) {
    if (!Array.isArray(data) || !data.length || !Array.isArray(data[0])) return "";
    const fixed = Array.isArray(headers) && headers.length ? headers : null;
    const html = [`<div class="report-section"><h4>${escapeHtml(label)}</h4><div class="report-table-wrap"><table class="report-table">`];

    if (fixed) {
      html.push(`<thead><tr>`);
      fixed.forEach((h) => { html.push(`<th>${escapeHtml(fieldLabel(readableText(h)))}</th>`); });
      html.push(`</tr></thead>`);
    }
    html.push(`<tbody>`);
    data.forEach((row) => {
      const r = Array.isArray(row) ? row : [row];
      html.push(`<tr>`);
      r.forEach((c) => { html.push(`<td>${escapeHtml(readableText(c))}</td>`); });
      if (fixed && r.length < fixed.length) {
        for (let i = r.length; i < fixed.length; i++) html.push(`<td></td>`);
      }
      html.push(`</tr>`);
    });
    html.push(`</tbody></table></div></div>`);
    return html.join("");
  }

  function renderObjectArraySection(key, label, items, reader = false) {
    if (!Array.isArray(items) || !items.length) return "";
    const html = [`<div class="report-section"><h4>${escapeHtml(label)}</h4>`];

    if (reader && (key === "question_bank" || key === "ai_sampling_plan")) {
      html.push(renderFocusedRows(key, items));
    } else if (REPORT_DL_KEYS.has(key)) {
      html.push(renderObjectArrayDL(items));
    } else if (REPORT_OL_KEYS.has(key)) {
      html.push(renderObjectArrayOL(items));
    } else if (REPORT_NOTE_KEYS.has(key)) {
      html.push(renderObjectArrayNotes(items));
    } else if (REPORT_TABLE_KEYS.has(key)) {
      html.push(renderObjectArrayTable(items));
    } else {
      // Fallback: by shape
      const firstObj = items[0];
      const fieldCount = Object.keys(firstObj).filter((k) => hasReportFieldValue(firstObj[k])).length;
      if (fieldCount <= 2) {
        html.push(renderObjectArrayDL(items));
      } else if (fieldCount >= 3 && objectsHaveSameKeys(items)) {
        html.push(renderObjectArrayTable(items));
      } else {
        html.push(renderObjectArrayDL(items));
      }
    }

    html.push(`</div>`);
    return html.join("");
  }

  function objectsHaveSameKeys(items) {
    if (!items.length) return false;
    const firstKeys = Object.keys(items[0]).sort().join(",");
    return items.every((obj) =>
      obj && typeof obj === "object" && Object.keys(obj).sort().join(",") === firstKeys
    );
  }

  function renderObjectArrayDL(items) {
    const html = [`<dl class="report-dl">`];
    items.forEach((obj) => {
      if (!obj || typeof obj !== "object") {
        const t = readableText(obj);
        if (t) html.push(`<dt>${escapeHtml(t)}</dt>`);
        return;
      }
      const entries = Object.keys(obj).filter((k) => hasReportFieldValue(obj[k]));
      if (!entries.length) return;

      const dtText = readableText(obj[entries[0]]);
      if (dtText) html.push(`<dt>${escapeHtml(dtText)}</dt>`);

      for (let i = 1; i < entries.length; i++) {
        const ddText = readableText(obj[entries[i]]);
        if (!ddText) continue;
        if (entries.length > 2) {
          html.push(`<dd>${escapeHtml(fieldLabel(entries[i]) + "：" + ddText)}</dd>`);
        } else {
          html.push(`<dd>${escapeHtml(ddText)}</dd>`);
        }
      }
    });
    html.push(`</dl>`);
    return html.join("");
  }

  function renderObjectArrayOL(items) {
    const html = [`<ol class="report-steps">`];
    items.forEach((obj) => {
      if (!obj || typeof obj !== "object") {
        const t = readableText(obj);
        if (t) html.push(`<li>${escapeHtml(t)}</li>`);
        return;
      }
      const entries = Object.keys(obj).filter((k) => hasReportFieldValue(obj[k]));
      if (!entries.length) return;

      const title = readableText(obj[entries[0]]);
      const subParts = [];
      for (let i = 1; i < entries.length; i++) {
        const t = readableText(obj[entries[i]]);
        if (t) subParts.push(escapeHtml(fieldLabel(entries[i]) + "：" + t));
      }
      html.push(`<li>` +
        (title ? `<strong>${escapeHtml(title)}</strong>` : "") +
        (subParts.length ? `<div class="report-substep">${subParts.join(" ｜ ")}</div>` : "") +
        `</li>`);
    });
    html.push(`</ol>`);
    return html.join("");
  }

  function renderObjectArrayNotes(items) {
    const html = [];
    items.forEach((obj) => {
      if (!obj || typeof obj !== "object") {
        const t = readableText(obj);
        if (t) html.push(`<div class="report-note">${escapeHtml(t)}</div>`);
        return;
      }
      const entries = Object.keys(obj).filter((k) => hasReportFieldValue(obj[k]));
      if (!entries.length) return;

      const parts = entries.map((k) => {
        const t = readableText(obj[k]);
        return t ? `<strong>${escapeHtml(fieldLabel(k))}：</strong>${escapeHtml(t)}` : "";
      }).filter(Boolean);
      if (parts.length) {
        html.push(`<div class="report-note">${parts.join("<br>")}</div>`);
      }
    });
    return html.join("");
  }

  function renderObjectArrayTable(items) {
    if (!items.length) return "";
    const fieldSet = [];
    items.forEach((obj) => {
      Object.keys(obj).forEach((k) => {
        if (fieldSet.indexOf(k) < 0) fieldSet.push(k);
      });
    });
    if (!fieldSet.length) return "";

    const html = [`<div class="report-table-wrap"><table class="report-table"><thead><tr>`];
    fieldSet.forEach((f) => { html.push(`<th data-column-kind="${reportColumnKind(f)}">${escapeHtml(fieldLabel(f))}</th>`); });
    html.push(`</tr></thead><tbody>`);

    items.forEach((obj) => {
      html.push(`<tr>`);
      fieldSet.forEach((f) => {
        const t = readableText(obj[f]);
        html.push(`<td data-column-kind="${reportColumnKind(f)}">${escapeHtml(t)}</td>`);
      });
      html.push(`</tr>`);
    });

    html.push(`</tbody></table></div>`);
    return html.join("");
  }

  function reportColumnKind(field) {
    const label = String(field || "").toLowerCase();
    if (/(^id$|_id$|编号|序号)/.test(label)) return "id";
    if (/(状态|status)/.test(label)) return "status";
    if (/(优先级|priority|合规等级)/.test(label)) return "priority";
    if (/(平台|platform)/.test(label)) return "platform";
    if (/(问题|问法|prompt|query|question)/.test(label)) return "question";
    return "description";
  }

  function renderFocusedRows(key, items) {
    const sampling = key === "ai_sampling_plan";
    const columns = sampling
      ? ["sample_id", "platform", "prompt", "status"]
      : ["question_id", "question", "intent_type", "priority"];
    const filterValues = (field) => [...new Set(items.map(item => readableText(item?.[field])).filter(Boolean))];
    const tools = sampling ? "" : `<div class="report-question-tools">
      <input type="search" data-report-search placeholder="搜索问题、问法或证据" aria-label="搜索问题">
      <select data-report-filter="intent_type" aria-label="按意图类型筛选"><option value="">全部意图类型</option>${filterValues("intent_type").map(value => `<option value="${escapeHtml(value)}">${escapeHtml(value)}</option>`).join("")}</select>
      <select data-report-filter="priority" aria-label="按优先级筛选"><option value="">全部优先级</option>${filterValues("priority").map(value => `<option value="${escapeHtml(value)}">${escapeHtml(value)}</option>`).join("")}</select>
      <span data-report-count data-report-total="${items.length}">显示 ${items.length} / 完整清单 ${items.length} 条</span>
    </div>`;
    const head = columns.map(field => `<th data-column-kind="${reportColumnKind(field)}">${escapeHtml(sampling && field === "prompt" ? "采样问题" : sampling && field === "status" ? "执行状态" : fieldLabel(field))}</th>`).join("");
    const rows = items.map((item) => {
      const main = columns.map(field => `<td data-column-kind="${reportColumnKind(field)}">${escapeHtml(readableText(item?.[field]))}</td>`).join("");
      const more = Object.keys(item || {}).filter(field => !columns.includes(field))
        .map(field => `<dt>${escapeHtml(fieldLabel(field))}</dt><dd>${escapeHtml(item[field] == null ? "未填写" : readableText(item[field]))}</dd>`).join("");
      const write = sampling || !state.resultArticle?.id || !item?.question_id ? ""
        : `<button class="format-link" type="button" data-report-write="${Number(state.resultArticle.id)}" data-writing-skill="yao-geo-explainer-builder" data-question-id="${escapeHtml(item.question_id)}">以此问题写作</button>`;
      const details = `<td><details class="report-row-details"><summary>查看详情</summary><dl>${more || "<dt>详情</dt><dd>无其他字段</dd>"}</dl></details>${write}</td>`;
      return `<tr data-report-row data-intent-type="${escapeHtml(readableText(item?.intent_type))}" data-priority="${escapeHtml(readableText(item?.priority))}">${main}${details}</tr>`;
    }).join("");
    return `<div class="report-focused-list">${tools}<div class="report-table-wrap"><table class="report-table"><thead><tr>${head}<th>详情</th></tr></thead><tbody>${rows}</tbody></table></div></div>`;
  }

  // 技能执行入口的「一眼摘要」（批 B 第 3 步）。原来这里是 renderExecutionOutput()，
  // 把整份结果铺在执行入口下面 —— 那是页面被撑到两万多像素的第二个来源。
  //
  // ⚠️ renderExecutionOutput 没有删：归档失败 / 没有 article_id 的执行仍然要靠它
  //    把结果显示出来，否则那次执行的产出在界面上就彻底看不到了（钱已经扣了）。
  function renderExecutionGlance(execution, text) {
    const articleId = Number(execution.article_id) || 0;
    if (!articleId) {
      // 没归档成报告：没有结果页可去，原样铺出来，别把人和他的产出隔开。
      return renderExecutionOutput(execution, text);
    }
    let structured = execution.structured || execution.output_data?.structured;
    if (typeof structured === "string") structured = tryParseJsonValue(structured);
    if (!structured) {
      structured = tryParseJsonValue(execution.result_text || execution.output_data?.result_text || "");
    }
    // 轮询接口不一定回 structured/result_text，但调用方手里的 text 常常就是那份 JSON。
    // 少了这一步，摘要只能去啃格式化 JSON 的第一行。
    if (!structured) structured = tryParseJsonValue(text || "");
    const parsed = (structured && typeof structured === "object" && !Array.isArray(structured)) ? structured : {};
    const summary = reportOneLineSummary(parsed) || glanceFallbackLine(text);
    return `
      <div class="report-glance">
        ${summary
          ? `<p class="report-glance-text">${escapeHtml(summary.slice(0, 200))}</p>`
          : ""}
        <button class="button secondary small" type="button" data-open-result="${articleId}">查看完整报告</button>
      </div>
    `;
  }

  function renderExecutionOutput(execution, text) {
    // Try structured data first (from execution.structured or result_text that is JSON)
    let structured = execution.structured || execution.output_data?.structured;
    if (!structured) {
      const rawText = execution.result_text || execution.output_data?.result_text || "";
      const parsed = tryParseJsonValue(rawText);
      if (parsed && typeof parsed === "object") {
        structured = parsed;
      }
    } else if (typeof structured === "string") {
      const parsed = tryParseJsonValue(structured);
      if (parsed && typeof parsed === "object") {
        structured = parsed;
      }
    }

    const structuredHasContent = structured && typeof structured === "object" && !Array.isArray(structured)
      && Object.keys(structured).some((key) => !REPORT_SKIP_KEYS.has(key) && hasReportFieldValue(structured[key]));
    if (structuredHasContent) {
      return `<div class="result-view execution-result">${renderStructuredReportHtml(structured)}</div>`;
    }

    if (text) {
      return `<div class="result-view execution-result">${renderReadableMarkdown(text)}</div>`;
    }

    return `<p class="muted">暂时没有文本输出。</p>`;
  }

  function renderReadableMarkdown(text) {
    const html = [];
    let listItems = [];
    let paragraph = [];

    const flushList = () => {
      if (listItems.length === 0) return;
      html.push(`<ul>${listItems.join("")}</ul>`);
      listItems = [];
    };

    const flushParagraph = () => {
      if (paragraph.length === 0) return;
      html.push(`<p>${escapeHtml(paragraph.join(" "))}</p>`);
      paragraph = [];
    };

    String(text || "").split(/\r?\n/).forEach((line) => {
      const trimmed = line.trim();
      if (!trimmed) {
        flushParagraph();
        flushList();
        return;
      }

      const heading = trimmed.match(/^#{1,4}\s+(.+)$/);
      if (heading) {
        flushParagraph();
        flushList();
        html.push(`<h4>${escapeHtml(heading[1])}</h4>`);
        return;
      }

      const bullet = trimmed.match(/^[-*•]\s+(.+)$/);
      if (bullet) {
        flushParagraph();
        listItems.push(`<li>${escapeHtml(bullet[1])}</li>`);
        return;
      }

      paragraph.push(trimmed);
    });

    flushParagraph();
    flushList();

    return html.length ? html.join("") : `<p class="muted">暂时没有文本输出。</p>`;
  }

  function skillExecutionText(execution) {
    const rawText = execution.result_text || execution.output_data?.result_text || "";
    const parsedText = tryParseJsonValue(rawText);
    if (parsedText) return skillStructuredText(parsedText);
    if (rawText) return rawText;

    const structured = execution.structured || execution.output_data?.structured;
    if (structured) return skillStructuredText(structured);
    return "";
  }

  function skillStructuredText(value) {
    const parsed = tryParseJsonValue(value) || value;
    if (!parsed || typeof parsed !== "object") return readableText(parsed);

    const sections = [];
    const projectName = readableText(parsed.project_name || parsed.project || "");
    if (projectName) sections.push(`## 项目\n${projectName}`);

    if (parsed.overall_score !== undefined && parsed.overall_score !== null) {
      sections.push(`## 综合评分\n${parsed.overall_score} / 100`);
    }

    const summary = readableText(
      parsed.analysis
      || parsed.summary
      || parsed.result
      || parsed.conclusion
      || parsed.recommendation
      || parsed.estimated_impact
      || "",
    );
    if (summary) sections.push(`## 分析结果\n${summary}`);

    appendSkillSection(sections, "意图关键词", parsed.intent_clusters);
    appendSkillSection(sections, "诊断维度", parsed.dimensions);
    appendSkillSection(sections, "执行路线", parsed.phases);
    appendSkillSection(sections, "问题清单", parsed.issues);
    appendSkillSection(sections, "候选标题", parsed.candidates);
    appendSkillSection(sections, "优化变化", parsed.improvements);
    appendSkillSection(sections, "页面结构", parsed.recommended_sections);

    const findings = firstReadableList(
      parsed.key_findings,
      parsed.findings,
      parsed.critical_issues,
      parsed.top_opportunities,
      parsed.knowledge_gaps,
    );
    if (findings.length) sections.push(`## 关键发现\n${findings.map((item) => `- ${item}`).join("\n")}`);

    const recommendations = firstReadableList(
      parsed.recommendations,
      parsed.suggestions,
      parsed.quick_wins,
      parsed.schema_recommendations,
      parsed.recommendation,
    );
    if (recommendations.length) sections.push(`## 优化建议\n${recommendations.map((item) => `- ${item}`).join("\n")}`);

    const nextSteps = firstReadableList(parsed.next_steps, parsed.next_actions, parsed.actions);
    if (nextSteps.length) sections.push(`## 下一步\n${nextSteps.map((item) => `- ${item}`).join("\n")}`);

    if (parsed.confidence !== undefined && parsed.confidence !== null) {
      sections.push(`## 置信度\n${parsed.confidence}`);
    }

    return sections.length ? sections.join("\n\n") : JSON.stringify(parsed, null, 2);
  }

  function appendSkillSection(sections, title, value) {
    const items = readableList(value);
    if (items.length) sections.push(`## ${title}\n${items.map((item) => `- ${item}`).join("\n")}`);
  }

  async function copySkillExecution(text) {
    if (!text) {
      setMessage(elements.skillMessage, "这次执行暂时没有可复制的文本。", "danger");
      return;
    }
    await copyText(text);
    setMessage(elements.skillMessage, "技能结果已复制。", "success");
  }

  function downloadSkillExecution(execution, text) {
    if (!text) {
      setMessage(elements.skillMessage, "这次执行暂时没有可下载的文本。", "danger");
      return;
    }
    downloadText(`skill-execution-${execution.id || "result"}.md`, text);
    setMessage(elements.skillMessage, "技能结果已下载。", "success");
  }

  async function downloadSkillFormat(execution, format) {
    try {
      const response = await fetch(`/api/v1/executions/${execution.id}/download/${format}`, {
        credentials: "include",
        headers: authHeaders({ Accept: "*/*" }),
      });
      if (!response.ok) throw new Error(`暂时没有 ${formatLabel(format)} 文件。`);
      const blob = await response.blob();
      downloadBlob(`skill-execution-${execution.id}.${format === "markdown" ? "md" : format}`, blob);
      setMessage(elements.skillMessage, `${formatLabel(format)} 已下载。`, "success");
    } catch (error) {
      setMessage(elements.skillMessage, error.message || `无法下载 ${formatLabel(format)}。`, "danger");
    }
  }

  // 🔴 2026-09-04 拆版式 · 步骤 4：接口一直是 Laravel 分页器（每页 20 条封顶），
  //    而这里从来不传 page、界面上也没有翻页控件 —— 第 21 条起客户在界面上
  //    没有任何办法够到。响应体自带 current_page / last_page，之前只是没用。
  //    append=true 表示「加载更多」：页码 +1，state.articles 累积而不是覆盖，
  //    并且不改动当前选中的报告。其余所有调用（换项目 / 换筛选 / 换搜索词 /
  //    刷新）都走默认的 append=false，页码归 1、累积清空。
  // ═══════════════════════════════════════════════
  // 资产视图：历史报告与文章列表（筛选 / 分组 / 分页 / 下一步）
  // ═══════════════════════════════════════════════

  async function loadArticles(projectId, selectId = null, { append = false } = {}) {
    if (!projectId || !elements.assetHistory) return;
    const request = ++articleHistoryRequest;
    const scope = recentProjectStorageKey();
    articleHistoryLoading = true;
    articleHistoryLoadingProjectId = Number(projectId);
    articleHistoryError = false;
    renderAssetNextPanel();
    try {
      renderAssetControls();
      setMessage(elements.assetMessage, "正在加载报告…");
      const page = append ? state.articlePage + 1 : 1;
      const params = new URLSearchParams();
      if (state.assetSourceType && state.assetSourceType !== "all") params.set("source_type", state.assetSourceType);
      if (state.assetSearch) params.set("search", state.assetSearch);
      if (page > 1) params.set("page", String(page));
      const suffix = params.toString() ? `?${params.toString()}` : "";
      const body = await api(`/api/v1/projects/${projectId}/articles${suffix}`);
      if (request !== articleHistoryRequest || scope !== recentProjectStorageKey() || Number(state.selectedProject?.id) !== Number(projectId)) return;
      const rows = body.data || [];
      state.articles = [...new Map((append ? [...state.articles, ...rows] : rows).map(article => [article.id, article])).values()];
      articleHistoryLoadedProjectId = Number(projectId);
      articleHistoryLoading = false;
      articleHistoryLoadingProjectId = 0;
      state.articlePage = Number(body.current_page) || page;
      state.articleLastPage = Number(body.last_page) || state.articlePage;
      renderArticleHistory();
      setMessage(elements.assetMessage, "");
      // Only an explicit save/selection opens a detail page. Browsing or refreshing stays in the list.
      if (selectId && !append) await selectArticle(selectId);
    } catch (error) {
      if (request !== articleHistoryRequest || scope !== recentProjectStorageKey()) return;
      articleHistoryLoading = false;
      articleHistoryLoadingProjectId = 0;
      articleHistoryError = true;
      articleHistoryLoadedProjectId = Number(projectId);
      renderAssetNextPanel();
      setMessage(elements.assetMessage, error.message || "无法加载历史报告。", "danger");
    }
  }

  function ensureAssetReportList(projectId) {
    if ((!articleHistoryLoading || articleHistoryLoadingProjectId !== Number(projectId))
      && articleHistoryLoadedProjectId !== Number(projectId)) {
      loadArticles(projectId);
    }
  }

  async function reloadArticles() {
    if (!state.selectedProject) {
      setMessage(elements.assetMessage, "请先选择一个项目，再刷新历史报告。", "danger");
      return;
    }

    await withBusyButton(elements.reloadAssetsButton, "刷新中", async () => {
      await loadArticles(state.selectedProject.id);
      if (elements.assetMessage?.dataset.variant !== "danger") {
        setMessage(elements.assetMessage, "历史报告已刷新。", "success");
      }
    });
  }

  function renderAssetControls() {
    if (elements.assetSourceFilter) {
      elements.assetSourceFilter.querySelectorAll("[data-source-type]").forEach((button) => {
        button.setAttribute("aria-pressed", button.dataset.sourceType === state.assetSourceType ? "true" : "false");
      });
    }
    if (elements.assetSearchInput && elements.assetSearchInput.value !== state.assetSearch) {
      elements.assetSearchInput.value = state.assetSearch;
    }
  }

  function renderAssetNextPanel() {
    if (!elements.assetNextPanel) return;
    const stats = assetWorkflowStats();
    const reports = scopedReportAssets().filter((article) => !clIsArticle(article));
    const selected = reports.find((article) => article.id === state.selectedArticleId) || null;
    const latestDiagnosis = scopedReportAssets().find((article) => String(article?.source_type || "").toLowerCase() === "geo_analysis");
    const hasReports = reports.length > 0;
    const filterKey = state.assetSourceType || "all";
    const listingPending = articleHistoryLoading || articleHistoryLoadedProjectId !== Number(state.selectedProject?.id);
    const hasEmptyFilteredView = filterKey !== "all" && !hasReports && !listingPending && !articleHistoryError;
    const primaryText = hasEmptyFilteredView
      ? "当前筛选没有历史报告"
      : hasReports
        ? "下一步：从诊断或路线结果里挑一个动作继续落地"
        : listingPending ? "正在核对当前项目的报告" : articleHistoryError ? "报告列表加载失败"
        : "下一步：先做一次综合诊断";
    const helperText = hasEmptyFilteredView
      ? `“${assetFilterLabel(filterKey)}”下没有结果。切回全部，可以查看已生成的综合诊断和技能报告。`
      : hasReports
        ? `${listingPending ? "其他报告仍在加载。" : ""}综合诊断和技能报告是参考材料，不会自动合并。可从当前报告选择问题进入写作。`
        : listingPending ? "请稍候，报告列表加载完成后再判断下一步。" : articleHistoryError ? "请刷新报告列表后再判断是否已有产物。"
        : "历史报告是收尾区。没有产物时，这里不会给出总建议。先到诊断与路线完成项目体检。";

    elements.assetNextPanel.innerHTML = `
      <div class="asset-next-card">
        <div>
          <span class="meta-label">完成后怎么用</span>
          <strong>${escapeHtml(primaryText)}</strong>
          <p>${escapeHtml(helperText)}</p>
        </div>
        <div class="asset-stats">
          <span>综合诊断 ${stats.diagnosis}${listingPending ? "（列表加载中）" : ""}</span>
          <span>技能报告 ${stats.skillResults}${listingPending ? "（列表加载中）" : ""}</span>
        </div>
        <div class="asset-next-actions">
          ${latestDiagnosis ? `<button class="button secondary small" type="button" data-asset-next="diagnosis" data-article-id="${escapeHtml(latestDiagnosis.id)}">查看综合诊断</button>` : ""}
          ${hasEmptyFilteredView
            ? `<button class="button primary small" type="button" data-asset-next="all">查看全部报告</button>`
            : hasReports
            ? `<button class="button secondary small" type="button" data-asset-next="skill">继续诊断与路线</button>`
            : listingPending || articleHistoryError
              ? `<button class="button secondary small" type="button" data-asset-next="all">刷新报告列表</button>`
              : `<button class="button primary small" type="button" data-asset-next="analysis">执行综合诊断</button>`}
          ${selected ? `<button class="button secondary small" type="button" data-asset-next="current">回到当前报告</button>` : ""}
        </div>
      </div>
    `;

    elements.assetNextPanel.querySelectorAll("[data-asset-next]").forEach((button) => {
      button.addEventListener("click", () => handleAssetNextAction(button.dataset.assetNext, button.dataset.articleId));
    });
  }

  function assetWorkflowStats() {
    return scopedReportAssets().reduce((stats, article) => {
      const sourceType = String(article?.source_type || "").toLowerCase();
      if (sourceType === "geo_analysis") stats.diagnosis += 1;
      if (sourceType === "skill_execution") {
        if (isArticleContentAsset(article)) {
          stats.contentArticles += 1;
        } else {
          stats.skillResults += 1;
        }
      }
      return stats;
    }, { diagnosis: 0, skillResults: 0, contentArticles: 0 });
  }

  function scopedReportAssets() {
    const projectId = Number(state.selectedProject?.id);
    const candidates = [...state.articles, state.resultArticle].filter(article => article
      && Number(article.project_id) === projectId);
    return [...new Map(candidates.map(article => [Number(article.id), article])).values()];
  }

  function handleAssetNextAction(action, articleId = "") {
    if (action === "all") {
      state.assetSourceType = "all";
      state.selectedArticleId = null;
      if (state.selectedProject) loadArticles(state.selectedProject.id);
      return;
    }
    if (action === "analysis") {
      startAnalysisFromProject();
      return;
    }
    if (action === "skill") {
      state.skillContext = "strategy";
      openWorkspaceView("skill");
      setMessage(elements.skillMessage, "按诊断或路线结果，继续选择页面审计、效果监测等技能；要生成文章，去「内容 → 文章」点「写新文章」。");
      return;
    }
    if (action === "current" && state.selectedArticleId) {
      selectArticle(state.selectedArticleId);
      return;
    }
    const targetId = Number(articleId || 0);
    if (targetId) {
      selectArticle(targetId);
    }
  }

  function reportHistoryGroup(createdAt, now = new Date()) {
    const date = new Date(createdAt);
    if (!createdAt || !Number.isFinite(date.getTime())) return "日期未知";
    const day = d => `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`;
    const yesterday = new Date(now);
    yesterday.setDate(yesterday.getDate() - 1);
    return day(date) === day(now) ? "今天" : day(date) === day(yesterday) ? "昨天" : "更早";
  }

  function sortedHistoryReports(articles = state.articles) {
    const time = article => Date.parse(article.created_at) || 0;
    return articles.filter(article => !clIsArticle(article)).sort((a, b) => time(b) - time(a) || Number(b.id) - Number(a.id));
  }

  function renderArticleHistory() {
    if (!elements.assetHistory) return;
    elements.assetHistory.innerHTML = "";
    const reports = sortedHistoryReports();
    if (!reports.length) {
      const message = state.articlePage < state.articleLastPage ? "本页暂无报告，可以继续加载。" : state.assetSearch || state.assetSourceType !== "all" ? "没有匹配的报告，试试其他关键词或分类。" : "还没有历史报告，完成诊断后会显示在这里。";
      elements.assetHistory.innerHTML = `<div class="empty">${message}</div>`;
    }
    let previousGroup = "";
    for (const article of reports) {
      const group = reportHistoryGroup(article.created_at);
      if (group !== previousGroup) {
        const heading = document.createElement("h3");
        heading.className = "report-date-group";
        heading.textContent = group;
        elements.assetHistory.appendChild(heading);
        previousGroup = group;
      }
      const row = document.createElement("div");
      row.className = "report-list-row";
      row.innerHTML = articleHistoryMarkup(article);
      row.querySelector(".report-title-link")?.addEventListener("click", event => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button > 0) return;
        event.preventDefault();
        state.resultZone = "full";
        openResultPage(article.id);
      });
      row.querySelector("[data-history-download]")?.addEventListener("click", () => downloadArticle(article, elements.assetMessage));
      elements.assetHistory.appendChild(row);
    }
    renderArticleHistoryMore();
  }

  // 「加载更多」（步骤 4）。⚠️ 不能只在 reports 非空时画：这一页每页取 20 条**文章**，
  // 再在前端把可编辑文章滤掉，所以整整一页全是文章、一条报告都没有是可能的 ——
  // 那时候更需要这个按钮。
  function renderArticleHistoryMore() {
    if (state.articleLastPage <= state.articlePage) return;
    const label = `加载更多（已显示第 ${state.articlePage} / ${state.articleLastPage} 页）`;
    const more = document.createElement("button");
    more.type = "button";
    more.className = "button secondary small history-more";
    more.textContent = label;
    more.addEventListener("click", async () => {
      if (!state.selectedProject) return;
      more.disabled = true;
      more.textContent = "加载中...";
      try {
        await loadArticles(state.selectedProject.id, null, { append: true });
      } finally {
        // 成功时整块列表已经重画、这个按钮连同状态一起被换掉；失败时它还在，恢复可点。
        if (more.isConnected) {
          more.disabled = false;
          more.textContent = label;
        }
      }
    });
    elements.assetHistory.appendChild(more);
  }

  function articleHistoryMarkup(article) {
    const id = Number(article.id);
    return `
      <a class="report-title-link" href="#result/${id}">${escapeHtml(articleDisplayTitle(article))}</a>
      <span class="report-row-type">${escapeHtml(article.source_type === "geo_analysis" ? "综合诊断" : "技能报告")}</span>
      <time class="report-row-time">${escapeHtml(formatDate(article.created_at))}</time>
      <span class="report-row-status">${escapeHtml(statusLabel(article.status || "-"))}</span>
      <button type="button" class="report-row-download" data-history-download aria-label="下载 ${escapeHtml(articleDisplayTitle(article))}">下载</button>
    `;
  }

  function articleKindLabel(article) {
    const sourceType = String(article?.source_type || "").toLowerCase();
    if (sourceType === "geo_analysis") return "综合诊断报告";
    if (sourceType === "skill_execution") {
      return isArticleContentAsset(article) ? "内容文章" : "技能单项结果";
    }
    if (sourceType === "draft") return "草稿";
    return "手动内容";
  }

  function articleKindDescription(article) {
    const sourceType = String(article?.source_type || "").toLowerCase();
    if (sourceType === "geo_analysis") {
      return "这是项目综合诊断，可作为当前项目的整体结论。";
    }
    if (sourceType === "skill_execution" && isArticleContentAsset(article)) {
      return "这是内容类技能生成的可编辑文章，不是前面所有测试合并后的总建议。";
    }
    if (sourceType === "skill_execution") {
      return "这是某个技能的单项结果，用来支持后续动作；多个技能结果不会自动合并成总报告。";
    }
    if (sourceType === "draft") {
      return "这是草稿内容，需要继续编辑后再使用。";
    }
    return "这是手动创建或编辑的内容。";
  }

  function isArticleContentAsset(article) {
    if (isStructuredResultAsset(article)) return false;

    const sourceSkill = String(article?.source_skill || article?.source_execution?.skill_id || "").toLowerCase();
    const sourceType = String(article?.source_type || "").toLowerCase();
    const contentType = String(article?.content_type || "").toLowerCase();
    const title = String(articleDisplayTitle(article)).toLowerCase();
    const body = String(article?.body_markdown || "");
    const hasArticleShape = /^#\s+.+/m.test(body) && /^##\s+.+/m.test(body);
    const contentSkillTerms = [
      "title-optimizer",
      "explainer",
      "comparison",
      "ranking",
      "content-refiner",
      "article-friendly",
    ];
    const isManagedContent = ["explainer", "comparison", "ranking"].includes(contentType)
      || contentSkillTerms.some((term) => sourceSkill.includes(term));

    // 技能执行既会产出文章，也会产出诊断报告。报告正文同样有 H1/H2，不能仅凭
    // Markdown 形状塞进文章库；对技能资产以技能身份为准。
    if (sourceType === "skill_execution") return isManagedContent;

    return hasArticleShape
      || isManagedContent
      || (/文章|指南|实战|对比|排行榜/.test(title) && body.length > 120);
  }

  function isStructuredResultAsset(article) {
    const fields = [
      article?.summary,
      article?.body_markdown,
      article?.structured ? JSON.stringify(article.structured) : "",
    ];
    return fields.some((value) => {
      const parsed = parseStructuredResultFromText(value);
      return parsed && typeof parsed === "object" && hasStructuredResultKeys(parsed);
    });
  }

  function hasStructuredResultKeys(value) {
    if (!value || typeof value !== "object") return false;
    return [
      "analysis",
      "key_findings",
      "findings",
      "next_steps",
      "confidence",
      "overall_score",
      "dimensions",
      "phases",
      "intent_clusters",
      "recommendations",
    ].some((key) => Object.prototype.hasOwnProperty.call(value, key));
  }

  function assetFilterLabel(value) {
    const labels = {
      all: "全部",
      geo_analysis: "综合诊断",
      skill_execution: "技能报告",
    };
    return labels[String(value || "").toLowerCase()] || value || "当前筛选";
  }

  // ═══════════════════════════════════════════════
  // 资产详情：正文、结构化报告、下载与动作
  // ═══════════════════════════════════════════════

  async function selectArticle(articleId) {
    if (!articleId) return;
    state.selectedArticleId = Number(articleId);
    state.resultArticle = null;
    openResultPage(articleId);
  }

  // 原编辑/审核表单现在位于阅读页的可展开管理区，列表只负责查找报告。
  function renderArticleResult(article) {
    state.articles = state.articles.map(item => Number(item.id) === Number(article.id) ? { ...item, ...article } : item);
    renderArticleHistory();
    if (Number(state.resultArticle?.id) === Number(article.id)) {
      state.resultArticle = article;
      if (elements.resultPageMeta) elements.resultPageMeta.textContent = [
        article.project?.name || state.selectedProject?.name || "",
        articleKindLabel(article), formatDate(article.created_at), statusLabel(article.status || "-"),
      ].filter(Boolean).join(" · ");
    }
    const summary = articleSummaryText(article);
    elements.assetResult.innerHTML = `
      <div class="report-heading">
        <div>
          <h3>${escapeHtml(articleDisplayTitle(article))}</h3>
          <p class="muted">${escapeHtml(articleKindLabel(article))} · ${escapeHtml(statusLabel(article.status || "-"))} · ${escapeHtml(formatDate(article.created_at))}</p>
        </div>
      </div>
      ${summary ? `<p class="muted">${escapeHtml(summary)}</p>` : ""}
      <div id="articlePublishPanel"></div>
    `;
    elements.assetResult
      .querySelector('[data-asset-action="edit"]')
      ?.addEventListener("click", () => renderArticleEditor(article));
    elements.assetResult
      .querySelector('[data-asset-action="copy"]')
      ?.addEventListener("click", () => copyArticle(article, elements.resultPageMessage));
    elements.assetResult
      .querySelector('[data-asset-action="download"]')
      ?.addEventListener("click", () => downloadArticle(article, elements.resultPageMessage));
    elements.assetResult.querySelectorAll("[data-asset-detail-action]").forEach((button) => {
      button.addEventListener("click", () => handleAssetDetailAction(button.dataset.assetDetailAction, article));
    });
    renderArticlePublish(article);
  }

  function assetArticleNextPanel(article) {
    const sourceType = String(article?.source_type || "").toLowerCase();
    if (sourceType === "geo_analysis") {
      return `
        <div class="asset-action-card">
          <div>
            <span class="meta-label">这份综合诊断怎么用</span>
            <strong>把结论转成后续动作</strong>
            <p>先看关键发现和下一步行动；如果要继续落地，就去诊断与路线选择路线图、文章生成、页面审计或效果监测。</p>
          </div>
          <button class="button primary small" type="button" data-asset-detail-action="skill">进入诊断与路线</button>
        </div>
      `;
    }
    if (sourceType === "skill_execution" && isArticleContentAsset(article)) {
      return `
        <div class="asset-action-card">
          <div>
            <span class="meta-label">这篇文章下一步</span>
            <strong>审稿编辑，然后下载或发布</strong>
            <p>这类内容可以作为交付稿。建议先检查标题、结构和正文，再下载；发布前可回到诊断与路线做页面审计。</p>
          </div>
          <div class="asset-next-actions">
            <button class="button primary small" type="button" data-asset-detail-action="edit">编辑正文</button>
            <button class="button secondary small" type="button" data-asset-detail-action="download">下载正文</button>
            <button class="button secondary small" type="button" data-asset-detail-action="skill">做页面审计</button>
          </div>
        </div>
      `;
    }
    if (sourceType === "skill_execution") {
      return `
        <div class="asset-action-card">
          <div>
            <span class="meta-label">这个技能结果下一步</span>
            <strong>把单项结果当参考，不直接当最终交付</strong>
            <p>如果这是意图、诊断或路线结果，请用它决定下一步执行哪个内容、页面或监测技能。</p>
          </div>
          <button class="button primary small" type="button" data-asset-detail-action="skill">继续选择技能</button>
        </div>
      `;
    }
    return `
      <div class="asset-action-card">
        <div>
          <span class="meta-label">这份内容下一步</span>
          <strong>继续编辑或下载</strong>
          <p>手动内容可以直接编辑、复制或下载。</p>
        </div>
        <button class="button primary small" type="button" data-asset-detail-action="edit">编辑内容</button>
      </div>
    `;
  }

  function handleAssetDetailAction(action, article) {
    if (action === "edit") {
      renderArticleEditor(article);
      return;
    }
    if (action === "download") {
      downloadArticle(article);
      return;
    }
    if (action === "skill") {
      state.skillContext = "strategy";
      openWorkspaceView("skill");
      setMessage(elements.skillMessage, "请选择页面审计、效果监测等技能，把当前报告继续落地。");
    }
  }

  function renderArticleEditor(article) {
    const statusOptions = ["draft", "editing", "pending", "approved", "published"];
    elements.assetResult.innerHTML = `
      <div class="report-heading">
        <div>
          <h3>编辑 ${escapeHtml(articleDisplayTitle(article))}</h3>
          <p class="muted">${escapeHtml(articleKindLabel(article))}</p>
        </div>
      </div>
      <form class="form-grid article-edit-form" id="articleEditForm">
        <label class="span-2">
          标题
          <input name="title" maxlength="500" value="${escapeHtml(article.title || "")}">
        </label>
        <label class="span-2">
          摘要
          <textarea name="summary" rows="3" maxlength="2000">${escapeHtml(article.summary || "")}</textarea>
        </label>
        <label>
          状态
          <select name="status">
            ${statusOptions.map((status) => `<option value="${status}" ${status === article.status ? "selected" : ""}>${statusLabel(status)}</option>`).join("")}
          </select>
        </label>
        <label class="span-2">
          正文
          <textarea name="body_markdown" rows="14">${escapeHtml(article.body_markdown || "")}</textarea>
        </label>
        <div class="form-actions span-2">
          <button class="button primary" type="submit">保存</button>
          <button class="button secondary" type="button" data-asset-action="cancel-edit">取消</button>
          <p class="form-message" id="articleEditMessage"></p>
        </div>
      </form>
    `;
    elements.assetResult
      .querySelector("#articleEditForm")
      ?.addEventListener("submit", (event) => saveArticle(event, article.id));
    elements.assetResult
      .querySelector('[data-asset-action="cancel-edit"]')
      ?.addEventListener("click", () => renderArticleResult(article));
  }

  async function saveArticle(event, articleId) {
    event.preventDefault();
    if (!state.selectedProject) return;

    const form = event.currentTarget;
    const message = form.querySelector("#articleEditMessage");
    const data = new FormData(form);
    const payload = {
      title: String(data.get("title") || "").trim(),
      summary: String(data.get("summary") || "").trim(),
      status: String(data.get("status") || "editing"),
      body_markdown: String(data.get("body_markdown") || ""),
    };

    setMessage(message, "正在保存...");
    try {
      const article = await api(`/api/v1/articles/${articleId}`, {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      setMessage(elements.assetMessage, isArticleContentAsset(article) ? "文章已保存。" : "历史报告已保存。", "success");
      // 结果页缓存了整条 article（切页签时不重复请求）。这里改了同一份，
      // 缓存作废，否则回到 #result/<同一个 id> 看到的还是改之前的内容。
      if (state.resultArticle && Number(state.resultArticle.id) === Number(article.id)) {
        state.resultArticle = null;
      }
      await loadArticles(state.selectedProject.id, article.id);
    } catch (error) {
      setMessage(message, validationMessage(error), "danger");
    }
  }

  function applyAssetSearch(event) {
    event.preventDefault();
    state.assetSearch = String(elements.assetSearchInput?.value || "").trim();
    state.selectedArticleId = null;
    if (state.selectedProject) loadArticles(state.selectedProject.id);
  }

  function clearAssetSearch() {
    state.assetSearch = "";
    if (elements.assetSearchInput) elements.assetSearchInput.value = "";
    state.selectedArticleId = null;
    if (state.selectedProject) loadArticles(state.selectedProject.id);
  }

  // messageSlot：提示往哪个槽里写。默认是历史报告页那个槽（所有老调用方零变化）；
  // 独立结果页把自己的槽传进来，否则提示会写进一个藏着的面板，用户点了没反应。
  async function copyArticle(article, messageSlot = elements.assetMessage) {
    const text = articleReadableBody(article);
    if (!text || text === "{}") {
      setMessage(messageSlot, "这份内容暂时没有可复制正文。", "danger");
      return;
    }
    await copyText(text);
    setMessage(messageSlot, isArticleContentAsset(article) ? "文章已复制。" : "历史报告已复制。", "success");
  }

  async function downloadArticle(article, messageSlot = elements.assetMessage) {
    const readableBody = articleReadableBody(article);
    if (readableBody && shouldUseReadableArticleBody(article)) {
      downloadText(`geo-report-${article.project_id}-${article.id}.md`, readableBody);
      setMessage(messageSlot, "已下载整理后的报告正文。", "success");
      return;
    }

    try {
      const response = await fetch(article.download_url || `/api/v1/articles/${article.id}/download`, {
        credentials: "include",
        headers: authHeaders({ Accept: "*/*" }),
      });
      if (!response.ok) throw new Error(isArticleContentAsset(article) ? "无法下载这份文章。" : "无法下载这份历史报告。");
      const blob = await response.blob();
      downloadBlob(`geo-report-${article.project_id}-${article.id}.md`, blob);
      setMessage(messageSlot, isArticleContentAsset(article) ? "文章已下载。" : "历史报告已下载。", "success");
    } catch (error) {
      setMessage(messageSlot, error.message || (isArticleContentAsset(article) ? "无法下载这份文章。" : "无法下载这份历史报告。"), "danger");
    }
  }

  // ═══════════════════════════════════════════════
  // 回挂（拆分前这些都在 geo-entry.js 里）
  // ═══════════════════════════════════════════════

  // 主文件的薄转发与技能块要取这些实现。
  Object.assign(GEO, {
    ensureAssetReportList,
    intentReportFacts,
    normalizeIntentReport,
    renderStructuredReportHtml,
    renderCompactTitleReport,
    reportZoneOf,
    reportZoneSlice,
    reportZoneHasContent,
    isSecondaryReportKey,
    withReportSectionId,
    hasReportFieldValue,
    renderReportSlider,
    slideReportTo,
    currentSliderIndex,
    syncReportSliderState,
    renderMetricsBar,
    renderReportSections,
    renderTableObject,
    renderReportField,
    renderPairList,
    renderPointList,
    render2DArraySection,
    renderObjectArraySection,
    renderObjectArrayDL,
    renderObjectArrayOL,
    renderObjectArrayTable,
    renderExecutionGlance,
    renderExecutionOutput,
    renderReadableMarkdown,
    skillExecutionText,
    skillStructuredText,
    appendSkillSection,
    copySkillExecution,
    downloadSkillExecution,
    downloadSkillFormat,
    isArticleContentAsset,
    isStructuredResultAsset,
    hasStructuredResultKeys,
    loadArticles,
    reloadArticles,
    renderAssetControls,
    renderAssetNextPanel,
    assetWorkflowStats,
    handleAssetNextAction,
    reportHistoryGroup,
    sortedHistoryReports,
    renderArticleHistory,
    renderArticleHistoryMore,
    articleHistoryMarkup,
    articleKindLabel,
    articleKindDescription,
    assetFilterLabel,
    applyAssetSearch,
    clearAssetSearch,
    selectArticle,
    renderArticleResult,
    assetArticleNextPanel,
    handleAssetDetailAction,
    copyArticle,
    downloadArticle,
  });
})();
