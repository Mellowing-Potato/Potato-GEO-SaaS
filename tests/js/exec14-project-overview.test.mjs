import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/geo-entry.js', import.meta.url), 'utf8');
const analyticsTemplate = readFileSync(new URL('../../resources/views/workbench/panels/analytics.blade.php', import.meta.url), 'utf8');
function extract(name) {
  const start = source.search(new RegExp(`^  (?:async )?function ${name}\\(`, 'm'));
  assert.ok(start >= 0, `Load actual ${name}`);
  const tail = source.slice(start);
  for (const closing of tail.matchAll(/^  \}/gm)) {
    const candidate = tail.slice(0, closing.index + closing[0].length);
    try {
      new vm.Script(candidate);
      return candidate;
    } catch (error) {
      if (!(error instanceof SyntaxError)) throw error;
    }
  }
  throw new Error(`Unclosed function ${name}`);
}
function compile(names, dependencies) {
  return new Function(...Object.keys(dependencies), `${names.map(extract).join('\n')}\nreturn {${names.join(',')}};`)(...Object.values(dependencies));
}
const escapeHtml = value => String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');
function node() {
  return {
    innerHTML: '', textContent: '', listeners: {},
    addEventListener(type, handler) { this.listeners[type] = handler; },
    querySelector() { return null; },
    querySelectorAll() { return []; },
  };
}
function analyticsFixture(projectId = 7) {
  const scopeHost = node();
  const scopeButtons = ['project', 'tenant'].map(scope => ({ ...node(), dataset: { analyticsScope: scope } }));
  scopeHost.querySelectorAll = selector => selector === '[data-analytics-scope]' ? scopeButtons : [];
  const articleLink = node();
  const summary = node();
  summary.closest = selector => selector === '.md-panel' ? { querySelector: value => value === '.analytics-scope' ? scopeHost : null } : null;
  summary.querySelector = selector => selector === '[data-analytics-articles]' && summary.innerHTML.includes('data-analytics-articles') ? articleLink : null;
  const content = node();
  content.querySelector = selector => selector === '.md-alert' && content.innerHTML.includes('md-alert') ? { remove() { content.innerHTML = ''; } } : null;
  const pendingButton = node();
  const reportsButton = node();
  const activityList = node();
  activityList.closest = selector => selector === '#dashboardActivity' ? {
    querySelector: value => value === '[data-analytics-pending-articles]' ? pendingButton : value === '[data-analytics-skill-reports]' ? reportsButton : null,
  } : null;
  const state = {
    analyticsTab: 'trends', analyticsScope: projectId ? 'project' : 'tenant', analyticsDays: 30,
    selectedProject: projectId ? { id: projectId } : null,
    analyticsData: {
      article_scope: { project_id: projectId },
      metrics: {
        projects_active: 3, projects_total: 13, articles_published: 5, articles_total: 17,
        report_assets_total: 19, executions_success: 23, executions_total: 29,
        publications_published: 31, publications_total: 37, tokens_used: 999,
      },
      ai_visibility: { state: 'sampled', mentions: 41, monitoring_url: '/visibility/' },
    },
    analyticsTrends: { articles: [], executions: [] },
    dashboardActivity: [],
  };
  const elements = { analyticsSummary: summary, analyticsContent: content, analyticsMessage: node(), activityList, clSearchInput: { value: '旧搜索' } };
  const navigations = [];
  const assetFilters = [];
  const dependencies = {
    state, elements, document: { querySelectorAll: () => [] }, escapeHtml, formatNumber: String,
    openWorkspaceView: view => navigations.push(view), loadAnalytics() {},
    openAssetsFiltered: type => assetFilters.push(type),
    renderAnalyticsModels() {}, renderAnalyticsProjects() {}, renderAnalyticsDistribution() {},
  };
  return { state, elements, scopeHost, scopeButtons, articleLink, pendingButton, reportsButton, navigations, assetFilters, dependencies };
}
const cardValues = html => [...html.matchAll(/<strong class="st-value">([^<]*)<\/strong>/g)].map(match => match[1]);

