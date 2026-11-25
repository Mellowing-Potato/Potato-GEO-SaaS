import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const entry = readFileSync(process.env.MAGPIE_ENTRY_FIXTURE || new URL('../../public/geo-entry-magpie.js', import.meta.url), 'utf8');
const backend = '/api/v1/ai-keys/magpie';
const gateway = { alias: 'fixture-gateway', base_url: 'http://gateway.example.com:3425/v1', auth_mode: 'gateway_key', revision: 'fixture-gateway-v1' };
const local = { alias: 'fixture-loopback', base_url: 'http://127.0.0.1:3425/v1', auth_mode: 'loopback', revision: 'fixture-loopback-v1' };
const longModel = 'example/' + 'magpie-model-'.repeat(12) + 'v2';
const catalog = [
  { id: longModel, display_name: '路由组：长名称', image_input: null },
  { id: 'example/text-only', image_input: false },
  { id: 'example/vision', image_input: true },
];

class Element {
  constructor() {
    this.listeners = new Map();
    this.dataset = {};
    this.value = '';
  }

  addEventListener(name, listener) { this.listeners.set(name, listener); }
  trigger(name) { return this.listeners.get(name)?.({ preventDefault() {} }); }
  click() { if (!this.hidden && !this.disabled) return this.trigger('click'); }
  replaceChildren(...children) { this.children = children; }
  setAttribute(name, value) { this[name] = value; }
}

function sectionFor(slot, desktop = false) {
  const section = new Element();
  section.dataset.magpieSlot = slot;
  const elements = Object.fromEntries(['target_alias', 'api_key', 'model'].map(name => [name, new Element()]));
  const nodes = Object.fromEntries(
    ['active-model-source', 'use-direct', 'magpie-authorization', 'magpie-endpoint', 'magpie-key', 'magpie-key-status', 'magpie-model-status', 'magpie-message']
      .map(name => [name, new Element()]),
  );
  if (desktop) {
    nodes['magpie-local-connect'] = new Element();
    nodes['magpie-local-connect'].disabled = true;
  }
  const actions = Object.fromEntries(['save', 'directory', 'activate', 'toggle'].map(action => {
    const button = new Element();
    button.dataset.magpieAction = action;
    return [action, button];
  }));
  const form = Object.assign(new Element(), {
    elements,
    querySelectorAll: () => Object.values(actions),
    reportValidity: () => !elements.api_key.required || Boolean(elements.api_key.value),
  });
  section.querySelector = selector => selector === '.model-magpie-form' ? form : nodes[selector.slice(6, -1)];
  return { section, form, nodes, actions, fields: elements };
}

const text = sectionFor('text');
const vision = sectionFor('vision');
const reload = new Element();
const state = { session: { tenant: { id: 21 }, user: { id: 32 } } };
let load;
const pending = [];
const calls = [];
const GEO = {
  state,
  elements: { aikeyList: { querySelectorAll: () => [text.section, vision.section] }, reloadAiKeysButton: reload },
  setMessage: (node, value, tone) => Object.assign(node, { textContent: value, tone }),
  validationMessage: error => error.message,
  registerViewLoader: (view, handler) => {
    assert.equal(view, 'aikeys');
    load = handler;
  },
  api: (path, options) => {
    const request = { path, method: options?.method ?? 'GET', body: options ? JSON.parse(options.body) : null };
    calls.push(request);
    return new Promise((resolve, reject) => pending.push({ ...request, resolve, reject }));
  },
};
new Function('window', 'Option', entry)({ GEO }, class {
  constructor(text, value) { Object.assign(this, { text, value }); }
});

const resolve = (path, result, method = 'GET') => {
  const request = pending.shift();
  assert.equal(request.path, backend + path);
  assert.equal(request.method, method);
  request.resolve(result);
};
const reject = (message) => pending.shift().reject(new Error(message));
const tick = () => new Promise(setImmediate);
const legacy = { source_type: 'legacy', profile_id: null };
const textProfile = {
  id: 61, slot: 'text', target_alias: gateway.alias, base_url: gateway.base_url, auth_mode: gateway.auth_mode,
  model: null, enabled: true, revision: 1, target_revision: gateway.revision, key_preview: 'sk-••••test',
};
const visionProfile = {
  ...textProfile, id: 62, slot: 'vision', target_alias: local.alias, base_url: local.base_url,
  auth_mode: local.auth_mode, target_revision: local.revision, key_preview: null, model: 'example/vision',
};

