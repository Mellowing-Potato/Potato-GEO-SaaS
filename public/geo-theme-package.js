/* ============================================================================
   GEO 主题包生成面板 · 交互引擎（geo-theme-package.js）
   ----------------------------------------------------------------------------
   2026-09-03 渲染层整体重写（B 稿 · 主从两栏）。出处：
     design-specs/designs/geo-theme-package/{主题包生成 重做.html, SPEC.md}
     geo-saas/docs/主题包页重做-工兵执行书-2026-09-03.md

   保留不动的：session-login 拿 Sanctum token、401/403 守卫、apiReq / webReq、
   下载走 /download、预览 iframe 的 srcdoc ＋ sandbox=""。

   ── 三条红线 ─────────────────────────────────────────────────────────
   1. 仅消费真实端点 /api/v1/theme-packages/* 与 /theme-preview/*；
      任何失败均渲染显式错误态，绝不回退假数据、绝不塞假数。
   2. 进度模型用服务端的（progressSnapshot()）：阶段名取 progress.stages[].label、
      状态名取 progress.status_label 或服务端 lang 表，段数取 stages.length（7/8/9），
      百分比直接用 progress_percent（服务端已钳在 5~96、终态 100）。
      **前端不许出现阶段中文字面量，也不许 Math.max(pct, 8) 这类补丁** ——
      那种补丁是数据源接错了的症状。
   3. 文案：只说「主题包生成 / 读取参考站点的配色与字体」，
      绝不说「复制 / 克隆 / 复刻站点」。
   ============================================================================ */
