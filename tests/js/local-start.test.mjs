import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/geo-local-start.js', import.meta.url), 'utf8');
const page = readFileSync(new URL('../../resources/views/local-start.blade.php', import.meta.url), 'utf8');
const ticket = 'a'.repeat(64); // Synthetic one-time launch credential.
const flush = () => new Promise(resolve => setImmediate(resolve));
const deferred = () => {
  let resolve, reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return {promise, resolve, reject};
};
const response = (result = {token: 'test-browser-token'}, status = 200) => ({
  ok: status >= 200 && status < 300, status, json: async () => result,
});

async function boot({hash = '#ticket=' + ticket, ok = true, status, result = {token: 'test-browser-token'}, error, responses = [], navigationError = false, script = source} = {}) {
  const calls = [], events = [], stored = new Map([['geo_token', 'test-stale-token']]);
  const clicks = new Map(), lifecycle = new Map(), timers = new Map();
  let nextTimer = 0;
  const nodes = {localStartMessage: {textContent: ''}, localStartHelp: {hidden: true},
    localStartCheck: {disabled: true, addEventListener: (name, handler) => clicks.set(name, handler)}};
  let redirected = '';
  const location = {hash, pathname: '/local-start', replace: target => {
    if (navigationError) throw new Error('test-navigation-blocked');
    redirected = target;
  }};
  vm.runInNewContext(script, {
    document: {getElementById: id => nodes[id]}, location,
    window: {addEventListener: (name, handler) => lifecycle.set(name, handler)},
    history: {replaceState(_state, _title, target) {events.push('scrub'); assert.equal(target, '/local-start'); location.hash = ''; }},
    sessionStorage: {setItem: (key, value) => stored.set(key, value), removeItem: key => stored.delete(key)},
    AbortController, AbortSignal,
    setTimeout: callback => { const id = ++nextTimer; timers.set(id, callback); return id; },
    clearTimeout: id => timers.delete(id),
    fetch: async (url, options) => {
      events.push('request'); calls.push({url, options});
      assert.equal(location.hash, '', 'Launch credential must be removed before a request.');
      if (responses.length >= calls.length) return await responses[calls.length - 1];
      if (error) throw error;
      return response(result, status ?? (ok ? 200 : 403));
    },
  });
  await flush();
  return {calls, events, stored, nodes, get redirected() { return redirected; },
    async click() { clicks.get('click')?.(); await flush(); },
    async expire() {
      const [id, callback] = timers.entries().next().value;
      timers.delete(id); callback(); await flush();
    },
    async dispatch(name, event = {}) { lifecycle.get(name)?.(event); await flush(); },
  };
}

const launched = await boot();
assert.deepEqual(launched.events, ['scrub', 'request']);
assert.equal(launched.calls.length, 1);
assert.equal(launched.calls[0].url, '/api/v1/auth/local-start');
assert.deepEqual(JSON.parse(launched.calls[0].options.body), {ticket});
assert.equal(launched.calls[0].options.method, 'POST');
assert.equal(launched.calls[0].options.credentials, 'same-origin');
assert.equal(launched.calls[0].options.cache, 'no-store');
assert.equal(launched.calls[0].options.referrerPolicy, 'no-referrer');
assert.equal(launched.calls[0].options.headers.Authorization, undefined);
assert.equal(launched.stored.get('geo_token'), 'test-browser-token');
assert.equal(launched.redirected, '/');
assert.equal(launched.nodes.localStartHelp.hidden, true);

