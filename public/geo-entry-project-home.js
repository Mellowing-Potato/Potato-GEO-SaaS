/* EXEC-10B: project home. Existing business actions stay in their owning modules. */
(() => {
  const GEO = window.GEO;
  if (!GEO) return;
  const { state, api, formatNumber } = GEO;
  let generation = 0;
  let home = null;
  let stageDrawerIndex = null;
  let stageDrawerKey = null;
  let stageDrawerTrigger = null;
  let stageActionBusy = false;
  const stageGoals = [
    "找到客户在 AI 搜索里会问的问题、种子话题和竞品信号。",
    "看清品牌在 AI 回答里的现状，定出要补的内容和页面。",
    "按执行路线图写出能被 AI 引用的文章，并通过人工审核。",
    "把通过审核的文章发到官网和外部渠道，铺好访问信标。",
    "追踪 AI 爬虫访问和智能回答里的提及情况。",
    "根据监测结果改写、补充内容，进入下一轮。",
  ];

  // ═══════════════════════════════════════════════
  // Project identity and display helpers
  function homeContext(project) {
    return JSON.stringify([state.session?.user?.id, state.session?.tenant?.id, project?.id]);
  }

  function isCurrentHome(key, request) {
    return home?.key === key && generation === request && homeContext(state.selectedProject) === key;
  }

  function homeText(id, value) {
    const node = document.getElementById(id);
    if (node) node.textContent = value;
  }

  function homeDate(value, short = false) {
    if (!value) return "";
    const date = new Date(value);
    if (!Number.isFinite(date.getTime())) return "";
    const month = String(date.getMonth() + 1).padStart(2, "0");
    const day = String(date.getDate()).padStart(2, "0");
    return short ? `${month}-${day}` : `${date.getFullYear()}-${month}-${day}`;
  }

  function renderProjectHomeMeta(project) {
    const parts = [];
    if (project.brand_url) {
      try { parts.push(new URL(project.brand_url).host); }
      catch { parts.push(String(project.brand_url).replace(/^https?:\/\//i, "").replace(/\/$/, "")); }
    }
    if (project.settings?.target_market) parts.push(project.settings.target_market);
    const created = homeDate(project.created_at);
    if (created) parts.push(`创建于 ${created}`);
    homeText("projectHomeMeta", parts.join(" · "));
    document.getElementById("projectHomeVisibilityLink").href = `/visibility?project_id=${encodeURIComponent(project.id)}`;
    document.getElementById("projectHomeCrawlerLink").href = `/analytics?project_id=${encodeURIComponent(project.id)}`;
  }

  // ═══════════════════════════════════════════════
  // Project metrics and isolated article snapshot
  async function readHomeVisibility(project, key, request) {
    try {
      const body = await window.GEO_SHELL?.readProjectVisibility?.(project.id);
      if (!isCurrentHome(key, request)) return;
      if (!body || !Array.isArray(body.data) || (body.meta?.total == null || !Number.isFinite(Number(body.meta.total)))) throw new Error("unavailable");
      const total = Number(body.meta.total);
      homeText("projectHomeVisibilityValue", total > 0 ? formatNumber(total) : "—");
      const completed = homeDate(body.data[0]?.completed_at, true);
      homeText("projectHomeVisibilityDelta", total === 0 ? "还没有监测" : completed ? `最近一次 ${completed}` : "");
    } catch {
      if (!isCurrentHome(key, request)) return;
      homeText("projectHomeVisibilityValue", "—");
      homeText("projectHomeVisibilityDelta", "读取失败");
    }
  }

  async function readHomeCrawler(project, key, request) {
    try {
      const [week, fortnight] = await Promise.all([
        api("/api/v1/analytics/crawler-breakdown?days=7"),
        api("/api/v1/analytics/crawler-breakdown?days=14"),
      ]);
      if (!isCurrentHome(key, request)) return;
      const visits = Number(week?.kpis?.ai_bot_pv);
      const combined = Number(fortnight?.kpis?.ai_bot_pv);
      if (week?.kpis?.ai_bot_pv == null || fortnight?.kpis?.ai_bot_pv == null || !Number.isFinite(visits) || !Number.isFinite(combined) || visits < 0 || combined < visits) throw new Error("unavailable");
      const previous = combined - visits;
      homeText("projectHomeCrawlerValue", formatNumber(visits));
      const percent = previous > 0 ? Math.round((visits - previous) / previous * 100) : null;
      homeText("projectHomeCrawlerDelta", percent === null ? "" : `较前 7 天 ${percent >= 0 ? "↑" : "↓"} ${Math.abs(percent)}%`);
    } catch {
      if (!isCurrentHome(key, request)) return;
      homeText("projectHomeCrawlerValue", "—");
      homeText("projectHomeCrawlerDelta", "读取失败");
    }
  }

  async function readHomeArticles(project, key, request, articleRequest = 0) {
    try {
      const result = await GEO.clFetchAll({ projectId: project.id, search: "", contentType: "" });
      if (!isCurrentHome(key, request) || home.articleRequest !== articleRequest) return;
      home.articles = result.rows.filter(row => Number(row.project_id) === Number(project.id) && GEO.clIsArticle(row) && row.status !== "recycled");
      home.articlesFailed = false;
      home.truncated = result.truncated;
      const published = home.articles.filter(row => row.status === "published");
      const now = new Date();
      const month = published.filter(row => {
        const date = new Date(row.published_at);
        return row.published_at && date.getFullYear() === now.getFullYear() && date.getMonth() === now.getMonth();
      }).length;
      homeText("projectHomePublishedValue", `${formatNumber(published.length)}${result.truncated ? "+" : ""}`);
      homeText("projectHomePublishedDelta", `本月新增 ${formatNumber(month)} 篇`);
    } catch {
      if (!isCurrentHome(key, request) || home.articleRequest !== articleRequest) return;
      home.articles = [];
      home.articlesFailed = true;
      homeText("projectHomePublishedValue", "—");
      homeText("projectHomePublishedDelta", "读取失败");
    }
    if (isCurrentHome(key, request) && home.articleRequest === articleRequest) {
      home.articlesLoading = false;
      GEO.renderProjectHomeQueue?.();
    }
  }

  // ═══════════════════════════════════════════════
  // Prioritized actions and existing article navigation
  function projectHomeQueueItems(project, articles, timeline = []) {
    const items = [];
    const partial = home?.truncated ? "+" : "";
    const blocked = articles.filter(row => row.distribution_blocked_reason);
    const pending = articles.filter(row => row.status === "pending");
    const returned = articles.filter(row => row.status === "editing" && row.reviewed_at);
    if (blocked.length) items.push({
      kind: "blocked", tone: "danger", tag: "发布被拦",
      title: blocked.length === 1 ? `《${blocked[0].title}》没有发布出去` : `${blocked.length}${partial} 篇文章没有发布出去`,
      why: String(blocked[0].distribution_blocked_reason).trim().split(/[。！？\n]/)[0],
      action: "看原因", article: blocked[0], tab: "all",
    });
    if (pending.length) items.push({
      kind: "pending", tone: "warning", tag: "待审核", title: `${pending.length}${partial} 篇文章等你审核`,
      why: pending.slice(0, 2).map(row => `《${row.title}》`).join("、"), action: "去审核", tab: "pending",
    });
    for (const article of returned) items.push({
      kind: "returned", tone: "danger", tag: "被退回", title: `《${article.title}》被退回修改`,
      why: article.review_notes || "没有填写退回意见", action: "看退回意见", article, tab: "editing",
    });
    const current = GEO.resolveCurrentStageIndex(project);
    const recommendation = (project.workflow_recommendations || []).find(row => Number(row.stage_index) === current);
    const completed = (recommendation?.completed_skill_ids || []).map(String);
    const stage = timeline.find(row => Number(row.stage_index) === current);
    const names = Array.isArray(stage?.skills) ? stage.skills : [];
    const matched = names.map(name => state.skills.find(skill => skill.name === name || skill.id === name)).filter(Boolean);
    const unrun = matched.filter(skill => !completed.includes(String(skill.id)));
    const requirements = Array.isArray(project.current_stage_requirements?.requirements) ? project.current_stage_requirements.requirements : [];
    const met = project.current_stage_requirements?.met === true;
    if (project.status !== "completed") {
      const needed = unrun.length ? unrun : !met ? matched.slice(0, 1) : [];
      needed.forEach((skill, index) => items.push({
        kind: "stage", tone: "primary", tag: GEO.STAGE_FULL_LABELS[current], title: `执行「${skill.name}」`,
        why: index === 0 && requirements.length ? requirements.join("；") : "这项技能还没有运行记录。",
        action: "执行", skill,
      }));
    }
    const settings = project.settings || {};
    const fields = [["品牌", project.brand_name], ["官网", project.brand_url], ["目标市场", settings.target_market],
      ["产品说明", settings.product_description], ["目标关键词", settings.target_keywords], ["竞品", settings.competitors], ["目标", settings.goals]];
    const missing = fields.filter(([, value]) => !GEO.hasDossierValue(value)).map(([label]) => label);
    if (missing.length) items.push({
      kind: "profile", tone: "neutral", tag: "项目档案", title: `项目档案还差 ${missing.length} 项`,
      why: missing.join("、"), action: "去补全",
    });
    if (met && project.status !== "completed") items.push({
      kind: current < 5 ? "advance" : "complete", tone: "neutral", tag: "阶段",
      title: current < 5 ? `可以推进到「${GEO.STAGE_FULL_LABELS[current + 1]}」` : "可以完成项目",
      why: current < 5 ? "推进后仍可回来补做" : "已满足完成条件", action: current < 5 ? "推进" : "完成项目",
    });
    return items;
  }

  function openHomeArticle(article, tab, trigger) {
    GEO.openExistingArticles();
    document.querySelector(`#clTabs [data-tab="${tab}"]`)?.click();
    if (article) void GEO.clOpenDrawer(article, trigger);
  }

  function runProjectHomeQueueItem(item, trigger) {
    if (item.article || item.kind === "pending") openHomeArticle(item.article, item.tab, trigger);
    else if (item.skill) GEO.selectRecommendedSkill(item.skill);
    else if (item.kind === "profile") {
      GEO.openWorkspaceView("projects/settings");
      if (document.getElementById("settingsWrap")?.hidden) document.getElementById("editProjectButton")?.click();
    } else if (item.kind === "advance" || item.kind === "complete") void GEO.requestProjectStageAdvance();
  }

  function renderProjectHomeQueue() {
    if (!home) return;
    const container = document.getElementById("projectHomeQueue");
    if (!container) return;
    const items = projectHomeQueueItems(home.project, home.articles, home.timeline || []);
    home.queue = items;
    const shown = home.queueExpanded ? items : items.slice(0, 5);
    const escape = GEO.escapeHtml;
    container.innerHTML = shown.map((item, index) => `<li class="queue-item" data-queue-kind="${item.kind}">
      <span class="md-badge md-badge--${item.tone}">${escape(item.tag)}</span>
      <div class="qi-text"><div class="qi-title">${escape(item.title)}</div><div class="qi-why">${escape(item.why)}</div></div>
      <button type="button" class="md-btn md-btn--${index === 0 ? "primary" : "secondary"} md-btn--sm" data-project-queue-action="${index}">${escape(item.action)}</button>
    </li>`).join("");
    container.hidden = items.length === 0;
    document.getElementById("projectHomeQueueEmpty").hidden = items.length > 0 || home.articlesLoading || home.timelineLoading || home.articlesFailed || home.timelineFailed || home.skillsFailed;
    homeText("projectHomeQueueCount", `按紧急度排序 · 共 ${items.length} 项`);
    const note = document.getElementById("projectHomeArticleNotice");
    note.textContent = home.articlesFailed ? "文章数据读取失败，文章相关待办暂未列出。" : home.articlesLoading ? "文章数据正在读取…" : home.truncated ? "文章最多读取 500 条，数量后“+”表示至少这些。" : "";
    note.hidden = !note.textContent;
    const stageNote = document.getElementById("projectHomeStageNotice");
    stageNote.textContent = home.timelineFailed ? "阶段任务读取失败，请刷新重试。" : home.skillsFailed ? "技能清单读取失败，暂时无法打开技能。" : home.timelineLoading ? "阶段任务正在读取…" : "";
    stageNote.hidden = !stageNote.textContent;
    const toggle = document.getElementById("projectHomeQueueToggle");
    toggle.hidden = items.length <= 5;
    toggle.textContent = home.queueExpanded ? "收起" : `再看 ${items.length - 5} 项`;
  }

  function syncProjectHomeArticles() {
    if (!home || homeContext(state.selectedProject) !== home.key) return;
    home.articlesLoading = true;
    const request = generation;
    const key = home.key;
    const articleRequest = (home.articleRequest || 0) + 1;
    home.articleRequest = articleRequest;
    void readHomeArticles(home.project, key, request, articleRequest);
    GEO.reloadProjectHomeActivity?.();
  }

  document.getElementById("projectHomeQueue")?.addEventListener("click", event => {
    const button = event.target.closest("[data-project-queue-action]");
    if (!button || !home) return;
    const item = home.queue?.[Number(button.dataset.projectQueueAction)];
    if (item) runProjectHomeQueueItem(item, button);
  });
  document.getElementById("projectHomeQueueToggle")?.addEventListener("click", () => {
    if (!home) return;
    home.queueExpanded = !home.queueExpanded;
    renderProjectHomeQueue();
  });

  // ═══════════════════════════════════════════════
  // Project-filtered recent activity
  async function readProjectHomeActivity(project, key, request, activityRequest) {
    try {
      const body = await api(`/api/v1/dashboard/activity?project_id=${encodeURIComponent(project.id)}&limit=8`);
      if (!isCurrentHome(key, request) || home.activityRequest !== activityRequest) return;
      if (!Array.isArray(body?.data)) throw new Error("unavailable");
      home.activity = body.data.filter(row => Number(row.project_id) === Number(project.id))
        .sort((a, b) => new Date(b.updated_at || b.created_at) - new Date(a.updated_at || a.created_at)).slice(0, 8);
      home.activityFailed = false;
    } catch {
      if (!isCurrentHome(key, request) || home.activityRequest !== activityRequest) return;
      home.activity = [];
      home.activityFailed = true;
    }
    if (!isCurrentHome(key, request) || home.activityRequest !== activityRequest) return;
    renderProjectHomeActivity();
  }

  function renderProjectHomeActivity() {
    if (!home) return;
    const list = document.getElementById("projectHomeActivity");
    if (!list) return;
    const escape = GEO.escapeHtml;
    list.innerHTML = (home.activity || []).map((row, index) => {
      const execution = row.type === "execution";
      const tone = execution ? ({ completed: "success", failed: "danger", running: "primary", processing: "primary", pending: "primary" }[row.status] || "neutral") : row.status === "pending" ? "warning" : "neutral";
      const text = execution ? `${row.skill_name} · ${GEO.statusLabel(row.status)}` : `《${row.title}》· ${row.status === "pending" ? "待审核" : "编辑中"}`;
      const date = new Date(row.updated_at || row.created_at);
      const time = Number.isFinite(date.getTime()) ? `${homeDate(date)} ${String(date.getHours()).padStart(2, "0")}:${String(date.getMinutes()).padStart(2, "0")}` : "";
      const link = execution ? '<a class="md-link" href="#asset">查看报告</a>' : `<button type="button" class="md-link" data-project-activity-article="${index}">查看</button>`;
      return `<li class="tl-item"><time class="tl-at">${escape(time)}</time><span class="tl-dot" data-tone="${tone}" aria-hidden="true"></span><span class="tl-text">${escape(text)}</span>${link}</li>`;
    }).join("");
    list.hidden = !home.activity?.length;
    const empty = document.getElementById("projectHomeActivityEmpty");
    empty.hidden = !!home.activity?.length;
    homeText("projectHomeActivityEmptyTitle", home.activityFailed ? "动态读取失败" : "这个项目还没有动态");
  }

  function reloadProjectHomeActivity() {
    if (!home || homeContext(state.selectedProject) !== home.key) return;
    const activityRequest = (home.activityRequest || 0) + 1;
    home.activityRequest = activityRequest;
    void readProjectHomeActivity(home.project, home.key, generation, activityRequest);
  }

  document.getElementById("projectHomeActivity")?.addEventListener("click", event => {
    const button = event.target.closest("[data-project-activity-article]");
    const row = button && home?.activity?.[Number(button.dataset.projectActivityArticle)];
    if (row) openHomeArticle({ ...row, title: row.title, id: row.id }, row.status === "pending" ? "pending" : "editing", button);
  });

  // ═══════════════════════════════════════════════
  // Six stages, timeline and guarded drawer actions
  function currentProjectStage(project = home?.project) {
    return Number(GEO.resolveCurrentStageIndex?.(project) ?? project?.current_stage ?? 0);
  }

  function projectTimelineStage(index) {
    return home?.timeline?.find(stage => Number(stage.stage_index) === Number(index));
  }

  function projectStageName(index) {
    return GEO.STAGE_FULL_LABELS?.[index] || projectTimelineStage(index)?.stage_name || GEO.stageLabel?.(index) || `阶段 ${Number(index) + 1}`;
  }

  function projectStageSkills(index) {
    const names = projectTimelineStage(index)?.skills;
    if (!Array.isArray(names)) return [];
    return names.map(name => ({
      name: String(name),
      skill: state.skills?.find(skill => skill.name === name || skill.id === name) || null,
    }));
  }

  function projectStageState(index) {
    const stage = projectTimelineStage(index) || home?.project?.stages?.find(row => Number(row.stage_index) === index);
    if (home?.project?.status === "completed" || stage?.status === "completed" || stage?.status === "done") return "done";
    return index === currentProjectStage() ? "current" : "todo";
  }

  function stageNode(tag, className = "", text = "") {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text) node.textContent = text;
    return node;
  }

  function renderProjectHomeStages() {
    const rail = document.getElementById("pjStageRail");
    if (!rail || !home) return;
    rail.replaceChildren();
    for (let index = 0; index < 6; index++) {
      const stateName = projectStageState(index);
      const button = stageNode("button", "stage-btn");
      button.type = "button";
      button.dataset.projectStage = String(index);
      button.dataset.state = stateName;
      button.setAttribute("aria-controls", "projectStageDrawer");
      if (index === currentProjectStage()) button.setAttribute("aria-current", "step");
      button.setAttribute("aria-pressed", String(stageDrawerKey === home.key && stageDrawerIndex === index));
      const bar = stageNode("progress", "stage-bar");
      bar.max = 100;
      const progress = projectTimelineStage(index)?.progress;
      bar.value = stateName === "done" ? 100 : stateName === "current"
        ? progress != null && Number.isFinite(Number(progress)) ? Math.max(0, Math.min(100, Number(progress))) : 40 : 0;
      bar.setAttribute("aria-hidden", "true");
      const name = stageNode("span", "stage-name");
      name.append(stageNode("span", "ix", stateName === "done" ? "✓" : String(index + 1)), stageNode("span", "", projectStageName(index)));
      button.append(bar, name, stageNode("span", "stage-state", stateName === "done" ? "已完成" : stateName === "current" ? "进行中" : "未开始"));
      rail.append(button);
    }
    if (stageDrawerKey === home.key && stageDrawerIndex !== null) renderProjectStageDrawer();
  }

  function stageTime(value) {
    if (!value) return "尚无记录";
    const date = new Date(value);
    return Number.isFinite(date.getTime()) ? date.toLocaleString("zh-CN", { hour12: false }) : "尚无记录";
  }

  function renderProjectStageDrawer() {
    const body = document.getElementById("projectStageDrawerBody");
    const actions = document.getElementById("projectStageDrawerActions");
    if (!home || stageDrawerKey !== home.key || stageDrawerIndex === null || !body || !actions) return;
    const index = stageDrawerIndex;
    const stage = projectTimelineStage(index);
    const isCurrent = index === currentProjectStage();
    const stateName = projectStageState(index);
    homeText("projectStageDrawerTitle", `阶段 ${index + 1} · ${projectStageName(index)}`);
    homeText("projectStageDrawerSub", stateName === "done" ? "已完成" : isCurrent ? "当前阶段" : "未开始");
    body.replaceChildren(stageNode("p", "stage-goal", stageGoals[index]));
    if (home.timelineLoading) {
      body.append(stageNode("p", "md-status", "正在读取阶段时间线…"));
    } else if (home.timelineFailed) {
      body.append(stageNode("p", "md-status", "阶段时间线读取失败，请刷新重试。"));
    } else if (!stage) {
      body.append(stageNode("p", "md-status", "这个阶段还没有时间线数据。"));
    } else {
      if (home.skillsFailed) body.append(stageNode("p", "md-status", "技能清单读取失败，暂时无法打开技能。"));
      const list = stageNode("ul", "stage-task-list");
      for (const entry of projectStageSkills(index)) {
        const row = stageNode("li", "stage-task");
        row.append(stageNode("span", "stage-task-name", entry.name));
        if (entry.skill) {
          const button = stageNode("button", "md-btn md-btn--secondary md-btn--sm", isCurrent ? "执行" : "打开");
          button.type = "button";
          button.dataset.projectStageSkill = String(entry.skill.id);
          row.append(button);
        }
        list.append(row);
      }
      body.append(list);
      const times = stageNode("dl", "stage-times");
      times.append(stageNode("dt", "", "开始时间"), stageNode("dd", "", stageTime(stage.started_at)),
        stageNode("dt", "", "完成时间"), stageNode("dd", "", stageTime(stage.completed_at)));
      body.append(times);
    }
    actions.replaceChildren();
    if (isCurrent) {
      const completed = home.project.status === "completed";
      const button = stageNode("button", "md-btn md-btn--secondary md-btn--sm", completed ? "项目已完成" : index === 5 ? "完成项目" : "推进到下一阶段");
      button.type = "button";
      button.dataset.projectStageAdvance = "";
      button.disabled = completed || stageActionBusy;
      actions.append(button);
    } else if (stateName === "done") {
      const targets = GEO.legalProjectRollbackTargets?.(home.project) || [];
      if (targets.includes(index)) {
        const button = stageNode("button", "md-btn md-btn--secondary md-btn--sm", "回到这一阶段");
        button.type = "button";
        button.dataset.projectStageRollback = String(index);
        button.disabled = stageActionBusy;
        actions.append(button);
      } else {
        actions.append(stageNode("p", "stage-action-note", `从「${projectStageName(currentProjectStage())}」不能直接回到这一阶段。`));
      }
    }
  }

  function openProjectStage(index, trigger = document.activeElement) {
    if (!home || !Number.isInteger(index) || index < 0 || index > 5) return;
    const mask = document.getElementById("projectStageDrawerMask");
    if (!mask) return;
    stageDrawerIndex = index;
    stageDrawerKey = home.key;
    stageDrawerTrigger = trigger;
    renderProjectHomeStages();
    mask.hidden = false;
    document.getElementById("projectStageDrawerClose")?.focus();
  }

  function closeProjectStage() {
    const index = stageDrawerIndex;
    const mask = document.getElementById("projectStageDrawerMask");
    if (mask) mask.hidden = true;
    stageDrawerIndex = null;
    stageDrawerKey = null;
    renderProjectHomeStages();
    if (stageDrawerTrigger?.isConnected) stageDrawerTrigger.focus();
    else document.querySelector(`#pjStageRail [data-project-stage="${index}"]`)?.focus();
    stageDrawerTrigger = null;
  }

  async function requestProjectStageAdvance() {
    if (!home || stageActionBusy || home.project.status === "completed") return;
    const key = home.key;
    const index = currentProjectStage();
    const trigger = document.activeElement;
    stageActionBusy = true;
    renderProjectStageDrawer();
    try {
      const confirmed = await GEO.confirmWorkspaceAction(index === 5 ? "确认完成整个项目？" : `确认推进到「${projectStageName(index + 1)}」？`);
      if (!confirmed || home?.key !== key || homeContext(state.selectedProject) !== key || currentProjectStage() !== index) return;
      openProjectStage(index, trigger);
      await GEO.advanceProjectStage();
      if (home?.key === key && homeContext(state.selectedProject) === key) openProjectStage(currentProjectStage(), trigger);
    } finally {
      stageActionBusy = false;
      renderProjectStageDrawer();
    }
  }

  async function requestProjectStageRollback(index) {
    if (!home || stageActionBusy || !(GEO.legalProjectRollbackTargets?.(home.project) || []).includes(index)) return;
    const key = home.key;
    const previous = currentProjectStage();
    const select = document.getElementById("rollbackTargetSelect");
    if (!select) return;
    select.value = String(index);
    stageActionBusy = true;
    renderProjectStageDrawer();
    try {
      await GEO.rollbackProjectStage();
      if (home?.key === key && homeContext(state.selectedProject) === key && currentProjectStage() !== previous) openProjectStage(currentProjectStage());
    } finally {
      stageActionBusy = false;
      renderProjectStageDrawer();
    }
  }

  async function readHomeTimeline(project, key, request) {
    try {
      const body = await api(`/api/v1/projects/${project.id}/timeline`);
      if (!isCurrentHome(key, request)) return;
      const timeline = Array.isArray(body) ? body : body?.data;
      if (!Array.isArray(timeline)) throw new Error("unavailable");
      home.timeline = timeline;
      if (!state.skills?.length && GEO.loadSkills) {
        const loaded = await GEO.loadSkills();
        if (!isCurrentHome(key, request)) return;
        home.skillsFailed = loaded === false;
      }
      home.unmatchedSkills = home.skillsFailed ? [] : timeline.flatMap(stage => projectStageSkills(Number(stage.stage_index))
        .filter(entry => !entry.skill).map(entry => ({ stage_index: Number(stage.stage_index), name: entry.name })));
      home.timelineFailed = false;
    } catch {
      if (!isCurrentHome(key, request)) return;
      home.timeline = [];
      home.timelineFailed = true;
    }
    if (isCurrentHome(key, request)) {
      home.timelineLoading = false;
      renderProjectHomeStages();
      GEO.renderProjectHomeQueue?.();
    }
  }

  // ═══════════════════════════════════════════════
  // Project lifecycle and UI bindings
  function renderProjectHome(project) {
    if (!project?.id || !document.getElementById("projectHomeMeta")) return;
    const request = ++generation;
    const key = homeContext(project);
    if (home?.key !== key) {
      closeProjectStage();
      GEO.setMessage?.(document.getElementById("stageActionMessage"), "");
    }
    home = { key, project, articleRequest: 0, articles: [], articlesFailed: false, articlesLoading: true, truncated: false,
      timeline: [], timelineLoading: true, timelineFailed: false, skillsFailed: false, unmatchedSkills: [] };
    renderProjectHomeMeta(project);
    for (const kind of ["Visibility", "Crawler", "Published"]) {
      homeText(`projectHome${kind}Value`, "—");
      homeText(`projectHome${kind}Delta`, "正在读取…");
    }
    GEO.renderProjectHomeStages?.(project);
    GEO.renderProjectHomeQueue?.();
    void readHomeVisibility(project, key, request);
    void readHomeCrawler(project, key, request);
    void readHomeArticles(project, key, request);
    void readHomeTimeline(project, key, request);
    homeText("projectHomeActivityEmptyTitle", "正在读取动态…");
    document.getElementById("projectHomeActivity").hidden = true;
    document.getElementById("projectHomeActivityEmpty").hidden = false;
    reloadProjectHomeActivity();
  }

  document.getElementById("projectHomePublishedLink")?.addEventListener("click", event => {
    event.preventDefault();
    openHomeArticle(null, "published");
  });

  document.getElementById("pjStageRail")?.addEventListener("click", event => {
    const button = event.target.closest("[data-project-stage]");
    if (button) openProjectStage(Number(button.dataset.projectStage), button);
  });
  document.getElementById("projectStageDrawerClose")?.addEventListener("click", closeProjectStage);
  document.getElementById("projectStageDrawerMask")?.addEventListener("click", event => {
    if (event.target === event.currentTarget) closeProjectStage();
  });
  document.getElementById("projectStageDrawer")?.addEventListener("click", event => {
    const skillButton = event.target.closest("[data-project-stage-skill]");
    if (skillButton) {
      const skill = state.skills?.find(row => String(row.id) === skillButton.dataset.projectStageSkill);
      if (skill) {
        closeProjectStage();
        GEO.selectRecommendedSkill(skill);
      }
    }
    if (event.target.closest("[data-project-stage-advance]")) void requestProjectStageAdvance();
    const rollback = event.target.closest("[data-project-stage-rollback]");
    if (rollback) void requestProjectStageRollback(Number(rollback.dataset.projectStageRollback));
  });
  window.addEventListener("hashchange", () => {
    if (window.location.hash !== "#projects") closeProjectStage();
  });
  document.addEventListener("keydown", event => {
    const mask = document.getElementById("projectStageDrawerMask");
    if (event.key === "Escape" && mask && !mask.hidden && !document.querySelector("dialog[open]")) closeProjectStage();
  });
  document.getElementById("projectStageDrawer")?.addEventListener("keydown", event => {
    if (event.key !== "Tab" || document.querySelector("dialog[open]")) return;
    const focusable = event.currentTarget.querySelectorAll('button:not([disabled]), [href], input:not([hidden]), select:not([hidden]), textarea, [tabindex]:not([tabindex="-1"])');
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last?.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first?.focus();
    }
  });

  Object.assign(GEO, { reloadProjectHomeActivity, projectHomeQueueItems, renderProjectHomeQueue, syncProjectHomeArticles, renderProjectHome, renderProjectHomeStages, requestProjectStageAdvance,
    closeProjectHomeStageDrawer: closeProjectStage });
})();
