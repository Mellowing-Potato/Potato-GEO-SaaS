#!/usr/bin/env node
/** 解释型文章表单、质量提示与扣点友好行为回归。 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { readEntrySource } from './helpers/entry-source.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '../..');
const entry = readEntrySource();
const registry = readFileSync(resolve(ROOT, 'app/Services/SkillAgent/SkillRegistryService.php'), 'utf8');
const renderer = readFileSync(resolve(ROOT, 'skill-overlays/yao-geo-explainer-builder/scripts/render_yao_geo_explainer_builder.py'), 'utf8');

let checks = 0;
let failures = 0;
function assert(label, condition) {
  checks++;
  console.log(`  ${condition ? '✓' : '✗'} ${label}`);
  if (!condition) failures++;
}
function extractFunction(source, name) {
  const start = source.indexOf(`function ${name}(`);
  if (start < 0) throw new Error(`找不到 ${name}`);
  const bodyStart = source.indexOf('{', source.indexOf(')', start));
  let depth = 0;
  for (let i = bodyStart; i < source.length; i++) {
    if (source[i] === '{') depth++;
    else if (source[i] === '}' && --depth === 0) return source.slice(start, i + 1);
  }
  throw new Error(`${name} 花括号未闭合`);
}

console.log('\n解释型文章 · 输入与质量提示\n');

const state = {
  selectedProject: {
    name: '孟德智达',
    brand_name: '孟德智达',
    settings: { goals: null, product_description: null, target_keywords: [] },
  },
};
const defaultBriefValue = new Function(
  'state',
  `${extractFunction(entry, 'defaultBriefValue')}; return defaultBriefValue;`,
)(state);
const explainer = { id: 'yao-geo-explainer-builder' };
assert('解释型文章不再把品牌名静默当主题', defaultBriefValue('topic', explainer) === '');
assert('其他技能仍可沿用品牌名作为主题兜底', defaultBriefValue('topic', { id: 'other' }) === '孟德智达');

const briefFieldHint = new Function(`${extractFunction(entry, 'briefFieldHint')}; return briefFieldHint;`)();
assert('主题提示要求聚焦一个真实问题', briefFieldHint('topic', explainer).includes('一个行业或场景'));
assert('关键词提示明确宽泛词的问题', briefFieldHint('target_keyword', explainer).includes('范围太宽'));

const escapeHtml = (value) => String(value);
const skillQualityWarning = new Function(
  'escapeHtml',
  `${extractFunction(entry, 'skillQualityWarning')}; return skillQualityWarning;`,
)(escapeHtml);
assert('质量门通过时不打扰用户', skillQualityWarning({ quality_report: { passed: true } }) === '');
assert('质量门未过时显示具体中文原因', skillQualityWarning({
  quality_report: { passed: false, checks: [{ name: 'article_body_complete_length', passed: false }] },
}).includes('可发布正文长度不足'));
assert('Word 长链接排版失败显示中文原因', skillQualityWarning({
  quality_report: { passed: false, checks: [{ name: 'docx_long_tokens_wrapped', passed: false }] },
}).includes('Word 正文中的长链接尚未正确换行'));
const skillNextActionPanel = new Function(
  'escapeHtml', 'isExecutionInFlight',
  `${extractFunction(entry, 'skillNextActionPanel')}; return skillNextActionPanel;`,
)(escapeHtml, () => false);
const renderRecoveryPanel = skillNextActionPanel({ status: 'failed', can_rerender: true });
assert('排版失败提供复用内容的无新增扣点入口', renderRecoveryPanel.includes('data-skill-rerender')
  && renderRecoveryPanel.includes('不调用模型，复用已保存内容')
  && !renderRecoveryPanel.includes('重新执行'));

assert('所有字段标签显式标注必填或可选', entry.includes('field.required ? `${label}（必填）` : `${label}（可选）`'));
assert('技能名称改成用户能理解的问题/搜索词', registry.includes("'文章要解释的问题或主题'") && registry.includes("'希望覆盖的搜索词'"));
assert('不再把未经在线核验的来源称为确定引用', registry.includes('附研究参考与待核验项'));
assert('需要质量或事实复核时不自动引导进入下一阶段', entry.includes('const nextStage = requiresReview ? null : nextStageAfterExecution(execution)'));
assert('字体包使用镜像中实际安装的字体兜底', renderer.includes('/usr/share/fonts/noto/NotoSerifCJK-Regular.ttc'));
assert('未完成最终正文质检时显示可理解的原因', skillQualityWarning({
  quality_report: { passed: false, checks: [{ name: 'publishable_article_matches_review', passed: false }] },
}).includes('最终正文尚未完成对应的质量检查'));

assert('意图挖掘旧质量字段显示中文并带实际数量', skillQualityWarning({
  quality_report: {
    passed: false,
    checks: [
      { name: 'question bank at least 18', passed: false },
      { name: 'follow up chains at least 8', passed: false },
    ],
    metrics: { section_count: 13, question_bank_count: 12, follow_up_chain_count: 4 },
    missing_required_sections: ['研究依据', '事实校准'],
  },
}).includes('问题清单少于 18 条') && skillQualityWarning({
  quality_report: {
    passed: false,
    checks: [{ name: 'follow up chains at least 8', passed: false }],
    metrics: { section_count: 13, question_bank_count: 12, follow_up_chain_count: 4 },
  },
}).includes('当前生成 13 章、12 条问题、4 条追问链'));
assert('后端已翻译的检查名在失败提示里改写成未达标原因', skillQualityWarning({
  quality_report: {
    passed: false,
    checks: [{ name: '问题清单至少 18 条', passed: false }],
  },
}).includes('问题清单少于 18 条'));

const skillExecutionStatusLabel = new Function(
  'statusLabel',
  `${extractFunction(entry, 'skillExecutionStatusLabel')}; return skillExecutionStatusLabel;`,
)((status) => status === 'failed' ? '失败' : status);
assert('已有文件的质量失败显示为编辑稿待复核', skillExecutionStatusLabel({
  status: 'failed',
  available_formats: ['markdown'],
  quality_report: { passed: false },
}) === '已生成编辑稿（质量未通过）');
assert('结构通过但证据未齐时显示待事实复核', skillExecutionStatusLabel({
  status: 'completed',
  available_formats: ['markdown'],
  quality_report: { passed: true, publication_ready: false },
}) === '已生成编辑稿（待事实复核）');
assert('模型用量已登记但业务未交付时不显示为已完成', skillExecutionStatusLabel({
  status: 'billing_pending', billing: { recovery_stage: 'model_result_recorded' },
}) === '交付待恢复');
assert('本地用量尚未登记时显示待核验', skillExecutionStatusLabel({
  status: 'billing_pending', billing: { recovery_stage: 'model_result_unrecorded' },
}) === '用量待核验');
const skillExecutionBillingLine = new Function(
  'formatNumber', 'escapeHtml',
  `${extractFunction(entry, 'skillExecutionBillingLine')}; return skillExecutionBillingLine;`,
)(String, String);
assert('已记录结果等待交付时仍明确显示待恢复', skillExecutionBillingLine({
  status: 'billing_pending', token_usage: 30,
  billing: { recovery_stage: 'model_result_recorded', model_call_count: 1 },
}).includes('正在恢复交付，不会重新调用模型'));
assert('本地用量尚未记录时不声称执行成功', skillExecutionBillingLine({
  status: 'billing_pending', billing: { recovery_stage: 'model_result_unrecorded', model_call_count: 0 },
}).includes('本地用量待恢复；报告尚未交付'));
assert('结构通过但证据未齐时给出发布前复核提示', skillQualityWarning({
  quality_report: {
    passed: true,
    publication_ready: false,
    fact_review_items: ['景区开放时间', '活动日程'],
  },
}).includes('还有 2 项事实或来源需要确认'));

const isArticleContentAsset = new Function(
  'isStructuredResultAsset',
  'articleDisplayTitle',
  `${extractFunction(entry, 'isArticleContentAsset')}; return isArticleContentAsset;`,
)(() => false, (article) => article.title || '');
const clIsArticle = new Function(
  'isStructuredResultAsset',
  'isArticleContentAsset',
  `${extractFunction(entry, 'clIsArticle')}; return clIsArticle;`,
)(() => false, isArticleContentAsset);
assert('通用类型的意图挖掘技能结果归入历史报告', clIsArticle({
  source_type: 'skill_execution',
  source_skill: 'yao-geo-intent-miner',
  content_type: 'general',
  body_markdown: '# 意图报告\n\n## 发现',
}) === false);
assert('带章节的全景诊断技能结果仍归入历史报告', clIsArticle({
  source_type: 'skill_execution',
  source_skill: 'yao-geo-panorama-audit',
  content_type: 'general',
  body_markdown: '# 诊断报告\n\n## 发现',
}) === false);
assert('内容生产技能结果仍归入文章库', clIsArticle({
  source_type: 'skill_execution',
  source_skill: 'yao-geo-explainer-builder',
  content_type: 'explainer',
  body_markdown: '# 科普文章\n\n## 正文',
}) === true);
assert('手动创建的通用文章仍归入文章库', clIsArticle({
  source_type: 'draft',
  content_type: 'general',
  body_markdown: '# 手工稿\n\n## 正文',
}) === true);

const clTabMatches = new Function(`${extractFunction(entry, 'clTabMatches')}; return clTabMatches;`)();
assert('新生成的 editing 编辑稿只归入草稿',
  clTabMatches({ status: 'editing', reviewed_at: null }, 'draft') === true
  && clTabMatches({ status: 'editing', reviewed_at: null }, 'editing') === false);
assert('审核退回的 editing 文章才归入待修改',
  clTabMatches({ status: 'editing', reviewed_at: '2026-09-09T12:00:00Z' }, 'editing') === true
  && clTabMatches({ status: 'editing', reviewed_at: '2026-09-09T12:00:00Z' }, 'draft') === false);

const clRowActions = new Function(`${extractFunction(entry, 'clRowActions')}; return clRowActions;`)();
assert('新生成编辑稿提供编辑文章和提交审核入口', (() => {
  const actions = clRowActions({ id: 49, status: 'editing', reviewed_at: null });
  return actions.includes('data-action="edit"') && actions.includes('编辑文章')
    && actions.includes('data-action="submit"') && !actions.includes('看退回意见');
})());
assert('审核退回稿显示退回意见并提供编辑入口', (() => {
  const actions = clRowActions({ id: 50, status: 'editing', reviewed_at: '2026-09-09T12:00:00Z' });
  return actions.includes('看退回意见') && actions.includes('data-action="edit"');
})());

const clPriorityAttr = new Function(`${extractFunction(entry, 'clPriorityAttr')}; return clPriorityAttr;`)();
assert('新生成编辑稿不显示审核退回的红色优先级', clPriorityAttr({ status: 'editing', reviewed_at: null }) === '');

console.log(`\n${checks - failures}/${checks} 项通过`);
if (failures) process.exit(1);
