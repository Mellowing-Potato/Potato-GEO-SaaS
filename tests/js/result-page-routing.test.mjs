#!/usr/bin/env node
/**
 * 独立结果页 · 带参路由与报告分区 · 前端回归测试
 *
 * 背景（执行入口 / 独立结果页重构 · 批 B，2026-09-05）
 * ────────────────────────────────────────────────
 * 工作台原来把「执行入口」和「执行结果」压在同一个面板里，报告一长页面就无限延长。
 * 重构把结果搬到独立的一页：`#result/<报告编号>`，页内再拆成 概览 / 问题清单 / 完整报告。
 *
 * 这份测试守三件事（都是踩过或差点踩到的坑）：
 *
 *   1. 🔴 路由参数不能在「视图被折回默认视图」时留在身上。
 *      #bogus/41 必须干干净净落在项目管理页，而不是把 41 带过去。
 *
 *   2. 🔴 分区表必须是**白名单加法**：没登记的字段一律归「完整报告」。
 *      反过来写（必须登记才显示）的话，新技能加个新字段就会在界面上凭空消失，
 *      而且不报错、没人会发现。
 *
 *   3. 🔴 渲染上下文字段（table_headers / section_meanings）每个分区切片都得带上。
 *      漏了的话，「概览」和「问题清单」两页的表格会退回猜表头 ——
 *      正是 EXEC-07 F1 修掉的那个病，不能从分区这条新路再长回来。
 *
 * 另外静态守：结果页面板存在、不在侧栏（它是详情页不是一级菜单）、
 * 且在 selfTitledViews 名单里（页头就是报告标题，共享 titlebar 会变成第二个标题）。
 *
 * 运行
 * ────
 *   node tests/js/result-page-routing.test.mjs
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
// 面板已拆到 workbench/panels/*（计划 §7 第 2 步），结果页面板在分部里 —— 读整组模板。
import { workbenchTemplateSource } from './helpers/workbench-templates.mjs';
import { readEntrySource } from './helpers/entry-source.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '../..');

const ENTRY_JS = 'public/geo-entry.js';
const BLADE = 'resources/views/welcome.blade.php';

const source = readEntrySource();
const blade = workbenchTemplateSource();

let failures = 0;
let checks = 0;

function assert(label, cond) {
    checks++;
    if (!cond) {
        failures++;
        console.log(`  ✗ ${label}`);
        return false;
    }
    console.log(`  ✓ ${label}`);
    return true;
}

function assertEq(label, actual, expected) {
    const ok = JSON.stringify(actual) === JSON.stringify(expected);
    checks++;
    if (!ok) {
        failures++;
        console.log(`  ✗ ${label}\n      实际: ${JSON.stringify(actual)}\n      期望: ${JSON.stringify(expected)}`);
        return false;
    }
    console.log(`  ✓ ${label}`);
    return true;
}

/** 剥 Blade 注释：注释里写了 data-workspace-view、data-view-target 等词，不剥会让断言恒真。 */
function stripBladeComments(src) {
    return src.replace(/\{\{--[\s\S]*?--\}\}/g, '');
}

/** 与 platform-ops-sensitive-words.test.mjs 同款：按花括号配对抠函数，不抄实现自测自话。 */
function extractFunction(src, name) {
    const marker = `function ${name}(`;
    const start = src.indexOf(marker);
    if (start === -1) throw new Error(`源码里找不到 \`${marker}\``);
    const bodyStart = src.indexOf('{', src.indexOf(')', start));
    if (bodyStart === -1) throw new Error(`\`function ${name}\` 缺少函数体`);
    let depth = 0;
    for (let i = bodyStart; i < src.length; i++) {
        if (src[i] === '{') depth++;
        else if (src[i] === '}' && --depth === 0) return src.slice(start, i + 1);
    }
    throw new Error(`\`function ${name}\` 花括号未配对`);
}

