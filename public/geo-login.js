(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const query = new URL(location.href).searchParams;
  let mode = query.get('mode') === 'reset' && query.get('token') ? 'reset' : 'login';
  const resetToken = query.get('token') || '';
  if (mode === 'reset') {
    $('email').value = query.get('email') || '';
    // Keep the reset credential out of subsequent same-origin referrers.
    history.replaceState(null, '', location.pathname);
  }
  let busy = false;
  const labels = {login: '登录', register: '注册账号', forgot: '发送重置邮件', reset: '重置密码'};
  const setStatus = message => { $('loginMessage').textContent = message; };
  function render() {
    $('loginTitle').textContent = labels[mode];
    $('submitLogin').textContent = busy ? '正在处理…' : labels[mode];
    $('submitLogin').disabled = busy;
    $('passwordFields').hidden = mode === 'forgot';
    $('password').required = mode !== 'forgot';
    $('password').autocomplete = mode === 'login' ? 'current-password' : 'new-password';
    $('confirmFields').hidden = !['register', 'reset'].includes(mode);
    $('confirmPassword').required = ['register', 'reset'].includes(mode);
    $('nameFields').hidden = mode !== 'register';
    $('name').required = mode === 'register';
    $('backToLogin').hidden = mode === 'login';
    $('forgotPassword').hidden = mode !== 'login';
    if ($('registerAccount')) $('registerAccount').hidden = mode !== 'login';
  }
  document.querySelectorAll('[data-mode]').forEach(button => button.addEventListener('click', () => {
    if (busy) return;
    mode = button.dataset.mode;
    $('password').value = $('confirmPassword').value = '';
    setStatus(''); render();
  }));
  $('terms')?.addEventListener('change', () => {
    if ($('terms').checked) { $('terms').removeAttribute('aria-invalid'); setStatus(''); }
  });
  $('geoLoginForm').addEventListener('submit', async event => {
    event.preventDefault();
    if (busy) return;
    if ($('terms') && !$('terms').checked) {
      $('terms').setAttribute('aria-invalid', 'true'); $('terms').focus();
      setStatus('请先勾选并同意用户协议和隐私政策。'); return;
    }
    if (!$('geoLoginForm').reportValidity()) return;
    if (['register', 'reset'].includes(mode) && $('password').value !== $('confirmPassword').value) {
      setStatus('两次输入的密码不一致。'); return;
    }
    busy = true; render(); setStatus('正在处理，请稍候。');
    try {
      const paths = {login: 'login', register: 'register', forgot: 'forgot-password', reset: 'reset-password'};
      const response = await fetch('/api/v1/account/' + paths[mode], {
        method: 'POST', credentials: 'same-origin', signal: AbortSignal.timeout(15000),
        headers: {'Content-Type': 'application/json', Accept: 'application/json'},
        body: JSON.stringify({email: $('email').value, name: $('name').value,
          password: mode === 'forgot' ? undefined : $('password').value,
          password_confirmation: $('confirmPassword').value,
          token: mode === 'reset' ? resetToken : undefined, terms_accepted: $('terms')?.checked}),
      });
      const body = await response.json();
      if (!response.ok) throw new Error(Object.values(body.errors || {}).flat()[0] || body.message || '操作失败，请重试。');
      if (mode === 'forgot' || mode === 'reset') {
        setStatus(body.message); if (mode === 'reset') mode = 'login';
        $('password').value = $('confirmPassword').value = ''; return;
      }
      if (!body.token) throw new Error('登录凭证缺失，请重试。');
      sessionStorage.setItem('geo_token', body.token);
      if (body.account_restored) sessionStorage.setItem('geo_account_restored', body.account_restored_at || '1');
      const target = new URL(query.get('redirect_to') || '/', location.origin);
      location.replace(target.origin === location.origin && !target.username && !target.password && !['/login', '/account'].includes(target.pathname) ? target.href : '/');
    } catch (error) { setStatus(error.name === 'TimeoutError' ? '请求超时，请重试。' : error.message); }
    finally { busy = false; render(); }
  });
  render();
})();
