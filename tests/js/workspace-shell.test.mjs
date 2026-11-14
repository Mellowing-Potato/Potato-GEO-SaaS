import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import vm from 'node:vm';

const root = resolve(import.meta.dirname, '../..');
const read = path => readFileSync(resolve(root, path), 'utf8');
const navigationPath = resolve(root, 'app/Support/WorkspaceNavigation.php');
// Exercise the production definition directly, without a framework boot or request.
const definition = spawnSync(process.env.PHP_BINARY || 'php', [
  '-r', 'require $argv[1]; echo json_encode(\\App\\Support\\WorkspaceNavigation::menus(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);',
  navigationPath,
], { encoding: 'utf8' });
assert.equal(definition.status, 0, `The static navigation definition must load independently: ${definition.error?.message || definition.stderr}`);
const menus = JSON.parse(definition.stdout);
assert.deepEqual(Object.keys(menus), ['work', 'settings']);

const workContract = [
  ['', ['projects', 'analytics']],
  ['分析诊断', ['skill', 'asset']],
  ['内容', ['content-library', 'materials', 'knowledge', 'url-imports']],
  ['发布', ['publishing', '/manual-publish', 'automation', '/theme-package', 'leads']],
  ['效果监测', ['/visibility', '/analytics']],
  ['平台管理', ['platform']],
];
const settingsContract = [
  ['账户', ['personal-settings', 'account-security', 'aikeys', 'team']],
  ['内容管控', ['sensitive-words', 'skill-prompts']],
  ['用量', ['usage']],
];
const route = item => item.page ? `/${item.page}` : item.view;
const outline = groups => groups.map(group => [group.label, group.items.map(route)]);
assert.deepEqual(outline(menus.work), workContract, 'The work menu follows the approved grouping and order');
assert.deepEqual(outline(menus.settings), settingsContract, 'Settings entries move out of the work menu');

const entries = Object.values(menus).flatMap(groups => groups.flatMap(group => group.items));
const views = entries.filter(item => item.view).map(item => item.view);
assert.equal(new Set(views).size, views.length, 'Work and settings must not duplicate an internal view target');
const identifiers = entries.map(item => item.id).filter(Boolean);
assert.equal(new Set(identifiers).size, identifiers.length, 'Navigation entries must not duplicate an id');
for (const groups of Object.values(menus)) {
  for (const group of groups) {
    for (const item of group.items) {
      assert.equal(item.group, group.key, `${item.label}: group metadata agrees with the rendered group`);
      assert.equal(Boolean(item.view) + Boolean(item.page), 1, `${item.label}: exactly one route type`);
      for (const field of ['label', 'view', 'page', 'id', 'flag', 'roles', 'hidden', 'group']) {
        assert(Object.hasOwn(item, field), `${item.label}: navigation field ${field}`);
      }
    }
  }
}
const byRoute = value => entries.find(item => route(item) === value);
assert.equal(byRoute('analytics').label, '项目总览');
assert.equal(byRoute('categories'), undefined, 'Article categories are reached from the article page');
for (const [value, flag] of [
  ['/manual-publish', 'manual_publish_enabled'],
  ['/theme-package', 'theme_package_enabled'],
  ['/visibility', 'visibility_panel_enabled'],
  ['/analytics', 'analytics_panel_enabled'],
  ['leads', 'lead_form_enabled'],
]) assert.equal(byRoute(value).flag, flag, `${value}: retain its existing feature gate`);
for (const value of ['/manual-publish', '/theme-package']) {
  assert.deepEqual(byRoute(value).roles, ['admin', 'platform_admin', 'editor'], `${value}: retain publisher roles`);
}
assert.equal(byRoute('team').id, 'teamMenuLink');
assert.equal(byRoute('team').hidden, true, 'The frozen team entry remains hidden');
assert.equal(byRoute('workspace-settings'), undefined, 'Workspace settings must not be revived');
const platform = menus.work.find(group => group.key === 'platform');
assert.equal(platform.id, 'platformAdminSection');
assert.equal(platform.hidden, true, 'Platform administration waits for the existing permission check');
assert(!entries.some(item => /api[- ]?tokens?|channel[- ]?keys?|agent[- ]?packages?|wp[- ]?beacon/i.test(route(item))), 'Credential entries must not be revived');

