import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const read = name => readFileSync(new URL(`../../public/${name}`, import.meta.url), 'utf8');
const projects = read('geo-entry-projects.js');
function extract(name, source = projects) {
    const start = source.indexOf(`  function ${name}(`);
    assert.ok(start >= 0, `${name} exists in the real owning module`);
    let depth = 0;
    const open = source.indexOf('{', start);
    for (let i = open; i < source.length; i++) {
        if (source[i] === '{') depth++;
        if (source[i] === '}' && --depth === 0) return source.slice(start, i + 1);
    }
    throw new Error(`Unclosed ${name}`);
}
const skills = [
    { id: 'first', name: '已完成技能', stage_index: 1 },
    { id: 'second', name: '待执行技能', stage_index: 1 },
];
const GEO = {
    state: { skills }, api: async () => { throw new Error('No request expected in pure queue tests'); },
    STAGE_FULL_LABELS: ['研究', '策略', '内容', '发布', '监测', '迭代'],
};
const document = { getElementById: () => null, querySelector: () => null, addEventListener() {} };
const context = vm.createContext({ GEO, window: { GEO, addEventListener() {} }, document, console, Date, URL, Number });
vm.runInContext(['resolveCurrentStageIndex', 'listToText', 'hasDossierValue'].map(name => extract(name)).join('\n'), context);
for (const name of ['resolveCurrentStageIndex', 'hasDossierValue']) GEO[name] = context[name];
vm.runInContext(read('geo-entry-project-home.js'), context);
const base = {
    id: 9, current_stage: 1, status: 'active', brand_name: 'Brand', brand_url: 'https://example.test',
    settings: { target_market: '市场', product_description: '说明', target_keywords: ['词'], competitors: ['竞品'], goals: '目标' },
    stages: [{ stage_index: 1, status: 'active' }],
    workflow_recommendations: [{ stage_index: 1, completed_skill_ids: ['first'] }],
    current_stage_requirements: { met: true, requirements: [] },
};
const timeline = [{ stage_index: 1, skills: ['已完成技能', '待执行技能', '无法匹配的显示名'] }];
const articles = [
    { id: 1, title: '<img src=x>', distribution_blocked_reason: '拦截第一句。第二句', status: 'published' },
    { id: 2, title: '待审一', status: 'pending' }, { id: 3, title: '待审二', status: 'pending' },
    { id: 4, title: '退回一', status: 'editing', reviewed_at: '2026-10-23', review_notes: '真实退回意见', review_note: '错误字段' },
    { id: 5, title: '退回二', status: 'editing', reviewed_at: '2026-10-23', review_notes: '' },
];
let items = GEO.projectHomeQueueItems({ ...base, brand_url: '' }, articles, timeline);
assert.deepEqual(Array.from(items, item => item.kind), ['blocked', 'pending', 'returned', 'returned', 'stage', 'profile', 'advance']);
assert.equal(items[0].why, '拦截第一句');
assert.equal(items[1].why, '《待审一》、《待审二》');
assert.equal(items[2].why, '真实退回意见');
assert.equal(items[3].why, '没有填写退回意见');
assert.equal(items[4].skill.id, 'second', 'completed IDs are not offered as unrun tasks');
assert.equal(items.filter(item => item.kind === 'stage').length, 1, 'unmatched timeline name cannot become an executable skill');
assert.equal(items[5].why, '官网', 'missing dossier uses the actual shared definition');
items = GEO.projectHomeQueueItems({ ...base, current_stage_requirements: { met: false, requirements: ['真实条件'] } }, [], timeline);
assert.equal(items.find(item => item.kind === 'stage').why, '真实条件');
assert.ok(!items.some(item => item.kind === 'advance'), 'no inferred advance when server requirements are unmet');
items = GEO.projectHomeQueueItems({ ...base, status: 'completed' }, [], timeline);
assert.equal(items.length, 0, 'completed project does not offer stage execution or advance');
items = GEO.projectHomeQueueItems({ ...base, current_stage: 5, stages: [{ stage_index: 5, status: 'active' }] }, [], []);
assert.equal(items.at(-1).kind, 'complete');
assert.equal(items.at(-1).action, '完成项目');
items = GEO.projectHomeQueueItems({ ...base, current_stage_requirements: {} }, [], []);
assert.ok(!items.some(item => ['advance', 'complete'].includes(item.kind)), 'unknown requirements never imply success');
console.log('Project home: ordered queue, real review_notes, shared dossier definition, skill identity, server requirements and completion passed');

