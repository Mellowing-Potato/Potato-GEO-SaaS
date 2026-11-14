import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { webcrypto } from 'node:crypto';
const read = file => fs.readFileSync(new URL('../../' + file, import.meta.url), 'utf8');
class Element {
  constructor(tag) { this.tagName = tag; this.children = []; this.textContent = ''; this.value = ''; this.isConnected = true; this.events = {}; }
  append(...children) { this.children.push(...children); }
  setAttribute() {}
  addEventListener(type, cb) { this.events[type] = cb; }
}
function elements(n) { return [n, ...n.children.flatMap(elements)]; }
const settle = async () => { for (let i = 0; i < 5; i++) await new Promise(setImmediate); };
function ui({ delivery = null, stale = false, helper = true, helperVersion = '0.3.1', failAck = false, loadError = false } = {}) {
  const host = new Element('div'), title = new Element('input'); title.value = '原始审核文章标题';
  let displayedTitleLength = title.value.length;
  const requests = [], posts = [], listeners = new Set();
  const window = {
    addEventListener(type, cb) { listeners.add(cb); }, removeEventListener(type, cb) { listeners.delete(cb); },
    postMessage(message) {
      posts.push(message);
      if (message.type === 'PROBE' && helper) queueMicrotask(() => dispatch({ type: 'PROBE_RESULT', requestId: message.requestId, version: helperVersion }));
      if (message.type === 'START') queueMicrotask(() => dispatch({ type: 'STAGE', requestId: message.requestId, stageId: 'stage-id', data: {
        stage: 'creating', delivery_id: 20, token: message.payload.token, uid: '1234567890' } }));
      if (message.type === 'STAGE_ACK') queueMicrotask(() => dispatch({ type: 'RESULT', requestId: posts.find(p => p.type === 'START').requestId, ok: message.ok, code: message.ok ? 'draft_saved' : 'receipt_not_confirmed' }));
    }
  };
  function dispatch(data) { for (const cb of [...listeners]) cb({ source: window, origin: 'http://localhost:8000', data: { channel: 'yanji-weibo-draft', ...data } }); }
  const timers = new Map(); let timerId = 0;
  vm.runInNewContext(read('public/geo-weibo-drafts.js'), { window, document: { createElement: tag => new Element(tag) },
    crypto: webcrypto, location: { origin: 'http://localhost:8000' }, Date, Object,
    setTimeout(cb) { timers.set(++timerId, cb); return timerId; }, clearTimeout(id) { timers.delete(id); }
  });
  window.GEO_WEIBO_DRAFTS.mount({ host, titleInput: title, publication: { id: 9, revision: 3, status: 'in_progress', risk_status: 'clean' },
    canTransition: true, onTitleSet() { displayedTitleLength = title.value.length; },
    async apiGet() { if (loadError) throw new Error('记录加载失败'); return { data: { delivery, stale } }; },
    async apiPost(path, data) {
      requests.push({ path, data });
      if (path.endsWith('/start')) return { data: { delivery: { id: 20, status: 'prepared', publication_title: '短标题', article_version: 1 },
        request_token: 'opaque-token', payload: { deliveryId: 20, token: 'opaque-token', mode: 'create' } } };
      if (failAck) throw new Error('文章审核版本已变化');
      return { data: { id: 20, status: 'creating', publication_title: '短标题', article_version: 1 } };
    }
  });
  return { host, title, posts, requests, timers, titleLength: () => displayedTitleLength, nodes: () => elements(host),
    button() { return elements(host).find(n => n.tagName === 'button'); },
    message() { return elements(host).find(n => n.className?.startsWith('form-msg'))?.textContent || ''; } };
}

test('a missing helper cannot reserve a sending task; failure is explicit', async () => {
  const h = ui({ helper: false }); await settle();
  const action = h.button().events.click(); await settle();
  for (const fn of [...h.timers.values()]) fn();
  await action;
  assert.equal(h.requests.length, 0); assert.match(h.message(), /未检测到新版草稿助手/);
});
test('a server-rejected stage is NACKed to the component and cannot show a saved result', async () => {
  const h = ui({ failAck: true }); await settle(); await h.button().events.click();
  assert.equal(h.posts.find(p => p.type === 'STAGE_ACK').ok, false);
  assert.match(h.message(), /停止后续写入/); assert.ok(!h.message().includes('已自动保存'));
  assert.ok(h.requests.every(r => !r.path.includes('/transition')));
});
test('0.3.0 is rejected before any reservation after the live readback compatibility fix', async () => {
  const h = ui({ helperVersion: '0.3.0' }); await settle(); await h.button().events.click();
  assert.equal(h.requests.length, 0); assert.match(h.message(), /更新到 0\.3\.1/);
});
test('unknown creation blocks another write while a known ID exposes only verification and its editor link', async () => {
  const unknown = ui({ delivery: { status: 'unknown', publication_title: '短标题', article_version: 1 } }); await settle();
  assert.equal(unknown.button().disabled, true);
  const saved = ui({ delivery: { status: 'saved', publication_title: '短标题', article_version: 1,
    draft_url: 'https://card.weibo.com/article/v5/editor#/draft/123' } }); await settle();
  assert.equal(saved.button().textContent, '重新核对已有微博草稿');
  assert.equal(saved.nodes().find(n => n.tagName === 'a').hidden, false);
  assert.equal(saved.title.disabled, true);
  assert.equal(saved.titleLength(), 3, 'restored channel title must also refresh its visible character count');
  const old = ui({ stale: true, delivery: { status: 'saved', publication_title: '旧标题', article_version: 1 } }); await settle();
  assert.equal(old.button().disabled, true);
});
test('failed record reads keep sending disabled and the actual product view distributes the UI before mount', async () => {
  const h = ui({ loadError: true }); await settle(); assert.equal(h.button().disabled, true);
  const blade = read('resources/views/manual-publish.blade.php');
  assert.ok(blade.indexOf('/geo-weibo-drafts.js') < blade.indexOf('/geo-manual-publish.js?v='));
  assert.match(read('public/geo-publication-drafts.js'), /GEO_WEIBO_DRAFTS\.mount/);
  assert.match(read('public/geo-manual-publish.js'), /apiPost: apiPost, apiGet: apiGet/);
});
