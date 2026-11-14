/* ============================================================================
   GEO 工作台 · 内容库 + 素材库（geo-entry-content-library.js）

   从 public/geo-entry.js 搬出来的第五块（计划 §7 前端大文件拆分）。
   两块合成一个文件：它们是同一条"内容资产"链路，在原文件里本来就连在一起、
   中间没有插进别的区块：

     CL  内容库（CL0-CL5）   列表 / 页签 / 搜索 → 审核流转（提交·通过·退回·回收站·恢复）
                             → 抽屉（正文、编辑、发布、版本、图片、审核记录）→ 下载
     MAT 素材库（Materials） 标题 / 关键词 / 图片 / 作者四类素材 + 技能面板选料 chips

   接入方式与前四块一致：主文件暴露 window.GEO（共享 state / elements / 通用辅助），
   本文件消费它；主文件仍然要用的入口（loadContentLibrary、loadMaterials、抽屉的
   clCloseDrawer、clSelectTab、initContentLibrary 等）在文件末尾挂回 window.GEO，
   主文件用 `globalThis.GEO?.xxx?.()` 取用 —— 契约测试的极简沙箱里没有 window。

   红线（与拆分前逐字一致）：
     - 不动任何 API 路径 / 查询参数 / 分页上限（CL_PAGE_SIZE=100、CL_MAX_PAGES=5）；
     - 抽屉的焦点回归（clDrawerState.trigger）与 Esc / Tab 键处理保持原样；
     - 列表行内动作走事件委托，重渲染多少次都不必重绑。

   判据：tests/fixtures/ui/dom-fingerprint.json（30 视图 DOM/文本/API 指纹）+
   npm run test:js 契约测试 + scripts/verify-ui-panels.mjs 的流程判据。
   ============================================================================ */
