(() => {
  'use strict';
  const message = document.getElementById('localStartMessage');
  const help = document.getElementById('localStartHelp');
  const card = document.getElementById('localStartCard');
  const copyButton = document.getElementById('localStartCopy');
  const copyStatus = document.getElementById('localStartCopyStatus');
  const fragment = location.hash;
  let ticket = /^#ticket=([a-f0-9]{64})$/.exec(fragment)?.[1] || '';
  // Scrub the one-time credential before any asynchronous request or navigation.
  history.replaceState(null, '', location.pathname);

  const fail = () => {
    message.textContent = '未能打开本机工作台，请重新从本机管理命令打开。';
    card?.classList?.add('is-error');
    help.hidden = false;
  };

  copyButton?.addEventListener?.('click', async () => {
    const command = 'yanji open';
    try {
      if (typeof navigator !== 'undefined' && navigator?.clipboard?.writeText) {
        await navigator.clipboard.writeText(command);
        if (copyStatus) copyStatus.textContent = '已复制命令：yanji open';
        return;
      }
    } catch {
      // Clipboard access can be restricted; fall back to manual copy hint.
    }
    if (copyStatus) copyStatus.textContent = '请手动复制上方命令：yanji open';
  });

  async function openWorkspace() {
    try {
      sessionStorage.removeItem('geo_token');
      if (fragment && !ticket) { fail(); return; }
      const endpoint = ticket ? '/api/v1/auth/local-start' : '/api/v1/auth/session-login';
      const body = JSON.stringify(ticket ? {ticket} : {});
      ticket = '';
      const response = await fetch(endpoint, {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        referrerPolicy: 'no-referrer', signal: AbortSignal.timeout(15000),
        headers: {'Content-Type': 'application/json', Accept: 'application/json'}, body,
      });
      if (!response.ok) { fail(); return; }
      const result = await response.json();
      if (typeof result.token !== 'string' || !result.token) { fail(); return; }
      sessionStorage.setItem('geo_token', result.token);
      location.replace('/');
    } catch {
      // Never print credentials or retry an exchange whose outcome is unknown.
      fail();
    }
  }

  openWorkspace();
})();