// Exercise the actual mounted module and its delegated callers. The existing
// article reset helper and route filter branch run from their real source too.
class HomeNode {
    hidden = false;
    textContent = '';
    innerHTML = '';
    value = '';
    dataset = {};
    children = [];
    attributes = {};
    listeners = new Map();
    isConnected = true;
    setAttribute(name, value) { this.attributes[name] = value; }
    addEventListener(type, callback) {
        this.listeners.set(type, [...(this.listeners.get(type) || []), callback]);
    }
    append(...nodes) { this.children.push(...nodes); }
    replaceChildren(...nodes) { this.children = [...nodes]; }
    focus() {}
    closest(selector) {
        for (const [attribute, key] of [['project-stage', 'projectStage'], ['project-queue-action', 'projectQueueAction']]) {
            if (selector === `[data-${attribute}]` && Object.hasOwn(this.dataset, key)) return this;
        }
        return null;
    }
    fire(type, event = {}) {
        const payload = { currentTarget: this, target: this, preventDefault() {}, ...event };
        for (const listener of this.listeners.get(type) || []) listener(payload);
    }
    click() { this.fire('click'); }
}

function mountHome({ articleRows = [], articlesFail = false, timelineFail = false, skillsFail = false } = {}) {
    const nodes = new Map();
    const node = id => {
        if (!nodes.has(id)) nodes.set(id, new HomeNode());
        return nodes.get(id);
    };
    const pageState = {
        session: { user: { id: 1 }, tenant: { id: 2 } }, selectedProject: null,
        skills: skillsFail ? [] : skills, clSearch: '', clContentType: '', clActiveTab: 'all',
    };
    const routes = [], resets = [], drawers = [];
    let editClicks = 0;
    const windowEvents = new HomeNode();
    const pageWindow = { location: { hash: '#projects' }, addEventListener: (...args) => windowEvents.addEventListener(...args) };
    const pageDocument = {
        activeElement: node('previous-focus'), getElementById: node, addEventListener() {},
        createElement: () => new HomeNode(),
        querySelector(selector) {
            const tab = selector.match(/^#clTabs \[data-tab="([^"]+)"\]$/)?.[1];
            return tab ? node(`tab-${tab}`) : null;
        },
    };
    node('settingsWrap').hidden = true;
    node('projectStageDrawerMask').hidden = true;
    node('editProjectButton').addEventListener('click', () => {
        editClicks++;
        node('settingsWrap').hidden = !node('settingsWrap').hidden;
    });
    for (const tab of ['all', 'pending', 'editing', 'published']) {
        node(`tab-${tab}`).addEventListener('click', () => { pageState.clActiveTab = tab; });
    }
    const pageGeo = {
        state: pageState, elements: { clSearchInput: node('clSearchInput') },
        STAGE_FULL_LABELS: GEO.STAGE_FULL_LABELS,
        resolveCurrentStageIndex: GEO.resolveCurrentStageIndex, hasDossierValue: GEO.hasDossierValue,
        formatNumber: String, escapeHtml: value => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;'),
        statusLabel: String, clIsArticle: () => true, legalProjectRollbackTargets: () => [],
        setMessage: (target, message) => { if (target) target.textContent = message; },
        clSelectTab: tab => { pageState.clActiveTab = tab; },
        clOpenDrawer: async article => { drawers.push(article.id); },
        loadSkills: async () => !skillsFail,
        clFetchAll: async () => {
            if (articlesFail) throw new Error('Article read failed');
            return { rows: articleRows, truncated: false };
        },
        api: async url => {
            if (url.endsWith('/timeline')) {
                if (timelineFail) throw new Error('Timeline read failed');
                return [];
            }
            if (url.includes('crawler-breakdown')) return { kpis: { ai_bot_pv: 0 } };
            if (url.includes('dashboard/activity')) return { data: [] };
            throw new Error(`Unexpected UI test request: ${url}`);
        },
    };
    pageWindow.GEO = pageGeo;
    pageWindow.GEO_SHELL = { readProjectVisibility: async () => ({ data: [], meta: { total: 0 } }) };
    const pageContext = vm.createContext({
        GEO: pageGeo, state: pageState, elements: pageGeo.elements,
        window: pageWindow, document: pageDocument, console, Date, URL, Number,
    });
    const entry = read('geo-entry.js');
    const filterStart = entry.indexOf('    if (view === "content-library") {');
    const filterEnd = entry.indexOf('\n    }', filterStart) + '\n    }'.length;
    assert.ok(filterStart >= 0 && filterEnd > filterStart, 'real content-library route filter branch exists');
    vm.runInContext(`globalThis.applyArticleRoute = (view, param) => {${entry.slice(filterStart, filterEnd)}};`, pageContext);
    pageGeo.openWorkspaceView = route => {
        routes.push(route);
        const [view, param = ''] = route.split('/');
        pageContext.applyArticleRoute(view, param);
    };
    pageContext.openWorkspaceView = pageGeo.openWorkspaceView;
    vm.runInContext(extract('openExistingArticles', read('geo-entry-skills.js')), pageContext);
    pageGeo.openExistingArticles = (...args) => {
        resets.push(args);
        return pageContext.openExistingArticles(...args);
    };
    vm.runInContext(read('geo-entry-project-home.js'), pageContext);
    return {
        GEO: pageGeo, state: pageState, node, routes, resets, drawers,
        editClicks: () => editClicks,
        flush: () => new Promise(resolve => setImmediate(resolve)),
        hashchange(hash) { pageWindow.location.hash = hash; windowEvents.fire('hashchange'); },
        clickQueue(index) {
            const button = new HomeNode();
            button.dataset.projectQueueAction = String(index);
            node('projectHomeQueue').fire('click', { target: button });
        },
    };
}

const profileHome = mountHome();
profileHome.state.selectedProject = { ...base, status: 'completed', brand_url: '' };
profileHome.GEO.renderProjectHome(profileHome.state.selectedProject);
await profileHome.flush();
profileHome.clickQueue(0);
assert.equal(profileHome.routes.at(-1), 'projects/settings');
assert.equal(profileHome.node('settingsWrap').hidden, false, 'profile action opens a closed editor');
profileHome.clickQueue(0);
assert.equal(profileHome.node('settingsWrap').hidden, false, 'repeated profile action leaves an already-open editor open');
assert.equal(profileHome.editClicks(), 1, 'profile caller does not toggle the existing editor twice');

const articleHome = mountHome({ articleRows: [{ id: 11, project_id: base.id, status: 'pending', title: '待审核文章' }] });
articleHome.state.selectedProject = { ...base, status: 'completed' };
articleHome.GEO.renderProjectHome(articleHome.state.selectedProject);
await articleHome.flush();
function setOldFilters(page) {
    page.state.clSearch = 'unrelated old search';
    page.state.clContentType = 'ranking';
    page.node('clSearchInput').value = page.state.clSearch;
    page.node('clContentType').value = page.state.clContentType;
}
setOldFilters(articleHome);
articleHome.clickQueue(0);
assert.equal(articleHome.resets.length, 1, 'pending action delegates to the existing article reset entry');
assert.equal(articleHome.state.clSearch, '');
assert.equal(articleHome.node('clSearchInput').value, '');
assert.equal(articleHome.state.clContentType, '', 'real route reset removes the prior content type');
assert.equal(articleHome.node('clContentType').value, '');
assert.equal(articleHome.state.clActiveTab, 'pending');
setOldFilters(articleHome);
articleHome.node('projectHomePublishedLink').click();
assert.equal(articleHome.resets.length, 2, 'published metric uses the same existing reset entry');
assert.equal(articleHome.state.clSearch, '');
assert.equal(articleHome.state.clContentType, '');
assert.equal(articleHome.state.clActiveTab, 'published');

const emptyHome = mountHome();
emptyHome.state.selectedProject = { ...base, status: 'completed' };
emptyHome.GEO.renderProjectHome(emptyHome.state.selectedProject);
assert.equal(emptyHome.node('projectHomeQueueEmpty').hidden, true, 'loading is not known-empty');
await emptyHome.flush();
assert.equal(emptyHome.node('projectHomeQueueEmpty').hidden, false, 'a successfully read empty queue retains its real empty state');
for (const [failure, notice] of [['articlesFail', 'projectHomeArticleNotice'], ['timelineFail', 'projectHomeStageNotice'], ['skillsFail', 'projectHomeStageNotice']]) {
    const page = mountHome({ [failure]: true });
    page.state.selectedProject = { ...base, status: 'completed' };
    page.GEO.renderProjectHome(page.state.selectedProject);
    await page.flush();
    assert.equal(page.node('projectHomeQueueEmpty').hidden, true, `${failure}: failed data cannot claim there is nothing to do`);
    assert.equal(page.node(notice).hidden, false, `${failure}: failure remains visible`);
    assert.match(page.node(notice).textContent, /读取失败/);
    if (failure === 'articlesFail') assert.equal(page.node('projectHomePublishedValue').textContent, '—');
}

const drawerHome = mountHome();
drawerHome.state.selectedProject = { ...base, status: 'completed' };
drawerHome.GEO.renderProjectHome(drawerHome.state.selectedProject);
await drawerHome.flush();
const stageButton = drawerHome.node('pjStageRail').children[2];
drawerHome.node('pjStageRail').fire('click', { target: stageButton });
assert.equal(drawerHome.node('projectStageDrawerMask').hidden, false);
assert.match(drawerHome.node('projectStageDrawerTitle').textContent, /阶段 3/);
drawerHome.hashchange('#asset');
assert.equal(drawerHome.node('projectStageDrawerMask').hidden, true, 'browser hash navigation closes the independent stage overlay');
console.log('Project home callers: idempotent profile editor, real article reset reuse, known-empty vs read failures, and drawer navigation passed');
