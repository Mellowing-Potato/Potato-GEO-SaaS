/* ============================================================================
   GEO 工作台 · 管理面板五视图（geo-entry-management.js）

   从 public/geo-entry.js（1.4 万行单个 IIFE）搬出来的第一块（计划 §7 前端大文件拆分）。
   搬的是 2026-09-21「工作台功能补齐」新增的入口，它们内聚度最高、与其它面板没有共享状态：
   （API 令牌那一块 2026-09-22 已按用户口径撤出：租户不得创建/吊销凭据。）

     UI   URL 文章导入     /api/v1/url-imports
     CAT  文章分类管理     /api/v1/categories

   接入方式照仓库已有先例（geo-workbench.js 暴露 window.GW、geo-workbench-views.js 消费）：
   geo-entry.js 暴露 window.GEO（共享 state / elements / 辅助函数 + 两个注册表），
   本文件只做三件事：注册视图加载器、注册财务面的一块附加加载器、绑定自己的表单与行内动作。

   红线（与拆分前逐字一致，行为不变）：
     - 不动任何 API 路径 / 表单 name / Sanctum token / 路由契约；
     - 三页列表的错误态照样如实写（403 = 没有权限，不是"暂无数据"），见 UI 规则 R29/R30；
     - 列表行内动作走事件委托，重新渲染多少次都不必重绑。

   判据：tests/fixtures/ui/dom-fingerprint.json（真 Chrome 逐视图 DOM/API 指纹，
   scripts/snapshot-ui-dom.mjs 比对）+ npm run test:js 的契约测试 + 验收工具里的流程检查。
   ============================================================================ */
