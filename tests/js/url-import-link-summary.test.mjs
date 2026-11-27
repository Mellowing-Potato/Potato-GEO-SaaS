import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const mgmtSource = fs.readFileSync(new URL('../../public/geo-entry-management.js', import.meta.url), 'utf8');
const detailSource = fs.readFileSync(new URL('../../public/geo-entry-url-import-detail.js', import.meta.url), 'utf8');

function escapeHtml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');
}

const listeners = new Map();
function makeNode(id) {
  return {
    id,
    innerHTML: '',
    textContent: '',
    value: '',
    addEventListener(type, fn) { listeners.set(`${id}:${type}`, fn); },
    focus() {},
    scrollIntoView() {},
    querySelector() { return null; },
  };
}

const nodes = {
  urlImportRows: makeNode('urlImportRows'),
  urlImportDetail: makeNode('urlImportDetail'),
  urlImportDetailMessage: makeNode('urlImportDetailMessage'),
  urlImportUrl: makeNode('urlImportUrl'),
  urlImportFormMessage: makeNode('urlImportFormMessage'),
};

const apiResponses = new Map();
const viewLoaders = new Map();
const state = {
  selectedProject: { id: 1, name: '示例项目' },
  urlImports: [],
  categories: [],
};
const elements = {
  urlImportRows: nodes.urlImportRows,
  urlImportMessage: makeNode('urlImportMessage'),
};

let copiedText = '';
const context = {
  URL,
  console,
  setTimeout,
  clearTimeout,
  encodeURIComponent,
  decodeURIComponent,
  navigator: { clipboard: { writeText: async (text) => { copiedText = text; } } },
  document: { getElementById: (id) => nodes[id] || null },
  window: {},
};
context.window.GEO = {
  state,
  elements,
  api: async (path) => apiResponses.get(path) || { data: [] },
  escapeHtml,
  formatDate: (v) => String(v || ''),
  formatNumber: (v) => String(v || 0),
  setMessage: () => {},
  validationMessage: (e) => String(e?.message || ''),
  promptWorkspaceValue: async () => null,
  confirmWorkspaceAction: async () => false,
  toast: () => {},
  registerViewLoader: (name, fn) => viewLoaders.set(name, fn),
  registerFinanceLoader: () => {},
};
vm.createContext(context);
vm.runInContext(mgmtSource, context);
vm.runInContext(detailSource, context);

const summarize = context.window.GEO.summarizeUrlImport;
assert.equal(typeof summarize, 'function', 'GEO.summarizeUrlImport must be exposed');

// 1. Decodes percent-encoded UTF-8 paths cleanly and strips leading www.
const cn = summarize('https://www.example.com/%E7%A4%BA%E4%BE%8B%E6%94%BB%E7%95%A5?utm_source=demo');
assert.equal(cn.host, 'example.com');
assert.equal(cn.path, '/示例攻略?utm_source=demo');
assert.equal(cn.safeHref, 'https://www.example.com/%E7%A4%BA%E4%BE%8B%E6%94%BB%E7%95%A5?utm_source=demo');

// 2. Malformed percent escapes never throw.
const malformed = summarize('https://example.com/%E6%ZZ/bad');
assert.equal(malformed.host, 'example.com');
assert.equal(malformed.path, '/%E6%ZZ/bad');
assert.ok(malformed.safeHref.startsWith('https://example.com/'));

// 3. Root URL shows only clean hostname without lonely trailing slash.
const rootOnly = summarize('https://docs.example.com/');
assert.equal(rootOnly.host, 'docs.example.com');
assert.equal(rootOnly.path, '');

// 4. Non-HTTP(S) schemes must NEVER produce an external href.
for (const bad of ['javascript:alert(1)', 'data:text/html,<script>1</script>', 'file:///etc/passwd']) {
  const res = summarize(bad);
  assert.equal(res.safeHref, '', `Unsafe scheme must not produce external href: ${bad}`);
}