(function () {
  'use strict';

  var $ = function (s, r) { return (r || document).querySelector(s); };

  var TK = 'geo_theme_package_token';
  var UK = 'geo_theme_package_user';

  var CFG = window.GEO_THEME_PACKAGE_CONFIG || {
    accountLoginUrl: '/login',
    accountUrl: '/#personal-settings',
    statusLabels: {},
    safePreviewNotice: '',
    previewPages: [],
    styleOptions: []
  };

  var POLL_MS = 3000;
  /* 并发上限：超过 5 个在跑，只给最新的 5 个拉进度，其余只显示状态徽标。 */
  var MAX_POLL = 5;
  var FEEDBACK_MAX = 2000;

  /* 终态四个，与 SiteThemeReplication::STATUS_* 以及服务端 progressSnapshot() 里
     $isTerminal 那个 in_array 逐字对应。非终态七个：queued / fetching / extracting /
     analyzing / generating / scanning / iterating。一共就这 11 个，**没有 cancelled**。

     ⚠️ 拿得到 progress 时一律以 progress.terminal 为准。字段名是 **terminal**，
        不是 is_terminal —— 本仓 SkillExecutionController / AiVisibilityController
        用的都是 is_terminal，照惯性写会永远拿到 undefined，而 undefined 是 falsy，
        于是每一行都被当成非终态，终态行也一直在打请求。
     ⚠️ 这份清单只在**还没有 progress 的时候**用：列表接口 GET /theme-packages
        不返回 progress，而「该不该给这一行打 /status」必须在拿到 progress 之前
        就决定。两者不会打架 —— 服务端的 terminal 正是拿 status 在这四个值里查的。 */
  var TERMINAL_STATUS = ['ready', 'published', 'archived', 'failed'];

  /* status → 视觉 tone 与相位。这一层是**前端的事**（服务端不管配色），
     和「状态中文」不同 —— 中文一律走服务端 lang（见 CFG.statusLabels）。 */
  var STATUS_TONE = {
    queued: { tone: 'neutral', phase: 'waiting' },
    fetching: { tone: 'info', phase: 'running' },
    extracting: { tone: 'info', phase: 'running' },
    analyzing: { tone: 'info', phase: 'running' },
    generating: { tone: 'info', phase: 'running' },
    scanning: { tone: 'info', phase: 'running' },
    iterating: { tone: 'info', phase: 'running' },
    failed: { tone: 'danger', phase: 'failed' },
    ready: { tone: 'success', phase: 'done' },
    published: { tone: 'success', phase: 'done' },
    archived: { tone: 'neutral', phase: 'archived' }
  };

  var ICONS = {
    plus: 'M12 5v14M5 12h14',
    close: 'M6 6l12 12M18 6L6 18',
    check: 'M4 12.5l5.5 5.5L20 7',
    alert: 'M12 8v5m0 3.5v.5M12 3 2.5 20h19L12 3Z',
    clock: 'M12 7v5l3.5 2M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Z',
    retry: 'M3 12a9 9 0 1 0 9-9M3 3v6h6',
    iterate: 'M4 7h11a4 4 0 0 1 0 8H8m0 0 3-3m-3 3 3 3',
    trash: 'M4 7h16M9 7V5h6v2m-8 0 1 13h8l1-13',
    download: 'M12 3v12m0 0 4-4m-4 4-4-4M4 19h16',
    link: 'M9.5 14.5 14.5 9.5M8 12l-2 2a3.5 3.5 0 0 0 5 5l2-2m-2-6 2-2a3.5 3.5 0 0 1 5 5l-2 2'
  };

  function icon(name, size) {
    var d = ICONS[name];
    if (!d) return '';
    var s = size || 16;
    return '<svg class="md-icon" width="' + s + '" height="' + s + '" viewBox="0 0 24 24" aria-hidden="true"'
      + ' fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'
      + '<path d="' + d + '"></path></svg>';
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  /* ---------------- 会话与权限守卫（保留原实现）---------------- */

  function token() { return sessionStorage.getItem(TK) || ''; }
  function userInfo() { try { return JSON.parse(sessionStorage.getItem(UK) || '{}') || {}; } catch (e) { return {}; } }
  function persistSession(p) {
    var t = p && (p.accessToken || p.access_token || p.token);
    if (!t) return;
    sessionStorage.setItem(TK, t);
    var u = (p && p.user) || null;
    if (u && !u.tenant && p && p.tenant) { u.tenant = p.tenant; }
    sessionStorage.setItem(UK, JSON.stringify(u));
  }
  function tenantSubdomain() {
    var t = (userInfo() || {}).tenant;
    return t && t.subdomain ? String(t.subdomain) : '';
  }
  function who() { return (userInfo().name || '已登录用户') + (tenantSubdomain() ? ' · 工作区 ' + tenantSubdomain() : ''); }
  function accountLoginUrl() {
    var base = CFG.accountLoginUrl || '/login';
    try { var t = new URL(base, window.location.href); t.searchParams.set('redirect_to', window.location.href); return t.toString(); }
    catch (e) { return base; }
  }

  function showGuard(kind) {
    var el = $('#guardState'); if (!el) return;
    el.hidden = false;
    var glyph = $('#guardGlyph');
    if (glyph) glyph.hidden = (kind !== 'forbidden' && kind !== 'error');
    var spinner = $('#guardIcon');
    if (spinner) spinner.hidden = (kind === 'forbidden' || kind === 'error');
    var title = $('#guardTitle');
    if (title) title.textContent = (kind === 'forbidden') ? '无访问权限' : (kind === 'error' ? '加载失败' : '连接中…');
    var text = $('#guardText');
    if (text) text.textContent = (kind === 'forbidden')
      ? '当前账号没有主题包生成权限（theme_package:manage）。如有疑问请联系系统管理员。'
      : (kind === 'error' ? '身份校验失败，请重新登录后重试。' : '正在校验主题包生成面板访问权限');
    var link = $('#guardLink');
    if (link) {
      if (kind === 'forbidden' || kind === 'error') { link.hidden = false; link.href = CFG.accountUrl; }
      else { link.hidden = true; }
    }
  }
  function hideGuard() { var el = $('#guardState'); if (el) el.hidden = true; }

  function handleUnauthorized() {
    sessionStorage.removeItem(TK);
    sessionStorage.removeItem(UK);
    fetch('/api/v1/auth/session-login', { method: 'POST', credentials: 'include', headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('relogin failed'); return r.json(); })
      .then(function (p) { persistSession(p); window.location.reload(); })
      .catch(function () { window.location.href = accountLoginUrl(); });
  }

  /* ---------------- 数据层：真实端点 + Bearer 鉴权 ---------------- */

  function apiReq(path, opts) {
    opts = opts || {};
    var headers = opts.headers || {};
    headers['Accept'] = 'application/json';
    var tk = token();
    if (tk) headers['Authorization'] = 'Bearer ' + tk;
    var sub = tenantSubdomain();
    if (sub) headers['X-Tenant'] = sub;
    if (opts.body && !(opts.body instanceof FormData)) headers['Content-Type'] = 'application/json';
    return fetch('/api/v1' + path, {
      method: opts.method || 'GET',
      credentials: 'include',
      headers: headers,
      body: opts.body
    }).then(function (r) {
      if (r.status === 401) { handleUnauthorized(); throw new Error('unauthorized'); }
      if (r.status === 403) { showGuard('forbidden'); throw new Error('forbidden'); }
      return r;
    });
  }

  function jsonOrThrow(r) { return r.json().catch(function () { return {}; }); }

  /* ⚠️ 预览是 **web 路由** /theme-preview/{id}/{page}，不在 /api/v1 下。
        不能走 apiReq —— 它会无条件拼 '/api/v1' 前缀，打到一条不存在的地址，
        表现是「预览永远加载失败」。这里单独拼请求，鉴权头照旧带上。 */
  function webReq(path) {
    var headers = { 'Accept': 'text/html' };
    var tk = token();
    if (tk) headers['Authorization'] = 'Bearer ' + tk;
    var sub = tenantSubdomain();
    if (sub) headers['X-Tenant'] = sub;
    return fetch(path, { credentials: 'include', headers: headers }).then(function (r) {
      if (r.status === 401) { handleUnauthorized(); throw new Error('unauthorized'); }
      return r;
    });
  }

  function errMessage(r, fallback) {
    return r.json().catch(function () { return {}; }).then(function (j) {
      throw new Error((j && j.message) ? j.message : fallback);
    });
  }

  /* ---------------- 状态 ---------------- */

  var state = {
    items: [],            // GET /theme-packages 的原样数据（服务端已按 id 倒序）
    progress: {},         // id → progressSnapshot()，来自 show / status
    advice: {},           // id → failure_advice（非 failed 时为 null）
    hydrated: {},         // id → 已为这条失败记录补过一次进度（只补一次，不轮询）
    selectedId: null,
    mode: 'detail',       // 'detail' | 'new'
    detail: null,         // GET /theme-packages/{id} 的整个响应体
    detailErr: '',
    q: '',
    previewPage: 'home',
    menuOpen: false,
    overlay: null,        // { kind: 'iterate' | 'delete', id }
    form: newForm(),
    formErr: '',
    booted: false
  };

  function newForm() {
    return {
      name: '', theme_id: '', base_theme_id: '',
      home_url: '', category_url: '', article_url: '',
      style_preference: (CFG.styleOptions[0] && CFG.styleOptions[0].value) || 'content_site'
    };
  }

  function findItem(id) {
    for (var i = 0; i < state.items.length; i++) {
      if (String(state.items[i].id) === String(id)) return state.items[i];
    }
    return null;
  }

  function progressOf(id) { return state.progress[id] || null; }

  function isTerminal(it) {
    var pr = progressOf(it.id);
    if (pr) return pr.terminal === true;
    return TERMINAL_STATUS.indexOf(String(it.status)) > -1;
  }
  function isFailed(it) {
    var pr = progressOf(it.id);
    if (pr) return pr.failed === true;
    return String(it.status) === 'failed';
  }
  function isDownloadable(status) { return status === 'ready' || status === 'published'; }

  /* 状态中文一律来自服务端：详情/轮询有 progress.status_label，
     列表那些不轮询的终态行走 CFG.statusLabels（同一个 lang 文件渲染进 blade 的）。
     ⚠️ 兜底 `|| status` 要保留：服务端将来加状态时界面不能白屏。 */
  function statusLabel(it) {
    var pr = progressOf(it.id);
    if (pr && pr.status_label) return pr.status_label;
    return (CFG.statusLabels && CFG.statusLabels[it.status]) || it.status;
  }

  function creatorName(it) {
    if (it.creator && it.creator.name) return it.creator.name;
    // 拿不到姓名时退回 #id，不显示裸整数当姓名（那正是缺陷 6 要修的）。
    return it.created_by_user_id ? '#' + it.created_by_user_id : '—';
  }

  function badgeHtml(it) {
    var meta = STATUS_TONE[it.status] || { tone: 'neutral', phase: 'waiting' };
    var glyph = meta.phase === 'running' ? '<span class="md-pulse" aria-hidden="true"></span>'
      : meta.phase === 'done' ? icon('check', 12)
      : meta.phase === 'failed' ? icon('alert', 12)
      : meta.phase === 'waiting' ? icon('clock', 12) : '';
    return '<span class="md-badge md-badge--' + meta.tone + '">' + glyph + esc(statusLabel(it)) + '</span>';
  }

  /* ---------------- 左栏 ---------------- */

  function filteredItems() {
    var k = state.q.trim().toLowerCase();
    if (!k) return state.items;
    return state.items.filter(function (p) {
      return (String(p.name) + ' ' + String(p.theme_id)).toLowerCase().indexOf(k) > -1;
    });
  }

  function currentStepIndex(pr) {
    for (var i = 0; i < pr.stages.length; i++) {
      if (pr.stages[i].state === 'current' || pr.stages[i].state === 'failed') return i;
    }
    return -1;
  }

  function rowHtml(it) {
    var pr = progressOf(it.id);
    var sep = '<span class="tp-row-sep">·</span>';
    var html = '<div class="tp-row-top"><span class="tp-row-name">' + esc(it.name) + '</span>' + badgeHtml(it) + '</div>'
      + '<div class="tp-row-mid"><code class="md-code">' + esc(it.theme_id) + '</code>'
      + sep + '<span>v' + (it.current_version || 0) + '</span>'
      + sep + '<span>' + esc(creatorName(it)) + '</span></div>';

    if (pr && !pr.terminal) {
      var step = currentStepIndex(pr) + 1;
      var total = pr.stages.length;
      var pct = Number(pr.progress_percent);
      if (!isFinite(pct)) pct = 0;
      /* 百分比直接用服务端的 progress_percent（已钳在 5~96，终态 100）。 */
      html += '<div class="tp-progress">'
        + '<span class="tp-progress-txt">' + esc(pr.current_step_label) + ' · 第 ' + step + '/' + total + ' 步</span>'
        + '<span class="tp-progress-track" role="img" aria-label="共 ' + total + ' 步，当前第 ' + step + ' 步">'
        + '<span class="tp-progress-fill" style="width:' + pct + '%"></span>'
        + '</span></div>';
    }
    if (pr && pr.failed) {
      // 列表里只给一句「失败在哪一步」；具体怎么办在右栏，不在这里挤。
      html += '<div class="tp-rowerr">在「' + esc(pr.current_step_label) + '」这一步失败</div>';
    }

    return '<button type="button" class="tp-row' + (String(it.id) === String(state.selectedId) ? ' is-sel' : '')
      + '" data-id="' + esc(it.id) + '">' + html + '</button>';
  }

  function groupHtml(title, items, tone) {
    if (!items.length) return '';   // 空组不渲染，不留空标题
    return '<div class="tp-group">'
      + '<div class="tp-group-head' + (tone ? ' is-' + tone : '') + '">' + esc(title)
      + '<span class="tp-group-n">' + items.length + '</span></div>'
      + items.map(rowHtml).join('') + '</div>';
  }

  function renderRail() {
    var host = $('#tpRailList');
    if (!host) return;
    var list = filteredItems();
    if (!state.booted) return;

    var running = list.filter(function (p) { return !isTerminal(p); });
    var failed = list.filter(function (p) { return isTerminal(p) && isFailed(p); });
    var settled = list.filter(function (p) { return isTerminal(p) && !isFailed(p); });

    var html = groupHtml('进行中', running, 'info')
      + groupHtml('需要处理', failed, 'danger')
      + groupHtml('已完成', settled, '');

    if (!list.length) {
      html = state.items.length
        ? '<div class="md-empty md-empty--sm"><div class="md-empty-title">没有匹配的主题包</div><p>换个关键词，或者新建一个。</p></div>'
        : '<div class="md-empty md-empty--sm"><div class="md-empty-title">还没有主题包</div><p>点上面「生成新主题包」创建第一个。</p></div>';
    }
    host.innerHTML = html;
  }

  /* ---------------- 右栏 · 阶段线 / 失败 / 产物 / 预览 ---------------- */

  function trackHtml(pr) {
    if (!pr || !pr.stages || !pr.stages.length) return '';

    /* 已完成 / 已归档收成一行摘要：铺 8 行全绿说不出「待确认」徽标
       没说过的事，只会把纵向空间从真正要看的预览那里抢走。 */
    if (pr.terminal && !pr.failed) {
      return '<div class="tp-trackdone">'
        + '<span class="tp-trackdone-mark" aria-hidden="true">' + icon('check', 13) + '</span>'
        + '<span>' + pr.stages.length + ' 步全部完成'
        + (pr.last_updated ? '，最后更新于 ' + esc(formatChinaDateTime(pr.last_updated, false)) : '') + '</span>'
        + '<span class="tp-trackdone-steps">'
        + pr.stages.map(function (st) { return '<span>' + esc(st.label) + '</span>'; }).join('')
        + '</span></div>';
    }

    var steps = pr.stages.map(function (st, i) {
      var mark = st.state === 'done' ? icon('check', 13)
        : st.state === 'failed' ? icon('alert', 13)
        : st.state === 'current' ? '<span class="md-pulse"></span>'
        : '<span class="tp-track-num">' + (i + 1) + '</span>';
      return '<li class="tp-track-step is-' + esc(st.state) + '">'
        + '<span class="tp-track-mark" aria-hidden="true">' + mark + '</span>'
        + '<span class="tp-track-body">'
        + '<span class="tp-track-label">' + esc(st.label)
        + (st.time ? '<span class="tp-track-time">' + esc(formatChinaDateTime(st.time, true)) + '</span>' : '') + '</span>'
        + '<span class="tp-track-hint">' + esc(st.message || st.description || '') + '</span>'
        + '</span></li>';
    }).join('');

    return '<ol class="tp-track" aria-label="生成进度，共 ' + pr.stages.length + ' 步">' + steps + '</ol>';
  }

  function adviceHtml(advice, rawError) {
    if (!advice) return '';
    var actions = (advice.actions && advice.actions.length)
      ? '<ol class="tp-fixlist">' + advice.actions.map(function (a) { return '<li>' + esc(a) + '</li>'; }).join('') + '</ol>'
      : '';
    return '<div class="md-alert">' + icon('alert', 16) + '<div>'
      + '<strong>' + esc(advice.title) + '</strong>'
      + '<p>' + esc(advice.description) + '</p>'
      + actions
      + (rawError ? '<p class="tp-rawerr">原始报错：' + esc(rawError) + '</p>' : '')
      + '<button type="button" class="md-btn md-btn--secondary md-btn--sm" data-act="retry">'
      + icon('retry', 14) + '修好了，重试</button>'
      + '</div></div>';
  }

  /* 提取到的配色是**服务端从参考站 CSS 里正则抓出来的原文**，不是我们生成的值，
     所以它会原样进 style 属性 —— 必须先过白名单，否则等于把参考站的内容
     当样式注入本页。只放行 #hex 与 rgb()/rgba() 两种形态。 */
  function safeCssColor(value) {
    var v = String(value == null ? '' : value).trim();
    if (/^#[0-9a-fA-F]{3,8}$/.test(v)) return v;
    if (/^rgba?\(\s*[0-9.,%\s/]+\)$/.test(v)) return v;
    return '';
  }

  function formatChinaDateTime(value, timeOnly) {
    if (!value) return '';
    var raw = String(value).trim();
    /* 兼容修复前接口返回的无时区 UTC 字符串。新接口返回带 +00:00 的 ISO。 */
    if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(raw)) {
      raw = raw.replace(' ', 'T') + 'Z';
    }
    var date = new Date(raw);
    if (isNaN(date.getTime())) return String(value);
    var options = {
      timeZone: 'Asia/Shanghai', hour: '2-digit', minute: '2-digit', second: '2-digit',
      hour12: false, hourCycle: 'h23'
    };
    if (!timeOnly) {
      options.year = 'numeric';
      options.month = '2-digit';
      options.day = '2-digit';
    }
    return new Intl.DateTimeFormat('zh-CN', options).format(date);
  }

  function displayFontFamilies(values) {
    var known = {
      'inter': 'Inter',
      'jetbrains mono': 'JetBrains Mono',
      'noto sans sc': 'Noto Sans SC',
      'noto serif sc': 'Noto Serif SC',
      'pingfang sc': 'PingFang SC',
      'microsoft yahei': 'Microsoft YaHei',
      'source han sans': 'Source Han Sans',
      'source han serif': 'Source Han Serif',
      'segoe ui': 'Segoe UI'
    };
    var seen = {};
    var fonts = [];
    (values || []).forEach(function (declaration) {
      String(declaration == null ? '' : declaration).split(',').forEach(function (font) {
        var clean = font.trim().replace(/^['\"]+|['\"]+$/g, '').replace(/\s+/g, ' ');
        var key = clean.toLowerCase();
        if (!clean || key.indexOf('var(') === 0 || seen[key]) return;
        seen[key] = true;
        fonts.push(known[key] || clean);
      });
    });
    return fonts.slice(0, 6);
  }

  function extractedHtml(d) {
    var tokens = (d && d.analysis_json && d.analysis_json.tokens) || {};
    var colors = [];
    (tokens.colors || []).forEach(function (c) {
      var safe = safeCssColor(c);
      if (safe && colors.length < 8) colors.push(safe);
    });
    var fonts = displayFontFamilies(tokens.font_families || []).slice(0, 4);
    if (!colors.length && !fonts.length) return '';

    var swatches = colors.map(function (c) {
      return '<span class="tp-swatch" style="background:' + esc(c) + '" title="' + esc(c) + '">'
        + '<span class="tp-swatch-hex">' + esc(c) + '</span></span>';
    }).join('');

    return '<div class="tp-extracted">'
      + (colors.length
        ? '<div class="tp-extracted-row"><span class="tp-extracted-key">提取到的配色</span>'
          + '<span class="tp-swatches">' + swatches + '</span></div>' : '')
      + (fonts.length
        ? '<div class="tp-extracted-row"><span class="tp-extracted-key">提取到的字体</span>'
          + '<span class="tp-fontlist">'
          + fonts.map(function (font) { return '<span class="tp-fontchip">' + esc(font) + '</span>'; }).join('')
          + '</span></div>' : '')
      + '</div>';
  }

  function previewHtml(id) {
    var tabs = (CFG.previewPages || []).map(function (p) {
      var on = state.previewPage === p.key;
      return '<button type="button" role="tab" aria-selected="' + (on ? 'true' : 'false') + '"'
        + ' class="md-seg' + (on ? ' is-on' : '') + '" data-prev="' + esc(p.key) + '">' + esc(p.label) + '</button>';
    }).join('');

    return '<div class="tp-preview">'
      + '<div class="tp-preview-bar">'
      + '<div class="md-segmented" role="tablist" aria-label="预览页型">' + tabs + '</div>'
      + '<span class="tp-preview-path">' + icon('link', 13) + '/theme-preview/' + esc(id) + '/' + esc(state.previewPage) + '</span>'
      + '</div>'
      /* ⚠️ 隔离全靠 sandbox=""（无脚本、无同源）：内容走 srcdoc，不经过响应头，
         服务端那套 CSP / X-Frame-Options 对这条路径不生效。这里一个字都不要改。 */
      + '<div class="tp-preview-stage">'
      + '<iframe class="tp-frame" id="tpFrame" sandbox="" title="主题包预览"></iframe>'
      + '<div class="tp-preview-load" id="tpPrevMsg"><span class="md-pulse"></span>载入预览…</div>'
      + '</div>'
      // 缺陷 11：这句服务端 lang 里一直有（safe_preview.notice），界面上从没显示过。
      + '<p class="tp-safenotice">' + esc(CFG.safePreviewNotice) + '</p>'
      + '</div>';
  }

  function menuHtml(d) {
    if (!state.menuOpen) return '';
    var retry = d.status === 'failed'
      ? '<button type="button" role="menuitem" data-act="retry">' + icon('retry', 14) + '重试</button>' : '';
    return '<div class="md-menu" role="menu">'
      + retry
      + '<button type="button" role="menuitem" data-act="copy-theme-id">' + icon('link', 14) + '复制主题标识</button>'
      + '<hr>'
      /* 删除只在这里，列表行里根本不出现（缺陷 8）。 */
      + '<button type="button" role="menuitem" class="is-danger" data-act="delete">' + icon('trash', 14) + '删除主题包</button>'
      + '</div>';
  }

  /* 右栏当前这条的数据：详情还没回来时先用列表里那一行（名字/状态/版本都够画头部），
     analysis_json / versions 这些列表没有的东西等详情到了再出现。绝不造假数据。 */
  function detailData() {
    if (state.detail && state.detail.data && String(state.detail.data.id) === String(state.selectedId)) {
      return state.detail.data;
    }
    return findItem(state.selectedId);
  }

  function detailHtml() {
    var d = detailData();
    if (!d) {
      return '<div class="tp-detail"><div class="tp-detail-body">'
        + (state.detailErr
          ? '<div class="md-alert">' + icon('alert', 16) + '<div><strong>详情加载失败</strong><p>' + esc(state.detailErr) + '</p></div></div>'
          : '<div class="md-empty"><div class="md-empty-title">从左边选一个主题包</div><p>或者点左上角「生成新主题包」。</p></div>')
        + '</div></div>';
    }

    var pr = progressOf(d.id) || (state.detail ? state.detail.progress : null);
    var advice = Object.prototype.hasOwnProperty.call(state.advice, d.id)
      ? state.advice[d.id]
      : (state.detail ? state.detail.failure_advice : null);

    var body = trackHtml(pr)
      + adviceHtml(advice, d.error_message)
      + extractedHtml(d);

    /* 详情还没回来时不渲染预览块：渲染了的话，等详情到达再渲染一次，
       /theme-preview 会被打两次（第一次那个 iframe 已经被换掉、纯属浪费）。
       这里先占位，等 show 回来只渲染一次。 */
    var detailLoaded = !!(state.detail && state.detail.data
      && String(state.detail.data.id) === String(state.selectedId));

    if (isDownloadable(d.status) && !detailLoaded) {
      body += '<div class="md-skeleton" role="status" aria-label="加载中">'
        + '<div class="md-sk md-sk--w80"></div><div class="md-sk md-sk--w60"></div>'
        + '<div class="md-sk md-sk--w40"></div></div>';
    } else if (isDownloadable(d.status)) {
      body += previewHtml(d.id);
    } else {
      /* 三种情形要说三句不同的话。archived 单列出来，是因为它已经跑完了 ——
         对它说「生成完成后……会在这里可预览」等于告诉用户再等等，而它永远不会变。 */
      var emptyTitle, emptyHint;
      if (d.status === 'failed') {
        emptyTitle = '这一版没有产出，修好参考站链接后重试';
        emptyHint = '';
      } else if (d.status === 'archived') {
        emptyTitle = '这个主题包已归档，不再提供预览与下载';
        emptyHint = '<p>归档前生成的文件已按保留期回收。需要的话可以重新生成一份。</p>';
      } else {
        emptyTitle = '生成完成后，三种页型会在这里可预览';
        emptyHint = '<p>进度每 3 秒自动刷新，不用手动刷页面。你也可以离开本页。</p>';
      }
      body += '<div class="md-empty"><div class="md-empty-title">' + emptyTitle + '</div>' + emptyHint + '</div>';
    }

    var actionbar = isDownloadable(d.status)
      ? '<div class="tp-actionbar">'
        + '<button type="button" class="md-btn md-btn--primary" data-act="download">'
        + icon('download', 15) + '下载 v' + (d.current_version || 0) + '</button>'
        + '<button type="button" class="md-btn md-btn--secondary" data-act="iterate">'
        + icon('iterate', 15) + '迭代出 v' + ((d.current_version || 0) + 1) + '</button>'
        + '<span class="tp-actionbar-hint">下载的是主题包 zip，可在站点后台自行安装。</span>'
        + '</div>'
      : '';

    var created = d.created_at ? String(d.created_at).slice(0, 10) : '';

    return '<div class="tp-detail">'
      + '<div class="tp-detail-head">'
      + '<div class="tp-detail-id"><h2 class="md-h2">' + esc(d.name) + '</h2>'
      + '<div class="tp-detail-meta"><code class="md-code">' + esc(d.theme_id) + '</code>'
      + '<span class="tp-row-sep">·</span>v' + (d.current_version || 0)
      + '<span class="tp-row-sep">·</span>' + esc(creatorName(d))
      + (created ? ' 创建于 ' + esc(created) : '') + '</div></div>'
      + badgeHtml(d)
      + '<div class="md-menuhost">'
      + '<button type="button" class="md-iconbtn" id="tpKebab" aria-label="更多操作" aria-haspopup="menu"'
      + ' aria-expanded="' + (state.menuOpen ? 'true' : 'false') + '">⋯</button>'
      + menuHtml(d)
      + '</div></div>'
      + '<div class="tp-detail-body">' + body + '</div>'
      + actionbar
      + '</div>';
  }

  /* ---------------- 右栏 · 新建三步 ---------------- */

  var SLOTS = [
    { key: 'home_url', title: '首页', hint: '品牌色与导航结构', ph: 'https://example.com/' },
    { key: 'category_url', title: '分类页', hint: '列表密度', ph: 'https://example.com/category/ai/' },
    { key: 'article_url', title: '文章页', hint: '正文字号与行高关系', ph: 'https://example.com/2026/08/notes/' }
  ];

  function composeHtml() {
    var f = state.form;

    var slots = SLOTS.map(function (s) {
      return '<label class="tp-slot' + (f[s.key] ? ' is-filled' : '') + '">'
        + '<span class="tp-slot-head"><span class="tp-slot-t">' + esc(s.title) + '</span>'
        + '<span class="tp-slot-h">' + esc(s.hint) + '</span></span>'
        + '<input class="md-input" type="url" name="' + s.key + '" placeholder="' + esc(s.ph) + '"'
        + ' value="' + esc(f[s.key]) + '">'
        + '</label>';
    }).join('');

    var styles = (CFG.styleOptions || []).map(function (o) {
      var on = f.style_preference === o.value;
      return '<label class="tp-style' + (on ? ' is-on' : '') + '">'
        + '<input type="radio" name="style_preference" value="' + esc(o.value) + '"' + (on ? ' checked' : '') + '>'
        + '<span class="tp-style-t">' + esc(o.label) + '</span>'
        + '<span class="tp-style-h">' + esc(o.hint) + '</span>'
        + '</label>';
    }).join('');

    return '<form class="tp-compose" id="tpNewForm" novalidate>'
      + '<div class="tp-compose-head"><h2 class="md-h2">生成新主题包</h2>'
      + '<button type="button" class="md-iconbtn" data-act="close-new" aria-label="关闭">' + icon('close', 18) + '</button></div>'

      + '<div class="tp-step"><div class="tp-step-n">1</div><div class="tp-step-body">'
      + '<div class="tp-step-title">给它起个名字</div>'
      + '<div class="md-formgrid">'
      + '<label class="md-field"><span class="md-field-label">主题包名称</span>'
      + '<input class="md-input" name="name" maxlength="120" placeholder="例如：科技博客主题" value="' + esc(f.name) + '"></label>'
      + '<label class="md-field"><span class="md-field-label">主题标识</span>'
      + '<input class="md-input md-input--mono" name="theme_id" maxlength="64" placeholder="tech-blog" value="' + esc(f.theme_id) + '">'
      + '<span class="md-field-hint">同租户内唯一，小写字母、数字与连字符</span></label>'
      + '</div></div></div>'

      + '<div class="tp-step"><div class="tp-step-n">2</div><div class="tp-step-body">'
      + '<div class="tp-step-title">给我看三种页型</div>'
      /* 缺陷 4 的正面修复：生产表单只写三个「链接」，不解释为什么要三个。 */
      + '<p class="md-note"><strong>为什么要三个链接：</strong>首页给品牌色与导航结构，'
      + '分类页给列表密度，文章页给正文字号与行高关系。少一个，生成出来的主题在那一类页面上就是猜的。</p>'
      + '<div class="tp-slots">' + slots + '</div>'
      + '</div></div>'

      + '<div class="tp-step"><div class="tp-step-n">3</div><div class="tp-step-body">'
      + '<div class="tp-step-title">告诉它这是什么类型的站</div>'
      + '<div class="tp-styles" role="radiogroup" aria-label="风格偏好">' + styles + '</div>'
      + '<label class="md-field tp-base"><span class="md-field-label">基础主题<span class="md-opt">选填</span></span>'
      + '<input class="md-input md-input--mono" name="base_theme_id" maxlength="64" placeholder="geoflow-template-01" value="' + esc(f.base_theme_id) + '">'
      + '<span class="md-field-hint">在已有主题上改，而不是从零生成。留空即从零。</span></label>'
      + '</div></div>'

      + '<div class="tp-compose-foot">'
      + '<button type="submit" class="md-btn md-btn--primary" id="tpSubmit">' + icon('plus', 15) + '生成主题包</button>'
      + '<span class="md-formfoot-hint">通常 1 分钟内完成。可以离开本页，左栏会一直显示进度。</span>'
      + (state.formErr ? '<span class="md-err">' + esc(state.formErr) + '</span>' : '')
      + '</div></form>';
  }

  function renderMain() {
    var host = $('#tpMain');
    if (!host) return;
    host.innerHTML = state.mode === 'new' ? composeHtml() : detailHtml();
    if (state.mode === 'detail') {
      var d = detailData();
      // ready / published 进详情就默认载入首页，不留空灰块（缺陷 7）。
      if (d && isDownloadable(d.status)) loadPreview(d.id, state.previewPage);
    }
  }

  function render() { renderRail(); renderMain(); }

  /* ---------------- 预览 / 下载 ---------------- */

  function loadPreview(id, page) {
    var frame = $('#tpFrame');
    var msg = $('#tpPrevMsg');
    if (!frame) return;
    if (msg) { msg.hidden = false; msg.className = 'tp-preview-load'; msg.innerHTML = '<span class="md-pulse"></span>载入预览…'; }
    webReq('/theme-preview/' + id + '/' + page).then(function (r) {
      if (!r.ok) throw new Error('preview ' + r.status);
      return r.text();
    }).then(function (html) {
      if (String(state.selectedId) !== String(id) || state.previewPage !== page) return;   // 已经切走了
      /* srcdoc 不走响应头，服务端那套 CSP / X-Frame-Options 对这条路径不生效，
         隔离全靠 iframe 自己的 sandbox=""（无脚本、无同源）。 */
      frame.srcdoc = html;
      var m = $('#tpPrevMsg');
      if (m) m.hidden = true;
    }).catch(function (e) {
      var m = $('#tpPrevMsg');
      if (!m) return;
      m.className = 'tp-preview-load is-err';
      m.textContent = (String(e && e.message).indexOf('403') > -1)
        ? '无权预览该主题包'
        : '预览加载失败，请确认该主题包已生成完成';
    });
  }

  function downloadPkg(id) {
    apiReq('/theme-packages/' + id + '/download').then(function (r) {
      // 409 带的是可执行的文案（「点重新生成」/「还没生成完」），必须显示出来，
      // 静默 catch 会让用户以为按钮坏了。
      if (!r.ok) return errMessage(r, '下载失败，请稍后重试');
      return r.blob();
    }).then(function (blob) {
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'theme-package-' + id + '.zip';
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
      toast('主题包 zip 已开始下载');
    }).catch(function (e) {
      if (e && e.message === 'unauthorized') return;
      toast((e && e.message) ? e.message : '下载失败', 'err');
    });
  }

  /* ---------------- 抽屉 / 对话框 / toast ---------------- */

  function closeOverlay() {
    state.overlay = null;
    var host = $('#tpOverlay');
    if (host) host.innerHTML = '';
    document.removeEventListener('keydown', onOverlayKey);
  }
  function onOverlayKey(e) { if (e.key === 'Escape') closeOverlay(); }

  function openOverlay(html, focusSel) {
    var host = $('#tpOverlay');
    if (!host) return;
    host.innerHTML = html;
    document.addEventListener('keydown', onOverlayKey);
    var el = focusSel ? $(focusSel) : null;
    if (el) el.focus();
  }

  function iterateSheet(d) {
    var versions = ((state.detail && state.detail.data && state.detail.data.versions) || [])
      .filter(function (v) { return v.feedback; })
      .sort(function (a, b) { return b.version - a.version; });

    var history = versions.length
      ? '<div class="md-history"><div class="md-history-head">往次反馈</div>'
        + versions.map(function (v) {
          return '<div class="md-history-item">'
            + '<span class="md-history-key">v' + esc(v.version) + '</span>'
            + '<span class="md-history-text">' + esc(v.feedback) + '</span>'
            + '<span class="md-history-at">' + esc(String(v.created_at || '').slice(0, 16).replace('T', ' ')) + '</span>'
            + '</div>';
        }).join('') + '</div>'
      : '';

    var next = (d.current_version || 0) + 1;
    /* ⚠️ 遮罩上不要挂 data-act："close-overlay" 会被面板内部任何一次点击
       通过 closest('[data-act]') 向上找到 —— 那样点文本域都会把抽屉关掉。
       点空白关闭改由下面 classList.contains('md-scrim') 那一条判。 */
    return '<div class="md-scrim">'
      + '<aside class="md-sheet" role="dialog" aria-modal="true" aria-label="迭代 ' + esc(d.name) + '" data-stop="1">'
      + '<header class="md-sheet-head"><div>'
      + '<div class="md-sheet-title">迭代出 v' + next + '</div>'
      + '<div class="md-sheet-sub">在 v' + (d.current_version || 0) + ' 的基础上改，不是从零重生成</div></div>'
      + '<button type="button" class="md-iconbtn" data-act="close-overlay" aria-label="关闭">' + icon('close', 18) + '</button>'
      + '</header>'
      + '<div class="md-sheet-body">'
      + '<label class="md-field"><span class="md-field-label">希望调整的方向</span>'
      + '<span class="md-field-hint">写清楚要改哪里、改成什么样。会连同上一版产物一起交给生成器。</span>'
      + '<textarea class="md-textarea" id="tpFeedback" rows="6"'
      + ' placeholder="例如：正文再宽一点，侧栏收窄到 240px；标题字重降一档"></textarea>'
      + '<span class="md-count" id="tpFeedbackCount">0 / ' + FEEDBACK_MAX + '</span></label>'
      + history
      + '</div>'
      + '<footer class="md-sheet-foot">'
      + '<button type="button" class="md-btn md-btn--secondary" data-act="close-overlay">取消</button>'
      + '<button type="button" class="md-btn md-btn--primary" id="tpIterateSubmit" data-act="iterate-submit" disabled>'
      + icon('iterate', 15) + '提交迭代</button>'
      + '</footer></aside></div>';
  }

  function deleteDialog(d) {
    return '<div class="md-scrim md-scrim--center">'
      + '<div class="md-dialog" role="alertdialog" aria-modal="true" data-stop="1">'
      + '<div class="md-dialog-icon">' + icon('alert', 20) + '</div>'
      + '<div class="md-dialog-title">删除「' + esc(d.name) + '」？</div>'
      + '<p class="md-dialog-text">会同时删除它的 ' + (d.current_version || 0)
      + ' 个版本与<strong>全部生成文件，不可恢复</strong>。已经用这套主题发布过的站点不受影响。</p>'
      + '<div class="md-dialog-acts">'
      + '<button type="button" class="md-btn md-btn--secondary" data-act="close-overlay" id="tpCancelDelete">不删了</button>'
      + '<button type="button" class="md-btn md-btn--danger" data-act="delete-confirm">' + icon('trash', 15) + '确认删除</button>'
      + '</div></div></div>';
  }

  var toastTimer = null;
  function toast(text, tone) {
    var host = $('#tpToastHost');
    if (!host) return;
    host.innerHTML = '<div class="md-toast' + (tone === 'err' ? ' is-err' : '') + '" role="status">'
      + icon(tone === 'err' ? 'alert' : 'check', 15) + esc(text) + '</div>';
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { host.innerHTML = ''; }, 3600);
  }

  /* ---------------- 加载与轮询 ---------------- */

  function loadList(keepSelection) {
    return apiReq('/theme-packages').then(jsonOrThrow).then(function (j) {
      state.items = (j && j.data) || [];
      state.booted = true;
      if (!keepSelection && state.selectedId === null && state.items.length) {
        selectPkg(state.items[0].id);
      } else {
        renderRail();
      }
      // ⚠️ 这两句不能放进上面的 if/else 里任何一边：自动选中那条早退过一次，
      //    结果失败行永远补不到进度、轮询也起不来（第一版就是这么写错的）。
      hydrateFailedRows();
      startPoll();
    }).catch(function (e) {
      if (e && (e.message === 'unauthorized' || e.message === 'forbidden')) return;
      state.booted = true;
      var host = $('#tpRailList');
      if (host) {
        host.innerHTML = '<div class="md-empty md-empty--sm"><div class="md-empty-title">列表加载失败</div>'
          + '<p>接口没有返回数据，刷新页面重试。</p></div>';
      }
    });
  }

  /* 失败行也要显示「在哪一步失败」，而这句话来自 progress.current_step_label。
     失败是终态、不该进轮询，所以这里对每条失败记录**只补一次** /status，
     结果进 state.progress 后永不再拉（state.hydrated 记账）。 */
  function hydrateFailedRows() {
    var pending = state.items.filter(function (it) {
      return String(it.status) === 'failed' && !state.progress[it.id] && !state.hydrated[it.id];
    }).slice(0, MAX_POLL);
    if (!pending.length) return;
    pending.forEach(function (it) { state.hydrated[it.id] = true; });

    Promise.allSettled(pending.map(function (it) {
      return apiReq('/theme-packages/' + it.id + '/status').then(jsonOrThrow).then(function (j) {
        return { id: it.id, body: j };
      });
    })).then(function (results) {
      var touched = false;
      results.forEach(function (r) {
        if (r.status !== 'fulfilled' || !r.value || !r.value.body) return;
        if (r.value.body.progress) { state.progress[r.value.id] = r.value.body.progress; touched = true; }
        state.advice[r.value.id] = r.value.body.failure_advice || null;
      });
      if (touched) renderRail();
    });
  }

  function loadDetail(id) {
    state.detailErr = '';
    return apiReq('/theme-packages/' + id).then(jsonOrThrow).then(function (j) {
      if (String(state.selectedId) !== String(id)) return;   // 用户已经切走了
      state.detail = j;
      if (j.progress) state.progress[id] = j.progress;
      state.advice[id] = j.failure_advice || null;
      var row = findItem(id);
      if (row && j.data) { row.status = j.data.status; row.current_version = j.data.current_version; row.creator = j.data.creator || row.creator; }
      render();
      startPoll();
    }).catch(function (e) {
      if (e && (e.message === 'unauthorized' || e.message === 'forbidden')) return;
      if (String(state.selectedId) !== String(id)) return;
      state.detailErr = '详情接口没有返回数据，请稍后重试。';
      renderMain();
    });
  }

  function selectPkg(id) {
    state.selectedId = id;
    state.mode = 'detail';
    state.menuOpen = false;
    state.previewPage = (CFG.previewPages[0] && CFG.previewPages[0].key) || 'home';
    if (!state.detail || String(state.detail.data && state.detail.data.id) !== String(id)) state.detail = null;
    render();
    loadDetail(id);
  }

  var pollTimer = null;
  function stopPoll() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }

  function pollTargets() {
    /* 只对非终态行发请求；items 已按 id 倒序（服务端 latest('id')），
       所以 slice 就是「最新的 5 个」，其余只显示状态徽标、不显示进度条。 */
    return state.items.filter(function (it) { return !isTerminal(it); }).slice(0, MAX_POLL);
  }

  function startPoll() {
    stopPoll();
    if (document.hidden) return;
    if (!pollTargets().length) return;
    /* ⚠️ 不要用「跑满 N 轮就停」：远端站点响应慢时生成时间不可预测，N 定小了进度条会停在半路，
       客户会以为卡死了。停的条件只有两个：全部变终态，或者页面被切到后台。 */
    pollTimer = setInterval(pollTick, POLL_MS);
  }

  function pollTick() {
    var targets = pollTargets();
    if (!targets.length) { stopPoll(); return; }

    /* ⚠️ allSettled 不是 all：某一行的 /status 挂了只影响那一行
       （保留上一次的进度、状态徽标照旧），不能一挂全挂。 */
    Promise.allSettled(targets.map(function (it) {
      return apiReq('/theme-packages/' + it.id + '/status').then(jsonOrThrow).then(function (j) {
        return { id: it.id, body: j };
      });
    })).then(function (results) {
      var railDirty = false;
      var selectedDirty = false;
      var reloadList = false;
      var reloadDetail = false;

      results.forEach(function (r) {
        if (r.status !== 'fulfilled' || !r.value || !r.value.body) return;
        var id = r.value.id, body = r.value.body, pr = body.progress || null;
        var row = findItem(id);

        var isSelected = String(id) === String(state.selectedId);

        if (row && body.data) {
          if (String(row.status) !== String(body.data.status)) {
            railDirty = true;
            if (isSelected) reloadDetail = true;
          }
          row.status = body.data.status;
          row.current_version = body.data.current_version;
          row.error_message = body.data.error_message;
          if (state.detail && state.detail.data && String(state.detail.data.id) === String(id)) {
            state.detail.data.status = body.data.status;
            state.detail.data.current_version = body.data.current_version;
            state.detail.data.error_message = body.data.error_message;
          }
        }
        if (pr) {
          var prev = state.progress[id];
          if (!prev || prev.progress_percent !== pr.progress_percent
            || prev.current_step !== pr.current_step || prev.terminal !== pr.terminal) {
            railDirty = true;
            if (isSelected) selectedDirty = true;
          }
          if (pr.terminal) reloadList = true;    // 版本号 / 产物这些只有列表与详情才有
          state.progress[id] = pr;
        }
        state.advice[id] = body.failure_advice || null;
      });

      if (railDirty) renderRail();
      /* ⚠️ 右栏只在**选中那一条**变了的时候重画。按 railDirty 重画的话，
         「选中一个已完成的包 + 另一个包还在跑」时，预览 iframe 会每 3 秒
         被重建并重新拉一次 /theme-preview，看上去就是预览一直在闪。 */
      if (selectedDirty && state.mode === 'detail' && !reloadDetail) renderMain();
      if (reloadDetail && state.selectedId !== null) loadDetail(state.selectedId);
      if (reloadList) loadList(true);
      if (!pollTargets().length) stopPoll();
    });
  }

  /* ---------------- 事件 ---------------- */

  function submitNew() {
    var payload = {
      name: state.form.name.trim(),
      theme_id: state.form.theme_id.trim(),
      base_theme_id: state.form.base_theme_id.trim() || null,
      home_url: state.form.home_url.trim(),
      category_url: state.form.category_url.trim(),
      article_url: state.form.article_url.trim(),
      style_preference: state.form.style_preference
    };
    var missing = [];
    if (!payload.name) missing.push('名称');
    if (!payload.theme_id) missing.push('主题标识');
    if (!payload.home_url) missing.push('首页链接');
    if (!payload.category_url) missing.push('分类页链接');
    if (!payload.article_url) missing.push('文章页链接');
    if (missing.length) {
      state.formErr = '还差：' + missing.join('、');
      renderMain();
      return;
    }
    state.formErr = '';
    var btn = $('#tpSubmit');
    if (btn) btn.disabled = true;

    apiReq('/theme-packages', { method: 'POST', body: JSON.stringify(payload) })
      .then(function (r) { return r.ok ? r.json() : errMessage(r, '创建失败，请稍后重试'); })
      .then(function (j) {
        var created = (j && j.data) || null;
        if (!created) throw new Error('创建失败，请稍后重试');
        state.items.unshift(created);
        state.form = newForm();
        state.selectedId = created.id;
        state.mode = 'detail';
        state.detail = null;
        state.menuOpen = false;
        render();
        toast('已创建「' + created.name + '」，已排进队列');
        /* ⚠️ 201 的响应体里**没有** progress（store() 不走 progressSnapshot），
           所以这里必须立刻补一次 /status，否则新行的进度条会是 0% 并停在那儿、
           右栏的阶段区是空的。原型里那句 created.progress = tpBuildProgress(created)
           就是在替代这次请求。 */
        return apiReq('/theme-packages/' + created.id + '/status').then(jsonOrThrow).then(function (s) {
          if (s && s.progress) state.progress[created.id] = s.progress;
          state.advice[created.id] = (s && s.failure_advice) || null;
          render();
          startPoll();
        }).catch(function () { startPoll(); });
      })
      .catch(function (e) {
        if (e && (e.message === 'unauthorized' || e.message === 'forbidden')) return;
        state.formErr = (e && e.message) ? e.message : '创建失败，请稍后重试';
        renderMain();
      })
      .then(function () {
        var b = $('#tpSubmit');
        if (b) b.disabled = false;
      });
  }

  function doRetry(id) {
    apiReq('/theme-packages/' + id + '/retry', { method: 'POST' })
      .then(function (r) { return r.ok ? r.json() : errMessage(r, '重新排队失败，请稍后重试'); })
      .then(function () {
        delete state.progress[id];
        delete state.hydrated[id];
        toast('已重新排队');
        return loadList(true).then(function () { if (String(state.selectedId) === String(id)) return loadDetail(id); });
      })
      .catch(function (e) {
        if (e && (e.message === 'unauthorized' || e.message === 'forbidden')) return;
        toast((e && e.message) ? e.message : '重新排队失败', 'err');
      });
  }

  function doIterate(id, feedback) {
    apiReq('/theme-packages/' + id + '/iterate', { method: 'POST', body: JSON.stringify({ feedback: feedback }) })
      .then(function (r) { return r.ok ? r.json() : errMessage(r, '提交迭代失败，请稍后重试'); })
      .then(function () {
        closeOverlay();
        delete state.progress[id];
        toast('迭代已提交，系统正在生成下一版');
        return loadList(true).then(function () { if (String(state.selectedId) === String(id)) return loadDetail(id); });
      })
      .catch(function (e) {
        if (e && (e.message === 'unauthorized' || e.message === 'forbidden')) return;
        toast((e && e.message) ? e.message : '提交迭代失败', 'err');
      });
  }

  function doDelete(id) {
    apiReq('/theme-packages/' + id, { method: 'DELETE' })
      .then(function (r) { return r.ok ? r.json().catch(function () { return {}; }) : errMessage(r, '删除失败，请稍后重试'); })
      .then(function () {
        closeOverlay();
        delete state.progress[id];
        delete state.advice[id];
        delete state.hydrated[id];
        if (String(state.selectedId) === String(id)) { state.selectedId = null; state.detail = null; }
        toast('已删除');
        return loadList(true).then(render);
      })
      .catch(function (e) {
        if (e && (e.message === 'unauthorized' || e.message === 'forbidden')) return;
        toast((e && e.message) ? e.message : '删除失败', 'err');
      });
  }

  function copyThemeId(themeId) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(themeId)
        .then(function () { toast('已复制主题标识'); })
        .catch(function () { toast('复制失败，请手动选中复制', 'err'); });
    } else {
      toast('当前浏览器不支持一键复制，请手动选中复制', 'err');
    }
  }

  function bindEvents() {
    // 左栏选行
    var rail = $('#tpRailList');
    if (rail) {
      rail.addEventListener('click', function (e) {
        var row = e.target.closest('.tp-row');
        if (!row) return;
        selectPkg(row.getAttribute('data-id'));
      });
    }

    var newBtn = $('#tpNewBtn');
    if (newBtn) {
      newBtn.addEventListener('click', function () {
        state.mode = 'new';
        state.menuOpen = false;
        state.formErr = '';
        renderMain();
      });
    }

    var search = $('#tpSearch');
    if (search) {
      search.addEventListener('input', function () { state.q = search.value || ''; renderRail(); });
    }

    // 右栏（详情与新建都在这一个容器里，用事件委托）
    var main = $('#tpMain');
    if (main) {
      main.addEventListener('click', function (e) {
        var seg = e.target.closest('[data-prev]');
        if (seg) {
          state.previewPage = seg.getAttribute('data-prev');
          renderMain();
          return;
        }
        if (e.target.closest('#tpKebab')) {
          state.menuOpen = !state.menuOpen;
          renderMain();
          return;
        }
        var btn = e.target.closest('[data-act]');
        if (!btn) {
          if (state.menuOpen) { state.menuOpen = false; renderMain(); }
          return;
        }
        var act = btn.getAttribute('data-act');
        var d = detailData();
        if (act === 'close-new') { state.mode = 'detail'; state.formErr = ''; renderMain(); return; }
        if (!d) return;
        if (act === 'download') { downloadPkg(d.id); return; }
        if (act === 'retry') { state.menuOpen = false; doRetry(d.id); return; }
        if (act === 'copy-theme-id') { state.menuOpen = false; renderMain(); copyThemeId(String(d.theme_id)); return; }
        if (act === 'iterate') { state.overlay = { kind: 'iterate', id: d.id }; openOverlay(iterateSheet(d), '#tpFeedback'); return; }
        if (act === 'delete') {
          state.menuOpen = false; renderMain();
          state.overlay = { kind: 'delete', id: d.id };
          openOverlay(deleteDialog(d), '#tpCancelDelete');
          return;
        }
      });

      // 新建表单：输入即写回 state，重渲染不丢已填内容
      main.addEventListener('input', function (e) {
        var el = e.target;
        if (!el.name || state.mode !== 'new') return;
        if (Object.prototype.hasOwnProperty.call(state.form, el.name)) {
          state.form[el.name] = el.value;
        }
        var slot = el.closest('.tp-slot');
        if (slot) slot.classList.toggle('is-filled', !!el.value.trim());
      });

      main.addEventListener('change', function (e) {
        if (state.mode !== 'new' || e.target.name !== 'style_preference') return;
        state.form.style_preference = e.target.value;
        Array.prototype.forEach.call(document.querySelectorAll('.tp-style'), function (l) {
          var input = l.querySelector('input');
          l.classList.toggle('is-on', !!(input && input.checked));
        });
      });

      main.addEventListener('submit', function (e) {
        if (e.target.id !== 'tpNewForm') return;
        e.preventDefault();
        submitNew();
      });
    }

    // 抽屉 / 对话框
    var overlay = $('#tpOverlay');
    if (overlay) {
      overlay.addEventListener('click', function (e) {
        var stop = e.target.closest('[data-stop]');
        var act = e.target.closest('[data-act]');
        var actName = act ? act.getAttribute('data-act') : '';
        if (actName === 'close-overlay') { closeOverlay(); return; }
        if (actName === 'delete-confirm' && state.overlay) { doDelete(state.overlay.id); return; }
        if (actName === 'iterate-submit' && state.overlay) {
          var area = $('#tpFeedback');
          var text = area ? area.value.trim() : '';
          if (!text || text.length > FEEDBACK_MAX) return;
          doIterate(state.overlay.id, text);
          return;
        }
        // 点遮罩空白处关闭；点面板内部不关
        if (!stop && e.target.classList.contains('md-scrim')) closeOverlay();
      });

      overlay.addEventListener('input', function (e) {
        if (e.target.id !== 'tpFeedback') return;
        var len = e.target.value.length;
        var over = len > FEEDBACK_MAX;
        var count = $('#tpFeedbackCount');
        if (count) {
          count.textContent = len + ' / ' + FEEDBACK_MAX;
          count.className = 'md-count' + (over ? ' is-over' : '');
        }
        e.target.classList.toggle('is-over', over);
        var submit = $('#tpIterateSubmit');
        if (submit) submit.disabled = over || !e.target.value.trim();
      });
    }

    /* 页面切到后台就暂停轮询，回到可见立刻补一轮再起。
       不这么做的话，切走十分钟回来会攒下一堆无人看的请求。 */
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) { stopPoll(); return; }
      pollTick();
      startPoll();
    });

    /* 点到别处就收起更多菜单。只挂在 #tpMain 上是不够的 ——
       点左栏或顶栏时菜单会一直开着。 */
    document.addEventListener('click', function (e) {
      if (!state.menuOpen) return;
      if (e.target.closest('.md-menuhost')) return;
      state.menuOpen = false;
      renderMain();
    });

    window.addEventListener('beforeunload', stopPoll);
  }

  function boot() {
    bindEvents();
    renderMain();
    loadList();
    hideGuard();
  }

  function bootstrap() {
    showGuard('connecting');
    fetch('/api/v1/auth/session-login', {
      method: 'POST', credentials: 'include', headers: { 'Accept': 'application/json' }
    }).then(function (response) {
      if (response.status === 401 || response.status === 419) {
        window.location.href = accountLoginUrl();
        return Promise.reject('redirect');
      }
      if (!response.ok) throw new Error('HTTP ' + response.status);
      return response.json();
    }).then(function (payload) {
      persistSession(payload);
      window.GEO_PAGE_NAV && window.GEO_PAGE_NAV.activate(payload);
      boot();
    }).catch(function (error) {
      if (error !== 'redirect') showGuard('error');
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrap);
  } else {
    bootstrap();
  }
})();
