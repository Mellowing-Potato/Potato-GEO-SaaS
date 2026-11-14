import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
// 面板已拆到 workbench/panels/*（计划 §7 第 2 步）：读整组模板，顺序与拆分前一致。
import { workbenchTemplateSource } from './helpers/workbench-templates.mjs';
import { readEntrySource } from './helpers/entry-source.mjs';
const source = readEntrySource();
const template = workbenchTemplateSource();
const contracts = JSON.parse(readFileSync(new URL('../fixtures/ui/lead-form-contracts.json', import.meta.url), 'utf8'));
for (const [id, expected] of Object.entries(contracts)) {
  const form = template.match(new RegExp(`<form\\b[^>]*\\bid="${id}"[^>]*>[\\s\\S]*?</form>`))?.[0];
  assert.ok(form, `${id} stays mounted`);
  const actual = [...form.matchAll(/<(input|textarea|select)\b([^>]*)>/g)].map(([, tag, raw]) => {
    const attrs = Object.fromEntries([...raw.matchAll(/([\w-]+)(?:="([^"]*)")?/g)].map(m => [m[1], m[2] || true]));
    return { tag, ...Object.fromEntries(['name', 'type', 'required', 'disabled', 'readonly', 'checked', 'maxlength', 'min', 'max', 'hidden'].filter(k => k in attrs).map(k => [k, attrs[k]])) };
  });
  assert.deepEqual(actual, expected, `${id} retains all fields and constraints`);
}

// Execute the real submit paths with a rejecting API stub: no real writes and no copied payload logic.
function extract(name, next) {
  const start = source.indexOf(`  async function ${name}(`);
  const end = source.indexOf(`  ${next}`, start);
  assert.ok(start >= 0 && end > start);
  return source.slice(start, end);
}
let request;
const fields = [{ label: '姓名', name: 'name', type: 'text', options: '', required: true }];
const state = { leadEditingFormId: 42, leadFormSubmitInFlight: false, leadDrawerSubmitInFlight: false, leadDrawerRow: { id: 81 } };
const elements = Object.fromEntries(['name', 'slug', 'status', 'description', 'submit_button_label', 'success_message', 'notify_webhook_format', 'notify_webhook_url'].map(k => [k, { value: '' }]));
elements.name.value = '样例表单'; elements.status.value = 'active'; elements.notify_webhook_format.value = 'robot';
elements.notify_webhook_clear = { checked: false };
const doc = { getElementById() { return { disabled: false }; } };
const api = async (path, options) => { request = { path, method: options.method, payload: JSON.parse(options.body) }; throw Object.assign(new Error('fixture rejection'), { status: 422 }); };
const submit = new Function('state', 'document', 'collectLeadFields', 'validateLeadFields', 'setMessage', 'api', 'errorNoText', `${extract('submitLeadFormEditor', 'async function toggleLeadFormStatus(')}; return submitLeadFormEditor;`)(state, doc, () => fields, () => '', () => {}, api, () => 'test');
await submit({ preventDefault() {}, currentTarget: { elements } });
assert.equal(request.method, 'PUT');
assert.equal(request.path, '/api/v1/lead-forms/42');
assert.ok(!Object.hasOwn(request.payload, 'notify_webhook_url'), 'blank notification input preserves stored config');
assert.deepEqual(request.payload.fields, fields);
assert.equal(state.leadFormSubmitInFlight, false);
elements.notify_webhook_clear.checked = true;
await submit({ preventDefault() {}, currentTarget: { elements } });
assert.equal(request.payload.notify_webhook_url, '', 'explicit checkbox clears notification address');
elements.notify_webhook_clear.checked = false;
elements.notify_webhook_url.value = ' https://example.invalid/robot ';
await submit({ preventDefault() {}, currentTarget: { elements } });
assert.equal(request.payload.notify_webhook_url, 'https://example.invalid/robot');
const drawerSubmit = new Function('state', 'document', 'setMessage', 'api', 'errorNoText', `${extract('submitLeadDrawer', '// ── 页签二')}; return submitLeadDrawer;`)(state, doc, () => {}, api, () => 'test');
await drawerSubmit({ preventDefault() {}, currentTarget: { elements: { status: { value: 'contacted' }, note: { value: '' } } } });
assert.equal(request.payload.note, '', 'clearing follow-up notes is submitted, not silently omitted');
assert.equal(request.payload.status, 'contacted');
assert.equal(state.leadDrawerSubmitInFlight, false);
console.log('Lead filters and editor fields retained; blank notification, explicit clear and empty follow-up note retain distinct semantics.');
