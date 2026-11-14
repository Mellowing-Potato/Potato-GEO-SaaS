import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/geo-publication-drafts.js', import.meta.url), 'utf8');

class Element {
  constructor(tag) {
    this.tagName = tag;
    this.children = [];
    this.events = {};
    this.attributes = {};
    this.isConnected = true;
    this.textContent = '';
    this.value = '';
    this.checked = false;
    this.disabled = false;
    this.hidden = false;
  }
  append(...children) { children.forEach(child => { child.parentNode = this; this.children.push(child); }); }
  appendChild(child) { this.append(child); return child; }
  setAttribute(key, value) { this.attributes[key] = value; }
  addEventListener(type, callback) { (this.events[type] ||= []).push(callback); }
  async fire(type) { await Promise.all((this.events[type] || []).map(callback => callback({ preventDefault() {} }))); }
  click() { return this.fire('click'); }
  remove() { this.isConnected = false; this.parentNode.children = this.parentNode.children.filter(child => child !== this); }
}

function descendants(root) { return [root, ...root.children.flatMap(descendants)]; }
const settle = async () => { await new Promise(setImmediate); await new Promise(setImmediate); };

function harness(platform = 'wechat', overrides = {}) {
  const host = new Element('div');
  const document = { createElement: tag => new Element(tag), body: new Element('body') };
  const requests = [];
  const copied = [];
  const downloads = [];
  const messages = [];
  let changed = 0;
  let nextError = null;
  let pending = null;
  let clipboardStarts = 0;
  const publication = {
    id: 70, type: 'post', platform, revision: 3, status: 'in_progress', risk_status: 'clean',
    account_id: 9, account: { account_name: '测试账号', profile_url: null, persona_id: 4, is_active: true },
    persona: { is_active: true }, article: { version: 2, status: 'approved' }, source_snapshot: { article_version: 2, title: overrides.payload?.title || '已审核标题' },
    content_fingerprint: 'body-hash', ...overrides
  };
  const payload = {
    publication_id: publication.id, platform, revision: publication.revision, article_version: 2,
    account_name: '测试账号', title: '已审核标题', plain_text: '正文及参考（https://reference.example.test/）',
    html: platform === 'wechat' ? '<section><p><strong>正文</strong></p></section>' : null,
    preparation_token: 'opaque-preparation-proof', image_count: 1, automatic_remote_save: false,
    draft_storage_scope: platform === 'xiaohongshu' ? 'browser_local' : 'not_verified',
    platform_label: '平台', ...overrides.payload
  };
  const clipboard = {
    async write(items) {
      clipboardStarts++;
      const result = {};
      for (const [type, value] of Object.entries(items[0].data)) result[type] = await (await value).text();
      copied.push(result);
    },
    async writeText(text) { copied.push({ 'text/plain': text }); }
  };
  const context = vm.createContext({
    window: {}, document, navigator: { clipboard }, Blob,
    ClipboardItem: class { constructor(data) { this.data = data; } },
    URL: { createObjectURL(blob) { downloads.push(blob); return 'blob:test'; }, revokeObjectURL() {} },
    setTimeout(callback) { callback(); }, Date, Promise
  });
  vm.runInContext(source, context);
  context.window.GEO_PUBLICATION_DRAFTS.mount({
    host, publication, canTransition: overrides.canTransition ?? true,
    async apiPost(url, data) {
      requests.push({ url, data });
      if (nextError) { const error = nextError; nextError = null; throw error; }
      if (url.endsWith('/draft-preparation')) {
        if (pending) await pending;
        return { data: { ...payload, title: data.publication_title } };
      }
      return { data: { status: 'in_progress', revision: 4 } };
    },
    onChanged() { changed++; }
  });
  function find(text) {
    const found = descendants(host).find(element => element.textContent === text);
    assert.ok(found, `Missing control: ${text}`);
    return found;
  }
  return {
    host, document, publication, payload, requests, copied, downloads, context,
    find, get changed() { return changed; }, get clipboardStarts() { return clipboardStarts; },
    failNext(message) { nextError = new Error(message); },
    pause() { let release; pending = new Promise(resolve => { release = resolve; }); return () => { pending = null; release(); }; },
    status() { return descendants(host).find(node => node.attributes.role === 'status')?.textContent || ''; },
    form() { return descendants(host).find(element => element.tagName === 'form'); },
    titleInput() { return descendants(host).find(element => element.tagName === 'input' && element.maxLength === 500); },
    confirmInputs() {
      const inputs = descendants(host).filter(element => element.tagName === 'input');
      inputs.find(input => input.type === 'text' && input.maxLength === 120).value = '草稿箱：已审核标题';
      inputs.filter(input => input.type === 'checkbox').forEach(input => { input.checked = true; });
    }
  };
}

