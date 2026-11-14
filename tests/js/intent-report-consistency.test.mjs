import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const reports = readFileSync(new URL('../../public/geo-entry-reports.js', import.meta.url), 'utf8');
const skills = readFileSync(new URL('../../public/geo-entry-skills.js', import.meta.url), 'utf8');
const extract = (source, name) => {
  const start = source.indexOf(`  function ${name}(`);
  assert(start >= 0, name);
  return source.slice(start, source.indexOf('\n  }', start) + 4);
};
const { intentReportFacts, normalizeIntentReport } = new Function(
  `${extract(reports, 'intentReportFacts')}\n${extract(reports, 'normalizeIntentReport')}\nreturn { intentReportFacts, normalizeIntentReport };`,
)();
const raw = {
  analysis_date: '2026-05-18',
  summary: '共 42 条问题，P0 18 条。研究覆盖豆包。',
  summary_cards: [{label:'问题总数', value:'42 条'}, {label:'P0 优先意图', value:'18 条'}],
  sections: [{title:'执行摘要', paragraphs:['共 42 条问题，P0 18 条。']}],
  question_bank: [
    {question_id:'Q001', question:'问题一', priority:'P1'},
    {question_id:'Q003', question:'言及是做什么的？', priority:'P0'},
    {question_id:'Q004', question:'问题三', priority:'P1'},
  ],
};
const fixed = normalizeIntentReport(raw);
assert.deepEqual(intentReportFacts(fixed), { verified:true, total:3, p0:1 });
assert.equal(fixed.summary_cards[0].value, '3 条');
assert.equal(fixed.summary_cards[1].value, '1 条');
assert.equal(normalizeIntentReport({ ...raw, summary_cards: [{ label: '意图问题库规模', value: '42+' }] }).summary_cards[0].value, '3 条');
assert(fixed.summary.includes('共 3 条问题') && fixed.summary.includes('计划监测'));
assert(fixed.sections[0].paragraphs[0].includes('P0 1 条'));
assert.equal(fixed.analysis_date, '');
assert.equal(raw.summary_cards[0].value, '42 条');
assert.equal(raw.analysis_date, '2026-05-18');
assert.deepEqual(intentReportFacts({question_bank:[{priority:'P1'}]}), { verified:true, total:1, p0:0 });
assert.deepEqual(intentReportFacts({question_bank:[]}), { verified:true, total:0, p0:0 });
assert.deepEqual(intentReportFacts({question_bank:'broken'}), { verified:false, total:null, p0:null });
const pending = normalizeIntentReport({ ...raw, question_bank:'broken' });
assert.equal(pending.summary_cards[0].value, '待校验');
assert(!pending.summary.includes('42 条') && !pending.summary.includes('18 条'));

const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
const reader = value => typeof value === 'string' ? value : String(value ?? '');
const renderFocusedRows = new Function('state', 'escapeHtml', 'readableText', 'fieldLabel', 'reportColumnKind',
  `${extract(reports, 'renderFocusedRows')}; return renderFocusedRows;`)(
    {resultArticle:{id:56}}, escapeHtml, reader, key => key,
    () => 'question',
  );
const rows = renderFocusedRows('question_bank', raw.question_bank);
assert(rows.includes('data-question-id="Q003"'));
assert(rows.includes('data-report-total="3"'));
assert(rows.includes('以此问题写作'));

const continuation = new Function('GEO', 'clIsArticle', 'escapeHtml',
  `${extract(skills, 'reportContinuationMarkup')}; return reportContinuationMarkup;`)(
    {resultReportData: () => fixed}, () => false, escapeHtml,
  )({id:56, content_type:'report'});
assert(continuation.includes('data-report-question-select'));
assert(continuation.includes('言及是做什么的？'));
const selectedReport = { id: 56, project_id: 15, title: '言及意图报告' };
const selectedState = {
  selectedProject: { id: 15, name: '言及', brand_url: 'https://example.test' },
  skillReportReference: selectedReport,
  skillReportQuestion: {
    question_id: 'Q003', question: '言及是做什么的？', intent_type: '品牌认知',
    evidence_query: '官网产品说明',
  },
};
const referenceFor = data => new Function('state', 'GEO', 'articleDisplayTitle', 'isContentProductionSkill',
  `${extract(skills, 'reportReferenceText')}; return reportReferenceText;`)(
    selectedState, { resultReportData: () => data }, report => report.title, () => true,
  )({ id: 'yao-geo-explainer-builder' });
const reference = referenceFor({ ...fixed,
  evidence_sources: [{ title: '官网首页', url: 'https://example.test' }],
  ai_sampling_plan: [{ platform: '豆包' }],
});
assert(reference.includes('编号 56'));
assert(reference.includes('Q003 · 言及是做什么的？'));
assert(reference.includes('目标意图：品牌认知'));
assert(reference.includes('官网首页 · https://example.test'));
assert(reference.includes('M0 尚未采样'));
assert(!reference.includes('<!-- chunk:'));
const truncated = referenceFor({ ...fixed, evidence_sources: [{ title: '来源', note: '😀'.repeat(4000) }] });
assert(truncated.includes('[参考材料已按完整字段截断；完整内容请查看原报告]'));
assert(!truncated.includes('😀'));
console.log('Intent report: complete-list totals, historical correction, zero/pending cases and question writing entry passed.');
