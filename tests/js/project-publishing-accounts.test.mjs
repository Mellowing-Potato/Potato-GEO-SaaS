import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync(new URL('../../public/geo-entry-publishing.js', import.meta.url), 'utf8');
const start = source.indexOf('  let projectBindingRequest =');
const end = source.indexOf('  // ═', start);
const sectionSource = source.slice(start, end);

class Node {
  constructor() { this.children = []; this.innerHTML = ''; this.disabled = false; this.hidden = false; }
  appendChild(child) { this.children.push(child); }
  querySelectorAll(selector) {
    const name = selector.match(/name="([^"]+)"/)?.[1];
    return this.children.flatMap(child => {
      const inputs = [...child.innerHTML.matchAll(/<input\b([^>]+)>/g)].map(([, attributes]) => ({
        name: attributes.match(/name="([^"]+)"/)?.[1], value: attributes.match(/value="([^"]+)"/)?.[1],
        checked: /\bchecked\b/.test(attributes), disabled: /\bdisabled\b/.test(attributes),
      })).filter(input => input.name === name);
      return [...inputs, ...child.querySelectorAll(selector)];
    });
  }
}

function harness(api) {
  const elements = Object.fromEntries(['projectChannelBinding', 'saveProjectChannelsButton', 'projectChannelMessage', 'projectChannelList', 'projectChannelSaveMessage'].map(key => [key, new Node()]));
  const state = { selectedProject: { id: 1, name: 'Project A' }, projectChannels: [] };
  const document = { getElementById: () => ({}), createElement: () => new Node() };
  const methods = new Function('state', 'elements', 'api', 'document', 'setMessage', 'escapeHtml', 'validationMessage', 'publishingChannelTypeLabel', `${sectionSource}; return { load: loadProjectChannels, save: saveProjectChannels };`)(
    state, elements, api, document, (node, text) => { node.textContent = text; }, String, error => error.message, String,
  );
  return { ...methods, state, elements };
}

const accountBindings = {
  mode: 'bound', bound_channel_ids: [], bound_manual_account_ids: [7], manual_publish_enabled: true,
  available_manual_accounts: [{ id: 7, platform_label: '知乎', account_name: 'Author A', persona_name: 'Editor', is_active: true }],
};

test('没有网站接口也能展示并保存明确选择的平台账号', async () => {
  const requests = [];
  const ui = harness(async (url, options) => {
    requests.push({ url, options });
    return url.includes('project-channels?') ? accountBindings : { data: [] };
  });
  await ui.load();
  assert.equal(ui.elements.saveProjectChannelsButton.disabled, false);
  assert.match(ui.elements.projectChannelMessage.textContent, /已显式绑定/);
  await ui.save();
  const request = requests.find(request => request.options?.method === 'POST');
  assert.deepEqual(JSON.parse(request.options.body), { project_id: 1, channel_ids: [], manual_account_ids: [7] });
});

test('绑定加载失败时不能把旧选项保存到新项目', async () => {
  let writes = 0;
  const ui = harness(async (url, options) => {
    if (options) { writes++; return {}; }
    throw new Error('offline');
  });
  await ui.load();
  await ui.save();
  assert.equal(writes, 0);
  assert.equal(ui.elements.saveProjectChannelsButton.disabled, true);
  assert.match(ui.elements.projectChannelList.textContent, /加载失败/);
});

test('较早项目的迟到响应不会覆盖当前项目绑定', async () => {
  let resolveOld;
  const old = new Promise(resolve => { resolveOld = resolve; });
  const ui = harness(async url => url.includes('project_id=1') ? old
    : url.includes('project_id=2') ? { ...accountBindings, mode: 'none', bound_manual_account_ids: [] } : { data: [] });
  const previous = ui.load();
  ui.state.selectedProject = { id: 2, name: 'Project B' };
  await ui.load();
  resolveOld(accountBindings);
  await previous;
  assert.match(ui.elements.projectChannelMessage.textContent, /Project B/);
  assert.match(ui.elements.projectChannelMessage.textContent, /未绑定，不会自动外发/);
  assert.equal(ui.elements.projectChannelList.querySelectorAll('input[name="project_manual_account"]').filter(input => input.checked).length, 0);
});
