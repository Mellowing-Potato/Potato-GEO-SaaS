import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { readEntrySource } from './helpers/entry-source.mjs';
const source = readEntrySource();
const code = source.slice(source.indexOf('  let workspaceDialogPending'), source.indexOf('  const config ='));
let focused, current;
class Element {
  constructor() { this.events = {}; this.attrs = {}; this.isConnected = true; this.value = ''; }
  setAttribute(k, v) { this.attrs[k] = v; }
  addEventListener(k, fn) { (this.events[k] ||= []).push(fn); }
  fire(k) { for (const fn of this.events[k] || []) fn({ preventDefault() {} }); }
  focus() { focused = this; }
  set innerHTML(value) { this.markup = value; }
  querySelector(key) { return (this.children ||= {})[key] ||= new Element(); }
  showModal() { this.open = true; }
  close() { this.open = false; this.fire('close'); }
  remove() { this.isConnected = false; }
}
const previous = new Element();
const state = { session: { user: { id: 901 } }, selectedProject: { id: 901 }, selectedKbId: 3 };
const events = new Map();
const window = { location: { hash: '#projects' }, addEventListener(k, fn) { events.set(k, fn); }, removeEventListener(k) { events.delete(k); } };
const document = { activeElement: previous, createElement() { return current = new Element(); }, body: { appendChild() {} } };
const context = vm.createContext({ window, document, state });
vm.runInContext(code + ';globalThis.ui={confirmWorkspaceAction,promptWorkspaceValue,showWorkspaceNotice};', context);
const ui = context.ui;
let pending = ui.confirmWorkspaceAction('删除 <img src=x>？');
assert.equal(current.querySelector('p').textContent, '删除 <img src=x>？');
assert.ok(!current.markup.includes('<img'), 'message is not inserted as HTML');
assert.equal(focused, current.querySelector('[data-dialog-cancel]'), 'confirm starts on cancel');
current.querySelector('[data-dialog-cancel]').fire('click');
assert.equal(await pending, false); assert.equal(focused, previous);
pending = ui.promptWorkspaceValue('原因', '初始值');
assert.equal(current.querySelector('input').value, '初始值');
assert.equal(await ui.confirmWorkspaceAction('double click'), false, 'duplicate action does not queue');
current.querySelector('input').value = '  保留原值  ';
current.querySelector('form').fire('submit');
assert.equal(await pending, '  保留原值  ', 'input payload is not silently trimmed');
pending = ui.promptWorkspaceValue('可选原因');
current.querySelector('form').fire('submit');
assert.equal(await pending, '', 'empty and cancel differ');
pending = ui.promptWorkspaceValue('取消'); current.fire('cancel');
assert.equal(await pending, null);
pending = ui.confirmWorkspaceAction('变更项目'); state.selectedProject = { id: 902 };
current.querySelector('form').fire('submit'); assert.equal(await pending, false, 'context change cannot confirm previous action');
pending = ui.confirmWorkspaceAction('导航离开'); window.location.hash = '#asset'; events.get('hashchange')();
assert.equal(await pending, false); assert.equal(current.isConnected, false);
pending = ui.confirmWorkspaceAction('确认一次'); current.querySelector('form').fire('submit'); current.querySelector('form').fire('submit');
assert.equal(await pending, true); assert.equal(events.size, 0);
// Extract the real action by balanced braces; a neighboring renderer may be removed.
function extractFunction(text, name) {
  const signature = new RegExp(`(?:async\\s+)?function\\s+${name}\\s*\\(`).exec(text);
  assert(signature, `Real ${name} function must exist`);
  const start = signature.index;
  const body = text.indexOf('{', start + signature[0].length);
  let depth = 0;
  let quote = null;
  let comment = null;
  for (let index = body; index < text.length; index++) {
    const character = text[index];
    const next = text[index + 1];
    if (comment === 'line') { if (character === '\n') comment = null; continue; }
    if (comment === 'block') { if (character === '*' && next === '/') { comment = null; index++; } continue; }
    if (quote) {
      if (character === '\\') index++;
      else if (character === quote) quote = null;
      continue;
    }
    if (character === '/' && next === '/') { comment = 'line'; index++; continue; }
    if (character === '/' && next === '*') { comment = 'block'; index++; continue; }
    if (character === '"' || character === "'" || character === '`') { quote = character; continue; }
    if (character === '{') depth++;
    if (character === '}' && --depth === 0) return text.slice(start, index + 1);
  }
  assert.fail(`Unbalanced real ${name} function`);
}
// Cancel must not become an empty reason submission. Both original assertions stay.
const rollback = extractFunction(source, 'rollbackProjectStage');
let sent = [];
const actionContext = { state, document: { getElementById() { return { value: 1 }; } }, promptWorkspaceValue: async () => null, api: async (...args) => { sent.push(args); }, setMessage() {}, selectProject: async () => {} };
vm.runInNewContext(rollback + ';globalThis.action=rollbackProjectStage;', actionContext);
await actionContext.action(); assert.equal(sent.length, 0);
actionContext.promptWorkspaceValue = async () => '';
await actionContext.action(); assert.equal(sent.length, 1); assert.equal(JSON.parse(sent[0][1].body).reason, '');
// EXEC-10B: cancel the real new advance confirmation before its old action runs.
const projectHome = readFileSync(new URL('../../public/geo-entry-project-home.js', import.meta.url), 'utf8');
const advanceEntry = extractFunction(projectHome, 'requestProjectStageAdvance');
sent = [];
const advanceContext = {
  state, home: { key: 'local-confirmation', project: { status: 'active' } }, stageActionBusy: false,
  document: { activeElement: previous }, currentProjectStage: () => 0,
  projectStageName: () => '策略诊断', renderProjectStageDrawer() {},
  GEO: { confirmWorkspaceAction: ui.confirmWorkspaceAction, advanceProjectStage: async () => { sent.push('advance'); } },
};
vm.runInNewContext(advanceEntry + ';globalThis.action=requestProjectStageAdvance;', advanceContext);
pending = advanceContext.action();
assert.match(current.querySelector('p').textContent, /推进到「策略诊断」/);
current.querySelector('[data-dialog-cancel]').fire('click');
await pending;
assert.equal(sent.length, 0, 'Cancel new confirmation must not invoke a stage write');
assert.equal(advanceContext.stageActionBusy, false, 'Cancel releases the stage action busy state');
console.log('Workspace dialogs: cancel, confirm, input, duplicate action, context, rollback and new advance cancellation passed');