// Invoke the real summary renderer with distinct values, so a swapped field
// cannot pass merely because a metric and its label are both present.
for (const projectId of [7, null]) {
  const fixture = analyticsFixture(projectId);
  let loads = 0;
  fixture.dependencies.loadAnalytics = () => loads++;
  const handlers = compile(['renderAnalytics', 'renderAnalyticsTrends'], fixture.dependencies);
  handlers.renderAnalytics();
  const html = fixture.elements.analyticsSummary.innerHTML;
  assert.equal((html.match(/class="md-stat"/g) || []).length, projectId ? 4 : 5);
  assert.deepEqual(cardValues(html), projectId ? ['5 / 17', '19', '23 / 29', '31 / 37'] : ['5 / 17', '19', '23 / 29', '31 / 37', '3 / 13']);
  assert.ok(html.includes('文章 · 已发布 / 全部') && html.includes('报告资产'));
  assert.ok(html.includes('技能执行 · 成功 / 全部') && html.includes('渠道发布 · 已公开 / 全部'));
  assert.equal(html.includes('活跃项目'), !projectId);
  assert.equal((html.match(/近 30 天/g) || []).length, 2);
  assert.ok(html.indexOf('运营数据：') > html.lastIndexOf('class="st-value"'));
  assert.ok(html.includes('排除回收站与已删除项目。执行与渠道发布指标按下方日期范围统计；手动发布单另行记录。'));
  assert.ok(!/令牌消耗|AI 回答采样|999|monitoring_url/.test(html));
  assert.ok(!html.includes('data-analytics-scope'), 'Scope controls moved out of summary body');
  assert.ok(fixture.scopeHost.innerHTML.includes('role="group" aria-label="统计范围"'));
  assert.ok(fixture.scopeHost.innerHTML.includes(`data-analytics-scope="project" aria-pressed="${projectId ? 'true' : 'false'}" ${projectId ? '' : 'disabled'}`));
  assert.ok(fixture.scopeHost.innerHTML.includes(`data-analytics-scope="tenant" aria-pressed="${projectId ? 'false' : 'true'}"`));
  fixture.scopeButtons[1].listeners.click();
  assert.equal(fixture.state.analyticsScope, 'tenant');
  assert.equal(loads, 1, 'Existing scope button reloads analytics');
  if (projectId) {
    assert.ok(html.includes('<a class="md-link" href="#content-library" data-analytics-articles>查看文章 →</a>'));
    fixture.articleLink.listeners.click({ preventDefault() {} });
    assert.deepEqual(fixture.navigations, ['content-library']);
    assert.equal(fixture.state.clActiveTab, 'all');
    assert.equal(fixture.state.clSearch, '');
    assert.equal(fixture.elements.clSearchInput.value, '');
  } else {
    assert.ok(html.includes('选择项目后可打开对应文章列表'));
    assert.ok(!html.includes('data-analytics-articles'));
  }
}

