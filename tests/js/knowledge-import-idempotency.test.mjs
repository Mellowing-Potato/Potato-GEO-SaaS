import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { webcrypto, randomUUID } from 'node:crypto';
import { File } from 'node:buffer';
import { runInContext, createContext } from 'node:vm';
import { test } from 'node:test';

const entry = readFileSync(new URL('../../public/geo-entry.js', import.meta.url), 'utf8');
const imports = readFileSync(new URL('../../public/geo-knowledge-import.js', import.meta.url), 'utf8');
const source = readFileSync(new URL('../../public/geo-entry-knowledge-base.js', import.meta.url), 'utf8');
const helpers = entry.slice(entry.indexOf('  function newIdempotencyKey('), entry.indexOf('  async function apiWithSessionRetry('));
const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
const material = (text = 'AAAA') => new File([text], 'same.txt', { type: 'text/plain', lastModified: 1 });
const pause = () => new Promise(resolve => setImmediate(resolve));
async function until(predicate) { for (let n = 0; n < 100 && !predicate(); n++) await pause(); assert.ok(predicate(), 'expected request did not arrive'); }

test('workbench loads the versioned import coordinator before the knowledge handlers', () => {
  const page = readFileSync(new URL('../../resources/views/welcome.blade.php', import.meta.url), 'utf8');
  const helper = page.indexOf('<script src="/geo-knowledge-import.js?v=');
  assert.ok(helper > page.indexOf('<script src="/geo-entry.js?v='));
  assert.ok(helper < page.indexOf('<script src="/geo-entry-knowledge-base.js?v='));
  assert.match(page, /PublicAssetVersion::for\('geo-knowledge-import\.js'\)/);
});

function harness({ storage = new Map(), crypto = webcrypto } = {}) {
  const state = { selectedKbId: 31, selectedProject: { id: 21 }, session: { tenant: { id: 11, subdomain: 'workspace-a' }, user: { id: 101 } }, kbDocuments: [], knowledgeBases: [] };
  const node = () => ({ textContent: '', disabled: false, handlers: {}, addEventListener(name, fn) { this.handlers[name] = fn; } });
  const makeForm = () => {
    const form = node();
    form.file = { ...node(), files: [material()] }; form.url = { value: 'https://example.com/material' };
    form.message = node(); form.submit = node(); form.project = { value: '21' }; form.resets = 0;
    form.querySelector = selector => selector === 'input[type="file"]' ? form.file
      : selector === 'button[type="submit"]' ? form.submit : selector.includes('Select') ? form.project : form.message;
    form.reset = () => { form.resets++; form.file.files = []; form.url.value = ''; };
    return form;
  };
  const upload = makeForm(), url = makeForm();
  const elements = { kbUploadForm: upload, kbIngestUrlForm: url, kbDetailMessage: node() };
  const calls = [];
  const h = { state, upload, url, elements, calls, storage, reply: async () => ({ data: { id: 41 } }) };
  class BrowserFormData extends FormData {
    constructor(form) { super(); if (form) this.append('url', form.url.value); }
  }
  const sessionStorage = { getItem: key => storage.get(key) ?? null, setItem: (key, value) => storage.set(key, value), removeItem: key => storage.delete(key) };
  const tenantSubdomain = () => state.session.tenant.subdomain;
  const GEO = { state, elements, tenantSubdomain, setMessage: (node, text) => { if (node) node.textContent = text; },
    validationMessage: error => error.message, formatNumber: String, loadKnowledgeStats() {},
    api: async (path, options = {}) => {
      if (options.method === 'POST') { calls.push({ path, options, tenant: state.session.tenant.id, project: state.selectedProject.id }); return h.reply(path, options); }
      return { data: [] };
    },
  };
  const context = createContext({ state, tenantSubdomain, sessionStorage, crypto, FormData: BrowserFormData,
    Uint8Array, console, URL, setTimeout, clearTimeout,
    window: { GEO, setInterval: () => 1, clearInterval() {} },
    document: { getElementById: () => null },
  });
  runInContext(helpers + '\nObject.assign(window.GEO, { pendingIdempotencyRequest, clearPendingIdempotencyRequest, retainPendingIdempotencyRequest });', context);
  runInContext(imports, context);
  // 只暴露原模块闭包中的操作用于测试；所有提交/保存键/异常逻辑均是源文件本身。
  const end = source.lastIndexOf('})();');
  runInContext(source.slice(0, end) + 'window.GEO.testRecrawl = recrawlKbDocument;\n' + source.slice(end), context);
  h.submit = kind => { const form = h[kind]; return form.handlers.submit({ preventDefault() {}, currentTarget: form }); };
  h.recrawl = () => GEO.testRecrawl(51);
  h.close = () => GEO.closeKnowledgeBaseDetail();
  return h;
}

