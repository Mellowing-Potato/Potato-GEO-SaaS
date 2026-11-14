import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/geo-publication-article-source.js', import.meta.url), 'utf8');
const manual = readFileSync(new URL('../../public/geo-manual-publish.js', import.meta.url), 'utf8');

class Element {
  constructor(value = '') { this.value = value; this.hidden = false; this.children = []; this.events = {}; this.textContent = ''; this.disabled = false; }
  append(...items) { this.children.push(...items); }
  setAttribute() {}
  addEventListener(name, handler) { (this.events[name] ||= []).push(handler); }
  async fire(name) { await Promise.all((this.events[name] || []).map(handler => handler({ preventDefault() {} }))); }
}
const settle = async () => { await new Promise(setImmediate); };

function fixture() {
  const article = new Element();
  const type = new Element('post');
  const content = new Element();
  const host = new Element();
  const form = new Element();
  const requests = [];
  let changed = 0;
  let responder = async id => ({ id, status: 'approved', version: 2, body_markdown: '# 标题\n\n**正文**及[参考](https://example.test/source)' });
  const context = vm.createContext({ window: {}, document: { createElement() { return new Element(); } }, Number, Error, Promise });
  vm.runInContext(source, context);
  const controller = context.window.GEO_PUBLICATION_ARTICLE_SOURCE.mount({ article, type, content, host, form,
    async apiGet(url) { requests.push(url); return responder(Number(url.split('/').pop())); },
    onChange() { changed++; }
  });
  return { article, type, content, host, form, controller, requests, context,
    get changed() { return changed; }, get message() { return host.children[0].textContent; },
    get button() { return host.children[1]; }, respond(fn) { responder = fn; },
    async select(id) { article.value = String(id); await article.fire('change'); }
  };
}

test('选择已审核文章后完整带入正文，并保留供提交的审核版本', async () => {
  const f = fixture();
  await f.select(71);
  assert.deepEqual(f.requests, ['/api/v1/articles/71']);
  assert.equal(f.content.value, '# 标题\n\n**正文**及[参考](https://example.test/source)');
  assert.equal(f.controller.versionForSubmission(), 2);
  assert.equal(f.changed, 1);
  assert.match(f.message, /已带入文章 v2/);
});

test('已发布文章也能复用；长正文保持完整，不静默截断', async () => {
  const f = fixture();
  const body = '正文'.repeat(12000);
  f.respond(async id => ({ data: { id, status: 'published', version: 6, body_markdown: body } }));
  await f.select(2);
  assert.equal(f.content.value, body);
  assert.equal(f.controller.versionForSubmission(), 6);
});

test('切换关联文章更新未手改的正文，保留已经手改的正文并提供明确替换', async () => {
  const f = fixture();
  f.respond(async id => ({ id, status: 'approved', version: id, body_markdown: '原文 ' + id }));
  await f.select(1);
  await f.select(2);
  assert.equal(f.content.value, '原文 2');
  f.content.value = '用户的未保存修改';
  await f.content.fire('input');
  await f.select(3);
  assert.equal(f.content.value, '用户的未保存修改');
  assert.equal(f.controller.versionForSubmission(), 3);
  assert.equal(f.button.hidden, false);
  assert.match(f.button.textContent, /替换当前内容/);
  await f.button.fire('click');
  assert.equal(f.content.value, '原文 3');
});

test('迟到的旧文章响应不能覆盖后来选择的文章', async () => {
  const f = fixture();
  let release;
  f.respond(id => id === 1 ? new Promise(resolve => { release = resolve; })
    : Promise.resolve({ id, status: 'approved', version: 7, body_markdown: '第二篇' }));
  const first = f.select(1);
  assert.throws(() => f.controller.versionForSubmission(), /正在读取/);
  await f.select(2);
  release({ id: 1, status: 'approved', version: 1, body_markdown: '第一篇' });
  await first;
  assert.equal(f.content.value, '第二篇');
  assert.equal(f.controller.versionForSubmission(), 7);
});

test('切为评论、取消文章或重置表单时，未完成响应不能写入正文', async () => {
  for (const action of ['comment', 'clear', 'reset']) {
    const f = fixture();
    let release;
    f.respond(() => new Promise(resolve => { release = resolve; }));
    const loading = f.select(1);
    if (action === 'comment') { f.type.value = 'comment'; await f.type.fire('change'); }
    if (action === 'clear') { f.article.value = ''; await f.article.fire('change'); }
    if (action === 'reset') await f.form.fire('reset');
    f.content.value = '正在写的其他内容';
    release({ id: 1, status: 'approved', version: 1, body_markdown: '迟到的正文' });
    await loading;
    assert.equal(f.content.value, '正在写的其他内容');
    assert.throws(() => f.controller.versionForSubmission(), /重新选择/);
  }
});

