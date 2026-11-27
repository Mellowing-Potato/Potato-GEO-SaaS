import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const read = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const template = read('resources/views/welcome.blade.php');
const scripts = [...template.matchAll(/<script src="\/(geo-[^?" ]+\.js)\?[^>]+defer><\/script>/g)].map(match => match[1]);
assert(scripts.indexOf('geo-entry.js') < scripts.indexOf('geo-entry-projects.js'));
assert(scripts.indexOf('geo-entry-projects.js') < scripts.indexOf('geo-entry-management.js'));
const sources = new Map(scripts.map(name => [name, read(`public/${name}`)]));
const tick = () => new Promise(resolve => setImmediate(resolve));
const deferred = () => { let resolve; const promise = new Promise(yes => { resolve = yes; }); return { promise, resolve }; };

class Events {
  listeners = new Map();
  addEventListener(type, fn, options) {
    const list = this.listeners.get(type) || [];
    list.push({ fn, once: options?.once }); this.listeners.set(type, list);
  }
  removeEventListener(type, fn) { this.listeners.set(type, (this.listeners.get(type) || []).filter(item => item.fn !== fn)); }
  emit(type, extra = {}) {
    const event = { type, target: this, preventDefault() {}, ...extra };
    return Promise.all((this.listeners.get(type) || []).slice().map(item => {
      if (item.once) this.removeEventListener(type, item.fn);
      return item.fn(event);
    }));
  }
}
class Node extends Events {
  constructor(id = '') {
    super(); this.id = id; this.dataset = {}; this.style = {}; this.hidden = false; this.disabled = false;
    this.value = ''; this.textContent = ''; this.innerHTML = ''; this.children = []; this.attributes = new Map(); this.fields = new Map();
    const classes = new Set();
    this.classList = { add: (...names) => names.forEach(name => classes.add(name)), remove: (...names) => names.forEach(name => classes.delete(name)), contains: name => classes.has(name), toggle(name, force) { const on = force ?? !classes.has(name); if (on) classes.add(name); else classes.delete(name); return on; } };
    this.elements = { namedItem: name => this.querySelector(`[name="${name}"]`) };
  }
  setAttribute(name, value) { this.attributes.set(name, String(value)); }
  getAttribute(name) { return this.attributes.get(name) ?? null; }
  removeAttribute(name) { this.attributes.delete(name); }
  querySelector(selector) { if (!this.fields.has(selector)) this.fields.set(selector, new Node(selector)); return this.fields.get(selector); }
  querySelectorAll() { return []; }
  appendChild(node) { this.children.push(node); node.parentElement = this; return node; }
  append(...nodes) { nodes.forEach(node => this.appendChild(node)); }
  replaceChildren(...nodes) { this.children = []; this.append(...nodes); }
  insertAdjacentHTML(position, html) { this.innerHTML = position === 'afterbegin' ? html + this.innerHTML : this.innerHTML + html; }
  closest() { return null; }
  contains(node) { return node === this || this.children.includes(node); }
  focus() {}
  scrollIntoView() {}
  reset() { for (const node of this.fields.values()) node.value = ''; }
  remove() {}
  matches() { return false; }
}
class FixtureFormData extends FormData {
  constructor(form) {
    super();
    for (const [selector, field] of form?.fields || []) {
      const name = /^\[name="([^"]+)"\]$/.exec(selector)?.[1];
      if (name) this.append(name, field.value);
    }
  }
}

