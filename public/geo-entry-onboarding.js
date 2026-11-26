/* ============================================================================
   GEO 工作台 · 模型配置交互与首次引导（geo-entry-onboarding.js）

   负责：
     1. 模型配置页的来源分段切换（第三方 API 直连 / Magpie），保留两边表单草稿；
     2. 可选能力折叠卡的实时状态徽标同步与文本模型就绪后的「下一步」条；
     3. 首次使用 3 步指引与 Day-1 首个技能启动卡（仅读元数据，不发送探测或计费请求）。
   ============================================================================ */
(() => {
  "use strict";

  const GEO = window.GEO;
  if (!GEO) return;
  const { state, elements, api } = GEO;

  let readinessScope = "";
  let readinessStatus = "unknown"; // "unknown" | "ready" | "missing"
  let readinessPromise = null;
  let day1PrimaryMode = "skill";
  let day1RecommendedSkill = null;

  // ═══════════════════════════════════════════════
  // Model readiness (read-only metadata check, zero model calls)
  // ═══════════════════════════════════════════════

  function currentReadinessScope() {
    const tenantId = state.session?.tenant?.id ?? "";
    const userId = state.session?.user?.id ?? "";
    return tenantId || userId ? `${tenantId}:${userId}` : "";
  }

  function hasActiveMagpieTextFromPayload(magpieBody) {
    const binding = magpieBody?.bindings?.text;
    if (binding?.source_type !== "profile" || !binding.profile_id) return false;
    const profile = (magpieBody?.profiles || []).find(
      (row) => Number(row.id) === Number(binding.profile_id) && row.slot === "text",
    );
    if (!profile || !profile.enabled || !profile.model) return false;
    const targets = magpieBody?.targets || [];
    return targets.some(
      (target) => target.alias === profile.target_alias
        && target.base_url === profile.base_url
        && target.auth_mode === profile.auth_mode
        && target.revision === profile.target_revision,
    );
  }

  function isMagpieSourceActive(section) {
    const label = section?.querySelector?.("[data-active-model-source]")?.textContent || "";
    return label.includes("当前使用 Magpie");
  }

  function isMagpieSourceSelected(section) {
    const label = section?.querySelector?.("[data-active-model-source]")?.textContent || "";
    return label.includes("Magpie");
  }

  function isTextModelReady() {
    const directText = (state.aiKeys || []).some((row) => row.slot === "text" && row.is_active);
    const textSection = elements.aikeyList?.querySelector?.('[data-magpie-slot="text"]');
    const magpieText = isMagpieSourceActive(textSection);
    return Boolean(directText || magpieText || readinessStatus === "ready");
  }

  async function ensureModelReadiness() {
    const scope = currentReadinessScope();
    if (!scope || typeof api !== "function") return readinessStatus;
    if (readinessScope === scope && readinessStatus !== "unknown") return readinessStatus;
    if (readinessScope === scope && readinessPromise) return readinessPromise;
    readinessScope = scope;
    readinessStatus = "unknown";
    const pending = Promise.allSettled([
      api("/api/v1/ai-keys"),
      api("/api/v1/ai-keys/magpie"),
    ]).then(([keysRes, magpieRes]) => {
      if (currentReadinessScope() !== scope) return readinessStatus;
      let checked = false;
      let directReady = false;
      let magpieReady = false;
      if (keysRes.status === "fulfilled" && Array.isArray(keysRes.value?.data)) {
        checked = true;
        directReady = keysRes.value.data.some((row) => row.slot === "text" && row.is_active);
      }
      if (magpieRes.status === "fulfilled" && magpieRes.value && typeof magpieRes.value === "object") {
        checked = true;
        magpieReady = hasActiveMagpieTextFromPayload(magpieRes.value);
      }
      readinessStatus = !checked ? "unknown" : (directReady || magpieReady ? "ready" : "missing");
      renderOnboardingGuide();
      syncDay1Spotlight(state.selectedProject);
      return readinessStatus;
    }).finally(() => {
      if (readinessPromise === pending) readinessPromise = null;
    });
    readinessPromise = pending;
    return pending;
  }

  // ═══════════════════════════════════════════════
  // Model configuration source tabs & optional summaries
  // ═══════════════════════════════════════════════

  function setModelSourcePane(section, pane, userInitiated = false) {
    if (!section) return;
    const normalized = pane === "magpie" ? "magpie" : "direct";
    const sources = section.querySelector?.(".model-sources");
    if (sources?.dataset) sources.dataset.activePane = normalized;
    if (userInitiated && section.dataset) section.dataset.userPickedSource = "1";
    section.querySelectorAll?.("[data-source-tab]").forEach((btn) => {
      btn.setAttribute("aria-pressed", String(btn.dataset.sourceTab === normalized));
    });
  }

  function applyBadgeTone(badge, text, tone) {
    if (!badge) return;
    badge.textContent = text;
    if (!badge.classList) return;
    for (const candidate of ["md-badge--neutral", "md-badge--primary", "md-badge--success", "md-badge--warning"]) {
      badge.classList.toggle(candidate, candidate === `md-badge--${tone}`);
    }
  }

  function syncModelConfigUi() {
    const list = elements.aikeyList;
    if (!list) return;

    list.querySelectorAll?.("[data-magpie-slot]").forEach((section) => {
      if (section.dataset?.userPickedSource !== "1") {
        setModelSourcePane(section, isMagpieSourceSelected(section) ? "magpie" : "direct", false);
      }
    });

    for (const slot of ["embedding", "vision", "visibility"]) {
      const key = (state.aiKeys || []).find((row) => row.slot === slot);
      if (slot === "vision") {
        const visionSection = list.querySelector?.('[data-magpie-slot="vision"]');
        if (isMagpieSourceActive(visionSection)) {
          applyBadgeTone(list.querySelector?.(`[data-optional-status="${slot}"]`), "已连接 Magpie", "success");
          continue;
        }
      }
      const badge = list.querySelector?.(`[data-optional-status="${slot}"]`);
      if (key) {
        applyBadgeTone(badge, key.is_active ? "已启用" : "已停用", key.is_active ? "success" : "neutral");
      } else {
        applyBadgeTone(badge, "未配置", "neutral");
      }
    }

    const directText = (state.aiKeys || []).some((row) => row.slot === "text" && row.is_active);
    const textSection = list.querySelector?.('[data-magpie-slot="text"]');
    const magpieText = isMagpieSourceActive(textSection);
    const ready = Boolean(directText || magpieText);
    const scope = currentReadinessScope();
    if (scope) {
      readinessScope = scope;
      const sourceText = textSection?.querySelector?.("[data-active-model-source]")?.textContent || "";
      if (ready || (Array.isArray(state.aiKeys) && !sourceText.includes("正在读取"))) {
        readinessStatus = ready ? "ready" : "missing";
      }
    }

    const banner = document.getElementById("aikeysNextBanner");
    if (banner) {
      banner.hidden = !ready;
      if (ready) {
        const hasProjects = Array.isArray(state.projects) && state.projects.length > 0;
        const title = document.getElementById("aikeysNextTitle");
        const desc = document.getElementById("aikeysNextDesc");
        const action = document.getElementById("aikeysNextAction");
        if (title) title.textContent = "文本模型已就绪";
        if (desc) {
          desc.textContent = hasProjects
            ? "现在可以回到当前项目运行诊断或生成内容。"
            : "现在可以建立第一个项目，开始你的首次 AI 搜索诊断。";
        }
        if (action) {
          action.textContent = hasProjects ? "返回项目工作台 →" : "下一步：建立第一个项目 →";
        }
      }
    }

    renderOnboardingGuide();
    syncDay1Spotlight(state.selectedProject);
  }

  // ═══════════════════════════════════════════════
  // 3-step first-run onboarding guide
  // ═══════════════════════════════════════════════

  function renderOnboardingGuide() {
    const guide = document.getElementById("onboardingGuide");
    if (!guide) return;
    const badge = document.getElementById("onboardingModelBadge");
    const stepModel = document.getElementById("onboardingStepModel");
    const stepProject = document.getElementById("onboardingStepProject");
    const copy = document.getElementById("onboardingModelCopy");
    const button = document.getElementById("onboardingGoModels");

    if (readinessStatus === "ready") {
      applyBadgeTone(badge, "文本模型已就绪", "success");
      if (stepModel?.dataset) stepModel.dataset.stepState = "done";
      if (stepProject?.dataset) stepProject.dataset.stepState = "current";
      if (copy) copy.textContent = "已配置文本模型，可直接填写下方核心信息建立第一个项目。";
      if (button) {
        button.textContent = "查看模型配置";
        button.classList?.toggle("md-btn--primary", false);
        button.classList?.toggle("md-btn--secondary", true);
      }
    } else if (readinessStatus === "missing") {
      applyBadgeTone(badge, "需要先配文本模型", "warning");
      if (stepModel?.dataset) stepModel.dataset.stepState = "current";
      if (stepProject?.dataset) stepProject.dataset.stepState = "todo";
      if (copy) copy.textContent = "尚未配置文本模型；建议先填自己的 API Key 或连接本机 Magpie，再回来建项目。";
      if (button) {
        button.textContent = "去配置文本模型";
        button.classList?.toggle("md-btn--primary", true);
        button.classList?.toggle("md-btn--secondary", false);
      }
    } else {
      applyBadgeTone(badge, "先确认文本模型", "neutral");
      if (stepModel?.dataset) stepModel.dataset.stepState = "current";
      if (stepProject?.dataset) stepProject.dataset.stepState = "todo";
      if (copy) copy.textContent = "填写自己的模型 API Key 或连接本机 Magpie；配好文本模型即可驱动诊断与写作。";
      if (button) {
        button.textContent = "去配置文本模型";
        button.classList?.toggle("md-btn--primary", true);
        button.classList?.toggle("md-btn--secondary", false);
      }
    }
  }

  function syncOnboardingGuide() {
    renderOnboardingGuide();
    if (!state.selectedProject && currentReadinessScope()) {
      void ensureModelReadiness();
    }
  }

  // ═══════════════════════════════════════════════
  // Day-1 first-skill spotlight on newly created projects
  // ═══════════════════════════════════════════════

  function isDay1Project(project) {
    if (!project || project.status === "completed") return false;
    const currentStage = Number(GEO.resolveCurrentStageIndex?.(project) ?? project.current_stage ?? 0);
    if (currentStage !== 0) return false;
    const recs = Array.isArray(project.workflow_recommendations) ? project.workflow_recommendations : [];
    const hasCompletedSkill = recs.some(
      (row) => Array.isArray(row.completed_skill_ids) && row.completed_skill_ids.length > 0,
    );
    if (hasCompletedSkill) return false;
    const publishedText = document.getElementById("projectHomePublishedValue")?.textContent || "";
    if (/^[1-9]/.test(publishedText.trim())) return false;
    return true;
  }

  function pickDay1Skill() {
    const skills = Array.isArray(state.skills) ? state.skills : [];
    return skills.find((skill) => skill.id === "yao-geo-intent-miner" && skill.available !== false)
      || skills.find((skill) => Number(skill.stage_index) === 0 && skill.available !== false && !skill.action_path)
      || skills.find((skill) => skill.available !== false && !skill.action_path)
      || null;
  }

  function syncDay1Spotlight(project = state.selectedProject) {
    const card = document.getElementById("projectHomeDay1Card");
    if (!card) return;
    if (!isDay1Project(project)) {
      card.hidden = true;
      return;
    }
    card.hidden = false;
    if (currentReadinessScope() && readinessScope !== currentReadinessScope()) {
      void ensureModelReadiness();
    }
    const badge = document.getElementById("projectHomeDay1Badge");
    const title = document.getElementById("projectHomeDay1Title");
    const desc = document.getElementById("projectHomeDay1Desc");
    const primary = document.getElementById("projectHomeDay1Primary");
    const secondary = document.getElementById("projectHomeDay1Secondary");
    day1RecommendedSkill = pickDay1Skill();

    if (readinessStatus === "missing") {
      day1PrimaryMode = "aikeys";
      applyBadgeTone(badge, "需要先配文本模型", "warning");
      if (title) title.textContent = "配好文本模型后，就能运行你的第一个 GEO 技能";
      if (desc) desc.textContent = "项目档案已建好；现在去填写自己的 API Key 或连接本机 Magpie，完成后回到这里即可一键开始诊断。";
      if (primary) primary.textContent = "前往配置文本模型 →";
      if (secondary) secondary.textContent = "先看看技能目录";
    } else {
      day1PrimaryMode = "skill";
      applyBadgeTone(badge, "推荐第一步", "primary");
      if (title) title.textContent = "运行你的第一个 GEO 技能";
      if (desc) {
        desc.textContent = day1RecommendedSkill
          ? `项目档案已就绪。建议先执行「${day1RecommendedSkill.name}」，摸清目标客户在 AI 搜索里最常问的问题。`
          : "项目档案已就绪。先执行意图挖掘或综合诊断，摸清目标客户在 AI 搜索里最常问的问题。";
      }
      if (primary) primary.textContent = day1RecommendedSkill ? `运行「${day1RecommendedSkill.name}」` : "前往技能工作区";
      if (secondary) secondary.textContent = "执行综合诊断";
    }
  }

  // ═══════════════════════════════════════════════
  // Bindings & lifecycle integration
  // ═══════════════════════════════════════════════

  function bindModelSourceSwitchers() {
    const list = elements.aikeyList;
    if (!list || typeof list.addEventListener !== "function") return;
    list.addEventListener("click", (event) => {
      const button = event.target?.closest?.("[data-source-tab]");
      if (!button) return;
      const section = button.closest?.("[data-magpie-slot]");
      setModelSourcePane(section, button.dataset.sourceTab, true);
    });
    list.addEventListener("keydown", (event) => {
      if (event.key !== "ArrowLeft" && event.key !== "ArrowRight") return;
      const button = event.target?.closest?.("[data-source-tab]");
      if (!button) return;
      const group = button.closest?.("[data-source-tabs]");
      const tabs = [...(group?.querySelectorAll?.("[data-source-tab]") || [])];
      if (tabs.length < 2) return;
      event.preventDefault();
      const currentIndex = tabs.indexOf(button);
      const nextTab = tabs[(currentIndex + (event.key === "ArrowRight" ? 1 : -1) + tabs.length) % tabs.length];
      setModelSourcePane(button.closest?.("[data-magpie-slot]"), nextTab.dataset.sourceTab, true);
      nextTab.focus?.();
    });
    list.querySelectorAll?.("details[data-optional-slot]").forEach((details) => {
      details.addEventListener?.("toggle", () => {
        const chevron = details.querySelector?.(".model-optional-chevron");
        if (chevron) chevron.textContent = details.open ? "收起" : "展开";
      });
    });
  }

  function bindDay1Actions() {
    document.getElementById("projectHomeDay1Primary")?.addEventListener("click", () => {
      if (day1PrimaryMode === "aikeys") {
        GEO.openWorkspaceView?.("aikeys");
        return;
      }
      if (day1RecommendedSkill && GEO.selectRecommendedSkill) {
        GEO.selectRecommendedSkill(day1RecommendedSkill);
      } else {
        GEO.openWorkspaceView?.("skill");
      }
    });
    document.getElementById("projectHomeDay1Secondary")?.addEventListener("click", () => {
      if (day1PrimaryMode === "aikeys") {
        GEO.openWorkspaceView?.("skill");
      } else {
        GEO.openAnalysisWorkspace?.();
      }
    });
  }

  const baseRenderProjectHome = GEO.renderProjectHome;
  if (typeof baseRenderProjectHome === "function") {
    GEO.renderProjectHome = function wrappedRenderProjectHome(project) {
      const result = baseRenderProjectHome.apply(this, arguments);
      syncDay1Spotlight(project);
      return result;
    };
  }
  const baseRenderProjectHomeQueue = GEO.renderProjectHomeQueue;
  if (typeof baseRenderProjectHomeQueue === "function") {
    GEO.renderProjectHomeQueue = function wrappedRenderProjectHomeQueue() {
      const result = baseRenderProjectHomeQueue.apply(this, arguments);
      syncDay1Spotlight(state.selectedProject);
      return result;
    };
  }

  bindModelSourceSwitchers();
  bindDay1Actions();
  Object.assign(GEO, {
    syncModelConfigUi,
    isTextModelReady,
    setModelSourcePane,
    syncOnboardingGuide,
    syncDay1Spotlight,
    isDay1Project,
    ensureModelReadiness,
  });
})();
