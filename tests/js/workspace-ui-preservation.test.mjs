import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
// 面板已拆到 workbench/panels/*（计划 §7 第 2 步）：读整组模板，顺序与拆分前一致。
import { workbenchTemplateSource } from './helpers/workbench-templates.mjs';
import { readEntrySource } from './helpers/entry-source.mjs';

const source = readEntrySource();
const template = workbenchTemplateSource();
// Empty search responses must not claim that the project has no articles.
const listBody = source.slice(source.indexOf('  function renderContentLibrary('), source.indexOf('  async function clRefreshRow('));
const listState = { contentLibrary: [], clActiveTab: 'all', clSearch: '' };
let listView;
const renderEmptyList = new Function('state', 'elements', 'clSetState', 'clTabMatches', `${listBody}; return renderContentLibrary;`)(listState, { clRows: {} }, value => { listView = value; }, () => true);
renderEmptyList();
assert.equal(listView, 'empty');
listState.clSearch = 'no matching article';
renderEmptyList();
assert.equal(listView, 'empty-filtered');
listState.clActiveTab = 'pending';
renderEmptyList();
assert.equal(listView, 'empty-filtered');
listState.clSearch = '';
listState.clActiveTab = 'recycled';
renderEmptyList();
assert.equal(listView, 'recycled-empty');
const body = source.slice(source.indexOf('  function setSkillWorkspaceMode('), source.indexOf('  function applySkillContext('));
const element = () => ({ hidden: false, attrs: {}, setAttribute(k, v) { this.attrs[k] = v; }, removeAttribute(k) { delete this.attrs[k]; } });
const tabs = ['diagnosis', 'tools'].map(mode => Object.assign(element(), { dataset: { skillWorkspaceMode: mode } }));
const pending = element();
const elements = { skillDiagnosisBlock: element(), skillToolsPanel: element(), skillWorkspaceTabs: Object.assign(element(), { querySelectorAll: () => tabs }), skillPendingStrip: pending };
const state = { skillContext: 'strategy', skillWorkspaceMode: 'diagnosis', skillMaterialRefs: [{ id: 8 }], selectedExecutionId: 9 };
const setMode = new Function('state', 'elements', `${body}; return setSkillWorkspaceMode;`)(state, elements);
// Make any accidental DOM replacement fail: switching must leave form values,
// validation messages, selection and material chips mounted in the same nodes.
Object.defineProperty(elements.skillToolsPanel, 'innerHTML', { set() { throw new Error('Tab switch discarded the form'); } });
setMode('tools');
assert.equal(elements.skillDiagnosisBlock.hidden, true);
assert.equal(elements.skillToolsPanel.hidden, false);
assert.equal(tabs[1].attrs['aria-selected'], 'true');
assert.equal(tabs[0].tabIndex, -1);
setMode('diagnosis');
assert.equal(elements.skillDiagnosisBlock.hidden, false);
assert.equal(elements.skillToolsPanel.hidden, true);
assert.equal(elements.skillPendingStrip.hidden, false);
assert.deepEqual(state.skillMaterialRefs, [{ id: 8 }]);
assert.equal(state.selectedExecutionId, 9);
state.skillContext = 'content';
setMode('diagnosis');
assert.equal(elements.skillWorkspaceTabs.hidden, true);
assert.equal(elements.skillDiagnosisBlock.hidden, true);
assert.equal(elements.skillToolsPanel.hidden, false);
assert.equal(elements.skillToolsPanel.attrs.role, undefined);
state.skillContext = 'strategy';
setMode('tools');
assert.equal(elements.skillWorkspaceTabs.hidden, false);
assert.equal(elements.skillToolsPanel.attrs.role, 'tabpanel');
const editBody = source.slice(source.indexOf('  function syncHeadActionsForEdit('), source.indexOf('  elements.editProjectButton?.addEventListener'));
const editElements = { settingsWrap: element(), editProjectButton: element(), openAnalysisButton: element() };
const syncEdit = new Function('elements', `${editBody}; return syncHeadActionsForEdit;`)(editElements);
syncEdit();
assert.equal(editElements.editProjectButton.attrs['aria-expanded'], 'true');
assert.equal(editElements.editProjectButton.textContent, '收起编辑');
assert.equal(editElements.openAnalysisButton.hidden, true);
editElements.settingsWrap.hidden = true;
syncEdit();
assert.equal(editElements.editProjectButton.textContent, '编辑');
assert.equal(editElements.openAnalysisButton.hidden, false);

// Both project workflows must retain the full input contract and label/help links.
for (const [formId, prefix] of [['createProjectForm', 'createProject'], ['settingsForm', 'settingsProject']]) {
  const form = template.match(new RegExp(`<form[^>]+id="${formId}"[\\s\\S]*?<\\/form>`))[0];
  for (const name of ['name', 'brand_name', 'brand_url', 'target_market', 'product_description', 'target_keywords', 'competitors', 'goals']) {
    assert.match(form, new RegExp(`for="${prefix}-${name}"`));
    assert.match(form, new RegExp(`<(input|textarea)[^>]+id="${prefix}-${name}"[^>]+name="${name}"`));
  }
  assert.match(form, /name="name" required maxlength="255"/);
  assert.match(form, /name="brand_url" type="url" maxlength="500"/);
}
console.log('Workspace tabs preserve mounted forms/materials/execution access; all 16 project fields retained.');
