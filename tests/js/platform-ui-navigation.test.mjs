import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const source = readFileSync(new URL('../../public/geo-platform-ops.js', import.meta.url), 'utf8');
const start = source.indexOf('  function initNavSpy()');
const end = source.indexOf('\n  /*', start);
const ids = ['sec-money', 'sec-orders', 'sec-anomaly', 'sec-subs', 'sec-invoice', 'sec-health', 'sec-risk', 'sec-words', 'sec-queue'];
function run(includeQueue) {
  const names = includeQueue ? ids : ids.slice(0, -1);
  let focused;
  const panels = Object.fromEntries(names.map(id => [id, { hidden: false, draft: '未提交的候选词', querySelector() { return { setAttribute() {}, focus() { focused = id; } }; }, set innerHTML(_) { throw new Error('panel was remounted'); } }]));
  const links = names.map(id => ({ attrs: { href: '#' + id }, classList: { toggle() {} }, getAttribute(k) { return this.attrs[k]; }, setAttribute(k, v) { this.attrs[k] = v; }, removeAttribute(k) { delete this.attrs[k]; } }));
  const events = {};
  const window = { location: { hash: '#sec-words' }, addEventListener(k, fn) { events[k] = fn; } };
  new Function('$$', 'document', 'window', source.slice(start, end) + '; initNavSpy();')(() => links, { getElementById: id => panels[id] }, window);
  assert.equal(focused, undefined, 'initial load does not steal focus');
  assert.equal(panels['sec-words'].hidden, false);
  for (const id of names) {
    window.location.hash = '#' + id; events.hashchange();
    assert.equal(focused, id);
    assert.equal(Object.values(panels).filter(p => !p.hidden).length, 1);
    assert.equal(links.find(l => l.attrs.href === '#' + id).attrs['aria-current'], 'page');
  }
  window.location.hash = '#sec-words'; events.hashchange();
  assert.equal(panels['sec-words'].draft, '未提交的候选词');
  window.location.hash = '#missing'; events.hashchange();
  assert.equal(panels['sec-money'].hidden, false, 'unknown or gated route falls back to overview');
}
run(true); run(false);
console.log('Platform navigation: all sections, mounted drafts, history, focus and queue gate passed');
