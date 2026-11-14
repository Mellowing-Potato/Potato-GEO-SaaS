#!/usr/bin/env node
/** 工作台付费技能：轮询隔离、恢复与幂等提交回归。 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { readEntrySource } from './helpers/entry-source.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '../..');
const source = readEntrySource();

let checks = 0;
let failures = 0;
function assert(label, condition) {
  checks++;
  console.log(`  ${condition ? '✓' : '✗'} ${label}`);
  if (!condition) failures++;
}

function between(start, end) {
  const from = source.indexOf(start);
  const to = source.indexOf(end, from + start.length);
  if (from < 0 || to < 0) throw new Error(`找不到代码区间：${start} -> ${end}`);
  return source.slice(from, to);
}

console.log('\n工作台付费技能 · 客户端安全与轮询\n');

const submit = between('async function runSelectedSkill', 'function skillExecutionPayload');
const adoptSource = between('function adoptClaimedSkillKey', 'function confirmedQueuedSkillExecution');
const adoptedState = { session: { user: { id: 101 }, tenant: { id: 7 } } };
const stored = new Map([['skill-slot', JSON.stringify({ key: 'local-key', owner_user_id: 101, owner_tenant_id: 7 })]]);
const adoptClaimedSkillKey = new Function('state', 'sessionStorage', `${adoptSource}; return adoptClaimedSkillKey;`)(
  adoptedState, { getItem: (key) => stored.get(key) ?? null, setItem: (key, value) => stored.set(key, value) },
);
const claimedRequest = { key: 'local-key', storageKey: 'skill-slot' };
adoptClaimedSkillKey(claimedRequest, 'original-server-key', 101, 7);
assert('服务端认领原键会持久替换本地新键',
  claimedRequest.key === 'original-server-key' && JSON.parse(stored.get('skill-slot')).key === 'original-server-key');
adoptedState.session.user.id = 202;
let switchedOwnerRejected = false;
try { adoptClaimedSkillKey(claimedRequest, 'another-key', 101, 7); }
catch (_) { switchedOwnerRejected = true; }
assert('账号切换时不能接受旧账号认领键', switchedOwnerRejected);
const confirmQueuedSource = between('function confirmedQueuedSkillExecution', 'async function runSelectedSkill');
const confirmQueued = new Function(`${confirmQueuedSource}; return confirmedQueuedSkillExecution;`)();
const validQueued = { execution: { id: 17, project_id: 7, user_id: 101, skill_id: 'skill-a' } };
assert('只有同账号、项目、技能且有执行 ID 的入队结果才可确认',
  confirmQueued(validQueued, 7, 'skill-a', 101).id === 17);
for (const [label, body] of [
  ['缺执行 ID', { execution: null }],
  ['异账号', { execution: { ...validQueued.execution, user_id: 202 } }],
  ['异项目', { execution: { ...validQueued.execution, project_id: 8 } }],
  ['异技能', { execution: { ...validQueued.execution, skill_id: 'skill-b' } }],
]) {
  let retained = false;
  try { confirmQueued(body, 7, 'skill-a', 101); }
  catch (error) { retained = error.responseParseFailed === true; }
  assert(`${label}的 2xx 响应保持原付费键待核验`, retained);
}
assert('提交事件有同步 in-flight 锁',
  submit.includes('if (state.skillSubmitInFlight)')
  && submit.includes('state.skillSubmitInFlight = true')
  && submit.includes('state.skillSubmitInFlight = false'));
assert('每次付费提交携带幂等键',
  submit.includes('pendingIdempotencyRequest("skill-execution", payload)')
  && submit.includes('"X-Idempotency-Key": pendingRequest.key'));
assert('连接结果不确定时保留原键，明确响应后才清除',
  submit.includes('clearPendingIdempotencyRequest(pendingRequest)')
  && submit.includes('retainPendingIdempotencyRequest(error)')
  && submit.indexOf('confirmedQueuedSkillExecution(body,') < submit.indexOf('clearPendingIdempotencyRequest(pendingRequest)'));
assert('认领先于付费入队，服务端确认送达后才释放原键',
  submit.indexOf('execute-async/claim') < submit.indexOf('skillExecuteAsyncEndpoint')
  && submit.indexOf('confirmedQueuedSkillExecution(body,') < submit.indexOf('execute-async/seen')
  && submit.indexOf('execute-async/seen') < submit.indexOf('clearPendingIdempotencyRequest(pendingRequest)')
  && submit.includes('!claimReady || retainPendingIdempotencyRequest(error)'));
assert('异常回执清空局部 body，不能继续显示入队成功',
  submit.indexOf('} catch (error) {\n      body = null;') > submit.indexOf('confirmedQueuedSkillExecution(body,')
  && submit.indexOf('if (!body) return;') > submit.indexOf('body = null;'));
assert('账号切换后保留原任务键，旧任务不在新账号显示成功',
  submit.includes('const submittedTenantId = Number(state.session?.tenant?.id)')
  && submit.includes('Number(state.session?.user?.id) !== submittedUserId')
  && submit.includes('Number(state.session?.tenant?.id) !== submittedTenantId')
  && submit.indexOf('Number(state.session?.tenant?.id) !== submittedTenantId') < submit.indexOf('clearPendingIdempotencyRequest(pendingRequest)'));
assert('异步入队返回后刷新列表，并由列表恢复在途轮询',
  submit.includes('await loadSkillExecutions(submittedProjectId'));
assert('入队成功后的列表刷新失败不会误报提交失败或再次发起付费请求',
  submit.includes('任务已经成功入队，但状态列表刷新失败')
  && submit.includes('pollSkillExecution(execution.id')
  && (submit.match(/skillExecuteAsyncEndpoint/g) || []).length === 1);

const retry = between('async function apiWithSessionRetry', 'let reverifyPromise');
assert('鉴权重试复用原请求 options，因此幂等键保持不变',
  retry.includes('apiWithSessionRetry(path, options, true)'));

const reverifySource = between('let reverifyPromise', '// 错误编号统一出口');
assert('登录续期只合并同一波并发，终态会释放 Promise',
  reverifySource.includes('.finally(() =>')
  && /reverifyPromise\s*=\s*null/.test(reverifySource));

let sessionLoginCalls = 0;
const pendingSessionLogins = [];
const reverifyRuntime = new Function(
  'fetch', 'config', 'pickToken', 'persistSession', 'state', 'window', 'accountLoginUrl',
  `${reverifySource}; return { reverifyOnce };`,
)(
  () => {
    sessionLoginCalls += 1;
    const callNumber = sessionLoginCalls;
    return new Promise((resolveLogin) => {
      pendingSessionLogins.push(() => resolveLogin({
        ok: true,
        json: async () => ({ token: `fresh-token-${callNumber}`, user: { id: 1 }, tenant: { id: 10 } }),
      }));
    });
  },
  {},
  (payload) => payload.token,
  () => {},
  {},
  { location: { href: '' } },
  () => '/login',
);
const firstWaveA = reverifyRuntime.reverifyOnce();
const firstWaveB = reverifyRuntime.reverifyOnce();
assert('同一波并发 401 只调用一次 session-login', sessionLoginCalls === 1);
pendingSessionLogins.shift()();
await Promise.all([firstWaveA, firstWaveB]);
const secondWave = reverifyRuntime.reverifyOnce();
assert('以后再次出现独立 401 会重新调用 session-login', sessionLoginCalls === 2);
pendingSessionLogins.shift()();
await secondWave;

const durableKey = between('function idempotencyFingerprintSlot', 'async function apiWithSessionRetry');
assert('同一标签页会按请求内容复用未确认的付费请求键',
  durableKey.includes('previous.fingerprint !== fingerprintSlot')
  && durableKey.includes('sessionStorage.setItem(storageKey')
  && durableKey.includes('previousRaw !== null'));
assert('服务端 pending 与防重保护故障不会丢弃原键',
  durableKey.includes('idempotency_result_pending')
  && durableKey.includes('idempotency_protection_unavailable'));
assert('网关失败、超时限流和成功响应解析失败都保留原键',
  durableKey.includes('status >= 500')
  && durableKey.includes('status === 408')
  && durableKey.includes('status === 425')
  && durableKey.includes('status === 429')
  && durableKey.includes('responseParseFailed'));
assert('不同请求按不可逆指纹分槽，损坏记录不自动清理换键',
  durableKey.includes('idempotencyFingerprintSlot(fingerprint)')
  && durableKey.includes('原付费请求编号记录损坏')
  && !durableKey.includes('${namespace}${fingerprint}'));

const idempotencyRecords = new Map();
let storageWritable = true;
const fakeSessionStorage = {
  get length() { return idempotencyRecords.size; },
  key(index) { return Array.from(idempotencyRecords.keys())[index] ?? null; },
  getItem(key) { return idempotencyRecords.get(key) ?? null; },
  setItem(key, value) {
    if (!storageWritable) throw new Error('storage unavailable');
    idempotencyRecords.set(key, String(value));
  },
  removeItem(key) { idempotencyRecords.delete(key); },
};
let generatedKeys = 0;
const accountState = { session: { tenant: { id: 11 }, user: { id: 101 } } };
const idempotency = new Function(
  'sessionStorage', 'tenantSubdomain', 'newIdempotencyKey', 'state',
  `${durableKey}; return { pendingIdempotencyRequest, clearPendingIdempotencyRequest, retainPendingIdempotencyRequest };`,
)(fakeSessionStorage, () => 'tenant-a', () => `generated-${++generatedKeys}`, accountState);
const firstRequest = idempotency.pendingIdempotencyRequest('skill-execution', { topic: '客户秘密 A' });
const otherRequest = idempotency.pendingIdempotencyRequest('skill-execution', { topic: '客户秘密 B' });
const replayedFirst = idempotency.pendingIdempotencyRequest('skill-execution', { topic: '客户秘密 A' });
assert('提交不同内容不会覆盖前一笔未确认请求',
  firstRequest.key !== otherRequest.key
  && replayedFirst.key === firstRequest.key
  && idempotencyRecords.size === 2);
assert('sessionStorage 的 key 不含客户输入原文',
  Array.from(idempotencyRecords.keys()).every((key) => !key.includes('客户秘密')));
assert('sessionStorage 的 value 也不保存客户输入原文',
  Array.from(idempotencyRecords.values()).every((value) => !value.includes('客户秘密')));
accountState.session.user.id = 202;
const otherAccountRequest = idempotency.pendingIdempotencyRequest('skill-execution', { topic: '客户秘密 A' });
idempotency.clearPendingIdempotencyRequest(firstRequest);
assert('同租户不同账号使用独立付费键，不能清除上一账号的未决键',
  otherAccountRequest.key !== firstRequest.key
  && otherAccountRequest.storageKey !== firstRequest.storageKey
  && idempotencyRecords.has(firstRequest.storageKey) && generatedKeys === 3);
accountState.session.user.id = 101;
assert('返回原账号仍沿用原付费请求键',
  idempotency.pendingIdempotencyRequest('skill-execution', { topic: '客户秘密 A' }).key === firstRequest.key);
const oldStorageKey = firstRequest.storageKey.replace(':11:101:', ':');
idempotencyRecords.set(oldStorageKey, JSON.stringify({ key: 'legacy-key', fingerprint: JSON.parse(idempotencyRecords.get(firstRequest.storageKey)).fingerprint }));
let oldKeyBlocked = false;
try { idempotency.pendingIdempotencyRequest('skill-execution', { topic: '客户秘密 A' }); }
catch (error) { oldKeyBlocked = error.message.includes('缺少账号归属'); }
assert('旧版无账号归属的未决键阻断同内容新发送', oldKeyBlocked && idempotencyRecords.has(oldStorageKey));
idempotencyRecords.delete(oldStorageKey);
const legacyRecord = JSON.parse(idempotencyRecords.get(otherRequest.storageKey));
delete legacyRecord.owner_user_id;
idempotencyRecords.set(otherRequest.storageKey, JSON.stringify(legacyRecord));
let legacyBlocked = false;
try { idempotency.pendingIdempotencyRequest('skill-execution', { topic: '客户秘密 B' }); }
catch (error) { legacyBlocked = error.message.includes('本次未发送'); }
assert('旧版未记录账号的未决键保持待核验，不生成新付费键',
  legacyBlocked && idempotencyRecords.has(otherRequest.storageKey) && generatedKeys === 3);
const scopedApi = new Function('state', 'fetch', 'authHeaders', 'AbortSignal', 'reverifyOnce',
  `${between('async function apiWithSessionRetry', '// 401 自愈')}; return apiWithSessionRetry;`,
)(accountState, async () => {
  accountState.session.user.id = 202;
  return { ok: true, headers: { get: () => null }, text: async () => '{"private":"old account"}' };
}, (headers) => headers, { timeout: () => undefined }, async () => {});
let staleResponseBlocked = false;
try { await scopedApi('/api/v1/skills/execute', { method: 'POST' }, false); }
catch (error) { staleResponseBlocked = error.identityChanged === true; }
assert('请求在途时切换账号，旧账号成功响应也不交付给新账号', staleResponseBlocked);
accountState.session.user.id = 101;
assert('500/502/429 与损坏响应保留键，明确 422 才释放',
  idempotency.retainPendingIdempotencyRequest({ status: 500 })
  && idempotency.retainPendingIdempotencyRequest({ status: 502 })
  && idempotency.retainPendingIdempotencyRequest({ status: 429 })
  && idempotency.retainPendingIdempotencyRequest({ status: 202, responseParseFailed: true })
  && !idempotency.retainPendingIdempotencyRequest({ status: 422 }));
const expiredRecord = JSON.parse(idempotencyRecords.get(firstRequest.storageKey));
expiredRecord.created_at = Date.now() - (31 * 60 * 1000);
idempotencyRecords.set(firstRequest.storageKey, JSON.stringify(expiredRecord));
idempotency.pendingIdempotencyRequest('skill-execution', { topic: '客户秘密 C' });
assert('超过旧 TTL 后未决请求仍复用原键，不能凭时间发新收费尝试',
  idempotencyRecords.has(firstRequest.storageKey)
  && idempotency.pendingIdempotencyRequest('skill-execution', { topic: '客户秘密 A' }).key === firstRequest.key);
storageWritable = false;
let storageBlocked = false;
try { idempotency.pendingIdempotencyRequest('skill-execution', { topic: '客户秘密 D' }); }
catch (error) { storageBlocked = error.message.includes('本次未发送'); }
assert('无法持久保存请求键时拒绝付费发送', storageBlocked);
storageWritable = true;
idempotencyRecords.set(firstRequest.storageKey, '{broken');
let damagedBlocked = false;
try { idempotency.pendingIdempotencyRequest('skill-execution', { topic: '客户秘密 A' }); }
catch (error) { damagedBlocked = error.message.includes('记录损坏'); }
assert('已存在但损坏的未决记录不得自动删除并发新请求',
  damagedBlocked && idempotencyRecords.get(firstRequest.storageKey) === '{broken');

const load = between('async function loadSkillExecutions', 'function setSkillBriefMessage');
assert('刷新列表后恢复在途执行轮询', load.includes('resumeSkillExecutionPolls(projectId)'));
assert('刷新时复用列表中的完整 payload，不为选中任务并发请求详情和状态',
  load.includes('renderSkillExecutionResult(target)')
  && !load.includes('await selectSkillExecution('));
assert('旧项目列表响应不会覆盖当前项目',
  // 2026-09-21 §7 第九刀：请求序号随技能工作区搬进了 geo-entry-skills.js，为了跨文件共享
  // 改成主文件声明的对象（`skillExecutionsState.request`），不再是一个裸变量。
  load.includes('requestId !== skillExecutionsState.request')
  && load.includes('Number(state.selectedProject?.id) !== Number(projectId)'));

const poll = between('function pollSkillExecution', 'function stopPollingExecution');
assert('轮询等待上次请求结束后再用 setTimeout 调度',
  poll.includes('window.setTimeout(tick, delay)') && !poll.includes('window.setInterval('));
assert('轮询响应同时校验 poller 身份与项目',
  poll.includes('executionPollers.get(executionId) === poller')
  && poll.includes('Number(state.selectedProject?.id) === startedProjectId'));
assert('轮询不再强制抢占用户选择', !poll.includes('state.selectedExecutionId = execution.id'));
assert('只重绘当前选中且确实变化的结果',
  poll.includes('if (selected && changed) renderSkillExecutionResult(execution)'));
assert('后台未选中的任务查询失败时不覆盖当前结果提示',
  poll.includes('const selected = Number(state.selectedExecutionId) === Number(executionId)')
  && /catch \(error\)[\s\S]*?if \(selected\) \{[\s\S]*?setMessage\(elements\.skillMessage/.test(poll));
assert('状态查询瞬断会限速重试，并明确后台任务仍继续',
  poll.includes('Math.min(30000')
  && poll.includes('schedule(retryDelay)')
  && poll.includes('后台任务仍会继续')
  && poll.includes('请勿重复提交'));
assert('只有明确 404 会停止这条状态跟踪',
  /Number\(error\?\.status\) === 404[\s\S]*?stopPollingExecution\(executionId\)/.test(poll));

const projectSwitch = between('async function selectProject', 'function upsertProject');
// 2026-09-21 §7 第十一刀：项目簇搬进 geo-entry-projects.js 后，它对技能侧函数的调用走运行期
// `globalThis.GEO?.stopAllExecutionPollers?.()`（两个文件互相依赖，靠加载顺序排不出拓扑序）。
// 所以这里同时认直接调用与可选链调用两种形态 —— 要守的是"切项目时确实注销了 poller"。
assert('切换项目会注销旧执行 poller', /stopAllExecutionPollers\??\.?\(/.test(projectSwitch));
assert('快速切换项目时丢弃较早请求的迟到响应',
  projectSwitch.includes('const requestId = ++projectSelectionRequest')
  && projectSwitch.includes('requestId !== projectSelectionRequest'));
const changed = between('function skillExecutionStateChanged', 'const PENDING_STRIP_LIMIT');
assert('调用数和恢复状态变化会刷新用量行',
  changed.includes('billingChanged') && changed.includes('model_call_count') && changed.includes('recovery_stage'));
const result = between('function renderSkillExecutionResult', 'function skillQualityWarning');
assert('执行结果展示调用次数、tokens 和恢复状态',
  result.includes('skillExecutionBillingLine(execution)') && result.includes('usage.model_call_count')
  && result.includes('execution.token_usage') && result.includes('usage.recovery_stage'));
console.log(`\n${checks - failures}/${checks} 项通过`);
if (failures) process.exit(1);
