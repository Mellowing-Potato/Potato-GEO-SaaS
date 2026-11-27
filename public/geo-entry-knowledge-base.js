/* 知识库材料：上传、检索、查看与删除。依赖 window.GEO。
 * 详情关闭、切页签或切项目时停止轮询；保持原 API 和表单契约。 */
(() => {
  const GEO = window.GEO;
  if (!GEO) {
    console.error('[geo-entry-knowledge-base] window.GEO 未就绪：请确认本文件在 geo-entry.js 之后加载');
    return;
  }
  const {
    state, elements, api, escapeHtml, formatNumber, setMessage, validationMessage,
    confirmWorkspaceAction, tenantSubdomain, authHeaders, recentProjectStorageKey,
    loadKnowledgeStats, loadKnowledgeBases,
    pendingIdempotencyRequest, clearPendingIdempotencyRequest, retainPendingIdempotencyRequest,
    // ⚠️ 这三个是静态护栏 tests/js/entry-split-scope.test.mjs 抓出来的：它们只在
    //    "打开知识库详情 → 渲染文档列表"这条路上用到，真浏览器指纹当时只跑到列表页，
    //    没覆盖到，所以差点漏过去。
    formatKnowledgeDate, KB_DOC_STATUS_LABELS, KB_DOC_STATUS_VARIANTS,
  } = GEO;

  // KB 文档轮询的定时器表：只被这一块使用（拆分前它住在主文件的 state 区旁边，
  // 但主文件里没有任何其它地方用它 —— 依赖扫描漏掉它是因为只扫了"被调用的名字"，
  // 没扫裸变量引用，结果 stopKbPolling 抛 ReferenceError 把整个 selectProject 带崩）。
  const kbDocPollers = new Map();

  // ═══════════════════════════════════════════════
  // 详情开关（打开时拉文档并起轮询，关闭时停轮询）
  // ═══════════════════════════════════════════════

  async function openKnowledgeBase(kbId) {
    state.selectedKbId = kbId;
    GEO.refreshKbImportButtons();
    const kb = state.knowledgeBases.find((k) => k.id === kbId);
    if (!kb) return;
    if (elements.kbList) elements.kbList.style.display = "none";
    if (elements.kbDetail) elements.kbDetail.hidden = false;
    if (elements.kbDetailTitle) elements.kbDetailTitle.textContent = kb.name || "知识库详情";
    setMessage(elements.kbDetailMessage, "");
    await loadKbDocuments(kbId);
    // B7/§7：打开详情时加载项目列表，渲染归属下拉（0/1/多项目三态见 renderKbProjectSelectors）
    await loadKbProjects();
    // 轮询此前只在「上传/摄入成功」之后启动。用户刷新页面或退出再进详情，
    // 还在排队的文档就永远停在「排队中」不动，得手动点刷新。这里补上：
    // 只要列表里还有没跑完的，进来就接着盯。
    if ((state.kbDocuments || []).some((doc) => doc.status === "pending" || doc.status === "processing")) {
      pollKbDocuments();
    }
  }

  function closeKnowledgeBaseDetail() {
    state.selectedKbId = null;
    GEO.refreshKbImportButtons();
    stopKbPolling();
    if (elements.kbList) elements.kbList.style.display = "";
    if (elements.kbDetail) elements.kbDetail.hidden = true;
  }

  // ═══ B7/§7：知识库上传的扣费归属（上传时选项目，归属挂文档）═══

  /** 记住上次选的项目（按租户子域隔离，同一个知识库通常一直传同一个项目的料）。 */
  function kbUploadProjectStorageKey() {
    return `geo:kb-upload-project:${tenantSubdomain() || "default"}`;
  }

  function rememberedKbUploadProjectId() {
    try {
      const value = Number(localStorage.getItem(kbUploadProjectStorageKey()) || 0);
      return Number.isFinite(value) && value > 0 ? value : null;
    } catch (error) {
      return null;
    }
  }

  /**
   * 从技能卡进入时，入口会把当前项目放进 project_id。这个显式上下文必须优先于
   * 浏览器里“上次上传”的记忆，否则多项目租户会把本次解析费用记到另一个项目。
   */
  function requestedKbProjectId() {
    try {
      const value = Number(new URL(window.location.href).searchParams.get("project_id") || 0);
      return Number.isFinite(value) && value > 0 ? value : null;
    } catch (error) {
      return null;
    }
  }

  function rememberKbUploadProject(id) {
    try {
      localStorage.setItem(kbUploadProjectStorageKey(), String(id));
    } catch (error) {
      // 存不进去就算了，接口校验仍在。
    }
  }

  async function loadKbProjects() {
    state.kbProjects = state.selectedProject ? [state.selectedProject] : [];
    renderKbProjectSelectors();
  }

  /**
   * 两个表单共用一套选项；三态（执行书 §7.3 前端三条）：
   *   · 1 个项目  → 自动选中并把下拉隐藏（让人从一个选项里选一个是骚扰）
   *   · 0 个项目  → 禁用上传/摄入按钮并提示「请先创建项目」，别让他传了再吃 422
   *   · 多个项目  → 显示下拉，默认选中上次选的（localStorage）
   */
  function renderKbProjectSelectors(notice = "") {
    const uploadField = document.getElementById("kbUploadProjectField");
    const uploadSelect = document.getElementById("kbUploadProjectSelect");
    const ingestField = document.getElementById("kbIngestProjectField");
    const ingestSelect = document.getElementById("kbIngestProjectSelect");
    if (!uploadField || !uploadSelect || !ingestField || !ingestSelect) return;

    const projects = state.kbProjects || [];
    const setSubmitDisabled = (form, disabled) => {
      form?.querySelector('button[type="submit"]')?.toggleAttribute("disabled", disabled);
    };

    if (projects.length === 0) {
      uploadField.hidden = true;
      ingestField.hidden = true;
      uploadSelect.innerHTML = "";
      ingestSelect.innerHTML = "";
      setSubmitDisabled(elements.kbUploadForm, true);
      setSubmitDisabled(elements.kbIngestUrlForm, true);
      const text = notice || "还没有项目。请先创建项目，再上传文档（解析费用记到项目名下）。";
      setMessage(elements.kbUploadForm?.querySelector("#kbUploadMessage"), text, "warning");
      setMessage(elements.kbIngestUrlForm?.querySelector("#kbIngestMessage"), text, "warning");
      return;
    }

    const embeddingConfigured = state.knowledgeModelStatus?.embedding_configured === true;
    setSubmitDisabled(elements.kbUploadForm, !embeddingConfigured);
    setSubmitDisabled(elements.kbIngestUrlForm, !embeddingConfigured);
    setMessage(elements.kbUploadForm?.querySelector("#kbUploadMessage"), "");
    setMessage(elements.kbIngestUrlForm?.querySelector("#kbIngestMessage"), "");
    if (notice) {
      setMessage(elements.kbUploadForm?.querySelector("#kbUploadMessage"), notice, "warning");
    }

    if (!embeddingConfigured) {
      setMessage(elements.kbUploadForm?.querySelector("#kbUploadMessage"), "未配置向量模型", "warning");
      setMessage(elements.kbIngestUrlForm?.querySelector("#kbIngestMessage"), "未配置向量模型", "warning");
    }

    const fill = (select) => {
      select.innerHTML = "";
      projects.forEach((p) => {
        const opt = document.createElement("option");
        opt.value = String(p.id);
        opt.textContent = p.name || `项目 ${p.id}`;
        select.appendChild(opt);
      });
    };
    fill(uploadSelect);
    fill(ingestSelect);

    const requested = requestedKbProjectId();
    const remembered = rememberedKbUploadProjectId();
    const defaultId = projects.some((p) => Number(p.id) === Number(requested))
      ? Number(requested)
      : projects.some((p) => Number(p.id) === Number(remembered))
        ? Number(remembered)
        : Number(projects[0].id);
    uploadSelect.value = String(defaultId);
    ingestSelect.value = String(defaultId);

    // onchange 用属性赋值而不是 addEventListener：本函数每次打开详情都会跑，
    // addEventListener 会把监听器叠一堆。
    const remember = (event) => rememberKbUploadProject(Number(event.currentTarget?.value || 0));
    uploadSelect.onchange = remember;
    ingestSelect.onchange = remember;

    const hide = projects.length === 1;
    uploadField.hidden = hide;
    ingestField.hidden = hide;
  }

  /** 当前选中的归属项目 id；表单没渲染或没选项时返回 null（调用方拿它拦提交）。 */
  function selectedKbProjectId(selectElement) {
    const value = Number(selectElement?.value || 0);
    return Number.isFinite(value) && value > 0 ? value : null;
  }

  // ═══════════════════════════════════════════════
  // 文档列表与轮询（2500ms；上限 15 分钟）
  // ═══════════════════════════════════════════════

  async function loadKbDocuments(kbId) {
    try {
      const body = await api(`/api/v1/knowledge-bases/${kbId}/documents`);
      if (Number(state.selectedKbId) !== Number(kbId)) return;
      state.kbDocuments = body.data || body || [];
      renderKbDocuments();
    } catch (error) {
      if (error.knowledgeContextChanged) return;
      setMessage(elements.kbDetailMessage, error.message || "无法加载文档列表。", "danger");
    }
  }

  const KB_POLL_INTERVAL_MS = 2500;
  const KB_POLL_MAX_MS = 15 * 60 * 1000; // 15 分钟上限，防止页面一直打接口

  /**
   * F1 上传/摄入成功后轮询文档列表：只要还有 pending/processing 就继续，
   * 全部 ready/failed 就停。离开详情页或超时自动停。
   *
   * ⚠️ statusNode 不是可选的锦上添花（2026-08-21 生产实测翻的车）：
   * 列表确实会自己变成「已解析」，但上传按钮下面那句「正在后台解析，完成后列表
   * 会自动更新」是上传成功时写的，轮询结束时没人回写它 —— 于是同一屏上，
   * 列表说「已解析」、提示说「还在解析」，用户只能相信那句话，以为卡住了。
   * 凡是由用户点击触发的轮询，都必须把结果回写到他点击时看的那个位置。
   */
  function pollKbDocuments(statusNode = null) {
    const kbId = state.selectedKbId;
    if (!kbId) return;
    stopKbPolling(kbId);

    // 记下开始时还没跑完的是哪几篇：结束时才知道该给谁报结果，
    // 也不会把列表里早就存在的旧失败文档误报成"你这次传的失败了"。
    const watching = new Set(
      (state.kbDocuments || [])
        .filter((doc) => doc.status === "pending" || doc.status === "processing")
        .map((doc) => Number(doc.id))
    );

    const reportOutcome = () => {
      if (!statusNode) return;
      const done = (state.kbDocuments || []).filter((doc) => watching.has(Number(doc.id)));
      if (done.length === 0) return;

      const failed = done.find((doc) => doc.status === "failed");
      if (failed) {
        setMessage(statusNode, `解析失败：${failed.error_message || "原因未知，请重试"}`, "danger", { sticky: true });
        return;
      }
      const chunks = done.reduce((sum, doc) => sum + Number(doc.chunk_count || 0), 0);
      setMessage(statusNode, `解析完成，共 ${formatNumber(chunks)} 个切片，已可被检索。`, "success", { sticky: true });
    };

    const startedAt = Date.now();
    const tick = async () => {
      // 用户已经切走（selectedKbId 被清空或换库），立刻停，别再打接口
      if (state.selectedKbId !== kbId) { stopKbPolling(kbId); return; }
      try {
        await loadKbDocuments(kbId);
      } catch (error) {
        stopKbPolling(kbId);
        setMessage(elements.kbDetailMessage, error.message || "无法刷新文档列表。", "danger");
        return;
      }
      const stillWorking = (state.kbDocuments || []).some(
        (doc) => doc.status === "pending" || doc.status === "processing"
      );
      if (!stillWorking) {
        stopKbPolling(kbId);
        reportOutcome();
        // 统计面板（文档数/切片数/占用）也得跟着走，否则它一直停在解析前的数字，
        // 又是一处"页面自相矛盾"（2026-08-21 实测：强刷后才从 2 篇/4 切片跳到 3 篇/7 切片）。
        loadKnowledgeStats();
        return;
      }
      if (Date.now() - startedAt > KB_POLL_MAX_MS) {
        stopKbPolling(kbId);
        setMessage(statusNode || elements.kbDetailMessage, "解析时间较长，稍后刷新查看。", "warning");
      }
    };

    const timer = window.setInterval(tick, KB_POLL_INTERVAL_MS);
    kbDocPollers.set(kbId, timer);
    tick();
  }

  function stopKbPolling(kbId) {
    if (!kbId) {
      kbDocPollers.forEach((timer) => window.clearInterval(timer));
      kbDocPollers.clear();
      return;
    }
    const timer = kbDocPollers.get(kbId);
    if (timer) {
      window.clearInterval(timer);
      kbDocPollers.delete(kbId);
    }
  }

  function renderKbDocuments() {
    if (!elements.kbDocList) return;
    elements.kbDocList.innerHTML = "";
    if (state.kbDocuments.length === 0) {
      elements.kbDocList.innerHTML = '<div class="empty">还没有文档。</div>';
      return;
    }
    state.kbDocuments.forEach((doc) => {
      const item = document.createElement("div");
      item.className = "history-item";
      const statusBadge = doc.status
        ? `<br><span class="badge" data-variant="${KB_DOC_STATUS_VARIANTS[doc.status] || "neutral"}">${escapeHtml(KB_DOC_STATUS_LABELS[doc.status] || doc.status)}</span>`
        : "";
      // F3：切片数 + 失败原因（后端一直有 error_message 字段，前端此前没显示过）
      let docMeta = "";
      if (doc.status === "ready") {
        const chunks = Number(doc.chunk_count || 0);
        if (chunks > 0) {
          docMeta = `<br><span class="kb-chunk-count">${chunks} 个切片</span>`;
          // A4-1：暴露「假成功」——提取字符数 + 页数。取不到（存量文档没有该字段）
          // 就不显示这一段，不要显示 0、也不要显示「未知」（§3.A4-1）。
          const ext = doc.metadata?.extraction;
          if (ext && typeof ext.chars === "number" && ext.chars > 0) {
            let extLabel = ` · 提取 ${formatNumber(ext.chars)} 字`;
            if (ext.pages && Number(ext.pages) > 0) {
              extLabel += ` · ${ext.pages} 页`;
            }
            docMeta += `<span class="kb-chunk-count">${extLabel}</span>`;
          }
        } else {
          docMeta = `<br><span class="kb-chunk-count kb-chunk-zero">0 个切片（可能未成功解析，请重新上传）</span>`;
        }
      } else if (doc.status === "failed" && doc.error_message) {
        docMeta = `<br><span class="kb-doc-error">失败原因：${escapeHtml(doc.error_message)}</span>`;
      }
      if (doc.metadata?.vision_notice) {
        docMeta += `<br><span class="kb-doc-error">${escapeHtml(doc.metadata.vision_notice)}</span>`;
      }
      const quality = doc.metadata?.extraction_quality;
      const source = doc.source_url && /^https?:\/\//i.test(doc.source_url)
        ? `<br><a href="${escapeHtml(doc.source_url)}" target="_blank" rel="noopener noreferrer">来源：${escapeHtml(doc.source_url)}</a>` : "";
      const qualityNote = doc.status === "ready"
        ? `<br><span class="kb-chunk-count">解析完成 · 内容完整性${quality?.state === "needs_review" ? "待确认" : "未核验"}</span>`
          + (quality?.reasons || []).map((reason) => `<br><span class="kb-doc-error">${escapeHtml(reason)}</span>`).join("")
        : "";
      const archived = doc.review_status === "archived" ? '<br><span class="badge" data-variant="neutral">已归档 · 不参与默认检索</span>' : "";
      item.innerHTML = `
        <strong>${escapeHtml(doc.filename || doc.title || "未命名文档")}</strong>
        <span>${escapeHtml(doc.mime_type || "-")} · ${formatNumber(doc.file_size || 0)} 字节 · ${escapeHtml(formatKnowledgeDate(doc.created_at))}</span>${statusBadge}${docMeta}${source}${qualityNote}${archived}
        <div>
          <button class="button small" type="button" data-doc-action="view" data-doc-id="${escapeHtml(doc.id)}" data-kb-id="${escapeHtml(state.selectedKbId)}">查看</button>
          ${doc.source_type === "url" && doc.review_status !== "archived" ? `<button class="button secondary small" type="button" data-doc-action="recrawl" data-doc-id="${escapeHtml(doc.id)}">重抓</button>` : ""}
          ${doc.review_status !== "archived" ? `<button class="button secondary small" type="button" data-doc-action="archive" data-doc-id="${escapeHtml(doc.id)}">归档</button>` : ""}
          <button class="button danger small" type="button" data-doc-action="delete" data-doc-id="${escapeHtml(doc.id)}">删除</button>
        </div>
      `;
      elements.kbDocList.appendChild(item);
    });
    elements.kbDocList.querySelectorAll("[data-doc-action]").forEach((btn) => {
      btn.addEventListener("click", () => {
        if (btn.dataset.docAction === "delete") {
          deleteKbDocument(Number(btn.dataset.docId));
        } else if (btn.dataset.docAction === "recrawl") {
          recrawlKbDocument(Number(btn.dataset.docId));
        } else if (btn.dataset.docAction === "archive") {
          archiveKbDocument(Number(btn.dataset.docId));
        } else if (btn.dataset.docAction === "view") {
          viewKbDocumentChunks(Number(btn.dataset.kbId), Number(btn.dataset.docId));
        }
      });
    });
  }

  async function recrawlKbDocument(docId) {
    if (!state.selectedKbId) return;
    await GEO.submitKbImport({ loadDocuments: loadKbDocuments, pollDocuments: pollKbDocuments, action: `recrawl-${docId}`, message: elements.kbDetailMessage,
      path: `documents/${docId}/recrawl`, payload: {},
      success: "重抓已开始；原文档保留，新版本解析成功后才会替代它。" });
  }

  async function archiveKbDocument(docId) {
    if (!await confirmWorkspaceAction("归档后文档仍可查看，但不再参与默认检索。确定归档吗？")) return;
    try {
      await api(`/api/v1/knowledge-bases/${state.selectedKbId}/documents/${docId}/archive`, { method: "POST" });
      await loadKbDocuments(state.selectedKbId);
    } catch (error) { setMessage(elements.kbDetailMessage, validationMessage(error), "danger"); }
  }

  // ═══════════════════════════════════════════════
  // 上传 / URL 摄入 / 删除文档
  // ═══════════════════════════════════════════════

  // 单个文档上传上限，必须与另外两处**同时**改，改一处不生效：
  //   Dockerfile 的 php.ini（upload_max_filesize=10M / post_max_size=20M）
  //   KnowledgeBaseController::MAX_UPLOAD_KB
  // 这里这一份是纯前端预检：拦在发请求之前，用户选完文件当场就知道行不行，
  // 不用把十几 MB 传上去再被拒。服务端那两道闸门一个都没撤，前端只是提前告知。
  const KB_MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

  function humanFileSize(bytes) {
    return bytes >= 1048576
      ? `${(bytes / 1048576).toFixed(1)} MB`
      : `${Math.max(1, Math.round(bytes / 1024))} KB`;
  }

  /** 超限返回一句提示，没超限返回 null。措辞与后端 uploadSizeError() 保持一致。 */
  function kbUploadSizeError(file) {
    if (!file || file.size <= KB_MAX_UPLOAD_BYTES) return null;
    return `文件约 ${humanFileSize(file.size)}，超过 ${KB_MAX_UPLOAD_BYTES / 1048576} MB 上限，请压缩或拆分后再传。`;
  }

  /**
   * 选文件的当下就给结论，并清掉上一次的提示。
   *
   * 不清旧提示会误导人：文件框已经换成新的大文件了，下面还挂着上一份的绿色
   * 「文档已上传。」，看起来像是这一份也成了。
   */
  function onKbFileSelected(event) {
    const message = elements.kbUploadForm?.querySelector("#kbUploadMessage");
    if (!message) return;
    const file = event.currentTarget?.files?.[0];
    if (!file) { setMessage(message, ""); return; }
    const sizeError = kbUploadSizeError(file);
    setMessage(message, sizeError || "", sizeError ? "danger" : "neutral");
  }

  async function uploadKbDocument(event) {
    event.preventDefault();
    if (!state.selectedKbId) return;
    const form = event.currentTarget;
    const message = form.querySelector("#kbUploadMessage");
    const fileInput = form.querySelector('input[type="file"]');
    if (!fileInput?.files?.[0]) { setMessage(message, "请选择文件。", "danger"); return; }
    // 超限的直接在本地拒掉，不发请求：服务端是 FrankenPHP 普通模式（单进程多线程），
    // 一个十几 MB 的上传会把并发线程占住、其他请求被排到后面（实测轻请求要等 3 秒以上）。
    const sizeError = kbUploadSizeError(fileInput.files[0]);
    if (sizeError) { setMessage(message, sizeError, "danger"); return; }
    // B7/§7：归属项目必带（后端 required，这里提前拦，别让用户白传一遍文件）
    const projectId = selectedKbProjectId(form.querySelector("#kbUploadProjectSelect"));
    if (!projectId) { setMessage(message, "请先创建项目，再上传文档。", "danger"); return; }
    const file = fileInput.files[0];
    await GEO.submitKbImport({ loadDocuments: loadKbDocuments, pollDocuments: pollKbDocuments, action: "upload", path: "documents", payload: { project_id: projectId }, file,
      form, message, stillSameInput: () => fileInput.files[0] === file,
      success: "文档已上传，正在后台解析，完成后这里会告诉你结果。" });
  }

  async function ingestKbUrl(event) {
    event.preventDefault();
    if (!state.selectedKbId) return;
    const form = event.currentTarget;
    const message = form.querySelector("#kbIngestMessage");
    const data = new FormData(form);
      const url = String(data.get("url") || "").trim();
      if (!url) { setMessage(message, "请输入 URL。", "danger"); return; }
      // B7/§7：归属项目必带（后端 required）
      const projectId = selectedKbProjectId(form.querySelector("#kbIngestProjectSelect"));
      if (!projectId) { setMessage(message, "请先创建项目，再摄入网页。", "danger"); return; }
    await GEO.submitKbImport({ loadDocuments: loadKbDocuments, pollDocuments: pollKbDocuments, action: "url", path: "ingest-url", payload: { url, project_id: projectId },
      form, message, stillSameInput: () => String(new FormData(form).get("url") || "").trim() === url,
      success: "网页已收下，正在后台抓取解析，完成后这里会告诉你结果。" });
  }

  async function deleteKbDocument(docId) {
    if (!state.selectedKbId || !await confirmWorkspaceAction("确定删除这篇文档吗？")) return;
    try {
      await api(`/api/v1/knowledge-bases/${state.selectedKbId}/documents/${docId}`, { method: "DELETE" });
      setMessage(elements.kbDetailMessage, "文档已删除。", "success");
      await loadKbDocuments(state.selectedKbId);
      loadKnowledgeStats();
    } catch (error) {
      setMessage(elements.kbDetailMessage, error.message || "删除失败。", "danger");
    }
  }

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
    if (!response.ok) return null;
    const blob = await response.blob();
    if (scope !== `${recentProjectStorageKey()}:${state.selectedProject?.id}`) return null;
    if (!blob || blob.size === 0) return null;
    return URL.createObjectURL(blob);
  }

  /**
   * A4-2：查看一篇文档入库后的切片正文（解析结果可见）。
   * B6-2 起对 method=vision/hybrid 的文档改走图文对照分栏（viewKbDocumentSplitView），
   * 本函数其余分支（text_layer / 存量文档）保持原样。
   *
   * 安全约束（§3.A4-2，踩过 ba5c933 的坑）：
   *   - 切片正文用 textContent 写进**专门的叶子节点**，绝不用 innerHTML 拼（防 XSS）
   *   - 绝不给容器写 textContent（会抹掉子节点 id）
   *   - 关闭按钮 + 遮罩点击都要能关
   */
  // ═══════════════════════════════════════════════
  // 检索测试（只调检索，不调模型）
  // ═══════════════════════════════════════════════

  /**
   * 第三层（检索测试框）：可视化现有 RAG 检索链路。
   *
   * 弹窗复用 kbChunkOverlay 的遮罩 + 关闭模式；不调模型、不花钱（D1 默认不做
   * 「AI 回答」）。输入问题 → POST search-test → 渲染 top-K 切片卡：双分数条
   * （vector / relevance，色阶区分）、来源文档标题 + 页码、section、正文前 200 字。
   * 空结果 / 失败都给明确文案，不静默空白。
   */
  async function openSearchTestModal() {
    const kbId = state.selectedKbId;
    if (!kbId) return;

    let overlay = document.getElementById("searchTestOverlay");
    if (!overlay) {
      overlay = document.createElement("div");
      overlay.id = "searchTestOverlay";
      overlay.className = "kb-chunk-overlay";
      overlay.style.cssText = "position:fixed;inset:0;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;z-index:1000;";
      document.body.appendChild(overlay);
    }

    // 重复打开时先拆掉上一轮的键盘监听，避免 Esc 监听越积越多。
    if (typeof overlay.closeSearchTest === "function") overlay.closeSearchTest();

    const box = document.createElement("div");
    box.className = "kb-chunk-box";

    overlay.innerHTML = "";
    overlay.appendChild(box);

    box.innerHTML = `
      <div>
        <h3>检索测试</h3>
        <button type="button" class="button small" id="searchTestCloseTop" aria-label="关闭检索测试">关闭</button>
      </div>
      <p>输入一个问题，看看知识库召回了哪些切片、分数多少、来自哪篇文档哪一页。仅验证检索链路，不调用模型回答。</p>
      <label>问题</label>
      <textarea id="searchTestQuery" rows="3" placeholder="例如：陈小聪是谁？ / 种植体多少钱？"></textarea>
      <div>
        <label>返回条数
          <select id="searchTestTopK">
            <option value="5" selected>5</option>
            <option value="10">10</option>
          </select>
        </label>
        <button type="button" class="button primary small" id="searchTestRun">检索</button>
        <span id="searchTestStatus"></span>
      </div>
      <div id="searchTestResults"></div>
      <button type="button" class="button small" id="searchTestClose">关闭</button>
    `;

    overlay.style.display = "flex";

    const runBtn = box.querySelector("#searchTestRun");
    const closeTopBtn = box.querySelector("#searchTestCloseTop");
    const closeBtn = box.querySelector("#searchTestClose");
    const status = box.querySelector("#searchTestStatus");
    const results = box.querySelector("#searchTestResults");
    const queryEl = box.querySelector("#searchTestQuery");
    const topKEl = box.querySelector("#searchTestTopK");

    const closeModal = () => {
      overlay.style.display = "none";
      document.removeEventListener("keydown", onKeydown);
    };
    const onKeydown = (event) => {
      if (event.key === "Escape") closeModal();
    };
    overlay.closeSearchTest = closeModal;
    overlay.onclick = (event) => {
      if (event.target === overlay) closeModal();
    };
    closeTopBtn.addEventListener("click", closeModal);
    closeBtn.addEventListener("click", closeModal);
    document.addEventListener("keydown", onKeydown);
    queryEl.focus();

    runBtn.addEventListener("click", async () => {
      const query = queryEl.value.trim();
      if (!query) { status.textContent = "请先输入问题。"; status.style.color = "var(--md-danger-ink)"; return; }
      runBtn.disabled = true;
      status.textContent = "检索中…";
      status.style.color = "var(--md-text-muted)";
      results.innerHTML = "";
      let pendingRequest;
      try {
        const payload = { knowledge_base_id: kbId, query, top_k: Number(topKEl.value) };
        pendingRequest = pendingIdempotencyRequest("knowledge-search-test", payload);
        const res = await api(`/api/v1/knowledge-bases/${kbId}/search-test`, {
          method: "POST",
          headers: { "Content-Type": "application/json", "X-Idempotency-Key": pendingRequest.key },
          body: JSON.stringify({ query: payload.query, top_k: payload.top_k }),
        });
        clearPendingIdempotencyRequest(pendingRequest);
        const data = res.data || {};
        const meta = data.meta || {};
        status.textContent = `召回 ${meta.total_hits ?? 0} 条 · 耗时 ${meta.duration_ms ?? "?"} ms · 维度 ${data.embedding_dim ?? "?"}`;
        status.style.color = "var(--md-success-ink)";
        renderSearchTestHits(results, data.hits || []);
      } catch (error) {
        if (pendingRequest && !retainPendingIdempotencyRequest(error)) {
          clearPendingIdempotencyRequest(pendingRequest);
        }
        status.textContent = "";
        const err = document.createElement("p");
        err.style.cssText = "color:#c0392b;font-size:var(--md-fs-xs);margin:0;";
        err.textContent = validationMessage(error) || "检索失败。";
        results.appendChild(err);
      } finally {
        runBtn.disabled = false;
      }
    });
  }

  function renderSearchTestHits(host, hits) {
    host.innerHTML = "";
    if (!Array.isArray(hits) || hits.length === 0) {
      const empty = document.createElement("p");
      empty.style.cssText = "color:#888;font-size:var(--md-fs-xs);margin:0;";
      empty.textContent = "未召回到任何切片，试试换关键词或确认文档已入库。";
      host.appendChild(empty);
      return;
    }
    hits.forEach((h) => {
      const card = document.createElement("div");


      const head = document.createElement("div");

      head.textContent = (h.document_title || "未命名文档") + (h.page ? ` · 第 ${h.page} 页` : "");
      card.appendChild(head);

      card.appendChild(searchTestScoreBar("向量分", h.vector_score));
      card.appendChild(searchTestScoreBar("相关分", h.relevance_score));

      if (h.section) {
        const sec = document.createElement("div");

        sec.textContent = `章节：${h.section}`;
        card.appendChild(sec);
      }

      const pre = document.createElement("pre");

      pre.textContent = h.content ?? "";
      card.appendChild(pre);

      host.appendChild(card);
    });
  }

  function searchTestScoreBar(label, score) {
    const wrap = document.createElement("div");

    const name = document.createElement("span");

    name.textContent = label;
    const track = document.createElement("div");

    const pct = Math.max(0, Math.min(1, Number(score) || 0)) * 100;
    const fill = document.createElement("div");
    // 色阶：高绿（黛绿 #3B7A57）/ 中琥珀 / 低红
    let color = "#c0392b";
    if (pct >= 70) color = "#3B7A57";
    else if (pct >= 40) color = "#E0A000";
    fill.style.cssText = `height:100%;width:${pct.toFixed(1)}%;background:${color};border-radius:4px;`;
    track.appendChild(fill);
    const val = document.createElement("span");

    val.textContent = (Number(score) || 0).toFixed(2);
    wrap.append(name, track, val);
    return wrap;
  }

  async function viewKbDocumentChunks(kbId, docId) {
    if (!kbId) return;
    // B6-2：vision/hybrid 文档走图文对照；text_layer（以及取不到元数据的存量文档）
    // 保持 A4-2 原来的纯文字弹窗，一行不动。
    const doc = kbDocById(docId);
    const extraction = doc?.metadata?.extraction || null;
    const method = typeof extraction?.method === "string" ? extraction.method : "text_layer";
    if (method === "vision" || method === "hybrid") {
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
        if (e.target === overlay) overlay.style.display = "none";
      });
    }

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

    const closeBtn = document.createElement("button");
    closeBtn.type = "button";
    closeBtn.className = "button small";

    closeBtn.textContent = "关闭";
    closeBtn.addEventListener("click", () => { overlay.style.display = "none"; });
    box.appendChild(closeBtn);

    overlay.style.display = "flex";

    try {
      const res = await api(`/api/v1/knowledge-bases/${kbId}/documents/${docId}/chunks?per_page=20`);
      const data = res.data || [];
      if (data.length === 0) {
        const empty = document.createElement("p");
        empty.style.cssText = "color:#888;font-size:var(--md-fs-xs);";
        empty.textContent = "这篇文档还没有可显示的切片。";
        body.appendChild(empty);
        return;
      }
      const total = res.meta?.total ?? data.length;
      data.forEach((chunk, idx) => {
        const block = document.createElement("div");


        const head = document.createElement("div");

        head.textContent = `第 ${Number(chunk.chunk_index) + 1} / ${total} 条切片`;
        block.appendChild(head);

        // 正文叶子节点：用 textContent，绝不用 innerHTML
        const pre = document.createElement("pre");

        pre.textContent = chunk.content ?? "";
        block.appendChild(pre);

        body.appendChild(block);
      });
    } catch (error) {
      const err = document.createElement("p");
      err.style.cssText = "color:#c0392b;font-size:var(--md-fs-xs);";
      err.textContent = validationMessage(error) || "加载解析内容失败。";
      body.appendChild(err);
    }
  }

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
        if (e.target === overlay) overlay.style.display = "none";
      });
    }

    // blob URL 复用与回收：翻页/关弹窗都要 revoke，不然每次翻页漏一个
    let pageObjectUrl = null;
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

    const closeBtn = document.createElement("button");
    closeBtn.type = "button";
    closeBtn.className = "button small";

    closeBtn.textContent = "关闭";
    closeBtn.addEventListener("click", () => { releasePageUrl(); overlay.style.display = "none"; });
    box.appendChild(closeBtn);

    overlay.style.display = "flex";

    // 拉全部切片（分页接口，翻页拉完为止；一次 100 条 + 安全上限 20 次）
    let chunks = [];
    try {
      let pageNo = 1;
      for (;;) {
        const res = await api(`/api/v1/knowledge-bases/${kbId}/documents/${docId}/chunks?per_page=100&page=${pageNo}`);
        const batch = res.data || [];
        chunks = chunks.concat(batch);
        const lastPage = Number(res.meta?.last_page || 1);
        if (batch.length === 0 || pageNo >= lastPage || pageNo >= 20) break;
        pageNo += 1;
      }
    } catch (error) {
      const err = document.createElement("p");
      err.style.cssText = "color:#c0392b;font-size:var(--md-fs-xs);";
      err.textContent = validationMessage(error) || "加载解析内容失败。";
      right.appendChild(err);
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
      pageLabel.textContent = `第 ${currentPage} / ${totalPages} 页`;
      prevBtn.disabled = currentPage <= 1;
      nextBtn.disabled = currentPage >= totalPages;

      // ── 左栏：页面图（带鉴权 fetch → blob URL）──
      releasePageUrl();
      left.innerHTML = "";
      const loading = document.createElement("p");
      loading.style.cssText = "font-size:var(--md-fs-xs);color:#888;";
      loading.textContent = "正在加载页面图像...";
      left.appendChild(loading);
      try {
        const url = await fetchKbPageImageUrl(kbId, docId, currentPage);
        left.innerHTML = "";
        if (url) {
          pageObjectUrl = url;
          const img = document.createElement("img");
          img.className = "kb-split-img";
          img.alt = `第 ${currentPage} 页原始页面`;
          img.src = url;
          // 新窗口打开原图（blob URL），不做缩放控件（执行书 §B6-2）
          img.addEventListener("click", () => { if (pageObjectUrl) window.open(pageObjectUrl, "_blank"); });
          left.appendChild(img);
          const tip = document.createElement("p");
          tip.className = "kb-split-imgtip";
          tip.textContent = "点击图片可在新窗口查看原图。";
          left.appendChild(tip);
        } else {
          const missing = document.createElement("p");
          missing.style.cssText = "font-size:var(--md-fs-xs);color:#b8860b;";
          missing.textContent = `第 ${currentPage} 页没有生成图像（可能渲染超限被跳过）。`;
          left.appendChild(missing);
        }
      } catch (error) {
        left.innerHTML = "";
        const missing = document.createElement("p");
        missing.style.cssText = "font-size:var(--md-fs-xs);color:#b8860b;";
        missing.textContent = `第 ${currentPage} 页图像加载失败，可点击「上一页/下一页」重试。`;
        left.appendChild(missing);
      }

      // ── 右栏：该页对应的切片正文 ──
      right.innerHTML = "";
      const pageChunks = chunks.filter((c) => Number(c.page) === currentPage);
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
      if (currentPage === totalPages && unmappedChunks.length > 0) {
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

  // ═══════════════════════════════════════════════
  // 删除知识库（列表入口，停详情与轮询）
  // ═══════════════════════════════════════════════

  async function deleteKnowledgeBase() {
    if (!state.selectedKbId || !await confirmWorkspaceAction("确定删除这个知识库吗？所有文档也会被删除。")) return;
    try {
      await api(`/api/v1/knowledge-bases/${state.selectedKbId}`, { method: "DELETE" });
      setMessage(elements.knowledgeMessage, "知识库已删除。", "success");
      closeKnowledgeBaseDetail();
      await loadKnowledgeBases();
    } catch (error) {
      setMessage(elements.knowledgeMessage, error.message || "删除失败。", "danger");
    }
  }
  elements.kbBackButton?.addEventListener("click", closeKnowledgeBaseDetail);
  elements.kbDeleteButton?.addEventListener("click", deleteKnowledgeBase);
  elements.kbUploadForm?.addEventListener("submit", uploadKbDocument);
  elements.kbUploadForm?.querySelector('input[type="file"]')?.addEventListener("change", onKbFileSelected);
  elements.kbIngestUrlForm?.addEventListener("submit", ingestKbUrl);
  elements.reloadKbDocsButton?.addEventListener("click", () => { if (state.selectedKbId) loadKbDocuments(state.selectedKbId); });
  elements.kbSearchTestButton?.addEventListener("click", openSearchTestModal);
  // 主文件的知识库列表还要用这两个入口：挂回共享命名空间（主文件用可选链调用）。
  GEO.openKnowledgeBase = openKnowledgeBase;
  GEO.viewKbDocumentChunks = viewKbDocumentChunks;
  GEO.closeKnowledgeBaseDetail = closeKnowledgeBaseDetail;
})();
