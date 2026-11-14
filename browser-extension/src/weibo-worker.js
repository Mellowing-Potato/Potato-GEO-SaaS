(() => {
  'use strict';
  const instance = globalThis.YANJI_RUNTIME;
  const prefix = 'weibo-draft-job:' + globalThis.YANJI_INSTANCE.id + ':';
  const running = new Set();
  const sourceUrl = instance.sourceUrl;
  const editorUrl = value => {
    try { const u = new URL(value); return u.origin === 'https://card.weibo.com' && u.pathname === '/article/v5/editor'; }
    catch { return false; }
  };
  const numeric = value => typeof value === 'string' && /^\d{1,24}$/.test(value);
  async function start(message, sender) {
    if (!sender.tab?.id || !sender.documentId || !sourceUrl(sender.url)) return { ok: false, code: 'wrong_source' };
    const p = message.payload;
    if (!/^[\w-]{20,80}$/.test(message.requestId || '') || !p || !Number.isSafeInteger(p.deliveryId)
      || !/^[a-f0-9-]{36}$/.test(p.token || '') || !/^\d{4,24}$/.test(p.uid || '')
      || typeof p.title !== 'string' || !p.title.trim() || p.title.length > 32
      || typeof p.html !== 'string' || !p.html.trim() || p.html.length > 300000
      || !['create', 'verify'].includes(p.mode) || (p.mode === 'verify' && !numeric(p.draftId))
      || !Number.isSafeInteger(p.expiresAt) || p.expiresAt <= Date.now() || p.expiresAt > Date.now() + 660000) {
      return { ok: false, code: 'invalid_payload' };
    }
    const all = await chrome.storage.session.get(null);
    for (const [key, job] of Object.entries(all)) {
      if (!key.startsWith(prefix)) continue;
      if (job.expiresAt <= Date.now()) await chrome.storage.session.remove(key);
      else if (job.payload.deliveryId === p.deliveryId) return { ok: false, code: 'already_running' };
    }
    const url = 'https://card.weibo.com/article/v5/editor#/draft' + (p.draftId ? '/' + p.draftId : '');
    const tab = await chrome.tabs.create({ url });
    await chrome.storage.session.set({ [prefix + tab.id]: { ...instance.stamp(sender), sourceTabId: sender.tab.id,
      requestId: message.requestId, payload: p, expiresAt: p.expiresAt, phase: p.mode === 'verify' ? 'verifying' : 'prepared' } });
    await deliver(tab.id);
    return { ok: true };
  }
  async function deliver(tabId) {
    if (running.has(tabId)) return;
    const key = prefix + tabId;
    const job = (await chrome.storage.session.get(key))[key];
    if (!job || !instance.owns(job) || job.expiresAt <= Date.now()) return;
    if (!await instance.sourceAlive(job)) return;
    // A reload during a write never repeats it. Only a saved draft ID can be read back.
    if (!['prepared', 'verifying'].includes(job.phase)) return;
    running.add(tabId);
    try {
      await chrome.tabs.sendMessage(tabId, { type: 'YANJI_WEIBO_RUN', payload: job.payload });
    } catch { /* The content script announces READY once the editor loads. */ }
    finally { running.delete(tabId); }
  }
  async function progress(message, sender) {
    if (!sender.tab?.id || !editorUrl(sender.url)) return { ok: false };
    const key = prefix + sender.tab.id;
    const job = (await chrome.storage.session.get(key))[key];
    if (!job || !instance.owns(job) || job.expiresAt <= Date.now()) return { ok: false };
    const d = message.data || {};
    if (!['creating', 'created', 'saving', 'verifying', 'saved', 'error'].includes(d.stage)) return { ok: false };
    if (d.stage === 'created' && !numeric(d.draft_id)) return { ok: false };
    if (d.stage !== 'error' && d.uid !== job.payload.uid) return { ok: false };
    if (!['creating', 'created', 'error'].includes(d.stage) && d.draft_id !== job.payload.draftId) return { ok: false };
    // Keep a returned ID locally even if the source page disappears before its ACK.
    if (d.stage === 'created') {
      job.payload.draftId = d.draft_id;
      job.phase = 'created';
      await chrome.storage.session.set({ [key]: job });
    }
    const result = await instance.sendSource(job, { type: 'YANJI_WEIBO_STAGE', requestId: job.requestId,
      data: { ...d, delivery_id: job.payload.deliveryId, token: job.payload.token } });
    if (!result?.ok) return { ok: false };
    job.phase = d.stage;
    if (d.stage === 'verifying') {
      job.payload.mode = 'verify';
      job.payload.remote_images = d.images;
    }
    await chrome.storage.session.set({ [key]: job });
    return { ok: true };
  }
  async function finish(message, sender) {
    if (!sender.tab?.id || !editorUrl(sender.url)) return { ok: false };
    const key = prefix + sender.tab.id;
    const job = (await chrome.storage.session.get(key))[key];
    if (!job || !instance.owns(job)) return { ok: false };
    const ok = job.phase === 'saved' && message.result?.ok === true;
    await instance.sendSource(job, { type: 'YANJI_WEIBO_RESULT', requestId: job.requestId,
      result: { ok, code: ok ? 'draft_saved' : message.result?.code || 'helper_error', draftId: job.payload.draftId } });
    await chrome.storage.session.remove(key);
    return { ok: true };
  }
  chrome.runtime.onMessage.addListener((message, sender, respond) => {
    let task;
    if (message?.type === 'YANJI_WEIBO_START') task = start(message, sender);
    if (message?.type === 'YANJI_WEIBO_PROGRESS') task = progress(message, sender);
    if (message?.type === 'YANJI_WEIBO_FINISH') task = finish(message, sender);
    if (message?.type === 'YANJI_WEIBO_READY' && sender.tab?.id && editorUrl(sender.url)) deliver(sender.tab.id).catch(() => {});
    if (message?.type === 'YANJI_WEIBO_REOPEN' && sender.tab?.id && editorUrl(sender.url)) {
      task = (async () => {
        const job = (await chrome.storage.session.get(prefix + sender.tab.id))[prefix + sender.tab.id];
        if (!instance.owns(job) || !await instance.sourceAlive(job) || job?.phase !== 'verifying' || !numeric(job.payload.draftId)) return { ok: false };
        // One full navigation avoids a hash-only SPA transition racing reload()
        // and restoring the editor's previously selected draft.
        await chrome.tabs.update(sender.tab.id, { url: 'https://card.weibo.com/article/v5/editor?yanjiDraftCheck='
          + job.payload.draftId + '#/draft/' + job.payload.draftId });
        return { ok: true };
      })();
    }
    if (!task) return false;
    task.then(respond).catch(() => respond({ ok: false, code: 'helper_error' }));
    return true;
  });
  chrome.tabs.onRemoved.addListener(tabId => {
    (async () => {
      const key = prefix + tabId;
      const job = (await chrome.storage.session.get(key))[key];
      if (!job || !instance.owns(job)) return;
      await instance.sendSource(job, { type: 'YANJI_WEIBO_RESULT', requestId: job.requestId,
        result: { ok: false, code: 'editor_closed', draftId: job.payload.draftId } });
      await chrome.storage.session.remove(key);
    })().catch(() => {});
  });
})();
