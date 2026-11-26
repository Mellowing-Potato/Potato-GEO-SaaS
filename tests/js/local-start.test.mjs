import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/geo-local-start.js', import.meta.url), 'utf8');
const page = readFileSync(new URL('../../resources/views/local-start.blade.php', import.meta.url), 'utf8');
const ticket = 'a'.repeat(64); // Synthetic launch credential.
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
    history: {replaceState(_state, _title, target) { events.push('scrub'); assert.equal(target, '/local-start'); location.hash = ''; }},
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
  await flush();
  return {calls, events, stored, nodes, get redirected() { return redirected; },
    async click() { clicks.get('click')?.(); await flush(); await flush(); },
    async expire() {
      const [id, callback] = timers.entries().next().value;
      timers.delete(id); callback(); await flush();
    },
    async dispatch(name, event = {}) { lifecycle.get(name)?.(event); await flush(); },
  };
}

// 1. Valid launch ticket exchanges directly in one request.
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

// 2. Existing browser cookie enters immediately via session-login without minting a new session.
const resumed = await boot({hash: ''});
assert.equal(resumed.calls.length, 1, 'An existing browser cookie must enter in one request.');
assert.equal(resumed.calls[0].url, '/api/v1/auth/session-login');
assert.deepEqual(JSON.parse(resumed.calls[0].options.body), {});
assert.equal(resumed.redirected, '/');

// 3. Direct bare http://127.0.0.1:8000 visit in a fresh browser (401 on session-login) automatically enters via direct loopback local-start!
const freshBrowser = await boot({hash: '', responses: [response({}, 401), response({token: 'direct-loopback-token'}, 200)]});
assert.equal(freshBrowser.calls.length, 2);
assert.equal(freshBrowser.calls[0].url, '/api/v1/auth/session-login');
assert.equal(freshBrowser.calls[1].url, '/api/v1/auth/local-start');
assert.deepEqual(JSON.parse(freshBrowser.calls[1].options.body), {});
assert.equal(freshBrowser.stored.get('geo_token'), 'direct-loopback-token');
assert.equal(freshBrowser.redirected, '/');
assert.equal(freshBrowser.nodes.localStartHelp.hidden, true);

// 4. Superseded or already-consumed launch ticket (e.g. installer + agent both ran `open`) recovers seamlessly via direct loopback entry!
const superseded = await boot({responses: [response({message: 'superseded'}, 403), response({token: 'recovered-token'}, 200)]});
assert.equal(superseded.calls.length, 2);
assert.equal(superseded.calls[0].url, '/api/v1/auth/local-start');
assert.deepEqual(JSON.parse(superseded.calls[0].options.body), {ticket});
assert.equal(superseded.calls[1].url, '/api/v1/auth/local-start');
assert.deepEqual(JSON.parse(superseded.calls[1].options.body), {});
assert.equal(superseded.stored.get('geo_token'), 'recovered-token');
assert.equal(superseded.redirected, '/');

// 5. Local proxy forwarding 127.0.0.1 stops immediately and displays explicit proxy bypass guidance.
const proxied = await boot({responses: [response({error_code: 'proxy_detected', message: ticket}, 403)]});
assert.equal(proxied.calls.length, 1, 'Proxy-blocked request must not make a second pointless call.');
assert.equal(proxied.redirected, '');
assert.match(proxied.nodes.localStartMessage.textContent, /代理.*127\.0\.0\.1.*绕过本地地址/);
assert(!proxied.nodes.localStartMessage.textContent.includes(ticket));
assert.equal(proxied.nodes.localStartCheck.disabled, false);

// 6. Rate limiting and service unavailability surface distinct messages without retrying.
const throttled = await boot({status: 429});
assert.equal(throttled.calls.length, 1);
assert.match(throttled.nodes.localStartMessage.textContent, /过于频繁/);
const unavailable = await boot({status: 503});
assert.equal(unavailable.calls.length, 1);
assert.match(unavailable.nodes.localStartMessage.textContent, /无法连接.*服务响应异常/);

