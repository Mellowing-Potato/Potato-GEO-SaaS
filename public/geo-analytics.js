/* ============================================================================
   GEO AI 爬虫可见性面板 · 交互引擎（geo-analytics.js）
   同构 platform-ops（方案 B：壳页先渲染 + 前端 session-login 拿 Sanctum token）。
   红线遵守：
     - 仅消费真实端点；任何读接口失败均渲染显式错误态（含重试），绝不回退假数据。
     - 空态要真：某视图无数据即显示「暂无」，不塞假数。
     - 不引 UI 框架 / 打包器 / 图表库；图表用手绘 SVG + CSS。
     - 无新色值字面量（仅用 geo-analytics.css 的 CSS 变量与等价 hex 令牌）。
   ============================================================================ */
(function () {
  'use strict';

  var $  = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /* ---------------- 认证与配置（方案 B） ---------------- */
  var CFG = window.GEO_ANALYTICS_CONFIG || {
    accountLoginUrl: '/login',
    accountUrl: '/#personal-settings'
  };

  function token() { return sessionStorage.getItem('geo_analytics_token') || ''; }
  function pickToken(p) { return (p && (p.accessToken || p.access_token || p.token)) || ''; }
  function persistSession(p) {
    var t = pickToken(p);
    if (!t) return;
    sessionStorage.setItem('geo_analytics_token', t);
    var u = (p && p.user) || null;
    if (u && !u.tenant && p && p.tenant) { u.tenant = p.tenant; }
    sessionStorage.setItem('geo_analytics_user', JSON.stringify(u));
  }
  function tenantSubdomain() {
    try {
      var u = JSON.parse(sessionStorage.getItem('geo_analytics_user') || '{}');
      return u && u.tenant && u.tenant.subdomain ? String(u.tenant.subdomain) : '';
    } catch (e) { return ''; }
  }
  function who() {
    try {
      var u = JSON.parse(sessionStorage.getItem('geo_analytics_user') || '{}') || {};
      var workspace = u.tenant && u.tenant.subdomain ? ' · 工作区 ' + u.tenant.subdomain : '';
      return (u.name || '已登录用户') + workspace;
    }
    catch (e) { return '已登录用户'; }
  }
  function accountLoginUrl() {
    var base = CFG.accountLoginUrl || '/login';
    try {
      var t = new URL(base, window.location.href);
      t.searchParams.set('redirect_to', window.location.href);
      return t.toString();
    } catch (e) { return base; }
  }

  /* 守卫态遮罩 */
  function showGuard(kind) {
    var el = $('#guardState');
    if (!el) return;
    el.hidden = false;
    var glyph = $('#guardGlyph');
    if (glyph) glyph.hidden = (kind !== 'forbidden' && kind !== 'error');
    var spinner = $('#guardIcon');
    if (spinner) spinner.hidden = (kind === 'forbidden' || kind === 'error');
    var title = $('#guardTitle');
    if (title) title.textContent = (kind === 'forbidden') ? '无访问权限' : (kind === 'error' ? '加载失败' : '连接中…');
    var text = $('#guardText');
    if (text) text.textContent = (kind === 'forbidden')
      ? '当前账号没有可见性面板权限（analytics:view）。如有疑问请联系系统管理员。'
      : (kind === 'error' ? '身份校验失败，请重新登录后重试。' : '正在校验可见性面板访问权限');
    var link = $('#guardLink');
    if (link) {
      if (kind === 'forbidden' || kind === 'error') { link.hidden = false; link.href = CFG.accountUrl; }
      else { link.hidden = true; }
    }
  }
  function hideGuard() { var el = $('#guardState'); if (el) el.hidden = true; }

  function handleUnauthorized() {
    sessionStorage.removeItem('geo_analytics_token');
    sessionStorage.removeItem('geo_analytics_user');
    fetch('/api/v1/auth/session-login', { method: 'POST', credentials: 'include', headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('relogin failed'); return r.json(); })
      .then(function (p) { persistSession(p); window.location.reload(); })
      .catch(function () { window.location.href = accountLoginUrl(); });
  }

  /* ---------------- 数据层：真实端点 + Bearer 鉴权（方案 B） ---------------- */
  function apiGet(path) {
    var headers = { 'Accept': 'application/json', 'Authorization': 'Bearer ' + token() };
    var sub = tenantSubdomain();
    if (sub) headers['X-Tenant'] = sub;
    return fetch(path, {
      credentials: 'same-origin',
      headers: headers
    }).then(function (r) {
      if (r.status === 401) { handleUnauthorized(); throw new Error('HTTP 401'); }
      if (r.status === 403) { showGuard('forbidden'); throw new Error('HTTP 403'); }
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    });
  }

  /* ---------------- 工具函数 ---------------- */
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function fmtInt(n) { return (Number(n) || 0).toLocaleString('zh-CN'); }
  function fmtWhen(iso) {
    if (!iso) return '—';
    var d = new Date(iso);
    if (isNaN(d.getTime())) return esc(iso);
    var now = new Date();
    var hm = ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
    var md = ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
    var sameDay = d.toDateString() === now.toDateString();
    var yest = new Date(now); yest.setDate(now.getDate() - 1);
    if (sameDay) return '今天 ' + hm;
    if (d.toDateString() === yest.toDateString()) return '昨天 ' + hm;
    return d.getFullYear() === now.getFullYear() ? md + ' ' + hm : d.getFullYear() + '-' + md + ' ' + hm;
  }
  function trafficClass(t) {
    return ({ human: 'human', ai_bot: 'ai', search_bot: 'search', other_bot: 'other', unknown: 'unknown' })[t] || 'unknown';
  }
  function trafficLabel(t) {
    return ({ human: '真人', ai_bot: 'AI 爬虫', search_bot: '搜索爬虫', other_bot: '其他爬虫', unknown: '未知' })[t] || esc(t);
  }

  /* 状态模板 */
  function loading(host) { host.innerHTML = '<div class="skeleton" role="status" aria-label="加载中"><div class="sk w80"></div><div class="sk w60"></div><div class="sk w40"></div></div>'; }
  function tableLoading(host, cols) {
    host.innerHTML = '<tr><td colspan="' + (Number(cols) || 1) + '" class="state-td"><div class="skeleton" role="status" aria-label="加载中"><div class="sk w80"></div><div class="sk w60"></div><div class="sk w40"></div></div></td></tr>';
  }
  function emptyState(host, title, reason) {
    host.innerHTML = '<div class="state-tpl"><div class="st-icon">○</div><h3>' + esc(title || '这里还没有内容') + '</h3><p>' + esc(reason || '暂无数据。') + '</p></div>';
  }
  function errorState(host, retryFn, msg) {
    msg = msg || '数据加载失败';
    host.innerHTML = '<div class="state-tpl"><div class="st-icon">!</div><p>' + esc(msg) + '</p><button class="btn" data-retry="1">重试</button></div>';
    var b = host.querySelector('[data-retry]');
    if (b && retryFn) b.addEventListener('click', retryFn);
  }

  /* ---------------- 各视图渲染（Tremor 风格零依赖 SVG） ---------------- */
  var COLORS = { human: '#8C5811', search_bot: '#484E4A', ai_bot: '#2D5F4C', other_bot: '#5E6560', unknown: '#B8BDB9' };

  function fmtCompact(n) {
    var v = Number(n) || 0;
    if (v >= 10000) return (v / 10000).toFixed(v % 10000 === 0 ? 0 : 1) + 'w';
    if (v >= 1000) return (v / 1000).toFixed(v % 1000 === 0 ? 0 : 1) + 'k';
    return String(Math.round(v));
  }
  function fmtPct(part, total) {
    if (!total) return '0%';
    var p = (Number(part) || 0) / total * 100;
    return (p >= 10 || p === 0 ? p.toFixed(0) : p.toFixed(1)) + '%';
  }
  function niceUpper(maxVal) {
    var m = Number(maxVal) || 0;
    if (m <= 4) return 4;
    var pow = Math.pow(10, Math.floor(Math.log10(m)));
    var norm = m / pow;
    var step = norm <= 1.2 ? 1.2 : norm <= 1.6 ? 1.6 : norm <= 2 ? 2 : norm <= 3 ? 3 : norm <= 4 ? 4 : norm <= 5 ? 5 : norm <= 8 ? 8 : 10;
    return Math.ceil((step * pow) / 4) * 4;
  }
  function smoothSvgPath(points, minY, maxY) {
    if (!points.length) return '';
    if (points.length === 1) return 'M ' + points[0].x.toFixed(1) + ' ' + points[0].y.toFixed(1);
    var d = 'M ' + points[0].x.toFixed(1) + ' ' + points[0].y.toFixed(1);
    for (var i = 0; i < points.length - 1; i++) {
      var p0 = points[i > 0 ? i - 1 : i];
      var p1 = points[i];
      var p2 = points[i + 1];
      var p3 = points[i + 2 < points.length ? i + 2 : i + 1];
      var cp1x = p1.x + (p2.x - p0.x) / 6;
      var cp1y = Math.max(minY, Math.min(maxY, p1.y + (p2.y - p0.y) / 6));
      var cp2x = p2.x - (p3.x - p1.x) / 6;
      var cp2y = Math.max(minY, Math.min(maxY, p2.y - (p3.y - p1.y) / 6));
      d += ' C ' + cp1x.toFixed(1) + ' ' + cp1y.toFixed(1) + ', ' + cp2x.toFixed(1) + ' ' + cp2y.toFixed(1) + ', ' + p2.x.toFixed(1) + ' ' + p2.y.toFixed(1);
    }
    return d;
  }

  function renderOverview(d) {
    var k = (d && d.kpis) || {};
    var pvTotal = Number(k.pv) || 0;
    var ipTotal = Number(k.unique_ip) || 0;
    $$('[data-kpi]').forEach(function (el) { el.textContent = fmtInt(k[el.getAttribute('data-kpi')] || 0); });
    var metas = {
      pv: pvTotal > 0 ? '周期累计' : '暂无记录',
      ai_bot_pv: pvTotal > 0 ? '占比 ' + fmtPct(k.ai_bot_pv, pvTotal) : '—',
      human_pv: pvTotal > 0 ? '占比 ' + fmtPct(k.human_pv, pvTotal) : '—',
      search_bot_pv: pvTotal > 0 ? '占比 ' + fmtPct(k.search_bot_pv, pvTotal) : '—',
      unique_ip: ipTotal > 0 ? '人均 ' + (pvTotal / ipTotal).toFixed(1) + ' PV' : '—',
    };
    $$('[data-kpi-meta]').forEach(function (el) {
      var key = el.getAttribute('data-kpi-meta');
      if (Object.prototype.hasOwnProperty.call(metas, key)) el.textContent = metas[key];
    });
    var ovHint = $('#ovHint');
    if (ovHint && d && d.period) ovHint.textContent = d.period.from + ' ~ ' + d.period.to;
    var gaPeriod = $('#gaPeriod');
    if (gaPeriod && d && d.period) gaPeriod.textContent = d.period.from + ' ~ ' + d.period.to;

    var rows = (d && d.traffic_breakdown) || [];
    var total = rows.reduce(function (s, r) { return s + (Number(r.count) || 0); }, 0);
    var donut = $('#donutWrap');
    if (!donut) return;
    if (total === 0) { emptyState(donut, '暂无流量数据', '近周期内没有访问记录。'); return; }

    // Tremor 风格：薄环 DonutChart + CategoryBar 比例条 + 右对齐图例表
    var radius = 54;
    var C = 2 * Math.PI * radius;
    var activeRows = rows.filter(function (r) { return (Number(r.count) || 0) > 0; });
    var gap = activeRows.length > 1 ? 2.5 : 0;
    var off = 0;
    var segs = '';
    var catX = 0;
    var catRects = '';
    activeRows.forEach(function (r) {
      var share = (Number(r.count) || 0) / total;
      var len = Math.max(0, share * C - gap);
      var cls = trafficClass(r.key);
      segs += '<circle cx="68" cy="68" r="' + radius + '" fill="none" stroke="' + (COLORS[r.key] || '#B8BDB9') +
        '" stroke-width="14" stroke-dasharray="' + len.toFixed(2) + ' ' + (C - len).toFixed(2) +
        '" stroke-dashoffset="' + (-off).toFixed(2) + '" transform="rotate(-90 68 68)"></circle>';
      off += share * C;

      var w = share * 100;
      catRects += '<rect x="' + catX.toFixed(2) + '" y="0" width="' + Math.max(0.6, w).toFixed(2) + '" height="8" class="cat-seg ' + cls + '"></rect>';
      catX += w;
    });
    var legend = rows.map(function (r) {
      var cnt = Number(r.count) || 0;
      var cls = trafficClass(r.key);
      return '<div class="lg">' +
        '<span class="lg-name"><span class="dot ' + cls + '"></span>' + trafficLabel(r.key) + '</span>' +
        '<span class="lg-val"><strong>' + fmtInt(cnt) + '</strong><span class="lg-pct">' + fmtPct(cnt, total) + '</span></span>' +
      '</div>';
    }).join('');
    donut.innerHTML =
      '<div class="tremor-donut-top">' +
        '<svg class="tremor-donut-svg" width="136" height="136" viewBox="0 0 136 136" aria-label="流量构成">' +
          '<circle cx="68" cy="68" r="' + radius + '" fill="none" stroke="#EAECEB" stroke-width="14"></circle>' + segs +
          '<text x="68" y="64" text-anchor="middle" class="donut-total">' + fmtInt(total) + '</text>' +
          '<text x="68" y="82" text-anchor="middle" class="donut-cap">总 PV</text>' +
        '</svg>' +
        '<div class="tremor-catbar-box">' +
          '<div class="tremor-catbar-meta"><span>流量占比分布</span><strong>' + fmtInt(activeRows.length) + ' 类来源</strong></div>' +
          '<svg class="tremor-catbar-svg" viewBox="0 0 100 8" preserveAspectRatio="none" aria-hidden="true">' + catRects + '</svg>' +
          '<div class="donut-legend">' + legend + '</div>' +
        '</div>' +
      '</div>';
  }

  function renderTopBots(d) {
    var host = $('#topBotsHost');
    if (!host) return;
    var list = (d && d.top_ai_bots) || [];
    if (!list.length) { emptyState(host, '暂无 AI 爬虫命中', '近周期内没有 AI 爬虫访问记录。'); return; }
    var max = list.reduce(function (m, r) { return Math.max(m, Number(r.hits) || 0); }, 0) || 1;
    var totalHits = list.reduce(function (s, r) { return s + (Number(r.hits) || 0); }, 0) || 1;
    host.innerHTML =
      '<div class="tremor-barlist-head"><span>爬虫来源</span><span>命中 · 覆盖文章</span></div>' +
      '<div class="barlist">' + list.map(function (r) {
        var hits = Number(r.hits) || 0;
        var pct = Math.max(3, hits / max * 100);
        return '<div class="bar-row">' +
          '<div class="bar-label-wrap">' +
            '<svg class="tremor-bar-svg" viewBox="0 0 100 32" preserveAspectRatio="none" aria-hidden="true">' +
              '<rect x="0" y="0" width="' + pct.toFixed(1) + '" height="32" rx="4" ry="4"></rect>' +
            '</svg>' +
            '<span class="bn" title="' + esc(r.bot_name) + '">' + esc(r.bot_name) + '</span>' +
          '</div>' +
          '<div class="bv"><strong>' + fmtInt(hits) + '</strong><small>' + fmtInt(r.articles_reached) + ' 篇 · ' + fmtPct(hits, totalHits) + '</small></div>' +
        '</div>';
      }).join('') + '</div>';
  }

  function renderTrend(d) {
    var host = $('#trendHost');
    if (!host) return;
    var arr = (d && d.trend) || [];
    if (!arr.length) { emptyState(host, '暂无趋势数据', '近周期内没有访问记录。'); return; }

    var rawMax = arr.reduce(function (m, r) { return Math.max(m, Number(r.pv) || 0, Number(r.ai_bot_pv) || 0); }, 0);
    var yMax = niceUpper(rawMax);
    var padL = 46, padR = 16, padT = 16, padB = 30;
    var W = 640, H = 240;
    var plotW = W - padL - padR;
    var plotH = H - padT - padB;
    var baseY = padT + plotH;
    var n = arr.length;

    function xAt(i) { return n > 1 ? padL + (i / (n - 1)) * plotW : padL + plotW / 2; }
    function yAt(val) { return baseY - (Number(val) || 0) / yMax * plotH; }

    var pvPts = arr.map(function (r, i) { return { x: xAt(i), y: yAt(r.pv) }; });
    var aiPts = arr.map(function (r, i) { return { x: xAt(i), y: yAt(r.ai_bot_pv) }; });
    var pvLine = smoothSvgPath(pvPts, padT, baseY);
    var aiLine = smoothSvgPath(aiPts, padT, baseY);
    var pvArea = pvLine + ' L ' + pvPts[n - 1].x.toFixed(1) + ' ' + baseY.toFixed(1) + ' L ' + pvPts[0].x.toFixed(1) + ' ' + baseY.toFixed(1) + ' Z';
    var aiArea = aiLine + ' L ' + aiPts[n - 1].x.toFixed(1) + ' ' + baseY.toFixed(1) + ' L ' + aiPts[0].x.toFixed(1) + ' ' + baseY.toFixed(1) + ' Z';

    // Y 轴 4 档虚线网格与刻度
    var grid = '';
    for (var g = 0; g <= 4; g++) {
      var tickVal = yMax * (g / 4);
      var gy = yAt(tickVal);
      grid += '<line x1="' + padL + '" y1="' + gy.toFixed(1) + '" x2="' + (W - padR) + '" y2="' + gy.toFixed(1) +
        '" class="' + (g === 0 ? 'ga-axis-base' : 'ga-grid-line') + '"></line>' +
        '<text x="' + (padL - 8) + '" y="' + (gy + 4).toFixed(1) + '" text-anchor="end" class="ga-axis-label">' + fmtCompact(tickVal) + '</text>';
    }

    // X 轴均匀日期标签（最多 7 个防重叠）
    var step = Math.max(1, Math.ceil((n - 1) / 6));
    var xLabels = '';
    for (var xi = 0; xi < n; xi++) {
      if (xi === 0 || xi === n - 1 || xi % step === 0) {
        if (xi !== n - 1 && (n - 1 - xi) < step * 0.6) continue;
        var label = String(arr[xi].date || '').slice(5) || String(arr[xi].date || '');
        var anchor = xi === 0 ? 'start' : (xi === n - 1 ? 'end' : 'middle');
        xLabels += '<text x="' + xAt(xi).toFixed(1) + '" y="' + (H - 8) + '" text-anchor="' + anchor + '" class="ga-axis-label">' + esc(label) + '</text>';
      }
    }

    // 交互命中区与焦点标记
    var colW = n > 1 ? plotW / (n - 1) : plotW;
    var markers = arr.map(function (r, i) {
      var cx = xAt(i);
      var hitX = Math.max(padL, cx - colW / 2);
      var hitW = Math.min(W - padR, cx + colW / 2) - hitX;
      var showStaticDot = n <= 14;
      return '<g class="ga-pt-group" data-idx="' + i + '" tabindex="0" role="button" aria-label="' + esc((r.date || '') + ' 总 PV ' + fmtInt(r.pv) + '，AI 爬虫 ' + fmtInt(r.ai_bot_pv)) + '">' +
        '<line x1="' + cx.toFixed(1) + '" y1="' + padT + '" x2="' + cx.toFixed(1) + '" y2="' + baseY + '" class="ga-hover-guide"></line>' +
        '<circle cx="' + cx.toFixed(1) + '" cy="' + pvPts[i].y.toFixed(1) + '" r="' + (showStaticDot ? '3' : '0') + '" class="ga-dot-total"></circle>' +
        '<circle cx="' + cx.toFixed(1) + '" cy="' + aiPts[i].y.toFixed(1) + '" r="' + (showStaticDot ? '3' : '0') + '" class="ga-dot-ai"></circle>' +
        '<rect x="' + hitX.toFixed(1) + '" y="' + padT + '" width="' + Math.max(8, hitW).toFixed(1) + '" height="' + plotH + '" class="ga-hit-rect"></rect>' +
      '</g>';
    }).join('');

    var peak = arr.reduce(function (best, r) { return (Number(r.pv) || 0) > (Number(best.pv) || 0) ? r : best; }, arr[0]);
    function readoutHtml(row, prefix) {
      var pv = Number(row.pv) || 0;
      var ai = Number(row.ai_bot_pv) || 0;
      return '<span class="ga-readout-date">' + esc((prefix ? prefix + ' · ' : '') + (row.date || '')) + '</span>' +
        '<span>总 PV <strong>' + fmtInt(pv) + '</strong></span>' +
        '<span>AI 爬虫 <strong class="ai">' + fmtInt(ai) + '</strong> <small>(' + fmtPct(ai, pv) + ')</small></span>';
    }

    host.innerHTML =
      '<div class="ga-trend-readout" id="trendReadout">' + readoutHtml(peak, '峰值日') + '</div>' +
      '<svg class="trend-svg" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="每日 PV 与 AI 爬虫 PV 面积趋势图">' +
        '<defs>' +
          '<linearGradient id="gaAreaTotal" x1="0" y1="0" x2="0" y2="1">' +
            '<stop offset="0%" stop-color="#484E4A" stop-opacity="0.14"></stop>' +
            '<stop offset="100%" stop-color="#484E4A" stop-opacity="0.01"></stop>' +
          '</linearGradient>' +
          '<linearGradient id="gaAreaAi" x1="0" y1="0" x2="0" y2="1">' +
            '<stop offset="0%" stop-color="#2D5F4C" stop-opacity="0.22"></stop>' +
            '<stop offset="100%" stop-color="#2D5F4C" stop-opacity="0.02"></stop>' +
          '</linearGradient>' +
        '</defs>' +
        grid +
        '<path d="' + pvArea + '" fill="url(#gaAreaTotal)"></path>' +
        '<path d="' + aiArea + '" fill="url(#gaAreaAi)"></path>' +
        '<path d="' + pvLine + '" fill="none" stroke="#484E4A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>' +
        '<path d="' + aiLine + '" fill="none" stroke="#2D5F4C" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"></path>' +
        xLabels +
        markers +
      '</svg>';

    var readout = host.querySelector('#trendReadout');
    var groups = $$( '.ga-pt-group', host);
    function activate(idx) {
      groups.forEach(function (g, i) { g.classList.toggle('is-active', i === idx); });
      if (readout && arr[idx]) readout.innerHTML = readoutHtml(arr[idx], '');
    }
    groups.forEach(function (g) {
      var idx = Number(g.getAttribute('data-idx'));
      g.addEventListener('mouseenter', function () { activate(idx); });
      g.addEventListener('focus', function () { activate(idx); });
    });
    var svgEl = host.querySelector('.trend-svg');
    if (svgEl) {
      svgEl.addEventListener('mouseleave', function () {
        groups.forEach(function (g) { g.classList.remove('is-active'); });
        if (readout) readout.innerHTML = readoutHtml(peak, '峰值日');
      });
    }
  }

  function renderArticles(d) {
    var body = $('#articlesBody');
    if (!body) return;
    var list = (d && d.data) || [];
    if (!list.length) { body.innerHTML = '<tr><td colspan="3" class="state-tpl"><div class="st-icon">○</div><p>暂无文章访问数据</p></td></tr>'; return; }
    body.innerHTML = list.map(function (r) {
      return '<tr><td class="ga-article-title">' + esc(r.title || ('文章 #' + r.article_id)) + '</td>' +
        '<td class="num"><strong>' + fmtInt(r.views) + '</strong></td><td class="num">' + fmtInt(r.unique_ip) + '</td></tr>';
    }).join('');
  }

  function renderBeacon(d) {
    var body = $('#beaconBody');
    var hint = $('#beaconHint');
    if (!body) return;
    var list = (d && d.data) || [];
    if (hint && d && d.beacon_threshold_days != null) hint.textContent = '近 ' + d.beacon_threshold_days + ' 天内有回传即判在线';
    if (!list.length) { body.innerHTML = '<tr><td colspan="4" class="state-tpl"><div class="st-icon">○</div><p>暂无外站信标回传</p><p>尚未铺设带信标回传的分发渠道，或回传数据未进入本租户。</p></td></tr>'; return; }
    body.innerHTML = list.map(function (r) {
      var st = r.beaconing
        ? '<span class="pill ok">在线</span>'
        : '<span class="pill warn">离线</span>';
      return '<tr><td>' + esc(r.channel_name || ('渠道 #' + r.channel_id)) + '</td>' +
        '<td>' + fmtWhen(r.last_beacon_at) + '</td><td>' + st + '</td><td class="num">' + fmtInt(r.hits_7d) + '</td></tr>';
    }).join('');
  }

  /* 原始日志：分页 + 过滤 */
  var logState = { page: 1, traffic: '', path: '' };
  function logQuery() {
    var p = [];
    p.push('per_page=25');
    p.push('page=' + logState.page);
    if (logState.traffic) p.push('traffic_type=' + encodeURIComponent(logState.traffic));
    if (logState.path) p.push('path=' + encodeURIComponent(logState.path));
    return '/api/v1/analytics/visit-logs?' + p.join('&');
  }
  function renderLogs(d) {
    var body = $('#logsBody');
    var pager = $('#logsPager');
    if (!body) return;
    var list = (d && d.data) || [];
    var meta = (d && d.meta) || {};
    if (!list.length) { body.innerHTML = '<tr><td colspan="6" class="state-tpl"><div class="st-icon">○</div><p>暂无匹配日志</p></td></tr>'; if (pager) pager.innerHTML = ''; return; }
    body.innerHTML = list.map(function (r) {
      return '<tr><td>' + esc(fmtWhen(r.created_at)) + '</td>' +
        '<td><span class="pill ' + trafficClass(r.traffic_type) + '">' + trafficLabel(r.traffic_type) + '</span></td>' +
        '<td>' + esc(r.bot_name || '—') + '</td>' +
        '<td>' + esc(r.path || '—') + '</td>' +
        '<td>' + esc(r.ip_address || '—') + '</td>' +
        '<td class="num">' + (r.status_code != null ? esc(r.status_code) : '—') + '</td></tr>';
    }).join('');
    if (pager) {
      var cur = Number(meta.current_page) || 1, last = Number(meta.last_page) || 1, total = Number(meta.total) || 0;
      pager.innerHTML = '<span>共 ' + fmtInt(total) + ' 条 · 第 ' + cur + '/' + last + ' 页</span>' +
        '<button class="btn ghost" id="logPrev" ' + (cur <= 1 ? 'disabled' : '') + '>上一页</button>' +
        '<button class="btn ghost" id="logNext" ' + (cur >= last ? 'disabled' : '') + '>下一页</button>';
      var prev = $('#logPrev'), next = $('#logNext');
      if (prev) prev.addEventListener('click', function () { if (logState.page > 1) { logState.page--; loadLogs(); } });
      if (next) next.addEventListener('click', function () { if (logState.page < last) { logState.page++; loadLogs(); } });
    }
  }
  function loadLogs() {
    var host = $('#logsBody');
    if (host) tableLoading(host, 6);
    apiGet(logQuery()).then(function (d) { renderLogs(d); }).catch(function (e) {
      if (host) errorState(host, loadLogs, '日志加载失败：' + e.message);
    });
  }

  /* ---------------- 加载全部 ---------------- */
  function loadAll() {
    var days = $('#daysSel') ? $('#daysSel').value : '30';
    var pv = '/api/v1/analytics/crawler-breakdown?days=' + days;
    var tv = '/api/v1/analytics/traffic-trend?days=' + days;
    var ta = '/api/v1/analytics/top-articles?days=' + days + '&limit=10';
    var bc = '/api/v1/analytics/channel-beacon-health?days=' + days;

    [pv, tv, ta, bc].forEach(function (url, idx) {
      var host;
      if (idx === 0) host = $('#donutWrap'); // overview: only skeleton the donut, keep KPI cells
      if (idx === 1) host = $('#trendHost');
      if (idx === 2) host = $('#articlesBody');
      if (idx === 3) host = $('#beaconBody');
      if (!host) return;
      if (idx === 0) { loading(host); }
      else if (idx === 2) { tableLoading(host, 3); }
      else if (idx === 3) { tableLoading(host, 4); }
      else { loading(host); }
    });
    if ($('#topBotsHost')) loading($('#topBotsHost')); // Top AI 爬虫柱与总览共用 crawler-breakdown 响应

    apiGet(pv).then(function (d) { renderOverview(d); renderTopBots(d); }).catch(function (e) {
      errorState($('#donutWrap'), loadAll, '总览加载失败：' + e.message);
      errorState($('#topBotsHost'), loadAll, 'Top 爬虫加载失败：' + e.message);
    });
    apiGet(tv).then(function (d) { renderTrend(d); }).catch(function (e) { errorState($('#trendHost'), loadAll, '趋势加载失败：' + e.message); });
    apiGet(ta).then(function (d) { renderArticles(d); }).catch(function (e) { errorState($('#articlesBody'), loadAll, '文章加载失败：' + e.message); });
    apiGet(bc).then(function (d) { renderBeacon(d); }).catch(function (e) { errorState($('#beaconBody'), loadAll, '信标加载失败：' + e.message); });
    loadLogs();
  }

  /* ---------------- 分区导航高亮（滚动联动） ---------------- */
  function wireSectionNav() {
    var links = $$('.section-nav a');
    links.forEach(function (a) {
      a.addEventListener('click', function () {
        links.forEach(function (x) { x.classList.remove('on'); });
        a.classList.add('on');
      });
    });
    var secs = $$('main section[id^="sec-"]');
    if ('IntersectionObserver' in window) {
      var obs = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          if (en.isIntersecting) {
            var id = en.target.id;
            links.forEach(function (x) { x.classList.toggle('on', x.getAttribute('href') === '#' + id); });
          }
        });
      }, { rootMargin: '-60px 0px -70% 0px' });
      secs.forEach(function (s) { obs.observe(s); });
    }
  }

  /* ---------------- 首屏认证引导 ---------------- */
  function bootstrap() {
    showGuard('connecting');
    fetch('/api/v1/auth/session-login', {
      method: 'POST', credentials: 'include', headers: { 'Accept': 'application/json' }
    }).then(function (r) {
      if (r.status === 401 || r.status === 419) { window.location.href = accountLoginUrl(); return Promise.reject('redirect'); }
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    }).then(function (p) {
      persistSession(p);
      window.GEO_PAGE_NAV && window.GEO_PAGE_NAV.activate(p);
      hideGuard();
      loadAll();
    }).catch(function (e) {
      if (e !== 'redirect') showGuard('error');
    });
  }

  /* ---------------- 事件绑定 ---------------- */
  function bind() {
    var daysSel = $('#daysSel');
    if (daysSel) daysSel.addEventListener('change', loadAll);
    var sb = $('#logSearchBtn');
    if (sb) sb.addEventListener('click', function () {
      logState.traffic = $('#logTraffic') ? $('#logTraffic').value : '';
      logState.path = $('#logPath') ? $('#logPath').value.trim() : '';
      logState.page = 1; loadLogs();
    });
    var rb = $('#logResetBtn');
    if (rb) rb.addEventListener('click', function () {
      logState = { page: 1, traffic: '', path: '' };
      if ($('#logTraffic')) $('#logTraffic').value = '';
      if ($('#logPath')) $('#logPath').value = '';
      loadLogs();
    });
    wireSectionNav();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { bind(); bootstrap(); });
  } else { bind(); bootstrap(); }
})();
