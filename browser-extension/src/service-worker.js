const instanceRuntime = globalThis.YANJI_RUNTIME;
const jobPrefix = 'zhihu-draft-job:' + globalThis.YANJI_INSTANCE.id + ':';
const activeDeliveries = new Set();

function validDraftUrl(value) {
  return typeof value === 'string' && /^https:\/\/zhuanlan\.zhihu\.com\/p\/\d+\/edit$/.test(value);
}

function validEditorUrl(value) {
  try {
    const url = new URL(value);
    return url.origin === 'https://zhuanlan.zhihu.com' && !url.username && !url.password
      && (url.pathname === '/write' || /^\/p\/\d+\/edit$/.test(url.pathname));
  } catch { return false; }
}

function validProfileUrl(value) {
  try {
    const url = new URL(value);
    return url.protocol === 'https:' && ['zhihu.com', 'www.zhihu.com'].includes(url.hostname)
      && /^\/people\/[^/]+\/?$/.test(url.pathname);
  } catch {
    return false;
  }
}

async function startDraft(message, sender) {
  if (!sender.tab?.id || !sender.documentId || !instanceRuntime.sourceUrl(sender.url)) {
    return { ok: false, code: 'wrong_source' };
  }
  const payload = message.payload;
  if (!/^[\w-]{20,80}$/.test(String(message.requestId ?? '')) || ! validProfileUrl(payload?.profileUrl)
    || typeof payload.title !== 'string' || ! payload.title.trim() || payload.title.length > 100
    || typeof payload.body !== 'string' || ! payload.body.trim() || payload.body.length > 60000
    || (payload.draftUrl != null && ! validDraftUrl(payload.draftUrl))) {
    return { ok: false, code: 'invalid_payload' };
  }
  if (payload.replaceExisting && ! payload.draftUrl) {
    return { ok: false, code: 'invalid_payload' };
  }

  const tab = await chrome.tabs.create({ url: payload.draftUrl || 'https://zhuanlan.zhihu.com/write' });
  await chrome.storage.session.set({ [jobPrefix + tab.id]: {
    ...instanceRuntime.stamp(sender), sourceTabId: sender.tab.id, requestId: message.requestId, payload,
    expiresAt: Date.now() + 120000,
  } });
  // The editor may have loaded before storage.set; the content script also sends READY after loading.
  await deliver(tab.id);
  return { ok: true };
}

async function deliver(tabId) {
  if (activeDeliveries.has(tabId)) return;
  const key = jobPrefix + tabId;
  const job = (await chrome.storage.session.get(key))[key];
  if (!job || !instanceRuntime.owns(job)) return;
  if (!await instanceRuntime.sourceAlive(job)) {
    await chrome.storage.session.remove(key);
    return;
  }
  if (!job.expiresAt || Date.now() >= job.expiresAt) {
    await chrome.storage.session.remove(key);
    await instanceRuntime.sendSource(job, {
      type: 'YANJI_DRAFT_RESULT', requestId: job.requestId,
      result: { ok: false, code: 'request_expired' },
    });
    return;
  }
  activeDeliveries.add(tabId);
  try {
    const result = await chrome.tabs.sendMessage(tabId, { type: 'YANJI_FILL_DRAFT', payload: job.payload });
    if (! result) return;
    await instanceRuntime.sendSource(job, {
      type: 'YANJI_DRAFT_RESULT', requestId: job.requestId, result,
    });
    await chrome.storage.session.remove(key);
  } catch {
    // No receiver yet: the editor content script will send READY when it loads.
  } finally {
    activeDeliveries.delete(tabId);
  }
}

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  if (message?.type === 'YANJI_START_DRAFT') {
    startDraft(message, sender).then(sendResponse).catch(() => sendResponse({ ok: false, code: 'extension_error' }));
    return true;
  }
  if (message?.type === 'YANJI_ZHIHU_READY' && sender.tab?.id && validEditorUrl(sender.url)) {
    deliver(sender.tab.id).catch(() => {});
  }
  return false;
});

chrome.tabs.onRemoved.addListener((tabId) => {
  (async () => {
    const targetKey = jobPrefix + tabId;
    const targetJob = (await chrome.storage.session.get(targetKey))[targetKey];
    if (targetJob && instanceRuntime.owns(targetJob)) {
      await instanceRuntime.sendSource(targetJob, {
        type: 'YANJI_DRAFT_RESULT', requestId: targetJob.requestId,
        result: { ok: false, code: 'editor_closed' },
      });
    }
    await chrome.storage.session.remove(targetKey);
    const all = await chrome.storage.session.get(null);
    const orphanKeys = Object.entries(all)
      .filter(([key, job]) => key.startsWith(jobPrefix) && job?.sourceTabId === tabId)
      .map(([key]) => key);
    if (orphanKeys.length) await chrome.storage.session.remove(orphanKeys);
  })().catch(() => {});
});
