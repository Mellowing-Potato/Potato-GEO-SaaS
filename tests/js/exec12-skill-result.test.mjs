import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const read = file => readFileSync(new URL(`../../public/${file}`, import.meta.url), 'utf8');
const mainSource = read('geo-entry.js');
const reportsSource = read('geo-entry-reports.js');
const skillsSource = read('geo-entry-skills.js');

function extract(source, name) {
  const marker = source.includes(`  function ${name}(`) ? `  function ${name}(` : `  async function ${name}(`;
  const start = source.indexOf(marker);
  assert.ok(start >= 0, `Load the real ${name} implementation`);
  // Use the JS parser at owning-module closing lines: braces in a regular
  // expression, comment or template are not function boundaries.
  const tail = source.slice(start);
  for (const closing of tail.matchAll(/^  \}/gm)) {
    const candidate = tail.slice(0, closing.index + closing[0].length);
    try { new vm.Script(candidate); return candidate; }
    catch (error) { if (!(error instanceof SyntaxError)) throw error; }
  }
  throw new Error(`Unclosed function ${name}`);
}

// A small HTML tree supplies only the browser environment. Rendering, state
// decisions, copy, billing/quality text and structured output all come from the
// actual application modules below. Computed-style checks run in the UI audit.
const decode = value => String(value).replace(/&(amp|lt|gt|quot|#039|#39|#x27);/g,
  (_, key) => ({ amp: '&', lt: '<', gt: '>', quot: '"', '#039': "'", '#39': "'", '#x27': "'" }[key]));
class Element {
  constructor(tag = 'div', attributes = {}) {
    this.tagName = tag.toLowerCase();
    this.attributes = attributes;
    this.children = [];
    this.listeners = new Map();
    this.dataset = Object.fromEntries(Object.entries(attributes).filter(([key]) => key.startsWith('data-'))
      .map(([key, value]) => [key.slice(5).replace(/-([a-z])/g, (_, letter) => letter.toUpperCase()), value]));
    this._html = '';
    this._text = '';
  }
  set innerHTML(html) {
    this._html = String(html);
    this._text = '';
    this.children = [];
    const stack = [this];
    for (const token of this._html.match(/<!--[\s\S]*?-->|<\/?[^>]+>|[^<]+/g) || []) {
      if (token.startsWith('<!--')) continue;
      if (token.startsWith('</')) { stack.pop(); continue; }
      if (token.startsWith('<')) {
        const [, tag] = token.match(/^<([\w-]+)/) || [];
        if (!tag) continue;
        const attributes = {};
        const tail = token.slice(tag.length + 1, -1);
        for (const match of tail.matchAll(/([\w:-]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+)))?/g)) {
          attributes[match[1]] = decode(match[2] ?? match[3] ?? match[4] ?? '');
        }
        const node = new Element(tag, attributes);
        stack.at(-1).appendChild(node);
        if (!['input', 'br', 'hr', 'img', 'meta', 'link'].includes(tag) && !token.endsWith('/>')) stack.push(node);
      } else {
        const node = new Element('#text');
        node._text = decode(token);
        stack.at(-1).appendChild(node);
      }
    }
  }
  get innerHTML() { return this._html; }
  set textContent(value) { this._text = String(value); this.children = []; }
  get textContent() { return this._text + this.children.map(child => child.textContent).join(''); }
  get className() { return this.attributes.class || ''; }
  set className(value) { this.attributes.class = String(value); }
  get disabled() { return Object.hasOwn(this.attributes, 'disabled'); }
  set disabled(value) { if (value) this.attributes.disabled = ''; else delete this.attributes.disabled; }
  setAttribute(name, value) { this.attributes[name] = String(value); }
  getAttribute(name) { return this.attributes[name] ?? null; }
  addEventListener(type, handler) { this.listeners.set(type, handler); }
  appendChild(node) { node.parentElement = this; this.children.push(node); return node; }
  matches(selector) {
    const text = selector.trim();
    if (text.startsWith('#')) return this.attributes.id === text.slice(1);
    if (text.startsWith('[')) {
      const [, key, value] = text.match(/^\[([^=\]]+)(?:="([^"]*)")?\]$/) || [];
      return key && Object.hasOwn(this.attributes, key) && (value === undefined || this.attributes[key] === value);
    }
    const [tag, ...classes] = text.split('.');
    return (!tag || tag === this.tagName) && classes.every(name => this.className.split(/\s+/).includes(name));
  }
  querySelectorAll(selector) {
    const selectors = selector.split(',');
    return this.children.flatMap(child => [
      ...(child.tagName !== '#text' && selectors.some(item => child.matches(item)) ? [child] : []),
      ...child.querySelectorAll(selector),
    ]);
  }
  querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
}