// 7. Destination is always fixed to '/' and navigation failure cleans up token.
const fixedDestination = await boot({result: {token: 'test-browser-token', redirect: 'https://example.com'}});
assert.equal(fixedDestination.redirected, '/', 'Server payloads must not change the workspace destination.');
const navigationBlocked = await boot({navigationError: true});
assert.equal(navigationBlocked.redirected, '');
assert.equal(navigationBlocked.nodes.localStartHelp.hidden, false);
assert.equal(navigationBlocked.nodes.localStartCheck.disabled, false);
assert.equal(navigationBlocked.stored.has('geo_token'), false);

// 8. Manual retry button recovers when both initial requests were denied.
const deniedThenRetried = await boot({
  hash: '',
  responses: [response({}, 401), response({message: ticket}, 403), response({token: 'retry-token'}, 200)],
});
assert.equal(deniedThenRetried.calls.length, 2);
assert.equal(deniedThenRetried.redirected, '');
assert(!deniedThenRetried.nodes.localStartMessage.textContent.includes(ticket));
await deniedThenRetried.click();
assert.equal(deniedThenRetried.calls.length, 3);
assert.equal(deniedThenRetried.calls[2].url, '/api/v1/auth/session-login');
assert.equal(deniedThenRetried.redirected, '/');
assert.equal(deniedThenRetried.stored.get('geo_token'), 'retry-token');
await deniedThenRetried.click();
assert.equal(deniedThenRetried.calls.length, 3, 'Completed navigation must not accept another click.');

// 9. Unknown network error never retries automatically.
const unknown = await boot({responses: [Promise.reject(new Error('test-network-' + ticket)), response()]});
assert.equal(unknown.calls.length, 1);
assert.match(unknown.nodes.localStartMessage.textContent, /服务响应异常/);
await unknown.click();
assert.equal(unknown.calls[1].url, '/api/v1/auth/session-login');

// 10. Concurrency, timeout, late JSON, pagehide, and bfcache guards remain strict.
const initial = deferred(), retry = deferred();
const concurrent = await boot({responses: [initial.promise, retry.promise]});
assert.equal(concurrent.nodes.localStartCheck.disabled, true);
await concurrent.click();
await concurrent.click();
assert.equal(concurrent.calls.length, 1, 'Clicks during a pending launch must not create concurrent requests.');
await concurrent.expire();
assert.equal(concurrent.calls[0].options.signal.aborted, true);
assert.match(concurrent.nodes.localStartMessage.textContent, /服务响应异常/);
assert.equal(concurrent.calls.length, 1, 'Timeout must not retry.');
await concurrent.click();
await concurrent.click();
assert.equal(concurrent.calls.length, 2, 'A pending retry must also be deduplicated.');
const checkingMessage = concurrent.nodes.localStartMessage.textContent;
initial.resolve(response({token: 'test-late-launch-token'}));
await flush();
assert.equal(concurrent.stored.has('geo_token'), false, 'A late timed-out exchange must not store a token.');
assert.equal(concurrent.redirected, '');
assert.equal(concurrent.nodes.localStartMessage.textContent, checkingMessage);
assert.equal(concurrent.nodes.localStartCheck.disabled, true);
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
assert.equal(departed.redirected, '/');

// 11. Malformed fragments never redirect to external URLs and are scrubbed before any request.
for (const hash of ['#ticket=short', '#redirect=https://example.com', '#ticket=' + 'G'.repeat(64)]) {
  const scrubbed = await boot({hash, responses: [response({token: 'clean-token'}, 200)]});
  assert.equal(scrubbed.redirected, '/');
  assert.equal(scrubbed.calls[0].url, '/api/v1/auth/session-login');
}

assert.match(page, /<meta name="referrer" content="no-referrer">/);
assert.doesNotMatch(page, /<input|<form|(?:src|href)="https?:\/\//);
assert.match(page, /yanji open/);
assert.match(page, /重新进入工作台/);
assert.match(page, /127\.0\.0\.1/);
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

console.log('Local launch: direct loopback entry, superseded-ticket recovery, proxy detection, timeout/concurrency/lifecycle guards and security assertions passed.');