const resumed = await boot({hash: ''});
assert.equal(resumed.calls[0].url, '/api/v1/auth/session-login');
assert.deepEqual(JSON.parse(resumed.calls[0].options.body), {});
assert.equal(resumed.redirected, '/');
assert.equal(resumed.calls.length, 1, 'An existing browser cookie must still enter automatically.');
const unauthorized = await boot({hash: '', status: 401});
assert.match(unauthorized.nodes.localStartMessage.textContent, /当前浏览器.*没有.*授权/);
assert.equal(unauthorized.nodes.localStartCheck.disabled, false);
const consumed = await boot({status: 403});
assert.match(consumed.nodes.localStartMessage.textContent, /过期.*已使用/);
assert.notEqual(consumed.nodes.localStartMessage.textContent, unauthorized.nodes.localStartMessage.textContent);
const unavailable = await boot({status: 503});
assert.match(unavailable.nodes.localStartMessage.textContent, /无法连接.*服务响应异常/);
assert.notEqual(unavailable.nodes.localStartMessage.textContent, consumed.nodes.localStartMessage.textContent);
const fixedDestination = await boot({result: {token: 'test-browser-token', redirect: 'https://example.com'}});
assert.equal(fixedDestination.redirected, '/', 'Server payloads must not change the workspace destination.');
const navigationBlocked = await boot({navigationError: true});
assert.equal(navigationBlocked.redirected, '');
assert.equal(navigationBlocked.nodes.localStartHelp.hidden, false);
assert.equal(navigationBlocked.nodes.localStartCheck.disabled, false);
assert.match(navigationBlocked.nodes.localStartMessage.textContent, /当前页面.*桌面或应用菜单.*本机入口/);
assert.equal(navigationBlocked.stored.has('geo_token'), false);

const rechecked = await boot({responses: [response({message: ticket}, 403), response()]});
assert.equal(rechecked.calls.length, 1, 'A failed launch must not automatically fall back or redeem again.');
await rechecked.click();
assert.equal(rechecked.calls.length, 2);
assert.equal(rechecked.calls[1].url, '/api/v1/auth/session-login');
assert.deepEqual(JSON.parse(rechecked.calls[1].options.body), {});
assert.equal(rechecked.calls[1].options.credentials, 'same-origin');
assert.equal(rechecked.calls[1].options.headers.Authorization, undefined);
assert.equal(rechecked.redirected, '/');
assert.equal(rechecked.stored.get('geo_token'), 'test-browser-token');
await rechecked.click();
assert.equal(rechecked.calls.length, 2, 'Completed navigation must not accept another click.');

const unknown = await boot({responses: [Promise.reject(new Error('test-network-' + ticket)), response()]});
assert.equal(unknown.calls.length, 1);
assert.match(unknown.nodes.localStartMessage.textContent, /服务响应异常/);
await unknown.click();
assert.equal(unknown.calls[1].url, '/api/v1/auth/session-login', 'Unknown exchange results can only be followed by an explicit cookie recheck.');
assert.deepEqual(JSON.parse(unknown.calls[1].options.body), {});

const initial = deferred(), retry = deferred();
const concurrent = await boot({responses: [initial.promise, retry.promise]});
assert.equal(concurrent.nodes.localStartCheck.disabled, true);
await concurrent.click();
await concurrent.click();
assert.equal(concurrent.calls.length, 1, 'Clicks during a pending launch must not create concurrent requests.');
await concurrent.expire();
assert.equal(concurrent.calls[0].options.signal.aborted, true);
assert.match(concurrent.nodes.localStartMessage.textContent, /服务响应异常/);
assert.equal(concurrent.calls.length, 1, 'Timeout must not retry a one-time exchange.');
await concurrent.click();
await concurrent.click();
assert.equal(concurrent.calls.length, 2, 'A pending cookie recheck must also be deduplicated.');
assert.equal(concurrent.calls[1].url, '/api/v1/auth/session-login');
const checkingMessage = concurrent.nodes.localStartMessage.textContent;
initial.resolve(response({token: 'test-late-launch-token'}));
await flush();
assert.equal(concurrent.stored.has('geo_token'), false, 'A late timed-out exchange must not store a token.');
assert.equal(concurrent.redirected, '');
assert.equal(concurrent.nodes.localStartMessage.textContent, checkingMessage);
assert.equal(concurrent.nodes.localStartCheck.disabled, true, 'A late response must not unlock the active request.');
retry.resolve(response());
await flush();
assert.equal(concurrent.stored.get('geo_token'), 'test-browser-token');
assert.equal(concurrent.redirected, '/');

const slowJson = deferred();
const parsing = await boot({responses: [{ok: true, status: 200, json: () => slowJson.promise}, response()]});
await parsing.expire();
await parsing.click();
slowJson.resolve({token: 'test-late-json-token'});
await flush();
assert.equal(parsing.stored.get('geo_token'), 'test-browser-token', 'A late parsed response must not overwrite a newer browser session.');
assert.equal(parsing.calls.length, 2);