async function loadFixture(failSummary = false, projectId = 7, failActivity = false) {
  const fixture = analyticsFixture(projectId);
  const requests = [];
  if (failActivity) fixture.state.dashboardActivity = [{ type: 'execution', skill_name: '过期动态' }];
  fixture.elements.analyticsSummary.innerHTML = '<div>上一份摘要</div>';
  fixture.elements.analyticsContent.innerHTML = '<p class="md-alert md-alert--danger">旧页签失败</p>';
  const messageSnapshots = [];
  const handlers = compile(['loadAnalytics', 'renderAnalytics', 'renderAnalyticsTrends', 'renderDashboardActivity', 'statusLabel', 'formatDate'], {
    ...fixture.dependencies,
    async api(path) {
      requests.push(path);
      if (path.includes('/analytics/summary')) {
        if (failSummary) throw new Error('本地夹具摘要失败');
        return fixture.state.analyticsData;
      }
      if (failActivity && path.includes('/dashboard/activity')) throw new Error('本地夹具动态失败');
      return path.includes('/analytics/trends') ? { articles: [], executions: [] } : { data: [] };
    },
    setMessage(element, text, variant) {
      element.textContent = text;
      element.variant = variant;
      messageSnapshots.push({ text, variant, content: fixture.elements.analyticsContent.innerHTML });
    },
  });
  await handlers.loadAnalytics();
  return { ...fixture, requests, messageSnapshots };
}
const success = await loadFixture();
assert.equal(success.requests.length, 6, 'All six project analytics requests are preserved');
assert.ok(!success.requests.some(path => path.includes('/dashboard/usage')), 'Quota usage is no longer requested by the project overview');
assert.equal(success.elements.analyticsMessage.textContent, '', 'Successful load clears the message');
assert.ok(success.elements.analyticsSummary.innerHTML.includes('class="md-stat"'));
assert.equal(success.messageSnapshots[0].text, '正在加载数据...');
assert.ok(!success.messageSnapshots[0].content.includes('md-alert'), 'Loading does not retain an old tab alert');
const failure = await loadFixture(true);
assert.equal(failure.elements.analyticsSummary.innerHTML, '');
assert.equal(failure.elements.analyticsContent.innerHTML, '');
assert.equal(failure.state.analyticsData, null);
assert.equal(failure.elements.analyticsMessage.textContent, '本地夹具摘要失败');
assert.equal(failure.elements.analyticsMessage.variant, 'danger');
assert.ok(failure.messageSnapshots.every(snapshot => !snapshot.text || !snapshot.content.includes('md-alert')), 'Summary failure has one message rather than a duplicate content alert');

// Keep each old failure message verbatim while using the shared alert.
for (const [name, property, message] of [
  ['renderAnalyticsProjects', 'analyticsByProject', '项目统计加载失败，请重试。'],
  ['renderAnalyticsDistribution', 'analyticsDistribution', '分发数据加载失败，请重试。'],
]) {
  const fixture = analyticsFixture();
  fixture.state[property] = null;
  const handler = compile([name], fixture.dependencies);
  handler[name]();
  assert.equal(fixture.elements.analyticsContent.innerHTML, `<p class="md-alert md-alert--danger">${message}</p>`);
}

function actualConstant(name) {
  const start = source.search(new RegExp(`^  const ${name} = \\{`, 'm'));
  assert.ok(start >= 0, `Load actual ${name} initializer`);
  const tail = source.slice(start);
  for (const closing of tail.matchAll(/^  \};/gm)) {
    const candidate = tail.slice(0, closing.index + closing[0].length);
    try {
      new vm.Script(candidate);
      return new Function(`${candidate}\nreturn ${name};`)();
    } catch (error) {
      if (!(error instanceof SyntaxError)) throw error;
    }
  }
  throw new Error(`Unclosed constant ${name}`);
}
const tabsFixture = analyticsFixture();
const tabValues = [...analyticsTemplate.matchAll(/data-analytics-tab="([^"]+)"/g)].map(match => match[1]);
assert.deepEqual(tabValues, ['trends', 'models', 'projects', 'distribution'], 'The actual project overview has exactly four retained tabs');
assert.ok(!analyticsTemplate.includes('data-analytics-tab="overview"'), 'Quota no longer has a project overview tab');
const tabs = tabValues.map(tab => {
  const button = node();
  button.dataset = { analyticsTab: tab };
  button.attributes = {};
  button.setAttribute = (name, value) => { button.attributes[name] = value; };
  button.classes = new Set();
  button.classList = { toggle(name, selected) { selected ? button.classes.add(name) : button.classes.delete(name); } };
  return button;
});
const defaultState = actualConstant('state');
assert.equal(defaultState.analyticsTab, 'trends', 'The actual page state defaults to trends');
assert.ok(!Object.hasOwn(defaultState, 'dashboardUsage'), 'The project state no longer owns account quotas');
tabsFixture.state.analyticsTab = defaultState.analyticsTab;
const tabRenderers = compile(['renderAnalytics', 'renderAnalyticsTrends'], {
  ...tabsFixture.dependencies, document: { querySelectorAll: () => tabs },
});
tabRenderers.renderAnalytics();
const selectedTabs = () => tabs.filter(button => button.attributes['aria-selected'] === 'true');
assert.deepEqual(selectedTabs().map(button => button.dataset.analyticsTab), ['trends']);
assert.ok(tabs[0].classes.has('is-on'));
assert.ok(tabs.slice(1).every(button => !button.classes.has('is-on')));
assert.ok(tabs.every(button => !Object.hasOwn(button.attributes, 'aria-pressed')));
assert.ok(tabsFixture.elements.analyticsContent.innerHTML.includes('暂无趋势数据。'), 'Default renderer displays the trends empty state');

