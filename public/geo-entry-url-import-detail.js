/* ============================================================================
   URL 导入 · 单条详情（geo-entry-url-import-detail.js）

   后端 `GET /api/v1/url-imports/{urlImport}` 从 2026-07 就在，列表却只显示
   网页/状态/标题与失败原因三列，看不到"这次抓到了什么、生成的是哪篇草稿"。
   本文件把详情接上：点列表里的网页地址 → 拉单条记录 → 在列表下方就地展开。

   边界：只读（GET），不改状态、不重跑抓取；抓取是异步的，状态仍由列表刷新更新。
   判据：tests/js/backend-endpoint-frontend-parity.test.mjs 钉住这条端点不再孤立。
   ============================================================================ */
(() => {
  const GEO = window.GEO;
  if (!GEO) {
    console.error('[geo-entry-url-import-detail] window.GEO 未就绪：请确认本文件在 geo-entry.js 之后加载');
    return;
  }
  const { api, escapeHtml, formatDate } = GEO;
  const byId = (id) => document.getElementById(id);

  const STATUS_LABELS = {
    pending: "等待抓取",
    processing: "抓取中",
    completed: "已完成",
    failed: "抓取失败",
  };

  let detailSequence = 0;

  function detailRow(label, value) {
    if (!value) return "";
    return `<p class="muted"><strong>${escapeHtml(label)}</strong>：${escapeHtml(value)}</p>`;
  }

  function safeExternalHref(rawUrl) {
    try {
      const parsed = new URL(String(rawUrl || "").trim());
      return (parsed.protocol === "http:" || parsed.protocol === "https:") ? parsed.href : "";
    } catch {
      return "";
    }
  }

  function detailUrlRow(rawUrl) {
    const url = String(rawUrl || "").trim();
    if (!url) return "";
    const safeHref = safeExternalHref(url);
    const linkAction = safeHref
      ? ` <a class="md-link" href="${escapeHtml(safeHref)}" target="_blank" rel="noopener noreferrer">打开原网页 ↗</a>`
      : "";
    return `<p class="muted url-import-detail-url"><strong>网页地址</strong>：<span class="url-import-full-url">${escapeHtml(url)}</span>${linkAction} <button type="button" class="button ghost small" data-url-import-copy="${escapeHtml(url)}">复制链接</button></p>`;
  }

  function renderDetail(record) {
    const host = byId("urlImportDetail");
    if (!host) return;
    const status = STATUS_LABELS[record.status] || record.status || "未知";
    const wordCount = record.metadata && record.metadata.word_count;
    const quality = record.metadata?.extraction_quality;
    const article = record.article_id
      ? `<a href="#content-library" class="md-link">查看文章 #${escapeHtml(String(record.article_id))}</a>`
      : "";
    host.innerHTML = [
      detailUrlRow(record.url),
      detailRow("状态", status),
      detailRow("标题", record.title || ""),
      detailRow("失败原因", record.error_message || ""),
      detailRow("抓取字数", wordCount ? String(wordCount) : ""),
      detailRow("内容完整性", record.status === "completed" ? (quality?.state === "needs_review" ? "待确认" : "未核验") : ""),
      detailRow("提取预览", quality?.preview || ""),
      ...(quality?.reasons || []).map((reason) => detailRow("提取提示", reason)),
      detailRow("提交时间", record.created_at ? formatDate(record.created_at) : ""),
      detailRow("更新时间", record.updated_at ? formatDate(record.updated_at) : ""),
      article ? `<p>${article}</p>` : "",
      record.status === "completed" || record.status === "failed"
        ? `<p><button class="button secondary small" type="button" data-url-import-retry="${escapeHtml(record.url)}">重抓此网址</button> · 可打开生成文章人工补全。</p>` : "",
    ].join("");
  }

  async function openDetail(id) {
    const host = byId("urlImportDetail");
    const message = byId("urlImportDetailMessage");
    if (!host) return;
    const sequence = ++detailSequence;
    host.innerHTML = "";
    if (message) message.textContent = "正在读取这条导入的详情…";
    try {
      const body = await api(`/api/v1/url-imports/${encodeURIComponent(id)}`);
      if (sequence !== detailSequence) return;
      renderDetail(body?.data || {});
      if (message) message.textContent = "下面是这条导入记录的完整结果。";
    } catch (error) {
      if (sequence !== detailSequence) return;
      // 读不到就如实说：403 是权限问题，不能显示成"没有这条记录"。
      const text = error?.status === 403
        ? "当前账号没有查看 URL 导入记录的权限。"
        : `详情读取失败：${error?.message || "请重试"}。`;
      host.innerHTML = `<p class="muted">${escapeHtml(text)}</p>`;
      if (message) message.textContent = text;
    }
  }

  byId("urlImportRows")?.addEventListener("click", (event) => {
    const retry = event.target.closest("[data-url-import-retry]");
    if (retry) {
      const input = byId("urlImportUrl");
      if (input) {
        input.value = retry.dataset.urlImportRetry;
        input.focus();
        input.scrollIntoView({ block: "center" });
        const message = byId("urlImportFormMessage");
        if (message) message.textContent = "地址已填入；点击“开始导入”会创建一条新记录。";
      }
      return;
    }
    if (event.target.closest("a")) return;
    const trigger = event.target.closest("[data-url-import-detail]");
    if (!trigger) return;
    event.preventDefault();
    openDetail(trigger.dataset.urlImportDetail);
  });
  byId("urlImportDetail")?.addEventListener("click", async (event) => {
    const copyBtn = event.target.closest("[data-url-import-copy]");
    if (copyBtn) {
      const value = copyBtn.dataset.urlImportCopy || "";
      try {
        const clipboard = navigator.clipboard;
        if (typeof clipboard?.writeText !== "function") throw new Error("Clipboard unavailable");
        await clipboard.writeText(value);
        copyBtn.textContent = "已复制";
        setTimeout(() => { copyBtn.textContent = "复制链接"; }, 1800);
      } catch {
        copyBtn.textContent = "复制链接";
        const message = byId("urlImportDetailMessage");
        if (message) message.textContent = "浏览器未允许自动复制，请直接选中上方网页地址复制。";
      }
      return;
    }
    const retry = event.target.closest("[data-url-import-retry]");
    if (!retry) return;
    const input = byId("urlImportUrl");
    if (input) { input.value = retry.dataset.urlImportRetry; input.focus(); }
    const message = byId("urlImportFormMessage");
    if (message) message.textContent = "地址已填入；重新导入会保留原记录供追溯。";
  });
})();
