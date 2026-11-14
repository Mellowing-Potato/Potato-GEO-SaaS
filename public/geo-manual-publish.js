/* ============================================================================
   GEO 手动发布面板 · 交互引擎（geo-manual-publish.js）
   同构 geo-visibility（壳页先渲染 + 前端 session-login 拿 Sanctum token）。
   红线遵守（前端接后端-防走偏与纠偏规程）：
     - 仅消费真实端点 /api/v1/manual-publications/*；任何失败均渲染显式错误态，绝不回退假数据。
     - 空态要真：无数据即显示「暂无」，不塞假数。
     - 不引 UI 框架 / 打包器 / 图表库。
   ============================================================================ */
(function () {
  'use strict';

  var $  = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  var TK = 'geo_manual_publish_token';
  var UK = 'geo_manual_publish_user';
  var currentRevision = 1;
  var editingAccountId = null;
  var articleSource = null;

  function projectQuery() {
    var id = new URLSearchParams(window.location.search).get('project_id');
    return /^\d+$/.test(id || '') && Number(id) > 0 ? 'project_id=' + encodeURIComponent(id) : '';
  }

  var CFG = window.GEO_MANUAL_PUBLISH_CONFIG || {
    accountLoginUrl: '/login',
    accountUrl: '/#personal-settings'
  };

  function token() { return sessionStorage.getItem(TK) || ''; }
  function userInfo() { try { return JSON.parse(sessionStorage.getItem(UK) || '{}'); } catch (e) { return {}; } }
  function persistSession(p) {
    var t = p && (p.accessToken || p.access_token || p.token);
    if (!t) return;
    sessionStorage.setItem(TK, t);
    /* session-login 同时返回 user（已 load tenant）与顶层 tenant。两边都收，
       因为 apiReq 必须拿得到 subdomain 才能带 X-Tenant（见下方 tenantSubdomain）。 */
    var u = (p && p.user) || null;
    if (u && !u.tenant && p && p.tenant) { u.tenant = p.tenant; }
    sessionStorage.setItem(UK, JSON.stringify(u));
  }
  /* 生产 TENANT_DOMAIN = 本页所在域名（实例主机名），IdentifyTenant 的
     extractSubdomain 在 host === baseDomain 时只认 X-Tenant / ?tenant=，
     不带就返回 null → abort(404) → 整组 /api/v1/* 全 404，界面表现为
     「加载人设失败 / 加载文章失败」。壳页自持 session，必须自己带这个头。 */
  function tenantSubdomain() {
    var t = (userInfo() || {}).tenant;
    return t && t.subdomain ? String(t.subdomain) : '';
  }
  function who() { return (userInfo().name || '已登录用户') + (tenantSubdomain() ? ' · 工作区 ' + tenantSubdomain() : ''); }
  /* 与 User::isAdmin 保持一致：平台管理员也能管理自己的工作区。
     后端 manual_publish:settings 和租户校验仍是授权边界。 */
  function isAdmin() { return ['admin', 'platform_admin'].indexOf((userInfo() || {}).role) !== -1; }
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
      ? '当前账号没有手动发布权限（manual_publish:manage）。如有疑问请联系系统管理员。'
      : (kind === 'error' ? '身份校验失败，请重新登录后重试。' : '正在校验手动发布面板访问权限');
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

  /* 缺陷 A（派单二 2026-08-31）：403 曾经是个永久结论。
     showGuard('forbidden') 说的是「当前账号没有手动发布权限」——这是一句**关于身份的断言**，
     但面板做这个断言时，用的是 sessionStorage 里一份从没核验过的缓存身份：
     退出登录清的是账户中心的 cookie，清不掉这里的这一份。于是浏览器 cookie 里已经是账号 B
     （有权限）、sessionStorage 里还留着账号 A（没权限）的 token，面板拿 A 的 token 吃 403，
     再告诉用户「当前账号没有权限」——「当前账号」是错的。
     401 那条路一直是对的（handleUnauthorized 会用 cookie 重新换一张 token），403 缺同一步。

     🔴 一次性（两重）：
       ① 核验整页只做一次，且**并发的 403 共用同一次**——boot 会同时发 3~5 个请求
          （列表 / 设置 / 人设 / 文章 / 成员），身份陈旧时它们是**一起** 403 的。
          若各发各的，就是 5 次 session-login；若用「已核验过」的布尔量卡，
          则是第一个去核验、其余四个直接竖墙——正是本缺陷要修的那个墙。
       ② 每个请求最多重试一次（见 send(isRetry)）。没有这两条，
          403 → 核验 → 403 → 核验 会把一次 403 变成对自家后端的循环打击。 */
  var reverifyPromise = null;

  /* 用 cookie 重换一张 token。走现成的 POST /api/v1/auth/session-login，不新造端点；
     不 reload（reload 会让「重试刚才那一次请求」失去意义，也可能把 403 变成刷新循环）。
     session-login 本身也失败 = 没登录（不是没权限）→ 跳登录页，
     **绝不**在这种情况下竖「无访问权限」墙：竖错了墙，用户会以为是权限问题去找管理员，
     而实际上他只需要重新登录。 */
  function reverifyOnce() {
    if (!reverifyPromise) {
      reverifyPromise = fetch('/api/v1/auth/session-login', { method: 'POST', credentials: 'include', headers: { 'Accept': 'application/json' } })
        .then(function (r) { if (!r.ok) throw new Error('relogin failed'); return r.json(); })
        .then(function (p) {
          var t = p && (p.accessToken || p.access_token || p.token);
          if (!t) throw new Error('relogin failed');
          persistSession(p);
        })
        .catch(function () { handleUnauthorized(); throw new Error('HTTP 401'); });
    }
    return reverifyPromise; // 并发的 403 拿同一个 promise，只打一次核验接口
  }

  function apiReq(path, opts) {
    opts = opts || {};
    // 抽成 send()，好让重新核验之后能原样重试一次（同一个 method / headers / body）。
    // isRetry：这一轮已经是「核验后重试」，再 403 就是真的没权限，不许再核验。
    function send(isRetry) {
      return fetch(path, {
        method: opts.method || 'GET',
        credentials: 'same-origin',
        headers: (function () {
          var h = { 'Accept': 'application/json', 'Authorization': 'Bearer ' + token() };
          var sub = tenantSubdomain();
          if (sub) { h['X-Tenant'] = sub; }
          if (opts.body) { h['Content-Type'] = 'application/json'; }
          return h;
        })(),
        body: opts.form || opts.body || undefined
      }).then(function (r) {
        if (r.status === 401) { handleUnauthorized(); throw new Error('HTTP 401'); }
        if (r.status === 403) {
          // soft403：这一项归 admin（如人设 / 账号的写），不代表整个模块无权限——
          // 只报到对应表单上，不要把整页替换成「无访问权限」。
          // 局部权限错是**内容**层面的结论，跟「我手里的 token 属于谁」无关，
          // 行为一个字都不许动，也不许触发重新核验。
          if (opts.soft403) { throw new Error('HTTP 403'); }
          // 缺陷 A：在断言「当前账号没有权限」之前，先用 cookie 重新核验一次身份
          // （并发 403 共用同一次核验），拿新 token 重试一次；重试后仍然 403，才是真的没权限。
          if (isRetry) { showGuard('forbidden'); throw new Error('HTTP 403'); }
          return reverifyOnce().then(function () { return send(true); });
        }
        if (!r.ok) {
          return r.json().catch(function () { return {}; }).then(function (j) {
            throw new Error(j.message || ('HTTP ' + r.status));
          });
        }
        return opts.raw ? r : r.json();
      });
    }
    return send();
  }
  function apiGet(path, soft403) { return apiReq(path, { soft403: !!soft403 }); }
  function apiPost(path, body, soft403) { return apiReq(path, { method: 'POST', body: JSON.stringify(body || {}), soft403: !!soft403 }); }
  function apiPut(path, body, soft403) { return apiReq(path, { method: 'PUT', body: JSON.stringify(body || {}), soft403: !!soft403 }); }
  function apiForm(path, form) { return apiReq(path, { method: 'POST', form: form }); }

  /* ---------------- 工具函数 ---------------- */
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function fmtWhen(iso) {
    if (!iso) return '—';
    var d = new Date(iso);
    if (isNaN(d.getTime())) return esc(iso);
    return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2) + ' ' +
      ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
  }

  var PLATFORMS = [
    ['zhihu', '知乎'], ['xiaohongshu', '小红书'], ['weibo', '微博'], ['wechat', '微信'],
    ['douyin', '抖音'], ['bilibili', 'B站'], ['reddit', 'Reddit'], ['x', 'X'],
    ['linkedin', 'LinkedIn'], ['custom', '自定义']
  ];
  function platformLabel(p) {
    for (var i = 0; i < PLATFORMS.length; i++) { if (PLATFORMS[i][0] === p) return PLATFORMS[i][1]; }
    return esc(p || '');
  }
  function statusPill(st) {
    var map = {
      draft: ['neutral', '草稿'], ready: ['info', '待发'], in_progress: ['warn', '进行中'],
      completed: ['ok', '已完成'], failed: ['bad', '失败'], skipped: ['neutral', '跳过'],
      cancelled: ['neutral', '已取消']
    };
    var v = map[st] || ['unknown', esc(st || '未知')];
    return '<span class="pill ' + v[0] + '">' + v[1] + '</span>';
  }
  function riskPill(r) {
    if (r === 'blocked') return '<span class="pill bad">拦截</span>';
    if (r === 'warning') return '<span class="pill warn">警告</span>';
    return '<span class="pill ok">干净</span>';
  }
  function loading(host) { host.innerHTML = '<div class="skeleton" role="status" aria-label="加载中"><div class="sk w80"></div><div class="sk w60"></div><div class="sk w40"></div></div>'; }

  /* ---------------- 列表 ---------------- */
  function loadList() {
    var body = $('#pubListBody');
    loading(body);
    apiGet('/api/v1/manual-publications' + (projectQuery() ? '?' + projectQuery() : ''))
      .then(function (j) {
        var rows = (j.data && j.data.data) || [];
        var stats = j.stats || {};
        if (stats.total !== undefined) $('#statTotal').textContent = '共 ' + stats.total + ' · 待发 ' + (stats.ready || 0) + ' · 进行中 ' + (stats.in_progress || 0) + ' · 完成 ' + (stats.completed || 0);
        if (!rows.length) { body.innerHTML = '<tr><td colspan="9" class="state-td">暂无发布单</td></tr>'; return; }
        body.innerHTML = rows.map(function (p) {
          return '<tr data-id="' + p.id + '">' +
            '<td>' + p.id + '</td>' +
            '<td>' + (p.type === 'comment' ? '评论' : '文章') + '</td>' +
            '<td>' + platformLabel(p.platform) + '</td>' +
            '<td>' + statusPill(p.status) + '</td>' +
            '<td>' + esc(p.assignee ? (p.assignee.name || p.assignee.id) : '—') + '</td>' +
            '<td>' + riskPill(p.risk_status) + '</td>' +
            '<td>' + (p.duplicate_warning_count > 0 ? '<span class="pill warn">' + p.duplicate_warning_count + '</span>' : '0') + '</td>' +
            '<td>' + fmtWhen(p.created_at) + '</td>' +
            '<td><button class="btn ghost mini" data-detail="' + p.id + '">详情</button></td>' +
            '</tr>';
        }).join('');
        $$('#pubListBody [data-detail]').forEach(function (b) {
          b.addEventListener('click', function () { showDetail(parseInt(b.getAttribute('data-detail'), 10)); });
        });
      })
      .catch(function (e) {
        if (e.message === 'HTTP 401' || e.message === 'HTTP 403') return;
        body.innerHTML = '<tr><td colspan="9" class="state-td">加载失败：' + esc(e.message) + '</td></tr>';
      });
  }

  /* ---------------- 详情 / 流转 ---------------- */
  var detailLoadingId = null;

  function showDetail(id) {
    window.location.hash = '#sec-detail';
    // 同一条详情仍在加载时忽略重复点击，避免并发请求与骨架反复替换造成页面抖动。
    if (detailLoadingId === id) return;
    detailLoadingId = id;
    var host = $('#detailHost');
    var retainedHeight = host.offsetHeight;
    if (retainedHeight > 0) host.style.minHeight = retainedHeight + 'px';
    host.setAttribute('aria-busy', 'true');
    loading(host);
    apiGet('/api/v1/manual-publications/' + id + (projectQuery() ? '?' + projectQuery() : ''))
      .then(function (j) {
        var p = j.data || {};
        var platformDraftSupported = ['wechat', 'xiaohongshu', 'weibo'].indexOf(p.platform) !== -1 && p.type === 'post';
        currentRevision = (typeof p.revision === 'number') ? p.revision : 1;
        var html = '';
        html += '<div class="detail-block"><div class="k">状态</div><div class="v">' + statusPill(p.status) + ' · ' + riskPill(p.risk_status) + '</div></div>';

        /* 评审项 5：#riskBox 只在新建区，详情里的命中明细此前也不展示——这里补真渲染 */
        var rms = (p.risk_result && Array.isArray(p.risk_result.matches)) ? p.risk_result.matches : [];
        // 防御性：平台红线词绝不在作者界面渲染（服务端已按 origin 过滤，这里兜底）。
        rms = rms.filter(function (m) { return m.origin !== 'platform'; });
        if (rms.length) {
          html += '<div class="detail-block"><div class="k">风险命中（' + rms.length + '）</div>';
          html += '<table class="tbl"><thead><tr><th>词</th><th>字段</th><th>次数</th><th>级别</th><th>建议</th></tr></thead><tbody>';
          rms.forEach(function (m) {
            html += '<tr><td>' + esc(m.word) + '</td><td>' + esc(m.field) + '</td><td>' + (m.count || 0) + '</td><td>' + esc(m.severity) + '</td><td>' + esc(m.suggestion || '—') + '</td></tr>';
          });
          html += '</tbody></table></div>';
        }
        html += '<div class="detail-block"><div class="k">平台 / 账号</div><div class="v">' + platformLabel(p.platform) + ' / ' + esc(p.account ? (p.account.account_name || '') : '—') + '</div></div>';
        html += '<div class="detail-block"><div class="k">内容</div><div class="v">' + esc(p.content) + '</div></div>';
        if (!platformDraftSupported && p.status === 'in_progress' && p.risk_status !== 'blocked') {
          var profile = p.account && p.account.profile_url;
          var target = p.platform === 'zhihu' && p.type === 'post' ? 'https://zhuanlan.zhihu.com/write' : profile;
          var validTarget = false;
          try { var parsedTarget = new URL(target); validTarget = ['https:', 'http:'].indexOf(parsedTarget.protocol) !== -1 && !parsedTarget.username && !parsedTarget.password; } catch (_) {}
          html += '<div class="detail-block"><div class="k">去平台发布</div><div class="v"><button class="btn mini" type="button" id="copyPublicationTitle">复制标题</button> <button class="btn mini" type="button" id="copyPublicationBody">复制正文</button> ' +
            (validTarget ? '<a class="btn mini" href="' + esc(target) + '" target="_blank" rel="noopener noreferrer">打开平台</a>' : '请先在账号设置中补充平台主页链接。') +
            '<span id="publicationCopyMessage" class="form-msg" role="status"></span><div class="hint">核对平台实际登录账号，检查内容后发布，再回填公开链接。复制或保存草稿不会标记为发布完成。</div></div></div>';
        }
        if (p.source_snapshot && p.source_snapshot.article_version) html += '<div class="detail-block"><div class="k">文章版本</div><div class="v">v' + esc(p.source_snapshot.article_version) + ' · 修改原文后需重新准备发布单。</div></div>';
        if (platformDraftSupported) {
          html += '<div class="detail-block" id="platformDraftHost"></div>';
        }
        if (p.draft_url) {
          var draftStale = p.draft_content_fingerprint !== p.content_fingerprint;
          html += '<div class="detail-block"><div class="k">知乎草稿</div><div class="v"><a href="' + esc(p.draft_url) + '" target="_blank" rel="noopener">打开草稿编辑页</a> · ' +
            (draftStale ? '发布单内容已变化，需重新同步' : '已确认保存') +
            (p.draft_synced_at ? ' · ' + fmtWhen(p.draft_synced_at) : '') + '</div></div>';
        }
        if (CFG.zhihuDraftSyncEnabled && p.platform === 'zhihu' && p.type === 'post' && p.status === 'in_progress' && p.risk_status !== 'blocked') {
          html += '<div class="detail-block"><div class="k">知乎草稿同步</div><div class="v"><button type="button" class="btn primary mini" id="syncZhihuDraft">同步到知乎草稿</button>' +
            '<span class="form-msg" id="zhihuSyncMsg" role="status"></span>' +
            '<div class="hint">需要在本机 Chrome 安装言及草稿助手 ' + esc(CFG.publishingHelperVersion || '') + '；旧版请更新后刷新此页。只写入草稿，发布由你在知乎完成。</div></div></div>';
        }
        if (p.target_url) html += '<div class="detail-block"><div class="k">目标链接</div><div class="v"><a href="' + esc(p.target_url) + '" target="_blank" rel="noopener">' + esc(p.target_url) + '</a></div></div>';
        if (p.completion_url) html += '<div class="detail-block"><div class="k">发布链接</div><div class="v"><a href="' + esc(p.completion_url) + '" target="_blank" rel="noopener">' + esc(p.completion_url) + '</a></div></div>';

        html += '<div class="detail-block"><div class="k">流转记录</div>';
        var tr = (p.transitions || []);
        if (!tr.length) { html += '<div class="v" style="color:var(--md-text-muted)">暂无流转记录</div>'; }
        else {
          html += '<table class="tbl"><thead><tr><th>从</th><th>到</th><th>执行人</th><th>备注</th><th>时间</th></tr></thead><tbody>';
          tr.forEach(function (t) {
            html += '<tr><td>' + (t.from_status ? statusPill(t.from_status) : '—') + '</td><td>' + statusPill(t.to_status) + '</td><td>' + esc(t.actor ? (t.actor.name || t.actor.id) : '—') + '</td><td>' + esc(t.result_note || '—') + '</td><td>' + fmtWhen(t.created_at) + '</td></tr>';
          });
          html += '</tbody></table>';
        }
        html += '</div>';

        if (j.can_transition) {
          // allowed_next 与 can_transition 都是详情响应的顶层权限/状态机元数据。
          var next = Array.isArray(j.allowed_next) ? j.allowed_next : [];
          var statusLabelOf = { draft: '待发', ready: '待发', in_progress: '进行中', completed: '完成', failed: '失败', skipped: '跳过', cancelled: '取消' };
          var reasonLabelOf = { failed: '失败原因', skipped: '跳过原因', cancelled: '取消原因', ready: '重新打开原因' };
          var terminalStatuses = ['failed', 'skipped', 'cancelled'];
          var isReopen = terminalStatuses.indexOf(p.status) !== -1;
          var reasonTargets = next.filter(function (st) {
            return terminalStatuses.indexOf(st) !== -1 || (st === 'ready' && isReopen);
          });
          html += '<div class="detail-block"><div class="k">流转操作</div><div>';
          next.forEach(function (st) {
            html += '<button class="btn mini" data-trans="' + p.id + '" data-st="' + st + '">→ ' + (statusLabelOf[st] || st) + '</button>';
          });
          html += '</div>';
          /* 评审阻断项 4：转「完成」必须留发布链接当证据（服务层 422 强制），界面出表单收集 */
          if (next.indexOf('completed') !== -1) {
            html += '<div class="run-form" id="completeForm" hidden>' +
              '<label class="fld fld-wide"><span class="fld-label">发布链接（完成必填，http/https）</span>' +
              '<input type="url" id="completeUrl" placeholder="https://…"></label>' +
              '<label class="fld fld-wide"><span class="fld-label">完成备注（可选，写入流转记录）</span>' +
              '<input type="text" id="completeNote" maxlength="2000" placeholder="例如：已按时发出，互动正常"></label>' +
              '<div class="form-actions"><button type="button" class="btn primary mini" data-complete="' + p.id + '">确认完成</button>' +
              '<span class="form-msg" id="completeMsg"></span></div></div>';
          }
          if (reasonTargets.length) {
            html += '<div class="run-form" id="transitionReasonForm" hidden>' +
              '<label class="fld fld-wide"><span class="fld-label" id="transitionReasonLabel">流转原因（必填）</span>' +
              '<textarea id="transitionReason" maxlength="2000" rows="3" placeholder="请说明原因，写入流转记录"></textarea></label>' +
              '<div class="form-actions"><button type="button" class="btn primary mini" id="confirmReasonTransition">确认</button>' +
              '<span class="form-msg" id="transitionReasonMsg"></span></div></div>';
          }
          html += '<span class="form-msg" id="transMsg"></span></div>';
        }

        if (j.can_update) {
          html = '<div><button type="button" class="btn ghost mini" id="editToggle">编辑</button></div>' + html;
        }
        host.innerHTML = html;
        if (window.GEO_WECHAT_DRAFTS && p.platform === 'wechat' && p.type === 'post' && $('#platformDraftHost')) {
          var officialDraftHost = document.createElement('div');
          officialDraftHost.className = 'detail-block';
          $('#platformDraftHost').before(officialDraftHost);
          window.GEO_WECHAT_DRAFTS.mount({host: officialDraftHost, publication: p, canTransition: j.can_transition, apiPost: apiPost, apiGet: apiGet, apiForm: apiForm});
        }
        if (window.GEO_PUBLICATION_DRAFTS) {
          window.GEO_PUBLICATION_DRAFTS.mount({
            host: $('#platformDraftHost'), publication: p, canTransition: j.can_transition, apiPost: apiPost, apiGet: apiGet,
            onChanged: function () {
              detailLoadingId = null;
              showDetail(id);
            }
          });
        }
        var et = $('#editToggle');
        if (et) et.addEventListener('click', function () { showEditForm(p, id); });
        [['#copyPublicationTitle', p.source_snapshot && p.source_snapshot.title], ['#copyPublicationBody', p.content]].forEach(function (item) {
          var copyButton = $(item[0]);
          if (copyButton) copyButton.addEventListener('click', function () {
            navigator.clipboard.writeText(item[1] || '').then(function () {
              var copyMessage = $('#publicationCopyMessage');
              if (copyMessage) copyMessage.textContent = '已复制，请到平台粘贴。';
            }).catch(function () {
              var copyMessage = $('#publicationCopyMessage');
              if (copyMessage) copyMessage.textContent = '复制失败，请从上方内容手动复制。';
            });
          });
        });
        var syncButton = $('#syncZhihuDraft');
        if (syncButton) syncButton.addEventListener('click', function () { syncZhihuDraft(p, id); });
        $$('#detailHost [data-trans]').forEach(function (b) {
          b.addEventListener('click', function () {
            var st = b.getAttribute('data-st');
            var completeForm = $('#completeForm');
            var reasonForm = $('#transitionReasonForm');
            if (st === 'completed' && completeForm) {
              // 点「→ 完成」先出表单收链接，不直接提交（直接提交必 422）
              if (reasonForm) reasonForm.hidden = true;
              completeForm.hidden = false;
              var urlInput = $('#completeUrl'); if (urlInput) urlInput.focus();
              return;
            }
            if (reasonForm && (terminalStatuses.indexOf(st) !== -1 || (st === 'ready' && isReopen))) {
              if (completeForm) completeForm.hidden = true;
              reasonForm.hidden = false;
              reasonForm.setAttribute('data-target-status', st);
              var reasonLabel = $('#transitionReasonLabel');
              if (reasonLabel) reasonLabel.textContent = (reasonLabelOf[st] || '流转原因') + '（必填）';
              var reasonButton = $('#confirmReasonTransition');
              if (reasonButton) reasonButton.textContent = '确认' + (statusLabelOf[st] || st);
              var reasonInput = $('#transitionReason');
              if (reasonInput) { reasonInput.value = ''; reasonInput.focus(); }
              var reasonMsg = $('#transitionReasonMsg');
              if (reasonMsg) { reasonMsg.textContent = ''; reasonMsg.className = 'form-msg'; }
              return;
            }
            doTransition(parseInt(b.getAttribute('data-trans'), 10), st);
          });
        });
        $$('#detailHost [data-complete]').forEach(function (b) {
          b.addEventListener('click', function () {
            doTransition(parseInt(b.getAttribute('data-complete'), 10), 'completed');
          });
        });
        var reasonButton = $('#confirmReasonTransition');
        if (reasonButton) {
          reasonButton.addEventListener('click', function () {
            var reasonForm = $('#transitionReasonForm');
            var targetStatus = reasonForm ? reasonForm.getAttribute('data-target-status') : '';
            var reason = ($('#transitionReason') && $('#transitionReason').value || '').trim();
            var reasonMsg = $('#transitionReasonMsg');
            if (!reason) {
              if (reasonMsg) { reasonMsg.textContent = '请填写原因，作为流转记录。'; reasonMsg.className = 'form-msg err'; }
              return;
            }
            doTransition(id, targetStatus, reason);
          });
        }
      })
      .catch(function (e) {
        if (e.message === 'HTTP 401' || e.message === 'HTTP 403') return;
        host.innerHTML = '<div class="err-box">加载失败：' + esc(e.message) + '</div>';
      })
      .finally(function () {
        detailLoadingId = null;
        host.removeAttribute('aria-busy');
        host.style.minHeight = '';
      });
  }

  async function syncZhihuDraft(p, id) {
    var msg = $('#zhihuSyncMsg');
    var button = $('#syncZhihuDraft');
    if (!msg || !button) return;
    if (!p.account || !/^https:\/\/(?:www\.)?zhihu\.com\/people\/[^/?#]+/i.test(p.account.profile_url || '')) {
      msg.textContent = '请先在账号设置中填写知乎个人主页链接。'; msg.className = 'form-msg err'; return;
    }
    var title = p.source_snapshot && p.source_snapshot.title;
    if (!title || !p.content) {
      msg.textContent = '发布单缺少文章标题或正文。'; msg.className = 'form-msg err'; return;
    }
    if (title.length > 100) {
      msg.textContent = '知乎文章标题最多 100 字，请先缩短关联文章标题。'; msg.className = 'form-msg err'; return;
    }
    var replaceExisting = false;
    if (p.draft_url) {
      if (!window.confirm('将用当前发布单内容覆盖已关联的知乎草稿。确认继续？')) return;
      replaceExisting = true;
    }
    button.disabled = true;
    var prepared;
    try {
      prepared = (await apiPost('/api/v1/manual-publications/' + id + '/draft-payload', { revision: p.revision })).data;
    } catch (error) {
      button.disabled = false;
      msg.textContent = '无法准备草稿：' + error.message; msg.className = 'form-msg err'; return;
    }
    var sourceWorkspace = tenantSubdomain();
    var sourceUser = (userInfo() || {}).id;

    var requestId = window.crypto.randomUUID();
    var acknowledged = false;
    var done = false;
    button.disabled = true;
    msg.textContent = '正在连接浏览器扩展…'; msg.className = 'form-msg';
    var ackTimer = window.setTimeout(function () {
      if (!acknowledged && !done) finish('未检测到言及知乎草稿助手。请先安装并刷新此页面。', true);
    }, 3000);
    var resultTimer = window.setTimeout(function () {
      if (!done) finish('同步超时。请检查助手打开的知乎页，确认当前 Chrome 用户资料已登录、是否需要人工验证，以及草稿是否保存。', true);
    }, 120000);
    function finish(message, error) {
      if (done) return;
      done = true;
      window.clearTimeout(ackTimer);
      window.clearTimeout(resultTimer);
      window.removeEventListener('message', onMessage);
      button.disabled = false;
      msg.textContent = message;
      msg.className = 'form-msg ' + (error ? 'err' : 'ok');
    }
    function onMessage(event) {
      if (event.source !== window || event.origin !== window.location.origin) return;
      var data = event.data || {};
      if (data.channel !== 'yanji-zhihu-draft' || data.requestId !== requestId) return;
      if (sourceWorkspace !== tenantSubdomain() || sourceUser !== (userInfo() || {}).id) {
        finish('当前工作区或登录账号已变化，请核对平台草稿后重新打开发布单。', true); return;
      }
      if (data.type === 'ACK') {
        acknowledged = true;
        msg.textContent = '已打开知乎编辑器，正在核对账号并填入内容…';
        return;
      }
      if (data.type !== 'RESULT') return;
      if (!data.ok) {
        var labels = {
          login_required: '请先在当前 Chrome 用户资料中登录知乎。', account_mismatch: '当前知乎账号与发布单绑定账号不一致。',
          account_not_ready: '尚未读取到稳定的知乎登录账号，请等待编辑器加载完成后重试。',
          editor_not_empty: '知乎编辑器已有内容，已停止以免覆盖。', editor_not_found: '未找到知乎文章编辑器。',
          human_verification_required: '知乎要求人工验证，请在编辑页完成。', insert_failed: '内容未能写入知乎编辑器。',
          content_mismatch: '写入后的标题、正文或引用目标未通过一致性校验，已停止登记草稿。请检查草稿并更新助手后重试。',
          save_not_ready: '知乎草稿尚未显示稳定的已保存状态，已停止覆盖。请等待平台保存完成后重试。',
          editor_closed: '知乎编辑页已关闭，草稿同步已停止。', wrong_draft: '打开的草稿与言及记录不一致。',
          request_expired: '草稿同步请求已超时，请检查原编辑页后再重试。'
        };
        var errorLabel = labels[data.code] || ('同步失败：' + (data.code || '未知错误'));
        if (data.code === 'account_not_ready' && data.identityStage === 'profile_menu_unavailable') {
          errorLabel += ' 账号菜单未能展开或主页链接尚未显示。';
        }
        if (data.code === 'account_mismatch' && /^\/people\/[^/?#\s]+$/.test(data.expectedProfile || '')
          && /^\/people\/[^/?#\s]+$/.test(data.currentProfile || '')) {
          errorLabel += ' 绑定：' + data.expectedProfile + '；实际识别：' + data.currentProfile + '。';
        }
        finish(errorLabel, true);
        return;
      }
      if (!data.saved || !/^https:\/\/zhuanlan\.zhihu\.com\/p\/\d+\/edit$/.test(data.draftUrl || '')) {
        var saveHint = data.saveStage === 'platform_not_saved' ? '知乎尚未显示已保存。'
          : data.saveStage === 'transition_not_observed' ? '未观察到本次改写后的新保存回执。' : '';
        finish('内容已填入知乎编辑器，但尚未确认保存。' + saveHint + '请在知乎检查草稿；言及不会将其标为已同步。', true);
        return;
      }
      msg.textContent = data.code === 'draft_unchanged'
        ? '现有草稿内容一致且平台显示已保存，正在记录核对结果…' : '知乎已保存草稿，正在记录链接…';
      apiPost('/api/v1/manual-publications/' + id + '/zhihu-draft', {
        draft_url: data.draftUrl, revision: p.revision
      }).then(function () {
        finish(data.code === 'draft_unchanged' ? '现有草稿已核对并记录，无需重新写入。请在知乎检查后自行发布。'
          : '草稿已保存并记录。请在知乎检查后自行发布。', false);
        showDetail(id);
      }).catch(function (error) {
        finish('知乎草稿已保存，但言及记录失败：' + error.message + '。草稿：' + data.draftUrl, true);
      });
    }
    window.addEventListener('message', onMessage);
    window.postMessage({
      channel: 'yanji-zhihu-draft', type: 'REQUEST', requestId: requestId,
      payload: Object.assign({}, prepared, { replaceExisting: replaceExisting })
    }, window.location.origin);
  }

  function doTransition(id, status, resultNote) {
    // completeMsg 在完成表单内（按钮旁），transMsg 在流转区底部——完成校验提示要显示在按钮旁
    var msg = status === 'completed'
      ? ($('#completeMsg') || $('#transMsg'))
      : (resultNote ? ($('#transitionReasonMsg') || $('#transMsg')) : $('#transMsg'));
    var payload = { target_status:  status, revision: currentRevision };
    if (resultNote) payload.result_note = resultNote;
    if (status === 'completed') {
      var url = ($('#completeUrl') && $('#completeUrl').value || '').trim();
      var note = ($('#completeNote') && $('#completeNote').value || '').trim();
      if (!/^https?:\/\//i.test(url)) {
        if (msg) { msg.textContent = '完成必须填写合法的 http/https 发布链接（留作发布证据）'; msg.className = 'form-msg err'; }
        return;
      }
      payload.completion_url = url;
      payload.result_note = note || null;
    }
    apiPost('/api/v1/manual-publications/' + id + '/transition', payload)
      .then(function () { if (msg) { msg.textContent = '已更新'; msg.className = 'form-msg ok'; } loadList(); showDetail(id); })
      .catch(function (e) {
        if (e.message === 'HTTP 401' || e.message === 'HTTP 403') return;
        if (msg) { msg.textContent = '失败：' + e.message; msg.className = 'form-msg err'; }
      });
  }

  /* ---------------- 设置（人设 / 账号） ---------------- */
  function loadSettings() {
    apiGet('/api/v1/manual-publications/settings/personas')
      .then(function (j) {
        var rows = (j.data) || [];
        // 评审项 8：列表含停用行（后端已放开 is_active 过滤），并给启停切换按钮
        $('#personaListBody').innerHTML = rows.length ? rows.map(function (p) {
          var act = isAdmin()
            ? '<button class="btn ghost mini" data-toggle-persona="' + p.id + '" data-active="' + (p.is_active ? 1 : 0) + '">' + (p.is_active ? '停用' : '启用') + '</button>'
            : '—';
          return '<tr><td>' + esc(p.name) + '</td><td>' + esc(p.tone || '—') + '</td><td>' + esc(p.domain || '—') + '</td><td>' + esc(p.disclosure_text || '—') + '</td><td>' + (p.is_active ? '启用' : '停用') + '</td>' +
            '<td>' + act + '</td></tr>';
        }).join('') : '<tr><td colspan="6" class="state-td">暂无人设</td></tr>';
        $$('#personaListBody [data-toggle-persona]').forEach(function (b) {
          b.addEventListener('click', function () { toggleActive('personas', parseInt(b.getAttribute('data-toggle-persona'), 10), b.getAttribute('data-active') !== '1'); });
        });
      })
      .catch(function (e) { if (e.message !== 'HTTP 401' && e.message !== 'HTTP 403') $('#personaListBody').innerHTML = '<tr><td colspan="6" class="state-td">加载失败：' + esc(e.message) + '</td></tr>'; });

    apiGet('/api/v1/manual-publications/settings/accounts')
      .then(function (j) {
        var rows = (j.data) || [];
        $('#accountListBody').innerHTML = rows.length ? rows.map(function (a) {
          var act = isAdmin()
            ? '<button class="btn ghost mini" data-edit-account="' + a.id + '">编辑</button> <button class="btn ghost mini" data-toggle-account="' + a.id + '" data-active="' + (a.is_active ? 1 : 0) + '">' + (a.is_active ? '停用' : '启用') + '</button>'
            : '—';
          if (isAdmin() && a.platform === 'wechat' && a.is_active) act += ' <button class="btn ghost mini" data-wechat-connect="' + a.id + '">连接公众号</button>';
          return '<tr><td>' + esc(a.account_name) + '</td><td>' + platformLabel(a.platform) + (a.platform === 'custom' && a.custom_platform ? '（' + esc(a.custom_platform) + '）' : '') + '</td><td>' + esc(a.persona ? a.persona.name : '—') + '</td><td>' + (a.is_active ? '启用' : '停用') + '</td>' +
            '<td>' + act + '</td></tr>';
        }).join('') : '<tr><td colspan="5" class="state-td">暂无账号</td></tr>';
        $$('#accountListBody [data-wechat-connect]').forEach(function (button) {
          button.addEventListener('click', function () {
            var account = rows.find(function (row) { return String(row.id) === button.getAttribute('data-wechat-connect'); });
            if (account && window.GEO_WECHAT_DRAFTS) {
              window.GEO_WECHAT_DRAFTS.mountConnection({host: $('#wechatConnectionHost'), account: account, apiGet: apiGet, apiPost: apiPost});
              $('#wechatConnectionHost').scrollIntoView({behavior: 'smooth', block: 'center'});
            }
          });
        });
        $$('#accountListBody [data-toggle-account]').forEach(function (b) {
          b.addEventListener('click', function () { toggleActive('accounts', parseInt(b.getAttribute('data-toggle-account'), 10), b.getAttribute('data-active') !== '1'); });
        });
        $$('#accountListBody [data-edit-account]').forEach(function (button) {
          button.addEventListener('click', function () {
            var account = rows.find(function (row) { return String(row.id) === button.getAttribute('data-edit-account'); });
            if (!account) return;
            editingAccountId = account.id;
            var form = $('#accountForm');
            form.closest('details').open = true;
            $('#accountPersonaSel').value = String(account.persona_id);
            $('#accountPlatformSel').value = account.platform;
            $('#accountName').value = account.account_name || '';
            $('#accountCustomPlatform').value = account.custom_platform || '';
            $('#accountCustomPlatformFld').hidden = account.platform !== 'custom';
            $('#accountProfileUrl').value = account.profile_url || '';
            $('#accountNotes').value = account.notes || '';
            form.querySelector('[type="submit"]').textContent = '保存账号修改';
            $('#accountEditCancel').hidden = false;
            $('#accountName').focus();
          });
        });
      })
      .catch(function (e) { if (e.message !== 'HTTP 401' && e.message !== 'HTTP 403') $('#accountListBody').innerHTML = '<tr><td colspan="5" class="state-td">加载失败：' + esc(e.message) + '</td></tr>'; });
  }

  /* 启停切换：评审项 8——destroy 置停用后，之前在界面上再也找不到、无法恢复 */
  function toggleActive(kind, id, activate) {
    var msg = $(kind === 'personas' ? '#personaListMsg' : '#accountListMsg');
    if (msg) { msg.textContent = ''; msg.className = 'form-msg'; }
    apiPut('/api/v1/manual-publications/settings/' + kind + '/' + id, { is_active: activate }, true)
      .then(function () { loadSettings(); fillSelects(); })
      .catch(function (e) {
        if (e.message === 'HTTP 401') return;
        if (msg) {
          msg.textContent = (e.message === 'HTTP 403')
            ? '启停人设 / 账号需要管理员权限，请联系租户管理员。'
            : '切换失败：' + e.message;
          msg.className = 'form-msg err';
        }
        loadSettings();
      });
  }

  /* ---------------- 账号下拉：人设 + 平台双条件 ----------------
     账号在库里绑死「人设 + 平台」两样，后端建单要求三者一致，不一致直接 422
     account_mismatch（ManualPublicationService::prepare）。此前这里只按人设过滤，
     于是「小红书的账号 + 知乎」这种后端必拒的组合会被摆到界面上；又因为平台下拉
     默认停在 PLATFORMS 第一项（知乎），表单一打开就是失败态——2026-08-30 生产
     实测两次 422 即此。故：过滤必须带平台，且改平台要重新过滤。 */
  function accountMatches(a, personaId, platform, customPlatform) {
    if (!a || !a.is_active) return false;
    if (String(a.persona_id) !== String(personaId)) return false;
    if (String(a.platform) !== String(platform)) return false;
    // 自定义平台：账号侧填了名字才比对，与后端同一判据（账号侧为空视为通配）
    if (platform === 'custom' && a.custom_platform) {
      return String(a.custom_platform).trim() === String(customPlatform || '').trim();
    }
    return true;
  }

  /* 空态必须说清「为什么空」：只显示「选择账号…」会让人以为没建账号，
     实际是平台选错了。这一句是本次缺陷里最要紧的可用性修复。 */
  function noAccountHint(platform) {
    return '该人设在「' + esc(platformLabel(platform)) + '」下没有可用账号';
  }

  function refreshAccountOptions() {
    var as = $('#accountSel'); if (!as) return;
    var pid = $('#personaSel').value;
    var platform = $('#platformSel').value;
    var custom = ($('#customPlatform') && $('#customPlatform').value || '').trim();
    if (!pid) { as.innerHTML = '<option value="">先选人设…</option>'; return; }
    apiGet('/api/v1/manual-publications/settings/accounts')
      .then(function (j) {
        var rows = ((j.data) || []).filter(function (a) {
          return accountMatches(a, pid, platform, custom);
        });
        if (!rows.length) { as.innerHTML = '<option value="">' + noAccountHint(platform) + '</option>'; return; }
        as.innerHTML = '<option value="">选择账号…</option>' + rows.map(function (a) {
          return '<option value="' + a.id + '">' + esc(a.account_name) + '</option>';
        }).join('');
      })
      .catch(function (e) { if (e.message !== 'HTTP 401' && e.message !== 'HTTP 403') as.innerHTML = '<option value="">加载账号失败</option>'; });
  }

  /* ---------------- 新建表单 ---------------- */
  /* fillSelects 只负责刷新下拉选项（会被 toggle/新建人设后反复调用）；
     事件监听一次性绑在 boot() 里，避免重复叠加监听器。 */
  function fillSelects() {
    var ps = $('#platformSel');
    ps.innerHTML = PLATFORMS.map(function (p) { return '<option value="' + p[0] + '">' + p[1] + '</option>'; }).join('');

    apiGet('/api/v1/manual-publications/settings/personas')
      .then(function (j) {
        var rows = (j.data || []).filter(function (p) { return p.is_active; });
        // 建单只能选启用人设（服务层校验 is_active=true）
        $('#personaSel').innerHTML = '<option value="">选择人设…</option>' + rows.map(function (p) {
          return '<option value="' + p.id + '">' + esc(p.name) + '</option>';
        }).join('');
        // 同时给「建账号」表单喂启用人设
        var aps = $('#accountPersonaSel');
        if (aps) {
          aps.innerHTML = '<option value="">选择人设…</option>' + rows.map(function (p) {
            return '<option value="' + p.id + '">' + esc(p.name) + '</option>';
          }).join('');
        }
      })
      .catch(function (e) { if (e.message !== 'HTTP 401' && e.message !== 'HTTP 403') $('#personaSel').innerHTML = '<option value="">加载人设失败</option>'; });

    $('#personaSel').dispatchEvent(new Event('change'));
  }

  /* 缺陷 C（派单二 2026-08-31）：「指派给」原本是让人手填数字用户 ID 的输入框——
     客户得先知道同事的数字 ID 才能指派，而这个数字界面上任何地方都不显示。
     改成下拉，选项取工作台现成的 GET /api/v1/team/（TeamController::index 返回 data[]：
     id / name / email / role），不新写端点。

     🔴 /api/v1/team/ 挂 can:team:manage，**只有 admin 能调**（routes/api.php:306 +
     Permissions.php:39）。editor 调它必吃 403 —— 若走默认路径，会触发缺陷 A 新加的
     「403 → 重新核验 → 竖整页无权限墙」，把明明拥有 manual_publish:manage 的 editor
     整页挡在门外。故两条规定：
       ① 这个请求必须走 soft403（拉不到成员是「这一项没法用」，不是「整个模块没权限」）；
       ② editor 根本不发这个请求，下拉里只放「我自己」——后端
          ManualPublicationService::prepare() 只在「指派对象发生变化」时校验
          manual_publish:assign（仅 admin），editor 指派给自己是放行的，不会被 422 顶回。 */
  /* 🔴 2026-08-31 评审实跑修正：「我自己」这一项的 value 必须是当前用户的**真实 id**，不能是空串。
     空串会让提交送出 assigned_user_id=null，而后端：
       · mergeWithExisting() 用 array_key_exists 判断，显式的 null **不会**被回填成现值；
       · prepare() 的指派权限校验写的是 `$assignedUserId !== null && ...`，null 根本不进校验；
       · 于是 null 直接落库，而 scopeVisibleTo() 对非 admin 是
         `where('assigned_user_id', 自己)` —— editor 保存一次改单就把自己从单上摘掉，
         这张单从他的列表里消失，而他没有权限再指派回来。
     （旧的数字输入框预填了现有 id、保存时原样送回，反而不会踩；这是改成下拉时新引入的回归。）
     用真实 id 之后：
       · editor 改单送回自己的 id，prepare() 里 previous === 自己 → 不算「换人」，不校验；
       · editor 建单送自己的 id → `$assignedUserId === $actor->id` 走放行分支，不会 422；
       · 标签上那句「留空=自己」原本就是假的（留空实际是未指派），现在改成显式选项，标签同步去掉那句。 */
  function selfOption() {
    var me = userInfo() || {};
    return me.id ? '<option value="' + me.id + '">我自己</option>' : '';
  }

  function fillAssignee(select, selectedId) {
    if (!select) return;
    var selfOpt = selfOption();
    if (!selfOpt) {
      /* 连自己是谁都读不出来（session 异常）。失效方向必须是「不动数据」：
         禁用 → 提交时整个不带这一项 → 后端回填现值，不会误清。 */
      select.innerHTML = '<option value="">（读不到当前用户）</option>';
      select.disabled = true;
      select.title = '读不到当前用户身份，指派项暂不可用';
      return;
    }
    var meId = String((userInfo() || {}).id);
    if (!isAdmin()) {
      /* editor 只能指派给自己（manual_publish:assign 仅 admin），所以只放「我自己」一项。
         也**不给「未指派」**：他一旦选了未指派，这张单就从他的列表里消失且收不回来。 */
      select.innerHTML = selfOpt;
      select.disabled = false;
      select.title = '指派给别人需要 manual_publish:assign（仅管理员）';
      select.value = meId;
      return;
    }
    select.innerHTML = selfOpt;
    select.disabled = true;  // 列表拉到之前不给选，不许静默降级回数字输入框
    select.title = '正在加载本租户成员…';
    apiGet('/api/v1/team/', true)
      .then(function (j) {
        // 自己已经在「我自己」那一项里了，成员列表里再出现一次就是两个同值选项。
        var rows = (j.data || []).filter(function (m) { return String(m.id) !== meId; });
        /* 「未指派」只给 admin：库里有存量单子 assigned_user_id 为 null，
           改单表单要能如实显示当前状态；admin 也确实有权把指派清掉。 */
        select.innerHTML = selfOpt + '<option value="">未指派</option>' + rows.map(function (m) {
          return '<option value="' + m.id + '">' + esc(m.name || ('用户 #' + m.id)) + (m.email ? '（' + esc(m.email) + '）' : '') + '</option>';
        }).join('');
        select.disabled = false;
        select.title = '';
        select.value = selectedId ? String(selectedId) : '';
      })
      .catch(function (e) {
        if (e.message === 'HTTP 401') return; // 正在跳登录页，别再动界面
        // 按本仓铁律：失败要显式，不拿 fallback 掩盖后端不通——
        // 禁用 + 写明原因，并且提交时这一项整个不带（见 submitNew / submitEdit）。
        select.innerHTML = '<option value="">（成员列表加载失败）</option>';
        select.disabled = true;
        select.title = '成员列表加载失败：' + (e.message || '未知错误');
      });
  }

  /* 一次性监听器（boot 调用） */
  function bindStaticListeners() {
    var ps = $('#platformSel');
    ps.addEventListener('change', function () {
      var fld = $('#customPlatformFld'); if (fld) fld.hidden = (ps.value !== 'custom');
      refreshAccountOptions();
    });

    $('#personaSel').addEventListener('change', refreshAccountOptions);

    var cpi = $('#customPlatform');
    if (cpi) cpi.addEventListener('change', refreshAccountOptions);

    var apsSel = $('#accountPlatformSel');
    if (apsSel) {
      apsSel.innerHTML = PLATFORMS.map(function (p) { return '<option value="' + p[0] + '">' + p[1] + '</option>'; }).join('');
      apsSel.addEventListener('change', function () {
        var fld = $('#accountCustomPlatformFld'); if (fld) fld.hidden = (apsSel.value !== 'custom');
      });
    }

    var content = $('#content');
    content.addEventListener('input', updateContentCounter);

    // B-2：类型切换 → 文章选择器 / 评论字段联动显隐
    var typeSel = $('#typeSel');
    if (typeSel) typeSel.addEventListener('change', function () { syncTypeUi(''); });
  }

  /* 评审项 5：#riskBox 声明了从未写入——创建成功后真渲染风险扫描结果 */
  function renderRiskBox(data) {
    var box = $('#riskBox'); if (!box) return;
    var rs = (data && data.risk_status) || 'clean';
    var rr = (data && data.risk_result) || {};
    var cls = rs === 'blocked' ? 'block' : (rs === 'warning' ? 'warn' : 'clean');
    var label = rs === 'blocked' ? '拦截' : (rs === 'warning' ? '警告' : '干净');
    var html = '<strong>风险扫描：' + label + '</strong>';
    var ms = (rr && Array.isArray(rr.matches)) ? rr.matches : [];
    // 防御性：平台红线词绝不在作者界面渲染（服务端已按 origin 过滤，这里兜底）。
    ms = ms.filter(function (m) { return m.origin !== 'platform'; });
    if (ms.length) {
      html += '<ul>';
      ms.forEach(function (m) {
        html += '<li>' + esc(m.word) + '（' + esc(m.field) + ' × ' + (m.count || 0) + '，' + esc(m.severity) + '）' +
          (m.suggestion ? ' — ' + esc(m.suggestion) : '') + '</li>';
      });
      html += '</ul>';
    }
    box.className = 'risk-box ' + cls;
    box.innerHTML = html;
    box.hidden = false;
  }

  function submitNew(e) {
    e.preventDefault();
    var msg = $('#formMsg');
    msg.textContent = ''; msg.className = 'form-msg';
    /* 上一单成功时渲染的风险扫描结果必须先清掉：否则本次失败时，「创建失败」
       旁边还挂着上一单的「风险扫描：干净」，看上去像是这一单扫过且干净。 */
    var rb0 = $('#riskBox'); if (rb0) { rb0.hidden = true; rb0.innerHTML = ''; }
    var platform = $('#platformSel').value;
    var customPlatform = ($('#customPlatform') && $('#customPlatform').value || '').trim();
    if (platform === 'custom' && !customPlatform) {
      msg.textContent = '选「自定义」平台必须填写平台名称'; msg.className = 'form-msg err';
      return;
    }
    var type = $('#typeSel').value;
    var articleId = (type === 'post') ? (parseInt($('#articleSel').value, 10) || null) : null;
    if (type === 'post' && !articleId) {
      msg.textContent = '请先选择一篇已审核文章'; msg.className = 'form-msg err';
      return;
    }
    var articleVersion = null;
    if (type === 'post') {
      try {
        if (!articleSource) throw new Error('文章正文工具未加载，请刷新页面。');
        articleVersion = articleSource.versionForSubmission();
      } catch (error) {
        msg.textContent = error.message;
        msg.className = 'form-msg err';
        return;
      }
    }
    var payload = {
      type: type,
      article_id: articleId,
      article_version: articleVersion,
      persona_id: parseInt($('#personaSel').value, 10) || null,
      account_id: parseInt($('#accountSel').value, 10) || null,
      platform: platform,
      custom_platform: platform === 'custom' ? customPlatform : null,
      content: $('#content').value,
      target_url: $('#targetUrl').value || null,
      target_context: $('#targetContext').value || null,
      // 验收第 7 条：裸串不带时区会被后端当 UTC 存，导致回显差 8 小时，这里统一转 UTC ISO。
      scheduled_at: scheduledPayload($('#scheduledAt').value)
    };
    /* 缺陷 C：成员列表没拉到时 #assignedUserId 是禁用的，此时**不带** assigned_user_id——
       带上 null 会被服务层当成「把指派清空」，等于在没问过用户的情况下改了数据。 */
    var assignSel = $('#assignedUserId');
    if (assignSel && !assignSel.disabled) { payload.assigned_user_id = parseInt(assignSel.value, 10) || null; }
    apiPost('/api/v1/manual-publications', payload)
      .then(function (j) {
        msg.textContent = '已创建 #' + j.data.id; msg.className = 'form-msg ok';
        renderRiskBox(j.data);
        $('#newPubForm').reset(); updateContentCounter();
        var cf = $('#customPlatformFld'); if (cf) cf.hidden = true;
        syncTypeUi(''); // 重置后类型回到默认（文章），重新同步显隐 + 加载文章
        loadList();
      })
      .catch(function (err) {
        if (err.message === 'HTTP 401' || err.message === 'HTTP 403') return;
        msg.textContent = '创建失败：' + err.message; msg.className = 'form-msg err';
      });
  }

  /* ---------------- 设置区新建表单（评审阻断项 5：没有表单则新租户走不通任何链路） ---------------- */
  function submitPersonaForm(e) {
    e.preventDefault();
    var msg = $('#personaMsg');
    msg.textContent = ''; msg.className = 'form-msg';
    var name = ($('#personaName').value || '').trim();
    if (!name) { msg.textContent = '人设名称必填'; msg.className = 'form-msg err'; return; }
    apiPost('/api/v1/manual-publications/settings/personas', {
      name: name,
      tone: $('#personaTone').value || null,
      domain: $('#personaDomain').value || null,
      disclosure_text: $('#personaDisclosure').value || null,
    }, true)
      .then(function () {
        msg.textContent = '人设已创建'; msg.className = 'form-msg ok';
        $('#personaForm').reset();
        loadSettings(); fillSelects();
      })
      .catch(function (err) {
        if (err.message === 'HTTP 401') return;
        msg.textContent = (err.message === 'HTTP 403')
          ? '新建人设需要管理员权限，请联系租户管理员。'
          : '创建失败：' + err.message;
        msg.className = 'form-msg err';
      });
  }

  function submitAccountForm(e) {
    e.preventDefault();
    var msg = $('#accountMsg');
    msg.textContent = ''; msg.className = 'form-msg';
    var personaId = parseInt($('#accountPersonaSel').value, 10) || null;
    var name = ($('#accountName').value || '').trim();
    var platform = $('#accountPlatformSel').value;
    var custom = ($('#accountCustomPlatform').value || '').trim();
    if (!personaId) { msg.textContent = '必须选择所属人设'; msg.className = 'form-msg err'; return; }
    if (!name) { msg.textContent = '账号名必填'; msg.className = 'form-msg err'; return; }
    if (platform === 'custom' && !custom) { msg.textContent = '选「自定义」平台必须填写平台名称'; msg.className = 'form-msg err'; return; }
    var savingId = editingAccountId;
    var saveAccount = savingId ? apiPut : apiPost;
    saveAccount('/api/v1/manual-publications/settings/accounts' + (savingId ? '/' + savingId : ''), {
      persona_id: personaId,
      platform: platform,
      custom_platform: platform === 'custom' ? custom : null,
      account_name: name,
      profile_url: $('#accountProfileUrl').value || null,
      notes: $('#accountNotes').value || null,
    }, true)
      .then(function () {
        msg.textContent = savingId ? '账号已更新' : '账号已创建'; msg.className = 'form-msg ok';
        resetAccountEditor();
        $('#accountForm').reset();
        var fld = $('#accountCustomPlatformFld'); if (fld) fld.hidden = true;
        loadSettings();
      })
      .catch(function (err) {
        if (err.message === 'HTTP 401') return;
        msg.textContent = (err.message === 'HTTP 403')
          ? '新建账号需要管理员权限，请联系租户管理员。'
          : '创建失败：' + err.message;
        msg.className = 'form-msg err';
      });
  }

  function resetAccountEditor() {
    editingAccountId = null;
    $('#accountForm').reset();
    $('#accountForm').querySelector('[type="submit"]').textContent = '新建账号';
    $('#accountEditCancel').hidden = true;
    $('#accountCustomPlatformFld').hidden = true;
  }

  /* 非 admin 隐藏人设 / 账号的新建表单，并说明原因（列表仍可读——建单要用）。 */
  function applySettingsWriteVisibility() {
    if (isAdmin()) return;
    ['#personaForm', '#accountForm'].forEach(function (sel) {
      var f = $(sel); if (f) f.hidden = true;
    });
    [['#personaListMsg', '人设'], ['#accountListMsg', '账号']].forEach(function (pair) {
      var m = $(pair[0]);
      if (m) { m.textContent = '新建 / 启停' + pair[1] + '仅管理员可操作，你仍可在建单时选用已有' + pair[1] + '。'; m.className = 'form-msg'; }
    });
  }

  /* ---------------- 文章选择器（B-2） + 编辑表单（A）共用辅助 ---------------- */

  /* 列表里的 scheduled_at 是带时区的 ISO；datetime-local 要本地 YYYY-MM-DDTHH:mm。
     用 Date 本地解析（尊重时区偏移），避免直接 slice(0,16) 造成的 8 小时漂移（A 坑1）。 */
  function isoToLocalInput(iso) {
    if (!iso) return '';
    var d = new Date(iso);
    if (isNaN(d.getTime())) return '';
    var pad = function (n) { return ('0' + n).slice(-2); };
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }

  /* isoToLocalInput 的反向操作（写侧）：把 datetime-local 的裸值转回带时区的 ISO。
     验收第 7 条实测（2026-08-29）发现的缺陷：此前提交的是「2026-09-01T14:30」这类
     不带时区的裸串，后端 app.timezone=UTC 会把它当 UTC 存成 14:30:00Z，而回显时
     isoToLocalInput 又按浏览器本地时区（Asia/Shanghai +08:00）渲染 → 显示 22:30，
     填写与回显差整整 8 小时，客户约的发布窗口会整体错位。
     读侧早就修过（见上一函数注释 A 坑1），写侧没修，存读两套基准才出的错。
     这里按浏览器本地时区解析后 toISOString()，与 isoToLocalInput 构成同一基准的往返：
       输入 14:30(+08:00) → 存 06:30:00Z → 回显 14:30。 */
  function scheduledPayload(v) {
    if (!v) return null;
    var d = new Date(v);
    return isNaN(d.getTime()) ? null : d.toISOString();
  }

  /* 取数用两个端点分别拉 approved / published（ofStatus 只吃单 status），合并去重。
     不前端过滤分页——接口分页上限 100，一页里可能一条已审核都没有（执行书 §四 B-2）。 */
  function loadArticles(selectEl, selectedId) {
    if (!selectEl) return;
    selectEl.innerHTML = '<option value="">加载中…</option>';
    Promise.all([
      apiGet('/api/v1/articles?status=approved&per_page=100' + (projectQuery() ? '&' + projectQuery() : '')),
      apiGet('/api/v1/articles?status=published&per_page=100' + (projectQuery() ? '&' + projectQuery() : ''))
    ]).then(function (res) {
      var list = [];
      var seen = {};
      [res[0], res[1]].forEach(function (j) {
        var rows = (j && j.data) || [];
        rows.forEach(function (a) {
          if (seen[a.id]) return;
          seen[a.id] = true;
          list.push(a);
        });
      });
      if (!list.length) { selectEl.innerHTML = '<option value="">暂无已审核文章</option>'; return; }
      selectEl.innerHTML = '<option value="">选择已审核文章…</option>' + list.map(function (a) {
        var st = (a.status === 'published') ? '已发布' : '已审核';
        var sel = (selectedId && parseInt(selectedId, 10) === parseInt(a.id, 10)) ? ' selected' : '';
        return '<option value="' + a.id + '"' + sel + '>' + esc(a.title || ('#'+a.id)) + '（' + st + '）</option>';
      }).join('');
    }).catch(function (e) {
      if (e.message === 'HTTP 401' || e.message === 'HTTP 403') return;
      selectEl.innerHTML = '<option value="">加载文章失败</option>';
    });
  }

  /* 类型联动：post → 显文章选择器、隐藏评论字段；comment → 反之（执行书 §四 B-2）。
     prefix 为空=新建表单，'edit'=编辑表单。 */
  function syncTypeUi(prefix, selectedId) {
    var ids = (prefix === 'edit')
      ? { type: '#editType', fld: '#editArticleFld', cmt: '#editCommentFields', sel: '#editArticle' }
      : { type: '#typeSel',  fld: '#articleFld',     cmt: '#commentFields',     sel: '#articleSel' };
    var typeEl = $(ids.type);
    var isPost = typeEl && typeEl.value === 'post';
    var af = $(ids.fld); if (af) af.hidden = !isPost;
    var cf = $(ids.cmt); if (cf) cf.hidden = isPost;
    if (isPost) loadArticles($(ids.sel), selectedId || null);
    if (prefix !== 'edit') updateContentCounter();
    else {
      var editContent = $('#editContent');
      if (editContent) editContent.maxLength = isPost ? 20000 : 2000;
    }
  }

  function updateContentCounter() {
    var content = $('#content');
    var counter = $('#contentCounter');
    if (!content || !counter) return;
    var limit = $('#typeSel').value === 'post' ? 20000 : 2000;
    content.maxLength = limit;
    counter.textContent = content.value.length + ' / ' + limit;
  }

  /* 编辑表单：人设下拉（启用 + 当前若已停用则补一行标注，A 坑2）；选中后联动账号 */
  function fillEditPersona(p) {
    apiGet('/api/v1/manual-publications/settings/personas').then(function (j) {
      var rows = (j.data || []).filter(function (x) { return x.is_active; });
      var html = '<option value="">选择人设…</option>';
      if (p.persona && p.persona.id) {
        var cur = rows.filter(function (x) { return x.id === p.persona.id; })[0];
        if (!cur) html += '<option value="' + p.persona.id + '" selected>' + esc(p.persona.name || ('#'+p.persona.id)) + '（已停用）</option>';
      }
      html += rows.map(function (x) {
        var sel = (p.persona && x.id === p.persona.id) ? ' selected' : '';
        return '<option value="' + x.id + '"' + sel + '>' + esc(x.name) + '</option>';
      }).join('');
      var sel = $('#editPersona'); if (sel) sel.innerHTML = html;
      fillEditAccount(p, p.persona ? p.persona.id : null);
    }).catch(function (e) {
      if (e.message !== 'HTTP 401' && e.message !== 'HTTP 403') { var s = $('#editPersona'); if (s) s.innerHTML = '<option value="">加载人设失败</option>'; }
    });
  }

  /* 编辑表单账号下拉：与建单同一判据（人设 + 平台，见 accountMatches）。
     平台改了必须重拉——否则会把「另一个平台的账号」留在选中位置，保存必 422。 */
  function fillEditAccount(p, personaId) {
    var as = $('#editAccount'); if (!as) return;
    var platEl = $('#editPlatform');
    var platform = platEl ? platEl.value : p.platform;
    var customEl = $('#editCustomPlatform');
    var custom = (customEl && customEl.value || '').trim();
    apiGet('/api/v1/manual-publications/settings/accounts').then(function (j) {
      var all = j.data || [];
      var rows = all.filter(function (a) { return accountMatches(a, personaId, platform, custom); });
      var curId = (p.account && p.account.id) || null;
      var curInRows = !!(curId && rows.filter(function (a) { return a.id === curId; }).length);
      var html = '<option value="">' + (rows.length ? '选择账号…' : noAccountHint(platform)) + '</option>';
      if (curId && !curInRows) {
        /* 本单原账号在当前「人设 + 平台」下不可用。要说清是停用还是平台不符，
           且不能预选它——预选等于把一个保存必 422 的值摆成默认值。 */
        var found = all.filter(function (a) { return a.id === curId; })[0];
        var why = !found ? '已删除' : (!found.is_active ? '已停用' : '属于「' + platformLabel(found.platform) + '」');
        html += '<option value="" disabled>原账号 ' + esc(p.account.account_name || ('#' + curId)) + '（' + esc(why) + '，不可用）</option>';
      }
      html += rows.map(function (a) {
        var sel = (curId && a.id === curId) ? ' selected' : '';
        return '<option value="' + a.id + '"' + sel + '>' + esc(a.account_name) + '</option>';
      }).join('');
      as.innerHTML = html;
    }).catch(function (e) {
      if (e.message !== 'HTTP 401' && e.message !== 'HTTP 403') as.innerHTML = '<option value="">加载账号失败</option>';
    });
  }

  function renderEditForm(p) {
    var typeOpts = '<option value="post"' + (p.type === 'post' ? ' selected' : '') + '>文章</option>' +
                   '<option value="comment"' + (p.type === 'comment' ? ' selected' : '') + '>评论</option>';
    var platOpts = PLATFORMS.map(function (x) {
      return '<option value="' + x[0] + '"' + (x[0] === p.platform ? ' selected' : '') + '>' + x[1] + '</option>';
    }).join('');
    return '<form id="editPubForm" class="run-form">' +
      '<input type="hidden" id="editRevision" value="' + (p.revision || 1) + '">' +
      '<label class="fld"><span class="fld-label">类型</span><select id="editType">' + typeOpts + '</select></label>' +
      '<label class="fld"><span class="fld-label">人设</span><select id="editPersona" required><option value="">选择人设…</option></select></label>' +
      '<label class="fld"><span class="fld-label">账号</span><select id="editAccount"><option value="">选择账号…</option></select></label>' +
      '<label class="fld"><span class="fld-label">平台</span><select id="editPlatform" required>' + platOpts + '</select></label>' +
      '<label class="fld" id="editCustomPlatformFld" hidden><span class="fld-label">自定义平台名（选「自定义」时必填）</span><input type="text" id="editCustomPlatform" maxlength="64" value="' + esc(p.custom_platform || '') + '" placeholder="平台名称，≤64 字"></label>' +
      '<label class="fld" id="editArticleFld" hidden><span class="fld-label">关联文章（选「文章」时必选）</span><select id="editArticle"><option value="">选择已审核文章…</option></select></label>' +
      '<label class="fld fld-wide"><span class="fld-label">内容</span><textarea id="editContent" rows="5" maxlength="' + (p.type === 'post' ? 20000 : 2000) + '" required>' + esc(p.content || '') + '</textarea></label>' +
      '<div id="editCommentFields">' +
        '<label class="fld fld-wide"><span class="fld-label">目标链接</span><input type="url" id="editTargetUrl" value="' + esc(p.target_url || '') + '"></label>' +
        '<label class="fld fld-wide"><span class="fld-label">目标上下文</span><input type="text" id="editTargetContext" value="' + esc(p.target_context || '') + '"></label>' +
      '</div>' +
      '<label class="fld fld-wide"><span class="fld-label">计划时间（可选）</span><input type="datetime-local" id="editScheduledAt" value="' + isoToLocalInput(p.scheduled_at) + '"></label>' +
      // 缺陷 C：原为手填数字用户 ID 的 input，改成下拉（选项由 fillAssignee 填充）。
      '<label class="fld"><span class="fld-label">指派给</span><select id="editAssigned" disabled></select></label>' +
      '<div class="form-actions"><button type="button" class="btn primary mini" id="editSave">保存</button>' +
      '<button type="button" class="btn ghost mini" id="editCancel">取消</button>' +
      '<span class="form-msg" id="editMsg"></span></div>' +
      '</form>';
  }

  function showEditForm(p, id) {
    var host = $('#detailHost');
    host.innerHTML = renderEditForm(p);
    var typeEl = $('#editType');
    if (typeEl) typeEl.addEventListener('change', function () { syncTypeUi('edit'); });
    syncTypeUi('edit', p.article_id); // 按当前类型显隐 + 若为文章预选已关联文章
    var epf = $('#editCustomPlatformFld'); if (epf) epf.hidden = (p.platform !== 'custom');
    var epl = $('#editPlatform');
    if (epl) epl.addEventListener('change', function () {
      var f = $('#editCustomPlatformFld'); if (f) f.hidden = (epl.value !== 'custom');
      fillEditAccount(p, $('#editPersona').value);
    });
    var ecp = $('#editCustomPlatform');
    if (ecp) ecp.addEventListener('change', function () { fillEditAccount(p, $('#editPersona').value); });
    fillEditPersona(p);
    fillAssignee($('#editAssigned'), p.assigned_user_id); // 缺陷 C：改单表单的「指派给」下拉
    var personaEl = $('#editPersona');
    if (personaEl) personaEl.addEventListener('change', function () { fillEditAccount(p, $('#editPersona').value); });
    $('#editCancel').addEventListener('click', function () { showDetail(id); });
    $('#editSave').addEventListener('click', function () { submitEdit(id); });
  }

  /* A 坑3：保存后由 showDetail 重新拉取拿新 revision，不自己 +1；
     A 坑4：409 只提示、不静默重试，也**不自动重拉**——重拉会把用户没保存的编辑内容冲掉
     （人工验收第 3 条），保留编辑态让他复制后自行刷新。 */
  function submitEdit(id) {
    var msg = $('#editMsg'); if (msg) { msg.textContent = ''; msg.className = 'form-msg'; }
    var type = $('#editType').value;
    var personaId = parseInt($('#editPersona').value, 10) || null;
    var accountId = parseInt($('#editAccount').value, 10) || null;
    var articleId = (type === 'post') ? (parseInt($('#editArticle').value, 10) || null) : null;
    var platform = $('#editPlatform').value;
    var customPlatform = ($('#editCustomPlatform') && $('#editCustomPlatform').value || '').trim();
    if (type === 'post' && !articleId) { if (msg) { msg.textContent = '请先选择一篇已审核文章'; msg.className = 'form-msg err'; } return; }
    if (!personaId) { if (msg) { msg.textContent = '请选择人设'; msg.className = 'form-msg err'; } return; }
    if (platform === 'custom' && !customPlatform) { if (msg) { msg.textContent = '请填写自定义平台名称'; msg.className = 'form-msg err'; } return; }
    var payload = {
      type: type,
      persona_id: personaId,
      account_id: accountId,
      platform: platform,
      custom_platform: platform === 'custom' ? customPlatform : null,
      content: $('#editContent').value,
      target_url: $('#editTargetUrl').value || null,
      target_context: $('#editTargetContext').value || null,
      // 验收第 7 条：同上，与 isoToLocalInput 构成同一基准往返。
      scheduled_at: scheduledPayload($('#editScheduledAt').value)
    };
    /* 缺陷 C：成员列表没拉到时 #editAssigned 是禁用的，此时**不带** assigned_user_id——
       改单是部分更新，带上 null 会被服务层当成「把指派清空」，
       等于用户只是改了内容，却顺手把同事从单子上摘掉了。 */
    var editAssignSel = $('#editAssigned');
    if (editAssignSel && !editAssignSel.disabled) { payload.assigned_user_id = parseInt(editAssignSel.value, 10) || null; }
    if (articleId) payload.article_id = articleId;
    var revision = parseInt($('#editRevision').value, 10) || 1;
    // soft403=true：局部权限错（如换指派人被拒是 422）只写到表单旁，不换整页（A 坑5）
    apiPut('/api/v1/manual-publications/' + id, Object.assign({ revision: revision }, payload), true)
      .then(function () { showDetail(id); })
      .catch(function (err) {
        if (err.message === 'HTTP 401') return;
        if (err.message === 'HTTP 403') { if (msg) { msg.textContent = '无权限执行此操作'; msg.className = 'form-msg err'; } return; }
        if (err.message && err.message.indexOf('已被其他人修改') !== -1) {
          /* 验收第 3 条：此前这里紧接着 showDetail(id)，会把 #detailHost 整体换成详情，
             刚写进去的提示当场被冲掉——用户只看到表单秒变详情、零提示，会误以为保存
             成功（数据其实被 409 拦住了、没被覆盖，但填的内容无声蒸发）。
             现在保留编辑态：内容还在、提示看得见，用户能复制后自行刷新重试。 */
          if (msg) {
            msg.textContent = '该发布单已被其他人修改，你的修改未保存。请复制内容后刷新页面重试。';
            msg.className = 'form-msg err';
          }
          return;
        }
        if (msg) { msg.textContent = '保存失败：' + err.message; msg.className = 'form-msg err'; }
      });
  }

  /* ---------------- 视图切换：保留锚点、表单和编辑版本 ---------------- */
  function showManualPublishSection(hash, focusHeading) {
    var ids = ['sec-list', 'sec-new', 'sec-detail', 'sec-settings'];
    var id = String(hash || '').replace(/^#/, '');
    if (ids.indexOf(id) === -1) id = 'sec-list';
    ids.forEach(function (name) {
      var section = $('#' + name);
      if (section) section.hidden = name !== id;
    });
    $$('.section-nav a').forEach(function (link) {
      var selected = link.getAttribute('href') === '#' + id;
      link.classList.toggle('on', selected);
      if (selected) link.setAttribute('aria-current', 'page');
      else link.removeAttribute('aria-current');
    });
    if (focusHeading) {
      var section = $('#' + id);
      var heading = section && section.querySelector('h2');
      if (heading) heading.focus({ preventScroll: true });
      if (section) section.scrollIntoView({ block: 'start' });
    }
  }

  function wireManualPublishNav() {
    $$('.section-nav a').forEach(function (link) {
      link.addEventListener('click', function () { showManualPublishSection(link.getAttribute('href'), false); });
    });
    window.addEventListener('hashchange', function () { showManualPublishSection(window.location.hash, true); });
    showManualPublishSection(window.location.hash, false);
  }

  /* ---------------- 启动 ---------------- */
  function boot() {
    wireManualPublishNav();
    $('#refreshBtn').addEventListener('click', loadList);
    $('#newPubForm').addEventListener('submit', submitNew);
    var pf = $('#personaForm'); if (pf) pf.addEventListener('submit', submitPersonaForm);
    var af = $('#accountForm'); if (af) af.addEventListener('submit', submitAccountForm);
    var helperDownload = $('#downloadPublishingHelper');
    if (helperDownload) helperDownload.addEventListener('click', function () {
      var message = $('#publishingHelperMessage');
      helperDownload.disabled = true;
      var packageName = 'yanji-publishing-helper.zip';
      apiReq('/api/v1/publishing/browser-extension', { raw: true }).then(function (response) {
        var filename = (response.headers.get('Content-Disposition') || '').match(/filename="(yanji-publishing-helper-\d+(?:\.\d+){0,3}(?:-[a-f0-9]{16})?\.zip)"/);
        if (filename) packageName = filename[1];
        return response.blob();
      })
        .then(function (blob) {
          var url = URL.createObjectURL(blob); var anchor = document.createElement('a');
          anchor.href = url; anchor.download = packageName; anchor.click();
          window.setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
          message.textContent = '安装包已下载。按下方步骤安装后刷新本页。';
        }).catch(function (error) { message.textContent = '下载失败：' + error.message; })
        .finally(function () { helperDownload.disabled = false; });
    });
    var cancelAccount = $('#accountEditCancel');
    if (cancelAccount) cancelAccount.addEventListener('click', resetAccountEditor);
    applySettingsWriteVisibility();
    bindStaticListeners();
    if (window.GEO_PUBLICATION_ARTICLE_SOURCE) {
      articleSource = window.GEO_PUBLICATION_ARTICLE_SOURCE.mount({
        form: $('#newPubForm'), article: $('#articleSel'), type: $('#typeSel'),
        content: $('#content'), host: $('#articleSourceHost'), apiGet: apiGet,
        onChange: updateContentCounter
      });
    }
    fillSelects();
    // 建单默认选中「我自己」：把自己的 id 当默认选中项传进去。
    // 原先传 null → 建出来的单没有指派人，而 scopeVisibleTo() 对非 admin 只看指派给自己的，
    // 于是 editor 建完单自己就找不到它了（评审 §六 记的那个语义坑，本次一并做实）。
    fillAssignee($('#assignedUserId'), (userInfo() || {}).id); // 缺陷 C：建单表单的「指派给」下拉
    syncTypeUi(''); // 新建表单按默认类型（文章）显隐文章选择器
    loadList();
    loadSettings();
    var publicationId = new URLSearchParams(window.location.search).get('publication_id');
    if (/^\d+$/.test(publicationId || '') && Number(publicationId) > 0) showDetail(Number(publicationId));
    var backLink = $('#backToProjectBindings');
    if (backLink && projectQuery()) { backLink.href = '/?' + projectQuery() + '#publishing'; backLink.hidden = false; }
  }

  /* ---------------- 首屏认证引导 ----------------
     B1 修复（docs/GEO-会话身份绑定-工兵执行书-2026-08-31.md §四之二）：
     原先是「sessionStorage 里有 token 就直接 boot」，等于把旧 token 当成了已登录且是本人。
     改成和另外四个壳页一致——每次加载都无条件用 cookie 重换一张 token，
     没有的时候跳登录页。这样退出账户中心 / 换账号后，本页不会再沿用旧身份。
     注意 TK/UK 是 geo_manual_publish 专用键，别抄成 visibility 的键名。 */
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
      boot();
    }).catch(function (e) {
      if (e !== 'redirect') showGuard('error');
    });
  }

  bootstrap();
})();
