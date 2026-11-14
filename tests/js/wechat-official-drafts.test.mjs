import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
const source = readFileSync(new URL('../../public/geo-wechat-drafts.js', import.meta.url), 'utf8');
test('公众号评论详情不挂载文章草稿组件', () => {
  const page = readFileSync(new URL('../../public/geo-manual-publish.js', import.meta.url), 'utf8');
  assert.match(page, /window\.GEO_WECHAT_DRAFTS && p\.platform === 'wechat' && p\.type === 'post' && \$\('#platformDraftHost'\)/);
});
class Element {
  constructor(tag) { this.tagName = tag; this.children = []; this.events = {}; this.value = ''; this.textContent = ''; this.isConnected = true; this.checked = false; this.files = []; }
  append(...els) { this.children.push(...els); }
  replaceChildren(...els) { this.children = els; }
  setAttribute() {}
  addEventListener(type, action) { (this.events[type] ||= []).push(action); }
  async fire(type) { for (const action of this.events[type] || []) await action({ preventDefault() {} }); }
}
const all = root => [root, ...root.children.flatMap(all)];
const text = root => all(root).map(x => x.textContent).join(' ');
function setup(response, overrides = {}) {
  const host = new Element('div');
  const requests = [];
  const window = {};
  const context = { window, document: { createElement: tag => new Element(tag) }, FormData };
  vm.runInNewContext(source, context);
  const publication = { id: 2, platform: 'wechat', type: 'post', status: 'in_progress', revision: 3, risk_status: 'clean', account_id: 4, account: { account_name: '测试公众号' }, content_fingerprint: 'hash', source_snapshot: { title: '原文标题', article_version: 1 }, article: { version: 1 }, ...overrides };
  const options = { host, publication, canTransition: true, async apiGet() { return response; }, async apiPost(url, body) { requests.push({url,body}); return {}; }, async apiForm(url, body) { requests.push({url,body}); return {}; } };
  return { host, requests, options, module: window.GEO_WECHAT_DRAFTS };
}
const connected = { connection: {app_id: 'wxexample'}, data: null };
test('未绑定时给出产品连接入口，不展示发送按钮', async () => {
  const h = setup({data: null, connection: null}); await h.module.mount(h.options);
  assert.match(text(h.host), /尚未连接公众号/);
  assert.equal(all(h.host).find(x => x.tagName === 'a').href, '#sec-settings');
  assert.equal(all(h.host).some(x => x.type === 'submit'), false);
});
test('发送需要真实封面与两项确认，产品通过 FormData 提交，不公开发表', async () => {
  const h = setup(connected); await h.module.mount(h.options);
  const form = all(h.host).find(x => x.tagName === 'form');
  await form.fire('submit'); assert.equal(h.requests.length, 0);
  all(form).filter(x => x.type === 'checkbox').forEach(x => { x.checked = true; });
  all(form).find(x => x.type === 'file').files = [new Blob(['cover'], {type:'image/png'})];
  await form.fire('submit'); assert.equal(h.requests.length, 1);
  assert.equal(h.requests[0].url, '/api/v1/manual-publications/2/wechat-draft');
  assert.equal(h.requests[0].body.get('publication_title'), '原文标题');
  assert.equal(h.requests[0].body.get('revision'), '3');
  assert.ok(h.requests[0].body.get('cover'));
});
test('API 标题 32 字限制在发送前提示，不截断原文', async () => {
  const h = setup(connected, {source_snapshot: {title: '字'.repeat(33), article_version: 1}}); await h.module.mount(h.options);
  const form = all(h.host).find(x => x.tagName === 'form');
  all(form).filter(x => x.type === 'checkbox').forEach(x => { x.checked = true; });
  all(form).find(x => x.type === 'file').files = [new Blob(['cover'])];
  await form.fire('submit');
  assert.match(text(h.host), /调整到 32/); assert.equal(h.requests.length, 0);
  assert.equal(h.options.publication.source_snapshot.title.length, 33);
});
test('未知结果只允许读取核对，不出现重试或新建按钮', async () => {
  const h = setup({connection: connected.connection, data: {status:'unknown', publication_title:'稿件', account_id:4, content_fingerprint:'hash', article_version:1}}); await h.module.mount(h.options);
  assert.match(text(h.host), /核对微信草稿结果/); assert.doesNotMatch(text(h.host), /重试同一草稿任务/);
  assert.equal(all(h.host).some(x => x.type === 'submit'), false);
  await all(h.host).find(x => x.textContent === '核对微信草稿结果').fire('click');
  assert.equal(h.requests[0].url, '/api/v1/manual-publications/2/wechat-draft/check');
});
test('已保存与历史版本回执同时展示，不能当作当前版本发布完成', async () => {
  const h = setup({connection: connected.connection, data: {status:'saved', publication_title:'旧稿', media_id:'remote-id', account_id:4, content_fingerprint:'old', article_version:1}}); await h.module.mount(h.options);
  assert.match(text(h.host), /历史版本/); assert.match(text(h.host), /不代表公开发布成功/); assert.match(text(h.host), /remote-id/);
});
test('连接表单不回显密钥，提交后清空，并要求管理员核对账号', async () => {
  const h = setup({data: null}); h.options.account = {id:4, account_name:'测试公众号'};
  await h.module.mountConnection(h.options);
  const form = all(h.host).find(x => x.tagName === 'form');
  const secret = all(form).find(x => x.type === 'password');
  assert.equal(secret.value, ''); secret.value = 'private-secret';
  await form.fire('submit'); assert.equal(h.requests.length, 0);
  all(form).find(x => x.type === 'checkbox').checked = true;
  await form.fire('submit'); assert.equal(secret.value, '');
  assert.equal(h.requests[0].url, '/api/v1/manual-publications/settings/accounts/4/wechat-connection');
});
test('真实设置页同时渲染人设与账号，并将连接操作交给所选公众号', async () => {
  const page = readFileSync(new URL('../../public/geo-manual-publish.js', import.meta.url), 'utf8');
  const start = page.indexOf('function loadSettings(');
  const end = page.indexOf('\n  /* 启停切换', start);
  assert.ok(start > 0 && end > start);
  const bodies = { '#personaListBody': {innerHTML:''}, '#accountListBody': {innerHTML:''}, '#wechatConnectionHost': {scrollIntoView() {}} };
  const accounts = [
    {id:42, platform:'wechat', account_name:'目标公众号', is_active:true},
    {id:43, platform:'zhihu', account_name:'知乎账号', is_active:true},
    {id:44, platform:'wechat', account_name:'停用公众号', is_active:false}
  ];
  let buttons = [];
  let mounted;
  const context = {
    async apiGet(path) { return {data: path.endsWith('/personas') ? [{id:8,name:'默认编辑',is_active:true}] : accounts}; },
    apiPost() {}, isAdmin() { return true; }, platformLabel(x) { return x; }, esc(x) { return String(x); },
    $(selector) { return bodies[selector]; },
    $$(selector) {
      if (selector.includes('[data-wechat-connect]')) {
        buttons = [...bodies['#accountListBody'].innerHTML.matchAll(/data-wechat-connect="(\d+)"/g)].map(m => ({
          value:m[1], getAttribute() { return this.value; }, addEventListener(type,fn) { this.click=fn; }
        }));
        return buttons;
      }
      return [];
    },
    window: {GEO_WECHAT_DRAFTS: {mountConnection(options) { mounted=options; }}}
  };
  vm.runInNewContext(page.slice(start,end)+'\nloadSettings();', context);
  await new Promise(setImmediate);
  assert.match(bodies['#personaListBody'].innerHTML,/默认编辑/);
  assert.doesNotMatch(bodies['#personaListBody'].innerHTML,/加载失败|data-wechat-connect/);
  assert.deepEqual(buttons.map(x=>x.value),['42']);
  buttons[0].click();
  assert.equal(mounted.account.id,42);
  assert.equal(mounted.host,bodies['#wechatConnectionHost']);
});
