/* ============================================================================
   GEO 工作台 · 平台运营台（geo-entry-platform.js）

   从 public/geo-entry.js 搬出来的第八块（计划 §7 前端大文件拆分，拆的是"平台"这一域）。
   搬走的是仅平台管理员可见的运营台：概览 / 租户 / 商业 / 健康 / 运营四个页签的
   拉取与渲染，以及页头那条时间窗切换。

   接入方式与前七块一致：主文件暴露 window.GEO，本文件消费它；进视图时的加载器
   （loadPlatform）在这里自己登记，事件绑定跟着代码一起搬。

   红线（与拆分前逐字一致）：
     - 不动任何 API 路径（/api/v1/platform/…）与 days 参数口径；
     - 五个端点用 Promise.allSettled 并行，**部分失败不能整页空白**（按 section 各自报错）；
     - 平台台只对 platform_admin 可见，前端隐藏不是权限（真门在 API）。

   判据：tests/fixtures/ui/dom-fingerprint.json（含 #platform 视图的 DOM/文本/API 指纹）
   + tests/Feature/PlatformOpsMarkupIntegrityTest.php + npm run test:js。
   ============================================================================ */
(() => {
  const GEO = window.GEO;
  if (!GEO) {
    console.error('[geo-entry-platform] window.GEO 未就绪：请确认本文件在 geo-entry.js 之后加载');
    return;
  }
  const {
    state, elements, api, escapeHtml, formatNumber, formatMoneyFen, setMessage, validationMessage,
    errorNoText, formatDate, statusLabel, withBusyButton,
  } = GEO;

  // ═══════════════════════════════════════════════
  // Platform Admin
  // ═══════════════════════════════════════════════

  document.getElementById('instanceRegistrationForm')?.addEventListener('submit', async event => {
    event.preventDefault();
    const button = document.getElementById('saveInstanceRegistration');
    if (button.disabled) return;
    await withBusyButton(button, '保存中…', async () => {
      try {
        const result = await api('/api/v1/platform/registration', {method: 'PUT', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({registration_enabled: document.getElementById('instanceRegistrationEnabled').checked})});
        document.getElementById('instanceRegistrationEnabled').checked = result.registration_enabled === true;
        document.getElementById('instanceRegistrationMessage').textContent = '注册设置已保存。';
      } catch (error) { document.getElementById('instanceRegistrationMessage').textContent = validationMessage(error); }
    });
  });

  async function loadPlatform() {
    const registration = document.getElementById('instanceRegistrationEnabled');
    const registrationSave = document.getElementById('saveInstanceRegistration');
    registration.disabled = registrationSave.disabled = true;
    try {
      const settings = await api('/api/v1/platform/registration');
      registration.checked = settings.registration_enabled === true;
      registration.disabled = registrationSave.disabled = false;
      document.getElementById('instanceRegistrationMessage').textContent = '';
    } catch (error) {
      document.getElementById('instanceRegistrationMessage').textContent = '注册设置读取失败：' + validationMessage(error);
    }
    const days = state.platformDays;
    setMessage(elements.platformMessage, "正在加载平台数据...");
    try {
      const [overview, tenants, health, operations] = await Promise.allSettled([
        api(`/api/v1/platform/overview?days=${days}`),
        api(`/api/v1/platform/tenants?per_page=20`),
        api("/api/v1/platform/health"),
        api(`/api/v1/platform/operations?days=${days}&recent=10`),
      ]);
      state.platformData = overview.status === "fulfilled" ? overview.value : null;
      state.platformTenants = tenants.status === "fulfilled" ? tenants.value : null;
      state.platformHealth = health.status === "fulfilled" ? health.value : null;
      state.platformOperations = operations.status === "fulfilled" ? operations.value : null;
      renderPlatform();
      setMessage(elements.platformMessage, "平台数据已加载。", "success");
      // Show admin section
      if (elements.platformAdminSection) elements.platformAdminSection.hidden = false;
      state.isAdmin = true;
    } catch (error) {
      if (error.status === 403) {
        setMessage(elements.platformMessage, "当前用户不是平台管理员。", "danger");
        state.isAdmin = false;
      } else {
        setMessage(elements.platformMessage, error.message || "无法加载平台数据。", "danger");
      }
    }
  }

  function renderPlatform() {
    if (!elements.platformContent) return;
    const tab = state.platformTab;
    document.querySelectorAll("[data-platform-tab]").forEach((btn) => {
      btn.setAttribute("aria-pressed", btn.dataset.platformTab === tab ? "true" : "false");
    });
    if (tab === "overview") renderPlatformOverview();
    else if (tab === "tenants") renderPlatformTenants();
    else if (tab === "health") renderPlatformHealth();
    else if (tab === "operations") renderPlatformOperations();
  }

  function renderPlatformOverview() {
    const data = state.platformData;
    if (!data) { elements.platformContent.innerHTML = '<p class="muted">暂无数据。</p>'; return; }
    const p = data.platform || {};
    const c = data.content || {};
    const e = data.execution || {};
    elements.platformContent.innerHTML = `
      <div class="usage-cards">
        <div class="usage-card"><span>租户总数</span><strong>${formatNumber(p.tenants_total || 0)}</strong></div>
        <div class="usage-card"><span>活跃租户</span><strong>${formatNumber(p.tenants_active || 0)}</strong></div>
        <div class="usage-card"><span>总用户</span><strong>${formatNumber(p.users_total || 0)}</strong></div>
        <div class="usage-card"><span>总项目</span><strong>${formatNumber(p.projects_total || 0)}</strong></div>
        <div class="usage-card"><span>期间文章</span><strong>${formatNumber(c.articles_period || 0)}</strong></div>
        <div class="usage-card"><span>已发布</span><strong>${formatNumber(c.articles_published_total || 0)}</strong></div>
        <div class="usage-card"><span>期间执行</span><strong>${formatNumber(e.executions_period || 0)}</strong></div>
        <div class="usage-card"><span>期间令牌</span><strong>${formatNumber(e.tokens_period || 0)}</strong></div>
      </div>
    `;
  }

  function renderPlatformTenants() {
    const data = state.platformTenants;
    if (!data?.data?.length) { elements.platformContent.innerHTML = '<p class="muted">暂无租户数据。</p>'; return; }
    let html = '<div class="trend-list">';
    data.data.forEach((t) => {
      html += `<div class="trend-item">
        <strong>${escapeHtml(t.name || t.subdomain || "-")}</strong>
        <span>${formatNumber(t.users_count || 0)} 用户 · ${formatNumber(t.projects_count || 0)} 项目 · ${formatNumber(t.articles_total || 0)} 文章</span>
        <span>近30天：执行 ${formatNumber(t.last_30d?.executions || 0)} · 令牌 ${formatNumber(t.last_30d?.tokens || 0)}</span>
      </div>`;
    });
    html += '</div>';
    elements.platformContent.innerHTML = html;
  }

  function renderPlatformHealth() {
    const data = state.platformHealth;
    if (!data) { elements.platformContent.innerHTML = '<p class="muted">暂无健康数据。</p>'; return; }
    const mc = data.model_calls || {};
    const ex = data.executions || {};
    const pub = data.publications || {};
    elements.platformContent.innerHTML = `
      <div class="usage-cards">
        <div class="usage-card"><span>模型调用</span><strong>${formatNumber(mc.total || 0)}</strong><small>成功率 ${(mc.success_rate || 0).toFixed(1)}%</small></div>
        <div class="usage-card"><span>执行失败率</span><strong>${(ex.failure_rate || 0).toFixed(1)}%</strong><small>总 ${formatNumber(ex.total || 0)} / 失败 ${formatNumber(ex.failed || 0)}</small></div>
        <div class="usage-card"><span>发布失败率</span><strong>${(pub.failure_rate || 0).toFixed(1)}%</strong><small>总 ${formatNumber(pub.total || 0)} / 失败 ${formatNumber(pub.failed || 0)}</small></div>
      </div>
    `;
  }

  function renderPlatformOperations() {
    const data = state.platformOperations;
    if (!data) { elements.platformContent.innerHTML = '<p class="muted">暂无运营数据。</p>'; return; }
    const m = data.metrics || {};
    let html = `<div class="usage-cards">
      <div class="usage-card"><span>租户</span><strong>${formatNumber(m.tenants_total || 0)}</strong></div>
      <div class="usage-card"><span>有风险的</span><strong>${formatNumber(m.tenants_with_risks || 0)}</strong></div>
      <div class="usage-card"><span>渠道</span><strong>${formatNumber(m.publishing_channels_active || 0)}/${formatNumber(m.publishing_channels_total || 0)}</strong></div>
      <div class="usage-card"><span>发布失败</span><strong>${formatNumber(m.publications_failed || 0)}</strong></div>
    </div>`;
    if (data.recent_publications?.length) {
      html += '<h4>最近发布</h4><div class="trend-list">';
      data.recent_publications.slice(0, 8).forEach((pub) => {
        const variant = pub.status === "published" ? "success" : pub.status === "failed" ? "danger" : "warning";
        html += `<div class="trend-item"><span>${escapeHtml(pub.tenant_subdomain || "-")} · ${escapeHtml(pub.article_title || "-")} → ${escapeHtml(pub.channel_name || "-")}</span><strong><span class="badge" data-variant="${variant}">${escapeHtml(statusLabel(pub.status))}</span></strong></div>`;
      });
      html += '</div>';
    }
    elements.platformContent.innerHTML = html;
  }

  // ═══════════════════════════════════════════════
  // 注册与绑定（拆分前这些都在 geo-entry.js 里）
  // ═══════════════════════════════════════════════

  // 进 #platform 时的数据加载：主文件统一按视图取（runViewLoaders），这里登记自己不写 if。
  GEO.registerViewLoader("platform", () => loadPlatform());

    // Platform
    elements.reloadPlatformButton?.addEventListener("click", loadPlatform);
    elements.platformDaysSelect?.addEventListener("change", () => {
      state.platformDays = Number(elements.platformDaysSelect.value || 30);
      loadPlatform();
    });
    document.querySelectorAll("[data-platform-tab]").forEach((btn) => {
      btn.addEventListener("click", () => {
        state.platformTab = btn.dataset.platformTab || "overview";
        renderPlatform();
      });
    });
})();
