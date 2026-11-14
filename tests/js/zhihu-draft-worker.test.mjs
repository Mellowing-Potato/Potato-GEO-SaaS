import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const source = fs.readFileSync(path.resolve(here, '../../browser-extension/src/service-worker.js'), 'utf8');

function workerHarness({ origin = 'http://localhost:8000', instanceId = 'test-instance', stored = new Map() } = {}) {
  const opened = [];
  const messages = [];
  let handler;
  let editorAvailable = true;
  let sourceAvailable = true;
  const probes = [];
  const chrome = {
    storage: { session: {
      async get(key) {
        if (key === null) return Object.fromEntries(stored);
        return { [key]: stored.get(key) };
      },
      async set(items) { for (const [key, value] of Object.entries(items)) stored.set(key, value); },
      async remove(keys) { for (const key of Array.isArray(keys) ? keys : [keys]) stored.delete(key); },
    } },
    tabs: {
      async create({ url }) { opened.push(url); return { id: 42 }; },
      async sendMessage(id, message, options) {
        if (message.type === 'YANJI_SOURCE_PROBE') {
          probes.push({ id, message, options });
          return { ok: sourceAvailable, origin };
        }
        messages.push({ id, message });
        if (id === 42 && !editorAvailable) throw new Error('no receiver');
        return id === 42
          ? { ok: true, code: 'draft_saved', saved: true,
            draftUrl: 'https://zhuanlan.zhihu.com/p/123/edit' }
          : undefined;
      },
      onRemoved: { addListener() {} },
    },
    runtime: { onMessage: { addListener(callback) { handler = callback; } } },
  };
  const context = vm.createContext({ chrome, URL, Set, Object });
  context.YANJI_INSTANCE = { origin, id: instanceId };
  vm.runInContext(fs.readFileSync(path.resolve(here, '../../browser-extension/src/instance-runtime.js'), 'utf8'), context);
  vm.runInContext(source, context);
  return {
    opened, messages, stored, probes,
    navigateSource() { sourceAvailable = false; },
    deferEditor() { editorAvailable = false; },
    deliver() { return vm.runInContext('deliver(42)', context); },
    send(message, sender) {
      return new Promise((resolve) => handler(message, sender, resolve));
    },
  };
}

const payload = {
  title: '科研文章', body: '正文', profileUrl: 'https://www.zhihu.com/people/test-author',
  draftUrl: null, replaceExisting: false,
};

test('从言及发布单打开编辑器并将保存结果回传原标签页', async () => {
  const harness = workerHarness();
  const response = await harness.send({ type: 'YANJI_START_DRAFT', requestId: 'request-id-1234567890', payload },
    { tab: { id: 7 }, documentId: 'test-source-document', url: 'http://localhost:8000/manual-publish#sec-detail' });
  assert.equal(response.ok, true);
  assert.deepEqual(harness.opened, ['https://zhuanlan.zhihu.com/write']);
  assert.equal(harness.messages.length, 2);
  assert.equal(harness.messages[0].id, 42);
  assert.equal(harness.messages[0].message.type, 'YANJI_FILL_DRAFT');
  assert.equal(harness.messages[1].id, 7);
  assert.equal(harness.messages[1].message.result.saved, true);
  assert.equal(harness.stored.size, 0);
});

test('超时后的延迟编辑器不再收到正文写入请求', async () => {
  const harness = workerHarness();
  harness.deferEditor();
  await harness.send({ type: 'YANJI_START_DRAFT', requestId: 'request-id-1234567890', payload },
    { tab: { id: 7 }, documentId: 'test-source-document', url: 'http://localhost:8000/manual-publish' });
  const job = harness.stored.get('zhihu-draft-job:test-instance:42');
  assert.ok(job);
  job.expiresAt = Date.now() - 1;
  const writesBefore = harness.messages.filter(item => item.id === 42).length;
  await harness.deliver();
  assert.equal(harness.messages.filter(item => item.id === 42).length, writesBefore);
  assert.equal(harness.messages.at(-1).message.result.code, 'request_expired');
  assert.equal(harness.stored.size, 0);
});

test('拒绝非言及页面和不合法账号链接', async () => {
  const harness = workerHarness();
  const wrongSource = await harness.send({ type: 'YANJI_START_DRAFT', requestId: 'request-id-1234567890', payload },
    { tab: { id: 7 }, documentId: 'test-source-document', url: 'https://example.test/manual-publish' });
  assert.equal(wrongSource.code, 'wrong_source');
  const wrongProfile = await harness.send({ type: 'YANJI_START_DRAFT', requestId: 'request-id-1234567890',
    payload: { ...payload, profileUrl: 'https://example.test/people/test-author' } },
  { tab: { id: 7 }, documentId: 'test-source-document', url: 'http://localhost:8000/manual-publish' });
  assert.equal(wrongProfile.code, 'invalid_payload');
  assert.equal(harness.opened.length, 0);
});

test('实例来源包含端口，缓存和回传绑定原文档', async () => {
  const origin = 'http://yanji.localhost:18765';
  const harness = workerHarness({ origin, instanceId: 'instance-a' });
  harness.deferEditor();
  const message = { type: 'YANJI_START_DRAFT', requestId: 'request-id-1234567890', payload };
  const sender = { tab: { id: 7 }, documentId: 'test-source-document', url: origin + '/manual-publish' };
  for (const url of ['http://yanji.localhost:18766/manual-publish', 'https://yanji.localhost:18765/manual-publish',
    'http://other.localhost:18765/manual-publish', origin + '/elsewhere']) {
    assert.equal((await harness.send(message, { ...sender, url })).code, 'wrong_source');
  }
  assert.equal((await harness.send(message, { ...sender, documentId: undefined })).code, 'wrong_source');
  assert.equal((await harness.send(message, sender)).ok, true);
  const job = harness.stored.get('zhihu-draft-job:instance-a:42');
  assert.equal(job.sourceOrigin, origin);
  assert.equal(job.sourceDocumentId, sender.documentId);
  assert.ok(harness.probes.every(probe => probe.options.documentId === sender.documentId));
  const writes = harness.messages.filter(item => item.id === 42).length;
  harness.navigateSource();
  await harness.deliver();
  assert.equal(harness.messages.filter(item => item.id === 42).length, writes);
  assert.equal(harness.stored.size, 0);
  assert.ok(!harness.messages.some(item => item.id === 7));
});

test('另一实例和旧版缓存不能被当前实例读取或投递', async () => {
  const shared = new Map();
  const a = workerHarness({ origin: 'http://a.localhost:18765', instanceId: 'instance-a', stored: shared });
  a.deferEditor();
  await a.send({ type: 'YANJI_START_DRAFT', requestId: 'request-id-1234567890', payload },
    { tab: { id: 7 }, documentId: 'test-source-document', url: 'http://a.localhost:18765/manual-publish' });
  shared.set('zhihu-draft-job:42', { sourceTabId: 7, payload, expiresAt: Date.now() + 120000 });
  const b = workerHarness({ origin: 'http://b.localhost:18765', instanceId: 'instance-b', stored: shared });
  await b.deliver();
  assert.equal(b.messages.length, 0);
  assert.equal(b.probes.length, 0);
  assert.equal(shared.size, 2);
});
