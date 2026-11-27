/* 知识材料预览：分页正文、PDF 图文对照与当前查看会话。依赖 window.GEO。 */
(() => {
  const GEO = window.GEO;
  if (!GEO) return;
  const { state, api, authHeaders, recentProjectStorageKey, validationMessage } = GEO;

  /**
   * B6-2：给 method=vision/hybrid 的文档拿「图文对照」要用的提取元数据。
   * 文档列表接口（listDocuments）返回完整模型 JSON，metadata 是 array cast，
   * 所以 state.kbDocuments 里就有。取不到就回落到 A4-2 的纯文字视图。
   */
  function kbDocById(docId) {
    return (state.kbDocuments || []).find((d) => Number(d.id) === Number(docId)) || null;
  }

  // ═══════════════════════════════════════════════
  // 切片对照与分屏（图文对照，窄屏上下排）
  // ═══════════════════════════════════════════════

  /**
   * B6-2：图文对照样式（一次性注入）。
   * 窄屏 <900px 上下排列是执行书 §B6-2 的硬性要求，内联 style 做不了 media
   * query，所以必须走 <style> 标签。
   */
  function ensureKbSplitStyles() {
    if (document.getElementById("kbSplitStyles")) return;
    const style = document.createElement("style");
    style.id = "kbSplitStyles";
    style.textContent = `
      .kb-split-wrap { display:flex; gap:14px; align-items:flex-start; }
      .kb-split-left { flex:0 0 44%; min-width:0; }
      .kb-split-right { flex:1 1 56%; min-width:0; }
      .kb-split-img { display:block; width:100%; height:auto; border:1px solid #ddd; border-radius:6px; background:#fafafa; cursor:zoom-in; }
      .kb-split-imgtip { font-size:var(--md-fs-xs); color:#666; margin-top:4px; }
      .kb-split-pagenav { display:flex; align-items:center; gap:8px; margin:8px 0 12px; flex-wrap:wrap; }
      .kb-split-pagelabel { font-size:var(--md-fs-xs); color:#333; }
      @media (max-width: 899px) {
        .kb-split-wrap { flex-direction:column; }
        .kb-split-left { flex:none; width:100%; }
      }
    `;
    document.head.appendChild(style);
  }

  /**
   * B6-2：把切片正文写进叶子节点，同时高亮 unverified_terms。
   *
   * 安全约束不变（§3.A4-2 / ba5c933）：正文只用 textContent / createTextNode
   * 写进 <pre> 叶子节点，绝不用 innerHTML 拼。高亮用 <mark> 包 term 片段，
   * mark.textContent 同样安全。
   *
   * 算法：把正文拆成 [{text, term}] 片段流。term 按长度降序处理（长的先占位，
   * 避免短词把长词切碎）。已命中 term 的片段不再参与后续切分。
   */
  function appendKbChunkText(container, text, terms, tooltip) {
    const pre = document.createElement("pre");

    const raw = String(text ?? "");
    const sortedTerms = (terms || []).filter((t) => typeof t === "string" && t.length >= 2).sort((a, b) => b.length - a.length);
    if (sortedTerms.length === 0 || raw === "") {
      pre.textContent = raw;
      container.appendChild(pre);
      return;
    }
    let fragments = [{ text: raw, term: false }];
    for (const term of sortedTerms) {
      const next = [];
      for (const frag of fragments) {
        if (frag.term || !frag.text.includes(term)) { next.push(frag); continue; }
        const parts = frag.text.split(term);
        for (let i = 0; i < parts.length; i++) {
          if (parts[i]) next.push({ text: parts[i], term: false });
          if (i < parts.length - 1) next.push({ text: term, term: true });
        }
      }
      fragments = next;
    }
    fragments.forEach((frag) => {
      if (!frag.term) { pre.appendChild(document.createTextNode(frag.text)); return; }
      const mark = document.createElement("mark");

      mark.title = tooltip;
      mark.textContent = frag.text;
      pre.appendChild(mark);
    });
    container.appendChild(pre);
  }

  // ═══════════════════════════════════════════════
  // 预览会话与私有图片生命周期
  // ═══════════════════════════════════════════════
  let activeKnowledgePreview = null;
  const knowledgePreviewScope = () => JSON.stringify([
    state.session?.tenant?.id, state.session?.user?.id, state.session?.tenant?.subdomain,
    state.selectedProject?.id, state.selectedKbId,
  ]);
  function closeKnowledgePreview() { activeKnowledgePreview?.close(); }
  function beginKnowledgePreview(overlay) {
    closeKnowledgePreview();
    overlay.closeKnowledgePreview?.();
    const scope = knowledgePreviewScope();
    const cleanup = new Set();
    let closed = false;
    const preview = {
      isCurrent() {
        if (closed) return false;
        if (activeKnowledgePreview !== preview || knowledgePreviewScope() !== scope || overlay.style.display === "none") {
          preview.close(); return false;
        }
        return true;
      },
      close() {
        if (closed) return;
        closed = true;
        cleanup.forEach(release => release()); cleanup.clear();
        if (activeKnowledgePreview === preview) {
          activeKnowledgePreview = null;
          overlay.style.display = "none";
          overlay.innerHTML = "";
        }
      },
      onClose(release) { if (closed) release(); else cleanup.add(release); },
    };
    activeKnowledgePreview = preview;
    overlay.closeKnowledgePreview = preview.close;
    return preview;
  }
  window.addEventListener?.("hashchange", closeKnowledgePreview);
  window.addEventListener?.("pagehide", closeKnowledgePreview);
  GEO.beginKnowledgePreview = beginKnowledgePreview;
  GEO.closeKnowledgePreview = closeKnowledgePreview;

  /** B6-2：带 Bearer 鉴权拉页面图（<img src> 直连带不上 Authorization 头，必须走 blob）。 */
  async function fetchKbPageImageUrl(kbId, docId, page) {
    const projectId = state.selectedProject?.id;
    if (!projectId) return null;
    const scope = `${recentProjectStorageKey()}:${projectId}`;
    const response = await fetch(`/api/v1/knowledge-bases/${kbId}/documents/${docId}/pages/${page}/image`, {
      credentials: "include",
      cache: "no-store",
      headers: authHeaders({ Accept: "image/jpeg", "X-Project-Id": String(projectId) }),
    });
    if (response.status === 404) return null;
    if (!response.ok) throw new Error("页面图像读取失败，请重试。");
    const blob = await response.blob();
    if (scope !== `${recentProjectStorageKey()}:${state.selectedProject?.id}`) return null;
    if (!blob || blob.size === 0) return null;
    return URL.createObjectURL(blob);
  }

  // ═══════════════════════════════════════════════
  // 正文切片分页
  // ═══════════════════════════════════════════════
  async function viewKbDocumentChunks(kbId, docId, forceText = false) {
    if (!kbId) return;
    // 大文档可显式退回分页文字视图，仍能访问所有切片。
    const doc = kbDocById(docId);
    const extraction = doc?.metadata?.extraction || null;
    const method = typeof extraction?.method === "string" ? extraction.method : "text_layer";
    if (!forceText && (method === "vision" || method === "hybrid")) {
      await viewKbDocumentSplitView(kbId, docId, doc, extraction);
      return;
    }

    let overlay = document.getElementById("kbChunkOverlay");
    if (!overlay) {
      overlay = document.createElement("div");
      overlay.id = "kbChunkOverlay";
      overlay.className = "kb-chunk-overlay";
      overlay.style.cssText = "position:fixed;inset:0;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;z-index:1000;";
      document.body.appendChild(overlay);
      // 遮罩点击关闭（点击遮罩本身，而非弹窗内容）
      overlay.addEventListener("click", (e) => {
        if (e.target === overlay) overlay.closeKnowledgePreview?.();
      });
    }

    const preview = GEO.beginKnowledgePreview(overlay);

    const box = document.createElement("div");
    box.className = "kb-chunk-box";

    overlay.innerHTML = "";
    overlay.appendChild(box);

    const title = document.createElement("h3");

    title.textContent = "解析内容（入库后参与检索的正文）";
    box.appendChild(title);

    // A4-3：提示语，位置在正文上方
    const hint = document.createElement("p");

    hint.textContent = "这里显示的是入库后真正参与检索的内容。与原文不一致，说明解析有问题，请重新上传或联系我们。";
    box.appendChild(hint);

    const body = document.createElement("div");
    body.id = "kbChunkBody";
    box.appendChild(body);

    const nav = document.createElement("div");
    const prev = document.createElement("button");
    prev.type = "button"; prev.className = "button small"; prev.id = "kbChunkPrev";
    prev.textContent = "← 上一页";
    const label = document.createElement("span");
    label.id = "kbChunkPageLabel";
    const next = document.createElement("button");
    next.type = "button"; next.className = "button small"; next.id = "kbChunkNext";
    next.textContent = "下一页 →";
    nav.append(prev, label, next);
    box.appendChild(nav);
    const message = document.createElement("p");
    message.id = "kbChunkPageMessage";
    box.appendChild(message);
    const retry = document.createElement("button");
    retry.type = "button"; retry.className = "button small"; retry.id = "kbChunkRetry";
    retry.textContent = "重试加载"; retry.hidden = true;
    box.appendChild(retry);

    const closeBtn = document.createElement("button");
    closeBtn.type = "button";
    closeBtn.className = "button small";

    closeBtn.textContent = "关闭";
    closeBtn.addEventListener("click", () => preview.close());
    box.appendChild(closeBtn);

    overlay.style.display = "flex";

    let currentPage = 1, lastPage = 1, requestedPage = 1, requestSequence = 0;
    const loadPage = async (page) => {
      if (!preview.isCurrent()) return;
      const sequence = ++requestSequence;
      requestedPage = page;
      prev.disabled = true; next.disabled = true; retry.hidden = true;
      message.textContent = `正在加载第 ${page} 页切片…`;
      try {
        const res = await api(`/api/v1/knowledge-bases/${kbId}/documents/${docId}/chunks?per_page=20&page=${page}`);
        if (!preview.isCurrent() || sequence !== requestSequence) return;
        if (!Array.isArray(res.data) || res.current_page !== page || !Number.isSafeInteger(res.total) || res.total < 0
          || !Number.isSafeInteger(res.last_page) || res.last_page < 1) {
          throw new Error("切片分页信息不完整，请重试加载。");
        }
        currentPage = page; lastPage = res.last_page;
        label.textContent = `第 ${currentPage} / ${lastPage} 页，共 ${res.total} 条切片`;
        body.innerHTML = "";
        if (res.data.length === 0) {
          const empty = document.createElement("p");
          empty.textContent = res.total === 0 ? "这篇文档还没有可显示的切片。" : "此页暂无切片，请返回上一页。";
          body.appendChild(empty);
        }
        res.data.forEach((chunk) => {
          const block = document.createElement("div");
          const head = document.createElement("div");
          head.textContent = `第 ${Number(chunk.chunk_index) + 1} / ${res.total} 条切片`;
          const pre = document.createElement("pre");
          pre.textContent = chunk.content ?? "";
          block.append(head, pre); body.appendChild(block);
        });
        message.textContent = "";
      } catch (error) {
        if (!preview.isCurrent() || sequence !== requestSequence) return;
        message.textContent = validationMessage(error) || "加载解析内容失败。";
        retry.hidden = false;
      } finally {
        if (preview.isCurrent() && sequence === requestSequence) {
          prev.disabled = currentPage <= 1; next.disabled = currentPage >= lastPage;
        }
      }
    };
    prev.addEventListener("click", () => { if (currentPage > 1) void loadPage(currentPage - 1); });
    next.addEventListener("click", () => { if (currentPage < lastPage) void loadPage(currentPage + 1); });
    retry.addEventListener("click", () => { void loadPage(requestedPage); });
    await loadPage(1);
  }

  // ═══════════════════════════════════════════════
  // PDF 图文对照与原图翻页
  // ═══════════════════════════════════════════════
  /**
   * B6-2：图文对照弹窗（method=vision/hybrid 专用）。
   *
   * 左栏 = 原始页面图（分页导航，点击新窗口打开原图 blob）；
   * 右栏 = 该页对应的切片正文（unverified_terms 高亮 + 悬停提示）；
   * 窄屏 <900px 上下排列（ensureKbSplitStyles 里的 media query）。
   *
   * 这是纯图 PDF 唯一的验收手段（执行书 §B6 原话），别省。
   */
  async function viewKbDocumentSplitView(kbId, docId, doc, extraction) {
    ensureKbSplitStyles();

    let overlay = document.getElementById("kbChunkOverlay");
    if (!overlay) {
      overlay = document.createElement("div");
      overlay.id = "kbChunkOverlay";
      overlay.className = "kb-chunk-overlay";
      overlay.style.cssText = "position:fixed;inset:0;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;z-index:1000;";
      document.body.appendChild(overlay);
      overlay.addEventListener("click", (e) => {
        if (e.target === overlay) overlay.closeKnowledgePreview?.();
      });
    }
    const preview = GEO.beginKnowledgePreview(overlay);

    // blob URL 复用与回收：翻页/关弹窗都要 revoke，不然每次翻页漏一个
    let pageObjectUrl = null;
    let pageRequest = 0;
    const releasePageUrl = () => {
      if (pageObjectUrl) { URL.revokeObjectURL(pageObjectUrl); pageObjectUrl = null; }
    };

    const box = document.createElement("div");
    box.className = "kb-chunk-box kb-split-box";

    overlay.innerHTML = "";
    overlay.appendChild(box);

    const title = document.createElement("h3");

    title.textContent = `图文对照 · ${doc?.filename || doc?.title || "文档"}`;
    box.appendChild(title);

    // 提取方式说明 + 提醒（失败页 / 未核对词如实告知，不静默）
    const methodLabel = method => (method === "vision" ? "全文图像识别" : "图文混合提取");
    const hintLines = [
      `提取方式：${methodLabel(extraction.method)}。左边是 PDF 原始页面，右边是识别出来、真正入库参与检索的文字。`,
    ];
    const failedPages = Array.isArray(extraction.failed_pages) ? extraction.failed_pages.filter((p) => Number(p) > 0) : [];
    if (failedPages.length > 0) {
      hintLines.push(`第 ${failedPages.map(String).join("、")} 页图像识别失败，那几页的文字来自原文字层，可能不完整。`);
    }
    const unverified = Array.isArray(extraction.unverified_terms) ? extraction.unverified_terms : [];
    if (unverified.length > 0) {
      hintLines.push(`右侧黄色高亮的 ${unverified.length} 个词只有图像识别读出来、原文字层里没有，请人工核对。`);
    }
    const hint = document.createElement("p");

    hint.textContent = hintLines.join(" ");
    box.appendChild(hint);

    const nav = document.createElement("div");
    nav.className = "kb-split-pagenav";
    box.appendChild(nav);

    const prevBtn = document.createElement("button");
    prevBtn.type = "button";
    prevBtn.className = "button small";
    prevBtn.textContent = "← 上一页";

    const pageLabel = document.createElement("span");
    pageLabel.className = "kb-split-pagelabel";

    const nextBtn = document.createElement("button");
    nextBtn.type = "button";
    nextBtn.className = "button small";
    nextBtn.textContent = "下一页 →";

    nav.append(prevBtn, pageLabel, nextBtn);

    const wrap = document.createElement("div");
    wrap.className = "kb-split-wrap";
    box.appendChild(wrap);

    const left = document.createElement("div");
    left.className = "kb-split-left";
    const right = document.createElement("div");
    right.className = "kb-split-right";
    wrap.append(left, right);
    preview.onClose(() => { pageRequest += 1; releasePageUrl(); left.innerHTML = ""; right.innerHTML = ""; });

    const closeBtn = document.createElement("button");
    closeBtn.type = "button";
    closeBtn.className = "button small";

    closeBtn.textContent = "关闭";
    closeBtn.addEventListener("click", preview.close);
    box.appendChild(closeBtn);

    overlay.style.display = "flex";

    // 图文对照只展示完整集合；超出自动读取预算时改用分页文字视图。
    let chunks = [];
    try {
      let pageNo = 1, total = null;
      for (;;) {
        if (!preview.isCurrent()) return;
        const res = await api(`/api/v1/knowledge-bases/${kbId}/documents/${docId}/chunks?per_page=100&page=${pageNo}`);
        if (!preview.isCurrent()) return;
        if (!Array.isArray(res.data) || res.current_page !== pageNo || !Number.isSafeInteger(res.total) || res.total < 0
          || !Number.isSafeInteger(res.last_page) || res.last_page < 1) {
          throw new Error("切片分页信息不完整，请重试加载。");
        }
        if (res.last_page > 20) {
          throw new Error(`文档共有 ${res.total} 条切片，超过图文对照的单次加载范围。请使用分页文字查看全部内容。`);
        }
        if (total !== null && total !== res.total) throw new Error("切片数量已变化，请重新加载后再核对。");
        total = res.total;
        chunks = chunks.concat(res.data);
        if (pageNo >= res.last_page) break;
        if (res.data.length === 0) throw new Error("切片尚未加载完整，请重试或使用分页文字查看。");
        pageNo += 1;
      }
      if (chunks.length !== total) throw new Error("切片尚未加载完整，请重试或使用分页文字查看。");
    } catch (error) {
      if (!preview.isCurrent()) return;
      prevBtn.disabled = true; nextBtn.disabled = true;
      pageLabel.textContent = "图文切片未加载完成";
      const err = document.createElement("p");
      err.style.cssText = "color:#c0392b;font-size:var(--md-fs-xs);";
      err.textContent = validationMessage(error) || "加载解析内容失败。";
      right.appendChild(err);
      const retry = document.createElement("button");
      retry.type = "button"; retry.className = "button small"; retry.textContent = "重试加载";
      retry.addEventListener("click", () => { if (preview.isCurrent()) void viewKbDocumentSplitView(kbId, docId, doc, extraction); });
      const textView = document.createElement("button");
      textView.type = "button"; textView.className = "button small"; textView.textContent = "分页查看文字";
      textView.addEventListener("click", () => { if (preview.isCurrent()) void viewKbDocumentChunks(kbId, docId, true); });
      right.append(retry, textView);
      return;
    }

    const totalPages = Math.max(
      1,
      Number(extraction.pages) || 0,
      chunks.reduce((max, c) => Math.max(max, Number(c.page) || 0), 0),
    );
    const unmappedChunks = chunks.filter((c) => c.page === null || c.page === undefined);
    const tooltip = "这个词只有图像识别读出来，原文字层里没有，请核对";
    let currentPage = 1;

    async function renderPage() {
      if (!preview.isCurrent()) return;
      const page = currentPage;
      const request = ++pageRequest;
      const isPageCurrent = () => preview.isCurrent() && request === pageRequest;
      pageLabel.textContent = `第 ${page} / ${totalPages} 页`;
      prevBtn.disabled = page <= 1;
      nextBtn.disabled = page >= totalPages;

      // ── 左栏：页面图（带鉴权 fetch → blob URL）──
      releasePageUrl();
      left.innerHTML = "";
      right.innerHTML = "";
      const loading = document.createElement("p");
      loading.style.cssText = "font-size:var(--md-fs-xs);color:#888;";
      loading.textContent = "正在加载页面图像...";
      left.appendChild(loading);
      try {
        const url = await fetchKbPageImageUrl(kbId, docId, page);
        if (!isPageCurrent()) { if (url) URL.revokeObjectURL(url); return; }
        left.innerHTML = "";
        if (url) {
          pageObjectUrl = url;
          const img = document.createElement("img");
          img.className = "kb-split-img";
          img.alt = `第 ${page} 页原始页面`;
          img.src = url;
          // 新窗口打开原图（blob URL），不做缩放控件（执行书 §B6-2）
          img.addEventListener("click", () => { if (isPageCurrent() && pageObjectUrl === url) window.open(url, "_blank"); });
          left.appendChild(img);
          const tip = document.createElement("p");
          tip.className = "kb-split-imgtip";
          tip.textContent = "点击图片可在新窗口查看原图。";
          left.appendChild(tip);
        } else {
          const missing = document.createElement("p");
          missing.style.cssText = "font-size:var(--md-fs-xs);color:#b8860b;";
          missing.textContent = `第 ${page} 页没有生成图像（可能渲染超限被跳过）。`;
          left.appendChild(missing);
        }
      } catch (error) {
        if (!isPageCurrent()) return;
        left.innerHTML = "";
        const missing = document.createElement("p");
        missing.style.cssText = "font-size:var(--md-fs-xs);color:#b8860b;";
        missing.textContent = `第 ${page} 页图像加载失败，请重试。`;
        left.appendChild(missing);
        const retry = document.createElement("button");
        retry.type = "button";
        retry.className = "button small";
        retry.textContent = "重试本页";
        retry.addEventListener("click", () => { if (isPageCurrent()) void renderPage(); });
        left.appendChild(retry);
      }

      // ── 右栏：该页对应的切片正文 ──
      right.innerHTML = "";
      const pageChunks = chunks.filter((c) => Number(c.page) === page);
      if (pageChunks.length === 0) {
        const empty = document.createElement("p");
        empty.style.cssText = "color:#888;font-size:var(--md-fs-xs);";
        empty.textContent = chunks.length === 0
          ? "这篇文档还没有可显示的切片。"
          : "本页没有对应的切片（该页文字可能并入相邻页或识别失败）。";
        right.appendChild(empty);
      } else {
        pageChunks.forEach((chunk) => {
          const block = document.createElement("div");

          const head = document.createElement("div");

          head.textContent = `切片 ${Number(chunk.chunk_index) + 1}`;
          block.appendChild(head);
          appendKbChunkText(block, chunk.content, unverified, tooltip);
          right.appendChild(block);
        });
      }
      // 未能定位到页的切片放在最后一页末尾，一次性展示（几乎不会发生，兜底）
      if (page === totalPages && unmappedChunks.length > 0) {
        const tail = document.createElement("div");

        const tailHead = document.createElement("div");

        tailHead.textContent = `以下 ${unmappedChunks.length} 条切片未能定位到具体页：`;
        tail.appendChild(tailHead);
        unmappedChunks.forEach((chunk) => {
          const block = document.createElement("div");

          appendKbChunkText(block, chunk.content, unverified, tooltip);
          tail.appendChild(block);
        });
        right.appendChild(tail);
      }
    }

    prevBtn.addEventListener("click", () => {
      if (currentPage > 1) { currentPage -= 1; renderPage(); }
    });
    nextBtn.addEventListener("click", () => {
      if (currentPage < totalPages) { currentPage += 1; renderPage(); }
    });

    await renderPage();
  }

  GEO.viewKbDocumentChunks = viewKbDocumentChunks;
})();
