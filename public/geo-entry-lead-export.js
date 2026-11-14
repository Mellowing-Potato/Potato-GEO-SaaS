/* Lead CSV export keeps the same filter query as the visible submissions list. */
(() => {
  const GEO = window.GEO;
  if (!GEO?.leadSubmissionQuery) return;

  // The export endpoint needs Authorization; an ordinary download link cannot send it.
  async function exportLeads() {
    const button = document.getElementById("leadExportButton");
    if (button) button.disabled = true;
    const message = (text, variant = "neutral") => GEO.setMessage(document.getElementById("leadMessage"), text, variant);
    message("正在导出…");
    try {
      const response = await fetch(`/api/v1/leads/export${GEO.leadSubmissionQuery()}`, {
        credentials: "include",
        cache: "no-store",
        headers: GEO.authHeaders(),
      });
      if (!response.ok) {
        const requestId = response.headers.get("X-Request-Id") || "无";
        throw new Error(`服务器返回 ${response.status}（错误编号 ${requestId}）`);
      }
      const blob = await response.blob();
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement("a");
      anchor.href = url;
      anchor.download = `leads-${new Date().toISOString().slice(0, 10)}.csv`;
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      URL.revokeObjectURL(url);
      message("导出完成，文件已开始下载。", "success");
    } catch (error) {
      message(`导出失败：${error.message || "未知错误"}`, "danger");
    } finally {
      if (button) button.disabled = false;
    }
  }

  document.getElementById("leadExportButton")?.addEventListener("click", exportLeads);
})();
