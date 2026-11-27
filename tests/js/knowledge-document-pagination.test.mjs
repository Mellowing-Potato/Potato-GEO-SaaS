import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createContext, runInContext } from 'node:vm';
import { test } from 'node:test';

const source = readFileSync(new URL('../../public/geo-entry-knowledge-base.js', import.meta.url), 'utf8');
const blade = readFileSync(new URL('../../resources/views/workbench/panels/knowledge.blade.php', import.meta.url), 'utf8');
const pause = () => new Promise(resolve => setImmediate(resolve));
const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };

class Node {
  constructor() { this.children = []; this.buttons = []; this.handlers = {}; this.dataset = {}; this.style = {}; this.textContent = ''; }
  set innerHTML(html) {
    this.html = html; this.children = [];
    this.buttons = [...html.matchAll(/<button\b([^>]*)>([\s\S]*?)<\/button>/g)].map(([, attrs, text]) => {
      const button = new Node(); button.textContent = text; button.disabled = /\bdisabled\b/.test(attrs);
      for (const [, key, value] of attrs.matchAll(/data-([\w-]+)="([^"]*)"/g)) button.dataset[key.replace(/-([a-z])/g, (_, c) => c.toUpperCase())] = value;
      return button;
    });
  }
  get innerHTML() { return this.html || ''; }
  appendChild(child) { this.children.push(child); return child; }
  addEventListener(name, fn) { this.handlers[name] = fn; }
  querySelectorAll(selector) {
    const key = selector.slice(6, -1).replace(/-([a-z])/g, (_, c) => c.toUpperCase());
    return [...this.buttons, ...this.children.flatMap(child => child.querySelectorAll(selector))].filter(button => key in button.dataset);
  }
  querySelector() { return null; }
  click() { if (!this.disabled) return this.handlers.click?.({ currentTarget: this }); }
}

const material = id => ({ id, title: `资料 ${id}`, status: 'ready', chunk_count: 2, source_type: 'upload', review_status: 'approved' });
// 与 Laravel LengthAwarePaginator 的真实顶层契约一致；PHP 契约由 KnowledgePaginationContractTest 固定。
function pagination(rows, page = 1) {
  return { data: rows.slice((page - 1) * 20, page * 20), current_page: page, per_page: 20,
    total: rows.length, last_page: Math.max(1, Math.ceil(rows.length / 20)),
    from: rows.length ? (page - 1) * 20 + 1 : null, to: Math.min(page * 20, rows.length) };
}

function harness(count = 21) {
  const state = { selectedKbId: null, selectedProject: { id: 11 }, session: { tenant: { id: 1, subdomain: 'workspace-a' }, user: { id: 7 } },
    knowledgeBases: [{ id: 31, name: '资料库 A' }, { id: 32, name: '资料库 B' }], kbDocuments: [] };
  const elements = Object.fromEntries(['kbDocList', 'kbDetail', 'kbList', 'kbDetailTitle', 'kbDetailMessage', 'knowledgeMessage', 'reloadKbDocsButton'].map(key => [key, new Node()]));
  const pager = blade.includes('id="kbDocPagination"') ? new Node() : null;
  const calls = [], views = [], timers = new Map();
  const h = { state, elements, pager, calls, views, timers, rows: Array.from({ length: count }, (_, n) => material(n + 1)), confirm: async () => true, stats: 0, now: 0 };
  let timer = 0;
  h.reply = async (path, options = {}) => {
    if (options.method === 'DELETE') { h.rows = h.rows.filter(row => row.id !== Number(path.split('/').at(-1))); return {}; }
    if (options.method === 'POST') return {};
    const url = new URL(path, 'https://example.com');
    return pagination(h.rows, Number(url.searchParams.get('page') || 1));
  };
  const GEO = { state, elements, tenantSubdomain: () => state.session.tenant.subdomain,
    escapeHtml: value => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;'),
    formatNumber: String, formatKnowledgeDate: () => '2026-11-27', KB_DOC_STATUS_LABELS: {}, KB_DOC_STATUS_VARIANTS: {},
    refreshKbImportButtons() {}, setMessage(node, text) { if (node) node.textContent = text; }, validationMessage: error => error.message,
    confirmWorkspaceAction: (...args) => h.confirm(...args), loadKnowledgeStats() { h.stats++; }, loadKnowledgeBases: async () => {},
    api: async (path, options = {}) => { calls.push({ path, options }); return h.reply(path, options); },
    submitKbImport: async options => { h.importOptions = options; await options.loadDocuments(state.selectedKbId); options.pollDocuments(); },
  };
  const context = createContext({ console, URL, Map, Set, Date: class extends Date { static now() { return h.now; } },
    window: { GEO, setInterval: fn => { timers.set(++timer, fn); return timer; }, clearInterval: key => timers.delete(key) },
    document: { getElementById: id => id === 'kbDocPagination' ? pager : null, createElement: () => new Node() },
    viewSpy: (kb, id) => views.push({ kb, id }),
  });
  // 暴露原有处理器，不改分页/竞态逻辑；查看按钮只记录转交预览的身份，预览由独立回归覆盖。
  const end = source.lastIndexOf('})();');
  runInContext(source.slice(0, end) + `Object.assign(GEO, { testLoad: loadKbDocuments, testDelete: deleteKbDocument,
    testArchive: archiveKbDocument, testPoll: pollKbDocuments, testRecrawl: recrawlKbDocument });
    viewKbDocumentChunks = viewSpy;\n` + source.slice(end), context);
  h.GEO = GEO;
  h.open = id => GEO.openKnowledgeBase(id || 31);
  h.load = (...args) => GEO.testLoad(...args);
  h.next = () => pager?.querySelectorAll('[data-kb-doc-page]').find(button => button.textContent === '下一页');
  h.previous = () => pager?.querySelectorAll('[data-kb-doc-page]').find(button => button.textContent === '上一页');
  h.action = (kind, id) => elements.kbDocList.querySelectorAll('[data-doc-action]').find(button => button.dataset.docAction === kind && Number(button.dataset.docId) === id);
  h.pageLabel = () => pager?.innerHTML || '';
  return h;
}

