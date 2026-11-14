import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const source = readFileSync(new URL('../../public/geo-visibility.js', import.meta.url), 'utf8');
const template = readFileSync(new URL('../../resources/views/visibility.blade.php', import.meta.url), 'utf8');
const ids = ['sec-list', 'sec-new', 'sec-detail'];
const events = {};
let focused = null;
const formState = { projectId: 901, questions: '尚未提交的问题', disabled: false };
const progress = { textContent: '整批 3 个问题，已完成 1 个。' };
const sections = Object.fromEntries(ids.map(id => [id, {
  hidden: false,
  querySelector() { return { focus() { focused = id; } }; },
  scrollIntoView() {},
  set innerHTML(_) { throw new Error('navigation must not remount contents'); },
}]));
const links = ids.map(id => ({
  attributes: { href: '#' + id },
  classList: { toggle() {} },
  getAttribute(name) { return this.attributes[name]; },
  setAttribute(name, value) { this.attributes[name] = value; },
  removeAttribute(name) { delete this.attributes[name]; },
  addEventListener(name, handler) { this[name] = handler; },
}));
const win = { location: { hash: '#sec-new' }, addEventListener(name, handler) { events[name] = handler; } };
const start = source.indexOf('  function showVisibilitySection(');
const end = source.indexOf('  /* ---------------- 首屏认证引导', start);
const helpers = new Function('$', '$$', 'window', `${source.slice(start, end)}; return { wireSectionNav, showVisibilitySection };`)(selector => sections[selector.slice(1)], () => links, win);
helpers.wireSectionNav();
assert.equal(sections['sec-new'].hidden, false, 'legacy incoming sec-new hash opens the form');
assert.equal(sections['sec-list'].hidden, true);
assert.equal(focused, null, 'initial render does not steal focus');
for (const id of ['sec-list', 'sec-detail', 'sec-new']) {
  win.location.hash = '#' + id;
  events.hashchange();
  assert.deepEqual(ids.filter(name => !sections[name].hidden), [id]);
  assert.equal(focused, id);
  assert.equal(links.find(link => link.attributes['aria-current'] === 'page').attributes.href, '#' + id);
}
helpers.showVisibilitySection('#unknown', false);
assert.equal(sections['sec-list'].hidden, false);
assert.deepEqual(formState, { projectId: 901, questions: '尚未提交的问题', disabled: false });
assert.equal(progress.textContent, '整批 3 个问题，已完成 1 个。');
assert.ok(template.indexOf('id="formMsg"') < template.indexOf('<section id="sec-list">'), 'shared batch progress stays outside hidden views');
const form = template.match(/<form id="newRunForm"[\s\S]*?<\/form>/)[0];
assert.match(form, /name="project_id" id="projectId" required disabled/);
assert.match(form, /name="questions" id="questions"[\s\S]*?required/);
for (const id of ['questionCount', 'questionSuggestions', 'suggestionList', 'submitBtn']) assert.ok(form.includes(`id="${id}"`));

// A foreground detail selection opens its view; background polling never steals the current view.
const detailStart = source.indexOf('  function openDetail(');
const detailEnd = source.indexOf('  function renderDetail(', detailStart);
const host = { innerHTML: '' };
const openDetail = new Function('$', 'window', 'apiGet', 'stopPoll', 'loading', 'renderDetail', 'scheduleDetailPoll', 'errorState', `var activeRunId = null, detailRequestSeq = 0, detailSignature = ''; ${source.slice(detailStart, detailEnd)}; return openDetail;`)(() => host, win, async () => ({ data: { id: 991, is_terminal: true } }), () => {}, () => {}, () => {}, () => {}, () => {});
openDetail(991);
assert.equal(win.location.hash, '#sec-detail');
await Promise.resolve();
win.location.hash = '#sec-new';
openDetail(991, { background: true });
await Promise.resolve();
assert.equal(win.location.hash, '#sec-new');
console.log('Monitoring navigation preserves mounted form/progress and legacy anchors; background detail polling does not change views.');