// Execute the real startup binding, then click its native button listener.
// The listener invokes the actual renderAnalytics with the four retained tabs.
const tabBinding = [...source.matchAll(/^  document\.querySelectorAll\("\[data-analytics-tab\]"\)\.forEach\(\(btn\) => \{[\s\S]*?^  \}\);/gm)].at(-1)?.[0];
assert.ok(tabBinding, 'Load actual tab click binding');
new Function('document', 'state', 'renderAnalytics', tabBinding)({ querySelectorAll: () => tabs }, tabsFixture.state, tabRenderers.renderAnalytics);
for (const button of tabs.slice(1)) {
  button.listeners.click();
  assert.equal(tabsFixture.state.analyticsTab, button.dataset.analyticsTab, 'The real click handler selects each retained tab');
  assert.deepEqual(selectedTabs().map(tab => tab.dataset.analyticsTab), [button.dataset.analyticsTab]);
  assert.ok(button.classes.has('is-on') && !tabs[0].classes.has('is-on'));
}
tabs.at(-1).dataset.analyticsTab = '';
tabs.at(-1).listeners.click();
assert.equal(tabsFixture.state.analyticsTab, 'trends', 'The actual click handler also falls back to trends');

const modelsFixture = analyticsFixture();
modelsFixture.state.analyticsModelUsage = {
  summary: { total_calls: 43, total_tokens: 47, total_points_deducted: 53 },
  by_model: [{ model: '本地模型', total_calls: 43, total_tokens: 47, completed_calls: 41 }],
};
const models = compile(['renderAnalyticsModels'], { ...modelsFixture.dependencies, formatGeoPoints: value => `积分${value}` });
models.renderAnalyticsModels();
assert.deepEqual(cardValues(modelsFixture.elements.analyticsContent.innerHTML), ['43', '47']);
assert.ok(modelsFixture.elements.analyticsContent.innerHTML.includes('<h4>按模型</h4>'));
assert.ok(modelsFixture.elements.analyticsContent.innerHTML.includes('成功率 95.3%'));

const distributionFixture = analyticsFixture();
distributionFixture.state.analyticsDistribution = {
  metrics: { channels_total: 59, channels_active: 61, publications_total: 67, publications_published: 71, publications_accepted: 73 },
  recent_publications: [
    { status: 'published', remote_url: 'https://local.test/article', article_title: '已公开文章', channel_name: '本地渠道', article_version: 2 },
    { status: 'failed', remote_url: 'https://local.test/failed', article_title: '失败文章', last_error: '原始失败说明' },
  ],
};
const distribution = compile(['renderAnalyticsDistribution', 'safeAnalyticsUrl'], { ...distributionFixture.dependencies, URL });
distribution.renderAnalyticsDistribution();
const distributionHtml = distributionFixture.elements.analyticsContent.innerHTML;
assert.deepEqual(cardValues(distributionHtml), ['59', '61', '67', '71', '73']);
assert.ok(distributionHtml.includes('<a class="md-link" href="https://local.test/article" target="_blank" rel="noopener noreferrer">核验目标 ↗</a>'));
assert.equal((distributionHtml.match(/核验目标/g) || []).length, 1, 'Failed publication does not gain a verification link');
assert.ok(distributionHtml.includes('原始失败说明'));

