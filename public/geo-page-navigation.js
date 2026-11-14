/* Shared tenant navigation for the four standalone workbench pages. */
(function () {
  'use strict';
  let workspaceShellState = null;
  let workspacePageSession = null, workspacePageProjectId = null;
  function initWorkspaceNavGroups(root = document) {
    root.querySelectorAll("[data-nav-group] > .workspace-menu-group").forEach(button => {
      const key = `geo_shell_group:${button.parentElement.dataset.navGroup}`;
      const items = document.getElementById(button.getAttribute("aria-controls"));
      if (!items) return;
      let collapsed = false;
      try { collapsed = localStorage.getItem(key) === "collapsed"; } catch { /* Storage is optional. */ }
      items.hidden = collapsed;
      button.setAttribute("aria-expanded", String(!collapsed));
      button.addEventListener("click", () => {
        items.hidden = !items.hidden;
        button.setAttribute("aria-expanded", String(!items.hidden));
        try { localStorage.setItem(key, items.hidden ? "collapsed" : "expanded"); } catch { /* Storage is optional. */ }
      });
    });
  }

  function syncWorkspaceArticleCount(state) {
    const badge = document.getElementById("workspaceArticleCount");
    if (!badge || !state) return;
    const loaded = typeof state.clAllTotal === "number";
    const count = loaded ? state.clAllArticles.filter(article => Number(article.project_id) === Number(state.selectedProject?.id)
      && (article.distribution_blocked_reason || article.status === "pending" || (article.status === "editing" && article.reviewed_at))).length : 0;
    badge.hidden = !loaded || count === 0;
    badge.textContent = loaded ? `${count}${state.clAllTruncated ? "+" : ""}` : "";
    badge.title = `${count}${state.clAllTruncated ? " 篇以上" : " 篇"}需要处理`;
  }

  function workspaceShellNavigate(view) {
    if (workspaceShellState) {
      globalThis.GEO?.setActiveWorkspaceView?.(view, { updateHash: true });
    } else {
      const suffix = workspacePageProjectId ? '?project_id=' + workspacePageProjectId : '';
      window.location.assign('/' + suffix + '#' + view);
    }
  }

  function runWorkspaceAction(action) {
    if (!workspaceShellState) {
      workspaceShellNavigate({ write: 'content-library', skill: 'skill', import: 'url-imports', project: 'projects/new' }[action]);
      return;
    }
    if (action === 'write') globalThis.GEO?.openArticleWriter?.();
    if (action === 'project') globalThis.GEO?.openNewProject?.();
    if (action === 'skill') workspaceShellNavigate('skill');
    if (action === 'import') workspaceShellNavigate('url-imports');
  }

  function workspaceCommandRows(query) {
    const term = String(query || '').trim().toLocaleLowerCase();
    const session = workspaceShellState?.session || workspacePageSession;
    const role = session?.user?.role;
    let navigation = [];
    try { navigation = JSON.parse(document.getElementById('workspaceNavigationIndex')?.textContent || '[]'); }
    catch { /* A missing index leaves the fixed actions available. */ }
    const actions = [
      ['写新文章', 'write'], ['执行技能', 'skill'], ['导入 URL', 'import'], ['新建项目', 'project'],
    ].map(([label, action]) => ({ kind: '操作', label, action }));
    const pages = navigation.filter(item => (!item.platformOnly || role === 'platform_admin')
      && (!item.roles.length || item.roles.includes(role)))
      .map(item => ({ ...item, kind: '页面', sub: item.group }));
    const state = workspaceShellState;
    const articles = typeof state?.clAllTotal === 'number' ? state.clAllArticles
      .filter(article => Number(article.project_id) === Number(state.selectedProject?.id) && article.status !== 'recycled')
      .map(article => ({ kind: '文章', label: String(article.title || '未命名文章'), articleId: article.id })) : [];
    const all = [...actions, ...pages, ...articles];
    return (term ? all.filter(row => (row.label + ' ' + (row.sub || '')).toLocaleLowerCase().includes(term))
      : [...actions, ...pages.slice(0, 6)]).slice(0, 12);
  }

  function executeWorkspaceCommand(row) {
    if (row.action) runWorkspaceAction(row.action);
    else if (row.articleId) workspaceShellNavigate('result/' + encodeURIComponent(row.articleId));
    else if (row.page) {
      const id = workspaceShellState?.selectedProject?.id || workspacePageProjectId;
      window.location.assign('/' + row.page + (id ? '?project_id=' + encodeURIComponent(id) : ''));
    } else workspaceShellNavigate(row.view);
  }

  function initWorkspaceGlobalActions() {
    const trigger = document.getElementById('workspaceSearchButton');
    const dialog = document.getElementById('workspaceCommandPalette');
    const input = document.getElementById('workspaceCommandInput');
    const list = document.getElementById('workspaceCommandResults');
    if (!trigger || !dialog || !input || !list) return;
    let rows = [], selected = 0, previousFocus = null;
    function select(index) {
      selected = Math.max(0, Math.min(rows.length - 1, index));
      list.querySelectorAll('[role="option"]').forEach((option, at) => {
        option.setAttribute('aria-selected', String(at === selected));
        if (at === selected) { input.setAttribute('aria-activedescendant', option.id); option.scrollIntoView({ block: 'nearest' }); }
      });
      if (!rows.length) input.removeAttribute('aria-activedescendant');
    }
    function render() {
      rows = workspaceCommandRows(input.value);
      list.replaceChildren();
      let lastKind = '';
      rows.forEach((row, at) => {
        if (row.kind !== lastKind) {
          const heading = document.createElement('li');
          heading.className = 'workspace-command-group'; heading.setAttribute('role', 'presentation');
          heading.textContent = row.kind; list.appendChild(heading); lastKind = row.kind;
        }
        const option = document.createElement('li');
        option.id = 'workspace-command-option-' + at; option.className = 'workspace-command-option';
        option.setAttribute('role', 'option');
        const label = document.createElement('span'); label.textContent = row.label; option.appendChild(label);
        if (row.sub) { const sub = document.createElement('span'); sub.className = 'workspace-command-sub'; sub.textContent = row.sub; option.appendChild(sub); }
        option.addEventListener('mouseenter', () => select(at));
        option.addEventListener('click', () => { dialog.close(); executeWorkspaceCommand(row); });
        list.appendChild(option);
      });
      if (!rows.length) {
        const empty = document.createElement('li'); empty.className = 'workspace-command-empty';
        empty.textContent = '没有找到「' + input.value.trim() + '」'; list.appendChild(empty);
      }
      select(0);
    }
    function open() {
      if (document.querySelector('.workspace-topbar')?.inert) return;
      if (dialog.open) return;
      previousFocus = document.activeElement; input.value = '';
      dialog.showModal(); input.setAttribute('aria-expanded', 'true'); render(); input.focus();
    }
    trigger.addEventListener('click', open);
    input.addEventListener('input', render);
    input.addEventListener('keydown', event => {
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') { event.preventDefault(); select(selected + (event.key === 'ArrowDown' ? 1 : -1)); }
      if (event.key === 'Enter' && rows[selected]) { event.preventDefault(); const row = rows[selected]; dialog.close(); executeWorkspaceCommand(row); }
      if (event.key === 'Escape') { event.preventDefault(); dialog.close(); }
    });
    dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
    dialog.addEventListener('close', () => { input.setAttribute('aria-expanded', 'false'); previousFocus?.focus?.(); });
    document.addEventListener('keydown', event => {
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); open(); }
    });
  }

  function initWorkspaceNewMenu() {
    const button = document.getElementById('workspaceNewButton');
    const menu = document.getElementById('workspaceNewMenu');
    if (!button || !menu) return;
    const items = [...menu.querySelectorAll('[data-shell-action]')];
    function close(focus) { menu.hidden = true; button.setAttribute('aria-expanded', 'false'); if (focus) button.focus(); }
    button.addEventListener('click', () => {
      menu.hidden = !menu.hidden; button.setAttribute('aria-expanded', String(!menu.hidden));
      if (!menu.hidden) items[0]?.focus();
    });
    items.forEach(item => item.addEventListener('click', () => { close(true); runWorkspaceAction(item.dataset.shellAction); }));
    menu.addEventListener('keydown', event => {
      if (event.key === 'Escape') { event.preventDefault(); close(true); }
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault(); const index = items.indexOf(document.activeElement);
        items[(index + items.length + (event.key === 'ArrowDown' ? 1 : -1)) % items.length]?.focus();
      }
    });
    document.addEventListener('pointerdown', event => { if (!button.parentElement.contains(event.target)) close(false); });
  }

  // B3 uses only three existing read endpoints. Tenant requests are shared by all
  // projects; a generation check stops late project responses repainting the badge.
  let workspaceSetupContext = null;
  let workspaceSetupGeneration = 0;
  const workspaceSetupTenantCache = new Map();
  const workspaceSetupProjectCache = new Map();

  function workspaceSetupNode(kind) {
    return document.querySelector?.(`[data-shell-setup="${kind}"]`);
  }

  function workspaceSetupProjectId(value) {
    const number = Number(value);
    return Number.isSafeInteger(number) && number > 0 ? number : null;
  }

  function workspaceSetupCurrent() {
    const context = workspaceSetupContext?.();
    const session = context?.session;
    const tenant = session?.tenant || session?.user?.tenant;
    const projectId = workspaceSetupProjectId(context?.projectId);
    const token = context?.token || session?.accessToken || session?.access_token || session?.token;
    const subdomain = String(tenant?.subdomain || '').trim();
    if (!session?.user || !tenant || !projectId || !token || !subdomain) return null;
    return { ...context, projectId, token, subdomain, tenantKey: `${tenant.id || ''}:${subdomain}` };
  }

  function setWorkspaceSetupBadge(kind, text, title, tone, href) {
    const node = workspaceSetupNode(kind);
    if (!node) return;
    node.hidden = !text;
    node.textContent = text || '';
    node.title = title || '';
    node.dataset.tone = tone || '';
    if (href) node.href = href;
  }

  function workspaceSetupRead(path, context) {
    return fetch(path, {
      method: 'GET', credentials: 'same-origin',
      headers: { Accept: 'application/json', Authorization: `Bearer ${context.token}`, 'X-Tenant': context.subdomain },
    }).then(response => {
      if (!response.ok) throw new Error('Setup state unavailable');
      return response.json();
    }).catch(() => null);
  }

  function openWorkspaceChannelForm(context) {
    if (!context || context.mode === 'page') return;
    context.setActiveWorkspaceView('publishing', { updateHash: true });
    const form = document.getElementById('channelForm');
    const button = document.getElementById('addChannelButton');
    if (form?.hidden) button?.click();
    else document.getElementById('createChannelForm')?.elements.name?.focus();
    // Initial project restoration releases panel inert after selectProject returns.
    window.requestAnimationFrame?.(() => {
      const panel = document.getElementById('publishingPanel');
      if (panel && !panel.hidden && !panel.inert && !form?.hidden)
        document.getElementById('createChannelForm')?.elements.name?.focus();
    });
  }

  function initWorkspaceSetup(getContext) {
    workspaceSetupContext = getContext;
    const channel = workspaceSetupNode('channels');
    if (channel && !channel.dataset.setupBound) {
      channel.dataset.setupBound = 'true';
      channel.addEventListener('click', event => {
        const context = workspaceSetupCurrent();
        if (!context || context.mode === 'page') return;
        event.preventDefault();
        openWorkspaceChannelForm(context);
      });
    }
  }

  function syncWorkspaceSetup() {
    const generation = ++workspaceSetupGeneration;
    ['channels', 'visibility', 'beacon'].forEach(kind => setWorkspaceSetupBadge(kind, ''));
    const context = workspaceSetupCurrent();
    if (!context) return Promise.resolve();
    const suffix = `?project_id=${context.projectId}`;
    const current = () => generation === workspaceSetupGeneration;
    const tasks = [];
    const tenantRequest = (kind, path) => {
      const key = `${context.tenantKey}:${kind}`;
      if (!workspaceSetupTenantCache.has(key)) workspaceSetupTenantCache.set(key, workspaceSetupRead(path, context));
      return workspaceSetupTenantCache.get(key);
    };
    if (workspaceSetupNode('channels')) tasks.push(tenantRequest('channels', '/api/v1/publishing/channels').then(body => {
      if (!current() || !Array.isArray(body?.data)) return;
      // is_active is a boolean in PublishingController::channelPayload; malformed
      // responses cannot be treated as a missing setup step.
      if (!body.data.every(channel => typeof channel?.is_active === 'boolean')) return;
      if (body.data.some(channel => channel.is_active)) return;
      setWorkspaceSetupBadge('channels', '绑渠道', '租户尚无启用的发布渠道', 'primary',
        context.mode === 'page' ? `/${suffix}&setup=channels#publishing` : '#publishing');
    }));
    // 外站信标只覆盖发布渠道；站点自身的访问记在访问日志里。没有信标行不等于没接入：
    // 只有信标为空、且近一年站点访问也为 0 时才显示「未接入」（线上曾对有访问数据的租户误报）。
    if (workspaceSetupNode('beacon')) tasks.push(Promise.all([
      tenantRequest('beacon', '/api/v1/analytics/channel-beacon-health'),
      tenantRequest('visits', '/api/v1/analytics/crawler-breakdown?days=366'),
    ]).then(([body, visits]) => {
      if (!current() || !Array.isArray(body?.data)) return;
      if (body.data.length === 0) {
        const pv = Number(visits?.kpis?.pv);
        if (visits?.kpis && Number.isFinite(pv) && pv === 0) {
          setWorkspaceSetupBadge('beacon', '未接入', '近一年没有收到任何站点访问记录', 'muted', `/analytics${suffix}`);
        }
        return;
      }
      const threshold = Number(body.beacon_threshold_days) > 0 ? Number(body.beacon_threshold_days) : 7;
      const expired = body.data.some(row => {
        if (typeof row?.beaconing === 'boolean') return !row.beaconing;
        const timestamp = Date.parse(row?.last_beacon_at);
        return Number.isFinite(timestamp) && Date.now() - timestamp > threshold * 86400000;
      });
      if (expired) setWorkspaceSetupBadge('beacon', '信标异常', `有渠道超过 ${threshold} 天未回传信标`, 'warning', `/analytics${suffix}`);
    }));
    if (workspaceSetupNode('visibility')) {
      const key = `${context.tenantKey}:${context.projectId}`;
      if (!workspaceSetupProjectCache.has(key)) workspaceSetupProjectCache.set(key,
        workspaceSetupRead(`/api/v1/visibility/runs?project_id=${context.projectId}&per_page=10`, context));
      tasks.push(workspaceSetupProjectCache.get(key).then(body => {
        if (current() && Array.isArray(body?.data) && body.data.length === 0)
          setWorkspaceSetupBadge('visibility', '去监测', '当前项目尚无智能回答监测记录', 'primary', `/visibility${suffix}#sec-new`);
      }));
    }
    if (context.mode !== 'page') {
      const url = new URL(window.location.href);
      if (url.searchParams.get('setup') === 'channels') {
        openWorkspaceChannelForm(context);
        url.searchParams.delete('setup');
        history.replaceState(null, '', url);
      }
    }
    return Promise.all(tasks);
  }

  function readProjectVisibility(projectId) {
    const context = workspaceSetupCurrent();
    if (!context || Number(projectId) !== context.projectId) return Promise.resolve(null);
    const key = `${context.tenantKey}:${context.projectId}`;
    if (!workspaceSetupProjectCache.has(key)) workspaceSetupProjectCache.set(key,
      workspaceSetupRead(`/api/v1/visibility/runs?project_id=${context.projectId}&per_page=10`, context));
    return workspaceSetupProjectCache.get(key);
  }

  function invalidateWorkspaceSetup(kind) {
    const context = workspaceSetupCurrent();
    if (!context) return Promise.resolve();
    if (kind === 'channels') workspaceSetupTenantCache.delete(`${context.tenantKey}:channels`);
    else if (kind === 'visibility') workspaceSetupProjectCache.delete(`${context.tenantKey}:${context.projectId}`);
    else return Promise.resolve();
    return syncWorkspaceSetup();
  }

  function initWorkspaceShell(state, setActiveWorkspaceView, elements) {
    workspaceShellState = state;
    initWorkspaceSetup(() => ({ session: state.session, projectId: state.selectedProject?.id, mode: 'spa', setActiveWorkspaceView }));
    document.querySelectorAll("[data-shell-view]").forEach(shortcut => {
      shortcut.addEventListener("click", event => {
        event.preventDefault();
        setActiveWorkspaceView(shortcut.dataset.shellView, { updateHash: true });
        const accountMenu = document.getElementById("accountMenu");
        if (accountMenu) accountMenu.open = false;
      });
    });
    syncWorkspaceArticleCount(state);
    initWorkspaceNavGroups();
  }
  window.GEO_SHELL = {
    init: initWorkspaceShell,
    readProjectVisibility,
    invalidateSetup: invalidateWorkspaceSetup,
    syncArticleCount: () => {
      syncWorkspaceArticleCount(workspaceShellState);
      syncWorkspaceSetup();
      window.GEO?.syncProjectHomeArticles?.();
    },
  };

  initWorkspaceGlobalActions();
  initWorkspaceNewMenu();

  var sidebar = document.getElementById('geoPageSidebar');
  var toggle = document.getElementById('geoPageMenuToggle');
  if (!sidebar || !toggle) return;
  var header = document.querySelector && document.querySelector('.workspace-topbar');
  function syncHeaderHeight() {
    if (header && document.body && document.body.style) {
      document.body.style.setProperty('--geo-page-header-height', header.getBoundingClientRect().height + 'px');
    }
  }
  syncHeaderHeight();
  if (header && typeof ResizeObserver !== 'undefined') new ResizeObserver(syncHeaderHeight).observe(header);

  function positiveId(value) {
    var number = Number(value);
    return Number.isSafeInteger(number) && number > 0 ? number : null;
  }

  function setOpen(open, focusToggle) {
    sidebar.classList.toggle('is-open', open);
    toggle.setAttribute('aria-expanded', String(open));
    toggle.setAttribute('aria-label', open ? '关闭工作台菜单' : '打开工作台菜单');
    if (focusToggle) toggle.focus();
  }

  toggle.addEventListener('click', function () { setOpen(!sidebar.classList.contains('is-open')); });
  sidebar.addEventListener('click', function (event) {
    if (event.target.closest('a[href]')) setOpen(false);
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && sidebar.classList.contains('is-open')) setOpen(false, true);
  });
  window.addEventListener('resize', function () { syncHeaderHeight(); if (window.innerWidth > 960) setOpen(false); });

  initWorkspaceNavGroups(sidebar);

  var activeTenant = null;
  var accountMenu = document.getElementById('accountMenu');
  if (accountMenu) {
    document.addEventListener('pointerdown', function (event) {
      if (accountMenu.open && !accountMenu.contains(event.target)) accountMenu.open = false;
    });
    accountMenu.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') { accountMenu.open = false; document.getElementById('accountMenuButton').focus(); }
    });
  }
  var logout = document.getElementById('accountLogoutButton');
  if (logout) logout.addEventListener('click', async function () {
    logout.disabled = true;
    try {
      var response = await fetch('/api/v1/auth/logout', {
        method: 'POST', credentials: 'include', headers: {
          Accept: 'application/json', Authorization: 'Bearer ' + sessionStorage.getItem('geo_token'),
          'X-Tenant': activeTenant || ''
        }
      });
      if (!response.ok) throw new Error('退出未完成，请重试。');
      sessionStorage.removeItem('geo_token'); sessionStorage.removeItem('geo_user'); sessionStorage.removeItem('geo_tenant');
      window.location.assign('/login');
    } catch (error) { logout.disabled = false; logout.textContent = '退出未完成，点击重试'; }
  });

  window.GEO_PAGE_NAV = {
    activate: function (payload) {
      var user = payload && payload.user;
      var tenant = payload && (payload.tenant || (user && user.tenant));
      if (!user || !tenant) return;
      var subdomain = String(tenant.subdomain || '').trim();
      activeTenant = subdomain;
      var accountUser = payload.account_user || user;
      var userName = accountUser.name || String(accountUser.phone || '').replace(/^(\d{3})\d+(\d{4})$/, '$1****$2') || accountUser.email || '用户';
      var workspaceText = '工作区 ' + (tenant.name || subdomain);
      [['userLabel', userName], ['accountMenuIdentity', userName], ['workspaceIdentity', workspaceText],
        ['accountMenuWorkspace', workspaceText], ['accountMenuPhone', String(accountUser.phone || '').replace(/^(\d{3})\d+(\d{4})$/, '$1****$2')]].forEach(function (pair) {
          var node = document.getElementById(pair[0]); if (node) { node.textContent = pair[1]; node.hidden = !pair[1]; }
        });
      sidebar.dataset.platformAdmin = String(user.role === 'platform_admin');
      var platform = document.getElementById('platformAdminSection');
      if (platform) platform.hidden = user.role !== 'platform_admin';
      if (header) header.inert = false;
      var requested = positiveId(new URLSearchParams(window.location.search).get('project_id'));
      var remembered = null;
      try { remembered = positiveId(localStorage.getItem('geo_last_project_id:' + (subdomain || 'default'))); }
      catch (error) { /* Private browsing can block localStorage. */ }
      var projectId = requested || remembered;
      workspacePageSession = payload; workspacePageProjectId = projectId;
      initWorkspaceSetup(() => ({ session: payload, projectId, mode: 'page' })); syncWorkspaceSetup();
      var suffix = projectId ? '?project_id=' + projectId : '';

      sidebar.querySelectorAll('[data-workspace-view]').forEach(function (link) {
        link.href = '/' + suffix + '#' + link.dataset.workspaceView;
      });
      sidebar.querySelectorAll('[data-tenant-page]').forEach(function (link) {
        link.href = '/' + link.dataset.tenantPage + suffix;
      });
      if (header) header.querySelectorAll('[data-shell-view]').forEach(function (link) {
        link.href = '/' + suffix + '#' + link.dataset.shellView;
      });
      var current = document.getElementById('geoPageCurrentProject');
      var projectName = document.getElementById('geoPageProjectName');
      var projectStage = document.getElementById('geoPageProjectStage');
      var summary = null;
      try { summary = JSON.parse(localStorage.getItem('geo_last_project_summary:' + (subdomain || 'default')) || 'null'); }
      catch (error) { /* Missing or corrupt summaries keep the generic project card. */ }
      var matchingSummary = projectId && summary && positiveId(summary.id) === projectId;
      if (projectName) projectName.textContent = matchingSummary ? String(summary.name || '当前项目') : '当前项目';
      if (projectStage) {
        var stage = matchingSummary && summary.current_stage !== null && summary.current_stage !== '' ? Number(summary.current_stage) : -1;
        var validStage = Number.isInteger(stage) && stage >= 0 && stage < 6;
        projectStage.hidden = !validStage;
        projectStage.textContent = validStage ? '阶段 ' + (stage + 1) + '/6' + (summary.stage_label ? ' · ' + String(summary.stage_label) : '') : '';
      }
      // The standalone card always stays available; project switching belongs to the workbench.
      if (current) current.href = '/' + suffix + '#projects';
      var publisher = ['admin', 'platform_admin', 'editor'].includes(user.role);
      sidebar.querySelectorAll('[data-role="publisher"]').forEach(function (link) { link.hidden = !publisher; });
      sidebar.inert = false;
      toggle.disabled = false;
      var active = sidebar.querySelector && sidebar.querySelector('#workspaceMenu [aria-current="page"]');
      if (active) {
        var items = active.closest('.workspace-menu-items');
        if (items && items.hidden) {
          items.hidden = false;
          items.parentElement.querySelector('.workspace-menu-group').setAttribute('aria-expanded', 'true');
        }
        active.scrollIntoView({ block: 'nearest' });
      }
    }
  };
})();
