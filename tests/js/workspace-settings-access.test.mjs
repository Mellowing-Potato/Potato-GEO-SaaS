import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const source = readFileSync(new URL('../../public/geo-entry-workspace-settings.js', import.meta.url), 'utf8');
const internalDetails = /team:manage|GET\s+\/api|\/api\/v1|权限清单|我的权限/;

function setup(permissionResponse, {saveError} = {}) {
  const calls = [];
  const nodes = Object.fromEntries(['workspaceName', 'saveWorkspaceName', 'workspaceNameHelp', 'workspaceNameMessage', 'workspaceNameForm']
    .map(id => [id, {value: '', textContent: '', disabled: true, addEventListener(name, listener) {this[name] = listener;}}]));
  const state = {session: {tenant: {name: 'Original fixture workspace'}}};
  let load;
  const GEO = {
    state,
    api: async (path, options) => {
      calls.push({path, options});
      if (path === '/api/v1/me/permissions') return permissionResponse();
      if (path === '/api/v1/me') return {tenant: {name: 'Original fixture workspace'}};
      if (path === '/api/v1/workspace') {
        if (saveError) throw saveError;
        return {tenant: {name: JSON.parse(options.body).name}};
      }
      throw new Error('Unexpected fixture endpoint');
    },
    setMessage(node, message) {node.textContent = message;},
    registerViewLoader(name, loader) {assert.equal(name, 'personal-settings'); load = loader;},
  };
  vm.runInNewContext(source, {window: {GEO}, document: {getElementById: id => nodes[id] || null}, console});
  return {calls, nodes, state, load: () => load(), submit: () => nodes.workspaceNameForm.submit({preventDefault() {}})};
}

function assertNoInternalDetails(nodes) {
  for (const node of Object.values(nodes)) assert.doesNotMatch(node.textContent, internalDetails);
}

test('workspace rename still checks authorization after the permission-list DOM is removed', async () => {
  const page = setup(() => ({permissions: ['team:manage']}));
  await page.load();
  assert.equal(page.calls.filter(call => call.path === '/api/v1/me/permissions').length, 1);
  assert.equal(page.nodes.workspaceName.disabled, false);
  assert.equal(page.nodes.saveWorkspaceName.disabled, false);
  page.nodes.workspaceName.value = 'Renamed fixture workspace';
  await page.submit();
  const writes = page.calls.filter(call => call.options?.method === 'PATCH');
  assert.equal(writes.length, 1);
  assert.equal(writes[0].path, '/api/v1/workspace');
  assert.deepEqual(JSON.parse(writes[0].options.body), {name: 'Renamed fixture workspace'});
  assert.equal(page.state.session.tenant.name, 'Renamed fixture workspace');
  assertNoInternalDetails(page.nodes);
});

test('a user without rename authorization cannot submit a write or see permission codes', async () => {
  const page = setup(() => ({permissions: ['project:view']}));
  await page.load();
  assert.equal(page.nodes.workspaceName.value, 'Original fixture workspace');
  assert.equal(page.nodes.workspaceName.disabled, true);
  assert.equal(page.nodes.saveWorkspaceName.disabled, true);
  page.nodes.workspaceName.value = 'Forbidden fixture rename';
  await page.submit();
  assert.equal(page.calls.filter(call => call.options?.method === 'PATCH').length, 0);
  assert.match(page.nodes.workspaceNameMessage.textContent, /不能修改/);
  assertNoInternalDetails(page.nodes);
});

test('a failed permission check stays read only and hides technical server messages', async () => {
  const page = setup(() => {throw new Error('GET /api/v1/me/permissions requires team:manage');});
  await page.load();
  assert.equal(page.nodes.workspaceName.value, 'Original fixture workspace');
  assert.equal(page.nodes.workspaceName.disabled, true);
  assert.equal(page.nodes.saveWorkspaceName.disabled, true);
  assert.match(page.nodes.workspaceNameHelp.textContent, /刷新|重试/);
  await page.submit();
  assert.equal(page.calls.filter(call => call.options?.method === 'PATCH').length, 0);
  assertNoInternalDetails(page.nodes);
});

test('a new permission check immediately closes a previously enabled rename form', async () => {
  let reject;
  let count = 0;
  const page = setup(() => ++count === 1 ? {permissions: ['team:manage']} : new Promise((_resolve, fail) => {reject = fail;}));
  await page.load();
  assert.equal(page.nodes.saveWorkspaceName.disabled, false);
  const recheck = page.load();
  assert.equal(page.nodes.workspaceName.disabled, true);
  assert.equal(page.nodes.saveWorkspaceName.disabled, true);
  await page.submit();
  assert.equal(page.calls.filter(call => call.options?.method === 'PATCH').length, 0);
  reject(new Error('Synthetic permission lookup failure'));
  await recheck;
  assert.equal(page.nodes.saveWorkspaceName.disabled, true);
  assertNoInternalDetails(page.nodes);
});

test('a late allowed response cannot override the latest read only decision', async () => {
  let resolve;
  let count = 0;
  const page = setup(() => ++count === 1 ? new Promise(done => {resolve = done;}) : {permissions: []});
  const olderLoad = page.load();
  await page.load();
  assert.equal(page.nodes.saveWorkspaceName.disabled, true);
  resolve({permissions: ['team:manage']});
  await olderLoad;
  assert.equal(page.nodes.workspaceName.disabled, true);
  assert.equal(page.nodes.saveWorkspaceName.disabled, true);
  await page.submit();
  assert.equal(page.calls.filter(call => call.options?.method === 'PATCH').length, 0);
  assertNoInternalDetails(page.nodes);
});

test('server rejection does not expose API paths or permission codes in the rename error', async () => {
  const page = setup(() => ({permissions: ['team:manage']}), {saveError: new Error('PATCH /api/v1/workspace requires team:manage')});
  await page.load();
  page.nodes.workspaceName.value = 'Unchanged fixture workspace';
  await page.submit();
  assert.equal(page.calls.filter(call => call.options?.method === 'PATCH').length, 1);
  assert.equal(page.state.session.tenant.name, 'Original fixture workspace');
  assert.match(page.nodes.workspaceNameMessage.textContent, /未能保存|失败/);
  assertNoInternalDetails(page.nodes);
});
