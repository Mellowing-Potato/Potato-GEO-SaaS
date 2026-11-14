(() => {
  'use strict';
  const config = globalThis.YANJI_INSTANCE;
  const sourceUrl = value => {
    try {
      const url = new URL(value);
      return typeof config?.origin === 'string' && url.origin === config.origin
        && !url.username && !url.password && url.pathname === '/manual-publish';
    } catch { return false; }
  };
  const owns = job => job?.instanceId === config?.id && job?.sourceOrigin === config?.origin;
  const sourceAlive = async job => {
    if (!owns(job) || !job.sourceDocumentId) return false;
    try {
      const reply = await chrome.tabs.sendMessage(job.sourceTabId,
        { type: 'YANJI_SOURCE_PROBE', instanceId: config.id, sourceOrigin: config.origin },
        { documentId: job.sourceDocumentId });
      return reply?.ok === true && reply?.origin === config.origin;
    }
    catch { return false; }
  };
  const sendSource = async (job, message) => {
    if (!await sourceAlive(job)) return null;
    return chrome.tabs.sendMessage(job.sourceTabId, { ...message, instanceId: config.id, sourceOrigin: config.origin },
      { documentId: job.sourceDocumentId }).catch(() => null);
  };
  globalThis.YANJI_RUNTIME = Object.freeze({ sourceUrl, owns, sourceAlive, sendSource,
    stamp: sender => ({ instanceId: config.id, sourceOrigin: config.origin, sourceDocumentId: sender.documentId || null }),
    accepts: message => message?.instanceId === config?.id && message?.sourceOrigin === config?.origin,
  });
})();