const registry = [
  { id: 'yao-geo-intent-miner', name: '意图挖掘', category: 'geo-research', stage_index: 0, brief_fields: [] },
  { id: 'yao-geo-explainer-builder', name: '解释型文章', category: 'geo-content-production', stage_index: 2, brief_fields: [] },
  { id: 'page-audit', name: '页面审计', category: 'geo-page-technical', stage_index: 3, brief_fields: [], available: true },
];
const constantNames = ['STAGE_FULL_LABELS', 'SECTION_LABELS', 'REPORT_SKIP_KEYS', 'REPORT_PAIR_KEYS', 'REPORT_POINT_KEYS',
  'OPEN_SECTION_COUNT', 'LONG_REPORT_SECTION_THRESHOLD', 'REPORT_READING_ORDER', 'REPORT_DL_KEYS', 'REPORT_TABLE_KEYS',
  'REPORT_OL_KEYS', 'REPORT_NOTE_KEYS', 'REPORT_CODE_KEYS', 'SECONDARY_REPORT_KEYS', 'REPORT_ZONE_OF', 'REPORT_ZONE_CARRY',
  'REPORT_ZONE_CHROME_KEYS', 'RESULT_ZONES'];
const mainHelperNames = ['escapeHtml', 'formatDate', 'formatNumber', 'formatLabel', 'statusLabel', 'readableText', 'readableObject', 'readableList', 'firstReadableList', 'tryParseJsonValue',
  'extractJsonCandidate', 'fieldLabel', 'glanceFallbackLine', 'reportOneLineSummary'];

function harness(execution) {
  const state = {
    selectedProject: { id: 901, name: '本地虚构项目' }, selectedSkill: registry.find(skill => skill.id === execution.skill_id),
    skills: registry, analyses: [], skillExecutions: [execution], selectedExecutionId: execution.id,
    skillReportReference: null, skillReportQuestion: null, skillMaterialRefs: [],
  };
  const elements = Object.fromEntries(['skillExecutionResult', 'skillBriefPanel', 'skillMessage', 'skillPendingStrip', 'result']
    .map(name => [name, new Element()]));
  elements.skillMessage.textContent = '保留原操作回执';
  elements.skillPendingStrip.innerHTML = '<p>保留未完成执行窄条</p>';
  elements.result.innerHTML = '<article>保留报告全文页</article>';
  const calls = [];
  const messages = [];
  const noNetwork = (...args) => { calls.push(args); throw new Error('A render-only unit test must never send a request'); };
  const GEO = {
    state, elements, api: noNetwork, registerViewLoader() {},
    executionPollers: new Map(), skillExecutionsState: { request: 0 }, config: {},
    matRenderSkillChips() {}, openMatPicker: noNetwork,
    setMessage(node, text, variant) { node.textContent = text; messages.push({ node, text, variant }); },
    confirmWorkspaceAction: noNetwork, openWorkspaceView: noNetwork,
    setActiveWorkspaceView: noNetwork, copyText: noNetwork, downloadText: noNetwork, downloadBlob: noNetwork,
  };
  const document = { addEventListener() {}, createElement: tag => new Element(tag), querySelector: () => null };
  const context = vm.createContext({ GEO, window: { GEO }, document, console, Intl, Date, Map, Set, Object, Array, Number, String, JSON });
  for (const name of mainHelperNames) vm.runInContext(extract(mainSource, name), context);
  const constantsStart = mainSource.indexOf('  const STAGE_FULL_LABELS =');
  const constantsEnd = mainSource.indexOf('  function publicationStatusLabel(', constantsStart);
  assert.ok(constantsStart > 0 && constantsEnd > constantsStart);
  vm.runInContext(mainSource.slice(constantsStart, constantsEnd)
    + `\nObject.assign(GEO, {${constantNames.join(',')}});`, context);
  for (const name of mainHelperNames) GEO[name] = context[name];
  vm.runInContext(reportsSource, context, { filename: 'real-geo-entry-reports.js' });
  // Expose private originals for the test without replacing their bodies or
  // dependencies. The production exported renderer uses its real lexical scope.
  const expose = `\nGEO.exec12Test = { renderSkillBriefForm, skillNextActionPanel, skillExecutionBillingLine, skillQualityWarning, skillExecutionStatusLabel };\n})();`;
  assert.match(skillsSource, /\}\)\(\);\s*$/);
  vm.runInContext(skillsSource.replace(/\}\)\(\);\s*$/, expose), context, { filename: 'real-geo-entry-skills.js' });
  GEO.exec12Test.renderSkillBriefForm(state.selectedSkill);
  const untouched = ['skillMessage', 'skillPendingStrip', 'result'].map(name => [name, elements[name].textContent, elements[name].innerHTML]);
  GEO.renderSkillExecutionResult(execution);
  for (const [name, text, html] of untouched) {
    assert.equal(elements[name].textContent, text, `${name} is outside EXEC-12`);
    assert.equal(elements[name].innerHTML, html, `${name} markup stays untouched`);
  }
  assert.deepEqual(calls, [], 'Rendering sends no requests and changes no message slots');
  assert.deepEqual(messages, [], 'Render-only cases do not write operation receipts');
  return { GEO, state, elements, calls, messages, result: elements.skillExecutionResult, form: elements.skillBriefPanel };
}

