import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { readEntrySource } from './helpers/entry-source.mjs';
const source = readEntrySource();
function fn(name) {
  const start = source.search(new RegExp(`  (?:async )?function ${name}\\(`));
  assert.ok(start >= 0, name);
  return source.slice(start, source.indexOf('\n  }', start) + 4);
}
function compile(name, deps) {
  return new Function(...Object.keys(deps), `${fn(name)}; return ${name};`)(...Object.values(deps));
}
const state = { selectedProject: { id: 9 }, skillStage: null, skillContext: 'strategy', skills: [
  { id: 'research', stage_index: 0 },
  { id: 'explainer', stage_index: 2, category: 'geo-content-production' },
  { id: 'yao-geo-panorama-audit', stage_index: 0 },
] };
const isContentProductionSkill = compile('isContentProductionSkill', {});
const effectiveSkillStage = compile('effectiveSkillStage', { state });
const visibleSkills = compile('visibleSkills', { state, effectiveSkillStage, isContentProductionSkill });
assert.deepEqual(visibleSkills().map(s => s.id), ['research', 'explainer']);
state.skillStage = '2';
assert.deepEqual(visibleSkills().map(s => s.id), ['explainer']);
state.skillContext = 'content'; state.skillStage = 'all';
assert.deepEqual(visibleSkills().map(s => s.id), ['explainer']);

const reportReferenceText = compile('reportReferenceText', { state, isContentProductionSkill,
  GEO: { resultReportData: a => a.structured },
  articleDisplayTitle: a => a.title,
});
state.skillReportReference = { id: 12, project_id: 9, title: '诊断', structured: {
  question_bank: [{question_id:'Q003', question:'言及是做什么的？', intent_type:'解释型', evidence_query:'官网介绍'}],
  limitations:'先核实来源', ai_sampling_plan:[{platform:'豆包', status:'计划'}],
} };
state.skillReportQuestion = state.skillReportReference.structured.question_bank[0];
const reference = reportReferenceText(state.skills[1]);
assert.ok(reference.includes('先核实来源') && reference.includes('言及是做什么的？') && reference.includes('编号 12'));
assert.ok(reference.includes('Q003') && reference.includes('尚未采样') && !reference.includes('<!-- chunk:'));
assert.equal(reportReferenceText(state.skills[0]), '');
state.selectedProject.id = 10;
assert.equal(reportReferenceText(state.skills[1]), '');
state.selectedProject.id = 9;
state.selectedSkill = state.skills[1]; state.skillMaterialRefs = [];
const payload = compile('skillExecutionPayload', { state, reportReferenceText,
  skillFieldsPayload: () => ({topic: '游客的问题'}),
  FormData: class { constructor(form) { this.form = form; } get(name) { return this.form[name]; } },
})({ query: '请保持审慎语气' });
assert.equal(payload.query, '请保持审慎语气');
assert.equal(payload.fields.report_id, 12);
assert.equal(payload.fields.report_question_id, 'Q003');
assert.ok(payload.fields.report_reference.includes('言及是做什么的？'));

state.clSearch = ''; state.clActiveTab = 'all';
const report = { id: 1, content_type: 'report', status: 'approved' };
const article = { id: 2, content_type: 'explainer', status: 'approved' };
const tabs = ['all','approved','recycled'].map(key => ({dataset: {tab:key}, count:{}, querySelector() {return this.count;} }));
const elements = { contentLibraryPanel: {}, clTabs: { querySelectorAll: () => tabs } };
const clTabMatches = compile('clTabMatches', {});
const clUpdateTabCounts = compile('clUpdateTabCounts', { state, elements, clTabMatches });
let truncated = false;
const load = compile('loadContentLibrary', {state, elements, contentLibraryRequest:0, clSetState:()=>{},
  clFetchAll: async ({status}) => ({rows: status ? [{...report,status}, {...article,status}] : [report,article], total:200, truncated}),
  clIsArticle: a => a.content_type === 'explainer', clUpdateTabCounts,
  clRenderTruncNote:()=>{}, renderContentLibrary:()=>{}, fillErrorNo: e=>{throw e;},
});
await load();
assert.equal(state.contentLibrary.length, 1);
assert.deepEqual(tabs.map(t=>t.count.textContent), ['1','1','1']);
truncated = true; state.clActiveTab = 'recycled';
await load();
assert.equal(state.contentLibrary[0].status, 'recycled');
assert.deepEqual(tabs.map(t=>t.count.textContent), ['1+','1+','1+']);
console.log('Project return flow: visible tools, report context payload, project isolation and article/recycle counts passed.');

const matchingSkillExecution = compile('matchingSkillExecution', {});
const executions = [{id:9,skill_id:'intent'}, {id:6,skill_id:'explainer'}, {id:8,skill_id:'explainer'}];
assert.equal(matchingSkillExecution(executions, {id:'explainer'}, 9).id, 8);
assert.equal(matchingSkillExecution(executions, {id:'explainer'}, 6).id, 6);
assert.equal(matchingSkillExecution(executions, {id:'ranking'}, 9), undefined);
const savedEntry = compile('generatedArticlesEntry', {isContentProductionSkill});
assert.ok(savedEntry({id:'yao-geo-explainer-builder',category:'geo-content-production'}).includes('data-open-existing-articles="explainer"'));
assert.equal(savedEntry({id:'intent'}), '');
let requestUrl;
await compile('clFetchArticlesRaw', {state, URLSearchParams, api:async url => {requestUrl=url;}})({contentType:'explainer',projectId:9,status:'recycled'});
assert.ok(requestUrl.includes('/projects/9/articles?'));
assert.equal(new URL(requestUrl,'http://local').searchParams.get('content_type'), 'explainer');
assert.equal(new URL(requestUrl,'http://local').searchParams.get('status'), 'recycled');
console.log('Saved-article entry filters the server request; selected tools only show their own executions.');
