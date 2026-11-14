import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const source = readFileSync(new URL('../../public/geo-manual-publish.js', import.meta.url), 'utf8');
const template = readFileSync(new URL('../../resources/views/manual-publish.blade.php', import.meta.url), 'utf8');
const contracts = JSON.parse(readFileSync(new URL('../fixtures/ui/manual-publish-form-contracts.json', import.meta.url), 'utf8'));
for (const [id, expected] of Object.entries(contracts)) {
  const form = template.match(new RegExp(`<form\\b[^>]*\\bid="${id}"[^>]*>[\\s\\S]*?</form>`))?.[0];
  assert.ok(form, `${id} remains mounted`);
  const actual = [...form.matchAll(/<(input|textarea|select)\b([^>]*)>/g)].map(([, tag, raw]) => {
    const attrs = Object.fromEntries([...raw.matchAll(/([\w-]+)(?:="([^"]*)")?/g)].map(m => [m[1], m[2] || true]));
    assert.ok(form.includes(`for="${attrs.id}"`), `${attrs.id} keeps an explicit label`);
    return { tag, ...Object.fromEntries(['id', 'name', 'type', 'required', 'disabled', 'readonly', 'checked', 'maxlength', 'min', 'max'].filter(k => k in attrs).map(k => [k, attrs[k]])) };
  });
  assert.deepEqual(actual, expected, `${id} retains field constraints and disabled assignment default`);
}

const ids = ['sec-list', 'sec-new', 'sec-detail', 'sec-settings'];
const state = { content: '未保存的内容', revision: 7, completeUrl: 'https://example.invalid/proof' };
let focused;
const sections = Object.fromEntries(ids.map(id => [id, {
  hidden: false, state,
  querySelector() { return { focus() { focused = id; } }; }, scrollIntoView() {},
  set innerHTML(_) { throw new Error('navigation must not remount the editor'); },
}]));
const links = ids.map(id => ({
  attrs: { href: '#' + id }, classList: { toggle() {} },
  getAttribute(name) { return this.attrs[name]; },
  setAttribute(name, value) { this.attrs[name] = value; },
  removeAttribute(name) { delete this.attrs[name]; },
  addEventListener(name, handler) { this[name] = handler; },
}));
const events = {};
const win = { location: { hash: '#sec-new' }, addEventListener(name, handler) { events[name] = handler; } };
const start = source.indexOf('  function showManualPublishSection(');
const end = source.indexOf('  /* ---------------- 启动', start);
const wire = new Function('$', '$$', 'window', `${source.slice(start, end)}; return wireManualPublishNav;`)(selector => sections[selector.slice(1)], () => links, win);
wire();
assert.equal(sections['sec-new'].hidden, false);
assert.equal(focused, undefined);
for (const id of ['sec-detail', 'sec-settings', 'sec-list', 'sec-new', 'sec-detail']) {
  win.location.hash = '#' + id; events.hashchange();
  assert.deepEqual(ids.filter(key => !sections[key].hidden), [id]);
  assert.equal(focused, id);
  assert.equal(links.find(link => link.attrs['aria-current'] === 'page').attrs.href, '#' + id);
}
assert.deepEqual(sections['sec-detail'].state, { content: '未保存的内容', revision: 7, completeUrl: 'https://example.invalid/proof' });

// Check the real dynamically generated editor, including the hidden concurrency revision.
const editStart = source.indexOf('  function renderEditForm(');
const editEnd = source.indexOf('  function showEditForm(', editStart);
const renderEdit = new Function('PLATFORMS', 'esc', 'isoToLocalInput', `${source.slice(editStart, editEnd)}; return renderEditForm;`)([['zhihu', '知乎']], String, String);
const editor = renderEdit({ type: 'comment', platform: 'zhihu', revision: 7, content: '待编辑内容' });
assert.match(editor, /type="hidden" id="editRevision" value="7"/);
const fieldIds = [...editor.matchAll(/<(?:input|textarea|select)\b[^>]*\bid="([^"]+)"/g)].map(m => m[1]);
assert.deepEqual(fieldIds, ['editRevision', 'editType', 'editPersona', 'editAccount', 'editPlatform', 'editCustomPlatform', 'editArticle', 'editContent', 'editTargetUrl', 'editTargetContext', 'editScheduledAt', 'editAssigned']);
assert.match(editor, /id="editAssigned" disabled/);
// The installer creates a platform administrator. Its own workspace settings
// must remain usable, while editors and other roles retain read-only settings.
const adminStart = source.indexOf('  function isAdmin(');
const adminEnd = source.indexOf('  function accountLoginUrl(', adminStart);
const visibilityStart = source.indexOf('  function applySettingsWriteVisibility(');
const visibilityEnd = source.indexOf('  /* ---------------- 文章选择器', visibilityStart);
for (const role of ['admin', 'platform_admin', 'editor', 'member', '']) {
  const fields = Object.fromEntries(['#personaForm', '#accountForm', '#personaListMsg', '#accountListMsg']
    .map(id => [id, { hidden: false, textContent: '' }]));
  const apply = new Function('userInfo', '$', `${source.slice(adminStart, adminEnd)}\n${source.slice(visibilityStart, visibilityEnd)}\nreturn applySettingsWriteVisibility;`)
    (() => ({ role }), selector => fields[selector]);
  apply();
  const readOnly = !['admin', 'platform_admin'].includes(role);
  assert.equal(fields['#personaForm'].hidden, readOnly, `${role}: persona settings access`);
  assert.equal(fields['#accountForm'].hidden, readOnly, `${role}: account settings access`);
  assert.equal(fields['#accountListMsg'].textContent.includes('仅管理员'), readOnly);
}
console.log('Manual publishing preserves form state and grants both workspace administrator roles access to settings.');