function harness({ readyState = 'interactive', hash = '#url-imports', identity = 901, empty = false } = {}) {
  const nodes = new Map();
  const $ = id => { if (!nodes.has(id)) nodes.set(id, new Node(id)); return nodes.get(id); };
  const views = ['projects', 'url-imports', 'categories', 'skill', 'asset', 'knowledge', 'content-library', 'usage', 'personal-settings'];
  const document = new Events();
  Object.assign(document, {
    readyState, documentElement: $('html'), body: $('body'), head: $('head'), title: '',
    getElementById: $, createElement: tag => new Node(tag), createTextNode: text => ({ textContent: text }),
    querySelector: selector => selector.startsWith('#') ? $(selector.slice(1)) : null,
    querySelectorAll: selector => selector === '[data-workspace-view]' ? views.map(view => { const node = $(`view:${view}`); node.dataset.workspaceView = view; return node; }) : [],
  });
  const storage = initial => { const data = new Map(initial); return { data, getItem: key => data.get(key) ?? null, setItem: (key, value) => data.set(key, String(value)), removeItem: key => data.delete(key) }; };
  const localStorage = storage([['geo_recent_projects:777:777', '[777]']]);
  const sessionStorage = storage([['geo_token', 'synthetic-old-token'], ['geo_user', '{"id":777}'], ['geo_tenant', '{"id":777,"subdomain":"synthetic-old"}']]);
  const location = new URL(`https://example.com/${hash}`);
  const redirects = [];
  location.replace = url => redirects.push(url); location.assign = url => redirects.push(url);
  const session = { token: 'synthetic-session', user: { id: identity, name: '测试用户', role: 'admin' }, tenant: { id: identity, subdomain: `synthetic-${identity}` } };
  const project = { id: identity, name: `测试项目 ${identity}`, brand_name: '测试品牌', current_stage: 0, settings: {}, stages: [] };
  const calls = []; const errors = [];
  const controls = { auth: deferred(), projects: null, projectStatus: 200, urls: null, urlStatus: 200 };
  const response = (body, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
  const window = new Events();
  const context = vm.createContext(Object.assign(window, {
    document, location, localStorage, sessionStorage, URL, URLSearchParams, Headers, AbortSignal, FormData: FixtureFormData, Blob,
    console: { log() {}, warn() {}, error: (...args) => errors.push(args.join(' ')) },
    navigator: {}, crypto: { randomUUID: () => 'synthetic-uuid' },
    history: { replaceState(_state, _title, url) { location.href = String(url); }, pushState(_state, _title, url) { location.href = String(url); } },
    requestAnimationFrame: callback => callback(), matchMedia: () => ({ matches: false, addEventListener() {}, removeEventListener() {} }),
    setTimeout, clearTimeout, setInterval: () => 0, clearInterval() {}, getComputedStyle: () => ({ getPropertyValue: () => '' }),
    GEO_ENTRY_CONFIG: { tenantSubdomain: session.tenant.subdomain },
    fetch: async (path, options = {}) => {
      const url = new URL(typeof path === 'string' ? path : path.url, location);
      calls.push({ path: url.pathname, method: options.method || 'GET', headers: new Headers(options.headers), body: options.body });
      if (url.pathname === '/api/v1/auth/session-login') return (await controls.auth.promise).clone();
      if (url.pathname === '/api/v1/projects') {
        if (controls.projects) await controls.projects.promise;
        return response(controls.projectStatus === 200 ? { data: empty ? [] : [project], meta: { last_page: 1 } } : { message: 'Synthetic project failure' }, controls.projectStatus);
      }
      if (url.pathname === `/api/v1/projects/${identity}`) return response(project);
      if (url.pathname === '/api/v1/url-imports') {
        if (controls.urls) await controls.urls.promise;
        return response(controls.urlStatus === 200 ? { data: [{ id: 14, url: 'https://example.com/source', status: 'completed', title: `工作区 ${identity} 材料` }] } : { message: 'Synthetic permission denial' }, controls.urlStatus);
      }
      return response({ data: [], meta: { last_page: 1 } });
    },
  }));
  context.window = context;
  const load = name => vm.runInContext(sources.get(name), context, { filename: `public/${name}` });
  const entryIndex = scripts.indexOf('geo-entry.js');
  const start = () => { for (const name of scripts.slice(0, entryIndex + 1)) load(name); };
  const register = async ({ pauseAt = '' } = {}) => {
    for (const name of scripts.slice(entryIndex + 1)) { load(name); if (!pauseAt || name === pauseAt) await tick(); }
  };
  const ready = async () => { document.readyState = 'interactive'; await document.emit('DOMContentLoaded'); await settle(); };
  const settle = async () => { for (let i = 0; i < 8; i++) await tick(); };
  const resolveAuth = (status = 200) => controls.auth.resolve(response(status === 200 ? session : { message: 'Synthetic session failure' }, status));
  return { $, context, document, calls, errors, controls, session, project, start, load, register, ready, settle, resolveAuth, redirects, localStorage, response };
}

function assertReady(h, identity = 901) {
  assert.equal(h.$('errorMessage').textContent, '', 'A fast session must not cause bootstrap recursion');
  assert.equal(h.$('workspaceState').dataset.sessionReady, 'true');
  assert.doesNotMatch(h.$('workspaceLoadMessage').textContent, /项目加载失败/);
  assert.equal(h.context.GEO.state.selectedProject?.id, identity);
  assert.match(h.$('urlImportRows').innerHTML, new RegExp(`工作区 ${identity} 材料`));
  assert(h.calls.some(call => call.path === '/api/v1/url-imports'));
  assert.deepEqual(h.errors, []);
}

async function startRegistered(h) {
  h.start(); await h.register(); await h.ready(); h.resolveAuth(); await h.settle();
}

test('interactive direct entry survives a fast session before deferred modules register', async () => {
  const h = harness(); h.start(); h.resolveAuth(); await h.settle();
  const callsBeforeModules = h.calls.length;
  await h.register(); await h.ready();
  assertReady(h);
  assert.equal(callsBeforeModules, 0, 'Initial authentication waits for the shared registration boundary');
});

test('loading entry also waits for delayed real module registration', async () => {
  const h = harness({ readyState: 'loading' }); h.start(); h.resolveAuth(); await h.settle();
  await h.register(); await h.ready(); assertReady(h);
});

test('warm cached modules and slow session both preserve the direct route', async () => {
  const h = harness(); h.start(); await h.register({ pauseAt: 'no-pause' }); await h.ready();
  assert.equal(h.context.GEO.state.session, null);
  assert.equal(h.calls.filter(call => call.path === '/api/v1/url-imports').length, 0);
  h.resolveAuth(); await h.settle(); assertReady(h);
  assert.equal(h.context.GEO.state.activeView, 'url-imports');
});

test('complete document can start without a future DOMContentLoaded event', async () => {
  const h = harness({ readyState: 'complete' }); h.start(); await h.register();
  h.resolveAuth(); await h.settle(); assertReady(h);
});

test('another non-project view does not lose its deferred registered loader', async () => {
  const h = harness({ hash: '#categories' }); h.start(); h.resolveAuth(); await h.settle();
  await h.register(); await h.ready();
  assert.equal(h.$('errorMessage').textContent, '');
  assert.equal(h.context.GEO.state.activeView, 'categories');
  assert(h.calls.some(call => call.path === '/api/v1/categories'));
  assert.equal(h.context.GEO.state.selectedProject?.id, 901);
});

test('a registered project helper does not allow startup to skip a later management loader', async () => {
  const h = harness(); h.start();
  const remaining = scripts.slice(scripts.indexOf('geo-entry.js') + 1);
  const management = remaining.indexOf('geo-entry-management.js');
  for (const name of remaining.slice(0, management)) h.load(name);
  h.resolveAuth(); await h.settle();
  for (const name of remaining.slice(management)) { h.load(name); await tick(); }
  await h.ready();
  assertReady(h);
});

test('URL list remains readable while project restoration is delayed', async () => {
  const h = harness(); h.controls.projects = deferred(); await startRegistered(h);
  assert.equal(h.context.GEO.state.selectedProject, null);
  assert.match(h.$('urlImportRows').innerHTML, /工作区 901 材料/);
  h.controls.projects.resolve(); await h.settle(); assertReady(h);
  assert.equal(h.$('urlImportForm').querySelector('[name="project_id_display"]').value, '测试项目 901（#901）');
});

for (const status of [200, 403]) {
  test(`late URL response ${status} refreshes the restored project display without another request`, async () => {
    const h = harness(); h.controls.urls = deferred(); h.controls.urlStatus = status; await startRegistered(h);
    assert.equal(h.context.GEO.state.selectedProject?.id, 901);
    const before = h.calls.filter(call => call.path === '/api/v1/url-imports').length;
    assert.equal(before, 1, 'In-flight list loading remains deduplicated');
    h.controls.urls.resolve(); await h.settle();
    assert.equal(h.$('urlImportForm').querySelector('[name="project_id_display"]').value, '测试项目 901（#901）');
    assert.equal(h.calls.filter(call => call.path === '/api/v1/url-imports').length, before);
    if (status === 403) assert.match(h.$('uiMessage').textContent, /没有查看 URL 导入记录的权限/);
    else assertReady(h);
  });
}

test('empty workspace still shows URL history and refuses submission without a project', async () => {
  const h = harness({ empty: true }); await startRegistered(h);
  assert.equal(h.context.GEO.state.selectedProject, null);
  assert.equal(h.$('workspaceState').dataset.sessionReady, 'true');
  assert.equal(h.$('workspaceLoadNotice').hidden, true);
  assert.match(h.$('urlImportRows').innerHTML, /工作区 901 材料/);
  await h.$('urlImportForm').emit('submit');
  assert.match(h.context.GEO.elements.urlImportFormMessage.textContent, /选择一个项目/);
  assert(!h.calls.some(call => call.method === 'POST' && call.path !== '/api/v1/auth/session-login'));
});

test('project failure leaves URL history usable and the real project retry recovers', async () => {
  const h = harness(); h.controls.projectStatus = 503; await startRegistered(h);
  assert.equal(h.$('workspaceState').dataset.sessionReady, 'true');
  assert.match(h.$('workspaceLoadMessage').textContent, /项目加载失败/);
  assert.equal(h.$('workspaceLoadRetry').hidden, false);
  assert.match(h.$('urlImportRows').innerHTML, /工作区 901 材料/);
  h.controls.projectStatus = 200; await h.$('workspaceLoadRetry').emit('click'); await h.settle();
  assertReady(h);
});

test('URL permission denial is explicit and the real list refresh recovers', async () => {
  const h = harness(); h.controls.urlStatus = 403; await startRegistered(h);
  assert.match(h.$('uiMessage').textContent, /没有查看 URL 导入记录的权限/);
  assert.doesNotMatch(h.$('uiMessage').textContent, /共 0 条/);
  h.controls.urlStatus = 200; await h.$('uiReloadButton').emit('click'); await h.settle(); assertReady(h);
});

test('pending startup coalesces clicks and post-DOMContentLoaded retry reuses readiness', async () => {
  const h = harness(); h.start();
  await h.$('retryButton').emit('click'); await h.$('errorRetryButton').emit('click');
  await h.register(); await h.ready(); await h.document.emit('DOMContentLoaded');
  assert.equal(h.calls.filter(call => call.path === '/api/v1/auth/session-login').length, 1);
  h.resolveAuth(503); await h.settle();
  assert.equal(h.$('workspaceState').dataset.sessionReady, 'false');
  assert.match(h.$('errorMessage').textContent, /Synthetic session failure/);
  h.controls.auth = deferred();
  const retry = h.$('errorRetryButton').emit('click'); h.resolveAuth(); await retry; await h.settle();
  assert.equal(h.$('workspaceState').dataset.sessionReady, 'true');
  assert.equal(h.context.GEO.state.selectedProject?.id, 901);
  assert.equal(h.calls.filter(call => call.path === '/api/v1/auth/session-login').length, 2);
});

test('anonymous direct entry keeps its login redirect and does not load private lists', async () => {
  const h = harness(); h.start(); await h.register(); await h.ready(); h.resolveAuth(401); await h.settle();
  assert.equal(h.redirects.length, 1);
  assert.equal(new URL(h.redirects[0]).searchParams.get('redirect_to'), 'https://example.com/#url-imports');
  assert.equal(h.calls.filter(call => call.path !== '/api/v1/auth/session-login').length, 0);
});

test('new session scope replaces stale stored credentials and never restores another workspace project', async () => {
  const h = harness({ identity: 902 }); await startRegistered(h); assertReady(h, 902);
  const privateCalls = h.calls.filter(call => call.path !== '/api/v1/auth/session-login');
  assert(privateCalls.length > 0);
  for (const call of privateCalls) {
    assert.equal(call.headers.get('X-Tenant'), 'synthetic-902');
    assert.equal(call.headers.get('Authorization'), 'Bearer synthetic-session');
  }
  assert(!privateCalls.some(call => call.path.includes('/777')));
  assert.equal(h.localStorage.data.get('geo_recent_projects:777:777'), '[777]');
  assert.equal(h.localStorage.data.get('geo_recent_projects:902:902'), '[902]');
});

test('the real submit handler uses the restored project and session scope', async () => {
  const h = harness({ identity: 902 }); await startRegistered(h);
  const form = h.$('urlImportForm');
  form.querySelector('[name="url"]').value = 'https://example.com/current-project-source';
  await form.emit('submit'); await h.settle();
  const submissions = h.calls.filter(call => call.path === '/api/v1/url-imports' && call.method === 'POST');
  assert.equal(submissions.length, 1);
  assert.deepEqual(JSON.parse(submissions[0].body), { url: 'https://example.com/current-project-source', project_id: 902 });
  assert.equal(submissions[0].headers.get('X-Tenant'), 'synthetic-902');
  assert.equal(form.querySelector('[name="project_id_display"]').value, '测试项目 902（#902）');
});
