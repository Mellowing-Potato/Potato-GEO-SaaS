(() => {
  'use strict';

  if (!globalThis.YANJI_RUNTIME.sourceUrl(location.href)) return;
  const channel = 'yanji-zhihu-draft';

  window.addEventListener('message', (event) => {
    if (!globalThis.YANJI_RUNTIME.sourceUrl(location.href)) return;
    if (event.source !== window || event.origin !== location.origin) return;
    const message = event.data;
    if (message?.channel !== channel || message.type !== 'REQUEST') return;
    const payload = message.payload;
    if (! /^[\w-]{20,80}$/.test(String(message.requestId ?? ''))
      || typeof payload?.title !== 'string' || ! payload.title.trim() || payload.title.length > 100
      || typeof payload?.body !== 'string' || ! payload.body.trim() || payload.body.length > 60000
      || typeof payload?.profileUrl !== 'string') return;

    window.postMessage({ channel, type: 'ACK', requestId: message.requestId }, location.origin);
    chrome.runtime.sendMessage({ type: 'YANJI_START_DRAFT', requestId: message.requestId, payload })
      .then((result) => {
        if (result?.ok) return;
        window.postMessage({ channel, type: 'RESULT', requestId: message.requestId,
          ok: false, code: result?.code || 'extension_error' }, location.origin);
      })
      .catch(() => {
        window.postMessage({ channel, type: 'RESULT', requestId: message.requestId,
          ok: false, code: 'extension_error' }, location.origin);
      });
  });

  chrome.runtime.onMessage.addListener((message, sender, respond) => {
    if (sender.id !== chrome.runtime.id || !globalThis.YANJI_RUNTIME.accepts(message)) return;
    if (message?.type === 'YANJI_SOURCE_PROBE') {
      respond({ ok: globalThis.YANJI_RUNTIME.sourceUrl(location.href), origin: location.origin });
      return false;
    }
    if (message?.type !== 'YANJI_DRAFT_RESULT') return;
    const profilePath = (value) => typeof value === 'string' && /^\/people\/[^/?#\s]+$/.test(value) ? value : null;
    window.postMessage({ channel, type: 'RESULT', requestId: message.requestId,
      ok: !!message.result?.ok, code: message.result?.code,
      expectedProfile: profilePath(message.result?.expectedProfile),
      currentProfile: profilePath(message.result?.currentProfile),
      identityStage: ['profile_changing', 'profile_menu_unavailable'].includes(message.result?.identityStage)
        ? message.result.identityStage : null,
      saveStage: ['platform_not_saved', 'transition_not_observed'].includes(message.result?.saveStage)
        ? message.result.saveStage : null,
      saved: !!message.result?.saved, draftUrl: message.result?.draftUrl || null }, location.origin);
  });
})();
