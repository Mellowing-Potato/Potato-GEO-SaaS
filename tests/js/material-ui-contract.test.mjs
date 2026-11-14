import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { readEntrySource } from './helpers/entry-source.mjs';

const source = readEntrySource();
const start = source.indexOf('  function matFormFields(');
const end = source.indexOf('  function openMaterialForm(', start);
const escapeHtml = value => String(value).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
const { fields, payload } = new Function('escapeHtml', `${source.slice(start, end)}; return { fields: matFormFields, payload: matFormPayload };`)(escapeHtml);

// The redesign must keep every submitted field, including author external_link
// and image storage which are easy to overlook when simplifying the form.
const contracts = {
  title: { title: 500, note: 2000, category: 120 },
  keyword: { keyword: 255, note: 2000, category: 120, search_volume: null },
  image: { url: 2000, title: 255, alt: 255, source: 120, storage: 60 },
  author: { name: 255, bio: 5000, avatar_url: 2000, external_link: 2000 },
};
for (const [type, names] of Object.entries(contracts)) {
  const html = fields(type, Object.fromEntries(Object.keys(names).map(name => [name, '<unsafe>"&'])));
  const controls = [...html.matchAll(/<(input|textarea|select)\b([^>]*)>/g)];
  assert.deepEqual(controls.map(m => m[2].match(/name="([^"]+)"/)[1]), Object.keys(names));
  for (const [, , attrs] of controls) {
    const name = attrs.match(/name="([^"]+)"/)[1];
    const id = attrs.match(/id="([^"]+)"/)[1];
    assert.ok(html.includes(`for="${id}"`), `${type}.${name} has an accessible label`);
    if (names[name]) assert.ok(attrs.includes(`maxlength="${names[name]}"`));
    const help = attrs.match(/aria-describedby="([^"]+)"/);
    if (help) assert.ok(html.includes(`id="${help[1]}"`));
  }
  assert.ok(controls[0][2].includes('required'));
  assert.ok(!html.includes('<unsafe>'));
  assert.ok(html.includes('&lt;unsafe&gt;&quot;&amp;'));
}
const form = values => ({ querySelector: selector => ({ value: values[selector.match(/name="([^"]+)"/)[1]] ?? '' }) });
assert.deepEqual(payload(form({ keyword: '  示例  ', search_volume: '0' }), 'keyword'), { keyword: '示例', note: null, category: null, search_volume: 0 });
assert.equal(payload(form({ keyword: '示例' }), 'keyword').search_volume, null);
assert.deepEqual(payload(form({ name: '编辑', external_link: 'https://example.test/author' }), 'author'), { name: '编辑', bio: null, avatar_url: null, external_link: 'https://example.test/author' });
console.log('Material UI field contracts passed: all four types, labels, limits, escaping, and zero/null semantics.');
