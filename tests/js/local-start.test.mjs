import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/geo-local-start.js', import.meta.url), 'utf8');
const page = readFileSync(new URL('../../resources/views/local-start.blade.php', import.meta.url), 'utf8');
const ticket = 'a'.repeat(64); // Synthetic one-time launch credential.
const flush = () => new Promise(resolve => setImmediate(resolve));

async function boot({hash = '#ticket=' + ticket, ok = true, result = {token: 'test-browser-token'}, error, script = source} = {}) {
  const calls = [], events = [], stored = new Map([['geo_token', 'test-stale-token']]);
  const nodes = {localStartMessage: {textContent: ''}, localStartHelp: {hidden: true}};
  let redirected = '';
  const location = {hash, pathname: '/local-start', replace: target => {redirected = target;}};
  vm.runInNewContext(script, {
    document: {getElementById: id => nodes[id]}, location,
    history: {replaceState(_state, _title, target) {events.push('scrub'); assert.equal(target, '/local-start'); location.hash = ''; }},
    sessionStorage: {setItem: (key, value) => stored.set(key, value), removeItem: key => stored.delete(key)},
    AbortSignal,
    fetch: async (url, options) => {
      events.push('request'); calls.push({url, options});
      assert.equal(location.hash, '', 'Launch credential must be removed before a request.');
      if (error) throw error;
      return {ok, json: async () => result};
    },
  });
  await flush();
  return {calls, events, stored, nodes, redirected};
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
assert.equal(launched.stored.get('geo_token'), 'test-browser-token');
assert.equal(launched.redirected, '/');
assert.equal(launched.nodes.localStartHelp.hidden, true);

const resumed = await boot({hash: ''});
assert.equal(resumed.calls[0].url, '/api/v1/auth/session-login');
assert.deepEqual(JSON.parse(resumed.calls[0].options.body), {});
assert.equal(resumed.redirected, '/');

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
assert.match(page, /id="localStartCopy"/);
assert.match(page, /class="launch-steps"/);

{
  let copied = '';
  const listeners = new Map();
  const classes = new Set();
  const copyStatus = {textContent: ''};
  const nodes = {
    localStartMessage: {textContent: ''},
    localStartHelp: {hidden: true},
    localStartCard: {classList: {add: name => classes.add(name)}},
    localStartCopy: {addEventListener: (name, fn) => listeners.set(name, fn)},
    localStartCopyStatus: copyStatus,
  };
  let fetchCount = 0;
  vm.runInNewContext(source, {
    document: {getElementById: id => nodes[id]},
    location: {hash: '#ticket=short', pathname: '/local-start', replace() {}},
    history: {replaceState() {}},
    sessionStorage: {setItem() {}, removeItem() {}},
    navigator: {clipboard: {writeText: async text => { copied = text; }}},
    AbortSignal,
    fetch: async () => { fetchCount++; return {ok: false, json: async () => ({})}; },
  });
  await flush();
  assert.equal(classes.has('is-error'), true, 'Failed launch must mark status card as error.');
  assert.equal(nodes.localStartHelp.hidden, false);
  await listeners.get('click')?.();
  assert.equal(copied, 'yanji open', 'Recovery copy button must copy the local launch command.');
  assert.match(copyStatus.textContent, /yanji open/);
  assert.equal(fetchCount, 0, 'Copy button must never trigger authentication requests.');
}

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

console.log('Local launch: scrubbed one-time credentials, protected session continuation, no retries, fixed destination and desktop account UI passed.');
