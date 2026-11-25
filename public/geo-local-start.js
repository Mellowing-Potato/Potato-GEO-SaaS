(() => {
  'use strict';
  const message = document.getElementById('localStartMessage');
  const help = document.getElementById('localStartHelp');
  const check = document.getElementById('localStartCheck');
  let ticket = /^#ticket=([a-f0-9]{64})$/.exec(location.hash)?.[1] || '';
  const invalidFragment = Boolean(location.hash && !ticket);
  // Scrub the one-time credential before any asynchronous request or navigation.
  history.replaceState(null, '', location.pathname);

  let sequence = 0;
  let busy = false;
  let departed = false;
  let navigating = false;
  let cancelActive = null;
  const failures = {
    unauthorized: '当前浏览器还没有工作台访问授权。',
    link: '这次打开链接未能完成授权，可能已过期或已使用。',
    unavailable: '暂时无法连接本机工作台，或本机服务响应异常。',
    navigation: '当前页面未能进入工作台，请从桌面或应用菜单的本机入口重新打开。',
  };
  const fail = kind => {
    message.textContent = failures[kind];
    message.className = 'status is-error';
    help.hidden = false;
    check.disabled = false;
  };

  async function openWorkspace(endpoint, payload) {
    if (busy || departed || navigating) return;
    busy = true;
    check.disabled = true;
    message.className = 'status';
    message.textContent = endpoint === '/api/v1/auth/session-login'
      ? '正在检查当前浏览器…' : '正在打开你的本机工作台…';
    const requestId = ++sequence;
    const current = () => requestId === sequence && !departed && !navigating;
    const controller = new AbortController();
    // Invalidate late responses even if a transport does not honor cancellation.
    const timeout = setTimeout(() => {
      if (!current()) return;
      sequence++;
      busy = false;
      cancelActive = null;
      controller.abort();
      fail('unavailable');
    }, 15000);
    cancelActive = () => { clearTimeout(timeout); controller.abort(); };
    try {
      sessionStorage.removeItem('geo_token');
      const response = await fetch(endpoint, {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        referrerPolicy: 'no-referrer', signal: controller.signal,
        headers: {'Content-Type': 'application/json', Accept: 'application/json'},
        body: JSON.stringify(payload),
      });
      if (!current()) return;
      if (!response.ok) {
        const denied = [400, 401, 403, 410, 422].includes(response.status);
        fail(denied ? (endpoint === '/api/v1/auth/local-start' ? 'link' : 'unauthorized') : 'unavailable');
        return;
      }
      const result = await response.json();
      if (!current()) return;
      if (typeof result?.token !== 'string' || !result.token) { fail('unavailable'); return; }
      sessionStorage.setItem('geo_token', result.token);
      navigating = true;
      try { location.replace('/'); } catch {
        navigating = false;
        sessionStorage.removeItem('geo_token');
        fail('navigation');
      }
    } catch {
      // Never print credentials or retry an exchange whose outcome is unknown.
      if (current()) fail('unavailable');
    } finally {
      clearTimeout(timeout);
      if (requestId === sequence) {
        cancelActive = null;
        busy = false;
        check.disabled = departed || navigating;
      }
    }
  }

  check.addEventListener('click', () => {
    // A manual recheck can only reuse this browser's existing protected cookie.
    openWorkspace('/api/v1/auth/session-login', {});
  });
  window.addEventListener('pagehide', () => {
    departed = true;
    sequence++;
    cancelActive?.();
    cancelActive = null;
    busy = false;
    check.disabled = true;
  });
  window.addEventListener('pageshow', event => {
    if (!event.persisted) return;
    departed = false;
    navigating = false;
    fail('unauthorized');
  });

  if (invalidFragment) {
    try { sessionStorage.removeItem('geo_token'); } catch { /* No credential is usable here. */ }
    fail('link');
  } else if (ticket) {
    const payload = {ticket};
    ticket = '';
    openWorkspace('/api/v1/auth/local-start', payload);
  } else {
    openWorkspace('/api/v1/auth/session-login', {});
  }
})();
