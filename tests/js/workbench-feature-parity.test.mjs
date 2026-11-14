#!/usr/bin/env node
/**
 * 工作台功能补齐 · 前端契约测试（计划 §5「正式工作台功能 UI 补齐」）
 *
 * 背景：一批后端能力（URL 导入 / API Token / 文章分类 / 账户中心概览 / 订阅运营总览）
 * 早就有路由，但正式工作台没有入口 —— 入口只存在于已关闭的 v2 原型里。补入口时最容易
 * 出的问题是：面板加了、加载函数没接上，或者请求打到一个代码里根本不存在的路径
 * （页面永远空白，只有控制台看得到 404）。
 *
 * 这个测试把"前端在用"和"后端确实有"对上：
 *   1. 每个新视图：侧栏入口 + 面板 + 视图 meta + 数据加载接线，四件套齐；
 *   2. 前端打的每个端点都能在 routes/api.php 里找到；
 *   3. 自动化任务：技能动态输入生成 + input_data 真的提交；
 *   4. 新页面不许用 window.prompt / window.confirm（工作台统一走原生模态对话框）。
 *
 * 运行：node tests/js/workbench-feature-parity.test.mjs
 */

import { readFileSync, readdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parentTemplateSource, workbenchTemplateSource } from './helpers/workbench-templates.mjs';
import { readEntrySource } from './helpers/entry-source.mjs';
import { workspaceNavigationSource } from './helpers/workspace-nav-source.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '../..');

let failures = 0;
let checks = 0;

function ok(label, condition, detail = '') {
  checks++;
  if (condition) {
    console.log(`  ✓ ${label}`);
    return true;
  }
  failures++;
  console.log(`  ✗ ${label}${detail ? `\n      ${detail}` : ''}`);
  return false;
}

function stripBladeComments(source) {
  return source.replace(/\{\{--[\s\S]*?--\}\}/g, '');
}

// 面板已拆到 resources/views/workbench/panels/*.blade.php（计划 §7 第 2 步）：
// 侧栏链接还在父模板（blade），"面板内容"类断言必须看**整组模板**（workbench）——
// 否则只看父模板会假红。读取顺序与拆分前一致由 helper 保证。
const blade = stripBladeComments(parentTemplateSource());
const workbench = stripBladeComments(workbenchTemplateSource());
const entry = readEntrySource();
const routes = readFileSync(resolve(ROOT, 'routes/api.php'), 'utf8');
const workspaceUi = readFileSync(resolve(ROOT, 'public/geo-workspace-ui.css'), 'utf8');
const entryCss = readFileSync(resolve(ROOT, 'public/geo-entry.css'), 'utf8');
const tokensCss = readFileSync(resolve(ROOT, 'public/geo-tokens.css'), 'utf8');

console.log('\n工作台功能补齐测试\n');

const navigation = workspaceNavigationSource();
const views = [
  { view: 'url-imports', panel: 'urlImportsPanel', loader: 'loadUrlImports', endpoint: '/api/v1/url-imports' },
  { view: 'categories', panel: 'categoriesPanel', loader: 'loadCategories', endpoint: '/api/v1/categories' },
];

