import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { createContext, runInContext } from 'node:vm';
import { test } from 'node:test';

const root = new URL('../../', import.meta.url);
const read = file => readFileSync(new URL(file, root), 'utf8');
const entry = read('public/geo-entry.js');
const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
const pause = () => new Promise(resolve => setImmediate(resolve));
async function until(check) { for (let n = 0; n < 80 && !check(); n++) await pause(); assert.ok(check(), 'expected request/render did not occur'); }

class Element {
  constructor(tag) { this.tagName = tag.toUpperCase(); this.children = []; this.style = {}; this.dataset = {}; this.handlers = {}; this._text = ''; this.hidden = false; this.disabled = false; }
  appendChild(child) { child.parentNode = this; this.children.push(child); return child; }
  append(...children) { children.forEach(c => this.appendChild(c)); }
  set innerHTML(value) { this.children.forEach(c => c.parentNode = null); this.children = []; this._text = value || ''; }
  get innerHTML() { return this._text; }
  set textContent(value) { this.innerHTML = ''; this._text = String(value); }
  get textContent() { return this._text + this.children.map(c => c.textContent).join(''); }
  addEventListener(event, handler) { (this.handlers[event] ||= []).push(handler); }
  click(target = this) { if (this.disabled) return; return Promise.all((this.handlers.click || []).map(fn => fn({ target, currentTarget: this, preventDefault() {} }))); }
  setAttribute(name, value) { this[name] = value; }
  querySelectorAll(selector) { return walk(this).filter(e => selector.startsWith('.') ? (e.className || '').split(' ').includes(selector.slice(1)) : e.tagName === selector.toUpperCase()); }
  querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
}
function walk(node) { return node.children.flatMap(child => [child, ...walk(child)]); }
function harness({ pages = 2 } = {}) {
  const body = new Element('body'), head = new Element('head'), documentEvents = {};
  const extra = new Map(['accountLogoutButton', 'workspaceState'].map(id => [id, new Element('button')]));
  const document = { body, head, createElement: tag => new Element(tag), createTextNode: text => Object.assign(new Element('#text'), { textContent: text }),
    getElementById: id => extra.get(id) || [...walk(body), ...walk(head)].find(e => e.id === id) || null,
    addEventListener(name, fn) { documentEvents[name] = fn; }, querySelectorAll: selector => body.querySelectorAll(selector),
  };
  const extraction = { method: 'vision', pages, unverified_terms: [] };
  const state = { selectedProject: { id: 11 }, selectedKbId: 21, session: { tenant: { id: 1, subdomain: 'fixture' }, user: { id: 2 } },
    kbDocuments: [31, 32].map(id => ({ id, filename: `fixture-${id}.pdf`, metadata: { extraction } })) };
  const events = {}, calls = [], revoked = [], created = [], opened = [], apiCalls = [];
  const h = { document, state, calls, apiCalls, revoked, created, opened, events, reply: null };
  const api = async (path, options = {}) => {
    apiCalls.push({ path, options });
    if (h.reply) { const res = h.reply(path, options); if (res !== undefined) return res; }
    if (path === '/api/v1/auth/logout') return {};
    const chunks = Array.from({ length: pages }, (_, i) => ({ id: i + 1, chunk_index: i, page: i + 1, content: `PAGE_${i + 1}_TEXT` }));
    return { data: chunks, current_page: 1, last_page: 1, per_page: 100, total: chunks.length };
  };
  const GEO = { state, elements: {}, api, validationMessage: error => error.message, escapeHtml: String, formatNumber: String, formatKnowledgeDate: String,
    authHeaders: headers => ({ ...headers, Authorization: 'Bearer fixture-preview' }), recentProjectStorageKey: () => `${state.session?.tenant?.id}:${state.session?.user?.id}`,
    tenantSubdomain: () => state.session?.tenant?.subdomain, refreshKbImportButtons() {}, setMessage() {}, loadKnowledgeStats() {}, loadKnowledgeBases: async () => {},
    KB_DOC_STATUS_LABELS: {}, KB_DOC_STATUS_VARIANTS: {},
  };
  class BrowserURL extends URL {
    static createObjectURL(blob) { const url = `blob:${blob.tag}`; created.push(url); return url; }
    static revokeObjectURL(url) { revoked.push(url); }
  }
  const context = createContext({ console, document, URL: BrowserURL, api, state, GEO,
    setTimeout, clearTimeout, AbortController,
    fetch: (path, options) => { const pending = deferred(); calls.push({ path, options, pending }); return pending.promise; },
    sessionStorage: { removeItem() {} }, config: { accountLoginUrl: '/login' }, showWorkspaceNotice() {},
    window: { GEO, location: { origin: 'http://example.test', hash: '#knowledge', replace: value => h.redirect = value }, open: (...args) => opened.push(args),
      addEventListener: (name, fn) => { (events[name] ||= []).push(fn); }, setInterval: () => 1, clearInterval() {} },
  });
  // Load real modules in their real template order. This supports the preview extraction
  // without duplicating the production renderPage or lifecycle implementation.
  const page = read('resources/views/welcome.blade.php');
  if (existsSync(new URL('public/geo-entry-knowledge-preview.js', root))) {
    const previewScript = page.indexOf('<script src="/geo-entry-knowledge-preview.js?v=');
    assert.ok(previewScript >= 0 && previewScript < page.indexOf('<script src="/geo-entry-knowledge-base.js?v='),
      'the real page must load the preview module before its caller');
    assert.match(page, /PublicAssetVersion::for\('geo-entry-knowledge-preview\.js'\).*defer><\/script>/);
  }
  const modules = ['public/geo-entry-knowledge-base.js', 'public/geo-entry-knowledge-preview.js', 'public/geo-knowledge-preview.js']
    .filter(file => existsSync(new URL(file, root))).sort((a, b) => page.indexOf('/' + a.split('/').at(-1)) - page.indexOf('/' + b.split('/').at(-1)));
  for (const file of modules) runInContext(read(file), context);
  const logoutStart = entry.indexOf('  document.getElementById("accountLogoutButton")');
  runInContext(entry.slice(logoutStart, entry.indexOf('  function persistSession', logoutStart)), context);
  h.open = (id = 31) => GEO.viewKbDocumentChunks(21, id);
  h.overlay = () => document.getElementById('kbChunkOverlay');
  h.find = className => body.querySelector('.' + className);
  h.image = () => h.find('kb-split-left')?.querySelector('img');
  h.button = text => walk(body).find(e => e.tagName === 'BUTTON' && e.textContent === text);
  h.next = () => h.button('下一页 →').click();
  h.previous = () => h.button('← 上一页').click();
  h.respond = (index, tag, { status = 200, blob } = {}) => calls[index].pending.resolve({ ok: status >= 200 && status < 300, status,
    blob: () => blob || Promise.resolve({ size: 1, tag }) });
  h.close = () => h.button('关闭').click();
  h.event = name => (events[name] || []).forEach(fn => fn());
  h.logout = () => extra.get('accountLogoutButton').click();
  return h;
}