const loading = load();
resolve('', { targets: [gateway, local], profiles: [visionProfile], bindings: { text: legacy, vision: legacy } });
await loading;
assert.match(text.nodes['active-model-source'].textContent, /第三方直连/);
assert.equal(text.fields.target_alias.value, gateway.alias);
assert(text.fields.api_key.required);
assert(text.actions.directory.disabled);
assert(vision.nodes['magpie-key'].hidden);

text.fields.api_key.value = 'sk-test-magpie-private';
text.fields.api_key.trigger('input');
text.form.trigger('submit');
assert.equal(text.form['aria-busy'], 'true');
assert(text.actions.save.disabled);
text.form.trigger('submit');
assert.equal(pending.length, 1, 'Saving must guard against duplicate form submissions.');
assert.deepEqual(calls.at(-1).body, { slot: 'text', target_alias: gateway.alias, api_key: 'sk-test-magpie-private' });
resolve('/profiles', textProfile, 'POST');
await tick();
assert.equal(text.fields.api_key.value, '');
assert.match(text.nodes['magpie-key-status'].textContent, /sk-••••test/);
assert(!text.nodes['magpie-key-status'].textContent.includes('sk-test-magpie-private'));
assert.match(text.nodes['active-model-source'].textContent, /第三方直连/);
assert(!text.actions.directory.disabled);

const staleDirectory = text.actions.directory.click();
assert.match(text.nodes['magpie-message'].textContent, /正在检测/);
text.fields.target_alias.value = local.alias;
text.fields.target_alias.trigger('change');
resolve('/profiles/61/directory', { version: 'fixture-stale', models: catalog, revision: 1 }, 'POST');
await staleDirectory;
assert.equal(text.fields.model.children.length, 1);
assert(!text.nodes['magpie-message'].textContent.includes('fixture-stale'));

text.fields.target_alias.value = gateway.alias;
text.fields.target_alias.trigger('change');
const stopped = text.actions.directory.click();
reject('Magpie 服务不可达。');
await stopped;
assert.match(text.nodes['magpie-message'].textContent, /服务不可达/);
assert.equal(vision.nodes['magpie-message'].textContent, '');

const wrongKey = text.actions.directory.click();
reject('Magpie Gateway Key 无效。');
await wrongKey;
assert.match(text.nodes['magpie-message'].textContent, /Key 无效/);
const empty = text.actions.directory.click();
resolve('/profiles/61/directory', { version: '0.0.0-fixture', models: [], revision: 1 }, 'POST');
await empty;
assert.match(text.nodes['magpie-model-status'].textContent, /目录为空/);
assert(text.fields.model.disabled);

const textDirectory = text.actions.directory.click();
const visionDirectory = vision.actions.directory.click();
resolve('/profiles/61/directory', { version: '0.0.0-fixture', models: catalog, revision: 1 }, 'POST');
resolve('/profiles/62/directory', { version: '0.0.0-fixture', models: catalog, revision: 1 }, 'POST');
await Promise.all([textDirectory, visionDirectory]);
assert.equal(text.fields.model.children[1].value, longModel);
assert(vision.fields.model.children[1].disabled);
assert.match(vision.fields.model.children[1].text, /图片能力未确认/);
assert(vision.fields.model.children[2].disabled);
assert.match(vision.fields.model.children[2].text, /不支持图片/);
assert(!vision.fields.model.children[3].disabled);

text.fields.model.value = longModel;
text.fields.model.trigger('change');
assert(text.nodes['magpie-model-status'].textContent.includes(longModel));
text.form.trigger('submit');
assert.deepEqual(calls.at(-1).body, { target_alias: gateway.alias, revision: 1, model: longModel });
resolve('/profiles/61', { ...textProfile, revision: 2, model: longModel }, 'PATCH');
await tick();
assert(calls.every(call => !call.path.includes('/bindings/')), 'Saving a profile must never select its source.');

const activatingText = text.actions.activate.click();
assert.deepEqual(calls.at(-1).body, { source_type: 'profile', profile_id: 61, revision: 2 });
resolve('/bindings/text', { source_type: 'profile', profile_id: 61 }, 'PUT');
await activatingText;
assert.match(text.nodes['active-model-source'].textContent, /使用 Magpie/);
assert.match(vision.nodes['active-model-source'].textContent, /第三方直连/);

