import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
// 面板已拆到 workbench/panels/*（计划 §7 第 2 步）：读整组模板，顺序与拆分前一致。
import { workbenchTemplateSource } from './helpers/workbench-templates.mjs';
import { readEntrySource } from './helpers/entry-source.mjs';
const source = readEntrySource();
const template = workbenchTemplateSource();
const expected = JSON.parse(readFileSync(new URL('../fixtures/ui/publishing-form-contract.json', import.meta.url), 'utf8'));
const form = template.match(/<form\b[^>]*id="createChannelForm"[^>]*>[\s\S]*?<\/form>/)[0];
const actual = [...form.matchAll(/<(input|textarea|select)\b([^>]*)>/g)].map(([, tag, text]) => {
  const attrs = Object.fromEntries([...text.matchAll(/([\w-]+)(?:="([^"]*)")?/g)].map(m => [m[1], m[2] || true]));
  assert.match(form, new RegExp(`for="${attrs.id}"`), `${attrs.name} has a visible label`);
  return { tag, ...Object.fromEntries(['name', 'type', 'required', 'disabled', 'readonly', 'checked', 'maxlength', 'min', 'max'].filter(k => k in attrs).map(k => [k, attrs[k]])) };
});
assert.deepEqual(actual, expected, 'all eight original channel controls retain submission constraints');
const options = [...form.matchAll(/<option value="([^"]+)"/g)].map(m => m[1]);
assert.deepEqual(options, ['wordpress_rest', 'http_webhook', 'none', 'bearer', 'basic', 'wordpress_application_password', 'hmac', 'proxy', 'static', 'hybrid']);

// Use real presentation helpers: changing modes must not reset typed values or binding choices.
const start = source.indexOf('  function setChannelFormOpen(');
const end = source.indexOf('  function syncCreateChannelFields(', start);
let focused = '';
const attrs = {};
const fields = { name: { value: '未保存渠道', focus() { focused = 'name'; } }, secret: { value: 'fixture-only' } };
const elements = {
  channelForm: { hidden: true },
  createChannelForm: { elements: fields, reset() { throw new Error('mode switch must not reset the form'); } },
  addChannelButton: { setAttribute(k, v) { attrs[k] = v; }, focus() { focused = 'add'; } },
  channelList: { style: { display: 'none' } }, channelDetail: { hidden: false },
};
const state = { selectedChannelId: 953, projectChannels: [{ id: 951 }] };
const closeStart = source.indexOf('  function closeChannelDetail(');
const closeEnd = source.indexOf('  async function checkChannelHealth(', closeStart);
const setOpen = new Function('elements', 'state', `${source.slice(closeStart, closeEnd)}\n${source.slice(start, end)}; return setChannelFormOpen;`)(elements, state);
setOpen(true);
assert.equal(elements.channelDetail.hidden, true);
assert.equal(elements.channelForm.hidden, false);
assert.equal(attrs['aria-expanded'], 'true');
assert.equal(focused, 'name');
setOpen(false);
assert.equal(elements.channelForm.hidden, true);
assert.equal(elements.channelList.style.display, '');
assert.equal(attrs['aria-expanded'], 'false');
assert.equal(focused, 'add');
assert.equal(fields.name.value, '未保存渠道');
assert.equal(fields.secret.value, 'fixture-only');
assert.deepEqual(state.projectChannels, [{ id: 951 }]);
console.log('Publishing controls, channel options and mode transitions retain values and restore focus.');