test('21 documents expose true count and a usable second page with view and delete actions', async () => {
  const h = harness(); await h.open();
  assert.match(h.pageLabel(), /共 21 篇/);
  assert.equal(h.next()?.disabled, false, 'a real next-page button must expose document 21');
  await h.next().click();
  assert.deepEqual([...h.state.kbDocuments].map(row => row.id), [21]);
  assert.match(h.pageLabel(), /第 2 \/ 2 页/);
  h.action('view', 21).click(); assert.deepEqual(h.views, [{ kb: 31, id: 21 }]);
  h.action('delete', 21).click(); await pause();
  assert.ok(h.calls.some(call => call.options.method === 'DELETE' && call.path.endsWith('/documents/21')));
  assert.deepEqual([...h.state.kbDocuments].map(row => row.id), Array.from({ length: 20 }, (_, n) => n + 1));
  assert.match(h.pageLabel(), /第 1 \/ 1 页 · 共 20 篇/);
  assert.ok(h.calls.some(call => call.path.endsWith('documents?page=2')), 'delete must first refresh the current page');
});

test('refresh, archive and polling preserve page 2 rather than replacing it with page 1', async () => {
  const h = harness(41); await h.open(); await h.load(31, 2);
  assert.equal(h.state.kbDocuments[0].id, 21);
  h.rows[20].status = 'pending';
  await h.elements.reloadKbDocsButton.click();
  await h.GEO.testArchive(21);
  h.GEO.testPoll(); await pause();
  const poll = [...h.timers.values()][0]; assert.ok(poll);
  const before = h.calls.length; await poll();
  assert.equal(h.calls[before].path, '/api/v1/knowledge-bases/31/documents?page=2');
  assert.equal(h.state.kbDocuments[0].id, 21);
  assert.match(h.pageLabel(), /第 2 \/ 3 页 · 共 41 篇/);
});

test('new recrawl submission returns to the first page and watches its newly queued material', async () => {
  const h = harness(41); await h.open(); await h.load(31, 2);
  h.rows.unshift({ ...material(90), status: 'pending' });
  await h.GEO.testRecrawl(21); await pause();
  assert.equal(h.state.kbDocuments[0].id, 90);
  assert.match(h.pageLabel(), /第 1 \/ 3 页/);
  assert.ok(h.timers.size > 0);
});

for (const failed of [false, true]) test(`late page ${failed ? 'failure' : 'success'} cannot replace the newer requested page`, async () => {
  const h = harness(41); await h.open();
  const old = deferred(); h.reply = () => old.promise;
  const pending = h.load(31, 2);
  h.reply = async () => pagination(h.rows, 3); await h.load(31, 3);
  h.elements.kbDetailMessage.textContent = '当前页提示';
  failed ? old.reject(new Error('旧页失败')) : old.resolve(pagination(h.rows, 2));
  await pending;
  assert.deepEqual([...h.state.kbDocuments].map(row => row.id), [41]);
  assert.equal(h.elements.kbDetailMessage.textContent, '当前页提示');
  assert.match(h.pageLabel(), /第 3 \/ 3 页/);
});

for (const boundary of ['library', 'project', 'workspace', 'user', 'reopen']) test(`late response cannot cross ${boundary} context`, async () => {
  const h = harness(21); await h.open();
  const old = deferred(); h.reply = () => old.promise;
  const pending = h.load(31, 2);
  h.reply = async () => pagination([material(99)], 1);
  if (boundary === 'project') h.state.selectedProject = { id: 12 };
  if (boundary === 'workspace') h.state.session.tenant = { id: 2, subdomain: 'workspace-b' };
  if (boundary === 'user') h.state.session.user = { id: 8 };
  await h.open(boundary === 'library' ? 32 : 31);
  old.resolve(pagination(h.rows, 2)); await pending;
  assert.deepEqual([...h.state.kbDocuments].map(row => row.id), [99]);
  assert.match(h.pageLabel(), /第 1 \/ 1 页 · 共 1 篇/);
});

