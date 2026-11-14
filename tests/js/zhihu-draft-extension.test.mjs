import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const source = fs.readFileSync(path.resolve(here, '../../browser-extension/src/zhihu-article.js'), 'utf8');

async function runEditor({ profile = 'test-author', expected = 'test-author', pathname = '/write',
  existing = false, replaceExisting = false, simulateSaved = false,
  profileAt = () => profile, initiallyOpen = false, hiddenProfile = null, menuReadyAt = 0,
  body = '新正文', simulatePasteLoss = false, smartLink = null, existingContent = null,
  initialSaved = true, payloadDraftUrl = undefined, replaceSaveTip = false, savedAt = null,
  removeTipDuringSave = false } = {}) {
  let handler;
  let mutationCallback;
  let menuOpen = initiallyOpen;
  let cloudChecked = initialSaved;
  let tipAvailable = true;
  let now = 0;
  let writes = 0;
  let avatarClicks = 0;
  let writtenProfile;
  let writtenAt;
  let linkFragment = null;
  const button = {
    click() { avatarClicks += 1; if (now >= menuReadyAt) menuOpen = !menuOpen; },
    getAttribute(name) { return name === 'aria-expanded' ? String(menuOpen) : null; },
  };
  class TextArea {
    #value = existingContent?.title ?? (existing ? '旧标题' : '');
    get value() { return this.#value; }
    set value(value) { this.#value = value; }
    dispatchEvent() {}
  }
  const title = new TextArea();
  const commitText = (text) => {
    writes += 1;
    writtenProfile = profileAt(now);
    writtenAt = now;
    if (replaceSaveTip) { saveTip.disconnected = true; saveTip = makeSaveTip(); }
    if (smartLink && text.includes(smartLink.url)) {
      const [before, after] = text.split(smartLink.url);
      linkFragment = { before, after, href: smartLink.href || smartLink.url, title: smartLink.title };
      editor.textContent = before + smartLink.title + after;
    } else { editor.textContent = text; linkFragment = null; }
    if (simulateSaved) {
      cloudChecked = false;
      if (removeTipDuringSave) tipAvailable = false;
      mutationCallback?.();
      tipAvailable = true;
      cloudChecked = true;
    }
  };
  const editor = {
    nodeType: 1, tagName: 'DIV',
    textContent: existingContent?.body ?? (existing ? '旧正文' : ''), focus() {},
    get childNodes() {
      const text = (value) => ({ nodeType: 3, nodeValue: value });
      return linkFragment ? [text(linkFragment.before), { nodeType: 1, tagName: 'A',
        getAttribute() { return linkFragment.href; }, childNodes: [text(linkFragment.title)] }, text(linkFragment.after)]
        : [text(this.textContent)];
    },
    dispatchEvent(event) {
      assert.equal(event.type, 'paste');
      const text = event.clipboardData.getData('text/plain');
      event.preventDefault();
      commitText(simulatePasteLoss ? text.split('\n').at(-1) : text);
      return false;
    },
  };
  const makeSaveTip = () => ({
    textContent: '刚刚 · 草稿',
    disconnected: false,
    querySelector() { return !this.disconnected && (cloudChecked || (savedAt !== null && now >= savedAt)) ? {} : null; },
  });
  let saveTip = makeSaveTip();
  const location = { hostname: 'zhuanlan.zhihu.com', origin: 'https://zhuanlan.zhihu.com', pathname };
  const document = {
    querySelectorAll(selector) {
      assert.equal(selector, 'a.AppHeaderProfileMenu-item[href*="/people/"]');
      const links = hiddenProfile ? [{ href: `https://www.zhihu.com/people/${hiddenProfile}`,
        getClientRects() { return []; } }] : [];
      if (menuOpen && profileAt(now)) links.push({
        href: `https://www.zhihu.com/people/${profileAt(now)}?utm_source=article-pc-editor`,
        getClientRects() { return [{}]; },
      });
      return links;
    },
    querySelector(selector) {
      if (selector.startsWith('iframe[')) return null;
      if (selector === 'button.AppHeader-profileEntry') return button;
      if (selector.startsWith('a[href*="/people/"]')) {
        return menuOpen ? { href: `https://www.zhihu.com/people/${profileAt(now)}?utm_source=article-pc-editor` } : null;
      }
      if (selector.startsWith('textarea[')) return title;
      if (selector.startsWith('.public-DraftEditor-content')) return editor;
      if (selector === '.DraftStatusTip') return tipAvailable ? saveTip : null;
      return null;
    },
    createRange() { return { selectNodeContents() {} }; },
    execCommand(command, _showUi, body) {
      assert.equal(command, 'insertText');
      // Reproduce the observed multi-line native input loss in a controlled editor fixture.
      commitText(body.split('\n').at(-1));
      return true;
    },
  };
  const context = {
    URL, location, document, HTMLTextAreaElement: TextArea,
    Node: { TEXT_NODE: 3, ELEMENT_NODE: 1 },
    Event: class {},
    DataTransfer: class {
      data = new Map();
      setData(type, text) { this.data.set(type, text); }
      getData(type) { return this.data.get(type) || ''; }
    },
    ClipboardEvent: class {
      constructor(type, options) { this.type = type; Object.assign(this, options); this.defaultPrevented = false; }
      preventDefault() { this.defaultPrevented = true; }
    },
    MutationObserver: class {
      constructor(callback) { mutationCallback = callback; }
      observe() {}
      disconnect() {}
    },
    Date: { now() { return now; } },
    setTimeout(callback, delay) { now += delay; callback(); },
    window: { getSelection() { return { removeAllRanges() {}, addRange() {} }; } },
    chrome: { runtime: {
      onMessage: { addListener(callback) { handler = callback; } },
      sendMessage() { return Promise.resolve(); },
    } },
  };
  vm.runInNewContext(source, context);
  const result = await new Promise((resolve) => handler({
    type: 'YANJI_FILL_DRAFT',
    payload: {
      title: '新标题', body, profileUrl: `https://www.zhihu.com/people/${expected}`,
      replaceExisting, draftUrl: payloadDraftUrl === undefined
        ? (pathname === '/write' ? null : `https://zhuanlan.zhihu.com${pathname}`) : payloadDraftUrl,
    },
  }, null, resolve));
  return { result, title: title.value, body: editor.textContent, writes, avatarClicks, writtenProfile, writtenAt, menuOpen };
}

test('账号不一致时不触碰文章编辑器', async () => {
  const outcome = await runEditor({ profile: 'someone-else' });
  assert.equal(outcome.result.code, 'account_mismatch');
  assert.equal(outcome.writes, 0);
  assert.equal(outcome.title, '');
  assert.equal(outcome.result.expectedProfile, '/people/test-author');
  assert.equal(outcome.result.currentProfile, '/people/someone-else');
});

test('页头先呈现旧身份后完成加载，必须等真实账号稳定后才能写入', async () => {
  const outcome = await runEditor({ profileAt: (now) => now < 2000 ? 'previous-header-value' : 'test-author' });
  assert.equal(outcome.writes, 1);
  assert.equal(outcome.writtenProfile, 'test-author');
  assert.ok(outcome.writtenAt >= 2750);
});

test('只读取可见账号菜单，忽略隐藏的旧主页链接', async () => {
  const outcome = await runEditor({ hiddenProfile: 'another-author' });
  assert.equal(outcome.writes, 1);
  assert.equal(outcome.writtenProfile, 'test-author');
});

test('账号菜单首次点击未展开时重试，直到页面事件就绪后再核对身份', async () => {
  const outcome = await runEditor({ menuReadyAt: 1500 });
  assert.equal(outcome.writes, 1);
  assert.equal(outcome.writtenProfile, 'test-author');
  assert.ok(outcome.writtenAt >= 2250);
  assert.ok(outcome.avatarClicks > 2);
});

test('已打开的账号菜单不被反向关闭再读取', async () => {
  const outcome = await runEditor({ initiallyOpen: true });
  assert.equal(outcome.writes, 1);
  assert.equal(outcome.avatarClicks, 0);
  assert.equal(outcome.menuOpen, true);
});

test('账号持续变化时停止，不能凭一次恰好匹配就填稿', async () => {
  const outcome = await runEditor({ profileAt: (now) => Math.floor(now / 300) % 2 ? 'someone-else' : 'test-author' });
  assert.equal(outcome.result.code, 'account_not_ready');
  assert.equal(outcome.writes, 0);
  assert.equal(outcome.title, '');
});

test('账号信息始终未加载时给出未就绪回执，不伪报账号不一致', async () => {
  const outcome = await runEditor({ profileAt: () => null });
  assert.equal(outcome.result.code, 'account_not_ready');
  assert.equal(outcome.writes, 0);
});

test('未授权覆盖已有内容时停止', async () => {
  const outcome = await runEditor({ pathname: '/p/123/edit', existing: true });
  assert.equal(outcome.result.code, 'editor_not_empty');
  assert.equal(outcome.writes, 0);
  assert.equal(outcome.body, '旧正文');
});

test('仅填入内容但无法确认保存时不回报成功', async () => {
  const outcome = await runEditor();
  assert.equal(outcome.result.code, 'filled_unconfirmed');
  assert.equal(outcome.result.saved, false);
  assert.equal(outcome.result.saveStage, 'transition_not_observed');
  assert.equal(outcome.title, '新标题');
  assert.equal(outcome.body, '新正文');
  assert.equal(outcome.avatarClicks, 2);
});

test('已有草稿出现保存状态变化后才回报已保存', async () => {
  const outcome = await runEditor({ pathname: '/p/123/edit', existing: true,
    replaceExisting: true, simulateSaved: true });
  assert.equal(outcome.result.code, 'draft_saved');
  assert.equal(outcome.result.saved, true);
  assert.equal(outcome.result.draftUrl, 'https://zhuanlan.zhihu.com/p/123/edit');
});

test('多段正文一次性作为编辑器片段写入，首尾段及空行全部保留', async () => {
  const body = '# 标题\n\n第一段内容。\n第二段内容。\n\n最后一段。';
  const outcome = await runEditor({ body, pathname: '/p/123/edit', simulateSaved: true });
  assert.equal(outcome.body, body);
  assert.equal(outcome.writes, 1);
  assert.equal(outcome.result.code, 'draft_saved');
});

test('即使平台出现保存标识，正文不完整也必须失败，不能声称仅待确认保存', async () => {
  const outcome = await runEditor({ body: '第一段\n\n最后一段', simulatePasteLoss: true,
    pathname: '/p/123/edit', simulateSaved: true });
  assert.equal(outcome.body, '最后一段');
  assert.equal(outcome.result.ok, false);
  assert.equal(outcome.result.code, 'content_mismatch');
});

test('平台将网址变为网页标题时，以真实链接目标核对正文，仍可确认保存', async () => {
  const url = 'https://example.test/research';
  const outcome = await runEditor({ body: `第一段\n[来源](${url})\n最后一段`,
    smartLink: { url, title: '研究资料页' }, pathname: '/p/123/edit', simulateSaved: true });
  assert.ok(outcome.body.includes('研究资料页'));
  assert.equal(outcome.result.code, 'draft_saved');
});

test('智能链接的文字看似正确但实际目标被改动时拒绝保存成功', async () => {
  const url = 'https://example.test/research';
  const outcome = await runEditor({ body: `第一段\n[来源](${url})\n最后一段`,
    smartLink: { url, title: url, href: 'https://wrong-target.invalid/' },
    pathname: '/p/123/edit', simulateSaved: true });
  assert.equal(outcome.result.ok, false);
  assert.equal(outcome.result.code, 'content_mismatch');
});

test('已关联草稿内容完全一致且平台显示已保存时，核对通过而不重复写入', async () => {
  const outcome = await runEditor({ pathname: '/p/123/edit', replaceExisting: true,
    existingContent: { title: '新标题', body: '新正文' } });
  assert.equal(outcome.result.code, 'draft_unchanged');
  assert.equal(outcome.result.saved, true);
  assert.equal(outcome.writes, 0);
});

test('相同内容尚在保存中时，不能把它当成已经保存', async () => {
  const outcome = await runEditor({ pathname: '/p/123/edit', replaceExisting: true, initialSaved: false,
    existingContent: { title: '新标题', body: '新正文' } });
  assert.equal(outcome.result.code, 'save_not_ready');
  assert.equal(outcome.writes, 0);
});

test('内容相同也不能把其他草稿当成已关联草稿', async () => {
  const outcome = await runEditor({ pathname: '/p/123/edit', replaceExisting: true,
    payloadDraftUrl: 'https://zhuanlan.zhihu.com/p/999/edit',
    existingContent: { title: '新标题', body: '新正文' } });
  assert.equal(outcome.result.code, 'wrong_draft');
  assert.equal(outcome.writes, 0);
});

test('保存提示组件被重新渲染后，仍核对当前组件的新保存状态', async () => {
  const outcome = await runEditor({ pathname: '/p/123/edit', existing: true, replaceExisting: true,
    replaceSaveTip: true, simulateSaved: true });
  assert.equal(outcome.result.code, 'draft_saved');
  assert.equal(outcome.result.saved, true);
});

test('已有草稿内容先加载而保存提示延迟出现时，等待稳定后核对，不重复改写', async () => {
  const outcome = await runEditor({ pathname: '/p/123/edit', replaceExisting: true,
    initialSaved: false, savedAt: 4000, existingContent: { title: '新标题', body: '新正文' } });
  assert.equal(outcome.result.code, 'draft_unchanged');
  assert.equal(outcome.result.saved, true);
  assert.equal(outcome.writes, 0);
});

test('实际改写时保存提示暂时卸载，恢复已保存后仍可确认本次保存', async () => {
  const outcome = await runEditor({ pathname: '/p/123/edit', existing: true,
    replaceExisting: true, simulateSaved: true, removeTipDuringSave: true });
  assert.equal(outcome.result.code, 'draft_saved');
  assert.equal(outcome.result.saved, true);
});
