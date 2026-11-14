import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/geo-entry.js', import.meta.url), 'utf8');
const skills = readFileSync(new URL('../../public/geo-entry-skills.js', import.meta.url), 'utf8');
const blade = readFileSync(new URL('../../resources/views/workbench/panels/skill.blade.php', import.meta.url), 'utf8');

// Run the real owning functions, without starting the application or sending any request.
function extract(text, name) {
  const marker = new RegExp(`^  (?:async )?function ${name}\\(`, 'm');
  const found = marker.exec(text);
  assert.ok(found, `Real ${name} exists`);
  const rest = text.slice(found.index + found[0].length);
  const next = /^  (?:async )?function \w+\(/m.exec(rest);
  assert.ok(next, `Real ${name} has a following declaration boundary`);
  return text.slice(found.index, found.index + found[0].length + next.index).trim();
}

class Button {
  constructor(markup) {
    this.markup = markup;
    this.events = new Map();
    this.className = /class="([^"]*)"/.exec(markup)?.[1] || '';
    this.disabled = /\sdisabled(?:\s|>)/.test(markup);
    this.dataset = {};
    for (const match of markup.matchAll(/\bdata-([\w-]+)(?:="([^"]*)")?/g)) {
      const key = match[1].replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
      this.dataset[key] = match[2] || '';
    }
  }
  matches(selector) {
    const match = /^\[([^=\]]+)(?:="([^"]*)")?\]$/.exec(selector);
    if (!match) return false;
    const pattern = match[2] === undefined ? new RegExp(`\\b${match[1]}(?:[ =>])`) : new RegExp(`${match[1]}="${match[2]}"`);
    return pattern.test(this.markup);
  }
  addEventListener(name, callback) {
    assert.ok(!this.events.has(name), 'Each newly rendered action receives one listener');
    this.events.set(name, callback);
  }
  click() {
    if (!this.disabled) this.events.get('click')?.();
  }
}

class Result {
  set innerHTML(markup) {
    this.markup = markup;
    this.buttons = [...markup.matchAll(/<button\b[^>]*>[\s\S]*?<\/button>/g)].map(match => new Button(match[0]));
  }
  get innerHTML() { return this.markup; }
  querySelector(selector) { return this.buttons.find(button => button.matches(selector)) || null; }
  querySelectorAll(selector) { return this.buttons.filter(button => button.matches(selector)); }
}

function primaryCount(html) {
  return [...html.matchAll(/<button\b[^>]*class="([^"]*)"[^>]*>/g)].filter(match => {
    const classes = match[1].split(/\s+/);
    return classes.includes('md-btn--primary') || (classes.includes('button') && classes.includes('primary'));
  }).length;
}

