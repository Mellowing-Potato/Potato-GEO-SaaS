/* ============================================================================
   个人设置 · 工作区与权限（geo-entry-workspace-settings.js）

   接上两条"后端有、前端零调用"的接口（2026-09-22 复核）：

     1) PATCH /api/v1/workspace        改工作区名称（can:team:manage）
        —— 接口 2026-09-13 就有，工作台一直没入口，改名只能靠运维改库。
     2) GET   /api/v1/me/permissions   当前角色的权限清单
        —— 用它决定改名表单是否可用（与接口层的 can:team:manage 同一口径），
           并把清单如实展示；读不到权限时不放开写入口。

   边界：只做名称读写，不碰成员/角色/租户设置（团队管理按产品口径暂不开放，
   见 panels/team.blade.php 的说明）。读不到的权限不猜：拉失败就置灰并说明原因。

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

  const ROLE_LABELS = { admin: "管理员", editor: "编辑", member: "成员", owner: "创建者", platform_admin: "平台管理员" };

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
    if (help && !allowed) {
      help.textContent = "只有工作区管理员（team:manage）可以改名；当前账号可以查看名称，但不能修改。";
    }
  }

  async function loadPermissions() {
    const roleNode = byId("myPermissionsRole");
    const listNode = byId("myPermissionsList");
    if (!roleNode || !listNode) return;
    try {
      const body = await api("/api/v1/me/permissions");
      permissions = Array.isArray(body?.permissions) ? body.permissions : [];
      const role = ROLE_LABELS[body?.role] || body?.role || "未知";
      roleNode.textContent = `当前角色：${role} · ${permissions.length} 项权限`;
      listNode.textContent = permissions.length
        ? permissions.join("、")
        : "当前账号没有任何显式权限，请联系工作区管理员。";
    } catch (error) {
      permissions = null;
      // 读不到权限就不放开写入口，也不能把"读不到"显示成"没有权限"。
      roleNode.textContent = `权限清单读取失败：${error?.message || "请重试"}。改名入口已暂时置灰，避免无权限提交。`;
      listNode.textContent = "";
    } finally {
      applyWorkspacePermission();
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
    } catch (error) {
      if (!fromSession && message) {
        setMessage(message, `工作区名称读取失败：${error?.message || "请重试"}。`, "danger");
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
      setMessage(message, "当前账号没有 team:manage 权限，不能修改工作区名称。", "danger");
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
    } catch (error) {
      setMessage(message, error?.message || "保存失败，请重试。", "danger");
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