const base = {
  id: 701, project_id: 901, skill_id: registry[1].id, skill_name: '解释型文章', stage_index: 2, status: 'completed',
  created_at: '2026-10-25T03:00:00Z', updated_at: '2026-10-25T03:05:00Z', article_id: 101, article_url: '',
  result_text: '本地验证输出：这是一份完全虚构的执行结果，用于检验结果区层级。',
  structured: { summary: '本地虚构摘要：先核对来源，再按阶段处理内容。' },
  available_formats: ['markdown', 'html', 'docx', 'pdf'],
  quality_report: { passed: true, publication_ready: true },
  // 积分协议 v2：存储、接口与展示同一积分单位（1:1），50 积分显示为 50。
  token_usage: 50,
  billing: { model_call_count: 5 },
};
const rich = {
  title: '本地富结构化结果', summary: '这份虚构结构化输出包含指标、表格和提示正文。',
  summary_cards: [{ label: '本地指标', value: '5', note: '虚构指标，仅验证边框' }],
  sections: [{ title: '本地表格与说明', paragraphs: ['所有正文完全虚构，仅用于去框边界验证。'],
    table: { headers: ['项目', '说明'], rows: [['验证项', '本地虚构数据']] },
    callout: '本地正文提示：这不是执行状态消息，正文内容与结构必须保留。' }],
};
const fixtures = [
  { row: 1, name: 'fact review / production shape', patch: { quality_report: { passed: true, publication_ready: false,
    fact_review_items: Array.from({ length: 17 }, (_, i) => ({ claim: `本地虚构待核验项${i + 1}`, reason: '仅用于本地验证' })) } }, badge: 'warning', label: '已生成编辑稿（待事实复核）', alert: 'warning', next: false, article: 1 },
  { row: 2, name: 'publication ready / next stage', patch: {}, badge: 'success', label: '已完成', alert: null, next: true, article: 1 },
  { row: 3, name: 'archived intent report', patch: { skill_id: registry[0].id, skill_name: '意图挖掘', stage_index: 0 }, badge: 'success', label: '已完成', alert: null, next: true, article: 1 },
  { row: 4, name: 'quality blocked failed draft', patch: { status: 'failed', quality_report: { passed: false,
    checks: [{ name: 'required_sections', passed: false, label: '必要结构缺失' }], missing_required_sections: ['本地必要章节'] },
    error_message: '本地执行错误：质量未通过时这段不应单独显示。' }, badge: 'warning', label: '已生成编辑稿（质量未通过）', alert: 'warning', next: true, article: 1 },
  { row: 5, name: 'failed / rerender', patch: { status: 'failed', article_id: null, quality_report: null,
    available_formats: ['markdown', 'html'], can_rerender: true, error_message: '本地执行错误：文档排版未完成。' }, badge: 'danger', label: '失败', alert: 'danger', next: true, article: 0 },
  { row: 6, name: 'billing pending / recorded', patch: { status: 'billing_pending', article_id: null, available_formats: [],
    quality_report: null, billing: { ...base.billing, recovery_stage: 'model_result_recorded' } }, badge: 'warning', label: '交付待恢复', alert: 'warning', next: true, article: 0 },
  { row: 9, name: 'running without text', patch: { status: 'running', article_id: null, result_text: '', structured: null,
    available_formats: [], quality_report: null, billing: null }, badge: 'neutral', label: '执行中', alert: null, next: false, article: 0 },
  { row: 10, name: 'unarchived rich structured output', patch: { article_id: null, structured: rich }, badge: 'success', label: '已完成', alert: null, next: false, article: 0 },
  { row: 11, name: 'archived output without summary', patch: { result_text: '', structured: {} }, badge: 'success', label: '已完成', alert: null, next: true, article: 1 },
];
const textOf = node => node?.textContent.trim() || '';
const paragraphs = node => node.querySelectorAll('p');
const hasClass = (node, name) => node.className.split(/\s+/).includes(name);
let failures = 0;
let checks = 0;
function check(label, work) {
  checks++;
  try { work(); console.log(`  ✓ ${label}`); }
  catch (error) { failures++; console.error(`  ✗ ${label}\n    ${error.stack}`); }
}