for (const item of views) {
  const links = [...navigation.html.matchAll(new RegExp(`<a\\b[^>]*href="#${item.view}"[^>]*>`, 'g'))].map((m) => m[0]);
  if (item.view === 'categories') {
    const categoryButton = workbench.match(/<button\b[^>]*id="clManageCategoriesButton"[^>]*>/)?.[0] || '';
    ok('categories：侧栏无链接，文章页按钮与点击处理已接入',
      links.length === 0 && Boolean(categoryButton) && !/data-view-target=/.test(categoryButton)
      && /getElementById\("clManageCategoriesButton"\)\?\.addEventListener\("click",[\s\S]*?setActiveWorkspaceView\("categories", \{ updateHash: true \}\)/.test(entry));
  } else {
    ok(`${item.view}：侧栏入口唯一且带 data-view-target`,
      links.length === 1 && /data-view-target=/.test(links[0]), links.join(' | '));
  }
  ok(`${item.view}：面板已挂载`,
    new RegExp(`id="${item.panel}"`).test(workbench)
    && new RegExp(`data-workspace-view="${item.view}"`).test(workbench));
  ok(`${item.view}：视图 meta 已登记`,
    entry.includes(`"${item.view}": {`) || new RegExp(`\\n\\s{4}${item.view}: \\{`).test(entry));
  // 2026-09-21 §7 拆分后，接线是"注册表"形式：主文件按视图取用（window.GEO.runViewLoaders），
  // 加载器由拆出去的文件注册。两段都要在，才算真的接上（只查注册、不查取用，等于面板永远转圈）。
  ok(`${item.view}：载入函数已注册且主文件按视图取用`,
    new RegExp(`registerViewLoader\\("${item.view}", ${item.loader}\\)`).test(entry)
    && /globalThis\.GEO\?\.runViewLoaders\?\.\(view, tasks\)/.test(entry), item.loader);
  ok(`${item.view}：面板 id → 视图名别名已登记`,
    new RegExp(`${item.panel}:\\s*"${item.view}"`).test(entry));
  ok(`${item.view}：端点在后端路由里存在`,
    routes.includes(item.endpoint.replace('/api/v1/', '')));
}

const selfTitledStart = entry.indexOf('const selfTitledViews');
const selfTitled = entry.slice(selfTitledStart, selfTitledStart + 900);
for (const item of views) {
  ok(`${item.view}：在 selfTitledViews 名单里（避免双层标题）`, selfTitled.includes(`"${item.view}"`));
}

ok('自动化：动态输入行已挂载', workbench.includes('id="atDynamicFieldRow"') && workbench.includes('id="atDynamicFields"'));
ok('自动化：按技能 brief_fields 生成输入',
  entry.includes('function atRenderDynamicFields(') && entry.includes('brief_fields'));
ok('自动化：技能下拉 change 时重绘动态输入',
  entry.includes('[name="skill_id"]\')?.addEventListener("change", atRenderDynamicFields)'));
ok('自动化：动态输入收集器存在', entry.includes('function atDynamicInputData('));
ok('自动化：input_data 进了创建 payload', entry.includes('payload.input_data = inputData'));
ok('自动化：空值不提交（避免覆盖技能默认值）',
  entry.includes('Object.keys(data).length ? data : null'));
ok('动态输入用独立 id 命名空间（不与技能面板撞 id）',
  entry.includes('fieldControl(skill, field, "atBrief")'));

// 锚点必须落在那一段代码本体上：状态表与注册表里都有同名注释，先命中的会切出空片段。
// 2026-09-21 §7 拆分后这段代码搬到了 geo-entry-management.js（entry 是主文件 + 拆出块的合集），
// 所以结尾锚点改用该文件自己的注册段注释 —— 它紧跟在被搬走的代码之后，与文件位置无关。
const sectionStart = entry.indexOf('const URL_IMPORT_STATUS = {');
// 结尾锚点必须**从 sectionStart 之后**再找：拆出去的每个文件都带同一句"注册与绑定"注释，
// 直接 indexOf 会命中另一个文件里的那份（文件是按名字排序拼起来的，billing 在 management 前面）。
const sectionEnd = entry.indexOf('// ── 注册与绑定（拆分前这些都在 geo-entry.js 里）', sectionStart);
const newSection = sectionStart < 0 || sectionEnd < sectionStart ? '' : entry.slice(sectionStart, sectionEnd);
ok('新增区块定位成功', newSection.length > 2000, `length=${newSection.length}`);
ok('新页面不使用 window.prompt / window.confirm',
  !/window\.(prompt|confirm)\(/.test(newSection),
  '工作台统一用 confirmWorkspaceAction / promptWorkspaceValue');
ok('删除类动作走确认对话框', newSection.includes('confirmWorkspaceAction('));

const endpoints = [
  ['/api/v1/url-imports', 'url-imports'],
  ['/api/v1/categories', 'categories'],
];
for (const [url, marker] of endpoints) {
  ok(`前端调用 ${url} ↔ 后端路由 ${marker}`, entry.includes(url) && routes.includes(marker));
}
ok('订阅运营：收费功能删除后路由不再注册', !routes.includes('subscription/operations'));

/* ── 6.1 租户侧不得出现凭据的创建/删除入口（2026-09-22 用户口径）──────────────
   用户原话：**租户只做积分展示，没有 key 与令牌的创建和删除，必须隐藏，避免敏感操作。**
   被撤下的是租户界面上的入口，不是后端能力 —— 所以每一条都成对断言：
   "前端没有"**且**"后端还在"（防止顺手把端点也删了，运营侧就没得用）。 */
const tenantCredentialSurfaces = [
  { label: 'API 令牌面板入口', markers: ['data-view-target="api-tokens"', 'id="apiTokensPanel"'] },
  { label: '渠道密钥管理入口', markers: ['id="channelSecretButton"', 'id="channelSecretResult"'] },
  { label: 'Agent 包入口', markers: ['id="channelAgentButton"', 'id="channelAgentPackage"'] },
  { label: 'WP 信标插件入口', markers: ['id="channelBeaconButton"', 'id="channelBeaconResult"'] },
];
for (const surface of tenantCredentialSurfaces) {
  ok(`租户界面不再有${surface.label}`,
    surface.markers.every((marker) => !blade.includes(marker) && !workbench.includes(marker)),
    surface.markers.join(' | '));
}
ok('租户前端源码不再调用凭据端点（令牌 / 渠道密钥 / Agent 包 / 信标插件）',
  !entry.includes("'/api/v1/api-tokens'")
  && !entry.includes("/api/v1/api-tokens/")
  && !entry.includes("/secrets")
  && !entry.includes("/agent-package")
  && !entry.includes("/beacon-plugin")
  && !/api-tokens", loadApiTokens/.test(entry));
ok('后端凭据端点原样保留（撤的是租户入口，不是能力）',
  ['api-tokens', 'publishing/channels/{channel}/secrets', 'agent-package', 'beacon-plugin']
    .every((marker) => routes.includes(marker)));

/* ── 7. 平台运营台：审计日志 + 安全设置 ───────────────────────── */
const opsBlade = readFileSync(resolve(ROOT, 'resources/views/platform-ops.blade.php'), 'utf8');
const opsJs = readFileSync(resolve(ROOT, 'public/geo-platform-ops.js'), 'utf8');

for (const section of ['sec-audit', 'sec-security']) {
  ok(`运营台：${section} 分区在导航里可直达`,
    opsBlade.includes(`href="#${section}"`) && opsBlade.includes(`id="${section}"`));
}
ok('运营台：审计日志面板挂了行容器', opsBlade.includes('id="auditBody"'));
// 2026-09-22：§9.7 起租户侧的敏感动作也写进这张表（行里带 tenant_id）。
// 缺这一列时，平台侧看到 channel_secret.created / api_token.created 根本判断不出归属。
ok('运营台：审计表有「租户」列', opsBlade.includes('<th>租户</th>'));
ok('运营台：审计行渲染租户归属（平台级动作标「平台」）',
  opsJs.includes("租户 #") && opsJs.includes("'<span class=\"cell-sub\">平台</span>'"));
ok('运营台：安全设置表单字段与后端校验范围一致（6–64 / 1–10000）',
  opsBlade.includes('min="6" max="64"') && opsBlade.includes('min="1" max="10000"'));
ok('运营台：调用 /api/v1/platform/audit', opsJs.includes('/api/v1/platform/audit'));
ok('运营台：调用 /api/v1/platform/security-settings（GET + PUT）',
  opsJs.includes("apiGet('/api/v1/platform/security-settings')")
  && opsJs.includes("apiSend('PUT', '/api/v1/platform/security-settings'"));
ok('运营台：两者都装配进 loadAll', /loadAudit\(\);[\s\S]{0,80}loadSecuritySettings\(\);/.test(opsJs));
ok('运营台：敏感词留空时不提交（不误清平台词表）',
  /if \(list\.length\) body\.sensitive_words = list;/.test(opsJs));
ok('运营台：保存成功/失败都带审计编号反馈',
  opsJs.includes('formatAudit(res.json.audit_id)'));
ok('运营台：两个端点在后端路由里存在',
  routes.includes('platform/audit') || routes.includes("'audit'"));
ok('运营台：安全设置路由受 platform:operations 权限组保护',
  /Route::prefix\('v1\/platform'\)[\s\S]{0,400}security-settings/.test(routes));

/* ── 8. 字重与排版作用域（UI 规则 R05 / R08，静态可验的那一半）────
   浏览器 computed style 复核仍待做（本会话没有可用浏览器）。但"新增表单是否落在已迁移
   的字重作用域里"这件事可以静态证明：geo-entry.css 里有一条全局 `label{font-weight:700}`，
   任何没被作用域覆盖的新面板都会重新长出粗黑说明字 —— 这正是用户当初指出的问题。
   所以：所有新增表单面板必须带 .ui-management-panel（或 .billing-panel）这个作用域类。 */

const formPanels = [
  ['urlImportsPanel', 'url-imports'],
  ['categoriesPanel', 'categories'],
  ['automationTasksPanel', 'automation'],
];

for (const [id, view] of formPanels) {
  const section = workbench.match(new RegExp(`<section[^>]*id="${id}"[^>]*>`))?.[0] || '';
  ok(`${view}：面板落在已迁移的字重作用域里（.ui-management-panel / .billing-panel）`,
    /ui-management-panel|billing-panel/.test(section),
    section.slice(0, 160));
}

ok('作用域内 label 是 500（不是继承全局 700）',
  /\.ui-management-panel label \{[^}]*font-weight: var\(--md-weight-medium\)/.test(workspaceUi));
ok('作用域内 input/textarea/select 是 400',
  /\.ui-management-panel :is\(input, textarea, select\) \{[^}]*font-weight: var\(--md-weight-normal\)/.test(workspaceUi));
ok('作用域内 .hint（帮助文字）是 400',
  /\.ui-management-panel :is\(\.hint, \.form-message, \.at-form-error\) \{[^}]*font-weight: var\(--md-weight-normal\)/.test(workspaceUi));
ok('全局 700 的 label 规则仍在（所以作用域覆盖必须一直成立，别以为它已经消失）',
  /label \{[^}]*font-weight: var\(--md-weight-bold\)/s.test(entryCss));
ok('动态技能输入容器在自动化面板内（因而受同一作用域约束）',
  /id="automationTasksPanel"[\s\S]*id="atDynamicFields"/.test(workbench));

// 字号层级（R05）：作用域必须给出页标题与区域标题两档，新面板里的 h2 / h3 才不会退回浏览器默认值。
//
// ⚠️ 2026-09-21 修正：这里原来断言页标题是 20px（var(--md-fs-xl)），但真 Chrome 量出来一直是
// 24px —— 全局排版层 `body.geo-app :is(h1,h2) { font: var(--md-type-page) }` 的特异性更高，
// 那条 20px 规则根本没生效（当时只断言了"规则文本存在"，没断言"它赢"）。
// 24px/600 是全局排版层的页面标题档位，
// 所以口径改成 24px，并要求两条声明**同值**，避免再出现"写了但被层叠吃掉"的死规则。
ok('作用域内页标题 24px/600（与全局排版层同一档）', /\.ui-management-panel :is\(h1, h2\) \{[^}]*font-size: var\(--md-fs-2xl\)[^}]*font-weight: var\(--md-weight-semibold\)/.test(workspaceUi));
ok('页标题档位与全局排版层的 --md-type-page 同值（24px）',
  /--md-type-page:\s*600\s*24px/.test(tokensCss));
ok('作用域内区域标题 16px/600', /\.ui-management-panel :is\(h3, \.panel-head h2, \.col-title\) \{[^}]*font-size: var\(--md-fs-md\)[^}]*font-weight: var\(--md-weight-semibold\)/.test(workspaceUi));

// 宽表局部横滚（R25 的孪生要求：应用侧也不许整页横滚）：新页的表格必须各自套滚动容器。
// （API 令牌页 2026-09-22 已撤出租户工作台，见 §6.1 的凭据红线。）
const scrolledTables = ['urlImportRows', 'categoryRows'];
for (const tbody of scrolledTables) {
  const table = workbench.match(new RegExp(`<div class="cl-tbl-scroll">[\\s\\S]{0,400}id="${tbody}"`));
  ok(`${tbody} 的表格套在 .cl-tbl-scroll 里（局部横滚，不挤整页）`, table !== null);
}

// 按钮档位（R17/R18）：新面板只用既有三档，不引入第四种按钮样式。
const newPanelBlocks = ['urlImportsPanel', 'categoriesPanel']
  .map((id) => {
    const start = workbench.indexOf(`id="${id}"`);
    return start < 0 ? '' : workbench.slice(start, workbench.indexOf('</section>', start));
  });
const buttonClasses = new Set();
for (const block of newPanelBlocks) {
  for (const match of block.matchAll(/<button[^>]*class="([^"]+)"/g)) {
    match[1].split(/\s+/).forEach((cls) => buttonClasses.add(cls));
  }
}
const allowedButtonClasses = new Set(['button', 'primary', 'secondary', 'ghost', 'small', 'danger']);
const unexpected = [...buttonClasses].filter((cls) => !allowedButtonClasses.has(cls));
ok('新三页的按钮只用既有档位（button/primary/secondary/ghost/small/danger）',
  unexpected.length === 0, unexpected.join(', '));

