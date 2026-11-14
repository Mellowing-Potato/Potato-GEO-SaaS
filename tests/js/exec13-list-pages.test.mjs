import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/geo-entry-content-library.js', import.meta.url), 'utf8');
function extract(name) {
  const start = source.search(new RegExp(`^  (?:async )?function ${name}\\(`, 'm'));
  assert.ok(start >= 0, `Load actual ${name}`);
  const tail = source.slice(start);
  for (const closing of tail.matchAll(/^  \}/gm)) {
    const candidate = tail.slice(0, closing.index + closing[0].length);
    try {
      new vm.Script(candidate);
      return candidate;
    } catch (error) {
      if (!(error instanceof SyntaxError)) throw error;
    }
  }
  throw new Error(`Unclosed function ${name}`);
}
function compile(names, dependencies = {}, setup = '') {
  return new Function(...Object.keys(dependencies), `${setup}\n${names.map(extract).join('\n')}\nreturn {${names.join(',')}};`)(...Object.values(dependencies));
}
const escapeHtml = value => String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');
function buttons(html) {
  return [...html.matchAll(/<button\b([^>]*)>([\s\S]*?)<\/button>/g)].map((match) => ({
    attributes: Object.fromEntries([...match[1].matchAll(/([\w-]+)(?:="([^"]*)")?/g)].map((part) => [part[1], part[2] ?? ''])),
    text: match[2].replace(/<[^>]*>/g, '').trim(),
  }));
}
const hasClass = (button, cls) => (button.attributes.class || '').split(/\s+/).includes(cls);
const primaryCount = html => buttons(html).filter(button => hasClass(button, 'md-btn--primary')).length;

// Eight A4 rows call the real renderer. Check the two action slots, copy,
// data-action, unchanged article IDs and the hierarchy of both buttons.
const rows = [
  [{ status: 'draft' }, ['编辑文章', '提交审核'], ['edit', 'submit']],
  [{ status: 'editing', reviewed_at: null }, ['编辑文章', '提交审核'], ['edit', 'submit']],
  [{ status: 'editing', reviewed_at: '2026-10-25T09:00:00Z' }, ['看退回意见', '编辑文章'], ['view-note', 'edit']],
  [{ status: 'pending' }, ['通过', '退回'], ['approve', 'reject']],
  [{ status: 'approved' }, ['选渠道发布', '编辑文章'], ['publish', 'edit']],
  [{ status: 'approved', distribution_blocked_reason: '出站闸门已拦下' }, ['看原因', '编辑文章'], ['view', 'edit']],
  [{ status: 'published' }, ['查看', '导出微信'], ['view', 'export']],
  [{ status: 'recycled' }, ['转为草稿'], ['restore']],
];
const { clRowActions } = compile(['clRowActions']);
for (const [index, [fixture, copy, actions]] of rows.entries()) {
  const html = clRowActions({ id: 31 + index, ...fixture });
  const actual = buttons(html);
  assert.equal(actual.length, copy.length, `A4 row ${index + 1}: action count`);
  assert.deepEqual(actual.map(button => button.text), copy);
  assert.deepEqual(actual.map(button => button.attributes['data-action']), actions);
  assert.ok(actual.every(button => button.attributes['data-id'] === String(31 + index)));
  assert.equal(primaryCount(html), 0, `A4 row ${index + 1}: no table primary`);
  assert.ok(hasClass(actual[0], 'md-btn--secondary'));
  assert.ok(actual.every(button => hasClass(button, 'md-btn--sm')));
  if (actual[1]) assert.ok(hasClass(actual[1], 'md-btn--ghost'));
  assert.equal((html.match(/class="md-act-slot"/g) || []).length, actual.length);
}

// Minimal browser nodes only provide the environment. The article body,
// footer, channel picker, manual targets and publication markup are all real.
function node() {
  return { innerHTML: '', textContent: '', hidden: false, isConnected: true, children: [], listeners: {},
    appendChild(child) { this.children.push(child); },
    addEventListener(type, handler) { this.listeners[type] = handler; },
    setAttribute() {},
    querySelector() { return node(); },
    querySelectorAll() { return []; },
  };
}
const fullHtml = item => item.innerHTML + item.children.map(fullHtml).join('');
async function drawerFixture(fixture, options = {}) {
  const article = { id: 61, project_id: 7, title: '本地夹具文章', version: 2, content_type: 'explainer', ...fixture };
  const state = { clDrawerArticle: article };
  const elements = { clDrawerBody: node(), clDrawerFoot: node(), clDrawerSub: node() };
  const distribution = node();
  const publishButton = node();
  const publishedCalls = [];
  const document = {
    getElementById(id) { return id === 'clDistSec' ? distribution : id === 'clPublishBtn' ? publishButton : null; },
    createElement: node,
  };
  const api = async url => url.includes('project-channels')
    ? { channels: options.channels ?? [{ id: 8, name: '本地渠道', is_active: true, publish_ready: true }], manual_accounts: [] }
    : { data: [] };
  const names = ['clCategory', 'clRelativeTime', 'clHasQualityScore', 'clStatusBadge', 'clMiniRailTitle', 'clMiniRail',
    'clRenderDrawerBody', 'clRenderDrawerFoot', 'clRenderDrawerPublications', 'clRenderManualTargets', 'clPublicationMarkup', 'publicationResultLink'];
  const handlers = compile(names, { state, elements, document, api, escapeHtml, formatNumber: String,
    articleDisplayTitle: item => item.title, window: { location: { origin: 'http://local.test' } },
    clPublishBatch: (...args) => publishedCalls.push(args), clSelectedChannelIds: () => [8],
  }, 'let clChannelLoad = {articleId: null, status: "loading", channels: []}; const clPublishBusy = new Set();');
  handlers.clRenderDrawerBody(article);
  handlers.clRenderDrawerFoot(article);
  await handlers.clRenderDrawerPublications(article);
  return { html: fullHtml(elements.clDrawerBody) + fullHtml(distribution) + fullHtml(elements.clDrawerFoot),
    footer: elements.clDrawerFoot.innerHTML, distribution: fullHtml(distribution), publishButton, publishedCalls };
}
const approved = await drawerFixture({ status: 'approved' });
assert.equal(primaryCount(approved.html), 1, 'Ordinary approved: whole reading drawer has one primary');
const approvedPrimary = buttons(approved.footer).find(button => hasClass(button, 'md-btn--primary'));
assert.equal(approvedPrimary.text, '网站分发');
assert.equal(approvedPrimary.attributes['data-cl-action'], 'publish');
assert.equal(Object.hasOwn(approvedPrimary.attributes, 'disabled'), false, 'Ready channels enable the unchanged footer action');
const pickerButton = buttons(approved.distribution).find(button => button.attributes.id === 'clPublishBtn');
assert.equal(pickerButton.text, '发布到选中渠道');
assert.ok(hasClass(pickerButton, 'md-btn--secondary'));
approved.publishButton.listeners.click();
assert.deepEqual(approved.publishedCalls, [[61, [8]]], 'Existing channel selection and click binding are unchanged');
const unavailable = await drawerFixture({ status: 'approved' }, { channels: [] });
assert.equal(primaryCount(unavailable.html), 1);
assert.equal(Object.hasOwn(buttons(unavailable.footer).find(button => hasClass(button, 'md-btn--primary')).attributes, 'disabled'), true);
const blocked = await drawerFixture({ status: 'approved', distribution_blocked_reason: '出站闸门已拦下' });
assert.equal(primaryCount(blocked.html), 1, 'Blocked: whole reading drawer has one primary');
const blockedPrimary = buttons(blocked.footer).find(button => hasClass(button, 'md-btn--primary'));
assert.equal(blockedPrimary.text, '编辑文章');
assert.equal(blockedPrimary.attributes['data-cl-action'], 'edit');
assert.equal(buttons(blocked.footer).filter(button => button.attributes['data-cl-action'] === 'publish').length, 0);
assert.ok(!blocked.footer.includes('网站分发'));
const published = await drawerFixture({ status: 'published' });
assert.equal(primaryCount(published.html), 0, 'Published keeps zero reading-drawer primaries');
assert.deepEqual(buttons(published.footer).map(button => button.text), ['下载 docx', '导出微信']);

// Real raw fetch and real paginated list loader prove both article request
// streams carry the same category and clearing removes only empty filters.
const state = { selectedProject: { id: 7 }, clSearch: '主题', clContentType: 'explainer', clCategoryId: '12', clActiveTab: 'all' };
const requests = [];
const api = async url => {
  requests.push(new URL(url, 'http://local.test'));
  return { data: [], total: 0, last_page: 1 };
};
const list = compile(['clFetchArticlesRaw', 'clRowsOf', 'clTotalOf', 'clLastPageOf', 'clFetchAll', 'loadContentLibrary'], {
  state, elements: { contentLibraryPanel: {} }, api, URLSearchParams,
  clIsArticle: () => true, clSetState() {}, clUpdateTabCounts() {}, clRenderTruncNote() {}, renderContentLibrary() {},
  fillErrorNo(_el, error) { throw error; },
}, 'let contentLibraryRequest = 0; const CL_PAGE_SIZE = 100; const CL_MAX_PAGES = 5;');
await list.loadContentLibrary();
assert.equal(requests.length, 2);
assert.deepEqual(requests.map(url => url.searchParams.get('category_id')), ['12', '12']);
assert.deepEqual(requests.map(url => url.searchParams.get('status')), [null, 'recycled']);
assert.ok(requests.every(url => url.searchParams.get('search') === '主题' && url.searchParams.get('content_type') === 'explainer'));
requests.length = 0;
await list.clFetchAll({ projectId: 7, search: '', contentType: '' });
assert.ok(requests.every(url => !url.searchParams.has('category_id')), 'Independent project-home snapshot does not inherit article category filters');
requests.length = 0;
state.clSearch = '';
state.clContentType = '';
state.clCategoryId = '';
await list.loadContentLibrary();
assert.ok(requests.every(url => !url.searchParams.has('category_id') && !url.searchParams.has('search') && !url.searchParams.has('content_type')));
requests.length = 0;
delete state.clCategoryId;
await list.clFetchArticlesRaw();
assert.equal(requests[0].searchParams.has('category_id'), false, 'Legacy state without category is a valid unfiltered request');

// Category helpers are exercised rather than asserting their source text.
const select = node();
const clear = node();
const categoryState = { selectedProject: { id: 7 }, clSearch: '', clContentType: '', clCategoryId: '' };
let categoryResult = { data: [{ id: 12, name: '分类 <一>' }] };
const filters = compile(['clSyncFilters', 'clLoadCategories'], { state: categoryState, escapeHtml,
  document: { getElementById: id => id === 'clCategoryFilter' ? select : clear },
  api: async url => { assert.equal(url, '/api/v1/categories'); if (categoryResult instanceof Error) throw categoryResult; return categoryResult; },
}, 'let clFilterProjectId = null; let clCategoryRequest = 0;');
filters.clSyncFilters();
await filters.clLoadCategories();
assert.equal(select.hidden, false);
assert.ok(select.innerHTML.includes('全部分类') && select.innerHTML.includes('分类 &lt;一&gt;'));
categoryState.clCategoryId = '12';
filters.clSyncFilters();
assert.equal(select.value, '12');
assert.equal(clear.hidden, false);
categoryState.selectedProject = { id: 8 };
filters.clSyncFilters();
assert.equal(categoryState.clCategoryId, '');
assert.equal(select.value, '');
assert.equal(clear.hidden, true);
categoryResult = { data: [] };
await filters.clLoadCategories();
assert.equal(select.hidden, true);
categoryResult = new Error('local fixture: unavailable');
select.hidden = false;
await filters.clLoadCategories();
assert.equal(select.hidden, true);
// Clearing filters: a route-bound type must be dropped from the address, not only from state.
for (const [type, expected] of [['ranking', ['route:content-library']], ['', ['sync', 'load']]]) {
  const calls = [];
  const clearState = { clSearch: 'q', clContentType: type, clCategoryId: '12' };
  const searchInput = { value: 'q' };
  const { clClearFilters } = compile(['clClearFilters'], {
    state: clearState, elements: { clSearchInput: searchInput },
    openWorkspaceView: view => calls.push(`route:${view}`),
    clSyncFilters: () => calls.push('sync'), loadContentLibrary: () => calls.push('load'),
  });
  clClearFilters();
  assert.deepEqual(calls, expected, `clear with type "${type}"`);
  assert.equal(clearState.clSearch, '');
  assert.equal(clearState.clCategoryId, '');
  assert.equal(searchInput.value, '');
}
// All four material row renderers use the existing component actions.
const { matRowHtml } = compile(['matRowHtml'], { escapeHtml });
for (const type of ['title', 'keyword', 'image', 'author']) {
  const html = matRowHtml({ id: 47, title: '<素材>', keyword: '关键词', name: '作者', bio: '简介', url: 'http://127.0.0.1/image.png' }, type);
  const actions = buttons(html);
  assert.equal(actions.length, 2);
  assert.deepEqual(actions.map(button => button.text), ['编辑', '删除']);
  assert.deepEqual(actions.map(button => button.attributes['data-mat-action']), ['edit', 'delete']);
  assert.ok(actions.every(button => button.attributes['data-id'] === '47' && hasClass(button, 'md-btn--sm')));
  assert.ok(hasClass(actions[0], 'md-btn--secondary') && hasClass(actions[1], 'md-btn--ghost'));
  assert.equal(primaryCount(html), 0);
  assert.equal(html.includes('link-btn'), false);
}
console.log('EXEC-13 list pages: A4 eight rows, reading drawer hierarchy, actual category requests/reset/failure, route-aware clear, four material row renderers, all passed.');
