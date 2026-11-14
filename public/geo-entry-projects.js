/* ============================================================================
   GEO 工作台 · 项目簇（geo-entry-projects.js）

   从 public/geo-entry.js 搬出来的第十一块（计划 §7 前端大文件拆分，计划点名的最后一个域）。
   搬走的是"项目"这一域的全部：项目列表与最近项目、左侧项目选择器、项目目录、
   项目详情与七项档案、阶段轨与推进/回退、时间线、项目名右键菜单与重命名、
   新建/删除项目、项目设置、以及从项目发起分析（工作流推荐 / 下一步）。

   ⚠️ 本文件**最先加载**（在 reports / skills 之前）：它提供 openWorkspaceView / openResultPage，
   那两个是所有拆出块与主文件都要用的入口；反过来它要用"报告块 / 技能块"的函数
   （selectArticle / stageLabel / renderSkillWorkspace …）一律走**运行期**
   `globalThis.GEO?.x?.()` —— 互相依赖排不出拓扑序，只能让先加载的一侧运行期取值。

   ⚠️ 阶段标签表 STAGE_FULL_LABELS 留在主文件：它是**对象**（薄转发只对函数成立），
   而且主文件别处也用它（结果页的阶段名）。

   红线（与拆分前逐字一致）：
     - 不动任何 API 路径与项目选择的 localStorage 键名（geo_recent_projects:…）；
     - 过期的项目列表/详情响应一律丢弃（requestId / sequence 口径照旧）；
     - 阶段推进与回退的合法目标、一键进分析、删除项目的确认文案都不动。

   判据：tests/fixtures/ui/dom-fingerprint.json（含 #projects 的 DOM/文本/API 指纹）+
   scripts/verify-ui-panels.mjs + npm run test:js（含 workspace-startup / project 相关契约测试）。
   ============================================================================ */
