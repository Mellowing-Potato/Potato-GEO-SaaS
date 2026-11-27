import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { createContext, runInContext } from 'node:vm';
import { test } from 'node:test';

const publicFile = name => new URL(`../../public/${name}`, import.meta.url);
const modulePaths = ['geo-entry-knowledge-preview.js', 'geo-entry-knowledge-base.js'].filter(name => existsSync(publicFile(name)));
const pause = () => new Promise(resolve => setImmediate(resolve));
const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
async function until(check) { for (let i = 0; i < 100 && !check(); i++) await pause(); assert.ok(check(), 'expected UI state did not arrive'); }

function harness() {
  class Element {
    constructor(tag) { this.tagName = tag.toUpperCase(); this.children = []; this.style = {}; this.dataset = {}; this.handlers = {}; this.hidden = false; this.disabled = false; this._text = ''; this._html = ''; }
    appendChild(node) { this.children.push(node); node.parentNode = this; return node; }
    append(...nodes) { nodes.forEach(node => this.appendChild(node)); }
    set innerHTML(value) { this._html = value; this._text = ''; this.children = []; }
    get innerHTML() { return this._html; }
    set textContent(value) { this._text = String(value); this._html = ''; this.children = []; }
    get textContent() { return this._text + this.children.map(child => child.textContent).join(''); }
    addEventListener(name, handler) { (this.handlers[name] ||= []).push(handler); }
    removeEventListener(name, handler) { this.handlers[name] = (this.handlers[name] || []).filter(fn => fn !== handler); }
    querySelectorAll(selector) { return descendants(this).filter(node => selector.startsWith('#') ? node.id === selector.slice(1)
      : selector.startsWith('.') ? (node.className || '').split(' ').includes(selector.slice(1)) : node.tagName.toLowerCase() === selector); }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
    setAttribute(name, value) { this[name] = value; }
    focus() {}
  }
  const descendants = root => root.children.flatMap(child => [child, ...descendants(child)]);
  const body = new Element('body'), head = new Element('head');
  const document = { body, head, createElement: tag => new Element(tag),
    getElementById: id => [...descendants(body), ...descendants(head)].find(node => node.id === id) || null,
    querySelectorAll: selector => [...body.querySelectorAll(selector), ...head.querySelectorAll(selector)],
    addEventListener() {}, removeEventListener() {},
  };
  const state = { selectedKbId: 31, selectedProject: { id: 21 }, session: { tenant: { id: 11, subdomain: 'fixture' }, user: { id: 1 } }, knowledgeBases: [], kbDocuments: [] };
  const elements = { kbDocList: new Element('div'), kbDetailMessage: new Element('p') };
  const h = { state, document, calls: [], reply: () => { throw new Error('unexpected fixture request'); } };
  const GEO = { state, elements, api: async path => { h.calls.push(path); return h.reply(path); },
    escapeHtml: String, formatNumber: String, formatKnowledgeDate: String, validationMessage: error => error.message,
    setMessage: (node, text) => { if (node) node.textContent = text; },
    tenantSubdomain: () => state.session?.tenant?.subdomain, recentProjectStorageKey: () => JSON.stringify(state.session), authHeaders: headers => headers,
    refreshKbImportButtons() {}, loadKnowledgeStats() {}, loadKnowledgeBases() {}, KB_DOC_STATUS_LABELS: {}, KB_DOC_STATUS_VARIANTS: {},
  };
  const context = createContext({ window: { GEO, addEventListener() {}, setInterval: () => 1, clearInterval() {}, open() {} },
    document, console, URL, setTimeout, clearTimeout, fetch: async () => ({ ok: false }), FormData,
  });
  for (const path of modulePaths) {
    let source = readFileSync(publicFile(path), 'utf8');
    if (source.includes('  async function loadKbDocuments(')) {
      const end = source.lastIndexOf('})();');
      source = source.slice(0, end) + 'window.GEO.testLoadDocuments = loadKbDocuments;\n' + source.slice(end);
    }
    runInContext(source, context, { filename: path });
  }
  h.open = (id, textOnly = false) => GEO.viewKbDocumentChunks(state.selectedKbId, id, textOnly);
  h.loadDocuments = page => GEO.testLoadDocuments(state.selectedKbId, page);
  h.find = id => document.getElementById(id);
  h.nodes = selector => document.querySelectorAll(selector);
  h.button = text => h.nodes('button').find(node => node.textContent === text);
  h.click = async node => {
    assert.ok(node, 'the requested paging/recovery control must exist');
    assert.equal(node.disabled, false, 'the requested control must be enabled');
    await Promise.all((node.handlers.click || []).map(fn => fn({ target: node, currentTarget: node })));
    await pause();
  };
  h.close = () => h.click(h.button('关闭'));
  h.text = () => body.textContent;
  return h;
}

