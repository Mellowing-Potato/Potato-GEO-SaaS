import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { readEntrySource } from './helpers/entry-source.mjs';
const source = readEntrySource().replaceAll('\r\n', '\n');
function fn(name) {
  const start = source.search(new RegExp(`  (?:async )?function ${name}\\(`));
  assert.ok(start >= 0, name);
  return source.slice(start, source.indexOf('\n  }', start) + 4);
}
function compile(names, deps, prelude = '') {
  return new Function(...Object.keys(deps), `${prelude}\n${names.map(fn).join('\n')}\nreturn {${names.join(',')}};`)(...Object.values(deps));
}
const state = { session: { user: { id: 1 }, tenant: { id: 10 } }, projects: [], selectedProject: null };
const saved = new Map();
const localStorage = { getItem: key => saved.get(key) ?? null, setItem: (key, value) => saved.set(key, value) };
const helpers = compile(['recentProjectStorageKey', 'recentProjectIds', 'rememberRecentProject', 'sidebarProjects', 'matchingProjects'], { state, localStorage, tenantSubdomain: () => 'preview' });
state.projects = Array.from({ length: 15 }, (_, i) => ({ id: i + 1, name: `项目 ${i + 1}`, brand_name: i === 14 ? 'GEO 文旅' : '品牌' }));
for (const project of state.projects) helpers.rememberRecentProject(project);
helpers.rememberRecentProject(state.projects[9]);
state.selectedProject = state.projects[9];
assert.equal(helpers.recentProjectIds().length, 12);
assert.equal(helpers.sidebarProjects().length, 15);
assert.deepEqual(helpers.sidebarProjects().slice(0, 3).map(p => p.id), [10, 15, 14]);
assert.deepEqual(helpers.sidebarProjects(state.projects, state.selectedProject, [999, 10, 10, 3, 4]).slice(0, 5).map(p => p.id), [10, 3, 4, 1, 2]);
assert.equal(helpers.sidebarProjects(state.projects, { id: 999 }, []).length, 15);
assert.equal(helpers.sidebarProjects([], null, [1, 2]).length, 0);
assert.deepEqual(helpers.matchingProjects('  geo  ').map(p => p.id), [15]);
assert.equal(helpers.matchingProjects('没有此项目').length, 0);
assert.equal(helpers.matchingProjects(' ').length, 15);
state.session.user.id = 2;
assert.deepEqual(helpers.recentProjectIds(), []);
state.session.user.id = 1; state.session.tenant.id = 11;
assert.deepEqual(helpers.recentProjectIds(), []);
state.session.tenant.id = 10;
saved.set(helpers.recentProjectStorageKey(), '{bad json');
assert.deepEqual(helpers.recentProjectIds(), []);
saved.set(helpers.recentProjectStorageKey(), '[10,"10",-1,null,"bad",3]');
assert.deepEqual(helpers.recentProjectIds(), [10, 3]);
const blocked = compile(['recentProjectIds', 'rememberRecentProject'], { recentProjectStorageKey: helpers.recentProjectStorageKey, localStorage: { getItem() { throw Error('blocked'); }, setItem() { throw Error('blocked'); } } });
assert.deepEqual(blocked.recentProjectIds(), []);
assert.doesNotThrow(() => blocked.rememberRecentProject({ id: 2 }));

// All permitted pages must load before committing a complete project list.
const requests = [];
let pageApi = async url => {
  requests.push(url);
  return url.endsWith('page=1') ? { data: [{ id: 1 }, { id: 2 }], last_page: 2 } : { data: [{ id: 2 }, { id: 101 }], last_page: 2 };
};
const { fetchProjectList } = compile(['fetchProjectList'], { config: {}, recentProjectStorageKey: helpers.recentProjectStorageKey, api: url => pageApi(url) });
assert.deepEqual((await fetchProjectList()).map(p => p.id), [1, 2, 101]);
assert.deepEqual(requests, ['/api/v1/projects?per_page=100&page=1', '/api/v1/projects?per_page=100&page=2']);
pageApi = async url => { if (url.endsWith('page=2')) throw Error('network'); return { data: [{ id: 1 }], last_page: 2 }; };
await assert.rejects(fetchProjectList, /network/);
pageApi = async () => { state.session.tenant.id++; return { data: [{ id: 1 }] }; };
await assert.rejects(fetchProjectList, /登录状态已变化/);
pageApi = async () => ({ error: 'bad response' });
await assert.rejects(fetchProjectList, /格式异常/);
pageApi = async () => [{ id: 1 }];
assert.equal((await fetchProjectList()).length, 1);

