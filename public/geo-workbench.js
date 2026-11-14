/* ============================================================================
   GEO 工作台 · 重构版交互引擎（geo-workbench.js）
   设计依据：design-specs/prototypes/geo-workbench-spec.md + geo-workbench.html
   红线遵守：
     - 不改 window.GEO_ENTRY_CONFIG（此处仅读取）
     - 不改任何 API / 表单 name / Sanctum token
     - data-buy-plan 等 hook 保留并派发 geo:buy-plan 事件
   数据策略：优先请求 GEO_ENTRY_CONFIG 中的真实端点；后端开发期失败则回退到
            内置 fixture，保证 UI 可演示。所有面板均走 loading/empty/error 状态模板。
   ============================================================================ */
(function () {
  'use strict';

  var CFG = window.GEO_ENTRY_CONFIG || {};
  var $  = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /* ---------------- 内置 fixture（后端就绪后由真实端点覆盖） ---------------- */
  var FIX = {
    projects: [
      { id: 'p1', name: '成都某某口腔官网', domain: 'chengdu-dental.com', industry: '口腔医疗', createdAt: '2026-06-28',
        status: 'run', statusLabel: '内容生产中', stage: 3, stageLabel: '内容生产',
        stageItems: [
          { status: 'ok',      name: '行业问答文章 × 12', action: '查看产出' },
          { status: 'run',     name: '品牌对比评测 × 4',  action: '查看进度' },
          { status: 'neutral', name: 'FAQ 结构化改写',     action: '执行', openDrawer: true }
        ] },
      { id: 'p2', name: '川西民宿品牌站', domain: 'west-sichuan-stay.com', industry: '文旅', createdAt: '2026-05-30',
        status: 'run', statusLabel: '分发进行中', stage: 5, stageLabel: '分发引用',
        stageItems: [
          { status: 'ok',      name: '品牌故事长文 × 6',   action: '查看产出' },
          { status: 'ok',      name: '渠道分发配置',        action: '查看' },
          { status: 'neutral', name: '效果回收埋点',        action: '执行', openDrawer: true }
        ] },
      { id: 'p3', name: '医疗器械 B2B', domain: 'med-device-b2b.com', industry: '医疗器械', createdAt: '2026-07-02',
        status: 'wait', statusLabel: '诊断待开始', stage: 1, stageLabel: '品牌诊断',
        stageItems: [
          { status: 'neutral', name: '竞品 AI 引用扫描',   action: '执行', openDrawer: true },
          { status: 'neutral', name: '行业关键词图谱',     action: '执行', openDrawer: true }
        ] }
    ],
    skills: [
      { id: 'faq-restructure', name: 'FAQ 结构化改写' },
      { id: 'qa-article',      name: '行业问答文章',   cost: 280 },
      { id: 'brand-compare',   name: '品牌对比评测',   cost: 360 }
    ],
    assets: {
      articles: [
        { title: '种植牙多少钱一颗？2026 成都行情与选择指南', meta: '今天 14:22' },
        { title: '隐形矫正 vs 传统托槽：适应症对比',           meta: '昨天' },
        { title: '品牌诊断报告 v2（AI 引用现状）',            meta: '07-06' }
      ],
      reports: [
        { title: '品牌诊断报告 v2（AI 引用现状）', meta: '07-06' },
        { title: '季度 GEO 健康度报告 Q2',          meta: '06-30' }
      ],
      analyses: [
        { title: 'AI 引用命中趋势 · 近 30 天', meta: '今天 09:12' },
        { title: '竞品对比 · 3 家本地机构',       meta: '07-08' }
      ]
    },
    service: { bound: true, provider: 'GEO 模型服务', keyMask: 'qh-live-•••••• 8f2a', lastTest: '今天 09:12 · 正常' },
    ops: [
      { k: 'AI 引用命中（近 30 天）', v: '18 次 · 环比 +38%' },
      { k: '已分发内容', v: '46 篇 / 5 渠道' },
      { k: '待处理告警', v: '1 条 · 渠道 Token 即将过期', warn: true }
    ]
  };

  /* ---------------- 极简 Toast ---------------- */
  function toast(msg) {
    var t = $('#gwToast');
    if (!t) {
      t = document.createElement('div');
      t.id = 'gwToast';
      t.style.cssText = 'position:fixed;left:50%;bottom:22px;transform:translateX(-50%);background:var(--md-text);color:#F7F4ED;padding:9px 16px;border-radius:999px;font-size:var(--md-fs-xs);box-shadow:var(--md-shadow-modal);z-index:80;opacity:0;transition:opacity .18s ease;pointer-events:none;';
      document.body.appendChild(t);
    }
    t.textContent = msg;
    t.style.opacity = '1';
    clearTimeout(t._tid);
    t._tid = setTimeout(function () { t.style.opacity = '0'; }, 2200);
  }

  /* ---------------- 数据层：真实端点优先，失败回退 fixture ---------------- */
  function apiGet(path) {
    return fetch(path, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (d) { return { ok: true, data: d }; })
      .catch(function (e) { return { ok: false, error: e }; });
  }
  function apiPost(path, body) {
    return fetch(path, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify(body || {})
    })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (d) { return { ok: true, data: d }; })
      .catch(function (e) { return { ok: false, error: e }; });
  }
  function apiPatch(path, body) {
    return fetch(path, {
      method: 'PATCH', credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify(body || {})
    })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (d) { return { ok: true, data: d }; })
      .catch(function (e) { return { ok: false, error: e }; });
  }
  function apiDelete(path) {
    return fetch(path, {
      method: 'DELETE', credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' }
    })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json().catch(function(){ return {}; }); })
      .then(function (d) { return { ok: true, data: d }; })
      .catch(function (e) { return { ok: false, error: e }; });
  }
  function apiUpload(path, form) {
    return fetch(path, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
      body: form
    })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (d) { return { ok: true, data: d }; })
      .catch(function (e) { return { ok: false, error: e }; });
  }

  /* ---------------- 通用状态模板 ---------------- */
  function applyLoading(host) {
    host.innerHTML = '<div class="skeleton" role="status" aria-label="加载中"><div class="sk w80"></div><div class="sk w60"></div><div class="sk w40"></div></div>';
  }
  function applyEmpty(host, opts) {
    opts = opts || {};
    host.innerHTML =
      '<div class="state-tpl state-empty">' +
        '<div class="st-icon">○</div>' +
        '<h3>' + (opts.title || '这里还没有内容') + '</h3>' +
        '<p>' + (opts.reason || '暂无数据。') + '</p>' +
        (opts.actionLabel ? '<button type="button" class="btn primary sm" data-gw-action>' + opts.actionLabel + '</button>' : '') +
      '</div>';
    if (opts.onAction) {
      var b = $('[data-gw-action]', host);
      if (b) b.addEventListener('click', opts.onAction);
    }
  }
  function applyError(host, opts) {
    opts = opts || {};
    host.innerHTML =
      '<div class="state-tpl state-error">' +
        '<div class="st-icon">!</div>' +
        '<h3>' + (opts.title || '加载失败') + '</h3>' +
        '<p>错误编号 <button type="button" class="err-code" data-gw-code>' + (opts.code || 'GW-000') + '</button>。' +
        (opts.reason || '你的操作没有丢失，请重试；持续失败请联系支持。') + '</p>' +
        '<button type="button" class="btn sm" data-gw-retry>重试</button>' +
      '</div>';
    var code = $('[data-gw-code]', host);
    if (code) code.addEventListener('click', function () { copyText(code.textContent); toast('错误编号已复制'); });
    var retry = $('[data-gw-retry]', host);
    if (retry && opts.onRetry) retry.addEventListener('click', opts.onRetry);
  }
  function copyText(t) {
    try { if (navigator.clipboard) navigator.clipboard.writeText(t); } catch (e) {}
  }

  /* ---------------- 视图 registry + 分组导航切换 ---------------- */
  var _views = {};
  var _entered = {};
  function registerView(name, impl) { _views[name] = impl; }
  function getView(name) { return _views[name]; }
  function hasEntered(name) { return !!_entered[name]; }
  function markEntered(name) { _entered[name] = true; }

  function goView(name) {
    $$('.nav-item[data-nav]').forEach(function (n) {
      n.setAttribute('aria-current', String(n.dataset.nav === name));
    });
    $$('section[data-view]').forEach(function (s) {
      s.hidden = (s.getAttribute('data-view') !== name);
    });
    var v = _views[name];
    if (v && v.enter) {
      if (!_entered[name]) { _entered[name] = true; v.enter(); }
      else if (typeof v.reenter === 'function') v.reenter();
    }
    if (name === 'biz' && typeof loadBizPanels === 'function') loadBizPanels();
    // 数据洞察：重放链路带描线动画
    if (name === 'insights') {
      var p = $('#chainPanel');
      if (p) { p.classList.remove('chain-enter'); void p.offsetWidth; p.classList.add('chain-enter'); }
    }
    window.scrollTo(0, 0);
  }

  function initNav() {
    $$('.nav-item[data-nav]').forEach(function (n) {
      n.addEventListener('click', function () { goView(n.dataset.nav); });
    });
    // data-nav-go：视图间跳转（原型 data-nav-go 等价行为）
    document.addEventListener('click', function (e) {
      var t = e.target.closest && e.target.closest('[data-nav-go]');
      if (t) { goView(t.getAttribute('data-nav-go')); }
    });
  }

  /* 导航徽标：由视图加载后回填（未加载前不显示） */
  function setNavBadge(name, text) {
    var el = $('[data-nav-badge="' + name + '"]');
    if (!el) return;
    el.textContent = text;
    el.hidden = !(text || '').toString().trim();
  }
  function setNavDot(name, show) {
    var el = $('[data-nav-dot="' + name + '"]');
    if (el) el.hidden = !show;
  }

  /* ---------------- 项目列表 / 详情 / 阶段 ---------------- */
  var state = { projects: [], currentId: null, assetsTab: 'articles' };

  function statusBadge(s) {
    return '<span class="badge ' + (s.status === 'ok' ? 'ok' : s.status === 'run' ? 'run' : s.status === 'wait' ? 'wait' : 'neutral') + '">' + s.statusLabel + '</span>';
  }

  function renderProjectList(list) {
    var host = $('#projDropdownList');
    if (!host) return;
    if (!list.length) {
      host.innerHTML = '<div class="proj-empty">还没有项目</div>';
      return;
    }
    host.innerHTML = '';
    list.forEach(function (p) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'project-item';
      btn.setAttribute('role', 'option');
      btn.setAttribute('aria-selected', p.id === state.currentId ? 'true' : 'false');
      btn.dataset.id = p.id;
      btn.innerHTML = '<span class="p-name">' + p.name + '</span><span class="p-meta">阶段 ' + p.stage + '/6 · ' + p.statusLabel + '</span>';
      btn.addEventListener('click', function () { selectProject(p.id); closeProjDropdown(); });
      host.appendChild(btn);
    });
  }

  function renderContextCard(p) {
    var name = $('#pcName'), meta = $('#pcMeta');
    if (name) name.textContent = p.name;
    if (meta) meta.textContent = '阶段 ' + p.stage + '/6 · ' + p.statusLabel;
  }

  function renderStageRail(stage) {
    var lis = $$('#stageList ol li');
    lis.forEach(function (li, i) {
      li.classList.remove('done', 'active');
      if (i + 1 < stage) li.classList.add('done');
      else if (i + 1 === stage) li.classList.add('active');
    });
  }

  function renderProjectDetail(p) {
    $('#pdName').textContent = p.name;
    $('#pdSub').textContent = p.domain + ' · ' + p.industry + ' · 创建于 ' + p.createdAt;
    var sb = $('#pdStatus');
    sb.className = 'badge ' + (p.status === 'ok' ? 'ok' : p.status === 'run' ? 'run' : p.status === 'wait' ? 'wait' : 'neutral');
    sb.textContent = p.statusLabel;
    renderStageRail(p.stage);
  }

  function renderCurrentStage(p) {
    var host = $('[data-endpoint="currentStage"]');
    var done = p.stageItems.filter(function (i) { return i.status === 'ok'; }).length;
    $('#stagePanelTitle').textContent = '当前阶段 · ' + p.stageLabel;
    $('#stagePanelHint').textContent = p.stageItems.length + ' 项中已完成 ' + done + ' 项';
    if (!p.stageItems.length) {
      applyEmpty(host, { title: '本阶段暂无任务', reason: '从右上角「执行技能」为当前阶段生成任务。', actionLabel: '执行技能', onAction: openDrawer });
      return;
    }
    host.innerHTML = '';
    p.stageItems.forEach(function (it) {
      var row = document.createElement('div');
      row.className = 'stage-item';
      var scls = it.status === 'ok' ? 'ok' : it.status === 'run' ? 'run' : 'neutral';
      row.innerHTML =
        '<span class="badge ' + scls + '">' + (it.status === 'ok' ? '已完成' : it.status === 'run' ? '执行中' : '未开始') + '</span>' +
        '<span class="s-name">' + it.name + '</span>' +
        '<button type="button" class="btn ' + (it.openDrawer ? '' : 'ghost ') + 'sm">' + it.action + '</button>';
      var b = $('button', row);
      if (it.openDrawer) b.addEventListener('click', openDrawer);
      else b.addEventListener('click', function () { toast(it.action + '（待接入）'); });
      host.appendChild(row);
    });
  }

  function renderAssets() {
    var host = $('[data-endpoint="assets"]');
    var list = FIX.assets[state.assetsTab] || [];
    if (!list.length) {
      applyEmpty(host, { title: '暂无' + assetTabLabel() + '产出', reason: '执行技能后，产出会出现在这里。', actionLabel: '执行技能', onAction: openDrawer });
      return;
    }
    host.innerHTML = '';
    list.forEach(function (a) {
      var row = document.createElement('div');
      row.className = 'asset-item';
      row.innerHTML = '<span class="a-title">' + a.title + '</span><span class="a-meta">' + a.meta + '</span><button type="button" class="btn ghost sm">预览</button>';
      $('button', row).addEventListener('click', function () { toast('预览：' + a.title); });
      host.appendChild(row);
    });
  }
  function assetTabLabel() {
    return state.assetsTab === 'articles' ? '文章' : state.assetsTab === 'reports' ? '报告' : '分析历史';
  }
  function initAssetTabs() {
    $$('#assetTabs button').forEach(function (b) {
      b.addEventListener('click', function () {
        $$('#assetTabs button').forEach(function (x) { x.setAttribute('aria-selected', x === b ? 'true' : 'false'); });
        state.assetsTab = b.dataset.asset;
        renderAssets();
      });
    });
  }

  function selectProject(id) {
    state.currentId = id;
    var p = state.projects.filter(function (x) { return x.id === id; })[0];
    if (!p) return;
    $$('#projDropdownList .project-item').forEach(function (x) { x.setAttribute('aria-selected', x.dataset.id === id ? 'true' : 'false'); });
    renderContextCard(p);
    renderProjectDetail(p);
    renderCurrentStage(p);
    renderAssets();
    // 切换项目后，各内容视图需重新懒加载
    _entered = {};
  }

  function loadProjects() {
    var host = $('#projDropdownList');
    applyLoading(host);
    if (!CFG.projectsEndpoint) { finishProjects(FIX.projects); return; }
    apiGet(CFG.projectsEndpoint).then(function (res) {
      if (res.ok && Array.isArray(res.data) && res.data.length) finishProjects(normalizeProjects(res.data));
      else finishProjects(FIX.projects); // 后端未就绪 → fixture
    });
  }
  function normalizeProjects(arr) {
    return arr.map(function (p) {
      return {
        id: p.id, name: p.name || p.title, domain: p.domain || '', industry: p.industry || '',
        createdAt: (p.created_at || p.createdAt || '').slice(0, 10),
        status: p.status || 'neutral',
        statusLabel: p.status_label || p.statusLabel || '—',
        stage: p.stage || 1, stageLabel: p.stage_label || p.stageLabel || ('阶段 ' + (p.stage || 1)),
        stageItems: p.stage_items || p.stageItems || []
      };
    });
  }
  function finishProjects(list) {
    state.projects = list;
    state.currentId = list[0] ? list[0].id : null;
    renderProjectList(list);
    if (list[0]) { renderContextCard(list[0]); renderProjectDetail(list[0]); renderCurrentStage(list[0]); }
    renderAssets();
  }

  /* ---------------- 经营状态面板 ---------------- */
  var bizLoaded = false;
  function loadBizPanels() {
    if (bizLoaded) return; bizLoaded = true;
    renderService();
    renderOps();
  }
  function withLoading(endpoint, fn) {
    var host = $('[data-endpoint="' + endpoint + '"]');
    if (!host) return;
    applyLoading(host);
    // 轻微延时让骨架屏可见（演示 loading 状态模板）
    setTimeout(function () { fn(host); }, 320);
  }

  function renderService() {
    withLoading('service', function (host) {
      host.innerHTML = '<p>在设置中分别配置文本、视觉和向量模型。</p><a class="btn sm" href="/workbench?view=aikeys">模型配置</a>';
    });
  }

  function renderOps() {
    withLoading('ops', function (host) {
      paintOps(host, FIX.ops); // 运营概览当前以 fixture 呈现，可接 analytics 端点
    });
  }
  function paintOps(host, rows) {
    host.innerHTML = rows.map(function (r) {
      return '<div class="kv"><span class="k">' + r.k + '</span><span class="v"'
        + (r.warn ? ' style="color:var(--md-warning)"' : '') + '>' + r.v + '</span></div>';
    }).join('');
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  /* ---------------- 技能执行抽屉（四态） ---------------- */
  var drawer = $('#skillDrawer');
  var lastFocus = null;
  var runTimer = null, runSecs = 0, activeTask = null, runLogLines = [];

  function setDrawerState(s) {
    $$('[data-drawer-state]', drawer).forEach(function (el) { el.hidden = (el.dataset.drawerState !== s); });
  }
  function openDrawer() {
    if (activeTask) { setDrawerState('running'); }
    else {
      lastFocus = document.activeElement;
      drawer._lastFocus = lastFocus;
      setDrawerState('form');
      updateEstimate();
    }
    document.body.classList.add('drawer-open');
    drawer.classList.add('open');
    (drawer.querySelector('#drawerClose') || drawer.querySelector('[data-drawer-state="form"] button')).focus();
    $('#drawerTitle').textContent = '执行技能' + (state.currentId ? ' · ' + currentName() : '');
  }
  /* 通用关闭：关掉当前打开的任一抽屉并归还焦点（技能抽屉与文章抽屉共用同一 mask） */
  function closeActiveDrawer() {
    var open = $('.drawer.open');
    if (!open) { document.body.classList.remove('drawer-open'); return; }
    open.classList.remove('open');
    if (!document.querySelector('.drawer.open')) document.body.classList.remove('drawer-open');
    var lf = open._lastFocus;
    if (lf && lf.focus) lf.focus();
    else if (lastFocus && lastFocus.focus) lastFocus.focus();
  }
  function closeDrawer() { closeActiveDrawer(); }
  function currentName() {
    var p = state.projects.filter(function (x) { return x.id === state.currentId; })[0];
    return p ? p.name : '';
  }

  function populateSkills() {
    var sel = $('#f-skill');
    sel.innerHTML = '';
    FIX.skills.forEach(function (sk) {
      var o = document.createElement('option');
      o.value = sk.id; o.textContent = sk.name;
      sel.appendChild(o);
    });
    sel.addEventListener('change', updateEstimate);
  }
  function currentSkill() {
    var id = $('#f-skill').value;
    return FIX.skills.filter(function (s) { return s.id === id; })[0] || FIX.skills[0];
  }
  function updateEstimate() {
    var sk = currentSkill();
    $('#estCost').textContent = '用量以实际调用记录为准';
    var rb = $('#runBtn');
    if (rb) rb.textContent = '开始执行';
  }

  function startRun() {
    var sk = currentSkill();
    setDrawerState('running');
    runSecs = 0; $('#runSecs').textContent = '0';
    runLogLines = ['→ 读取知识库：诊疗项目 FAQ（28 条）', '→ 提取意图分组：价格 / 流程 / 恢复期 / 风险', '→ 正在生成结构化 FAQ（Schema 标注）…'];
    $('#runLog').innerHTML = runLogLines.join('<br>');
    activeTask = { skill: sk, startedAt: Date.now() };

    // 优先真实异步执行；失败则本地模拟（后端未就绪）
    if (CFG.skillExecuteAsyncEndpoint) {
      var payload = { project_id: state.currentId, skill: sk.id, source: $('#f-source').value, extra: $('#f-extra').value };
      apiPost(CFG.skillExecuteAsyncEndpoint, payload).then(function (res) {
        if (res.ok) simulateProgress(sk, true);
        else simulateProgress(sk, false);
      });
    } else {
      simulateProgress(sk, false);
    }
  }
  function simulateProgress(sk, real) {
    clearInterval(runTimer);
    runTimer = setInterval(function () {
      runSecs++;
      $('#runSecs').textContent = runSecs;
      if (runSecs === 3) $('#runLog').innerHTML += '<br>→ ' + (real ? '真实任务已提交，轮询中' : '本地模拟：生成中') + '…';
      if (runSecs >= 6) { clearInterval(runTimer); finishRun(sk); }
    }, 1000);
  }
  function finishRun(sk) {
    activeTask = null;
    setDrawerState('result');
    $('#resultMeta').textContent = '耗时 ' + runSecs + ' 秒';
    $('#resultDoc').innerHTML =
      '<h3>' + (sk ? sk.name : '技能') + ' · 产出预览</h3>' +
      '<p><strong>Q：种植一颗牙全流程需要多久？</strong><br>常规情况 3–6 个月，含骨结合期。即刻种植可当天戴临时冠……</p>' +
      '<p style="margin-top:8px;"><strong>Q：价格为什么差异大？</strong><br>差异主要来自植体品牌、骨条件与修复方式……（共 28 条，已附 FAQPage 结构化标注）</p>';
  }
  function abortRun() {
    clearInterval(runTimer);
    activeTask = null;
    setDrawerState('form');
    updateEstimate();
  }
  function backgroundRun() {
    // 转后台：关抽屉但保留轮询（runTimer 继续）
    document.body.classList.remove('drawer-open');
    toast('已转入后台执行，可随时点「执行技能」回到进度');
  }
  function showError(code, reason) {
    activeTask = null;
    setDrawerState('error');
    $('#errCode').textContent = code;
    $('#errReason').innerHTML = reason + ' <strong>本次未扣费，你的输入已保留。</strong>';
  }

  function initDrawer() {
    populateSkills();
    updateEstimate();
    $('#openDrawerBtn').addEventListener('click', openDrawer);
    $$('[data-open-drawer]').forEach(function (b) { b.addEventListener('click', openDrawer); });
    $('#drawerClose').addEventListener('click', closeDrawer);
    $('#drawerCancel1').addEventListener('click', closeDrawer);
    $('#drawerMask').addEventListener('click', closeActiveDrawer);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && document.body.classList.contains('drawer-open')) closeActiveDrawer();
    });
    $('#runBtn').addEventListener('click', startRun);
    $('#cancelRunBtn').addEventListener('click', abortRun);
    $('#bgRunBtn').addEventListener('click', backgroundRun);
    $('#againBtn').addEventListener('click', function () { setDrawerState('form'); updateEstimate(); });
    $('#backFormBtn').addEventListener('click', function () { setDrawerState('form'); updateEstimate(); });
    $('#saveAssetBtn').addEventListener('click', function () { toast('已保存到产出内容（演示）'); });
    $('#downloadDocxBtn').addEventListener('click', function () {
      var text = ($('#resultDoc').innerText || '').trim();
      var blob = new Blob([text], { type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' });
      var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'geo-output.docx'; a.click();
      toast('已下载 docx（演示）');
    });
    var ec = $('#errCode');
    if (ec) ec.addEventListener('click', function () { copyText(ec.textContent); toast('错误编号已复制'); });
  }

  /* ---------------- 项目情境卡下拉 ---------------- */
  function openProjDropdown() {
    var dd = $('#projDropdown'), btn = $('#projContextBtn');
    if (!dd) return;
    dd.hidden = false;
    btn.setAttribute('aria-expanded', 'true');
  }
  function closeProjDropdown() {
    var dd = $('#projDropdown'), btn = $('#projContextBtn');
    if (!dd) return;
    dd.hidden = true;
    btn.setAttribute('aria-expanded', 'false');
  }
  function initProjContext() {
    var btn = $('#projContextBtn'), dd = $('#projDropdown');
    if (!btn || !dd) return;
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      if (dd.hidden) openProjDropdown(); else closeProjDropdown();
    });
    dd.addEventListener('click', function (e) { e.stopPropagation(); });
    $('#newProjectBtn').addEventListener('click', function () { toast('新建项目（待接入）'); closeProjDropdown(); });
    document.addEventListener('click', function () { if (!dd.hidden) closeProjDropdown(); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !dd.hidden) { closeProjDropdown(); btn.focus(); }
    });
  }

  /* ---------------- 初始化 ---------------- */
  function init() {
    if (!CFG || !CFG.projectsEndpoint && !CFG.skillsEndpoint) {
      // 仍允许以 fixture 渲染，保证可演示
    }
    initNav();
    initProjContext();
    initAssetTabs();
    initDrawer();
    loadProjects();
    // 顶栏余额先用 fixture，进入经营状态后由真实端点刷新
    var av = $('#userAvatar'); if (av && CFG.tenantDomain) av.title = CFG.tenantDomain;
  }

  /* ---------------- 暴露 GW 命名空间供 geo-workbench-views.js 复用 ---------------- */
  window.GW = {
    registerView: registerView,
    getView: getView,
    hasEntered: hasEntered,
    markEntered: markEntered,
    goView: goView,
    setNavBadge: setNavBadge,
    setNavDot: setNavDot,
    apiGet: apiGet,
    apiPost: apiPost,
    apiPatch: apiPatch,
    apiDelete: apiDelete,
    apiUpload: apiUpload,
    toast: toast,
    applyLoading: applyLoading,
    applyEmpty: applyEmpty,
    applyError: applyError,
    copyText: copyText,
    $: $,
    $$: $$,
    FIX: FIX,
    state: state,
    openDrawer: openDrawer,
    closeActiveDrawer: closeActiveDrawer,
    CFG: CFG
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
