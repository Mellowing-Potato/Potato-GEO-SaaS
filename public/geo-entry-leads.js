/* ============================================================================
   GEO 工作台 · 线索表单管理台（geo-entry-leads.js）

   从 public/geo-entry.js 搬出来的第七块（计划 §7 前端大文件拆分）。
   搬走的是单 8 的整块：三个页签共用一个视图 —— 线索（列表 / 详情抽屉 / 导出）、
   表单管理（字段编辑器 / 启用停用 / 删除 / 复制公开链接）、转化统计（漏斗 / 趋势 /
   来源 / 最近提交），外加两条前端可见性（线索菜单按 role、手动发布菜单按 role）。

   接入方式与前六块一致：主文件暴露 window.GEO，本文件消费它。这一块的对外面只有两处：
     · loadLeadsView —— 进 #leads 视图时的加载器，这里自己 registerViewLoader；
     · applyManualPublishVisibility —— 主文件 bootstrap 里要调（会话就绪后再决定菜单显隐），
       在文件末尾挂回 window.GEO。

   红线（与拆分前逐字一致）：
     - 照 API 契约写，不照原型脑补后端；接口失败一律显式报错并给错误编号，绝不静默回落假数据；
     - 一切进 innerHTML 的文案都过 escapeHtml（表单文案是租户自己填的，等同不可信输入）；
     - 菜单项失效方向必须是"看不见"：取不到角色就保持隐藏，不能反过来。

   判据：tests/Feature/LeadAdminUiTest.php（端点与元素 id 对账，已改为读"主文件 + 拆出块"合集）
   + tests/fixtures/ui/dom-fingerprint.json + npm run test:js。
   ============================================================================ */
