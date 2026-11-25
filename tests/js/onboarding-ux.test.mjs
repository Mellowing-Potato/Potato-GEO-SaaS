import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const template = readFileSync(new URL('../../resources/views/workbench/panels/project-workspace.blade.php', import.meta.url), 'utf8');
const script = readFileSync(new URL('../../public/geo-entry-onboarding.js', import.meta.url), 'utf8');

// 1. Template contracts for 3-step guide, streamlined project form & Day-1 spotlight
assert.match(template, /id="onboardingGuide"/, 'Fresh project panel must include the 3-step onboarding guide.');
assert.match(template, /id="onboardingGoModels"[^>]*data-open-workspace-view="aikeys"/, 'Step 1 must link straight to #aikeys.');
assert.match(template, /<details class="pj-more-fields" id="createProjectMoreFields">/, 'Optional creation fields must be grouped inside collapsible details.');
assert.match(template, /id="projectHomeDay1Card"[^>]*hidden/, 'Day-1 first-skill card must start hidden until evaluated for a fresh project.');

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
  constructor(classes = []) {
    this.dataset = {};
    this.classList = new FakeClassList(classes);
    this.listeners = new Map();
    this.hidden = false;
    this.textContent = '';
  }
  addEventListener(name, fn) {
    if (!this.listeners.has(name)) this.listeners.set(name, []);
    this.listeners.get(name).push(fn);
  }
  click() {
    for (const fn of this.listeners.get('click') || []) fn({ preventDefault() {} });
  }
}

function mountOnboarding({ apiImpl }) {
  const nodes = {
    onboardingGuide: new FakeNode(),
    onboardingModelBadge: new FakeNode(['md-badge', 'md-badge--neutral']),
    onboardingStepModel: new FakeNode(),
    onboardingStepProject: new FakeNode(),
    onboardingModelCopy: new FakeNode(),
    onboardingGoModels: new FakeNode(['md-btn', 'md-btn--primary', 'md-btn--sm']),
    projectHomeDay1Card: Object.assign(new FakeNode(), { hidden: true }),
    projectHomeDay1Badge: new FakeNode(['md-badge', 'md-badge--primary']),
    projectHomeDay1Title: new FakeNode(),
    projectHomeDay1Desc: new FakeNode(),
    projectHomeDay1Primary: new FakeNode(),
    projectHomeDay1Secondary: new FakeNode(),
    projectHomePublishedValue: Object.assign(new FakeNode(), { textContent: '0' }),
  };
  const calls = [];
  const actions = [];
  const state = {
    session: { tenant: { id: 1 }, user: { id: 10 } },
    selectedProject: null,
    aiKeys: [],
    projects: [],
    skills: [
      { id: 'yao-geo-intent-miner', name: '意图挖掘', stage_index: 0, available: true },
      { id: 'yao-geo-explainer-builder', name: '解释型文章', stage_index: 2, available: true },
    ],
  };
  const GEO = {
    state,
    elements: { aikeyList: null },
    api: async (path, options) => {
      calls.push({ path, method: options?.method || 'GET' });
      return apiImpl(path, options);
    },
    resolveCurrentStageIndex: (project) => Number(project?.current_stage ?? 0),
    openWorkspaceView: (view) => actions.push(['view', view]),
    openAnalysisWorkspace: () => actions.push(['analysis']),
    selectRecommendedSkill: (skill) => actions.push(['skill', skill.id]),
    renderProjectHome: () => actions.push(['baseHome']),
    renderProjectHomeQueue: () => actions.push(['baseQueue']),
  };
  vm.runInNewContext(script, {
    window: { GEO },
    document: { getElementById: (id) => nodes[id] || null },
  });
  return { GEO, state, nodes, calls, actions };
}

// 2. Missing text model: read-only GET metadata check (zero POST/test), Step 1 highlighted, Day-1 directs to #aikeys
{
  const env = mountOnboarding({
    apiImpl: async (path) => {
      if (path === '/api/v1/ai-keys') return { data: [] };
      if (path === '/api/v1/ai-keys/magpie') return { targets: [], profiles: [], bindings: {} };
      throw new Error(`Unexpected endpoint: ${path}`);
    },
  });
  env.GEO.syncOnboardingGuide();
  await env.GEO.ensureModelReadiness();
  assert.deepEqual(env.calls, [
    { path: '/api/v1/ai-keys', method: 'GET' },
    { path: '/api/v1/ai-keys/magpie', method: 'GET' },
  ], 'Readiness check must only read metadata and never probe models.');
  assert.equal(env.nodes.onboardingModelBadge.textContent, '需要先配文本模型');
  assert.equal(env.nodes.onboardingModelBadge.classList.contains('md-badge--warning'), true);
  assert.equal(env.nodes.onboardingStepModel.dataset.stepState, 'current');
  assert.equal(env.nodes.onboardingGoModels.classList.contains('md-btn--primary'), true);

  const day1Project = { id: 1, current_stage: 0, status: 'active', workflow_recommendations: [{ stage_index: 0, completed_skill_ids: [] }] };
  env.state.selectedProject = day1Project;
  env.GEO.renderProjectHome(day1Project);
  assert.equal(env.nodes.projectHomeDay1Card.hidden, false, 'Day-1 card must show on a fresh Stage-0 project.');
  assert.match(env.nodes.projectHomeDay1Primary.textContent, /前往配置文本模型/);
  env.nodes.projectHomeDay1Primary.click();
  assert.deepEqual(env.actions.at(-1), ['view', 'aikeys'], 'Missing model Day-1 CTA must route to #aikeys.');
}

