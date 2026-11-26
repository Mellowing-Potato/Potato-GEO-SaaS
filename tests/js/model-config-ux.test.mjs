import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const template = readFileSync(new URL('../../resources/views/workbench/panels/ai-keys.blade.php', import.meta.url), 'utf8');
const script = readFileSync(new URL('../../public/geo-entry-onboarding.js', import.meta.url), 'utf8');

// 1. Structural contract on ai-keys.blade.php
assert.match(template, /id="aikeysNextBanner"[^>]*hidden/, 'Next-step banner must start hidden until text model is ready.');
assert.match(template, /data-source-tabs="\{\{ \$slot \}\}"/, 'Magpie-capable slots must provide segmented Direct/Magpie tabs.');
assert.match(template, /data-optional-slot="\{\{ \$slot \}\}"/, 'Optional model slots must render inside collapsible details cards.');
assert.match(template, /class="button primary small" type="submit">保存配置<\/button>/, 'Direct save CTA must use primary style.');
assert.match(template, /class="button primary small" type="submit" data-magpie-action="save">保存 Magpie 配置<\/button>/, 'Magpie save CTA must use primary style.');

// 2. Runtime behavior of public/geo-entry-onboarding.js
class FakeClassList {
  constructor(initial = []) { this.set = new Set(initial); }
  toggle(name, force) {
    if (force === undefined) {
      if (this.set.has(name)) { this.set.delete(name); return false; }
      this.set.add(name); return true;
    }
    if (force) this.set.add(name); else this.set.delete(name);
    return Boolean(force);
  }
  contains(name) { return this.set.has(name); }
}

class FakeNode {
  constructor(dataset = {}, classes = []) {
    this.dataset = { ...dataset };
    this.attrs = new Map();
    this.classList = new FakeClassList(classes);
    this.listeners = new Map();
    this.hidden = false;
    this.textContent = '';
  }
  setAttribute(name, value) { this.attrs.set(name, String(value)); }
  getAttribute(name) { return this.attrs.get(name); }
  addEventListener(name, fn) {
    if (!this.listeners.has(name)) this.listeners.set(name, []);
    this.listeners.get(name).push(fn);
  }
  emit(name, event) {
    for (const fn of this.listeners.get(name) || []) fn(event);
  }
}

function makeMagpieSection(slot) {
  const section = new FakeNode({ magpieSlot: slot });
  const sources = new FakeNode({ activePane: 'direct' });
  const activeSource = new FakeNode();
  activeSource.textContent = '当前选择第三方直连';
  const directDraft = { value: 'sk-test-unsaved-direct' };
  const magpieDraft = { value: 'sk-test-unsaved-magpie' };
  const directTab = new FakeNode({ sourceTab: 'direct' });
  const magpieTab = new FakeNode({ sourceTab: 'magpie' });
  directTab.setAttribute('aria-pressed', 'true');
  magpieTab.setAttribute('aria-pressed', 'false');
  const group = new FakeNode({ sourceTabs: slot });
  group.querySelectorAll = (sel) => sel === '[data-source-tab]' ? [directTab, magpieTab] : [];
  directTab.closest = (sel) => sel === '[data-source-tab]' ? directTab : sel === '[data-source-tabs]' ? group : sel === '[data-magpie-slot]' ? section : null;
  magpieTab.closest = (sel) => sel === '[data-source-tab]' ? magpieTab : sel === '[data-source-tabs]' ? group : sel === '[data-magpie-slot]' ? section : null;
  section.querySelector = (sel) => {
    if (sel === '.model-sources') return sources;
    if (sel === '[data-active-model-source]') return activeSource;
    return null;
  };
  section.querySelectorAll = (sel) => sel === '[data-source-tab]' ? [directTab, magpieTab] : [];
  return { section, sources, activeSource, directTab, magpieTab, directDraft, magpieDraft };
}