test('微信富文本复制由产品按钮执行，并在每次复制时重新核对审核版本', async () => {
  const h = harness();
  await h.find('准备发布内容').click();
  assert.equal(h.requests.length, 1);
  await h.find('复制公众号排版正文').click();
  await settle();
  assert.equal(h.requests.length, 2);
  assert.equal(h.clipboardStarts, 1);
  assert.equal(h.copied[0]['text/html'], h.payload.html);
  assert.equal(h.copied[0]['text/plain'], h.payload.plain_text);
  assert.match(h.status(), /已复制公众号排版正文/);
  assert.equal(h.changed, 0);
  assert.ok(h.requests.every(request => request.url.endsWith('/draft-preparation')));
});

test('异步审核检查期间立即启动剪贴板写入，保留用户点击手势', async () => {
  const h = harness();
  await h.find('准备发布内容').click();
  const release = h.pause();
  await h.find('复制公众号排版正文').click();
  assert.equal(h.clipboardStarts, 1);
  assert.equal(h.copied.length, 0);
  release();
  await settle();
  assert.equal(h.copied.length, 1);
});

test('小红书和微博使用纯文本，标题与正文独立复制且不调用外站发布', async () => {
  for (const platform of ['xiaohongshu', 'weibo']) {
    const h = harness(platform);
    await h.find('准备发布内容').click();
    await h.find('复制正文').click();
    await settle();
    await h.find('复制标题').click();
    await settle();
    assert.equal(h.copied[0]['text/plain'], h.payload.plain_text);
    assert.equal(h.copied[0]['text/html'], undefined);
    assert.equal(h.copied[1]['text/plain'], h.payload.title);
    if (platform === 'weibo') assert.equal(h.find('打开微博').href, 'https://card.weibo.com/article/v5/editor#/draft');
    assert.ok(h.requests.every(request => request.url.startsWith('/api/v1/manual-publications/70/')));
  }
});

test('旧版本被服务端拒绝后不写入旧正文，也不继续确认草稿', async () => {
  const h = harness();
  await h.find('准备发布内容').click();
  h.failNext('原文章已修改，请重新审核。');
  await h.find('复制公众号排版正文').click();
  await settle();
  assert.equal(h.copied.length, 0);
  assert.match(h.status(), /原文章已修改/);
  h.confirmInputs();
  await h.form().fire('submit');
  assert.equal(h.requests.filter(request => request.url.endsWith('/platform-draft')).length, 0);
});

test('人工草稿确认要求勾选三项并传递当前准备凭据，不能标记公开完成', async () => {
  const h = harness();
  await h.find('准备发布内容').click();
  await h.form().fire('submit');
  assert.equal(h.requests.length, 1);
  h.confirmInputs();
  await h.form().fire('submit');
  assert.equal(h.changed, 1);
  const saved = h.requests[1];
  assert.equal(saved.url, '/api/v1/manual-publications/70/platform-draft');
  assert.deepEqual({ ...saved.data }, {
    revision: 3, preparation_token: 'opaque-preparation-proof', draft_label: '草稿箱：已审核标题',
    account_checked: true, content_checked: true, saved: true
  });
  assert.ok(!h.requests.some(request => request.url.endsWith('/transition')));
});