const chunks = count => Array.from({ length: count }, (_, i) => ({ id: i + 1, chunk_index: i, content: `chunk-${i + 1}`, page: i < 100 ? 1 : 2 }));
const paginated = (rows, page, perPage) => ({ current_page: page, last_page: Math.max(1, Math.ceil(rows.length / perPage)), total: rows.length, per_page: perPage, data: rows.slice((page - 1) * perPage, page * perPage) });
function fixture(h, rows, vision = false) {
  h.state.kbDocuments = [{ id: 41, metadata: { extraction: { method: vision ? 'vision' : 'text_layer', pages: 2 } } }];
  h.reply = path => {
    const url = new URL(path, 'https://example.com');
    return paginated(rows, Number(url.searchParams.get('page') || 1), Number(url.searchParams.get('per_page') || 20));
  };
}

async function consumeHttpContract(payload) {
  const h = harness();
  h.state.selectedKbId = payload.base_id;
  h.reply = path => {
    const url = new URL(path, 'https://example.com');
    const page = Number(url.searchParams.get('page') || 1);
    if (url.pathname.endsWith('/chunks')) {
      const id = Number(url.pathname.split('/').at(-2));
      const pages = id === payload.text_document.id ? payload.text_pages : payload.vision_pages;
      assert.ok(pages[page - 1], 'frontend requested an unexpected chunk page');
      return pages[page - 1];
    }
    assert.ok(payload.document_pages[page - 1], 'frontend requested an unexpected document page');
    return payload.document_pages[page - 1];
  };
  await h.loadDocuments(1); await h.loadDocuments(2);
  assert.equal(h.state.kbDocuments.length, payload.document_pages[1].data.length);
  assert.equal(h.state.kbDocuments[0].id, payload.document_pages[1].data[0].id, '21st HTTP document must be accessible');
  h.state.kbDocuments = [payload.text_document, payload.vision_document];
  await h.open(payload.text_document.id);
  assert.match(h.text(), /共 21 条切片/);
  await h.click(h.find('kbChunkNext'));
  await until(() => h.text().includes(payload.text_pages[1].data[0].content));
  await h.open(payload.vision_document.id);
  await h.click(h.button('下一页 →'));
  await until(() => h.text().includes(payload.vision_pages[1].data[0].content));
  assert.equal(h.calls.filter(path => path.includes(`/documents/${payload.vision_document.id}/chunks`)).length, 2);
}