for (const fixture of fixtures) check(`§3.5 row ${fixture.row}: ${fixture.name}`, () => {
  const execution = structuredClone({ ...base, ...fixture.patch });
  const { GEO, result, form } = harness(execution);
  const alerts = result.querySelectorAll('.md-alert');
  assert.equal(alerts.length, fixture.alert ? 1 : 0, 'At most one merged state alert');
  if (fixture.alert) assert.ok(hasClass(alerts[0], `md-alert--${fixture.alert}`), 'The merged alert has the required severity');
  assert.equal(result.querySelectorAll('.inline-result, .callout').length, 0, 'No legacy result notice surfaces remain');
  const badge = result.querySelector('.md-badge');
  assert.ok(badge && hasClass(badge, `md-badge--${fixture.badge}`), 'Status badge severity follows A1');
  assert.equal(textOf(badge), fixture.label, 'Original status label survives');
  const primary = [...form.querySelectorAll('.button.primary, .md-btn--primary'), ...result.querySelectorAll('.button.primary, .md-btn--primary')];
  assert.equal(primary.length, 1, 'Mounted real form plus result have exactly one primary');
  assert.equal(textOf(primary[0]), '开始执行');
  assert.equal(result.querySelectorAll('.skill-next-card').length, fixture.next ? 1 : 0, 'Next-step row visibility follows B1');
  assert.equal(result.querySelectorAll('[data-open-article]').length, fixture.article, 'Artifact destination appears once only');
  assert.equal(result.querySelectorAll('[data-format]').length, execution.available_formats.length, 'All export formats survive');
  if (fixture.next) assert.equal(result.querySelector('.skill-next-card').querySelectorAll('[data-open-article]').length, 0, 'Next-step row has no repeated article link');
  const copy = result.querySelector('[data-skill-action="copy"]');
  const download = result.querySelector('[data-skill-action="download"]');
  const hasText = Boolean(GEO.skillExecutionText(execution).trim());
  assert.equal(copy.disabled, !hasText);
  assert.equal(download.disabled, !hasText);
  assert.ok(copy.listeners.has('click') && download.listeners.has('click'), 'Original copy/download events stay attached');
  if (fixture.row === 1) {
    assert.equal(execution.quality_report.fact_review_items.length, 17);
    assert.match(textOf(alerts[0]), /还有 17 项事实或来源需要确认/);
    assert.ok(paragraphs(result).some(p => hasClass(p, 'muted') && textOf(p) === '已记录 5 次模型调用 · 50 tokens。'));
    assert.ok(paragraphs(result).some(p => hasClass(p, 'muted') && textOf(p).startsWith('已保存到文章库。打开文章导出报告：')));
  }
  if (fixture.row === 2) assert.match(textOf(result.querySelector('[data-next-stage-skill]')), /^继续：页面审计$/);
  if (fixture.row === 3) {
    assert.ok(paragraphs(result).some(p => hasClass(p, 'muted') && textOf(p).startsWith('已归档到历史报告。去历史报告列表导出报告：')));
    assert.match(textOf(result.querySelector('.skill-next-card')), /下一步从报告选择问题进入写作/);
  }
  if (fixture.row === 4) {
    assert.ok(!result.textContent.includes(execution.error_message), 'Original qualityBlockedDraft condition hides duplicate raw error');
    assert.match(textOf(alerts[0]), /必要结构缺失/);
    assert.match(textOf(result.querySelector('.skill-next-card')), /检查失败原因和已保存结果/);
    assert.equal(textOf(result.querySelector('[data-skill-recheck]')), '刷新任务状态');
  }
  if (fixture.row === 5) {
    assert.equal(textOf(alerts[0]), execution.error_message);
    assert.equal(textOf(result.querySelector('[data-skill-rerender]')), '重新排版（复用内容）');
    assert.match(textOf(result.querySelector('.skill-next-card')), /不调用模型，复用已保存内容/);
  }
  if (fixture.row === 6) {
    assert.equal(textOf(alerts[0]), '已记录 5 次模型调用 · 50 tokens。模型结果已保存，正在恢复交付，不会重新调用模型。');
    assert.equal(textOf(result.querySelector('[data-skill-recheck]')), '刷新任务状态');
  }
  if (fixture.row === 9) {
    assert.equal(textOf(result.querySelector('[data-skill-action="cancel"]')), '取消执行');
    assert.ok(result.querySelector('[data-skill-action="cancel"]').listeners.has('click'));
  }
  if (fixture.row === 10) {
    assert.equal(result.querySelectorAll('.result-view.execution-result').length, 1, 'Real unarchived fallback still renders the body wrapper');
    assert.equal(result.querySelectorAll('.report-metrics').length, 1, 'Rich metrics content is not stubbed away');
    assert.equal(result.querySelectorAll('.report-table').length, 1, 'Real structured table remains readable');
    assert.deepEqual(result.querySelectorAll('th').map(textOf), ['项目', '说明']);
    assert.match(textOf(result.querySelector('.report-note')), /本地正文提示：这不是执行状态消息，正文内容与结构必须保留。/);
  }
  if (fixture.row === 11) {
    assert.equal(result.querySelectorAll('.report-glance-text').length, 0);
    assert.ok(!result.textContent.includes('这次执行没有摘要段落'));
    assert.equal(textOf(result.querySelector('[data-open-result]')), '查看完整报告');
    assert.equal(result.querySelector('[data-open-result]').getAttribute('data-open-result'), '101');
  }
  const children = result.children.filter(node => node.tagName !== '#text');
  const headingIndex = children.indexOf(result.querySelector('.report-heading'));
  const glance = result.querySelector('.report-glance') || result.querySelector('.result-view.execution-result') || children.find(node => textOf(node) === '暂时没有文本输出。');
  assert.equal(headingIndex, 0);
  if (alerts.length) assert.ok(children.indexOf(alerts[0]) < children.indexOf(glance), 'Merged state alert precedes the summary/body');
  for (const p of children.filter(node => node.tagName === 'p' && hasClass(node, 'muted') && /^(本次用量|当前已记录|已保存到文章库|已归档到历史报告|导出报告)：?/.test(textOf(node)))) {
    assert.ok(children.indexOf(glance) < children.indexOf(p), 'Summary/body precedes usage and artifacts');
  }
  if (fixture.next) assert.equal(children.at(-1), result.querySelector('.skill-next-card'), 'Next-step row is last');
});

