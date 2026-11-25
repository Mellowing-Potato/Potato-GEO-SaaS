/* ============================================================================
   个人设置 · 工作区名称（geo-entry-workspace-settings.js）

   通过权限接口决定改名表单是否可用，接口层继续检查 can:team:manage。
   权限代码只用于内部判断，不在设置页展示；读取失败时保持只读。

   接入方式与 geo-entry-management.js 一致：消费 window.GEO，只注册视图加载器。
   注意：本地取元素的辅助函数叫 byId（不叫 el）—— entry-split-scope 的作用域护栏把
   另一个拆出块里的箭头参数 `el` 也算作引用名，用 `el` 会被判成跨块裸引用。
   ============================================================================ */
(() => {
  const GEO = window.GEO;
  if (!GEO) {
    console.error('[geo-entry-workspace-settings] window.GEO 未就绪：请确认本文件在 geo-entry.js 之后加载');
    return;
  }
  const { api, setMessage, state } = GEO;
  const WORKSPACE_PERMISSION = "team:manage";
  const byId = (id) => document.getElementById(id);

  let permissions = null;
  let permissionState = "loading";
  let permissionRequestId = 0;

  function canManageWorkspace() {
    return Array.isArray(permissions) && permissions.includes(WORKSPACE_PERMISSION);
  }

  function applyWorkspacePermission() {
    const input = byId("workspaceName");
    const button = byId("saveWorkspaceName");
    const help = byId("workspaceNameHelp");
    if (!input || !button) return;
    const allowed = canManageWorkspace();
    input.disabled = !allowed;
    button.disabled = !allowed;
    if (help) {
      help.textContent = allowed
        ? "名称用于区分你的工作区，不改变资料归属和已经发布的内容。"
        : permissionState === "loading"
          ? "正在确认是否可以修改名称，请稍候。"
          : permissionState === "failed"
            ? "暂时无法修改工作区名称，请刷新后重试。"
            : "当前账号只能查看工作区名称，不能修改。";
    }
  }

  async function loadPermissions() {
    const requestId = ++permissionRequestId;
    permissions = null;
    permissionState = "loading";
    applyWorkspacePermission();
    try {
      const body = await api("/api/v1/me/permissions");
      if (requestId !== permissionRequestId) return;
      if (!Array.isArray(body?.permissions)) throw new Error("Missing workspace authorization");
      permissions = body.permissions;
      permissionState = "ready";
    } catch {
      if (requestId !== permissionRequestId) return;
      permissions = null;
      permissionState = "failed";
    } finally {
      if (requestId === permissionRequestId) applyWorkspacePermission();
    }
  }

  async function loadWorkspaceName() {
    const input = byId("workspaceName");
    const message = byId("workspaceNameMessage");
    if (!input) return;
    const fromSession = state?.session?.tenant?.name || "";
    if (fromSession) input.value = fromSession;
    try {
      // /api/v1/me 返回 user + tenant（服务端权威），比会话快照新。
      const me = await api("/api/v1/me");
      const name = me?.tenant?.name || fromSession || "";
      input.value = name;
      if (!name && message) setMessage(message, "服务端没有返回工作区名称，请刷新重试。", "danger");
    } catch {
      if (!fromSession && message) {
        setMessage(message, "工作区名称暂时无法读取，请刷新后重试。", "danger");
      }
    }
  }

  async function saveWorkspaceName(event) {
    event.preventDefault();
    const input = byId("workspaceName");
    const message = byId("workspaceNameMessage");
    const button = byId("saveWorkspaceName");
    if (!input || !message) return;
    if (!canManageWorkspace()) {
      setMessage(message, "当前账号不能修改工作区名称。", "danger");
      return;
    }
    const name = String(input.value || "").trim();
    if (!name) {
      setMessage(message, "工作区名称不能为空。", "danger");
      return;
    }
    if (button) button.disabled = true;
    try {
      const body = await api("/api/v1/workspace", {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ name }),
      });
      const saved = body?.tenant?.name || name;
      input.value = saved;
      // 会话快照同步改名，避免侧栏/标题还显示旧名字（下次登录会以服务端为准）。
      if (state?.session?.tenant) state.session.tenant.name = saved;
      setMessage(message, "工作区名称已保存。", "success");
    } catch {
      setMessage(message, "工作区名称未能保存，请稍后重试。", "danger");
    } finally {
      if (button) button.disabled = !canManageWorkspace();
    }
  }

  async function loadWorkspaceSettings() {
    // 两个请求互不阻塞：权限拉不到不影响名称显示，反之亦然。
    await Promise.allSettled([loadPermissions(), loadWorkspaceName()]);
  }

  byId("workspaceNameForm")?.addEventListener("submit", saveWorkspaceName);
  GEO.registerViewLoader?.("personal-settings", loadWorkspaceSettings);
})();
