import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createContext, runInContext } from 'node:vm';
import { test } from 'node:test';

const source = readFileSync(new URL('../../public/geo-entry-enterprise-knowledge.js', import.meta.url), 'utf8');
const coordinator = readFileSync(new URL('../../public/geo-enterprise-draft.js', import.meta.url), 'utf8');
const entry = readFileSync(new URL('../../public/geo-entry.js', import.meta.url), 'utf8');
const deferred = () => { let resolve, reject; const promise = new Promise((y, n) => { resolve = y; reject = n; }); return { promise, resolve, reject }; };
const pause = () => new Promise(resolve => setImmediate(resolve));
async function until(check) { for (let n = 0; n < 80 && !check(); n++) await pause(); assert.ok(check(), 'expected behavior did not occur'); }
const conflict = (version, content) => Object.assign(new Error('version conflict'), { status: 409, body: { action: 'enterprise_draft_conflict', current: { draft_version: version, draft_content: content, validation_items: [] } } });

function harness() {
  const node = () => ({ value: '', textContent: '', innerHTML: '', hidden: false, disabled: false, dataset: {}, handlers: {},
    addEventListener(event, fn) { this.handlers[event] = fn; }, querySelectorAll() { return []; }, querySelector() { return null; } });
  const state = { selectedEkId: null, selectedProject: { id: 10 }, session: { tenant: { id: 1, subdomain: 'fixture' }, user: { id: 2 } }, knowledgeModelStatus: { embedding_configured: true } };
  const elements = Object.fromEntries(['ekDraftEditor', 'ekAutosaveStatus', 'ekDetail', 'ekDetailMessage', 'ekHost', 'ekDetailTitle', 'ekDetailStatus', 'ekValidationPanel', 'ekValidateButton', 'ekPublishButton', 'ekGenerateButton', 'ekBackButton'].map(name => [name, node()]));
  const extra = Object.fromEntries(['ekDraftRecovery', 'ekDraftRecoveryMessage', 'ekServerDraft', 'ekRetrySaveButton', 'ekKeepLocalDraftButton', 'ekUseServerDraftButton', 'ekTargetKnowledgeBase'].map(name => [name, node()]));
  extra.ekTargetKnowledgeBase.value = '31';
  const timers = new Map(), events = {}, calls = []; let nextTimer = 0;
  const setTimeout = fn => { timers.set(++nextTimer, fn); return nextTimer; };
  const clearTimeout = id => timers.delete(id);
  const projects = new Map([101, 202].map(id => [id, { id, project_id: 10, name: `Draft ${id}`, draft_version: 0, draft_content: `server ${id}`, sources: [] }]));
  const h = { state, elements, extra, calls, events, projects, timers, reply: null, confirm: async () => true };
  const api = async (path, options = {}) => {
    const request = { path, options, body: options.body ? JSON.parse(options.body) : null, tenant: state.session?.tenant?.id, project: state.selectedProject?.id };
    calls.push(request);
    if (h.reply) { const answer = h.reply(request); if (answer !== undefined) return answer; }
    if (path === '/api/v1/knowledge-bases') return { data: [] };
    const id = Number(path.split('/')[4]);
    if (!options.method) return { data: { ...projects.get(id) }, validation_items: [] };
    return { draft_version: request.body?.base_version + 1, saved_at: 'now', validation_items: [] };
  };
  const GEO = { state, elements, api, escapeHtml: String, formatNumber: String, formatKnowledgeDate: String, setMessage: (el, msg) => { if (el) el.textContent = msg; },
    validationMessage: e => e.message, withBusyButton: async (_button, _label, fn) => fn(), confirmWorkspaceAction: (...args) => h.confirm(...args), clToast() {}, fillErrorNo() {}, loadKnowledgeBases: async () => {},
    KB_DOC_STATUS_LABELS: {}, KB_DOC_STATUS_VARIANTS: {} };
  const context = createContext({ console, setTimeout, clearTimeout, FormData, window: { GEO, location: { hash: '#knowledge' }, addEventListener: (name, fn) => events[name] = fn, setInterval: () => 1, clearInterval() {} },
    document: { getElementById: name => extra[name] || null, querySelectorAll: () => [] },
  });
  const start = entry.indexOf('  function clDebounce('), end = entry.indexOf('\n  function ', start + 1);
  runInContext(entry.slice(start, end) + '\nwindow.GEO.clDebounce = clDebounce;', context);
  runInContext(coordinator, context);
  const endModule = source.lastIndexOf('})();');
  runInContext(source.slice(0, endModule) + 'Object.assign(window.GEO, { testOpen: openEnterpriseDetail, testRestore: ekRestore, testValidate: ekValidate, testGenerate: ekGenerate });\n' + source.slice(endModule), context);
  h.open = id => GEO.testOpen(id); h.close = () => GEO.ekCloseDetail(); h.restore = id => GEO.testRestore(id); h.validate = () => GEO.testValidate(); h.generate = () => GEO.testGenerate();
  h.input = text => { elements.ekDraftEditor.value = text; elements.ekDraftEditor.handlers.input(); };
  h.tick = async () => { const batch = [...timers.values()]; timers.clear(); for (const fn of batch) fn(); await pause(); };
  h.posts = () => calls.filter(c => c.options.method === 'POST');
  h.click = name => extra[name].handlers.click(); h.discard = () => GEO.discardEnterpriseDrafts();
  h.leave = () => { context.window.location.hash = '#dashboard'; events.hashchange?.(); };
  return h;
}

