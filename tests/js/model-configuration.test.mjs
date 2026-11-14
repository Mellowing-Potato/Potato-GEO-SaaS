import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

// Execute the actual form handlers with isolated DOM/API fixtures, without a model call.
const source = readFileSync(new URL('../../public/geo-entry.js', import.meta.url), 'utf8');
const start = source.indexOf('  async function loadAiKeys(');
const end = source.indexOf('  // Knowledge Base (RAG)', start);
assert(start >= 0 && end > start);
const fields = Object.fromEntries(['provider', 'base_url', 'model', 'api_key', 'label', 'json_mode'].map(name => [name, { value: '', checked: false }]));
const message = {}, status = {}, jsonMode = {};
const buttons = [{ dataset: { aikeyAction: 'toggle' } }, { dataset: { aikeyAction: 'delete' } }];
const form = {
  dataset: { modelSlot: 'embedding' }, elements: fields,
  reset() { Object.values(fields).forEach(field => { field.value = ''; field.checked = false; }); fields.provider.value = 'openai'; },
  reportValidity: () => true,
  querySelector: selector => ({ '[data-model-message]': message, '[data-model-status]': status, '[data-custom-json]': jsonMode }[selector]),
  querySelectorAll: () => buttons,
};
const otherFields = Object.fromEntries(Object.keys(fields).map(name => [name, { value: '', checked: false }]));
const otherMessage = {}, otherStatus = {}, otherJsonMode = {};
const otherForm = {
  dataset: { modelSlot: 'text' }, elements: otherFields,
  reset() { Object.values(otherFields).forEach(field => { field.value = ''; field.checked = false; }); otherFields.provider.value = 'openai'; },
  querySelector: selector => ({ '[data-model-message]': otherMessage, '[data-model-status]': otherStatus, '[data-custom-json]': otherJsonMode }[selector]),
  querySelectorAll: () => [],
};
const maskedKey = 'sk-test-form-connection'.slice(0, 4) + '••••tion';
const state = { aiKeys: [], aiPresets: { openai: { base_url: 'https://api.example.test/v1', json_mode: true } } };
let resolveRequest;
const calls = [];
const handlers = new Function('state', 'elements', 'api', 'FormData', 'setMessage', 'validationMessage', 'confirmWorkspaceAction',
  source.slice(start, end) + '\nreturn { renderAiKeys, submitModelConfiguration, modelConfigurationPayload };'
)(state, { aikeyList: { querySelectorAll: () => [form, otherForm] }, aikeysMessage: {} }, async (path, options) => {
  calls.push({ path, options });
  if (path === '/api/v1/ai-keys' && !options) return { data: state.aiKeys, presets: state.aiPresets };
  return new Promise((resolve, reject) => { resolveRequest = { resolve, reject }; });
}, class {
  constructor(input) { this.fields = input.elements; }
  get(name) { return name === 'json_mode' ? (this.fields[name].checked ? 'on' : null) : this.fields[name].value; }
}, (node, text, tone) => { node.textContent = text; node.tone = tone; }, error => error.message, async () => true);

handlers.renderAiKeys();
assert.equal(status.textContent, '未配置');
assert.equal(fields.base_url.value, 'https://api.example.test/v1');
assert(buttons.every(button => button.hidden));
assert.equal(calls.length, 0, 'Rendering configuration must not spend tokens.');

fields.api_key.value = 'sk-test-form-connection';
fields.model.value = 'test-embedding';
const testing = handlers.submitModelConfiguration(form, true);
assert(buttons.every(button => button.disabled));
await handlers.submitModelConfiguration(form, true);
assert.equal(calls.length, 1, 'Double click cannot create a second connection test.');
assert.equal(calls[0].path, '/api/v1/ai-keys/test');
assert.equal(JSON.parse(calls[0].options.body).slot, 'embedding');
resolveRequest.resolve({ success: true, message: '连接测试通过。', verification_token: 'test-proof' });
await testing;
assert.equal(form.dataset.verificationToken, 'test-proof');
assert.equal(message.tone, 'success');
assert.equal(calls.length, 1, 'A successful probe does not save a model automatically.');

const rejected = handlers.submitModelConfiguration(form, false);
resolveRequest.reject(new Error('当前知识库已用原模型生成向量。更换模型前，请先删除知识库中的材料，再重新导入。'));
await rejected;
assert.equal(message.tone, 'danger');
assert.match(message.textContent, /先删除知识库中的材料/);
assert.equal(fields.api_key.value, 'sk-test-form-connection', 'A rejected save preserves the user input.');
assert(buttons.every(button => !button.disabled));

state.aiKeys = [{ id: 1, slot: 'embedding', provider: 'openai', model: 'test-embedding',
  base_url: 'https://api.example.test/v1', key_preview: maskedKey, is_active: true }];
otherFields.model.value = 'test-unsaved-text';
otherFields.api_key.value = 'sk-test-unsaved-text';
otherFields.label.value = '尚未保存的配置';
otherForm.dataset.verificationToken = 'test-other-proof';
const saving = handlers.submitModelConfiguration(form, false);
assert.equal(JSON.parse(calls.at(-1).options.body).verification_token, 'test-proof');
resolveRequest.resolve({ message: '模型配置已保存' });
await saving;
assert.equal(fields.api_key.value, '');
assert.equal(form.dataset.verificationToken, undefined);
assert.equal(status.textContent, '已启用 · ' + maskedKey);
assert(buttons.every(button => !button.hidden));
assert.equal(otherFields.api_key.value, 'sk-test-unsaved-text', 'Saving one model must preserve another model\'s unsaved Key.');
assert.equal(otherFields.model.value, 'test-unsaved-text');
assert.equal(otherFields.label.value, '尚未保存的配置');
assert.equal(otherForm.dataset.verificationToken, 'test-other-proof', 'Saving one slot must preserve another slot\'s connection proof.');
assert.equal(calls.filter(call => call.path.endsWith('/test')).length, 1);
console.log('Model configuration: masked credentials, explicit probe, duplicate prevention, lock error and save passed.');