(() => {
  const GEO = window.GEO;
  if (!GEO) {
    console.error('[geo-entry-content-library] window.GEO 未就绪：请确认本文件在 geo-entry.js 之后加载');
    return;
  }
  const {
    state, elements, api, escapeHtml, formatNumber, setMessage, validationMessage,
    withBusyButton, confirmWorkspaceAction, clDebounce, clToast, fillErrorNo,
    openWorkspaceView, showWorkspaceNotice,
    authHeaders,
    pendingIdempotencyRequest, clearPendingIdempotencyRequest, retainPendingIdempotencyRequest,
    articleDisplayTitle, articleBodyBlock, articleReadableBody, errorNoText, downloadBlob,
    openContentTools, openExistingArticles,
    // 这两个留在主文件里（结果页/发布面与技能提示词抽屉也在用），按名解构。
    clIsArticle, clDrawerState,
  } = GEO;

  // 内容库的请求序号：用来丢弃过期响应（只被这一块使用）。
  let contentLibraryRequest = 0;
  let clDrawerRequest = 0;
  let clFilterProjectId = null;
  let clCategoryRequest = 0;
  state.clCategoryId ??= "";
  const clReviewBusy = new Set();
  const clPublishBusy = new Set();
  let clChannelLoad = { articleId: null, version: null, status: "loading", channels: [] };

  // ═══════════════════════════════════════════════
  // 内容库（CL0-CL5）— 独立视图，不复用 #assetPanel 系
  // ═══════════════════════════════════════════════

  function clEscapeAttr(value) {
    return escapeHtml(value);
  }

  function clRelativeTime(value) {
    if (!value) return "";
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return String(value);
    const now = new Date();
    const startToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const targetDay = new Date(d.getFullYear(), d.getMonth(), d.getDate());
    const diffDays = Math.round((startToday - targetDay) / 86400000);
    const hhmm = `${String(d.getHours()).padStart(2, "0")}:${String(d.getMinutes()).padStart(2, "0")}`;
    if (diffDays <= 0) return `今天 ${hhmm}`;
    if (diffDays === 1) return `昨天 ${hhmm}`;
    if (diffDays < 7) return `${diffDays} 天前`;
    return `${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
  }

  function clTruncate(text, n) {
    const t = String(text || "").replace(/\r/g, "").replace(/\n{2,}/g, "\n").trim();
    return t.length > n ? `${t.slice(0, n)}…` : t;
  }

  function clCategory(article) {
    if (article.category) return article.category;
    const map = { explainer: "解释型文章", comparison: "对比型文章", ranking: "排名型文章", general: "通用文章" };
    return map[String(article.content_type || "").toLowerCase()] || "文章";
  }

  function clTier(article) {
    // 回收站单独一档，不参与「全部」的紧急度排序。
    if (article.status === "recycled") return 9;
    // 最前面插入 blocked（2026-09-03）：flag 是「你自己复核」，
    // blocked 是「出站闸门已经拦了、站点上没有你的稿子」，后者更紧急，
    // 而且客户正是带着「我的稿子怎么没上线」这个问题来的。
    // ⚠️ 这两条必须是两档，不能都给 0：都给 0 的话档内按时间排，
    //    一篇「只是提示、还没出事」的文章会盖在「已经没发出去」的上面，
    //    与上面这段说明自相矛盾。2026-09-04 修。
    if (article.distribution_blocked_reason) return 0;
    if (article.flagged_sensitive && !["published", "recycled"].includes(article.status)) return 1;
    if (article.status === "pending") return 2;
    if (article.status === "editing" && article.reviewed_at) return 3;
    if (article.status === "approved") return 4;
    if (article.status === "draft" || (article.status === "editing" && !article.reviewed_at)) return 5;
    if (article.status === "published") return 6;
    return 7;
  }

  function clTierTime(article) {
    if (article.status === "pending") return article.submitted_for_review_at || article.updated_at;
    if (article.status === "editing" || article.status === "approved") return article.reviewed_at || article.updated_at;
    if (article.status === "draft") return article.updated_at;
    if (article.status === "published") return article.published_at || article.updated_at;
    return article.updated_at;
  }

  function clSortTime(article, tab) {
    if (tab === "pending") return article.submitted_for_review_at || article.updated_at;
    if (tab === "editing" || tab === "approved") return article.reviewed_at || article.updated_at;
    if (tab === "published") return article.published_at || article.updated_at;
    return article.updated_at;
  }

  function clPriorityAttr(article) {
    if (article.status === "recycled") return "";
    if (article.distribution_blocked_reason) return "blocked";
    if (article.flagged_sensitive && !["published", "recycled"].includes(article.status)) return "flag";
    if (article.status === "pending") return "pending";
    if (article.status === "editing" && article.reviewed_at) return "editing";
    if (article.status === "approved") return "approved";
    return "";
  }

  // ⚠️ quality_score 这一列是 NOT NULL DEFAULT 0（2026_06_25_000004 建表迁移），
  //    所以「没打过分」在库里长得就是 0，跟「打了 0 分」分不开。
  //    唯一的写入方 SkillAgentRunnerService::evaluateQuality() 从 50 起步、只加不减，
  //    真实分数不可能低于 50 —— 于是 0 只可能是「从没打过」。
  //    不判掉的话，手写的草稿会挂一个没人给过的「质量分 0」。
  function clHasQualityScore(article) {
    const n = Number(article.quality_score);
    return Number.isFinite(n) && n > 0;
  }

  // 状态 → 零件层徽章变体。⚠️ 状态值只能是这六个，别发明新枚举。
  function clStatusBadge(article) {
    switch (article.status) {
      case "draft": return { cls: "md-badge--neutral", label: "草稿" };
      case "editing": return article.reviewed_at
        ? { cls: "md-badge--danger", label: "待修改" }
        : { cls: "md-badge--neutral", label: "编辑稿" };
      case "pending": return { cls: "md-badge--warning", label: "待审核" };
      case "approved": return { cls: "md-badge--info", label: "已通过" };
      case "published": return { cls: "md-badge--success", label: "已发布" };
      case "recycled": return { cls: "md-badge--neutral", label: "已废弃" };
      default: return { cls: "md-badge--neutral", label: article.status || "未知" };
    }
  }

  function clMiniRailTitle(article) {
    const s = article.status;
    if (s === "draft") return "草稿";
    if (s === "editing") return article.reviewed_at ? "审核退回，需修改后重新提交" : "编辑稿，核实后提交审核";
    if (s === "pending") return "待审核";
    if (s === "approved") return "已通过，待发布";
    if (s === "published") return "已发布";
    return "";
  }

  function clMiniRail(article) {
    const s = article.status;
    // 已回收的不画流转轨：一排空灰条读起来像「还没开始」，
    // 而它其实是归档态 —— 那是在说一件不成立的事。
    if (s === "recycled") return "";
    let nodes;
    if (s === "draft") nodes = ["now", "", "", ""];
    else if (s === "editing") nodes = article.reviewed_at ? ["done", "stop", "", ""] : ["now", "", "", ""];
    else if (s === "pending") nodes = ["done", "now", "", ""];
    else if (s === "approved") nodes = ["done", "done", "now", ""];
    else if (s === "published") nodes = ["done", "done", "done", "done"];
    else nodes = ["", "", "", ""];
    const title = clMiniRailTitle(article);
    return `<span class="cl2-rail" title="${escapeHtml(title)}" aria-hidden="true">${nodes.map((n) => `<i class="${n}"></i>`).join("")}</span>`;
  }

  // ⚠️ 只返回「时间那半句」，分类由 clRowHtml 单独渲染成 .cl2-cat（配色不同）。
  //    改这里之前它也拼了一遍分类，跟外面那个 span 撞成「科普解答 · 科普解答 · …」，
  //    生产上一直这么显示（2026-09-03 顺手修掉，同一视图内）。
  function clUpdateNote(article) {
    const who = article.reviewer?.name || "审核";
    if (article.status === "pending") return `提交于 ${clRelativeTime(article.submitted_for_review_at || article.updated_at)}`;
    if (article.status === "editing" && article.reviewed_at) return `${who} 退回于 ${clRelativeTime(article.reviewed_at)}`;
    if (article.status === "approved") return `${who} 通过于 ${clRelativeTime(article.reviewed_at || article.updated_at)}`;
    if (article.status === "published") return `发布于 ${clRelativeTime(article.published_at || article.updated_at)}`;
    // recycled 是工作流里的废弃状态，updated_at 可作为最近废弃时间展示。
    if (article.status === "recycled") return `废弃于 ${clRelativeTime(article.updated_at)}`;
    const created = `创建于 ${clRelativeTime(article.created_at)}`;
    return article.updated_at && article.created_at && article.updated_at !== article.created_at
      ? `${created} · 编辑于 ${clRelativeTime(article.updated_at)}` : created;
  }

  // ⚠️ 两个动作各包一层 .md-act-slot，让主按钮、次按钮各自对齐成一条竖线。
  //    槽宽在 geo-entry.css 里覆盖成 96px（零件层那条 88px 装不下 5 字按钮），
  //    别在这里靠改文案凑宽度。
  // 行内动作统一次级 + 文字按钮，紧急度由左侧色条和状态徽标表达。
  function clRowActions(article) {
    const slot = (html) => `<span class="md-act-slot">${html}</span>`;
    switch (article.status) {
      case "draft":
        return slot(`<button class="md-btn md-btn--secondary md-btn--sm" data-action="edit" data-id="${article.id}" type="button">编辑文章</button>`)
          + slot(`<button class="md-btn md-btn--ghost md-btn--sm" data-action="submit" data-id="${article.id}" type="button">提交审核</button>`);
      case "editing":
        if (!article.reviewed_at) {
          return slot(`<button class="md-btn md-btn--secondary md-btn--sm" data-action="edit" data-id="${article.id}" type="button">编辑文章</button>`)
            + slot(`<button class="md-btn md-btn--ghost md-btn--sm" data-action="submit" data-id="${article.id}" type="button">提交审核</button>`);
        }
        return slot(`<button class="md-btn md-btn--secondary md-btn--sm" data-action="view-note" data-id="${article.id}" type="button">看退回意见</button>`)
          + slot(`<button class="md-btn md-btn--ghost md-btn--sm" data-action="edit" data-id="${article.id}" type="button">编辑文章</button>`);
      case "pending":
        return slot(`<button class="md-btn md-btn--secondary md-btn--sm" data-action="approve" data-id="${article.id}" type="button">通过</button>`)
          + slot(`<button class="md-btn md-btn--ghost md-btn--sm" data-action="reject" data-id="${article.id}" type="button">退回</button>`);
      case "approved":
        if (article.distribution_blocked_reason) {
          return slot(`<button class="md-btn md-btn--secondary md-btn--sm" data-action="view" data-id="${article.id}" type="button">看原因</button>`)
            + slot(`<button class="md-btn md-btn--ghost md-btn--sm" data-action="edit" data-id="${article.id}" type="button">编辑文章</button>`);
        }
        return slot(`<button class="md-btn md-btn--secondary md-btn--sm" data-action="publish" data-id="${article.id}" type="button">选渠道发布</button>`)
          + slot(`<button class="md-btn md-btn--ghost md-btn--sm" data-action="edit" data-id="${article.id}" type="button">编辑文章</button>`);
      case "published":
        return slot(`<button class="md-btn md-btn--secondary md-btn--sm" data-action="view" data-id="${article.id}" type="button">查看</button>`)
          + slot(`<button class="md-btn md-btn--ghost md-btn--sm" data-action="export" data-id="${article.id}" type="button">导出微信</button>`);
      // 缺口 A 的另一半：进得去也要出得来。POST /articles/{id}/restore
      // 这个端点从 2026-07 起就在，前端从没调过。
      // ⚠️ 文案必须是「转为草稿」：restore() 固定把状态设回 draft，
      //    不是恢复到回收前的原状态。写「恢复」是对客户撒谎。
      case "recycled":
        return slot(`<button class="md-btn md-btn--secondary md-btn--sm" data-action="restore" data-id="${article.id}" type="button">转为草稿</button>`);
      default:
        return "";
    }
  }

  function clRowHtml(article) {
    const badge = clStatusBadge(article);
    const priority = clPriorityAttr(article);
    // 两种旗子含义不同，不能合成一个：
    //   ! 黄 = flagged_sensitive，命中敏感词，系统只提示、不阻止，你自己复核
    //   ✕ 红 = distribution_blocked_reason，出站闸门已经拦下，站点上没有这篇
    let flag = "";
    if (article.distribution_blocked_reason) {
      flag = `<span class="cl2-blocked-flag" title="已被出站闸门拦下，未发布到任何站点">✕</span>`;
    } else if (article.flagged_sensitive) {
      flag = `<span class="cl2-flag" title="命中敏感词，发布前请复核">!</span>`;
    }
    // 署名与作者不是一回事：author_id 是谁在系统里建的，byline_author_id 是对外署谁的名。
    const byline = article.byline_author?.name ? ` · 署名 ${escapeHtml(article.byline_author.name)}` : "";
    const wc = formatNumber(article.word_count || 0);
    return `<tr data-id="${article.id}" data-status="${escapeHtml(article.status)}" data-priority="${priority}">`
        + `<td><div class="cl2-main">${flag}<button type="button" class="article-title-link" data-action="view" data-id="${article.id}" aria-label="查看文章：${escapeHtml(articleDisplayTitle(article))}">${escapeHtml(articleDisplayTitle(article))}</button></div>`
      + `<div class="cl2-sub"><span class="cl2-cat">${escapeHtml(clCategory(article))}</span> · ${escapeHtml(clUpdateNote(article))}${byline}</div></td>`
      + `<td class="cl2-num">${wc}</td>`
      + `<td>${clMiniRail(article)}</td>`
      + `<td><span class="md-badge ${badge.cls}">${escapeHtml(badge.label)}</span></td>`
      + `<td class="cl2-sub">${escapeHtml(clRelativeTime(clSortTime(article, state.clActiveTab)))}</td>`
      + `<td class="md-col-act"><div class="md-acts">${clRowActions(article)}</div></td></tr>`;
  }

  function clSetState(name) {
    if (!elements.clHost) return;
    elements.clHost.querySelectorAll("[data-state]").forEach((s) => { s.hidden = s.dataset.state !== name; });
  }

  // tab 归属只有这一处判据 —— 列表渲染、角标计数、单行刷新三处共用。
  // 分开写过一次，结果是「待修改」角标和「待修改」列表对不上（角标只数
  // status=editing，列表还多收了一批没审过的 editing）。
  function clTabMatches(article, tab) {
    if (tab === "all") return true;
    if (tab === "draft") return article.status === "draft" || (article.status === "editing" && !article.reviewed_at);
    if (tab === "editing") return article.status === "editing" && Boolean(article.reviewed_at);
    return article.status === tab;
  }

  function clUpdateTabCounts(articles, recycledTotal) {
    const counts = { pending: 0, editing: 0, approved: 0, draft: 0, published: 0 };
    articles.forEach((a) => {
      Object.keys(counts).forEach((key) => { if (clTabMatches(a, key)) counts[key]++; });
    });
      // 与列表使用相同的文章分类；后端 total 含报告，不可作为文章总数。
    counts.all = typeof state.clAllTotal === "number" ? state.clAllTotal : articles.length;
      // 回收站也按同一分类统计。
    // 拿不到就留空，不要写 0 —— 没问过就不能断言「一篇都没有」。
    counts.recycled = recycledTotal;
      // 未拉全时所有相关角标均带 +，避免把下限冒充总数。
    const partial = state.clAllTruncated;
    elements.clTabs?.querySelectorAll("[role=tab]").forEach((tab) => {
      const key = tab.dataset.tab;
      const span = tab.querySelector(".md-tab-count");
      if (!span) return;
      if (counts[key] == null) { span.textContent = ""; return; }
      const approx = key === "recycled" ? state.clRecycledTruncated : partial;
      span.textContent = String(counts[key]) + (approx ? "+" : "");
    });
  }

  // 到了翻页上限就必须说出来。静默截断正是这一批要修的毛病本身：
  // 客户看到的是一个「完整」的列表，实际上后面的文章被吞掉了。
  function clRenderTruncNote() {
    const el = elements.clTruncNote;
    if (!el) return;
    const onRecycled = state.clActiveTab === "recycled";
    const truncated = onRecycled ? state.clRecycledTruncated : state.clAllTruncated;
    if (!truncated) { el.hidden = true; el.textContent = ""; return; }
    const shown = state.contentLibrary.length;
      el.textContent = `当前已加载 ${formatNumber(shown)} 篇文章，尚有记录未加载，数量标记“+”表示至少这些。`
      + "要找更早的，用右上角搜索框搜标题或摘要 —— 搜索是在服务器上做的，能搜到全部。";
    el.hidden = false;
  }

  // 返回分页原体（要 total 做回收站计数），行数据在 .data 里。
  // ═══════════════════════════════════════════════
  // 数据拉取（分页上限 100 × 5 页；按请求序号丢弃过期响应）
  // ═══════════════════════════════════════════════

  async function clFetchArticlesRaw({ status, search, perPage, page, projectId = state.selectedProject?.id, contentType = state.clContentType, categoryId = "" } = {}) {
    const params = new URLSearchParams();
    if (contentType) params.set("content_type", contentType);
    if (categoryId) params.set("category_id", categoryId);
    if (status) params.set("status", status);
    if (search) params.set("search", search);
    if (perPage) params.set("per_page", String(perPage));
    if (page && page > 1) params.set("page", String(page));
    const suffix = params.toString() ? `?${params.toString()}` : "";
    return api(`/api/v1/projects/${projectId}/articles${suffix}`);
  }

  function clRowsOf(body) {
    return Array.isArray(body?.data) ? body.data : (Array.isArray(body) ? body : []);
  }

  // paginate() 的顶层键；有的接口包了一层 meta，两种都认。
  function clTotalOf(body) {
    const t = body?.total ?? body?.meta?.total;
    return typeof t === "number" ? t : null;
  }
  function clLastPageOf(body) {
    return Number(body?.last_page ?? body?.meta?.last_page ?? 1) || 1;
  }

  // 一次 100 条（后端 per_page 硬上限就是 100），最多翻 5 页 = 500 篇。
  //
  // 为什么必须翻页拉全：本页的排序（已拦下 / 命中敏感词 排最前）和六个 tab 的
  // 角标全在前端算。只拿后端第一页 = 一篇很久以前被拦下的文章永远排不到第一行，
  // 角标也只统计得到那一页 —— 而这两件事恰恰是这一页存在的理由。
  //
  // 为什么还要留上限：列表接口每一行都带整篇 body_markdown，无上限地拉会把
  // 几 MB 正文搬进浏览器。到顶了**不许静默**，见 clRenderTruncNote。
  const CL_PAGE_SIZE = 100;
  const CL_MAX_PAGES = 5;

  async function clFetchAll(opts = {}) {
    let rows = [];
    let total = null;
    let page = 1;
    for (;;) {
      const body = await clFetchArticlesRaw({ ...opts, perPage: CL_PAGE_SIZE, page });
      const batch = clRowsOf(body);
      rows = rows.concat(batch);
      const t = clTotalOf(body);
      if (t !== null) total = t;
      if (batch.length === 0 || page >= clLastPageOf(body) || page >= CL_MAX_PAGES) break;
      page += 1;
    }
    return { rows, total, truncated: total !== null && rows.length < total };
  }

  async function clFetchArticles(opts = {}) {
    return clRowsOf(await clFetchArticlesRaw(opts));
  }

  async function loadContentLibrary(projectId) {
    projectId = projectId || state.selectedProject?.id;
    if (!projectId || !elements.contentLibraryPanel) return;
    const requestId = ++contentLibraryRequest;
    const contentType = state.clContentType;
    const categoryId = state.clCategoryId;
    const search = state.clSearch || "";
    const tab = state.clActiveTab;
    clSetState("loading");
    try {
      // 两条请求并行：
      //  ① 全集（不含 recycled）—— 六个常规 tab 的行数据 + 计数都从它派生，
      //     tab 过滤在前端做，避免 tab 之间互相污染计数。翻页拉到 500 篇为止。
      //  ② 回收站同样分页读取并排除报告，后端 total 混合了报告和文章。
      const wantRecycledRows = tab === "recycled";
      const [allRes, recRes] = await Promise.all([
        clFetchAll({ search, projectId, contentType, categoryId }),
        clFetchAll({ status: "recycled", search, projectId, contentType, categoryId }),
      ]);
      if (requestId !== contentLibraryRequest || Number(projectId) !== Number(state.selectedProject?.id)) return;
      const all = allRes.rows.filter(clIsArticle);
      state.clAllArticles = all;
      state.clAllTotal = all.length;
      state.clAllTruncated = allRes.truncated;
      state.clRecycledTotal = recRes.rows.filter(clIsArticle).length;
      state.clRecycledTruncated = recRes.truncated;
      state.contentLibrary = wantRecycledRows ? recRes.rows.filter(clIsArticle) : all; globalThis.GEO_SHELL?.syncArticleCount?.();
      clUpdateTabCounts(state.clAllArticles, state.clRecycledTotal);
      clRenderTruncNote();
      renderContentLibrary();
    } catch (error) {
      if (requestId !== contentLibraryRequest || Number(projectId) !== Number(state.selectedProject?.id)) return;
      fillErrorNo(elements.clErrorNo, error);
      clSetState("error");
    }
  }

  // ═══════════════════════════════════════════════
  // 列表渲染与行内动作（提交 / 通过 / 退回 / 回收站 / 恢复 / 发布 / 版本回滚 / 下载）
  // ═══════════════════════════════════════════════

  function renderContentLibrary() {
    if (!elements.clRows) return;
    const tab = state.clActiveTab;
    let list = state.contentLibrary.slice();
    if (tab !== "all") {
      list = list.filter((a) => clTabMatches(a, tab));
      list.sort((a, b) => new Date(clSortTime(b, tab)) - new Date(clSortTime(a, tab)));
    } else {
      list.sort((a, b) => clTier(a) - clTier(b) || (new Date(clTierTime(b)) - new Date(clTierTime(a))));
    }
    const hasAny = state.contentLibrary.length > 0;
    if (list.length === 0) {
      // 回收站空是个「好」状态，不是筛选没命中，文案不能共用。
      if (tab === "recycled") { clSetState("recycled-empty"); return; }
      clSetState(tab === "all" && !hasAny && !state.clSearch && !state.clContentType && !state.clCategoryId ? "empty" : "empty-filtered");
      return;
    }
    elements.clRows.innerHTML = list.map(clRowHtml).join("");
    clSetState("populated");
  }

  async function clRefreshRow(id) {
    try {
      const body = await api(`/api/v1/articles/${id}`);
      const article = body.data || body;
      const aIdx = state.clAllArticles.findIndex((a) => String(a.id) === String(id));
      if (aIdx >= 0) state.clAllArticles[aIdx] = article;
      else state.clAllArticles.push(article); globalThis.GEO_SHELL?.syncArticleCount?.();
      const idx = state.contentLibrary.findIndex((a) => String(a.id) === String(id));
      if (idx >= 0) state.contentLibrary[idx] = article;
      else state.contentLibrary.push(article);
      // ⚠️ 只有回收站 tab 的数据不是从全集派生的（它走单独一条 ?status=recycled），
      //    所以只有它需要手工把不再属于本 tab 的行摘掉。其余 tab 一律交给
      //    renderContentLibrary 里的 clTabMatches 过滤 —— 从 clAllArticles 里删
      //    是错的：文章只是换了状态、并没有消失，删掉会让「全部」角标凭空少一。
      if (state.clActiveTab === "recycled" && !clTabMatches(article, "recycled")) {
        state.contentLibrary = state.contentLibrary.filter((a) => String(a.id) !== String(id));
      }
      clUpdateTabCounts(state.clAllArticles, state.clRecycledTotal);
      renderContentLibrary();
    } catch (e) { /* 列表局部刷新失败不阻断 */ }
  }

  function clApplyArticle(article) {
    const id = String(article.id);
    const aIdx = state.clAllArticles.findIndex((a) => String(a.id) === id);
    if (aIdx >= 0) state.clAllArticles[aIdx] = article;
    else state.clAllArticles.push(article); globalThis.GEO_SHELL?.syncArticleCount?.();
    const idx = state.contentLibrary.findIndex((a) => String(a.id) === id);
    if (idx >= 0) state.contentLibrary[idx] = article;
    else state.contentLibrary.push(article);
    clUpdateTabCounts(state.clAllArticles, state.clRecycledTotal);
    renderContentLibrary();
    if (String(state.clDrawerArticle?.id) !== id || elements.clDrawerMask?.hidden) return;
    state.clDrawerArticle = article;
    clChannelLoad = { articleId: id, version: Number(article.version), status: "loading", channels: [] };
    clRenderDrawerBody(article);
    clRenderDrawerFoot(article);
    void Promise.allSettled([
      clRenderArticlePreview(article), clRenderDrawerPublications(article),
      clRenderDrawerReviews(article), clRenderDrawerVersions(article),
    ]);
  }

  async function clReviewTransition(id, path, payload, success, failure) {
    const key = String(id);
    if (clReviewBusy.has(key)) return;
    clReviewBusy.add(key);
    elements.clRows?.querySelectorAll(`[data-id="${key}"]`).forEach((button) => { button.disabled = true; });
    elements.clDrawerFoot?.querySelectorAll("button").forEach((button) => { button.disabled = true; });
    try {
      const body = await api(`/api/v1/articles/${id}/${path}`, {
        method: "POST", ...(payload ? { headers: { "Content-Type": "application/json" }, body: JSON.stringify(payload) } : {}),
      });
      const article = body.data || body;
      clApplyArticle(article);
      clToast(success, "success");
    } catch (error) {
      clToast(error.message || failure, "danger");
    } finally {
      clReviewBusy.delete(key);
      elements.clRows?.querySelectorAll(`[data-id="${key}"]`).forEach((button) => { button.disabled = false; });
      if (String(state.clDrawerArticle?.id) === key) clRenderDrawerFoot(state.clDrawerArticle);
    }
  }

  async function clSubmitReview(id) {
    await clReviewTransition(id, "submit-review", null, "已提交审核。", "提交失败。");
  }

  async function clApprove(id) {
    await clReviewTransition(id, "approve", null, "已通过。", "通过失败。");
  }

  async function clRequestChanges(id, notes) {
    await clReviewTransition(id, "request-changes", { review_notes: notes }, "已退回，作者会收到意见。", "退回失败。");
  }

  async function clRecycle(id) {
    if (!await confirmWorkspaceAction("废弃这篇文章吗？它会保留在「已废弃」列表，不会按回收站保留期限清除；重新使用时可转为草稿并重新审核。")) return;
    try {
      await api(`/api/v1/articles/${id}/recycle`, { method: "POST" });
      clToast("文章已废弃，可在「已废弃」列表中重新转为草稿。", "success");
      state.contentLibrary = state.contentLibrary.filter((a) => String(a.id) !== String(id));
      state.clAllArticles = state.clAllArticles.filter((a) => String(a.id) !== String(id));
      if (state.clDrawerArticle && String(state.clDrawerArticle.id) === String(id)) clCloseDrawer();
      // 它确实进了回收站，角标当场 +1 / 全集 −1；下一次 loadContentLibrary 会用后端 total 校准。
      if (typeof state.clRecycledTotal === "number") state.clRecycledTotal += 1;
      if (typeof state.clAllTotal === "number") state.clAllTotal -= 1; globalThis.GEO_SHELL?.syncArticleCount?.();
      clUpdateTabCounts(state.clAllArticles, state.clRecycledTotal);
      renderContentLibrary();
    } catch (e) { clToast(e.message || "废弃文章失败。", "danger"); }
  }

  // 已废弃文章重新启用时固定进入 draft，再走审核流程。
  async function clRestore(id) {
    try {
      await api(`/api/v1/articles/${id}/restore`, { method: "POST" });
      clToast("文章已转为草稿，可以编辑并重新提交审核。", "success");
      if (state.clDrawerArticle && String(state.clDrawerArticle.id) === String(id)) clCloseDrawer();
      // 这篇要从回收站消失、同时出现在草稿里，两个列表都得重取 —— 整页重载最省心。
      await loadContentLibrary();
    } catch (e) { clToast(e.message || "恢复失败。", "danger"); }
  }

  /**
   * 一次分发到多个渠道（后端 `POST /articles/{id}/publish-batch`，上限 10 个）。
   *
   * 2026-09-22 之前前端只会调单渠道的 `publish-async` —— 后端的多渠道端点在 2026-07
   * 就在了，却一直没有调用方，界面上"一次只能发一个渠道"。
   * 部分失败时后端回 207 + errors[]，这里必须如实把失败渠道说出来，不能只说"已提交"。
   */
  async function clPublishBatch(id, channelIds) {
    if (clPublishBusy.has(String(id))) return;
    if (clChannelLoad.articleId !== String(id) || clChannelLoad.status !== "ready"
      || !clChannelLoad.channels.length) { clToast("请先确认可用发布渠道。", "danger"); return; }
    const ids = (channelIds || []).map((v) => Number(v)).filter((v) => Number.isInteger(v) && v > 0);
    if (ids.some((id) => !clChannelLoad.channels.some((channel) => Number(channel.id) === id))) {
      clToast("所选渠道已不可用，请重新打开文章。", "danger"); return;
    }
    if (!ids.length) { clToast("请先选择发布渠道。", "danger"); return; }
    clPublishBusy.add(String(id));
    elements.clDrawerFoot?.querySelectorAll('[data-cl-action="publish"]').forEach((button) => { button.disabled = true; });
    document.getElementById("clPublishBtn")?.setAttribute("disabled", "");
    try {
      const body = await api(`/api/v1/articles/${id}/publish-batch`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ channel_ids: ids, async: true }),
      });
      const submitted = Array.isArray(body.publications) ? body.publications.length : 0;
      const failed = Array.isArray(body.errors) ? body.errors : [];
      if (failed.length) {
        const reasons = failed.map((e) => e.error || "原因未提供").slice(0, 3).join("；");
        clToast(submitted
          ? `已提交 ${submitted} 个渠道，${failed.length} 个失败：${reasons}`
          : `发布失败（${failed.length} 个渠道）：${reasons}`, submitted ? "warning" : "danger");
      } else {
        clToast(`已提交到 ${submitted} 个渠道，请查看下方发布记录。`, "success");
      }
      await clRefreshRow(id);
      const refreshed = state.contentLibrary.find((a) => String(a.id) === String(id));
      if (refreshed && String(state.clDrawerArticle?.id) === String(id)) { state.clDrawerArticle = refreshed; await clOpenDrawer(refreshed, clDrawerState.trigger); }
    } catch (e) { clToast(e.message || "发布失败。", "danger"); }
    finally {
      clPublishBusy.delete(String(id));
      if (String(state.clDrawerArticle?.id) === String(id)) clRenderDrawerFoot(state.clDrawerArticle);
    }
  }

  /** 抽屉「分发去向」里勾选的渠道 id（多选）。 */
  function clSelectedChannelIds() {
    const host = document.getElementById("clChannelPicker") || (elements.clDrawerBody || document);
    return Array.from(host.querySelectorAll('input[name="clPublishChannel"]'))
      .filter((el) => el.checked)
      .map((el) => el.value);
  }

  async function clRetryPublication(article, pubId) {
    try {
      await api(`/api/v1/articles/${article.id}/publications/${pubId}/retry`, { method: "POST" });
      clToast("已重试分发。", "success");
      await clRefreshRow(article.id);
      if (String(state.clDrawerArticle?.id) === String(article.id)) await clOpenDrawer(state.contentLibrary.find((a) => String(a.id) === String(article.id)) || article, clDrawerState.trigger);
    } catch (e) { clToast(e.message || "重试失败。", "danger"); }
  }

  async function clRestoreVersion(article, versionId) {
    if (!await confirmWorkspaceAction("确定恢复到这个版本吗？这会覆盖当前正文。")) return;
    try {
      await api(`/api/v1/articles/${article.id}/versions/${versionId}/restore`, { method: "POST" });
      clToast("已恢复该版本。", "success");
      await clRefreshRow(article.id);
      await clOpenDrawer(article, clDrawerState.trigger);
    } catch (e) { clToast(e.message || "恢复失败。", "danger"); }
  }

  async function clDownload(article, kind) {
    const wechat = kind === "wechat";
    const url = `/api/v1/articles/${article.id}/export/${wechat ? "wechat" : "docx"}`;
    const filename = articleDisplayTitle(article).replace(/[<>:"/\\|?*\u0000-\u001f]/g, "-").trim() || `文章-${article.id}`;
    try {
      const response = await fetch(url, { credentials: "include", headers: authHeaders({ Accept: wechat ? "application/json" : "application/vnd.openxmlformats-officedocument.wordprocessingml.document" }) });
      if (!response.ok) throw new Error("下载失败。");
      if (wechat) {
        const exported = await response.json();
        if (typeof exported.html === "string" && exported.html.trim()) {
          const html = `<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><title>${escapeHtml(articleDisplayTitle(article))}</title></head><body>${exported.html}</body></html>`;
          downloadBlob(`${filename}.html`, new Blob([html], { type: "text/html;charset=utf-8" }));
          clToast("已下载微信排版 HTML，打开文件后复制正文到公众号编辑器。", "success");
        } else if (typeof exported.plain_text === "string" && exported.plain_text.trim()) {
          downloadBlob(`${filename}.txt`, new Blob([exported.plain_text], { type: "text/plain;charset=utf-8" }));
          clToast("富文本不可用，已下载正文纯文本。", "success");
        } else {
          throw new Error("这篇文章没有可导出的正文。");
        }
        return;
      }
      if (!(response.headers.get("Content-Type") || "").toLowerCase().startsWith("application/vnd.openxmlformats-officedocument.wordprocessingml.document")) {
        throw new Error("下载内容不是 Word 文档，请重试。");
      }
      const blob = await response.blob();
      downloadBlob(`${filename}.docx`, blob);
      clToast("已开始下载。", "success");
    } catch (e) { clToast(e.message || "下载失败。", "danger"); }
  }

  function clDownloadById(id, kind) {
    const a = state.contentLibrary.find((x) => String(x.id) === String(id));
    if (a) clDownload(a, kind);
  }

  // ═══════════════════════════════════════════════
  // 素材库（Materials）— P0 Curate UI + P1 消费加深
  // 四铁律：契约先行 / 禁静默 fallback / 写后回读 / 建真实路径
  // ═══════════════════════════════════════════════

  const MAT_TYPES = ["title", "keyword", "image", "author"];
  const MAT_ENDPOINT = { title: "titles", keyword: "keywords", image: "images", author: "authors" };
  const MAT_LABEL = { title: "标题", keyword: "关键词", image: "图片", author: "作者" };

  function matEndpoint(type) { return MAT_ENDPOINT[type]; }

  function matMessage(message, variant = "neutral") {
    setMessage(document.getElementById("matMessage"), message, variant);
  }

  function matSetState(name) {
    const host = document.getElementById("matHost");
    if (!host) return;
    host.querySelectorAll("[data-state]").forEach((s) => { s.hidden = s.dataset.state !== name; });
  }

  async function loadMaterials() {
    const type = state.materialsType;
    const genBtn = document.getElementById("matGenerateButton");
    if (genBtn) genBtn.hidden = type !== "title";
    matSetState("loading");
    try {
      const body = await api(`/api/v1/materials/${matEndpoint(type)}`);
      state.materialsLists[type] = Array.isArray(body.data) ? body.data : [];
      renderMaterials();
    } catch (e) {
      const code = errorNoText(e);
      const node = document.getElementById("matErrorNo");
      if (node) node.textContent = code;
      matSetState("error");
      matMessage(`素材加载失败（${code}）：${e.message || "未知错误"}`, "danger");
    }
  }

  function renderMaterials() {
    const type = state.materialsType;
    const list = state.materialsLists[type] || [];
    const head = document.getElementById("matHead");
    const rows = document.getElementById("matRows");
    if (!head || !rows) return;
    head.innerHTML = matHeadHtml(type);
    if (list.length === 0) { matSetState("empty"); return; }
    rows.innerHTML = list.map((item) => matRowHtml(item, type)).join("");
    matSetState("populated");
  }

  function matHeadHtml(type) {
    if (type === "title") return `<tr><th>标题</th><th>备注</th><th>分类</th><th>使用次数</th><th class="cl-cell-actions">操作</th></tr>`;
    if (type === "keyword") return `<tr><th>关键词</th><th>备注</th><th>分类</th><th>搜索量</th><th>使用次数</th><th class="cl-cell-actions">操作</th></tr>`;
    if (type === "image") return `<tr><th>预览</th><th>URL</th><th>标题</th><th>来源</th><th class="cl-cell-actions">操作</th></tr>`;
    return `<tr><th>姓名</th><th>简介</th><th>头像</th><th class="cl-cell-actions">操作</th></tr>`;
  }

  function matRowHtml(item, type) {
    const esc = escapeHtml;
    const id = item.id;
    const actions = `<td class="cl-cell-actions"><button type="button" class="md-btn md-btn--secondary md-btn--sm" data-mat-action="edit" data-id="${id}">编辑</button> <button type="button" class="md-btn md-btn--ghost md-btn--sm" data-mat-action="delete" data-id="${id}">删除</button></td>`;
    if (type === "title") {
      return `<tr><td>${esc(item.title)}</td><td>${esc(item.note || "")}</td><td>${esc(item.category || "")}</td><td>${item.usage_count ?? 0}</td>${actions}</tr>`;
    }
    if (type === "keyword") {
      return `<tr><td>${esc(item.keyword)}</td><td>${esc(item.note || "")}</td><td>${esc(item.category || "")}</td><td>${item.search_volume ?? ""}</td><td>${item.usage_count ?? 0}</td>${actions}</tr>`;
    }
    if (type === "image") {
      const thumb = item.url ? `<img src="${esc(item.url)}" alt="${esc(item.alt || "")}" class="mat-thumb">` : "—";
      return `<tr><td>${thumb}</td><td><a href="${esc(item.url)}" target="_blank" rel="noopener">${esc(item.url)}</a></td><td>${esc(item.title || "")}</td><td>${esc(item.source || "")}</td>${actions}</tr>`;
    }
    const avatar = item.avatar_url ? `<img src="${esc(item.avatar_url)}" alt="${esc(item.name)}" class="mat-thumb">` : "—";
    return `<tr><td>${esc(item.name)}</td><td>${esc((item.bio || "").slice(0, 80))}</td><td>${avatar}</td>${actions}</tr>`;
  }

  function matFormFields(type, item) {
    const v = (key) => escapeHtml(item?.[key] ?? "");
    if (type === "title") {
      return `
        <div class="frow"><label for="material-title">标题 <span class="req">*</span></label><div class="fwrap"><input id="material-title" aria-describedby="material-title-help" name="title" required maxlength="500" value="${v("title")}" placeholder="素材标题，最多 500 字"><div class="hint" id="material-title-help">选用后会作为文章主题填入技能表单。</div></div></div>
        <div class="frow"><label for="material-note">备注</label><div class="fwrap"><textarea id="material-note" name="note" rows="2" maxlength="2000" placeholder="可选">${v("note")}</textarea></div></div>
        <div class="frow"><label for="material-category">分类</label><div class="fwrap"><input id="material-category" name="category" maxlength="120" value="${v("category")}" placeholder="可选，统一打标"></div></div>
        <div class="frow"><div class="fwrap"><button type="submit" class="button primary">保存</button></div></div>`;
    }
    if (type === "keyword") {
      return `
        <div class="frow"><label for="material-keyword">关键词 <span class="req">*</span></label><div class="fwrap"><input id="material-keyword" name="keyword" required maxlength="255" value="${v("keyword")}" placeholder="关键词，最多 255 字"></div></div>
        <div class="frow"><label for="material-note">备注</label><div class="fwrap"><textarea id="material-note" name="note" rows="2" maxlength="2000" placeholder="可选">${v("note")}</textarea></div></div>
        <div class="frow"><label for="material-category">分类</label><div class="fwrap"><input id="material-category" name="category" maxlength="120" value="${v("category")}" placeholder="可选"></div></div>
        <div class="frow"><label for="material-search_volume">搜索量</label><div class="fwrap"><input id="material-search_volume" aria-describedby="material-search_volume-help" name="search_volume" type="number" min="0" value="${v("search_volume")}" placeholder="可选，整数"><div class="hint" id="material-search_volume-help">预估月搜索量，仅作记录。</div></div></div>
        <div class="frow"><div class="fwrap"><button type="submit" class="button primary">保存</button></div></div>`;
    }
    if (type === "image") {
      return `
        <div class="frow"><label for="material-url">图片 URL <span class="req">*</span></label><div class="fwrap"><input id="material-url" aria-describedby="material-url-help" name="url" type="url" required maxlength="2000" value="${v("url")}" placeholder="https://..."><div class="hint" id="material-url-help">必填且须为合法 URL。</div></div></div>
        <div class="frow"><label for="material-title">标题</label><div class="fwrap"><input id="material-title" name="title" maxlength="255" value="${v("title")}" placeholder="可选，图片说明"></div></div>
        <div class="frow"><label for="material-alt">Alt 文本</label><div class="fwrap"><input id="material-alt" name="alt" maxlength="255" value="${v("alt")}" placeholder="可选，无障碍描述"></div></div>
        <div class="frow"><label for="material-source">来源</label><div class="fwrap"><input id="material-source" name="source" maxlength="120" value="${v("source")}" placeholder="可选，如 unsplash"></div></div>
        <div class="frow"><label for="material-storage">存储</label><div class="fwrap"><input id="material-storage" name="storage" maxlength="60" value="${v("storage")}" placeholder="可选，如 s3/oss"></div></div>
        <div class="frow"><div class="fwrap"><button type="submit" class="button primary">保存</button></div></div>`;
    }
    return `
      <div class="frow"><label for="material-name">姓名 <span class="req">*</span></label><div class="fwrap"><input id="material-name" name="name" required maxlength="255" value="${v("name")}" placeholder="作者姓名"></div></div>
      <div class="frow"><label for="material-bio">简介</label><div class="fwrap"><textarea id="material-bio" name="bio" rows="3" maxlength="5000" placeholder="可选，最多 5000 字">${v("bio")}</textarea></div></div>
      <div class="frow"><label for="material-avatar_url">头像 URL</label><div class="fwrap"><input id="material-avatar_url" name="avatar_url" type="url" maxlength="2000" value="${v("avatar_url")}" placeholder="可选，合法 URL"></div></div>
      <div class="frow"><label for="material-external_link">外链</label><div class="fwrap"><input id="material-external_link" name="external_link" type="url" maxlength="2000" value="${v("external_link")}" placeholder="可选，合法 URL"></div></div>
      <div class="frow"><div class="fwrap"><button type="submit" class="button primary">保存</button></div></div>`;
  }

  function matFormPayload(form, type) {
    const get = (n) => (form.querySelector(`[name="${n}"]`)?.value ?? "").trim();
    const payload = {};
    if (type === "title") {
      payload.title = get("title");
      payload.note = get("note") || null;
      payload.category = get("category") || null;
    } else if (type === "keyword") {
      payload.keyword = get("keyword");
      payload.note = get("note") || null;
      payload.category = get("category") || null;
      const sv = get("search_volume");
      payload.search_volume = sv === "" ? null : (parseInt(sv, 10) || 0);
    } else if (type === "image") {
      payload.url = get("url");
      payload.title = get("title") || null;
      payload.alt = get("alt") || null;
      payload.source = get("source") || null;
      payload.storage = get("storage") || null;
    } else {
      payload.name = get("name");
      payload.bio = get("bio") || null;
      payload.avatar_url = get("avatar_url") || null;
      payload.external_link = get("external_link") || null;
    }
    return payload;
  }

  function openMaterialForm(type, item) {
    const panel = document.getElementById("matFormPanel");
    const form = document.getElementById("matForm");
    const titleEl = document.getElementById("matFormTitle");
    const genPanel = document.getElementById("matGeneratePanel");
    if (!panel || !form || !titleEl) return;
    state.materialsEditing = item ? { ...item } : null;
    titleEl.textContent = (item ? "编辑" : "新增") + MAT_LABEL[type];
    form.innerHTML = matFormFields(type, item);
    form.dataset.type = type;
    if (genPanel) genPanel.hidden = true;
    panel.hidden = false;
    matMessage("");
  }

  async function submitMaterialForm(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const type = form.dataset.type;
    if (!type) return;
    const required = type === "title" ? "title" : type === "keyword" ? "keyword" : type === "image" ? "url" : "name";
    const requiredVal = (form.querySelector(`[name="${required}"]`)?.value || "").trim();
    if (!requiredVal) { matMessage(`请填写必填项：${MAT_LABEL[type]} 主字段。`, "danger"); return; }
    if (!form.reportValidity()) return;

    const editing = state.materialsEditing;
    const payload = matFormPayload(form, type);
    const url = editing
      ? `/api/v1/materials/${matEndpoint(type)}/${editing.id}`
      : `/api/v1/materials/${matEndpoint(type)}`;
    const method = editing ? "PATCH" : "POST";
    if (state.matSubmitInFlight) return;
    state.matSubmitInFlight = true;
    try {
      await api(url, { method, headers: { "Content-Type": "application/json" }, body: JSON.stringify(payload) });
      matMessage(editing ? "已保存。" : "已添加。", "success");
      form.closest("#matFormPanel").hidden = true;
      state.materialsEditing = null;
      await loadMaterials(); // 写后回读：证明落库，非假 toast
    } catch (e) {
      matMessage(e.message || "保存失败。", "danger");
    } finally {
      state.matSubmitInFlight = false;
    }
  }

  async function deleteMaterial(type, id) {
    if (!await confirmWorkspaceAction("确定删除这条素材吗？删除后无法恢复。")) return;
    try {
      await api(`/api/v1/materials/${matEndpoint(type)}/${id}`, { method: "DELETE" });
      matMessage("已删除。", "success");
      await loadMaterials(); // 写后回读
    } catch (e) {
      matMessage(e.message || "删除失败。", "danger");
    }
  }

  function confirmedGeneratedTitles(body) {
    const rows = body?.data;
    if (body?.object !== "title_generation" || !Array.isArray(rows)
      || !Number.isSafeInteger(body.count) || body.count !== rows.length
      || rows.some((row) => !Number.isSafeInteger(Number(row?.id)) || Number(row.id) < 1
        || typeof row.title !== "string" || !row.title.trim())) {
      throw Object.assign(new Error("服务端未返回可确认的原标题结果，本次结果待核验。"),
        { responseParseFailed: true });
    }
    return rows;
  }

  function adoptClaimedTitleKey(request, claimedKey, ownerUserId, ownerTenantId) {
    if (!/^[A-Za-z0-9_.:-]{1,160}$/.test(String(claimedKey || ""))) {
      throw new Error("标题请求认领编号无效，本次未发送。");
    }
    if (Number(state.session?.user?.id) !== ownerUserId
      || Number(state.session?.tenant?.id) !== ownerTenantId) {
      throw new Error("账号已切换，本次未发送标题请求。");
    }
    if (request.key === claimedKey) return;
    try {
      const saved = JSON.parse(sessionStorage.getItem(request.storageKey) || "null");
      if (saved?.key !== request.key || saved.owner_user_id !== ownerUserId
        || saved.owner_tenant_id !== ownerTenantId) throw new Error("mismatched original key");
      saved.key = claimedKey;
      sessionStorage.setItem(request.storageKey, JSON.stringify(saved));
      if (JSON.parse(sessionStorage.getItem(request.storageKey) || "null")?.key !== claimedKey) {
        throw new Error("claimed key not stored");
      }
    } catch (_) {
      throw new Error("无法保存恢复的标题请求编号，本次未发送；请先核对原标题任务。");
    }
    request.key = claimedKey;
  }

  async function submitGenerateTitles(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const keywords = (form.querySelector('[name="keywords"]').value || "")
      .split("\n").map((s) => s.trim()).filter(Boolean);
    if (keywords.length === 0) {
      setMessage(form.querySelector("#matGenerateError"), "请至少填一个种子关键词（每行一个）。", "danger");
      return;
    }
    // project_id 是后端必填：生成标题会真实调模型并扣积分，扣费须挂到项目上。
    if (!state.selectedProject) {
      setMessage(form.querySelector("#matGenerateError"), "请先选择项目。", "danger");
      return;
    }
    const payload = {
      project_id: state.selectedProject.id,
      keywords,
      count: parseInt(form.querySelector('[name="count"]').value || "5", 10) || 5,
      style: form.querySelector('[name="style"]').value,
      category: (form.querySelector('[name="category"]').value || "").trim() || null,
    };
    let pendingRequest;
    let claimReady = false;
    const submitBtn = form.querySelector("#matGenerateSubmit");
    const idle = submitBtn?.textContent;
    if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = "生成中…"; }
    if (state.matGenerateInFlight) return;
    state.matGenerateInFlight = true;
    try {
      pendingRequest = pendingIdempotencyRequest("material-titles", payload);
      const ownerUserId = Number(state.session?.user?.id);
      const ownerTenantId = Number(state.session?.tenant?.id);
      const claim = await api("/api/v1/materials/titles/generate/claim", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ payload, request_key: pendingRequest.key }),
      });
      if (claim?.ok !== true || claim.object !== "title_generation_claim") {
        throw new Error("标题请求认领失败，本次未发送。");
      }
      adoptClaimedTitleKey(pendingRequest, claim.request_key, ownerUserId, ownerTenantId);
      claimReady = true;
      const body = await api("/api/v1/materials/titles/generate", {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-Idempotency-Key": pendingRequest.key },
        body: JSON.stringify(payload),
      });
      const created = confirmedGeneratedTitles(body);
      if (Number(state.session?.user?.id) !== ownerUserId
        || Number(state.session?.tenant?.id) !== ownerTenantId) {
        throw Object.assign(new Error("账号已切换，原标题结果待原账号核验。"), { responseParseFailed: true });
      }
      let seen;
      try {
        seen = await api("/api/v1/materials/titles/generate/seen", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ request_key: pendingRequest.key }),
        });
      } catch (error) {
        error.responseParseFailed = true;
        throw error;
      }
      if (seen?.ok !== true || seen.object !== "title_generation_seen") {
        throw Object.assign(new Error("标题已生成，送达确认待核验。"), { responseParseFailed: true });
      }
      if (Number(state.session?.user?.id) !== ownerUserId
        || Number(state.session?.tenant?.id) !== ownerTenantId) {
        throw Object.assign(new Error("账号已切换，原标题结果待原账号核验。"), { responseParseFailed: true });
      }
      clearPendingIdempotencyRequest(pendingRequest);
      let msg = `已生成 ${created.length} 条标题并写入标题库。`;
      if (body.fallback_used) {
        msg += `（AI 模型不可用，已用模板兜底：${body.fallback_reason || "未知原因"}）`;
        matMessage(msg, "warning");
      } else {
        matMessage(msg, "success");
      }
      form.closest("#matGeneratePanel").hidden = true;
      if (state.materialsType !== "title") { state.materialsType = "title"; matActivateTab("title"); }
      else { await loadMaterials(); }
    } catch (e) {
      if (pendingRequest && claimReady && !retainPendingIdempotencyRequest(e)) clearPendingIdempotencyRequest(pendingRequest);
      setMessage(form.querySelector("#matGenerateError"), e.message || "生成失败。", "danger");
    } finally {
      if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = idle; }
      state.matGenerateInFlight = false;
    }
  }

  async function matActivateTab(type) {
    if (!MAT_TYPES.includes(type)) return;
    state.materialsType = type;
    document.querySelectorAll("#matTabs [data-mat-tab]").forEach((t) => {
      t.setAttribute("aria-selected", t.dataset.matTab === type ? "true" : "false");
    });
    const genBtn = document.getElementById("matGenerateButton");
    if (genBtn) genBtn.hidden = type !== "title";
    const gp = document.getElementById("matGeneratePanel");
    const fp = document.getElementById("matFormPanel");
    if (gp) gp.hidden = true;
    if (fp) fp.hidden = true;
    await loadMaterials();
  }

  // ── P1 消费加深：技能面板显式选料 ──

  let matPickerType = "title";

  function matPickerLabel(type, item) {
    if (type === "title") return item.title || "";
    if (type === "keyword") return item.keyword || "";
    return item.name || "";
  }

  function matRenderSkillChips() {
    const host = document.getElementById("skillMatChips");
    if (!host) return;
    if (!state.skillMaterialRefs.length) { host.innerHTML = ""; return; }
    host.innerHTML = state.skillMaterialRefs.map((r) =>
      `<span class="mat-chip" data-chip-type="${r.type}" data-chip-id="${r.id}">${MAT_LABEL[r.type]} #${r.id}<button type="button" class="mat-chip-x" aria-label="移除">×</button></span>`
    ).join("");
  }

  async function openMatPicker() {
    let modal = document.getElementById("matPickerModal");
    if (!modal) {
      modal = document.createElement("div");
      modal.id = "matPickerModal";
      modal.className = "mat-picker-modal";
      modal.hidden = true;
      modal.innerHTML = `
        <div class="mat-picker-backdrop" data-mat-picker-close></div>
        <div class="mat-picker-dialog" role="dialog" aria-modal="true" aria-label="从素材选料">
          <div class="panel-head"><h2>从素材选料</h2><div class="spacer"></div><button class="button ghost small" data-mat-picker-close type="button">关闭</button></div>
          <div class="mat-seg-tabs md-tabs" id="matPickerTabs">
            <button class="md-tab" type="button" data-picker-tab="title" aria-selected="true">标题库</button>
            <button class="md-tab" type="button" data-picker-tab="keyword" aria-selected="false">关键词库</button>
            <button class="md-tab" type="button" data-picker-tab="author" aria-selected="false">作者库</button>
          </div>
          <div class="mat-picker-list" id="matPickerList"></div>
          <div class="panel-head"><div class="spacer"></div><button class="button secondary small" data-mat-picker-close type="button">取消</button><button class="button primary small" id="matPickerConfirm" type="button">确定</button></div>
        </div>`;
      document.body.appendChild(modal);
      // 三处关闭入口（背景遮罩 / 顶部「关闭」/ 底部「取消」）都带 data-mat-picker-close，
      // 必须用 querySelectorAll —— 单数 querySelector 只会命中 DOM 里第一个（遮罩），
      // 导致两个按钮点了没反应、只能点空白处关闭。
      modal.querySelectorAll("[data-mat-picker-close]").forEach((el) => {
        el.addEventListener("click", () => { modal.hidden = true; });
      });
      modal.querySelectorAll("#matPickerTabs [data-picker-tab]").forEach((tab) => {
        tab.addEventListener("click", () => {
          matPickerType = tab.dataset.pickerTab;
          modal.querySelectorAll("#matPickerTabs [data-picker-tab]").forEach((t) =>
            t.setAttribute("aria-selected", t.dataset.pickerTab === matPickerType ? "true" : "false"));
          matRenderPickerList();
        });
      });
      modal.querySelector("#matPickerConfirm")?.addEventListener("click", matPickerConfirm);
    }
    modal.hidden = false;
    matRenderPickerList();
  }

  async function matRenderPickerList() {
    const modal = document.getElementById("matPickerModal");
    if (!modal) return;
    const listEl = modal.querySelector("#matPickerList");
    listEl.innerHTML = `<div class="cl-skeleton"><div class="cl-sk w80"></div><div class="cl-sk w60"></div><div class="cl-sk w80"></div></div>`;
    try {
      const body = await api(`/api/v1/materials/${matEndpoint(matPickerType)}`);
      const list = Array.isArray(body.data) ? body.data : [];
      const selectedIds = state.skillMaterialRefs.filter((r) => r.type === matPickerType).map((r) => String(r.id));
      if (list.length === 0) {
        listEl.innerHTML = `<p class="muted">该素材还没有内容。先去「素材」视图添加${MAT_LABEL[matPickerType]}。</p>`;
        return;
      }
      listEl.innerHTML = list.map((item) => {
        const label = matPickerLabel(matPickerType, item);
        const checked = selectedIds.includes(String(item.id)) ? "checked" : "";
        return `<label class="mat-picker-row"><input type="checkbox" data-mat-pick-id="${item.id}" ${checked}> <span>${escapeHtml(label)}</span></label>`;
      }).join("");
    } catch (e) {
      listEl.innerHTML = `<p class="cl-state-error">加载失败：${escapeHtml(e.message || "未知错误")}</p>`;
    }
  }

  function matPickerConfirm() {
    const modal = document.getElementById("matPickerModal");
    if (!modal) return;
    const checked = Array.from(modal.querySelectorAll("#matPickerList input[data-mat-pick-id]:checked"))
      .map((cb) => ({ type: matPickerType, id: parseInt(cb.dataset.matPickId, 10) }));
    state.skillMaterialRefs = state.skillMaterialRefs.filter((r) => r.type !== matPickerType).concat(checked);
    matRenderSkillChips();
    modal.hidden = true;
  }

  // ═══════════════════════════════════════════════
  // 抽屉（正文 / 编辑 / 发布 / 版本 / 图片 / 审核记录）与关闭时的焦点回归
  // ═══════════════════════════════════════════════

  function clOpenRejectForm(id) {
    document.querySelectorAll("[data-cl-form-row]").forEach((n) => n.remove());
    const tr = elements.clRows?.querySelector(`tr[data-id="${id}"]`);
    if (!tr) return;
    const formTr = document.createElement("tr");
    formTr.setAttribute("data-cl-form-row", id);
    formTr.innerHTML = `<td colspan="6" class="cl2-form-cell">`
      + `<label class="cl2-form-label">退回意见（必填）</label>`
      + `<textarea class="md-textarea" rows="3" data-cl-reject-area placeholder="说明需要修改的地方，作者会看到这条意见"></textarea>`
      + `<div class="cl2-form-actions"><button class="md-btn md-btn--secondary md-btn--sm" data-cl-reject-cancel type="button">取消</button>`
      + `<button class="md-btn md-btn--primary md-btn--sm" data-cl-reject-confirm type="button">确认退回</button></div></td>`;
    tr.after(formTr);
    formTr.querySelector("[data-cl-reject-cancel]").addEventListener("click", () => formTr.remove());
    formTr.querySelector("[data-cl-reject-confirm]").addEventListener("click", async () => {
      const area = formTr.querySelector("[data-cl-reject-area]");
      const notes = area.value.trim();
      if (!notes) { area.classList.add("is-over"); setMessage(elements.clMessage, "退回意见不能为空。", "danger"); return; }
      formTr.remove();
      await clRequestChanges(id, notes);
    });
    formTr.querySelector("[data-cl-reject-area]").focus();
  }

  function clOpenRejectFormFromDrawer(article) {
    let sec = document.getElementById("clRejectSec");
    if (!sec) {
      sec = document.createElement("div");
      sec.className = "cl2-sec";
      sec.id = "clRejectSec";
      elements.clDrawerBody.appendChild(sec);
    }
    sec.innerHTML = `<h4>退回意见（必填）</h4>`
      + `<textarea class="md-textarea" rows="3" id="clRejectArea" placeholder="说明需要修改的地方"></textarea>`
      + `<div class="cl2-form-actions"><button class="md-btn md-btn--secondary md-btn--sm" id="clRejectCancel" type="button">取消</button>`
      + `<button class="md-btn md-btn--primary md-btn--sm" id="clRejectConfirm" type="button">确认退回</button></div>`;
    document.getElementById("clRejectCancel").addEventListener("click", () => sec.remove());
    document.getElementById("clRejectConfirm").addEventListener("click", async () => {
      const area = document.getElementById("clRejectArea");
      const notes = area.value.trim();
      if (!notes) { area.classList.add("is-over"); return; }
      sec.remove();
      await clRequestChanges(article.id, notes);
    });
    document.getElementById("clRejectArea").focus();
  }

  // 抽屉焦点元素由主文件声明（GEO.clDrawerState），这里只读写它的属性。
  async function clOpenDrawer(article, trigger) {
    const request = ++clDrawerRequest;
    clChannelLoad = { articleId: String(article.id), version: Number(article.version), status: "loading", channels: [] };
    clDrawerState.trigger = trigger || null;
    state.clDrawerArticle = article;
    if (elements.clDrawerTitle) elements.clDrawerTitle.textContent = articleDisplayTitle(article);
    if (elements.clDrawerSub) elements.clDrawerSub.textContent = "";
    if (elements.clDrawerBody) elements.clDrawerBody.innerHTML = `<div class="cl2-loading">加载中…</div>`;
    if (elements.clDrawerFoot) elements.clDrawerFoot.innerHTML = "";
    // ⚠️ 开关挂在遮罩上，不再挂 #clDrawer —— 零件层的 .md-scrim 是 fixed 的
    //    flex 容器、面板是它的子元素，藏面板只会留下一层糊满屏幕的遮罩。
    if (elements.clDrawerMask) elements.clDrawerMask.hidden = false;
    elements.clDrawerClose?.focus();
    try {
      const body = await api(`/api/v1/articles/${article.id}`);
      if (request !== clDrawerRequest) return;
      const full = body.data || body;
      state.clDrawerArticle = full;
      clRenderDrawerBody(full);
      clRenderDrawerFoot(full);
      await Promise.allSettled([
        clRenderDrawerPublications(full),
        clRenderArticlePreview(full),
        clRenderDrawerReviews(full),
        clRenderDrawerVersions(full),
        clRenderDrawerImages(full),
      ]);
    } catch (e) {
      if (request !== clDrawerRequest) return;
      if (elements.clDrawerBody) elements.clDrawerBody.innerHTML = `<div class="md-empty md-empty--sm"><div class="md-empty-title">文章详情加载失败</div><p>${escapeHtml(e.message || "")}</p></div>`;
    }
  }

  async function clOpenDrawerById(id, trigger) {
    const article = state.contentLibrary.find((a) => String(a.id) === String(id));
    if (!article) return;
    await clOpenDrawer(article, trigger);
  }

  async function clOpenEditorById(id, trigger) {
    const request = ++clDrawerRequest;
    const article = state.contentLibrary.find((a) => String(a.id) === String(id));
    if (!article) return;
    clDrawerState.trigger = trigger || null;
    state.clDrawerArticle = article;
    if (elements.clDrawerTitle) elements.clDrawerTitle.textContent = "编辑文章";
    if (elements.clDrawerSub) elements.clDrawerSub.textContent = "加载中…";
    if (elements.clDrawerBody) elements.clDrawerBody.innerHTML = `<div class="cl2-loading">加载中…</div>`;
    if (elements.clDrawerFoot) elements.clDrawerFoot.innerHTML = "";
    if (elements.clDrawerMask) elements.clDrawerMask.hidden = false;
    elements.clDrawerClose?.focus();
    try {
      const body = await api(`/api/v1/articles/${article.id}`);
      if (request !== clDrawerRequest) return;
      const full = body.data || body;
      state.clDrawerArticle = full;
      clRenderDrawerEditor(full);
    } catch (e) {
      if (request !== clDrawerRequest) return;
      if (elements.clDrawerBody) elements.clDrawerBody.innerHTML = `<div class="md-empty md-empty--sm"><div class="md-empty-title">文章编辑器加载失败</div><p>${escapeHtml(e.message || "")}</p></div>`;
    }
  }

  function clRenderDrawerEditor(article) {
    if (elements.clDrawerTitle) elements.clDrawerTitle.textContent = "编辑文章";
    if (elements.clDrawerSub) elements.clDrawerSub.textContent = `${clCategory(article)} · ${formatNumber(article.word_count || 0)} 字`;
    if (elements.clDrawerBody) {
      elements.clDrawerBody.innerHTML = `
        <form class="form-grid article-edit-form" id="clArticleEditForm">
          ${article.status === "approved" ? '<p class="span-2 cl2-hint">修改已通过的文章会撤销本次审核；保存后请重新提交审核。</p>' : ""}
          <label class="span-2">
            标题
            <input name="title" maxlength="500" value="${escapeHtml(article.title || "")}">
          </label>
          <label class="span-2">
            摘要
            <textarea name="summary" rows="3" maxlength="2000">${escapeHtml(article.summary || "")}</textarea>
          </label>
          <label class="span-2">
            正文
            <textarea name="body_markdown" rows="20">${escapeHtml(article.body_markdown || "")}</textarea>
          </label>
          <p class="form-message span-2" id="clArticleEditMessage"></p>
        </form>`;
      elements.clDrawerBody.querySelector("#clArticleEditForm")?.addEventListener("submit", (event) => clSaveDrawerArticle(event, article.id));
    }
    if (elements.clDrawerFoot) {
      elements.clDrawerFoot.innerHTML = `<button class="md-btn md-btn--secondary md-btn--sm" data-cl-action="cancel-edit" type="button">取消</button>`
        + `<span class="cl2-foot-spacer"></span>`
        + `<button class="md-btn md-btn--primary md-btn--sm" form="clArticleEditForm" type="submit">保存修改</button>`;
    }
  }

  async function clSaveDrawerArticle(event, articleId) {
    event.preventDefault();
    const form = event.currentTarget;
    const message = form.querySelector("#clArticleEditMessage");
    const data = new FormData(form);
    const payload = {
      title: String(data.get("title") || "").trim(),
      summary: String(data.get("summary") || "").trim(),
      body_markdown: String(data.get("body_markdown") || ""),
    };
    setMessage(message, "正在保存…");
    try {
      const body = await api(`/api/v1/articles/${articleId}`, {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const article = body.data || body;
      state.clDrawerArticle = article;
      const allIndex = state.clAllArticles.findIndex((item) => String(item.id) === String(article.id));
      if (allIndex >= 0) state.clAllArticles[allIndex] = article; globalThis.GEO_SHELL?.syncArticleCount?.();
      const listIndex = state.contentLibrary.findIndex((item) => String(item.id) === String(article.id));
      if (listIndex >= 0) state.contentLibrary[listIndex] = article;
      clUpdateTabCounts(state.clAllArticles, state.clRecycledTotal);
      renderContentLibrary();
      clToast("文章已保存，可以继续核实或提交审核。", "success");
      await clOpenDrawer(article, clDrawerState.trigger);
    } catch (e) {
      setMessage(message, validationMessage(e), "danger");
    }
  }

  function clCloseDrawer() {
    clDrawerRequest++;
    clChannelLoad = { articleId: null, version: null, status: "loading", channels: [] };
    // 旧实现靠 body.cl-drawer-open 驱动 .cl-drawer 的 transform 过渡；
    // 迁到零件层后进场由 .md-scrim / .md-sheet 自带的 animation 负责，
    // 这个 body class 已经没人读了，留着只会让下一个人以为它还有用。
    // 零件层的进场动画是 CSS animation（不是 transition），关的时候没有回放，
    // 所以不用等 200ms —— 拖着只会让遮罩多糊 200ms。
    if (elements.clDrawerMask) elements.clDrawerMask.hidden = true;
    if (clDrawerState.trigger && clDrawerState.trigger.focus) clDrawerState.trigger.focus();
    state.clDrawerArticle = null;
  }

  function clRenderDrawerBody(full) {
    const badge = clStatusBadge(full);

    if (elements.clDrawerSub) {
      elements.clDrawerSub.textContent = `${clCategory(full)} · ${formatNumber(full.word_count || 0)} 字 · 创建于 ${clRelativeTime(full.created_at)} · 编辑于 ${clRelativeTime(full.updated_at)}`;
    }

    // 头部 meta：状态 + 流转 + 署名 + 质量分。
    // 缺口 C/D（2026-09-03）：quality_score 与 byline_author 后端一直返回、前端零引用。
    // ⚠️ 两者为空时整项不显示 —— 不显示「0」也不显示「—」，那是在断言一个没测过的值。
    const meta = `<div class="cl2-meta">`
      + `<span class="md-badge ${badge.cls}">${escapeHtml(badge.label)}</span>`
      + clMiniRail(full)
      + (full.byline_author?.name ? `<span>署名 ${escapeHtml(full.byline_author.name)}</span>` : "")
      + (clHasQualityScore(full) ? `<span>质量分 <b class="md-amount">${escapeHtml(String(full.quality_score))}</b></span>` : "")
      + `</div>`;

    // 缺口 B（2026-09-03）：出站被拦下的作者可见落点。放在最上面 ——
    // 它回答的是「我的稿子为什么没上线」，是客户带着问题来时唯一想找的东西。
    // ⚠️ 用裸 .md-alert：零件层里它本身就是 danger 配色。
    //    没有 .md-alert--danger 这个修饰类，写了会静默退化。
    // ⚠️ 被拦下时**不要**同时显示「系统只做提示、不会阻止」——在一篇真被拦下的
    //    文章上那句话是错的。
    // ⚠️ distribution_blocked_reason 的值只可能是那句固定文案或 null，绝不含
    //    命中的词。不要解析它、不要拼词表、不要试图告诉客户是哪个词。
    let alert = "";
    if (full.distribution_blocked_reason) {
      alert = `<div class="md-alert"><div><b>这篇没有发布出去。</b>`
        + `${escapeHtml(full.distribution_blocked_reason)} 改完正文后重新提交，系统会再检查一次。</div></div>`;
    } else if (full.flagged_sensitive) {
      alert = `<div class="md-alert md-alert--warning"><div><b>命中敏感词，发布前请人工复核。</b>`
        + `系统只做提示，不会阻止通过或发布——这是唯一一道人工关卡。</div></div>`;
    }

    const review = (full.status === "editing" && full.reviewed_at)
      ? `<div class="cl2-sec"><h4>审核退回意见</h4><div class="cl2-note"><p>${escapeHtml(full.review_notes || "（无文字说明）")}</p>`
        + `<div class="cl2-note-meta">${escapeHtml(full.reviewer?.name || "审核人")} · ${escapeHtml(clRelativeTime(full.reviewed_at))}</div></div></div>`
      : "";

    const preview = `<div class="cl2-doc"><h3>${escapeHtml(articleDisplayTitle(full))}</h3>`
      + (full.summary ? `<p><strong>摘要</strong>：${escapeHtml(full.summary)}</p>` : "")
      + `<div id="clArticlePreview" class="cl2-markdown"><p class="cl2-hint">正在加载正文预览…</p></div></div>`;

    if (elements.clDrawerBody) {
      elements.clDrawerBody.innerHTML = meta + alert + review + preview
        + `<div class="cl2-sec" id="clDistSec"><h4>分发去向</h4><div class="cl2-hint">加载中…</div></div>`
        + `<div class="cl2-sec" id="clRevSec"><h4>审核记录</h4><div class="cl2-hint">加载中…</div></div>`
        + `<div class="cl2-sec" id="clVerSec"><h4>版本记录</h4><div class="cl2-hint">加载中…</div></div>`
        + `<div class="cl2-sec" id="clImgSec" hidden><h4>配图</h4><div class="cl2-imgs" id="clImgGrid"></div></div>`;
    }
  }

  async function clRenderArticlePreview(article) {
    const host = document.getElementById("clArticlePreview");
    if (!host) return;
    try {
      const preview = await api(`/api/v1/articles/${article.id}/preview`);
      if (!host.isConnected || String(state.clDrawerArticle?.id) !== String(article.id)
        || Number(state.clDrawerArticle?.version) !== Number(preview.version)) return;
      host.innerHTML = preview.html || '<p class="cl2-hint">暂时没有正文。</p>';
    } catch (error) {
      if (host.isConnected && String(state.clDrawerArticle?.id) === String(article.id)) {
        host.innerHTML = `<p class="cl2-hint">预览加载失败：${escapeHtml(error.message || "请重试")}</p>`;
      }
    }
  }

  function clRenderDrawerFoot(full) {
    if (!elements.clDrawerFoot) return;
    const docx = `<button class="md-btn md-btn--secondary md-btn--sm" data-cl-action="download-docx" type="button">下载 docx</button>`;
    let primary = "";
    if (full.status === "pending") {
      primary = `<button class="md-btn md-btn--secondary md-btn--sm" data-cl-action="reject" type="button">退回</button><button class="md-btn md-btn--primary md-btn--sm" data-cl-action="approve" type="button">通过</button>`;
    } else if (full.status === "editing") {
      primary = `<button class="md-btn md-btn--secondary md-btn--sm" data-cl-action="edit" type="button">编辑文章</button>`
        + `<button class="md-btn md-btn--primary md-btn--sm" data-cl-action="${full.reviewed_at ? "resubmit" : "submit"}" type="button">${full.reviewed_at ? "重新提交" : "提交审核"}</button>`;
    } else if (full.status === "approved" && full.distribution_blocked_reason) {
      primary = `<button class="md-btn md-btn--primary md-btn--sm" data-cl-action="edit" type="button">编辑文章</button>`;
    } else if (full.status === "approved") {
      const available = clChannelLoad.articleId === String(full.id) && clChannelLoad.status === "ready"
        && clChannelLoad.channels.length > 0 && !clPublishBusy.has(String(full.id));
      primary = `<button class="md-btn md-btn--secondary md-btn--sm" data-cl-action="edit" type="button">编辑文章</button>`
        + `<button class="md-btn md-btn--primary md-btn--sm" data-cl-action="publish" type="button" ${available ? "" : "disabled"}>网站分发</button>`;
    } else if (full.status === "draft") {
      primary = `<button class="md-btn md-btn--secondary md-btn--sm" data-cl-action="edit" type="button">编辑文章</button>`
        + `<button class="md-btn md-btn--primary md-btn--sm" data-cl-action="submit" type="button">提交审核</button>`;
    } else if (full.status === "published") {
      primary = `<button class="md-btn md-btn--secondary md-btn--sm" data-cl-action="export" type="button">导出微信</button>`;
    } else if (full.status === "recycled") {
      primary = `<button class="md-btn md-btn--primary md-btn--sm" data-cl-action="restore" type="button">转为草稿</button>`;
    }
    elements.clDrawerFoot.innerHTML = docx + `<span class="cl2-foot-spacer"></span>` + primary;
  }

  function publicationResultLink(publication) {
    if (publication.status !== "published" || publication.channel?.type === "hosted_page" || !publication.remote_url) return "";
    try {
      const url = new URL(publication.remote_url, window.location.origin);
      if (!["https:", "http:"].includes(url.protocol) || url.username || url.password) return "";
      return `<a class="md-btn md-btn--secondary md-btn--sm" href="${escapeHtml(url.href)}" target="_blank" rel="noopener noreferrer">查看发布页面</a>`;
    } catch { return ""; }
  }

  async function clRenderDrawerPublications(full) {
    const sec = document.getElementById("clDistSec");
    if (!sec) return;
    if (full.status !== "approved" && full.status !== "published") { sec.hidden = true; return; }
    sec.hidden = false;
    sec.innerHTML = '<h4>分发去向</h4><p class="cl2-hint">正在加载发布信息...</p>';
    clChannelLoad = { articleId: String(full.id), version: Number(full.version), status: "loading", channels: [] };
    clRenderDrawerFoot(full);
    try {
      const chBody = await api(`/api/v1/publishing/project-channels?project_id=${encodeURIComponent(full.project_id)}`);
      if (!sec.isConnected || String(state.clDrawerArticle?.id) !== String(full.id)
        || Number(state.clDrawerArticle?.version) !== Number(full.version)) return;
      if (full.status === "approved") {
        const channels = (chBody.channels || []).filter((c) => c.is_active === true && c.publish_ready !== false);
        clChannelLoad = { articleId: String(full.id), version: Number(full.version), status: "ready", channels };
        clRenderDrawerFoot(full);
        if (!channels.length) {
          sec.innerHTML = `<h4>发布目标</h4><p class="cl2-hint">当前项目未绑定可用的网站接口。可以使用下方已绑定的平台账号，也可前往发布管理配置。</p><button class="md-btn md-btn--secondary md-btn--sm" type="button" id="clGoPublishing">前往发布管理绑定渠道</button>`;
          sec.querySelector("#clGoPublishing")?.addEventListener("click", () => clGoPublishing(full));
        } else {
          const boxes = channels.map((c) => `<label class="cl2-channel-opt"><input type="checkbox" name="clPublishChannel" value="${escapeHtml(String(c.id))}"> <span>${escapeHtml(c.name || c.display_name || ("渠道 " + c.id))}</span></label>`).join("");
          const scope = "仅显示当前项目明确绑定的可用网站接口，请明确勾选本次目标。";
          sec.innerHTML = `<h4>分发去向</h4><p class="cl2-hint">当前文章 v${escapeHtml(String(full.version || 1))} · ${scope}</p><div class="cl2-channel-picker" id="clChannelPicker">${boxes}</div><div class="cl2-picker"><button class="md-btn md-btn--secondary md-btn--sm" id="clPublishBtn" type="button">发布到选中渠道</button></div><p class="cl2-hint">可勾选多个渠道（一次最多 10 个），提交后异步分发；目标确认公开后才显示「已发布」，仅接收或保存草稿会单独标注。</p>`;
          document.getElementById("clPublishBtn").addEventListener("click", () => {
            clPublishBatch(full.id, clSelectedChannelIds());
          });
        }
      } else {
        sec.innerHTML = '<h4>继续发布到平台</h4><p class="cl2-hint">已发布文章仍可准备其他平台的发布单；同账号、同版本已有记录时会直接打开。</p><button class="md-btn md-btn--secondary md-btn--sm" type="button" id="clGoPublishing">绑定更多发布账号</button>';
        sec.querySelector("#clGoPublishing")?.addEventListener("click", () => clGoPublishing(full));
      }
      clRenderManualTargets(sec, full, chBody.manual_accounts || []);
    } catch (e) {
      if (!sec.isConnected || String(state.clDrawerArticle?.id) !== String(full.id)
        || Number(state.clDrawerArticle?.version) !== Number(full.version)) return;
      clChannelLoad = { articleId: String(full.id), version: Number(full.version), status: "error", channels: [] };
      clRenderDrawerFoot(full);
      sec.innerHTML = `<h4>分发去向</h4><p class="cl2-hint">渠道加载失败，请重试。</p><button class="md-btn md-btn--secondary md-btn--sm" type="button" id="clRetryChannels">重新加载渠道</button>`;
      sec.querySelector("#clRetryChannels")?.addEventListener("click", () => clRenderDrawerPublications(full));
    }
    if (!sec.isConnected || String(state.clDrawerArticle?.id) !== String(full.id)) return;
    const history = document.createElement("div");
    sec.appendChild(history);
    try {
      const pubBody = await api(`/api/v1/articles/${full.id}/publications`);
      if (!history.isConnected || String(state.clDrawerArticle?.id) !== String(full.id)
        || Number(state.clDrawerArticle?.version) !== Number(full.version)) return;
      const pubs = pubBody.data || pubBody || [];
      history.innerHTML = pubs.length ? pubs.map(clPublicationMarkup).join("") : '<p class="cl2-hint">暂无分发记录。</p>';
      history.querySelectorAll("[data-cl-retry-pub]").forEach((b) => b.addEventListener("click", () => clRetryPublication(full, b.dataset.clRetryPub)));
      if (document.getElementById('manualPublishMenuLink')?.hidden === false) {
        const manualBody = await api(`/api/v1/manual-publications?article_id=${encodeURIComponent(full.id)}`);
        if (!history.isConnected || String(state.clDrawerArticle?.id) !== String(full.id)) return;
        const records = manualBody.data?.data || manualBody.data || [];
        const list = document.createElement("div");
        list.innerHTML = records.length ? '<h4>平台发布记录</h4>' + records.map((record) => {
          const label = record.status === "completed" ? "已回填发布链接" : ({ draft: "草稿", ready: "待发布", in_progress: "进行中", failed: "失败", cancelled: "已取消", skipped: "已跳过" }[record.status] || record.status);
          const href = `/manual-publish?project_id=${encodeURIComponent(full.project_id)}&publication_id=${Number(record.id)}#sec-detail`;
          return `<div class="cl2-kv"><span>${escapeHtml(record.account?.account_name || record.platform)} · ${escapeHtml(label)}</span><a class="md-btn md-btn--secondary md-btn--sm" href="${href}">查看发布单 #${Number(record.id)}</a></div>`;
        }).join("") : '';
        history.appendChild(list);
      }
    } catch {
      if (history.isConnected) history.innerHTML = '<p class="cl2-hint">发布记录加载失败，请重新打开详情。</p>';
    }
  }
  function clRenderManualTargets(section, article, accounts) {
    if (!accounts.length) return;
    const host = document.createElement("div");
    host.innerHTML = '<h4>第三方平台账号</h4><p class="cl2-hint">创建发布单会准备当前审核版本的内容，随后在平台确认发布并回填公开链接。</p>' + accounts.map((account) =>
      `<button class="md-btn md-btn--secondary md-btn--sm" type="button" data-manual-account="${Number(account.id)}">${escapeHtml(account.platform_label)} · ${escapeHtml(account.account_name)}：准备发布</button>`).join("");
    const message = document.createElement("p");
    message.className = "cl2-hint";
    message.setAttribute("role", "status");
    host.appendChild(message);
    host.querySelectorAll("[data-manual-account]").forEach((button) => button.addEventListener("click", async () => {
      button.disabled = true;
      message.textContent = "正在准备发布单…";
      try {
        const result = await api(`/api/v1/articles/${article.id}/manual-publications`, {
          method: "POST", headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ account_id: Number(button.dataset.manualAccount), article_version: Number(article.version) }),
        });
        if (!host.isConnected || String(state.clDrawerArticle?.id) !== String(article.id)) return;
        window.location.href = `/manual-publish?project_id=${encodeURIComponent(article.project_id)}&publication_id=${Number(result.data.id)}#sec-detail`;
      } catch (error) {
        message.textContent = validationMessage(error);
      } finally { button.disabled = false; }
    }));
    section.appendChild(host);
  }

  function clPublicationMarkup(p) {
    const labels = { queued: "排队中", publishing: "分发中", accepted: "目标已接收，尚未公开", published: "已发布", withdrawn: "已撤下", takedown: "已撤下", failed: "失败" };
    const variant = p.status === "failed" || p.status === "withdrawn" || p.status === "takedown" ? "danger" : p.status === "published" ? "success" : "warning";
    const retryable = p.status === "failed" || (p.status === "accepted" && p.channel?.type === "wordpress_rest");
    const retry = retryable ? `<button class="md-btn md-btn--secondary md-btn--sm" data-cl-retry-pub="${escapeHtml(p.id)}" type="button">${p.status === "accepted" ? "重新提交到目标" : "重试"}</button>` : "";
    const link = p.status === "published" ? publicationResultLink(p) : "";
    return `<div class="cl2-kv"><span class="cl2-k">${escapeHtml(p.channel?.name || p.channel_name || ("渠道 " + p.channel_id))}</span><span class="cl2-v"><span class="md-badge md-badge--${variant}">${escapeHtml(labels[p.status] || p.status || "状态未知")}</span>${link}${retry}</span></div>`;
  }

  async function clGoPublishing(article) {
    state.clPublishingReturnArticleId = article.id;
    clCloseDrawer();
    await openWorkspaceView("publishing");
    setMessage(elements.publishingMessage, `当前项目：${state.selectedProject?.name || "—"} · 从文章 #${article.id}（v${article.version || 1}）前往配置发布渠道。`);
    const back = document.createElement("button");
    back.type = "button";
    back.className = "md-btn md-btn--secondary md-btn--sm";
    back.textContent = "返回这篇文章";
    back.addEventListener("click", async () => {
      await openWorkspaceView("content-library");
      const body = await api(`/api/v1/articles/${article.id}`);
      await clOpenDrawer(body.data || body, back);
    });
    elements.publishingMessage?.appendChild(back);
  }

  // 审核状态 → 文案。⚠️ ArticleReview 定义了四个常量，但今天只有前三个真的会被
  // 写入（published 是定义了没人写的）。读不出来的值一律照原值显示，
  // 不许杜撰「按审核意见修改」这类句子。
  const CL_REVIEW_LABEL = { submitted: "提交审核", approved: "审核通过", changes_requested: "退回修改" };

  function clReviewLabel(review) {
    // ⚠️ 「移入回收站」会伪装成「退回修改」：recycle() 在改完状态之后调了
    //    recordReview(author_id, STATUS_CHANGES_REQUESTED, '已移入回收站')，
    //    所以一篇被回收过的文章，reviews 里会多一条 changes_requested。
    //    直接按状态映射会显示成「张三 退回修改：已移入回收站」——
    //    把一次归档说成一次审核退回。必须按 note 内容特判。
    //    这条只有读后端代码才看得出来，接口响应体里两者长得一模一样。
    const note = String(review.review_note || "").trim();
    if (review.review_status === "changes_requested" && ["已移入回收站", "已废弃"].includes(note)) {
      return { label: "废弃文章", note: "" };
    }
    return { label: CL_REVIEW_LABEL[review.review_status] || review.review_status || "—", note };
  }

  // 缺口 C 之二（2026-09-03）：GET /articles/{id}/reviews 这个端点从 2026-07 起
  // 就在，前端从没调过。在此之前抽屉只在 status=editing && reviewed_at 时显示
  // **最后一条**退回意见 —— 已通过的文章看不到当初的意见，被打回过三次的
  // 也看不出打回过三次。
  async function clRenderDrawerReviews(full) {
    const sec = document.getElementById("clRevSec");
    if (!sec) return;
    try {
      const body = await api(`/api/v1/articles/${full.id}/reviews`);
      const reviews = body.data || body || [];
      if (!reviews.length) { sec.innerHTML = `<h4>审核记录</h4><p class="cl2-hint">还没有审核记录。</p>`; return; }
      sec.innerHTML = `<h4>审核记录</h4><ul class="cl2-hist">` + reviews.map((r) => {
        const t = clReviewLabel(r);
        return `<li><span class="cl2-h-who">${escapeHtml(r.reviewer_name || "系统")}</span>`
          + `<span class="cl2-h-what">${escapeHtml(t.label)}${t.note ? "：" + escapeHtml(t.note) : ""}</span>`
          + `<span class="cl2-h-at">${escapeHtml(clRelativeTime(r.created_at))}</span></li>`;
      }).join("") + `</ul>`;
    } catch (e) {
      sec.innerHTML = `<h4>审核记录</h4><p class="cl2-hint">审核记录加载失败。</p>`;
    }
  }

  async function clRenderDrawerVersions(full) {
    const sec = document.getElementById("clVerSec");
    if (!sec) return;
    try {
      const vBody = await api(`/api/v1/articles/${full.id}/versions`);
      const versions = vBody.data || vBody || [];
      if (!versions.length) { sec.hidden = true; return; }
      sec.hidden = false;
      sec.innerHTML = `<h4>版本记录</h4>` + versions.map((v) => {
        const isCurrent = String(v.version) === String(full.version);
        const restore = isCurrent ? `<span class="cl2-hint">当前版本</span>` : `<button class="md-btn md-btn--secondary md-btn--sm" data-cl-restore-version="${escapeHtml(v.id)}" type="button">恢复此版</button>`;
        const who = v.creator_name || (v.created_by_user_id ? "用户#" + v.created_by_user_id : "系统");
        return `<div class="cl2-kv"><span class="cl2-k">v${escapeHtml(v.version || v.id)} · ${escapeHtml(clRelativeTime(v.created_at))} · ${escapeHtml(who)}</span><span class="cl2-v">${restore}</span></div>`;
      }).join("");
      sec.querySelectorAll("[data-cl-restore-version]").forEach((b) => b.addEventListener("click", () => clRestoreVersion(full, b.dataset.clRestoreVersion)));
    } catch (e) { sec.hidden = true; }
  }

  async function clRenderDrawerImages(full) {
    const sec = document.getElementById("clImgSec");
    if (!sec) return;
    try {
      const iBody = await api(`/api/v1/articles/${full.id}/images`);
      const images = iBody.data || iBody || [];
      if (!images.length) { sec.hidden = true; return; }
      sec.hidden = false;
      const grid = document.getElementById("clImgGrid");
      if (grid) grid.innerHTML = images.map((img) => `<div class="cl2-img"><div class="cl2-img-ph">${escapeHtml(img.title || img.alt || "配图")}</div><div class="cl2-img-cap">${escapeHtml(img.title || img.alt || "")}</div></div>`).join("");
    } catch (e) { sec.hidden = true; }
  }

  function clRowsClick(e) {
    const btn = e.target.closest("button[data-action]");
    if (btn) {
      const id = btn.dataset.id || btn.closest("tr")?.dataset.id;
      const action = btn.dataset.action;
      if (action === "approve") clApprove(id);
      else if (action === "reject") clOpenRejectForm(id);
      else if (action === "submit" || action === "resubmit") clSubmitReview(id);
      // ⚠️ 焦点回归要交给**按钮**，不是它所在的 <tr>：<tr> 默认不可聚焦，
      //    tr.focus() 是个空操作，关掉抽屉后焦点会掉回 body（Tab 从页首重来）。
      //    2026-09-21 第五刀补抽屉流程判据时抓出来（拆分前后都这样，属于顺带修的真 bug）。
      else if (action === "edit") clOpenEditorById(id, btn);
      else if (action === "recycle") clRecycle(id);
      else if (action === "restore") clRestore(id);
      else if (action === "publish" || action === "view" || action === "view-note") clOpenDrawerById(id, btn);
      else if (action === "export") clDownloadById(id, "wechat");
      return;
    }
    const tr = e.target.closest("tr[data-id]");
    // 点行内空白处（不是按钮）：仍然把行当触发元素 —— 这条路径本来就是鼠标专用。
    if (tr) clOpenDrawerById(tr.dataset.id, tr.querySelector("[data-action]") || tr);
  }

  function clDrawerFootClick(e) {
    const btn = e.target.closest("button[data-cl-action]");
    if (!btn) return;
    const a = state.clDrawerArticle;
    if (!a) return;
    const action = btn.dataset.clAction;
    if (action === "download-docx") clDownload(a, "docx");
    else if (action === "edit") clRenderDrawerEditor(a);
    else if (action === "cancel-edit") clOpenDrawer(a, clDrawerState.trigger);
    else if (action === "approve") clApprove(a.id);
    else if (action === "reject") clOpenRejectFormFromDrawer(a);
    else if (action === "resubmit") clSubmitReview(a.id);
    else if (action === "submit") clSubmitReview(a.id);
    else if (action === "restore") clRestore(a.id);
    else if (action === "publish") {
      clPublishBatch(a.id, clSelectedChannelIds());
    } else if (action === "export") clDownload(a, "wechat");
  }

  // ⚠️ 零件层的选中态挂 .is-on（geo-base.css: .md-app .md-tab.is-on），
  //    不是 [aria-selected="true"] 属性选择器。aria-selected 还要继续写
  //    （无障碍要它），但两个必须同时改，只改一个 tab 就看不出选中了。
  function clSelectTab(key) {
    elements.clTabs?.querySelectorAll("[role=tab]").forEach((t) => {
      const on = t.dataset.tab === key;
      t.setAttribute("aria-selected", String(on));
      t.classList.toggle("is-on", on);
    });
  }
  function openArticleWriter() {
      openContentTools();
    }
  function clSyncFilters() {
    const projectId = state.selectedProject?.id;
    if (String(clFilterProjectId) !== String(projectId)) {
      state.clCategoryId = "";
      clFilterProjectId = projectId;
    }
    const category = document.getElementById("clCategoryFilter");
    if (category) category.value = state.clCategoryId || "";
    const clear = document.getElementById("clClearFilters");
    if (clear) clear.hidden = !(state.clSearch || state.clContentType || state.clCategoryId);
  }
  async function clLoadCategories() {
    const select = document.getElementById("clCategoryFilter");
    if (!select) return;
    const requestId = ++clCategoryRequest;
    const projectId = state.selectedProject?.id;
    try {
      const body = await api("/api/v1/categories");
      if (requestId !== clCategoryRequest || String(projectId) !== String(state.selectedProject?.id)) return;
      const categories = Array.isArray(body.data) ? body.data : [];
      select.innerHTML = '<option value="">全部分类</option>' + categories.map((category) => `<option value="${escapeHtml(String(category.id))}">${escapeHtml(category.name)}</option>`).join("");
      select.value = state.clCategoryId || "";
      select.hidden = categories.length === 0;
    } catch {
      if (requestId === clCategoryRequest && String(projectId) === String(state.selectedProject?.id)) select.hidden = true;
    }
  }
  function clClearFilters() {
    const typeInRoute = Boolean(state.clContentType);
    state.clSearch = "";
    state.clCategoryId = "";
    if (elements.clSearchInput) elements.clSearchInput.value = "";
    // 类型跟着地址 #content-library/<type> 走：只清 state，下次进页会被地址带回来。
    if (typeInRoute) {
      openWorkspaceView("content-library");
      return;
    }
    clSyncFilters();
    loadContentLibrary();
  }
  function initContentLibrary() {
    document.getElementById("clCategoryFilter")?.addEventListener("change", (event) => {
      state.clCategoryId = event.target.value;
      clSyncFilters();
      loadContentLibrary();
    });
    document.getElementById("clClearFilters")?.addEventListener("click", clClearFilters);
    document.getElementById("clContentType")?.addEventListener("change", (event) => {
      openExistingArticles(event.target.value);
    });
    elements.clTabs?.querySelectorAll("[role=tab]").forEach((tab) => {
      tab.addEventListener("click", () => {
        clSelectTab(tab.dataset.tab);
        state.clActiveTab = tab.dataset.tab;
        loadContentLibrary();
      });
    });
    elements.clSearchInput?.addEventListener("input", clDebounce(() => {
      state.clSearch = elements.clSearchInput.value.trim();
      clSyncFilters();
      loadContentLibrary();
    }, 300));
      elements.clNewArticleButton?.addEventListener("click", openArticleWriter);
      elements.clEmptyGoSkill?.addEventListener("click", () => {
      openContentTools();
    });
    elements.skillContextBackLink?.addEventListener("click", () => openWorkspaceView("content-library"));
    elements.clEmptyBackAll?.addEventListener("click", () => {
      clSelectTab("all");
      state.clActiveTab = "all";
      state.clSearch = "";
      if (elements.clSearchInput) elements.clSearchInput.value = "";
      clSyncFilters();
      loadContentLibrary();
    });
    elements.clRetryButton?.addEventListener("click", () => loadContentLibrary());
    elements.clRows?.addEventListener("click", clRowsClick);
    elements.clDrawerClose?.addEventListener("click", clCloseDrawer);
    // ⚠️ 遮罩现在包着面板，点面板内部也会冒泡到这里。只认「点的就是遮罩本身」，
    //    否则点面板里任何空白都会把抽屉关掉。
    elements.clDrawerMask?.addEventListener("click", (e) => {
      if (e.target === elements.clDrawerMask) clCloseDrawer();
    });
    elements.clDrawerFoot?.addEventListener("click", clDrawerFootClick);
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && elements.clDrawerMask && !elements.clDrawerMask.hidden) clCloseDrawer();
    });
    elements.clDrawer?.addEventListener("keydown", (e) => {
      if (e.key !== "Tab") return;
      const focusables = elements.clDrawer.querySelectorAll('button:not([disabled]), [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
      if (!focusables.length) return;
      const first = focusables[0];
      const last = focusables[focusables.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });
  }

  // 素材库（Materials）视图事件绑定
  document.getElementById("matAddButton")?.addEventListener("click", () => openMaterialForm(state.materialsType, null));
  document.getElementById("matGenerateButton")?.addEventListener("click", () => {
    const gp = document.getElementById("matGeneratePanel");
    const fp = document.getElementById("matFormPanel");
    if (fp) fp.hidden = true;
    if (gp) { gp.hidden = !gp.hidden; setMessage(document.getElementById("matGenerateError"), ""); }
    matMessage("");
  });
  document.getElementById("matCancelFormButton")?.addEventListener("click", () => {
    const p = document.getElementById("matFormPanel");
    if (p) p.hidden = true;
    state.materialsEditing = null;
  });
  document.getElementById("matCancelGenerateButton")?.addEventListener("click", () => {
    const p = document.getElementById("matGeneratePanel");
    if (p) p.hidden = true;
  });
  document.getElementById("matForm")?.addEventListener("submit", submitMaterialForm);
  document.getElementById("matGenerateForm")?.addEventListener("submit", submitGenerateTitles);
  document.getElementById("matRetryButton")?.addEventListener("click", loadMaterials);
  document.querySelectorAll("#matTabs [data-mat-tab]").forEach((tab) =>
    tab.addEventListener("click", () => matActivateTab(tab.dataset.matTab)));
  document.getElementById("matRows")?.addEventListener("click", (e) => {
    const btn = e.target.closest("[data-mat-action]");
    if (!btn) return;
    const type = state.materialsType;
    const id = btn.dataset.id;
    if (btn.dataset.matAction === "edit") {
      const item = (state.materialsLists[type] || []).find((x) => String(x.id) === String(id));
      if (item) openMaterialForm(type, item);
    } else if (btn.dataset.matAction === "delete") {
      deleteMaterial(type, id);
    }
  });
  // 技能面板选料 chips 移除（skillBriefPanel 稳定容器，事件委托）
  elements.skillBriefPanel?.addEventListener("click", (e) => {
    const x = e.target.closest(".mat-chip-x");
    if (!x) return;
    const chip = x.closest(".mat-chip");
    const type = chip?.dataset.chipType;
    const id = parseInt(chip?.dataset.chipId || "0", 10);
    state.skillMaterialRefs = state.skillMaterialRefs.filter((r) => !(r.type === type && r.id === id));
    matRenderSkillChips();
  });
  // ═══════════════════════════════════════════════
  // 注册与绑定（拆分前这些都在 geo-entry.js 里）
  // ═══════════════════════════════════════════════

  // 进视图时的数据加载：主文件统一按视图取（runViewLoaders），这里登记自己不写 if。
  GEO.registerViewLoader("content-library", () => {
    clSyncFilters();
    return Promise.all([loadContentLibrary(), clLoadCategories()]);
  });
  GEO.registerViewLoader("materials", () => loadMaterials());

  // 本块自己的事件绑定在文件加载时直接跑（拆分文件在 geo-entry.js 之后加载，
  // 主文件的 bootstrap 那时已经跑完，所以不能指望主文件回头来调它）。
  initContentLibrary();

  // 主文件还要用的入口（结果页跳转、技能面板选料 chips、技能提示词抽屉共用抽屉）。
  Object.assign(GEO, {
    clFetchAll,
    clOpenDrawer,
    clIsArticle,
    loadContentLibrary,
    loadMaterials,
    matMessage,
    openMaterialForm,
    submitMaterialForm,
    deleteMaterial,
    submitGenerateTitles,
    matActivateTab,
    matRenderSkillChips,
    openMatPicker,
    clCloseDrawer,
    clSelectTab,
    initContentLibrary, openArticleWriter,
  });
})();