const projectFixture = analyticsFixture();
const projectButton = { ...node(), dataset: { analyticsProjectArticles: '79' } };
projectFixture.elements.analyticsContent.querySelectorAll = () => [projectButton];
projectFixture.state.analyticsByProject = { projects: [{ project_id: 79, project_name: '本地项目', brand_name: '本地品牌', articles: { total: 83 }, executions: { total: 89, success_rate: 97 }, publications: { period: 101 } }] };
const selectedProjects = [];
const projects = compile(['renderAnalyticsProjects'], {
  ...projectFixture.dependencies, globalThis: { GEO: { async selectProject(id) { selectedProjects.push(id); } } },
});
projects.renderAnalyticsProjects();
assert.ok(projectFixture.elements.analyticsContent.innerHTML.includes('<button class="md-btn md-btn--ghost md-btn--sm" type="button" data-analytics-project-articles="79">查看此项目文章</button>'));
await projectButton.listeners.click();
assert.deepEqual(selectedProjects, [79]);
assert.deepEqual(projectFixture.navigations, ['content-library']);
assert.equal(projectFixture.state.clActiveTab, 'all');
assert.equal(projectFixture.state.clSearch, '');

// F083: the real project-row click handler reports both explicit and default
// errors and does not navigate or clear existing filters after select fails.
for (const [error, message] of [
  [new Error('本地夹具项目不可用'), '本地夹具项目不可用'],
  [new Error(''), '无法打开文章列表。'],
]) {
  const fixture = analyticsFixture();
  const button = { ...node(), dataset: { analyticsProjectArticles: '79' } };
  fixture.elements.analyticsContent.querySelectorAll = () => [button];
  fixture.state.analyticsByProject = projectFixture.state.analyticsByProject;
  fixture.state.clActiveTab = 'approved';
  fixture.state.clSearch = '保留搜索';
  const selectionAttempts = [];
  const handler = compile(['renderAnalyticsProjects'], {
    ...fixture.dependencies,
    globalThis: { GEO: { async selectProject(id) { selectionAttempts.push(id); throw error; } } },
    setMessage(element, text, variant) { element.textContent = text; element.variant = variant; },
  });
  handler.renderAnalyticsProjects();
  await button.listeners.click();
  assert.deepEqual(selectionAttempts, [79]);
  assert.equal(fixture.elements.analyticsMessage.textContent, message);
  assert.equal(fixture.elements.analyticsMessage.variant, 'danger');
  assert.deepEqual(fixture.navigations, [], 'A failed project switch does not continue to articles');
  assert.equal(fixture.state.clActiveTab, 'approved');
  assert.equal(fixture.state.clSearch, '保留搜索');
  assert.equal(fixture.elements.clSearchInput.value, '旧搜索');
}

// Activity uses the same project scope as summary; five other endpoint and
// date-range choices are checked alongside it so this stays a parameter fix.
for (const projectId of [7, null]) {
  const fixture = await loadFixture(false, projectId);
  const projectParam = projectId ? '&project_id=7' : '';
  assert.deepEqual(fixture.requests, [
    `/api/v1/analytics/summary?days=30${projectParam}`,
    `/api/v1/analytics/trends?days=30${projectParam}`,
    `/api/v1/analytics/model-usage?days=30${projectParam}`,
    '/api/v1/analytics/by-project?days=30',
    `/api/v1/analytics/distribution?days=30${projectParam}`,
    `/api/v1/dashboard/activity?limit=10${projectParam}`,
  ]);
  assert.equal(fixture.elements.analyticsMessage.textContent, '');
  assert.ok(fixture.elements.activityList.innerHTML.includes('class="md-empty"'));
}

// F105: activity is optional. Its rejected request clears stale records while
// a successful summary still renders normally and clears the loading message.
const rejectedActivity = await loadFixture(false, 7, true);
assert.equal(rejectedActivity.requests.length, 6);
assert.equal(rejectedActivity.requests.filter(path => path === '/api/v1/dashboard/activity?limit=10&project_id=7').length, 1);
assert.deepEqual(rejectedActivity.state.dashboardActivity, []);
assert.deepEqual(cardValues(rejectedActivity.elements.analyticsSummary.innerHTML), ['5 / 17', '19', '23 / 29', '31 / 37']);
assert.equal(rejectedActivity.elements.activityList.innerHTML, '<div class="md-empty"><h3 class="md-empty-title">暂无动态</h3><p>最近没有技能执行或待处理文章。</p></div>');
assert.equal(rejectedActivity.elements.analyticsMessage.textContent, '');
assert.ok(!rejectedActivity.elements.activityList.innerHTML.includes('过期动态'));
assert.ok(rejectedActivity.messageSnapshots.every(snapshot => snapshot.variant !== 'danger'));