test('versioned coordinator is loaded before enterprise handlers and successful logout discards drafts', () => {
  const page = readFileSync(new URL('../../resources/views/welcome.blade.php', import.meta.url), 'utf8');
  assert.ok(page.indexOf('/geo-enterprise-draft.js?v=') > 0);
  assert.ok(page.indexOf('/geo-enterprise-draft.js?v=') < page.indexOf('/geo-entry-enterprise-knowledge.js?v='));
  assert.match(page, /PublicAssetVersion::for\('geo-enterprise-draft.js'\)/);
  const logout = entry.slice(entry.indexOf('document.getElementById("accountLogoutButton")'), entry.indexOf('  function persistSession'));
  assert.ok(logout.indexOf('discardEnterpriseDrafts') > logout.indexOf('await api('));
  assert.ok(logout.indexOf('discardEnterpriseDrafts') < logout.indexOf('window.location.replace'));
});

test('A timer cannot save A content into B while B detail is delayed', async () => {
  const h = harness(); await h.open(101); h.input('local A'); h.close();
  const pending = deferred(); h.reply = c => c.path === '/api/v1/enterprise-knowledge/202' ? pending.promise : undefined;
  const opened = h.open(202); await h.tick();
  assert.equal(h.posts().length, 0);
  assert.equal(h.elements.ekDraftEditor.disabled, true);
  assert.equal(h.elements.ekDraftEditor.value, '');
  pending.resolve({ data: h.projects.get(202) }); await opened;
  assert.equal(h.elements.ekDraftEditor.value, 'server 202');
});

test('same-id reopen uses a new epoch for both GET success and error', async () => {
  for (const fails of [false, true]) {
    const h = harness(), old = deferred(); let count = 0;
    h.reply = c => c.path === '/api/v1/enterprise-knowledge/101' && ++count === 1 ? old.promise : undefined;
    const opening = h.open(101); await h.open(101); h.input('current editor');
    if (fails) old.reject(new Error('old load error')); else old.resolve({ data: { ...h.projects.get(101), draft_content: 'stale GET' } });
    await opening;
    assert.equal(h.elements.ekDraftEditor.value, 'current editor');
    assert.notEqual(h.elements.ekDetailMessage.textContent, 'old load error');
  }
});

test('one in-flight autosave serializes newer input and advances base version', async () => {
  const h = harness(); await h.open(101); const one = deferred(), two = deferred();
  h.reply = c => c.options.method === 'POST' ? (h.posts().length === 1 ? one.promise : two.promise) : undefined;
  h.input('first edit'); await h.tick(); h.input('latest edit'); await h.tick();
  assert.equal(h.posts().length, 1);
  assert.equal(h.posts()[0].body?.base_version, 0);
  one.resolve({ draft_version: 1, saved_at: 'first' }); await until(() => h.posts().length === 2);
  assert.deepEqual(h.posts()[1].body, { content: 'latest edit', base_version: 1 });
  assert.notEqual(h.elements.ekAutosaveStatus.textContent, '已保存 first');
  two.resolve({ draft_version: 2, saved_at: 'latest' }); await pause();
  assert.equal(h.elements.ekAutosaveStatus.textContent, '已保存 latest');
});

