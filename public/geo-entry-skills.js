/* ============================================================================
   GEO 工作台 · 技能工作区（geo-entry-skills.js）

   从 public/geo-entry.js 搬出来的第九块（计划 §7 前端大文件拆分，拆的是"技能/报告"域的技能侧）。
   搬走的是技能工作区整条链路：

     技能目录（按阶段筛选）→ AI 辅助建议（种子关键词 / 竞品）→ 选技能
     → 执行说明表单（含 brief_fields 动态字段）→ 跑起来（入队 + 轮询 + 取消）
     → 执行结果卡（用量、质量警告、产物入口）→ 待跑/进行中条 → 「下一步」卡

   接入方式与前八块一致：主文件暴露 window.GEO，本文件消费它。**这一刀多一层**：
   主文件里还有二十多处调用这些函数（结果页的下一步卡、路由重绘、启动绑定…），
   逐个改调用点回归面太大，所以主文件保留同名薄转发（const name = (...args) =>
   globalThis.GEO?.name?.(...args)），实现在末尾挂回 window.GEO。

   ⚠️ 本文件必须在**其它拆出块之前**加载：内容库块（geo-entry-content-library.js）在
   自己加载时就解构了 articleDisplayTitle / openContentTools / openExistingArticles，
   那三个名字由本文件挂回 GEO。

   红线（与拆分前逐字一致）：
     - 不动任何 API 路径与轮询口径（执行状态 2500ms 级轮询、终态即停、切项目全停）；
     - 「正在跑的执行不能因为切页丢轮询」：stopAllExecutionPollers() 仍由主文件在切项目时调；
     - 失败一律显式报错（setMessage / 错误编号），绝不静默回落假数据。

   判据：tests/fixtures/ui/dom-fingerprint.json（30 视图，含 #skill 的 DOM/文本/API 指纹）
   + verify-ui-panels.mjs 的 F08 动态技能输入 + npm run test:js 契约测试。
   ============================================================================ */
