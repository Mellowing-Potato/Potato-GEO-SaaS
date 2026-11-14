import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const skillsSource = readFileSync(new URL('../../public/geo-entry-skills.js', import.meta.url), 'utf8');
const reportsSource = readFileSync(new URL('../../public/geo-entry-reports.js', import.meta.url), 'utf8');
function extract(source, name) {
  const synchronous = source.indexOf(`  function ${name}(`);
  const start = synchronous >= 0 ? synchronous : source.indexOf(`  async function ${name}(`);
  assert.ok(start >= 0, `${name} is read from its actual owning module`);
  const body = source.indexOf('{', source.indexOf(')', start));
  let depth = 0;
  for (let i = body; i < source.length; i++) {
    if (source[i] === '{') depth++;
    else if (source[i] === '}' && --depth === 0) return source.slice(start, i + 1);
  }
  throw new Error(`Unclosed function ${name}`);
}
class Node {
  children = [];
  dataset = {};
  attributes = {};
  listeners = new Map();
  nodes = new Map();
  textContent = '';
  _html = '';
  set innerHTML(value) { this._html = value; this.children = []; this.nodes.clear(); }
  get innerHTML() { return this._html; }
  setAttribute(name, value) { this.attributes[name] = value; }
  addEventListener(type, callback) { this.listeners.set(type, callback); }
  appendChild(node) { this.children.push(node); }
  querySelectorAll() { return []; }
  querySelector(selector) {
    if (this.nodes.has(selector)) return this.nodes.get(selector);
    const attribute = selector === '#skillBriefForm' ? 'id="skillBriefForm"' : selector.replace(/^\[|\]$/g, '');
    if (!this.innerHTML.includes(attribute)) return null;
    const node = new Node();
    if (selector === '[data-next-stage-skill]') {
      node.dataset.nextStageSkill = this.innerHTML.match(/data-next-stage-skill="([^"]+)"/)?.[1];
    }
    this.nodes.set(selector, node);
    return node;
  }
}
const escaped = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const registry = [
  { id: 'yao-geo-intent-miner', name: '意图挖掘', category: 'geo-research', stage_index: 0, brief_fields: [] },
  { id: 'yao-geo-explainer-builder', name: '解释型文章', category: 'geo-content-production', stage_index: 2, brief_fields: [] },
  { id: 'page-audit', name: '页面检查', category: 'geo-distribution', stage_index: 3, brief_fields: [] },
];
function harness() {
  const state = { selectedProject: { id: 7 }, selectedSkill: registry[0], selectedExecutionId: 71, skills: registry, skillExecutions: [], analyses: [], skillMaterialRefs: [] };
  const elements = { skillPanel: new Node(), skillList: new Node(), skillBriefPanel: new Node(), skillExecutionResult: new Node(), skillMessage: new Node() };
  const messages = [];
  const calls = { nextSkills: [], analyses: 0, copies: [], downloads: [], refreshes: 0 };
  const context = vm.createContext({
    state, elements, console, Date, Map, Number, JSON, String, Boolean,
    GEO: { matRenderSkillChips() {}, openMatPicker() {} },
    document: { createElement: () => new Node(), querySelector: () => elements.skillBriefMessage ||= new Node() },
    escapeHtml: escaped,
    categoryLabel: category => category === 'geo-research' ? '研究' : '内容生产',
    stageLabel: () => '研究拓词',
    visibleSkills: () => state.skills,
    renderSkillFilters() {},
    setSkillWorkspaceMode: mode => { state.skillWorkspaceMode = mode; },
    renderSkillPendingStrip() {},
    renderSkillExecutionPlaceholder() { elements.skillExecutionResult.innerHTML = ''; },
    matchingSkillExecution: rows => rows.find(row => row.skill_id === state.selectedSkill?.id),
    reportReferenceText: () => '',
    fieldControl: () => { throw new Error('No dynamic fields needed in these fixtures'); },
    skillFormDrafts: new Map(), skillDraftKey: () => 'fixture', saveSkillDraft() {}, runSelectedSkill() {},
    skillExecutionText: execution => execution.result_text || '',
    executionDisplayName: execution => execution.skill_name || execution.skill_id,
    skillExecutionStatusLabel: execution => execution.status,
    formatDate: () => '2026/10/24',
    isExecutionCancellable: status => ['pending', 'running'].includes(status),
    isExecutionInFlight: status => ['pending', 'running', 'processing'].includes(status),
    skillExecutionBillingLine: () => '', skillQualityWarning: () => '',
    formatLabel: value => value,
    STAGE_FULL_LABELS: ['研究', '策略', '内容', '发布', '监测', '迭代'], STAGE_NEXT_HINTS: {},
    goToSkill: id => calls.nextSkills.push(id), runAnalysis: () => { calls.analyses++; },
    tryParseJsonValue: value => { try { return JSON.parse(value); } catch { return null; } },
    reportOneLineSummary: parsed => parsed.summary || '', glanceFallbackLine: () => '',
    renderExecutionOutput: () => '<p>existing output</p>',
    setMessage: (node, text, variant = 'neutral') => { if (!node) return; node.textContent = text; node.variant = variant; messages.push({ node, text, variant }); },
    copyText: async text => calls.copies.push(text), downloadText: (name, text) => calls.downloads.push({ name, text }),
    executionPollers: new Map(),
    api: async () => { throw new Error('Unexpected network operation in fixture test'); },
    window: { setTimeout: () => 1, clearTimeout() {} },
    loadSkillExecutions: async () => { calls.refreshes++; }, loadArticles: async () => {},
    statusLabel: value => value,
  });
  for (const name of ['isContentProductionSkill', 'executionProducesArticle', 'renderSkillWorkspace', 'renderSkillList', 'renderSkillBriefForm', 'generatedArticlesEntry', 'selectSkill', 'renderSelectedSkillResult', 'renderSkillExecutionResult', 'nextStageAfterExecution', 'skillNextActionPanel', 'upsertSkillExecution', 'skillExecutionStateChanged', 'isTerminalExecutionStatus', 'pollSkillExecution', 'stopPollingExecution', 'setSkillBriefMessage']) {
    vm.runInContext(extract(skillsSource, name), context);
  }
  for (const name of ['renderExecutionGlance', 'copySkillExecution', 'downloadSkillExecution']) vm.runInContext(extract(reportsSource, name), context);
  return { context, state, elements, messages, calls };
}
const buttons = html => [...html.matchAll(/<button\b([^>]*)>([\s\S]*?)<\/button>/g)].map(match => ({
  attributes: match[1], text: match[2].replace(/<[^>]*>/g, '').trim(), classes: (match[1].match(/class="([^"]*)"/)?.[1] || '').split(/\s+/),
}));
const primary = html => buttons(html).filter(button => button.classes.includes('primary'));