test('late autosave success or failure cannot change the next editor status', async () => {
  for (const fail of [false, true]) {
    const h = harness(); await h.open(101); const pending = deferred();
    h.reply = c => c.options.method === 'POST' ? pending.promise : undefined;
    h.input('old draft'); await h.tick(); await h.open(202); h.input('new draft');
    if (fail) pending.reject(new Error('late save error')); else pending.resolve({ draft_version: 1, saved_at: 'old save' });
    await pause(); assert.equal(h.elements.ekAutosaveStatus.textContent, '编辑中…'); assert.equal(h.elements.ekDraftEditor.value, 'new draft');
  }
});

for (const change of ['tenant', 'user', 'project']) test(`pending timer never sends under changed ${change}`, async () => {
  const h = harness(); await h.open(101); h.input('private edit');
  if (change === 'project') h.state.selectedProject = { id: 99 };
  else h.state.session[change] = { ...h.state.session[change], id: 99 };
  await h.tick(); assert.equal(h.posts().length, 0);
});

test('leaving editor cancels the timer and reopening restores this-page unsaved text', async () => {
  const h = harness(); await h.open(101); h.input('kept local'); h.close(); await h.tick();
  assert.equal(h.posts().length, 0); await h.open(202); await h.open(101);
  assert.equal(h.elements.ekDraftEditor.value, 'kept local'); await h.tick();
  assert.equal(h.posts()[0].path, '/api/v1/enterprise-knowledge/101/autosave');
  assert.equal(h.posts()[0].body.content, 'kept local');
});

test('leaving the knowledge route cancels the pending autosave', async () => {
  const h = harness(); await h.open(101); h.input('route draft'); h.leave(); await h.tick(); assert.equal(h.posts().length, 0);
});

test('owner changes and successful logout purge local drafts and unload warnings', async () => {
  const h = harness(); await h.open(101); h.input('private edit'); h.close();
  h.state.session = null;
  let prevented = false; h.events.beforeunload({ preventDefault() { prevented = true; } }); assert.equal(prevented, false);
  h.state.session = { tenant: { id: 1, subdomain: 'fixture' }, user: { id: 2 } };
  await h.open(101); assert.equal(h.elements.ekDraftEditor.value, 'server 101');
  h.input('second edit'); h.discard(); await h.tick();
  prevented = false; h.events.beforeunload({ preventDefault() { prevented = true; } }); assert.equal(prevented, false);
  await h.open(101); assert.equal(h.elements.ekDraftEditor.value, 'server 101');
});

test('409 preserves local draft; explicit keep uses returned version and can conflict again', async () => {
  const h = harness(); await h.open(101);
  h.reply = c => c.options.method === 'POST' ? Promise.reject(conflict(h.posts().length + 1, `server version ${h.posts().length + 1}`)) : undefined;
  h.input('my local edit'); await h.tick();
  assert.equal(h.elements.ekDraftEditor.value, 'my local edit'); assert.equal(h.extra.ekServerDraft.value, 'server version 2');
  assert.match(h.extra.ekDraftRecoveryMessage.textContent, /请比较下方内容/);
  h.input('more local edit'); await h.tick(); assert.equal(h.posts().length, 1, 'a conflict must stop automatic sends');
  await h.click('ekKeepLocalDraftButton'); assert.equal(h.posts()[1].body.base_version, 2);
  assert.equal(h.elements.ekDraftEditor.value, 'more local edit'); assert.equal(h.extra.ekServerDraft.value, 'server version 3');
  await h.click('ekUseServerDraftButton'); assert.equal(h.elements.ekDraftEditor.value, 'server version 3'); assert.equal(h.posts().length, 2);
});

