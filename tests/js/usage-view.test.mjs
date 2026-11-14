import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const nodes = new Map();
const node = id => {
  if (!nodes.has(id)) nodes.set(id, {
    value: 'month', innerHTML: '', textContent: '', disabled: false, events: {},
    replaceChildren() { this.innerHTML = ''; },
    addEventListener(event, listener) { this.events[event] = listener; },
  });
  return nodes.get(id);
};
const requests = [];
let load;
const escapeHtml = value => String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
const GEO = {
  api(path) { return new Promise((resolve, reject) => requests.push({path, resolve, reject})); },
  escapeHtml, formatNumber: String, formatDate: String,
  setMessage(element, text) { element.textContent = text; },
  registerViewLoader(view, loader) { assert.equal(view, 'usage'); load = loader; },
};
vm.runInNewContext(fs.readFileSync('public/geo-entry-usage.js', 'utf8'), {
  window: { GEO }, document: { getElementById: node }, encodeURIComponent,
});
const data = {
  period: { from: '2026-11-01', to: '2026-11-12' },
  summary: { total_calls: 1, total_tokens: 50 },
  records: { data: [{ project_name: '<script>test</script>', provider: 'example', model: 'test-model',
    status: 'unknown', total_tokens: 50, duration_seconds: 1.5, created_at: '2026-11-12' }],
    page: 1, last_page: 2 },
};
const first = load();
assert.equal(requests[0].path, '/api/v1/usage?granularity=month&page=1');
requests[0].resolve(data);
await first;
assert.match(node('usageCalls').innerHTML, /结果未知/);
assert.match(node('usageCalls').innerHTML, /&lt;script&gt;/);
assert.doesNotMatch(node('usageCalls').innerHTML, /<script>/);
assert.equal(node('usagePrevious').disabled, true);
assert.equal(node('usageNext').disabled, false);
node('usageNext').events.click();
assert.equal(requests[1].path, '/api/v1/usage?granularity=month&page=2');
node('usagePeriod').value = 'all';
node('usagePeriod').events.change();
assert.equal(requests[2].path, '/api/v1/usage?granularity=all&page=1');
requests[2].reject(new Error('test read failed'));
await new Promise(resolve => setImmediate(resolve));
assert.equal(node('usageSummary').innerHTML, '');
assert.equal(node('usageCalls').innerHTML, '');
assert.equal(node('usageNext').disabled, true);
assert.equal(node('usageMessage').textContent, 'test read failed');
requests[1].resolve(data);
await new Promise(resolve => setImmediate(resolve));
assert.equal(node('usageCalls').innerHTML, '', 'A stale page cannot overwrite the latest failed read');
assert.equal(node('usageMessage').textContent, 'test read failed');
console.log('Usage view: real loader pagination, escaping, unknown status, read failure and stale-response checks passed.');