test('closing details clears the previous list and prevents late render', async () => {
  const h = harness(); await h.open(); const old = deferred(); h.reply = () => old.promise;
  const pending = h.load(31, 2); h.GEO.closeKnowledgeBaseDetail(); old.resolve(pagination(h.rows, 2)); await pending;
  assert.equal(h.state.selectedKbId, null); assert.equal(h.state.kbDocuments.length, 0);
  assert.equal(h.elements.kbDetail.hidden, true); assert.equal(h.timers.size, 0);
});

test('confirmation and mutation completion cannot refresh or delete a newly selected library', async () => {
  const h = harness(); await h.open(); const confirmation = deferred(); h.confirm = () => confirmation.promise;
  const before = h.GEO.testDelete(1); await h.open(32); confirmation.resolve(true); await before;
  assert.equal(h.calls.filter(call => call.options.method === 'DELETE').length, 0);
  h.confirm = async () => true; const response = deferred();
  h.reply = (path, options) => options.method === 'DELETE' ? response.promise : Promise.resolve(pagination([material(99)]));
  const sent = h.GEO.testDelete(1); await pause(); await h.open(31);
  h.elements.kbDetailMessage.textContent = '新资料库提示'; const count = h.calls.length;
  response.resolve({}); await sent;
  assert.equal(h.calls.length, count); assert.equal(h.elements.kbDetailMessage.textContent, '新资料库提示');
});

test('empty library has a true zero count and no active page navigation', async () => {
  const h = harness(0); await h.open();
  assert.match(h.pageLabel(), /共 0 篇/); assert.equal(h.next()?.disabled, true); assert.equal(h.previous()?.disabled, true);
  assert.match(h.elements.kbDocList.innerHTML, /还没有文档/);
});

test('a failed page reload can recover without erasing an unrelated operation message', async () => {
  const h = harness(21); await h.open();
  h.reply = async () => { throw new Error('当前列表加载失败'); };
  await h.load(31, 2); assert.match(h.elements.kbDetailMessage.textContent, /当前列表加载失败/);
  h.reply = async () => pagination(h.rows, 2); h.elements.reloadKbDocsButton.click(); await pause();
  assert.equal(h.elements.kbDetailMessage.textContent, ''); assert.equal(h.state.kbDocuments[0].id, 21);
  h.reply = async () => { throw new Error('下一次加载失败'); }; await h.load(31, 2);
  h.elements.kbDetailMessage.textContent = '请求结果未知，请使用原请求确认';
  h.reply = async () => pagination(h.rows, 2); await h.load(31, 2);
  assert.equal(h.elements.kbDetailMessage.textContent, '请求结果未知，请使用原请求确认');
});

test('slow polling neither overlaps requests nor reports success for a page the user left', async () => {
  const h = harness(41); h.rows[20].status = 'pending'; await h.open(); await h.load(31, 2);
  const response = deferred(); h.reply = () => response.promise; const message = new Node();
  h.GEO.testPoll(message); const tick = [...h.timers.values()][0]; const count = h.calls.length;
  const overlapping = tick(); await pause(); const during = h.calls.length;
  h.reply = async () => pagination(h.rows, 3); await h.load(31, 3);
  response.resolve(pagination(h.rows, 2)); await overlapping; await pause();
  assert.equal(during, count, 'a slow poll must not fan out more requests');
  assert.deepEqual([...h.state.kbDocuments].map(row => row.id), [41]);
  assert.equal(h.stats, 0); assert.equal(message.textContent, ''); assert.equal(h.timers.size, 0);
});

test('continuous polling failures still stop at the fifteen-minute limit', async () => {
  const h = harness(1); await h.open(); h.state.kbDocuments[0].status = 'pending';
  h.reply = async () => { throw new Error('列表暂不可用'); };
  h.GEO.testPoll(); await pause(); const tick = [...h.timers.values()][0]; assert.ok(tick);
  h.now = 15 * 60 * 1000 + 1; const count = h.calls.length; await tick();
  assert.equal(h.calls.length, count); assert.equal(h.timers.size, 0);
  assert.match(h.elements.kbDetailMessage.textContent, /解析时间较长/);
});

for (const boundary of ['project', 'workspace', 'user']) test(`identity change alone discards a late ${boundary} list without relying on reopening`, async () => {
  const h = harness(); await h.open(); const old = deferred(); h.reply = () => old.promise;
  const pending = h.load(31, 2);
  if (boundary === 'project') h.state.selectedProject = { id: 12 };
  if (boundary === 'workspace') h.state.session.tenant = { id: 2, subdomain: 'workspace-b' };
  if (boundary === 'user') h.state.session.user = { id: 8 };
  h.state.kbDocuments = [material(99)]; h.elements.kbDetailMessage.textContent = '新身份提示';
  old.resolve(pagination(h.rows, 2)); await pending;
  assert.deepEqual([...h.state.kbDocuments].map(row => row.id), [99]);
  assert.equal(h.elements.kbDetailMessage.textContent, '新身份提示');
});