const welcome = read('resources/views/welcome.blade.php');
const standalone = read('resources/views/partials/tenant-workspace-sidebar.blade.php');
const nav = read('resources/views/partials/workspace-nav.blade.php');
const topbar = read('resources/views/partials/workspace-topbar.blade.php');
assert.match(nav, /\\App\\Support\\WorkspaceNavigation::menus\(\)/, 'The renderer reads the static definition');
assert.match(welcome, /@include\('partials\.workspace-nav',\s*\['mode'\s*=>\s*'spa'\]\)/);
assert.match(standalone, /@include\('partials\.workspace-nav',\s*\['mode'\s*=>\s*'page'\]\)/);
assert.doesNotMatch(standalone, /\$workspaceSections\s*=/, 'Standalone navigation has no second menu definition');
const mainSidebar = welcome.match(/<aside\b[^>]*id="workspaceSidebar"[^>]*>[\s\S]*?<\/aside>/)?.[0];
assert(mainSidebar, 'Main workspace sidebar exists');
assert.doesNotMatch(mainSidebar, /data-view-target\s*=/, 'The parent template has no hand-written view link list');
assert.doesNotMatch(standalone, /data-tenant-page\s*=|\['(?:分析诊断|内容资产|发布与增长)'/, 'Standalone wrapper has no hand-written page link list');
assert.doesNotMatch(nav, /href="(?:#|\/#|\/)(?:skill|asset|materials|content-library|publishing|automation|visibility|analytics|manual-publish|theme-package)"/, 'The shared renderer does not duplicate menu routes');
assert.match(nav, /@continue\(\$mode\s*===\s*'page'\s*&&\s*\$menu\s*===\s*'settings'\)/, 'Standalone pages only render the work menu');
assert.match(nav, /config\('geo\.'\.\$item\['flag'\]/, 'The shared renderer applies feature gates');
assert.match(nav, /\$item\['roles'\][\s\S]*?data-role="publisher"\s+hidden/, 'Restricted entries are initially hidden');

const tags = source => [...source.matchAll(/<(?:a|button)\b[^>]*>/g)].map(match => match[0]);
for (const id of ['openSettingsButton', 'settingsBackButton']) {
  const tag = tags(welcome + standalone + nav).find(value => value.includes(`id="${id}"`));
  assert(tag, `${id} is present`);
  assert.doesNotMatch(tag, /data-view-target/, `${id} must not be intercepted as a menu view link`);
}
const shortcuts = tags(topbar).filter(tag => tag.includes('data-shell-view='));
assert(shortcuts.length >= 4, 'Shared topbar has the common settings and billing shortcuts');
for (const tag of shortcuts) assert.doesNotMatch(tag, /data-view-target/, 'Topbar shortcuts must not duplicate side menu targets');
for (const id of ['accountLink', 'accountSecurityLink']) {
  const tag = tags(topbar).find(value => value.includes(`id="${id}"`));
  assert(tag && tag.includes('data-shell-view='), `${id}: shared shortcut contract`);
}
for (const id of [
  'workspaceIdentity', 'workspaceMobileMenuToggle', 'accountMenu', 'accountMenuButton', 'userLabel',
  'accountMenuIdentity', 'accountMenuWorkspace', 'accountMenuPhone', 'accountLink',
  'accountSecurityLink', 'accountLogoutButton', 'geoPageMenuToggle',
]) assert.equal([...topbar.matchAll(new RegExp(`id="${id}"`, 'g'))].length, 1, `${id}: preserved once in the shared topbar`);
assert.match(topbar, /<header\b[^>]*class="topbar workspace-topbar"[^>]*\binert\b/, 'The topbar waits for verified session identity');
assert.match(topbar, /<x-yanji-logo\b[^>]*variant="compact"[^>]*class="topbar-logo"[^>]*width="128"[^>]*height="44"/);
assert.doesNotMatch(topbar, /variant="symbol"/, 'The shared header keeps the compact brand mark');

const pageNames = ['analytics', 'manual-publish', 'theme-package', 'visibility'];
const pageTemplates = pageNames.map(page => read(`resources/views/${page}.blade.php`));
for (const [index, source] of pageTemplates.entries()) {
  const page = pageNames[index];
  assert.match(source, /<div class="geo-page-shell">\s*@include\('partials\.workspace-topbar',\s*\['mode'\s*=>\s*'page'\]\)/, `${page}: common header spans the page shell`);
  assert.match(source, /@include\('partials\.tenant-workspace-sidebar'/, `${page}: existing sidebar include remains`);
  assert.match(source, /class="geo-page-content"/, `${page}: existing content wrapper remains`);
}
assert.match(welcome, /@include\('partials\.workspace-topbar',\s*\['mode'\s*=>\s*'spa'\]\)/);
for (const [name, source] of [
  ['welcome', welcome], ...pageNames.map((page, index) => [page, pageTemplates[index]]),
]) {
  const tokenPosition = source.indexOf('href="/geo-tokens.css');
  const shellPosition = source.indexOf('href="/geo-shell.css');
  const nextStyle = source.indexOf('<link rel="stylesheet"', tokenPosition + 1);
  assert(tokenPosition >= 0 && shellPosition > tokenPosition, `${name}: shell CSS loads after tokens`);
  assert.equal(nextStyle, source.lastIndexOf('<link rel="stylesheet"', shellPosition), `${name}: shell CSS precedes the other page styles`);
}
for (const source of [welcome, standalone, nav, topbar, ...pageTemplates]) {
  assert.doesNotMatch(source, /可见性看板/, 'The old overview label is absent from tenant shell templates');
}
const cssFiles = [...new Set([welcome, ...pageTemplates].flatMap(source => [...source.matchAll(/href="\/(geo-[a-z-]+\.css)\?/g)].map(match => match[1])))];
for (const file of cssFiles) {
  const css = read(`public/${file}`).replace(/\/\*[\s\S]*?\*\//g, '');
  for (const [, selectors, declarations] of css.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
    if (!/\.topbar-logo\b/.test(selectors)) continue;
    for (const [, property, amount, unit] of declarations.matchAll(/(?:^|;)\s*((?:min-|max-)?width)\s*:\s*([\d.]+)(px|rem)\b/g)) {
      const pixels = Number(amount) * (unit === 'rem' ? 16 : 1);
      assert(pixels >= 128, `${file}: ${selectors.trim()} ${property} must not compress the brand below 128px`);
    }
  }
}
assert.doesNotMatch(read('public/geo-shell.css'), /--md-[\w-]+\s*:/, 'The shell consumes the shared design tokens without defining another copy');

// Run the production view transition against a minimal DOM. Data loaders stay at
// their existing boundary; navigation and settings state come from the real code.
const entry = read('public/geo-entry.js');
function realFunction(name) {
  const start = new RegExp(`^  (?:async )?function ${name}\\(`, 'm').exec(entry);
  assert(start, `${name}: production function exists`);
  const end = entry.indexOf('\n  }', start.index);
  assert(end > start.index, `${name}: complete function body`);
  return entry.slice(start.index, end + 4);
}
function domNode(dataset = {}, hidden = false) {
  const attributes = {};
  return {
    dataset, hidden, attributes,
    classList: { toggle() {} },
    setAttribute(name, value) { attributes[name] = value; },
    getAttribute(name) { return attributes[name]; },
    closest() { return null; },
    scrollIntoView() { this.scrolled = true; },
  };
}
const sidebarNode = domNode({ navMode: 'work' });
const trashNode = domNode();
const menuLinks = entries.filter(item => item.view).map(item => domNode({ viewTarget: item.view }, item.hidden));
const availableViews = [...new Set([...views, 'categories', 'result', 'workspace-settings'])];
const viewNodes = availableViews.map(view => domNode({ workspaceView: view }));
const loads = [];
const transition = {
  URL, String, Number,
  defaultWorkspaceView: 'projects', workspaceViewAliases: {},
  workspaceViewMeta: Object.fromEntries(availableViews.map(view => [view, { title: view }])),
  state: { activeView: 'projects', activeViewParam: '', lastWorkView: 'projects', selectedProject: null },
  elements: { workspaceViews: viewNodes, workspaceMenuLinks: menuLinks },
  document: {
    documentElement: { dataset: { brandName: '言及' } },
    getElementById(id) { return { workspaceSidebar: sidebarNode, globalTrashNav: trashNode }[id] || null; },
    querySelector() { return menuLinks.find(link => link.dataset.viewTarget === 'projects'); },
  },
  window: { location: { href: 'http://localhost/#projects', hash: '#projects' }, requestAnimationFrame(callback) { callback(); } },
  syncWorkspaceAvailability() {},
  loadWorkspaceViewData(view, param) { loads.push([view, param]); },
  applySkillContext() {},
};
const updateLocation = (_state, _title, url) => { transition.window.location = { href: url.href, hash: url.hash }; };
transition.history = { pushState: updateLocation, replaceState: updateLocation };
vm.createContext(transition);
const routingConstants = ['FROZEN_WORKSPACE_VIEWS', 'SETTINGS_VIEWS'].map(name => {
  const declaration = entry.match(new RegExp(`const ${name} = \\[[\\s\\S]*?\\];`));
  assert(declaration, `${name}: routing guard exists`);
  return declaration[0];
});
vm.runInContext(routingConstants.join('\n') + '\n' + [
  'parseWorkspaceHash', 'expandWorkspaceAlias', 'normalizeWorkspaceView', 'setActiveWorkspaceView',
].map(realFunction).join('\n'), transition);
const visit = (value, options) => transition.setActiveWorkspaceView(value, options);
const currentMenu = () => menuLinks.filter(link => link.getAttribute('aria-current') === 'page').map(link => link.dataset.viewTarget);
visit('content-library');
assert.equal(sidebarNode.dataset.navMode, 'work');
assert.equal(transition.state.lastWorkView, 'content-library');
assert.deepEqual(currentMenu(), ['content-library']);
visit('personal-settings', { updateHash: true });
assert.equal(sidebarNode.dataset.navMode, 'settings');
assert.equal(transition.state.lastWorkView, 'content-library', 'Entering settings preserves the previous work view');
assert.deepEqual(currentMenu(), ['personal-settings']);
assert.equal(transition.window.location.hash, '#personal-settings');
visit('#usage');
assert.equal(sidebarNode.dataset.navMode, 'settings', 'A direct settings hash selects settings navigation');
assert.equal(transition.state.lastWorkView, 'content-library');
assert.deepEqual(currentMenu(), ['usage']);
visit(transition.state.lastWorkView);
assert.equal(sidebarNode.dataset.navMode, 'work', 'Returning to the previous work route restores work navigation');
assert.equal(transition.state.activeView, 'content-library');
visit('result/41');
visit('usage');
assert.equal(transition.state.lastWorkView, 'result/41', 'Entering settings preserves a parameterized work route');
visit(transition.state.lastWorkView);
assert.equal(transition.state.activeView, 'result');
assert.equal(transition.state.activeViewParam, '41');
for (const frozen of ['team', 'workspace-settings']) {
  visit(frozen);
  assert.equal(transition.state.activeView, 'projects', `${frozen}: frozen routes still fall back`);
  assert.equal(sidebarNode.dataset.navMode, 'work');
  assert.deepEqual(currentMenu(), ['projects'], 'A frozen hidden menu entry never becomes the active link');
}
assert(loads.some(([view]) => view === 'usage'), 'Settings continue to use the existing view data loader');

// Exercise the shared display functions with the production point formatter.
// These are unit inputs, not injected browser/business success states.
const shellSource = read('public/geo-page-navigation.js');
const shellFunction = name => {
  const start = shellSource.indexOf(`  function ${name}(`);
  const end = shellSource.indexOf('\n  }', start);
  assert(start >= 0 && end > start, `${name}: complete shared production function`);
  return shellSource.slice(start, end + 4);
};
const shellNodes = { workspaceArticleCount: {} };
const display = { document: { getElementById: id => shellNodes[id] || null } };
display.window = display;
vm.createContext(display);
vm.runInContext(shellFunction('syncWorkspaceArticleCount'), display);
const articleState = { selectedProject: { id: 7 }, clAllTotal: null, clAllArticles: [] };
display.syncWorkspaceArticleCount(articleState);
assert.equal(shellNodes.workspaceArticleCount.hidden, true, 'No attention count before articles are loaded');
articleState.clAllTotal = 6;
articleState.clAllArticles = [
  { project_id: 7, status: 'pending' }, { project_id: 7, status: 'editing', reviewed_at: '2026-10-23' },
  { project_id: 7, status: 'pending', distribution_blocked_reason: 'blocked' },
  { project_id: 7, status: 'draft' }, { project_id: 7, status: 'editing' }, { project_id: 8, status: 'pending' },
];
display.syncWorkspaceArticleCount(articleState);
assert.equal(shellNodes.workspaceArticleCount.textContent, '3', 'Blocked, pending and returned articles are counted once and scoped to the current project');
articleState.selectedProject.id = 9;
display.syncWorkspaceArticleCount(articleState);
assert.equal(shellNodes.workspaceArticleCount.hidden, true, 'Changing projects does not retain another project’s attention badge');

// R7: data owners notify the shell directly; counters do not depend on panel DOM mutations.
assert.doesNotMatch(shellSource, /MutationObserver/, 'Shell counters must not observe panel markup');
assert.match(shellSource, /new ResizeObserver\(syncHeaderHeight\)/, 'The unrelated header size observer stays intact');
display.document.querySelectorAll = () => [];
vm.runInContext(shellSource, display);
assert.equal(display.GEO_SHELL.syncArticleCount.length, 0, 'Article notifications need no state argument');
assert.doesNotThrow(() => { display.GEO_SHELL.syncArticleCount(); }, 'Notifications are safe before shell initialization');
const notifiedState = {
  billingOverview: null, selectedProject: { id: 7 }, clAllTotal: null, clAllArticles: [],
  contentLibrary: [], clActiveTab: 'all', clRecycledTotal: 0,
};
display.GEO_SHELL.init(notifiedState, () => {}, {});
assert.equal(shellNodes.workspaceArticleCount.hidden, true);

const notificationCalls = { points: 0, articles: 0 };
const notificationBridge = {
  GEO: { viewExtrasFor: () => [] },
  GEO_SHELL: {
    syncArticleCount(...args) {
      assert.equal(args.length, 0);
      notificationCalls.articles++;
      display.GEO_SHELL.syncArticleCount();
    },
  },
};
function dataFunction(source, name) {
  const start = new RegExp(`^  (?:async )?function ${name}\\(`, 'm').exec(source);
  assert(start, `${name}: data owner function exists`);
  return source.slice(start.index, source.indexOf('\n  }', start.index) + 4);
}
function runDataFunctions(source, names, dependencies, prelude = '') {
  return new Function(...Object.keys(dependencies), `${prelude}\n${names.map(name => dataFunction(source, name)).join('\n')}\nreturn { ${names.join(', ')} };`)(...Object.values(dependencies));
}
const noop = () => {};
const librarySource = read('public/geo-entry-content-library.js');
const articleWriters = ['loadContentLibrary', 'clRefreshRow', 'clApplyArticle', 'clRecycle', 'clSaveDrawerArticle'];
for (const name of articleWriters) {
  assert.match(dataFunction(librarySource, name), /globalThis\.GEO_SHELL\?\.syncArticleCount\?\.\(\)/, `${name}: article state writes notify directly`);
}
assert.doesNotMatch(dataFunction(librarySource, 'renderContentLibrary'), /syncArticleCount/, 'Article counts are independent of list rendering');
let fetchedArticle = { id: 1, project_id: 7, status: 'pending' };
const libraryData = runDataFunctions(librarySource, articleWriters, {
  state: notifiedState, globalThis: notificationBridge,
  elements: { contentLibraryPanel: {}, clDrawerMask: { hidden: true } },
  api: async () => ({ data: fetchedArticle }),
  clFetchAll: async options => ({ rows: options.status === 'recycled' ? [] : [fetchedArticle], truncated: !options.status }),
  clIsArticle: () => true, clSetState: noop, clUpdateTabCounts: noop, clRenderTruncNote: noop,
  renderContentLibrary: noop, fillErrorNo: error => { throw error; }, clTabMatches: () => true,
  confirmWorkspaceAction: async () => true, clToast: noop, setMessage: noop,
  clOpenDrawer: async () => {}, clDrawerState: { trigger: null }, validationMessage: String,
  FormData: class { get() { return ''; } },
}, 'let contentLibraryRequest = 0;');
await libraryData.loadContentLibrary(7);
assert.equal(shellNodes.workspaceArticleCount.textContent, '1+', 'Loading the real article list uses the new truncated state without rendering panel DOM');
fetchedArticle = { ...fetchedArticle, status: 'draft' };
await libraryData.clRefreshRow(1);
assert.equal(shellNodes.workspaceArticleCount.hidden, true, 'Refreshing a row clears resolved attention');
libraryData.clApplyArticle({ ...fetchedArticle, status: 'editing', reviewed_at: '2026-10-23' });
assert.equal(shellNodes.workspaceArticleCount.textContent, '1+', 'The shared review state writer updates attention immediately');
fetchedArticle = { ...fetchedArticle, status: 'published' };
await libraryData.clSaveDrawerArticle({ preventDefault() {}, currentTarget: { querySelector: () => ({}) } }, 1);
assert.equal(shellNodes.workspaceArticleCount.hidden, true, 'Saving an edit synchronizes the replaced article');
notifiedState.clDrawerArticle = null;
await libraryData.clRecycle(1);
assert.equal(notificationCalls.articles, 5, 'Every real article writer notifies once after its state change');
assert.equal(notifiedState.clAllTotal, 0, 'The recycle business state is unchanged');
assert.equal(shellNodes.workspaceArticleCount.hidden, true);
assert.match(read('public/geo-entry-projects.js'), /state\.selectedProject = project;\s*globalThis\.GEO_SHELL\?\.syncArticleCount\?\.\(\)/, 'Project selection immediately clears stale attention');
notifiedState.clAllArticles = [{ project_id: 7, status: 'pending' }];
notifiedState.clAllTotal = 1;
notifiedState.selectedProject = { id: 7 };
display.GEO_SHELL.syncArticleCount();
assert.equal(shellNodes.workspaceArticleCount.hidden, false);
notifiedState.selectedProject = { id: 8 };
display.GEO_SHELL.syncArticleCount();
assert.equal(shellNodes.workspaceArticleCount.hidden, true, 'A zero-argument notification reads the current project from the retained state');

console.log('Unified workspace shell contracts passed.');

// B4: exercise stored summaries against the real standalone activation.
{
function fn(source, name) {
  const start = source.indexOf('function ' + name + '(');
  assert(start >= 0, name + ' exists');
  let braces = 0;
  for (let at = source.indexOf('{', start); at < source.length; at++) {
    if (source[at] === '{') braces++;
    if (source[at] === '}' && --braces === 0) return source.slice(start, at + 1);
  }
}
const storage = new Map();
const localStorage = { getItem: key => storage.get(key) ?? null, setItem: (key, value) => storage.set(key, value), removeItem: key => storage.delete(key) };
const entry = read('public/geo-entry.js');
const entryContext = {
  localStorage, URL, window: { location: { href: 'http://127.0.0.1:8021/?project_id=13#projects' } },
  history: { replaceState(...args) { this.args = args; } },
  tenantSubdomain: () => 'alpha', rememberRecentProject() {}, lastProjectKeyPrefix: 'geo_last_project_id',
};
vm.createContext(entryContext);
vm.runInContext(entry.match(/const STAGE_FULL_LABELS = \{[\s\S]*?\n  \};/)[0], entryContext);
for (const name of ['projectSelectionStorageKey', 'rememberProject', 'forgetRememberedProject']) vm.runInContext(fn(entry, name), entryContext);
entryContext.rememberProject({ id: 17, name: '<img onerror=alert(1)> 项目A', current_stage: 2 });
assert.equal(storage.get('geo_last_project_id:alpha'), '17');
assert.deepEqual(JSON.parse(storage.get('geo_last_project_summary:alpha')), { id: 17, name: '<img onerror=alert(1)> 项目A', current_stage: 2, stage_label: '内容生产' });
const node = () => ({ dataset: {}, hidden: false, disabled: true, inert: true, addEventListener() {}, setAttribute() {}, focus() {}, classList: { contains: () => false, toggle() {} } });
const ids = Object.fromEntries(['geoPageSidebar', 'geoPageMenuToggle', 'geoPageCurrentProject', 'geoPageProjectName', 'geoPageProjectStage'].map(id => [id, node()]));
ids.geoPageSidebar.querySelectorAll = () => [];
const document = { getElementById: id => ids[id] ?? null, querySelector: () => null, addEventListener() {} };
const window = { location: { search: '?project_id=17' }, addEventListener() {}, innerWidth: 1440 };
vm.runInNewContext(read('public/geo-page-navigation.js'), { document, window, localStorage, URLSearchParams, Number, String, JSON });
const activate = () => window.GEO_PAGE_NAV.activate({ user: { role: 'admin' }, tenant: { subdomain: 'alpha' } });
activate();
assert.equal(ids.geoPageProjectName.textContent, '<img onerror=alert(1)> 项目A');
assert.equal(ids.geoPageProjectName.innerHTML, undefined, 'name is written as text, never HTML');
assert.equal(ids.geoPageProjectStage.textContent, '阶段 3/6 · 内容生产');
assert.equal(ids.geoPageProjectStage.hidden, false);
assert.equal(ids.geoPageCurrentProject.href, '/?project_id=17#projects');
entryContext.rememberProject({ id: 17, name: '<img onerror=alert(1)> 项目A', current_stage: 3 });
activate();
assert.equal(ids.geoPageProjectStage.textContent, '阶段 4/6 · ' + vm.runInContext('STAGE_FULL_LABELS[3]', entryContext), 'The standalone phase label comes directly from the workbench summary');
storage.set('geo_last_project_summary:alpha', JSON.stringify({ id: 17, name: '<img onerror=alert(1)> 项目A', current_stage: 3 }));
activate();
assert.equal(ids.geoPageProjectStage.textContent, '阶段 4/6', 'Old summaries show the phase number without inventing a name');
storage.set('geo_last_project_summary:alpha', JSON.stringify({ id: 17, name: '<img onerror=alert(1)> 项目A', current_stage: 3, stage_label: '<img onerror=alert(1)>' }));
activate();
assert.equal(ids.geoPageProjectStage.textContent, '阶段 4/6 · <img onerror=alert(1)>');
assert.equal(ids.geoPageProjectStage.innerHTML, undefined, 'Stored phase labels use textContent, never HTML');
assert.doesNotMatch(read('public/geo-page-navigation.js'), /stageLabels|研究拓词|策略诊断|内容生产|渠道发布|多站发布|效果监测|迭代优化/, 'Standalone script has no independent phase-name list');
window.location.search = '?project_id=18';
activate();
assert.equal(ids.geoPageProjectName.textContent, '当前项目');
assert.equal(ids.geoPageProjectStage.hidden, true);
assert.equal(ids.geoPageProjectStage.textContent, '');
assert.equal(ids.geoPageCurrentProject.href, '/?project_id=18#projects');
window.location.search = '';
activate();
assert.equal(ids.geoPageProjectName.textContent, '<img onerror=alert(1)> 项目A', 'remembered id is used only without a URL project');
for (const summary of [null, '{broken-json', JSON.stringify({ id: 18, name: '别的项目', current_stage: 1 })]) {
  if (summary === null) storage.delete('geo_last_project_summary:alpha'); else storage.set('geo_last_project_summary:alpha', summary);
  activate();
  assert.equal(ids.geoPageProjectName.textContent, '当前项目');
  assert.equal(ids.geoPageProjectStage.hidden, true);
}
entryContext.forgetRememberedProject();
assert.equal(storage.get('geo_last_project_id:alpha'), undefined);
assert.equal(storage.get('geo_last_project_summary:alpha'), undefined);
activate();
assert.equal(ids.geoPageCurrentProject.hidden, false, 'card stays visible when no project id exists');
assert.equal(ids.geoPageCurrentProject.href, '/#projects');
assert.equal(ids.geoPageProjectName.textContent, '当前项目');
assert.equal(ids.geoPageProjectStage.hidden, true);
console.log('B4 checks passed: selected-project summary, text-only name, phase, URL precedence, mismatch/missing/corrupt/empty-storage fallback, visible fallback card, cleanup.');
console.log('R-B1 checks passed: shared stage-3 label, old-summary phase-number fallback, text-only cached labels, no standalone phase-name list.');
}

// B1: the real search index uses the shared definition and only loaded project data.
{
  assert.match(topbar, /WorkspaceNavigation::menus\(\)/);
  assert.match(topbar, /<script type="application\/json" id="workspaceNavigationIndex" nonce="\{\{ \$cspNonce \}\}">@json\(\$workspaceSearchIndex\)<\/script>/);
  const commandSource = shellFunction('initWorkspaceGlobalActions');
  assert.doesNotMatch(commandSource, /innerHTML|insertAdjacentHTML|outerHTML/);
  assert.match(commandSource, /label\.textContent = row\.label/);
  assert.match(commandSource, /empty\.textContent = '没有找到「'/);
  for (const key of ['ArrowDown', 'ArrowUp', 'Enter', 'Escape']) assert(commandSource.includes(key));
  assert.match(commandSource, /event\.metaKey \|\| event\.ctrlKey/);
  assert.match(commandSource, /workspace-topbar'\)\?\.inert/);
  const index = entries.filter(item => !item.hidden).map(item => ({
    ...item, platformOnly: item.group === 'platform', group: item.group,
  }));
  const context = {
    workspaceShellState: null, workspacePageSession: { user: { role: 'member' } },
    document: { getElementById: () => ({ textContent: JSON.stringify(index) }) },
  };
  vm.createContext(context);
  vm.runInContext(shellFunction('workspaceCommandRows'), context);
  assert.equal(context.workspaceCommandRows('写新文章')[0].action, 'write');
  assert.equal(context.workspaceCommandRows('文章').some(row => row.kind === '文章'), false, 'Unloaded articles are never fabricated');
  assert.equal(context.workspaceCommandRows('手动发布').length, 0, 'Restricted pages remain absent for member');
  assert.equal(context.workspaceCommandRows('团队管理').length, 0, 'Frozen entries never enter the search index');
  assert.equal(context.workspaceCommandRows('运营面板').length, 0);
  context.workspacePageSession.user.role = 'admin';
  assert.equal(context.workspaceCommandRows('手动发布')[0].page, 'manual-publish');
  context.workspaceShellState = { session: { user: { role: 'admin' } }, selectedProject: { id: 7 }, clAllTotal: 3, clAllArticles: [
    { id: 1, project_id: 7, status: 'draft', title: '<img onerror=alert(1)> 文本标题' },
    { id: 2, project_id: 8, status: 'draft', title: '别的项目' },
    { id: 3, project_id: 7, status: 'recycled', title: '回收站文章' },
  ] };
  assert.equal(context.workspaceCommandRows('<img onerror')[0].label, '<img onerror=alert(1)> 文本标题');
  assert.equal(context.workspaceCommandRows('别的项目').length, 0);
  assert.equal(context.workspaceCommandRows('回收站文章').length, 0);
  assert.equal(context.workspaceCommandRows('个人设置')[0].view, 'personal-settings');
  assert.match(read('public/geo-shell.css'), /max-width: 1199px/);
  console.log('B1 command search: shared navigation, role gates, loaded current-project titles, text-only labels and keyboard wiring passed.');
}

// B2: both original buttons and global actions share byte-identical extracted bodies.
{
  // 授权基线固定在 tests/fixtures/workspace-shell-b2-baseline（摘自 B2 的父提交 a435b01，见其 README）。
  const library = read('public/geo-entry-content-library.js');
  const oldProjectBody = read('tests/fixtures/workspace-shell-b2-baseline/new-project-handler-body.txt').replace(/\n$/, '');
  const newProjectBody = entry.match(/function openNewProject\(\) \{([\s\S]*?)\n  \}/)[1];
  assert.equal(newProjectBody, oldProjectBody, 'New-project handler body is byte-identical to the authorized baseline');
  const oldWriterBody = read('tests/fixtures/workspace-shell-b2-baseline/article-writer-handler-body.txt').replace(/\n$/, '');
  const newWriterBody = library.match(/function openArticleWriter\(\) \{([\s\S]*?)\n    \}/)[1];
  assert.equal(newWriterBody, oldWriterBody, 'Writer handler body is byte-identical to the authorized baseline');
  assert.match(entry, /newProjectButton\?\.addEventListener\("click", openNewProject\)/);
  assert.match(library, /clNewArticleButton\?\.addEventListener\("click", openArticleWriter\)/);
  const actionTags = [...topbar.matchAll(/<button\b[^>]*data-shell-action="([^"]+)"[^>]*>/g)];
  assert.deepEqual(actionTags.map(match => match[1]), ['write', 'skill', 'import', 'project']);
  actionTags.forEach(([tag]) => assert.doesNotMatch(tag, /data-view-target/));
  const called = [];
  const actionContext = { workspaceShellState: {}, workspaceShellNavigate: view => called.push(view),
    GEO: { openArticleWriter: () => called.push('original-writer'), openNewProject: () => called.push('original-project') } };
  vm.createContext(actionContext);
  vm.runInContext(shellFunction('runWorkspaceAction'), actionContext);
  ['write', 'skill', 'import', 'project'].forEach(actionContext.runWorkspaceAction);
  assert.deepEqual(called, ['original-writer', 'skill', 'url-imports', 'original-project']);
  called.length = 0; actionContext.workspaceShellState = null;
  ['write', 'skill', 'import', 'project'].forEach(actionContext.runWorkspaceAction);
  assert.deepEqual(called, ['content-library', 'skill', 'url-imports', 'projects/new'], 'Standalone write only navigates, without invoking the writer');
  console.log('B2 shared named handlers: exact baseline bodies, original/new bindings and standalone destinations passed.');
}

// B3: execute the production setup readers with API and race fixtures.
{
const currentShell = read('public/geo-page-navigation.js');
const source = currentShell.slice(currentShell.indexOf('  // B3 uses only'), currentShell.indexOf('  function initWorkspaceShell('));
function env(reply){
 const nodes=Object.fromEntries(['channels','visibility','beacon'].map(x=>[x,{hidden:true,dataset:{},events:{},addEventListener(k,fn){this.events[k]=fn;}}]));
 let ctx={session:{user:{id:1},tenant:{id:3,subdomain:'demo'}},projectId:1,token:'t',mode:'spa',setActiveWorkspaceView:(...args)=>calls.push(['navigate',...args])};
 const calls=[], requests=[];
 const globals={console,URL,Map,Promise,Date,Number,history:{replaceState(){}},window:{location:{href:'http://127.0.0.1:8021/#projects'}},document:{querySelector:q=>nodes[/="([^"]+)"/.exec(q)?.[1]]||null,getElementById:()=>null},fetch:(path,opts)=>{requests.push({path,opts});return Promise.resolve(reply(path,opts));}};
 vm.createContext(globals);vm.runInContext(source+'\nthis.api={initWorkspaceSetup,syncWorkspaceSetup};',globals);globals.api.initWorkspaceSetup(()=>ctx);
 return {nodes, requests,calls,api:globals.api,setContext:x=>ctx=x,getContext:()=>ctx};
}
function ok(data){return{ok:true,json:async()=>data}}
const noVisits=p=>p.includes('crawler-breakdown')?ok({kpis:{pv:0}}):null;
await (async()=>{
const normal=env(p=>noVisits(p)||ok(p.includes('channels')?{data:[]}:p.includes('beacon')?{data:[]}:{data:[]}));
await normal.api.syncWorkspaceSetup();
assert.equal(normal.nodes.channels.textContent,'绑渠道');assert.equal(normal.nodes.visibility.textContent,'去监测');assert.equal(normal.nodes.beacon.textContent,'未接入');assert.equal(normal.requests.length,4);
assert(normal.requests.some(x=>x.path==='/api/v1/analytics/crawler-breakdown?days=366'),'未接入 also reads one year of site visits');
normal.setContext({...normal.getContext(),projectId:2});await normal.api.syncWorkspaceSetup();assert.equal(normal.requests.length,5);assert.equal(normal.requests.filter(x=>x.path.includes('/channels')).length,1);assert.equal(normal.requests.filter(x=>x.path.includes('crawler-breakdown')).length,1);assert.equal(normal.requests.filter(x=>x.path.includes('channel-beacon')).length,1);
assert(normal.requests.every(x=>x.opts.method==='GET'&&x.opts.headers.Authorization==='Bearer t'&&x.opts.headers['X-Tenant']==='demo'));
const stale=env(p=>ok(p.includes('channels')?{data:[{is_active:true}]}:p.includes('beacon')?{data:[{beaconing:false,last_beacon_at:'2020-01-01'}],beacon_threshold_days:7}:{data:[{id:9}]}));await stale.api.syncWorkspaceSetup();assert(stale.nodes.channels.hidden);assert(stale.nodes.visibility.hidden);assert.equal(stale.nodes.beacon.textContent,'信标异常');
for(const failed of ['channels','visibility','beacon']){const paths={channels:'/publishing/channels',visibility:'/visibility/runs',beacon:'/analytics/channel-beacon-health'};const test=env(p=>p.includes(paths[failed])?{ok:false}:noVisits(p)||ok({data:[]}));await test.api.syncWorkspaceSetup();assert(test.nodes[failed].hidden);assert(Object.entries(test.nodes).filter(([key])=>key!==failed).every(([,node])=>!node.hidden));}
for(const visits of [ok({kpis:{pv:812}}),{ok:false},ok({data:[]})]){const t=env(p=>p.includes('crawler-breakdown')?visits:ok({data:[]}));await t.api.syncWorkspaceSetup();assert(t.nodes.beacon.hidden,'信标为空但有访问、或访问读不到时不显示「未接入」');}
let resolveA;const race=env(p=>p.includes('project_id=1')?new Promise(r=>resolveA=r):ok(p.includes('visibility')?{data:[{id:2}]}:{data:[]}));let first=race.api.syncWorkspaceSetup();race.setContext({...race.getContext(),projectId:2});await race.api.syncWorkspaceSetup();resolveA(ok({data:[]}));await first;assert(race.nodes.visibility.hidden);assert.equal(race.nodes.channels.textContent,'绑渠道');
const empty=env(()=>{throw new Error('should never request')});empty.setContext({...empty.getContext(),projectId:null});await empty.api.syncWorkspaceSetup();assert.equal(empty.requests.length,0);
console.log('PASS B3 snippets: four label states, beacon needs zero visits for 未接入, 3 isolated HTTP500 failures, no pre-project requests, tenant cache, project-switch response race, GET headers.');
})();

}

// B5: removed page identity nodes have no remaining JS writes or lookups.
for (const file of ['public/geo-analytics.js', 'public/geo-visibility.js', 'public/geo-theme-package.js', 'public/geo-manual-publish.js']) {
  assert.doesNotMatch(read(file), /gaWho|gvWho|tpWho|tpAvatar/);
}
console.log('B5 retired identity IDs are absent from standalone scripts.');

// B3: a cross-page setup action focuses only after initial panel inert is released.
{
  let frame = null, focusCount = 0;
  const panel = { hidden: false, inert: true }, form = { hidden: true };
  const nodes = { publishingPanel: panel, channelForm: form,
    addChannelButton: { click() { form.hidden = false; } },
    createChannelForm: { elements: { name: { focus() { focusCount++; } } } } };
  const context = { document: { getElementById: id => nodes[id] }, window: { requestAnimationFrame: fn => { frame = fn; } } };
  vm.createContext(context);
  const freshShell = read('public/geo-page-navigation.js');
  const start = freshShell.indexOf('  function openWorkspaceChannelForm(');
  vm.runInContext(freshShell.slice(start, freshShell.indexOf('\n  }', start) + 4), context);
  context.openWorkspaceChannelForm({ mode: 'spa', setActiveWorkspaceView() {} });
  assert.equal(form.hidden, false); assert.equal(focusCount, 0);
  panel.inert = false; frame(); assert.equal(focusCount, 1);
  panel.hidden = true; frame(); assert.equal(focusCount, 1, 'Deferred focus never steals focus after leaving publishing');
}

// R-B3: reviewed statements stay on separate lines and the standalone card has a token gap.
assert.match(entry, /setItem\(projectSelectionStorageKey\(\), String\(project\.id\)\);\n\s+localStorage\.setItem\(`geo_last_project_summary:/);
assert.match(entry, /removeItem\(projectSelectionStorageKey\(\)\);\n\s+localStorage\.removeItem\(`geo_last_project_summary:/);
assert.match(entry, /\n  \}\n  elements\.newProjectButton\?\.addEventListener\("click", openNewProject\);/);
assert.match(read('public/geo-shell.css'), /#geoPageCurrentProject\s*\{[^}]*margin-bottom: var\(--md-space-2\);/);
console.log('R-B3 formatting and standalone project-card spacing passed.');

// R-B2: invalidate only the written setup state, then reread via the existing GET readers.
{
  const shell = read('public/geo-page-navigation.js');
  assert.match(shell, /invalidateSetup:\s*invalidateWorkspaceSetup/);
  const setup = shell.slice(shell.indexOf('  // B3 uses only'), shell.indexOf('  function initWorkspaceShell('));
  const nodes = Object.fromEntries(['channels', 'visibility', 'beacon'].map(kind => [kind, {
    hidden: true, dataset: {}, addEventListener() {},
  }]));
  let current = { session: { user: { id: 1 }, tenant: { id: 3, subdomain: 'demo' } }, projectId: 7, token: 't', mode: 'spa' };
  let channels = [], runs = [];
  const requests = [];
  const context = {
    URL, Map, Promise, Date, Number,
    window: { location: { href: 'http://127.0.0.1:8021/#publishing' } },
    document: { querySelector: selector => nodes[/="([^"]+)"/.exec(selector)?.[1]] || null },
    fetch: async (path, options) => {
      requests.push({ path, options });
      return { ok: true, json: async () => ({ data: path.includes('/publishing/channels') ? channels : path.includes('/visibility/runs') ? runs : [] }) };
    },
  };
  vm.createContext(context);
  vm.runInContext(setup + '\nthis.api = { initWorkspaceSetup, syncWorkspaceSetup, invalidateWorkspaceSetup };', context);
  context.api.initWorkspaceSetup(() => current);
  await context.api.syncWorkspaceSetup();
  assert.equal(requests.length, 4);
  assert.equal(nodes.channels.textContent, '绑渠道');
  assert.equal(nodes.visibility.textContent, '去监测');
  channels = [{ id: 12, is_active: true }];
  await context.api.invalidateWorkspaceSetup('channels');
  assert.equal(requests.length, 5, 'Successful channel create rereads only channels');
  assert.equal(requests.at(-1).path, '/api/v1/publishing/channels');
  assert.equal(nodes.channels.hidden, true, 'Channel hint disappears without reloading the document');
  channels = [];
  await context.api.invalidateWorkspaceSetup('channels');
  assert.equal(requests.length, 6, 'Successful channel delete rereads only channels');
  assert.equal(nodes.channels.hidden, false);
  assert.equal(nodes.channels.textContent, '绑渠道');
  runs = [{ id: 90, status: 'pending' }];
  await context.api.invalidateWorkspaceSetup('visibility');
  assert.equal(requests.length, 7, 'Confirmed pending monitoring rereads only the current project');
  assert.equal(requests.at(-1).path, '/api/v1/visibility/runs?project_id=7&per_page=10');
  assert.equal(nodes.visibility.hidden, true, 'A confirmed pending run removes the first-monitoring hint');
  assert.equal(requests.filter(request => request.path.includes('channel-beacon')).length, 1, 'Beacon cache stays intact');
  assert.equal(requests.filter(request => request.path.includes('crawler-breakdown')).length, 1, 'Visit totals share the beacon tenant cache');
  assert.equal(requests.filter(request => request.path.includes('/publishing/channels')).length, 3);
  current = { ...current, projectId: 8 };
  await context.api.syncWorkspaceSetup();
  assert.equal(requests.length, 8);
  current = { ...current, projectId: 7 };
  await context.api.syncWorkspaceSetup();
  assert.equal(requests.length, 8, 'Other project requests do not evict the current project cache');
  await context.api.invalidateWorkspaceSetup('unknown');
  assert.equal(requests.length, 8, 'Unknown notifications do not evict caches');
  current = { ...current, projectId: null };
  await context.api.invalidateWorkspaceSetup('visibility');
  assert.equal(requests.length, 8, 'No setup request without a selected project');
  assert(requests.every(request => request.options.method === 'GET'), 'Invalidation adds no write request');
}

// R-B2: run the actual channel owners and visibility callback to verify success-only notification.
{
  const publishing = read('public/geo-entry-publishing.js');
  for (const name of ['createChannel', 'deleteChannel']) {
    const body = dataFunction(publishing, name);
    const notification = "globalThis.GEO_SHELL?.invalidateSetup?.('channels')";
    assert.equal(body.split(notification).length - 1, 1, name + ': exactly one notification');
    assert(body.indexOf('await api(') < body.indexOf(notification), name + ': request must resolve first');
    assert(body.indexOf(notification) < body.indexOf('} catch (error)'), name + ': notification is in the success path');
    const calls = [];
    let fails = false;
    const run = new Function('state', 'elements', 'api', 'globalThis', 'FormData', 'confirmWorkspaceAction',
      'setMessage', 'validationMessage', 'syncCreateChannelFields', 'setChannelFormOpen', 'loadChannels', 'closeChannelDetail',
      body + '\nreturn ' + name + ';')(
      { selectedChannelId: 12 }, {}, async (_path, options) => { calls.push(options.method); if (fails) throw Error('failed write'); },
      { GEO_SHELL: { invalidateSetup(kind) { calls.push('notify:' + kind); } } },
      class { get() { return ''; } }, async () => true, () => {}, () => 'failed write', () => {}, () => {}, async () => {}, () => {});
    const event = { preventDefault() {}, currentTarget: { querySelector: () => ({}), reset() {} } };
    await run(event);
    assert.deepEqual(calls, [name === 'createChannel' ? 'POST' : 'DELETE', 'notify:channels']);
    calls.length = 0; fails = true;
    await run(event);
    assert.deepEqual(calls, [name === 'createChannel' ? 'POST' : 'DELETE'], name + ': rejected write never notifies');
  }
  const visibility = read('public/geo-visibility.js');
  const begin = visibility.indexOf("form.addEventListener('submit', function (e) {") + "form.addEventListener('submit', ".length;
  const finish = visibility.indexOf('\n    });\n    var projectSelect', begin);
  assert(begin > 0 && finish > begin, 'The real monitoring submit callback is identifiable');
  const callback = visibility.slice(begin, finish) + '\n    }';
  const notification = "globalThis.GEO_SHELL?.invalidateSetup?.('visibility')";
  assert.equal(callback.split(notification).length - 1, 1);
  assert(callback.indexOf("throw new Error('服务端返回了无法确认的提交结果')") < callback.indexOf(notification));
  assert(callback.indexOf(notification) < callback.indexOf('var first ='), 'Notify immediately after batch/run validation');
  assert(callback.indexOf(notification) < callback.indexOf('.catch(function'), 'Notification is outside failure handlers');
  for (const result of [
    { batch_id: 'batch-90', runs: [{ id: 90, status: 'pending' }] },
    null, {}, { batch_id: 'invalid', runs: [] }, { runs: [{ id: 90 }] },
  ]) {
    const calls = [];
    const nodes = { projectId: { value: '7', disabled: false }, questions: { value: '测试问题', disabled: false }, submitBtn: {}, formMsg: {} };
    const dependencies = {
      $: selector => nodes[selector.slice(1)] || null, MAX_QUESTIONS: 10, questionLines: value => [value],
      stopSubmittedBatchTracking() {}, renderQuestionSuggestions() {}, updateQuestionCount() {},
      pendingIdempotencyRequest: () => ({ key: 'existing-key' }), adoptClaimedVisibilityKey() {},
      apiPost: async (path, _payload, key) => {
        calls.push(path);
        if (path.endsWith('/claim')) return { ok: true, request_key: 'existing-key' };
        if (path.endsWith('/seen')) return { ok: true };
        assert.equal(key, 'existing-key', 'The existing paid submission key is forwarded unchanged');
        return result;
      },
      globalThis: { GEO_SHELL: { invalidateSetup: kind => calls.push('notify:' + kind) } },
      startSubmittedBatchTracking() {}, openDetail() {}, loadList() {}, clearPendingIdempotencyRequest() {},
      retainPendingIdempotencyRequest: () => true, window: { location: {} },
    };
    const submit = new Function(...Object.keys(dependencies),
      'var monitoringConfigured = true, visibilitySubmitInFlight = false, submittedRunId = null; return (' + callback + ');')(...Object.values(dependencies));
    submit({ preventDefault() {} });
    await new Promise(resolve => setImmediate(resolve));
    const valid = Boolean(result?.batch_id && result.runs?.length);
    assert.equal(calls.filter(call => call === 'notify:visibility').length, valid ? 1 : 0,
      'Only a confirmed batch, including pending runs, invalidates monitoring state');
    if (valid) assert(calls.indexOf('/api/v1/visibility/runs') < calls.indexOf('notify:visibility'));
    else assert(!calls.includes('/api/v1/visibility/runs/seen'), 'Invalid results keep the existing recovery path');
  }
  console.log('R-B2 passed: exact cache invalidation/rereads, channel create/delete success and failure, confirmed pending visibility and invalid-result negatives.');
}
