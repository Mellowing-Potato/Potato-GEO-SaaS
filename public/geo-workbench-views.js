/* ============================================================================
   GEO 工作台 v2 · 七个新视图交互（geo-workbench-views.js）
   设计依据：design-specs/prototypes/geo-workbench-v2.html（+ spec 一页纸）
   基座：geo-workbench.js（IIFE，已暴露 window.GW：registerView / apiGet /
        apiPost / apiPatch / apiDelete / apiUpload / toast / applyLoading /
        applyEmpty / applyError / setNavBadge / setNavDot / state / closeActiveDrawer）
   红线遵守：
     - 不改 window.GEO_ENTRY_CONFIG；不改任何 API / 表单 name / Sanctum token
     - 不引 UI 框架 / npm 依赖 / 打包器；色值只用 --md-* token
     - demo-bar / 标注层（原型专用）不集成；prefers-reduced-motion 下动画降级
   数据策略：真实端点优先，失败（含断网）回退内置 fixture 并 console.warn 一次，
            保证七视图可离线演示。
   ============================================================================ */
(function () {
  'use strict';

  var GW = window.GW;
  if (!GW) { console.error('[geo-workbench-views] window.GW 未就绪，视图无法注册'); return; }

  var $ = GW.$, $$ = GW.$$, apiGet = GW.apiGet, apiPost = GW.apiPost,
      apiPatch = GW.apiPatch, apiDelete = GW.apiDelete, apiUpload = GW.apiUpload,
      toast = GW.toast, applyLoading = GW.applyLoading, applyEmpty = GW.applyEmpty,
      applyError = GW.applyError, setNavBadge = GW.setNavBadge, setNavDot = GW.setNavDot,
      state = GW.state;

  var API = '/api/v1';
  function pid() { return (state && state.currentId) || 'p1'; }

  /* 仅 warn 一次，避免回退刷屏 */
  var _warned = {};
  function warnOnce(key, msg) { if (!_warned[key]) { _warned[key] = 1; console.warn('[geo-workbench-views] ' + msg); } }

  /* ---------------- 通用 helper ---------------- */
  function esc(s) {
    s = (s == null ? '' : String(s));
    return s.replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function fmt(n) { return Number(n || 0).toLocaleString('zh-CN'); }
  function badge(cls, text) { return '<span class="badge ' + cls + '">' + esc(text) + '</span>'; }

  /* 表格渲染：cols=[{label,cls}]，rows=[[{html,cls},...],...] */
  function renderTbl(host, cols, rows) {
    var thead = '<thead><tr>' + cols.map(function (c) {
      return '<th' + (c.cls ? ' class="' + c.cls + '"' : '') + '>' + esc(c.label) + '</th>';
    }).join('') + '</tr></thead>';
    var tbody = '<tbody>' + rows.map(function (r) {
      return '<tr>' + r.map(function (cell) {
        var html = cell.html || '';
        return '<td' + (cell.cls ? ' class="' + cell.cls + '"' : '') + '>' + html + '</td>';
      }).join('') + '</tr>';
    }).join('') + '</tbody>';
    host.innerHTML = '<div class="tbl-scroll"><table class="tbl">' + thead + tbody + '</table></div>';
  }

  /* 状态模板（复用 v1 applyLoading/applyEmpty/applyError） */
  function hostState(host, mode, opts) {
    if (mode === 'loading') applyLoading(host);
    else if (mode === 'empty') applyEmpty(host, opts);
    else if (mode === 'error') applyError(host, opts);
    /* populated：调用方自行渲染 */
  }

  /* mini-rail（5 节） */
  function railHTML(classes) {
    return '<span class="mini-rail" title="流转进度">' + classes.map(function (c) {
      return '<i' + (c ? ' class="' + c + '"' : '') + '></i>';
    }).join('') + '</span>';
  }

  /* ===================================================================
     W1 · 内容库 + 文章详情抽屉
     =================================================================== */
  var ARTICLES = [
    { id:'a1', title:'种植牙多少钱一颗？2026 年成都价格全解', keywords:'种植牙价格', words:3214, images:2, status:'published', updated:'今天 09:12', author:'李医生', category:'种植牙',
      excerpt:'「种一颗牙到底多少钱」是后台被问得最多的问题。价格差异主要来自种植体品牌、骨条件和修复方式三个变量——本文按 2026 年成都市场行情逐项拆开说……',
      versions:[{vid:'v3',label:'v3 · 当前',meta:'今天 09:12 · 发布版',current:true},{vid:'v2',label:'v2',meta:'07-08 · 按审核意见修改'},{vid:'v1',label:'v1',meta:'07-05 · AI 初稿'}],
      publications:[{id:'pub1',channel:'官网 WordPress',status:'ok',meta:'已同步',link:'#'},{id:'pub2',channel:'行业站投稿接口',status:'fail',meta:'失败',retry:true}],
      images:[{name:'implant-diagram.png',note:'正文图'},{name:'clinic-hall.jpg',note:'头图'}] },
    { id:'a2', title:'隐形矫正 vs 传统矫正：口腔医生怎么选', keywords:'隐形矫正对比', words:2876, images:1, status:'pending', updated:'今天 08:40' },
    { id:'a3', title:'洗牙会伤牙釉质吗？常见误区一次说清', keywords:'洗牙误区', words:2102, images:0, status:'approved', updated:'昨天 17:03' },
    { id:'a4', title:'儿童看牙第一次要做什么检查', keywords:'儿童牙科初诊', words:1844, images:0, status:'draft', updated:'昨天 15:22' },
    { id:'a5', title:'牙齿美白项目横评（已退回）', keywords:'牙齿美白', words:1980, images:0, status:'request-changes', updated:'昨天 11:40', reviewNote:'价格数据需注明来源 · 王审核 · 昨天' },
    { id:'a6', title:'智齿冠周炎应急处理指南', keywords:'智齿发炎', words:1620, images:0, status:'draft', updated:'07-08' },
    { id:'a7', title:'种植牙和镶牙哪个更划算', keywords:'种植牙对比', words:2400, images:1, status:'pending', updated:'07-07' },
    { id:'a8', title:'正畸保持器要戴多久', keywords:'正畸保持', words:1500, images:0, status:'recycled', updated:'07-05' },
    { id:'a9', title:'牙周炎能治好吗', keywords:'牙周炎治疗', words:2100, images:0, status:'published', updated:'07-03' },
    { id:'a10', title:'种植牙术后饮食注意事项', keywords:'种植牙护理', words:1800, images:0, status:'published', updated:'06-30' }
  ];

  var STATUS_META = {
    draft:           { label:'草稿',   badge:'neutral' },
    editing:         { label:'编辑中', badge:'run' },
    pending:         { label:'待审核', badge:'wait' },
    'request-changes':{ label:'已退回', badge:'fail' },
    approved:        { label:'审核通过', badge:'run' },
    published:       { label:'已发布', badge:'ok' },
    recycled:        { label:'回收站', badge:'neutral' }
  };
  function articleRail(status) {
    switch (status) {
      case 'draft':    return ['now','','','',''];
      case 'editing':  return ['done','now','','',''];
      case 'pending':  return ['done','done','now','',''];
      case 'request-changes': return ['done','stop','','',''];
      case 'approved': return ['done','done','done','now',''];
      case 'published':return ['done','done','done','done','done'];
      case 'recycled': return ['done','done','done','done','done'];
      default:         return ['','','','',''];
    }
  }
  function articleActions(a) {
    var btns = [];
    switch (a.status) {
      case 'draft': case 'editing':
        btns.push('<button type="button" class="btn sm" data-action="submit-review" data-id="'+a.id+'">提交审核</button>');
        btns.push('<button type="button" class="btn ghost sm" data-action="edit" data-id="'+a.id+'">继续编辑</button>');
        break;
      case 'pending':
        btns.push('<button type="button" class="btn sm" data-action="approve" data-id="'+a.id+'">通过</button>');
        btns.push('<button type="button" class="btn ghost sm" data-action="request-changes" data-id="'+a.id+'">退回修改</button>');
        break;
      case 'approved':
        btns.push('<button type="button" class="btn primary sm" data-action="publish" data-id="'+a.id+'">发布</button>');
        btns.push('<button type="button" class="btn ghost sm" data-open-article data-id="'+a.id+'">查看</button>');
        break;
      case 'published':
        btns.push('<button type="button" class="btn sm" data-open-article data-id="'+a.id+'">查看</button>');
        btns.push('<button type="button" class="btn ghost sm" data-action="export-wechat" data-id="'+a.id+'">导出微信</button>');
        break;
      case 'request-changes':
        btns.push('<button type="button" class="btn sm" data-action="edit" data-id="'+a.id+'">按意见修改</button>');
        btns.push('<button type="button" class="btn ghost sm" data-action="view-note" data-id="'+a.id+'">看审核意见</button>');
        break;
      case 'recycled':
        btns.push('<button type="button" class="btn sm" data-action="restore" data-id="'+a.id+'">恢复</button>');
        btns.push('<button type="button" class="btn danger-ghost sm" data-action="purge" data-id="'+a.id+'">彻底删除</button>');
        break;
    }
    return btns.join('');
  }

  var content = {
    data: null, tab: 'all', q: '',
    enter: function () {
      var sec = $('section[data-view="content"]');
      if (!sec) return;
      sec.innerHTML =
        '<div class="view-title"><h1>内容库</h1>' +
        '<span class="vt-sub">项目全部文章 · 从草稿到分发的完整流转</span>' +
        '<div class="spacer"></div>' +
        '<button type="button" class="btn primary" data-open-drawer>写新文章（执行技能）</button></div>' +
        '<div class="panel"><div class="panel-head">' +
        '<div class="seg-tabs" role="tablist" aria-label="按状态筛选" id="articleTabs">' +
        '<button type="button" role="tab" data-tab="all" aria-selected="true">全部 <span class="t-count" data-tab-count="all">0</span></button>' +
        '<button type="button" role="tab" data-tab="draft" aria-selected="false">草稿 <span class="t-count" data-tab-count="draft">0</span></button>' +
        '<button type="button" role="tab" data-tab="pending" aria-selected="false">待审核 <span class="t-count" data-tab-count="pending">0</span></button>' +
        '<button type="button" role="tab" data-tab="published" aria-selected="false">已发布 <span class="t-count" data-tab-count="published">0</span></button>' +
        '<button type="button" role="tab" data-tab="recycled" aria-selected="false">回收站 <span class="t-count" data-tab-count="recycled">0</span></button>' +
        '</div><div class="spacer"></div>' +
        '<input class="search-input" type="search" id="articleSearch" placeholder="搜索标题或关键词"></div>' +
        '<div class="panel-body" id="articleListHost"></div></div>';

      // tab 切换
      $$('#articleTabs [data-tab]').forEach(function (b) {
        b.addEventListener('click', function () {
          $$('#articleTabs [data-tab]').forEach(function (x) { x.setAttribute('aria-selected', x === b ? 'true' : 'false'); });
          content.tab = b.dataset.tab;
          content.paintArticles();
        });
      });
      // 搜索
      $('#articleSearch').addEventListener('input', function (e) { content.q = e.target.value.trim().toLowerCase(); content.paintArticles(); });
      // 行内动作委托
      sec.addEventListener('click', content.onClick);

      content.loadArticles();
    },
    loadArticles: function () {
      var host = $('#articleListHost');
      applyLoading(host);
      apiGet(API + '/projects/' + pid() + '/articles').then(function (res) {
        if (res.ok && Array.isArray(res.data) && res.data.length) content.data = normalizeArticles(res.data);
        else { warnOnce('articles', 'articles 端点未就绪，使用 fixture'); content.data = normalizeArticles(ARTICLES); }
        content.paintArticles();
        setNavBadge('content', fmt(content.data.filter(function (a) { return a.status !== 'recycled'; }).length));
      });
    },
    paintArticles: function () {
      var host = $('#articleListHost');
      if (!host) return;
      var list = content.data || [];
      var tab = content.tab;
      var q = content.q;
      // 计数（前端聚合）
      var counts = { all: 0, draft: 0, pending: 0, published: 0, recycled: 0 };
      list.forEach(function (a) {
        if (a.status === 'recycled') counts.recycled++; else counts.all++;
        if (a.status === 'draft' || a.status === 'editing') counts.draft++;
        if (a.status === 'pending' || a.status === 'request-changes' || a.status === 'approved') counts.pending++;
        if (a.status === 'published') counts.published++;
      });
      Object.keys(counts).forEach(function (k) {
        var el = $('[data-tab-count="' + k + '"]'); if (el) el.textContent = counts[k];
      });
      // 过滤
      var rows = list.filter(function (a) {
        if (tab === 'all' && a.status === 'recycled') return false;
        if (tab === 'draft' && !(a.status === 'draft' || a.status === 'editing')) return false;
        if (tab === 'pending' && !(a.status === 'pending' || a.status === 'request-changes' || a.status === 'approved')) return false;
        if (tab === 'published' && a.status !== 'published') return false;
        if (tab === 'recycled' && a.status !== 'recycled') return false;
        if (q && (a.title + ' ' + (a.keywords || '')).toLowerCase().indexOf(q) < 0) return false;
        return true;
      });

      if (!list.length) {
        hostState(host, 'empty', { title:'这个项目还没有文章', reason:'文章由技能执行产出：先在项目工作台运行「行业问答文章」或「品牌对比评测」，产出会自动进入内容库。', actionLabel:'去执行技能', onAction: function () { GW.goView('work'); } });
        return;
      }
      if (!rows.length) {
        hostState(host, 'empty', { title:'没有匹配的文章', reason:'当前筛选或搜索条件下没有文章，换个状态或清空搜索试试。' });
        return;
      }
      var cols = [
        { label:'文章' }, { label:'流转' }, { label:'状态' }, { label:'更新' }, { label:'操作', cls:'cell-actions' }
      ];
      var body = rows.map(function (a) {
        var m = STATUS_META[a.status] || { label:a.status, badge:'neutral' };
        var sub = '关键词：' + esc(a.keywords || '—') + ' · ' + fmt(a.words) + ' 字' + (a.images ? ' · 配图 ' + a.images : ' · 未配图');
        return [
          { html:'<div class="cell-main">' + esc(a.title) + '</div><div class="cell-sub">' + sub + '</div>' },
          { html: railHTML(articleRail(a.status)) },
          { html: badge(m.badge, m.label) },
          { html:'<span class="cell-sub">' + esc(a.updated) + '</span>' },
          { html: articleActions(a), cls:'cell-actions' }
        ];
      });
      renderTbl(host, cols, body);
    },
    onClick: function (e) {
      var open = e.target.closest('[data-open-article]');
      if (open) { openArticle(open.dataset.id, open); return; }
      var act = e.target.closest('[data-action]');
      if (!act) return;
      var id = act.dataset.id, action = act.dataset.action;
      var a = (content.data || []).filter(function (x) { return x.id === id; })[0];
      if (action === 'submit-review')      doArticleAction(id, 'submit-review', 'POST', null, '已提交审核');
      else if (action === 'approve')       doArticleAction(id, 'approve', 'POST', null, '已通过审核');
      else if (action === 'publish')       doArticleAction(id, 'publish', 'PATCH', { status:'published' }, '已发布');
      else if (action === 'restore')       doArticleAction(id, 'restore', 'POST', null, '已恢复');
      else if (action === 'recycle')       doArticleAction(id, 'recycle', 'POST', null, '已移入回收站');
      else if (action === 'request-changes') {
        var note = window.prompt('请填写退回意见（必填）：');
        if (note === null) return;
        if (!note.trim()) { toast('退回意见不能为空'); return; }
        doArticleAction(id, 'request-changes', 'POST', { note: note }, '已退回，请按意见修改');
      }
      else if (action === 'export-wechat') { window.open(API + '/articles/' + id + '/export/wechat', '_blank'); }
      else if (action === 'view-note')     { if (a && a.reviewNote) toast('审核意见：' + a.reviewNote); else toast('暂无审核意见'); }
      else if (action === 'purge') {
        if (!window.confirm('彻底删除后无法恢复，确认？')) return;
        doArticleAction(id, null, 'DELETE', null, '已彻底删除', true);
      }
      else if (action === 'edit') { toast('继续编辑（待接入编辑器）'); }
    }
  };

  function doArticleAction(id, action, method, body, okMsg, isDelete) {
    var url = API + '/articles/' + id + (action ? '/' + action : '');
    var p = isDelete ? apiDelete(url) : (method === 'PATCH' ? apiPatch(url, body) : apiPost(url, body));
    p.then(function (res) {
      if (res.ok) { toast(okMsg); content.loadArticles(); }
      else toast((okMsg || '操作') + '失败：' + (res.error && res.error.message || '服务端错误'));
    });
  }

  /* 文章详情抽屉 */
  var articleDrawer = $('#articleDrawer');
  var currentArticle = null;
  function openArticle(id, trigger) {
    if (!articleDrawer) return;
    var a = (content.data || []).filter(function (x) { return x.id === id; })[0];
    var body = $('#articleDrawerBody'), foot = $('#articleDrawerFoot');
    applyLoading(body);
    foot.innerHTML = '';
    articleDrawer.classList.add('open');
    document.body.classList.add('drawer-open');
    articleDrawer._lastFocus = trigger || document.activeElement;
    $('#articleDrawerClose').focus();

    apiGet(API + '/articles/' + id).then(function (res) {
      if (res.ok && res.data) a = normalizeArticle(res.data, a);
      else warnOnce('article-'+id, 'article 端点未就绪，使用 fixture');
      paintArticleDrawer(a);
    });
  }
  function paintArticleDrawer(a) {
    var body = $('#articleDrawerBody'), foot = $('#articleDrawerFoot');
    if (!a) { applyError(body, { title:'文章加载失败', code:'AR-260710-0000', onRetry: function () {} }); return; }
    currentArticle = a;
    var m = STATUS_META[a.status] || { label:a.status, badge:'neutral' };
    var versions = a.versions || [
      { vid:'v1', label:'v1', meta:'07-05 · AI 初稿' }, { vid:'v2', label:'v2 · 当前', meta:'今天 · 发布版', current:true }
    ];
    var pubs = a.publications || [];
    var imgs = a.images2 || [];
    body.innerHTML =
      '<div style="display:flex; align-items:center; gap:8px; margin-bottom:14px; flex-wrap:wrap;">' +
        badge(m.badge, m.label) + railHTML(articleRail(a.status)) +
        '<span style="font-size:var(--md-fs-xs); color:var(--md-text-faint)">' + esc(a.author || '—') + ' 署名 · 分类：' + esc(a.category || '—') + ' · ' + esc(a.updated) + '</span>' +
      '</div>' +
      '<div class="doc-preview"><h3>' + esc(a.title) + '</h3><p>' + esc(a.excerpt || '（暂无正文预览）') + '</p></div>' +
      '<div class="drawer-sec"><h4>分发去向</h4>' + (pubs.length ? pubs.map(function (p) {
        return '<div class="kv"><span class="k">' + esc(p.channel) + '</span><span class="v">' + badge(p.status === 'ok' ? 'ok' : 'fail', p.status === 'ok' ? '已同步' : '失败') +
          (p.link ? ' <a href="' + esc(p.link) + '">查看线上页</a>' : '') +
          (p.retry ? ' <button type="button" class="btn ghost sm" data-pub-retry="' + esc(p.id) + '">重试</button>' : '') + '</span></div>';
      }).join('') : '<p class="cell-sub">尚未分发</p>') + '</div>' +
      '<div class="drawer-sec"><h4>版本记录</h4>' + versions.map(function (v) {
        return '<div class="kv"><span class="k">' + esc(v.label) + '</span><span class="v">' + esc(v.meta) +
          (v.current ? '' : ' <button type="button" class="btn ghost sm" data-ver-restore="' + esc(v.vid) + '">恢复此版</button>') + '</span></div>';
      }).join('') + '</div>' +
      '<div class="drawer-sec"><h4>配图（' + imgs.length + '）</h4>' + (imgs.length ? '<div class="img-grid" style="grid-template-columns:repeat(2,1fr);">' +
        imgs.map(function (im) { return '<div class="img-card"><div class="ig-ph">' + esc(im.note || '配图') + '</div><div class="ig-meta"><div class="ig-name">' + esc(im.name) + '</div>' + esc(im.note || '') + '</div></div>'; }).join('') +
        '</div>' : '<p class="cell-sub">无配图</p>') + '</div>';

    foot.innerHTML =
      (a.status !== 'recycled' ? '<button type="button" class="btn danger-ghost" style="margin-right:auto" data-ad-recycle="' + a.id + '">移入回收站</button>' : '') +
      '<button type="button" class="btn" data-ad-export="' + a.id + '">导出微信排版</button>' +
      '<button type="button" class="btn" data-ad-download="' + a.id + '">下载 docx</button>' +
      '<button type="button" class="btn primary" data-ad-redistribute="' + a.id + '">再次分发</button>';
  }
  /* 抽屉内动作（持久委托，避免多次 paint 重复绑定） */
  if (articleDrawer) {
    articleDrawer.querySelector('#articleDrawerBody').addEventListener('click', function (e) {
      var r = e.target.closest('[data-pub-retry]');
      if (r) { toast('正在重试分发（演示）'); return; }
      var v = e.target.closest('[data-ver-restore]');
      if (v) { if (window.confirm('恢复到 ' + v.dataset.verRestore + '？')) toast('已恢复至 ' + v.dataset.verRestore); return; }
    });
    articleDrawer.querySelector('#articleDrawerFoot').addEventListener('click', function (e) {
      var id = currentArticle && currentArticle.id;
      if (!id) return;
      if (e.target.closest('[data-ad-recycle]')) {
        if (!window.confirm('将这篇文章移入回收站？可随时从回收站恢复。')) return;
        GW.closeActiveDrawer();
        doArticleAction(id, 'recycle', 'POST', null, '已移入回收站');
      }
      else if (e.target.closest('[data-ad-export]')) window.open(API + '/articles/' + id + '/export/wechat', '_blank');
      else if (e.target.closest('[data-ad-download]')) window.open(API + '/articles/' + id + '/download', '_blank');
      else if (e.target.closest('[data-ad-redistribute]')) {
        apiPost(API + '/articles/' + id + '/publish-async', {}).then(function (res) {
          toast(res.ok ? '已提交再次分发，正在轮询结果' : '再次分发提交失败');
        });
      }
    });
  }
  if ($('#articleDrawerClose')) $('#articleDrawerClose').addEventListener('click', function () { GW.closeActiveDrawer(); });

  function normalizeArticles(arr) { return arr.map(function (d) { return normalizeArticle(d); }); }
  function normalizeArticle(d, base) {
    base = base || {};
    var dImages = d.images, baseImages = base.images;
    var imageCount = 0;
    if (dImages != null) imageCount = Array.isArray(dImages) ? dImages.length : (Number(dImages) || 0);
    else if (d.image_count != null) imageCount = Number(d.image_count) || 0;
    else if (baseImages != null) imageCount = Array.isArray(baseImages) ? baseImages.length : (Number(baseImages) || 0);
    var imageList = d.images_list || (Array.isArray(dImages) ? dImages : (Array.isArray(baseImages) ? baseImages : []));
    return {
      id: d.id || base.id, title: d.title || d.name || base.title,
      keywords: d.keywords || d.keywords_text || base.keywords,
      words: d.words || d.word_count || base.words,
      images: imageCount,
      status: d.status || base.status, updated: String(d.updated_at || d.updatedAt || d.updated || base.updated || '').slice(0, 10),
      author: d.author || base.author, category: d.category || base.category, excerpt: d.excerpt || base.excerpt,
      versions: d.versions || base.versions, publications: d.publications || base.publications, images2: imageList
    };
  }

  /* ===================================================================
     W2 · 分发渠道（含健康告警）
     =================================================================== */
  var CHANNELS = [
    { id:'c1', name:'官网 WordPress', kind:'wordpress_rest', status:'ok', meta:'chengdu-dental.com · 可发布 · 可传图 · 绑定 1 个项目', consecutive_failures:0 },
    { id:'c2', name:'行业站投稿接口', kind:'http_webhook', status:'fail', meta:'对方接口返回 401：签名密钥可能已过期。', consecutive_failures:3 },
    { id:'c3', name:'分院站点 Agent', kind:'geoflow_agent', status:'ok', meta:'自托管站点代理 · 密钥 2 个 · 绑定 2 个项目', consecutive_failures:0 }
  ];
  var KIND_LABEL = { wordpress_rest:'wordpress', http_webhook:'webhook', geoflow_agent:'agent' };
  var CHANNEL_LOGS = [
    { article:'种植牙多少钱一颗？…', channel:'官网 WordPress', result:'ok',   meta:'09:14 · 正文图 2 张已同步' },
    { article:'种植牙多少钱一颗？…', channel:'行业站投稿接口', result:'fail', meta:'09:14' },
    { article:'隐形矫正 vs 传统矫正…', channel:'分院站点 Agent', result:'run', meta:'09:02 · 排队中' }
  ];

  var channels = {
    data: null,
    enter: function () {
      var sec = $('section[data-view="channels"]');
      if (!sec) return;
      sec.innerHTML =
        '<div class="view-title"><h1>分发渠道</h1>' +
        '<span class="vt-sub">文章发布后自动同步到你的网站与外部平台</span>' +
        '<div class="spacer"></div>' +
        '<button type="button" class="btn primary" data-add-channel>＋ 接入渠道</button></div>' +
        '<div class="panel"><div class="panel-head"><h2>已接入渠道</h2><div class="spacer"></div>' +
        '<span class="hint">健康检查每小时自动运行，连续失败 5 次将自动停用</span></div>' +
        '<div class="panel-body"><div class="channel-grid" id="channelGrid"></div></div></div>' +
        '<div class="grid-2">' +
        '<div class="panel"><div class="panel-head"><h2>最近分发记录</h2><div class="spacer"></div><button type="button" class="btn ghost sm" data-all-logs>全部记录</button></div>' +
        '<div class="panel-body" id="channelLogsHost"></div></div>' +
        '<div class="panel"><div class="panel-head"><h2>站点收录文件</h2><div class="spacer"></div><span class="hint">让 AI 爬虫找得到你的内容</span></div>' +
        '<div class="panel-body" id="siteFilesHost"></div></div>' +
        '</div>';
      sec.addEventListener('click', channels.onClick);
      channels.load();
    },
    load: function () {
      var grid = $('#channelGrid'), logs = $('#channelLogsHost'), files = $('#siteFilesHost');
      applyLoading(grid); applyLoading(logs); applyLoading(files);
      apiGet(API + '/publishing/channels').then(function (res) {
        if (res.ok && Array.isArray(res.data) && res.data.length) channels.data = normalizeChannels(res.data);
        else { warnOnce('channels', 'channels 端点未就绪，使用 fixture'); channels.data = CHANNELS; }
        channels.paintGrid();
        // n-dot：任一活跃渠道不健康
        var unhealthy = channels.data.some(function (c) { return c.status !== 'ok'; });
        setNavDot('channels', unhealthy);
      });
      apiGet(API + '/publishing/channels/logs').then(function (res) {
        channels.logs = (res.ok && res.data) ? res.data : CHANNEL_LOGS;
        channels.paintLogs();
      });
      apiGet(API + '/publishing/sitemap').then(function (res) {
        channels.sitemap = (res.ok && res.data) ? res.data : { generatedAt:'今天 06:00', count:42 };
        channels.paintFiles();
      });
    },
    paintGrid: function () {
      var grid = $('#channelGrid'); if (!grid) return;
      grid.innerHTML = channels.data.map(function (c) {
        var kl = KIND_LABEL[c.kind] || c.kind;
        var foot = [];
        if (c.kind === 'wordpress_rest') { foot.push(btn('测试连接','ghost',"data-ch-test='"+c.id+"'"), btn('分发日志','ghost',"data-ch-logs='"+c.id+"'"), btn('同步分类','ghost',"data-ch-sync='"+c.id+"'"), btn('设置','ghost',"data-ch-set='"+c.id+"'")); }
        // 2026-09-22 用户口径：租户只做积分展示，「更换密钥 / 密钥管理」这类凭据入口一律不出现
        // （后端 /publishing/channels/{id}/secrets 保留给运营身份）。
        else if (c.kind === 'http_webhook') { foot.push(btn('测试连接','ghost',"data-ch-test='"+c.id+"'"), btn('分发日志','ghost',"data-ch-logs='"+c.id+"'")); }
        else if (c.kind === 'geoflow_agent') { foot.push(btn('重写规则','ghost',"data-ch-rules='"+c.id+"'")); }
        var badgeHtml = c.status === 'ok' ? badge('ok','连接正常') : badge('fail','连接失败 ×' + (c.consecutive_failures || 1));
        var metaExtra = c.status === 'fail' ? '<div class="ch-meta" style="color:var(--md-danger)">' + esc(c.meta) + ' 再失败 ' + Math.max(0, 5 - (c.consecutive_failures || 0)) + ' 次将自动停用。</div>' : '';
        return '<div class="channel-card' + (c.status === 'fail' ? ' err' : '') + '">' +
          '<div class="ch-head"><span class="ch-name">' + esc(c.name) + '</span>' +
          '<span class="ch-kind">' + esc(kl) + '</span>' + badgeHtml + '</div>' +
          '<div class="ch-meta">' + esc(c.meta || '') + '</div>' + metaExtra +
          '<div class="ch-foot">' + foot.join('') + '</div></div>';
      }).join('');
    },
    paintLogs: function () {
      var host = $('#channelLogsHost'); if (!host) return;
      var cols = [{ label:'文章 → 渠道' }, { label:'结果' }, { label:'时间' }];
      var body = channels.logs.map(function (l) {
        var rb = l.result === 'ok' ? 'ok' : l.result === 'fail' ? 'fail' : 'run';
        var rl = l.result === 'ok' ? '成功' : l.result === 'fail' ? '失败' : '排队中';
        var retry = l.result === 'fail' ? ' <button type="button" class="btn ghost sm" data-log-retry>重试</button>' : '';
        return [
          { html:'<div class="cell-main">' + esc(l.article) + '</div><div class="cell-sub">→ ' + esc(l.channel) + (l.meta ? ' · ' + esc(l.meta) : '') + '</div>' },
          { html: badge(rb, rl) + retry },
          { html:'<span class="cell-sub">' + esc(l.time || '') + '</span>' }
        ];
      });
      renderTbl(host, cols, body);
    },
    paintFiles: function () {
      var host = $('#siteFilesHost'); if (!host) return;
      var s = channels.sitemap || {};
      host.innerHTML =
        '<div class="kv"><span class="k">sitemap.xml</span><span class="v">今天 06:00 已生成 · 收录 ' + fmt(s.count || 0) + ' 篇 <button type="button" class="btn ghost sm" data-regen-sitemap>重新生成</button></span></div>' +
        '<div class="kv"><span class="k">robots.txt</span><span class="v mono">Allow: GPTBot / ClaudeBot ✓ <button type="button" class="btn ghost sm" data-view-robots>预览</button></span></div>' +
        '<p style="font-size:var(--md-fs-xs); color:var(--md-text-faint); margin-top:10px;">robots.txt 默认对主流 AI 引擎爬虫开放，这是内容进入 AI 答案的前提。</p>';
    },
    onClick: function (e) {
      var t = e.target;
      if (t.closest('[data-add-channel]')) { channels.showAddForm(); return; }
      if (t.closest('[data-all-logs]')) { toast('查看全部分发记录（演示）'); return; }
      if (t.closest('[data-regen-sitemap]')) { toast('sitemap 重新生成中（演示）'); return; }
      if (t.closest('[data-view-robots]')) { toast('robots.txt：Allow: GPTBot / ClaudeBot / Bytespider …'); return; }
      if (t.closest('[data-ch-test]')) { var id = t.closest('[data-ch-test]').dataset.chTest; channels.test(id); return; }
      if (t.closest('[data-ch-sync]')) { toast('同步分类（演示）'); return; }
      if (t.closest('[data-ch-rules]')) { toast('重写规则（演示）'); return; }
      if (t.closest('[data-ch-set]')) { toast('渠道设置（演示）'); return; }
      if (t.closest('[data-log-retry]')) { toast('已重试分发（演示）'); return; }
    },
    test: function (id) {
      toast('正在测试连接…');
      apiPost(API + '/publishing/channels/' + id + '/health', {}).then(function (res) {
        toast(res.ok ? '连接正常' : '连接失败');
      });
    },
    showAddForm: function () {
      var sec = $('section[data-view="channels"]');
      var panel = document.createElement('div');
      panel.className = 'panel';
      panel.style.marginTop = '16px';
      panel.innerHTML = '<div class="panel-head"><h2>接入渠道</h2><div class="spacer"></div><button type="button" class="btn ghost sm" data-add-cancel>取消</button></div>' +
        '<div class="panel-body"><div class="field"><label>类型</label><select id="addKind">' +
        '<option value="wordpress_rest">WordPress（REST）</option><option value="http_webhook">Webhook（HTTP）</option><option value="geoflow_agent">站点 Agent</option></select></div>' +
        '<div class="field"><label>名称</label><input id="addName" placeholder="如：官网 WordPress"></div>' +
        '<div class="field" id="addCredField"><label>Endpoint / 凭证</label><input id="addCred" placeholder="https://… 或 密钥"></div>' +
        '<button type="button" class="btn primary" data-add-save>创建并测试连接</button></div>';
      sec.appendChild(panel);
      panel.querySelector('[data-add-cancel]').addEventListener('click', function () { panel.remove(); });
      panel.querySelector('#addKind').addEventListener('change', function () {
        panel.querySelector('#addCred').placeholder = this.value === 'wordpress_rest' ? 'https://yoursite.com/wp-json' : (this.value === 'http_webhook' ? 'https://hook.example.com/post' : 'Agent 地址');
      });
      panel.querySelector('[data-add-save]').addEventListener('click', function () {
        apiPost(API + '/publishing/channels', { kind: panel.querySelector('#addKind').value, name: panel.querySelector('#addName').value, endpoint: panel.querySelector('#addCred').value })
          .then(function (res) { toast(res.ok ? '渠道已创建并测试连接' : '创建失败'); if (res.ok) { panel.remove(); channels.load(); } });
      });
    },
  };
  function btn(label, cls, attr) { return '<button type="button" class="btn ' + cls + '" ' + (attr || '') + '>' + label + '</button>'; }
  function normalizeChannels(arr) { return arr.map(function (c) { return { id:c.id, name:c.name||c.title, kind:c.kind||c.type, status:c.status==='healthy'?'ok':(c.status==='error'?'fail':'ok'), meta:c.meta||'', consecutive_failures:c.consecutive_failures||0 }; }); }

  /* ===================================================================
     W3 · 数据洞察（AI 引用链路带）
     =================================================================== */
  var ENGINE_MAP = { 'GPTBot':'ChatGPT', 'Bytespider':'豆包 / 抖音搜索', 'ClaudeBot':'Claude', 'PerplexityBot':'Perplexity', 'Google-Extended':'Gemini' };
  var INSIGHTS = {
    summary: { published: 42, distribution_success: 78, citations: 17 },
    crawler: [
      { bot:'GPTBot', hits:512, trend:'up', path:'/blog/implant-price' },
      { bot:'Bytespider', hits:361, trend:'up', path:'/blog/ortho-compare' },
      { bot:'ClaudeBot', hits:204, trend:'flat', path:'/blog/implant-price' },
      { bot:'PerplexityBot', hits:148, trend:'up', path:'/faq' },
      { bot:'Google-Extended', hits:81, trend:'flat', path:'/blog/kids-first-visit' }
    ],
    trend: [18,22,15,26,24,31,28,35,30,41,38,36,47,44,52,49,58,54,62,59,66,71,64,76,73,82,79,100,88,92],
    top: [
      { title:'种植牙多少钱一颗？2026 年成都价格全解', hits:318, engines:'GPTBot · ClaudeBot · Bytespider', published:'06-14' },
      { title:'隐形矫正 vs 传统矫正：口腔医生怎么选', hits:211, engines:'Bytespider · PerplexityBot', published:'06-20' },
      { title:'儿童看牙第一次要做什么检查', hits:96, engines:'Google-Extended', published:'06-28' }
    ]
  };

  var insights = {
    data: null,
    enter: function () {
      var sec = $('section[data-view="insights"]');
      if (!sec) return;
      sec.innerHTML =
        '<div class="view-title"><h1>数据洞察</h1>' +
        '<span class="vt-sub">近 30 天 · ' + esc((state && state.currentId) ? (GW.FIX.projects.filter(function(p){return p.id===state.currentId;})[0]||{}).name || '当前项目' : '当前项目') + '</span>' +
        '<div class="spacer"></div>' +
        '<button type="button" class="btn ghost sm" data-period disabled title="更多周期后续开放">切换周期</button></div>' +
        '<div class="panel chain-wrap" id="chainPanel">' +
        '<p class="chain-cap">从发布到被 AI 引用的完整链路——GEO 的效果不是流量，是你的内容出现在 AI 的答案里。</p>' +
        '<div class="chain" id="chainNodes"></div></div>' +
        '<div class="grid-2">' +
        '<div class="panel"><div class="panel-head"><h2>AI 引擎访客</h2><div class="spacer"></div><span class="hint">哪家 AI 的爬虫在读你的内容</span></div>' +
        '<div class="panel-body" id="crawlerHost"></div></div>' +
        '<div class="panel"><div class="panel-head"><h2>抓取趋势</h2><div class="spacer"></div><span class="hint">近 30 天 · 日抓取量</span></div>' +
        '<div class="panel-body" id="trendHost"></div></div>' +
        '</div>' +
        '<div class="panel"><div class="panel-head"><h2>被抓取最多的文章</h2><div class="spacer"></div><button type="button" class="btn ghost sm" data-visit-logs>访问明细</button></div>' +
        '<div class="panel-body" id="topHost"></div></div>' +
        '<div id="visitLogsHost"></div>';
      sec.addEventListener('click', insights.onClick);
      insights.load();
    },
    load: function () {
      applyLoading($('#crawlerHost')); applyLoading($('#trendHost')); applyLoading($('#topHost'));
      apiGet(API + '/analytics/summary').then(function (res) {
        insights.data = (res.ok && res.data) ? res.data : INSIGHTS;
        if (!res.ok) warnOnce('insights', 'analytics 端点未就绪，使用 fixture');
        insights.paintChain(); insights.paintCrawler(); insights.paintTrend(); insights.paintTop();
        // 重放描线动画
        var p = $('#chainPanel'); if (p) { p.classList.remove('chain-enter'); void p.offsetWidth; p.classList.add('chain-enter'); }
      });
    },
    paintChain: function () {
      var s = (insights.data && insights.data.summary) || INSIGHTS.summary;
      var nodes = [
        { num: fmt(s.published || 0), lab:'已发布文章', sub:'本期累计' },
        { num: fmt(s.distribution_success || 0), lab:'分发成功次数', sub:'全渠道' },
        { num: fmt(s.crawler_hits != null ? s.crawler_hits : (s.ai_crawler_hits || 0)), lab:'AI 爬虫抓取', sub:'各家引擎合计' },
        { num: (s.citations != null ? fmt(s.citations) : '—'), lab:'检出 AI 引用', sub: (s.citations != null ? '带来源链接回访' : '检测建设中'), value:true }
      ];
      $('#chainNodes').innerHTML = nodes.map(function (n) {
        return '<div class="chain-node' + (n.value ? ' value' : '') + '"><div class="c-num">' + n.num + '</div><div class="c-lab">' + esc(n.lab) + '</div><div class="c-sub">' + esc(n.sub) + '</div></div>';
      }).join('');
    },
    paintCrawler: function () {
      var host = $('#crawlerHost');
      var rows = (insights.data.crawler || INSIGHTS.crawler).map(function (c) {
        var arrow = c.trend === 'up' ? ' ↑' : c.trend === 'down' ? ' ↓' : ' →';
        return [
          { html:'<span class="cell-mono">' + esc(c.bot) + '</span>' },
          { html:'<span class="cell-sub">' + esc(ENGINE_MAP[c.bot] || c.bot) + '</span>' },
          { html:'<span class="cell-num">' + fmt(c.hits) + arrow + '</span>' },
          { html:'<span class="cell-sub">' + esc(c.path) + '</span>' }
        ];
      });
      renderTbl(host, [{ label:'爬虫' }, { label:'所属引擎' }, { label:'抓取次数' }, { label:'最常抓取' }], rows);
    },
    paintTrend: function () {
      var host = $('#trendHost');
      var arr = insights.data.trend || INSIGHTS.trend;
      var max = Math.max.apply(null, arr);
      var bars = arr.map(function (v) {
        var h = Math.round(v / max * 100);
        var hi = (v === max) ? ' hi' : '';
        return '<i class="' + hi + '" style="height:' + h + '%" title="' + v + ' 次"></i>';
      }).join('');
      host.innerHTML = '<div class="bars" role="img" aria-label="近30天AI爬虫抓取量趋势，整体上升，峰值 ' + max + ' 次/日">' + bars + '</div>' +
        '<div class="bars-x"><span>06-11</span><span>06-25</span><span>07-10</span></div>';
    },
    paintTop: function () {
      var host = $('#topHost');
      var rows = (insights.data.top || INSIGHTS.top).map(function (t) {
        return [
          { html:'<div class="cell-main">' + esc(t.title) + '</div>' },
          { html:'<span class="cell-num">' + fmt(t.hits) + '</span>' },
          { html:'<span class="cell-sub">' + esc(t.engines) + '</span>' },
          { html:'<span class="cell-sub">' + esc(t.published) + '</span>' }
        ];
      });
      renderTbl(host, [{ label:'文章' }, { label:'抓取次数' }, { label:'抓取引擎' }, { label:'发布' }], rows);
    },
    onClick: function (e) {
      if (e.target.closest('[data-visit-logs]')) { insights.showVisitLogs(); }
    },
    showVisitLogs: function () {
      var host = $('#visitLogsHost');
      applyLoading(host);
      apiGet(API + '/analytics/visit-logs').then(function (res) {
        var logs = (res.ok && res.data) ? res.data : [
          { ip:'bot-GPTBot', path:'/blog/implant-price', engine:'ChatGPT', at:'09:14' },
          { ip:'bot-Bytespider', path:'/blog/ortho-compare', engine:'豆包', at:'08:50' },
          { ip:'bot-ClaudeBot', path:'/blog/implant-price', engine:'Claude', at:'08:31' },
          { ip:'bot-PerplexityBot', path:'/faq', engine:'Perplexity', at:'07:58' }
        ];
        var cols = [{ label:'来源' }, { label:'路径' }, { label:'引擎' }, { label:'时间' }];
        var rows = logs.map(function (l) {
          return [{ html:'<span class="cell-mono">' + esc(l.ip) + '</span>' }, { html:'<span class="cell-sub">' + esc(l.path) + '</span>' }, { html:'<span class="cell-sub">' + esc(l.engine) + '</span>' }, { html:'<span class="cell-sub">' + esc(l.at) + '</span>' }];
        });
        host.innerHTML = '<div class="panel" style="margin-top:16px;"><div class="panel-head"><h2>访问明细</h2><div class="spacer"></div><span class="hint">第 1 / 1 页</span></div><div class="panel-body"></div></div>';
        renderTbl(host.querySelector('.panel-body'), cols, rows);
      });
    }
  };

  /* ===================================================================
     W4 · 素材库
     =================================================================== */
  var MATERIALS = {
    titles: [
      { id:'t1', title:'种植牙终身使用是真的吗？医生的大实话', kw:'种植牙寿命', used:2, status:'ok' },
      { id:'t2', title:'成都正畸避坑指南：这 5 个问题先问清楚', kw:'成都正畸', used:0, status:'ok' },
      { id:'t3', title:'拔智齿前必看的三件事', kw:'拔智齿注意事项', used:5, status:'neutral' }
    ],
    keywords: [
      { id:'k1', kw:'种植牙价格', intent:'比价决策', articles:4 },
      { id:'k2', kw:'隐形矫正对比', intent:'方案对比', articles:2 },
      { id:'k3', kw:'儿童牙科初诊', intent:'流程了解', articles:1 }
    ],
    images: [
      { id:'i1', name:'clinic-hall.jpg', note:'诊室实拍 1600×1200', used:3 },
      { id:'i2', name:'implant-diagram.png', note:'种植体示意图', used:6 },
      { id:'i3', name:'team-2026.jpg', note:'医生团队合影', used:1 },
      { id:'i4', name:'ortho-compare.jpg', note:'矫正前后对比', used:0 }
    ],
    authors: [
      { id:'au1', name:'李医生', title:'种植科主任 · 从业 15 年', articles:7 },
      { id:'au2', name:'王医生', title:'正畸科副主任', articles:3 }
    ]
  };
  var material = {
    tab: 'titles',
    enter: function () {
      var sec = $('section[data-view="material"]');
      if (!sec) return;
      sec.innerHTML =
        '<div class="view-title"><h1>素材库</h1><span class="vt-sub">标题、关键词、图片与作者 · 供自动化任务与技能取用</span></div>' +
        '<div class="panel"><div class="panel-head">' +
        '<div class="seg-tabs" role="tablist" aria-label="素材类型" id="materialTabs">' +
        '<button type="button" role="tab" data-mat="titles" aria-selected="true">标题</button>' +
        '<button type="button" role="tab" data-mat="keywords" aria-selected="false">关键词</button>' +
        '<button type="button" role="tab" data-mat="images" aria-selected="false">图片</button>' +
        '<button type="button" role="tab" data-mat="authors" aria-selected="false">作者</button></div>' +
        '<div class="spacer"></div>' +
        '<button type="button" class="btn sm" id="matGenTitles" data-mat-only="titles">前往正式标题工具</button>' +
        '<button type="button" class="btn primary sm" id="matAdd">＋ 添加</button></div>' +
        '<div class="panel-body" id="materialHost"></div></div>';
      $$('#materialTabs [data-mat]').forEach(function (b) {
        b.addEventListener('click', function () {
          $$('#materialTabs [data-mat]').forEach(function (x) { x.setAttribute('aria-selected', x === b ? 'true' : 'false'); });
          material.tab = b.dataset.mat;
          material.paint();
        });
      });
      sec.addEventListener('click', material.onClick);
      material.load();
    },
    load: function () {
      material.data = MATERIALS;
      material.paint();
    },
    paint: function () {
      var host = $('#materialHost'); if (!host) return;
      var t = material.tab, d = material.data;
      if (t === 'titles') {
        var rows = d.titles.map(function (r) {
          return [{ html:'<div class="cell-main">' + esc(r.title) + '</div>' }, { html:'<span class="cell-sub">' + esc(r.kw) + '</span>' }, { html:'<span class="cell-num">' + r.used + ' 次</span>' }, { html: badge(r.status==='ok'?'ok':'neutral', r.status==='ok'?'可用':'已用尽') }, { html:'<button type="button" class="btn ghost sm" data-mat-edit="titles:'+r.id+'">编辑</button><button type="button" class="btn danger-ghost sm" data-mat-del="titles:'+r.id+'">删除</button>', cls:'cell-actions' }];
        });
        renderTbl(host, [{ label:'标题' }, { label:'关联关键词' }, { label:'已用' }, { label:'状态' }, { label:'操作', cls:'cell-actions' }], rows);
      } else if (t === 'keywords') {
        var rows2 = d.keywords.map(function (r) {
          return [{ html:'<div class="cell-main">' + esc(r.kw) + '</div>' }, { html:'<span class="cell-sub">' + esc(r.intent) + '</span>' }, { html:'<span class="cell-num">' + r.articles + ' 篇</span>' }, { html:'<button type="button" class="btn ghost sm" data-mat-edit="keywords:'+r.id+'">编辑</button><button type="button" class="btn danger-ghost sm" data-mat-del="keywords:'+r.id+'">删除</button>', cls:'cell-actions' }];
        });
        renderTbl(host, [{ label:'关键词' }, { label:'意图' }, { label:'已产出文章' }, { label:'操作', cls:'cell-actions' }], rows2);
      } else if (t === 'images') {
        host.innerHTML = '<div class="img-grid">' + d.images.map(function (r) {
          return '<div class="img-card"><div class="ig-ph">' + esc(r.note) + '</div><div class="ig-meta"><div class="ig-name">' + esc(r.name) + '</div>已用 ' + r.used + ' 次 <button type="button" class="btn ghost sm" data-mat-del="images:'+r.id+'">删除</button></div></div>';
        }).join('') + '</div>';
      } else if (t === 'authors') {
        var rows3 = d.authors.map(function (r) {
          return [{ html:'<div class="cell-main">' + esc(r.name) + '</div>' }, { html:'<span class="cell-sub">' + esc(r.title) + '</span>' }, { html:'<span class="cell-num">' + r.articles + ' 篇</span>' }, { html:'<button type="button" class="btn ghost sm" data-mat-edit="authors:'+r.id+'">编辑</button>', cls:'cell-actions' }];
        });
        renderTbl(host, [{ label:'署名' }, { label:'头衔' }, { label:'署名文章' }, { label:'操作', cls:'cell-actions' }], rows3);
      }
    },
    onClick: function (e) {
      var t = e.target;
      if (t.closest('#matGenTitles')) {
        // This preview has fixture data and no paid-attempt recovery contract.
        // The live content tool collects keywords and preserves its request key.
        window.location.assign('/#materials');
        return;
      }
      if (t.closest('#matAdd')) { var v = window.prompt('新增' + ({titles:'标题',keywords:'关键词',images:'图片',authors:'作者'}[material.tab]) + '：'); if (v) { toast('已添加（演示）'); } return; }
      var ed = t.closest('[data-mat-edit]'); if (ed) { var v2 = window.prompt('编辑：', ''); if (v2 !== null) toast('已保存（演示）'); return; }
      var del = t.closest('[data-mat-del]');
      if (del) {
        var pair = del.dataset.matDel.split(':');
        apiDelete(API + '/materials/' + pair[0] + '/' + pair[1]).then(function (res) {
          if (res.ok) { material.data[pair[0]] = material.data[pair[0]].filter(function (x) { return x.id !== pair[1]; }); material.paint(); toast('已删除'); }
          else toast('删除失败');
        });
      }
    }
  };

  /* ===================================================================
     W5 · 自动化任务
     =================================================================== */
  var CADENCE = { hourly:'每小时', daily:'每天', weekly:'每周', monthly:'每月' };
  var TASKS = [
    { id:'tk1', name:'每周问答文章', skill:'行业问答文章', cadence:'weekly', at:'周一 09:00', auto_publish:true, auto_distribute:true, next:'07-13 09:00', status:'run' },
    { id:'tk2', name:'每日热点改写', skill:'FAQ 结构化改写', cadence:'daily', at:'07:30', auto_publish:true, auto_distribute:false, next:'明天 07:30', status:'run' },
    { id:'tk3', name:'月度品牌对比评测', skill:'品牌对比评测', cadence:'monthly', at:'1 日', auto_publish:false, auto_distribute:false, next:'已暂停', status:'neutral' }
  ];
  var TASK_RUNS = [
    { task:'每日热点改写', result:'ok', output:'《洗牙会伤牙釉质吗…》已发布', cost:340, at:'今天 07:31' },
    { task:'每周问答文章', result:'fail', output:'素材库可用标题不足 · 未调用模型', cost:0, at:'07-06 09:00' }
  ];
  var tasks = {
    enter: function () {
      var sec = $('section[data-view="tasks"]');
      if (!sec) return;
      sec.innerHTML =
        '<div class="view-title"><h1>自动化任务</h1><span class="vt-sub">到点自动生成内容，可选自动发布与自动分发</span>' +
        '<div class="spacer"></div><button type="button" class="btn primary" id="newTaskBtn">＋ 新建任务</button></div>' +
        '<div class="panel"><div class="panel-head"><h2>任务列表</h2><div class="spacer"></div><span class="hint">时区 Asia/Shanghai</span></div>' +
        '<div class="panel-body" id="taskListHost"></div></div>' +
        '<div class="panel"><div class="panel-head"><h2>最近运行</h2><div class="spacer"></div><span class="hint">生成 → 发布 → 分发，每步结果单独记录</span></div>' +
        '<div class="panel-body" id="taskRunsHost"></div></div>';
      sec.addEventListener('click', tasks.onClick);
      tasks.load();
    },
    load: function () {
      applyLoading($('#taskListHost')); applyLoading($('#taskRunsHost'));
      apiGet(API + '/tasks').then(function (res) {
        tasks.data = (res.ok && res.data) ? res.data : TASKS;
        if (!res.ok) warnOnce('tasks', 'tasks 端点未就绪，使用 fixture');
        tasks.paintList();
        setNavBadge('tasks', fmt(tasks.data.length));
      });
      apiGet(API + '/tasks/runs').then(function (res) {
        tasks.runs = (res.ok && res.data) ? res.data : TASK_RUNS;
        tasks.paintRuns();
      });
    },
    paintList: function () {
      var host = $('#taskListHost');
      var rows = tasks.data.map(function (t) {
        var flags = (t.auto_publish ? '<span class="flag on">自动发布</span> ' : '<span class="flag">自动发布</span> ') +
                    (t.auto_distribute ? '<span class="flag on">自动分发</span>' : '<span class="flag">自动分发</span>');
        var paused = t.status === 'paused' || t.status === 'neutral';
        var ops = paused
          ? '<button type="button" class="btn sm" data-task-resume="'+t.id+'">恢复</button><button type="button" class="btn danger-ghost sm" data-task-del="'+t.id+'">删除</button>'
          : '<button type="button" class="btn ghost sm" data-task-run="'+t.id+'">立即运行</button><button type="button" class="btn ghost sm" data-task-pause="'+t.id+'">暂停</button>';
        return [
          { html:'<div class="cell-main">' + esc(t.name) + '</div><div class="cell-sub">技能：' + esc(t.skill || t.skill_id || '—') + '</div>' },
          { html: esc((CADENCE[t.cadence] || t.cadence) + (t.at ? ' ' + esc(t.at) : '')) },
          { html: flags },
          { html:'<span class="cell-sub">' + esc(t.next || t.next_run_at || '—') + '</span>' },
          { html: badge(paused?'neutral':'ok', paused?'已暂停':'运行中') },
          { html: ops, cls:'cell-actions' }
        ];
      });
      renderTbl(host, [{ label:'任务' }, { label:'节奏' }, { label:'发布链路' }, { label:'下次运行' }, { label:'状态' }, { label:'操作', cls:'cell-actions' }], rows);
    },
    paintRuns: function () {
      var host = $('#taskRunsHost');
      var rows = tasks.runs.map(function (r) {
        var status = r.status || (r.result === 'ok' ? 'succeeded' : 'failed');
        var labels = { pending:'排队中', running:'运行中', billing_pending:'结果待核验', succeeded:'成功', failed:'生成失败', skipped:'跳过' };
        var task = r.task || (tasks.data || []).find(function (t) { return String(t.id) === String(r.task_id); });
        var taskName = typeof task === 'object' ? task && task.name : task;
        var output = r.output || r.article_title || (status === 'billing_pending' ? '模型调用结果待核验，请勿重复发起' : r.error_message || '—');
        var tokens = r.total_tokens == null ? (r.metadata && r.metadata.total_tokens) : r.total_tokens;
        return [
          { html:'<div class="cell-main">' + esc(taskName || '任务 #' + r.task_id) + '</div>' },
          { html: badge(status === 'succeeded' ? 'ok' : status === 'failed' ? 'fail' : 'neutral', labels[status] || '未知') },
          { html:'<span class="cell-sub">' + esc(output) + '</span>' },
          { html:'<span class="cell-num">' + (tokens != null ? fmt(tokens) + ' tokens' : '未提供') + '</span>' },
          { html:'<span class="cell-sub">' + esc(r.at || r.started_at || '—') + '</span>' }
        ];
      });
      renderTbl(host, [{ label:'任务' }, { label:'结果' }, { label:'产出' }, { label:'消耗' }, { label:'时间' }], rows);
    },
    onClick: function (e) {
      var t = e.target;
      if (t.closest('#newTaskBtn')) { tasks.showNewForm(); return; }
      var run = t.closest('[data-task-run]'); if (run) { apiPost(API + '/tasks/' + run.dataset.taskRun + '/run', {}).then(function(res){ toast(res.ok?'已触发运行':'运行失败'); }); return; }
      var pause = t.closest('[data-task-pause]'); if (pause) { apiPost(API + '/tasks/' + pause.dataset.taskPause + '/pause', {}).then(function(res){ toast(res.ok?'已暂停':'操作失败'); if(res.ok){var x=tasks.data.find(function(z){return z.id===pause.dataset.taskPause;}); if(x)x.status='neutral'; tasks.paintList();} }); return; }
      var resume = t.closest('[data-task-resume]'); if (resume) { apiPost(API + '/tasks/' + resume.dataset.taskResume + '/resume', {}).then(function(res){ toast(res.ok?'已恢复':'操作失败'); if(res.ok){var x=tasks.data.find(function(z){return z.id===resume.dataset.taskResume;}); if(x)x.status='run'; tasks.paintList();} }); return; }
      var del = t.closest('[data-task-del]'); if (del) { if (!window.confirm('删除该任务？')) return; apiDelete(API + '/tasks/' + del.dataset.taskDel).then(function(res){ if(res.ok){tasks.data=tasks.data.filter(function(z){return z.id!==del.dataset.taskDel;}); tasks.paintList(); toast('已删除');} else toast('删除失败'); }); }
    },
    showNewForm: function () {
      var sec = $('section[data-view="tasks"]');
      var panel = document.createElement('div'); panel.className = 'panel'; panel.style.marginTop = '16px';
      var skillOpts = (GW.FIX.skills || []).map(function (s) { return '<option value="'+s.id+'">'+esc(s.name)+'</option>'; }).join('');
      panel.innerHTML = '<div class="panel-head"><h2>新建任务</h2><div class="spacer"></div><button type="button" class="btn ghost sm" data-task-cancel>取消</button></div>' +
        '<div class="panel-body">' +
        '<div class="field"><label>名称</label><input id="tkName" placeholder="如：每周问答文章"></div>' +
        '<div class="field"><label>技能</label><select id="tkSkill">'+skillOpts+'</select></div>' +
        '<div class="field"><label>节奏</label><select id="tkCadence"><option value="hourly">每小时</option><option value="daily">每天</option><option value="weekly">每周</option><option value="monthly">每月</option></select></div>' +
        '<div class="field"><label><input type="checkbox" id="tkPub" checked> 自动发布</label> <label style="margin-left:12px;"><input type="checkbox" id="tkDist"> 自动分发</label></div>' +
        '<div class="field"><label>主题来源</label><input id="tkTopic" placeholder="从素材库取标题 / 关键词"></div>' +
        '<button type="button" class="btn primary" data-task-save>创建任务</button></div>';
      sec.appendChild(panel);
      panel.querySelector('[data-task-cancel]').addEventListener('click', function(){ panel.remove(); });
      panel.querySelector('[data-task-save]').addEventListener('click', function(){
        apiPost(API + '/tasks', { name: panel.querySelector('#tkName').value, skill: panel.querySelector('#tkSkill').value, cadence: panel.querySelector('#tkCadence').value, auto_publish: panel.querySelector('#tkPub').checked, auto_distribute: panel.querySelector('#tkDist').checked, topic: panel.querySelector('#tkTopic').value })
          .then(function(res){ toast(res.ok?'任务已创建':'创建失败'); if(res.ok){ panel.remove(); tasks.load(); } });
      });
    }
  };

  /* ===================================================================
     W6 · 知识库
     =================================================================== */
  var DOCS = [
    { id:'d1', name:'2026 价目表.pdf', meta:'14 页 · 今天上传', status:'ok' },
    { id:'d2', name:'品牌介绍与医生资质.docx', meta:'8 页 · 06-30 上传', status:'ok' },
    { id:'d3', name:'chengdu-dental.com/about', meta:'网页导入 · 解析中', status:'run' }
  ];
  var KB = {
    rail: ['done','now',''],
    lastGen: '今天 10:02 · 由 3 份资料汇总', published: 'v4 · 07-02（可回滚）',
    draft: '', revisions: [{ vid:'r3', label:'v3 · 当前草稿', meta:'今天 10:02' }, { vid:'r2', label:'v2', meta:'07-01' }, { vid:'r1', label:'v1', meta:'06-28' }]
  };
  var knowledge = {
    enter: function () {
      var sec = $('section[data-view="knowledge"]');
      if (!sec) return;
      sec.innerHTML =
        '<div class="view-title"><h1>知识库</h1><span class="vt-sub">品牌资料喂给 AI · 让每篇产出都懂你的业务</span></div>' +
        '<div class="grid-2">' +
        '<div class="panel"><div class="panel-head"><h2>项目知识库</h2><div class="spacer"></div>' +
        '<button type="button" class="btn sm" id="kbIngest">导入网页</button><button type="button" class="btn primary sm" id="kbUpload">上传文档</button></div>' +
        '<div class="panel-body" id="kbDocsHost"></div></div>' +
        '<div class="panel"><div class="panel-head"><h2>企业知识档案</h2><div class="spacer"></div><span class="badge wait">草稿待校验</span></div>' +
        '<div class="panel-body" id="kbArchiveHost"></div></div>' +
        '</div>';
      sec.addEventListener('click', knowledge.onClick);
      // 企业知识档案内动作（草稿编辑区之外）委托一次，避免每次 paint 重复绑定
      var ah = $('#kbArchiveHost');
      if (ah) ah.addEventListener('click', function (e) {
        var t = e.target;
        if (t.closest('[data-kb-regen]')) { toast('正在重新生成企业知识档案（演示）'); knowledge.kb.rail=['now','','']; knowledge.paintArchive(); return; }
        if (t.closest('[data-kb-validate]')) { toast('校验草稿（演示）'); knowledge.kb.rail=['done','now','']; knowledge.paintArchive(); return; }
        if (t.closest('[data-kb-publish]')) { apiPost(API + '/enterprise-knowledge/publish', {}).then(function(res){ toast(res.ok?'已发布本版':'发布失败'); if(res.ok){knowledge.kb.rail=['done','done','now']; knowledge.paintArchive();} }); return; }
        if (t.closest('[data-kb-history]')) { knowledge.showHistory(); return; }
      });
      knowledge.load();
    },
    load: function () {
      applyLoading($('#kbDocsHost')); applyLoading($('#kbArchiveHost'));
      apiGet(API + '/knowledge-bases/documents').then(function (res) {
        knowledge.docs = (res.ok && res.data) ? res.data : DOCS;
        if (!res.ok) warnOnce('kb', 'knowledge-bases 端点未就绪，使用 fixture');
        knowledge.paintDocs();
      });
      apiGet(API + '/enterprise-knowledge').then(function (res) {
        knowledge.kb = (res.ok && res.data) ? res.data : KB;
        knowledge.paintArchive();
      });
    },
    paintDocs: function () {
      var host = $('#kbDocsHost');
      var rows = knowledge.docs.map(function (d) {
        return [{ html:'<div class="cell-main">' + esc(d.name) + '</div><div class="cell-sub">' + esc(d.meta) + '</div>' },
                { html: badge(d.status==='ok'?'ok':(d.status==='run'?'run':'neutral'), d.status==='ok'?'已解析':(d.status==='run'?'解析中':'待解析')) },
                { html: d.status==='run' ? '<button type="button" class="btn ghost sm" data-kb-progress="'+d.id+'">查看进度</button>' : '<button type="button" class="btn danger-ghost sm" data-kb-del="'+d.id+'">删除</button>', cls:'cell-actions' }];
      });
      renderTbl(host, [{ label:'资料' }, { label:'状态' }, { label:'操作', cls:'cell-actions' }], rows);
    },
    paintArchive: function () {
      var host = $('#kbArchiveHost'); var k = knowledge.kb;
      host.innerHTML =
        '<p style="font-size:var(--md-fs-xs); color:var(--md-text-muted); margin-bottom:12px;">把上面的资料整理成一份结构化「企业事实档案」，发布后 AI 写作会优先引用，避免编错价格与资质。</p>' +
        '<div class="kv"><span class="k">修订流转</span><span class="v">' + railHTML(k.rail) + '　生成 → 校验 → 发布</span></div>' +
        '<div class="kv"><span class="k">最近生成</span><span class="v">' + esc(k.lastGen) + '</span></div>' +
        '<div class="kv"><span class="k">已发布版本</span><span class="v">' + esc(k.published) + '</span></div>' +
        '<div style="display:flex; gap:10px; margin-top:14px; flex-wrap:wrap;">' +
        '<button type="button" class="btn sm" data-kb-regen>重新生成</button>' +
        '<button type="button" class="btn sm" data-kb-validate>校验草稿</button>' +
        '<button type="button" class="btn primary sm" data-kb-publish>发布本版</button>' +
        '<button type="button" class="btn ghost sm" data-kb-history>历史版本</button></div>' +
        '<div class="drawer-sec" style="margin-top:16px;"><h4>草稿编辑</h4>' +
        '<textarea id="kbDraft" style="width:100%; min-height:120px; border:1px solid var(--md-border); border-radius:var(--md-radius-sm); padding:8px 11px; font-size:var(--md-fs-xs);">' + esc(k.draft || '本机构为成都本地口腔连锁，主推种植与正畸；价格以官网价目表为准，不承诺“最便宜”。') + '</textarea>' +
        '<p style="font-size:var(--md-fs-xs); color:var(--md-text-faint); margin-top:6px;" id="kbAutosaveHint">编辑后 30 秒自动保存</p></div>' +
        '<div id="kbHistoryHost"></div>';
      // 节流 autosave（#kbDraft 每次 paint 重建，故此处绑定无重复累积）
      var ta = $('#kbDraft'), hint = $('#kbAutosaveHint'), timer = null;
      ta.addEventListener('input', function () {
        hint.textContent = '编辑中…';
        clearTimeout(timer);
        timer = setTimeout(function () {
          apiPost(API + '/' + pid() + '/autosave', { type:'enterprise_knowledge', content: ta.value }).then(function (res) {
            hint.textContent = res.ok ? '已自动保存（' + new Date().toLocaleTimeString('zh-CN') + '）' : '自动保存失败，可手动重试';
          });
        }, 30000);
      });
    },
    showHistory: function () {
      var host = $('#kbHistoryHost');
      var revs = knowledge.kb.revisions || KB.revisions;
      var rows = revs.map(function (r) {
        return [{ html:'<div class="cell-main">' + esc(r.label) + '</div><div class="cell-sub">' + esc(r.meta) + '</div>' },
                { html:'<button type="button" class="btn ghost sm" data-kb-rollback="'+esc(r.vid)+'">回滚到此版</button>', cls:'cell-actions' }];
      });
      host.innerHTML = '<div class="panel" style="margin-top:12px;"><div class="panel-head"><h2>历史版本</h2></div><div class="panel-body"></div></div>';
      renderTbl(host.querySelector('.panel-body'), [{ label:'版本' }, { label:'操作', cls:'cell-actions' }], rows);
      host.querySelector('.panel-body').addEventListener('click', function (e) {
        var rb = e.target.closest('[data-kb-rollback]');
        if (rb && window.confirm('回滚到 ' + rb.dataset.kbRollback + '？')) toast('已回滚到 ' + rb.dataset.kbRollback);
      });
    },
    onClick: function (e) {
      var t = e.target;
      if (t.closest('#kbUpload')) { window.prompt('选择要上传的文档（演示）：输入文件名'); apiUpload(API + '/knowledge-bases/documents', new FormData()).then(function(){ toast('上传中（演示）'); }); return; }
      if (t.closest('#kbIngest')) { var u = window.prompt('导入网页 URL：'); if (u) { apiPost(API + '/knowledge-bases/ingest-url', { url:u }).then(function(res){ toast(res.ok?'已开始解析':'导入失败'); if(res.ok) knowledge.load(); }); } return; }
      var prog = t.closest('[data-kb-progress]'); if (prog) { toast('解析进度（演示）'); return; }
      var del = t.closest('[data-kb-del]'); if (del) { if (!window.confirm('删除该资料？')) return; apiDelete(API + '/knowledge-bases/documents/' + del.dataset.kbDel).then(function(res){ if(res.ok){knowledge.docs=knowledge.docs.filter(function(x){return x.id!==del.dataset.kbDel;}); knowledge.paintDocs(); toast('已删除');} else toast('删除失败'); }); }
    }
  };

  /* ===================================================================
     W7 · 设置
     =================================================================== */
  var SETTINGS = {
    team: [
      { id:'u1', name:'示例管理员', email:'admin***@example.com', role:'run', roleLabel:'管理员', current:true },
      { id:'u2', name:'示例审核员', email:'reviewer***@example.com', role:'neutral', roleLabel:'审核员' },
      { id:'u3', name:'运营小李', email:'邀请已发出 · 待接受', role:'wait', roleLabel:'待加入' }
    ],
    // 2026-09-22 用户口径：租户只做积分展示，没有 key 与令牌的创建/删除 ——
    // v2 原型里的「API 令牌」面板已撤下（后端 /api/v1/api-tokens 保留给运营身份）。
    aikeys: [
      { id:'ak1', name:'DeepSeek', mask:'sk-…8f2a', on:true },
      { id:'ak2', name:'通义千问', mask:null, on:false }
    ],
    categories: ['种植牙','正畸','儿童齿科','口腔护理'],
    words: ['最便宜','无痛保证','百分百成功'],
    prompts: [
      { id:'p1', name:'行业问答文章', custom:true },
      { id:'p2', name:'品牌对比评测', custom:false }
    ]
  };
  var settings = {
    enter: function () {
      var sec = $('section[data-view="settings"]');
      if (!sec) return;
      sec.innerHTML =
        '<div class="view-title"><h1>设置</h1><span class="vt-sub">团队、接入与内容规范</span></div>' +
        '<div class="grid-2">' +
        '<div class="panel"><div class="panel-head"><h2>团队成员</h2><div class="spacer"></div><button type="button" class="btn primary sm" id="inviteBtn">邀请成员</button></div>' +
        '<div class="panel-body" id="teamHost"></div></div>' +
        '<div class="panel"><div class="panel-head"><h2>自带 AI 密钥</h2><div class="spacer"></div><button type="button" class="btn sm" id="aikeyBtn">添加密钥</button></div>' +
        '<div class="panel-body" id="aikeyHost"></div></div>' +
        '<div class="panel"><div class="panel-head"><h2>内容规范</h2><div class="spacer"></div><span class="hint">对本租户全部项目生效</span></div>' +
        '<div class="panel-body" id="normHost"></div></div>' +
        '</div>';
      sec.addEventListener('click', settings.onClick);
      settings.load();
    },
    load: function () {
      applyLoading($('#teamHost')); applyLoading($('#aikeyHost')); applyLoading($('#normHost'));
      apiGet(API + '/team').then(function (res) { settings.team = (res.ok && res.data) ? res.data : SETTINGS.team; if (!res.ok) warnOnce('settings', 'settings 端点未就绪，使用 fixture'); settings.paintTeam(); });
      apiGet(API + '/ai-keys').then(function (res) { settings.aikeys = (res.ok && res.data) ? res.data : SETTINGS.aikeys; settings.paintAiKeys(); });
      apiGet(API + '/categories').then(function (res) {
        settings.categories = (res.ok && res.data) ? res.data : SETTINGS.categories;
        settings.words = SETTINGS.words; settings.prompts = SETTINGS.prompts;
        settings.paintNorm();
      });
    },
    paintTeam: function () {
      var host = $('#teamHost');
      var rows = settings.team.map(function (m) {
        var ops = m.current ? '<span class="hint">当前账号</span>'
          : (m.role === 'wait' ? '<button type="button" class="btn ghost sm" data-team-resend="'+m.id+'">重发邀请</button>'
          : '<button type="button" class="btn ghost sm" data-team-role="'+m.id+'">改角色</button><button type="button" class="btn danger-ghost sm" data-team-remove="'+m.id+'">移除</button>');
        return [{ html:'<div class="cell-main">' + esc(m.name) + '</div><div class="cell-sub">' + esc(m.email) + '</div>' }, { html: badge(m.role==='run'?'run':(m.role==='wait'?'wait':'neutral'), m.roleLabel) }, { html: ops, cls:'cell-actions' }];
      });
      renderTbl(host, [{ label:'成员' }, { label:'角色' }, { label:'操作', cls:'cell-actions' }], rows);
    },
    paintAiKeys: function () {
      var host = $('#aikeyHost');
      var kv = settings.aikeys.map(function (k) {
        return '<div class="kv"><span class="k">' + esc(k.name) + '</span><span class="v mono">' + (k.mask ? esc(k.mask) : '未配置') + '　' +
          (k.mask ? badge(k.on?'ok':'neutral', k.on?'启用中':'已停用') + '　<button type="button" class="btn ghost sm" data-aikey-toggle="'+k.id+'">' + (k.on?'停用':'启用') + '</button>' : '<button type="button" class="btn ghost sm" data-aikey-add="'+k.id+'">添加</button>') + '</span></div>';
      }).join('');
      host.innerHTML = '<p style="font-size:var(--md-fs-xs); color:var(--md-text-muted); margin-bottom:10px;">填写你自己的模型 Key，模型费用由你的供应商账户承担。</p>' + kv;
    },
    paintNorm: function () {
      var host = $('#normHost');
      var cats = '<div class="drawer-sec" style="margin-top:0;"><h4>文章分类（同步到 WordPress）</h4><div class="chip-row">' +
        settings.categories.map(function (c) { return '<span class="chip">' + esc(c) + '<button aria-label="删除分类 ' + esc(c) + '" data-cat-del="' + esc(c) + '">✕</button></span>'; }).join('') +
        '<button class="chip add" data-cat-add>＋ 新增分类</button></div></div>';
      var words = '<div class="drawer-sec"><h4>敏感词（出现即拦截发布）</h4><div class="chip-row">' +
        settings.words.map(function (w) { return '<span class="chip">' + esc(w) + '<button aria-label="删除敏感词 ' + esc(w) + '" data-word-del="' + esc(w) + '">✕</button></span>'; }).join('') +
        '<button class="chip add" data-word-add>＋ 新增敏感词</button></div></div>';
      var prompts = '<div class="drawer-sec"><h4>写作风格模板</h4>' +
        settings.prompts.map(function (p) {
          return '<div class="kv"><span class="k">' + esc(p.name) + '</span><span class="v">' + (p.custom ? badge('wait','已自定义') + '　' : badge('neutral','默认') + '　') +
            '<button type="button" class="btn ghost sm" data-prompt-edit="'+p.id+'">编辑</button>' +
            (p.custom ? '<button type="button" class="btn ghost sm" data-prompt-reset="'+p.id+'">恢复默认</button>' : '') + '</span></div>';
        }).join('') + '</div>';
      host.innerHTML = cats + words + prompts;
    },
    onClick: function (e) {
      var t = e.target;
      if (t.closest('#inviteBtn')) { var em = window.prompt('邀请成员邮箱：'); if (em) apiPost(API + '/team/invite', { email:em }).then(function(res){ toast(res.ok?'已发送邀请':'邀请失败'); }); return; }
      if (t.closest('#aikeyBtn')) { var k = window.prompt('添加模型密钥（名称:密钥）：'); if (k) toast('密钥已添加（演示）'); return; }
      var tr = t.closest('[data-team-remove]'); if (tr) { if (!window.confirm('移除该成员？')) return; apiPost(API + '/team/' + tr.dataset.teamRemove + '/remove', {}).then(function(res){ if(res.ok){settings.team=settings.team.filter(function(x){return x.id!==tr.dataset.teamRemove;}); settings.paintTeam(); toast('已移除');} else toast('移除失败'); }); return; }
      var trr = t.closest('[data-team-resend]'); if (trr) { apiPost(API + '/team/' + trr.dataset.teamResend + '/invite', {}).then(function(res){ toast(res.ok?'已重发邀请':'失败'); }); return; }
      var tro = t.closest('[data-team-role]'); if (tro) { window.prompt('修改角色为（admin/editor/auditor）：'); return; }
      var at = t.closest('[data-aikey-toggle]'); if (at) { var k = settings.aikeys.find(function(x){return x.id===at.dataset.aikeyToggle;}); if(k){k.on=!k.on; settings.paintAiKeys(); toast(k.on?'已启用':'已停用'); } return; }
      var cad = t.closest('[data-cat-del]'); if (cad) { apiDelete(API + '/categories/' + encodeURIComponent(cad.dataset.catDel)).then(function(res){ if(res.ok){settings.categories=settings.categories.filter(function(x){return x!==cad.dataset.catDel;}); settings.paintNorm(); toast('已删除分类');} }); return; }
      var caa = t.closest('[data-cat-add]'); if (caa) { var v = window.prompt('新增分类：'); if (v) { settings.categories.push(v); settings.paintNorm(); toast('已添加分类'); } return; }
      var wd = t.closest('[data-word-del]'); if (wd) { apiDelete(API + '/sensitive-words/' + encodeURIComponent(wd.dataset.wordDel)).then(function(res){ if(res.ok){settings.words=settings.words.filter(function(x){return x!==wd.dataset.wordDel;}); settings.paintNorm(); toast('已删除敏感词');} }); return; }
      var wda = t.closest('[data-word-add]'); if (wda) { var v2 = window.prompt('新增敏感词：'); if (v2) { settings.words.push(v2); settings.paintNorm(); toast('已添加敏感词'); } return; }
      var pe = t.closest('[data-prompt-edit]'); if (pe) { window.prompt('编辑「' + (settings.prompts.find(function(x){return x.id===pe.dataset.promptEdit;})||{}).name + '」模板：'); return; }
      var pr = t.closest('[data-prompt-reset]'); if (pr) { if (!window.confirm('恢复默认模板？')) return; apiDelete(API + '/prompts/' + pr.dataset.promptReset).then(function(res){ if(res.ok){var p=settings.prompts.find(function(x){return x.id===pr.dataset.promptReset;}); if(p)p.custom=false; settings.paintNorm(); toast('已恢复默认');} }); }
    },
  };

  /* ===================================================================
     注册七个视图
     =================================================================== */
  GW.registerView('content',   content);
  GW.registerView('material',  material);
  GW.registerView('knowledge', knowledge);
  GW.registerView('channels',  channels);
  GW.registerView('tasks',     tasks);
  GW.registerView('insights',  insights);
  GW.registerView('settings',  settings);
})();