(() => {
  const GEO = window.GEO;
  if (!GEO) {
    // 顺序问题（本文件必须在 geo-entry.js 之后加载）要显式报错，不能静默少五个入口。
    console.error('[geo-entry-management] window.GEO 未就绪：请确认 geo-entry-management.js 在 geo-entry.js 之后加载');
    return;
  }
  // 这些名字与拆分前完全同名 —— 搬动只改"住在哪个文件里"。
  const {
    state, elements, api, escapeHtml, formatDate, formatNumber, setMessage,
    validationMessage, promptWorkspaceValue, confirmWorkspaceAction, toast,
  } = GEO;

  // ═══════════════════════════════════════════════
  // 2026-09-21 工作台功能补齐（计划 §5）
  // 后端早已存在、正式工作台此前没有入口的能力：
  //   UI  URL 文章导入      /api/v1/url-imports       权限 article:create / article:view
  //   CAT 文章分类管理      /api/v1/categories        权限 project:view / category:manage
  // 列表的错误态一律显式写清（403 = 没有权限，不是"暂无数据"），
  // 不把读不到渲染成 0 或空，见 UI 规则 R29/R30。
  // ═══════════════════════════════════════════════

  const URL_IMPORT_STATUS = {
    pending: ["排队中", "neutral"],
    processing: ["抓取中", "warning"],
    completed: ["已生成草稿", "success"],
    failed: ["失败", "danger"],
  };

  function urlImportStatusBadge(status) {
    const [label, variant] = URL_IMPORT_STATUS[status] || [String(status || "未知"), "neutral"];
    return `<span class="badge" data-variant="${variant}">${escapeHtml(label)}</span>`;
  }

  /** 列表页统一的权限/失败文案：403 是权限问题，不能显示成"暂无记录"。 */
  function managementListErrorMessage(error, actionLabel) {
    if (error?.status === 403) return `当前账号没有${actionLabel}的权限。`;
    if (error?.status === 401) return "登录状态已失效，请重新登录后重试。";
    return error?.message || `无法加载${actionLabel}，请点击刷新重试。`;
  }

  function currentProjectDisplay() {
    return state.selectedProject
      ? `${state.selectedProject.name || state.selectedProject.id}（#${state.selectedProject.id}）`
      : "请先选择项目";
  }

  // ═══════════════════════════════════════════════

  // URL 导入

  // ═══════════════════════════════════════════════
  async function loadUrlImports() {
    const projInput = elements.urlImportForm?.querySelector('[name="project_id_display"]');
    if (projInput) projInput.value = currentProjectDisplay();
    if (elements.urlImportRows) {
      elements.urlImportRows.innerHTML = `<tr><td colspan="4" class="muted">正在加载导入记录…</td></tr>`;
    }
    try {
      const body = await api("/api/v1/url-imports");
      state.urlImports = Array.isArray(body.data) ? body.data : [];
      renderUrlImports();
      setMessage(elements.urlImportMessage, `共 ${state.urlImports.length} 条导入记录。`);
      return true;
    } catch (error) {
      state.urlImports = [];
      if (elements.urlImportRows) {
        elements.urlImportRows.innerHTML = `<tr><td colspan="4" class="muted">${escapeHtml(managementListErrorMessage(error, "查看 URL 导入记录"))}</td></tr>`;
      }
      setMessage(elements.urlImportMessage, managementListErrorMessage(error, "查看 URL 导入记录"), "danger");
      return false;
    }
  }

  function renderUrlImports() {
    if (!elements.urlImportRows) return;
    if (state.urlImports.length === 0) {
      elements.urlImportRows.innerHTML = `<tr><td colspan="4" class="muted">还没有导入记录。在上方粘贴网页地址即可开始。</td></tr>`;
      return;
    }
    elements.urlImportRows.innerHTML = state.urlImports.map((item) => {
      const url = String(item.url || "");
      const title = item.title ? escapeHtml(item.title) : "";
      const failure = item.error_message ? escapeHtml(String(item.error_message)) : "";
      const detail = ([title, failure].filter(Boolean).join(" · ") || "—") + (item.status === "failed" ? ` <button type="button" class="button ghost small" data-url-import-retry="${escapeHtml(url)}">重新提交此地址</button>` : "");
      // 已生成草稿的记录给出回到文章列表的路径（R22：跳进工具要能走回来）。
      const article = item.article_id
        ? ` <a href="#content-library" class="md-link">查看文章 #${escapeHtml(String(item.article_id))}</a>`
        : "";
      return `<tr>
        <td><button type="button" class="button ghost small" data-url-import-detail="${escapeHtml(String(item.id))}">${escapeHtml(url)}</button></td>
        <td>${urlImportStatusBadge(item.status)}</td>
        <td>${detail}${article}</td>
        <td>${escapeHtml(formatDate(item.created_at))}</td>
      </tr>`;
    }).join("");
  }

  async function submitUrlImport(event) {
    event.preventDefault();
    const form = elements.urlImportForm;
    if (!form) return;
    const message = elements.urlImportFormMessage;
    if (!state.selectedProject) {
      setMessage(message, "请先在左上角选择一个项目，再提交 URL 导入。", "warning");
      return;
    }
    const urlValue = String(new FormData(form).get("url") || "").trim();
    if (urlValue === "") {
      setMessage(message, "请填写要导入的网页地址。", "danger");
      return;
    }
    const submitBtn = elements.urlImportSubmitButton;
    try {
      if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = "提交中…"; }
      await api("/api/v1/url-imports", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ url: urlValue, project_id: state.selectedProject.id }),
      });
      setMessage(message, "已提交，正在后台抓取；状态会自动更新。", "success");
      form.reset();
      await loadUrlImports();
    } catch (error) {
      setMessage(message, validationMessage(error), "danger");
    } finally {
      if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = "开始导入"; }
    }
  }

  // ═══════════════════════════════════════════════

  // 文章分类

  // ═══════════════════════════════════════════════
  async function loadCategories() {
    if (elements.categoryRows) {
      elements.categoryRows.innerHTML = `<tr><td colspan="5" class="muted">正在加载分类…</td></tr>`;
    }
    try {
      const body = await api("/api/v1/categories?with_counts=1");
      state.categories = Array.isArray(body.data) ? body.data : [];
      renderCategories();
      setMessage(elements.categoryMessage, `共 ${state.categories.length} 个分类。`);
      return true;
    } catch (error) {
      state.categories = [];
      const text = managementListErrorMessage(error, "管理文章分类");
      if (elements.categoryRows) {
        elements.categoryRows.innerHTML = `<tr><td colspan="5" class="muted">${escapeHtml(text)}</td></tr>`;
      }
      setMessage(elements.categoryMessage, text, "danger");
      return false;
    }
  }

  function renderCategories() {
    if (!elements.categoryRows) return;
    if (state.categories.length === 0) {
      elements.categoryRows.innerHTML = `<tr><td colspan="5" class="muted">还没有分类。在上方添加第一个分类。</td></tr>`;
      return;
    }
    elements.categoryRows.innerHTML = state.categories.map((item) => `
      <tr>
        <td>${escapeHtml(item.name || "")}</td>
        <td><span class="cl-cell-sub">${escapeHtml(item.slug || "—")}</span></td>
        <td>${item.articles_count === null || item.articles_count === undefined ? "—" : escapeHtml(String(item.articles_count))}</td>
        <td>${escapeHtml(String(item.sort_order ?? 0))}</td>
        <td class="cl-cell-actions">
          <button class="button secondary small" type="button" data-cat-rename="${escapeHtml(String(item.id))}" data-cat-current="${escapeHtml(item.name || "")}">改名</button>
          <button class="button ghost small" type="button" data-cat-delete="${escapeHtml(String(item.id))}" data-cat-name="${escapeHtml(item.name || "")}">删除</button>
        </td>
      </tr>
    `).join("");
  }

  async function submitCategory(event) {
    event.preventDefault();
    const form = elements.categoryForm;
    if (!form) return;
    const message = elements.categoryFormMessage;
    const data = new FormData(form);
    const name = String(data.get("name") || "").trim();
    if (name === "") {
      setMessage(message, "请填写分类名称。", "danger");
      return;
    }
    const payload = { name };
    const slug = String(data.get("slug") || "").trim();
    const description = String(data.get("description") || "").trim();
    const sortOrder = String(data.get("sort_order") || "").trim();
    if (slug !== "") payload.slug = slug;
    if (description !== "") payload.description = description;
    if (sortOrder !== "") payload.sort_order = Number(sortOrder);
    const submitBtn = elements.categorySubmitButton;
    try {
      if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = "保存中…"; }
      await api("/api/v1/categories", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      setMessage(message, "分类已添加。", "success");
      form.reset();
      await loadCategories();
    } catch (error) {
      setMessage(message, validationMessage(error), "danger");
    } finally {
      if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = "添加分类"; }
    }
  }

  async function renameCategory(id, currentName) {
    const next = await promptWorkspaceValue("输入新的分类名称：", currentName);
    if (next === null) return;
    const name = String(next).trim();
    if (name === "" || name === currentName) return;
    try {
      await api(`/api/v1/categories/${encodeURIComponent(id)}`, {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ name }),
      });
      toast("分类已更新。", "success");
      await loadCategories();
    } catch (error) {
      toast(validationMessage(error), "danger");
    }
  }

  async function deleteCategory(id, name) {
    if (!await confirmWorkspaceAction(`删除分类「${name}」？分类下的文章不会被删除，只是不再归属于这个分类。`)) return;
    try {
      await api(`/api/v1/categories/${encodeURIComponent(id)}`, { method: "DELETE" });
      toast("分类已删除。", "success");
      await loadCategories();
    } catch (error) {
      toast(error.message || "删除失败。", "danger");
    }
  }

  // ═══════════════════════════════════════════════

  // ── 注册与绑定（拆分前这些都在 geo-entry.js 里）──────────────────────────

  // 三个独立视图的加载器：进页面就回读账户级列表，状态不停在旧快照。
  GEO.registerViewLoader("url-imports", loadUrlImports);
  GEO.registerViewLoader("categories", loadCategories);
  // 财务面的一块附加视图：由主文件的 loadBillingOverview 取用，与余额并行拉取、各自独立失败。

  elements.urlImportForm?.addEventListener("submit", submitUrlImport);
  elements.urlImportsReloadButton?.addEventListener("click", loadUrlImports);
  elements.categoryForm?.addEventListener("submit", submitCategory);
  elements.categoriesReloadButton?.addEventListener("click", loadCategories);
  // 列表行内动作走委托：重新渲染多少次都不必重绑。
  elements.categoryRows?.addEventListener("click", (event) => {
    const rename = event.target.closest("[data-cat-rename]");
    if (rename) { renameCategory(rename.dataset.catRename, rename.dataset.catCurrent || ""); return; }
    const remove = event.target.closest("[data-cat-delete]");
    if (remove) deleteCategory(remove.dataset.catDelete, remove.dataset.catName || "");
  });
})();
