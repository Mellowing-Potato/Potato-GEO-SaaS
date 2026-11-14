(() => {
  'use strict';
  if (location.origin !== 'https://card.weibo.com' || location.pathname !== '/article/v5/editor') return;
  let busy = false;
  const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
  const fail = code => { throw new Error(code); };
  const normal = text => String(text).replace(/[\s\u200b-\u200d\ufeff]/gu, '');
  const documentOf = html => new DOMParser().parseFromString(html, 'text/html');
  const unique = values => [...new Set(values)].sort();
  const imageId = src => {
    try { const u = new URL(src); return /(^|\.)sinaimg\.cn$/.test(u.hostname) ? u.pathname.split('/').pop() : null; }
    catch { return null; }
  };
  async function hash(text) {
    return [...new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(text)))].map(x => x.toString(16).padStart(2, '0')).join('');
  }
  function identity() {
    const ids = unique([...document.querySelectorAll('.name a[href]')].map(a => {
      try { const u = new URL(a.href); return /^(www\.)?weibo\.com$/.test(u.hostname) ? u.pathname.match(/^\/u\/(\d{4,24})\/?$/)?.[1] : null; }
      catch { return null; }
    }).filter(Boolean));
    return ids.length === 1 ? ids[0] : null;
  }
  async function waitIdentity(uid) {
    for (let i = 0; i < 60; i++) {
      const current = identity();
      if (current) { if (current !== uid) fail('wrong_account'); return; }
      await delay(500);
    }
    fail('login_required');
  }
  function requestId(uid) {
    const seed = btoa(uid + '&' + Date.now()).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    const extra = [...crypto.getRandomValues(new Uint8Array(43))].map(x => 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_'[x % 64]).join('');
    return (seed + extra).slice(0, 43);
  }
  async function api(path, uid, fields = {}, draftId = null) {
    if (identity() !== uid) fail('wrong_account');
    const rid = requestId(uid);
    const url = new URL('/article/v5/aj/editor/' + path, location.origin);
    url.search = new URLSearchParams({ uid, ...(draftId ? { id: draftId } : {}), _rid: rid }).toString();
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 20000);
    try {
      const response = await fetch(url.href, { method: 'POST', credentials: 'include', redirect: 'error',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'SN-REQID': rid },
        body: new URLSearchParams(fields).toString(), signal: controller.signal });
      if (!response.ok) fail('platform_rejected');
      const data = await response.json();
      if (String(data.code) !== '100000') fail('platform_rejected');
      return data.data;
    } finally { clearTimeout(timer); }
  }
  async function transferImages(html, uid) {
    const dom = documentOf(html);
    if (dom.querySelector('script,iframe,object,embed,form,input,svg')) fail('unsupported_content');
    const images = [...dom.querySelectorAll('img')];
    if (images.length > 20) fail('too_many_images');
    const mapped = new Map();
    for (const img of images) {
      const src = img.getAttribute('src');
      if (imageId(src)) { img.src = new URL(src).href; continue; }
      let u;
      try { u = new URL(src); } catch { fail('image_transfer_failed'); }
      if (u.protocol !== 'https:' || u.username || u.password || u.href.length > 2048
        || /^(localhost|127\.|0\.|10\.|192\.168\.|169\.254\.|172\.(1[6-9]|2\d|3[01])\.|\[)/i.test(u.hostname)) fail('image_transfer_failed');
      if (!mapped.has(u.href)) {
        await api('plugins/asyncuploadimg', uid, { 'urls[0]': u.href });
        let pid;
        for (let i = 0; i < 30; i++) {
          await delay(1000);
          const rows = await api('plugins/asyncimginfo', uid, { 'urls[0]': u.href });
          const row = Array.isArray(rows) ? rows[0] : null;
          if (row && Number(row.task_status_code) === 2) fail('image_transfer_failed');
          if (row && Number(row.task_status_code) === 1 && /^[a-zA-Z0-9]+$/.test(row.pid || '')) { pid = row.pid; break; }
        }
        if (!pid) fail('image_transfer_failed');
        mapped.set(u.href, 'https://wx3.sinaimg.cn/large/' + pid + '.jpg');
      }
      img.src = mapped.get(u.href);
    }
    return { html: dom.body.innerHTML, images: images.map(img => img.src) };
  }
  async function stage(payload, name, data = {}) {
    const response = await chrome.runtime.sendMessage({ type: 'YANJI_WEIBO_PROGRESS',
      data: { stage: name, uid: payload.uid, draft_id: payload.draftId, ...data } });
    if (!response?.ok) fail('receipt_not_confirmed');
  }
  async function verify(payload) {
    const expected = documentOf(payload.html);
    const expectedLinks = unique([...expected.querySelectorAll('a[href]')].map(a => a.href));
    const expectedImages = (payload.remote_images || []).map(imageId).sort();
    for (let i = 0; i < 90; i++) {
      if (identity() !== payload.uid) fail('wrong_account');
      if (location.hash !== '#/draft/' + payload.draftId) fail('wrong_draft');
      const title = document.querySelector('textarea[placeholder="请输入标题"]');
      const body = document.querySelector('.ProseMirror[contenteditable="true"]');
      if (title && body && title.value === payload.title) {
        const clone = body.cloneNode(true);
        // Source-less ProseMirror separators are editor UI, not article images.
        // An unmarked image with a missing source must still fail verification.
        clone.querySelectorAll('.ProseMirror-widget, img.ProseMirror-separator:not([src])').forEach(e => e.remove());
        const textHash = await hash(normal(clone.textContent));
        const links = unique([...clone.querySelectorAll('a[href]')].map(a => a.href));
        const images = [...clone.querySelectorAll('img')].map(img => img.getAttribute('src') || '');
        if (textHash === payload.text_hash && JSON.stringify(links) === JSON.stringify(expectedLinks)
          && images.length === payload.image_count && images.every(src => imageId(src))
          && (!expectedImages.length || JSON.stringify(images.map(imageId).sort()) === JSON.stringify(expectedImages))) {
          await stage(payload, 'saved', { title: title.value, text_hash: textHash, links, image_count: images.length,
            helper_version: chrome.runtime.getManifest().version });
          await chrome.runtime.sendMessage({ type: 'YANJI_WEIBO_FINISH', result: { ok: true } });
          return;
        }
      }
      await delay(500);
    }
    fail('readback_mismatch');
  }
  async function run(payload) {
    if (busy) return;
    busy = true;
    try {
      await waitIdentity(payload.uid);
      if (payload.expiresAt <= Date.now()) fail('request_expired');
      if (payload.mode === 'verify') { await verify(payload); return; }
      const transformed = await transferImages(payload.html, payload.uid);
      // The product must persist this step before a non-idempotent platform write.
      await stage(payload, 'creating');
      const created = await api('draft/create', payload.uid);
      const id = String(created?.id || '');
      if (!/^\d{1,24}$/.test(id)) fail('create_unknown');
      payload.draftId = id;
      await stage(payload, 'created');
      await stage(payload, 'saving');
      await api('draft/save', payload.uid, { id, title: payload.title, subtitle: '', type: '', status: '0',
        publish_at: '', error_msg: '', error_code: '0', collection: '[]', free_content: '', content: transformed.html,
        cover: '', summary: '', writer: '', extra: 'null', is_word: '0', article_recommend: '[]',
        follow_to_read: '0', isreward: '0', pay_setting: '{"ispay":0,"isvclub":0}', source: '0',
        action: '1', content_type: '0', save: '1' }, id);
      await stage(payload, 'verifying', { images: transformed.images });
      await chrome.runtime.sendMessage({ type: 'YANJI_WEIBO_REOPEN' });
    } catch (error) {
      const known = ['wrong_account', 'login_required', 'unsupported_content', 'too_many_images', 'image_transfer_failed',
        'receipt_not_confirmed', 'request_expired', 'wrong_draft', 'readback_mismatch', 'platform_rejected', 'create_unknown'];
      const code = known.includes(error.message) ? error.message : 'connection_interrupted';
      await stage(payload, 'error', { code }).catch(() => {});
      await chrome.runtime.sendMessage({ type: 'YANJI_WEIBO_FINISH', result: { ok: false, code } }).catch(() => {});
    } finally { busy = false; }
  }
  chrome.runtime.onMessage.addListener((message, sender, respond) => {
    if (message?.type !== 'YANJI_WEIBO_RUN') return false;
    run(message.payload).catch(() => {});
    respond({ accepted: true });
    return false;
  });
  chrome.runtime.sendMessage({ type: 'YANJI_WEIBO_READY' }).catch(() => {});
})();