/** 抠 `const NAME = <字面量>;`，按括号配对找结尾，同样是取真源码而不是抄一份。 */
function extractConst(src, name) {
    const marker = `const ${name} = `;
    const start = src.indexOf(marker);
    if (start === -1) throw new Error(`源码里找不到 \`${marker}\``);
    const open = { '{': '}', '[': ']', '(': ')' };
    // ⚠️ 标量常量（`const OPEN_SECTION_COUNT = 6;`）里一个括号都没有。
    //    不先在本行找分号的话，下面那个「往后找第一个括号」会一路跑进**下一个**常量，
    //    把两个声明一起抠出来 —— 症状是 new Function 报「已声明过」，一眼看不出原因。
    const semi = src.indexOf(';', start);
    const bracketAt = (() => {
        for (let j = start + marker.length; j < src.length; j++) if (open[src[j]]) return j;
        return -1;
    })();
    if (bracketAt === -1 || (semi !== -1 && semi < bracketAt)) {
        if (semi === -1) throw new Error(`\`${name}\` 既不是括号字面量也没有分号收尾`);
        return src.slice(start, semi + 1);
    }
    // `new Set([...])` 这种前面还有个构造调用，从第一个括号开始配对。
    let i = bracketAt;
    const first = src[i];
    const close = open[first];
    let depth = 0;
    for (; i < src.length; i++) {
        if (src[i] === first) depth++;
        else if (src[i] === close && --depth === 0) return `${src.slice(start, i + 1)};`;
    }
    throw new Error(`\`${name}\` 括号未配对`);
}

// ── 把真源码里的那几段搬进本进程执行 ───────────────────────────
const pieces = [
    extractConst(source, 'REPORT_SKIP_KEYS'),
    extractConst(source, 'REPORT_ZONE_OF'),
    extractConst(source, 'REPORT_ZONE_CARRY'),
    extractConst(source, 'REPORT_ZONE_CHROME_KEYS'),
    extractConst(source, 'OPEN_SECTION_COUNT'),
    extractConst(source, 'REPORT_READING_ORDER'),
    extractConst(source, 'RESULT_ZONES'),   // ⚠️ 必须排在 REPORT_READING_ORDER 之后，它引用了那份顺序
    extractFunction(source, 'parseWorkspaceHash'),
    extractFunction(source, 'reportZoneOf'),
    extractFunction(source, 'reportZoneSlice'),
    extractFunction(source, 'reportZoneHasContent'),
    extractFunction(source, 'hasReportFieldValue'),
    extractFunction(source, 'glanceFallbackLine'),
    'return { parseWorkspaceHash, reportZoneOf, reportZoneSlice, reportZoneHasContent, glanceFallbackLine, RESULT_ZONES, REPORT_ZONE_CARRY, REPORT_READING_ORDER, OPEN_SECTION_COUNT };',
];
// eslint-disable-next-line no-new-func
const api = new Function(pieces.join('\n'))();

console.log('\n独立结果页 · 带参路由与报告分区\n');

// ── 1. 带参路由解析 ───────────────────────────────────────────
console.log('  ── hash 解析 ──');
assertEq('#asset 单段：无参数', api.parseWorkspaceHash('#asset'), { view: 'asset', param: '' });
assertEq('#result/41 拆成视图 + 参数', api.parseWorkspaceHash('#result/41'), { view: 'result', param: '41' });
assertEq('多余的段丢掉', api.parseWorkspaceHash('#result/41/x'), { view: 'result', param: '41' });
assertEq('不带 # 也能解析', api.parseWorkspaceHash('result/41'), { view: 'result', param: '41' });
assertEq('空值不炸', api.parseWorkspaceHash(''), { view: '', param: '' });
assertEq('undefined 不炸', api.parseWorkspaceHash(undefined), { view: '', param: '' });

// 🔴 折回默认视图时参数必须一起丢掉。这条判据写在 setActiveWorkspaceView 里，
//    靠 expandWorkspaceAlias(parsed.view) === view 判断，改坏了这里会红。
assert(
    '折回默认视图时丢参数（setActiveWorkspaceView 里那句判断还在）',
    /const param = expandWorkspaceAlias\(parsed\.view\) === view \? parsed\.param : "";/.test(source),
);
assert(
    '地址栏写回带参数（不是只写视图名）',
    /const target = param \? `#\$\{view\}\/\$\{param\}` : `#\$\{view\}`;/.test(source),
);
assert(
    '重放当前视图走 currentWorkspaceRoute()（否则参数被抹）',
    /setActiveWorkspaceView\(currentWorkspaceRoute\(\) \|\| defaultWorkspaceView\)/.test(source),
);

