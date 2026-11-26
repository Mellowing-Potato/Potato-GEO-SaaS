import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const analyticsJs = fs.readFileSync(new URL('../../public/geo-analytics.js', import.meta.url), 'utf8');
const analyticsBlade = fs.readFileSync(new URL('../../resources/views/analytics.blade.php', import.meta.url), 'utf8');
const analyticsCss = fs.readFileSync(new URL('../../public/geo-analytics.css', import.meta.url), 'utf8');

// Structural checks on Blade & CSS (Tremor layout without double-nested KPI panel)
assert.match(analyticsBlade, /<div class="ga-grid-main">/);
assert.match(analyticsBlade, /<div class="ga-grid-split">/);
assert.match(analyticsBlade, /data-kpi-meta="ai_bot_pv"/);
assert.match(analyticsCss, /\.kpi-row\s*\{[^}]*repeat\(5,\s*minmax\(0,\s*1fr\)\)/s);
assert.match(analyticsCss, /\.ga-grid-main\s*\{[^}]*minmax\(0,\s*7fr\)\s+minmax\(0,\s*5fr\)/s);
assert.doesNotMatch(analyticsJs, /style="/, 'geo-analytics.js must render Tremor charts via pure SVG/CSS classes without inline style attributes');

// Real execution in VM against mocked API responses
const kpiNodes = new Map(['pv', 'unique_ip', 'ai_bot_pv', 'search_bot_pv', 'human_pv'].map(k => [k, { textContent: '', getAttribute: (a) => a === 'data-kpi' ? k : null }]));
const metaNodes = new Map(['pv', 'unique_ip', 'ai_bot_pv', 'search_bot_pv', 'human_pv'].map(k => [k, { textContent: '', getAttribute: (a) => a === 'data-kpi-meta' ? k : null }]));
const hosts = {};
function makeHost(id) {
  return {
    id,
    innerHTML: '',
    textContent: '',
    hidden: false,
    value: '30',
    listeners: {},
    addEventListener(type, fn) { this.listeners[type] = fn; },
    querySelector() { return null; },
    querySelectorAll() { return []; },
  };
}
for (const id of ['guardState', 'guardGlyph', 'guardIcon', 'guardTitle', 'guardText', 'guardLink', 'ovHint', 'gaPeriod', 'daysSel', 'donutWrap', 'topBotsHost', 'trendHost', 'articlesBody', 'beaconBody', 'beaconHint', 'logsBody', 'logsPager', 'logTraffic', 'logPath', 'logSearchBtn', 'logResetBtn']) {
  hosts[id] = makeHost(id);
}

const trendInteractive = {
  readout: { innerHTML: '' },
  groups: [],
  svgListeners: {},
};
hosts.trendHost.querySelector = (sel) => {
  if (sel === '#trendReadout') return trendInteractive.readout;
  if (sel === '.trend-svg') return { addEventListener: (t, fn) => { trendInteractive.svgListeners[t] = fn; } };
  return null;
};
hosts.trendHost.querySelectorAll = (sel) => {
  if (sel === '.ga-pt-group') {
    trendInteractive.groups = [0, 1, 2].map((i) => {
      const classes = new Set();
      const l = {};
      return {
        getAttribute: (a) => a === 'data-idx' ? String(i) : null,
        classList: { toggle: (c, on) => on ? classes.add(c) : classes.delete(c), remove: (c) => classes.delete(c), has: (c) => classes.has(c) },
        addEventListener: (t, fn) => { l[t] = fn; },
        listeners: l,
      };
    });
    return trendInteractive.groups;
  }
  return [];
};

const apiFixtures = {
  '/api/v1/auth/session-login': { token: 'test-token', user: { name: '测试', tenant: { subdomain: 'demo' } } },
  '/api/v1/analytics/crawler-breakdown?days=30': {
    kpis: { pv: 10000, unique_ip: 500, ai_bot_pv: 2600, search_bot_pv: 1400, human_pv: 6000 },
    period: { from: '2026-09-01', to: '2026-09-30' },
    traffic_breakdown: [
      { key: 'human', count: 6000 },
      { key: 'ai_bot', count: 2600 },
      { key: 'search_bot', count: 1400 },
    ],
    top_ai_bots: [
      { bot_name: 'GPTBot <script>', hits: 1800, articles_reached: 24 },
      { bot_name: 'PerplexityBot', hits: 800, articles_reached: 12 },
    ],
  },
  '/api/v1/analytics/traffic-trend?days=30': {
    trend: [
      { date: '2026-09-01', pv: 1200, ai_bot_pv: 300 },
      { date: '2026-09-02', pv: 2400, ai_bot_pv: 600 },
      { date: '2026-09-03', pv: 1500, ai_bot_pv: 450 },
    ],
  },
  '/api/v1/analytics/top-articles?days=30&limit=10': { data: [{ article_id: 1, title: '示例文章', views: 500, unique_ip: 120 }] },
  '/api/v1/analytics/channel-beacon-health?days=30': { beacon_threshold_days: 7, data: [] },
  '/api/v1/analytics/visit-logs?per_page=25&page=1': { data: [], meta: { current_page: 1, last_page: 1, total: 0 } },
};

const store = new Map();
const context = {
  console,
  URL,
  Date,
  Math,
  Number,
  String,
  Array,
  Object,
  Promise,
  encodeURIComponent,
  sessionStorage: {
    getItem: (k) => store.get(k) || null,
    setItem: (k, v) => store.set(k, String(v)),
    removeItem: (k) => store.delete(k),
  },
  window: {
    GEO_ANALYTICS_CONFIG: { accountLoginUrl: '/login', accountUrl: '/#personal-settings' },
    location: { href: 'http://127.0.0.1:8788/analytics', reload() {} },
  },
  document: {
    readyState: 'complete',
    querySelector: (sel) => sel.startsWith('#') ? (hosts[sel.slice(1)] || null) : null,
    querySelectorAll: (sel) => {
      if (sel === '[data-kpi]') return [...kpiNodes.values()];
      if (sel === '[data-kpi-meta]') return [...metaNodes.values()];
      return [];
    },
    addEventListener() {},
  },
  fetch: async (url) => ({
    ok: true,
    status: 200,
    json: async () => apiFixtures[url] || {},
  }),
};
vm.createContext(context);
vm.runInContext(analyticsJs, context);

await new Promise((r) => setTimeout(r, 30));

// 1. KPI values & Tremor share/ratio badges
assert.equal(kpiNodes.get('pv').textContent, '10,000');
assert.equal(metaNodes.get('ai_bot_pv').textContent, '占比 26%');
assert.equal(metaNodes.get('human_pv').textContent, '占比 60%');
assert.equal(metaNodes.get('unique_ip').textContent, '人均 20.0 PV');

// 2. DonutChart + CategoryBar SVG
assert.match(hosts.donutWrap.innerHTML, /class="tremor-donut-svg"/);
assert.match(hosts.donutWrap.innerHTML, /class="tremor-catbar-svg"/);
assert.match(hosts.donutWrap.innerHTML, /class="cat-seg ai"/);

// 3. BarList with background SVG fill & escaped crawler name
assert.match(hosts.topBotsHost.innerHTML, /class="tremor-bar-svg"/);
assert.match(hosts.topBotsHost.innerHTML, /GPTBot &lt;script&gt;/);
assert.doesNotMatch(hosts.topBotsHost.innerHTML, /<script>/);
assert.match(hosts.topBotsHost.innerHTML, /24 篇/);

// 4. AreaChart with gradient fills, dashed gridlines, date ticks, and interactive hover readout
assert.match(hosts.trendHost.innerHTML, /id="gaAreaTotal"/);
assert.match(hosts.trendHost.innerHTML, /id="gaAreaAi"/);
assert.match(hosts.trendHost.innerHTML, /class="ga-grid-line"/);
assert.match(hosts.trendHost.innerHTML, />09-01</);
assert.match(hosts.trendHost.innerHTML, /峰值日 · 2026-09-02/);

trendInteractive.groups[2].listeners.mouseenter();
assert.equal(trendInteractive.groups[2].classList.has('is-active'), true);
assert.match(trendInteractive.readout.innerHTML, /2026-09-03/);
assert.match(trendInteractive.readout.innerHTML, /30%/);
trendInteractive.svgListeners.mouseleave();
assert.equal(trendInteractive.groups[2].classList.has('is-active'), false);
assert.match(trendInteractive.readout.innerHTML, /峰值日 · 2026-09-02/);

console.log('Analytics Tremor overview: KPI badges, Donut+CategoryBar, BarList, AreaChart gradients/axes/hover readout and zero inline styles passed.');
