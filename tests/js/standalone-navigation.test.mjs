import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import vm from 'node:vm';
import { workspaceNavigationSource } from './helpers/workspace-nav-source.mjs';

const root = resolve(import.meta.dirname, '../..');
const read = path => readFileSync(resolve(root, path), 'utf8');
const navigation = workspaceNavigationSource({ mode: 'page' });
const template = navigation.html;

for (const page of ['visibility', 'analytics', 'manual-publish']) {
  const blade = read('resources/views/' + page + '.blade.php');
  assert.match(blade, /@include\('partials\.tenant-workspace-sidebar'/);
  assert.match(blade, /geo-page-navigation\.css/);
  assert(blade.indexOf('<script src="/geo-page-navigation.js') < blade.indexOf('<script src="/geo-' + page + '.js'), page + ': navigation loads before page boot');
  assert.match(blade, /@include\('partials\.workspace-topbar'/);
  assert.match(navigation.topbarSource, /id="geoPageMenuToggle"/);
  assert.match(blade, /class="geo-page-content"/);
}
assert.match(template, /href="\/visibility"/);
assert.match(template, /href="\/analytics"/);

function node(dataset = {}) {
  const classes = new Set();
  const listeners = {};
  return {
    dataset, href: '', hidden: false, inert: true, disabled: true, attributes: {},
    classList: {
      add: value => classes.add(value),
      remove: value => classes.delete(value),
      contains: value => classes.has(value),
      toggle(value, on) { if (on) classes.add(value); else classes.delete(value); },
    },
    setAttribute(name, value) { this.attributes[name] = value; },
    addEventListener(type, callback) { listeners[type] = callback; },
    fire(type, event = {}) { listeners[type]?.(event); },
    focus() { this.focused = true; },
  };
}

const views = [...template.matchAll(/data-workspace-view="([^"]+)"/g)].map(match => node({ workspaceView: match[1] }));
const pages = [...template.matchAll(/data-tenant-page="([^"]+)"/g)].map(match => node({ tenantPage: match[1] }));
const publishers = pages.filter(link => ['manual-publish'].includes(link.dataset.tenantPage));
const current = node();
const toggle = node();
const sidebar = node();
sidebar.querySelectorAll = selector => ({
  '[data-workspace-view]': views,
  '[data-tenant-page]': pages,
  '[data-role="publisher"]': publishers,
})[selector] || [];
const ids = { geoPageSidebar: sidebar, geoPageMenuToggle: toggle, geoPageCurrentProject: current };
const document = {
  getElementById: id => ids[id],
  addEventListener(type, callback) { this[type] = callback; },
};
const localStorage = {
  getItem: key => ({ 'geo_last_project_id:alpha': '16', 'geo_last_project_id:beta': '29' })[key] || null,
};
const window = {
  location: { search: '?project_id=17' },
  innerWidth: 800,
  addEventListener(type, callback) { this[type] = callback; },
};
vm.runInNewContext(read('public/geo-page-navigation.js'), { document, window, localStorage, URLSearchParams, Number, String });
assert.equal(sidebar.inert, true, 'navigation is unavailable before fresh session verification');
window.GEO_PAGE_NAV.activate({});
assert.equal(sidebar.inert, true, 'missing identity does not unlock navigation');
window.GEO_PAGE_NAV.activate({ user: { role: 'member' }, tenant: { subdomain: 'alpha' } });
assert.equal(sidebar.inert, false);
assert.equal(toggle.disabled, false);
assert.equal(views.find(link => link.dataset.workspaceView === 'skill').href, '/?project_id=17#skill');
assert.equal(pages.find(link => link.dataset.tenantPage === 'visibility').href, '/visibility?project_id=17');
assert.equal(current.hidden, false);
assert(publishers.every(link => link.hidden), 'member does not see restricted modules');

toggle.fire('click');
assert.equal(toggle.attributes['aria-expanded'], 'true');
document.keydown({ key: 'Escape' });
assert.equal(toggle.attributes['aria-expanded'], 'false');
assert.equal(toggle.focused, true);

window.location.search = '';
window.GEO_PAGE_NAV.activate({ user: { role: 'admin' }, tenant: { subdomain: 'beta' } });
assert.equal(views.find(link => link.dataset.workspaceView === 'skill').href, '/?project_id=29#skill');
assert(publishers.every(link => !link.hidden), 'admin sees modules enabled by the server template');

function functionSource(source, name) {
  const start = source.indexOf('function ' + name + '(');
  assert(start >= 0, name + ' exists');
  const brace = source.indexOf('{', start);
  let depth = 0;
  for (let i = brace; i < source.length; i++) {
    if (source[i] === '{') depth++;
    else if (source[i] === '}' && --depth === 0) return source.slice(source.slice(start - 6, start) === 'async ' ? start - 6 : start, i + 1);
  }
  throw new Error(name + ' has no closing brace');
}

const projectSource = read('public/geo-entry-projects.js');
const selected = [];
const projectContext = {
  URLSearchParams, Number, window: { location: { search: '?project_id=17' } },
  state: { projects: [], selectedProject: null },
  fetchProjectList: async () => [{ id: 16 }, { id: 17 }],
  rememberedProjectId: () => 16,
  renderProjects() {},
  selectProject: async id => { selected.push(id); },
  setProjectCreateMode() {}, renderAnalysisProjectSummary() {}, syncProjectContextCard() {},
};
vm.createContext(projectContext);
vm.runInContext(functionSource(projectSource, 'loadProjects'), projectContext);
await projectContext.loadProjects();
assert.deepEqual(selected, [17], 'deep link selects the requested project over local memory');
selected.length = 0;
projectContext.window.location.search = '?project_id=999';
await projectContext.loadProjects();
assert.deepEqual(selected, [16], 'an inaccessible project ID falls back to a valid remembered project');

console.log('Standalone navigation and project handoff passed.');