test('page 2 then page 1 responses keep page 2 image, alt and text together; stale blob is revoked', async () => {
  const h = harness(); const opening = h.open(); await until(() => h.calls.length === 1);
  await h.next(); await until(() => h.calls.length === 2);
  assert.equal(h.button('← 上一页').disabled, false, 'navigation remains available during image loading');
  h.respond(1, 'page-2'); await until(() => h.image()?.src === 'blob:page-2');
  h.respond(0, 'page-1'); await opening;
  assert.equal(h.image().src, 'blob:page-2'); assert.equal(h.image().alt, '第 2 页原始页面');
  assert.match(h.find('kb-split-right').textContent, /PAGE_2_TEXT/); assert.doesNotMatch(h.find('kb-split-right').textContent, /PAGE_1_TEXT/);
  assert.deepEqual(h.revoked, ['blob:page-1']); await h.close(); assert.deepEqual(h.revoked, ['blob:page-1', 'blob:page-2']);
});

test('outdated image errors cannot replace a newer successful page', async () => {
  const h = harness(); const opening = h.open(); await until(() => h.calls.length === 1); await h.next();
  h.respond(1, 'page-2'); await until(() => h.image()?.src === 'blob:page-2'); h.calls[0].pending.reject(new Error('old page failure')); await opening;
  assert.equal(h.image()?.src, 'blob:page-2'); assert.doesNotMatch(h.find('kb-split-left').textContent, /加载失败/);
});

test('closing while image fetch is pending revokes its eventual blob and never repopulates detached DOM', async () => {
  const h = harness(); const opening = h.open(); await until(() => h.calls.length === 1);
  const left = h.find('kb-split-left'), right = h.find('kb-split-right'); await h.close(); h.respond(0, 'late-close'); await opening;
  assert.equal(h.overlay().style.display, 'none'); assert.equal(left.children.length, 0); assert.equal(right.children.length, 0);
  assert.deepEqual(h.revoked, ['blob:late-close']);
});

test('backdrop close releases the displayed blob', async () => {
  const h = harness(); const opening = h.open(); await until(() => h.calls.length === 1); h.respond(0, 'shown'); await opening;
  await h.overlay().click(); assert.equal(h.overlay().style.display, 'none'); assert.deepEqual(h.revoked, ['blob:shown']);
});

for (const id of [31, 32]) test(`reopening document ${id} replaces the preview epoch even when ids match`, async () => {
  const h = harness(); const old = h.open(); await until(() => h.calls.length === 1); const oldLeft = h.find('kb-split-left');
  const latest = h.open(id); await until(() => h.calls.length === 2); h.respond(1, 'latest-document'); await latest;
  h.respond(0, 'old-document'); await old;
  assert.equal(h.image().src, 'blob:latest-document'); assert.equal(oldLeft.children.length, 0); assert.deepEqual(h.revoked, ['blob:old-document']);
});

