/* ============================================================================
   GEO 商业运营台 · 平台侧交互引擎（geo-platform-ops.js）
   设计依据：design-specs/prototypes/geo-commercial-ops.html（原型=验收标准）
             geo-commercial-ops-spec.md（一页纸）
             GEO-Commercial-Ops-Frontend-Dev-Plan.md（执行书）
   红线遵守：
     - 页面与全部 platform 数据在 can:platform:operations 门后（后端路由已挂门）
     - 写操作端点未就绪一律「预留 UI + 明示未接入」，禁止 mock 成功
     - 金额只做分→元格式化，业务口径零换算（价值金仅用于金额展示）
     - 不引 UI 框架 / 打包器 / 图表库；不动 /workbench 任何文件
     - 无新色值字面量（仅用 CSS 变量）；不使用原生 confirm()
    数据策略：仅请求真实端点；任何读接口失败均渲染显式错误态（含重试按钮），绝不回退假数据。
            认证：页面加载先 POST session-login 拿 Sanctum token，非 platform_admin 走客户端守卫态。
   ============================================================================ */
(function () {
  'use strict';

  var $  = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /* ---------------- 认证与配置（方案 B：壳页先渲染 + 前端 session-login） ----------------
     GEO_OPS_CONFIG 由后端注入（window.GEO_OPS_CONFIG），登录地址不写死域名。 */
  var GEO_OPS_CONFIG = window.GEO_OPS_CONFIG || {
    accountLoginUrl: '/login',
    accountUrl: '/#personal-settings'
  };

  function token() {
    return sessionStorage.getItem('geo_token') || '';
  }
  function pickToken(payload) {
    return (payload && (payload.accessToken || payload.access_token || payload.token)) || '';
  }
  function persistSession(payload) {
    var t = pickToken(payload);
    if (!t) return;
    sessionStorage.setItem('geo_token', t);
    sessionStorage.setItem('geo_user', JSON.stringify((payload && payload.user) || null));
  }
  function accountLoginUrl() {
    var base = GEO_OPS_CONFIG.accountLoginUrl || '/login';
    try {
      var t = new URL(base, window.location.href);
      t.searchParams.set('redirect_to', window.location.href);
      return t.toString();
    } catch (e) {
      return base;
    }
  }

  /* 连接中 / 守卫态遮罩 */
  function showGuardState(kind) {
    var el = $('#opsState');
    if (!el) return;
    el.hidden = false;
    el.classList.toggle('is-forbidden', kind === 'forbidden');
    var glyph = $('#opsStateGlyph');
    if (glyph) glyph.hidden = (kind !== 'forbidden');
    var title = $('#opsStateTitle');
    if (title) title.textContent = (kind === 'forbidden') ? '无访问权限' : '连接中…';
    var text = $('#opsStateText');
    if (text) text.textContent = (kind === 'forbidden')
      ? '当前账号没有平台运营权限（platform:operations）。如有疑问请联系系统管理员。'
      : '正在校验运营台访问权限';
    var link = $('#opsStateLink');
    if (link) {
      if (kind === 'forbidden') { link.hidden = false; link.href = GEO_OPS_CONFIG.accountUrl; }
      else { link.hidden = true; }
    }
  }
  function hideState() {
    var el = $('#opsState');
    if (el) el.hidden = true;
  }
  function handleUnauthorized() {
    sessionStorage.removeItem('geo_token');
    sessionStorage.removeItem('geo_user');
    fetch('/api/v1/auth/session-login', { method: 'POST', credentials: 'include', headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('relogin failed'); return r.json(); })
      .then(function (payload) { persistSession(payload); window.location.reload(); })
      .catch(function () { window.location.href = accountLoginUrl(); });
  }

  /* 读接口失败 → 显式错误态（含重试），不回退假数据 */
  function showError(hostSel, colspan, retryFn, msg) {
    var host = $(hostSel);
    if (!host) return;
    msg = msg || '数据加载失败';
    if (host.tagName === 'TBODY') {
      host.innerHTML = '<tr><td colspan="' + colspan + '" class="state-tpl"><div class="st-icon">!</div><p>' +
        esc(msg) + '</p><button class="btn" data-retry="1">重试</button></td></tr>';
    } else {
      host.innerHTML = '<div class="state-tpl"><div class="st-icon">!</div><p>' +
        esc(msg) + '</p><button class="btn" data-retry="1">重试</button></div>';
    }
    var btn = host.querySelector('[data-retry]');
    if (btn && retryFn) btn.addEventListener('click', retryFn);
  }

  /* 首屏认证引导：session-login 拿 Sanctum token；非 platform_admin 走守卫态 */
  function bootstrap() {
    showGuardState('connecting');
    return fetch('/api/v1/auth/session-login', {
      method: 'POST', credentials: 'include', headers: { 'Accept': 'application/json' }
    }).then(function (r) {
      if (r.status === 401 || r.status === 419) { window.location.href = accountLoginUrl(); return Promise.reject('redirect'); }
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    }).then(function (payload) {
      persistSession(payload);
      var user = (payload && payload.user) || {};
      if (user.role !== 'platform_admin') { showGuardState('forbidden'); return Promise.reject('forbidden'); }
      var who = $('#opsWho');
      if (who) who.textContent = user.name || '平台运营';
      hideState();
      return payload;
    });
  }

  /* ---------------- 工具函数 ---------------- */
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function fmtInt(n) {
    n = Number(n) || 0;
    return n.toLocaleString('zh-CN');
  }
  function fmtWhen(iso) {
    if (!iso) return '—';
    var d = new Date(iso);
    if (isNaN(d.getTime())) return esc(iso);
    var now = new Date();
    var hm = ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
    var sameDay = d.toDateString() === now.toDateString();
    var yest = new Date(now); yest.setDate(now.getDate() - 1);
    if (sameDay) return '今天 ' + hm;
    if (d.toDateString() === yest.toDateString()) return '昨天 ' + hm;
    return ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
  }
  /* 到期友好：2026-08-02（31 天） */
  function fmtPeriodEnd(iso) {
    if (!iso) return '—';
    var end = new Date(iso);
    if (isNaN(end.getTime())) return esc(iso);
    var now = new Date();
    var days = Math.round((end.getTime() - now.getTime()) / 86400000);
    var ds = ('0' + (end.getMonth() + 1)).slice(-2) + '-' + ('0' + end.getDate()).slice(-2);
    if (days >= 0) return ds + '（' + days + ' 天）';
    return ds + '（已过 ' + Math.abs(days) + ' 天）';
  }

  /* 订单状态 → 语义化 pill（后端真实状态 → 前端呈现） */
  var STATUS_MAP = {
    pending:    { pill: 'wait',    label: '待支付' },
    processing: { pill: 'wait',    label: '待支付' },
    paid:       { pill: 'ok',      label: '已开通' },
    failed:     { pill: 'bad',     label: '回调失败' },
    expired:    { pill: 'neutral', label: '超时关闭' },
    cancelled:  { pill: 'neutral', label: '已取消' },
    refunded:   { pill: 'info',    label: '已退款' }
  };
  /* 后端状态 → 筛选下拉值（与 blade 下拉 option value 对齐） */
  function toast(msg) {
    var t = $('#gwToast');
    if (!t) {
      t = document.createElement('div');
      t.id = 'gwToast';
      t.style.cssText = 'position:fixed;left:50%;bottom:22px;transform:translateX(-50%);background:var(--md-text);color:var(--md-bg-page);padding:9px 16px;border-radius:999px;font-size:var(--md-fs-xs);box-shadow:var(--md-shadow-modal);z-index:80;opacity:0;transition:opacity .18s ease;pointer-events:none;max-width:90vw;';
      document.body.appendChild(t);
    }
    t.textContent = msg;
    t.style.opacity = '1';
    clearTimeout(t._tid);
    t._tid = setTimeout(function () { t.style.opacity = '0'; }, 2600);
  }

  /* ---------------- 数据层：真实端点 + Bearer 鉴权（方案 B） ---------------- */
  function apiGet(path) {
    return fetch(path, {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'Authorization': 'Bearer ' + token() }
    }).then(function (r) {
      if (r.status === 401) { handleUnauthorized(); throw new Error('HTTP 401'); }
      if (r.status === 403) { showGuardState('forbidden'); throw new Error('HTTP 403'); }
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    });
  }

  /* 状态模板 */
  function emptyRow(colspan, text) {
    return '<tr><td colspan="' + colspan + '" class="state-tpl"><div class="st-icon">○</div><p>' + esc(text) + '</p></td></tr>';
  }

  /* ===================================================================
     渲染：钱流总览（KPI + 链路带 + 告警条）
     =================================================================== */
  function setText(sel, txt) { var el = $(sel); if (el) el.textContent = txt; }

  /* ===================================================================
     渲染：订单查询（fixture + 前端筛选）
     =================================================================== */
  function renderSubs(tenants) {
    var list = tenants && tenants.data || [];
    var body = $('#subsBody');
    if (!body) return;
    setPill('#subActive', '活跃', list.filter(function (t) { return t.status === 'active'; }).length);
    setPill('#subPaused', '已暂停', list.filter(function (t) { return t.status === 'paused'; }).length);
    if (!list.length) { body.innerHTML = emptyRow(5, '暂无当前工作区数据'); return; }
    body.innerHTML = list.map(function (t) {
      var action = t.status === 'paused' ? 'resume' : 'suspend';
      var label = t.status === 'paused' ? '恢复账号' : '暂停账号';
      return '<tr><td>' + esc(t.name || t.subdomain) + '</td><td>' + esc(t.status) + '</td>'
        + '<td>' + fmtInt(t.users_count) + '</td><td>' + fmtInt(t.last_30d && t.last_30d.executions) + '</td>'
        + '<td><button class="btn ghost" data-act="' + action + '" data-tenant="' + esc(t.subdomain)
        + '" data-name="' + esc(t.name) + '">' + label + '</button></td></tr>';
    }).join('');
  }

  function setPill(sel, label, n) {
    var el = $(sel);
    if (!el) return;
    el.hidden = false;
    el.textContent = label + ' ' + n;
  }

  /* ===================================================================
     渲染：发票队列（fixture，协调点 E）
     =================================================================== */
  function renderHealth(health) {
    var host = $('#healthRow');
    if (!host) return;
    if (!health) { host.innerHTML = emptyRow(4, '健康数据暂不可用'); return; }
    function card(label, num, sub, warn) {
      return '<div class="hcard"><div class="h-num' + (warn ? ' warn' : '') + '">' + esc(num) + '</div>' +
        '<div class="h-label">' + esc(label) + '</div>' +
        (sub ? '<div class="h-sub' + (warn ? ' warn' : '') + '">' + esc(sub) + '</div>' : '') + '</div>';
    }
    var mc = health.model_calls || {};
    var ex = health.executions || {};
    var pb = health.publications || {};
    host.innerHTML =
      card('模型调用成功率', (mc.success_rate == null ? '—' : mc.success_rate + '%'), (mc.total ? fmtInt(mc.total) + ' 次调用' : ''), false) +
      card('技能执行失败率', (ex.failure_rate == null ? '—' : ex.failure_rate + '%'), (ex.total ? fmtInt(ex.total) + ' 次执行' : ''), false) +
      card('分发失败率', (pb.failure_rate == null ? '—' : pb.failure_rate + '%'), (pb.total ? fmtInt(pb.total) + ' 次发布' : ''), false);
  }

  /* ===================================================================
     渲染：队列健康（单 9）—— 复用既有 .tbl / .pill / .mc-alert，不新造配色
     =================================================================== */
  function renderQueueHealth(data) {
    // ① 告警（无消费者检测，本单最重要的一条）
    var alerts = (data && data.alerts) || [];
    var alertHost = $('#queueAlerts');
    if (alertHost) {
      alertHost.innerHTML = alerts.length
        ? alerts.map(function (a) {
            return '<div class="mc-alert"><span class="dot"></span><span>' + esc(a.message) + '</span></div>';
          }).join('')
        : '';
    }

    // ② worker 心跳表
    var workers = (data && data.workers) || [];
    var wbody = $('#queueWorkers');
    if (wbody) {
      if (!workers.length) { wbody.innerHTML = emptyRow(5, '暂无 worker 心跳（心跳写入可能未开启）'); }
      else {
        wbody.innerHTML = workers.map(function (w) {
          var stalePill = w.stale ? '<span class="pill bad">判死</span>' : '<span class="pill">存活</span>';
          var statusPill = (w.status === 'stopping')
            ? '<span class="pill wait">回收中</span>'
            : (w.status === 'idle' ? '<span class="pill wait">空闲</span>' : esc(w.status || 'running'));
          var seen = w.last_seen_at ? w.last_seen_at : '—';
          var queues = (w.queues && w.queues.length) ? w.queues.map(function (q) { return esc(q); }).join(', ') : '—';
          return '<tr><td class="cell-main">' + esc(w.worker_id) + '</td><td>' + statusPill + '</td><td>' + esc(seen) + '</td><td>' + stalePill + '</td><td>' + queues + '</td></tr>';
        }).join('');
      }
    }

    // ③ 队列深度表
    var queues = (data && data.queues) || [];
    var dbody = $('#queueDepth');
    if (dbody) {
      if (!queues.length) { dbody.innerHTML = emptyRow(5, 'jobs 表无任务'); }
      else {
        dbody.innerHTML = queues.map(function (q) {
          var wait = (q.oldest_wait_seconds == null) ? '—' : fmtQueueWait(q.oldest_wait_seconds);
          var pendingCls = q.pending > 0 ? ' class="warn"' : '';
          // 「处理中卡住」= 被 worker 领走后超过 retry_after 还没跑完的任务数（后端算，前端只显示）。
          var stuck = Number(q.stuck_reserved || 0);
          var stuckCls = stuck > 0 ? ' class="warn"' : '';
          var stuckTxt = stuck > 0
            ? (fmtInt(stuck) + '（已 ' + fmtQueueWait(q.oldest_reserved_seconds || 0) + '）')
            : '0';
          return '<tr><td class="cell-main">' + esc(q.queue) + '</td><td' + pendingCls + '>' + fmtInt(q.pending) + '</td><td>' + esc(wait) + '</td><td' + stuckCls + '>' + esc(stuckTxt) + '</td><td>' + fmtInt(q.failed_24h) + '</td></tr>';
        }).join('');
      }
    }
  }

  /* 队列等待时长格式化（秒 → 「X 分 Y 秒」），仅本文件使用 */
  function fmtQueueWait(sec) {
    sec = Math.floor(Number(sec) || 0);
    if (sec <= 0) return '0 秒';
    var m = Math.floor(sec / 60);
    var s = sec % 60;
    if (m <= 0) return s + ' 秒';
    return s > 0 ? (m + ' 分 ' + s + ' 秒') : (m + ' 分');
  }

  /* ===================================================================
     渲染：风险租户（前端聚合规则，常量可调）
     =================================================================== */
  var __lastTenants = null;
  var __lastHealth = null;
  function renderRisk(tenants) {
    var body = $('#riskBody');
    if (!body) return;
    var list = (tenants && tenants.data || []).filter(function (t) {
      return t.status === 'paused' && t.last_30d && t.last_30d.executions === 0;
    });
    body.innerHTML = list.length ? list.map(function (t) {
      return '<tr><td>' + esc(t.name || t.subdomain) + '</td><td>已暂停，近 30 天无执行</td></tr>';
    }).join('') : emptyRow(2, '当前工作区没有需要跟进的风险');
  }

  var dmMask, dmReason, dmConfirm, dmNote, dmNoteLabel, dmAction;
  function initModal() {
    dmMask = $('#dmMask');
    if (!dmMask) return;
    dmReason = $('#dmReason');
    dmConfirm = $('#dmConfirm');
    dmNote = $('#dmNote');
    dmNoteLabel = $('#dmNoteLabel');

    dmReason.addEventListener('change', function () { syncDmConfirm(); });
    if (dmNote) dmNote.addEventListener('input', function () { syncDmConfirm(); });
    $('#dmCancel').addEventListener('click', closeModal);
    dmMask.addEventListener('click', function (e) { if (e.target === dmMask) closeModal(); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && dmMask.classList.contains('open')) closeModal();
    });
    dmConfirm.addEventListener('click', function () {
      if (dmConfirm.disabled || !dmAction) return;
      var value = (dmAction.reasonMode === 'select') ? dmReason.value : (dmNote ? dmNote.value.trim() : '');
      var action = dmAction;
      closeModal();
      runAction(action, value);
    });
  }
  function syncDmConfirm() {
    if (!dmAction || !dmConfirm) return;
    if (dmAction.reasonMode === 'select') {
      dmConfirm.disabled = !dmReason.value;
    } else {
      dmConfirm.disabled = !!(dmAction.reasonRequired && (!dmNote || !dmNote.value.trim()));
    }
  }
  function openModal(action) {
    if (!dmMask) return;
    dmAction = action;
    $('#dmTitle').innerHTML = action.title;
    var impact = $('.dm-impact'); if (impact) impact.hidden = !action.showImpact;
    var sub = $('.dm-sub');      if (sub) sub.hidden = !action.showImpact;
    if (action.subject) { var t = $('#dmTenant'); if (t) t.textContent = action.subject; }
    if (action.reasonMode === 'select') {
      if ($('#dmReasonField')) $('#dmReasonField').hidden = false;
      if ($('#dmNoteField')) $('#dmNoteField').hidden = true;
      dmReason.value = '';
    } else {
      if ($('#dmReasonField')) $('#dmReasonField').hidden = true;
      if ($('#dmNoteField')) $('#dmNoteField').hidden = false;
      if (dmNoteLabel) dmNoteLabel.textContent = action.reasonLabel || '备注（写入审计日志）';
      if (dmNote) { dmNote.value = ''; dmNote.placeholder = action.reasonPlaceholder || ''; }
    }
    dmConfirm.textContent = action.confirmLabel || '确认执行';
    syncDmConfirm();
    dmMask.classList.add('open');
    if (action.reasonMode === 'select') { if (dmReason) dmReason.focus(); }
    else if (dmNote) { dmNote.focus(); }
  }
  function closeModal() {
    if (!dmMask) return;
    dmMask.classList.remove('open');
    dmAction = null;
  }

  /* 审计编号格式化：AUD-{YYYYMMDD}-{id}（与后端 AdminActivityLog 一致） */
  function formatAudit(id) {
    if (!id) return '';
    var d = new Date();
    var ymd = '' + d.getFullYear() +
      ('0' + (d.getMonth() + 1)).slice(-2) + ('0' + d.getDate()).slice(-2);
    return 'AUD-' + ymd + '-' + id;
  }

  /* 执行写动作（真实端点）；成功/失败均用持久横幅反馈，失败横幅带真实审计编号 */
  function runAction(action, value) {
    var body = action.buildBody ? action.buildBody(value) : (value ? { reason: value } : {});
    apiSend(action.method || 'POST', action.endpoint, body).then(function (res) {
      if (!res) { showBanner(false, '网络异常，暂时无法确认操作结果，请刷新核对后再操作', ''); return; }
      var audit = (res.json && res.json.audit_id) ? formatAudit(res.json.audit_id) : '';
      if (res.status >= 200 && res.status < 300) {
        showBanner(true, action.okMsg || '操作成功，状态已更新', audit);
        reloadAfter(action);
      } else {
        var msg = (res.json && res.json.message)
          ? res.json.message
          : ('操作未成功（HTTP ' + res.status + '），状态未变更');
        showBanner(false, msg, audit);
      }
    }).catch(function () { showBanner(false, '网络异常，暂时无法确认操作结果，请刷新核对后再操作', ''); });
  }
  function reloadAfter(action) {
    if (action.key === 'pause' || action.key === 'recover') loadTenants();
    else if (action.key === 'sw-disable' || action.key === 'sw-enable') loadSensitiveWords();
  }
  function fireAction(action) { runAction(action, ''); }

  /* ---------------- 写动作定义（端点已就绪） ---------------- */
  function actPause(subdomain, name, plan, until) {
    var who = name || subdomain;
    return {
      key: 'pause', title: '暂停账号 · <span class="dm-tenant">' + esc(who) + '</span>',
      endpoint: '/api/v1/platform/tenants/' + encodeURIComponent(subdomain) + '/pause',
      reasonMode: 'select', reasonRequired: true,
      confirmLabel: '暂停账号', showImpact: true, subject: who, plan: plan, until: until,
      buildBody: function (v) { return { reason: v }; },
      okMsg: '已暂停租户「' + esc(who) + '」，执行与分发已停止'
    };
  }
  function actRecover(subdomain, name) {
    var who = name || subdomain;
    return {
      key: 'recover', title: '恢复账号 · <span class="dm-tenant">' + esc(who) + '</span>',
      endpoint: '/api/v1/platform/tenants/' + encodeURIComponent(subdomain) + '/recover',
      reasonMode: 'text', reasonRequired: true,
      reasonLabel: '恢复原因（必填，写入审计日志）', reasonPlaceholder: '如：欠费已补缴 / 违规已整改',
      confirmLabel: '恢复账号', showImpact: false, subject: who,
      buildBody: function (v) { return { reason: v }; },
      okMsg: '已恢复租户「' + esc(who) + '」，服务已重新开通'
    };
  }
  function showBanner(ok, html, auditCode) {
    var banner = $('#opBanner');
    if (!banner) return;
    var obIcon = banner.querySelector('.ob-icon');
    if (obIcon) obIcon.textContent = ok ? '✓' : '!';
    $('#opBannerBody').innerHTML = html;
    var a = $('#opBannerAudit');
    a.textContent = auditCode ? '审计编号 ' + auditCode : '（已记录审计日志）';
    a.onclick = auditCode ? function () { copyText(auditCode); } : null;
    banner.classList.remove('ok', 'bad');
    banner.classList.add(ok ? 'ok' : 'bad', 'show');
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
  function copyText(txt) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(txt).then(function () { toast('已复制审计编号 ' + txt); }, function () { toast(txt); });
    } else { toast(txt); }
  }

  /* ---------------- 数据层：写端点（无 CSRF，api 中间件组不含 VerifyCsrfToken）+ Bearer 鉴权 ---------------- */
  function apiSend(method, path, body) {
    var opts = {
      method: method, credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token() }
    };
    if (body !== undefined) opts.body = JSON.stringify(body);
    return fetch(path, opts).then(function (r) {
      if (r.status === 401) { handleUnauthorized(); return { status: r.status, json: null }; }
      if (r.status === 403) { showGuardState('forbidden'); return { status: r.status, json: null }; }
      return r.json().then(function (j) { return { status: r.status, json: j }; },
        function () { return { status: r.status, json: null }; });
    }).catch(function () { return null; });
  }
  function apiPost(path, body) { return apiSend('POST', path, body || {}); }

  /* ===================================================================
     平台敏感词基线库（T8）：试算闸门 + 词表 + 按词聚合 + 命中日志
     红线：保存前必须先跑试算（syncWordSaveGate）；平台词命中即拦，
     且租户无法自行停用，误伤代价全部由平台承担，不能「盲加」。
     =================================================================== */
  var __lastDryRunId = null;
  var __lastDryRunTimer = null;
  var __dryRunAttempts = 0;

  /* 拆词（仅用于前端预估条数 + 空值提示）；真正拆词仍由后端做，两侧规则一致。 */
  function splitSwCandidates(text) {
    if (!text) return [];
    var out = [];
    text.split(/[\r\n,，、;；]+/).forEach(function (p) {
      p = (p || '').trim();
      if (p && out.indexOf(p) < 0) out.push(p);
    });
    return out;
  }

  /* 列表：带筛选；渲染同时刷新版本 pill 与生效计数 */
  function loadSensitiveWords() {
    var cat = ($('#swFilterCategory') || {}).value || '';
    var en = ($('#swFilterEnabled') || {}).value || '';
    var q = ($('#swSearch') || {}).value || '';
    var qs = [];
    if (cat) qs.push('category=' + encodeURIComponent(cat));
    if (en !== '') qs.push('is_enabled=' + encodeURIComponent(en));
    if (q) qs.push('q=' + encodeURIComponent(q));
    apiGet('/api/v1/platform/sensitive-words' + (qs.length ? '?' + qs.join('&') : ''))
      .then(function (d) {
        if (!d) return;
        renderSensitiveWords(d.data || []);
        var ver = $('#swVersion');
        if (ver) ver.textContent = '版本 ' + (d.rule_cache_version != null ? d.rule_cache_version : '—');
        var enEl = $('#swEnabledCount');
        if (enEl) { enEl.hidden = false; enEl.textContent = '生效 ' + fmtInt(d.enabled_total || 0); }
      })
      .catch(function () { showError('#swBody', 7, loadSensitiveWords, '平台词加载失败'); });
  }

  function renderSensitiveWords(rows) {
    var body = $('#swBody');
    if (!body) return;
    if (!rows.length) { body.innerHTML = emptyRow(7, '暂无平台敏感词（基线库为空）'); return; }
    body.innerHTML = rows.map(function (w) {
      var enabled = !!w.is_enabled;
      var toggle = enabled
        ? '<button class="btn ghost" data-act="sw-disable" data-id="' + w.id + '" data-word="' + esc(w.word) + '">停用</button>'
        : '<button class="btn ghost" data-act="sw-enable" data-id="' + w.id + '" data-word="' + esc(w.word) + '">启用</button>';
      return '<tr>' +
        '<td>' + esc(w.word) + '</td>' +
        '<td><span class="pill ' + (w.severity === 'blocked' ? 'bad' : 'wait') + '">' + esc(w.severity) + '</span></td>' +
        '<td>' + esc(w.category) + '</td>' +
        '<td>' + (enabled ? '<span class="pill ok">生效中</span>' : '<span class="pill neutral">已停用</span>') + '</td>' +
        '<td>' + esc(w.note || w.suggestion || '') + '</td>' +
        '<td>' + fmtWhen(w.updated_at) + '</td>' +
        '<td class="cell-actions">' + toggle + '</td>' +
      '</tr>';
    }).join('');
  }

  /* 按词聚合（默认命中篇数倒序） */
  function loadWordSummary() {
    var days = ($('#swSummaryDays') || {}).value || '';
    apiGet('/api/v1/platform/sensitive-word-hits/summary' + (days ? '?days=' + encodeURIComponent(days) : ''))
      .then(function (d) {
        if (!d) return;
        renderWordSummary(d.data || []);
      })
      .catch(function () { showError('#swSummaryBody', 7, loadWordSummary, '聚合数据加载失败'); });
  }

  function renderWordSummary(rows) {
    var body = $('#swSummaryBody');
    if (!body) return;
    if (!rows.length) { body.innerHTML = emptyRow(7, '该时间窗内暂无命中记录'); return; }
    body.innerHTML = rows.map(function (r) {
      var view = '<button class="btn ghost" data-act="sw-viewhits" data-word="' + esc(r.word) + '">看命中</button>';
      return '<tr>' +
        '<td>' + esc(r.word) + '</td>' +
        '<td><span class="pill ' + (r.origin === 'platform' ? 'bad' : 'info') + '">' + (r.origin === 'platform' ? '平台基线' : '租户自加') + '</span></td>' +
        '<td>' + fmtInt(r.article_count) + '</td>' +
        '<td>' + fmtInt(r.tenant_count) + '</td>' +
        '<td>' + fmtInt(r.hit_count) + '</td>' +
        '<td>' + fmtWhen(r.last_hit_at) + '</td>' +
        '<td class="cell-actions">' + view + '</td>' +
      '</tr>';
    }).join('');
  }

  /* 命中日志（给定文章 ID 必须能答出「被哪条词拦的」） */
  function loadWordHits() {
    var art = ($('#swHitArticle') || {}).value || '';
    var word = ($('#swHitWord') || {}).value || '';
    var origin = ($('#swHitOrigin') || {}).value || '';
    var qs = [];
    if (art) qs.push('article_id=' + encodeURIComponent(art));
    if (word) qs.push('word=' + encodeURIComponent(word));
    if (origin) qs.push('origin=' + encodeURIComponent(origin));
    apiGet('/api/v1/platform/sensitive-word-hits' + (qs.length ? '?' + qs.join('&') : ''))
      .then(function (d) {
        if (!d) return;
        renderWordHits(d.data || []);
        var note = $('#swHitRetention');
        if (note) note.textContent = '命中日志保留 ' + (d.retention_days != null ? d.retention_days : '—') + ' 天（合规留存，到期自动清理）。';
      })
      .catch(function () { showError('#swHitBody', 8, loadWordHits, '命中日志加载失败'); });
  }

  function renderWordHits(rows) {
    var body = $('#swHitBody');
    if (!body) return;
    if (!rows.length) { body.innerHTML = emptyRow(8, '无命中记录'); return; }
    body.innerHTML = rows.map(function (h) {
      var ctx = h.context || '';
      if (ctx.length > 80) ctx = ctx.slice(0, 80) + '…';
      return '<tr>' +
        '<td>' + fmtWhen(h.created_at) + '</td>' +
        '<td>' + esc(h.tenant_name || ('租户 #' + h.tenant_id)) + '</td>' +
        '<td>' + (h.article_id != null ? '#' + h.article_id : '—') + '</td>' +
        '<td>' + esc(h.word) + '</td>' +
        '<td><span class="pill ' + (h.origin === 'platform' ? 'bad' : 'info') + '">' + (h.origin === 'platform' ? '平台' : '租户') + '</span></td>' +
        '<td>' + esc(h.surface || '') + '</td>' +
        '<td>' + esc(h.trigger || '') + '</td>' +
        '<td title="' + esc(h.context || '') + '">' + esc(ctx) + '</td>' +
      '</tr>';
    }).join('');
  }

  /* 试算闸门：先跑 dry-run，结果回来之前「保存」禁用 */
  function submitDryRun() {
    var bulk = ($('#swBulk') || {}).value || '';
    var cand = splitSwCandidates(bulk);
    if (!cand.length) { toast('请先填入至少一个候选词'); return; }
    if (cand.length > 200) { toast('一次最多试算 200 个候选词，请分批'); return; }
    syncWordSaveGate(false);
    var box = $('#swDryResult'); if (box) box.hidden = false;
    var title = $('#swDryTitle'); if (title) title.textContent = '试算中…（扫描当前工作区，可能需要一会儿）';
    var meta = $('#swDryMeta'); if (meta) meta.textContent = cand.length + ' 个候选词';
    var body = $('#swDryBody'); if (body) body.innerHTML = '<div class="st-icon">○</div><p>已入队，正在扫描当前工作区文章…</p>';

    apiPost('/api/v1/platform/sensitive-words/dry-run', { words: cand.join('\n') })
      .then(function (res) {
        if (!res) { showDryRunError('网络异常，试算未启动'); return; }
        if (res.status !== 202 || !res.json || !res.json.data) {
          var msg = (res.json && res.json.message) ? res.json.message : ('试算未启动（HTTP ' + res.status + '）');
          showDryRunError(msg);
          return;
        }
        __lastDryRunId = res.json.data.id;
        __dryRunAttempts = 0;
        pollDryRun(res.json.data.id);
      });
  }

  function showDryRunError(msg) {
    syncWordSaveGate(false);
    var box = $('#swDryResult'); if (box) box.hidden = false;
    var title = $('#swDryTitle'); if (title) title.textContent = '试算失败';
    var body = $('#swDryBody');
    if (body) body.innerHTML = '<div class="state-tpl"><div class="st-icon">!</div><p>' + esc(msg) + '</p></div>';
  }

  function pollDryRun(id) {
    if (__lastDryRunTimer) clearTimeout(__lastDryRunTimer);
    if (__dryRunAttempts++ > 60) {
      showDryRunError('试算超过预期时间未完成（约 90 秒）。请确认队列 worker 正在运行后重试。');
      return;
    }
    apiGet('/api/v1/platform/sensitive-words/dry-run/' + encodeURIComponent(id))
      .then(function (d) {
        if (!d || !d.data) { showDryRunError('试算结果读取失败'); return; }
        var p = d.data;
        if (p.status === 'pending' || p.status === 'running') {
          __lastDryRunTimer = setTimeout(function () { pollDryRun(id); }, 1500);
          return;
        }
        if (p.status === 'failed') {
          showDryRunError('试算执行失败：' + esc(p.error || '未知错误'));
          return;
        }
        renderDryRun(p);
        syncWordSaveGate(true);
      })
      .catch(function () { showDryRunError('试算结果读取失败'); });
  }

  function renderDryRun(p) {
    var box = $('#swDryResult'); if (box) box.hidden = false;
    var title = $('#swDryTitle'); if (title) title.textContent = '当前工作区试算完成';
    var meta = $('#swDryMeta');
    if (meta) meta.textContent = '扫描 ' + fmtInt(p.articles_scanned) + ' 篇' +
      (p.articles_skipped ? '，跳过 ' + fmtInt(p.articles_skipped) + ' 篇' : '') +
      '，命中 ' + fmtInt(p.hit_articles) + ' 篇';
    var body = $('#swDryBody');
    if (!body) return;
    var html = '';
    var byWord = p.by_word || [];
    if (byWord.length) {
      html += '<div class="sw-dry-sub">按候选词（命中 ' + byWord.length + ' 个）</div>';
      html += '<table class="tbl"><thead><tr><th>词</th><th>命中篇数</th><th>命中工作区数</th><th>样本文章</th></tr></thead><tbody>';
      html += byWord.map(function (w) {
        var samples = (w.samples || []).slice(0, 3).map(function (s) {
          return '#' + s.article_id + (s.field ? '(' + s.field + ')' : '');
        }).join('、');
        return '<tr><td>' + esc(w.word) + '</td><td>' + fmtInt(w.hit_articles) + '</td><td>' + fmtInt(w.hit_tenants) + '</td><td>' + (samples || '—') + '</td></tr>';
      }).join('');
      html += '</tbody></table>';
    } else {
      html += '<p class="sw-dry-empty">候选词均未命中当前工作区文章。</p>';
    }
    var byTenant = p.by_tenant || [];
    if (byTenant.length) {
      html += '<div class="sw-dry-sub">当前工作区</div>';
      html += '<table class="tbl"><thead><tr><th>租户</th><th>命中篇数</th></tr></thead><tbody>';
      html += byTenant.map(function (t) {
        return '<tr><td>' + esc(t.tenant_name || ('租户 #' + t.tenant_id)) + '</td><td>' + fmtInt(t.hit_articles) + '</td></tr>';
      }).join('');
      html += '</tbody></table>';
    }
    body.innerHTML = html;
  }

  /* 保存闸门：没跑过试算 / 试算未完成 → 禁用 */
  function syncWordSaveGate(allowed) {
    var btn = $('#swSaveBtn');
    if (btn) btn.disabled = !allowed;
  }

  /* 保存：复用后端批量拆词；severity/category 取加词区选择 */
  function saveWords() {
    var bulk = ($('#swBulk') || {}).value || '';
    var severity = ($('#swSeverity') || {}).value || 'blocked';
    var category = ($('#swCategory') || {}).value || 'politics';
    var cand = splitSwCandidates(bulk);
    if (!cand.length) { toast('请先填入至少一个候选词'); return; }
    var btn = $('#swSaveBtn'); if (btn) btn.disabled = true;
    apiPost('/api/v1/platform/sensitive-words', { words: cand.join('\n'), severity: severity, category: category })
      .then(function (res) {
        if (!res) { toast('网络异常，未保存'); if (btn) btn.disabled = false; return; }
        if (res.status === 201 || res.status === 200) {
          var ins = (res.json && res.json.inserted) || 0;
          var upd = (res.json && res.json.updated) || 0;
          toast('已保存到基线库：新增 ' + ins + ' / 更新 ' + upd);
          syncWordSaveGate(false);
          loadSensitiveWords();
        } else {
          var msg = (res.json && res.json.message) ? res.json.message : ('保存未成功（HTTP ' + res.status + '）');
          toast(msg);
          if (btn) btn.disabled = false;
        }
      });
  }

  /* 停用 / 启用：走模态确认（不消灭审计），写后重拉列表 */
  function actSwDisable(id, word) {
    return {
      key: 'sw-disable', title: '停用平台敏感词 · <span class="dm-tenant">' + esc(word) + '</span>',
      endpoint: '/api/v1/platform/sensitive-words/' + encodeURIComponent(id),
      method: 'DELETE', reasonMode: 'none', showImpact: false,
      buildBody: function () { return {}; },
      okMsg: '已停用平台敏感词「' + esc(word) + '」（记录保留，可随时重新启用）'
    };
  }
  function actSwEnable(id, word) {
    return {
      key: 'sw-enable', title: '重新启用平台敏感词 · <span class="dm-tenant">' + esc(word) + '</span>',
      endpoint: '/api/v1/platform/sensitive-words/' + encodeURIComponent(id),
      method: 'PATCH', reasonMode: 'none', showImpact: false,
      buildBody: function () { return { is_enabled: true }; },
      okMsg: '已重新启用平台敏感词「' + esc(word) + '」，重新生效'
    };
  }

  /* ===================================================================
     动作分发：点击 → 真实端点（B3/B4/B5 均已就绪）
     =================================================================== */
  function bindActions() {
    document.addEventListener('click', function (e) {
      var btn = e.target.closest && e.target.closest('[data-act]');
      if (!btn) return;
      var act = btn.getAttribute('data-act');
      var tenant = btn.getAttribute('data-tenant') || '';
      var id = btn.getAttribute('data-id') || '';
      switch (act) {
        case 'suspend':
          openModal(actPause(tenant, btn.getAttribute('data-name'), btn.getAttribute('data-plan'), btn.getAttribute('data-until')));
          break;
        case 'resume':
          openModal(actRecover(tenant, btn.getAttribute('data-name')));
          break;
        case 'sw-disable':
          if (!id) { toast('缺少平台词 ID'); break; }
          openModal(actSwDisable(id, btn.getAttribute('data-word') || ('#' + id)));
          break;
        case 'sw-enable':
          if (!id) { toast('缺少平台词 ID'); break; }
          openModal(actSwEnable(id, btn.getAttribute('data-word') || ('#' + id)));
          break;
        case 'sw-viewhits': {
          var sw = btn.getAttribute('data-word') || '';
          var hi = $('#swHitWord'); if (hi) hi.value = sw;
          loadWordHits();
          if (sw) toast('已按「' + sw + '」过滤命中日志');
          break;
        }
        default:
          toast('未知操作：' + act);
      }
    });
  }

  /* ===================================================================
     分区跳转高亮（scroll spy）
     =================================================================== */
  function initNavSpy() {
    var links = $$('.section-nav a');
    // Keep panels mounted: navigation must not clear pending input or dry-run state.
    function selectSection(focus) {
      var requested = window.location.hash;
      var active = links.filter(function (link) { return link.getAttribute('href') === requested; })[0] || links[0];
      if (!active) return;
      links.forEach(function (link) {
        var selected = link === active;
        link.classList.toggle('on', selected);
        if (selected) link.setAttribute('aria-current', 'page');
        else link.removeAttribute('aria-current');
        var panel = document.getElementById(link.getAttribute('href').slice(1));
        if (!panel) return;
        panel.hidden = !selected;
        if (selected && focus) {
          var heading = panel.querySelector('h2');
          if (heading) { heading.setAttribute('tabindex', '-1'); heading.focus(); }
        }
      });
    }
    window.addEventListener('hashchange', function () { selectSection(true); });
    selectSection(false);
  }

  /* ===================================================================
     主流程
     =================================================================== */
  /* ===================================================================
     主流程（方案 B：先 session-login 守卫，再拉真实数据；失败显式错误态）
     =================================================================== */
  function loadAll() {
    loadHealth();
    loadQueueHealth();
    loadTenants();
    loadSensitiveWords();
    loadWordSummary();
    loadWordHits();
    loadAudit();
    loadSecuritySettings();
  }

  /* 钱流总览 + 付款异常（commercial 端点） */
  /* 系统健康（health 端点） */
  function loadHealth() {
    apiGet('/api/v1/platform/health').then(function (d) {
      __lastHealth = d;
      renderHealth(d);
      if (__lastTenants) renderRisk(__lastTenants);
    }).catch(function () { showError('#healthRow', 0, loadHealth, '健康数据加载失败'); });
  }

  /* 队列健康（queue-health 端点，单 9） */
  function loadQueueHealth() {
    apiGet('/api/v1/platform/queue-health').then(function (d) {
      if (!d) return;
      renderQueueHealth(d);
    }).catch(function (e) {
      // 面板开关关着时端点返回 404（红线 8 门控），静默跳过，不污染其它区块。
      if (e && e.message === 'HTTP 404') return;
      showError('#queueWorkers', 5, loadQueueHealth, '队列健康数据加载失败');
    });
  }

  /* 租户订阅 + 风险租户（tenants 端点） */
  function loadTenants() {
    apiGet('/api/v1/platform/tenants').then(function (d) {
      if (!d) return;
      __lastTenants = d;
      renderSubs(d);
      renderRisk(d);
    }).catch(function () {
      showError('#subsBody', 6, loadTenants, '当前工作区数据加载失败');
      showError('#riskBody', 4, loadTenants, '工作区风险加载失败');
    });
  }

  var __auditPage = 1;
  var __auditLastPage = 1;

  function auditQuery() {
    var qs = ['page=' + __auditPage];
    var per = ($('#auditPerPage') || {}).value;
    if (per) qs.push('per_page=' + encodeURIComponent(per));
    var action = ($('#auditAction') || {}).value;
    if (action) qs.push('action=' + encodeURIComponent(action.trim()));
    var target = ($('#auditTargetType') || {}).value;
    if (target) qs.push('target_type=' + encodeURIComponent(target.trim()));
    var from = ($('#auditFrom') || {}).value;
    if (from) qs.push('from=' + encodeURIComponent(from));
    var to = ($('#auditTo') || {}).value;
    if (to) qs.push('to=' + encodeURIComponent(to));
    return qs.join('&');
  }

  function auditDetails(row) {
    var details = row && row.details;
    if (details == null || details === '') return '—';
    if (typeof details === 'string') return details;
    try {
      var text = JSON.stringify(details);
      return text.length > 160 ? text.slice(0, 160) + '…' : text;
    } catch (e) {
      return '—';
    }
  }

  function renderAudit(rows) {
    var body = $('#auditBody');
    if (!body) return;
    if (!rows.length) {
      body.innerHTML = emptyRow(6, '当前筛选条件下没有审计记录。');
      return;
    }
    body.innerHTML = rows.map(function (r) {
      var target = r.target_type
        ? esc(r.target_type) + (r.target_id != null ? ' #' + esc(r.target_id) : '')
        : '—';
      return '<tr>'
        + '<td class="cell-sub">' + esc(fmtWhen(r.created_at)) + '</td>'
        + '<td class="cell-main">' + esc(r.action || '—') + '</td>'
        + '<td>' + esc(r.actor_name || r.actor_type || '—') + '</td>'
        + '<td class="cell-sub">' + (r.tenant_id == null ? '<span class="cell-sub">平台</span>' : esc('租户 #' + r.tenant_id)) + '</td>'
        + '<td class="cell-sub">' + target + '</td>'
        + '<td class="cell-mono">' + esc(auditDetails(r)) + '</td>'
        + '</tr>';
    }).join('');
  }

  function loadAudit() {
    if (!$('#auditBody')) return;
    $('#auditBody').innerHTML = emptyRow(6, '正在加载审计日志…');
    apiGet('/api/v1/platform/audit?' + auditQuery()).then(function (d) {
      if (!d) return;
      var meta = d.meta || {};
      __auditLastPage = Number(meta.last_page || 1);
      renderAudit(Array.isArray(d.data) ? d.data : []);
      var metaEl = $('#auditMeta');
      if (metaEl) {
        metaEl.textContent = '共 ' + fmtInt(meta.total || 0) + ' 条 · 第 '
          + fmtInt(meta.current_page || 1) + ' / ' + fmtInt(__auditLastPage) + ' 页';
      }
      var prev = $('#auditPrevBtn');
      var next = $('#auditNextBtn');
      if (prev) prev.disabled = __auditPage <= 1;
      if (next) next.disabled = __auditPage >= __auditLastPage;
    }).catch(function () { showError('#auditBody', 6, loadAudit, '审计日志加载失败'); });
  }

  /* ===================================================================
     平台安全设置（2026-09-21 补接线，计划 §5）
     端点 GET/PUT /api/v1/platform/security-settings。
     红线：留空的字段不提交 —— PUT 是"合并"语义，但把空数组当成"清空词表"是
     操作员最容易误触的破坏动作，所以敏感词为空时整项不发。
     =================================================================== */

  /* ===================================================================
     对账待办与人工复核（计划 §9.6，2026-09-21 补接线）
     端点：GET /api/v1/platform/reconciliations
           POST .../verify（读网关账本重新核验，只写差异待办）
           POST .../{finding_key}/review（落结论：reviewed / waived / escalated）
     红线（与命令口径一致）：**这里只落"谁/何时/依据什么/下了什么结论"，
     不做任何资金写操作**。真正的冲正必须带着这条结论去网关侧的资金复核流程执行 ——
     在后台直接改数就是第二套账本，正是这一轮要消灭的东西。
     落点选平台运营台（不是账户与用量页）：待办是平台侧资金问题，租户看到只会引发
     无法自助解决的恐慌；同页已有钱流总览/付款异常/发票队列，复核上下文都在手边。
     =================================================================== */
  function loadSecuritySettings() {
    var host = $('#secPasswordMin');
    if (!host) return;
    apiGet('/api/v1/platform/security-settings').then(function (d) {
      if (!d || !d.settings) return;
      var s = d.settings;
      if ($('#secPasswordMin')) $('#secPasswordMin').value = s.password_min_length != null ? s.password_min_length : '';
      if ($('#secRequireSpecial')) $('#secRequireSpecial').value = s.require_special_char ? '1' : '0';
      if ($('#secRateLimit')) $('#secRateLimit').value = s.api_rate_limit_per_min != null ? s.api_rate_limit_per_min : '';
      var saved = $('#secSavedAt');
      if (saved) {
        saved.textContent = '当前生效：密码 ≥ ' + (s.password_min_length != null ? s.password_min_length : '—')
          + ' 位 · 特殊字符 ' + (s.require_special_char ? '必需' : '不要求')
          + ' · 接口限流 ' + (s.api_rate_limit_per_min != null ? s.api_rate_limit_per_min : '—') + ' 次/分钟';
      }
    }).catch(function () {
      var note = $('#secNote');
      if (note) note.textContent = '安全设置读取失败，保存按钮已停用；刷新后重试。';
      var save = $('#secSaveBtn');
      if (save) save.disabled = true;
    });
  }

  function saveSecuritySettings() {
    var body = {};
    var minLen = ($('#secPasswordMin') || {}).value;
    if (minLen !== '' && minLen != null) body.password_min_length = Number(minLen);
    var special = ($('#secRequireSpecial') || {}).value;
    if (special === '0' || special === '1') body.require_special_char = special === '1';
    var rate = ($('#secRateLimit') || {}).value;
    if (rate !== '' && rate != null) body.api_rate_limit_per_min = Number(rate);
    var words = ($('#secSensitiveWords') || {}).value || '';
    var list = words.split(/[\r\n,，、;；]+/).map(function (w) { return w.trim(); }).filter(Boolean);
    if (list.length) body.sensitive_words = list;

    if (!Object.keys(body).length) {
      showBanner(false, '没有任何需要保存的改动（敏感词留空表示不改动）。', '');
      return;
    }

    var btn = $('#secSaveBtn');
    if (btn) btn.disabled = true;
    apiSend('PUT', '/api/v1/platform/security-settings', body).then(function (res) {
      if (btn) btn.disabled = false;
      if (!res) { showBanner(false, '网络异常，暂时无法确认是否保存成功，请刷新核对后再操作。', ''); return; }
      var audit = (res.json && res.json.audit_id) ? formatAudit(res.json.audit_id) : '';
      if (res.status >= 200 && res.status < 300) {
        showBanner(true, '安全设置已保存并生效。', audit);
        if ($('#secSensitiveWords')) $('#secSensitiveWords').value = '';
        loadSecuritySettings();
      } else {
        var msg = (res.json && res.json.message) ? res.json.message : ('HTTP ' + res.status);
        showBanner(false, '保存失败：' + esc(msg), audit);
      }
    });
  }

  function init() {
    initModal();
    bindActions();
    initNavSpy();
    var closeBtn = $('#opBannerClose');
    if (closeBtn) closeBtn.addEventListener('click', function () { $('#opBanner').classList.remove('show'); });
    var rf = $('#refreshBtn');
    if (rf) rf.addEventListener('click', loadAll);
    var qrf = $('#queueRefreshBtn');
    if (qrf) qrf.addEventListener('click', loadQueueHealth);

    var swDry = $('#swDryRunBtn'); if (swDry) swDry.addEventListener('click', submitDryRun);
    var swSave = $('#swSaveBtn'); if (swSave) swSave.addEventListener('click', saveWords);
    var swReload = $('#swReloadBtn'); if (swReload) swReload.addEventListener('click', loadSensitiveWords);
    var swCat = $('#swFilterCategory'); if (swCat) swCat.addEventListener('change', loadSensitiveWords);
    var swEn = $('#swFilterEnabled'); if (swEn) swEn.addEventListener('change', loadSensitiveWords);
    var swQ = $('#swSearch'); if (swQ) swQ.addEventListener('input', loadSensitiveWords);
    var swDays = $('#swSummaryDays'); if (swDays) swDays.addEventListener('change', loadWordSummary);
    var swHit = $('#swHitReloadBtn'); if (swHit) swHit.addEventListener('click', loadWordHits);

    // 审计日志 / 安全设置（2026-09-21 补接线）
    var aApply = $('#auditApplyBtn');
    if (aApply) aApply.addEventListener('click', function () { __auditPage = 1; loadAudit(); });
    var aRefresh = $('#auditRefreshBtn'); if (aRefresh) aRefresh.addEventListener('click', loadAudit);
    var aPrev = $('#auditPrevBtn');
    if (aPrev) aPrev.addEventListener('click', function () { if (__auditPage > 1) { __auditPage -= 1; loadAudit(); } });
    var aNext = $('#auditNextBtn');
    if (aNext) aNext.addEventListener('click', function () { if (__auditPage < __auditLastPage) { __auditPage += 1; loadAudit(); } });
    var aAction = $('#auditAction');
    if (aAction) aAction.addEventListener('keydown', function (e) { if (e.key === 'Enter') { __auditPage = 1; loadAudit(); } });
    var aTarget = $('#auditTargetType');
    if (aTarget) aTarget.addEventListener('keydown', function (e) { if (e.key === 'Enter') { __auditPage = 1; loadAudit(); } });
    var sReload = $('#secReloadBtn'); if (sReload) sReload.addEventListener('click', loadSecuritySettings);
    var sSave = $('#secSaveBtn'); if (sSave) sSave.addEventListener('click', saveSecuritySettings);

    bootstrap().then(loadAll).catch(function (e) {
      if (e !== 'redirect' && e !== 'forbidden') {
        toast('进入运营台失败：' + (e && e.message ? e.message : e));
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
