/* ============================================================================
   GEO 工作台 · 模型配置交互与首次引导（geo-entry-onboarding.js）

   负责：
     1. 模型配置页的来源分段切换（第三方 API 直连 / Magpie），保留两边表单草稿；
     2. 可选能力折叠卡的实时状态徽标同步与文本模型就绪后的「下一步」条；
     3. 首次使用引导状态同步（不发起任何额外模型探测或计费调用）。
   ============================================================================ */
(() => {
  "use strict";

  const GEO = window.GEO;
  if (!GEO) return;
  const { state, elements } = GEO;

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
    return Boolean(directText || magpieText);
  }

  function applyOptionalBadge(slot, text, ready) {
    const badge = elements.aikeyList?.querySelector?.(`[data-optional-status="${slot}"]`);
    if (!badge) return;
    badge.textContent = text;
    if (badge.classList) {
      badge.classList.toggle("md-badge--success", ready);
      badge.classList.toggle("md-badge--neutral", !ready);
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
          applyOptionalBadge(slot, "已连接 Magpie", true);
          continue;
        }
      }
      if (key) {
        applyOptionalBadge(slot, key.is_active ? "已启用" : "已停用", Boolean(key.is_active));
      } else {
        applyOptionalBadge(slot, "未配置", false);
      }
    }

    const banner = document.getElementById("aikeysNextBanner");
    if (banner) {
      const ready = isTextModelReady();
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
  }

  // ═══════════════════════════════════════════════
  // Bindings
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

  bindModelSourceSwitchers();
  Object.assign(GEO, { syncModelConfigUi, isTextModelReady, setModelSourcePane });
})();
