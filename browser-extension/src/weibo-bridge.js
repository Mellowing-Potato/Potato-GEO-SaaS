(() => {
  'use strict';
  if (!globalThis.YANJI_RUNTIME.sourceUrl(location.href)) return;
  const channel = 'yanji-weibo-draft';
  const pending = new Map();
  const post = (data) => window.postMessage({ channel, ...data }, location.origin);
  window.addEventListener('message', (event) => {
    if (!globalThis.YANJI_RUNTIME.sourceUrl(location.href)) return;
    if (event.source !== window || event.origin !== location.origin || event.data?.channel !== channel) return;
    const message = event.data;
    if (message.type === 'STAGE_ACK') {
      const respond = pending.get(message.stageId);
      if (respond) { pending.delete(message.stageId); respond({ ok: message.ok === true }); }
      return;
    }
    if (!/^[\w-]{20,80}$/.test(String(message.requestId ?? ''))) return;
    if (message.type === 'PROBE') {
      post({ type: 'PROBE_RESULT', requestId: message.requestId, version: chrome.runtime.getManifest().version, instanceOrigin: globalThis.YANJI_INSTANCE.origin });
    } else if (message.type === 'START') {
      chrome.runtime.sendMessage({ type: 'YANJI_WEIBO_START', requestId: message.requestId, payload: message.payload })
        .then(result => { if (!result?.ok) post({ type: 'RESULT', requestId: message.requestId, ok: false, code: result?.code || 'helper_error' }); })
        .catch(() => post({ type: 'RESULT', requestId: message.requestId, ok: false, code: 'helper_error' }));
    }
  });
  chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
    if (sender.id !== chrome.runtime.id || !globalThis.YANJI_RUNTIME.accepts(message)) return false;
    if (message?.type === 'YANJI_WEIBO_STAGE') {
      const stageId = crypto.randomUUID();
      const timer = setTimeout(() => { pending.delete(stageId); sendResponse({ ok: false }); }, 20000);
      pending.set(stageId, (result) => { clearTimeout(timer); sendResponse(result); });
      post({ type: 'STAGE', requestId: message.requestId, stageId, data: message.data });
      return true;
    }
    if (message?.type === 'YANJI_WEIBO_RESULT') {
      post({ type: 'RESULT', requestId: message.requestId, ok: !!message.result?.ok,
        code: message.result?.code || 'helper_error', draftId: message.result?.draftId || null });
    }
    return false;
  });
})();