// ── 2. 分区表是白名单加法 ─────────────────────────────────────
console.log('\n  ── 分区表 ──');
assertEq('登记过的字段进概览', api.reportZoneOf('key_findings'), 'overview');
assertEq('登记过的字段进问题清单', api.reportZoneOf('question_bank'), 'questions');
assertEq('🔴 没登记的字段落默认区「完整报告」', api.reportZoneOf('competitors'), 'full');
assertEq('🔴 将来新技能的新字段也落默认区、不会消失', api.reportZoneOf('some_brand_new_field_2027'), 'full');

const sample = {
    title: '示例报告',
    key_findings: ['发现一'],
    conclusion: '结论',
    question_bank: [{ 问题: 'a' }],
    competitors: [{ 竞品: 'A' }],
    table_headers: { question_bank: ['问题'] },
    section_meanings: { question_bank: '说明' },
};

const overview = api.reportZoneSlice(sample, 'overview');
const questions = api.reportZoneSlice(sample, 'questions');

assert('概览切片含 key_findings', Object.keys(overview).includes('key_findings'));
assert('概览切片不含 question_bank', !Object.keys(overview).includes('question_bank'));
assert('概览切片不含 competitors（它是默认区的）', !Object.keys(overview).includes('competitors'));
assert('问题清单切片含 question_bank', Object.keys(questions).includes('question_bank'));

// 🔴 渲染上下文必须每片都带。
// ⚠️ 这里**先断言名单本身**再逐项检查 —— 只写 forEach 的话，把 REPORT_ZONE_CARRY
//    清空会让循环跑 0 次、断言集体消失，测试照样全绿（变异测试实测过这个盲点）。
assertEq('渲染上下文名单就是这两个（清空它会让下面的检查凭空消失）',
    api.REPORT_ZONE_CARRY, ['table_headers', 'section_meanings']);
['table_headers', 'section_meanings'].forEach((key) => {
    assert(`🔴 概览切片带上渲染上下文 ${key}`, Object.keys(overview).includes(key));
    assert(`🔴 问题清单切片带上渲染上下文 ${key}`, Object.keys(questions).includes(key));
});

// ── 3. 页签按数据有无生成 ─────────────────────────────────────
console.log('\n  ── 页签生成 ──');
assert('有问题表 → 出「问题清单」页签', api.reportZoneHasContent(sample, 'questions'));
assert('有结论/发现 → 出「概览」页签', api.reportZoneHasContent(sample, 'overview'));
assert('有内容 → 一定有「完整报告」页签', api.reportZoneHasContent(sample, 'full'));

const noQuestions = { title: 'x', key_findings: ['a'] };
assert('没有问题表 → 不出「问题清单」页签', !api.reportZoneHasContent(noQuestions, 'questions'));

// 只有标题这类页眉字段时，概览等于空页，不该出页签
const chromeOnly = { title: 'x', brand_name: 'y', analysis_date: '2026-09-05', competitors: [{ a: 1 }] };
assert('🔴 只有标题/品牌名时不出「概览」页签（那是空页）', !api.reportZoneHasContent(chromeOnly, 'overview'));
assert('但「完整报告」照常有', api.reportZoneHasContent(chromeOnly, 'full'));

assertEq('页签顺序固定：概览 → 问题清单 → 完整报告',
    api.RESULT_ZONES.map((z) => z.id), ['overview', 'questions', 'full']);
// 三个页签都按统一的阅读顺序排。「完整报告」也排 —— 它是这次新开的一页，
// 不存在「保持旧顺序」的回归包袱，而登记顺序会把结论排在依据前面。
assertEq('三个页签共用同一份阅读顺序',
    api.RESULT_ZONES.map((z) => z.keyOrder === api.REPORT_READING_ORDER), [true, false, true]);
assert('问题清单有自己的顺序（问题表在前、FAQ 在后）',
    api.RESULT_ZONES.find((z) => z.id === 'questions').keyOrder[0] === 'question_bank');
assertEq('阅读顺序开头是「先说结论和要做什么」',
    api.REPORT_READING_ORDER.slice(0, 3), ['notice', 'core_answer', 'analysis_summary']);

// ── 4. 页面结构静态守 ─────────────────────────────────────────
console.log('\n  ── 页面结构 ──');
const cleanBlade = stripBladeComments(blade);
assert('blade 里有 data-workspace-view="result" 面板',
    /data-workspace-view="result"/.test(cleanBlade));
assert('🔴 结果页不在侧栏（它是从报告点进来的详情页，不是一级菜单）',
    !/data-view-target="result"/.test(cleanBlade) && !/href="#result"/.test(cleanBlade));