function fixture() {
  const result = new Result();
  const header = /<button\b[^>]*id="runAnalysisPanelButton"[^>]*>/.exec(blade)?.[0];
  assert.ok(header, 'Panel header retains its real action');
  const elements = { analysisResult: result, analysisMessage: { textContent: '', dataset: {} }, runAnalysisPanelButton: { hidden: false, markup: header } };
  const actions = [];
  const context = vm.createContext({
    elements, Date, Intl, MESSAGE_HOLD_MS: 8000,
    globalThis: { GEO: {} },
    normalizeReport: report => report,
    readableText: value => String(value || '').trim(),
    readableList: value => Array.isArray(value) ? value : value ? [value] : [],
    formatGeoPoints: value => String(value),
    statusLabel: status => ({ failed: '失败', cancelled: '已取消', completed: '已完成', running: '执行中', pending: '待处理', deducted: '已记录', not_integrated: '扣点未完成' }[status] || status),
    runAnalysis: () => actions.push('run'),
    cancelAnalysis: analysis => actions.push(['cancel', analysis.id]),
    copyReport: text => actions.push(['copy', text]),
    downloadReport: (analysis, text) => actions.push(['download', analysis.id, text]),
    downloadReportFormat: (analysis, format, text) => actions.push(['format', analysis.id, format, text]),
    openArticleFromAnalysis: id => actions.push(['article', id]),
    scrollToSkillCatalog: () => actions.push('skill'),
  });
  const helpers = ['isTerminalExecutionStatus', 'analysisDisplayName'].map(name => extract(skills, name));
  const functions = ['setMessage', 'escapeHtml', 'formatDate', 'formatLabel', 'formatNumber', 'markdownSection', 'buildReportMarkdown', 'reportOneLineSummary', 'reportGlanceBlock', 'renderAnalysisEmptyState', 'renderAnalysisResult'].map(name => extract(source, name));
  vm.runInContext([...helpers, ...functions].join('\n'), context);
  return {
    context, elements, actions,
    render(analysis) { context.renderAnalysisResult(analysis); },
    empty() { context.renderAnalysisEmptyState(); },
    primaries() { return primaryCount(result.innerHTML) + (elements.runAnalysisPanelButton.hidden ? 0 : primaryCount(header)); },
    alerts() { return [...result.innerHTML.matchAll(/class="[^"]*\bmd-alert(?:\s|")/g)].length + Number(Boolean(elements.analysisMessage.textContent)); },
  };
}

const base = { id: 11, title: '本地测试诊断', created_at: '2026-10-24T01:00:00Z', status: 'completed', article_id: 19, report: { summary: '一条真实摘要' } };

// §6 ruling 2: state feedback occupies exactly one surface, while original explanations survive.
for (const status of ['failed', 'cancelled', 'running']) {
  const f = fixture();
  f.render({ ...base, status, error_message: '上游<不可用>', model_call_logs: [{ id: 1 }, { id: 2 }] });
  assert.equal(f.alerts(), 1, `${status}: one status alert`);
  assert.equal(f.primaries(), 0, `${status}: no paid retry primary`);
  if (status === 'running') {
    assert.ok(f.elements.analysisMessage.textContent.includes('完成后会自动展示结果'));
    assert.match(f.elements.analysisResult.innerHTML, /class="muted">\s*这次综合诊断执行中，还没有产出结果/);
    assert.ok(f.elements.analysisResult.querySelector('[data-analysis-action="cancel"]'));
  } else {
    assert.equal(f.elements.analysisMessage.textContent, '');
    assert.match(f.elements.analysisResult.innerHTML, /已经发起 2 次模型调用，用量已记录。/);
    assert.match(f.elements.analysisResult.innerHTML, status === 'failed' ? /这次综合诊断失败了，没有产出报告。上游&lt;不可用&gt;/ : /这次综合诊断已取消，没有产出报告。/);
  }
}

// §6 ruling 3: every row in the approved primary-button table is exercised.
for (const [name, analysis, expected] of [
  ['completed with article', base, 1],
  ['completed without article', { ...base, article_id: null }, 0],
  ['failed', { ...base, status: 'failed' }, 0],
  ['cancelled', { ...base, status: 'cancelled' }, 0],
  ['running', { ...base, status: 'running' }, 0],
  ['pending', { ...base, status: 'pending' }, 0],
]) {
  const f = fixture();
  f.render(analysis);
  assert.equal(f.primaries(), expected, name);
  assert.equal(f.elements.runAnalysisPanelButton.hidden, false, `${name}: retain secondary header action`);
  if (analysis.status === 'completed') assert.equal(f.elements.analysisMessage.textContent, '');
  if (name === 'completed without article') {
    const button = f.elements.analysisResult.querySelector('[data-analysis-next-action="article"]');
    assert.equal(button.disabled, true);
    assert.ok(button.className.includes('secondary'));
    assert.match(button.markup, /报告未生成/);
  }
}
{
  const f = fixture();
  f.empty();
  assert.equal(f.primaries(), 1, 'No history: one empty-state primary');
  assert.equal(f.elements.runAnalysisPanelButton.hidden, true);
  assert.match(f.elements.analysisResult.innerHTML, /还没有综合诊断/);
  const run = f.elements.analysisResult.querySelector('[data-start-project-analysis]');
  run.click();
  assert.deepEqual(f.actions, ['run'], 'Empty state invokes the original runAnalysis exactly once');
}

{
  const f = fixture();
  const markdown = '# 原始正文\n\n## 结算状态\n- GEO 扣点状态：原样保留\n';
  f.render({ ...base, report: {}, report_markdown: markdown, available_formats: ['md', 'txt'] });
  assert.ok(!f.elements.analysisResult.innerHTML.includes('没有摘要段落'));
  assert.ok(!f.elements.analysisResult.innerHTML.includes('report-glance'), 'Missing summary emits no placeholder block');
  f.elements.analysisResult.querySelector('[data-report-action="copy"]').click();
  f.elements.analysisResult.querySelector('[data-report-format="md"]').click();
  f.elements.analysisResult.querySelector('[data-analysis-next-action="article"]').click();
  f.elements.analysisResult.querySelector('[data-analysis-next-action="skill"]').click();
  assert.deepEqual(f.actions, [['copy', markdown], ['format', 11, 'md', markdown], ['article', 19], 'skill']);
}

{
  const f = fixture();
  const markdown = f.context.buildReportMarkdown(base, { summary: '结论' }, { total_tokens: 30 });
  assert.ok(markdown.includes('Tokens：30'));
  assert.ok(!markdown.includes('结算状态'));
}
console.log('EXEC-11 diagnosis: one state alert, approved primary table, settlement display, original actions and unchanged copied Markdown passed.');
