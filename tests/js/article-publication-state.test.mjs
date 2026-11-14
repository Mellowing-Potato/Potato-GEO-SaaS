import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = readFileSync(new URL('../../public/geo-entry-content-library.js', import.meta.url), 'utf8');
const start = source.indexOf('  async function clRenderDrawerPublications(full) {');
const end = source.indexOf('  async function clGoPublishing(', start);
assert.ok(start > 0 && end > start);
const makeNode = () => ({ innerHTML: '', isConnected: true, children: [],
  setAttribute() {},
  appendChild(child) { this.children.push(child); },
  querySelector() { return { addEventListener() {} }; }, querySelectorAll() { return []; } });
const section = makeNode();
const state = { clDrawerArticle: { id: 9, version: 2, project_id: 3, status: 'approved' } };
let channels = [{ id: 1, name: '配置不全', is_active: true, publish_ready: false },
  { id: 2, name: '有效渠道', is_active: true, publish_ready: true }];
let channelError = false;
let manualAccounts = [];
const notices = [];
const api = async (url) => {
  if (url.includes('project-channels')) {
    if (channelError) throw new Error('offline');
    return { channels, manual_accounts: manualAccounts, mode: 'bound' };
  }
  return { data: [{ id: 5, status: 'accepted', channel: { name: 'WordPress', type: 'wordpress_rest' },
    remote_url: 'https://example.test/draft' }] };
};
const document = { getElementById: (id) => id === 'clDistSec' ? section : { addEventListener() {} }, createElement: makeNode };
const esc = (v) => String(v).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');
const handlers = new Function('api', 'state', 'document', 'escapeHtml', 'publicationResultLink', `
  let clChannelLoad;
  function clRenderDrawerFoot() {}
  function clGoPublishing() {}
  function clPublishBatch() {}
  function clSelectedChannelIds() {}
  function clRetryPublication() {}
  ${source.slice(start, end)}
  return { render: clRenderDrawerPublications, markup: clPublicationMarkup, channelState: () => clChannelLoad };
`)(api, state, document, esc, () => { notices.push('public link'); return '查看发布页面'; });

await handlers.render(state.clDrawerArticle);
assert.deepEqual(handlers.channelState().channels.map((c) => c.id), [2]);
assert.ok(section.innerHTML.includes('有效渠道'));
assert.ok(!section.innerHTML.includes('配置不全'));
assert.ok(section.innerHTML.includes('明确勾选'));
assert.ok(section.children.at(-1).innerHTML.includes('目标已接收，尚未公开'));
assert.ok(section.children.at(-1).innerHTML.includes('重新提交到目标'));
assert.equal(notices.length, 0, 'accepted must not expose a public-page link');

channels = [];
await handlers.render(state.clDrawerArticle);
assert.equal(handlers.channelState().status, 'ready');
assert.ok(section.innerHTML.includes('未绑定可用的网站接口'));
assert.ok(section.children.at(-1).innerHTML.includes('目标已接收'), 'history remains visible without channels');

channelError = true;
await handlers.render(state.clDrawerArticle);
assert.equal(handlers.channelState().status, 'error');
assert.ok(section.innerHTML.includes('渠道加载失败'));
assert.ok(!section.innerHTML.includes('暂无可用'));

channelError = false;
manualAccounts = [{ id: 8, platform_label: '微信', account_name: '科研公众号' }];
state.clDrawerArticle.status = 'published';
await handlers.render(state.clDrawerArticle);
assert.ok(section.innerHTML.includes('继续发布到平台'));
assert.ok(section.children.some((node) => node.innerHTML.includes('科研公众号：准备发布')),
  'publishing to one platform must not hide the next platform publishing action');

assert.ok(handlers.markup({ id: 1, status: 'published', channel_name: '<script>' }).includes('&lt;script&gt;'));
assert.equal(notices.length, 1);
assert.ok(handlers.markup({ status: 'queued' }).includes('排队中'));
console.log('Article publishing integration: readiness, accepted/public states and history passed.');