(() => {
  const GEO = window.GEO;
  if (!GEO) {
    console.error('[geo-entry-skills] window.GEO 未就绪：请确认本文件在 geo-entry.js 之后加载');
    return;
  }
  const {
    state, elements, api, escapeHtml, formatNumber, formatDate, setMessage, validationMessage,
    confirmWorkspaceAction, openWorkspaceView, withBusyButton, errorNoText, fillErrorNo,
    clToast, clDebounce, config, workspaceViewMeta, clIsArticle, upsertProject,
    setActiveWorkspaceView,
    pendingIdempotencyRequest, clearPendingIdempotencyRequest, retainPendingIdempotencyRequest,
    // 报告渲染器住在主文件（第十刀才会搬），技能结果卡要用它渲染结构化输出。
    skillExecutionText, renderExecutionGlance, copySkillExecution, downloadSkillExecution, downloadSkillFormat,
    // 主文件里的技能状态常量与轮询表（第九刀随本文件共享）。
    STAGE_FULL_LABELS, executionPollers, skillExecutionsState,
    // 结果页 / 资产视图 / 项目详情侧的几个函数（它们还住在主文件，第十刀才会搬）。
    renderProjectDetail, selectArticle, loadArticles, runAnalysis,
    articleSummaryText, articleReadableBody, lineList, statusLabel, formatLabel,
  } = GEO;
  const skillFormDrafts = new Map();
  function skillDraftKey() {
    return [state.selectedProject?.id, state.selectedSkill?.id, state.skillReportReference?.id || "",
      state.skillReportQuestion?.question_id || ""].join(":");
  }
  function saveSkillDraft(form) {
    if (!form) return;
    const values = {};
    form.querySelectorAll('[name]').forEach(field => { values[field.name] = field.value; });
    skillFormDrafts.set(skillDraftKey(), values);
  }

  async function loadSkills() {
    setMessage(elements.skillMessage, "正在加载技能...");
    try {
      const body = await api(config.skillsEndpoint || "/api/v1/skills");
      state.skills = body.data || [];
      renderSkillWorkspace();
      // Skills and execution history load independently; either may arrive first.
      // Refresh the next-step actions when the skill catalogue arrives last.
      if (state.lastRenderedExecution) renderSkillExecutionResult(state.lastRenderedExecution);
      // 同上：技能加载成功时不播报「请选择一个技能并填写执行说明。」——
      // 下方 #skillBriefPanel 的占位符写的是「请选择一个技能，填写执行说明。」，
      // 两句话意思一样、相距 1230px。让顶上这条空掉收起，把这句留在真正要填的地方。
      // 「暂无可用技能」是真状态，保留。
      setMessage(elements.skillMessage, state.skills.length ? "" : "暂无可用技能。");
    } catch (error) {
      setMessage(elements.skillMessage, error.message || "无法加载技能。", "danger");
      return false;
    }
  }

  function renderSkillWorkspace() {
    if (!elements.skillPanel) return;
    renderSkillFilters();
    renderSkillList();
    // renderSkillSuggestions() 已随「AI 辅助建议」搬到 renderProjectDetail()（步骤 1）。
    if (state.selectedSkill) {
      renderSkillBriefForm(state.selectedSkill);
    }
  }

  // 2026-09-05：这里原本是「推荐动线」的 7 个函数（skillFlowSteps / renderSkillFlowPanel /
  // flowStepMarkup / findExecutionForFlowStep / executionMatchesFlowStep /
  // findSkillForFlowStep / selectFlowSkill），整块删除。它是前端自己写死的四步清单
  //（意图 → 全景 → 路线 → 落地），跟后端六阶段 WorkflowStage 是两套进度模型、永远对不齐；
  // 第 2 张卡的按钮还只有一句 scrollIntoView，长得像「去执行」但什么也没执行。
  // 进度现在只由六阶段讲一次：筛选标签跟着 stage_index 走，「下一步」卡见 nextStageAfterExecution()。

  // ═══════════════════════════════════════════════
  // AI 辅助建议：种子关键词 / 竞品 (P2)
  // ═══════════════════════════════════════════════

  // 这五个字段此前一律「只在当前为空时才填」。用意是别覆盖用户正在输入的内容，
  // 但切项目时旧值还在 → 判空为假 → 一个都不更新，于是 A 项目的品牌、官网、业务
  // 描述原样留在 B 项目的表单里（2026-08-19 老板实测：切回「孟德智达企业内部测试」
  // 后卡片仍显示「示例文旅 / 李白」）。
  //
  // 隐藏的 project_id 受害最重：它决定这次辅助建议扣谁的钱
  //（SkillExecutionController::chargeAssistUsage），停在旧项目上意味着人在 B 项目
  // 操作、账记在 A 项目头上。同租户内不越权，但归属是错的。
  //
  // 正解是分清两种情形：同一个项目内不覆盖用户输入（保留原意图），换了项目就整体
  // 重置——包括上一个项目的推荐结果卡和两条表单提示，否则 A 的推荐列表会挂在 B 的
  // 界面上，用户一点「填入项目关键词」就把 A 的结果写进 B。
  function renderSkillSuggestions() {
    // 守卫看的是两张表单，不是结果槽：两个结果槽都不在也要继续往下走 ——
    // 预填字段和换项目重置是这个函数的另一半活，跟结果卡在不在没关系。
    if (!elements.suggestKeywordsForm && !elements.suggestCompetitorsForm) return;
    const project = state.selectedProject;
    const kwForm = elements.suggestKeywordsForm;
    const cForm = elements.suggestCompetitorsForm;
    const projectId = project?.id ?? null;
    const projectChanged = String(state.suggestFormProjectId ?? "") !== String(projectId ?? "");

    if (projectChanged) {
      state.suggestFormProjectId = projectId;
      // ⚠️ 清的是两个槽，不是外层容器的 innerHTML —— 后者会把槽本身一起删掉，
      //    之后两个推荐函数就再也找不到自己的落点了。
      if (elements.skillSuggestKeywordsResult) elements.skillSuggestKeywordsResult.innerHTML = "";
      if (elements.skillSuggestCompetitorsResult) elements.skillSuggestCompetitorsResult.innerHTML = "";
      setMessage(document.getElementById("suggestKeywordsMessage"), "");
      setMessage(document.getElementById("suggestCompetitorsMessage"), "");
    }

    const settings = project?.settings || {};
    // projectChanged 时无条件写入（含写空，用于清掉上一个项目的残留）；
    // 同项目内维持「空了才填」，不打断用户正在输入的内容。
    const applyField = (form, name, value) => {
      const field = form?.querySelector(`[name="${name}"]`);
      if (!field) return;
      if (projectChanged || !field.value) field.value = value || "";
    };

    applyField(kwForm, "domain", project?.brand_url);
    applyField(cForm, "brand_name", project?.brand_name);
    applyField(cForm, "brand_url", project?.brand_url);
    applyField(cForm, "description", settings.product_description || settings.goals);
    applyField(cForm, "project_id", projectId);
  }

  async function suggestSkillKeywords(event) {
    event.preventDefault();
    const form = event.currentTarget;
    if (form.dataset.suggestBusy === "1") return;
    const message = form.querySelector("#suggestKeywordsMessage");
    const domain = String(form.querySelector('[name="domain"]').value || "").trim();
    if (!domain) { setMessage(message, "请输入业务领域/官网。", "danger"); return; }
    const payload = { domain, project_id: state.selectedProject?.id || null };
    let pendingRequest;
    form.dataset.suggestBusy = "1";
    setMessage(message, "AI 正在推荐关键词...");
    try {
      pendingRequest = pendingIdempotencyRequest("skill-suggest-keywords", payload);
      const body = await api("/api/v1/skills/suggest-keywords", {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-Assist-Attempt-Key": pendingRequest.key },
        // project_id 用于扣费收口（辅助建议也是真实模型调用，按当前项目显式扣点）
        body: JSON.stringify(payload),
      });
      clearPendingIdempotencyRequest(pendingRequest);
      const keywords = Array.isArray(body.keywords) ? body.keywords : [];
      const slot = elements.skillSuggestKeywordsResult;
      if (keywords.length === 0) {
        setMessage(message, "未返回关键词。", "warning");
        if (slot) slot.innerHTML = "";
        return;
      }
      setMessage(message, `推荐 ${keywords.length} 个种子关键词。`, "success");
      if (!slot) return;
      slot.innerHTML = `
        <div class="suggest-card">
          <div class="suggest-head">推荐种子关键词 <button class="md-btn md-btn--secondary md-btn--sm" type="button" data-suggest-action="use-keywords">填入项目关键词</button></div>
          <ul class="suggest-list">${keywords.map((k) => `<li>${escapeHtml(k)}</li>`).join("")}</ul>
        </div>`;
      slot.querySelector('[data-suggest-action="use-keywords"]')?.addEventListener("click", () => useSuggestedKeywords(keywords));
    } catch (error) {
      if (pendingRequest && !retainPendingIdempotencyRequest(error)) clearPendingIdempotencyRequest(pendingRequest);
      setMessage(message, error.message || "推荐失败。", "danger");
    } finally {
      form.dataset.suggestBusy = "0";
    }
  }

  async function suggestSkillCompetitors(event) {
    event.preventDefault();
    const form = event.currentTarget;
    if (form.dataset.suggestBusy === "1") return;
    const message = form.querySelector("#suggestCompetitorsMessage");
    const payload = {
      brand_name: String(form.querySelector('[name="brand_name"]').value || "").trim(),
      brand_url: String(form.querySelector('[name="brand_url"]').value || "").trim() || null,
      description: String(form.querySelector('[name="description"]').value || "").trim() || null,
      // 扣费归属只认当前选中的项目，不读 DOM 里的隐藏字段——隐藏字段是渲染产物，
      // 一旦漏同步就会把账记到上一个项目头上（推荐关键词那条一直是这么取的）。
      project_id: state.selectedProject?.id || null,
    };
    if (!payload.brand_name) { setMessage(message, "请输入品牌名称。", "danger"); return; }
    let pendingRequest;
    form.dataset.suggestBusy = "1";
    setMessage(message, "AI 正在推荐竞品...");
    try {
      pendingRequest = pendingIdempotencyRequest("skill-suggest-competitors", payload);
      const body = await api("/api/v1/skills/suggest-competitors", {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-Assist-Attempt-Key": pendingRequest.key },
        body: JSON.stringify(payload),
      });
      clearPendingIdempotencyRequest(pendingRequest);
      const competitors = Array.isArray(body.competitors) ? body.competitors : [];
      const slot = elements.skillSuggestCompetitorsResult;
      if (competitors.length === 0) {
        setMessage(message, "未返回竞品。", "warning");
        if (slot) slot.innerHTML = "";
        return;
      }
      setMessage(message, `推荐 ${competitors.length} 个竞品。`, "success");
      if (!slot) return;
      slot.innerHTML = `
        <div class="suggest-card">
          <div class="suggest-head">推荐竞品 <button class="md-btn md-btn--secondary md-btn--sm" type="button" data-suggest-action="use-competitors">填入项目竞品</button></div>
          <ul class="suggest-list">${competitors.map((c) => `<li>${escapeHtml(c)}</li>`).join("")}</ul>
        </div>`;
      slot.querySelector('[data-suggest-action="use-competitors"]')?.addEventListener("click", () => useSuggestedCompetitors(competitors));
    } catch (error) {
      if (pendingRequest && !retainPendingIdempotencyRequest(error)) clearPendingIdempotencyRequest(pendingRequest);
      setMessage(message, error.message || "推荐失败。", "danger");
    } finally {
      form.dataset.suggestBusy = "0";
    }
  }

  async function useSuggestedKeywords(keywords) {
    const project = state.selectedProject;
    if (!project || !await confirmWorkspaceAction("将用推荐关键词覆盖当前项目的目标关键词吗？")) return;
    const settings = { ...(project.settings || {}), target_keywords: keywords };
    saveProjectSettings(settings, "种子关键词已更新。");
  }

  async function useSuggestedCompetitors(competitors) {
    const project = state.selectedProject;
    if (!project || !await confirmWorkspaceAction("将用推荐竞品覆盖当前项目的竞品列表吗？")) return;
    const settings = { ...(project.settings || {}), competitors };
    saveProjectSettings(settings, "竞品列表已更新。");
  }

  async function saveProjectSettings(settings, successText) {
    const project = state.selectedProject;
    if (!project) return;
    try {
      const updated = await api(`/api/v1/projects/${project.id}`, {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ settings }),
      });
      upsertProject(updated);
      state.selectedProject = updated;
      renderProjectDetail(updated);
      setMessage(elements.settingsMessage, successText, "success");
      // 2026-09-04 拆版式 · 步骤 1：两个「填入项目…」按钮现在就在项目管理页上，
      // 而 #settingsMessage 长在默认收起的「编辑」表单里 —— 只播报到那儿等于没播报。
      // 项目档案里的值确实变了，但用户点完看不到任何确认。
      setMessage(elements.projectDetailMessage, successText, "success");
    } catch (error) {
      setMessage(elements.settingsMessage, error.message || "保存失败。", "danger");
    }
  }

  // 首次进入展示全部专项功能，项目阶段不再隐藏可用工具。
  // 用户显式选择的阶段筛选保留到切换项目。
  function effectiveSkillStage() {
    if (state.skillStage !== null) return state.skillStage;
    return "all";
  }

  function renderSkillFilters() {
    if (!elements.skillFilters) return;
    const stages = Array.from(new Set(
      state.skills
        .map((skill) => skill.stage_index)
        .filter((stage) => stage !== undefined && stage !== null && stage !== ""),
    // -1 = 贯穿全流程的运维技能，不是第一个阶段，排最后。
    )).sort((a, b) => (a < 0 ? 1 : b < 0 ? -1 : a - b));
    const filters = [{ key: "all", label: "全部" }, ...stages.map((stage) => ({ key: String(stage), label: stageLabel(stage) }))];
    const activeStage = effectiveSkillStage();
    elements.skillFilters.innerHTML = filters.map((filter) => `
      <button class="filter-chip" type="button" data-stage="${escapeHtml(filter.key)}" aria-pressed="${activeStage === filter.key ? "true" : "false"}">
        ${escapeHtml(filter.label)}
      </button>
    `).join("");
    elements.skillFilters.querySelectorAll("[data-stage]").forEach((button) => {
      button.addEventListener("click", () => {
        state.skillStage = button.dataset.stage || "all";
        renderSkillWorkspace();
      });
    });
  }

  function renderSkillList() {
    if (!elements.skillList) return;
    const skills = visibleSkills();
    elements.skillList.innerHTML = "";
    if (skills.length === 0) {
      elements.skillList.innerHTML = `<div class="empty">当前阶段没有技能。</div>`;
      return;
    }

    skills.forEach((skill) => {
      const skillLabel = skill.name || skill.scene_description || "GEO 工作流能力";
      const metaParts = [...new Set([
        categoryLabel(skill.category || skill.category_label || "技能"),
        stageLabel(skill.stage_index),
      ].filter(Boolean))];
      // 后端标了不可用就置灰、点不动，并把原因写在卡片上。
      // 不从列表里删掉：客户该知道有这个能力、只是暂时不能用，凭空少几个反而像产品缩水。
      const available = skill.available !== false;
      const hasDedicatedAction = Boolean(skill.action_path);
      const card = document.createElement("div");
      card.className = "skill-card";
      card.tabIndex = available ? 0 : -1;
      card.setAttribute("role", hasDedicatedAction ? "link" : "button");
      card.setAttribute("aria-current", state.selectedSkill?.id === skill.id ? "true" : "false");
      card.setAttribute("aria-disabled", available ? "false" : "true");
      card.dataset.disabled = available ? "false" : "true";
      card.innerHTML = `
        <span class="skill-card-top">
          <strong>${escapeHtml(skillLabel)}</strong>
          ${!available || hasDedicatedAction ? `<span class="badge" data-variant="${available ? "success" : "neutral"}">${available ? escapeHtml(skill.action_label || "进入") : "暂不可用"}</span>` : ""}
        </span>
        <span class="skill-card-meta">${escapeHtml(metaParts.join(" · "))}</span>
        <span class="skill-card-copy">${escapeHtml(
          available
            ? (skill.scene_description || skill.description || "执行这个 GEO 工作流技能。")
            : (skill.unavailable_reason || "该技能暂不可用。"),
        )}</span>
      `;
      if (available) {
        card.addEventListener("click", () => selectSkill(skill.id));
        card.addEventListener("keydown", (event) => {
          if (event.key === "Enter" || event.key === " ") {
            event.preventDefault();
            selectSkill(skill.id);
          }
        });
      }
      elements.skillList.appendChild(card);
    });
  }

  function visibleSkills() {
    let skills = state.skills;
    if (state.skillContext === "content") {
      skills = skills.filter((skill) => isContentProductionSkill(skill));
    } else {
      skills = skills.filter((skill) => skill.id !== "yao-geo-panorama-audit");
    }
    const stage = effectiveSkillStage();
    if (stage === "all") return skills;
    return skills.filter((skill) => String(skill.stage_index) === stage);
  }

  function isContentProductionSkill(skill) {
    const c = String(skill?.category || skill?.category_label || "").toLowerCase();
    return c === "geo-content-production" || c.includes("content-production");
  }

  function reportContinuationMarkup(article) {
    if (!article?.id || clIsArticle(article)) return "";
    const data = GEO.resultReportData?.(article);
    const questions = Array.isArray(data?.question_bank) ? data.question_bank : [];
    const choose = questions.length ? `<label>选择要回答的问题 <select data-report-question-select><option value="">请先选问题</option>${questions.map(row => `<option value="${escapeHtml(row.question_id || "")}">${escapeHtml(`${row.question_id || ""} · ${row.question || ""}`)}</option>`).join("")}</select></label>`
      : '<p class="hint">这份旧报告没有可解析的问题清单，请在写作表单中明确填写主题。</p>';
    return `<div class="report-continuation">
      <p>用这份报告准备内容，或回看当前项目已生成的文章。</p>
      ${choose}
      <div class="work-resume-links">
        <button class="button secondary small" type="button" data-report-write="${Number(article.id)}" data-writing-skill="yao-geo-explainer-builder">生成解释型文章</button>
        <button class="format-link" type="button" data-report-write="${Number(article.id)}">选择其他内容类型</button>
        <button class="format-link" type="button" data-open-existing-articles>查看已有文章</button>
      </div><p class="hint">报告是选题参考；平台尚未采样时，监测计划不能当作观测结果。确认问题与事实来源后再执行。</p>
    </div>`;
  }

  function reportReferenceText(skill) {
    const report = state.skillReportReference;
    if (!report || Number(report.project_id) !== Number(state.selectedProject?.id)
      || !isContentProductionSkill(skill)) return "";
    const data = GEO.resultReportData?.(report);
    const question = state.skillReportQuestion;
    const project = state.selectedProject || {};
    const lines = [
      `参考报告：${articleDisplayTitle(report)}（编号 ${report.id}）`,
      `所选问题：${question ? `${question.question_id} · ${question.question}` : "未从清单选择；请手工填写主题"}`,
      `目标意图：${question?.intent_type || "待填写"}`,
      `证据边界：${data?.limitations || "报告属于选题假设；未提供实测证据时不得写成平台观测结论。"}`,
      `来源：报告 #${report.id}；${project.brand_url || "项目官网未填写"}`,
      `项目品牌资料（待核实）：${[project.brand_name || project.name, project.settings?.product_description].filter(Boolean).join("；") || "未填写"}`,
    ];
    for (const [label, value] of [["独立问法", question?.standalone_rewrite], ["检索问法", question?.retrieval_rewrite], ["证据查询", question?.evidence_query], ["内容建议", question?.content_asset]]) {
      if (value) lines.push(`${label}：${String(value)}`);
    }
    const sources = Array.isArray(data?.evidence_sources) ? data.evidence_sources : [];
    sources.forEach(source => {
      const value = typeof source === "string" ? source : [source?.title || source?.source_name || source?.name,
        source?.url || source?.source_url, source?.note || source?.description].filter(Boolean).join(" · ");
      if (value) lines.push(`来源线索：${value}`);
    });
    if (Array.isArray(data?.ai_sampling_plan) && !data.ai_sampling_plan.some(row => row?.observed_at && row?.observed_result)) {
      lines.push("采样状态：M0 尚未采样；报告列出的平台仅为计划监测平台。");
    }
    const budget = 3500;
    const kept = [];
    let used = 0;
    for (const line of lines) {
      const size = Array.from(line).length + 1;
      if (used + size > budget) { kept.push("[参考材料已按完整字段截断；完整内容请查看原报告]"); break; }
      kept.push(line); used += size;
    }
    return kept.join("\n");
  }

  async function openContentTools(report = null, skillId = null, questionId = null) {
    const projectId = Number(state.selectedProject?.id);
    if (!projectId) return;
    if (report && Number(report.project_id) !== projectId) {
      throw new Error("报告与当前项目不一致，请先切换到报告所属项目。");
    }
    if (!state.skills.length) await loadSkills();
    if (Number(state.selectedProject?.id) !== projectId) return;
    state.skillReportReference = report;
    const questions = GEO.resultReportData?.(report)?.question_bank;
    state.skillReportQuestion = Array.isArray(questions)
      ? questions.find(row => String(row?.question_id) === String(questionId)) || null : null;
    state.skillContext = "content";
    state.skillStage = "all";
    state.selectedSkill = null;
    openWorkspaceView("skill");
    renderSkillExecutionPlaceholder();
    const skill = state.skills.find((item) => item.id === skillId && item.available !== false);
    if (skill) {
      goToSkill(skill.id);
      const topic = elements.skillBriefPanel?.querySelector('[name="topic"]');
      if (topic && state.skillReportQuestion && !topic.value.trim()) topic.value = state.skillReportQuestion.question || "";
    } else {
      if (elements.skillBriefPanel) elements.skillBriefPanel.innerHTML = '<p class="muted">请选择一种内容类型，再填写主题。已有文章可从上方“查看已有文章”打开。</p>';
      setMessage(elements.skillMessage, report ? "已选中参考报告。选择内容类型后，请核对所选问题和参考材料。" : "选择内容类型，填写主题后再执行生成。");
    }
  }

  function openExistingArticles(contentType = "") {
    state.clActiveTab = "all";
    state.clSearch = "";
    if (elements.clSearchInput) elements.clSearchInput.value = "";
    globalThis.GEO?.clSelectTab?.("all");
    openWorkspaceView(contentType ? `content-library/${contentType}` : "content-library");
  }

  document.addEventListener("click", async (event) => {
    const button = event.target.closest("[data-open-content-tools], [data-report-write], [data-open-existing-articles]");
    if (!button) return;
    event.preventDefault();
    if (button.hasAttribute("data-open-existing-articles")) { openExistingArticles(button.dataset.openExistingArticles); return; }
    if (button.disabled) return;
    button.disabled = true;
    const projectId = Number(state.selectedProject?.id);
    try {
      let report = null;
      if (button.dataset.reportWrite) {
        const body = await api(`/api/v1/articles/${Number(button.dataset.reportWrite)}`);
        report = body.data || body;
      }
      if (Number(state.selectedProject?.id) !== projectId) return;
      const questionId = button.dataset.questionId || button.closest('.report-continuation')?.querySelector('[data-report-question-select]')?.value || null;
      if (report && Array.isArray(GEO.resultReportData?.(report)?.question_bank) && GEO.resultReportData(report).question_bank.length && !questionId) {
        button.closest('.report-continuation')?.querySelector('[data-report-question-select]')?.focus();
        throw new Error("请先从完整问题清单选择一个问题，再进入写作。");
      }
      await openContentTools(report, button.dataset.writingSkill || null, questionId);
    } catch (error) {
      const slot = state.activeView === "result" ? elements.resultPageMessage : state.activeView === "asset" ? elements.assetMessage : elements.skillMessage;
      setMessage(slot, error.message || "无法打开写作入口，请稍后重试。", "danger");
    } finally { button.disabled = false; }
  });

  function executionProducesArticle(execution) {
    const skillId = String(execution?.skill_id || "").toLowerCase();
    const skill = state.skills.find((item) => String(item.id).toLowerCase() === skillId);
    if (skill) return isContentProductionSkill(skill);
    return ["explainer", "comparison", "ranking", "content-refiner", "article-friendly", "title-optimizer"]
      .some((term) => skillId.includes(term));
  }

  // Only toggle existing panels: do not rebuild the form or discard selected materials.
  function setSkillWorkspaceMode(mode) {
    state.skillWorkspaceMode = mode === "tools" ? "tools" : "diagnosis";
    const isContent = state.skillContext === "content";
    const showTools = isContent || state.skillWorkspaceMode === "tools";
    if (elements.skillWorkspaceTabs) {
      elements.skillWorkspaceTabs.hidden = isContent;
      elements.skillWorkspaceTabs.querySelectorAll("[data-skill-workspace-mode]").forEach((tab) => {
        const selected = tab.dataset.skillWorkspaceMode === state.skillWorkspaceMode;
        tab.setAttribute("aria-selected", String(selected));
        tab.tabIndex = selected ? 0 : -1;
      });
    }
    if (elements.skillDiagnosisBlock) elements.skillDiagnosisBlock.hidden = showTools;
    if (elements.skillToolsPanel) {
      elements.skillToolsPanel.hidden = !showTools;
      if (isContent) {
        elements.skillToolsPanel.removeAttribute("role");
        elements.skillToolsPanel.removeAttribute("aria-labelledby");
      } else {
        elements.skillToolsPanel.setAttribute("role", "tabpanel");
        elements.skillToolsPanel.setAttribute("aria-labelledby", "skillToolsTab");
      }
    }
  }

  function applySkillContext() {
    const isContent = (state.skillContext || "strategy") === "content";
    setSkillWorkspaceMode(state.skillWorkspaceMode);
    if (elements.skillFilters) elements.skillFilters.hidden = isContent;
    if (elements.skillContextBackLink) elements.skillContextBackLink.hidden = !isContent;
    if (isContent) state.skillStage = "all";
    // 2026-09-04 拆版式 · 步骤 1 连带删掉两段：
    //  ① `elements.skillSuggestSection.hidden = isContent;` —— AI 辅助建议已经搬到
    //     项目管理页，再按「技能页现在是内容上下文还是策略上下文」去隐藏它就是错的。
    //  ② 一段按 skillPanel 里**第一个** .panel-heading 改眉标和 h2 的死代码。
    //     走查批（2dc67dc）已经把它本来要改的那个面板头删了，此后它找到的是
    //     「AI 辅助建议」那个头（既没有 .eyebrow 也没有 h2，两个查询都是 null，
    //     所以一直什么也没做）。AI 辅助建议一搬走，第一个就变成**综合诊断**那个头，
    //     它有 <p class="eyebrow">综合诊断</p> —— 那段代码会把「综合诊断」四个字
    //     改写成「分析诊断」。页标题本来就由下面这段按 workspaceViewMeta 出，
    //     面板里不需要第二份。别再加回来。
    if (state.activeView === "skill") {
      const meta = workspaceViewMeta.skill;
      if (elements.workspaceViewEyebrow) elements.workspaceViewEyebrow.textContent = isContent ? "内容" : meta.eyebrow;
      if (elements.workspaceViewTitle) elements.workspaceViewTitle.textContent = isContent ? "AI生成文章" : meta.title;
      if (elements.workspaceViewDescription) {
        elements.workspaceViewDescription.textContent = isContent
          ? "挑一个写作技能生成文章，产出会自动进入「内容 → 文章」列表。"
          : meta.description;
      }
    }
    renderSkillList();
  }

  function stageLabel(stage) {
    if (stage === undefined || stage === null || stage === "") return "";
    const labels = {
      "-1": "运维",
      0: "研究",
      1: "策略",
      2: "内容",
      3: "发布",
      4: "监测",
      5: "迭代",
      operations: "运维",
      research: "研究",
      strategy: "策略",
      content: "内容",
      publishing: "发布",
      measurement: "监测",
      iteration: "迭代",
    };
    return labels[String(stage)] || `阶段 ${stage}`;
  }

  function workflowText(value) {
    const labels = {
      Research: "研究",
      Strategy: "策略",
      "Content production": "内容生产",
      "Publishing readiness": "发布准备",
      Measurement: "效果监测",
      Iteration: "迭代优化",
      "Find AI-search intent, seed topics, and competitor signals.": "挖掘 AI 搜索意图、种子话题和竞品信号。",
      "Diagnose GEO readiness and turn findings into a practical plan.": "诊断 GEO 准备度，把发现转成可执行计划。",
      "Create AI-citable content and knowledge assets.": "创建可被 AI 引用的内容和知识资产。",
      "Check page readiness before publishing or distribution.": "发布或分发前检查页面准备度。",
      "Track visibility, citations, and competitor movement.": "追踪可见度、引用率和竞品变化。",
      "Refresh weak content and repeat the highest-impact work.": "刷新薄弱内容，重复最高影响的工作。",
      "Start by mining customer questions and keyword intent.": "先挖掘客户问题和关键词意图。",
      "Run a panorama audit, then build the execution roadmap.": "先做一次综合诊断，再生成执行路线图。",
      "Generate or refine one article asset from the plan.": "根据计划生成或优化一篇文章。",
      "Audit the target page before publishing.": "发布前先审计目标页面。",
      "Generate a monitoring report for the project.": "为项目生成一份监测报告。",
      "Refine the weakest existing asset and rerun the loop.": "优化最薄弱的已有内容，再重新跑一轮。",
    };
    const key = String(value || "").trim();
    return labels[key] || value || "";
  }

  function categoryLabel(category) {
    const labels = {
      operations: "运维",
      research: "研究",
      strategy: "策略",
      content: "内容",
      publishing: "发布",
      measurement: "监测",
      "geo-research": "研究",
      "geo-strategy": "策略",
      "geo-content-production": "内容生产",
      "geo-page-technical": "页面技术",
      "geo-knowledge-assets": "知识资产",
      "geo-measurement": "效果监测",
      "geo-operations": "发布运维",
    };
    return labels[String(category || "").toLowerCase()] || category || "技能";
  }

  function executionDisplayName(execution) {
    return execution?.skill_name || execution?.title || "GEO 工作流结果";
  }

  function analysisDisplayName(analysis) {
    return analysis?.title || "综合诊断结果";
  }

  function articleDisplayTitle(article) {
    return article?.title || "未命名内容";
  }

  function selectSkill(skillId) {
    const selected = state.skills.find((skill) => skill.id === skillId) || null;
    if (selected?.action_path) {
      openSkillAction(selected);
      return;
    }
    state.selectedSkill = selected;
    if (selected) setSkillWorkspaceMode("tools");
    renderSkillWorkspace();
    renderSelectedSkillResult();
    if (state.selectedSkill) {
      setMessage(elements.skillMessage, "");
    }
  }

  // 独立业务能力不经过通用技能执行器。当前项目只作为入口上下文传递，
  // 目标页仍会通过受租户权限保护的 projects API 验证并解析项目名称。
  function openSkillAction(skill) {
    if (!skill?.action_path) return;
    const target = new URL(skill.action_path, window.location.origin);
    if (state.selectedProject?.id) {
      target.searchParams.set("project_id", String(state.selectedProject.id));
    }
    // 独立入口可以自带站内落点（如 #knowledge）。没有指定时才使用专页通用的
    // #sec-new；否则知识库入口会被覆盖成首页上不存在的锚点。
    if (!target.hash) target.hash = "sec-new";
    window.location.assign(target.toString());
  }

  // 项目管理页「使用 <技能名>」的落点。2026-09-05：这里原本自己抄了一遍 goToSkill()
  // 的前半段（选档位 → 选技能 → 重渲），唯独漏了最后那句 scrollIntoView，于是从项目页
  // 点过来会停在页顶——表单在一屏半以外，老板两次走查都卡在「下一步我应该点什么」。
  // 补一行滚动不够：同一个动作留两份实现，下次再加第三个入口照样漏。这里只保留本函数
  // 独有的两件事（把推荐来的技能塞进 state.skills、切视图），其余全部交给 goToSkill()。
  // ⚠️ setActiveWorkspaceView 必须排在 goToSkill 前面：面板还挂着 hidden 的时候
  //    scrollIntoView 量不到位置，会静默失效。
  function selectRecommendedSkill(skill) {
    if (!skill?.id) return;

    const registered = state.skills.find((item) => item.id === skill.id) || skill;
    if (!state.skills.some((item) => item.id === registered.id)) {
      state.skills = [registered, ...state.skills];
    }
    setActiveWorkspaceView("skill", { updateHash: true });
    goToSkill(registered.id);
  }

  function renderSkillBriefForm(skill) {
    if (!elements.skillBriefPanel) return;
    const fields = Array.isArray(skill.brief_fields) ? skill.brief_fields : [];
    const skillLabel = skill.name || skill.scene_description || "GEO 工作流能力";
    // 切换技能时清空上次显式选料，避免串味
    state.skillMaterialRefs = [];
    const reference = reportReferenceText(skill);
    elements.skillBriefPanel.innerHTML = `
      <div class="skill-brief-heading">
        <div>
          <p class="eyebrow">${escapeHtml(categoryLabel(skill.category || skill.category_label || "技能"))}</p>
          <h3>${escapeHtml(isContentProductionSkill(skill) ? `生成新的${skillLabel}` : skillLabel)}</h3>
        </div>
      </div>
      ${generatedArticlesEntry(skill)}
      <p class="muted">${escapeHtml(skill.effect_description || skill.description || "填写执行说明后运行这个技能。")}</p>
      <form class="form-grid skill-brief-form" id="skillBriefForm">
        ${fields.map((field) => fieldControl(skill, field)).join("")}
        ${reference ? `<div class="span-2"><strong>报告参考材料</strong><pre class="execution-output">${escapeHtml(reference)}</pre><p class="hint">来自所选问题和项目资料；请核对来源与证据边界。下方补充说明只保存你的要求。</p></div>` : ""}
        <label class="span-2" for="skillBriefQuery">
          <span>补充说明 <span class="field-optional">（可选）</span></span>
          <textarea id="skillBriefQuery" name="query" rows="3" maxlength="2000" placeholder="补充背景、偏好或执行要求"></textarea>
        </label>
        <div class="skill-material-field span-2" role="group" aria-labelledby="skillMaterialLabel">
          <span id="skillMaterialLabel">从素材选料 <span class="field-optional">（可选）</span></span>
          <div class="mat-picker-trigger">
            <button type="button" class="button secondary small" data-open-mat-picker aria-describedby="skillMaterialHelp">选择标题 / 关键词 / 作者</button>
            <div class="mat-picker-chips" id="skillMatChips"></div>
            <div class="hint" id="skillMaterialHelp">选中的素材随本次执行提交，并计入使用次数。标题填入主题，关键词填入目标关键词，作者填入作者名；不选素材时沿用表单内容和默认值。</div>
          </div>
        </div>
        <div class="form-actions span-2">
          <button class="button primary" type="submit">开始执行</button>
          <p class="form-message" id="skillBriefMessage"></p>
        </div>
      </form>
    `;
    const form = elements.skillBriefPanel.querySelector("#skillBriefForm");
    const draft = skillFormDrafts.get(skillDraftKey());
    if (draft) form?.querySelectorAll('[name]').forEach(field => {
      if (Object.prototype.hasOwnProperty.call(draft, field.name)) field.value = draft[field.name];
    });
    form?.addEventListener("input", () => saveSkillDraft(form));
    form?.addEventListener("change", () => saveSkillDraft(form));
    form?.addEventListener("submit", runSelectedSkill);
    elements.skillBriefPanel.querySelector("[data-open-mat-picker]")?.addEventListener("click", (event) => globalThis.GEO?.openMatPicker?.(event));
    globalThis.GEO?.matRenderSkillChips?.();
  }

  function generatedArticlesEntry(skill) {
    if (!isContentProductionSkill(skill)) return "";
    const type = ["explainer", "comparison", "ranking"].find((key) => String(skill.id).includes(key)) || "";
    const label = { explainer: "解释型文章", comparison: "对比型文章", ranking: "排名型文章" }[type] || "文章";
    return `<div class="skill-saved-entry"><button type="button" class="button secondary small" data-open-existing-articles="${type}">查看已生成的${label}</button><p class="hint">打开当前项目的已有文章，可查看、编辑和发布，无需重新生成。</p></div>`;
  }

  function matchingSkillExecution(executions, skill, selectedId) {
    const matching = executions.filter((item) => !skill || String(item.skill_id) === String(skill.id));
    return matching.find((item) => Number(item.id) === Number(selectedId))
      || matching.slice().sort((a, b) => Number(b.id) - Number(a.id))[0];
  }

  function renderSelectedSkillResult() {
    const execution = matchingSkillExecution(state.skillExecutions, state.selectedSkill, state.selectedExecutionId);
    state.selectedExecutionId = execution?.id || null;
    renderSkillPendingStrip();
    if (execution) renderSkillExecutionResult(execution);
    else renderSkillExecutionPlaceholder();
  }

  // namespace 只影响生成的 DOM id 前缀：技能面板用默认的 skillBrief，
  // 自动化任务表单传 "atBrief"，避免两个表单同时在 DOM 里时 id 撞车（2026-09-21）。
  function fieldControl(skill, field, namespace = "skillBrief") {
    const key = String(field.key || "");
    const label = field.label || key.replace(/_/g, " ");
    const visibleLabel = field.required ? `${label}（必填）` : `${label}（可选）`;
    const type = field.type || "text";
    const required = field.required ? "required" : "";
    const defaultValue = defaultBriefValue(key, skill);
    const value = readableValue(defaultValue) === "" && field.default !== undefined ? field.default : defaultValue;
    const span = type === "textarea" || key === "competitors" || key === "seed_keywords" ? " span-2" : "";
    const listKeys = ["competitors", "seed_keywords", "tracking_keywords", "target_keywords", "platforms"];
    const placeholder = briefFieldPlaceholder(key, field);
    const placeholderAttr = placeholder ? `placeholder="${escapeHtml(placeholder)}"` : "";
    const numberAttrs = type === "number" ? numericFieldAttrs(field) : "";
    const fieldId = `${namespace}-${encodeURIComponent(key)}`;
    const labelMarkup = `<span id="${fieldId}-label">${escapeHtml(visibleLabel)}</span>`;

    if (type === "textarea" || listKeys.includes(key)) {
      return `
        <label class="${span.trim()}">
          ${labelMarkup}
          <textarea id="${fieldId}" aria-labelledby="${fieldId}-label" name="${escapeHtml(key)}" data-field-type="${escapeHtml(type)}" data-list="${listKeys.includes(key) ? "true" : "false"}" rows="3" ${placeholderAttr} ${required}>${escapeHtml(readableValue(value))}</textarea>
        </label>
      `;
    }

    if (type === "select" && Array.isArray(field.options) && field.options.length > 0) {
      return `
        <label>
          ${labelMarkup}
          <select id="${fieldId}" aria-labelledby="${fieldId}-label" name="${escapeHtml(key)}" data-field-type="select" ${required}>
            ${field.options.map((option) => `<option value="${escapeHtml(option.value || option)}">${escapeHtml(option.label || option.value || option)}</option>`).join("")}
          </select>
        </label>
      `;
    }

    const inputType = ["url", "number"].includes(type) ? type : "text";
    const autocomplete = key === "brand_url" || inputType === "url" ? 'autocomplete="off"' : "";
    const hint = briefFieldHint(key, skill);
    return `
      <label class="${span.trim()}">
        ${labelMarkup}
        <input id="${fieldId}" aria-labelledby="${fieldId}-label" ${hint ? `aria-describedby="${fieldId}-help"` : ""} name="${escapeHtml(key)}" data-field-type="${escapeHtml(type)}" type="${inputType}" value="${escapeHtml(readableValue(value))}" ${placeholderAttr} ${autocomplete} ${numberAttrs} ${required}>
        ${hint ? `<span class="hint" id="${fieldId}-help">${escapeHtml(hint)}</span>` : ""}
      </label>
    `;
  }

  // 填之前就要知道这个字段意味着什么。
  //
  // 页面审计这条提示是有来历的：2026-09-06 之前它根本不抓网页，客户填什么都能"跑成功"，
  // 拿到一份凭空写的报告。现在它会真的把页面抓下来，抓不到就直接失败（不扣费），
  // 所以必须提前说清楚「要一个公网能打开的地址」，别让人填个占位符白点一次。
  function briefFieldHint(key, skill = null) {
    if (key === "url") {
      return "会真实抓取这个页面来做检查，所以要填公网上能直接打开的地址（不需要登录）。"
        + "还没有官网的话，可以先填任何一个已经发布出去的内容页；抓不到会明确说明原因，不会调用模型。";
    }
    if (skill?.id === "yao-geo-explainer-builder" && key === "topic") {
      return "写一个读者真正会问的问题，并聚焦一个行业或场景，例如：四川教培机构如何选择 GEO 服务。";
    }
    if (skill?.id === "yao-geo-explainer-builder" && key === "target_keyword") {
      return "写一个希望文章覆盖的真实搜索词，尽量带地区、行业或需求；只写“GEO 服务”范围太宽。";
    }
    return "";
  }

  function numericFieldAttrs(field) {
    const attrs = [];
    if (field.min !== undefined) attrs.push(`min="${escapeHtml(field.min)}"`);
    if (field.max !== undefined) attrs.push(`max="${escapeHtml(field.max)}"`);
    if (field.step !== undefined) attrs.push(`step="${escapeHtml(field.step)}"`);
    if (field.min !== undefined && Number(field.min) >= 0) attrs.push('inputmode="numeric"');

    return attrs.join(" ");
  }

  function briefFieldPlaceholder(key, field) {
    if (key === "topic") {
      return "例如：四川教培机构如何选择 GEO 服务";
    }
    if (key === "target_keyword") {
      return "例如：四川教培 GEO 服务";
    }
    if (key === "brand_url") {
      return field.required ? "请输入真实官网 URL" : "没有官网可留空；不要填写测试网址";
    }
    if (key === "url") {
      return "https://你的域名/要检查的页面";
    }
    if (key === "timeline_days") {
      return "留空默认 90 天；请输入 1-365 的正整数";
    }
    if (key === "competitors") {
      return "每行一个竞品名称或网址";
    }
    if (key === "seed_keywords" || key === "target_keywords" || key === "tracking_keywords") {
      return "每行一个关键词";
    }
    if (field.type === "textarea") {
      return "可选：补充背景、偏好或执行要求";
    }

    return "";
  }

  function defaultBriefValue(key, skill = null) {
    const project = state.selectedProject || {};
    const settings = project.settings || {};
    const defaults = {
      brand_name: project.brand_name || project.name || "",
      brand_url: project.brand_url || "",
      // 页面审计的「页面 URL」。整个注册表里只有它用 url 这个键（站点设计用的是别的键），
      // 所以拿项目官网预填不会串到别的技能上。
      url: project.brand_url || "",
      domain: settings.target_market || project.brand_name || "",
      competitors: settings.competitors || [],
      seed_keywords: settings.target_keywords || [],
      target_keyword: Array.isArray(settings.target_keywords) ? settings.target_keywords[0] || "" : "",
      // 解释型文章需要一个明确问题。项目只有品牌名时留空，避免把品牌名悄悄当成
      // 文章主题，生成一篇范围失控、却已经产生模型费用的内容。
      topic: (String(skill?.category || "").includes("content-production") ? state.skillReportQuestion?.question : null) || settings.goals || settings.product_description
        || (skill?.id === "yao-geo-explainer-builder" ? "" : project.brand_name || ""),
      tracking_keywords: settings.target_keywords || [],
      kb_name: project.name ? `${project.name} 知识库` : "",
    };
    return defaults[key] ?? "";
  }

  function readableValue(value) {
    return Array.isArray(value) ? value.join("\n") : String(value || "");
  }

  // 技能执行只剩一条路：投队列，前端轮询状态。
  //
  // 2026-09-05 删掉了同步执行那条路（原来表单上有「同步执行（等待结果）」和
  // 「后台执行（可离开）」两个按钮）。删的理由不是「异步更好」这种偏好：
  //   · 技能实跑 6~8 分钟，同步就是让浏览器干等 6~8 分钟，中途刷新 / 断网就白跑一次；
  //   · 而且同步路径在 420 秒会被 set_time_limit 掐断（SkillAgentRunnerService），
  //     等于让人等满七分钟再收一个超时 —— 钱照扣，结果拿不到；
  //   · 两个按钮长得一样重，客户没有任何依据去选，还得先读一段解释。
  // 后端 /api/v1/skills/execute（execution_mode='sync'）保留未动，只是前端不再走它。
  //
  // ⚠️ 保留 reportValidity()：这个函数现在是 submit 处理器，
  //    preventDefault() 会一并挡掉浏览器自己的必填校验，必须自己叫一次。
  function adoptClaimedSkillKey(request, claimedKey, ownerUserId, ownerTenantId) {
    if (!/^[A-Za-z0-9_.:-]{1,160}$/.test(String(claimedKey || ""))) {
      throw new Error("技能任务认领编号无效，本次未入队。");
    }
    if (Number(state.session?.user?.id) !== ownerUserId
      || Number(state.session?.tenant?.id) !== ownerTenantId) {
      throw new Error("账号已切换，本次未入队。");
    }
    if (request.key === claimedKey) return;
    try {
      const saved = JSON.parse(sessionStorage.getItem(request.storageKey) || "null");
      if (saved?.key !== request.key || saved.owner_user_id !== ownerUserId
        || saved.owner_tenant_id !== ownerTenantId) throw new Error("mismatched original key");
      saved.key = claimedKey;
      sessionStorage.setItem(request.storageKey, JSON.stringify(saved));
      if (JSON.parse(sessionStorage.getItem(request.storageKey) || "null")?.key !== claimedKey) {
        throw new Error("claimed key not stored");
      }
    } catch (_) {
      throw new Error("无法保存恢复的技能请求编号，本次未入队；请先核对原任务。");
    }
    request.key = claimedKey;
  }

  function confirmedQueuedSkillExecution(body, projectId, skillId, userId) {
    const execution = body?.execution;
    if (!Number.isSafeInteger(Number(execution?.id)) || Number(execution.id) < 1
      || Number(execution.project_id) !== projectId
      || Number(execution.user_id) !== userId
      || String(execution.skill_id || '') !== skillId) {
      throw Object.assign(new Error('服务端未返回可确认的原技能任务，本次结果待核验。'),
        { responseParseFailed: true });
    }
    return execution;
  }

  async function runSelectedSkill(event) {
    event.preventDefault();
    if (!state.selectedProject || !state.selectedSkill) return;

    const form = event.currentTarget;
    if (!form.reportValidity()) return;

    const submit = form.querySelector('button[type="submit"]');
    const message = form.querySelector("#skillBriefMessage");
    if (state.skillSubmitInFlight) {
      setMessage(message, "这次任务正在提交，请不要重复操作。", "warning");
      return;
    }
    state.skillSubmitInFlight = true;
    if (submit) submit.disabled = true;
    setMessage(message, "正在提交任务...");
    setMessage(elements.skillMessage, "任务正在入队...");

    // 必须在请求外生成一次：api() 的鉴权重试会复用下面这份 options，从而复用该键。
    const submittedProjectId = Number(state.selectedProject.id);
    const submittedSkillId = String(state.selectedSkill.id || "");
    const submittedUserId = Number(state.session?.user?.id);
    const submittedTenantId = Number(state.session?.tenant?.id);
    const payload = skillExecutionPayload(form);
    let pendingRequest;
    let claimReady = false;
    let body = null;

    try {
      pendingRequest = pendingIdempotencyRequest("skill-execution", payload);
      const claim = await api("/api/v1/skills/execute-async/claim", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ payload, request_key: pendingRequest.key }),
      });
      if (claim?.ok !== true || claim.object !== "skill_execution_claim") {
        throw new Error("技能任务认领失败，本次未入队。");
      }
      adoptClaimedSkillKey(pendingRequest, claim.request_key, submittedUserId, submittedTenantId);
      claimReady = true;
      body = await api(config.skillExecuteAsyncEndpoint || "/api/v1/skills/execute-async", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-Idempotency-Key": pendingRequest.key,
        },
        body: JSON.stringify(payload),
      });
      confirmedQueuedSkillExecution(body, submittedProjectId, submittedSkillId, submittedUserId);
      if (Number(state.session?.user?.id) !== submittedUserId
        || Number(state.session?.tenant?.id) !== submittedTenantId) {
        throw Object.assign(new Error("账号已切换，原技能任务待原账号核验。"),
          { responseParseFailed: true });
      }
      let seen;
      try {
        seen = await api("/api/v1/skills/execute-async/seen", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ request_key: pendingRequest.key }),
        });
      } catch (error) {
        error.responseParseFailed = true;
        throw error;
      }
      if (seen?.ok !== true || seen.object !== "skill_execution_seen") {
        throw Object.assign(new Error("技能已入队，送达确认待核验。"), { responseParseFailed: true });
      }
      if (Number(state.session?.user?.id) !== submittedUserId
        || Number(state.session?.tenant?.id) !== submittedTenantId) {
        throw Object.assign(new Error("账号已切换，原技能任务待原账号核验。"),
          { responseParseFailed: true });
      }
      clearPendingIdempotencyRequest(pendingRequest);
    } catch (error) {
      body = null;
      const retainKey = !claimReady || retainPendingIdempotencyRequest(error);
      if (pendingRequest && !retainKey) clearPendingIdempotencyRequest(pendingRequest);
      const friendly = !pendingRequest || !claimReady || error?.responseParseFailed ? error.message : retainKey && typeof error?.status !== "number"
        ? "连接在提交过程中中断。系统已保留本次请求编号；请用原内容再次提交，服务端会返回同一任务，不会重复执行。"
        : skillExecutionErrorMessage(error);
      setMessage(message, friendly, "danger");
      if (Number(state.selectedProject?.id) === submittedProjectId) {
        setMessage(elements.skillMessage, friendly, "danger");
      }
    } finally {
      state.skillSubmitInFlight = false;
      if (submit) submit.disabled = false;
    }

    if (!body) return;

    // 执行接口成功就已经完成入队。后面的列表/余额刷新即使失败，也不能把这次
    // 付费动作说成“提交失败”，否则用户会再次点击并创建另一条真实任务。
    const execution = body.execution;
    setMessage(message, "已开始执行，正在查询状态...", "success");
    const sameProject = Number(state.selectedProject?.id) === submittedProjectId;
    const sameSkill = String(state.selectedSkill?.id || "") === submittedSkillId;
    // 余额属于整个租户。即使用户在 POST 返回前切到了另一个项目，这笔扣点仍然
    // 真实发生，必须刷新当前页面上的余额，不能因为项目上下文变化而跳过。
    if (!sameProject) {
      return;
    }

    setMessage(elements.skillMessage, "已开始执行。可以继续做别的，结果会在下方「最新结果」里更新。", "success");
    if (execution) {
      upsertSkillExecution(execution);
      if (sameSkill) {
        state.selectedExecutionId = execution.id;
        renderSkillExecutionResult(execution);
        scrollSkillResultIntoView();
      }
    }

    try {
      await loadSkillExecutions(submittedProjectId, sameSkill ? execution?.id : state.selectedExecutionId);
    } catch (error) {
      if (Number(state.selectedProject?.id) !== submittedProjectId) return;
      // 列表请求失败时直接盯住 execute-async 已返回的 execution。它与原 POST 共用
      // 同一真实任务，不会补打一条付费请求。
      if (execution?.id) {
        renderSkillPendingStrip();
        pollSkillExecution(execution.id, {
          projectId: submittedProjectId,
          skillId: submittedSkillId,
        });
      }
      const refreshWarning = "任务已经成功入队，但状态列表刷新失败；系统仍在查询这条任务，请勿重复提交。";
      setMessage(elements.skillMessage, refreshWarning, "warning");
      setSkillBriefMessage(submittedSkillId, refreshWarning, "warning");
    }
  }

  function skillExecutionPayload(form) {
    const reference = reportReferenceText(state.selectedSkill);
    const fields = skillFieldsPayload(form);
    if (reference) {
      fields.report_reference = reference;
      fields.report_id = Number(state.skillReportReference.id);
      if (state.skillReportQuestion?.question_id) fields.report_question_id = String(state.skillReportQuestion.question_id);
    }
    return {
      skill_id: state.selectedSkill.id,
      project_id: state.selectedProject.id,
      fields,
      query: String(new FormData(form).get("query") || "").trim(),
      material_refs: state.skillMaterialRefs,
    };
  }

  function skillExecutionErrorMessage(error) {
    if (error.body?.action === "skill_execution_queue_failed") {
      return "技能任务无法入队，请稍后重试或联系支持。";
    }
    if (error.body?.action === "skill_execution_failed") {
      return error.message || "技能执行失败，请检查执行说明后重试。";
    }
    return error.message || "技能失败，请检查执行说明后重试。";
  }

  function skillFieldsPayload(form) {
    const data = new FormData(form);
    const fields = {};
    Array.from(form.elements).forEach((field) => {
      if (!field.name || field.name === "query") return;
      fields[field.name] = normalizeFieldValue(field, data.get(field.name));
    });
    return fields;
  }

  function normalizeFieldValue(field, value) {
    if (field.dataset.list === "true") return lineList(value);
    if (field.dataset.fieldType === "number" && value !== "") return Number(value);
    return String(value || "").trim();
  }

  async function loadSkillExecutions(projectId, selectId = null) {
    // 🔴 守卫此前挂在 #skillExecutionHistory 上，那个列表 2026-09-04 已经撤走。
    //    这次请求**必须留着**：上面的「未完成执行」窄条（renderSkillPendingStrip）和
    //    右侧「最新结果」都直接读 state.skillExecutions，删了请求两处都会空 ——
    //    正在跑 / 失败 / 被取消的执行在界面上就没有任何落脚点了（归档只在成功时发生）。
    //    2026-09-05 之前这里写的理由是「推荐动线要用」，动线已删，理由换成这两处。
    if (!projectId || !elements.skillPanel) return;
    const requestId = ++skillExecutionsState.request;
    const body = await api(`/api/v1/executions?project_id=${encodeURIComponent(projectId)}`);
    // 项目请求可能和用户切换项目并发。晚回来的旧项目列表不能覆盖当前项目。
    if (requestId !== skillExecutionsState.request
      || Number(state.selectedProject?.id) !== Number(projectId)) return;
    state.skillExecutions = body.data || [];
    const requestedTargetId = Number(selectId || state.selectedExecutionId || 0);
    const target = matchingSkillExecution(state.skillExecutions, state.selectedSkill, requestedTargetId);
    if (target) {
      // history 已返回完整 executionPayload，直接使用它，避免刷新后为当前在途任务
      // 同时发详情和状态两条请求。随后 resume 只负责一条状态轮询链。
      state.selectedExecutionId = target.id;
      renderSkillPendingStrip();
      renderSkillExecutionResult(target);
    } else {
      state.selectedExecutionId = null;
      renderSkillPendingStrip();
      renderSkillExecutionPlaceholder();
    }
    resumeSkillExecutionPolls(projectId);
  }

  // 点击处必须说话。
  // 09-05 走查现场：技能跑完了，页面看着像什么都没发生——因为轮询只把终态写进
  // 面板级消息（elements.skillMessage），而人盯着的是自己点「开始执行」的地方，
  // 那句话一直停在「已开始执行，正在查询状态...」，从头到尾没变过。
  // ⚠️ 只在表单还是同一个技能时才写：跑的六到八分钟里人可能已经切去别的技能，
  //    那时 #skillBriefMessage 是另一张表单的槽，写进去就成了张冠李戴。
  function setSkillBriefMessage(skillId, text, variant = "neutral") {
    const showing = String(state.selectedSkill?.id || "");
    if (!showing || showing !== String(skillId || "")) return;
    setMessage(document.querySelector("#skillBriefMessage"), text, variant);
  }

  function resumeSkillExecutionPolls(projectId) {
    if (Number(state.selectedProject?.id) !== Number(projectId)) return;
    state.skillExecutions
      .filter((execution) => isExecutionInFlight(execution?.status))
      .forEach((execution) => {
        if (executionPollers.has(execution.id)) return;
        pollSkillExecution(execution.id, {
          projectId,
          skillId: execution.skill_id,
        });
      });
  }

  function pollSkillExecution(executionId, context = {}) {
    if (!executionId) return;
    stopPollingExecution(executionId);
    // 开跑那一刻的技能。用它而不是 execution.skill_id 来判断表单归属，
    // 是因为查询本身失败时根本拿不到 execution——那种时候更需要在点击处报错。
    const startedSkillId = String(context.skillId || state.selectedSkill?.id || "");
    const startedProjectId = Number(context.projectId || state.selectedProject?.id || 0);
    if (!startedProjectId) return;

    // 用递归 setTimeout，等本次请求结束后再排下一次。async setInterval 在网络慢于
    // 2.5 秒时会让请求重叠，旧响应可能在终态之后回来，把页面又写回“执行中”。
    const poller = { timer: null, projectId: startedProjectId, failureCount: 0 };
    executionPollers.set(executionId, poller);

    const isCurrentPoller = () => executionPollers.get(executionId) === poller
      && Number(state.selectedProject?.id) === startedProjectId;

    const schedule = (delay = 2500) => {
      if (!isCurrentPoller()) return;
      poller.timer = window.setTimeout(tick, delay);
    };

    const tick = async () => {
      if (!isCurrentPoller()) return;
      try {
        const status = await api(`/api/v1/executions/${executionId}/status`);
        // stop / 换项目可能发生在 await 期间；此时丢弃旧响应。
        if (!isCurrentPoller()) return;
        poller.failureCount = 0;
        const execution = status.execution || status;
        const previous = state.skillExecutions.find((item) => Number(item.id) === Number(execution.id));
        const changed = skillExecutionStateChanged(previous, execution);
        upsertSkillExecution(execution);
        if (changed) renderSkillPendingStrip();
        const selected = Number(state.selectedExecutionId) === Number(execution.id);
        if (selected && changed) renderSkillExecutionResult(execution);

        if (status.is_terminal || isTerminalExecutionStatus(execution.status)) {
          stopPollingExecution(executionId);
          const message = execution.status === "completed"
            ? "异步技能执行已完成。"
            : execution.error_message || "异步技能执行已结束。";
          const done = execution.status === "completed";
          // 只有用户当前正在看的执行可以更新主结果提示；别的后台任务由未完成窄条
          // 反映状态，不能抢占当前选择。
          if (selected) {
            setMessage(elements.skillMessage, done ? "" : message, done ? "success" : "danger");
            setSkillBriefMessage(
              startedSkillId,
              done ? "执行完成，报告已生成，结果在下方。" : message,
              done ? "success" : "danger",
            );
          }
          if (Number(state.selectedProject?.id) === startedProjectId) {
            await loadSkillExecutions(startedProjectId, selected ? execution.id : state.selectedExecutionId);
            if (selected && execution.status === "completed") {
              await loadArticles(startedProjectId, execution.article_id);
            }
          }
        } else {
          // 长报告是分多次写出来的，一份要十来分钟。只写「执行中」的话，客户
          // 分不清是在写还是卡死了 —— 有进度就一起说出来。
          const relay = status.relay;
          const progress = relay && relay.total_steps > 0
            ? `（进度 ${relay.completed_steps}/${relay.total_steps}）`
            : "";
          const live = `技能${statusLabel(execution.status || "running")}${progress}...`;
          if (selected) {
            setMessage(elements.skillMessage, live);
            // 跑的过程中也要照实说，别让「正在查询状态」一挂就是八分钟。
            setSkillBriefMessage(startedSkillId, live);
          }
          schedule();
        }
      } catch (error) {
        if (!isCurrentPoller()) return;
        const selected = Number(state.selectedExecutionId) === Number(executionId);
        // 404 明确说明这条执行已经不可访问，可以停止；普通网络/网关瞬断只代表
        // “这次没查到状态”，后台 Job 不会因此停止，更不能诱导用户再点一次付费入口。
        if (Number(error?.status) === 404) {
          stopPollingExecution(executionId);
          const missing = "这条执行记录已不可用，请刷新执行列表或联系支持。";
          if (selected) {
            setMessage(elements.skillMessage, missing, "danger");
            setSkillBriefMessage(startedSkillId, missing, "danger");
          }
          return;
        }
        poller.failureCount += 1;
        const retryDelay = Math.min(30000, 2500 * (2 ** Math.min(poller.failureCount, 4)));
        const retrySeconds = Math.ceil(retryDelay / 1000);
        const failure = `状态查询暂时失败，后台任务仍会继续；${retrySeconds} 秒后自动重试，请勿重复提交。`;
        if (selected) {
          setMessage(elements.skillMessage, failure, "warning");
          setSkillBriefMessage(startedSkillId, failure, "warning");
        }
        schedule(retryDelay);
      }
    };

    tick();
  }

  function stopPollingExecution(executionId) {
    const poller = executionPollers.get(executionId);
    if (!poller) return;
    if (poller.timer) window.clearTimeout(poller.timer);
    executionPollers.delete(executionId);
  }

  function stopAllExecutionPollers() {
    Array.from(executionPollers.keys()).forEach((executionId) => stopPollingExecution(executionId));
  }

  function isTerminalExecutionStatus(status) {
    return ["completed", "failed", "cancelled"].includes(String(status || ""));
  }

  function upsertSkillExecution(execution) {
    if (!execution?.id) return;
    const index = state.skillExecutions.findIndex((item) => item.id === execution.id);
    if (index >= 0) {
      state.skillExecutions[index] = execution;
    } else {
      state.skillExecutions = [execution, ...state.skillExecutions];
    }
  }

  // 轮询响应没有变化时不要重建结果 DOM：重建会丢失文本选择、键盘焦点，
  // 也会让长结果区发生肉眼可见的跳动。updated_at 会在分段完成和终态落库时变化。
  function skillExecutionStateChanged(previous, next) {
    if (!previous) return true;
    const executionChanged = ["status", "updated_at", "error_message", "article_id", "token_usage", "duration_seconds"]
      .some((key) => String(previous?.[key] ?? "") !== String(next?.[key] ?? ""));
    const billingChanged = ["model_call_count", "recovery_stage"]
      .some((key) => String(previous?.billing?.[key] ?? "") !== String(next?.billing?.[key] ?? ""));
    return executionChanged || billingChanged;
  }

  // 窄条最多列几行。超出的只报个数——未完成的执行本来就该是少数，
  // 一次列十几条就不是「窄条」而是又一个长列表了。
  const PENDING_STRIP_LIMIT = 5;

  // 2026-09-04 拆版式 · 步骤 0：未完成执行窄条。
  // 只显示状态不是 completed 的执行（排队 / 进行中 / 失败 / 已取消）——它们**不在**
  // articles 表里（归档只在成功分支发生），撤掉「技能执行记录」列表之后，这里是
  // 它们在界面上唯一的落脚点，取消按钮也跟着搬到这里。
  // ⚠️ 排除 yao-geo-panorama-audit：全景诊断的执行归上面的综合诊断区管，
  //    与原 renderSkillExecutionHistory() 的口径一致。
  function renderSkillPendingStrip() {
    const strip = elements.skillPendingStrip;
    if (!strip) return;

    const pending = state.skillExecutions.filter((execution) => {
      if (String(execution?.skill_id || "").toLowerCase() === "yao-geo-panorama-audit") return false;
      return String(execution?.status || "").toLowerCase() !== "completed";
    });

    strip.innerHTML = "";
    strip.hidden = pending.length === 0;
    if (pending.length === 0) return;

    const head = document.createElement("div");
    head.className = "pending-strip-head";
    head.innerHTML = `
      <strong>未完成执行</strong>
        <span>这里保留待处理的执行记录。生成的报告在「历史报告」，内容文章在「文章」。</span>
    `;
    strip.appendChild(head);

    pending.slice(0, PENDING_STRIP_LIMIT).forEach((execution) => {
      const row = document.createElement("div");
      row.className = "pending-row";
      const button = document.createElement("button");
      button.type = "button";
      button.className = "pending-item";
      button.setAttribute("aria-current", state.selectedExecutionId === execution.id ? "true" : "false");
      button.innerHTML = `
        <strong>${escapeHtml(executionDisplayName(execution))}</strong>
        <span>${escapeHtml(skillExecutionStatusLabel(execution))} · ${escapeHtml(formatDate(execution.created_at))}</span>
      `;
      button.addEventListener("click", () => selectSkillExecution(execution.id, { scroll: true }));
      row.appendChild(button);
      // 取消入口一字不改地沿用 cancelExecution(id)：同一个判据、同一个二次确认。
      if (isExecutionCancellable(execution.status)) {
        const cancelBtn = document.createElement("button");
        cancelBtn.type = "button";
        cancelBtn.className = "button danger small pending-cancel";
        cancelBtn.textContent = "取消";
        cancelBtn.addEventListener("click", () => cancelExecution(execution.id));
        row.appendChild(cancelBtn);
      }
      strip.appendChild(row);
    });

    if (pending.length > PENDING_STRIP_LIMIT) {
      const more = document.createElement("p");
      more.className = "pending-more";
      more.textContent = `另有 ${pending.length - PENDING_STRIP_LIMIT} 条未完成执行没有列出。`;
      strip.appendChild(more);
    }
  }

  async function selectSkillExecution(executionId, { scroll = false, expectedProjectId = state.selectedProject?.id } = {}) {
    const execution = await api(`/api/v1/executions/${executionId}`);
    // 点击后立刻切项目、或旧项目请求晚回来时，不把旧执行画到新项目下面。
    if (Number(state.selectedProject?.id) !== Number(expectedProjectId)
      || Number(execution.project_id) !== Number(expectedProjectId)) return;
    // 显式查看旧记录时同步工具选择，后台轮询则不改变用户正在使用的工具。
    if (scroll) {
      const skill = state.skills.find((item) => item.id === execution.skill_id);
      if (skill) {
        state.skillContext = isContentProductionSkill(skill) ? "content" : "strategy";
        applySkillContext();
        goToSkill(skill.id);
      } else {
        state.selectedSkill = null;
        if (elements.skillBriefPanel) elements.skillBriefPanel.innerHTML = '<p class="muted">正在查看历史执行记录。请选择工具以新建任务。</p>';
      }
    }
    state.selectedExecutionId = execution.id || executionId;
    renderSkillPendingStrip();
    renderSkillExecutionResult(execution);
    if (scroll) {
      setSkillWorkspaceMode("tools");
      scrollSkillResultIntoView();
    }
  }

  function renderSkillExecutionResult(execution) {
    if (!elements.skillExecutionResult) return;
    if (state.selectedSkill && String(execution.skill_id) !== String(state.selectedSkill.id)) return;
    // 留一份给 loadSkills() 补渲用，见那里的注释。
    state.lastRenderedExecution = execution;
    const text = skillExecutionText(execution);
    const formats = Array.isArray(execution.available_formats) ? execution.available_formats : [];
    const cancellable = isExecutionCancellable(execution.status);
    const qualityBlockedDraft = execution?.quality_report?.passed === false && formats.length > 0;
    // 还没有产出时把复制/下载置灰。
    // copySkillExecution / downloadSkillExecution 里本来就有空值拦截，但它们把「没东西可复制」
    // 写进面板级消息 elements.skillMessage —— 离按钮十万八千里，点下去等于没反应。
    // 不摆一个只能拒绝人的按钮，比事后解释为什么拒绝更省事。
    // ⚠️ 用置灰不用隐藏：轮询每 2.5 秒重渲一次，隐藏会让工具条在结果到达那一刻跳一下。
    const hasText = Boolean(String(text || "").trim());
    const noTextAttr = hasText ? "" : ` disabled title="${escapeHtml("这次执行还没有产出文本")}"`;
    const billingLine = skillExecutionBillingLine(execution);
    const recoveryStage = String(execution?.billing?.recovery_stage || "");
    const billingWarning = Boolean(billingLine && recoveryStage);
    const executionError = execution.error_message && !qualityBlockedDraft;
    const qualityWarning = skillQualityWarning(execution);
    const alertBody = `${billingWarning ? billingLine : ""}${executionError ? `<p>${escapeHtml(execution.error_message)}</p>` : ""}${qualityWarning}`;
    const alertVariant = executionError ? "danger" : "warning";
    const status = String(execution?.status || "").toLowerCase();
    const badgeVariant = isExecutionInFlight(execution?.status) ? "neutral"
      : qualityWarning || recoveryStage || status === "billing_pending" ? "warning"
      : status === "completed" ? "success" : status === "failed" ? "danger" : "neutral";
    const artifactLine = execution.article_id || execution.article_url ? `${executionProducesArticle(execution) ? "已保存到文章库" : "已归档到历史报告"}。<button class="format-link" type="button" data-open-article="${escapeHtml(execution.article_id || "")}">${executionProducesArticle(execution) ? "打开文章" : "去历史报告列表"}</button>` : "";
    const reasons = {
      python: "未找到 Python", pdf_engine: "缺少 PDF 引擎", native_library: "缺少本地渲染库",
      pandoc: "未安装 Pandoc", python_docx: "未安装 python-docx", reportlab: "未安装 ReportLab",
      pdf_reader: "未安装 pypdf", python_markdown: "未安装 Markdown", beautifulsoup: "未安装 Beautiful Soup",
      python_dependency: "缺少 Python 组件", pdftoppm: "缺少 Poppler", pyftsubset: "缺少字体子集工具",
      html_fonts: "字体附件未通过检查", export_failed: "导出失败", missing_output: "未生成文件",
    };
    const formatNotes = ["markdown", "html", "docx", "pdf"].flatMap((format) => {
      const item = execution.format_status?.[format];
      if (!item || item.status === "available") return [];
      const detail = item.status === "unsupported" ? "此技能不提供" : reasons[item.reason] || "未生成文件";
      return [escapeHtml(formatLabel(format)) + "（" + escapeHtml(detail) + "）"];
    });
    const exportLine = formats.length ? `导出报告：${formats.map((format) => `<button class="format-link" type="button" data-format="${escapeHtml(format)}">${escapeHtml(formatLabel(format))}</button>`).join(" ")}` : "";
    elements.skillExecutionResult.innerHTML = `
      <div class="report-heading">
        <div>
          <h3>${escapeHtml(executionDisplayName(execution))}</h3>
          <p class="muted">${escapeHtml(formatDate(execution.created_at))} <span class="md-badge md-badge--${badgeVariant}">${escapeHtml(skillExecutionStatusLabel(execution))}</span></p>
        </div>
        <div class="report-actions">
          ${cancellable ? `<button class="button danger small" type="button" data-skill-action="cancel">取消执行</button>` : ""}
          <button class="button secondary small" type="button" data-skill-action="copy"${noTextAttr}>复制</button>
          <button class="button secondary small" type="button" data-skill-action="download"${noTextAttr}>下载文本</button>
        </div>
      </div>
      ${alertBody ? `<div class="md-alert md-alert--${alertVariant}">${alertBody}</div>` : ""}
      ${renderExecutionGlance(execution, text)}
      ${billingWarning ? "" : billingLine}
      ${artifactLine || exportLine ? `<p class="muted">${artifactLine}${exportLine}</p>` : ""}
      ${formatNotes.length ? `<p class="muted" data-format-status>${formatNotes.join(" · ")}</p>` : ""}
      ${skillNextActionPanel(execution)}
    `;
    elements.skillExecutionResult
      .querySelector('[data-skill-action="cancel"]')
      ?.addEventListener("click", () => cancelExecution(execution.id || executionId));
    elements.skillExecutionResult
      .querySelector('[data-skill-action="copy"]')
      ?.addEventListener("click", () => copySkillExecution(text));
    elements.skillExecutionResult
      .querySelector('[data-skill-action="download"]')
      ?.addEventListener("click", () => downloadSkillExecution(execution, text));
    elements.skillExecutionResult.querySelectorAll("[data-format]").forEach((button) => {
      button.addEventListener("click", () => downloadSkillFormat(execution, button.dataset.format));
    });
    elements.skillExecutionResult.querySelector("[data-skill-recheck]")?.addEventListener("click", () => {
      if (state.selectedProject?.id) loadSkillExecutions(state.selectedProject.id, execution.id);
    });
    elements.skillExecutionResult.querySelector("[data-skill-rerender]")?.addEventListener("click", async (event) => {
      const button = event.currentTarget;
      button.disabled = true;
      button.textContent = "正在重新排版…";
      try {
        const body = await api(`/api/v1/executions/${execution.id}/rerender`, { method: "POST" });
        renderSkillExecutionResult(body.execution);
        setMessage(elements.skillMessage, body.message, body.execution.status === "completed" ? "success" : "warning");
        if (state.selectedProject?.id) await loadSkillExecutions(state.selectedProject.id, execution.id);
      } catch (error) {
        button.disabled = false;
        button.textContent = "重新排版（复用内容）";
        setMessage(elements.skillMessage, error.message || "重新排版未完成，请刷新状态。", "danger");
      }
    });
    elements.skillExecutionResult.querySelector("[data-next-stage-skill]")?.addEventListener("click", (event) => {
      goToSkill(event.currentTarget.dataset.nextStageSkill);
    });
    // ⚠️ 这里要真的开跑，不是滚动。原推荐动线第 2 张卡的按钮只有一句 scrollIntoView，
    //    长得像「去执行」但什么也没执行 —— 这一批就是来根治它的，别再写成滚动。
    elements.skillExecutionResult.querySelector("[data-next-run-analysis]")?.addEventListener("click", () => runAnalysis());
    elements.skillExecutionResult.querySelectorAll("[data-open-article]").forEach((button) => {
      button.addEventListener("click", async () => {
        const articleId = Number(button.dataset.openArticle || execution.article_id || 0);
        if (!articleId) return;
        let isArticle = executionProducesArticle(execution);
        try {
          const article = await api(`/api/v1/articles/${articleId}`);
          isArticle = clIsArticle(article);
        } catch (_) {
          // 拿不到详情时按技能家族兜底分流
        }
        if (isArticle) {
          openWorkspaceView("content-library");
        } else {
          setActiveWorkspaceView("asset", { updateHash: true });
          selectArticle(articleId);
        }
      });
    });
  }

  function skillExecutionStatusLabel(execution) {
    if (execution?.billing?.recovery_stage === "known_chunk_identity_conflict") return "补写请求身份冲突，待受控恢复";
    if (execution?.billing?.recovery_stage === "model_result_recorded") return "交付待恢复";
    if (execution?.billing?.recovery_stage === "model_result_unrecorded") return "用量待核验";
    const formats = Array.isArray(execution?.available_formats) ? execution.available_formats : [];
    if (execution?.quality_report?.passed === true
      && execution?.quality_report?.publication_ready === false
      && formats.length > 0) {
      return "已生成编辑稿（待事实复核）";
    }
    if (String(execution?.status || "").toLowerCase() === "failed"
      && execution?.quality_report?.passed === false
      && formats.length > 0) {
      return "已生成编辑稿（质量未通过）";
    }
    return statusLabel(execution?.status_label || execution?.status || "-");
  }

  function skillExecutionBillingLine(execution) {
    const usage = execution?.billing;
    if (!usage || typeof usage !== "object") return "";
    const calls = Math.max(0, Number(usage.model_call_count) || 0);
    const stage = String(usage.recovery_stage || "");
    const note = stage === "known_chunk_identity_conflict" ? "请求身份冲突，需核对已保存结果；继续恢复可能产生新的模型调用。"
      : stage === "model_result_recorded" ? "模型结果已保存，正在恢复交付，不会重新调用模型。"
      : stage === "model_result_unrecorded" ? "模型响应已收到，本地用量待恢复；报告尚未交付。"
      : stage ? "原模型请求待核验，尚未交付；请勿重复发起。" : "";
    return `<p class="muted">已记录 ${calls.toLocaleString("zh-CN")} 次模型调用 · ${formatNumber(execution.token_usage)} tokens。${escapeHtml(note)}</p>`;
  }

  function skillQualityWarning(execution) {
    const report = execution?.quality_report;
    if (!report) return "";
    if (report.passed === true && report.publication_ready === false) {
      const count = Array.isArray(report.fact_review_items) ? report.fact_review_items.length : 0;
      const detail = count > 0 ? `，还有 ${count} 项事实或来源需要确认` : "";
      return `<p>文章结构和安全检查已经通过${escapeHtml(detail)}。当前内容已保存为编辑稿，请补充官方来源并完成人工复核后再发布。</p>`;
    }
    if (report.passed !== false) return "";
    const labels = {
      html_kami_fonts_packaged: "导出 HTML 没有打包本地字体",
      docx_long_tokens_wrapped: "Word 正文中的长链接尚未正确换行",
      brand_mentions_within_limit: "品牌提及次数超过文章设定",
      article_body_complete_length: "可发布正文长度不足",
      publishable_article_2000_to_4000_chars: "可发布正文不在 2000-4000 字范围内",
      publishable_article_available: "没有取得完整的可发布正文",
      publishable_article_matches_review: "最终正文尚未完成对应的质量检查",
      required_sections_present: "必需章节未全部生成",
      "required sections present": "必需章节未全部生成",
      "必需章节完整": "必需章节未全部生成",
      section_count_at_least_15: "报告章节少于 15 章",
      "section count at least 15": "报告章节少于 15 章",
      "报告章节至少 15 章": "报告章节少于 15 章",
      question_bank_at_least_18: "问题清单少于 18 条",
      "question bank at least 18": "问题清单少于 18 条",
      "问题清单至少 18 条": "问题清单少于 18 条",
      follow_up_chains_at_least_8: "追问链少于 8 条",
      "follow up chains at least 8": "追问链少于 8 条",
      "追问链至少 8 条": "追问链少于 8 条",
    };
    const failed = (Array.isArray(report.checks) ? report.checks : [])
      .filter((check) => check && check.passed === false)
      .map((check) => labels[check.name] || check.label || check.name)
      .filter(Boolean);
    const detail = failed.length ? `：${failed.join("；")}` : "";
    const metrics = report.metrics && typeof report.metrics === "object" ? report.metrics : null;
    const actual = metrics
      ? `当前生成 ${Math.max(0, Number(metrics.section_count) || 0)} 章、${Math.max(0, Number(metrics.question_bank_count) || 0)} 条问题、${Math.max(0, Number(metrics.follow_up_chain_count) || 0)} 条追问链。`
      : "";
    const missing = Array.isArray(report.missing_required_sections) && report.missing_required_sections.length
      ? `缺少章节：${report.missing_required_sections.join("、")}。`
      : "";
    return `<p>报告已经生成，但尚未达到正式交付标准${escapeHtml(detail)}。${escapeHtml(actual)}${escapeHtml(missing)}请补全并复核后再进入下一阶段。</p>`;
  }

  // 「在途」＝还没落终态。取消入口和「下一步」卡共用这一份判据：
  // 两处各写一份列表的话，日后后端加个新的中间状态，只会改到其中一处。
  function isExecutionInFlight(status) {
    const inFlight = ["queued", "pending", "running", "processing", "in_progress", "scheduled"];
    return inFlight.includes(String(status || "").toLowerCase());
  }

  function isExecutionCancellable(status) {
    return isExecutionInFlight(status);
  }

  async function cancelExecution(executionId) {
    if (!executionId) return;
    if (!await confirmWorkspaceAction("确定取消该执行吗？")) return;
    try {
      const body = await api(`/api/v1/executions/${executionId}/cancel`, { method: "POST" });
      setMessage(elements.skillMessage, body.message || "已取消执行。", "success");
      if (state.selectedProject) await loadSkillExecutions(state.selectedProject.id);
      if (state.selectedExecutionId === executionId) {
        await selectSkillExecution(executionId);
      }
    } catch (error) {
      setMessage(elements.skillMessage, error.message || "取消失败。", "danger");
    }
  }

  function renderSkillExecutionPlaceholder() {
    if (!elements.skillExecutionResult) return;
    state.lastRenderedExecution = null;
    elements.skillExecutionResult.innerHTML = `
      <div class="empty">
        <strong>${state.selectedSkill ? `当前没有可展示的${escapeHtml(state.selectedSkill.name || "该工具")}执行结果。` : "选择工具后，这里显示对应的执行结果。"}</strong>
        <p>已有内容以成果列表为准；没有执行记录不代表文章不存在。</p>
        ${state.selectedSkill ? generatedArticlesEntry(state.selectedSkill) : ""}
      </div>
    `;
  }

  function scrollSkillResultIntoView() {
    scrollToWithHeaderOffset(elements.skillExecutionResult);
  }

  const STAGE_NEXT_HINTS = {
    0: "先找清楚用户会怎么问，产出种子问题和意图清单。",
    1: "先做一次综合诊断看清现状，再用「执行路线图」把结论排成行动清单。",
    2: "按路线写解释型 / 对比型 / 排名型文章，或做页面蓝图和知识库。",
    3: "发布前做一次页面审计，再选站点模板发出去。",
    4: "跑效果监测，看内容发出去之后 AI 有没有引用。",
    5: "拿监测结果回头优化内容。",
  };

  // 「下一步」由六阶段推：本次执行落在第 N 阶段 → 往后找第一个还有可执行技能的阶段。
  // 2026-09-05 之前这里读的是前端写死的四步动线（意图/全景/路线/落地），
  // 跟后端六阶段是两套模型、永远对不齐。动线已删，这里改成认 stage_index。
  function nextStageAfterExecution(execution) {
    const raw = execution?.stage_index;
    const idx = Number(raw);
    // -1 是贯穿全流程的运维技能，不在阶段链上，不给它推下一步。
    if (raw === null || raw === undefined || !Number.isFinite(idx) || idx < 0) return null;
    for (let next = idx + 1; next <= 5; next += 1) {
      // 综合诊断（yao-geo-panorama-audit）不在技能列表里，它由上面的综合诊断区执行，
      // 所以第 1 阶段即使没有别的可选技能也要出卡 —— 那一步要做的就是它。
      const needsDiagnosis = next === 1 && state.analyses.length === 0;
      const skill = state.skills.find((item) => Number(item.stage_index) === next
        && item.available !== false
        && item.id !== "yao-geo-panorama-audit");
      if (needsDiagnosis || skill) {
        return {
          index: next,
          label: STAGE_FULL_LABELS[next] || stageLabel(next),
          hint: STAGE_NEXT_HINTS[next] || "",
          skill: needsDiagnosis ? null : skill,
          needsDiagnosis,
        };
      }
    }
    return null;
  }

  function skillNextActionPanel(execution) {
    if (isExecutionInFlight(execution?.status)) return "";
    const status = String(execution?.status || "").toLowerCase();
    if (status === "billing_pending" || status === "failed" || status === "cancelled") {
      if (execution.can_rerender) {
        return `<div class="skill-next-card"><div><span class="meta-label">当前任务</span><strong>重新排版已生成内容</strong><p>内容已经保留，Word 导出排版需要修复。重新排版复用原内容，不调用模型，复用已保存内容。</p></div><div class="skill-next-actions"><button class="button secondary small" type="button" data-skill-rerender>重新排版（复用内容）</button></div></div>`;
      }
      const known = execution?.billing?.recovery_stage === "known_chunk_identity_conflict";
      const label = known ? "等待运营核对并恢复这次补写" : status === "billing_pending" ? "等待原请求核验" : "检查失败原因和已保存结果";
      const hint = known ? "原父段已结算，不能重跑整单。可刷新状态；运营需要先执行只读预演，再决定是否继续缺失步骤。" : status === "billing_pending"
        ? "请保留当前执行编号，核对原请求后再处理。不要新建相同付费任务。" : "本次没有可交付文章。可查看错误和已保存文本，再决定是否重新执行。";
      const updated = execution?.updated_at ? new Date(execution.updated_at).toLocaleString("zh-CN", { timeZone: "Asia/Shanghai" }) : "未知";
      return `<div class="skill-next-card"><div><span class="meta-label">当前任务</span><strong>${label}</strong><p>${hint}最后更新：${escapeHtml(updated)}</p></div><div class="skill-next-actions"><button class="button secondary small" type="button" data-skill-recheck>刷新任务状态</button></div></div>`;
    }
    // 执行中不摆「下一步」（09-05 走查拍板）。
    // 这张卡的意思是「这一步完了，往下走」，可它还没完 —— 现场看到的就是
    // 状态写着「执行中」「暂时没有文本输出」，下面已经在请人去做第 3 步。
    // 点它不会取消当前执行，只是跳走，但它在说一件还没发生的事。
    if (status !== "completed" || (!execution.article_id && !execution.article_url)) return "";
    if (!executionProducesArticle(execution)) {
      return `<div class="skill-next-card"><div><span class="meta-label">下一步</span><strong>从报告选择问题进入写作</strong><p>报告是选题参考，文章尚未生成。打开报告的问题清单，选择具体问题并核对事实来源。</p></div></div>`;
    }

    const qualityFailed = execution?.quality_report?.passed === false;
    const factReviewPending = execution?.quality_report?.passed === true
      && execution?.quality_report?.publication_ready === false;
    const requiresReview = qualityFailed || factReviewPending;
    const nextStage = requiresReview ? null : nextStageAfterExecution(execution);
    if (requiresReview || !nextStage) return "";

    const nextButton = nextStage
      ? (nextStage.needsDiagnosis
          ? `<button class="button secondary small" type="button" data-next-run-analysis="1">执行综合诊断</button>`
          : (nextStage.skill
              ? `<button class="button secondary small" type="button" data-next-stage-skill="${escapeHtml(nextStage.skill.id)}">继续：${escapeHtml(nextStage.skill.name || nextStage.skill.id)}</button>`
              : ""))
      : "";
    return `
      <div class="skill-next-card">
        <div>
          <span class="meta-label">下一步</span>
          <strong>第 ${nextStage.index + 1} 步 · ${escapeHtml(nextStage.label)}</strong>
          <p>${escapeHtml(nextStage.hint)}</p>
        </div>
        <div class="skill-next-actions">
          ${nextButton}
        </div>
      </div>
    `;
  }

  // 顶栏是 sticky（header.topbar，实测高 84px、z-index 30），scrollIntoView({block:"start"})
  // 把目标顶到视口 0 位，正好整条压在顶栏底下。2026-09-05 实测：滚到「单项技能」之后
  // 那行标题一个字都看不见，人到了也不知道到了哪。所有「滚到某处」统一走这里，
  // 按顶栏当时的实测高度留净空（量不到才退回 84）。
  function scrollToWithHeaderOffset(node, gap = 16) {
    if (!node) return;
    const bar = document.querySelector("header.topbar");
    const offset = (bar ? bar.getBoundingClientRect().height : 84) + gap;
    const top = node.getBoundingClientRect().top + window.scrollY - offset;
    window.scrollTo({ top: Math.max(top, 0), behavior: "smooth" });
  }

  function goToSkill(skillId) {
    const skill = state.skills.find((item) => String(item.id) === String(skillId));
    if (!skill) return;
    state.skillStage = (skill.stage_index === undefined || skill.stage_index === null)
      ? "all"
      : String(skill.stage_index);
    renderSkillFilters();
    selectSkill(skill.id);
    scrollToWithHeaderOffset(elements.skillBriefPanel);
  }

  // ═══════════════════════════════════════════════
  // 注册与回挂（拆分前这些都在 geo-entry.js 里）
  // ═══════════════════════════════════════════════

  // 进视图时的数据加载：主文件统一按视图取（runViewLoaders），这里登记自己不写 if。
  // 「项目」页也要技能目录（项目详情里要按阶段推荐技能），原来主文件那行两个视图一起判。
  GEO.registerViewLoader("projects", () => (state.skills.length ? Promise.resolve() : loadSkills()));
  GEO.registerViewLoader("skill", async () => {
    const tasks = [];
    if (!state.skills.length) tasks.push(loadSkills());
    if (state.selectedProject?.id) tasks.push(loadSkillExecutions(state.selectedProject.id));
    const results = await Promise.allSettled(tasks);
    // 保持主文件的提示口径：谁失败就把**原始原因**原样抛回去（值是 false 时同样抛它，
    // 主文件会退化成通用文案 —— 与拆分前逐字一致）。
    const failed = results.find((r) => r.status === "rejected" || r.value === false);
    if (failed) throw failed.reason;
  });

  // 主文件的薄转发要取到这些实现（含内容库块加载时解构的三个）。
  Object.assign(GEO, {
    loadSkills,
    renderSkillWorkspace,
    renderSkillSuggestions,
    suggestSkillKeywords,
    suggestSkillCompetitors,
    effectiveSkillStage,
    reportContinuationMarkup,
    openContentTools,
    openExistingArticles,
    setSkillWorkspaceMode,
    applySkillContext,
    stageLabel,
    workflowText,
    analysisDisplayName,
    articleDisplayTitle,
    selectRecommendedSkill,
    fieldControl,
    runSelectedSkill,
    normalizeFieldValue,
    loadSkillExecutions,
    stopAllExecutionPollers,
    isTerminalExecutionStatus,
    renderSkillPendingStrip,
    renderSkillExecutionResult,
    nextStageAfterExecution,
    scrollToWithHeaderOffset,
  });
})();