for (const kind of ['upload', 'url']) {
  test(`${kind}: double submit sends one keyed request while first response is pending`, async () => {
    const h = harness(); const response = deferred(); h.reply = () => response.promise;
    const one = h.submit(kind), two = h.submit(kind);
    await until(() => h.calls.length > 0);
    assert.equal(h.calls.length, 1);
    assert.ok(h.calls[0].options.headers['X-Idempotency-Key']);
    assert.equal(h[kind].submit.disabled, true);
    response.resolve({ data: { id: 41 } }); await Promise.all([one, two]);
    assert.equal(h[kind].submit.disabled, false); assert.equal(h[kind].resets, 1);
  });

  test(`${kind}: lost response keeps the same key across a page reload`, async () => {
    const h = harness(); h.reply = async () => { throw new Error('response lost'); };
    await h.submit(kind);
    const firstKey = h.calls[0].options.headers?.['X-Idempotency-Key']; assert.ok(firstKey);
    assert.equal(h[kind].resets, 0);
    const reloaded = harness({ storage: h.storage }); await reloaded.submit(kind);
    assert.equal(reloaded.calls[0].options.headers['X-Idempotency-Key'], firstKey);
    assert.equal(reloaded.storage.size, 0);
    reloaded[kind].file.files = [material()]; reloaded[kind].url.value = 'https://example.com/material';
    await reloaded.submit(kind);
    assert.notEqual(reloaded.calls[1].options.headers['X-Idempotency-Key'], firstKey, 'a confirmed success permits a deliberate new import');
  });

  test(`${kind}: accepted request after switching project cannot reset new form or discard old action`, async () => {
    const h = harness(); const response = deferred(); h.reply = () => response.promise;
    const one = h.submit(kind); await until(() => h.calls.length === 1);
    const originalKey = h.calls[0].options.headers?.['X-Idempotency-Key']; assert.ok(originalKey);
    h.close(); h.state.selectedKbId = 32; h.state.selectedProject = { id: 22 }; h[kind].project.value = '22';
    h[kind].message.textContent = 'new project';
    response.resolve({ data: { id: 41 } }); await one;
    assert.equal(h[kind].resets, 0); assert.equal(h[kind].message.textContent, 'new project');
    h.reply = async () => { throw new Error('response lost'); }; await h.submit(kind);
    assert.notEqual(h.calls[1].options.headers['X-Idempotency-Key'], originalKey);
    h.state.selectedKbId = 31; h.state.selectedProject = { id: 21 }; h[kind].project.value = '21';
    await h.submit(kind); assert.equal(h.calls[2].options.headers['X-Idempotency-Key'], originalKey);
  });
}

test('changed bytes with identical name, size and timestamp get a different action', async () => {
  for (const crypto of [webcrypto, { randomUUID }]) {
    const h = harness({ crypto }); h.reply = async () => { throw new Error('response lost'); };
    await h.submit('upload'); h.upload.file.files = [material('BBBB')]; await h.submit('upload');
    assert.ok(h.calls[0].options.headers?.['X-Idempotency-Key']);
    assert.notEqual(h.calls[0].options.headers['X-Idempotency-Key'], h.calls[1].options.headers['X-Idempotency-Key']);
  }
});

test('switching workspace while file bytes are read sends no request under the new identity', async () => {
  const h = harness(); const bytes = deferred();
  h.upload.file.files = [{ name: 'same.txt', size: 4, type: 'text/plain', arrayBuffer: () => bytes.promise }];
  const pending = h.submit('upload');
  h.state.session = { tenant: { id: 12, subdomain: 'workspace-b' }, user: { id: 102 } };
  bytes.resolve(new Uint8Array([65, 65, 65, 65]).buffer); await pending;
  assert.equal(h.calls.length, 0);
});

test('workspace and user changes cannot reuse another identity’s pending action', async () => {
  const h = harness(); h.reply = async () => { throw new Error('response lost'); }; await h.submit('url');
  const originalKey = h.calls[0].options.headers['X-Idempotency-Key']; assert.ok(originalKey);
  h.state.session.user.id = 102; await h.submit('url');
  assert.notEqual(h.calls[1].options.headers['X-Idempotency-Key'], originalKey);
  h.state.session.tenant = { id: 12, subdomain: 'workspace-b' }; await h.submit('url');
  assert.notEqual(h.calls[2].options.headers['X-Idempotency-Key'], h.calls[1].options.headers['X-Idempotency-Key']);
});

test('active project remains part of the action even when the attribution field has not rerendered', async () => {
  const h = harness(); h.reply = async () => { throw new Error('response lost'); }; await h.submit('url');
  const originalKey = h.calls[0].options.headers['X-Idempotency-Key']; assert.ok(originalKey);
  h.state.selectedProject = { id: 22 }; await h.submit('url');
  assert.notEqual(h.calls[1].options.headers['X-Idempotency-Key'], originalKey);
});

test('422 after acceptance, 409 conflict and malformed success all retain the original key', async () => {
  for (const failure of [
    Object.assign(new Error('partial stage'), { status: 422, body: { action: 'knowledge_ingestion_result_unknown' } }),
    Object.assign(new Error('different request'), { status: 409, body: {} }),
    null,
  ]) {
    const h = harness(); h.reply = async () => { if (failure) throw failure; return {}; };
    await h.submit('url'); await h.submit('url');
    assert.ok(h.calls[0].options.headers?.['X-Idempotency-Key']);
    assert.equal(h.calls[1].options.headers['X-Idempotency-Key'], h.calls[0].options.headers['X-Idempotency-Key']);
    assert.equal(h.url.resets, 0);
  }
});

test('known validation rejection can be corrected without retaining a rejected action', async () => {
  const h = harness(); h.reply = async () => { throw Object.assign(new Error('missing model'), { status: 422, body: {} }); };
  await h.submit('url'); const first = h.calls[0].options.headers?.['X-Idempotency-Key']; assert.ok(first);
  h.reply = async () => ({ data: { id: 41 } }); await h.submit('url');
  assert.notEqual(h.calls[1].options.headers['X-Idempotency-Key'], first);
});

test('recrawl double click and lost response also reuse one keyed action', async () => {
  const h = harness(); const response = deferred(); h.reply = () => response.promise;
  const one = h.recrawl(), two = h.recrawl(); await until(() => h.calls.length > 0);
  assert.equal(h.calls.length, 1); const key = h.calls[0].options.headers?.['X-Idempotency-Key']; assert.ok(key);
  response.reject(new Error('response lost')); await Promise.all([one, two]);
  h.reply = async () => ({ data: { id: 41 } }); await h.recrawl();
  assert.equal(h.calls[1].options.headers['X-Idempotency-Key'], key);
});