const leaving = deferred();
const departed = await boot({responses: [leaving.promise, response()]});
await departed.dispatch('pagehide');
leaving.resolve(response({token: 'test-departed-token'}));
await flush();
assert.equal(departed.stored.has('geo_token'), false, 'Responses after leaving the page must be ignored.');
assert.equal(departed.redirected, '');
await departed.click();
assert.equal(departed.calls.length, 1);
await departed.dispatch('pageshow', {persisted: true});
assert.equal(departed.nodes.localStartCheck.disabled, false);
assert.equal(departed.calls.length, 1, 'Restoring a cached page must not redeem again automatically.');
await departed.click();
assert.equal(departed.calls[1].url, '/api/v1/auth/session-login');
assert.equal(departed.redirected, '/');

for (const hash of ['#ticket=short', '#ticket=' + ticket + '&ticket=' + ticket, '#redirect=https://example.com', '#ticket=' + 'G'.repeat(64)]) {
  const invalid = await boot({hash});
  assert.equal(invalid.calls.length, 0, 'Malformed launch fragments must not trigger authentication.');
  assert.equal(invalid.redirected, '');
  assert.equal(invalid.stored.has('geo_token'), false);
  assert.equal(invalid.nodes.localStartHelp.hidden, false);
}

for (const options of [
  {ok: false, result: {message: ticket}},
  {result: {}},
  {result: {token: 1}},
  {error: new Error('test-network-failure-' + ticket)},
  {hash: '', ok: false},
]) {
  const failed = await boot(options);
  await flush();
  assert.equal(failed.calls.length, 1, 'Failed or unknown one-time exchanges must never auto-retry.');
  assert.equal(failed.redirected, '');
  assert.equal(failed.stored.has('geo_token'), false);
  assert.equal(failed.nodes.localStartHelp.hidden, false);
  assert(!failed.nodes.localStartMessage.textContent.includes(ticket), 'Failure messages must not expose launch credentials.');
}

assert.match(page, /<meta name="referrer" content="no-referrer">/);
assert.doesNotMatch(page, /<input|<form|(?:src|href)="https?:\/\//);
assert.match(page, /yanji open/);
assert.match(page, /<p class="intro">从桌面或应用菜单的“言及工作台”本机入口打开/);
assert.match(page, /请从桌面或应用菜单的“言及工作台”本机入口打开，在系统浏览器中使用/);
assert.match(page, /没有快捷方式时，可按安装完成时显示的位置找到本机入口并双击/);
assert.match(page, /<noscript>[\s\S]*?桌面或应用菜单[\s\S]*?没有快捷方式[\s\S]*?<\/noscript>/);
assert.match(page, /重新检查当前浏览器/);
assert.match(page, /不同浏览器或应用窗口.*分开保存/);
assert.doesNotMatch(page.replace(/<details\b[\s\S]*?<\/details>/g, ''), /yanji open|管理命令|ticket/);
assert.match(page, /<details\b[\s\S]*?yanji open[\s\S]*?<\/details>/);
assert.doesNotMatch(source, /window\.open|navigator|userAgent|document\.cookie|console\./);
assert.doesNotMatch(page + source, /回到本机桌面|[A-Za-z]:[\\/]|\/Users\/|\/home\//);

const entry = readFileSync(new URL('../../public/geo-entry.js', import.meta.url), 'utf8');
const start = entry.indexOf('  async function loadPersonalAccount() {');
assert(start >= 0);
const account = entry.slice(start, entry.indexOf('\n  }', start) + 4);
let profileCalls = 0;
const desktopAccount = new Function('config', 'api', account + '; return loadPersonalAccount;')(
  {desktopMode: true}, async () => { profileCalls++; throw new Error('Desktop mode must not load account forms.'); },
);
await desktopAccount();
assert.equal(profileCalls, 0, 'Desktop settings must not load generated email or password forms.');

console.log('Local launch: protected cookie recovery, desktop guidance, distinct failures, no redemption retries, timeout/concurrency/lifecycle guards and existing security assertions passed.');