test('草稿确认失败保留输入与错误，不能显示已保存或调用刷新成功', async () => {
  const h = harness();
  await h.find('准备发布内容').click();
  h.confirmInputs();
  h.failNext('准备凭据已过期，请重新准备。');
  await h.form().fire('submit');
  assert.equal(h.changed, 0);
  assert.match(h.status(), /已过期/);
  assert.equal(h.find('记录人工确认草稿').disabled, false);
});

test('准备包转义标题与账号，不包含会话凭据或准备令牌', async () => {
  const h = harness('wechat', { payload: { title: '<img src=x>', account_name: '<script>账号</script>' } });
  await h.find('准备发布内容').click();
  await h.find('下载发布准备包').click();
  const html = await h.downloads[0].text();
  assert.ok(html.includes('&lt;img src=x&gt;'));
  assert.ok(html.includes('&lt;script&gt;账号&lt;/script&gt;'));
  assert.ok(html.includes(h.payload.html));
  assert.ok(!html.includes('opaque-preparation-proof'));
  assert.match(html, /不代表已在平台保存或公开发布/);
});

test('不支持富文本剪贴板时显示下载替代，不谎报复制成功', async () => {
  const h = harness();
  h.context.ClipboardItem = undefined;
  await h.find('准备发布内容').click();
  await h.find('复制公众号排版正文').click();
  await settle();
  assert.equal(h.copied.length, 0);
  assert.match(h.status(), /不能复制富文本.*下载发布准备包/);
});

test('页面切换后的迟到准备响应不会写剪贴板或更新新页面', async () => {
  const h = harness();
  await h.find('准备发布内容').click();
  const release = h.pause();
  await h.find('复制公众号排版正文').click();
  h.host.isConnected = false;
  release();
  await settle();
  assert.equal(h.copied.length, 0);
  assert.equal(h.changed, 0);
});

test('草稿确认与发布单内容或账号不一致时明确失效', () => {
  const receipt = { method: 'manual_confirmation', draft_label: '<script>旧草稿</script>',
    content_fingerprint: 'old', account_id: 9, account_name: '测试账号', article_version: 2 };
  const h = harness('wechat', { draft_receipt: receipt, status: 'completed' });
  const text = descendants(h.host).map(node => node.textContent).join(' ');
  assert.match(text, /原人工确认已失效/);
  assert.equal(descendants(h.host).filter(node => node.tagName === 'button').length, 0);
});

test('原文版本、审核状态和账号身份变更会使已记录的人工确认失效', () => {
  const receipt = { method: 'manual_confirmation', draft_label: '草稿箱记录',
    content_fingerprint: 'body-hash', account_id: 9, account_name: '测试账号', article_version: 2,
    account_profile_url: null, persona_id: 4 };
  const current = harness('wechat', { draft_receipt: receipt });
  assert.match(descendants(current.host).map(node => node.textContent).join(' '), /人工确认草稿：草稿箱记录/);
  for (const changed of [
    { article: { version: 3, status: 'approved' } },
    { article: { version: 2, status: 'editing' } },
    { account: { account_name: '测试账号', profile_url: 'https://example.test/changed', persona_id: 4, is_active: true } },
    { persona: { is_active: false } }
  ]) {
    const h = harness('wechat', { draft_receipt: receipt, ...changed });
    assert.match(descendants(h.host).map(node => node.textContent).join(' '), /原人工确认已失效/);
  }
});

test('各渠道标题编辑进入正常准备、复制与下载，原文章快照不变', async () => {
  for (const platform of ['wechat', 'xiaohongshu', 'weibo']) {
    const h = harness(platform);
    const original = structuredClone(h.publication.source_snapshot);
    h.titleInput().value = '用户提供的渠道标题';
    await h.titleInput().fire('input');
    await h.find('准备发布内容').click();
    assert.equal(h.requests[0].data.publication_title, '用户提供的渠道标题');
    await h.find('复制标题').click();
    await settle();
    assert.equal(h.copied[0]['text/plain'], '用户提供的渠道标题');
    await h.find('下载发布准备包').click();
    assert.match(await h.downloads[0].text(), /用户提供的渠道标题/);
    assert.deepEqual(h.publication.source_snapshot, original);
  }
});