/* ── 9. 响应式卫生（计划 §5.1 要求覆盖到 390px；浏览器复核不可用时的静态代理）──
   浏览器实测仍待做，但"新增样式是不是流体"可以静态证明：固定列数 / 固定像素宽
   在 390px 窄屏上必然溢出，而 auto-fit + minmax 的网格会自己降到单列。 */
const newCssStart = entryCss.indexOf('2026-09-21 工作台功能补齐');
const newCss = newCssStart < 0 ? '' : entryCss.slice(newCssStart);
ok('新增样式块定位成功', newCss.length > 500, `len=${newCss.length}`);
ok('新网格用 auto-fit + minmax（窄屏自动降列，不是固定列数）',
  /grid-template-columns:\s*repeat\(auto-fit,\s*minmax\(/.test(newCss));
ok('新网格的列宽下限 ≤ 200px（390px 视口下仍能排下，不靠横向溢出）',
  [...newCss.matchAll(/minmax\((\d+)px,/g)].every((match) => Number(match[1]) <= 200),
  [...newCss.matchAll(/minmax\((\d+)px,/g)].map((match) => match[1]).join(', '));
ok('新增样式里没有固定像素宽（width: NNNpx 那种会撑破窄屏）',
  !/(?:^|[^-\w])width:\s*\d{3,}px/.test(newCss),
  (newCss.match(/(?:^|[^-\w])width:\s*\d{3,}px/g) || []).join(', '));

console.log('');
if (failures === 0) {
  console.log(`✓ 全部通过：${checks} 项断言\n`);
  process.exit(0);
}
console.log(`✗ ${failures} 项失败（共 ${checks} 项）\n`);
process.exit(1);