// 3. Ready via Magpie binding alone: Step 1 done, Step 2 current, Day-1 primary launches Stage-0 skill
{
  const env = mountOnboarding({
    apiImpl: async (path) => {
      if (path === '/api/v1/ai-keys') return { data: [] };
      if (path === '/api/v1/ai-keys/magpie') {
        return {
          targets: [{ alias: 'local', base_url: 'http://127.0.0.1:3425/v1', auth_mode: 'loopback', revision: 'r1' }],
          profiles: [{ id: 7, slot: 'text', target_alias: 'local', base_url: 'http://127.0.0.1:3425/v1', auth_mode: 'loopback', target_revision: 'r1', enabled: true, model: 'fixture-chat' }],
          bindings: { text: { source_type: 'profile', profile_id: 7 } },
        };
      }
      throw new Error(`Unexpected endpoint: ${path}`);
    },
  });
  await env.GEO.ensureModelReadiness();
  assert.equal(env.nodes.onboardingModelBadge.textContent, '文本模型已就绪');
  assert.equal(env.nodes.onboardingStepModel.dataset.stepState, 'done');
  assert.equal(env.nodes.onboardingStepProject.dataset.stepState, 'current');
  assert.equal(env.nodes.onboardingGoModels.classList.contains('md-btn--secondary'), true);

  const day1Project = { id: 2, current_stage: 0, status: 'active', workflow_recommendations: [{ stage_index: 0, completed_skill_ids: [] }] };
  env.state.selectedProject = day1Project;
  env.GEO.renderProjectHome(day1Project);
  assert.equal(env.nodes.projectHomeDay1Card.hidden, false);
  assert.equal(env.nodes.projectHomeDay1Primary.textContent, '运行「意图挖掘」');
  env.nodes.projectHomeDay1Primary.click();
  assert.deepEqual(env.actions.at(-1), ['skill', 'yao-geo-intent-miner']);
  env.nodes.projectHomeDay1Secondary.click();
  assert.deepEqual(env.actions.at(-1), ['analysis']);

  // Once a skill finishes or project moves past stage 0 or publishes an article, Day-1 card hides automatically
  env.GEO.syncDay1Spotlight({ ...day1Project, workflow_recommendations: [{ stage_index: 0, completed_skill_ids: ['yao-geo-intent-miner'] }] });
  assert.equal(env.nodes.projectHomeDay1Card.hidden, true, 'Completed skill must hide Day-1 card.');
  env.GEO.syncDay1Spotlight({ ...day1Project, current_stage: 1 });
  assert.equal(env.nodes.projectHomeDay1Card.hidden, true, 'Stage > 0 must hide Day-1 card.');
  env.nodes.projectHomePublishedValue.textContent = '2';
  env.GEO.syncDay1Spotlight(day1Project);
  assert.equal(env.nodes.projectHomeDay1Card.hidden, true, 'Published articles must hide Day-1 card.');
}

// 4. 403 / non-admin gracefully degrades to unknown without falsely flagging model as missing
{
  const env = mountOnboarding({
    apiImpl: async () => { throw Object.assign(new Error('Forbidden'), { status: 403 }); },
  });
  await env.GEO.ensureModelReadiness();
  assert.equal(env.nodes.onboardingModelBadge.textContent, '先确认文本模型');
  const day1Project = { id: 3, current_stage: 0, status: 'active', workflow_recommendations: [] };
  env.state.selectedProject = day1Project;
  env.GEO.renderProjectHome(day1Project);
  assert.equal(env.nodes.projectHomeDay1Card.hidden, false);
  assert.equal(env.nodes.projectHomeDay1Primary.textContent, '运行「意图挖掘」');
}

console.log('Onboarding UX: 3-step guide, read-only model readiness, Magpie detection and Day-1 spotlight passed.');
