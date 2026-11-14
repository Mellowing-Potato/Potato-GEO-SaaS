/* Scoped editor for the existing report result route. Mutates only the article PATCH fields. */
(() => {
  let state, api, tryParseJsonValue, structuredBodyCandidate, parseReportChunks, escapeHtml, fieldLabel, statusLabel, setMessage, validationMessage, clToast, renderResultPageLoaded;
  let resultEditorDraft = null;

  function openResultEditor(article, helpers) {
    ({ state, api, tryParseJsonValue, structuredBodyCandidate, parseReportChunks, escapeHtml, fieldLabel, statusLabel, setMessage, validationMessage, clToast, renderResultPageLoaded } = helpers);
    const host = document.getElementById("reportEditor");
    if (!article || !host) return;
    const body = String(article.body_markdown || "");
    const chunks = parseReportChunks(body);
    const candidate = chunks ? "" : structuredBodyCandidate(body);
    const json = candidate ? tryParseJsonValue(candidate) : null;
    const structuredOnly = !body.trim() && !chunks && !json && (tryParseJsonValue(article.structured) || (article.structured && typeof article.structured === "object"));
    const mode = chunks ? "chunks" : json && !Array.isArray(json) ? "json" : /<!--\s*chunk:/.test(body) ? "unsafe" : structuredOnly ? "readonly" : "markdown";
    const parts = chunks ? chunks.parts.map(part => structuredClone(part.value)) : mode === "json" ? [structuredClone(json)] : [];
    const fields = [];
    const input = (label, value, source, path, rows = 3) => {
      const index = fields.push({ source, path, value: String(value ?? "") }) - 1;
      return `<label>${escapeHtml(label)}<textarea name="report_field_${index}" rows="${rows}">${escapeHtml(String(value ?? ""))}</textarea></label>`;
    };
    const sections = [];
    parts.forEach((part, source) => {
      ["title", "report_title", "subtitle", "summary", "analysis_summary", "core_answer", "limitations", "conclusion"].forEach(key => {
        const labels = { title: "报告内标题", report_title: "报告内标题", subtitle: "副标题", summary: "报告摘要", analysis_summary: "分析摘要", core_answer: "核心回答", limitations: "限制与适用范围", conclusion: "结论" };
        if (typeof part[key] === "string") sections.push(input(labels[key], part[key], source, [key]));
      });
      if (!Array.isArray(part.sections)) return;
      part.sections.forEach((section, sectionIndex) => {
        if (!section || typeof section !== "object") return;
        const sectionFields = [];
        if (typeof section.title === "string") sectionFields.push(input("章节标题", section.title, source, ["sections", sectionIndex, "title"], 2));
        ["paragraphs", "bullets"].forEach(key => {
          if (!Array.isArray(section[key])) return;
          section[key].forEach((paragraph, index) => {
            if (typeof paragraph === "string") sectionFields.push(input(key === "paragraphs" ? `段落 ${index + 1}` : `条目 ${index + 1}`, paragraph, source, ["sections", sectionIndex, key, index], 4));
          });
        });
        if (sectionFields.length) sections.push(`<div class="report-edit-section"><h4>${escapeHtml(section.title || `章节 ${sectionIndex + 1}`)}</h4>${sectionFields.join("")}</div>`);
      });
    });
    const statusOptions = [...new Set([article.status, "draft", "editing", "pending", "approved", "published"].filter(Boolean))];
    host.innerHTML = `<h3>编辑报告</h3><p class="muted">编辑的内容保存到当前报告。表格及其他复杂字段保留原文；如需修改，使用高级原始数据入口。</p>
      <form id="resultEditForm"><label>标题<input name="title" maxlength="500" value="${escapeHtml(article.title || "")}" required></label>
      <label>摘要<textarea name="summary" rows="3" maxlength="2000">${escapeHtml(article.summary || "")}</textarea></label>
      <label>状态<select name="status">${statusOptions.map(status => `<option value="${status}" ${status === article.status ? "selected" : ""}>${statusLabel(status)}</option>`).join("")}</select></label>
      ${sections.join("")}
      ${mode === "markdown" ? `<label>Markdown 正文<textarea name="markdown_body" rows="18">${escapeHtml(body)}</textarea></label>` : ""}
      ${mode === "unsafe" ? `<p class="muted">检测到无法安全解析的分块结构。已保留原文，仅可通过高级入口修改正文。</p>` : ""}
      ${mode === "readonly" ? `<p class="muted">这份报告的结构化正文保存在当前接口不可编辑的字段中。这里只能安全修改标题、摘要和状态；章节与正文保留原文。</p>` : ""}
      ${mode === "chunks" || mode === "json" || mode === "unsafe" ? `<details><summary>高级：编辑原始数据</summary><p class="muted">请保留所有字段与 chunk 标记。原始数据和上方字段不能同时修改。</p><textarea name="raw_body" rows="18">${escapeHtml(body)}</textarea></details>` : ""}
      <div class="report-edit-actions"><button class="button primary" type="submit">保存报告</button><button class="button secondary" type="button" data-report-cancel>取消</button><span class="form-message" id="resultEditMessage" role="status"></span></div></form>`;
    resultEditorDraft = { article, body, chunks, candidate, mode, parts, fields, route: window.location.hash, initial: new FormData(host.querySelector("form")) };
    document.getElementById("resultPanel").classList.add("is-editing");
    host.hidden = false;
    host.querySelector("form").addEventListener("submit", saveResultEditor);
    host.querySelector("[data-report-cancel]").addEventListener("click", () => closeResultEditor());
    host.scrollIntoView({ block: "start" });
  }

  function resultEditorDirty() {
    const form = document.getElementById("resultEditForm");
    if (!form || !resultEditorDraft) return false;
    const current = new FormData(form);
    return [...current.entries()].some(([key, value]) => value !== resultEditorDraft.initial.get(key));
  }

  function closeResultEditor(force = false) {
    if (!force && resultEditorDirty() && !window.confirm("有未保存的修改，确定离开编辑吗？")) return false;
    document.getElementById("resultPanel")?.classList.remove("is-editing");
    const host = document.getElementById("reportEditor");
    if (host) { host.hidden = true; host.innerHTML = ""; }
    resultEditorDraft = null;
    return true;
  }

  function guardResultEditorNavigation() {
    if (!resultEditorDirty()) { closeResultEditor(true); return true; }
    if (window.confirm("有未保存的修改，确定离开编辑吗？")) { closeResultEditor(true); return true; }
    const url = new URL(window.location.href);
    url.hash = resultEditorDraft.route;
    history.replaceState(null, "", url);
    return false;
  }

  async function saveResultEditor(event) {
    event.preventDefault();
    const draft = resultEditorDraft;
    if (!draft) return;
    const form = event.currentTarget;
    const values = new FormData(form);
    const message = form.querySelector("#resultEditMessage");
    const rawBody = values.get("raw_body");
    const rawChanged = rawBody != null && rawBody !== draft.body;
    const editedFields = draft.fields.some((field, index) => values.get(`report_field_${index}`) !== field.value);
    if (rawChanged && editedFields) { setMessage(message, "请只修改上方字段或高级原始数据，不能同时修改。", "danger"); return; }
    let nextBody = draft.body;
    if (rawChanged) {
      if (draft.mode === "chunks" && !parseReportChunks(rawBody)) { setMessage(message, "分块 JSON 无法解析，修改未保存。", "danger"); return; }
      if (draft.mode === "json" && !tryParseJsonValue(rawBody)) { setMessage(message, "JSON 无法解析，修改未保存。", "danger"); return; }
      nextBody = rawBody;
    } else if (draft.mode === "markdown") {
      nextBody = String(values.get("markdown_body") || "");
    } else if (editedFields) {
      draft.fields.forEach((field, index) => {
        const value = String(values.get(`report_field_${index}`) ?? "");
        let target = draft.parts[field.source];
        field.path.slice(0, -1).forEach(key => { target = target[key]; });
        target[field.path.at(-1)] = value;
      });
      if (draft.mode === "chunks") {
        nextBody = draft.chunks.preamble + draft.chunks.parts.map((part, index) => {
          const modified = draft.fields.some((field, fieldIndex) => field.source === index && values.get(`report_field_${fieldIndex}`) !== field.value);
          return part.marker + (modified ? part.raw.replace(part.raw.trim(), JSON.stringify(draft.parts[index], null, 2)) : part.raw);
        }).join("");
      } else if (draft.mode === "json") {
        nextBody = draft.body.replace(draft.candidate, JSON.stringify(draft.parts[0], null, 2));
      }
    }
    const payload = { title: String(values.get("title") || "").trim(), summary: String(values.get("summary") || ""), status: String(values.get("status") || draft.article.status) };
    if (nextBody !== draft.body) payload.body_markdown = nextBody;
    const button = form.querySelector('[type="submit"]');
    button.disabled = true;
    setMessage(message, "正在保存…");
    try {
      const article = await api(`/api/v1/articles/${draft.article.id}`, { method: "PATCH", headers: { "Content-Type": "application/json" }, body: JSON.stringify(payload) });
      state.resultArticle = article;
      closeResultEditor(true);
      renderResultPageLoaded(article);
      clToast("报告已保存", "success");
    } catch (error) {
      setMessage(message, validationMessage(error), "danger");
    } finally { button.disabled = false; }
  }

  window.GeoReportEditor = { open: openResultEditor, dirty: resultEditorDirty, close: closeResultEditor, guard: guardResultEditorNavigation };
})();
