import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { readEntrySource } from './helpers/entry-source.mjs';

const source = readEntrySource();
const extract = name => {
  const start = source.search(new RegExp(`  (?:async )?function ${name}\\(`));
  assert(start >= 0, name);
  return source.slice(start, source.indexOf('\n  }', start) + 4);
};
const escapeHtml = value => String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');
const { fieldLabel, renderTableObject, render2DArraySection, publicationResultLink, clPublicationMarkup } = new Function('escapeHtml', 'readableText', 'window',
  ['fieldLabel', 'reportColumnKind', 'renderTableObject', 'render2DArraySection', 'publicationResultLink', 'clPublicationMarkup'].map(extract).join('\n')
  + '\nreturn {fieldLabel,renderTableObject,render2DArraySection,publicationResultLink,clPublicationMarkup};'
)(escapeHtml, String, { location: { origin: 'https://app.example.test' } });

// All registered intent-report column names must be translated, not just the two screenshots.
const registry = readFileSync(new URL('../../app/Services/SkillAgent/RenderSchemaRegistry.php', import.meta.url), 'utf8');
const fields = registry.slice(registry.indexOf('private function itemFields('), registry.indexOf('private function normalizeItemShapes('));
const columnLists = [...fields.matchAll(/=> \[([^\]]+)\]/g)];
assert.equal(columnLists.length, 13);
for (const [, list] of columnLists) {
  for (const [, key] of list.matchAll(/'([^']+)'/g)) assert(/[\u4e00-\u9fff]/u.test(fieldLabel(key)), `Untranslated report field: ${key}`);
}
assert.equal(fieldLabel('business value'), '商业价值');
assert.equal(fieldLabel('AI Answer Probability'), 'AI 答案触发概率');
assert.equal(fieldLabel('root_question_id'), '起始问题编号');
assert.equal(fieldLabel('DeepSeek'), 'DeepSeek');
assert.equal(fieldLabel('李白故里'), '李白故里');
assert.equal(fieldLabel('constructor'), 'constructor');
const table = renderTableObject({ headers: ['chain id', 'follow_up_question', 'DeepSeek'], rows: [['C001', 'English project question', '<source>']] });
assert(table.includes('>追问链编号</th>') && table.includes('>追问问题</th>') && table.includes('>DeepSeek</th>'));
assert(table.includes('>English project question</td>') && table.includes('&lt;source&gt;'));
assert(render2DArraySection('scoring_matrix', '评分矩阵', [[5]], ['business_value']).includes('<th>商业价值</th>'));

const published = { id: 1, status: 'published', remote_url: 'https://blog.example.test/article-49', channel: { name: '客户站', type: 'wordpress_rest' } };
const link = publicationResultLink(published);
assert(link.includes('href="https://blog.example.test/article-49"') && link.includes('查看发布页面') && link.includes('rel="noopener noreferrer"'));
assert.equal(publicationResultLink({ ...published, remote_url: '/read/demo/article-49', channel: { type: 'hosted_page' } }), '');
assert(publicationResultLink({ ...published, remote_url: 'https://external.example.test/post?id=1&x=2' }).includes('&amp;x=2'));
for (const remote_url of ['javascript:alert(1)', 'data:text/html,test', 'https://user:fixture-user-21@example.com', 'http://[', '']) assert.equal(publicationResultLink({ ...published, remote_url }), '');
assert.equal(publicationResultLink({ ...published, status: 'failed' }), '');
assert(clPublicationMarkup(published).includes('查看发布页面'));
assert(!clPublicationMarkup({ ...published, channel: { type: 'hosted_page' } }).includes('查看发布页面'));
assert(clPublicationMarkup({ id: 2, status: 'takedown', channel_name: '历史站内渠道' }).includes('已撤下'));
console.log('Report column translations, preserved content, safe publication links and drawer rendering passed.');