test('读取失败不能沿用旧版本；重试由产品按钮重新读取', async () => {
  const f = fixture();
  await f.select(1);
  f.respond(async () => { throw new Error('读取失败'); });
  await f.select(2);
  assert.throws(() => f.controller.versionForSubmission(), /重新选择/);
  assert.match(f.message, /读取失败/);
  f.respond(async id => ({ id, status: 'approved', version: 8, body_markdown: '恢复后的原文' }));
  await f.button.fire('click');
  assert.equal(f.content.value, '恢复后的原文');
  assert.equal(f.controller.versionForSubmission(), 8);
});

test('未审核、空正文、错误文章身份或无效版本均不能变成可提交内容', async () => {
  for (const invalid of [{ status: 'editing' }, { status: 'recycled' }, { body_markdown: ' ' }, { id: 99 }, { version: null }]) {
    const f = fixture();
    f.respond(async id => ({ id, status: 'approved', version: 1, body_markdown: '正文', ...invalid }));
    await f.select(1);
    assert.equal(f.content.value, '');
    assert.throws(() => f.controller.versionForSubmission(), /重新选择/);
    assert.match(f.message, /未审核通过|版本无效/);
  }
});

function submitHarness(f) {
  const fields = {
    '#formMsg': new Element(), '#platformSel': new Element('wechat'), '#customPlatform': new Element(),
    '#typeSel': f.type, '#articleSel': f.article, '#personaSel': new Element('3'), '#accountSel': new Element('4'),
    '#content': f.content, '#targetUrl': new Element(), '#targetContext': new Element(), '#scheduledAt': new Element(),
    '#assignedUserId': new Element('5'), '#newPubForm': { reset() {} }
  };
  const payloads = [];
  const start = manual.indexOf('  function submitNew(e) {');
  const end = manual.indexOf('  /* ---------------- 设置区新建表单', start);
  const context = vm.createContext({
    articleSource: f.controller, $: selector => fields[selector] || null,
    apiPost(url, payload) { payloads.push({ url, payload }); return Promise.resolve({ data: { id: 87 } }); },
    updateContentCounter() {}, renderRiskBox() {}, syncTypeUi() {}, loadList() {}, scheduledPayload() { return null; }
  });
  vm.runInContext(manual.slice(start, end) + '\nthis.submit = submitNew;', context);
  return { fields, payloads, submit() { context.submit({ preventDefault() {} }); } };
}

test('真实建单处理器随正文发送读取到的版本，不发起额外写稿请求', async () => {
  const f = fixture();
  await f.select(71);
  const submit = submitHarness(f);
  submit.submit();
  await settle();
  assert.equal(submit.payloads.length, 1);
  assert.equal(submit.payloads[0].url, '/api/v1/manual-publications');
  assert.equal(submit.payloads[0].payload.article_id, 71);
  assert.equal(submit.payloads[0].payload.article_version, 2);
  assert.equal(submit.payloads[0].payload.content, f.content.value);
});

test('真实建单处理器在读取未完成时拒绝提交，评论不依赖文章读取', async () => {
  const f = fixture();
  let release;
  f.respond(() => new Promise(resolve => { release = resolve; }));
  const loading = f.select(71);
  const submit = submitHarness(f);
  submit.submit();
  assert.equal(submit.payloads.length, 0);
  assert.match(submit.fields['#formMsg'].textContent, /正在读取/);
  f.type.value = 'comment';
  await f.type.fire('change');
  f.content.value = '人工评论';
  submit.submit();
  await settle();
  assert.equal(submit.payloads.length, 1);
  assert.equal(submit.payloads[0].payload.article_version, null);
  release({ id: 71, status: 'approved', version: 1, body_markdown: '旧响应' });
  await loading;
  assert.equal(f.content.value, '人工评论');
});

test('页面正常分发模块，并在建单主交互前加载', () => {
  const blade = readFileSync(new URL('../../resources/views/manual-publish.blade.php', import.meta.url), 'utf8');
  assert.ok(blade.indexOf('/geo-publication-article-source.js?') < blade.indexOf('/geo-manual-publish.js?'));
  assert.match(blade, /id="articleSourceHost"/);
});
