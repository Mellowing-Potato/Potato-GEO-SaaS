import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import test from 'node:test';
import { readEntrySource } from './helpers/entry-source.mjs';

const source = readEntrySource();
const start = source.indexOf('  let reverifyPromise = null;');
const end = source.indexOf('  // 错误编号统一出口', start);
assert.ok(start > 0 && end > start);

for (const [label, userId, tenantId, changed] of [
  ['same account', 1, 10, false], ['different account', 2, 10, true], ['different workspace', 1, 20, true],
]) {
  test(`session retry: ${label}`, async () => {
    const workspace = { hidden: false };
    const redirects = [];
    const payload = { token: 'new-token', user: { id: userId }, tenant: { id: tenantId } };
    const context = vm.createContext({
      state: { session: { user: { id: 1 }, tenant: { id: 10 } } }, config: {}, AbortSignal,
      fetch: async () => ({ ok: true, json: async () => payload }),
      pickToken: p => p.token, persistSession: () => {}, $: () => workspace,
      window: { location: { href: '/#projects', replace: url => redirects.push(url) } },
      accountLoginUrl: () => '/login',
    });
    vm.runInContext(source.slice(start, end), context);
    const pending = vm.runInContext('reverifyOnce()', context);
    if (changed) {
      await assert.rejects(pending, error => error.identityChanged === true);
      assert.equal(workspace.hidden, true);
      assert.deepEqual(redirects, ['/#projects']);
      assert.equal(context.state.session.user.id, 1);
      assert.equal(context.state.session.tenant.id, 10);
    } else {
      await pending;
      assert.equal(context.state.session, payload);
      assert.deepEqual(redirects, []);
    }
  });
}