const textSlot = makeMagpieSection('text');
const visionSlot = makeMagpieSection('vision');
const badges = {
  embedding: new FakeNode({ optionalStatus: 'embedding' }, ['md-badge', 'md-badge--neutral']),
  vision: new FakeNode({ optionalStatus: 'vision' }, ['md-badge', 'md-badge--neutral']),
  visibility: new FakeNode({ optionalStatus: 'visibility' }, ['md-badge', 'md-badge--neutral']),
};
const aikeyList = new FakeNode();
aikeyList.querySelector = (sel) => {
  if (sel === '[data-magpie-slot="text"]') return textSlot.section;
  if (sel === '[data-magpie-slot="vision"]') return visionSlot.section;
  const opt = /^\[data-optional-status="(\w+)"\]$/.exec(sel);
  return opt ? badges[opt[1]] : null;
};
aikeyList.querySelectorAll = (sel) => {
  if (sel === '[data-magpie-slot]') return [textSlot.section, visionSlot.section];
  return [];
};

const banner = new FakeNode();
banner.hidden = true;
const nextTitle = new FakeNode();
const nextDesc = new FakeNode();
const nextAction = new FakeNode();
const docNodes = {
  aikeysNextBanner: banner,
  aikeysNextTitle: nextTitle,
  aikeysNextDesc: nextDesc,
  aikeysNextAction: nextAction,
};

const state = { aiKeys: [], projects: [] };
const GEO = { state, elements: { aikeyList } };
vm.runInNewContext(script, {
  window: { GEO },
  document: { getElementById: (id) => docNodes[id] || null },
});

// Unconfigured state: banner hidden, badges neutral
GEO.syncModelConfigUi();
assert.equal(banner.hidden, true, 'Next-step banner stays hidden when no text model is active.');
assert.equal(badges.embedding.textContent, '未配置');
assert.equal(badges.embedding.classList.contains('md-badge--neutral'), true);
assert.equal(textSlot.sources.dataset.activePane, 'direct');

// Switching segmented tabs toggles activePane without destroying unsaved drafts
aikeyList.emit('click', { target: textSlot.magpieTab });
assert.equal(textSlot.sources.dataset.activePane, 'magpie');
assert.equal(textSlot.magpieTab.getAttribute('aria-pressed'), 'true');
assert.equal(textSlot.directTab.getAttribute('aria-pressed'), 'false');
assert.equal(textSlot.directDraft.value, 'sk-test-unsaved-direct', 'Switching to Magpie must keep unsaved Direct draft.');
aikeyList.emit('click', { target: textSlot.directTab });
assert.equal(textSlot.sources.dataset.activePane, 'direct');
assert.equal(textSlot.magpieDraft.value, 'sk-test-unsaved-magpie', 'Switching back to Direct must keep unsaved Magpie draft.');

// Active direct text key + optional embedding key updates badges and reveals project creation CTA
state.aiKeys = [
  { id: 1, slot: 'text', is_active: true },
  { id: 2, slot: 'embedding', is_active: true },
  { id: 3, slot: 'visibility', is_active: false },
];
GEO.syncModelConfigUi();
assert.equal(banner.hidden, false, 'Active text model must reveal next-step banner.');
assert.match(nextAction.textContent, /建立第一个项目/);
assert.equal(badges.embedding.textContent, '已启用');
assert.equal(badges.embedding.classList.contains('md-badge--success'), true);
assert.equal(badges.visibility.textContent, '已停用');
assert.equal(badges.visibility.classList.contains('md-badge--neutral'), true);

// Existing project changes banner CTA to return to project workbench
state.projects = [{ id: 10, name: 'Test Project' }];
GEO.syncModelConfigUi();
assert.match(nextAction.textContent, /返回项目工作台/);

// Magpie active on vision syncs optional badge and auto-switches unpicked vision pane to magpie
visionSlot.activeSource.textContent = '当前使用 Magpie';
GEO.syncModelConfigUi();
assert.equal(badges.vision.textContent, '已连接 Magpie');
assert.equal(badges.vision.classList.contains('md-badge--success'), true);
assert.equal(visionSlot.sources.dataset.activePane, 'magpie', 'Unpicked slot using Magpie must auto-select Magpie pane.');

// Text model ready via Magpie alone also reveals banner
state.aiKeys = [];
state.projects = [];
textSlot.activeSource.textContent = '当前使用 Magpie';
GEO.syncModelConfigUi();
assert.equal(banner.hidden, false, 'Active Magpie text model must also reveal next-step banner.');
assert.equal(textSlot.sources.dataset.activePane, 'direct', 'User-picked source tab must not be overridden on sync.');

console.log('Model config UX: segmented source tabs, draft preservation, optional badges and next-step banner passed.');
