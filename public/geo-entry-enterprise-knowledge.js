/* 企业知识库：草稿、来源、修订与入库。依赖 window.GEO。
 * 详情关闭、切页签或切项目时停止轮询；保持原 API 和表单契约。 */
(() => {
  const GEO = window.GEO;
  if (!GEO) {
    console.error('[geo-entry-enterprise-knowledge] window.GEO 未就绪：请确认本文件在 geo-entry.js 之后加载');
    return;
  }
  const {
    state, elements, api, escapeHtml, formatNumber, setMessage, validationMessage,
    confirmWorkspaceAction, withBusyButton, clDebounce, clToast, fillErrorNo, loadKnowledgeBases,
    // 静态护栏 tests/js/entry-split-scope.test.mjs 抓出来的漏项：formatDate 与两个 KB 文档状态常量都在主文件里定义。
    formatKnowledgeDate, KB_DOC_STATUS_LABELS, KB_DOC_STATUS_VARIANTS,
  } = GEO;

  // ═══════════════════════════════════════════════
  // Enterprise Knowledge (EK) — tenant-level draft workflow
  // ═══════════════════════════════════════════════

  const EK_STATUS_LABELS = {
    draft: "草稿",
    queued: "排队中",
    processing: "生成中",
    reviewing: "复核中",
    published: "已发布",
    failed: "失败",
  };
  const EK_STATUS_VARIANTS = {
    draft: "neutral",
    queued: "info",
    processing: "info",
    reviewing: "warning",
    published: "success",
    failed: "danger",
  };
  function safeJson(value) {
    if (typeof value !== "string") return value;
    try { return JSON.parse(value); } catch { return []; }
  }

  function setEkState(stateName) {
    if (!elements.ekHost) return;
    elements.ekHost.querySelectorAll("[data-state]").forEach((node) => {
      node.hidden = node.dataset.state !== stateName;
    });
  }

  function switchKnowledgeSegment(seg) {
    document.querySelectorAll("[data-kb-segment]").forEach((node) => {
      node.hidden = node.dataset.kbSegment !== seg;
    });
    document.querySelectorAll(".kb-seg-tab").forEach((tab) => {
      const active = tab.dataset.kbSeg === seg;
      tab.setAttribute("aria-selected", active ? "true" : "false");
    });
    document.querySelectorAll("[data-kb-only]").forEach((btn) => {
      btn.hidden = btn.dataset.kbOnly !== seg;
    });
    if (seg === "enterprise") {
      if (!state.ekLoaded) {
        loadEnterpriseKnowledge();
      } else if (state.ekProjects.length === 0) {
        renderEnterpriseKnowledge();
      }
    }
  }

  // ═══════════════════════════════════════════════
  // 项目列表与状态（加载 / 重试 / 空态）
  // ═══════════════════════════════════════════════
  async function loadEnterpriseKnowledge() {
    setEkState("loading");
    if (elements.ekMessage) { elements.ekMessage.hidden = true; setMessage(elements.ekMessage, "", "neutral"); }
    try {
      const body = await api("/api/v1/enterprise-knowledge");
      state.ekProjects = body.data || [];
      state.ekLoaded = true;
      renderEnterpriseKnowledge();
    } catch (error) {
      if (error.knowledgeContextChanged) return;
      state.ekLoaded = true;
      fillErrorNo(elements.ekErrorNo, error);
      if (elements.ekMessage) { elements.ekMessage.hidden = false; setMessage(elements.ekMessage, error.message || "档案加载失败。", "danger"); }
      setEkState("error");
    }
  }

  function renderEnterpriseKnowledge() {
    if (!elements.ekRows) return;
    if (state.ekProjects.length === 0) {
      setEkState("empty");
      return;
    }
    setEkState("populated");
    elements.ekRows.innerHTML = "";
    state.ekProjects.forEach((project) => {
      const tr = document.createElement("tr");
      const status = String(project.status || "draft");
      tr.innerHTML = `
        <td><strong>${escapeHtml(project.name || "未命名档案")}</strong><br><span class="muted">${escapeHtml(formatKnowledgeDate(project.created_at))}</span></td>
        <td>${formatNumber(project.sources_count || 0)}</td>
        <td>${formatNumber(project.revisions_count || 0)}</td>
        <td><span class="badge" data-variant="${EK_STATUS_VARIANTS[status] || "neutral"}">${escapeHtml(EK_STATUS_LABELS[status] || status)}</span></td>
        <td class="cl-cell-actions"><button class="button secondary small" type="button" data-ek-open="${project.id}">打开</button></td>
      `;
      elements.ekRows.appendChild(tr);
    });
    elements.ekRows.querySelectorAll("[data-ek-open]").forEach((btn) => {
      btn.addEventListener("click", () => openEnterpriseDetail(Number(btn.dataset.ekOpen)));
    });
  }

  // ═══════════════════════════════════════════════
  // 新建项目（表单提交）
  // ═══════════════════════════════════════════════
  async function createEnterpriseProject(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const message = elements.ekCreateMessage;
    const data = new FormData(form);
    const content = String(data.get("content") || "").trim();
    const sourceContent = String(data.get("source_content") || "").trim();
    if (content === "" && sourceContent === "") {
      setMessage(message, "请至少填写「草稿内容」或「首个来源」之一。", "danger");
      return;
    }
    setMessage(message, "正在创建…");
    try {
      const sources = sourceContent !== "" ? [{ content: sourceContent }] : [];
      const res = await api("/api/v1/enterprise-knowledge", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          name: String(data.get("name") || "").trim() || null,
          description: String(data.get("description") || "").trim() || null,
          content: content !== "" ? content : null,
          sources,
        }),
      });
      const project = res.data || res;
      setMessage(message, "档案已创建。", "success");
      form.reset();
      if (elements.ekCreateForm) elements.ekCreateForm.hidden = true;
      await loadEnterpriseKnowledge();
      if (project && project.id) openEnterpriseDetail(project.id);
    } catch (error) {
      setMessage(message, validationMessage(error), "danger");
    }
  }

  // ═══════════════════════════════════════════════
  // 详情：草稿编辑器与自动保存
  // ═══════════════════════════════════════════════
  async function openEnterpriseDetail(ekId) {
    stopEkPublishPolling();
    state.selectedEkId = ekId;
    if (elements.ekHost) elements.ekHost.hidden = true;
    if (elements.ekCreateForm) elements.ekCreateForm.hidden = true;
    if (elements.ekDetail) elements.ekDetail.hidden = false;
    setMessage(elements.ekDetailMessage, "正在加载档案…");
    try {
      const body = await api(`/api/v1/enterprise-knowledge/${ekId}`);
      if (String(state.selectedEkId) !== String(ekId)) return;
      const project = body.data || body;
      state.ekDetail = project;
      renderEnterpriseDetail(project, body.validation_items || []);
      void loadEkTargets(ekId, project);
      setMessage(elements.ekDetailMessage, "");
      // 和知识库详情同一个道理：重进页面时若索引还没建完，接着盯。
      const pubDoc = project.published_document || project.publishedDocument;
      if (pubDoc && (pubDoc.status === "pending" || pubDoc.status === "processing")) {
        pollEkPublishedDoc(project);
      }
    } catch (error) {
      setMessage(elements.ekDetailMessage, error.message || "档案加载失败。", "danger");
    }
  }

  async function loadEkTargets(ekId, project) {
    const select = document.getElementById("ekTargetKnowledgeBase");
    if (!select) return;
    select.disabled = true;
    select.innerHTML = '<option value="">正在加载知识库…</option>';
    try {
      const body = await api("/api/v1/knowledge-bases");
      if (String(state.selectedEkId) !== String(ekId)) return;
      const bases = (body.data || body || []).filter((kb) => kb.status === "active" && Number(kb.project_id) === Number(project.project_id));
      const preferred = project.published_knowledge_base_id;
      select.innerHTML = bases.map((kb) => `<option value="${escapeHtml(String(kb.id))}">${escapeHtml(kb.name)}</option>`).join("")
        + '<option value="new">创建新知识库（仅在明确选择时）</option>';
      if (preferred && bases.some((kb) => Number(kb.id) === Number(preferred))) select.value = String(preferred);
      select.disabled = false;
    } catch (error) {
      if (String(state.selectedEkId) !== String(ekId)) return;
      select.innerHTML = '<option value="">知识库加载失败，请重新打开档案</option>';
      setMessage(elements.ekDetailMessage, "目标知识库加载失败，请重新打开档案。", "danger");
    }
  }

  function renderEnterpriseDetail(project, validationItems) {
    if (!elements.ekDetail) return;
    if (elements.ekDetailTitle) elements.ekDetailTitle.textContent = project.name || "未命名档案";
    const status = String(project.status || "draft");
    if (elements.ekDetailStatus) {
      elements.ekDetailStatus.textContent = EK_STATUS_LABELS[status] || status;
      elements.ekDetailStatus.dataset.variant = EK_STATUS_VARIANTS[status] || "neutral";
    }
    if (elements.ekDraftEditor) elements.ekDraftEditor.value = project.draft_content || "";
    if (elements.ekAutosaveStatus) elements.ekAutosaveStatus.textContent = "";
    renderEkValidation(validationItems);
    renderEkSources(project.sources || []);
    renderEkPublished(project);
  }

  function renderEkValidation(items) {
    if (!elements.ekValidationPanel) return;
    const list = Array.isArray(items) ? items : [];
    if (list.length === 0) {
      elements.ekValidationPanel.innerHTML = '<p class="muted">暂无校验项。保存或点击「校验」后，这里会显示校验结果。</p>';
      return;
    }
    elements.ekValidationPanel.innerHTML = `<ul class="ek-validation-list">${list.map((item) => {
      const ok = item.ok === true || (item.ok === undefined && item.level === "info");
      return `<li class="ek-validation-item ${ok ? "ok" : "bad"}"><span class="ek-vi-icon">${ok ? "✓" : "!"}</span><div><strong>${escapeHtml(item.label || item.section || "检查项")}</strong><p>${escapeHtml(item.detail || item.message || "仅完成结构检查，事实仍需人工核验。")}</p></div></li>`;
    }).join("")}</ul>`;
  }

  function renderEkSources(sources) {
    if (!elements.ekSourcesPanel) return;
    if (!sources || sources.length === 0) {
      elements.ekSourcesPanel.innerHTML = '<p class="muted">还没有来源。</p>';
      return;
    }
    elements.ekSourcesPanel.innerHTML = `<ul class="ek-sources-list">${sources.map((s) => `<li><strong>${escapeHtml(s.original_name || "来源")}</strong><br><span class="muted">${formatNumber(s.character_count || (s.content ? s.content.length : 0))} 字 · ${escapeHtml(s.file_type || "text")}</span>${s.source_url ? `<br><a href="${escapeHtml(s.source_url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(s.source_url)}</a>` : ""}</li>`).join("")}</ul>`;
  }

  function renderEkPublished(project) {
    if (!elements.ekPublishedInfo) return;
    // ⚠️ 接口返回的关系键是下划线的：Eloquent 序列化关系时走 Str::snake，
    // published_knowledge_base / published_document。此前这里读驼峰，永远是 undefined，
    // 这张「已发布到知识库」卡片其实一次都没显示过（2026-08-20 评审发现）。
    // 两种都读一下，将来后端换成资源类改了键名也不会又悄悄不显示。
    const kb = project.published_knowledge_base || project.publishedKnowledgeBase;
    const doc = project.published_document || project.publishedDocument;
    if (!kb && !doc) {
      elements.ekPublishedInfo.hidden = true;
      elements.ekPublishedInfo.innerHTML = "";
      return;
    }
    elements.ekPublishedInfo.hidden = false;
    const status = doc && doc.status ? String(doc.status) : "";
    const kbLink = kb ? `<a href="#" class="ek-kb-link" data-ek-kb="${kb.id}">${escapeHtml(kb.name || "知识库")}</a>` : "知识库";
    let html = `<div class="ek-published-card"><p class="eyebrow">已发布到知识库</p><p>知识库：${kbLink}`;
    if (doc) html += ` · <a href="#" data-ek-doc="${escapeHtml(String(doc.id))}">文档：${escapeHtml(doc.title || doc.filename || "文档")}</a>`;
    if (status) html += ` · <span class="badge" data-variant="${KB_DOC_STATUS_VARIANTS[status] || "neutral"}">${escapeHtml(KB_DOC_STATUS_LABELS[status] || status)}</span>`;
    if (status === "ready") html += ` · ${formatNumber(Number(doc.chunk_count || 0))} 个切片`;
    html += `</p>`;
    // 三种要说清楚的情况：后台建索引失败 / 发布这一步本身就失败 / 还在后台跑。
    if (status === "failed") {
      html += `<p class="inline-result" data-variant="danger">后台建立索引失败：${escapeHtml(doc.error_message || "原因未知，请重新发布")}</p>`;
    } else if (project.error_message) {
      html += `<p class="inline-result" data-variant="danger">${escapeHtml(project.error_message)}</p>`;
    } else if (status === "pending" || status === "processing") {
      html += `<p class="inline-result" data-variant="info">正在后台建立索引，完成后这里会自动变成「已解析」。</p>`;
    }
    html += `</div>`;
    elements.ekPublishedInfo.innerHTML = html;
    const link = elements.ekPublishedInfo.querySelector("[data-ek-kb]");
    if (link) link.addEventListener("click", (e) => {
      e.preventDefault();
      switchKnowledgeSegment("bases");
      Promise.resolve(loadKnowledgeBases()).then(() => GEO.openKnowledgeBase?.(Number(kb.id)));
    });
    elements.ekPublishedInfo.querySelector("[data-ek-doc]")?.addEventListener("click", async (e) => {
      e.preventDefault();
      switchKnowledgeSegment("bases");
      await loadKnowledgeBases();
      await GEO.openKnowledgeBase?.(Number(kb.id));
      GEO.viewKbDocumentChunks?.(Number(kb.id), Number(doc.id));
    });
  }

  const ekAutosaveDebounced = clDebounce(async () => {
    if (!state.selectedEkId) return;
    const content = elements.ekDraftEditor ? elements.ekDraftEditor.value : "";
    if (content.trim() === "") return;
    try {
      const res = await api(`/api/v1/enterprise-knowledge/${state.selectedEkId}/autosave`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ content }),
      });
      if (elements.ekAutosaveStatus) elements.ekAutosaveStatus.textContent = `已保存 ${formatKnowledgeDate(res.saved_at)}`;
      if (res.validation_items) renderEkValidation(res.validation_items);
    } catch (error) {
      if (elements.ekAutosaveStatus) elements.ekAutosaveStatus.textContent = "保存失败";
    }
  }, 900);

  function ekOnDraftInput() {
    if (elements.ekAutosaveStatus) elements.ekAutosaveStatus.textContent = "编辑中…";
    ekAutosaveDebounced();
  }

  // ═══════════════════════════════════════════════
  // 校验：草稿与已发布内容的一致性
  // ═══════════════════════════════════════════════
  async function ekValidate() {
    if (!state.selectedEkId) return;
    const content = elements.ekDraftEditor ? elements.ekDraftEditor.value : "";
    if (!elements.ekValidateButton) return;
    await withBusyButton(elements.ekValidateButton, "校验中…", async () => {
      try {
        const res = await api(`/api/v1/enterprise-knowledge/${state.selectedEkId}/validate`, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ content }),
        });
        renderEkValidation(res.validation_items || []);
        clToast("校验完成。", "success");
      } catch (error) {
        clToast(error.message || "校验失败。", "danger");
      }
    });
  }

  async function ekGenerate() {
    if (!state.selectedEkId) return;
    if (elements.ekGenerateButton) elements.ekGenerateButton.disabled = true;
    try {
      const res = await api(`/api/v1/enterprise-knowledge/${state.selectedEkId}/generate`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({}),
      });
      clToast(res.message || "草稿生成任务已入队。", "success");
      if (res.data && res.data.status) {
        const st = String(res.data.status);
        if (elements.ekDetailStatus) {
          elements.ekDetailStatus.textContent = EK_STATUS_LABELS[st] || st;
          elements.ekDetailStatus.dataset.variant = EK_STATUS_VARIANTS[st] || "neutral";
        }
      }
    } catch (error) {
      clToast(validationMessage(error), "danger");
    } finally {
      if (elements.ekGenerateButton) elements.ekGenerateButton.disabled = false;
    }
  }

  // ═══════════════════════════════════════════════
  // 发布：轮询与失败收尾
  // ═══════════════════════════════════════════════
  async function ekPublish() {
    if (!state.selectedEkId) return;
    if (state.knowledgeModelStatus?.embedding_configured !== true) {
      setMessage(elements.ekDetailMessage, "未配置向量模型", "warning");
      return;
    }
    const ekId = state.selectedEkId;
    const target = document.getElementById("ekTargetKnowledgeBase")?.value;
    if (!target) { setMessage(elements.ekDetailMessage, "请选择目标知识库。", "danger"); return; }
    const content = elements.ekDraftEditor ? elements.ekDraftEditor.value : "";
    if (elements.ekPublishButton) elements.ekPublishButton.disabled = true;
    // 发布现在是「登记 + 入队」，秒回；耗时的切片/向量化在后台。旧文案会让人以为要等很久。
    setMessage(elements.ekDetailMessage, "正在发布到知识库…");
    try {
      const res = await api(`/api/v1/enterprise-knowledge/${state.selectedEkId}/publish`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ content: content !== "" ? content : undefined,
          ...(target === "new" ? { create_knowledge_base: true } : { knowledge_base_id: Number(target) }) }),
      });
      if (String(state.selectedEkId) !== String(ekId)) return;
      // 后端已经不返回 chunk_count / chunk_error 这两个字段了（改异步之后，
      // 发布返回时还没开始切片）。原来这里照旧读它们，于是「失败」分支永远进不去，
      // 弹的一直是绿色成功提示。改成看文档自己的状态。
      const project = res.data || {};
      state.ekDetail = project;
      renderEnterpriseDetail(project, project.validation_json ? safeJson(project.validation_json) : []);
      await loadKnowledgeBases();
      void loadEkTargets(ekId, project);
      setMessage(elements.ekDetailMessage, res.message || "已发布到知识库，正在后台建立索引。", "success");
      clToast("已发布到知识库，正在后台建立索引。", "success");
      pollEkPublishedDoc(project);
    } catch (error) {
      setMessage(elements.ekDetailMessage, validationMessage(error), "danger");
      clToast("发布失败。", "danger");
    } finally {
      if (String(state.selectedEkId) === String(ekId) && elements.ekPublishButton) elements.ekPublishButton.disabled = state.knowledgeModelStatus?.embedding_configured !== true;
    }
  }

  const EK_PUBLISH_POLL_INTERVAL_MS = 2500;
  const EK_PUBLISH_POLL_MAX_MS = 15 * 60 * 1000;
  let ekPublishPoller = null;

  function stopEkPublishPolling() {
    if (ekPublishPoller) {
      window.clearInterval(ekPublishPoller);
      ekPublishPoller = null;
    }
  }

  /**
   * 发布之后一路盯着这份文档，直到它变成「已解析」或「失败」。
   *
   * 为什么必须有：发布只说一句「正在后台建立索引」，然后就没有下文了 ——
   * 后台失败只在另一个页面（知识库的文档列表）看得到，用户在这个页面上
   * 永远等不到结果，也不知道要去哪儿看。
   */
  function pollEkPublishedDoc(project) {
    stopEkPublishPolling();
    const kb = project.published_knowledge_base || project.publishedKnowledgeBase;
    const doc = project.published_document || project.publishedDocument;
    if (!kb || !kb.id || !doc || !doc.id) return;

    const ekId = state.selectedEkId;
    const startedAt = Date.now();

    const tick = async () => {
      if (state.selectedEkId !== ekId) { stopEkPublishPolling(); return; }

      let fresh = null;
      try {
        const body = await api(`/api/v1/knowledge-bases/${kb.id}/documents`);
        const list = body.data || body || [];
        fresh = (Array.isArray(list) ? list : []).find((d) => Number(d.id) === Number(doc.id)) || null;
      } catch (error) {
        stopEkPublishPolling();
        return;
      }
      if (!fresh) { stopEkPublishPolling(); return; }

      if (state.ekDetail) {
        state.ekDetail.published_document = fresh;
        renderEkPublished(state.ekDetail);
      }

      if (fresh.status === "ready") {
        stopEkPublishPolling();
        setMessage(elements.ekDetailMessage, `索引已建立，共 ${formatNumber(Number(fresh.chunk_count || 0))} 个切片。`, "success");
        return;
      }
      if (fresh.status === "failed") {
        stopEkPublishPolling();
        setMessage(elements.ekDetailMessage, fresh.error_message || "后台建立索引失败，请重新发布。", "danger");
        clToast("后台建立索引失败。", "danger");
        return;
      }
      if (Date.now() - startedAt > EK_PUBLISH_POLL_MAX_MS) {
        stopEkPublishPolling();
        setMessage(elements.ekDetailMessage, "建立索引时间较长，稍后回到这个页面查看。", "warning");
      }
    };

    ekPublishPoller = window.setInterval(tick, EK_PUBLISH_POLL_INTERVAL_MS);
    tick();
  }

  // ═══════════════════════════════════════════════
  // 来源（资料）增删
  // ═══════════════════════════════════════════════
  async function ekAddSource(event) {
    event.preventDefault();
    if (!state.selectedEkId) return;
    const form = event.currentTarget;
    const message = elements.ekAddSourceMessage;
    const data = new FormData(form);
    const content = String(data.get("content") || "").trim();
    if (!content) { setMessage(message, "请输入来源内容。", "danger"); return; }
    setMessage(message, "正在追加…");
    try {
      await api(`/api/v1/enterprise-knowledge/${state.selectedEkId}/sources`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          content,
          original_name: String(data.get("original_name") || "").trim() || null,
          source_url: String(data.get("source_url") || "").trim() || null,
        }),
      });
      setMessage(message, "来源已追加。", "success");
      form.reset();
      await openEnterpriseDetail(state.selectedEkId);
    } catch (error) {
      setMessage(message, validationMessage(error), "danger");
    }
  }

  async function ekDeleteProject() {
    if (!state.selectedEkId) return;
    if (!await confirmWorkspaceAction("确定删除这份企业知识档案吗？此操作不可恢复。")) return;
    try {
      await api(`/api/v1/enterprise-knowledge/${state.selectedEkId}`, { method: "DELETE" });
      clToast("档案已删除。", "success");
      ekCloseDetail();
      await loadEnterpriseKnowledge();
    } catch (error) {
      clToast(error.message || "删除失败。", "danger");
    }
  }

  function ekCloseDetail() {
    state.selectedEkId = null;
    state.ekDetail = null;
    stopEkPublishPolling();
    if (elements.ekDetail) elements.ekDetail.hidden = true;
    if (elements.ekHost) elements.ekHost.hidden = false;
  }

  // ═══════════════════════════════════════════════
  // 修订历史与回滚
  // ═══════════════════════════════════════════════
  async function loadEkRevisions() {
    if (!state.selectedEkId) return;
    if (elements.clDrawerTitle) elements.clDrawerTitle.textContent = "修订历史";
    if (elements.clDrawerSub) elements.clDrawerSub.textContent = "";
    if (elements.clDrawerBody) elements.clDrawerBody.innerHTML = '<div class="cl-skeleton"><div class="cl-sk w80"></div><div class="cl-sk w60"></div></div>';
    if (elements.clDrawerFoot) elements.clDrawerFoot.innerHTML = "";
    // ⚠️ 这个抽屉是三个视图共用的（文章 / 本视图 / 技能提示词）。2026-09-03
    //    迁零件层后开关挂在遮罩 #clDrawerMask 上，面板 #clDrawer 只是它的子元素。
    //    再去动 clDrawer.hidden 会留下一个「遮罩在、面板不见」的空屏。
    if (elements.clDrawerMask) elements.clDrawerMask.hidden = false;
    try {
      const body = await api(`/api/v1/enterprise-knowledge/${state.selectedEkId}/revisions`);
      state.ekRevisions = body.data || [];
      renderEkRevisions(state.ekRevisions);
    } catch (error) {
      if (elements.clDrawerBody) elements.clDrawerBody.innerHTML = `<p class="inline-result" data-variant="danger">${escapeHtml(error.message || "修订加载失败")}</p>`;
    }
  }

  function renderEkRevisions(revisions) {
    if (!elements.clDrawerBody) return;
    if (!revisions || revisions.length === 0) {
      elements.clDrawerBody.innerHTML = '<p class="muted">还没有修订记录。</p>';
      return;
    }
    elements.clDrawerBody.innerHTML = `<ul class="ek-revisions-list">${revisions.map((rev) => {
      const creator = rev.creator ? (rev.creator.name || rev.creator.email || "用户") : "系统";
      return `<li class="ek-revision-item">
        <div class="ek-rev-head"><strong>#${rev.id}</strong><span class="badge" data-variant="neutral">${escapeHtml(rev.source || "版本")}</span><span class="muted">${escapeHtml(creator)} · ${escapeHtml(formatKnowledgeDate(rev.created_at))}</span></div>
        <p class="muted">${escapeHtml(String(rev.note || "").slice(0, 140))}</p>
        <button class="button secondary small" type="button" data-ek-restore="${rev.id}">恢复到此版</button>
      </li>`;
    }).join("")}</ul>`;
    elements.clDrawerBody.querySelectorAll("[data-ek-restore]").forEach((btn) => {
      btn.addEventListener("click", () => ekRestore(Number(btn.dataset.ekRestore)));
    });
  }

  async function ekRestore(revId) {
    if (!state.selectedEkId) return;
    try {
      const res = await api(`/api/v1/enterprise-knowledge/${state.selectedEkId}/restore/${revId}`, { method: "POST" });
      const project = res.data || {};
      state.ekDetail = project;
      renderEnterpriseDetail(project, project.validation_json ? safeJson(project.validation_json) : []);
      clToast("已恢复到该版本。", "success");
      if (elements.clDrawerMask) elements.clDrawerMask.hidden = true;
    } catch (error) {
      clToast(validationMessage(error), "danger");
    }
  }
  // Enterprise Knowledge (EK)
  elements.ekSegmentTabs?.querySelectorAll(".kb-seg-tab").forEach((tab) => {
    tab.addEventListener("click", () => switchKnowledgeSegment(tab.dataset.kbSeg || "bases"));
  });
  elements.ekNewButton?.addEventListener("click", () => {
    if (elements.ekCreateForm) elements.ekCreateForm.hidden = !elements.ekCreateForm.hidden;
  });
  elements.ekEmptyNewButton?.addEventListener("click", () => {
    if (elements.ekCreateForm) elements.ekCreateForm.hidden = false;
  });
  elements.ekCancelCreateButton?.addEventListener("click", () => {
    if (elements.ekCreateForm) elements.ekCreateForm.hidden = true;
  });
  elements.ekCreateFormEl?.addEventListener("submit", createEnterpriseProject);
  elements.ekReloadButton?.addEventListener("click", loadEnterpriseKnowledge);
  elements.ekRetryButton?.addEventListener("click", loadEnterpriseKnowledge);
  elements.ekBackButton?.addEventListener("click", ekCloseDetail);
  elements.ekDeleteButton?.addEventListener("click", ekDeleteProject);
  elements.ekDraftEditor?.addEventListener("input", ekOnDraftInput);
  elements.ekValidateButton?.addEventListener("click", ekValidate);
  elements.ekGenerateButton?.addEventListener("click", ekGenerate);
  elements.ekPublishButton?.addEventListener("click", ekPublish);
  elements.ekRevisionsButton?.addEventListener("click", loadEkRevisions);
  elements.ekAddSourceForm?.addEventListener("submit", ekAddSource);
  // 拆出去之后仍被主文件调用的两个入口：挂到共享命名空间上（主文件用可选链调用）。
  GEO.loadEnterpriseKnowledge = loadEnterpriseKnowledge;
  GEO.ekCloseDetail = ekCloseDetail;
})();