if (process.argv.includes('--http-contract')) {
  test('real PHP TestResponse paginators drive document, text and image preview handlers', async () => {
    await consumeHttpContract(JSON.parse(readFileSync(0, 'utf8')));
  });
} else {
  test('21 text chunks use top-level totals and user paging rather than loading all content', async () => {
    const h = harness(); fixture(h, chunks(21)); await h.open(41);
    assert.match(h.text(), /共 21 条切片/); assert.equal(h.nodes('pre').length, 20); assert.equal(h.calls.length, 1);
    await h.click(h.find('kbChunkNext')); await until(() => h.text().includes('chunk-21'));
    assert.equal(h.nodes('pre').length, 1); assert.equal(h.find('kbChunkNext').disabled, true);
    await h.click(h.find('kbChunkPrev')); await until(() => h.nodes('pre').length === 20);
    assert.match(h.calls.at(-1), /page=1$/);
  });
  test('101 image chunks load the second paginator page and show the final physical page', async () => {
    const h = harness(); fixture(h, chunks(101), true); await h.open(41);
    assert.equal(h.calls.length, 2, 'the top-level last_page must cause a second request');
    await h.click(h.button('下一页 →')); await until(() => h.text().includes('chunk-101'));
    assert.doesNotMatch(h.text(), /本页没有对应切片/);
  });
  test('a failed text page keeps the prior content and retries only after a user action', async () => {
    const h = harness(); fixture(h, chunks(21)); const respond = h.reply; let fail = true;
    h.reply = path => path.endsWith('page=2') && fail ? Promise.reject(new Error('synthetic page failure')) : respond(path);
    await h.open(41); await h.click(h.find('kbChunkNext')); await until(() => h.text().includes('synthetic page failure'));
    assert.equal(h.nodes('pre').length, 20); assert.equal(h.calls.length, 2); fail = false;
    await h.click(h.find('kbChunkRetry')); await until(() => h.text().includes('chunk-21'));
    assert.equal(h.calls.length, 3);
  });
  for (const changed of ['document', 'project', 'owner', 'close']) {
    test(`late text response is discarded after ${changed} changes`, async () => {
      const h = harness(); fixture(h, chunks(21)); const pending = deferred(); h.reply = () => pending.promise;
      const old = h.open(41);
      if (changed === 'document') { fixture(h, chunks(1)); await h.open(41); }
      if (changed === 'project') h.state.selectedProject.id = 22;
      if (changed === 'owner') h.state.session.user.id = 2;
      if (changed === 'close') await h.close();
      pending.resolve(paginated([{ id: 999, chunk_index: 0, content: 'obsolete private text' }], 1, 20)); await old;
      assert.doesNotMatch(h.text(), /obsolete private text/);
    });
  }
  test('a later image chunk failure never presents a partial collection as complete', async () => {
    const h = harness(); fixture(h, chunks(101), true); const respond = h.reply;
    h.reply = path => path.endsWith('page=2') ? Promise.reject(new Error('synthetic second-page failure')) : respond(path);
    await h.open(41);
    assert.match(h.text(), /synthetic second-page failure/); assert.ok(h.button('重试加载')); assert.ok(h.button('分页查看文字'));
    assert.doesNotMatch(h.text(), /本页没有对应切片/); assert.equal(h.nodes('pre').length, 0);
  });
  test('image pagination can be retried explicitly after a second-page failure', async () => {
    const h = harness(); fixture(h, chunks(101), true); const respond = h.reply; let fail = true;
    h.reply = path => path.endsWith('page=2') && fail ? Promise.reject(new Error('retryable chunk failure')) : respond(path);
    await h.open(41); assert.equal(h.calls.length, 2); fail = false;
    await h.click(h.button('重试加载')); await until(() => h.calls.length === 4 && h.text().includes('chunk-1'));
    await h.click(h.button('下一页 →')); await until(() => h.text().includes('chunk-101'));
  });
  test('closing during image pagination discards the late page and does not render a partial preview', async () => {
    const h = harness(); fixture(h, chunks(101), true); const respond = h.reply; const pending = deferred();
    h.reply = path => path.endsWith('page=2') ? pending.promise : respond(path);
    const loading = h.open(41); await until(() => h.calls.length === 2); await h.close();
    pending.resolve(paginated(chunks(101), 2, 100)); await loading;
    assert.equal(h.find('kbChunkOverlay').style.display, 'none'); assert.equal(h.nodes('pre').length, 0);
  });
  test('more than 20 image paginator pages explicitly fall back to bounded text paging', async () => {
    const h = harness(); fixture(h, chunks(2001), true); await h.open(41);
    assert.equal(h.calls.length, 1); assert.match(h.text(), /2001.*超过图文对照/);
    assert.doesNotMatch(h.text(), /本页没有对应切片/);
    await h.click(h.button('分页查看文字')); await until(() => h.nodes('pre').length === 20);
    assert.match(h.text(), /共 2001 条切片/); assert.equal(h.calls.length, 2);
  });
  test('text chunk HTML remains text in its leaf node', async () => {
    const h = harness(); const rows = chunks(1); rows[0].content = '<img src=x onerror=alert(1)>';
    fixture(h, rows); await h.open(41);
    assert.equal(h.nodes('pre')[0].textContent, rows[0].content); assert.equal(h.nodes('img').length, 0);
  });
}
