/* ============================================================================
   GEO 工作台 · 发布与渠道（geo-entry-publishing.js）

   从 public/geo-entry.js 搬出来的第六块（计划 §7 前端大文件拆分，拆的是"发布"这一域）。
   搬的是发布视图的三段：发布渠道列表/详情（含健康检查、日志、重写规则、分类标签同步）、
   站内文件（robots.txt / sitemap）、以及项目与网站接口、平台账号的明确绑定。

   接入方式与前五块一致：主文件暴露 window.GEO，本文件消费它；主文件还需要用的入口
   （publishingChannelTypeLabel —— 文章发布抽屉里在显示渠道类型）在文件末尾挂回 window.GEO。

  红线（与拆分前逐字一致）：
     - 不动任何 API 路径（/api/v1/publishing/…）与表单字段名；
     - 渠道详情的打开/关闭行为不动；失败一律走 setMessage(..., "danger") 与 errorNoText 口径，不静默。

   2026-09-22 追加红线（用户口径：租户只做积分展示，没有 key 与令牌的创建/删除）：
     - 本文件**不得**再出现凭据入口 —— 密钥管理（生成/撤销）、Agent 包、WP 信标插件
       三者都依赖渠道密钥，属运营动作，已从租户模板与接线里移除（后端端点保留）。
     - 判据：tests/js/wp-channel-beacon-ui.test.mjs 断言租户侧这三类入口不存在。

   判据：tests/fixtures/ui/dom-fingerprint.json（视图 DOM/文本/API 指纹）+
   npm run test:js 契约测试 + scripts/verify-ui-panels.mjs 的布局/触屏判据。
   ============================================================================ */