(() => {
  const GEO = window.GEO;
  if (!GEO) {
    console.error('[geo-entry-projects] window.GEO 未就绪：请确认本文件在 geo-entry.js 之后加载');
    return;
  }
  const {
    $, state, elements, api, escapeHtml, formatDate, formatNumber, setMessage, validationMessage, confirmWorkspaceAction, promptWorkspaceValue, withBusyButton, errorNoText, fillErrorNo, clToast, config, tenantSubdomain, statusLabel, listText, lineList, wait, formPayload, setFormValue, rememberProject, rememberedProjectId, forgetRememberedProject, currentWorkspaceRoute, defaultWorkspaceView, setActiveWorkspaceView, syncHeadActionsForEdit, runAnalysis, STAGE_FULL_LABELS, loadAnalyses, viewLoadState,
  } = GEO;

  // 项目选择/导航的请求序号：用来丢弃过期响应（只被这一块使用，随代码搬）。
  let projectSelectionRequest = 0;
  let projectNavigationRequest = 0;

  async function loadProjects(selectId = null) {
    let projects = await fetchProjectList();
    const rememberedId = rememberedProjectId();

    if (projects.length === 0 && rememberedId) {
      await wait(350);
      projects = await fetchProjectList();
    }
    const linkedId = Number(new URLSearchParams(window.location.search).get("project_id"));
    const requestedId = Number.isSafeInteger(linkedId) && linkedId > 0 && projects.some(project => Number(project.id) === linkedId)
      ? linkedId : null;

    state.projects = projects;
    renderProjects();

    const targetId = selectId || requestedId || state.selectedProject?.id || rememberedId || state.projects[0]?.id;
    if (targetId) {
      try {
        await selectProject(targetId);
        return;
      } catch (error) {
        if (![403, 404].includes(error.status)) throw error;
        forgetRememberedProject();
        if (state.projects.length > 0 && Number(targetId) !== Number(state.projects[0].id)) {
          await selectProject(state.projects[0].id);
          return;
        }
        if (state.projects.length > 0) throw error;
      }
    }

    setProjectCreateMode(true);
    syncProjectContextCard();
  }

  async function fetchProjectList() {
    const scope = recentProjectStorageKey();
    const endpoint = config.projectsEndpoint || "/api/v1/projects";
    const projects = new Map();
    let page = 1;
    let lastPage = 1;
    do {
      const separator = endpoint.includes("?") ? "&" : "?";
      const body = await api(`${endpoint}${separator}per_page=100&page=${page}`);
      if (scope !== recentProjectStorageKey()) throw new Error("登录状态已变化，请重新加载项目。");
      const rows = Array.isArray(body) ? body : body?.data;
      if (!Array.isArray(rows)) throw new Error("项目列表格式异常，请重试。");
      rows.forEach(project => projects.set(Number(project.id), project));
      lastPage = Number(body?.last_page || body?.meta?.last_page || 1);
      if (!Number.isSafeInteger(lastPage) || lastPage < 1) throw new Error("项目分页信息异常，请重试。");
      if (!rows.length && page < lastPage) throw new Error("项目列表尚未完整加载，请重试。");
      page++;
    } while (page <= lastPage);
    return [...projects.values()];
  }

  function recentProjectStorageKey() {
    return `geo_recent_projects:${state.session?.user?.id || "guest"}:${state.session?.tenant?.id || tenantSubdomain() || "default"}`;
  }

  function recentProjectIds() {
    try {
      const ids = JSON.parse(localStorage.getItem(recentProjectStorageKey()) || "[]");
      return Array.isArray(ids) ? [...new Set(ids.map(Number).filter(id => Number.isFinite(id) && id > 0))].slice(0, 12) : [];
    } catch { return []; }
  }

  function rememberRecentProject(project) {
    if (!project?.id) return;
    try { localStorage.setItem(recentProjectStorageKey(), JSON.stringify([Number(project.id), ...recentProjectIds().filter(id => id !== Number(project.id))].slice(0, 12))); } catch { /* Storage may be unavailable; selection still works. */ }
  }

  function sidebarProjects(projects = state.projects, selected = state.selectedProject, recent = recentProjectIds()) {
    const byId = new Map(projects.map(project => [Number(project.id), project]));
    // Keep the current and recently opened projects near the top, then show every
    // remaining project. The sidebar must not silently hide projects after three.
    return [...new Set([Number(selected?.id), ...recent, ...byId.keys()])].map(id => byId.get(id)).filter(Boolean);
  }

  function matchingProjects(query, projects = state.projects) {
    const needle = String(query || "").trim().toLocaleLowerCase();
    return projects.filter(project => `${project.name || ""} ${project.brand_name || ""}`.toLocaleLowerCase().includes(needle));
  }

  function projectChoiceButton(project, compact = false) {
    const item = document.createElement("button");
    item.type = "button";
    item.className = compact ? "project-nav-item" : "project-choice";
    item.dataset.projectId = String(project.id);
    const selected = Number(state.selectedProject?.id) === Number(project.id);
    item.setAttribute("aria-pressed", String(selected));
    item.title = project.name || "未命名项目";
    item.innerHTML = `<span class="project-choice-name">${escapeHtml(project.name || "未命名项目")}</span>${selected ? '<span class="project-current-tag">当前</span>' : ""}${compact ? "" : `<small>${escapeHtml(project.brand_name || "未填写品牌")} · ${escapeHtml(computeStageBadge(project))}</small>`}`;
    item.addEventListener("click", () => openProjectOverview(project.id));
    item.addEventListener("contextmenu", event => {
      event.preventDefault();
      openProjectNameMenu(project.id, item, event.clientX, event.clientY);
    });
    item.addEventListener("keydown", event => {
      if (event.key === "ContextMenu" || (event.shiftKey && event.key === "F10")) {
        event.preventDefault();
        const rect = item.getBoundingClientRect();
        openProjectNameMenu(project.id, item, rect.left, rect.bottom);
      }
    });
    return item;
  }

  let projectNameTarget = null;
  let projectRenameBusy = false;

  function closeProjectNameMenu(restoreFocus = false) {
    const menu = $("projectNameMenu");
    if (menu.hidden) return;
    menu.hidePopover?.();
    menu.hidden = true;
    if (restoreFocus) projectNameTarget?.trigger?.focus();
  }

  // ═══════════════════════════════════════════════
  // 项目名右键菜单（重命名 / 去执行 / 归档入口）
  // ═══════════════════════════════════════════════

  function openProjectNameMenu(id, trigger, x, y) {
    projectNameTarget = { id: Number(id), trigger, scope: recentProjectStorageKey() };
    const menu = $("projectNameMenu");
    menu.hidden = false;
    menu.showPopover?.();
    const rect = menu.getBoundingClientRect();
    menu.style.left = `${Math.max(8, Math.min(x, innerWidth - rect.width - 8))}px`;
    menu.style.top = `${Math.max(8, Math.min(y, innerHeight - rect.height - 8))}px`;
    $("projectNameMenuRename").focus();
  }

  $("projectNameMenuRename").addEventListener("click", () => {
    closeProjectNameMenu();
    const target = projectNameTarget;
    if (!target || target.scope !== recentProjectStorageKey()) return;
    const project = state.projects.find(p => Number(p.id) === target.id) || (Number(state.selectedProject?.id) === target.id ? state.selectedProject : null);
    if (!project) return;
    cancelProjectNavigation();
    $("projectRenameInput").value = project.name || "";
    $("projectRenameMessage").textContent = "";
    $("projectRenameDialog").showModal();
    $("projectRenameInput").select();
  });
  document.addEventListener("pointerdown", event => {
    if (!$("projectNameMenu").contains(event.target)) closeProjectNameMenu();
  });
  document.addEventListener("keydown", event => {
    if (!$("projectNameMenu").hidden && ["Escape", "Tab"].includes(event.key)) {
      if (event.key === "Escape") event.preventDefault();
      closeProjectNameMenu(true);
    }
  });
  window.addEventListener("resize", () => closeProjectNameMenu());
  window.addEventListener("scroll", () => closeProjectNameMenu(), true);
  window.addEventListener("hashchange", () => closeProjectNameMenu());
  $("projectRenameCancel").addEventListener("click", () => $("projectRenameDialog").close());
  $("projectRenameDialog").addEventListener("cancel", event => { if (projectRenameBusy) event.preventDefault(); });
  $("projectRenameDialog").addEventListener("close", () => {
    if (projectNameTarget?.trigger?.isConnected) projectNameTarget.trigger.focus();
    else document.querySelector(`#projectNavList [data-project-id="${projectNameTarget?.id}"]`)?.focus();
  });
  $("projectRenameForm").addEventListener("submit", async event => {
    event.preventDefault();
    if (projectRenameBusy) return;
    const target = projectNameTarget;
    const name = $("projectRenameInput").value.trim();
    if (!name) { $("projectRenameMessage").textContent = "请输入项目名称。"; $("projectRenameInput").focus(); return; }
    if (!target || target.scope !== recentProjectStorageKey()) { $("projectRenameMessage").textContent = "登录状态已变化，请关闭后重新操作。"; return; }
    projectRenameBusy = true;
    $("projectRenameSave").disabled = true;
    $("projectRenameCancel").disabled = true;
    $("projectRenameInput").disabled = true;
    $("projectRenameSave").textContent = "保存中…";
    $("projectRenameMessage").textContent = "";
    try {
      const updated = await api(`/api/v1/projects/${target.id}`, { method: "PATCH", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ name }) });
      if (target.scope !== recentProjectStorageKey()) return;
      state.projects = state.projects.map(p => Number(p.id) === target.id ? { ...p, name: updated.name } : p);
      if (Number(state.selectedProject?.id) === target.id) {
        state.selectedProject.name = updated.name;
        elements.detailTitle.textContent = updated.name;
        setFormValue(elements.settingsForm, "name", updated.name);
        syncProjectContextCard();
      }
      renderProjects();
      $("projectRenameDialog").close();
    } catch (error) { $("projectRenameMessage").textContent = validationMessage(error); }
    finally {
      projectRenameBusy = false;
      $("projectRenameSave").disabled = false;
      $("projectRenameCancel").disabled = false;
      $("projectRenameInput").disabled = false;
      $("projectRenameSave").textContent = "保存";
    }
  });

  async function openProjectOverview(projectId) {
    const context = recentProjectStorageKey();
    const route = window.location.hash;
    const sequence = ++projectNavigationRequest;
    setMessage(document.getElementById("projectNavMessage"), "");
    try {
      await selectProject(projectId);
      if (sequence !== projectNavigationRequest || context !== recentProjectStorageKey() || Number(state.selectedProject?.id) !== Number(projectId) || route !== window.location.hash) return;
      cancelProjectNavigation();
      setMessage(document.getElementById("projectNavMessage"), "");
      if (route !== "#projects/settings") openWorkspaceView("projects");
      const heading = route === "#projects/settings" ? document.getElementById("projectSettingsTitle") : elements.detailTitle;
      heading?.setAttribute("tabindex", "-1");
      heading?.focus();
    } catch (error) {
      if (sequence !== projectNavigationRequest || context !== recentProjectStorageKey()) return;
      const message = error.message || "项目切换失败，请重试。";
      setMessage(document.getElementById("projectNavMessage"), message, "danger");
    }
  }

  function renderProjects() {
    const list = document.getElementById("projectNavList");
    if (list) {
      const query = document.getElementById("projectNavSearch")?.value || "";
      const projects = matchingProjects(query, sidebarProjects());
      list.replaceChildren(...projects.map(project => projectChoiceButton(project, true)));
      if (!projects.length) {
        const empty = document.createElement("p");
        empty.className = "project-nav-empty";
        empty.textContent = query.trim() ? "没有匹配的项目。" : "还没有项目，点击新建项目开始。";
        list.appendChild(empty);
      }
    }
    renderProjectDirectory();
  }

  function renderProjectDirectory() {
    const host = document.getElementById("directoryProjectList");
    if (!host) return;
    const query = document.getElementById("directoryProjectSearch")?.value || "";
    const projects = matchingProjects(query);
    host.replaceChildren(...projects.map(project => projectChoiceButton(project)));
    if (!projects.length) {
      const empty = document.createElement("p");
      empty.className = "muted";
      empty.textContent = query.trim() ? "没有匹配的项目。" : "还没有项目，点击新建项目开始。";
      host.appendChild(empty);
    }
  }

  function showProjectDirectory() {
    cancelProjectNavigation();
    document.getElementById("globalTrashNav")?.setAttribute("aria-current", "false");
    renderProjectDirectory();
    openWorkspaceView("projects/list");
    document.getElementById("projectDirectoryTitle")?.focus();
  }

  function showProjectTrash() {
    cancelProjectNavigation();
    document.getElementById("globalTrashNav")?.setAttribute("aria-current", "page");
    openWorkspaceView("projects/trash");
    document.querySelector('[data-view-target="projects"]')?.setAttribute("aria-current", "false");
    document.getElementById("projectTrashTitle")?.focus();
  }

  async function createProject(event) {
    event.preventDefault();
    setMessage(elements.createProjectMessage, "正在创建项目...");
    const payload = formPayload(elements.createProjectForm);
    try {
      const project = await api(config.projectsEndpoint || "/api/v1/projects", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      elements.createProjectForm.reset();
      setMessage(elements.createProjectMessage, "项目已创建。", "success");
      await loadProjects(project.id);
      setProjectCreateMode(false);
      setMessage(elements.projectDetailMessage, "项目已创建。下一步照下面阶段卡的推荐做第 1 步。", "success");
    } catch (error) {
      setMessage(elements.createProjectMessage, validationMessage(error), "danger");
    }
  }

  // ═══════════════════════════════════════════════
  // 选项目：写状态 + 并行拉详情/分析/执行历史（过期响应丢弃）
  // ═══════════════════════════════════════════════

  async function selectProject(projectId) {
    const requestId = ++projectSelectionRequest;
    const selectionContext = recentProjectStorageKey();
    const project = await api(`/api/v1/projects/${projectId}`);
    // 用户快速点 A → B 时，A 的慢响应不能在 B 之后重新成为当前项目。
    if (requestId !== projectSelectionRequest || selectionContext !== recentProjectStorageKey()) return;
    // 项目切换后，旧项目的状态请求即使晚回来也不应再写当前页面。先从 Map 中
    // 注销所有 poller；tick 在 await 后还会复核 Map 与 project id，因此在途响应
    // 也会被丢弃。后台 Job 不受影响，切回项目时会从执行列表恢复轮询。
    globalThis.GEO?.stopAllExecutionPollers?.();
    rememberProject(project);
    upsertProject(project);
    if (Number(state.selectedProject?.id) !== Number(project.id)) {
      globalThis.GEO?.closeKnowledgeBaseDetail?.(); globalThis.GEO?.ekCloseDetail?.();
      if (elements.clDrawerMask) elements.clDrawerMask.hidden = true;
      for (const id of ["kbChunkOverlay", "searchTestOverlay"]) {
        const overlay = $(id);
        if (overlay) { overlay.closeSearchTest?.(); overlay.style.display = "none"; }
      }
      state.knowledgeBases = []; state.kbDocuments = []; state.ekProjects = []; state.ekLoaded = false;
      state.skillPromptsLoaded = false; state.skillPrompts = []; state.coveredPrompts = {};
      if (elements.spList) elements.spList.innerHTML = "";
      if (elements.kbCreateForm) elements.kbCreateForm.hidden = true;
      if (elements.ekCreateForm) elements.ekCreateForm.hidden = true;
      for (const form of [elements.createKbForm, elements.kbUploadForm, elements.kbIngestUrlForm, elements.ekCreateFormEl, elements.ekAddSourceForm]) form?.reset();
      if (elements.ekDraftEditor) elements.ekDraftEditor.value = "";
      if (elements.ekRows) elements.ekRows.innerHTML = "";
      if (elements.kbStats) elements.kbStats.innerHTML = "";
      if (elements.kbList) elements.kbList.innerHTML = "";
      $("knowledgeLegacyList").innerHTML = ""; $("knowledgeLegacy").hidden = true;
    }
    state.selectedProject = project;
    globalThis.GEO_SHELL?.syncArticleCount?.();
    viewLoadState.active = null;
    state.articles = [];
    state.analyses = [];
    state.contentLibrary = [];
    state.lastRenderedExecution = null;
    if (elements.assetHistory) elements.assetHistory.textContent = "正在加载报告…";
    if (elements.analysisResult) elements.analysisResult.textContent = "正在加载诊断结果…";
    if (elements.skillExecutionResult) elements.skillExecutionResult.textContent = "正在加载执行记录…";
    state.selectedArticleId = null;
    state.selectedAnalysisId = null;
    state.selectedExecutionId = null;
    state.skillExecutions = [];
    globalThis.GEO?.renderSkillPendingStrip?.();
    // 换项目要重新按新项目的阶段停，不能把上一个项目手动选的那格带过来。
    state.skillStage = null;
    state.skillReportReference = null;
    state.skillReportQuestion = null;
    renderProjects();
    renderProjectDetail(project);
    globalThis.GEO?.renderSkillWorkspace?.();
  }

  function upsertProject(project) {
    if (!project?.id) return;
    const index = state.projects.findIndex((item) => Number(item.id) === Number(project.id));
    if (index >= 0) {
      state.projects[index] = { ...state.projects[index], ...project };
    } else {
      state.projects = [project, ...state.projects];
    }
  }

  // ═══════════════════════════════════════════════
  // 项目详情：阶段轨 / 阶段卡 / 七项档案 / 时间线
  // ═══════════════════════════════════════════════

  function renderProjectDetail(project) {
    const settings = project.settings || {};
    setProjectCreateMode(false);
    // ⚠️ 这里是「按当前状态重放一次」，必须走 currentWorkspaceRoute() 把参数一起带上。
    //    传 state.activeView 会把 #result/41 的 41 抹掉（地址栏还是 41、内部状态却空了）。
    setActiveWorkspaceView(currentWorkspaceRoute() || defaultWorkspaceView);
    elements.detailTitle.textContent = project.name;

    // 换了项目、或者刚推进 / 回退过，都归位到当前阶段——人已经不在原来那一格了，
    // 界面继续停在那儿会让人以为项目没动。
    state.pjPeekStage = null;

    renderDossier(project);
    globalThis.GEO?.renderProjectHome?.(project);

    setFormValue(elements.settingsForm, "name", project.name);
    setFormValue(elements.settingsForm, "brand_name", project.brand_name || "");
    setFormValue(elements.settingsForm, "brand_url", project.brand_url || "");
    setFormValue(elements.settingsForm, "target_market", settings.target_market || "");
    setFormValue(elements.settingsForm, "product_description", settings.product_description || "");
    setFormValue(elements.settingsForm, "target_keywords", listText(settings.target_keywords));
    setFormValue(elements.settingsForm, "competitors", listText(settings.competitors));
    setFormValue(elements.settingsForm, "goals", settings.goals || "");
    setMessage(elements.settingsMessage, "");
    setMessage(elements.projectDetailMessage, "");
    if (elements.settingsWrap) elements.settingsWrap.hidden = true;
    syncHeadActionsForEdit();
    if (elements.openAnalysisButton) elements.openAnalysisButton.hidden = false;
    // 2026-09-04 拆版式 · 步骤 1：「AI 辅助建议」搬进本页的项目档案列，
    // 它的表单预填与换项目重置也跟着从 globalThis.GEO?.renderSkillWorkspace?.() 挪到这里。
    globalThis.GEO?.renderSkillSuggestions?.();

    syncProjectContextCard();
  }

  // DAG 合法回退目标（与后端 WorkflowStage::legalRollbackTargets 对齐）
  const LEGAL_ROLLBACK_TARGETS = {
    0: [], 1: [0], 2: [1], 3: [2], 4: [], 5: [0, 1, 2],
  };

  function legalProjectRollbackTargets(project) {
    return Object.freeze([...(LEGAL_ROLLBACK_TARGETS[resolveCurrentStageIndex(project)] || [])]);
  }

  function renderStageFocus(project) {
    globalThis.GEO?.renderProjectHomeStages?.(project);
  }

  /* ── 项目管理面辅助：阶段轨 / 档案列 / 情境卡（PJ0–PJ5） ── */

  // 「当前阶段是哪一格」的唯一算法。以前阶段轨自己算一套（第一个没做完的），
  // 推进按钮又算另一套（status=active，否则 current_stage）。两套一旦对不上，
  // 轨上亮着 A、按钮却在推进 B，界面上一点痕迹都没有。现在都走这里。
  function resolveCurrentStageIndex(project) {
    const stages = project?.stages || [];
    const active = stages.find((s) => s.status === "active")
      || stages.find((s) => Number(s.stage_index) === Number(project?.current_stage));
    return active ? Number(active.stage_index) : Number(project?.current_stage || 0);
  }

  // 阶段轨该画哪几格。正常走 project.stages —— 后端建项目时必写六行
  // （ProjectController@store）。万一某个项目没有阶段行（老数据、或直接写库造出来的），
  // 退回用 workflow_recommendations 的阶段列表画：六张卡砍成一张之后，轨是翻看
  // 其它阶段的唯一入口，它一空，卡上那句「共 6 步」就成了没有出口的空话。
  function stageNodesOf(project) {
    const stages = [...(project?.stages || [])];
    if (stages.length) return stages.sort((a, b) => Number(a.stage_index) - Number(b.stage_index));
    const recs = Array.isArray(project?.workflow_recommendations) ? project.workflow_recommendations : [];
    return [...recs]
      .sort((a, b) => Number(a.stage_index) - Number(b.stage_index))
      .map((r) => ({ stage_index: Number(r.stage_index), stage_key: r.stage_key, status: "pending", progress: 0 }));
  }

  function stageDisplayClasses(project) {
    const ordered = stageNodesOf(project);
    const currentIndex = resolveCurrentStageIndex(project);
    return ordered.map((stage) => {
      if (stage.status === "completed" || stage.status === "done") return "done";
      if (Number(stage.stage_index) === currentIndex) return "now";
      return "pending";
    });
  }

  function currentStageLabel(project) {
    if (!stageNodesOf(project).length) return "";
    return globalThis.GEO?.stageLabel?.(resolveCurrentStageIndex(project));
  }

  function computeStageBadge(project) {
    const label = currentStageLabel(project);
    return `${label ? label + " · " : ""}路线 ${project.progress || 0}%`;
  }

  function listToText(value) {
    if (Array.isArray(value)) return value.join(" · ");
    return String(value || "").trim();
  }

  function hasDossierValue(value) {
    const display = listToText(value);
    return Boolean(display) && display !== "-";
  }

  function dossierRow(label, value) {
    return `<div><dt>${escapeHtml(label)}</dt><dd>${escapeHtml(listToText(value))}</dd></div>`;
  }

  // 七项档案里，新项目往往五项没填。原来这五项逐行铺开五条灰字提示，
  // 第一屏第一眼看到的就是一列「未填写——」，其中两条提示语还一字不差。
  // 现在：填了的照常显示，没填的收成一行，字段名仍然写得清清楚楚。
  function renderDossier(project) {
    const el = elements.pjDossier;
    if (!el) return;
    const s = project.settings || {};
    const rows = [
      ["品牌", project.brand_name],
      ["官网", project.brand_url],
      ["目标市场", s.target_market],
      ["产品说明", s.product_description],
      ["目标关键词", s.target_keywords],
      ["竞品", s.competitors],
      ["目标", s.goals],
    ];
    const filled = rows.filter(([, value]) => hasDossierValue(value));
    const missing = rows.filter(([, value]) => !hasDossierValue(value)).map(([label]) => label);

    el.innerHTML = filled.map(([label, value]) => dossierRow(label, value)).join("");
    if (missing.length) {
      el.insertAdjacentHTML("beforeend", `
        <div class="dossier-missing">
          <p><strong>还差 ${missing.length} 项没填</strong>：${escapeHtml(missing.join("、"))}</p>
          <p class="dm-why">补全后，诊断报告会更贴近你的业务。</p>
          <button class="md-btn md-btn--secondary md-btn--sm" type="button" data-dossier-fill>去填写</button>
        </div>
      `);
      el.querySelector("[data-dossier-fill]")?.addEventListener("click", () => elements.editProjectButton?.click());
    }
  }

  function syncProjectContextCard() {
    const nameEl = document.getElementById("projectCardName"), metaEl = document.getElementById("projectCardStage");
    const p = state.selectedProject;
    const contextName = document.getElementById("pcName");
    const contextMeta = document.getElementById("pcMeta");
    const settingsName = document.getElementById("projectSettingsName");
    if (settingsName) settingsName.textContent = p?.name || "";
    if (contextName) contextName.textContent = p?.name || (p ? "未命名项目" : "还没有项目");
    if (contextMeta) contextMeta.textContent = p ? computeStageBadge(p) : "创建第一个项目开始";
    if (!nameEl || !metaEl) return;
    const settingsButton = document.getElementById("projectCardSettings");
    if (settingsButton) settingsButton.disabled = !p;
    if (!p) {
      nameEl.textContent = "还没有项目";
      metaEl.textContent = "创建第一个项目开始";
      return;
    }
    nameEl.textContent = p.name || "未命名项目";
    const stage = Number(p.current_stage);
    metaEl.textContent = Number.isInteger(stage) && stage >= 0 && stage <= 5
      ? `阶段 ${stage + 1}/6 · ${STAGE_FULL_LABELS[stage]}` : "阶段 —";
  }
  function closeProjectSwitcher(restoreFocus = false) {
    const children = document.getElementById("projectNavChildren"), projectToggle = document.getElementById("projectNavToggle");
    if (!children || !projectToggle || children.hidden) return;
    children.hidden = true;
    projectToggle.setAttribute("aria-expanded", "false");
    projectToggle.setAttribute("aria-label", "展开项目列表");
    if (restoreFocus) projectToggle.focus();
  }
  // 开合由主文件的 projectNavToggle 处理；这里复用项目列表的排序和搜索。
  document.getElementById("projectNavSearch")?.addEventListener("input", renderProjects);
  document.getElementById("projectNavToggle")?.addEventListener("click", () => {
    if (!document.getElementById("projectNavChildren")?.hidden) document.getElementById("projectNavSearch")?.focus();
  });
  document.getElementById("pcContext")?.addEventListener("click", event => {
    if (event.target.closest("#projectNavList [data-project-id], #newProjectButton, #projectCardAllProjects")) closeProjectSwitcher();
  });
  ["projectCardSettings", "projectSettingsButton"].forEach(id => document.getElementById(id)?.addEventListener("click", () => {
    if (!state.selectedProject) return;
    cancelProjectNavigation();
    closeProjectSwitcher();
    openWorkspaceView("projects/settings");
    document.getElementById("projectSettingsTitle")?.focus();
  }));
  document.getElementById("projectSettingsBackButton")?.addEventListener("click", () => openWorkspaceView("projects"));
  document.addEventListener("pointerdown", event => {
    if (!document.getElementById("pcContext")?.contains(event.target)) closeProjectSwitcher();
  });
  document.addEventListener("keydown", event => {
    if (event.key !== "Escape" || document.getElementById("projectNavChildren")?.hidden) return;
    event.preventDefault();
    closeProjectSwitcher(true);
  });
  window.addEventListener("hashchange", () => closeProjectSwitcher());

  function cancelProjectNavigation() {
    projectNavigationRequest++;
    setMessage(document.getElementById("projectNavMessage"), "");
  }

  async function advanceProjectStage() {
    const project = state.selectedProject;
    if (!project) return;
    const stages = project.stages || [];
    const activeStage = stages.find((s) => s.status === "active")
      || stages.find((s) => Number(s.stage_index) === Number(project.current_stage));
    if (!activeStage) {
      setMessage(document.getElementById("stageActionMessage"), "未找到可推进的阶段。", "danger");
      return;
    }
    try {
      await api(`/api/v1/projects/${project.id}/stages/${activeStage.id}/advance`, { method: "POST" });
      setMessage(document.getElementById("stageActionMessage"), Number(project.current_stage) === 5 ? "项目已完成。" : "阶段已推进。", "success", { sticky: true });
      await selectProject(project.id);
    } catch (error) {
      setMessage(document.getElementById("stageActionMessage"), error.message || "推进失败。", "danger", { sticky: true });
    }
  }

  async function rollbackProjectStage() {
    const project = state.selectedProject;
    if (!project) return;
    const target = Number(document.getElementById("rollbackTargetSelect")?.value || 0);
    const reason = await promptWorkspaceValue("回退原因（可选）：");
    if (reason === null) return;
    try {
      await api(`/api/v1/projects/${project.id}/stages/rollback`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ target_stage: target, reason }),
      });
      setMessage(document.getElementById("stageActionMessage"), "阶段已回退。", "success", { sticky: true });
      await selectProject(project.id);
    } catch (error) {
      setMessage(document.getElementById("stageActionMessage"), error.message || "回退失败。", "danger", { sticky: true });
    }
  }

  function setProjectCreateMode(enabled) {
    const showCreate = Boolean(enabled) || !state.selectedProject;
    if (showCreate) globalThis.GEO?.closeProjectHomeStageDrawer?.();
    if (elements.createProjectPanel) elements.createProjectPanel.hidden = !showCreate;
    if (elements.projectDetail) elements.projectDetail.hidden = showCreate || !state.selectedProject;
    if (elements.cancelCreateProjectButton) {
      elements.cancelCreateProjectButton.hidden = !state.selectedProject;
    }
    // 新建 / 开局 视觉切换（红线：仅改模板与挂载，不动控制流）
    if (elements.createProjectPanel) {
      const fresh = !state.selectedProject;
      elements.createProjectPanel.classList.toggle("is-fresh", fresh);
      const rail = document.getElementById("pjCreateRail");
      const caption = document.getElementById("pjCreateRailCaption");
      if (rail) rail.hidden = !fresh;
      if (caption) caption.hidden = !fresh;
    }
  }

  function openCreateProjectPanel() {
    openWorkspaceView("projects");
    setMessage(elements.createProjectMessage, "");
    setProjectCreateMode(true);
    const firstField = elements.createProjectForm?.querySelector("input, textarea, select");
    firstField?.focus();
  }

  function closeCreateProjectPanel() {
    if (!state.selectedProject) {
      setProjectCreateMode(true);
      return;
    }

    elements.createProjectForm?.reset();
    setMessage(elements.createProjectMessage, "");
    setProjectCreateMode(false);
  }

  function openWorkspaceView(view) {
    setActiveWorkspaceView(view, { updateHash: true });
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  // 去独立结果页看某一份报告。所有「查看完整报告 / 打开报告」都走这一个口，
  // 别再各自 setActiveWorkspaceView("asset") + globalThis.GEO?.selectArticle?.()：
  // 报告的落脚点只有一个，多一个入口就多一份要同步的逻辑。
  function openResultPage(articleId) {
    const id = Number(articleId) || 0;
    if (!id) return;
    openWorkspaceView(`result/${id}`);
  }

  function openAnalysisWorkspace() {
    if (!state.selectedProject) {
      setMessage(elements.createProjectMessage, "请先创建或选择一个项目。", "danger");
      openWorkspaceView("projects");
      return;
    }

    state.skillContext = "strategy";
    globalThis.GEO?.setSkillWorkspaceMode?.("diagnosis");
    openWorkspaceView("analysis");
    window.setTimeout(() => globalThis.GEO?.scrollToWithHeaderOffset?.(elements.skillDiagnosisBlock), 80);
    // 有记录时不再播报「请选择历史记录…」：右边报告区的占位符 87px 外就写着
    // 「执行或选择一次分析后，报告会显示在这里。」，同一件事说两遍。
    // 空字符串会让这条 callout 整块收起（.skill-panel .callout:empty）。
    // 没有记录是另一回事——那是真需要一句引导，保留。
    setMessage(elements.analysisMessage, state.analyses.length ? "" : "当前项目还没有分析记录。点击「执行综合诊断」生成第一份。");
  }

  async function startAnalysisFromProject() {
    if (!state.selectedProject) {
      setMessage(elements.createProjectMessage, "请先创建或选择一个项目。", "danger");
      openWorkspaceView("projects");
      return;
    }

    if (state.analyses.length > 0) {
      openAnalysisWorkspace();
      return;
    }

    await runAnalysis();
  }

  async function saveSettings(event) {
    event.preventDefault();
    if (!state.selectedProject) return;
    setMessage(elements.settingsMessage, "正在保存设置...");
    try {
      const updated = await api(`/api/v1/projects/${state.selectedProject.id}`, {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(formPayload(elements.settingsForm)),
      });
      setMessage(elements.settingsMessage, "设置已保存。", "success");
      await selectProject(updated.id);
      await loadProjects(updated.id);
    } catch (error) {
      setMessage(elements.settingsMessage, validationMessage(error), "danger");
    }
  }

  // ═══════════════════════════════════════════════
  // 新建 / 删除 / 项目设置
  // ═══════════════════════════════════════════════

  async function deleteSelectedProject() {
    const project = state.selectedProject;
    if (!project?.id) return;

    await ensureTrashRetention();

    // 2026-09-21 起后端 ProjectController@destroy 是**进回收站**（软删 + 暂停自动化任务）：
    // 文章、分析报告、阶段原地保留 7 天可恢复，期满由 geo:projects-purge 才连坐真删；
    // 调用流水（跟钱有关）始终不删。确认文案要把"有后悔药、但会过期"同时说清楚。
    const confirmed = await confirmWorkspaceAction(
      `确定删除项目「${project.name || "未命名项目"}」吗？\n\n它会进全局回收站保留 ${trashRetentionDays()} 天，期内可恢复本地项目、文章、分析报告和自动化任务；已撤回的外站内容需要重新发布。超过保留期后无法恢复。`,
    );
    if (!confirmed) return;

    await withBusyButton(elements.deleteProjectButton, "删除中...", async () => {
      setMessage(elements.projectDetailMessage, "正在删除项目...");
      try {
        await api(`/api/v1/projects/${project.id}`, { method: "DELETE" });

        state.projects = state.projects.filter((item) => Number(item.id) !== Number(project.id));
        state.selectedProject = null;
        state.selectedAnalysisId = null;
        state.selectedExecutionId = null;
        state.selectedArticleId = null;
        state.analyses = [];
        state.skillExecutions = [];
        state.articles = [];
        forgetRememberedProject();

        const nextProjectId = state.projects[0]?.id;
        await loadProjects(nextProjectId);

        const trashNote = `已把项目「${project.name || "未命名项目"}」放进全局回收站，${trashRetentionDays()} 天内可以恢复。`;
        if (state.selectedProject) {
          setMessage(elements.projectDetailMessage, trashNote, "success");
        } else {
          setMessage(elements.createProjectMessage, `${trashNote}也可以创建新的项目。`, "success");
        }
        // 桶里多了一条，进页面才刷新会让人以为"没删掉" —— 删完立刻回读一次。
        await loadProjectTrash();
      } catch (error) {
        setMessage(elements.projectDetailMessage, validationMessage(error) || "删除项目失败。", "danger");
      }
    });
  }

  // ═══════════════════════════════════════════════
  // 回挂（拆分前这些都在 geo-entry.js 里）
  // ═══════════════════════════════════════════════

  // ═══════════════════════════════════════════════
  // 回收站（计划 §9.5；用户口径 2026-09-21「删项目保留 7 天，7 天之后无法恢复」）
  // ═══════════════════════════════════════════════

  /**
   * 保留天数只认后端给的数（服务端是唯一权威，前端写死就等着两处漂移）。
   * 还没读到响应时按 7 天兜底显示，绝不显示"0 天"这种会吓人的中间态。
   */
  let trashRetention = 7;
  let trashRetentionLoaded = false;
  function trashRetentionDays() {
    return trashRetention;
  }

  function setTrashRetention(value) {
    const days = Number(value);
    if (Number.isFinite(days) && days > 0) {
      trashRetention = days;
      ["projectTrashRetention", "deleteProjectRetention"].forEach((id) => {
        const node = document.getElementById(id);
        if (node) node.textContent = String(days);
      });
    }
  }

  /**
   * 让"删除确认文案里的天数"是服务端真正的保留期，而不是前端猜的一个数字。
   *
   * 只在**真要删的那一刻**取一次（同一页面会话内缓存）：放在进页面时取，
   * 会给 30 个视图的加载指纹各加一条请求，而这条数据删除之外没人用。
   * 取不到就用当前显示值继续 —— 删除本身不能被一次"读不到天数"挡住。
   */
  async function ensureTrashRetention() {
    if (trashRetentionLoaded) return trashRetention;
    try {
      const body = await api("/api/v1/trash");
      setTrashRetention(body.retention_days);
      trashRetentionLoaded = true;
    } catch { /* 读不到就按当前显示值继续，后端仍是唯一权威 */ }

    return trashRetention;
  }

  function trashRow(row) {
    const name = row.name || "未命名项目";
    const type = row.type || "project";
    const itemKey = row.key || `${type}:${row.id}`;
    const typeLabel = row.type_label || (type === "article" ? "文章" : "项目");
    const parts = [];
    if (Number(row.articles_count) > 0) parts.push(`${Number(row.articles_count)} 篇文章`);
    if (Number(row.skill_executions_count) > 0) parts.push(`${Number(row.skill_executions_count)} 份分析报告`);
    if (row.project_name) parts.push(`所属项目：${row.project_name}`);
    if (row.type === "article" && row.status_label) parts.push(`删除前状态：${row.status_label}`);
    if (row.deleted_at) parts.push(`删除于 ${formatDate(row.deleted_at)}`);
    const expired = Boolean(row.expired);
    const expiring = !expired && Number(row.days_left) <= 2;
    parts.push(expired ? "已到期，等待清理" : `还有 ${Number(row.days_left) || 0} 天可恢复`);
    if (row.purge_at) parts.push(`恢复截止 ${formatDate(row.purge_at)}`);
    // 外站撤稿待办（计划 §9.5）：客户删了项目、外站却没撤下来的那些必须亮在明面上 ——
    // 否则"我删了"和"外站真的没了"在他眼里是同一件事，而实际上不是。
    const takedown = row.takedown || {};
    const online = Number(takedown.online) || 0;
    const failed = Number(takedown.failed) || 0;
    const takedownNote = online === 0
      ? ""
      : failed > 0
        ? `<p class="project-trash-takedown is-failed">⚠️ 外站还有 ${online} 篇没撤下来（${escapeHtml(takedown.last_error || "原因见服务端记录")}）</p>`
        : `<p class="project-trash-takedown">外站还有 ${online} 篇待撤回</p>`;
    const cell = document.createElement("div");
    cell.className = `project-trash-row${expired ? " is-expired" : expiring ? " is-expiring" : ""}`;
    cell.innerHTML = `
      <div class="project-trash-meta">
        <strong><span class="project-trash-type">${escapeHtml(typeLabel)}</span>${escapeHtml(name)}</strong>
        <span>${escapeHtml(parts.join(" · "))}</span>
        ${takedownNote}
      </div>
      <div class="project-trash-actions">
        ${online > 0 ? `<button class="md-btn md-btn--secondary md-btn--sm" type="button" data-trash-takedown="${escapeHtml(itemKey)}" data-trash-type="${escapeHtml(type)}" data-trash-id="${escapeHtml(String(row.id))}" data-trash-name="${escapeHtml(name)}">重试撤稿</button>` : ""}
        ${row.restorable === false ? '<button class="md-btn md-btn--secondary md-btn--sm" type="button" disabled>已过期</button>' : `<button class="md-btn md-btn--secondary md-btn--sm" type="button" data-trash-restore="${escapeHtml(itemKey)}" data-trash-type="${escapeHtml(type)}" data-trash-id="${escapeHtml(String(row.id))}" data-trash-restore-url="${escapeHtml(row.restore_url || "")}" data-trash-name="${escapeHtml(name)}">恢复</button>`}
      </div>
    `;
    return cell;
  }

  /**
   * 重试外站撤稿（§9.5）：目标站掉线/凭据过期导致的失败，客户恢复后自己点一下就重试。
   * 成功后回收站列表里的那条待办就该消失 —— 所以必须回读，不能只弹一句"已重试"。
   */
  async function retryTrashedTakedown(type, id, name, button) {
    const subject = type === "article" ? "文章" : "项目";
    const confirmed = await confirmWorkspaceAction(
      `对${subject}「${name || "未命名"}」重试撤回外站内容吗？\n\n会再次请求目标站删除已发布内容；失败的会保留在这里继续显示。`,
    );
    if (!confirmed) return;

    const message = document.getElementById("projectTrashMessage");
    await withBusyButton(button, "撤稿中…", async () => {
      setMessage(message, "正在请求目标站撤回内容…");
      try {
        const url = type === "article" ? `/api/v1/articles/${id}/takedown` : `/api/v1/projects/${id}/takedown`;
        const body = await api(url, { method: "POST" });
        setMessage(message, body.message || "撤稿重试完成。", body.data?.failed?.length ? "warning" : "success");
        await loadProjectTrash();
      } catch (error) {
        setMessage(message, validationMessage(error) || "撤稿重试失败，请稍后再试。", "danger");
      }
    });
  }

  /**
   * 回读回收站。三种结局都要有人管：
   *  - 有权限：正常渲染（空桶也要说"是空的"，不能留一句"正在读取"）；
   *  - 403（editor/member 没有 project:delete）：整块藏起来 —— 无权的人看一个读不出来的列表只会以为系统坏了；
   *  - 其它错误：如实写在列表里，不假装空桶（"看不到"和"没有"是两件事）。
   */
  async function loadProjectTrash() {
    const section = document.getElementById("projectTrashSection");
    const host = document.getElementById("projectTrashList");
    if (!section || !host) return true;

    host.innerHTML = '<p class="muted">正在读取回收站…</p>';
    try {
      const body = await api("/api/v1/trash");
      setTrashRetention(body.retention_days);
      trashRetentionLoaded = true;
      const rows = Array.isArray(body.data) ? body.data : [];
      const count = document.getElementById("projectTrashCount");
      if (count) count.textContent = rows.length ? `${rows.length} 个` : "空";
      const globalCount = document.getElementById("globalTrashCount");
      if (globalCount) globalCount.textContent = rows.length ? String(rows.length) : "";
      const globalNav = document.getElementById("globalTrashNav");
      if (globalNav) globalNav.hidden = false;
      section.hidden = state.activeViewParam !== "trash";
      host.replaceChildren();
      if (!rows.length) {
        const empty = document.createElement("p");
        empty.className = "muted";
        empty.textContent = "回收站是空的。删除的项目和文章会先放在这里。";
        host.appendChild(empty);
      } else {
        rows.forEach((row) => host.appendChild(trashRow(row)));
      }
    } catch (error) {
      if (error?.status === 403) {
        section.hidden = true;
        const globalNav = document.getElementById("globalTrashNav");
        if (globalNav) globalNav.hidden = true;
        return true;
      }
      section.hidden = state.activeViewParam !== "trash";
      const count = document.getElementById("projectTrashCount");
      if (count) count.textContent = "!";
      const globalNav = document.getElementById("globalTrashNav");
      if (globalNav) globalNav.hidden = false;
      host.innerHTML = `<p class="muted">回收站暂时读取失败：${escapeHtml(validationMessage(error) || "请稍后重试。")}</p>`;
    }

    return true;
  }

  async function restoreTrashedItem(type, id, name, restoreUrl, button) {
    const isArticle = type === "article";
    const subject = isArticle ? "文章" : "项目";
    const confirmed = await confirmWorkspaceAction(
      isArticle
        ? `恢复文章「${name || "未命名文章"}」吗？\n\n它会恢复删除前的内容和状态；已撤回的外站内容需要重新发布。`
        : `恢复项目「${name || "未命名项目"}」吗？\n\n本地项目、文章、分析报告和自动化任务会一起回来。已撤回的外站内容需要重新发布。`,
    );
    if (!confirmed) return;

    const message = document.getElementById("projectTrashMessage");
    await withBusyButton(button, "恢复中…", async () => {
      setMessage(message, `正在恢复${subject}…`);
      try {
        await api(restoreUrl, { method: "POST" });
        setMessage(message, isArticle ? `文章「${name || "未命名文章"}」已原样恢复。` : `项目「${name || "未命名项目"}」已恢复，可以在项目列表里打开。`, "success");
        await loadProjectTrash();
        // 侧栏/目录列表都要立刻能看到它回来（只刷新当前视图会让人以为没恢复）。
        if (!isArticle) {
          state.projects = await fetchProjectList();
          renderProjects();
        }
      } catch (error) {
        setMessage(message, validationMessage(error) || "恢复失败，请稍后重试。", "danger");
      }
    });
  }

  document.getElementById("openProjectTrash")?.addEventListener("click", showProjectTrash);
  document.getElementById("projectTrashRefresh")?.addEventListener("click", loadProjectTrash);
  document.getElementById("globalTrashNav")?.addEventListener("click", showProjectTrash);
  document.getElementById("projectTrashList")?.addEventListener("click", (event) => {
    const button = event.target.closest("[data-trash-restore]");
    if (button) {
      restoreTrashedItem(button.dataset.trashType, button.dataset.trashId, button.dataset.trashName || "", button.dataset.trashRestoreUrl, button);
      return;
    }
    const retry = event.target.closest("[data-trash-takedown]");
    if (retry) retryTrashedTakedown(retry.dataset.trashType, retry.dataset.trashId, retry.dataset.trashName || "", retry);
  });
  // 只有项目目录和全局回收站页才拉列表：项目详情屏拉它没有用处，
  // 反而会让每个视图的加载指纹都多一条与当前屏无关的请求（§7 的 30 视图指纹实测过）。
  GEO.registerViewLoader("projects", () => {
    if (!['list', 'trash'].includes(state.activeViewParam)) return true;

    return loadProjectTrash();
  });

  // 主文件的薄转发与其它拆出块要取这些实现。
  Object.assign(GEO, {
    loadProjects,
    fetchProjectList,
    recentProjectStorageKey,
    recentProjectIds,
    rememberRecentProject,
    sidebarProjects,
    matchingProjects,
    projectChoiceButton,
    closeProjectNameMenu,
    openProjectNameMenu,
    openProjectOverview,
    renderProjects,
    renderProjectDirectory,
    showProjectDirectory,
    showProjectTrash,
    createProject,
    selectProject,
    upsertProject,
    renderProjectDetail,
    renderStageFocus,
    legalProjectRollbackTargets,
    resolveCurrentStageIndex,
    stageNodesOf,
    stageDisplayClasses,
    currentStageLabel,
    computeStageBadge,
    listToText,
    hasDossierValue,
    dossierRow,
    renderDossier,
    syncProjectContextCard,
    cancelProjectNavigation,
    advanceProjectStage,
    rollbackProjectStage,
    setProjectCreateMode,
    openCreateProjectPanel,
    closeCreateProjectPanel,
    openWorkspaceView,
    openResultPage,
    openAnalysisWorkspace,
    startAnalysisFromProject,
    saveSettings,
    deleteSelectedProject,
    loadProjectTrash,
    trashRetentionDays,
  });
})();