for (const change of ['tenant', 'user', 'project', 'kb']) test(`changing ${change} prevents a pending image from reattaching`, async () => {
  const h = harness(); const opening = h.open(); await until(() => h.calls.length === 1);
  if (change === 'project') h.state.selectedProject = { id: 99 };
  else if (change === 'kb') h.state.selectedKbId = 99;
  else h.state.session[change] = { ...h.state.session[change], id: 99 };
  h.respond(0, 'foreign-context'); await opening;
  assert.equal(h.overlay().style.display, 'none'); assert.equal(h.image(), undefined);
  assert.deepEqual(h.revoked, h.created, 'every URL created after an invalidated scope is released');
});

test('identity change during blob decoding creates no object URL', async () => {
  const h = harness(); const opening = h.open(); await until(() => h.calls.length === 1); const blob = deferred();
  h.respond(0, '', { blob: blob.promise }); await pause(); h.state.session.user.id = 99; blob.resolve({ size: 1, tag: 'late-blob' }); await opening;
  assert.equal(h.created.length, 0); assert.equal(h.overlay().style.display, 'none');
});

test('route navigation releases current image and invalidates pending images', async () => {
  const h = harness(); const opening = h.open(); await until(() => h.calls.length === 1); h.respond(0, 'initial'); await opening;
  await h.next(); h.event('hashchange'); h.respond(1, 'after-navigation'); await pause();
  assert.equal(h.overlay().style.display, 'none'); assert.deepEqual(h.revoked, ['blob:initial', 'blob:after-navigation']);
});

test('closing during chunk retrieval does not start an image request', async () => {
  const h = harness(), chunks = deferred(); h.reply = () => chunks.promise; const opening = h.open(); await pause(); await h.close();
  chunks.resolve({ data: [{ id: 1, chunk_index: 0, page: 1, content: 'late chunk' }], current_page: 1, last_page: 1, total: 1 }); await pause();
  assert.equal(h.calls.length, 0, 'closed preview must not start another image request');
  await opening; assert.equal(h.overlay().style.display, 'none');
});

test('500 is an error and a single-page preview can retry safely', async () => {
  const h = harness({ pages: 1 }); const opening = h.open(); await until(() => h.calls.length === 1); h.respond(0, '', { status: 500 }); await opening;
  assert.match(h.find('kb-split-left').textContent, /加载失败/); assert.doesNotMatch(h.find('kb-split-left').textContent, /没有生成图像/);
  assert.ok(h.button('重试本页')); await h.button('重试本页').click(); await until(() => h.calls.length === 2); h.respond(1, 'retried');
  await until(() => h.image()?.src === 'blob:retried'); assert.equal(h.image().alt, '第 1 页原始页面');
  assert.equal(h.calls[1].options.headers['X-Project-Id'], '11'); assert.equal(h.calls[1].options.headers.Authorization, 'Bearer fixture-preview');
});

test('404 remains a missing image and retains the matching text', async () => {
  const h = harness({ pages: 1 }); const opening = h.open(); await until(() => h.calls.length === 1); h.respond(0, '', { status: 404 }); await opening;
  assert.match(h.find('kb-split-left').textContent, /第 1 页没有生成图像/); assert.match(h.find('kb-split-right').textContent, /PAGE_1_TEXT/);
});

test('actual logout success closes preview; logout failure keeps it available', async () => {
  const h = harness(); const opening = h.open(); await until(() => h.calls.length === 1); h.respond(0, 'private-image'); await opening;
  h.reply = path => path === '/api/v1/auth/logout' ? Promise.reject(new Error('synthetic logout failure')) : undefined;
  await h.logout(); assert.equal(h.overlay().style.display, 'flex'); assert.deepEqual(h.revoked, []);
  h.reply = null; await h.logout(); assert.equal(h.overlay().style.display, 'none'); assert.deepEqual(h.revoked, ['blob:private-image']); assert.match(h.redirect, /^\/login/);
});

test('detached prior-page image cannot open the next page URL and every displayed URL is released', async () => {
  const h = harness(); const opening = h.open(); await until(() => h.calls.length === 1); h.respond(0, 'page-1'); await opening;
  const prior = h.image(); await h.next(); assert.deepEqual(h.revoked, ['blob:page-1']);
  h.respond(1, 'page-2'); await until(() => h.image()?.src === 'blob:page-2');
  await prior.click(); assert.deepEqual(h.opened, []);
  await h.image().click(); assert.deepEqual(h.opened, [['blob:page-2', '_blank']]);
  const reopened = h.open(); await until(() => h.calls.length === 3); assert.deepEqual(h.revoked, ['blob:page-1', 'blob:page-2']);
  h.respond(2, 'reopened-page-1'); await reopened; await h.close();
  assert.deepEqual(h.revoked, ['blob:page-1', 'blob:page-2', 'blob:reopened-page-1']);
});
