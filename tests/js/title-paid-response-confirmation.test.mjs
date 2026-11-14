#!/usr/bin/env node
/** A paid title request keeps its retry key until the saved rows are confirmed. */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = readFileSync(new URL('../../public/geo-entry-content-library.js', import.meta.url), 'utf8');
const start = source.indexOf('  function confirmedGeneratedTitles(');
const end = source.indexOf('  async function submitGenerateTitles(', start);
assert.ok(start > 0 && end > start);
const confirm = new Function(`${source.slice(start, end)}; return confirmedGeneratedTitles;`)();
const adoptStart = source.indexOf('  function adoptClaimedTitleKey(', start);
const state = { session: { user: { id: 7 }, tenant: { id: 9 } } };
const values = new Map([['paid-slot', JSON.stringify({ key: 'local-key', owner_user_id: 7, owner_tenant_id: 9 })]]);
const sessionStorage = {
  getItem: (key) => values.get(key) ?? null,
  setItem: (key, value) => values.set(key, value),
};
const adopt = new Function('state', 'sessionStorage', `${source.slice(adoptStart, end)}; return adoptClaimedTitleKey;`)(state, sessionStorage);
const request = { key: 'local-key', storageKey: 'paid-slot' };
adopt(request, 'server-original-key', 7, 9);
assert.equal(request.key, 'server-original-key');
assert.equal(JSON.parse(values.get('paid-slot')).key, 'server-original-key');
assert.throws(() => adopt(request, 'wrong key!', 7, 9));
state.session.user.id = 8;
assert.throws(() => adopt(request, 'another-key', 7, 9));
state.session.user.id = 7;

const valid = { object: 'title_generation', count: 2, data: [
  { id: 11, title: '标题一' }, { id: 12, title: '标题二' },
] };
assert.deepEqual(confirm(valid), valid.data);
for (const body of [
  null,
  { ...valid, object: 'wrong_result' },
  { ...valid, count: 1 },
  { ...valid, data: null },
  { ...valid, data: [{ id: 11, title: '' }, valid.data[1]] },
  { ...valid, data: [{ id: 0, title: '标题一' }, valid.data[1]] },
]) {
  assert.throws(() => confirm(body), (error) => error.responseParseFailed === true);
}

const submit = source.slice(end, source.indexOf('  function ', end));
assert.ok(submit.includes('confirmedGeneratedTitles(body)'));
assert.ok(submit.indexOf('generate/claim') < submit.indexOf('adoptClaimedTitleKey(pendingRequest,'));
assert.ok(submit.indexOf('adoptClaimedTitleKey(pendingRequest,') < submit.indexOf('generate", {'));
assert.ok(submit.indexOf('confirmedGeneratedTitles(body)') < submit.indexOf('generate/seen'));
assert.ok(submit.indexOf('generate/seen') < submit.indexOf('clearPendingIdempotencyRequest(pendingRequest)'));
assert.ok(submit.includes('claimReady && !retainPendingIdempotencyRequest(e)'));
assert.ok(submit.includes('retainPendingIdempotencyRequest(e)'));
console.log('Paid title response confirmation: valid rows accepted; malformed 2xx retains retry identity');