// 5. Render table rows via real loadUrlImports(): domain + decoded path IS the direct full-URL anchor (no redundant "原页 ↗").
const encodedUrl = 'https://example.com/%E7%A4%BA%E4%BE%8B%E6%94%BB%E7%95%A5';
const unsafeUrl = 'javascript:alert(1)';
apiResponses.set('/api/v1/url-imports', {
  data: [
    { id: 101, url: encodedUrl, status: 'completed', title: '测试标题', article_id: 601, created_at: '2026-09-10' },
    { id: 102, url: unsafeUrl, status: 'failed', error_message: '协议不支持', created_at: '2026-09-10' },
  ],
});
await viewLoaders.get('url-imports')();
const rowsHtml = nodes.urlImportRows.innerHTML;
assert.match(rowsHtml, /<a class="url-import-link" href="https:\/\/example\.com\/%E7%A4%BA%E4%BE%8B%E6%94%BB%E7%95%A5" target="_blank" rel="noopener noreferrer"/, 'Shortened URL summary must link directly to full original URL');
assert.match(rowsHtml, /<span class="url-import-host">example\.com<\/span>/, 'Row must lead with readable source hostname');
assert.match(rowsHtml, /<span class="url-import-path">\/示例攻略<\/span>/, 'Row must decode percent-encoded Chinese path');
assert.doesNotMatch(rowsHtml, /原页 ↗/, 'Row must not render a redundant external link column');
assert.match(rowsHtml, /data-url-import-detail="101"/, 'Row and detail trigger must remain available for inline detail');
assert.match(rowsHtml, /<span class="url-import-link is-static"/, 'Unsafe scheme must render as plain non-clickable text');
assert.doesNotMatch(rowsHtml, /href="javascript:/i, 'Unsafe scheme must never appear as an href in list rows');

const flush = () => new Promise((resolve) => setImmediate(resolve));

// 5b. Clicking the direct <a> link must NOT be intercepted by the row detail handler.
let linkPrevented = false;
listeners.get('urlImportRows:click')({
  preventDefault() { linkPrevented = true; },
  target: { closest: (sel) => (sel === 'a' ? {} : sel === '[data-url-import-detail]' ? { dataset: { urlImportDetail: '101' } } : null) },
});
await flush();
assert.equal(linkPrevented, false, 'Clicking direct source link must open native anchor without opening detail');
assert.equal(nodes.urlImportDetail.innerHTML, '', 'Clicking direct source link must not trigger detail fetch');

// 6. Clicking detail renders full URL + safe external link + copy button, and copy works.
apiResponses.set('/api/v1/url-imports/101', {
  data: { id: 101, url: encodedUrl, status: 'completed', title: '测试标题', article_id: 601, created_at: '2026-09-10' },
});
listeners.get('urlImportRows:click')({
  preventDefault() {},
  target: { closest: (sel) => (sel === '[data-url-import-detail]' ? { dataset: { urlImportDetail: '101' } } : null) },
});
await flush();
assert.match(nodes.urlImportDetail.innerHTML, /class="url-import-full-url"[^>]*>https:\/\/example\.com\/%E7%A4%BA%E4%BE%8B%E6%94%BB%E7%95%A5<\/span>/);
assert.match(nodes.urlImportDetail.innerHTML, /target="_blank" rel="noopener noreferrer">打开原网页 ↗<\/a>/);
assert.match(nodes.urlImportDetail.innerHTML, /data-url-import-copy="https:\/\/example\.com\/%E7%A4%BA%E4%BE%8B%E6%94%BB%E7%95%A5"/);

const copyBtn = { dataset: { urlImportCopy: encodedUrl }, textContent: '复制链接' };
await listeners.get('urlImportDetail:click')({
  target: { closest: (sel) => (sel === '[data-url-import-copy]' ? copyBtn : null) },
});
assert.equal(copiedText, encodedUrl, 'Copy button must copy full original URL');
assert.equal(copyBtn.textContent, '已复制');

// 7. Unsafe URL in detail also omits external anchor.
apiResponses.set('/api/v1/url-imports/102', {
  data: { id: 102, url: unsafeUrl, status: 'failed', error_message: '协议不支持' },
});
listeners.get('urlImportRows:click')({
  preventDefault() {},
  target: { closest: (sel) => (sel === '[data-url-import-detail]' ? { dataset: { urlImportDetail: '102' } } : null) },
});
await flush();
assert.doesNotMatch(nodes.urlImportDetail.innerHTML, /打开原网页/, 'Detail must omit external anchor for non-HTTP(S) URLs');

async function copyFixture(navigator) {
  context.navigator = navigator;
  listeners.get('urlImportRows:click')({
    preventDefault() {},
    target: { closest: (sel) => (sel === '[data-url-import-detail]' ? { dataset: { urlImportDetail: '101' } } : null) },
  });
  await flush();
  assert.match(nodes.urlImportDetail.innerHTML, /class="url-import-full-url"[^>]*>https:\/\/example\.com\/%E7%A4%BA%E4%BE%8B%E6%94%BB%E7%95%A5<\/span>/, 'Manual copy must retain the complete original URL');
  const button = { dataset: { urlImportCopy: encodedUrl }, textContent: '复制链接' };
  return {
    button,
    click: () => listeners.get('urlImportDetail:click')({
      target: { closest: (sel) => (sel === '[data-url-import-copy]' ? button : null) },
    }),
  };
}

for (const [name, navigator] of [
  ['missing clipboard', {}],
  ['missing writeText', { clipboard: {} }],
  ['non-callable writeText', { clipboard: { writeText: true } }],
]) {
  await test(`${name} offers manual copy without claiming success`, async () => {
    const copy = await copyFixture(navigator);
    await copy.click();
    assert.equal(copy.button.textContent, '复制链接', 'An unavailable clipboard must not claim a copy happened');
    assert.equal(nodes.urlImportDetailMessage.textContent, '浏览器未允许自动复制，请直接选中上方网页地址复制。');
  });
}

await test('rejected clipboard write keeps the full URL and offers manual copy', async () => {
  const calls = [];
  const copy = await copyFixture({ clipboard: { async writeText(value) {
    calls.push(value);
    throw new Error('Synthetic clipboard permission denial');
  } } });
  await copy.click();
  assert.deepEqual(calls, [encodedUrl]);
  assert.equal(copy.button.textContent, '复制链接');
  assert.equal(nodes.urlImportDetailMessage.textContent, '浏览器未允许自动复制，请直接选中上方网页地址复制。');
});

await test('delayed clipboard success is acknowledged only after the write resolves', async () => {
  let finishWrite;
  const calls = [];
  const clipboard = { writeText(value) {
    assert.equal(this, clipboard, 'writeText must retain the browser clipboard receiver');
    calls.push(value);
    return new Promise((resolve) => { finishWrite = resolve; });
  } };
  const copy = await copyFixture({ clipboard });
  const pending = copy.click();
  await flush();
  assert.deepEqual(calls, [encodedUrl]);
  assert.equal(copy.button.textContent, '复制链接', 'Pending writes are not completed copies');
  finishWrite();
  await pending;
  assert.equal(copy.button.textContent, '已复制');
  assert.doesNotMatch(nodes.urlImportDetailMessage.textContent, /未允许自动复制/);
});

await test('a failed attempt after success clears the previous success label', async () => {
  const copy = await copyFixture({ clipboard: { async writeText() {} } });
  await copy.click();
  assert.equal(copy.button.textContent, '已复制');
  context.navigator = {};
  await copy.click();
  assert.equal(copy.button.textContent, '复制链接', 'Previous success must not represent an unperformed new copy');
  assert.equal(nodes.urlImportDetailMessage.textContent, '浏览器未允许自动复制，请直接选中上方网页地址复制。');
});

console.log('URL import link summary: hostname/path decoding, Scheme A detail trigger, safe external links, copy action and XSS scheme guard passed.');
