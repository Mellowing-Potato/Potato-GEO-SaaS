import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { webcrypto } from 'node:crypto';

const read = name => fs.readFileSync(new URL('../../browser-extension/src/' + name, import.meta.url), 'utf8');
function bridge({ origin = 'http://yanji.localhost:18765', configured = true } = {}) {
  const browserOrigin = 'http://yanji.localhost:18765';
  const location = { origin: browserOrigin, href: browserOrigin + '/manual-publish', pathname: '/manual-publish' };
  const requests = [], posts = [], windowHandlers = [], runtimeHandlers = [];
  const window = { addEventListener(type, listener) { assert.equal(type, 'message'); windowHandlers.push(listener); },
    postMessage(message, target) { posts.push({ message, target }); } };
  const chrome = { runtime: { id: 'test-extension', getManifest: () => ({ version: '0.4.0' }),
    onMessage: { addListener(listener) { runtimeHandlers.push(listener); } },
    async sendMessage(message) { requests.push(message); return { ok: true }; } } };
  const context = vm.createContext({ window, location, chrome, URL, Map, crypto: webcrypto, setTimeout, clearTimeout,
    YANJI_INSTANCE: { origin: configured ? origin : null, id: configured ? 'instance-a' : null } });
  vm.runInContext(read('instance-runtime.js'), context);
  vm.runInContext(read('yanji-bridge.js'), context);
  vm.runInContext(read('weibo-bridge.js'), context);
  return { location, requests, posts, windowHandlers, runtimeHandlers,
    page(message, eventOrigin = browserOrigin) {
      windowHandlers.forEach(handler => handler({ source: window, origin: eventOrigin, data: message }));
    },
    runtime(message, senderId = 'test-extension') {
      const replies = [];
      runtimeHandlers.forEach(handler => handler(message, { id: senderId }, reply => replies.push(reply)));
      return replies;
    },
  };
}

const stamp = { instanceId: 'instance-a', sourceOrigin: 'http://yanji.localhost:18765' };
test('unconfigured source and other origins do not register a page bridge', () => {
  for (const options of [{ configured: false }, { origin: 'http://yanji.localhost:18766' }, { origin: 'https://yanji.localhost:18765' }]) {
    const h = bridge(options);
    assert.equal(h.windowHandlers.length, 0);
    assert.equal(h.runtimeHandlers.length, 0);
  }
});

test('probe and result messages require the same extension, instance and origin', () => {
  const h = bridge();
  const probe = { ...stamp, type: 'YANJI_SOURCE_PROBE' };
  assert.equal(h.runtime(probe)[0].ok, true);
  assert.equal(h.runtime(probe, 'another-extension').length, 0);
  assert.equal(h.runtime({ ...probe, instanceId: 'instance-b' }).length, 0);
  assert.equal(h.runtime({ ...probe, sourceOrigin: 'http://other.localhost:18765' }).length, 0);
  h.runtime({ ...stamp, type: 'YANJI_DRAFT_RESULT', requestId: 'test-request', result: { ok: true } });
  assert.equal(h.posts.length, 1);
  assert.equal(h.posts[0].target, stamp.sourceOrigin);
  h.location.href = 'http://other.localhost:18765/manual-publish';
  assert.equal(h.runtime(probe)[0].ok, false);
});

test('page requests stop after same-document navigation outside the publishing page', () => {
  const h = bridge();
  const message = { channel: 'yanji-zhihu-draft', type: 'REQUEST', requestId: 'request-id-1234567890',
    payload: { title: '测试草稿', body: '仅测试桥接，不调用模型。', profileUrl: 'https://www.zhihu.com/people/test-author' } };
  h.page(message, 'http://other.localhost:18765');
  assert.equal(h.requests.length, 0);
  h.page(message);
  assert.equal(h.requests.length, 1);
  h.location.href = stamp.sourceOrigin + '/elsewhere';
  h.page(message);
  assert.equal(h.requests.length, 1);
});
