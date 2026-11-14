import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = readFileSync(new URL('../../public/geo-entry-reports.js', import.meta.url), 'utf8');
const start = source.indexOf('  function isCompactTitleReport(');
const end = source.indexOf('  function renderStructuredReportHtml(', start);
assert.ok(start > 0 && end > start);
const escape = (value) => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
let table;
const { render, isCompact } = new Function('escapeHtml', 'readableText', 'renderTableObject', source.slice(start, end) + ';return { render: renderCompactTitleReport, isCompact: isCompactTitleReport };')(
  escape, (value) => String(value ?? ''), (value) => { table = value; return '<table></table>'; },
);
const scores = { intent_match: 4, entity_clarity: 4, differentiation: 4, citation_potential: 4, compliance: 4, freshness: 4 };
const data = {
  report_title: '标题测试',
  title_candidates: Array.from({ length: 5 }, (_, index) => ({
    id: `T${index + 1}`, title: index === 2 ? '<script>bad</script> 库存阈值' : `库存标题${index + 1}`,
    intent: '操作指南', why_it_works: '具体', scores,
  })),
  recommended_id: 'T3', recommendation: '与正文一致', usage_advice: ['核对内容'], compliance_checks: null,
};
const result = render(data);
assert.ok(isCompact(data));
assert.ok(!isCompact({ ...data, recommended_id: undefined }));
assert.ok(!isCompact({ ...data, title_candidates: [{ title: '旧报告标题' }] }));
assert.equal((result.match(/<h4>/g) || []).length, 3);
assert.deepEqual(table.headers, ['标题', '适用意图', '建议评分', '理由']);
assert.equal(table.rows.length, 5);
assert.equal(table.rows[2][0], 'T3 · <script>bad</script> 库存阈值');
assert.ok(result.includes('T3 · &lt;script&gt;bad&lt;/script&gt; 库存阈值：与正文一致'));
assert.ok(!result.includes('<script>'));
assert.ok(result.includes('尚未经过实际点击率或排名验证'));
assert.ok(source.includes('if (isCompactTitleReport(parsed))'));
console.log('Compact title report: stable T3 recommendation, five candidates, escaped HTML passed');
