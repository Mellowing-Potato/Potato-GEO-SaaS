/* 知识材料付费动作：并发认领、稳定请求编号、迟到结果隔离。依赖 window.GEO。 */
(() => {
  const GEO = window.GEO;
  if (!GEO) return;
  const { state, elements, api, tenantSubdomain, setMessage, validationMessage,
    pendingIdempotencyRequest, clearPendingIdempotencyRequest, retainPendingIdempotencyRequest } = GEO;

  const kbImportBusy = new Set();

  function kbImportScope() {
    return JSON.stringify([state.session?.tenant?.id, state.session?.user?.id,
      tenantSubdomain(), state.selectedProject?.id, state.selectedKbId]);
  }

  function refreshKbImportButtons() {
    for (const [action, form] of [["upload", elements.kbUploadForm], ["url", elements.kbIngestUrlForm]]) {
      const button = form?.querySelector('button[type="submit"]');
      if (button) button.disabled = kbImportBusy.has(`${kbImportScope()}:${action}`);
    }
  }

  async function kbFileFingerprint(file) {
    const bytes = new Uint8Array(await file.arrayBuffer());
    let digest;
    if (globalThis.crypto?.subtle) {
      const hash = await globalThis.crypto.subtle.digest("SHA-256", bytes);
      digest = `sha256:${Array.from(new Uint8Array(hash), (byte) => byte.toString(16).padStart(2, "0")).join("")}`;
    } else {
      // HTTP 上 WebCrypto 可能不可用。这里只选择本地 pending 槽，不作安全校验；
      // 服务端仍按实际字节 SHA-256 校验，任何碰撞都会 409，绝不换键重发。
      let first = 2166136261, second = 5381;
      for (const byte of bytes) {
        first = Math.imul(first ^ byte, 16777619) >>> 0;
        second = (Math.imul(second, 33) ^ byte) >>> 0;
      }
      digest = `local:${bytes.length}:${first}:${second}`;
    }
    return { name: file.name, size: file.size, type: file.type, digest };
  }

  async function submitKbImport({ action, path, payload, file, form, message, success, stillSameInput, loadDocuments, pollDocuments }) {
    const scope = kbImportScope();
    const kbId = state.selectedKbId;
    const busyKey = `${scope}:${action}`;
    if (kbImportBusy.has(busyKey)) return;
    // 在读取文件/任何 await 之前认领，双击不能启动两个请求。
    kbImportBusy.add(busyKey);
    refreshKbImportButtons();
    let pendingRequest;
    try {
      setMessage(message, file ? "正在上传..." : "正在提交...");
      const fingerprint = { knowledge_base_id: kbId, active_project_id: state.selectedProject?.id, ...payload };
      if (file) fingerprint.file = await kbFileFingerprint(file);
      if (scope !== kbImportScope()) return;
      pendingRequest = pendingIdempotencyRequest(`knowledge-${action}`, fingerprint);
      let body = JSON.stringify(payload);
      const headers = { "X-Idempotency-Key": pendingRequest.key };
      if (file) {
        body = new FormData();
        body.append("file", file);
        body.append("project_id", String(payload.project_id));
      } else {
        headers["Content-Type"] = "application/json";
      }
      const result = await api(`/api/v1/knowledge-bases/${kbId}/${path}`, { method: "POST", headers, body });
      if (!Number.isSafeInteger(Number(result?.data?.id)) || Number(result.data.id) < 1) {
        throw new Error("未收到材料编号，请先查看材料列表，再用原请求重试确认。");
      }
      if (scope !== kbImportScope()) return;
      clearPendingIdempotencyRequest(pendingRequest);
      setMessage(message, success, "success");
      if (form && stillSameInput()) form.reset();
      await loadDocuments(kbId);
      if (scope === kbImportScope()) pollDocuments(message);
    } catch (error) {
      const uncertain = retainPendingIdempotencyRequest(error) || Number(error?.status) === 409
        || error?.body?.action === "knowledge_ingestion_result_unknown";
      if (scope !== kbImportScope()) return;
      if (pendingRequest && !uncertain) clearPendingIdempotencyRequest(pendingRequest);
      setMessage(message, validationMessage(error) + (pendingRequest && uncertain
        ? " 原请求编号已保留；请先查看材料列表，再重试确认，系统不会重复受理。" : ""), "danger");
    } finally {
      kbImportBusy.delete(busyKey);
      refreshKbImportButtons();
    }
  }

  Object.assign(GEO, { refreshKbImportButtons, submitKbImport });
})();
