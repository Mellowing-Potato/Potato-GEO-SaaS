#!/usr/bin/env node
/**
 * 租户侧的凭据边界（2026-09-22 用户口径）。
 *
 * 原话：**租户只做积分展示，没有 key 与令牌的创建和删除，必须隐藏，避免敏感操作。**
 *
 * 所以这里钉两件事，缺一不可：
 *   1) 租户工作台里**不存在**凭据入口 —— 渠道密钥管理（生成/撤销）、Agent 包、
 *      WP 信标插件（三者都依赖渠道密钥）、API 令牌（签发/吊销）。
 *      "不存在"指的是模板里连按钮/面板都没有（不是靠 hidden 属性藏起来 ——
 *      任何一处 `hidden = false` 就能让它重新露出来）。
 *   2) 撤的是**租户入口**，不是后端能力：四个端点必须原样在 routes/api.php 里，
 *      运营侧/API 仍能调用。只断言"前端没有"会让后来人顺手把端点也删掉。
 *
 * 同时守住仍然属于租户侧、且不需要凭据的两件事：WP 分类标签同步、多渠道路由发布。
 * 它们用真实函数驱动（不是 grep），能抓住"路径拼错、请求体字段写错、部分失败报成成功"。
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { readEntrySource } from './helpers/entry-source.mjs';
import { parentTemplateSource, workbenchTemplateSource } from './helpers/workbench-templates.mjs';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const source = readEntrySource();
const routes = readFileSync(resolve(ROOT, 'routes/api.php'), 'utf8');
const blade = parentTemplateSource().replace(/\{\{--[\s\S]*?--\}\}/g, '');
const workbench = workbenchTemplateSource().replace(/\{\{--[\s\S]*?--\}\}/g, '');

// ─── 一、租户界面里不许有凭据入口 ───

const forbiddenMarkers = [
  ['API 令牌面板', ['id="apiTokensPanel"', 'data-view-target="api-tokens"']],
  ['渠道密钥管理', ['id="channelSecretButton"', 'id="channelSecretResult"']],
  ['Agent 包', ['id="channelAgentButton"', 'id="channelAgentPackage"']],
  ['WP 信标插件', ['id="channelBeaconButton"', 'id="channelBeaconResult"']],
];
for (const [label, markers] of forbiddenMarkers) {
  for (const marker of markers) {
    assert.ok(!blade.includes(marker), `租户父模板不得出现${label}（${marker}）`);
    assert.ok(!workbench.includes(marker), `租户面板模板不得出现${label}（${marker}）`);
  }
}

// 前端源码里也不许再调凭据端点（连按钮都没了，还留着调用就是死代码 + 复活风险）。
for (const endpoint of ['/api/v1/api-tokens', '/secrets', '/agent-package', '/beacon-plugin']) {
  assert.ok(!source.includes(endpoint), `租户前端源码不得调用 ${endpoint}`);
}

// 后端凭据端点（API 令牌 / WP 信标密钥与插件）必须在；旧站点 Agent 包端点已随协议退役删除。
for (const route of ['api-tokens', 'publishing/channels/{channel}/secrets', 'beacon-plugin']) {
  assert.ok(routes.includes(route), `后端路由 ${route} 必须保留（运营侧仍要用）`);
}
assert.ok(!routes.includes('agent-package'), '旧站点 Agent 包路由应已退役移除');

// ─── 二、渠道详情：按类型只放不需要凭据的按钮 ───

const sliceBetween = (startMarker, endMarker) => {
  const start = source.indexOf(startMarker);
  const end = source.indexOf(endMarker, start);
  assert.ok(start >= 0, `找不到起点：${startMarker}`);
  assert.ok(end > start, `找不到终点：${endMarker}`);

  return source.slice(start, end);
};

const channelDetailSource = sliceBetween(
  '  function publishingChannelTypeLabel(type) {',
  '  async function checkChannelHealth() {',
);

function channelDetailFor(type) {
  const elements = {
    channelList: { style: { display: '' } },
    channelDetail: { hidden: true },
    channelDetailTitle: { textContent: '' },
    channelDetailMessage: { textContent: '' },
    channelHealthResult: { hidden: false },
    channelLogsResult: { hidden: false },
    channelRewriteResult: { hidden: false },
    channelTaxonomyResult: { hidden: false },
    channelTaxonomyButton: { hidden: false },
  };
  const state = { selectedChannelId: null, channels: [{ id: 7, name: '客户站', type, endpoint_url: 'https://blog.example.com' }] };
  const factory = new Function(
    'state', 'elements', 'api', 'escapeHtml', 'formatDate', 'setMessage', 'validationMessage',
    `${channelDetailSource}; return { openChannelDetail };`,
  )(
    state, elements, async () => ({}), (v) => String(v), (v) => String(v ?? ''), () => {}, (e) => String(e),
  );

  return { elements, factory };
}

for (const type of ['wordpress_rest', 'http_webhook', 'hosted_page']) {
  const { elements, factory } = channelDetailFor(type);
  await factory.openChannelDetail(7);
  assert.equal(elements.channelTaxonomyButton.hidden, type !== 'wordpress_rest',
    `分类标签同步只对 wordpress_rest 开放（当前 ${type}）`);
  for (const key of ['channelSecretButton', 'channelAgentButton', 'channelBeaconButton']) {
    assert.equal(elements[key], undefined, `${type} 渠道的 ${key} 不该再有任何登记/可见性逻辑`);
  }
}

// ─── 三、仍属租户侧的两件事：分类标签同步 + 多渠道路由发布 ───

const taxonomySource = sliceBetween(
  '  // WP 分类标签预同步（阶段 4）',
  '  // ─── 项目渠道绑定 (B6.4) ───',
);

/** 最小的结果槽替身：记 innerHTML。 */
function fakeHost() {
  return {
    hidden: true,
    html: '',
    set innerHTML(value) { this.html = value; },
    get innerHTML() { return this.html; },
    querySelector() { return { addEventListener() {} }; },
    querySelectorAll() { return []; },
  };
}