// Use the real project selection function with deliberately reordered API responses.
const pending = new Map();
const selected = [];
const noop = () => {};
const { selectProject } = compile(['selectProject'], {
  state, recentProjectStorageKey: helpers.recentProjectStorageKey,
  elements: {}, $: () => ({ style: {} }), viewLoadState: { active: null },
  api: url => new Promise(resolve => pending.set(Number(url.split('/').at(-1)), resolve)),
  stopAllExecutionPollers: noop, rememberProject: p => selected.push(p.id), upsertProject: noop,
  loadProjectChannels: noop, renderSkillPendingStrip: noop, renderProjects: noop,
  renderProjectDetail: noop, renderAnalysisProjectSummary: noop, renderSkillWorkspace: noop,
  loadAnalyses: noop, loadSkillExecutions: noop, loadArticles: noop, loadContentLibrary: noop,
}, 'let projectSelectionRequest = 0;');
const older = selectProject(1); const newer = selectProject(2);
pending.get(2)({ id: 2 }); await newer;
pending.get(1)({ id: 1 }); await older;
assert.equal(state.selectedProject.id, 2);
assert.deepEqual(selected, [2]);
const foreign = selectProject(3);
state.session.tenant.id++;
pending.get(3)({ id: 3 }); await foreign;
assert.equal(state.selectedProject.id, 2);

class Node {
  hidden = false; open = false; value = ''; textContent = ''; dataset = {}; attrs = {}; handlers = {}; children = [];
  style = { removeProperty: key => { delete this.style[key]; } };
  setAttribute(key, value) { this.attrs[key] = value; }
  addEventListener(type, cb) { this.handlers[type] = cb; }
  replaceChildren(...children) { this.children = children; }
  appendChild(child) { this.children.push(child); }
  focus() { focused = this; }
  contains(target) { return target === this; }
}
let focused;
const nodes = new Map();
const document = { handlers: {}, addEventListener(type, cb) { this.handlers[type] = cb; }, getElementById(id) { if (!nodes.has(id)) nodes.set(id, new Node()); return nodes.get(id); }, createElement: () => new Node() };
const elements = { detailTitle: document.getElementById('detailTitle') };
const window = { innerWidth: 1400, innerHeight: 900, location: { hash: '#skill' }, handlers: {}, addEventListener(type, cb) { this.handlers[type] = cb; } };
const routes = [];
let selection = async id => { state.selectedProject = { id }; };
let creating = false;
const ui = compile(['projectChoiceButton', 'renderProjects', 'renderProjectDirectory', 'cancelProjectNavigation', 'openProjectOverview', 'showProjectDirectory', 'openCreateProjectPanel'], {
  state, elements, document, window, ...helpers,
  escapeHtml: text => text.replaceAll('<', '&lt;').replaceAll('>', '&gt;'), computeStageBadge: () => '0%',
  setMessage: (node, text) => { node.textContent = text; },
  selectProject: id => selection(id), openWorkspaceView: route => { routes.push(route); window.location.hash = `#${route}`; },
  setProjectCreateMode: value => { creating = value; },
}, 'let projectNavigationRequest = 0;');
elements.createProjectMessage = new Node();
elements.createProjectForm = { querySelector: () => document.getElementById('firstCreateField') };
state.selectedProject = state.projects[9];
ui.renderProjects();
const sidebar = document.getElementById('projectNavList');
assert.equal(sidebar.children.length, 15);
assert.equal(sidebar.children[0].dataset.projectId, '10');
assert.ok(sidebar.children.some(child => child.dataset.projectId === '1'));
assert.ok(sidebar.children.some(child => child.dataset.projectId === '15'));
document.getElementById('directoryProjectSearch').value = 'geo';
ui.renderProjectDirectory();
assert.deepEqual(document.getElementById('directoryProjectList').children.map(child => child.dataset.projectId), ['15']);
document.getElementById('directoryProjectSearch').value = '不存在';
ui.renderProjectDirectory();
assert.match(document.getElementById('directoryProjectList').children[0].textContent, /没有匹配/);
const template = readFileSync(new URL('../../resources/views/welcome.blade.php', import.meta.url), 'utf8');
assert.match(template, /id="projectNavList" aria-label="所有项目"/);
assert.doesNotMatch(template, /id="pcBtn"|id="pcDrop"/);

await ui.openProjectOverview(15);
assert.equal(routes.at(-1), 'projects');
assert.equal(focused, elements.detailTitle);
const delayed = [];
selection = id => new Promise(resolve => delayed.push(() => { state.selectedProject = { id }; resolve(); }));
const leaving = ui.openProjectOverview(14);
ui.showProjectDirectory(); delayed.shift()(); await leaving;
assert.equal(routes.at(-1), 'projects/list'); // Late switch must not replace the page just opened.
assert.equal(document.getElementById('projectNavMessage').textContent, '');
selection = async () => { throw Error('无权访问该项目'); };
await ui.openProjectOverview(13);
assert.equal(document.getElementById('projectNavMessage').textContent, '无权访问该项目');
ui.openCreateProjectPanel();
assert.equal(routes.at(-1), 'projects');
assert.equal(creating, true);
assert.equal(focused, document.getElementById('firstCreateField'));
const hostile = ui.projectChoiceButton({ id: 99, name: '<img src=x>', brand_name: '<b>brand</b>' });
assert.ok(!hostile.innerHTML.includes('<img') && !hostile.innerHTML.includes('<b>'));
console.log('Project navigation: all sidebar projects, complete pagination, stale selections, directory search and create flow passed.');