const activatingVision = vision.actions.activate.click();
resolve('/bindings/vision', { source_type: 'profile', profile_id: 62 }, 'PUT');
await activatingVision;
const disable = vision.actions.toggle.click();
assert.deepEqual(calls.at(-1).body, { enabled: false, revision: 1 });
resolve('/profiles/62', { ...visionProfile, enabled: false, revision: 2 }, 'PATCH');
await disable;
assert.match(vision.nodes['active-model-source'].textContent, /Magpie.*不可用/);
assert.match(text.nodes['active-model-source'].textContent, /使用 Magpie/);
const direct = text.nodes['use-direct'].click();
assert.deepEqual(calls.at(-1).body, { source_type: 'legacy' });
resolve('/bindings/text', legacy, 'PUT');
await direct;
assert.match(text.nodes['active-model-source'].textContent, /第三方直连/);

text.fields.api_key.value = 'sk-test-new-setting';
text.fields.api_key.trigger('input');
text.form.trigger('submit');
reject('无效凭据 sk-test-new-setting。');
await tick();
assert.equal(text.fields.api_key.value, 'sk-test-new-setting', 'Failed save must preserve typed input.');
assert(!text.nodes['magpie-message'].textContent.includes('sk-test-new-setting'));
text.fields.target_alias.value = local.alias;
text.fields.target_alias.trigger('change');
assert.equal(text.fields.api_key.value, '', 'Changing the target clears credentials entered for a different target.');

const revisedAuthorization = load();
resolve('', {
  targets: [{ ...gateway, revision: 'fixture-gateway-v2' }, local],
  profiles: [{ ...textProfile, revision: 2, model: longModel }],
  bindings: { text: { source_type: 'profile', profile_id: 61 }, vision: legacy },
});
await revisedAuthorization;
assert.match(text.nodes['active-model-source'].textContent, /连接不可用/);
assert.match(text.nodes['magpie-authorization'].textContent, /授权已变更/);
text.fields.target_alias.value = gateway.alias;
text.fields.target_alias.trigger('change');
assert(text.actions.directory.disabled);
assert(text.fields.api_key.required);
assert.equal(text.fields.model.value, longModel, 'A deployment revision change keeps the existing model selection.');
assert.doesNotMatch(text.nodes['magpie-key-status'].textContent, /sk-••••test/);
text.form.trigger('submit');
assert.equal(pending.length, 0, 'A changed target revision must request a fresh Gateway Key.');
text.fields.api_key.value = 'sk-test-authorized-revision';
text.fields.api_key.trigger('input');
text.form.trigger('submit');
assert.deepEqual(calls.at(-1).body, { target_alias: gateway.alias, revision: 2, api_key: 'sk-test-authorized-revision' });
resolve('/profiles/61', { ...textProfile, revision: 3, model: longModel, target_revision: 'fixture-gateway-v2' }, 'PATCH');
await tick();
assert(!text.actions.directory.disabled);
assert.match(text.nodes['active-model-source'].textContent, /使用 Magpie/);

const downgraded = load();
pending.shift().reject(Object.assign(new Error('Forbidden'), { status: 403 }));
await downgraded;
assert(text.form.hidden);
assert.equal(text.nodes['magpie-endpoint'].textContent, '');
assert.equal(text.nodes['active-model-source'].textContent, '');
assert.equal(text.fields.model.children.length, 1);
assert.match(text.nodes['magpie-message'].textContent, /当前角色不能管理/);

const oldWorkspace = reload.trigger('click');
state.session.tenant = { id: 22 };
const newWorkspace = load();
resolve('', { targets: [gateway], profiles: [textProfile] });
resolve('', { targets: [], profiles: [] });
await Promise.all([oldWorkspace, newWorkspace]);
assert(text.form.hidden && vision.form.hidden);
assert.match(text.nodes['magpie-authorization'].textContent, /未获得/);
assert.equal(text.fields.target_alias.children.length, 0); // hidden form is emptied on workspace switch
assert.equal(text.fields.target_alias.value, '');
assert(calls.every(call => !call.path.includes('/test')), 'Directory checks must not send a model test.');
assert.equal(pending.length, 0);

function desktopFixture() {
  const text = sectionFor('text', true), vision = sectionFor('vision', true);
  const state = { session: { tenant: { id: 41 }, user: { id: 51 } } };
  const calls = [], pending = [];
  let load;
  const GEO = {
    state,
    elements: { aikeyList: { querySelectorAll: () => [text.section, vision.section] } },
    setMessage: (node, value, tone) => Object.assign(node, { textContent: value, tone }),
    validationMessage: error => error.message,
    registerViewLoader: (_view, handler) => { load = handler; },
    api: (path, options) => {
      const request = { path, method: options?.method || 'GET', body: options?.body ? JSON.parse(options.body) : null };
      calls.push(request);
      return new Promise((resolve, reject) => pending.push({ ...request, resolve, reject }));
    },
  };
  new Function('window', 'Option', entry)({ GEO }, class { constructor(text, value) { Object.assign(this, { text, value }); } });
  const reply = (path, result, method = 'GET') => {
    const request = pending.shift();
    assert(request, `Expected a ${method} ${backend + path} request.`);
    assert.equal(request.path, backend + path);
    assert.equal(request.method, method);
    request.resolve(result);
  };
  return { text, vision, state, calls, pending, reply, load: () => load() };
}