{
  const requests = [];
  const host = fakeHost();
  const elements = { channelTaxonomyResult: host };
  const state = { selectedChannelId: 12, selectedProject: { id: 5, settings: { target_keywords: ['种植牙价格', ' '] } } };
  const confirmed = [];
  const factory = new Function(
    'state', 'elements', 'api', 'escapeHtml', 'confirmWorkspaceAction',
    `${taxonomySource}; return { syncWordPressTaxonomy };`,
  )(
    state, elements,
    async (path, options) => {
      requests.push({ path, options });
      if (path === '/api/v1/categories') return { data: [{ id: 1, name: '种植牙' }, { id: 2, name: ' ' }] };
      return { object: 'taxonomy_sync', categories: { '种植牙': 11 }, tags: { '种植牙价格': 22 }, synced_categories: 1, synced_tags: 1 };
    },
    (v) => String(v),
    async (message) => { confirmed.push(message); return true; },
  );

  await factory.syncWordPressTaxonomy();
  const post = requests.find((r) => r.path.endsWith('/sync-taxonomy'));
  assert.ok(post, '确认后必须真的发同步请求');
  assert.equal(post.options.method, 'POST');
  assert.deepEqual(JSON.parse(post.options.body), { categories: ['种植牙'], tags: ['种植牙价格'] }, '空白词要被丢掉');
  assert.ok(confirmed.at(-1).includes('分类 1 个'), '确认框要先说清同步什么');
  assert.ok(host.innerHTML.includes('分类 1 个 · 标签 1 个'));
  assert.ok(host.innerHTML.includes('种植牙价格 → #22'), '映射结果要看得到');
}

{
  const requests = [];
  const host = fakeHost();
  const elements = { channelTaxonomyResult: host };
  const state = { selectedChannelId: 12, selectedProject: null };
  const factory = new Function(
    'state', 'elements', 'api', 'escapeHtml', 'confirmWorkspaceAction',
    `${taxonomySource}; return { syncWordPressTaxonomy };`,
  )(
    state, elements,
    async (path) => { requests.push(path); return { data: [] }; },
    (v) => String(v), async () => true,
  );

  await factory.syncWordPressTaxonomy();
  assert.deepEqual(requests, ['/api/v1/categories'], '空词表只读一次分类，绝不发空同步请求');
  assert.ok(host.innerHTML.includes('没有可同步的分类或标签'), '要如实说明为什么没同步');
}

const batchSource = sliceBetween(
  '  async function clPublishBatch(id, channelIds) {',
  '  /** 抽屉「分发去向」里勾选的渠道 id（多选）。 */',
);

{
  const requests = [];
  const notices = [];
  const state = { contentLibrary: [{ id: 31, title: '文章' }], clDrawerArticle: null };
  const factory = new Function(
    'api', 'clToast', 'clRefreshRow', 'state', 'clOpenDrawer', 'clDrawerState',
    'clPublishBusy', 'clChannelLoad', 'elements', 'document', 'clRenderDrawerFoot',
    `${batchSource}; return { clPublishBatch };`,
  )(
    async (path, options) => {
      requests.push({ path, options });
      return { object: 'article_publication_batch', publications: [{ id: 1 }], errors: [{ channel_id: 9, error: '渠道不存在。' }] };
    },
    (message, variant) => notices.push({ message, variant }),
    async () => {},
    state, async () => {}, { trigger: null }, new Set(),
    { articleId: '31', status: 'ready', channels: [{ id: 7 }, { id: 9 }] },
    {}, { getElementById: () => null }, () => {},
  );

  await factory.clPublishBatch(31, ['7', '9']);
  assert.equal(requests.at(-1).path, '/api/v1/articles/31/publish-batch');
  assert.equal(requests.at(-1).options.method, 'POST');
  assert.deepEqual(JSON.parse(requests.at(-1).options.body), { channel_ids: [7, 9], async: true });
  assert.equal(notices.at(-1).variant, 'warning', '部分失败不能报成成功');
  assert.ok(notices.at(-1).message.includes('渠道不存在。'), '失败原因要带到用户面前');
  assert.ok(notices.at(-1).message.includes('已提交 1 个渠道'));
}

{
  const requests = [];
  const notices = [];
  const factory = new Function(
    'api', 'clToast', 'clRefreshRow', 'state', 'clOpenDrawer', 'clDrawerState',
    'clPublishBusy', 'clChannelLoad', 'elements', 'document', 'clRenderDrawerFoot',
    `${batchSource}; return { clPublishBatch };`,
  )(
    async (path, options) => { requests.push({ path, options }); return {}; },
    (message, variant) => notices.push({ message, variant }),
    async () => {}, { contentLibrary: [] }, async () => {}, { trigger: null }, new Set(),
    { articleId: '31', status: 'ready', channels: [{ id: 7 }] },
    {}, { getElementById: () => null }, () => {},
  );

  await factory.clPublishBatch(31, []);
  assert.equal(requests.length, 0, '一个渠道都没勾就不该发请求');
  assert.equal(notices.at(-1).variant, 'danger');
  assert.equal(notices.at(-1).message, '请先选择发布渠道。');
}

console.log('租户侧无凭据入口（密钥/令牌/Agent 包/信标插件），而分类标签同步与多渠道发布仍可用。');
