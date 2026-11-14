import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
// 面板已拆到 workbench/panels/*（计划 §7 第 2 步）：读整组模板，顺序与拆分前一致
// （本文件有用 indexOf 比位置的断言，所以顺序必须保持文档顺序）。
import { workbenchTemplateSource } from './helpers/workbench-templates.mjs';
import { readEntrySource } from './helpers/entry-source.mjs';
const source = readEntrySource();
const blade = workbenchTemplateSource();
function fn(name) {
  const start = source.search(new RegExp(`  (?:async )?function ${name}\\(`));
  assert.ok(start >= 0, name);
  return source.slice(start, source.indexOf('\n  }', start) + 4);
}
function compile(names, deps, prelude = '') {
  return new Function(...Object.keys(deps), `${prelude}\n${names.map(fn).join('\n')}\nreturn {${names.join(',')}};`)(...Object.values(deps));
}
const day = (date, hour = 12) => new Date(2026, 8, date, hour).toISOString();
const state = { selectedProject: { id: 1 }, articles: [
  { id: 1, title: '<old>', content_type: 'report', created_at: day(7), updated_at: day(10) },
  { id: 2, title: 'new', content_type: 'report', created_at: day(10) },
  { id: 3, title: 'yesterday', content_type: 'report', created_at: day(9), source_type: 'geo_analysis' },
  { id: 4, title: 'article', content_type: 'explainer', created_at: day(10) },
], assetSourceType: 'all', articlePage: 1, articleLastPage: 1 };
const clIsArticle = a => a.content_type === 'explainer';
const helpers = compile(['reportHistoryGroup', 'sortedHistoryReports', 'articleHistoryMarkup'], {
  state, clIsArticle, articleDisplayTitle: a => a.title,
  escapeHtml: text => String(text).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;'),
  statusLabel: value => value, formatDate: value => value,
});
assert.deepEqual(helpers.sortedHistoryReports().map(a => a.id), [2, 3, 1]);
assert.deepEqual(state.articles.map(a => a.id), [1, 2, 3, 4]); // No mutation or updated_at sorting.
assert.equal(helpers.reportHistoryGroup(day(10), new Date(2026, 8, 10)), '今天');
assert.equal(helpers.reportHistoryGroup(day(9), new Date(2026, 8, 10)), '昨天');
assert.equal(helpers.reportHistoryGroup(day(7), new Date(2026, 8, 10)), '更早');
assert.equal(helpers.reportHistoryGroup('bad', new Date(2026, 8, 10)), '日期未知');
assert.equal(helpers.reportHistoryGroup(new Date(2026, 7, 31, 23).toISOString(), new Date(2026, 8, 1)), '昨天');
const markup = helpers.articleHistoryMarkup(state.articles[0]);
assert.ok(markup.includes('href="#result/1"') && markup.includes('&lt;old&gt;'));
assert.ok(!markup.includes(day(10)) && !markup.includes('<old>'));

class Node {
  children = []; handlers = {}; textContent = ''; hidden = false; open = false;
  set innerHTML(value) { this.html = value; this.children = []; }
  get innerHTML() { return this.html || ''; }
  appendChild(node) { this.children.push(node); }
  addEventListener(type, handler) { this.handlers[type] = handler; }
  querySelector(selector) { this.parts ??= {}; return this.parts[selector] ??= new Node(); }
}
const elements = { assetHistory: new Node(), assetMessage: new Node(), assetResult: new Node() };
const nodes = { reportUseOptions: new Node(), reportManagement: new Node() };
const document = { createElement: () => new Node(), getElementById: id => nodes[id] };
const opened = []; const downloaded = [];
const { renderArticleHistory, resetReportOptions } = compile(['renderArticleHistory', 'resetReportOptions'], {
  elements, state, document, ...helpers,
  reportHistoryGroup: date => helpers.reportHistoryGroup(date, new Date(2026, 8, 10)),
  openResultPage: id => opened.push(id), downloadArticle: article => downloaded.push(article.id), renderArticleHistoryMore: () => {},
});
renderArticleHistory();
assert.deepEqual(elements.assetHistory.children.filter(n => n.className === 'report-date-group').map(n => n.textContent), ['今天', '昨天', '更早']);
const row = elements.assetHistory.children.find(n => n.className === 'report-list-row');
row.parts['.report-title-link'].handlers.click({ button: 0, preventDefault() {} });
assert.deepEqual(opened, [2]);
assert.equal(state.resultZone, 'full');
row.parts['.report-title-link'].handlers.click({ button: 0, ctrlKey: true });
assert.deepEqual(opened, [2]); // Modified clicks retain the native link behavior.
row.parts['[data-history-download]'].handlers.click();
assert.deepEqual(downloaded, [2]);
nodes.reportManagement.open = true;
resetReportOptions(false);
assert.ok(nodes.reportManagement.hidden && !nodes.reportManagement.open);
resetReportOptions(true);
assert.ok(!nodes.reportManagement.hidden && !nodes.reportManagement.open);

let scope = 'tenant-a'; let pending = [];
const selected = [];
const { loadArticles } = compile(['loadArticles'], {
  state, elements, URLSearchParams, recentProjectStorageKey: () => scope,
  renderAssetControls: () => {}, renderArticleHistory,
  renderAssetNextPanel: () => {},
  setMessage: (node, text) => { node.textContent = text; }, selectArticle: id => selected.push(id),
  api: url => new Promise((resolve, reject) => pending.push({ url, resolve, reject })),
}, 'let articleHistoryRequest = 0; let articleHistoryLoadedProjectId = 0; let articleHistoryLoading = false; let articleHistoryLoadingProjectId = 0; let articleHistoryError = false;');
let request = loadArticles(1);
pending.shift().resolve({ data: [{ id: 6, content_type: 'report', title: 'report', created_at: day(9) }], current_page: 1, last_page: 2 });
await request;
assert.deepEqual(selected, []); // Loading a list must never fetch/open the first report.
request = loadArticles(1, null, { append: true });
const append = pending.shift(); assert.ok(append.url.includes('page=2'));
append.resolve({ data: [{ id: 6 }, { id: 7 }], current_page: 2, last_page: 2 }); await request;
assert.deepEqual(state.articles.map(a => a.id), [6, 7]);
const older = loadArticles(1); state.assetSearch = 'latest'; const newer = loadArticles(1);
pending[1].resolve({ data: [{ id: 9 }] }); await newer;
pending[0].resolve({ data: [{ id: 8 }] }); await older;
assert.deepEqual(state.articles.map(a => a.id), [9]); pending = [];
request = loadArticles(1); scope = 'tenant-b'; pending.shift().resolve({ data: [{ id: 10 }] }); await request;
assert.deepEqual(state.articles.map(a => a.id), [9]);
request = loadArticles(1, 11); pending.shift().resolve({ data: [{ id: 11 }] }); await request;
assert.deepEqual(selected, [11]); // Explicit save/reload still opens the updated report.

const assetStart = blade.indexOf('id="assetPanel"');
const resultStart = blade.indexOf('id="resultPanel"');
assert.ok(!blade.slice(assetStart, resultStart).includes('id="assetResult"'));
assert.ok(blade.indexOf('id="resultPageBody"') < blade.indexOf('id="reportManagement"'));
assert.ok(blade.indexOf('id="reportManagement"') < blade.indexOf('id="assetResult"'));
assert.ok(blade.includes('data-result-action="manage"'));
assert.ok(fn('renderArticleResult').includes('renderArticlePublish(article)'));
console.log('History reports: date grouping/order, direct reading, downloads, pagination, stale responses and retained detail management passed.');
