import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { webcrypto, createHash } from 'node:crypto';

const read = file => fs.readFileSync(new URL('../../' + file, import.meta.url), 'utf8');
const basePayload = () => ({ deliveryId: 20, token: '00000000-0000-4000-8000-000000000001', uid: '1234567890',
  title: '科研工作流', html: '<p>完整正文<a href="https://example.test/ref">来源</a></p>', mode: 'create', draftId: null,
  text_hash: createHash('sha256').update('完整正文来源').digest('hex'), links: ['https://example.test/ref'], image_count: 0,
  expiresAt: Date.now() + 600000 });
const requestId = 'request-id-123456789012345';
const sourceSender = { tab: { id: 7 }, documentId: 'test-source-document', url: 'http://localhost:8000/manual-publish?project_id=5' };
const editorSender = { tab: { id: 42 }, url: 'https://card.weibo.com/article/v5/editor' };

function worker() {
  const stored = new Map(), messages = [], opened = [], updated = [], reloaded = [];
  let listener, ack = true;
  const chrome = {
    storage: { session: {
      async get(key) { return key == null ? Object.fromEntries(stored) : { [key]: stored.get(key) }; },
      async set(items) { Object.entries(items).forEach(([key, value]) => stored.set(key, structuredClone(value))); },
      async remove(key) { stored.delete(key); }
    } },
    tabs: { async create({ url }) { opened.push(url); return { id: 42 }; },
      async update(id, data) { updated.push(data.url); }, async reload(id) { reloaded.push(id); },
      async sendMessage(id, message, options) { if (message.type === 'YANJI_SOURCE_PROBE') { assert.equal(options.documentId, 'test-source-document'); return { ok: true, origin: 'http://localhost:8000' }; } messages.push({ id, message }); return id === 7 ? { ok: ack } : { accepted: true }; },
      onRemoved: { addListener() {} }
    }, runtime: { onMessage: { addListener(callback) { listener = callback; } } }
  };
  const context = vm.createContext({ chrome, URL, Date, Set, Object, YANJI_INSTANCE: { origin: 'http://localhost:8000', id: 'test-instance' } });
  vm.runInContext(read('browser-extension/src/instance-runtime.js'), context);
  vm.runInContext(read('browser-extension/src/weibo-worker.js'), context);
  return { stored, messages, opened, updated, reloaded, rejectAck() { ack = false; },
    send(message, sender = sourceSender) { return new Promise(resolve => listener(message, sender, resolve)); } };
}

test('worker rejects foreign pages, limits payloads and serializes one target tab per delivery', async () => {
  const h = worker(), payload = basePayload();
  assert.equal((await h.send({ type: 'YANJI_WEIBO_START', requestId, payload }, { ...sourceSender, url: 'https://example.test/manual-publish' })).code, 'wrong_source');
  assert.equal((await h.send({ type: 'YANJI_WEIBO_START', requestId, payload: { ...payload, title: '字'.repeat(33) } })).code, 'invalid_payload');
  assert.equal((await h.send({ type: 'YANJI_WEIBO_START', requestId, payload })).ok, true);
  assert.equal((await h.send({ type: 'YANJI_WEIBO_START', requestId, payload })).code, 'already_running');
  assert.equal(h.opened.length, 1);
});

test('a returned draft ID survives a lost receipt ACK; saved requires an acknowledged readback', async () => {
  const h = worker();
  await h.send({ type: 'YANJI_WEIBO_START', requestId, payload: basePayload() });
  h.rejectAck();
  const r = await h.send({ type: 'YANJI_WEIBO_PROGRESS', data: { stage: 'created', uid: '1234567890', draft_id: '987654' } }, editorSender);
  assert.equal(r.ok, false);
  assert.equal(h.stored.get('weibo-draft-job:test-instance:42').payload.draftId, '987654');
  await h.send({ type: 'YANJI_WEIBO_FINISH', result: { ok: true } }, editorSender);
  assert.equal(h.messages.at(-1).message.result.ok, false);
});

test('the component reloads only the created draft, then a later start is read-only', async () => {
  const h = worker();
  await h.send({ type: 'YANJI_WEIBO_START', requestId, payload: basePayload() });
  for (const stage of ['creating', 'created', 'saving', 'verifying']) {
    assert.equal((await h.send({ type: 'YANJI_WEIBO_PROGRESS', data: { stage, uid: '1234567890', draft_id: '987654', images: [] } }, editorSender)).ok, true);
  }
  await h.send({ type: 'YANJI_WEIBO_REOPEN' }, editorSender);
  const url = new URL(h.updated[0]);
  assert.equal(url.hash, '#/draft/987654');
  assert.equal(url.searchParams.get('yanjiDraftCheck'), '987654', 'query change forces one document navigation; hash-only update races the old SPA route');
  assert.deepEqual(h.reloaded, []);
  assert.equal(h.stored.get('weibo-draft-job:test-instance:42').payload.mode, 'verify');
});