(() => {
  const GEO = window.GEO;
  if (!GEO) {
    console.error('[geo-entry-leads] window.GEO 未就绪：请确认本文件在 geo-entry.js 之后加载');
    return;
  }
  const {
    state, elements, api, escapeHtml, formatDate, setMessage, tenantSubdomain,
    confirmWorkspaceAction, errorNoText,
  } = GEO;

  // ═══════════════════════════════════════════════
  // 线索表单管理台（单 8，执行书第 5 步「并进现有工作台，不新开顶级页面」）
  //
  // 三个页签共用一个视图：线索 / 表单 / 转化统计。
  // 红线（design-specs/prototypes/前端接后端-防走偏与纠偏规程-2026-07-15.md）：
  //  - 照 API 契约写，不照原型脑补后端。契约见 LeadFormController / LeadController /
  //    LeadAnalyticsController —— 列表返回 {data, meta, stats}，详情才带 payload / fields。
  //  - 接口失败一律显式报错并给出错误编号，绝不静默回落到假数据。
  //  - 一切进 innerHTML 的文案都过 escapeHtml：表单文案是租户自己填的，等同不可信输入。
  // ═══════════════════════════════════════════════

  const LEAD_STATUS_LABEL = {
    new: "新线索",
    contacted: "已联系",
    qualified: "已确认意向",
    converted: "已成交",
    invalid: "无效",
  };
  const LEAD_STATUS_VARIANT = {
    new: "warning",
    contacted: "neutral",
    qualified: "neutral",
    converted: "success",
    invalid: "danger",
  };
  const LEAD_FIELD_TYPES = [
    ["text", "单行文本"],
    ["phone", "电话"],
    ["email", "邮箱"],
    ["textarea", "多行文本"],
    ["select", "下拉选择"],
    ["checkbox", "勾选框"],
  ];

  function leadMessage(message, variant = "neutral") {
    setMessage(document.getElementById("leadMessage"), message, variant);
  }

  function leadSetState(hostId, name) {
    const host = document.getElementById(hostId);
    if (!host) return;
    host.querySelectorAll("[data-state]").forEach((node) => { node.hidden = node.dataset.state !== name; });
  }

  // 403 是「当前角色不能管线索」，跟服务故障不是一回事，要分开说。
  function leadReportError(error, hostId, errorNoId, what) {
    const node = document.getElementById(errorNoId);
    if (node) node.textContent = errorNoText(error);
    if (error && error.status === 403) {
      leadSetState(hostId, "error");
      leadMessage("当前角色没有线索管理权限（需要管理员或编辑）。", "danger");
      return;
    }
    leadSetState(hostId, "error");
    leadMessage(`${what}失败（错误编号 ${errorNoText(error)}）：${error.message || "未知错误"}`, "danger");
  }

  function leadPublicUrl(slug) {
    const subdomain = tenantSubdomain();
    const prefix = subdomain ? `/forms/${encodeURIComponent(subdomain)}/` : "/forms/<租户标识>/";
    return `${window.location.origin}${prefix}${encodeURIComponent(slug)}`;
  }

  // 视图入口：表单列表是两个筛选下拉的数据源，先拿它再渲染当前页签。
  async function loadLeadsView() {
    // 直接打开 /#leads 时，本函数会在会话建立之前就被 init 调到（所有视图同一形状）。
    // 那时候没有 token，发请求只会拿回 401 并弹一句「加载失败」吓人。
    // 这里先让路，会话就绪后由下面 session 那段回头再调一次。
    if (!state.session) return;
    leadApplyRoleVisibility();
    if (!state.leadFormsLoaded) await loadLeadForms({ silent: true });
    leadActivateTab(state.leadTab);
  }

  // 前端隐藏不是权限（真门在 API 的 can:lead:manage），只是别让 member 点进死路。
  function leadApplyRoleVisibility() {
    const role = state.session?.user?.role || "";
    const allowed = role === "" || role === "admin" || role === "platform_admin" || role === "editor";
    const link = document.getElementById("leadsMenuLink");
    if (link) link.hidden = !allowed;
  }

  // 单 7 手动发布：与上面单 8 同款的前端隐藏，真门仍在 API 的
  // can:manual_publish:manage（Permissions.php 里该权限只给 admin + editor）。
  //
  // 这里刻意与单 8 那处不同：单 8 把 role === "" 也算放行，本处不放行。
  // 菜单项在 blade 里默认 hidden，取不到角色就保持隐藏 —— 失效方向必须是
  // "看不见"。反过来（默认可见 + JS 藏）的话，JS 挂了或角色取不到时，
  // member 就会看见一个点进去整页「无访问权限」的死路。
  function applyManualPublishVisibility() {
    const role = state.session?.user?.role || "";
    if (elements.manualPublishMenuLink) {
      elements.manualPublishMenuLink.hidden = !["admin", "platform_admin", "editor"].includes(role);
    }
    // 单 10 主题包生成：权限口径与手动发布相同（Permissions.php 的
    // theme_package:manage => ['admin','editor']），失效方向同样是"看不见"。
    if (elements.themePackageMenuLink) {
      elements.themePackageMenuLink.hidden = !["admin", "platform_admin", "editor"].includes(role);
    }
  }

  function leadActivateTab(tab) {
    state.leadTab = tab;
    document.querySelectorAll("#leadTabs [data-lead-tab]").forEach((btn) => {
      btn.setAttribute("aria-selected", btn.dataset.leadTab === tab ? "true" : "false");
    });
    document.querySelectorAll("[data-lead-pane]").forEach((pane) => {
      pane.hidden = pane.dataset.leadPane !== tab;
    });
    const exportBtn = document.getElementById("leadExportButton");
    const addBtn = document.getElementById("leadAddFormButton");
    if (exportBtn) exportBtn.hidden = tab !== "submissions";
    if (addBtn) addBtn.hidden = tab !== "forms";
    leadMessage("");
    if (tab === "submissions") loadLeadSubmissions();
    else if (tab === "forms") loadLeadForms();
    else if (tab === "analytics") loadLeadAnalytics();
  }

  // ── 页签一：线索 ────────────────────────────────────────────────

  function leadSubmissionQuery() {
    const params = new URLSearchParams();
    Object.entries(state.leadFilters).forEach(([key, value]) => {
      if (String(value || "").trim() !== "") params.set(key, value);
    });
    if (state.leadPage > 1) params.set("page", String(state.leadPage));
    const qs = params.toString();
    return qs ? `?${qs}` : "";
  }

  async function loadLeadSubmissions() {
    leadSetState("leadHost", "loading");
    try {
      const body = await api(`/api/v1/leads${leadSubmissionQuery()}`);
      state.leadRows = Array.isArray(body.data) ? body.data : [];
      state.leadMeta = body.meta || null;
      renderLeadStats(body.stats || null);
      renderLeadSubmissions();
    } catch (error) {
      leadReportError(error, "leadHost", "leadErrorNo", "线索加载");
    }
  }

  function renderLeadStats(stats) {
    const host = document.getElementById("leadStatRow");
    if (!host) return;
    if (!stats) { host.innerHTML = ""; return; }
    const tiles = [
      ["全部线索", stats.total],
      ["新线索", stats.new],
      ["待跟进", stats.pending],
      ["已成交", stats.converted],
    ];
    host.innerHTML = tiles
      .map(([label, value]) => `<div class="lead-stat"><span class="lead-stat-label">${escapeHtml(label)}</span><strong class="lead-stat-value">${escapeHtml(String(value ?? 0))}</strong></div>`)
      .join("");
  }

  function renderLeadSubmissions() {
    const rows = document.getElementById("leadRows");
    if (!rows) return;
    if (state.leadRows.length === 0) {
      const filtered = Object.values(state.leadFilters).some((value) => String(value || "").trim() !== "");
      const pager = document.getElementById("leadPager");
      if (pager) { pager.hidden = true; pager.innerHTML = ""; }
      leadSetState("leadHost", filtered ? "empty-filtered" : "empty");
      return;
    }
    rows.innerHTML = state.leadRows.map((row) => leadRowHtml(row)).join("");
    renderLeadPager();
    leadSetState("leadHost", "populated");
  }

  // 列表只给摘要，完整内容在详情抽屉里看（列表接口本来就不返回 payload）。
  function leadSummaryText(row) {
    const form = row.form ? row.form.name : "";
    return form ? `来自「${form}」的提交` : "提交记录";
  }

  const LEAD_NOTIFY_LABEL = {
    sent: "已通知",
    queued: "发送中",
    failed: "通知失败",
    skipped: "未配置",
  };

  const LEAD_NOTIFY_VARIANT = {
    sent: "success",
    queued: "neutral",
    failed: "danger",
    skipped: "neutral",
  };

  /**
   * 通知结果格。失败必须看得见 —— 通知发不出去而界面不吭声，
   * 等于租户以为「有新线索会有人告诉我」，实际没人告诉。
   * notify_status 为空是通知功能上线之前的旧线索，显示 "-" 而不是「未配置」。
   */
  function leadNotifyCellHtml(row) {
    const status = String(row.notify_status || "");
    if (status === "") return "-";
    const label = LEAD_NOTIFY_LABEL[status] || status;
    const variant = LEAD_NOTIFY_VARIANT[status] || "neutral";
    const reason = String(row.notify_error || "");
    const title = reason === "" ? "" : ` title="${escapeHtml(reason)}"`;
    return `<span class="badge" data-variant="${variant}"${title}>${escapeHtml(label)}</span>`;
  }

  function leadRowHtml(row) {
    const status = String(row.status || "new");
    const label = LEAD_STATUS_LABEL[status] || status;
    const variant = LEAD_STATUS_VARIANT[status] || "neutral";
    const source = row.source_url ? escapeHtml(row.source_url) : "-";
    return `<tr>
      <td>${escapeHtml(formatDate(row.created_at))}</td>
      <td>${escapeHtml(row.form ? row.form.name : "（表单已删除）")}</td>
      <td>${escapeHtml(leadSummaryText(row))}</td>
      <td class="lead-cell-source" title="${source}">${source}</td>
      <td><span class="badge" data-variant="${variant}">${escapeHtml(label)}</span></td>
      <td>${leadNotifyCellHtml(row)}</td>
      <td>${escapeHtml(row.handler_name || "-")}</td>
      <td class="cl-cell-actions"><button type="button" class="link-btn" data-lead-action="detail" data-id="${row.id}">查看 / 跟进</button></td>
    </tr>`;
  }

  function renderLeadPager() {
    const pager = document.getElementById("leadPager");
    if (!pager) return;
    const meta = state.leadMeta;
    if (!meta || Number(meta.last_page || 1) <= 1) { pager.hidden = true; pager.innerHTML = ""; return; }
    const current = Number(meta.current_page || 1);
    const last = Number(meta.last_page || 1);
    pager.hidden = false;
    pager.innerHTML = `
      <button class="button ghost small" type="button" data-lead-page="${current - 1}" ${current <= 1 ? "disabled" : ""}>上一页</button>
      <span class="lead-pager-label">第 ${current} / ${last} 页，共 ${escapeHtml(String(meta.total ?? 0))} 条</span>
      <button class="button ghost small" type="button" data-lead-page="${current + 1}" ${current >= last ? "disabled" : ""}>下一页</button>`;
  }

  async function openLeadDrawer(id, trigger) {
    const drawer = document.getElementById("leadDrawer");
    const content = document.getElementById("leadDrawerContent");
    if (!drawer || !content) return;
    state.leadDrawerTrigger = trigger || document.activeElement;
    state.leadDrawerInitial = null;
    drawer.hidden = false;
    document.getElementById("leadDrawerClose")?.focus();
    content.textContent = "正在加载线索详情…";
    setMessage(document.getElementById("leadDrawerError"), "");
    try {
      const body = await api(`/api/v1/leads/${encodeURIComponent(id)}`);
      const row = body.data || null;
      if (!row) throw new Error("服务器没有返回线索详情。");
      state.leadDrawerRow = row;
      content.innerHTML = leadDrawerContentHtml(row);
      const form = document.getElementById("leadDrawerForm");
      if (form) {
        form.elements.status.value = row.status || "new";
        form.elements.note.value = row.note || "";
        state.leadDrawerInitial = { status: form.elements.status.value, note: form.elements.note.value };
      }
    } catch (error) {
      state.leadDrawerRow = null;
      content.textContent = `线索详情加载失败（错误编号 ${errorNoText(error)}）：${error.message || "未知错误"}`;
    }
  }

  function leadDrawerContentHtml(row) {
    const payload = row.payload && typeof row.payload === "object" ? row.payload : {};
    const fields = Object.keys(payload).length === 0
      ? `<p class="lead-drawer-empty">这条提交没有字段内容。</p>`
      : `<dl class="lead-kv">${Object.entries(payload).map(([key, value]) => {
          const shown = typeof value === "boolean" ? (value ? "是" : "否") : String(value ?? "");
          return `<dt>${escapeHtml(key)}</dt><dd>${escapeHtml(shown) || "<span class=\"lead-muted\">（空）</span>"}</dd>`;
        }).join("")}</dl>`;
    const meta = [
      ["表单", row.form ? row.form.name : "（表单已删除）"],
      ["提交时间", formatDate(row.created_at)],
      ["来源页面", row.source_url || "-"],
      ["访客 IP", row.ip_address || "-"],
      ["最近处理", row.handled_at ? `${formatDate(row.handled_at)}（${row.handler_name || "未知"}）` : "尚未处理"],
    ];
    return `${fields}<dl class="lead-kv lead-kv-meta">${meta.map(([k, v]) => `<dt>${escapeHtml(k)}</dt><dd>${escapeHtml(String(v))}</dd>`).join("")}</dl>`;
  }

  function closeLeadDrawer(force = false) {
    const drawer = document.getElementById("leadDrawer");
    const form = document.getElementById("leadDrawerForm");
    const initial = state.leadDrawerInitial;
    if (force !== true && initial && form && (
      form.elements.status.value !== initial.status || form.elements.note.value !== initial.note
    ) && !window.confirm("线索详情有未保存的修改，确定关闭吗？")) return;
    if (drawer) drawer.hidden = true;
    state.leadDrawerRow = null;
    state.leadDrawerInitial = null;
    const trigger = state.leadDrawerTrigger;
    state.leadDrawerTrigger = null;
    if (trigger?.isConnected) trigger.focus();
  }

  async function submitLeadDrawer(event) {
    event.preventDefault();
    if (state.leadDrawerSubmitInFlight) return;
    const row = state.leadDrawerRow;
    const errorNode = document.getElementById("leadDrawerError");
    if (!row) { setMessage(errorNode, "线索详情没有加载成功，请关闭后重试。", "danger"); return; }
    const form = event.currentTarget;
    const button = document.getElementById("leadDrawerSubmit");
    state.leadDrawerSubmitInFlight = true;
    if (button) button.disabled = true;
    setMessage(errorNode, "");
    try {
      await api(`/api/v1/leads/${encodeURIComponent(row.id)}`, {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          status: form.elements.status.value,
          // note 一并回传：后端「不传就保留原值」，这里是编辑框，用户清空就该清空。
          note: form.elements.note.value,
        }),
      });
      closeLeadDrawer(true);
      leadMessage("线索已更新。", "success");
      await loadLeadSubmissions();
    } catch (error) {
      setMessage(errorNode, `保存失败（错误编号 ${errorNoText(error)}）：${error.message || "未知错误"}`, "danger");
    } finally {
      state.leadDrawerSubmitInFlight = false;
      if (button) button.disabled = false;
    }
  }

  // ── 页签二：表单管理 ────────────────────────────────────────────

  async function loadLeadForms({ silent = false } = {}) {
    if (!silent) leadSetState("leadFormsHost", "loading");
    try {
      const body = await api("/api/v1/lead-forms");
      state.leadForms = Array.isArray(body.data) ? body.data : [];
      state.leadFormsLoaded = true;
      renderLeadFormFilters();
      if (!silent) renderLeadForms();
    } catch (error) {
      state.leadFormsLoaded = false;
      if (silent) return;
      leadReportError(error, "leadFormsHost", "leadFormsErrorNo", "表单加载");
    }
  }

  function renderLeadFormFilters() {
    const options = `<option value="">全部表单</option>` + state.leadForms
      .map((form) => `<option value="${form.id}">${escapeHtml(form.name)}</option>`)
      .join("");
    ["leadFormFilter", "leadAnalyticsFormFilter"].forEach((id) => {
      const select = document.getElementById(id);
      if (!select) return;
      const previous = select.value;
      select.innerHTML = options;
      // 选中项还在就保留，被删了就回落到「全部表单」——不要留一个指向已删表单的死筛选。
      select.value = Array.from(select.options).some((o) => o.value === previous) ? previous : "";
    });
  }

  function renderLeadForms() {
    const rows = document.getElementById("leadFormRows");
    if (!rows) return;
    if (state.leadForms.length === 0) { leadSetState("leadFormsHost", "empty"); return; }
    rows.innerHTML = state.leadForms.map((form) => leadFormRowHtml(form)).join("");
    leadSetState("leadFormsHost", "populated");
  }

  function leadFormRowHtml(form) {
    const active = form.status === "active";
    const url = leadPublicUrl(form.slug);
    const count = Number(form.submissions_count ?? 0);
    return `<tr>
      <td><strong>${escapeHtml(form.name)}</strong><div class="lead-muted">${escapeHtml(form.description || "")}</div></td>
      <td class="lead-cell-source"><code title="${escapeHtml(url)}">${escapeHtml(url)}</code></td>
      <td><span class="badge" data-variant="${active ? "success" : "neutral"}">${active ? "启用" : "停用"}</span></td>
      <td>${escapeHtml(String(count))}</td>
      <td>${escapeHtml(formatDate(form.created_at))}</td>
      <td class="cl-cell-actions">
        <button type="button" class="link-btn" data-lead-form-action="copy" data-slug="${escapeHtml(form.slug)}">复制链接</button>
        <button type="button" class="link-btn" data-lead-form-action="edit" data-id="${form.id}">编辑</button>
        <button type="button" class="link-btn" data-lead-form-action="toggle" data-id="${form.id}">${active ? "停用" : "启用"}</button>
        <button type="button" class="link-btn danger" data-lead-form-action="delete" data-id="${form.id}" ${count > 0 ? 'title="已有线索的表单不能删除，请改为停用"' : ""}>删除</button>
      </td>
    </tr>`;
  }

  function leadDefaultFields() {
    return [
      { name: "name", label: "姓名", type: "text", required: true, options: [] },
      { name: "phone", label: "电话", type: "phone", required: false, options: [] },
      { name: "message", label: "留言内容", type: "textarea", required: true, options: [] },
    ];
  }

  function renderLeadFieldsEditor(fields) {
    const host = document.getElementById("leadFieldsEditor");
    if (!host) return;
    host.innerHTML = (fields.length ? fields : leadDefaultFields())
      .map((field, index) => leadFieldRowHtml(field, index))
      .join("");
  }

  function leadFieldRowHtml(field, index) {
    const types = LEAD_FIELD_TYPES
      .map(([value, label]) => `<option value="${value}" ${field.type === value ? "selected" : ""}>${escapeHtml(label)}</option>`)
      .join("");
    const needsOptions = field.type === "select" || field.type === "checkbox";
    return `<div class="lead-field-row" data-lead-field-index="${index}">
      <label class="lead-field-control lead-field-label"><span>字段标题（访客可见）</span><input data-lead-field="label" value="${escapeHtml(field.label || "")}" placeholder="例如：姓名" maxlength="80"></label>
      <label class="lead-field-control lead-field-name"><span>系统标识（可留空）</span><input data-lead-field="name" value="${escapeHtml(field.name || "")}" placeholder="留空自动生成，如 name" maxlength="60" spellcheck="false"></label>
      <label class="lead-field-control lead-field-type"><span>填写方式</span><select data-lead-field="type">${types}</select></label>
      <label class="lead-field-control lead-field-options" ${needsOptions ? "" : "hidden"}><span>可选项</span><input data-lead-field="options" value="${escapeHtml((field.options || []).join("、"))}" placeholder="用顿号或换行分隔"></label>
      <label class="lead-field-required"><input type="checkbox" data-lead-field="required" ${field.required ? "checked" : ""}> 必填</label>
      <button type="button" class="link-btn danger" data-lead-field-remove>删除</button>
    </div>`;
  }

  function validateLeadFields(fields) {
    const names = new Set();
    for (let index = 0; index < fields.length; index += 1) {
      const field = fields[index];
      const position = index + 1;
      const label = String(field.label || "").trim();
      const name = String(field.name || "").trim();
      if (label === "") return `第 ${position} 个字段缺少“字段标题”。这里填写访客看到的题目，例如“姓名”。`;
      if (name !== "" && !/^[a-z][a-z0-9_]*$/.test(name)) {
        return `第 ${position} 个字段的“系统标识”格式不正确。请留空让系统自动生成，或填写以小写字母开头、只含小写字母、数字和下划线的标识。`;
      }
      if (name !== "" && names.has(name)) return `系统标识“${name}”重复，请修改或留空自动生成。`;
      if (name !== "") names.add(name);
    }
    return "";
  }

  function collectLeadFields() {
    return Array.from(document.querySelectorAll("#leadFieldsEditor .lead-field-row")).map((row) => ({
      label: row.querySelector('[data-lead-field="label"]').value,
      name: row.querySelector('[data-lead-field="name"]').value,
      type: row.querySelector('[data-lead-field="type"]').value,
      options: row.querySelector('[data-lead-field="options"]').value,
      required: row.querySelector('[data-lead-field="required"]').checked,
    }));
  }

  /**
   * 通知配置区的回填。原值永远拿不到（接口只回掩码），所以：
   * 输入框保持空 = 不改动，掩码只作为提示文字展示，清空走单独的勾选框。
   */
  function leadRenderNotifyState(data) {
    const input = document.getElementById("leadFormEditor")?.elements?.notify_webhook_url;
    const clearWrap = document.getElementById("leadNotifyClearWrap");
    const clearBox = document.getElementById("leadNotifyClear");
    const hint = document.getElementById("leadNotifyHint");
    if (!input) return;
    input.value = "";
    if (clearBox) clearBox.checked = false;
    const configured = Boolean(data && data.notify_webhook_configured);
    if (clearWrap) clearWrap.hidden = !configured;
    const format = (data && data.notify_webhook_format) || "robot";
    const select = document.getElementById("leadFormEditor")?.elements?.notify_webhook_format;
    if (select) select.value = format === "json" ? "json" : "robot";
    if (hint) {
      const existing = hint.querySelector(".lead-notify-current");
      if (existing) existing.remove();
      if (configured) {
        const line = document.createElement("div");
        line.className = "lead-notify-current";
        // 掩码是服务端算好的（只留协议+host+路径首 4 字），这里仍走 textContent，
        // 不拼 innerHTML —— 渲染函数一律不碰 innerHTML 是本模块的规矩。
        line.textContent = `当前已配置：${data.notify_webhook_masked || "（已设置）"}。留空＝保持不变。`;
        hint.appendChild(line);
      }
    }
    input.placeholder = configured
      ? "留空＝不改动；要换地址就填新的"
      : "https:// 开头的机器人地址，留空＝不通知";
  }

  async function openLeadFormEditor(id) {
    const panel = document.getElementById("leadFormEditorPanel");
    const form = document.getElementById("leadFormEditor");
    const title = document.getElementById("leadFormEditorTitle");
    if (!panel || !form) return;
    setMessage(document.getElementById("leadFormEditorError"), "");
    panel.hidden = false;
    state.leadEditingFormId = id || null;
    if (!id) {
      title.textContent = "新建表单";
      form.reset();
      form.elements.status.value = "active";
      leadRenderNotifyState(null);
      renderLeadFieldsEditor([]);
      return;
    }
    title.textContent = "编辑表单";
    try {
      // 列表接口不返回 fields（只有详情带），编辑必须重新取一次，不能拿列表数据凑。
      const body = await api(`/api/v1/lead-forms/${encodeURIComponent(id)}`);
      const data = body.data || {};
      form.elements.name.value = data.name || "";
      form.elements.slug.value = data.slug || "";
      form.elements.status.value = data.status || "active";
      form.elements.description.value = data.description || "";
      form.elements.submit_button_label.value = data.submit_button_label || "";
      form.elements.success_message.value = data.success_message || "";
      leadRenderNotifyState(data);
      renderLeadFieldsEditor(Array.isArray(data.fields) ? data.fields : []);
    } catch (error) {
      panel.hidden = true;
      state.leadEditingFormId = null;
      leadMessage(`表单详情加载失败（错误编号 ${errorNoText(error)}）：${error.message || "未知错误"}`, "danger");
    }
  }

  async function submitLeadFormEditor(event) {
    event.preventDefault();
    if (state.leadFormSubmitInFlight) return;
    const form = event.currentTarget;
    const errorNode = document.getElementById("leadFormEditorError");
    const button = document.getElementById("leadFormEditorSubmit");
    const fields = collectLeadFields();
    if (fields.length === 0) {
      setMessage(errorNode, "至少要留一个有名称的字段。", "danger");
      return;
    }
    const fieldError = validateLeadFields(fields);
    if (fieldError) {
      setMessage(errorNode, fieldError, "danger");
      return;
    }
    const payload = {
      name: form.elements.name.value,
      slug: form.elements.slug.value,
      status: form.elements.status.value,
      description: form.elements.description.value,
      submit_button_label: form.elements.submit_button_label.value,
      success_message: form.elements.success_message.value,
      notify_webhook_format: form.elements.notify_webhook_format.value,
      fields,
    };
    // 通知地址是「只写不读」的：接口回的是掩码，前端手里从来没有原值，
    // 所以空输入框只能理解成「不改动」，绝不能当成「清空」——否则租户
    // 改一下表单名就会把通知配置顺手抹掉。清空必须是勾选那个开关的显式动作。
    const notifyUrl = String(form.elements.notify_webhook_url.value || "").trim();
    if (form.elements.notify_webhook_clear && form.elements.notify_webhook_clear.checked) {
      payload.notify_webhook_url = "";
    } else if (notifyUrl !== "") {
      payload.notify_webhook_url = notifyUrl;
    }
    const id = state.leadEditingFormId;
    state.leadFormSubmitInFlight = true;
    if (button) button.disabled = true;
    setMessage(errorNode, "");
    try {
      const body = await api(id ? `/api/v1/lead-forms/${encodeURIComponent(id)}` : "/api/v1/lead-forms", {
        method: id ? "PUT" : "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      document.getElementById("leadFormEditorPanel").hidden = true;
      state.leadEditingFormId = null;
      await loadLeadForms();
      const saved = body.data || {};
      leadMessage(id ? "表单已保存。" : `表单已创建，公开链接：${leadPublicUrl(saved.slug || payload.slug)}`, "success");
    } catch (error) {
      // 422 的字段级错误要摊开说，不然用户只看到「请求失败」不知道改哪。
      const details = error.body && error.body.errors && typeof error.body.errors === "object"
        ? Object.values(error.body.errors).flat().join("；")
        : "";
      const networkMessage = !Number.isFinite(Number(error?.status))
        ? "网络连接中断，请确认网络恢复后再次保存。当前填写内容仍保留在页面中。"
        : "";
      setMessage(errorNode, details || networkMessage || `${error.message || "保存失败"}（错误编号 ${errorNoText(error)}）`, "danger");
    } finally {
      state.leadFormSubmitInFlight = false;
      if (button) button.disabled = false;
    }
  }

  async function toggleLeadFormStatus(id) {
    try {
      await api(`/api/v1/lead-forms/${encodeURIComponent(id)}/toggle-status`, { method: "POST" });
      await loadLeadForms();
      leadMessage("表单状态已切换。", "success");
    } catch (error) {
      leadMessage(`切换失败（错误编号 ${errorNoText(error)}）：${error.message || "未知错误"}`, "danger");
    }
  }

  async function deleteLeadForm(id) {
    const target = state.leadForms.find((form) => String(form.id) === String(id));
    const name = target ? target.name : "该表单";
    if (!await confirmWorkspaceAction(`确定删除「${name}」？删除后公开链接立即失效。`)) return;
    try {
      await api(`/api/v1/lead-forms/${encodeURIComponent(id)}`, { method: "DELETE" });
      await loadLeadForms();
      leadMessage("表单已删除。", "success");
    } catch (error) {
      // 后端对「已有线索的表单」回 422 并带明确文案（让改用停用），照原样透出。
      leadMessage(error.message || `删除失败（错误编号 ${errorNoText(error)}）`, "danger");
    }
  }

  async function copyLeadFormLink(slug) {
    const url = leadPublicUrl(slug);
    try {
      await navigator.clipboard.writeText(url);
      leadMessage(`公开链接已复制：${url}`, "success");
    } catch (_) {
      // 浏览器不给剪贴板权限时如实说，并把链接显示出来让人手抄，不假装复制成功。
      leadMessage(`浏览器拒绝了剪贴板访问，请手动复制：${url}`, "warning");
    }
  }

  // ── 页签三：转化统计 ────────────────────────────────────────────

  async function loadLeadAnalytics() {
    leadSetState("leadAnalyticsHost", "loading");
    const params = new URLSearchParams();
    Object.entries(state.leadAnalyticsQuery).forEach(([key, value]) => {
      if (String(value || "").trim() !== "") params.set(key, value);
    });
    try {
      const body = await api(`/api/v1/lead-analytics?${params.toString()}`);
      renderLeadAnalytics(body.summary || null);
    } catch (error) {
      leadReportError(error, "leadAnalyticsHost", "leadAnalyticsErrorNo", "统计加载");
    }
  }

  function renderLeadAnalytics(summary) {
    if (!summary) { leadSetState("leadAnalyticsHost", "empty"); return; }
    const kpis = summary.kpis || {};
    const funnel = summary.funnel || {};
    const kpiHost = document.getElementById("leadAnalyticsKpis");
    if (kpiHost) {
      const tiles = [
        ["提交数", kpis.submissions ?? 0],
        ["新线索", kpis.new ?? 0],
        ["待跟进", kpis.pending ?? 0],
        ["已成交", kpis.converted ?? 0],
        ["成交率", `${kpis.conversion_rate ?? 0}%`],
      ];
      kpiHost.innerHTML = tiles
        .map(([label, value]) => `<div class="lead-stat"><span class="lead-stat-label">${escapeHtml(label)}</span><strong class="lead-stat-value">${escapeHtml(String(value))}</strong></div>`)
        .join("");
    }
    renderLeadFunnel(funnel);
    renderLeadTrend(Array.isArray(summary.trend) ? summary.trend : []);
    renderLeadSources(Array.isArray(summary.sources) ? summary.sources : []);
    renderLeadRecent(Array.isArray(summary.recent) ? summary.recent : []);
    leadSetState("leadAnalyticsHost", "populated");
  }

  function renderLeadFunnel(funnel) {
    const host = document.getElementById("leadFunnel");
    if (!host) return;
    // views_tracked=false 表示采集表不在（迁移没跑），跟「没人访问」不是一回事，要说清楚。
    if (funnel.views_tracked === false) {
      host.innerHTML = `<p class="lead-muted">访问采集未启用，暂时只能看提交与成交：提交 ${escapeHtml(String(funnel.submissions ?? 0))}、成交 ${escapeHtml(String(funnel.converted ?? 0))}。</p>`;
      return;
    }
    const views = Number(funnel.views ?? 0);
    const submissions = Number(funnel.submissions ?? 0);
    const converted = Number(funnel.converted ?? 0);
    const steps = [
      ["打开表单页", views, 100],
      ["提交线索", submissions, views > 0 ? (submissions * 100) / views : 0],
      ["成交", converted, views > 0 ? (converted * 100) / views : 0],
    ];
    host.innerHTML = `${steps.map(([label, value, pct]) => `
      <div class="lead-funnel-step">
        <div class="lead-funnel-head"><span>${escapeHtml(label)}</span><strong>${escapeHtml(String(value))}</strong></div>
        <div class="lead-funnel-bar"><span style="width:${Math.max(0, Math.min(100, pct)).toFixed(1)}%"></span></div>
      </div>`).join("")}
      <p class="lead-muted">浏览→提交 ${escapeHtml(String(funnel.view_to_submission_rate ?? 0))}% · 提交→成交 ${escapeHtml(String(funnel.submission_to_converted_rate ?? 0))}%。浏览量只数成功打开的页面，不含 404。</p>`;
  }

  function renderLeadTrend(trend) {
    const host = document.getElementById("leadTrend");
    if (!host) return;
    if (trend.length === 0) { host.innerHTML = `<p class="lead-muted">这段时间没有数据。</p>`; return; }
    const peak = Math.max(1, ...trend.map((point) => Number(point.views ?? 0)));
    host.innerHTML = trend.map((point) => {
      const views = Number(point.views ?? 0);
      const submissions = Number(point.submissions ?? 0);
      const converted = Number(point.converted ?? 0);
      const height = Math.round((views / peak) * 100);
      const date = String(point.date || "");
      return `<div class="lead-trend-col" title="${escapeHtml(date)}：浏览 ${views} / 提交 ${submissions} / 成交 ${converted}">
        <div class="lead-trend-bar"><span style="height:${height}%"></span></div>
        <div class="lead-trend-sub">${escapeHtml(date.slice(5))}</div>
      </div>`;
    }).join("");
  }

  function renderLeadSources(sources) {
    const host = document.getElementById("leadSources");
    if (!host) return;
    if (sources.length === 0) { host.innerHTML = `<p class="lead-muted">还没有来源数据。</p>`; return; }
    host.innerHTML = `<table class="cl-tbl"><thead><tr><th>来源页面</th><th>提交</th><th>成交</th></tr></thead><tbody>${
      sources.map((item) => `<tr><td class="lead-cell-source">${escapeHtml(item.source || "（直接访问）")}</td><td>${escapeHtml(String(item.submissions ?? 0))}</td><td>${escapeHtml(String(item.converted ?? 0))}</td></tr>`).join("")
    }</tbody></table>`;
  }

  function renderLeadRecent(recent) {
    const host = document.getElementById("leadRecent");
    if (!host) return;
    if (recent.length === 0) { host.innerHTML = `<p class="lead-muted">还没有提交。</p>`; return; }
    host.innerHTML = `<ul class="lead-recent">${recent.map((row) => {
      const status = LEAD_STATUS_LABEL[row.status] || row.status || "";
      const formName = row.form && row.form.name ? row.form.name : "（表单已删除）";
      return `<li><span class="lead-recent-time">${escapeHtml(formatDate(row.created_at))}</span><span>${escapeHtml(formName)}</span><span class="badge" data-variant="${LEAD_STATUS_VARIANT[row.status] || "neutral"}">${escapeHtml(status)}</span></li>`;
    }).join("")}</ul>`;
  }

  // ── 事件绑定 ────────────────────────────────────────────────────

  document.querySelectorAll("#leadTabs [data-lead-tab]").forEach((tab) =>
    tab.addEventListener("click", () => leadActivateTab(tab.dataset.leadTab)));
  document.getElementById("leadRetryButton")?.addEventListener("click", loadLeadSubmissions);
  document.getElementById("leadFormsRetryButton")?.addEventListener("click", () => loadLeadForms());
  document.getElementById("leadAnalyticsRetryButton")?.addEventListener("click", loadLeadAnalytics);
  document.getElementById("leadAddFormButton")?.addEventListener("click", () => openLeadFormEditor(null));
  document.getElementById("leadFormEditorCancel")?.addEventListener("click", () => {
    document.getElementById("leadFormEditorPanel").hidden = true;
    state.leadEditingFormId = null;
  });
  document.getElementById("leadFormEditor")?.addEventListener("submit", submitLeadFormEditor);
  document.getElementById("leadAddFieldButton")?.addEventListener("click", () => {
    const host = document.getElementById("leadFieldsEditor");
    if (!host) return;
    const index = host.querySelectorAll(".lead-field-row").length;
    host.insertAdjacentHTML("beforeend", leadFieldRowHtml({ name: "", label: "", type: "text", required: false, options: [] }, index));
  });
  document.getElementById("leadFieldsEditor")?.addEventListener("click", (event) => {
    const remove = event.target.closest("[data-lead-field-remove]");
    if (!remove) return;
    remove.closest(".lead-field-row")?.remove();
  });
  // 选项框只对下拉/勾选有意义，切类型时跟着显隐。
  document.getElementById("leadFieldsEditor")?.addEventListener("change", (event) => {
    const select = event.target.closest('[data-lead-field="type"]');
    if (!select) return;
    const row = select.closest(".lead-field-row");
    const options = row?.querySelector(".lead-field-options");
    if (options) options.hidden = !["select", "checkbox"].includes(select.value);
  });
  document.getElementById("leadFormRows")?.addEventListener("click", (event) => {
    const button = event.target.closest("[data-lead-form-action]");
    if (!button) return;
    const action = button.dataset.leadFormAction;
    if (action === "copy") copyLeadFormLink(button.dataset.slug);
    else if (action === "edit") openLeadFormEditor(button.dataset.id);
    else if (action === "toggle") toggleLeadFormStatus(button.dataset.id);
    else if (action === "delete") deleteLeadForm(button.dataset.id);
  });
  document.getElementById("leadRows")?.addEventListener("click", (event) => {
    const button = event.target.closest("[data-lead-action]");
    if (!button) return;
    if (button.dataset.leadAction === "detail") openLeadDrawer(button.dataset.id, button);
  });
  document.getElementById("leadPager")?.addEventListener("click", (event) => {
    const button = event.target.closest("[data-lead-page]");
    if (!button || button.disabled) return;
    state.leadPage = Math.max(1, Number(button.dataset.leadPage) || 1);
    loadLeadSubmissions();
  });
  document.getElementById("leadFilterForm")?.addEventListener("submit", (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    state.leadFilters = {
      status: form.elements.status.value,
      form_id: form.elements.form_id.value,
      date_from: form.elements.date_from.value,
      date_to: form.elements.date_to.value,
      search: form.elements.search.value,
    };
    state.leadPage = 1;
    loadLeadSubmissions();
  });
  document.getElementById("leadFilterReset")?.addEventListener("click", () => {
    const form = document.getElementById("leadFilterForm");
    if (form) form.reset();
    state.leadFilters = { status: "", form_id: "", date_from: "", date_to: "", search: "" };
    state.leadPage = 1;
    loadLeadSubmissions();
  });
  document.getElementById("leadEmptyReset")?.addEventListener("click", () => {
    document.getElementById("leadFilterReset")?.click();
  });
  document.getElementById("leadDrawerForm")?.addEventListener("submit", submitLeadDrawer);
  document.getElementById("leadDrawerClose")?.addEventListener("click", closeLeadDrawer);
  document.getElementById("leadDrawerMask")?.addEventListener("click", closeLeadDrawer);
  document.getElementById("leadDrawer")?.addEventListener("keydown", (event) => {
    if (event.key === "Escape") { event.preventDefault(); closeLeadDrawer(); return; }
    if (event.key !== "Tab") return;
    const focusable = [...event.currentTarget.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href]')]
      .filter((element) => element.getClientRects().length > 0);
    if (focusable.length === 0) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  });
  document.getElementById("leadAnalyticsPreset")?.addEventListener("change", (event) => {
    const custom = event.target.value === "custom";
    const form = document.getElementById("leadAnalyticsFilterForm");
    if (!form) return;
    form.elements.lead_date_from.hidden = !custom;
    form.elements.lead_date_to.hidden = !custom;
  });
  document.getElementById("leadAnalyticsFilterForm")?.addEventListener("submit", (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const preset = form.elements.lead_preset.value;
    state.leadAnalyticsQuery = {
      lead_preset: preset,
      lead_date_from: preset === "custom" ? form.elements.lead_date_from.value : "",
      lead_date_to: preset === "custom" ? form.elements.lead_date_to.value : "",
      lead_form_id: form.elements.lead_form_id.value,
    };
    loadLeadAnalytics();
  });

  // ═══════════════════════════════════════════════
  // 注册与回挂（拆分前这些都在 geo-entry.js 里）
  // ═══════════════════════════════════════════════

  // 进 #leads 时的数据加载：主文件统一按视图取（runViewLoaders），这里登记自己不写 if。
  GEO.registerViewLoader("leads", () => loadLeadsView());

  // 主文件 bootstrap 里要调（会话就绪后再决定手动发布菜单的显隐）。
  Object.assign(GEO, { applyManualPublishVisibility, leadSubmissionQuery });
})();
