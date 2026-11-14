#!/usr/bin/env node
/** AI 可见性付费提交安全回归：全程 fake fetch，不访问网络、不触发模型。 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { runInNewContext } from 'node:vm';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '../..');
const source = readFileSync(resolve(ROOT, 'public/geo-visibility.js'), 'utf8');

let checks = 0;
let failures = 0;
function assert(label, condition) {
  checks += 1;
  if (condition) console.log('  ✓ ' + label);
  else {
    failures += 1;
    console.log('  ✗ ' + label);
  }
}

function response(body, status = 200) {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: async () => body,
  };
}

function element() {
  return {
    value: '', innerHTML: '', textContent: '', className: '',
    disabled: false, hidden: false,
    addEventListener() {},
    querySelector() { return null; },
    querySelectorAll() { return []; },
    focus() {},
  };
}

const handlers = new Map();
const ids = [
  'newRunForm', 'formMsg', 'projectId', 'questions', 'submitBtn', 'suggestionList',
  'suggestionHint', 'questionCount', 'runDetailHost', 'downloadMd', 'downloadHtml',
  'runListBody', 'runListPager',
];
const nodes = new Map(ids.map((id) => ['#' + id, element()]));
nodes.forEach((node, selector) => {
  const id = selector.slice(1);
  node.addEventListener = (type, fn) => handlers.set(id + ':' + type, fn);
});

const storage = new Map();
storage.set('geo_visibility_user', JSON.stringify({ id: 101, tenant: { id: 11, subdomain: 'tenant-a' } }));
const sessionStorage = {
  get length() { return storage.size; },
  key(index) { return [...storage.keys()][index] ?? null; },
  getItem(key) { return storage.has(key) ? storage.get(key) : null; },
  setItem(key, value) { storage.set(key, String(value)); },
  removeItem(key) { storage.delete(key); },
};

let fetchMode = 'normal';
let claimedKeyOverride = null;
let seenConfirmationFails = false;
const requests = [];
const deferredPosts = [];
const deferredLists = [];
function fakeFetch(path, options = {}) {
  requests.push({ path, method: options.method || 'GET', body: options.body, headers: options.headers || {} });

  if (path === '/api/v1/visibility/runs/claim') {
    return Promise.resolve(response({ ok: true, request_key: claimedKeyOverride || JSON.parse(options.body).request_key }));
  }
  if (path === '/api/v1/visibility/runs/seen') {
    return Promise.resolve(seenConfirmationFails
      ? response({ message: 'confirmation storage unavailable' }, 503)
      : response({ ok: true }));
  }

  if ((options.method || 'GET') === 'POST' && path === '/api/v1/visibility/runs') {
    if (fetchMode === 'deferred-post') {
      return new Promise((resolvePost) => deferredPosts.push((body, status = 202) => resolvePost(response(body, status))));
    }
    if (fetchMode === 'post-503') return Promise.resolve(response({ message: '网关暂时不可用' }, 503));
    if (fetchMode === 'post-network-error') return Promise.reject(new Error('socket closed'));
    if (fetchMode === 'post-invalid-success') return Promise.resolve(response(null, 202));
    if (fetchMode === 'batch-success') {
      return Promise.resolve(response({
        batch_id: 'batch-2', question_count: 2,
        runs: [{ id: 201, batch_id: 'batch-2', project_id: 22, status: 'pending', is_terminal: false }],
      }, 202));
    }
  }

  if (path === '/api/v1/visibility/runs?batch_id=batch-2') {
    return Promise.resolve(response({
      data: [
        { id: 201, batch_id: 'batch-2', status: 'completed', is_terminal: true },
        { id: 202, batch_id: 'batch-2', status: 'failed', is_terminal: true },
      ],
      batches: [{
        batch_id: 'batch-2', question_count: 2,
        completed_count: 1, failed_count: 1, running_count: 0,
      }],
    }));
  }

  if (path === '/api/v1/visibility/runs/201') {
    return Promise.resolve(response({
      data: {
        id: 201, batch_id: 'batch-2', project_id: 22,
        question: '问题一', status: 'completed', is_terminal: true,
        sources: [], search_queries: [], usage: {},
      },
    }));
  }

  if (path.startsWith('/api/v1/visibility/runs?per_page=')) {
    if (fetchMode === 'deferred-list') {
      return new Promise((resolveList) => deferredLists.push((body) => resolveList(response(body))));
    }
    return Promise.resolve(response({ configured: true, data: [], meta: { current_page: 1, last_page: 1, total: 0 } }));
  }

  return Promise.resolve(response({ data: [] }));
}

const browser = {
  console, URL, Math, Date, Promise,
  setTimeout, clearTimeout,
  sessionStorage,
  localStorage: { getItem() { return null; }, setItem() {} },
  fetch: fakeFetch,
  document: {
    readyState: 'loading',
    addEventListener() {},
    querySelector(selector) { return nodes.get(selector) || null; },
    querySelectorAll() { return []; },
  },
  window: {
    location: { href: 'https://app.example.test/visibility?project_id=11', hash: '' },
    history: { replaceState() {} },
    GEO_VISIBILITY_CONFIG: { maxQuestions: 10 },
  },
};

const instrumented = source.replace(
  "  if (document.readyState === 'loading') {",
  "  window.test = { bindForm: bindForm, loadList: loadList };\n  if (document.readyState === 'loading') {",
);
runInNewContext(instrumented, browser);
browser.window.test.bindForm();

const formSubmit = handlers.get('newRunForm:submit');
const project = nodes.get('#projectId');
const questions = nodes.get('#questions');
const submit = nodes.get('#submitBtn');
const message = nodes.get('#formMsg');
const listBody = nodes.get('#runListBody');
const flush = async (turns = 5) => {
  for (let i = 0; i < turns; i += 1) await new Promise(setImmediate);
};

await browser.window.test.loadList();
await flush();
console.log('\nAI 可见性 · 付费提交与异步刷新安全\n');

// A 项目发起后模拟脚本强行切到 B；真实 UI 已 disabled，这里仍验证迟到回调的二道守卫。
fetchMode = 'deferred-post';
project.value = '11';
questions.value = '品牌 A 是做什么的？';
formSubmit({ preventDefault() {} });
formSubmit({ preventDefault() {} });
assert('付费 POST 在途时冻结项目、问题框和提交按钮', project.disabled && questions.disabled && submit.disabled);
await flush();
assert('付费 POST 在途时重复 submit 仍只发送一次',
  requests.filter((item) => item.path === '/api/v1/visibility/runs' && item.method === 'POST').length === 1);

project.value = '22';
questions.value = '品牌 B 的新问题不能被 A 清空';
deferredPosts.shift()({
  batch_id: 'batch-a', question_count: 1,
  runs: [{ id: 101, batch_id: 'batch-a', project_id: 11, status: 'pending', is_terminal: false }],
});
await flush();
assert('A 的迟到响应不会清空 B 项目的新输入', questions.value === '品牌 B 的新问题不能被 A 清空');
assert('A 的迟到响应不会打开旧项目运行详情', !requests.some((item) => item.path === '/api/v1/visibility/runs/101'));
assert('提交结束后恢复项目与问题框', !project.disabled && !questions.disabled);

// 5xx 与无 HTTP 状态都属于结果不确定；提示复用请求，而不是鼓励新建一次。
fetchMode = 'post-503';
questions.value = '网关失败问题';
formSubmit({ preventDefault() {} });
await flush();
assert('5xx 显示待确认而非发起失败', message.textContent.includes('提交状态暂时无法确认') && !message.textContent.startsWith('发起失败'));
assert('5xx 文案明确不会重复执行', message.textContent.includes('不会重复执行') && message.className.includes('warn'));

fetchMode = 'post-network-error';
questions.value = '网络瞬断问题';
formSubmit({ preventDefault() {} });
await flush();
assert('未知网络结果沿用安全待确认文案', message.textContent.includes('提交状态暂时无法确认'));

fetchMode = 'post-invalid-success';
questions.value = '返回体损坏问题';
formSubmit({ preventDefault() {} });
await flush();
assert('2xx 缺少批次标识也按未知结果处理', message.textContent.includes('提交状态暂时无法确认'));
assert('未确认请求只保存短指纹，不把监测问题写进 sessionStorage',
  [...storage.values()].every((value) => !value.includes('网关失败问题')
    && !value.includes('网络瞬断问题')
    && !value.includes('返回体损坏问题')));

// 相同列表地址也可能因手动刷新与后台刷新同时在途；后发响应必须胜出。
fetchMode = 'deferred-list';
browser.window.test.loadList();
browser.window.test.loadList({ background: true });
assert('列表并发测试确实发出两条请求', deferredLists.length === 2);
deferredLists[1]({
  configured: true,
  data: [{ id: 302, question: '新响应', status: 'completed', is_terminal: true }],
  meta: { current_page: 1, last_page: 1, total: 1 },
});
await flush();
assert('后发列表响应先渲染', listBody.innerHTML.includes('302') && listBody.innerHTML.includes('新响应'));
deferredLists[0]({
  configured: true,
  data: [{ id: 301, question: '迟到旧响应', status: 'completed', is_terminal: true }],
  meta: { current_page: 1, last_page: 1, total: 1 },
});
await flush();
assert('迟到列表响应不能覆盖最新列表', listBody.innerHTML.includes('302') && !listBody.innerHTML.includes('迟到旧响应'));

// 接口已有 batch_id/question_count/batches；验证表单展示整批终态而非首条完成。
fetchMode = 'batch-success';
project.value = '22';
questions.value = '问题一\n问题二';
formSubmit({ preventDefault() {} });
await flush(8);
assert('成功提交按 batch_id 查询整批进度', requests.some((item) => item.path === '/api/v1/visibility/runs?batch_id=batch-2'));
assert('整批终态展示完成与失败数量', message.textContent.includes('本轮 2 个问题已结束')
  && message.textContent.includes('完成 1') && message.textContent.includes('失败 1'));
assert('首条详情完成不会覆盖整批状态', !message.textContent.startsWith('运行 #201'));
assert('同一项目同一内容成功后才清空已提交问题', questions.value === '');

// Browser storage loss: the claim response is authoritative. Until seen is
// saved, a repeat click must reuse that recovered key.
claimedKeyOverride = 'server-recovered-key';
seenConfirmationFails = true;
questions.value = '恢复原批次的问题';
formSubmit({ preventDefault() {} });
await flush(8);
const recoveredPaid = requests.filter((item) => item.path === '/api/v1/visibility/runs'
  && item.method === 'POST' && item.headers['X-Idempotency-Key'] === claimedKeyOverride);
assert('服务端认领恢复的键用于实际付费请求', recoveredPaid.length === 1);
assert('送达确认失败时保留恢复的原键', [...storage.values()].some((value) => value.includes('server-recovered-key')));
seenConfirmationFails = false;
questions.value = '恢复原批次的问题';
formSubmit({ preventDefault() {} });
await flush(8);
assert('再次提交复用原键而不换新付费尝试',
  requests.filter((item) => item.path === '/api/v1/visibility/runs'
    && item.method === 'POST' && item.headers['X-Idempotency-Key'] === claimedKeyOverride).length === 2);
assert('送达确认成功后才清除未决键', ![...storage.values()].some((value) => value.includes('server-recovered-key')));

console.log(`\n${checks - failures}/${checks} 项通过`);
if (failures) process.exit(1);