assert('结果页在 selfTitledViews 名单里（页头就是报告标题，否则标题重复两遍）',
    /selfTitledViews = \[[^\]]*"result"/s.test(source));
assert('结果页正文容器用 .result-view（和历史报告页同一套排版规则）',
    /<article class="result-view reader-document" id="resultPageBody"/.test(cleanBlade));
assert('🔴 结果页不封顶（不调 applyResultClamp）',
    !/applyResultClamp\(elements\.resultPageBody\)/.test(source));
assert('结果页正文渲染传 omitTitle（页头已经写了标题）',
    /omitTitle: true,/.test(source));
assert('阅读器接管当前分区目录，关闭旧的正文内目录',
    /hideToc: true,\s+reader: true,/.test(source));
assert('复制/下载把提示写进结果页自己的槽（不是藏着的历史报告页）',
    /copyArticle\(state\.resultArticle, elements\.resultPageMessage\)/.test(source)
    && /downloadArticle\(state\.resultArticle, elements\.resultPageMessage\)/.test(source));
assert('报告打不开时显式报错，不静默变空页',
    /showResultPageNotice\(\s*"打不开这份报告"/.test(source));

// ── 5. 执行入口瘦身 + 封顶下线 + 同步执行下线（第 3 步）────────
console.log('\n  ── 执行入口 ──');
const css = readFileSync(resolve(ROOT, 'public/geo-entry.css'), 'utf8');

// 🔴 封顶必须整套消失。留半套（比如 CSS 还在、JS 没了）不会报错，
//    只会在某次改动后神不知鬼不觉地把报告又截一半。
assert('🔴 JS 里没有 applyResultClamp / releaseResultClamp',
    !/\b(apply|release)ResultClamp\s*\(/.test(source));
assert('🔴 JS 里没有 RESULT_CLAMP_RESERVE', !/RESULT_CLAMP_RESERVE/.test(source));
assert('🔴 JS 里不再挂 is-clamped / result-expand',
    !/is-clamped/.test(source) && !/result-expand/.test(source));
assert('🔴 CSS 里封顶那几条规则也删干净了',
    !/is-clamped/.test(css) && !/\.result-expand/.test(css));

// 同步执行下线：只剩一条路，两个按钮和那段「选哪个」的说明一起没了
assert('技能表单只有一个提交按钮「开始执行」',
    /<button class="button primary" type="submit">开始执行<\/button>/.test(source));
assert('🔴 没有 data-run-async 按钮了', !/data-run-async/.test(source));
assert('🔴 execution-mode-help 在 JS 和 CSS 里都没了',
    !/execution-mode-help/.test(source) && !/execution-mode-help/.test(css));
assert('🔴 技能执行只打异步端点（不再调 skillExecuteEndpoint）',
    /skillExecuteAsyncEndpoint/.test(source) && !/config\.skillExecuteEndpoint/.test(source));
assert('submit 处理器自己叫 reportValidity（preventDefault 会挡掉浏览器的必填校验）',
    /async function runSelectedSkill\(event\)[\s\S]{0,400}?form\.reportValidity\(\)/.test(source));

// 三个入口不再把整份报告铺出来
assert('综合诊断入口渲染的是一眼摘要，不是整份报告',
    /\$\{reportGlanceBlock\(rawOutput, 0\)\}/.test(source)
    && !/\$\{renderStructuredReportHtml\(rawOutput\)\}/.test(source));
assert('技能执行入口渲染的是一眼摘要',
    /\$\{renderExecutionGlance\(execution, text\)\}/.test(source));
assert('历史报告标题直接链接独立阅读页',
    source.includes('class="report-title-link" href="#result/${id}"'));

// 🔴 但没归档的执行仍然要看得见产出 —— 钱已经扣了
assert('🔴 没有 article_id 的执行仍回落到 renderExecutionOutput（不能让人看不到自己花钱买的东西）',
    /if \(!articleId\) \{[\s\S]{0,200}?return renderExecutionOutput\(execution, text\);/.test(source));
assert('renderExecutionOutput 没被删（回落路径要用）',
    /function renderExecutionOutput\(/.test(source));

// 编辑 / 发布保留原流程，入口移到阅读页可展开的管理区。
assert('原编辑操作仍保留', /data-asset-action="edit"/.test(cleanBlade) === false
    && /data-asset-action="edit"/.test(source));
assert('发布与审核面板仍保留', /<div id="articlePublishPanel"><\/div>/.test(source));

// ── 6. 折叠闸换成位置规则（第 4 步）────────────────────────
console.log('\n  ── 折叠闸 ──');
assert('🔴 字段名白名单 ALWAYS_OPEN_REPORT_KEYS 已删（只剩注释里提它）',
    !/const ALWAYS_OPEN_REPORT_KEYS/.test(source) && !/ALWAYS_OPEN_REPORT_KEYS\.has/.test(source));
assert('常开改成按位置取前 N 节',
    /const alwaysOpen = longMode \? remaining\.slice\(0, OPEN_SECTION_COUNT\) : \[\];/.test(source));
assert('折叠判断用位置集合，不再判字段名',
    /longMode \? !openKeys\.has\(key\) : isSecondaryReportKey\(key\)/.test(source));
assert('🔴 「命中 0 就退回短报告」那道硬闸删了（位置规则下常开永远非空，闸没有存在意义）',
    /const longMode = !options\.reader && remaining\.length >= LONG_REPORT_SECTION_THRESHOLD;/.test(source));
assert('常开节数是个正数且小于长报告门槛（否则长报告会全开或全折）',
    api.OPEN_SECTION_COUNT > 0 && api.OPEN_SECTION_COUNT < 10);

// ── 7. 一眼摘要不能吐 JSON 碎片（09-05 走查现场发现）──────────
// 现场症状：技能执行入口的摘要位置显示了一个孤零零的 `{`。
// 成因：摘要先找 core_answer 等字段，找不到就退回「正文第一行」；
//       技能正文是格式化 JSON，第一行正好是 `{`。
console.log('\n  ── 一眼摘要 ──');
assertEq('正文是格式化 JSON → 不拿它的行当摘要', api.glanceFallbackLine('{\n  "title": "x"\n}'), '');
assertEq('正文是 JSON 数组 → 同样不取', api.glanceFallbackLine('[\n  {"a":1}\n]'), '');
assertEq('孤零零的括号不当摘要', api.glanceFallbackLine('{'), '');
assertEq('压成一行的 JSON 也不取', api.glanceFallbackLine('{"title":"x","a":1}'), '');
assertEq('人话第一行照常取', api.glanceFallbackLine('这是一句摘要。\n后面还有别的'), '这是一句摘要。');
assertEq('空正文不炸', api.glanceFallbackLine(''), '');
assertEq('null 不炸', api.glanceFallbackLine(null), '');
assertEq('前后空白照常清掉', api.glanceFallbackLine('  开头有空格  \n下一行'), '开头有空格');

assert('摘要兜底走 glanceFallbackLine，不再直接切正文第一行',
    /const summary = reportOneLineSummary\(parsed\) \|\| glanceFallbackLine\(text\);/.test(source)
    && !/String\(text \|\| ""\)\.trim\(\)\.split\(\/\\n\+\/\)\[0\]/.test(source));
assert('拿不到 structured 时再拿 text 试一次（轮询接口不一定回 structured）',
    /if \(!structured\) structured = tryParseJsonValue\(text \|\| ""\);/.test(source));

// subtitle 是最后一档：它讲「这份报告在做什么」，不是结论，不能盖过 core_answer
{
    // ⚠️ 必须先抠出函数本体再找 `const fields`：文件里另有同名常量（article?.summary 那个），
    //    直接对全文匹配会锚到别处，断言看着绿其实什么也没守住。
    const fn = extractFunction(source, 'reportOneLineSummary');
    const fields = fn.match(/const fields = \[([^\]]+)\];/)?.[1] ?? '';
    const list = fields.split(',').map((s) => s.trim().replace(/^["']|["']$/g, ''));
    assert('摘要字段表里有 subtitle（意图挖掘这类技能只有它）', list.includes('subtitle'));
    assertEq('subtitle 排在最后一位', list[list.length - 1], 'subtitle');
    assertEq('core_answer 仍排第一', list[0], 'core_answer');
}

// ── 8. 关键指标不能用 flex-wrap + border-right（09-05 走查现场发现）──
// 现场症状：意图挖掘的「关键指标」竖线飘在半空、缩进忽深忽浅。
// 成因：flex-wrap 下 note 长的项被撑到独占一行，右边框就成了半空中的竖线。
console.log('\n  ── 关键指标排版 ──');
{
    const block = css.match(/\.report-metrics \{[^}]+\}/)?.[0] ?? '';
    assert('🔴 .report-metrics 用网格不用 flex（flex-wrap 会让分隔线飘掉）',
        /display:\s*grid/.test(block) && !/flex-wrap/.test(block));
    assert('列宽定死，换行由列宽决定', /grid-template-columns:\s*repeat\(auto-fit,\s*minmax\(\d+px,\s*1fr\)\)/.test(block));
    const item = css.match(/\.report-metric \{[^}]+\}/)?.[0] ?? '';
    assert('🔴 单项不再挂 border-right', !/border-right/.test(item));
    assert('单项有 min-width: 0（否则长 note 顶宽网格列、又退回错位）', /min-width:\s*0/.test(item));
    assert('🔴 first-child / last-child 那两条补丁一起删掉（网格里它们只会补出半行怪边距）',
        !/\.report-metric:first-child/.test(css) && !/\.report-metric:last-child/.test(css));
}

// ── 9. 点击处必须说话（09-05 走查现场发现）────────────────────
// 现场症状：技能跑完了，「开始执行」旁边那句还停在「已开始执行，正在查询状态...」，
// 从头到尾没变过 —— 老板的原话是「我的页面没有任何变化」。
// 成因：轮询只把终态写进面板级消息，没回写到人点击的那个位置。
console.log('\n  ── 点击处回写 ──');
{
    // 真执行：拿真源码里的 setSkillBriefMessage，喂一个假的 state / setMessage / document
    const fnSrc = extractFunction(source, 'setSkillBriefMessage');
    const make = (selectedSkillId) => {
        const written = [];
        const slot = { id: 'skillBriefMessage' };
        const ctx = new Function('state', 'setMessage', 'document', `
            ${fnSrc}
            return setSkillBriefMessage;
        `)(
            { selectedSkill: selectedSkillId ? { id: selectedSkillId } : null },
            (node, text, variant) => written.push({ node, text, variant }),
            { querySelector: (sel) => (sel === '#skillBriefMessage' ? slot : null) },
        );
        return { ctx, written, slot };
    };

    {
        const { ctx, written, slot } = make('yao-geo-execution-roadmap');
        ctx('yao-geo-execution-roadmap', '执行完成，报告已生成，结果在下方。', 'success');
        assertEq('表单还是同一个技能 → 写进点击处', written.length, 1);
        assertEq('写的是 #skillBriefMessage 那个槽', written[0]?.node, slot);
        assertEq('用的是成功态', written[0]?.variant, 'success');
    }
    {
        // 🔴 六到八分钟里人可能已经切去别的技能，那时那个槽是另一张表单的
        const { ctx, written } = make('yao-geo-intent-miner');
        ctx('yao-geo-execution-roadmap', '执行完成，报告已生成，结果在下方。', 'success');
        assertEq('🔴 已经切到别的技能 → 一个字都不写（否则张冠李戴）', written.length, 0);
    }
    {
        const { ctx, written } = make(null);
        ctx('yao-geo-execution-roadmap', '执行完成', 'success');
        assertEq('没有选中技能 → 不写', written.length, 0);
    }
    {
        const { ctx, written } = make('yao-geo-execution-roadmap');
        ctx('', '执行完成', 'success');
        assertEq('技能 id 为空 → 不写（空串不能当通配）', written.length, 0);
    }
}

assert('终态回写点击处（成功说完成、失败说原因）',
    /setSkillBriefMessage\(\s*startedSkillId,\s*done \? "执行完成，报告已生成，结果在下方。" : message/.test(source));
assert('执行中也照实回写，不让「正在查询状态」一挂八分钟',
    /setMessage\(elements\.skillMessage, live\);\s*\/\/[^\n]*\n\s*setSkillBriefMessage\(startedSkillId, live\);/.test(source));
assert('查询瞬断时在点击处提示后台仍继续，并保留自动重试',
    /const failure = `状态查询暂时失败，后台任务仍会继续；\$\{retrySeconds\} 秒后自动重试，请勿重复提交。`;[\s\S]{0,220}?setSkillBriefMessage\(startedSkillId, failure, "warning"\);[\s\S]{0,80}?schedule\(retryDelay\);/.test(source));
assert('🔴 判断归属用开跑那一刻记下的技能，不用 execution.skill_id（查询失败时拿不到 execution）',
    /const startedSkillId = String\(context\.skillId \|\| state\.selectedSkill\?\.id \|\| ""\);/.test(source)
    && !/setSkillBriefMessage\(\s*execution/.test(source));

// ── 10. 没产出就不给复制/下载（09-05 走查拍板）──────────────────
// 老板原话：「肯定要收，不能为空」。
// 注意现状不是「复制了空内容」——copySkillExecution 里本来就拦了空值，
// 但它把「没东西可复制」写进面板级消息，离按钮十万八千里，点下去等于没反应。
console.log('\n  ── 复制/下载可用性 ──');
assert('没文本时复制/下载都带 disabled',
    /const noTextAttr = hasText \? "" : ` disabled title=/.test(source)
    && /data-skill-action="copy"\$\{noTextAttr\}/.test(source)
    && /data-skill-action="download"\$\{noTextAttr\}/.test(source));
assert('hasText 判定要去空白（全是空格不算有产出）',
    /const hasText = Boolean\(String\(text \|\| ""\)\.trim\(\)\);/.test(source));
assert('🔴 空值兜底没被删（置灰只是别让人点，拦截仍要在）',
    /async function copySkillExecution\(text\) \{\s*if \(!text\) \{/.test(source)
    && /function downloadSkillExecution\(execution, text\) \{\s*if \(!text\) \{/.test(source));
assert('用置灰不用隐藏（轮询每 2.5 秒重渲，隐藏会让工具条跳）',
    !/\$\{hasText \? `<button class="button secondary small" type="button" data-skill-action="copy"/.test(source));

// 真渲染一次：把 noTextAttr 那两行的行为跑出来，不靠读正则想象
{
    const render = (text) => {
        const hasText = Boolean(String(text || "").trim());
        const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
        const noTextAttr = hasText ? '' : ` disabled title="${esc('这次执行还没有产出文本')}"`;
        return `<button data-skill-action="copy"${noTextAttr}>复制</button>`;
    };
    assert('执行中（无文本）→ 按钮 disabled', /disabled/.test(render('')));
    assert('只有空白 → 仍然 disabled', /disabled/.test(render('   \n  ')));
    assert('有产出 → 按钮可点', !/disabled/.test(render('这是一份报告正文')));
    assert('置灰时给出原因（title 说人话）', /title="这次执行还没有产出文本"/.test(render('')));
}

// ── 11. 执行中不摆「下一步」（09-05 走查拍板）──────────────────
// 现场：状态「执行中」、正文「暂时没有文本输出」，下面已经在请人去做第 3 步。
console.log('\n  ── 执行中不摆下一步 ──');
{
    const inFlightSrc = extractFunction(source, 'isExecutionInFlight');
    const isInFlight = new Function(`${inFlightSrc}\nreturn isExecutionInFlight;`)();
    assert('running 算在途', isInFlight('running'));
    assert('pending / queued / processing 都算在途',
        isInFlight('pending') && isInFlight('queued') && isInFlight('processing')
        && isInFlight('in_progress') && isInFlight('scheduled'));
    assert('completed 不算在途', !isInFlight('completed'));
    assert('failed / cancelled 不算在途', !isInFlight('failed') && !isInFlight('cancelled'));
    assert('大小写不敏感（后端回 RUNNING 也得认）', isInFlight('RUNNING'));
    assert('状态缺失不当成在途（否则历史执行的下一步会整片消失）', !isInFlight(undefined) && !isInFlight(''));
}
assert('「下一步」卡在途时直接返回空',
    /function skillNextActionPanel\(execution\) \{[\s\S]{0,400}?if \(isExecutionInFlight\(execution\?\.status\)\) return "";/.test(source));
assert('🔴 在途判据只有一份（取消按钮改成委托，不再自带列表）',
    /function isExecutionCancellable\(status\) \{\s*return isExecutionInFlight\(status\);\s*\}/.test(source));
assert('🔴 那份状态列表在源码里只出现一次（出现两次＝日后加状态只会改到一处）',
    (source.match(/"queued", "pending", "running", "processing", "in_progress", "scheduled"/g) || []).length === 1);

console.log(`\n${failures === 0 ? '✓' : '✗'} ${failures === 0 ? `全部通过：${checks} 项断言` : `${failures} / ${checks} 项失败`}\n`);
process.exit(failures === 0 ? 0 : 1);