test('failed save keeps edits and retries only on user action with original base version', async () => {
  const h = harness(); await h.open(101); h.reply = c => c.options.method === 'POST' ? Promise.reject(new Error('response lost')) : undefined;
  h.input('unknown save'); await h.tick(); h.input('new local'); await h.tick();
  assert.equal(h.posts().length, 1); assert.match(h.elements.ekAutosaveStatus.textContent, /本地编辑已保留/);
  await h.click('ekRetrySaveButton'); await pause(); assert.equal(h.posts()[1].body.base_version, 0);
  assert.equal(h.elements.ekDraftEditor.value, 'new local');
});

test('validate waits for pending autosave and uses its acknowledged version', async () => {
  const h = harness(); await h.open(101); const pending = deferred();
  h.reply = c => c.path.endsWith('/autosave') ? pending.promise : undefined;
  h.input('edited content'); await h.tick(); const validating = h.validate(); await pause();
  assert.equal(h.posts().length, 1); pending.resolve({ draft_version: 1, saved_at: 'now' }); await validating;
  assert.equal(h.posts()[1].path, '/api/v1/enterprise-knowledge/101/validate'); assert.equal(h.posts()[1].body.base_version, 1);
  assert.equal(h.posts()[1].body.content, 'edited content');
});

test('restore uses current version and does not wipe edits typed during its response', async () => {
  const h = harness(); await h.open(101); const pending = deferred();
  h.reply = c => c.path.includes('/restore/') ? pending.promise : undefined;
  const restoring = h.restore(51); await until(() => h.posts().length === 1); h.input('typed after restore');
  assert.equal(h.posts()[0].body?.base_version, 0);
  pending.resolve({ data: { ...h.projects.get(101), draft_version: 1, draft_content: 'historical version' } }); await restoring;
  assert.equal(h.elements.ekDraftEditor.value, 'typed after restore'); await h.tick();
  assert.equal(h.posts()[1].body.base_version, 1); assert.equal(h.posts()[1].body.content, 'typed after restore');
});


test('editing directly into a new owner does not throw or recover the former draft', async () => {
  const h = harness(); await h.open(101); h.input('old owner private edit');
  h.state.session = { tenant: { id: 50, subdomain: 'other' }, user: { id: 51 } };
  await h.open(202); await h.tick();
  assert.equal(h.elements.ekDraftEditor.value, 'server 202'); assert.equal(h.posts().length, 0);
});

test('late published-document poll cannot mutate another editor or a same-id reopened editor', async () => {
  for (const id of [101, 202]) {
    const h = harness();
    h.projects.set(101, { ...h.projects.get(101), published_knowledge_base: { id: 31 }, published_document: { id: 41, status: 'pending' } });
    const pending = deferred(); let polls = 0;
    h.reply = c => c.path === '/api/v1/knowledge-bases/31/documents' ? (++polls === 1 ? pending.promise : { data: [{ id: 41, status: 'processing' }] }) : undefined;
    await h.open(101); await h.open(id); const latest = h.state.ekDetail;
    h.elements.ekDetailMessage.textContent = 'current editor message';
    pending.resolve({ data: [{ id: 41, status: 'ready', chunk_count: 7 }] }); await pause();
    assert.equal(h.elements.ekDetailMessage.textContent, 'current editor message');
    assert.notEqual(latest.published_document?.status, 'ready');
  }
});

test('generate is versioned and preserves edits made while its request is pending', async () => {
  const h = harness(); await h.open(101); const pending = deferred();
  h.reply = c => c.path.endsWith('/generate') ? pending.promise : undefined;
  const generating = h.generate(); await until(() => h.posts().length === 1);
  assert.equal(h.posts()[0].body?.base_version, 0);
  h.input('newer than generation click'); await h.tick(); assert.equal(h.posts().length, 1);
  pending.resolve({ data: { ...h.projects.get(101), draft_version: 1, status: 'queued' } }); await generating;
  assert.equal(h.elements.ekDraftEditor.value, 'newer than generation click'); await h.tick();
  assert.equal(h.posts()[1].body.base_version, 1); assert.equal(h.posts()[1].body.content, 'newer than generation click');
});