async function article({ uid = '1234567890', ack = true, rejectCreate = false, verify = false, mismatch = false, imageFailure = false, separator = false, brokenImage = false } = {}) {
  let listener, finish;
  const finished = new Promise(resolve => { finish = resolve; });
  const requests = [], stages = [];
  const payload = { ...basePayload(), ...(verify ? { mode: 'verify', draftId: '987654', remote_images: [] } : {}) };
  if (imageFailure) { payload.html += '<img src="https://images.example.test/img.png">'; payload.image_count = 1; }
  const img = { src: 'https://images.example.test/img.png', getAttribute() { return this.src; } };
  function parsed() {
    return { body: { innerHTML: payload.html }, querySelector() { return null; },
      querySelectorAll(selector) { return selector === 'img' && imageFailure ? [img] : selector === 'a[href]' ? [{ href: 'https://example.test/ref' }] : []; } };
  }
  const body = { cloneNode() {
    let hasSeparator = separator;
    return { textContent: mismatch ? '缺失正文' : '完整正文来源', querySelectorAll(selector) {
      if (selector.includes('img.ProseMirror-separator:not([src])')) return hasSeparator ? [{ remove() { hasSeparator = false; } }] : [];
      if (selector === 'img') return hasSeparator || brokenImage ? [{ getAttribute() { return null; } }] : [];
      return selector === 'a[href]' ? [{ href: 'https://example.test/ref' }] : [];
    } };
  } };
  const document = {
    querySelectorAll(selector) { return selector === '.name a[href]' ? [{ href: 'https://weibo.com/u/' + uid }] : []; },
    querySelector(selector) { return selector.startsWith('textarea') ? { value: payload.title } : body; }
  };
  const chrome = { runtime: { getManifest() { return { version: '0.4.0' }; },
    onMessage: { addListener(callback) { listener = callback; } }, async sendMessage(message) {
      if (message.type === 'YANJI_WEIBO_PROGRESS') { stages.push(message.data); return { ok: ack }; }
      if (message.type === 'YANJI_WEIBO_FINISH') finish(message.result);
      if (message.type === 'YANJI_WEIBO_REOPEN') finish({ reopened: true });
      return { ok: true };
    } } };
  vm.runInNewContext(read('browser-extension/src/weibo-article.js'), {
    chrome, document, location: { origin: 'https://card.weibo.com', pathname: '/article/v5/editor', hash: '#/draft/987654' },
    DOMParser: class { parseFromString() { return parsed(); } }, URL, URLSearchParams, TextEncoder,
    crypto: webcrypto, btoa, AbortController, Uint8Array,
    setTimeout(fn, ms) { return ms <= 1000 ? setImmediate(fn) : 123; }, clearTimeout() {},
    async fetch(url, options) {
      requests.push({ url, options });
      if (rejectCreate && new URL(url).pathname.endsWith('/draft/create')) throw new Error('network lost');
      return { ok: true, async json() { return { code: 100000, data: imageFailure ? [{ task_status_code: 2 }] : { id: '987654' } }; } };
    }
  });
  listener({ type: 'YANJI_WEIBO_RUN', payload }, {}, () => {});
  return { result: await finished, requests, stages };
}

test('wrong account and missing product ACK stop before any remote draft write', async () => {
  const wrong = await article({ uid: '9999999999' });
  assert.equal(wrong.result.code, 'wrong_account'); assert.equal(wrong.requests.length, 0);
  const denied = await article({ ack: false });
  assert.equal(denied.result.code, 'receipt_not_confirmed'); assert.equal(denied.requests.length, 0);
});

test('direct API path creates a new draft, saves full HTML, persists ID before saving, and never publishes', async () => {
  const h = await article();
  assert.equal(h.result.reopened, true);
  assert.deepEqual(h.requests.map(r => new URL(r.url).pathname), ['/article/v5/aj/editor/draft/create', '/article/v5/aj/editor/draft/save']);
  const body = new URLSearchParams(h.requests[1].options.body);
  assert.equal(body.get('content'), basePayload().html); assert.equal(body.get('status'), '0');
  assert.deepEqual(h.stages.map(s => s.stage), ['creating', 'created', 'saving', 'verifying']);
  assert.equal(h.stages[1].draft_id, '987654');
});

test('lost create response is not retried and image transfer failure cannot silently create an incomplete draft', async () => {
  const lost = await article({ rejectCreate: true });
  assert.equal(lost.requests.length, 1); assert.equal(lost.result.ok, false);
  const image = await article({ imageFailure: true });
  assert.equal(image.result.code, 'image_transfer_failed');
  assert.ok(image.requests.every(r => !r.url.includes('/draft/')));
});

test('reload readback checks the entire body and links without writing or overwriting a draft', async () => {
  const good = await article({ verify: true });
  assert.equal(good.result.ok, true); assert.equal(good.requests.length, 0);
  assert.equal(good.stages[0].stage, 'saved');
  const bad = await article({ verify: true, mismatch: true });
  assert.equal(bad.result.code, 'readback_mismatch'); assert.equal(bad.requests.length, 0);
  assert.ok(!bad.stages.some(s => s.stage === 'saved'));
});

test('ProseMirror source-less separators are not article pictures; unmarked broken pictures still fail', async () => {
  const separator = await article({ verify: true, separator: true });
  assert.equal(separator.result.ok, true);
  assert.equal(separator.stages[0].image_count, 0);
  const broken = await article({ verify: true, brokenImage: true });
  assert.equal(broken.result.code, 'readback_mismatch');
  assert.ok(!broken.stages.some(s => s.stage === 'saved'));
});

test('normally distributed manifest includes both adapters with storage-only permissions', () => {
  const m = JSON.parse(read('browser-extension/manifest.json'));
  assert.equal(m.version, '0.4.0'); assert.deepEqual(m.permissions, ['storage']);
  assert.ok(m.content_scripts.some(s => s.matches.includes('https://card.weibo.com/article/v5/editor*')));
  assert.equal(read('browser-extension/src/publishing-worker.js').trim(), "importScripts('instance-config.js', 'instance-runtime.js', 'service-worker.js', 'weibo-worker.js');");
});
