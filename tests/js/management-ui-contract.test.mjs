import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
// 面板已拆到 workbench/panels/*（计划 §7 第 2 步）：读整组模板，顺序与拆分前一致。
import { workbenchTemplateSource } from './helpers/workbench-templates.mjs';
const template = workbenchTemplateSource();
// Captured before the visual migration. These are submission contracts, not CSS.
const contracts = JSON.parse(readFileSync(new URL('../fixtures/ui/management-form-contracts.json', import.meta.url), 'utf8'));
for (const [id, expected] of Object.entries(contracts)) {
  const form = template.match(new RegExp(`<form\\b[^>]*\\bid="${id}"[^>]*>[\\s\\S]*?</form>`))?.[0];
  assert.ok(form, `${id} remains mounted`);
  const actual = [...form.matchAll(/<(input|textarea|select)\b([^>]*)>/g)].map(([, tag, attrText]) => {
    const attrs = Object.fromEntries([...attrText.matchAll(/([\w-]+)(?:="([^"]*)")?/g)].map(m => [m[1], m[2] || true]));
    return { tag, ...Object.fromEntries(['name', 'type', 'required', 'disabled', 'readonly', 'checked', 'maxlength', 'min', 'max', 'accept'].filter(k => k in attrs).map(k => [k, attrs[k]])) };
  });
  assert.deepEqual(actual, expected, `${id}: preserve submitted fields, limits and defaults`);
}
assert.match(template, /id="kbUploadProjectField" hidden/);
assert.match(template, /id="kbIngestProjectField" hidden/);
assert.match(template, /value="warning"/);
assert.match(template, /value="blocked"/);
console.log('Management forms retain fields, validation, automatic distribution default, and knowledge project attribution.');