const activityFixture = analyticsFixture();
activityFixture.state.dashboardActivity = [
  { type: 'execution', skill_name: '技能优先', title: '不应覆盖技能名', status: 'completed', project_name: '项目甲', created_at: '2026-10-25T00:00:00Z', updated_at: '2026-10-24T00:00:00Z' },
  { type: 'review', title: '<script>文章</script>', status: 'pending', project_name: '项目乙', updated_at: '2026-10-24T00:00:00Z' },
  { type: 'editor' },
  ...Array.from({ length: 9 }, (_, index) => ({ type: 'other', title: `编辑记录${index}`, status: 'draft', project_name: '项目丙', created_at: '2026-10-23T00:00:00Z' })),
];
const activity = compile(['renderDashboardActivity', 'statusLabel', 'formatDate'], activityFixture.dependencies);
activity.renderDashboardActivity();
const activityHtml = activityFixture.elements.activityList.innerHTML;
assert.ok(activityHtml.startsWith('<div class="md-tablewrap"><table class="md-table">'));
assert.deepEqual([...activityHtml.matchAll(/<th>([^<]*)<\/th>/g)].map(match => match[1]), ['内容', '类型', '状态', '项目', '时间']);
const activityRows = [...activityHtml.matchAll(/<tr>((?:<td>[\s\S]*?<\/td>)+)<\/tr>/g)].map(row => [...row[1].matchAll(/<td>([\s\S]*?)<\/td>/g)].map(cell => cell[1]));
assert.equal(activityRows.length, 10, 'The real renderer caps a larger response at ten records');
assert.deepEqual(activityRows[0], ['技能优先', '技能执行', '已完成', '项目甲', '2026/10/25 08:00:00']);
assert.deepEqual(activityRows[1], ['&lt;script&gt;文章&lt;/script&gt;', '审稿', '待处理', '项目乙', '2026/10/24 08:00:00']);
assert.deepEqual(activityRows[2], ['-', '编辑', '-', '-', '-']);
assert.ok(!activityHtml.includes('不应覆盖技能名') && !activityHtml.includes('编辑记录7') && !activityHtml.includes('<script>'));
assert.ok(!/history-item|class="empty"|inline-result|usage-card/.test(activityHtml));

// The pending-articles destination also moves the article page's tab highlight,
// which only clSelectTab changes. The empty activity state keeps its markup.
activityFixture.state.clActiveTab = 'all';
activityFixture.state.clSearch = '旧搜索';
activityFixture.elements.clSearchInput.value = '旧搜索';
const pendingTabSelections = [];
const previousGeo = globalThis.GEO;
globalThis.GEO = { clSelectTab: tab => pendingTabSelections.push(tab) };
const { openPendingArticlesFromOverview } = compile(['openPendingArticlesFromOverview'], activityFixture.dependencies);
openPendingArticlesFromOverview();
globalThis.GEO = previousGeo;
assert.equal(activityFixture.state.clActiveTab, 'pending');
assert.equal(activityFixture.state.clSearch, '');
assert.equal(activityFixture.elements.clSearchInput.value, '');
assert.deepEqual(pendingTabSelections, ['pending'], 'The article tab highlight follows the pending destination');
assert.deepEqual(activityFixture.navigations, ['content-library']);
activityFixture.state.dashboardActivity = [];
activity.renderDashboardActivity();
assert.equal(activityFixture.elements.activityList.innerHTML, '<div class="md-empty"><h3 class="md-empty-title">暂无动态</h3><p>最近没有技能执行或待处理文章。</p></div>');

console.log('EXEC-14 project overview: real statistics, four-tab behavior, scoped loading, activity table/empty state and both navigation actions passed');