(() => {
  const GEO = window.GEO;
  if (!GEO) {
    console.error('[geo-entry-publishing] window.GEO 未就绪：请确认本文件在 geo-entry.js 之后加载');
    return;
  }
  const {
    state, elements, api, escapeHtml, setMessage, validationMessage,
    confirmWorkspaceAction, withBusyButton, showWorkspaceNotice,
    formatDate, copyText, errorNoText,
  } = GEO;

  // ═══════════════════════════════════════════════
  // Publishing / Distribution
  // ═══════════════════════════════════════════════

  function publishingChannelTypeLabel(type) {
    const labels = {
      hosted_page: "站内托管页（已退役）",
      wordpress_rest: "WordPress REST",
      http_webhook: "HTTP Webhook",
      geoflow_agent: "GEOFlow Agent",
    };
    return labels[String(type || "")] || type || "-";
  }

  function setChannelFormOpen(open) {
    if (open) closeChannelDetail();
    if (elements.channelForm) elements.channelForm.hidden = !open;
    elements.addChannelButton?.setAttribute("aria-expanded", String(open));
    if (elements.addChannelButton) elements.addChannelButton.textContent = open ? "收起添加" : "添加网站接口";
    if (open) elements.createChannelForm?.elements.name?.focus();
    else elements.addChannelButton?.focus();
  }

  function syncCreateChannelFields() {
    const form = elements.createChannelForm;
    if (!form) return;
    form.querySelectorAll("[data-external-channel-field]").forEach((field) => {
      field.hidden = false;
    });
    if (form.elements.endpoint_url) {
      form.elements.endpoint_url.required = true;
    }
  }

  async function loadChannels() {
    setMessage(elements.publishingMessage, "正在加载发布渠道...");
    try {
      const body = await api("/api/v1/publishing/channels");
      state.channels = body.data || body || [];
      renderChannels();
      setMessage(elements.publishingMessage, `共 ${state.channels.length} 个网站接口（含历史记录）。`);
    } catch (error) {
      setMessage(elements.publishingMessage, error.message || "无法加载渠道。", "danger");
    }
  }

  function renderChannels() {
    if (!elements.channelList) return;
    elements.channelList.innerHTML = "";
    if (state.channels.length === 0) {
      elements.channelList.innerHTML = '<div class="empty">尚未配置网站接口，可直接使用下方的平台账号。</div>';
      return;
    }
    state.channels.forEach((ch) => {
      const item = document.createElement("button");
      item.type = "button";
      item.className = "history-item channel-item";
      const statusVariant = ch.publish_ready ? "success" : "warning";
      const statusText = ch.publish_ready ? "可选目标" : (ch.publish_unavailable_reason || "不可用");
      item.innerHTML = `
        <strong>${escapeHtml(ch.name || "未命名")}</strong>
        <span>${escapeHtml(publishingChannelTypeLabel(ch.type))} · <span class="badge" data-variant="${statusVariant}">${statusText}</span> · ${ch.type === "hosted_page" ? "历史记录，公开页已撤下" : escapeHtml(ch.endpoint_url || "-")}</span>
        <span>创建：${escapeHtml(formatDate(ch.created_at))}</span>
      `;
      item.addEventListener("click", () => openChannelDetail(ch.id));
      elements.channelList.appendChild(item);
    });
  }

  async function createChannel(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const message = form.querySelector("#channelCreateMessage");
    const data = new FormData(form);
    setMessage(message, "正在保存...");
    try {
      const payload = {
        name: String(data.get("name") || "").trim(),
        type: String(data.get("type") || "wordpress_rest"),
        endpoint_url: String(data.get("endpoint_url") || "").trim(),
        auth_type: String(data.get("auth_type") || "none"),
        username: String(data.get("username") || "").trim() || null,
        secret: String(data.get("secret") || "").trim() || null,
        domain: String(data.get("domain") || "").trim() || null,
        front_mode: String(data.get("front_mode") || "proxy"),
      };
      await api("/api/v1/publishing/channels", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      }); globalThis.GEO_SHELL?.invalidateSetup?.('channels');
      setMessage(message, "渠道已保存。", "success");
      form.reset();
      syncCreateChannelFields();
      setChannelFormOpen(false);
      await loadChannels();
    } catch (error) {
      setMessage(message, validationMessage(error), "danger");
    }
  }

  async function openChannelDetail(channelId) {
    state.selectedChannelId = channelId;
    const ch = state.channels.find((c) => c.id === channelId);
    if (!ch) return;
    if (elements.channelList) elements.channelList.style.display = "none";
    if (elements.channelDetail) elements.channelDetail.hidden = false;
    if (elements.channelDetailTitle) elements.channelDetailTitle.textContent = ch.name || "渠道详情";
    setMessage(elements.channelDetailMessage, `${publishingChannelTypeLabel(ch.type)} · ${ch.type === "hosted_page" ? "历史记录，公开页已撤下" : (ch.endpoint_url || "-")}`);
    if (elements.channelHealthResult) elements.channelHealthResult.hidden = true;
    if (elements.channelLogsResult) elements.channelLogsResult.hidden = true;
    if (elements.channelRewriteResult) elements.channelRewriteResult.hidden = true;
    if (elements.channelTaxonomyResult) elements.channelTaxonomyResult.hidden = true;
    if (elements.channelHealthButton) elements.channelHealthButton.hidden = ch.type === "hosted_page";
    if (elements.channelRewriteButton) elements.channelRewriteButton.hidden = ch.type === "hosted_page";
    // ⛔ 这里**不再**决定「密钥管理 / Agent 包 / 信标插件」的可见性：三个按钮已从租户模板移除。
    //    2026-09-22 用户口径：租户只做积分展示，没有 key 与令牌的创建/删除；
    //    渠道密钥（生成/轮换/撤销）是运营动作，凭据入口不得出现在租户界面。
    //    端点仍在（PublishingController::generateSecret / generateAgentPackage /
    //    generateBeaconPlugin），改由运营台或运营身份的 API 调用。
    const isWordPress = ch.type === "wordpress_rest";
    if (elements.channelTaxonomyButton) elements.channelTaxonomyButton.hidden = !isWordPress;
  }

  function closeChannelDetail() {
    state.selectedChannelId = null;
    if (elements.channelList) elements.channelList.style.display = "";
    if (elements.channelDetail) elements.channelDetail.hidden = true;
  }

  async function checkChannelHealth() {
    if (!state.selectedChannelId) return;
    if (elements.channelHealthResult) {
      elements.channelHealthResult.hidden = false;
      elements.channelHealthResult.innerHTML = '<p class="muted">正在检查...</p>';
    }
    try {
      const body = await api(`/api/v1/publishing/channels/${state.selectedChannelId}/health`);
      const variant = body.healthy ? "success" : "danger";
      const detail = body.details || body.message || "";
      const latency = body.latency_ms != null ? ` · 延迟 ${body.latency_ms}ms` : "";
      elements.channelHealthResult.innerHTML = `<p class="inline-result" data-variant="${variant}">${body.healthy ? "渠道健康" : "渠道异常"}${latency}${detail ? " · " + escapeHtml(detail) : ""}</p>`;
    } catch (error) {
      elements.channelHealthResult.innerHTML = `<p class="inline-result" data-variant="danger">${escapeHtml(error.message || "健康检查失败")}</p>`;
    }
  }

  async function loadChannelLogs() {
    if (!state.selectedChannelId) return;
    if (elements.channelLogsResult) {
      elements.channelLogsResult.hidden = false;
      elements.channelLogsResult.innerHTML = '<p class="muted">正在加载日志...</p>';
    }
    try {
      const body = await api(`/api/v1/publishing/channels/${state.selectedChannelId}/logs`);
      const logs = body.data || body || [];
      if (logs.length === 0) {
        elements.channelLogsResult.innerHTML = '<p class="muted">暂无分发日志。</p>';
        return;
      }
      let html = '<div class="trend-list">';
      logs.slice(0, 30).forEach((log) => {
        const lv = log.level || "info";
        const lvVariant = lv === "error" ? "danger" : lv === "warning" ? "warning" : "success";
        html += `<div class="trend-item"><span><span class="badge" data-variant="${lvVariant}">${escapeHtml(lv)}</span> ${escapeHtml(log.event || "-")} · ${escapeHtml(formatDate(log.created_at))}</span><span>${escapeHtml(log.message || "-")}</span></div>`;
      });
      html += '</div>';
      elements.channelLogsResult.innerHTML = html;
    } catch (error) {
      elements.channelLogsResult.innerHTML = `<p class="inline-result" data-variant="danger">${escapeHtml(error.message || "加载日志失败")}</p>`;
    }
  }

  async function loadRewriteRules() {
    if (!state.selectedChannelId) return;
    if (elements.channelRewriteResult) {
      elements.channelRewriteResult.hidden = false;
      elements.channelRewriteResult.innerHTML = '<p class="muted">正在生成...</p>';
    }
    try {
      const body = await api(`/api/v1/publishing/channels/${state.selectedChannelId}/rewrite-rules`);
      elements.channelRewriteResult.innerHTML = `<p class="muted">服务器类型：${escapeHtml(body.server_type || "nginx")} · 域名：${escapeHtml(body.domain || "-")}</p><pre class="execution-output">${escapeHtml(body.rules || "")}</pre>${body.instructions ? `<p class="muted">${escapeHtml(body.instructions)}</p>` : ""}`;
    } catch (error) {
      elements.channelRewriteResult.innerHTML = `<p class="inline-result" data-variant="danger">${escapeHtml(error.message || "生成失败")}</p>`;
    }
  }

  async function deleteChannel() {
    if (!state.selectedChannelId || !await confirmWorkspaceAction("确定删除这个渠道吗？")) return;
    try {
      await api(`/api/v1/publishing/channels/${state.selectedChannelId}`, { method: "DELETE" }); globalThis.GEO_SHELL?.invalidateSetup?.('channels');
      setMessage(elements.publishingMessage, "渠道已删除。", "success");
      closeChannelDetail();
      await loadChannels();
    } catch (error) {
      setMessage(elements.publishingMessage, error.message || "删除失败。", "danger");
    }
  }

  // ═══════════════════════════════════════════════
  // WP 分类标签预同步（阶段 4）
  //
  // 这个能力**不用凭据**（走 WP 渠道自带的 REST 账号），所以留在租户侧；
  // 同组的密钥管理 / Agent 包 / 信标插件因为要签发渠道密钥，2026-09-22 已按用户口径
  // 从租户界面撤下（端点保留给运营）。
  // ═══════════════════════════════════════════════

  /**
   * 预同步词表：分类取本租户的文章分类，标签取当前项目的目标关键词。
   * 两处都读不到时不静默发空请求 —— 空词表同步等于什么都没做，要如实说清楚。
   */
  async function loadTaxonomyWords() {
    const [categories, selected] = await Promise.allSettled([
      api("/api/v1/categories"),
      Promise.resolve(
        Array.isArray(state.selectedProject?.settings?.target_keywords)
          ? state.selectedProject.settings.target_keywords
          : [],
      ),
    ]);
    const catRows = categories.status === "fulfilled" ? (categories.value.data || categories.value || []) : [];
    const catNames = catRows.map((row) => String(row.name || "").trim()).filter(Boolean);
    const tagNames = (selected.status === "fulfilled" ? selected.value : [])
      .map((name) => String(name || "").trim())
      .filter(Boolean);
    return { catNames, tagNames };
  }

  async function syncWordPressTaxonomy() {
    if (!state.selectedChannelId) return;
    const host = elements.channelTaxonomyResult;
    if (host) {
      host.hidden = false;
      host.innerHTML = '<p class="muted">正在读取本租户分类与项目关键词...</p>';
    }
    const { catNames, tagNames } = await loadTaxonomyWords();
    if (!catNames.length && !tagNames.length) {
      if (host) host.innerHTML = '<p class="inline-result" data-variant="warning">没有可同步的分类或标签：先去「文章分类」建分类，或在项目档案里填目标关键词。</p>';
      return;
    }
    const preview = `将同步到目标 WordPress：分类 ${catNames.length} 个${catNames.length ? `（${catNames.slice(0, 5).join("、")}${catNames.length > 5 ? "…" : ""}）` : ""}，标签 ${tagNames.length} 个${tagNames.length ? `（${tagNames.slice(0, 5).join("、")}${tagNames.length > 5 ? "…" : ""}）` : ""}。同名术语按已存在的复用，不会重复创建。继续吗？`;
    if (!await confirmWorkspaceAction(preview)) return;
    if (host) host.innerHTML = '<p class="muted">正在同步...</p>';
    try {
      const body = await api(`/api/v1/publishing/channels/${state.selectedChannelId}/sync-taxonomy`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ categories: catNames, tags: tagNames }),
      });
      const mapping = (rows) => Object.entries(rows && typeof rows === "object" ? rows : {})
        .map(([name, id]) => `<li>${escapeHtml(name)} → #${escapeHtml(String(id))}</li>`).join("");
      const failed = (total, rows) => {
        const missed = Math.max(0, total - Object.keys(rows || {}).length);
        return missed ? `<p class="inline-result" data-variant="warning">有 ${missed} 个术语没同步成功（目标站拒绝或超时），可稍后重试。</p>` : "";
      };
      if (host) host.innerHTML = `
        <div class="callout" data-variant="success">
          <strong>同步完成</strong>
          <p>分类 ${Number(body.synced_categories) || 0} 个 · 标签 ${Number(body.synced_tags) || 0} 个。</p>
        </div>
        ${failed(catNames.length, body.categories)}
        ${failed(tagNames.length, body.tags)}
        <div class="trend-list">
          <div class="trend-item"><span>分类映射</span><span><ul>${mapping(body.categories) || "<li class='muted'>无</li>"}</ul></span></div>
          <div class="trend-item"><span>标签映射</span><span><ul>${mapping(body.tags) || "<li class='muted'>无</li>"}</ul></span></div>
        </div>`;
    } catch (error) {
      if (host) host.innerHTML = `<p class="inline-result" data-variant="danger">${escapeHtml(error.message || "分类标签同步失败")}</p>`;
    }
  }

  // ═══════════════════════════════════════════════
  // 站点文件：robots.txt / sitemap
  // ═══════════════════════════════════════════════

  async function generateSitemap() {
    const projectId = state.selectedProject?.id || "";
    if (elements.publishingSiteFilesResult) {
      elements.publishingSiteFilesResult.hidden = false;
      elements.publishingSiteFilesResult.innerHTML = '<p class="muted">正在生成 Sitemap...</p>';
    }
    try {
      const query = projectId ? `?project_id=${encodeURIComponent(projectId)}` : "";
      const body = await api(`/api/v1/publishing/sitemap${query}`, { method: "POST" });
      const data = body.data || body;
      const live = data.live_check?.state === "live";
      const stateText = live ? "线上已生效" : data.live_check?.state === "check_failed" ? "线上检查失败" : "已生成，线上尚未核实";
      elements.publishingSiteFilesResult.innerHTML = `
        <p class="muted">${escapeHtml(stateText)} · ${escapeHtml(String(data.url_count ?? 0))} 条 · 按当前公开状态实时生成；不代表已被收录。</p>
        <p><a href="${escapeHtml(data.url || "#")}" target="_blank" rel="noopener noreferrer">打开公开 Sitemap ↗</a></p>
        <pre class="execution-output">${escapeHtml(JSON.stringify(data, null, 2))}</pre>`;
    } catch (error) {
      elements.publishingSiteFilesResult.innerHTML = `<p class="inline-result" data-variant="danger">${escapeHtml(error.message || "Sitemap 生成失败")}</p>`;
    }
  }

  async function loadRobotsTxt() {
    const projectId = state.selectedProject?.id || "";
    if (elements.publishingSiteFilesResult) {
      elements.publishingSiteFilesResult.hidden = false;
      elements.publishingSiteFilesResult.innerHTML = '<p class="muted">正在加载 robots.txt...</p>';
    }
    try {
      const query = projectId ? `?project_id=${encodeURIComponent(projectId)}` : "";
      const body = await api(`/api/v1/publishing/robots-txt${query}`);
      const robotState = body.live_check?.state === "live" ? "线上已生效" : body.live_check?.state === "check_failed" ? "线上检查失败" : "预览与线上不一致";
      const pageState = body.public_page_check?.state === "live" ? "公开页响应可抓取" : body.public_page_check?.state === "no_public_page" ? "当前租户暂无可核验的公开页" : "公开页抓取状态未核实";
      elements.publishingSiteFilesResult.innerHTML = `
        <p class="muted">域名级 robots.txt：${escapeHtml(robotState)}。${escapeHtml(pageState)}。以下为应用预览，其他租户共用同一根文件。</p>
        <p><a href="${escapeHtml(body.source_url || "#")}" target="_blank" rel="noopener noreferrer">打开线上 robots.txt ↗</a></p>
        <p class="muted">线上根文件响应头：${escapeHtml(body.live_check?.x_robots_tag || "无 X-Robots-Tag")}；公开页响应头：${escapeHtml(body.public_page_check?.x_robots_tag || "未取得")}</p>
        <pre class="execution-output">${escapeHtml(body.content || "")}</pre>`;
    } catch (error) {
      elements.publishingSiteFilesResult.innerHTML = `<p class="inline-result" data-variant="danger">${escapeHtml(error.message || "robots.txt 加载失败")}</p>`;
    }
  }

  // ─── 项目渠道绑定 (B6.4) ───
  let projectBindingRequest = 0;
  let loadedBindingProjectId = null;
  const bindingSaves = new Set();
  async function loadProjectChannels() {
    const request = ++projectBindingRequest;
    loadedBindingProjectId = null;
    if (!elements.projectChannelBinding) return;
    if (!state.selectedProject) {
      elements.projectChannelBinding.hidden = true;
      return;
    }
    elements.projectChannelBinding.hidden = false;
    const projectId = Number(state.selectedProject.id);
    setMessage(elements.projectChannelSaveMessage, "");
    const settingsLink = document.getElementById("manualAccountSettingsLink");
    if (settingsLink) settingsLink.href = `/manual-publish?project_id=${projectId}#sec-settings`;
    if (elements.saveProjectChannelsButton) elements.saveProjectChannelsButton.disabled = true;
    setMessage(elements.projectChannelMessage, "正在加载项目渠道绑定...");
    try {
      const [bindings, channels] = await Promise.allSettled([
        api(`/api/v1/publishing/project-channels?project_id=${projectId}`),
        api("/api/v1/publishing/channels"),
      ]);
      if (request !== projectBindingRequest || Number(state.selectedProject?.id) !== projectId) return;
      if (bindings.status !== "fulfilled" || channels.status !== "fulfilled") {
        throw new Error("渠道或项目绑定加载失败，请重试；当前目标尚未确认。");
      }
      const bindingBody = bindings.value;
      const channelBody = channels.value;
      state.projectChannels = channelBody.data || channelBody || [];
      const boundIds = new Set((bindingBody.bound_channel_ids || []).map((id) => Number(id)));
      const manualIds = new Set((bindingBody.bound_manual_account_ids || []).map(Number));
      renderProjectChannels(boundIds, manualIds, bindingBody.available_manual_accounts || [], bindingBody.manual_publish_enabled);
      loadedBindingProjectId = projectId;
      if (elements.saveProjectChannelsButton) elements.saveProjectChannelsButton.disabled = bindingSaves.has(projectId);
      const modeText = bindingBody.mode === "bound" ? "已显式绑定" : "未绑定，不会自动外发";
      setMessage(elements.projectChannelMessage, `当前模式：${modeText}。勾选要绑定到「${state.selectedProject.name}」的渠道，保存后生效。`);
    } catch (error) {
      if (request !== projectBindingRequest || Number(state.selectedProject?.id) !== projectId) return;
      if (elements.projectChannelList) elements.projectChannelList.textContent = "绑定加载失败，当前无法保存。";
      setMessage(elements.projectChannelMessage, error.message || "无法加载项目渠道绑定。", "danger");
    }
  }

  function renderProjectChannels(boundIds, manualIds = new Set(), accounts = [], manualEnabled = false) {
    if (!elements.projectChannelList) return;
    elements.projectChannelList.innerHTML = '<h4>网站接口</h4>';
    if (!state.projectChannels.length) elements.projectChannelList.innerHTML += '<p class="muted">尚无网站接口，无官网时可直接绑定下面的平台账号。</p>';
    state.projectChannels.forEach((ch) => {
      const id = Number(ch.id);
      const row = document.createElement("label");
      row.className = "channel-binding-row";
      row.innerHTML = `
        <input type="checkbox" name="project_channel" value="${id}" ${boundIds.has(id) ? "checked" : ""} ${ch.type === "hosted_page" || !ch.is_active ? "disabled" : ""}>
        <span><strong>${escapeHtml(ch.name || "未命名")}</strong> · <span class="muted">${escapeHtml(publishingChannelTypeLabel(ch.type))}</span></span>
      `;
      elements.projectChannelList.appendChild(row);
    });
    const section = document.createElement("section");
    section.innerHTML = '<h4>第三方平台账号</h4><p class="muted">绑定用于选择发文账号，不代表平台授权或自动代发。发布后需回填公开链接。</p>';
    if (!manualEnabled) section.innerHTML += '<p class="muted">当前未启用平台人工发布，请联系管理员。</p>';
    else if (!accounts.length) section.innerHTML += '<p class="muted">尚无平台账号，请从上方“添加平台账号”登记自己的账号。</p>';
    accounts.forEach((account) => {
      const row = document.createElement("label");
      row.className = "channel-binding-row";
      row.innerHTML = `<input type="checkbox" name="project_manual_account" value="${Number(account.id)}" ${manualIds.has(Number(account.id)) ? "checked" : ""} ${account.is_active ? "" : "disabled"}>
        <span><strong>${escapeHtml(account.platform_label)} · ${escapeHtml(account.account_name)}</strong> · ${escapeHtml(account.persona_name || "未配置人设")} · ${account.is_active ? "人工确认发布" : "已停用"}</span>`;
      section.appendChild(row);
    });
    elements.projectChannelList.appendChild(section);
  }

  async function saveProjectChannels() {
    if (!state.selectedProject || loadedBindingProjectId !== Number(state.selectedProject.id)) return;
    const projectId = loadedBindingProjectId;
    if (bindingSaves.has(projectId)) return;
    bindingSaves.add(projectId);
    if (elements.saveProjectChannelsButton) elements.saveProjectChannelsButton.disabled = true;
    const rows = elements.projectChannelList?.querySelectorAll('input[name="project_channel"]') || [];
    const channelIds = Array.from(rows).filter((el) => el.checked && !el.disabled).map((el) => Number(el.value));
    const manualRows = elements.projectChannelList?.querySelectorAll('input[name="project_manual_account"]') || [];
    const accountIds = Array.from(manualRows).filter((el) => el.checked && !el.disabled).map((el) => Number(el.value));
    const message = elements.projectChannelSaveMessage;
    setMessage(message, "正在保存绑定...");
    try {
      await api("/api/v1/publishing/project-channels/bind", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ project_id: projectId, channel_ids: channelIds, manual_account_ids: accountIds }),
      });
      if (Number(state.selectedProject?.id) !== projectId) return;
      await loadProjectChannels();
      if (Number(state.selectedProject?.id) !== projectId) return;
      setMessage(message, channelIds.length + accountIds.length ? `已绑定 ${channelIds.length} 个网站接口、${accountIds.length} 个平台账号。` : "已解除全部绑定，不会自动外发。", "success");
    } catch (error) {
      if (Number(state.selectedProject?.id) !== projectId) return;
      setMessage(message, validationMessage(error), "danger");
    } finally {
      bindingSaves.delete(projectId);
      if (Number(state.selectedProject?.id) === projectId && loadedBindingProjectId === projectId && elements.saveProjectChannelsButton) elements.saveProjectChannelsButton.disabled = false;
    }
  }

  // ═══════════════════════════════════════════════
  // 注册与绑定（拆分前这些都在 geo-entry.js 里）
  // ═══════════════════════════════════════════════

  // 进"发布"视图要同时拉渠道列表与项目绑定（拆分前是主文件里的两个 tasks.push）。
  GEO.registerViewLoader("publishing", () => Promise.all([loadChannels(), loadProjectChannels()]));

    // Publishing
    elements.addChannelButton?.addEventListener("click", () => {
      setChannelFormOpen(Boolean(elements.channelForm?.hidden));
      syncCreateChannelFields();
    });
    elements.cancelChannelButton?.addEventListener("click", () => {
      setChannelFormOpen(false);
    });
    elements.createChannelForm?.addEventListener("submit", createChannel);
    elements.createChannelForm?.elements.type?.addEventListener("change", syncCreateChannelFields);
    elements.reloadChannelsButton?.addEventListener("click", loadChannels);
    elements.channelHealthButton?.addEventListener("click", checkChannelHealth);
    elements.channelLogsButton?.addEventListener("click", loadChannelLogs);
    elements.channelRewriteButton?.addEventListener("click", loadRewriteRules);
    elements.channelTaxonomyButton?.addEventListener("click", syncWordPressTaxonomy);
    elements.channelDeleteButton?.addEventListener("click", deleteChannel);
    elements.generateSitemapButton?.addEventListener("click", generateSitemap);
    elements.robotsTxtButton?.addEventListener("click", loadRobotsTxt);
    elements.channelBackButton?.addEventListener("click", closeChannelDetail);
    elements.reloadProjectChannelsButton?.addEventListener("click", loadProjectChannels);
    elements.saveProjectChannelsButton?.addEventListener("click", saveProjectChannels);

  // 主文件还要用的入口：文章发布抽屉要显示渠道类型的中文名。
  Object.assign(GEO, { publishingChannelTypeLabel });
})();