const localListing = (profiles = [], additionalTargets = []) => ({
  targets: [...additionalTargets, local], profiles, bindings: { text: legacy, vision: legacy },
  local_connect_available: true, local_target_alias: local.alias, local_target: local,
  service: { name: 'magpie', version: '0.0.0-fixture' },
});
const localTextProfile = { ...visionProfile, id: 81, slot: 'text', model: null };
async function hydrate(fixture, body = { targets: [], profiles: [], bindings: { text: legacy, vision: legacy }, local_connect_available: true }) {
  const loading = fixture.load();
  fixture.reply('', body);
  await loading;
}

const desktop = desktopFixture();
await hydrate(desktop);
assert.equal(desktop.calls.length, 1, 'Opening model settings must not probe or authorize Magpie.');
const connecting = desktop.text.nodes['magpie-local-connect'].click();
assert.equal(desktop.calls.at(-1).path, backend + '/local-connect', 'Clicking the local connection button must request authorization without requiring a terminal command.');
assert.match(desktop.text.nodes['magpie-authorization'].textContent, /先启动 Magpie/);
assert.doesNotMatch(desktop.text.nodes['magpie-authorization'].textContent, /联系部署者/);
assert.deepEqual(desktop.calls.at(-1).body, { consent: true });
assert.equal(desktop.calls.at(-1).method, 'POST');
desktop.text.nodes['magpie-local-connect'].trigger('click');
desktop.vision.nodes['magpie-local-connect'].trigger('click');
desktop.text.form.trigger('submit');
await desktop.load();
assert.equal(desktop.pending.length, 1, 'Both connect buttons, form actions and reload must share the pending authorization guard.');
assert(desktop.text.nodes['magpie-local-connect'].disabled && desktop.vision.nodes['magpie-local-connect'].disabled);
desktop.reply('/local-connect', localListing(), 'POST');
await tick();
assert.equal(desktop.pending.length, 1);
assert.deepEqual(desktop.calls.at(-1).body, { slot: 'text', target_alias: local.alias, model: null });
desktop.reply('/profiles', localTextProfile, 'POST');
await tick();
assert(desktop.vision.nodes['magpie-local-connect'].disabled, 'The shared guard must remain held while reading the catalog.');
desktop.vision.nodes['magpie-local-connect'].trigger('click');
assert.equal(desktop.pending.length, 1);
desktop.reply('/profiles/81/directory', { version: '0.0.0-fixture', models: catalog, revision: 1 }, 'POST');
await connecting;
assert.equal(desktop.text.fields.model.children.length, 4, 'One click must populate model options for the selected purpose.');
assert.equal(desktop.text.fields.model.value, '', 'Reading the catalog must not select a model.');
assert.equal(desktop.vision.fields.model.children.length, 1, 'Connecting text must not create or read a vision profile.');
assert.match(desktop.text.nodes['magpie-message'].textContent, /Magpie 0\.0\.0-fixture.*目录包含 3/);
assert.match(desktop.text.nodes['magpie-authorization'].textContent, /已连接本机 Magpie.*选择.*保存模型/);
assert.doesNotMatch(desktop.text.nodes['magpie-authorization'].textContent, /保存.*连接.*读取目录/);
assert.doesNotMatch(desktop.text.nodes['magpie-message'].textContent, /账号.*可用|登录成功/);
assert.match(desktop.text.nodes['active-model-source'].textContent, /第三方直连/);
assert(desktop.calls.every(call => !/\/bindings\/|\/test|chat\/completions/.test(call.path)), 'Local connection must not activate a source or perform inference.');
assert(!desktop.text.nodes['magpie-local-connect'].disabled && !desktop.vision.nodes['magpie-local-connect'].disabled);

const savedLocal = desktopFixture();
const selectedLocalProfile = { ...localTextProfile, model: longModel };
await hydrate(savedLocal, localListing([selectedLocalProfile]));
const rereading = savedLocal.text.nodes['magpie-local-connect'].click();
savedLocal.reply('/local-connect', localListing([selectedLocalProfile]), 'POST');
await tick();
savedLocal.reply('/profiles/81/directory', { version: '0.0.0-fixture', models: catalog, revision: 1 }, 'POST');
await rereading;
assert.equal(savedLocal.text.fields.model.value, longModel);
assert(savedLocal.calls.every(call => call.path !== backend + '/profiles'), 'An existing local profile must not be recreated or overwritten.');

