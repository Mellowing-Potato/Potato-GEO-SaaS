/* ============================================================================
   GEO AI 可见性监测面板 · 交互引擎（geo-visibility.js）
   同构 analytics（方案 B：壳页先渲染 + 前端 session-login 拿 Sanctum token）。
   红线遵守：
     - 仅消费真实端点；任何读接口失败均渲染显式错误态（含重试），绝不回退假数据。
     - 空态要真：某视图无数据即显示「暂无」，不塞假数。
     - 不引 UI 框架 / 打包器 / 图表库。
     - 无新色值字面量（仅用 geo-visibility.css 的 CSS 变量与等价 hex 令牌）。
     - 渲染 sources 时显式处理 published_at === null（V3 降级态要可见，不能崩）。
     - 金额只显示「本次扣费」（客户自己付的）；我们付给上游的进货价不进这个页面。
   ============================================================================ */
(function () {
  'use strict';

  var $  = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /* ---------------- 认证与配置（方案 B） ---------------- */
  var CFG = window.GEO_VISIBILITY_CONFIG || {
    accountLoginUrl: '/login',
    accountUrl: '/#personal-settings',
    maxQuestions: 10
  };
  var MAX_QUESTIONS = Math.max(1, Number(CFG.maxQuestions) || 10);

  function token() { return sessionStorage.getItem('geo_visibility_token') || ''; }
  function pickToken(p) { return (p && (p.accessToken || p.access_token || p.token)) || ''; }
  function persistSession(p) {
    var t = pickToken(p);
    if (!t) return;
    sessionStorage.setItem('geo_visibility_token', t);
    /* session-login 同时返回 user（已 load tenant）与顶层 tenant。两边都收，
       因为 apiReq 必须拿得到 subdomain 才能带 X-Tenant（见下方 tenantSubdomain）。 */
    var u = (p && p.user) || null;
    if (u && !u.tenant && p && p.tenant) { u.tenant = p.tenant; }
    sessionStorage.setItem('geo_visibility_user', JSON.stringify(u));
  }
  /* 生产 TENANT_DOMAIN = 本页所在域名（实例主机名），IdentifyTenant 的
     extractSubdomain 在 host === baseDomain 时只认 X-Tenant / ?tenant=，
     不带就返回 null → abort(404) → 整组 /api/v1/* 全 404。壳页自持 session，
     必须自己带这个头。 */
  function tenantSubdomain() {
    try {
      var u = JSON.parse(sessionStorage.getItem('geo_visibility_user') || '{}');
      return u && u.tenant && u.tenant.subdomain ? String(u.tenant.subdomain) : '';
    } catch (e) { return ''; }
  }
  function visibilityOwner() {
    try {
      var user = JSON.parse(sessionStorage.getItem('geo_visibility_user') || 'null');
      var userId = Number(user && user.id), tenantId = Number(user && user.tenant && user.tenant.id);
      return Number.isSafeInteger(userId) && userId > 0 && Number.isSafeInteger(tenantId) && tenantId > 0
        ? { userId: userId, tenantId: tenantId } : null;
    } catch (e) { return null; }
  }
  function who() {
    try {
      var u = JSON.parse(sessionStorage.getItem('geo_visibility_user') || '{}') || {};
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
      ? '当前账号没有 AI 可见性监测面板权限（visibility:view）。如有疑问请联系系统管理员。'
      : (kind === 'error' ? '身份校验失败，请重新登录后重试。' : '正在校验可见性面板访问权限');
    var link = $('#guardLink');
    if (link) {
      if (kind === 'forbidden' || kind === 'error') { link.hidden = false; link.href = CFG.accountUrl; }
      else { link.hidden = true; }
    }
  }
  function hideGuard() { var el = $('#guardState'); if (el) el.hidden = true; }

  function handleUnauthorized() {
    sessionStorage.removeItem('geo_visibility_token');
    sessionStorage.removeItem('geo_visibility_user');
    fetch('/api/v1/auth/session-login', { method: 'POST', credentials: 'include', headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('relogin failed'); return r.json(); })
      .then(function (p) { persistSession(p); window.location.reload(); })
      .catch(function () { window.location.href = accountLoginUrl(); });
  }

  /* ---------------- 数据层：真实端点 + Bearer 鉴权（方案 B） ---------------- */

  /* 缺陷 A（派单二 2026-08-31）：403 曾经是个永久结论。
     showGuard('forbidden') 说的是「当前账号没有权限」——这是一句**关于身份的断言**，
     但面板做这个断言时，用的是 sessionStorage 里一份从没核验过的缓存身份：
     退出登录清的是账户中心的 cookie，清不掉这里的这一份。于是浏览器 cookie 里已经是账号 B
     （有权限）、sessionStorage 里还留着账号 A（没权限）的 token，面板拿 A 的 token 吃 403，
     再告诉用户「当前账号没有权限」——「当前账号」是错的。
     401 那条路一直是对的（handleUnauthorized 会用 cookie 重新换一张 token），403 缺同一步。
     与 geo-manual-publish.js 同款写法，改一边必须改另一边（X-Tenant 那次事故的教训）。

     🔴 一次性（两重）：
       ① 核验整页只做一次，且**并发的 403 共用同一次**——页面启动会同时发好几个请求，
          身份陈旧时它们是**一起** 403 的。若各发各的，就是 N 次 session-login；
          若用「已核验过」的布尔量卡，则是第一个去核验、其余直接竖墙——
          正是本缺陷要修的那个墙。
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
      var originalOwner = visibilityOwner();
      reverifyPromise = fetch('/api/v1/auth/session-login', { method: 'POST', credentials: 'include', headers: { 'Accept': 'application/json' } })
        .then(function (r) { if (!r.ok) throw new Error('relogin failed'); return r.json(); })
        .then(function (p) {
          var t = p && (p.accessToken || p.access_token || p.token);
          if (!t) throw new Error('relogin failed');
          var freshUser = p && p.user, freshTenant = p && (p.tenant || (freshUser && freshUser.tenant));
          if (originalOwner && (Number(freshUser && freshUser.id) !== originalOwner.userId || Number(freshTenant && freshTenant.id) !== originalOwner.tenantId)) {
            window.location.reload();
            throw Object.assign(new Error('登录账号已变化，原付费请求待核验。'), { identityChanged: true });
          }
          persistSession(p);
        })
        .catch(function (error) { if (error && error.identityChanged) throw error; handleUnauthorized(); throw new Error('HTTP 401'); });
    }
    return reverifyPromise; // 并发的 403 拿同一个 promise，只打一次核验接口
  }

  function apiReq(path, opts) {
    opts = opts || {};
    var originalOwner = visibilityOwner();
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
          if (opts.idempotencyKey) { h['X-Idempotency-Key'] = opts.idempotencyKey; }
          return h;
        })(),
        body: opts.body || undefined
      }).then(function (r) {
        if (r.status === 401) {
          handleUnauthorized();
          var unauthorized = new Error('HTTP 401');
          unauthorized.status = 401;
          throw unauthorized;
        }
        if (r.status === 403) {
          // soft403：局部权限错不竖整页墙，也不触发重新核验。
          if (opts.soft403) {
            var softForbidden = new Error('HTTP 403');
            softForbidden.status = 403;
            throw softForbidden;
          }
          // 缺陷 A：在断言「当前账号没有权限」之前，先用 cookie 重新核验一次身份
          // （并发 403 共用同一次核验），拿新 token 重试一次；重试后仍然 403，才是真的没权限。
          if (isRetry) {
            showGuard('forbidden');
            var forbidden = new Error('HTTP 403');
            forbidden.status = 403;
            throw forbidden;
          }
          return reverifyOnce().then(function () { return send(true); });
        }
        if (!r.ok) {
          return r.json().catch(function () { return {}; }).then(function (j) {
            var requestError = new Error(j.message || ('HTTP ' + r.status));
            requestError.status = r.status;
            requestError.body = j;
            throw requestError;
          });
        }
        return opts.raw ? r : r.json();
      });
    }
    return send().then(function (result) {
      var currentOwner = visibilityOwner();
      if (originalOwner && (!currentOwner || originalOwner.userId !== currentOwner.userId || originalOwner.tenantId !== currentOwner.tenantId)) {
        throw Object.assign(new Error('登录账号已切换，原请求结果待核验。'), { identityChanged: true });
      }
      return result;
    });
  }
  function apiGet(path) { return apiReq(path); }
  function apiPost(path, body, idempotencyKey) {
    return apiReq(path, {
      method: 'POST',
      body: JSON.stringify(body || {}),
      idempotencyKey: idempotencyKey || null
    });
  }

  function newIdempotencyKey(prefix) {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      return String(prefix || 'geo') + '-' + window.crypto.randomUUID();
    }
    return String(prefix || 'geo') + '-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2);
  }

  // 未确认的付费尝试不会因本地时钟走过 30 分钟而自动变成新请求。
  function idempotencyFingerprintSlot(fingerprint) {
    var first = 2166136261;
    var second = 5381;
    for (var index = 0; index < fingerprint.length; index += 1) {
      var code = fingerprint.charCodeAt(index);
      first = Math.imul(first ^ code, 16777619) >>> 0;
      second = (Math.imul(second, 33) ^ code) >>> 0;
    }
    return fingerprint.length.toString(36) + '-' + first.toString(36) + '-' + second.toString(36);
  }
  function pendingIdempotencyRequest(prefix, payload) {
    var fingerprint = JSON.stringify(payload || {});
    var fingerprintSlot = idempotencyFingerprintSlot(fingerprint);
    var owner = visibilityOwner();
    if (!owner) throw new Error('登录身份未确认，本次未发送付费请求。');
    var namespace = 'geo_pending_idempotency:' + (tenantSubdomain() || 'default') + ':' + prefix + ':';
    // 每份 payload 独占一个不可逆短指纹槽；既不把用户问题写进 key，也不让后续
    // 不同问题覆盖这笔尚未确认的请求。
    var legacyStorageKey = namespace + fingerprintSlot;
    var storageKey = namespace + owner.tenantId + ':' + owner.userId + ':' + fingerprintSlot;
    var previousRaw, legacyRaw;
    try { legacyRaw = sessionStorage.getItem(legacyStorageKey); previousRaw = sessionStorage.getItem(storageKey); }
    catch (e) { throw new Error('无法读取原付费请求编号，本次未发送；请先核对原任务。'); }
    if (legacyRaw !== null) throw new Error('旧版原付费请求缺少账号归属，本次未发送；请先核对原任务。');
    if (previousRaw !== null) {
      var previous;
      try { previous = JSON.parse(previousRaw); } catch (e) { /* 原键可能已发送，不能自动换键。 */ }
      if (!previous || typeof previous.key !== 'string' || !previous.key || previous.fingerprint !== fingerprintSlot || previous.owner_user_id !== owner.userId || previous.owner_tenant_id !== owner.tenantId) {
        throw new Error('原付费请求编号记录损坏或账号不符，本次未发送；请先核对原任务。');
      }
      return { key: previous.key, storageKey: storageKey };
    }

    var request = { key: newIdempotencyKey(prefix), storageKey: storageKey };
    try {
      sessionStorage.setItem(storageKey, JSON.stringify({
        key: request.key,
        owner_user_id: owner.userId,
        owner_tenant_id: owner.tenantId,
        // 只保存短指纹；监测问题原文不进入浏览器存储。
        fingerprint: fingerprintSlot,
        created_at: Date.now()
      }));
      var stored = JSON.parse(sessionStorage.getItem(storageKey) || 'null');
      if (!stored || stored.key !== request.key) {
        throw new Error('stored request key mismatch');
      }
    } catch (e) { throw new Error('无法保存本次付费请求编号，本次未发送；请检查浏览器存储后重试。'); }
    return request;
  }
  function clearPendingIdempotencyRequest(request) {
    if (!request || !request.storageKey || !request.key) return;
    try {
      var current = JSON.parse(sessionStorage.getItem(request.storageKey) || 'null');
      var owner = visibilityOwner();
      if (!owner || !current || current.owner_user_id !== owner.userId || current.owner_tenant_id !== owner.tenantId) return;
      if (!current || !current.key || String(current.key) === String(request.key)) {
        sessionStorage.removeItem(request.storageKey);
      }
    } catch (e) { /* 存储不可用不影响已完成请求。 */ }
  }
  function adoptClaimedVisibilityKey(request, claimedKey) {
    if (!/^[A-Za-z0-9_.:-]{1,160}$/.test(String(claimedKey || ''))) {
      throw new Error('服务端未返回有效的监测请求编号，本次未发送。');
    }
    var owner = visibilityOwner();
    var current;
    try { current = JSON.parse(sessionStorage.getItem(request.storageKey) || 'null'); }
    catch (e) { throw new Error('原监测请求编号无法核对，本次未发送。'); }
    if (!owner || !current || current.key !== request.key
      || current.owner_user_id !== owner.userId || current.owner_tenant_id !== owner.tenantId) {
      throw new Error('监测请求账号或本地编号已变化，本次未发送。');
    }
    if (request.key === claimedKey) return;
    current.key = claimedKey;
    try {
      sessionStorage.setItem(request.storageKey, JSON.stringify(current));
      if (JSON.parse(sessionStorage.getItem(request.storageKey) || 'null').key !== claimedKey) throw new Error('claim not stored');
    } catch (e) { throw new Error('无法保存服务端恢复的监测请求编号，本次未发送。'); }
    request.key = claimedKey;
  }
  function retainPendingIdempotencyRequest(error) {
    var action = String(error && error.body && error.body.action || '');
    var status = Number(error && error.status);
    return !error || !Number.isFinite(status) || status >= 500 || status === 408 || status === 425 || status === 429 || [
      'idempotency_request_in_progress',
      'idempotency_result_pending',
      'idempotency_protection_unavailable'
    ].indexOf(action) !== -1;
  }

  /* ---------------- 当前项目 ---------------- */
  var projectState = { list: [], suggestions: [], suggestionRequest: 0 };
  var visibilitySubmitInFlight = false;
  var submittedRunId = null;
  var submittedBatchState = null;
  var submittedBatchTimer = null;
  var submittedBatchRequestSeq = 0;

  function requestedProjectId() {
    try {
      var value = Number(new URL(window.location.href).searchParams.get('project_id') || 0);
      return Number.isInteger(value) && value > 0 ? value : null;
    } catch (e) { return null; }
  }
  function projectStorageKey() { return 'geo_last_project_id:' + (tenantSubdomain() || 'default'); }
  function rememberedProjectId() {
    try {
      var value = Number(localStorage.getItem(projectStorageKey()) || 0);
      return Number.isInteger(value) && value > 0 ? value : null;
    } catch (e) { return null; }
  }
  function rememberProjectId(projectId) {
    try { localStorage.setItem(projectStorageKey(), String(projectId)); } catch (e) { /* 可禁用存储，不影响本次提交。 */ }
  }
  function projectLabel(project) {
    if (!project) return '';
    var brand = project.brand_name && project.brand_name !== project.name ? ' · ' + project.brand_name : '';
    return String(project.name || '未命名项目') + brand;
  }
  function projectName(projectId) {
    var found = projectState.list.find(function (project) { return Number(project.id) === Number(projectId); });
    return found ? projectLabel(found) : '';
  }
  function syncProjectQuery(projectId) {
    try {
      var url = new URL(window.location.href);
      url.searchParams.set('project_id', String(projectId));
      window.history.replaceState(null, '', url.toString());
    } catch (e) { /* 老浏览器不支持时保留当前地址。 */ }
  }
  function renderProjectChoices(selectedId) {
    var select = $('#projectId'), submit = $('#submitBtn');
    if (!select) return;
    if (!projectState.list.length) {
      select.innerHTML = '<option value="">暂无可用项目</option>';
      select.disabled = true;
      if (submit) submit.disabled = true;
      return;
    }
    select.innerHTML = projectState.list.map(function (project) {
      return '<option value="' + esc(project.id) + '">' + esc(projectLabel(project)) + '</option>';
    }).join('');
    select.value = String(selectedId || projectState.list[0].id);
    select.disabled = false;
    if (submit) submit.disabled = !monitoringConfigured;
    rememberProjectId(select.value);
    syncProjectQuery(select.value);
    loadQuestionSuggestions(select.value);
  }

  function questionLines(value) {
    var seen = {};
    return String(value || '').split('\n').map(function (x) { return x.trim(); }).filter(function (x) {
      if (x === '' || seen[x]) return false;
      seen[x] = true;
      return true;
    });
  }
  function renderQuestionSuggestions() {
    var host = $('#suggestionList'), hint = $('#suggestionHint'), input = $('#questions');
    if (!host) return;
    var selected = {};
    questionLines(input ? input.value : '').forEach(function (question) { selected[question] = true; });
    if (!projectState.suggestions.length) {
      host.innerHTML = '<span class="muted">当前项目暂无可选问题，你仍可在上方手动填写。</span>';
      return;
    }
    host.innerHTML = projectState.suggestions.map(function (item, index) {
      var meta = [item.priority, item.cluster].filter(Boolean).join(' · ');
      var added = Boolean(selected[item.question]);
      var disabled = added || visibilitySubmitInFlight;
      return '<button type="button" class="suggestion-item" data-suggestion-index="' + index + '"' + (disabled ? ' disabled' : '') + '>' +
        (meta ? '<span class="suggestion-meta">' + esc(meta) + '</span>' : '') +
        esc(item.question) + (added ? '（已加入）' : '') + '</button>';
    }).join('');
    if (hint && !hint.textContent) hint.textContent = '点击问题加入上方输入框；读取与填入不调用模型。';
  }
  function updateQuestionCount() {
    var input = $('#questions'), count = $('#questionCount'), submit = $('#submitBtn'), project = $('#projectId');
    var lines = questionLines(input ? input.value : '');
    var total = lines.length;
    var tooMany = total > MAX_QUESTIONS;
    var tooLong = lines.some(function (question) { return question.length > 500; });
    if (count) {
      count.textContent = tooMany
        ? '已填写 ' + total + ' 个问题，超过最多 ' + MAX_QUESTIONS + ' 个的限制，请删减后再提交。'
        : (tooLong
          ? '有问题超过 500 字，请缩短后再提交。'
          : '已填写 ' + total + ' 个问题。提交后每个问题独立执行并记录用量。');
      count.className = 'fld-help cost-help' + (tooMany || tooLong ? ' err' : '');
    }
    if (submit && project && !project.disabled) {
      submit.disabled = !monitoringConfigured || visibilitySubmitInFlight || tooMany || tooLong;
    }
    renderQuestionSuggestions();
  }
  function addSuggestedQuestion(index) {
    var input = $('#questions'), item = projectState.suggestions[index];
    if (visibilitySubmitInFlight || !input || !item || !item.question) return;
    var lines = questionLines(input.value);
    if (lines.indexOf(item.question) === -1) lines.push(item.question);
    input.value = lines.join('\n');
    updateQuestionCount();
    input.focus();
  }
  function loadQuestionSuggestions(projectId) {
    var host = $('#suggestionList'), hint = $('#suggestionHint');
    var requestId = ++projectState.suggestionRequest;
    projectState.suggestions = [];
    if (host) host.innerHTML = '<span class="muted">正在读取问题库…</span>';
    if (hint) hint.textContent = '读取当前项目已有的问题库，不调用模型。';
    apiGet('/api/v1/visibility/question-suggestions?project_id=' + encodeURIComponent(projectId)).then(function (payload) {
      if (requestId !== projectState.suggestionRequest) return;
      projectState.suggestions = Array.isArray(payload && payload.data) ? payload.data : [];
      if (hint) hint.textContent = payload && payload.source === 'intent_report'
        ? '来自本项目最近的意图挖掘报告；点击加入，不调用模型。'
        : '项目还没有完整问题库；以下按项目资料整理，不调用模型。';
      renderQuestionSuggestions();
    }).catch(function (error) {
      if (requestId !== projectState.suggestionRequest) return;
      projectState.suggestions = [];
      if (hint) hint.textContent = '问题库读取失败，你仍可手动填写。';
      if (host) host.innerHTML = '<span class="muted">' + esc(error.message) + '</span>';
    });
  }
  function loadProjectChoices() {
    var select = $('#projectId'), submit = $('#submitBtn'), msg = $('#formMsg');
    if (select) { select.disabled = true; select.innerHTML = '<option value="">正在加载项目…</option>'; }
    if (submit) submit.disabled = true;

    var requested = requestedProjectId();
    return apiGet('/api/v1/projects?per_page=100').then(function (payload) {
      projectState.list = Array.isArray(payload && payload.data) ? payload.data : (Array.isArray(payload) ? payload : []);
      var requestedInList = requested && projectState.list.some(function (project) { return Number(project.id) === requested; });
      if (requested && !requestedInList) {
        return apiGet('/api/v1/projects/' + requested).then(function (project) {
          projectState.list.unshift(project);
          return requested;
        }).catch(function (error) {
          if (error.status === 403 || error.status === 404) return null;
          throw error;
        });
      }
      return requestedInList ? requested : null;
    }).then(function (validRequested) {
      var remembered = rememberedProjectId();
      var rememberedInList = remembered && projectState.list.some(function (project) { return Number(project.id) === remembered; });
      var selected = validRequested || (rememberedInList ? remembered : null) || (projectState.list[0] && projectState.list[0].id);
      renderProjectChoices(selected);
      if (requested && !validRequested && msg) {
        msg.textContent = '项目 #' + requested + ' 在当前登录账号下无法访问。请核对页面右上角的工作区标识，并登录该项目所属账号。' +
          (projectState.list.length ? ' 已选择当前账号下的项目。' : ' 当前账号下暂无项目。');
        msg.className = 'form-msg warn';
      }
    }).catch(function (error) {
      projectState.list = [];
      renderProjectChoices(null);
      if (msg) { msg.textContent = '项目加载失败：' + error.message; msg.className = 'form-msg err'; }
    });
  }

  /* ---------------- 工具函数 ---------------- */
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function fmtInt(n) { if (n == null) return '未提供'; return (Number(n) || 0).toLocaleString('zh-CN'); }
  /* API 金额仍是内部分；页面只换算 GEO 显示积分。未结算不能伪装成零。 */
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
  /* 搜索次数：上游没给真值时这个数是按每问均值兜底估出来的，必须当场标出来——
     否则编出来的 16 和实测的 16 在界面上完全分不出（单 6 收口拍板）。 */
  function fmtSearchCount(n, estimated) {
    var s = fmtInt(n);
    if (!estimated) return s;
    return s + ' <span class="est-tag" title="上游本次未返回真实搜索次数，此处按每问均值估算">估算</span>';
  }
  function fmtDur(sec) {
    sec = Number(sec);
    sec = Number.isFinite(sec) ? Math.max(0, Math.round(sec)) : 0;
    if (sec < 60) return sec + 's';
    var m = Math.floor(sec / 60), s = sec % 60;
    return m + '分' + (s > 0 ? s + '秒' : '');
  }
  function statusPill(st) {
    return ({
      pending: '<span class="pill neutral">排队中</span>',
      running: '<span class="pill warn">运行中</span>',
      reconciliation_required: '<span class="pill warn">待核对</span>',
      completed: '<span class="pill ok">已完成</span>',
      failed: '<span class="pill bad">失败</span>'
    })[st] || ('<span class="pill unknown">' + esc(st || '未知') + '</span>');
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
  function kpi(label, val, isAmt) {
    return '<div class="kpi"><div class="k-num' + (isAmt ? ' amt' : '') + '">' + val + '</div><div class="k-label">' + esc(label) + '</div></div>';
  }

  /* ---------------- 监测历史列表 ---------------- */
  var monitoringConfigured = false;
  var listState = { page: 1, perPage: 20 };
  var listSignature = '';
  var listRequestSeq = 0;
  function listQuery() { return '/api/v1/visibility/runs?per_page=' + listState.perPage + '&page=' + listState.page; }

  function loadList(options) {
    options = options || {};
    var requestSeq = ++listRequestSeq;
    var requestPath = listQuery();
    var body = $('#runListBody');
    if (body && !options.background) tableLoading(body, 7);
    apiGet(requestPath).then(function (d) {
      // 翻页、手动刷新和后台刷新可能同时在途。只允许最后发出的请求更新列表，
      // 避免慢响应把新页或新状态覆盖回旧内容。
      if (requestSeq !== listRequestSeq) return;
      monitoringConfigured = d && d.configured === true;
      var configNotice = $('#monitoringConfigNotice');
      if (configNotice) configNotice.hidden = monitoringConfigured;
      updateQuestionCount();
      var nextSignature = JSON.stringify({ data: d && d.data, meta: d && d.meta });
      if (nextSignature !== listSignature) {
        listSignature = nextSignature;
        renderList(d);
      }
      scheduleListRefresh(d);
    }).catch(function (e) {
      if (requestSeq !== listRequestSeq) return;
      if (options.background) {
        if (!window.__gvListTimer) {
          window.__gvListTimer = setTimeout(function () { window.__gvListTimer = null; loadList({ background: true }); }, 8000);
        }
        return;
      }
      if (body) errorState(body, loadList, '监测历史加载失败：' + e.message);
    });
  }
  function scheduleListRefresh(d) {
    var hasActive = (d && d.data || []).some(runNeedsPolling);
    if (hasActive && !window.__gvListTimer) {
      window.__gvListTimer = setTimeout(function () { window.__gvListTimer = null; loadList({ background: true }); }, 8000);
    }
  }
  function renderList(d) {
    var body = $('#runListBody'), pager = $('#runListPager');
    if (!body) return;
    var list = (d && d.data) || [];
    if (!list.length) {
      body.innerHTML = '<tr><td colspan="7" class="state-td"><div class="state-tpl"><div class="st-icon">○</div><p>暂无监测记录</p><p>在「发起监测」里提出第一个问题开始。</p></div></td></tr>';
      if (pager) pager.innerHTML = '';
      return;
    }
    body.innerHTML = list.map(function (r) {
      return '<tr class="run-row" data-run="' + esc(r.id) + '">' +
        '<td class="tnum">' + esc(r.id) + '</td>' +
        '<td><button type="button" class="run-question">' + esc(r.question || ('查看运行 #' + r.id)) + '</button></td>' +
        '<td>' + statusPill(r.status) + '</td>' +
        '<td class="tnum">' + fmtSearchCount(r.search_call_count, r.search_count_estimated) + '</td>' +
        '<td class="tnum amt">' + fmtInt(r.total_tokens) + '</td>' +
        '<td class="tnum">' + fmtDur(r.duration_seconds) + '</td>' +
        '<td>' + fmtWhen(r.created_at) + '</td>' +
        '</tr>';
    }).join('');
    $$('.run-row', body).forEach(function (tr) {
      tr.addEventListener('click', function () { openDetail(Number(tr.getAttribute('data-run'))); });
    });
    if (pager) {
      var meta = (d && d.meta) || {};
      var cur = Number(meta.current_page) || 1, last = Number(meta.last_page) || 1, total = Number(meta.total) || 0;
      pager.innerHTML = '<span>共 ' + fmtInt(total) + ' 条 · 第 ' + cur + '/' + last + ' 页</span>' +
        '<button class="btn ghost" id="listPrev" ' + (cur <= 1 ? 'disabled' : '') + '>上一页</button>' +
        '<button class="btn ghost" id="listNext" ' + (cur >= last ? 'disabled' : '') + '>下一页</button>';
      var prev = $('#listPrev'), next = $('#listNext');
      if (prev) prev.addEventListener('click', function () { if (listState.page > 1) { listState.page--; loadList(); } });
      if (next) next.addEventListener('click', function () { if (listState.page < last) { listState.page++; loadList(); } });
    }
  }

  /* ---------------- 运行详情（含轮询） ---------------- */
  var pollTimer = null;
  var activeRunId = null;
  var detailRequestSeq = 0;
  var detailSignature = '';
  function runNeedsPolling(run) {
    return Boolean(run && (!run.is_terminal || run.status === 'reconciliation_required'));
  }
  function stopPoll() { if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; } }
  function scheduleDetailPoll(runId) {
    stopPoll();
    pollTimer = setTimeout(function () {
      pollTimer = null;
      openDetail(runId, { background: true });
    }, 5000);
  }

  function openDetail(runId, options) {
    options = options || {};
    runId = Number(runId);
    if (options.background && activeRunId !== runId) return;
    if (!options.background) {
      window.location.hash = '#sec-detail';
      stopPoll();
      activeRunId = runId;
      detailSignature = '';
    }
    var requestSeq = ++detailRequestSeq;
    var host = $('#runDetailHost');
    if (host && !options.background) loading(host);
    var md = $('#downloadMd'), html = $('#downloadHtml');
    if (md && !options.background) { md.hidden = true; md.onclick = function () { download(runId, 'markdown'); }; }
    if (html && !options.background) { html.hidden = true; html.onclick = function () { download(runId, 'html'); }; }
    apiGet('/api/v1/visibility/runs/' + runId).then(function (d) {
      if (requestSeq !== detailRequestSeq || activeRunId !== runId) return;
      var run = d && d.data;
      var nextSignature = JSON.stringify(run || null);
      if (nextSignature !== detailSignature) {
        detailSignature = nextSignature;
        renderDetail(run);
      }
      if (runNeedsPolling(run)) scheduleDetailPoll(run.id);
    }).catch(function (e) {
      if (requestSeq !== detailRequestSeq || activeRunId !== runId) return;
      if (options.background) {
        scheduleDetailPoll(runId);
        return;
      }
      if (host) errorState(host, function () { openDetail(runId); }, '详情加载失败：' + e.message);
    });
  }

  function renderDetail(r) {
    if (!r) return;
    var host = $('#runDetailHost');
    if (!host) return;
    var md = $('#downloadMd'), html = $('#downloadHtml');
    var usage = r.usage || {};
    var sources = (r.sources || []);
    var queries = (r.search_queries || []);

    var statusLine = statusPill(r.status);
    if (r.status === 'failed' || r.status === 'reconciliation_required') {
      statusLine += ' <span class="muted">' + esc(r.error_message || '') + '</span>';
    }

    var srcRows = sources.length ? sources.map(function (s) {
      var pa = s.published_at ? fmtWhen(s.published_at) : '<span class="muted">未知</span>';
      return '<tr>' +
        '<td>' + esc(s.citation_key || '') + '</td>' +
        '<td><a href="' + esc(s.url || '#') + '" target="_blank" rel="noopener">' + esc(s.title || s.url || '未命名') + '</a>' +
        (s.site_name ? ' <small class="muted">· ' + esc(s.site_name) + '</small>' : '') + '</td>' +
        '<td class="muted">' + esc(s.domain || '') + '</td>' +
        '<td>' + pa + '</td>' +
        '</tr>';
    }).join('') : '<tr><td colspan="4" class="state-td"><div class="state-tpl"><div class="st-icon">○</div><p>本次未返回检索参考</p></div></td></tr>';

    var qHtml = queries.length
      ? '<ul class="qlist">' + queries.map(function (q) { return '<li>' + esc(q) + '</li>'; }).join('') + '</ul>'
      : '<p class="muted">无</p>';

    var answerHtml = r.answer_text
      ? '<div class="detail-section"><h3>回答</h3><div class="answer">' + esc(r.answer_text) + '</div></div>'
      : '';

    var progressHtml = '';
    if (!r.is_terminal) {
      progressHtml = '<div class="run-progress-state" role="status">' +
        (r.status === 'pending'
          ? '任务已进入队列，后台会自动开始。你可以离开本页，完成后结果会保留在监测历史。'
          : '正在联网检索并整理回答，通常需 1–4 分钟。你可以离开本页，任务会在后台继续。') +
        '</div>';
    }

    host.innerHTML =
      '<div class="detail-head">' +
        '<div><strong>运行 #' + esc(r.id) + '</strong> · ' + statusLine + '</div>' +
        '<div class="muted">问题：' + esc(r.question || '') + ' · 项目：' + esc(projectName(r.project_id) || ('#' + (r.project_id || ''))) + '</div>' +
      '</div>' +
      progressHtml +
      answerHtml +
      '<div class="detail-section"><h3>本次搜索词（' + queries.length + '）</h3>' + qHtml + '</div>' +
      '<div class="detail-section"><h3>检索参考（' + sources.length + '）</h3>' +
        (sources.length && !r.answer_citations_present
          ? '<p class="muted">上游回答未标出正文引用；下列内容是本次联网检索返回的参考结果，不代表正文逐条采用。</p>'
          : '') +
        '<div class="tbl-wrap"><table class="tbl"><thead><tr>' +
        '<th>返回序号</th><th>标题 / 站点</th><th>域名</th><th>发布时间</th>' +
        '</tr></thead><tbody>' + srcRows + '</tbody></table></div></div>' +
      '<div class="detail-section"><h3>用量与成本</h3><div class="kpi-row">' +
        kpi('搜索次数', fmtSearchCount(usage.search_call_count, r.search_count_estimated)) +
        kpi('Tokens', fmtInt(r.total_tokens), true) +
        kpi('输入 token', fmtInt(usage.prompt_tokens)) +
        kpi('输出 token', fmtInt(usage.completion_tokens)) +
        kpi('缓存 token', fmtInt(usage.cached_tokens)) +
      '</div>' +
      (r.search_count_estimated ? '<p class="muted">搜索次数为估算：本次上游未返回真实搜索次数，此处按每问均值展示。</p>' : '') +
      (r.approximate_pricing ? '<p class="muted">' + esc(r.pricing_note || '预估计价，正式定价待定。') + '</p>' : '') +
      '</div>';

    if (md) md.hidden = !r.is_terminal;
    if (html) html.hidden = !r.is_terminal;

    if (submittedRunId === Number(r.id)) {
      var trackedBatch = submittedBatchState && String(submittedBatchState.batchId) === String(r.batch_id || '');
      if (trackedBatch) return;
      var submittedMessage = $('#formMsg');
      if (submittedMessage) {
        submittedMessage.className = 'form-msg' + (r.status === 'failed' ? ' err' : '');
        submittedMessage.textContent = r.status === 'completed'
          ? '运行 #' + r.id + ' 已完成，结果已显示。'
          : (r.status === 'failed'
            ? '运行 #' + r.id + ' 失败：' + (r.error_message || '请查看运行详情。')
            : '运行 #' + r.id + (r.status === 'running' ? ' 正在监测；可离开本页，任务会在后台继续。' : ' 已进入队列；可离开本页，任务会在后台继续。'));
      }
      if (r.is_terminal) submittedRunId = null;
    }

  }

  function stopSubmittedBatchTracking(clearSubmittedRun) {
    if (submittedBatchTimer) {
      clearTimeout(submittedBatchTimer);
      submittedBatchTimer = null;
    }
    submittedBatchRequestSeq += 1;
    submittedBatchState = null;
    if (clearSubmittedRun) submittedRunId = null;
  }

  function submittedBatchProgress(payload, fallbackTotal) {
    var summary = payload && payload.batches && payload.batches[0] || {};
    var runs = payload && Array.isArray(payload.data) ? payload.data : [];
    var completed = Number(summary.completed_count);
    var failed = Number(summary.failed_count);
    var running = Number(summary.running_count);
    var reconciling = runs.filter(function (run) { return run.status === 'reconciliation_required'; }).length;
    if (!Number.isFinite(completed)) completed = runs.filter(function (run) { return run.status === 'completed'; }).length;
    if (!Number.isFinite(failed)) failed = runs.filter(function (run) { return run.status === 'failed'; }).length;
    if (!Number.isFinite(running)) running = runs.filter(function (run) { return !run.is_terminal; }).length;
    var total = Number(summary.question_count) || Number(fallbackTotal) || runs.length;
    total = Math.max(total, completed + failed + running);
    return {
      total: total,
      completed: completed,
      failed: failed,
      running: Math.max(running, total - completed - failed - reconciling),
      reconciling: reconciling,
      terminal: total > 0 && completed + failed >= total
    };
  }

  function renderSubmittedBatchProgress(progress) {
    var msg = $('#formMsg');
    if (!msg || !progress) return;
    if (progress.terminal) {
      msg.className = 'form-msg' + (progress.failed ? ' warn' : ' ok');
      msg.textContent = '本轮 ' + progress.total + ' 个问题已结束：完成 ' + progress.completed + '，失败 ' + progress.failed + '。可在监测历史中逐条查看。';
      return;
    }
    msg.className = 'form-msg';
    msg.textContent = '本轮共 ' + progress.total + ' 个问题：已完成 ' + progress.completed + '，失败 ' + progress.failed + '，进行中 ' + progress.running + (progress.reconciling ? '，待核对 ' + progress.reconciling : '') + '。后台会继续执行。';
  }

  function scheduleSubmittedBatchPoll(state) {
    if (!state || submittedBatchState !== state || state.terminal) return;
    if (submittedBatchTimer) clearTimeout(submittedBatchTimer);
    submittedBatchTimer = setTimeout(function () {
      submittedBatchTimer = null;
      pollSubmittedBatch(state);
    }, 8000);
  }

  function pollSubmittedBatch(state) {
    if (!state || submittedBatchState !== state || state.terminal) return;
    var project = $('#projectId');
    if (project && Number(project.value) !== Number(state.projectId)) {
      stopSubmittedBatchTracking(true);
      return;
    }
    var requestSeq = ++submittedBatchRequestSeq;
    apiGet('/api/v1/visibility/runs?batch_id=' + encodeURIComponent(state.batchId)).then(function (payload) {
      if (submittedBatchState !== state || requestSeq !== submittedBatchRequestSeq) return;
      var progress = submittedBatchProgress(payload, state.questionCount);
      renderSubmittedBatchProgress(progress);
      state.terminal = progress.terminal;
      if (!state.terminal) scheduleSubmittedBatchPoll(state);
    }).catch(function () {
      if (submittedBatchState !== state || requestSeq !== submittedBatchRequestSeq) return;
      var msg = $('#formMsg');
      if (msg) {
        msg.className = 'form-msg warn';
        msg.textContent = '整批进度暂时刷新失败，后台任务仍会继续；稍后自动重试，请勿重复发起。';
      }
      scheduleSubmittedBatchPoll(state);
    });
  }

  function startSubmittedBatchTracking(payload, projectId) {
    var batchId = String(payload && payload.batch_id || '');
    if (!batchId) return false;
    stopSubmittedBatchTracking();
    var state = {
      batchId: batchId,
      projectId: Number(projectId),
      questionCount: Number(payload.question_count) || (payload.runs || []).length,
      terminal: false
    };
    submittedBatchState = state;
    var initial = submittedBatchProgress({ data: payload.runs || [] }, state.questionCount);
    renderSubmittedBatchProgress(initial);
    state.terminal = initial.terminal;
    if (!state.terminal) pollSubmittedBatch(state);
    return true;
  }

  /* ---------------- 发起监测 ---------------- */
  function bindForm() {
    var form = $('#newRunForm');
    if (!form) return;
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var msg = $('#formMsg');
      if (!monitoringConfigured) {
        if (msg) msg.textContent = '未配置豆包监测，请先在设置中填写模型配置。';
        return;
      }
      if (visibilitySubmitInFlight) {
        if (msg) msg.textContent = '本次提交仍在处理中，请勿重复发起。';
        return;
      }
      if (msg) { msg.textContent = ''; msg.className = 'form-msg'; }
      var proj = $('#projectId'), q = $('#questions');
      var pid = Number(proj ? proj.value : 0);
      var lines = questionLines(q ? q.value : '');
      if (!pid || pid < 1) { if (msg) msg.textContent = '请选择项目'; return; }
      if (!lines.length) { if (msg) msg.textContent = '请至少填写一个问题'; return; }
      if (lines.length > MAX_QUESTIONS) { if (msg) msg.textContent = '一次最多提交 ' + MAX_QUESTIONS + ' 个问题，请删减后再试。'; return; }
      if (lines.some(function (question) { return question.length > 500; })) { if (msg) msg.textContent = '每个问题最多 500 字，请缩短后再试。'; return; }
      // 用户主动发起新一轮后，表单提示应只属于新请求；旧批次仍在后台运行并保留在历史里。
      stopSubmittedBatchTracking(true);
      if (msg) msg.textContent = '提交中…';
      var btn = $('#submitBtn');
      var submittedQuestionText = q ? q.value : '';
      var projectWasDisabled = Boolean(proj && proj.disabled);
      var questionsWereDisabled = Boolean(q && q.disabled);
      visibilitySubmitInFlight = true;
      if (btn) btn.disabled = true;
      // POST 结果返回前固定项目和问题，保证这次付费动作的表单上下文不会被切走。
      // 成功回调仍会复核原值，防脚本或浏览器恢复状态绕过 disabled 后误清新输入。
      if (proj) proj.disabled = true;
      if (q) q.disabled = true;
      renderQuestionSuggestions();
      var payload = { project_id: pid, questions: lines };
      var pendingRequest;
      var claimReady = false;
      try { pendingRequest = pendingIdempotencyRequest('visibility-run', payload); }
      catch (error) {
        visibilitySubmitInFlight = false;
        if (btn) btn.disabled = false;
        if (proj) proj.disabled = projectWasDisabled;
        if (q) q.disabled = questionsWereDisabled;
        if (msg) { msg.textContent = error.message; msg.className = 'form-msg err'; }
        updateQuestionCount();
        return;
      }
      // Claim before the paid POST. A lost browser slot or another tab can then
      // recover the original account-bound key instead of starting a new batch.
      apiPost('/api/v1/visibility/runs/claim', { payload: payload, request_key: pendingRequest.key })
        .then(function (claim) {
          if (!claim || claim.ok !== true) throw new Error('监测请求认领失败，本次未发送。');
          adoptClaimedVisibilityKey(pendingRequest, claim.request_key);
          if (Number(proj && proj.value) !== pid || !q || q.value !== submittedQuestionText) {
            throw new Error('监测项目或问题已变化，本次未发送付费请求。');
          }
          claimReady = true;
          return apiPost('/api/v1/visibility/runs', payload, pendingRequest.key);
        })
        .then(function (d) {
          // 2xx 但缺批次/运行标识也不能视为已确认：付费动作可能已经发生，
          // 必须保留原幂等键，让相同内容的重试回放这一笔而不是新建一批。
          if (!d || !d.batch_id || !Array.isArray(d.runs) || !d.runs.length) {
            throw new Error('服务端返回了无法确认的提交结果');
          }
          globalThis.GEO_SHELL?.invalidateSetup?.('visibility');
          globalThis.GEO_SHELL?.refreshPoints?.();
          var first = (d.runs && d.runs[0]);
          var sameProject = Number(proj && proj.value) === pid;
          var sameQuestions = Boolean(q && q.value === submittedQuestionText);
          if (first && sameProject) {
            submittedRunId = Number(first.id);
            startSubmittedBatchTracking(d, pid);
            openDetail(submittedRunId);
            window.location.hash = '#sec-detail';
          } else if (!first && sameProject && msg) {
            msg.textContent = '已提交，任务会在后台执行。';
          }
          if (sameProject && sameQuestions && q) q.value = '';
          loadList({ background: true });
          return apiPost('/api/v1/visibility/runs/seen', { request_key: pendingRequest.key })
            .then(function (seen) {
              if (!seen || seen.ok !== true) throw new Error('送达确认失败');
              clearPendingIdempotencyRequest(pendingRequest);
            })
            .catch(function () {
              if (msg && Number(proj && proj.value) === pid) {
                msg.textContent = '监测批次已提交，送达确认暂未保存；再次提交相同问题会恢复原批次。';
                msg.className = 'form-msg warn';
              }
            });
        })
        .catch(function (err) {
          var retainKey = !claimReady || retainPendingIdempotencyRequest(err);
          if (!retainKey) clearPendingIdempotencyRequest(pendingRequest);
          if (msg && Number(proj && proj.value) === pid) {
            if (retainKey) {
              msg.textContent = '提交状态暂时无法确认。系统已保留本次请求编号；请保持当前项目和问题不变，稍后再次发起会复用同一请求，不会重复执行。';
              msg.className = 'form-msg warn';
            } else {
              msg.textContent = '发起失败：' + (err && err.message || '请求失败');
              msg.className = 'form-msg err';
            }
          }
        })
        .then(function () {
          visibilitySubmitInFlight = false;
          if (proj) proj.disabled = projectWasDisabled;
          if (q) q.disabled = questionsWereDisabled;
          updateQuestionCount();
        });
    });
    var projectSelect = $('#projectId');
    if (projectSelect) projectSelect.addEventListener('change', function () {
      if (visibilitySubmitInFlight) return;
      if (!projectSelect.value) return;
      stopSubmittedBatchTracking(true);
      var questions = $('#questions'), msg = $('#formMsg');
      var hadQuestions = questions && questions.value.trim() !== '';
      if (questions) questions.value = '';
      updateQuestionCount();
      rememberProjectId(projectSelect.value);
      syncProjectQuery(projectSelect.value);
      loadQuestionSuggestions(projectSelect.value);
      if (msg) {
        msg.className = 'form-msg';
        msg.textContent = hadQuestions ? '已切换项目，已清空上一项目的问题，请重新选择或填写。' : '';
      }
    });
    var questions = $('#questions');
    if (questions) questions.addEventListener('input', updateQuestionCount);
    var suggestionList = $('#suggestionList');
    if (suggestionList) suggestionList.addEventListener('click', function (event) {
      var button = event.target.closest('[data-suggestion-index]');
      if (button) addSuggestedQuestion(Number(button.getAttribute('data-suggestion-index')));
    });
    updateQuestionCount();
  }

  /* ---------------- 报告下载（带鉴权，Blob 触发） ---------------- */
  function download(runId, format) {
    apiReq('/api/v1/visibility/runs/' + runId + '/download/' + format, { raw: true })
      .then(function (resp) { return resp.blob(); })
      .then(function (blob) {
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'ai-visibility-report-' + runId + '.' + format;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
      })
      .catch(function () { /* 鉴权失败已由守卫态处理，此处静默 */ });
  }

  /* ---------------- 视图切换：保留原锚点和全部已挂载的状态 ---------------- */
  function showVisibilitySection(hash, focusHeading) {
    var ids = ['sec-list', 'sec-new', 'sec-detail'];
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

  function wireSectionNav() {
    $$('.section-nav a').forEach(function (link) {
      link.addEventListener('click', function () {
        showVisibilitySection(link.getAttribute('href'), false);
      });
    });
    window.addEventListener('hashchange', function () { showVisibilitySection(window.location.hash, true); });
    showVisibilitySection(window.location.hash, false);
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
      loadList();
      loadProjectChoices();
    }).catch(function (e) {
      if (e !== 'redirect') showGuard('error');
    });
  }

  /* ---------------- 事件绑定 ---------------- */
  function bind() {
    var rb = $('#refreshBtn');
    if (rb) rb.addEventListener('click', function () { loadList({ background: true }); });
    bindForm();
    wireSectionNav();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { bind(); bootstrap(); });
  } else { bind(); bootstrap(); }
})();