for (const [skill, stage] of [[registry[0], 0], [registry[1], 2]]) {
  const { context, state, elements, calls } = harness();
  state.selectedSkill = skill;
  const execution = { id: 71, skill_id: skill.id, skill_name: skill.name, stage_index: stage, status: 'completed', article_id: 91, result_text: '{"summary":"已有归档内容"}', created_at: '2026-10-24' };
  state.skillExecutions = [execution];
  context.selectSkill(skill.id);
  const formHtml = elements.skillBriefPanel.innerHTML;
  const resultHtml = elements.skillExecutionResult.innerHTML;
  assert.equal(primary(formHtml + resultHtml).length, 1, `${skill.name}: the mounted form and actual result renderer have one primary`);
  assert.equal(primary(formHtml + resultHtml)[0].text, '开始执行');
  assert.equal(elements.skillMessage.textContent, '', 'selection does not repeat that the visible brief is ready');
  assert.match(resultHtml, /data-open-result="91"/);
  assert.ok(buttons(resultHtml).find(button => button.attributes.includes('data-open-result')).classes.includes('secondary'));
  assert.match(formHtml, /<p class="eyebrow">(?:研究|内容生产)<\/p>/, 'category eyebrow survives');
  assert.ok(!formHtml.includes('<span class="badge">'), 'the duplicate stage pill is removed');
  assert.equal(elements.skillBriefPanel.querySelector('#skillBriefForm').listeners.get('submit'), context.runSelectedSkill, 'the original paid submit handler remains attached');
  if (skill === registry[1]) {
    assert.match(resultHtml, /data-next-stage-skill="page-audit"/);
    const next = elements.skillExecutionResult.querySelector('[data-next-stage-skill]');
    next.listeners.get('click')({ currentTarget: next });
    assert.deepEqual(calls.nextSkills, ['page-audit'], 'next-skill event still navigates through the existing caller');
    assert.match(resultHtml, /data-open-article="91"/);
  }
}
{
  const { context, state } = harness();
  state.skills = [{ ...registry[1], stage_index: 0 }];
  const html = context.skillNextActionPanel({ skill_id: registry[1].id, status: 'completed', stage_index: 0, article_id: 91 });
  assert.match(html, /class="button secondary small" type="button" data-next-run-analysis="1"/);
  assert.equal(primary(html).length, 0, 'the diagnostic continuation is secondary too');
  const fallback = context.renderExecutionGlance({ article_id: 91 }, '');
  assert.ok(!fallback.includes('这次执行没有摘要段落'), 'EXEC-12 B2: a missing summary has no placeholder sentence');
  assert.ok(fallback.includes('data-open-result="91"'), 'EXEC-12 §3.7: the existing full-report action remains');
}
{
  const { context, state, elements } = harness();
  state.skills = [registry[0], { ...registry[1], available: false }, { id: 'visibility', action_path: '/visibility', action_label: '打开监测', available: true }];
  context.renderSkillList();
  assert.ok(!elements.skillList.children[0].innerHTML.includes('class="badge"'), 'ordinary executable skills have no availability badge');
  assert.match(elements.skillList.children[1].innerHTML, /data-variant="neutral">暂不可用/);
  assert.match(elements.skillList.children[2].innerHTML, /data-variant="success">打开监测/);
}
for (const status of ['completed', 'failed', 'running']) {
  const { context, state, elements } = harness();
  const execution = { id: 71, skill_id: registry[0].id, status, error_message: status === 'failed' ? '保留真实失败原因' : '', article_id: null };
  state.skillExecutions = [{ ...execution }];
  context.api = async () => ({ execution, is_terminal: status !== 'running', relay: { completed_steps: 2, total_steps: 5 } });
  context.pollSkillExecution(71, { skillId: registry[0].id, projectId: 7 });
  await new Promise(resolve => setImmediate(resolve));
  if (status === 'completed') {
    assert.equal(elements.skillMessage.textContent, '');
    assert.equal(elements.skillBriefMessage.textContent, '执行完成，报告已生成，结果在下方。', 'form-level completion feedback remains useful');
  } else if (status === 'failed') {
    assert.equal(elements.skillMessage.textContent, '保留真实失败原因');
    assert.equal(elements.skillMessage.variant, 'danger');
  } else {
    assert.equal(elements.skillMessage.textContent, '技能running（进度 2/5）...');
    assert.equal(elements.skillBriefMessage.textContent, elements.skillMessage.textContent, 'real-time progress still reaches both original slots');
  }
}
{
  const { context, elements, calls } = harness();
  context.api = async () => { throw new Error('local transient failure'); };
  context.pollSkillExecution(71, { skillId: registry[0].id, projectId: 7 });
  await new Promise(resolve => setImmediate(resolve));
  assert.match(elements.skillMessage.textContent, /后台任务仍会继续；5 秒后自动重试，请勿重复提交/);
  assert.equal(elements.skillMessage.variant, 'warning');
  await context.copySkillExecution('原样结果');
  assert.equal(calls.copies[0], '原样结果');
  assert.equal(elements.skillMessage.textContent, '技能结果已复制。', 'copy acknowledgement is preserved');
  context.downloadSkillExecution({ id: 71 }, '原样结果');
  assert.deepEqual(calls.downloads, [{ name: 'skill-execution-71.md', text: '原样结果' }]);
  assert.equal(elements.skillMessage.textContent, '技能结果已下载。', 'download acknowledgement is preserved');
}
console.log('EXEC-11 C / §6 ruling 4: original renderers retain one primary action, useful messages, category and result routes.');