test('修改发布标题使旧准备与确认失效，读取中的旧结果不能写入剪贴板', async () => {
  const h = harness('weibo');
  await h.find('准备发布内容').click();
  h.confirmInputs();
  const release = h.pause();
  await h.find('复制标题').click();
  h.titleInput().value = '修改后的标题';
  await h.titleInput().fire('input');
  assert.ok(descendants(h.form()).filter(input => input.type === 'checkbox').every(input => !input.checked));
  assert.equal(descendants(h.form()).find(input => input.maxLength === 120).value, '');
  h.confirmInputs();
  await h.form().fire('submit');
  assert.equal(h.requests.filter(request => request.url.endsWith('/platform-draft')).length, 0);
  release();
  await settle();
  assert.equal(h.copied.length, 0);
  await h.find('准备发布内容').click();
  await h.find('复制标题').click();
  await settle();
  assert.equal(h.copied[0]['text/plain'], '修改后的标题');
});

test('空发布标题不能发起准备，已确认的渠道标题在重新打开时保留', async () => {
  const h = harness();
  h.titleInput().value = ' ';
  await h.titleInput().fire('input');
  await h.find('准备发布内容').click();
  assert.equal(h.requests.length, 0);
  assert.match(h.status(), /填写本渠道发布标题/);
  const receipt = { method: 'manual_confirmation', draft_label: '位置', publication_title: '已确认的实际标题',
    content_fingerprint: 'body-hash', account_id: 9, account_name: '测试账号', article_version: 2,
    account_profile_url: null, persona_id: 4 };
  const restored = harness('wechat', { draft_receipt: receipt });
  assert.equal(restored.titleInput().value, '已确认的实际标题');
});

test('标题和纯文本走原生文本剪贴板，不依赖富文本 Blob 写入', async () => {
  const h = harness('wechat');
  h.context.ClipboardItem = class { constructor() { throw new Error('Unexpected rich clipboard for a title'); } };
  await h.find('准备发布内容').click();
  await h.find('复制标题').click();
  await settle();
  assert.equal(h.copied[0]['text/plain'], h.payload.title);
  assert.equal(h.clipboardStarts, 0);
  assert.equal(h.requests.length, 2);
  assert.match(descendants(h.host).map(el => el.textContent).join(' '), /微信公众号最多 64/);
});

test('小红书长文入口、64字提示与浏览器本地草稿说明进入页面和下载包', async () => {
  const h = harness('xiaohongshu');
  assert.match(descendants(h.host).map(el => el.textContent).join(' '), /小红书长文最多 64/);
  assert.match(descendants(h.host).map(el => el.textContent).join(' '), /当前浏览器本地.*清理浏览器数据会删除/);
  assert.equal(h.find('打开小红书').href, 'https://creator.xiaohongshu.com/publish/publish?from=menu&target=article');
  await h.find('准备发布内容').click();
  await h.find('下载发布准备包').click();
  assert.match(await h.downloads[0].text(), /小红书长文草稿存储在当前浏览器本地/);
});

test('待发可准备但没有人工保存确认表单；无权限、阻断及其他平台不暴露操作', async () => {
  const ready = harness('wechat', { status: 'ready' });
  await ready.find('准备发布内容').click();
  assert.equal(ready.form(), undefined);
  for (const overrides of [{ canTransition: false }, { risk_status: 'blocked' }, { status: 'completed' }]) {
    const h = harness('wechat', overrides);
    assert.equal(descendants(h.host).filter(node => node.tagName === 'button').length, 0);
  }
  const other = harness('zhihu');
  assert.equal(other.host.children.length, 0);
});
