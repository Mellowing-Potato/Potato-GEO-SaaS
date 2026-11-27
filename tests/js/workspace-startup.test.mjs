import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { readEntrySource } from './helpers/entry-source.mjs';

const source = readEntrySource();
function realFunction(name) {
  const match = new RegExp(`^  (?:async )?function ${name}\\(`, 'm').exec(source);
  assert(match, name);
  return source.slice(match.index, source.indexOf('\n  }', match.index) + 4);
}
const deferred = () => {
  let resolve, reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
};
const tick = () => new Promise(resolve => setImmediate(resolve));
function harness() {
  const nodes = new Map();
  const $ = id => {
    if (!nodes.has(id)) nodes.set(id, { hidden: false, inert: false, dataset: {}, textContent: '', querySelector: child => $(child) });
    return nodes.get(id);
  };
  const calls = [];
  const loaderNames = [...source.matchAll(/tasks\.push\((load\w+)\(/g)].map(m => m[1]);
  // 2026-09-21 §7 拆分：一部分视图的加载器改由 window.GEO 的注册表提供（主文件只调
  // runViewLoaders）。测试环境没有那两个拆出去的文件，所以这里按同样的语义给一个桩：
  // 注册表里的加载器照样走 context 上的记录器，"进这个视图会调那个加载器"依然验得到。
  const registeredLoaders = {
    usage: ['loadUsage'],
    'url-imports': ['loadUrlImports'],
    'categories': ['loadCategories'],
    // 2026-09-21 §7 第九刀：技能目录与执行历史也搬进了拆出文件（geo-entry-skills.js 注册）——
    // 「项目」页同样要技能目录（项目详情按阶段推荐技能），条件 `!state.skills.length` 写在
    // 那边的加载器里；沙箱里 state.skills 恒为空，所以桩按"每次都调"来记。
    projects: ['loadSkills'],
    skill: ['loadSkills', 'loadSkillExecutions'],
  };
  const context = {
    $, console, AbortSignal, Promise, Map, JSON, Number, String,
    workspaceModulesReady: Promise.resolve(),
    state: { session: null, activeView: 'projects', activeViewParam: '', skills: [], selectedProject: { id: 1 } },
    stateIds: ['connectingState', 'signedOutState', 'errorState', 'workspaceState'],
    elements: { workspaceViews: ['projects', 'skill', 'asset', 'content-library', 'usage'].map(view => { const n = $(view); n.dataset.workspaceView = view; return n; }), newProjectButton: $('newProjectButton'), errorMessage: $('errorMessage') },
    config: {}, sessionStorage: { removeItem() {} }, window: { location: { replace(url) { calls.push(url); } } },
    fetch: async () => ({ status: 200, ok: true, json: async () => ({ token: 'verified' }) }),
    persistSession() {}, syncAccountHeader() {}, applyManualPublishVisibility() {},
    accountLoginUrl: () => '/login', recentProjectStorageKey: () => 'user-1:tenant-1',
    currentWorkspaceRoute: () => context.state.activeView,
    loadProjects: async () => {},
  };
  for (const name of [...loaderNames, ...Object.values(registeredLoaders).flat(), 'loadSkillExecutions', 'loadTaskRuns', 'loadProjectChannels', 'renderResultPage']) {
    context[name] = async (...args) => { calls.push([name, ...args]); };
  }
  // 挂在 context 根上：主文件读的是 globalThis.GEO（浏览器里 globalThis === window）
  context.GEO = {
    viewLoaders: registeredLoaders,
    viewExtras: {},
    runViewLoaders(view, tasks) {
      (this.viewLoaders[view] || []).forEach((name) => tasks.push(context[name]()));
    },
    viewExtras() { return []; },
  };
  context.setActiveWorkspaceView = view => context.loadWorkspaceViewData(view, context.state.activeViewParam);
  vm.createContext(context);
  const declarations = source.slice(source.indexOf('  let sessionReady'), source.indexOf('  const state ='));
  vm.runInContext(declarations + ['showState', 'syncWorkspaceAvailability', 'restoreWorkspaceProjects', 'loadWorkspaceViewData', 'bootstrap'].map(realFunction).join('\n'), context);
  return { context, $, calls, run: text => vm.runInContext(text, context) };
}

// A slow project response must not keep authenticated navigation locked.
{
  const { context: c, $, calls, run } = harness();
  const auth = deferred(), projects = deferred();
  c.fetch = () => auth.promise;
  c.loadProjects = () => projects.promise;
  const boot = c.bootstrap();
  c.loadWorkspaceViewData('usage', '');
  assert.equal(calls.length, 0, 'no data requests before authentication');
  assert.equal($('.workspace-sidebar').inert, true);
  assert.equal($('workspaceState').hidden, false, 'frame paints while login is pending');
  auth.resolve({ status: 200, ok: true, json: async () => ({ token: 'verified' }) });
  await boot;
  assert.equal($('.workspace-sidebar').inert, false);
  assert.equal($('projects').inert, true, 'project actions wait for real project context');
  c.state.activeView = 'usage';
  c.loadWorkspaceViewData('usage', '');
  await tick();
  assert(calls.some(([name]) => name === 'loadUsage'));
  projects.reject(new Error('network offline'));
  await tick();
  assert.equal(run('sessionReady'), true);
  assert.match($('workspaceLoadMessage').textContent, /项目加载失败/);
  assert.equal($('workspaceLoadRetry').hidden, false);
  c.loadProjects = async () => {};
  await c.restoreWorkspaceProjects();
  assert.equal($('projects').inert, false);
  assert.equal($('workspaceLoadNotice').hidden, true);
}

// Only the active view loads; a late failure from a view we left cannot cover the new page.
{
  const { context: c, $, calls, run } = harness();
  run('sessionReady = projectsReady = true');
  const history = deferred();
  c.loadAnalyses = () => { calls.push(['loadAnalyses']); return history.promise; };
  c.loadWorkspaceViewData('projects', '');
  await tick();
  assert.deepEqual(calls.map(([name]) => name), ['loadSkills']);
  calls.length = 0;
  c.loadWorkspaceViewData('skill', '');
  c.loadWorkspaceViewData('skill', '');
  assert.equal(calls.filter(([name]) => name === 'loadAnalyses').length, 1, 'coalesce in-flight view loads');
  assert(!calls.some(([name]) => ['loadArticles', 'loadContentLibrary'].includes(name)));
  c.loadWorkspaceViewData('workspace-settings', '');
  history.reject(new Error('old view failed'));
  await tick();
  assert.equal($('workspaceViewLoadNotice').hidden, true);
  c.loadAnalyses = async () => { throw new Error('diagnosis unavailable'); };
  c.loadWorkspaceViewData('skill', '');
  await tick();
  assert.equal($('workspaceViewLoadRetry').hidden, false);
  assert.match($('workspaceViewLoadMessage').textContent, /diagnosis unavailable/);
  c.loadAnalyses = async () => {};
  c.loadWorkspaceViewData('skill', '');
  await tick();
  assert.equal($('workspaceViewLoadNotice').hidden, true);
  c.loadWorkspaceViewData('result', '47');
  assert(calls.some(([name, id]) => name === 'renderResultPage' && id === '47'));
}

// Auth failures retain the frame, expose retry, and never open project operations.
{
  const { context: c, $, run } = harness();
  c.fetch = async () => { const error = new Error('timeout'); error.name = 'TimeoutError'; throw error; };
  await c.bootstrap();
  assert.equal(run('sessionReady'), false);
  assert.equal($('workspaceLoadRetry').hidden, false);
  assert.match($('workspaceLoadMessage').textContent, /登录验证超时/);
  assert.equal($('.workspace-sidebar').inert, true);
  assert.equal($('workspaceState').hidden, false);
}

// A network failure while restoring a remembered project is not an empty workspace.
{
  let reads = 0, creates = 0;
  const c = {
    state: { projects: [] }, rememberedProjectId: () => 9, wait: async () => {},
    fetchProjectList: async () => { if (++reads === 1) return []; throw new Error('offline'); },
    renderProjects() {}, setProjectCreateMode() { creates++; }, renderAnalysisProjectSummary() {}, syncProjectContextCard() {},
  };
  vm.createContext(c);
  vm.runInContext(realFunction('loadProjects'), c);
  await assert.rejects(c.loadProjects(), /offline/);
  assert.equal(creates, 0);
}

// Selection completes when the project itself arrives, even if secondary services never settle.
{
  const c = {
    projectSelectionRequest: 0, recentProjectStorageKey: () => 'scope',
    api: async () => ({ id: 2, name: 'project two' }), state: {}, elements: {},
    stopAllExecutionPollers() {}, rememberProject() {}, upsertProject() {}, renderSkillPendingStrip() {},
    closeKnowledgeBaseDetail() {}, ekCloseDetail() {}, $: () => ({ style: {} }),
    renderProjects() {}, renderProjectDetail() {}, renderAnalysisProjectSummary() {}, renderSkillWorkspace() {},
    // 2026-09-21 §7 第十一刀：项目块与主文件都要复位"视图加载序号"（切项目时），
    // 所以它从裸 let 变成共享对象 viewLoadState = { active }。沙箱里按同样形状给桩。
    viewLoadState: { active: null },
    loadAnalyses: () => new Promise(() => {}), loadSkillExecutions: () => new Promise(() => {}),
    loadArticles: () => new Promise(() => {}), loadContentLibrary: () => new Promise(() => {}),
  };
  vm.createContext(c);
  vm.runInContext(realFunction('selectProject'), c);
  let finished = false;
  c.selectProject(2).then(() => { finished = true; });
  await tick();
  assert.equal(finished, true);
  assert.equal(c.state.selectedProject.id, 2);
}

// Late diagnosis responses must not overwrite the new project's result.
{
  const request = deferred();
  const c = { state: { selectedProject: { id: 1 }, analyses: [] }, api: () => request.promise, recentProjectStorageKey: () => 'scope', renderAnalysisHistory() { throw new Error('stale render'); }, renderAnalysisEmptyState() {}, selectAnalysis() {} };
  vm.createContext(c);
  vm.runInContext(realFunction('loadAnalyses'), c);
  const pending = c.loadAnalyses(1);
  c.state.selectedProject = { id: 2 };
  request.resolve({ data: [{ id: 41 }] });
  await pending;
  assert.equal(c.state.analyses.length, 0);
}

// Read timeout covers the body too. Writes must not inherit the short read deadline.
{
  const timeouts = [], optionsSeen = [];
  const c = {
    state: { session: null },
    AbortSignal: { timeout(ms) { timeouts.push(ms); return AbortSignal.timeout(5); } },
    authHeaders: headers => headers, reverifyOnce: async () => {},
    fetch: async (_path, options) => {
      optionsSeen.push(options);
      return { ok: true, status: 200, headers: new Headers(), text: () => options.signal
        ? new Promise((_, reject) => options.signal.addEventListener('abort', () => reject(options.signal.reason), { once: true }))
        : Promise.resolve('{}') };
    },
  };
  vm.createContext(c);
  vm.runInContext(realFunction('api') + realFunction('apiWithSessionRetry'), c);
  const keepAlive = setTimeout(() => {}, 1000);
  try { await assert.rejects(c.api('/read'), /数据读取超时/); } finally { clearTimeout(keepAlive); }
  await c.api('/paid-execution', { method: 'POST', headers: { 'Idempotency-Key': 'same-key' } });
  assert.deepEqual(timeouts, [20000]);
  assert.equal(optionsSeen[1].signal, undefined);
  assert.equal(optionsSeen[1].headers['Idempotency-Key'], 'same-key');
}
console.log('Workspace startup: auth gate, nonblocking restore, lazy views, retry, stale response and read timeout checks passed.');