for (const [stage, text] of [
  ['known_chunk_identity_conflict', '请求身份冲突，需核对已保存结果；继续恢复可能产生新的模型调用。'],
  ['model_result_unrecorded', '模型响应已收到，本地用量待恢复；报告尚未交付。'],
]) check(`Recovery text remains exact: ${stage}`, () => {
  const { result } = harness({ ...base, status: 'billing_pending', article_id: null, quality_report: null,
    billing: { ...base.billing, recovery_stage: stage, points_deduction_status: 'unknown' } });
  const alerts = result.querySelectorAll('.md-alert');
  assert.equal(alerts.length, 1);
  assert.ok(hasClass(alerts[0], 'md-alert--warning'));
  assert.ok(textOf(alerts[0]).includes(text));
  assert.equal(paragraphs(alerts[0]).length, 1, 'Recovery branch wins over generic unknown-charge text');
  assert.ok(!textOf(alerts[0]).includes('任务总费用未知'));
});

check('Report downloads remain available alongside individual format failures', () => {
  const { result } = harness({ ...base, available_formats: ['markdown', 'html', 'docx'],
    format_status: { markdown: {status: 'available'}, html: {status: 'failed', reason: 'html_fonts'},
      docx: {status: 'available'}, pdf: {status: 'unavailable', reason: 'native_library'} } });
  assert.equal(result.querySelectorAll('[data-format]').length, 3);
  const note = textOf(result.querySelector('[data-format-status]'));
  assert.ok(note.includes('字体附件未通过检查'));
  assert.ok(note.includes('缺少本地渲染库'));
});

