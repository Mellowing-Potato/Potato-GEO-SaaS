/* 企业档案编辑会话：定时器、串行保存与显式版本冲突恢复。 */
(() => {
  const GEO = window.GEO;
  if (!GEO) return;
  GEO.createEnterpriseDraftEditor = ({ renderValidation }) => {
    const { state, elements, api } = GEO;
    const editor = elements.ekDraftEditor;
    const recovery = document.getElementById('ekDraftRecovery');
    const recoveryMessage = document.getElementById('ekDraftRecoveryMessage');
    const serverDraft = document.getElementById('ekServerDraft');
    const retry = document.getElementById('ekRetrySaveButton');
    const keep = document.getElementById('ekKeepLocalDraftButton');
    const useServer = document.getElementById('ekUseServerDraftButton');
    const unsaved = new Map(); // 页面内草稿，不写入跨登录持久化存储。
    let session = null;
    const owner = () => JSON.stringify([state.session?.tenant?.id, state.session?.user?.id, state.session?.tenant?.subdomain]);
    let lastOwner = owner();
    const scope = id => JSON.stringify([owner(), state.selectedProject?.id, id]);
    const current = s => syncOwner() && s && session === s && s.key === scope(s.id) && String(state.selectedEkId) === String(s.id);
    const status = text => { if (elements.ekAutosaveStatus) elements.ekAutosaveStatus.textContent = text; };
    const hideRecovery = () => { if (recovery) recovery.hidden = true; if (serverDraft) { serverDraft.value = ''; serverDraft.hidden = true; } };
    const dirty = s => editor.value !== s.savedContent;
    const remember = s => {
      if (!current(s) || !s.ready) return;
      if (dirty(s)) unsaved.set(s.key, { content: editor.value, version: s.version });
      else unsaved.delete(s.key);
    };
    function syncOwner() {
      const next = owner();
      if (lastOwner !== next) {
        unsaved.clear(); lastOwner = next;
        if (session) clearTimeout(session.timer);
        session = null;
        if (editor) { editor.value = ''; editor.disabled = true; }
        hideRecovery(); status('');
        return false;
      }
      return true;
    }
    function close() {
      const closing = session;
      if (closing) { remember(closing); clearTimeout(closing.timer); }
      session = null;
      if (editor) editor.disabled = true;
      hideRecovery();
    }
    function begin(id) {
      close();
      syncOwner();
      session = { id, key: scope(id), ready: false, version: null, savedContent: '', pending: null, blocked: false, conflict: null, external: false };
      if (editor) { editor.value = ''; editor.disabled = true; }
      status('正在加载草稿…');
      return session;
    }
    function fail(s, error) {
      if (!current(s)) return;
      s.blocked = true; remember(s);
      const conflict = error?.body?.action === 'enterprise_draft_conflict' ? error.body.current : null;
      s.conflict = conflict && Number.isSafeInteger(conflict.draft_version) ? conflict : null;
      status(s.conflict ? '版本冲突，本地编辑已保留' : '保存未确认，本地编辑已保留');
      if (recoveryMessage) recoveryMessage.textContent = s.conflict ? '服务器草稿已更新。请比较下方内容，选择保留本地编辑或使用服务器草稿。' : '保存结果尚未确认。请重试保存，或重新打开档案核对；本页编辑会保留。';
      if (recovery) recovery.hidden = false;
      if (retry) retry.hidden = !!s.conflict;
      if (keep) keep.hidden = !s.conflict;
      if (useServer) useServer.hidden = !s.conflict;
      if (serverDraft) { serverDraft.hidden = !s.conflict; serverDraft.value = s.conflict?.draft_content || ''; }
    }
    function loaded(s, project) {
      if (!current(s)) return false;
      if (!Number.isSafeInteger(project.draft_version)) { status('无法确认草稿版本，请刷新页面。'); return false; }
      s.ready = true; s.version = project.draft_version; s.savedContent = project.draft_content || '';
      editor.value = s.savedContent; editor.disabled = false; hideRecovery(); status('');
      const local = unsaved.get(s.key);
      if (local && local.content !== s.savedContent) {
        editor.value = local.content;
        if (local.version !== s.version) fail(s, { body: { action: 'enterprise_draft_conflict', current: { ...project, validation_items: project.validation_items } } });
        else { status('已恢复本页未保存的编辑。'); schedule(s); }
      } else unsaved.delete(s.key);
      return true;
    }
    function schedule(s) {
      clearTimeout(s.timer);
      if (!current(s) || !s.ready || s.blocked || s.external) return;
      s.timer = setTimeout(() => { if (current(s)) void flush(s); }, 900);
    }
    function input() {
      const s = session;
      if (!current(s) || !s.ready) return;
      remember(s);
      if (!s.blocked) status('编辑中…');
      schedule(s);
    }
    async function flush(s = session) {
      if (!current(s) || !s.ready || s.blocked || s.external) return false;
      clearTimeout(s.timer);
      if (s.pending) { await s.pending; return current(s) && !s.blocked ? flush(s) : false; }
      if (!dirty(s)) return true;
      const content = editor.value;
      if (!content.trim()) { status('正文为空，尚未保存。'); return false; }
      status('正在保存…');
      s.pending = (async () => {
        try {
          const res = await api(`/api/v1/enterprise-knowledge/${s.id}/autosave`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ content, base_version: s.version }),
          });
          if (!current(s)) return;
          if (!Number.isSafeInteger(res.draft_version)) throw new Error('missing draft version');
          s.version = res.draft_version; s.savedContent = content;
          if (state.ekDetail) state.ekDetail.draft_version = s.version;
          remember(s);
          if (!dirty(s)) { status(`已保存 ${GEO.formatKnowledgeDate(res.saved_at)}`); renderValidation(res.validation_items || []); }
        } catch (error) { fail(s, error); }
      })();
      await s.pending; s.pending = null;
      return current(s) && !s.blocked ? flush(s) : false;
    }
    async function action(path, payload, { replace = false } = {}) {
      const s = session;
      if (!await flush(s) || !current(s) || s.external) return null;
      s.external = true; clearTimeout(s.timer);
      const content = editor.value;
      try {
        const body = typeof payload === 'function' ? payload(content) : payload;
        const res = await api(`/api/v1/enterprise-knowledge/${s.id}/${path}`, {
          method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...body, base_version: s.version }),
        });
        if (!current(s)) return null;
        const version = res.data?.draft_version ?? res.draft_version;
        if (!Number.isSafeInteger(version)) throw new Error('missing draft version');
        s.version = version;
        const unchanged = editor.value === content;
        s.savedContent = replace ? (res.data?.draft_content || '') : content;
        if (replace && unchanged) editor.value = s.savedContent;
        if (state.ekDetail) state.ekDetail.draft_version = version;
        remember(s); hideRecovery();
        if (!dirty(s)) status('已保存');
        return { res, unchanged, session: s };
      } catch (error) { fail(s, error); return null; }
      finally { s.external = false; if (current(s) && dirty(s)) schedule(s); }
    }
    const discard = () => {
      if (session) clearTimeout(session.timer);
      session = null; unsaved.clear();
      if (editor) { editor.value = ''; editor.disabled = true; }
      hideRecovery(); status('');
    };
    retry?.addEventListener('click', () => {
      const s = session; if (!current(s) || s.conflict) return;
      s.blocked = false; hideRecovery(); void flush(s);
    });
    keep?.addEventListener('click', async () => {
      const s = session; if (!current(s) || !s.conflict) return;
      const conflict = s.conflict;
      if (!await GEO.confirmWorkspaceAction('将当前本地编辑保存为新的草稿版本？服务器现有内容仍可在修订历史中查看。')) return;
      if (!current(s) || s.conflict !== conflict) return;
      s.version = conflict.draft_version; s.savedContent = conflict.draft_content || ''; s.blocked = false; s.conflict = null;
      hideRecovery(); remember(s); await flush(s);
    });
    useServer?.addEventListener('click', async () => {
      const s = session; if (!current(s) || !s.conflict) return;
      const conflict = s.conflict;
      if (!await GEO.confirmWorkspaceAction('放弃本页未保存的编辑，使用所显示的服务器草稿？')) return;
      if (!current(s) || s.conflict !== conflict) return;
      s.version = conflict.draft_version; s.savedContent = conflict.draft_content || ''; editor.value = s.savedContent;
      s.blocked = false; s.conflict = null; remember(s); hideRecovery(); status('已使用服务器草稿'); renderValidation(conflict.validation_items || []);
    });
    window.addEventListener('beforeunload', event => {
      syncOwner();
      if (session) remember(session);
      if (unsaved.size) { event.preventDefault(); event.returnValue = ''; }
    });
    return { begin, loaded, current, close, input, flush, action, discard, capture: () => current(session) ? session : null };
  };
})();
