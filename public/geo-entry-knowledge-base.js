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
  let kbDetailVersion = 0;
  let kbDocumentRequest = 0;
  let kbDocumentPage = 1;
  let kbDocumentPagination = null;
  let kbDocumentLoading = false;
  let kbDocumentError = null;

  function kbDocumentScope() {
    return JSON.stringify([state.session?.tenant?.id, state.session?.user?.id,
      tenantSubdomain(), state.selectedProject?.id, state.selectedKbId, kbDetailVersion]);
  }

  // ═══════════════════════════════════════════════
  // 详情开关（打开时拉文档并起轮询，关闭时停轮询）
  // ═══════════════════════════════════════════════

  async function openKnowledgeBase(kbId) {
    const kb = state.knowledgeBases.find((k) => k.id === kbId);
    if (!kb) return;
    GEO.closeKnowledgePreview?.();
    stopKbPolling();
    kbDetailVersion++;
    state.selectedKbId = kbId;
    state.kbDocuments = [];
    kbDocumentPage = 1;
    kbDocumentPagination = null;
    kbDocumentError = null;
    GEO.refreshKbImportButtons();
    const scope = kbDocumentScope();
    if (elements.kbList) elements.kbList.style.display = "none";
    if (elements.kbDetail) elements.kbDetail.hidden = false;
    if (elements.kbDetailTitle) elements.kbDetailTitle.textContent = kb.name || "知识库详情";
    setMessage(elements.kbDetailMessage, "");
    await loadKbDocuments(kbId, 1);
    if (scope !== kbDocumentScope()) return;
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
    GEO.closeKnowledgePreview?.();
    kbDetailVersion++;
    kbDocumentRequest++;
    kbDocumentLoading = false;
    kbDocumentPage = 1;
    kbDocumentPagination = null;
    kbDocumentError = null;
    state.selectedKbId = null;
    state.kbDocuments = [];
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

  async function loadKbDocuments(kbId, page = kbDocumentPage, { polling = false } = {}) {
    if (Number(state.selectedKbId) !== Number(kbId) || (polling && kbDocumentLoading)) return false;
    page = Math.max(1, Math.trunc(Number(page) || 1));
    const scope = kbDocumentScope();
    const request = ++kbDocumentRequest;
    const isCurrent = () => request === kbDocumentRequest && scope === kbDocumentScope();
    if (page !== kbDocumentPage || !kbDocumentPagination) {
      stopKbPolling(kbId);
      state.kbDocuments = [];
      if (elements.kbDocList) elements.kbDocList.innerHTML = '<div class="empty">正在加载文档...</div>';
    }
    kbDocumentPage = page;
    kbDocumentLoading = true;
    renderKbDocumentPagination();
    try {
      const body = await api(`/api/v1/knowledge-bases/${kbId}/documents?page=${page}`);
      if (!isCurrent()) return false;
      const documents = Array.isArray(body.data) ? body.data : [];
      const lastPage = Math.max(1, Number(body.last_page) || 1);
      // 删除最后一项或其他会话同时删除后，回到仍然存在的最后一页。
      if (page > lastPage) return await loadKbDocuments(kbId, lastPage);
      state.kbDocuments = documents;
      kbDocumentPagination = { current_page: Number(body.current_page) || page,
        last_page: lastPage, total: Number(body.total) || 0 };
      if (kbDocumentError && elements.kbDetailMessage?.textContent === kbDocumentError) setMessage(elements.kbDetailMessage, "");
      kbDocumentError = null;
      renderKbDocuments();
      return true;
    } catch (error) {
      if (!isCurrent() || error.knowledgeContextChanged) return false;
      if (elements.kbDocList && !state.kbDocuments.length) elements.kbDocList.innerHTML = '<div class="empty">文档加载失败，请点击刷新重试。</div>';
      kbDocumentError = error.message || "无法加载文档列表。";
      setMessage(elements.kbDetailMessage, kbDocumentError, "danger");
      return false;
    } finally {
      if (isCurrent()) {
        kbDocumentLoading = false;
        renderKbDocumentPagination();
      }
    }
  }

  function renderKbDocumentPagination() {
    const host = document.getElementById("kbDocPagination");
    if (!host) return;
    const lastPage = kbDocumentPagination?.last_page || 1;
    const label = kbDocumentPagination
      ? `第 ${kbDocumentPage} / ${lastPage} 页 · 共 ${formatNumber(kbDocumentPagination.total)} 篇`
      : (kbDocumentLoading ? "正在加载文档..." : "文档尚未加载");
    host.innerHTML = `<button class="button secondary small" type="button" data-kb-doc-page="${kbDocumentPage - 1}" ${kbDocumentLoading || kbDocumentPage <= 1 ? "disabled" : ""}>上一页</button>
      <span class="muted" role="status" aria-live="polite">${escapeHtml(label)}</span>
      <button class="button secondary small" type="button" data-kb-doc-page="${kbDocumentPage + 1}" ${kbDocumentLoading || kbDocumentPage >= lastPage ? "disabled" : ""}>下一页</button>`;
    host.querySelectorAll("[data-kb-doc-page]").forEach((button) => {
      button.addEventListener("click", async () => {
        if (button.disabled || !state.selectedKbId) return;
        const loaded = await loadKbDocuments(state.selectedKbId, Number(button.dataset.kbDocPage));
        if (loaded && state.kbDocuments.some((doc) => doc.status === "pending" || doc.status === "processing")) pollKbDocuments();
      });
    });
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
    const scope = kbDocumentScope();
    let inFlight = false;

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

      if (done.some((doc) => doc.status === "deleting")) {
        setMessage(statusNode, "材料清理未完成，请在文档列表重试删除。", "warning", { sticky: true });
        return;
      }
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
      if (kbDocPollers.get(kbId) !== timer) return;
      if (scope !== kbDocumentScope()) { stopKbPolling(kbId); return; }
      if (Date.now() - startedAt > KB_POLL_MAX_MS) {
        stopKbPolling(kbId);
        setMessage(statusNode || elements.kbDetailMessage, "解析时间较长，稍后刷新查看。", "warning");
        return;
      }
      if (inFlight || kbDocumentLoading) return;
      inFlight = true;
      let loaded;
      try {
        loaded = await loadKbDocuments(kbId, kbDocumentPage, { polling: true });
      } catch (error) {
        if (kbDocPollers.get(kbId) === timer && scope === kbDocumentScope()) {
          stopKbPolling(kbId);
          setMessage(elements.kbDetailMessage, error.message || "无法刷新文档列表。", "danger");
        }
        return;
      } finally {
        inFlight = false;
      }
      if (kbDocPollers.get(kbId) !== timer || scope !== kbDocumentScope() || loaded === false) return;
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
      } else if (doc.status === "deleting") {
        docMeta = `<br><span class="kb-doc-error">${escapeHtml(doc.error_message || "材料尚未清理完成，请重试删除。")}</span>`;
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
          ${doc.status !== "deleting" ? `<button class="button small" type="button" data-doc-action="view" data-doc-id="${escapeHtml(doc.id)}" data-kb-id="${escapeHtml(state.selectedKbId)}">查看</button>` : ""}
          ${doc.status !== "deleting" && doc.source_type === "url" && doc.review_status !== "archived" ? `<button class="button secondary small" type="button" data-doc-action="recrawl" data-doc-id="${escapeHtml(doc.id)}">重抓</button>` : ""}
          ${doc.status !== "deleting" && doc.review_status !== "archived" ? `<button class="button secondary small" type="button" data-doc-action="archive" data-doc-id="${escapeHtml(doc.id)}">归档</button>` : ""}
          <button class="button danger small" type="button" data-doc-action="delete" data-doc-id="${escapeHtml(doc.id)}">${doc.status === "deleting" ? "重试删除" : "删除"}</button>
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
    await GEO.submitKbImport({ loadDocuments: (kbId) => loadKbDocuments(kbId, 1), pollDocuments: pollKbDocuments, action: `recrawl-${docId}`, message: elements.kbDetailMessage,
      path: `documents/${docId}/recrawl`, payload: {},
      success: "重抓已开始；原文档保留，新版本解析成功后才会替代它。" });
  }

  async function archiveKbDocument(docId) {
    const kbId = state.selectedKbId;
    const scope = kbDocumentScope();
    if (!kbId || !await confirmWorkspaceAction("归档后文档仍可查看，但不再参与默认检索。确定归档吗？") || scope !== kbDocumentScope()) return;
    try {
      await api(`/api/v1/knowledge-bases/${kbId}/documents/${docId}/archive`, { method: "POST" });
      if (scope === kbDocumentScope()) await loadKbDocuments(kbId);
    } catch (error) {
      if (scope === kbDocumentScope() && !error.knowledgeContextChanged) setMessage(elements.kbDetailMessage, validationMessage(error), "danger");
    }
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
    await GEO.submitKbImport({ loadDocuments: (kbId) => loadKbDocuments(kbId, 1), pollDocuments: pollKbDocuments, action: "upload", path: "documents", payload: { project_id: projectId }, file,
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
    await GEO.submitKbImport({ loadDocuments: (kbId) => loadKbDocuments(kbId, 1), pollDocuments: pollKbDocuments, action: "url", path: "ingest-url", payload: { url, project_id: projectId },
      form, message, stillSameInput: () => String(new FormData(form).get("url") || "").trim() === url,
      success: "网页已收下，正在后台抓取解析，完成后这里会告诉你结果。" });
  }

  async function deleteKbDocument(docId) {
    const kbId = state.selectedKbId;
    const scope = kbDocumentScope();
    if (!kbId || !await confirmWorkspaceAction("确定删除这篇文档吗？") || scope !== kbDocumentScope()) return;
    try {
      await api(`/api/v1/knowledge-bases/${kbId}/documents/${docId}`, { method: "DELETE" });
      if (scope !== kbDocumentScope()) return;
      setMessage(elements.kbDetailMessage, "文档已删除。", "success");
      await loadKbDocuments(kbId);
      if (scope !== kbDocumentScope()) return;
      loadKnowledgeStats();
    } catch (error) {
      if (scope !== kbDocumentScope() || error.knowledgeContextChanged) return;
      setMessage(elements.kbDetailMessage, error.message || "删除失败。", "danger");
    }
  }

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

  function viewKbDocumentChunks(...args) { return GEO.viewKbDocumentChunks(...args); }

  // ═══════════════════════════════════════════════
  // 删除知识库（列表入口，停详情与轮询）
  // ═══════════════════════════════════════════════

  async function deleteKnowledgeBase() {
    const kbId = state.selectedKbId;
    const scope = kbDocumentScope();
    if (!kbId || !await confirmWorkspaceAction("确定删除这个知识库吗？所有文档也会被删除。") || scope !== kbDocumentScope()) return;
    try {
      await api(`/api/v1/knowledge-bases/${kbId}`, { method: "DELETE" });
      if (scope !== kbDocumentScope()) return;
      setMessage(elements.knowledgeMessage, "知识库已删除。", "success");
      closeKnowledgeBaseDetail();
      await loadKnowledgeBases();
    } catch (error) {
      if (scope !== kbDocumentScope() || error.knowledgeContextChanged) return;
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
  GEO.closeKnowledgeBaseDetail = closeKnowledgeBaseDetail;
})();
