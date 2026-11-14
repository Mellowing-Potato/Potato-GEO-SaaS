import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { test } from 'node:test';
import { readEntrySource } from './helpers/entry-source.mjs';

const source = readEntrySource();
const start = source.indexOf('  async function api(');
const apiSource = source.slice(start, source.indexOf('\n  }', start) + 4);

test('knowledge requests require and preserve the selected project', async () => {
  const calls = [];
  const c = {
    state: { selectedProject: null }, recentProjectStorageKey: () => 'account-1',
    apiWithSessionRetry: async (path, options) => { calls.push({ path, options }); return {}; },
  };
  vm.createContext(c); vm.runInContext(apiSource, c);
  await assert.rejects(c.api('/api/v1/knowledge-bases'), /请先创建或选择项目/);
  assert.equal(calls.length, 0);
  c.state.selectedProject = { id: 9 };
  for (const path of ['/api/v1/knowledge-bases', '/api/v1/enterprise-knowledge', '/api/v1/knowledge-legacy/assign', '/api/v1/generation-preferences']) {
    await c.api(path, { headers: { 'X-Project-Id': '99', 'Content-Type': 'application/json' } });
    assert.equal(calls.at(-1).options.headers['X-Project-Id'], '9');
    assert.equal(calls.at(-1).options.headers['Content-Type'], 'application/json');
  }
});

for (const failed of [false, true]) {
  test(`late knowledge ${failed ? 'failure' : 'response'} is discarded after changing project`, async () => {
    let finish;
    const c = {
      state: { selectedProject: { id: 9 } }, recentProjectStorageKey: () => 'account-1',
      apiWithSessionRetry: () => new Promise((resolve, reject) => { finish = failed ? reject : resolve; }),
    };
    vm.createContext(c); vm.runInContext(apiSource, c);
    const pending = c.api('/api/v1/knowledge-bases');
    c.state.selectedProject = { id: 10 };
    finish(failed ? new Error('old project failed') : { data: [{ name: 'old project document' }] });
    await assert.rejects(pending, error => error.knowledgeContextChanged === true);
  });
}