check('All three notice sources keep their order, text and escaping', () => {
  const error = '本地错误 <script>不执行</script> & "请核对"';
  const { result } = harness({ ...base, available_formats: [], error_message: error,
    quality_report: { passed: false, checks: [{ name: 'article_body_complete_length', passed: false }] },
    billing: { ...base.billing, recovery_stage: 'model_attempt_unknown' } });
  const alert = result.querySelector('.md-alert');
  assert.ok(hasClass(alert, 'md-alert--danger'));
  assert.equal(result.querySelectorAll('.md-alert').length, 1);
  const lines = paragraphs(alert).map(textOf);
  assert.equal(lines.length, 3);
  assert.equal(lines[0], '已记录 5 次模型调用 · 50 tokens。原模型请求待核验，尚未交付；请勿重复发起。');
  assert.equal(lines[1], error);
  assert.equal(lines[2], '报告已经生成，但尚未达到正式交付标准：可发布正文长度不足。请补全并复核后再进入下一阶段。');
  assert.equal(result.querySelectorAll('script').length, 0, 'Error text cannot create elements');
});

check('Article completion without a next stage removes the whole redundant row', () => {
  const { result } = harness({ ...base, stage_index: 5 });
  assert.equal(result.querySelectorAll('.skill-next-card').length, 0);
  assert.equal(result.querySelectorAll('[data-open-article]').length, 1);
});

{
  const { GEO, elements, calls, messages } = harness({ ...base, result_text: '', structured: {} });
  await GEO.copySkillExecution('');
  check('Real empty-copy guard keeps its original receipt without copying or requesting', () => {
    assert.deepEqual(calls, [], 'No clipboard, download or API action occurs');
    assert.equal(messages.length, 1);
    assert.equal(messages[0].node, elements.skillMessage);
    assert.equal(messages[0].text, '这次执行暂时没有可复制的文本。');
    assert.equal(messages[0].variant, 'danger', 'Preserve the original helper severity');
    assert.equal(elements.skillMessage.textContent, messages[0].text);
  });
}

check('Real empty-download guard keeps its original receipt without downloading or requesting', () => {
  const execution = { ...base, result_text: '', structured: {} };
  const { GEO, elements, calls, messages } = harness(execution);
  GEO.downloadSkillExecution(execution, '');
  assert.deepEqual(calls, [], 'No clipboard, download or API action occurs');
  assert.equal(messages.length, 1);
  assert.equal(messages[0].node, elements.skillMessage);
  assert.equal(messages[0].text, '这次执行暂时没有可下载的文本。');
  assert.equal(messages[0].variant, 'danger', 'Preserve the original helper severity');
  assert.equal(elements.skillMessage.textContent, messages[0].text);
});

console.log(`EXEC-12 real result renderers: ${checks - failures}/${checks} cases passed (retained delivery, quality, recovery and export cases).`);
if (failures) process.exit(1);
