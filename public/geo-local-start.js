(() => {
  'use strict';
  const message = document.getElementById('localStartMessage');
  const help = document.getElementById('localStartHelp');
  const check = document.getElementById('localStartCheck');
  let ticket = /^#ticket=([a-f0-9]{64})$/.exec(location.hash)?.[1] || '';
  // Scrub any fragment before asynchronous requests or navigation.
  history.replaceState(null, '', location.pathname);

  let sequence = 0;
  let busy = false;
  let departed = false;
  let navigating = false;
  let cancelActive = null;
  const failures = {
    unauthorized: '当前浏览器未能完成本机工作台连接，请点击下方按钮重新进入。',
    proxy: '检测到代理工具转发了本机地址（127.0.0.1）。请在代理软件中绕过本地地址或关闭浏览器代理插件后重试。',
    throttled: '打开操作过于频繁，请稍等片刻后再点击下方按钮重试。',
    unavailable: '暂时无法连接本机工作台，或本机服务响应异常。',
    navigation: '当前页面未能进入工作台，请点击下方按钮或在系统浏览器中重新打开本机地址。',
  };
  const fail = kind => {
    message.textContent = failures[kind] || failures.unavailable;
    message.className = 'status is-error';
    help.hidden = false;
    check.disabled = false;
  };

  async function postAuth(endpoint, payload, signal) {
    return fetch(endpoint, {
      method: 'POST', credentials: 'same-origin', cache: 'no-store',
      referrerPolicy: 'no-referrer', signal,
      headers: {'Content-Type': 'application/json', Accept: 'application/json'},
      body: JSON.stringify(payload),
    });
  }

  async function readErrorCode(response) {
    try {
      const body = await response.json();
      return typeof body?.error_code === 'string' ? body.error_code : '';
    } catch {
      return '';
    }
  }

  async function enterWorkspace(initialTicket = '') {
    if (busy || departed || navigating) return;
    busy = true;
    check.disabled = true;
    message.className = 'status';
    message.textContent = '正在打开你的本机工作台…';
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
      let response;
      if (initialTicket) {
        response = await postAuth('/api/v1/auth/local-start', {ticket: initialTicket}, controller.signal);
        if (!current()) return;
        if (!response.ok) {
          if (response.status === 429) { fail('throttled'); return; }
          if ([400, 401, 403, 410, 422].includes(response.status)) {
            if ((await readErrorCode(response)) === 'proxy_detected') {
              if (current()) fail('proxy');
              return;
            }
            if (!current()) return;
            // Ticket was already consumed or superseded by another open call: fall back to direct loopback entry.
            response = await postAuth('/api/v1/auth/local-start', {}, controller.signal);
            if (!current()) return;
          }
        }
      } else {
        response = await postAuth('/api/v1/auth/session-login', {}, controller.signal);
        if (!current()) return;
        if (response.status === 401 || response.status === 419) {
          response = await postAuth('/api/v1/auth/local-start', {}, controller.signal);
          if (!current()) return;
        }
      }

      if (!response.ok) {
        if (response.status === 429) { fail('throttled'); return; }
        const denied = [400, 401, 403, 410, 422].includes(response.status);
        if (denied && (await readErrorCode(response)) === 'proxy_detected') {
          if (current()) fail('proxy');
          return;
        }
        if (current()) fail(denied ? 'unauthorized' : 'unavailable');
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
      // Never print credentials or retry an exchange whose network outcome is unknown.
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
    enterWorkspace('');
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

  const initialTicket = ticket;
  ticket = '';
  enterWorkspace(initialTicket);
})();