const savedGateway = desktopFixture();
const gatewayProfile = { ...textProfile, model: longModel };
await hydrate(savedGateway, { targets: [gateway], profiles: [gatewayProfile], bindings: { text: legacy }, local_connect_available: true });
const preserveGateway = savedGateway.text.nodes['magpie-local-connect'].click();
savedGateway.reply('/local-connect', localListing([gatewayProfile], [gateway]), 'POST');
await preserveGateway;
assert.equal(savedGateway.pending.length, 0);
assert.equal(savedGateway.text.fields.target_alias.value, gateway.alias);
assert.equal(savedGateway.text.fields.model.value, longModel);
assert.match(savedGateway.text.nodes['magpie-message'].textContent, /保留.*现有/);
assert.equal(savedGateway.calls.filter(call => call.method !== 'GET').length, 1, 'A different saved target must never be replaced automatically.');

const draft = desktopFixture();
await hydrate(draft);
draft.text.fields.api_key.value = 'sk-test-unsaved-magpie-key';
draft.text.fields.api_key.trigger('input');
const preserveDraft = draft.text.nodes['magpie-local-connect'].click();
draft.reply('/local-connect', localListing(), 'POST');
await preserveDraft;
assert.equal(draft.text.fields.api_key.value, 'sk-test-unsaved-magpie-key');
assert.equal(draft.pending.length, 0);
assert.match(draft.text.nodes['magpie-message'].textContent, /保留/);
assert(!draft.text.nodes['magpie-message'].textContent.includes('sk-test-unsaved-magpie-key'));

const timedOut = desktopFixture();
await hydrate(timedOut);
const unknownConnection = timedOut.text.nodes['magpie-local-connect'].click();
timedOut.pending.shift().reject(new Error('请求超时，连接结果未确认。'));
await unknownConnection;
await tick();
assert.equal(timedOut.calls.filter(call => call.method === 'POST').length, 1, 'A failed or unknown authorization must never auto-retry.');
assert.equal(timedOut.pending.length, 0);
assert.match(timedOut.text.nodes['magpie-message'].textContent, /启动.*刷新/);

const wrongService = desktopFixture();
await hydrate(wrongService);
const wrongIdentity = wrongService.text.nodes['magpie-local-connect'].click();
wrongService.reply('/local-connect', { ...localListing(), service: { name: 'other-service', version: '0.0.0-fixture' } }, 'POST');
await wrongIdentity;
assert.equal(wrongService.pending.length, 0, 'Unrecognized service metadata must not create a connection profile or read models.');
assert.match(wrongService.text.nodes['magpie-message'].textContent, /未能确认.*服务身份/);
assert.doesNotMatch(wrongService.text.nodes['magpie-message'].textContent, /检测到 Magpie/);

const moved = desktopFixture();
await hydrate(moved);
const oldConnection = moved.text.nodes['magpie-local-connect'].click();
moved.state.session.tenant.id = 42;
const newSettings = moved.load();
moved.reply('/local-connect', localListing(), 'POST');
await oldConnection;
moved.reply('', { targets: [], profiles: [], local_connect_available: true });
await newSettings;
assert.equal(moved.pending.length, 0, 'A late authorization from another workspace must not create a profile.');
assert(moved.text.form.hidden && moved.vision.form.hidden);
assert(!moved.text.nodes['magpie-message'].textContent.includes('0.0.0-fixture'));

const delayedProfile = desktopFixture();
await hydrate(delayedProfile);
const oldProfileConnection = delayedProfile.text.nodes['magpie-local-connect'].click();
delayedProfile.reply('/local-connect', localListing(), 'POST');
await tick();
delayedProfile.state.session.tenant.id = 43;
const currentSettings = delayedProfile.load();
delayedProfile.reply('/profiles', localTextProfile, 'POST');
await oldProfileConnection;
delayedProfile.reply('', { targets: [], profiles: [], local_connect_available: true });
await currentSettings;
assert(delayedProfile.calls.every(call => !call.path.endsWith('/directory')), 'A late profile response must not start a catalog request for a previous workspace.');
assert.equal(delayedProfile.text.fields.model.children.length, 1);

console.log('Magpie settings: sources, safe credentials, dynamic capabilities, local one-click catalogs, draft preservation, shared guards, errors and stale replies passed.');
