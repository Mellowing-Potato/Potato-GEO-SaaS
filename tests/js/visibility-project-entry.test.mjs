#!/usr/bin/env node
/** 效果监测入口与项目上下文回归：守住真实入口、真实平台文案和自动项目选择。 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { runInNewContext } from 'node:vm';
import { readEntrySource } from './helpers/entry-source.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '../..');
const entry = readEntrySource();
const visibility = readFileSync(resolve(ROOT, 'public/geo-visibility.js'), 'utf8');
const blade = readFileSync(resolve(ROOT, 'resources/views/visibility.blade.php'), 'utf8');
const registry = readFileSync(resolve(ROOT, 'app/Services/SkillAgent/SkillRegistryService.php'), 'utf8');

let checks = 0;
let failures = 0;
function assert(label, condition) {
  checks++;
  if (!condition) {
    failures++;
    console.log('  ✗ ' + label);
  } else {
    console.log('  ✓ ' + label);
  }
}
function extractFunction(source, name) {
  const marker = `function ${name}(`;
  const start = source.indexOf(marker);
  if (start === -1) throw new Error(`源码里找不到 ${marker}`);
  const bodyStart = source.indexOf('{', source.indexOf(')', start));
  let depth = 0;
  for (let i = bodyStart; i < source.length; i++) {
    if (source[i] === '{') depth++;
    else if (source[i] === '}' && --depth === 0) return source.slice(start, i + 1);
  }
  throw new Error(`${name} 花括号未配对`);
}

console.log('\n效果监测 · 真实入口与项目上下文\n');

const assigned = [];
const state = { selectedProject: { id: 11 } };
const fakeWindow = {
  location: {
    origin: 'https://app.example.test',
    assign: (url) => assigned.push(url),
  },
};
const openSkillAction = new Function(
  'state', 'window',
  `${extractFunction(entry, 'openSkillAction')}; return openSkillAction;`,
)(state, fakeWindow);
openSkillAction({ action_path: '/visibility' });
assert('技能卡进入真实 /visibility 页面', assigned[0]?.startsWith('https://app.example.test/visibility?'));
assert('入口自动携带当前项目 11', assigned[0]?.includes('project_id=11'));
assert('入口落在发起监测区', assigned[0]?.endsWith('#sec-new'));
openSkillAction({ action_path: '/#knowledge' });
assert('知识库技能进入真实企业知识库', assigned[1]?.includes('/?project_id=11#knowledge'));
assert('独立入口自带的 hash 不被 sec-new 覆盖', !assigned[1]?.endsWith('#sec-new'));
openSkillAction({ action_path: '/theme-package' });
assert('站点模板技能进入真实主题包生成页', assigned[2] === 'https://app.example.test/theme-package?project_id=11#sec-new');
const requestedKbProjectId = new Function(
  'window', 'URL',
  `${extractFunction(entry, 'requestedKbProjectId')}; return requestedKbProjectId;`,
)({ location: { href: assigned[1] } }, URL);
assert('知识库上传优先使用技能卡带入的当前项目', requestedKbProjectId() === 11);
assert('知识库显式项目优先于上次上传记忆',
  /const requested = requestedKbProjectId\(\);[\s\S]*Number\(requested\)[\s\S]*Number\(remembered\)/.test(entry));
assert('知识库检索弹窗始终提供顶部关闭按钮', entry.includes('id="searchTestCloseTop"'));
assert('知识库检索弹窗支持 Esc 关闭且会移除监听',
  entry.includes('event.key === "Escape"')
  && entry.includes('document.removeEventListener("keydown", onKeydown)'));
assert('知识库检索使用稳定幂等键并在结果不确定时保留原键',
  entry.includes('pendingIdempotencyRequest("knowledge-search-test", payload)')
  && entry.includes('"X-Idempotency-Key": pendingRequest.key')
  && entry.includes('retainPendingIdempotencyRequest(error)'));

assert('监测页用项目下拉框', /<select[^>]+id="projectId"/.test(blade));
assert('监测页不再要求手填数字项目 ID', !/<input[^>]+id="projectId"[^>]+type="number"/.test(blade));
assert('监测页从受权项目 API 加载选项', /apiGet\('\/api\/v1\/projects\?per_page=100'\)/.test(visibility));
assert('监测页优先读取入口 project_id', /function requestedProjectId\(\)/.test(visibility));
assert('项目选择沿用工作台的租户级记忆键', /geo_last_project_id:/.test(visibility));
assert('监测页按当前项目读取已有问题库', /visibility\/question-suggestions\?project_id=/.test(visibility));
assert('问题建议明确读取不扣点', blade.includes('不调用模型'));
assert('页面明示每个问题分别扣点', blade.includes('每个问题独立执行'));
assert('项目与问题都显式标为必填', blade.includes('项目（必填）') && blade.includes('问题（必填'));
assert('页面明确单批最多问题数', blade.includes('最多 {{ max(1, (int) config(\'geo.visibility.max_questions\', 10)) }} 个问题'));
assert('提交前在前端去重问题', /function questionLines\(/.test(visibility));
assert('付费提交带幂等键并有同步提交锁', visibility.includes("h['X-Idempotency-Key']") && visibility.includes('visibilitySubmitInFlight'));
assert('网络结果不确定时按相同问题复用监测请求键',
  visibility.includes("pendingIdempotencyRequest('visibility-run', payload)")
  && visibility.includes('previous.fingerprint !== fingerprintSlot')
  && visibility.includes('retainPendingIdempotencyRequest(err)'));
assert('服务端明确接收前不会丢弃原监测请求键',
  visibility.includes('idempotency_result_pending')
  && visibility.includes('idempotency_protection_unavailable'));
assert('监测请求遇到网关失败、超时或限流仍保留原键',
  visibility.includes('status >= 500')
  && visibility.includes('status === 408')
  && visibility.includes('status === 425')
  && visibility.includes('status === 429'));
assert('不同监测内容各用不可逆指纹槽，损坏记录不得自动换键',
  visibility.includes('idempotencyFingerprintSlot(fingerprint)')
  && visibility.includes('原付费请求编号记录损坏')
  && visibility.includes('namespace + fingerprintSlot')
  && !/namespace\s*\+\s*fingerprint\s*;/.test(visibility));
const keyHelperStart = visibility.indexOf('function idempotencyFingerprintSlot(');
const keyHelperEnd = visibility.indexOf('function clearPendingIdempotencyRequest(', keyHelperStart);
const keyRecords = new Map();
const keyStorage = {
  get length() { return keyRecords.size; },
  key(index) { return Array.from(keyRecords.keys())[index] ?? null; },
  getItem(key) { return keyRecords.get(key) ?? null; },
  setItem(key, value) { keyRecords.set(key, String(value)); },
  removeItem(key) { keyRecords.delete(key); },
};
let generatedVisibilityKeys = 0;
const visibilityIdentity = { tenantId: 11, userId: 101 };
const pendingVisibilityKey = new Function('sessionStorage', 'tenantSubdomain', 'newIdempotencyKey', 'visibilityOwner',
  `${visibility.slice(keyHelperStart, keyHelperEnd)}; return pendingIdempotencyRequest;`,
)(keyStorage, () => 'tenant-a', () => `visibility-${++generatedVisibilityKeys}`, () => visibilityIdentity);
const firstVisibilityKey = pendingVisibilityKey('visibility-run', { projectId: 7, questions: ['same'] });
visibilityIdentity.userId = 202;
const secondAccountKey = pendingVisibilityKey('visibility-run', { projectId: 7, questions: ['same'] });
assert('监测付费键按用户分槽，不复用同租户其他账号的未决键',
  secondAccountKey.key !== firstVisibilityKey.key && secondAccountKey.storageKey !== firstVisibilityKey.storageKey);
visibilityIdentity.userId = 101;
const oldVisibilityKey = firstVisibilityKey.storageKey.replace(':11:101:', ':');
keyRecords.set(oldVisibilityKey, JSON.stringify({ key: 'old-unknown-owner' }));
let legacyVisibilityBlocked = false;
try { pendingVisibilityKey('visibility-run', { projectId: 7, questions: ['same'] }); }
catch (error) { legacyVisibilityBlocked = error.message.includes('缺少账号归属'); }
assert('监测旧版无账号归属的未决键拒绝自动换键', legacyVisibilityBlocked);
keyRecords.delete(oldVisibilityKey);
const agedVisibilityRecord = JSON.parse(keyRecords.get(firstVisibilityKey.storageKey));
agedVisibilityRecord.created_at = Date.now() - (31 * 60 * 1000);
keyRecords.set(firstVisibilityKey.storageKey, JSON.stringify(agedVisibilityRecord));
assert('监测待核验超过旧 TTL 后仍保留同一收费请求键',
  pendingVisibilityKey('visibility-run', { projectId: 7, questions: ['same'] }).key === firstVisibilityKey.key
  && generatedVisibilityKeys === 2);
keyRecords.set(firstVisibilityKey.storageKey, '{broken');
let damagedVisibilityBlocked = false;
try { pendingVisibilityKey('visibility-run', { projectId: 7, questions: ['same'] }); }
catch (error) { damagedVisibilityBlocked = error.message.includes('记录损坏'); }
assert('监测旧键损坏时不自动发新收费尝试',
  damagedVisibilityBlocked && generatedVisibilityKeys === 2 && keyRecords.has(firstVisibilityKey.storageKey));
assert('返回工作台连接态不再误写为检查登录', !readFileSync(resolve(ROOT, 'resources/views/welcome.blade.php'), 'utf8').includes('正在检查孟德账户登录状态'));
assert('详情轮询使用后台刷新，不反复清空详情区', /openDetail\(runId, \{ background: true \}\)/.test(visibility) && /if \(host && !options\.background\) loading\(host\)/.test(visibility));
assert('详情数据没变化时不重绘', /nextSignature !== detailSignature/.test(visibility));
assert('列表轮询不反复缩成骨架', /loadList\(\{ background: true \}\)/.test(visibility) && /if \(body && !options\.background\) tableLoading/.test(visibility));
assert('列表数据没变化时不重绘', /nextSignature !== listSignature/.test(visibility));
assert('检索结果不再冒充正文信源', visibility.includes('检索参考（') && !visibility.includes('<h3>信源（'));
assert('没有正文引用时明确说明参考未被逐条采用', visibility.includes('不代表正文逐条采用'));
assert('检索结果序号不再标成引注', visibility.includes('<th>返回序号</th>') && !visibility.includes('<th>引注</th>'));

assert('注册表不再暴露追踪配置 ID', !registry.includes("'tracking_config_id'"));
assert('注册表不再宣称五大 AI 平台', !registry.includes('五大 AI 平台'));
assert('注册表明示当前平台为豆包', registry.includes('当前接入平台：豆包'));
assert('真实监测页也明示当前接入豆包', blade.includes('当前接入豆包'));
assert('通用执行器识别独立入口，避免重复模型调用', /if \(\$skill->actionPath !== null\)/.test(
  readFileSync(resolve(ROOT, 'app/Http/Controllers/Api/V1/SkillExecutionController.php'), 'utf8'),
));

// 用实际事件处理与请求体回归项目切换；不启动页面认证、不访问网络。
const handlers = new Map();
const nodes = new Map([
  'newRunForm', 'formMsg', 'projectId', 'questions', 'submitBtn', 'suggestionList',
  'suggestionHint', 'questionCount', 'runDetailHost', 'downloadMd', 'downloadHtml',
  'runListBody', 'runListPager',
].map((id) => ['#' + id, {
  value: '', innerHTML: '', textContent: '', disabled: false, hidden: false,
  addEventListener(type, fn) { handlers.set(id + ':' + type, fn); },
  querySelector() { return null; }, querySelectorAll() { return []; },
}]));
const requests = [];
const browserSessionRecords = new Map();
browserSessionRecords.set('geo_visibility_user', JSON.stringify({ id: 101, tenant: { id: 11, subdomain: 'tenant-a' } }));
const browser = {
  console, URL, setTimeout, clearTimeout,
  localStorage: { getItem() { return null; }, setItem() {} },
  sessionStorage: {
    get length() { return browserSessionRecords.size; },
    key(index) { return Array.from(browserSessionRecords.keys())[index] ?? null; },
    getItem(key) { return browserSessionRecords.get(key) ?? null; },
    setItem(key, value) { browserSessionRecords.set(key, String(value)); },
    removeItem(key) { browserSessionRecords.delete(key); },
  },
  document: {
    readyState: 'loading', addEventListener() {},
    querySelector(selector) { return nodes.get(selector) || null; },
    querySelectorAll() { return []; },
  },
  window: {
    location: { href: 'https://geo.example.test/visibility?project_id=11' },
    history: { replaceState() {} },
  },
  async fetch(path, options) {
    requests.push({ path, method: options.method, body: options.body, headers: options.headers });
    return {
      ok: true, status: 200,
      async json() {
        if (path.includes('question-suggestions')) return { data: [], source: 'project_profile' };
        if (path === '/api/v1/visibility/runs/claim') {
          return { ok: true, request_key: JSON.parse(options.body).request_key };
        }
        return options.method === 'POST' ? { runs: [] } : { configured: true, data: [], meta: {} };
      },
    };
  },
};
runInNewContext(visibility.replace(
  "  if (document.readyState === 'loading') {",
  "  window.test = { loadList, bindForm, renderDetail, apiReq, runNeedsPolling, submittedBatchProgress, renderSubmittedBatchProgress };\n  if (document.readyState === 'loading') {",
), browser);
nodes.get('#projectId').value = '11';
nodes.get('#questions').value = '品牌 A 是做什么的？';
browser.window.test.bindForm();
await browser.window.test.loadList();
await new Promise(setImmediate);
nodes.get('#projectId').value = '22';
handlers.get('projectId:change')();
assert('切换项目清空上一项目问题', nodes.get('#questions').value === '');
assert('切换项目同步问题计数', nodes.get('#questionCount').textContent.includes('已填写 0 个问题'));
assert('切换项目解释问题已清空', nodes.get('#formMsg').textContent.includes('已清空上一项目的问题'));
assert('切换项目读取新项目建议', requests.some((r) => r.path.endsWith('question-suggestions?project_id=22')));
handlers.get('newRunForm:submit')({ preventDefault() {} });
assert('未重选问题不能误提交旧项目问题', !requests.some((r) => r.method === 'POST'));
nodes.get('#questions').value = '品牌 B 适合哪些客户？';
handlers.get('newRunForm:submit')({ preventDefault() {} });
handlers.get('newRunForm:submit')({ preventDefault() {} });
await new Promise(setImmediate);
const paidRequests = () => requests.filter((r) => r.path === '/api/v1/visibility/runs' && r.method === 'POST');
const submitted = JSON.parse(paidRequests()[0]?.body || '{}');
assert('新项目提交只含新项目与新问题', submitted.project_id === 22
  && JSON.stringify(submitted.questions) === JSON.stringify(['品牌 B 适合哪些客户？']));
assert('同一时刻重复提交只发送一次付费请求', paidRequests().length === 1);
assert('付费请求携带 X-Idempotency-Key', Boolean(paidRequests()[0]?.headers?.['X-Idempotency-Key']));
const sendsBeforeStorageFailure = paidRequests().length;
browser.sessionStorage.setItem = () => { throw new Error('storage unavailable'); };
nodes.get('#questions').value = '新问题：存储不可用时不发送';
handlers.get('newRunForm:submit')({ preventDefault() {} });
assert('存储不可用时监测不发送付费请求且恢复表单',
  paidRequests().length === sendsBeforeStorageFailure
  && nodes.get('#submitBtn').disabled === false
  && nodes.get('#formMsg').textContent.includes('本次未发送'));

for (const status of ['pending', 'running', 'completed', 'failed', 'reconciliation_required']) {
  const terminal = ['completed', 'failed', 'reconciliation_required'].includes(status);
  browser.window.test.renderDetail({
    id: 6, project_id: 22, status, is_terminal: terminal,
    error_message: status === 'failed' ? '上游连接失败'
      : status === 'reconciliation_required' ? '原请求结果待核对' : null,
    sources: [], search_queries: [],
  });
  assert(`${status} 详情加载状态与终态一致`, nodes.get('#runDetailHost').innerHTML.includes('class="run-progress-state"') === !terminal);
  if (status === 'failed') {
    assert('失败详情保留错误原因', nodes.get('#runDetailHost').innerHTML.includes('上游连接失败'));
  }
  if (status === 'reconciliation_required') {
    assert('待核对继续轮询详情直到原结果恢复', browser.window.test.runNeedsPolling({ status, is_terminal: true }));
    assert('待核对状态显示中文与原因', nodes.get('#runDetailHost').innerHTML.includes('待核对')
      && nodes.get('#runDetailHost').innerHTML.includes('原请求结果待核对')
      && !nodes.get('#runDetailHost').innerHTML.includes('reconciliation_required'));
  }
}

const pendingReconciliationProgress = browser.window.test.submittedBatchProgress({ data: [
  { status: 'reconciliation_required', is_terminal: true },
] }, 1);
browser.window.test.renderSubmittedBatchProgress(pendingReconciliationProgress);
assert('待核对单独显示，不再冒充进行中', pendingReconciliationProgress.running === 0
  && nodes.get('#formMsg').textContent.includes('待核对 1')
  && nodes.get('#formMsg').textContent.includes('进行中 0'));
assert('已完成和失败结果停止轮询', !browser.window.test.runNeedsPolling({ status: 'completed', is_terminal: true })
  && !browser.window.test.runNeedsPolling({ status: 'failed', is_terminal: true }));

const originalFetch = browser.fetch;
let finishOldResponse;
browser.fetch = (path, options) => path === '/account-swap-test'
  ? new Promise(resolve => { finishOldResponse = resolve; }) : originalFetch(path, options);
const oldAccountRequest = browser.window.test.apiReq('/account-swap-test');
browserSessionRecords.set('geo_visibility_user', JSON.stringify({ id: 202, tenant: { id: 11, subdomain: 'tenant-a' } }));
finishOldResponse({ ok: true, status: 200, json: async () => ({ private: 'old account' }) });
let oldResponseBlocked = false;
try { await oldAccountRequest; } catch (error) { oldResponseBlocked = error.identityChanged === true; }
assert('监测请求在途切换账号后不交付旧账号响应', oldResponseBlocked);

let reloads = 0, persists = 0, unauthorized = 0;
const reverifySource = visibility.slice(visibility.indexOf('  function reverifyOnce()'), visibility.indexOf('  function apiReq('));
const swappedLogin = runInNewContext(`var reverifyPromise = null; ${reverifySource} reverifyOnce()`, {
  visibilityOwner: () => ({ userId: 101, tenantId: 11 }),
  fetch: async () => ({ ok: true, json: async () => ({ accessToken: 'new-account', user: { id: 202 }, tenant: { id: 11 } }) }),
  persistSession: () => { persists += 1; },
  window: { location: { reload: () => { reloads += 1; } } },
  handleUnauthorized: () => { unauthorized += 1; },
});
let crossAccountRetryBlocked = false;
try { await swappedLogin; } catch (error) { crossAccountRetryBlocked = error.identityChanged === true; }
assert('403 重新核验发现换号后不保存新身份或重发原付费请求',
  crossAccountRetryBlocked && reloads === 1 && persists === 0 && unauthorized === 0);
const sameAccountLogin = runInNewContext(`var reverifyPromise = null; ${reverifySource} reverifyOnce()`, {
  visibilityOwner: () => ({ userId: 101, tenantId: 11 }),
  fetch: async () => ({ ok: true, json: async () => ({ accessToken: 'same-account', user: { id: 101 }, tenant: { id: 11 } }) }),
  persistSession: () => { persists += 1; },
  window: { location: { reload: () => { reloads += 1; } } },
  handleUnauthorized: () => { unauthorized += 1; },
});
await sameAccountLogin;
assert('403 重新核验仍允许同账号续办且不刷新', persists === 1 && reloads === 1 && unauthorized === 0);

console.log(`\n${checks - failures}/${checks} 项通过`);
if (failures) process.exit(1);
