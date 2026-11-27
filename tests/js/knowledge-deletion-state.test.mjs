import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { test } from 'node:test';

const entry = readFileSync(new URL('../../public/geo-entry.js', import.meta.url), 'utf8');
const source = readFileSync(new URL('../../public/geo-entry-knowledge-base.js', import.meta.url), 'utf8');
const labels = entry.slice(entry.indexOf('  const KB_DOC_STATUS_LABELS ='), entry.indexOf('  async function loadKnowledgeBases('));
const render = source.slice(source.indexOf('  function renderKbDocuments('), source.indexOf('  async function recrawlKbDocument('));

function html(document) {
  const rows = [];
  const context = {
    state: { selectedKbId: 21, kbDocuments: [{ id: 31, title: '测试资料', source_type: 'url', review_status: 'approved', ...document }] },
    elements: { kbDocList: { innerHTML: '', appendChild: row => rows.push(row.innerHTML), querySelectorAll: () => [] } },
    document: { createElement: () => ({ innerHTML: '', className: '' }) },
    escapeHtml: value => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;'),
    formatNumber: String, formatKnowledgeDate: () => '2026-11-27',
  };
  runInNewContext(`${labels}\n${render}\nrenderKbDocuments();`, context);
  return rows.join('');
}

test('unfinished deletion shows a Chinese warning and escaped cleanup error', () => {
  const result = html({ status: 'deleting', error_message: '<cleanup>请重试删除' });
  assert.match(result, /清理未完成/);
  assert.match(result, /data-variant="warning"/);
  assert.match(result, /&lt;cleanup&gt;请重试删除/);
  assert.doesNotMatch(result, /<cleanup>/);
  assert.doesNotMatch(result, /已解析|解析完成/);
});

test('unfinished deletion exposes only explicit retry and supplies a fallback explanation', () => {
  const result = html({ status: 'deleting' });
  assert.match(result, /材料尚未清理完成，请重试删除/);
  assert.match(result, /data-doc-action="delete"[^>]*>重试删除/);
  assert.doesNotMatch(result, /data-doc-action="(?:view|recrawl|archive)"/);
});

test('ready and failed documents retain their existing available actions', () => {
  for (const status of ['ready', 'failed']) {
    const result = html({ status, chunk_count: 1, error_message: '解析异常' });
    for (const action of ['view', 'recrawl', 'archive', 'delete']) assert.match(result, new RegExp(`data-doc-action="${action}"`));
    assert.doesNotMatch(result, /重试删除|清理未完成/);
    assert.match(result, status === 'ready' ? /1 个切片/ : /失败原因：解析异常/);
  }
});

test('an interrupted library deletion remains visible with an explicit recovery instruction', () => {
  const renderer = entry.slice(entry.indexOf('  function renderKnowledgeBases('), entry.indexOf('  async function createKnowledgeBase('));
  const rows = [];
  runInNewContext(`${renderer}\nrenderKnowledgeBases();`, {
    state: { knowledgeBases: [{ id: 21, name: '测试资料库', status: 'deleting' }] },
    elements: { kbList: { innerHTML: '', appendChild: item => rows.push(item.innerHTML) } },
    document: { createElement: () => ({ addEventListener() {} }) },
    escapeHtml: String, formatNumber: String, formatKnowledgeDate: () => '2026-11-27',
  });
  assert.match(rows[0], /清理未完成，请进入知识库重试删除/);
});

test('polling must not report a deleting material as successfully indexed', async () => {
  const poll = source.slice(source.indexOf('  function pollKbDocuments('), source.indexOf('  function renderKbDocuments('));
  const messages = [];
  const state = { selectedKbId: 21, kbDocuments: [{ id: 31, status: 'pending' }] };
  runInNewContext(`${poll}\npollKbDocuments({});`, {
    state, elements: {}, Date, kbDocPollers: new Map(), KB_POLL_INTERVAL_MS: 2500, KB_POLL_MAX_MS: 10000,
    kbDocumentScope: () => 'unchanged-workspace-and-library', kbDocumentPage: 1, kbDocumentLoading: false,
    window: { setInterval: () => 1, clearInterval() {} },
    loadKbDocuments: async () => { state.kbDocuments = [{ id: 31, status: 'deleting', chunk_count: 0 }]; },
    loadKnowledgeStats() {}, formatNumber: String,
    setMessage: (_node, message, variant) => messages.push({ message, variant }),
  });
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(messages.length, 1);
  assert.equal(messages[0].variant, 'warning');
  assert.match(messages[0].message, /清理未完成/);
  assert.doesNotMatch(messages[0].message, /解析完成|可被检索/);
});
