#!/usr/bin/env node
/**
 * 单 7 · 手动发布工作台入口 前端回归测试
 *
 * 为什么要这个测试
 * ────────────────
 * 单 7 关账批补了工作台侧栏入口（welcome.blade.php，发布与增长组 / 发布管理下面一行）。
 * 这个入口有一个只有踩过才知道的坑：
 *
 *   geo-entry.js 顶部把页面上所有带 data-view-target 的链接收进一个 NodeList
 *   （workspaceMenuLinks），点击处理里**无条件** event.preventDefault()，
 *   然后拿 data-view-target 去切工作台内部视图。
 *
 *   手动发布是外链（/manual-publish，自持 session 的独立壳页），不是内部视图。
 *   链接一旦顺手带上 data-view-target：
 *       点击被吞掉 → 跳转不发生 → 工作台去找一个不存在的视图 → 主区一片空白。
 *   **不报错，只是白。** 这种问题查起来非常费时间。
 *
 *   不带这个属性，链接就不在 NodeList 里，浏览器原生跳转，一切正常。
 *
 * 所以下面第 2 条断言是这批最关键的一条 —— 它是这个坑的防回归网。
 *
 * 测什么（读真实源文件做静态断言，不抄一份实现自测自话）
 * ──────────────────────────────────────────────────────
 *   1. welcome.blade.php 里存在 href="/manual-publish" 的链接；
 *   2. 这个链接**不含** data-view-target（🔴 防回归，见上）；
 *   3. 这个链接被 @if (config('geo.manual_publish_enabled')) 包住
 *      —— 开关关掉时路由不注册，菜单必须一起消失，否则回滚出一个 404 链接；
 *   4. geo-entry.js 里对 manualPublishMenuLink 做了角色判断，且判断里
 *      同时出现 admin 和 editor（member 不该看见入口）；
 *   5. 该函数**真的被调用**（调用点检查）
 *      —— 单 7 的时区 bug 正是「函数写对了但没人调用它」的半边工程，
 *         只测实现抓不到这个形态。
 *
 * 运行
 * ────
 *   node tests/js/manual-publish-menu-entry.test.mjs
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { readEntrySource } from './helpers/entry-source.mjs';
import { workspaceNavigationSource } from './helpers/workspace-nav-source.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '../..');

const BLADE = 'WorkspaceNavigation.php / workspace-nav.blade.php';
const ENTRY_JS = 'public/geo-entry.js';

let failures = 0;
let checks = 0;

function assert(label, actual, expected) {
    checks++;
    if (actual !== expected) {
        failures++;
        console.log(`  ✗ ${label}\n      实际: ${JSON.stringify(actual)}\n      期望: ${JSON.stringify(expected)}`);
        return false;
    }
    console.log(`  ✓ ${label}`);
    return true;
}

/** 按花括号配对，从真实源文件里抠出函数体。 */
function extractFunction(source, name) {
    const marker = `function ${name}(`;
    const start = source.indexOf(marker);
    if (start === -1) throw new Error(`源码里找不到 \`${marker}\` —— 函数被改名或删除了？`);
    const bodyStart = source.indexOf('{', start);
    if (bodyStart === -1) throw new Error(`\`function ${name}\` 缺少函数体`);
    let depth = 0;
    for (let i = bodyStart; i < source.length; i++) {
        if (source[i] === '{') depth++;
        else if (source[i] === '}' && --depth === 0) return source.slice(start, i + 1);
    }
    throw new Error(`\`function ${name}\` 花括号未配对，无法提取`);
}

/** 剥掉 Blade 注释 {{-- ... --}}，避免注释里提到的属性名干扰静态断言。 */
function stripBladeComments(source) {
    return source.replace(/\{\{--[\s\S]*?--\}\}/g, '');
}

/**
 * 剥掉 JS 注释，只留真代码。
 *
 * 调用点检查必须先做这一步：否则把调用注释掉之后，注释文本里的
 * `applyManualPublishVisibility()` 仍会被正则当成一次调用，断言永远为真。
 * （这个测试的第一版就是这么写的，注入故障时完全没变红 —— 摆设。）
 *
 * `//` 前面排除 `:`，以免把 https://... 里的双斜杠当注释吃掉。
 */
function stripJsComments(source) {
    return source
        .replace(/\/\*[\s\S]*?\*\//g, ' ')
        .replace(/(^|[^:'"`\\])\/\/[^\n]*/g, '$1');
}

console.log('\n单 7 · 手动发布工作台入口测试\n');

const navigation = workspaceNavigationSource();
const blade = stripBladeComments(navigation.html);
const entry = readEntrySource();

/* 精确取出锚点标签本体 —— 只看 <a ...>，不看周围文本。
   注释里会提到 data-view-target 这个词，必须排除掉，否则第 2 条永远为真。 */
const anchors = [...blade.matchAll(/<a\b[^>]*\bhref="\/manual-publish"[^>]*>/g)].map((m) => m[0]);

if (!assert(`${BLADE} · 存在 href="/manual-publish" 的入口链接`, anchors.length, 1)) {
    console.log('\n✗ 入口链接不存在或不止一个，后续断言无意义\n');
    process.exit(1);
}
const anchor = anchors[0];

/* 🔴 本次最关键的一条：入口绝不能带 data-view-target。
   带了 → 点击被 geo-entry.js 的 preventDefault() 吞掉 → 跳转不发生 → 主区变白且不报错。 */
assert(
    `${BLADE} · 入口链接不带 data-view-target（否则点击被吞、主区变白）`,
    /data-view-target/.test(anchor),
    false,
);

/* 外链没有「当前」态，aria-current 是内部视图高亮用的 */
assert(`${BLADE} · 入口链接不带 aria-current`, /aria-current/.test(anchor), false);

/* 默认隐藏：由 JS 按角色放出。失效方向必须是「看不见」 */
assert(`${BLADE} · 入口链接默认 hidden（由 JS 按角色放出）`, /\bhidden\b/.test(anchor), true);

/* The approved static definition declares the gate; render the same partial
   with that gate disabled to prove the external link really disappears. */
const gated = Object.values(navigation.definition).flatMap(groups => groups.flatMap(group => group.items))
    .filter(item => item.page === 'manual-publish' && item.flag === 'manual_publish_enabled');
assert(`${BLADE} · 入口被 GEO_MANUAL_PUBLISH_ENABLED 开关包住`, gated.length, 1);
if (gated.length === 1) {
    const disabled = workspaceNavigationSource({ flags: { manual_publish_enabled: false } });
    assert(`${BLADE} · 开关关闭时真实渲染不含这个入口链接`, /href="\/manual-publish"/.test(disabled.html), false);
}

/* Position is checked in the actual rendered group, including anchor order. */
const section = navigation.groups.find(group => group.links.some(link => link.attributes.href === '/manual-publish'));
assert(`${BLADE} · 入口落在「发布」分组`, section?.label === '发布', true);
const preceding = section?.links.findIndex(link => link.attributes.href === '/manual-publish') ?? -1;
assert(`${BLADE} · 入口紧跟在「发布管理」之后`, preceding > 0 && section.links[preceding - 1].label === '发布管理', true);

/* ── geo-entry.js 侧 ───────────────────────────────────────────── */

let fn;
try {
    fn = extractFunction(entry, 'applyManualPublishVisibility');
} catch (error) {
    failures++;
    console.log(`  ✗ ${ENTRY_JS} · ${error.message}`);
    console.log(`\n✗ 无法继续\n`);
    process.exit(1);
}

assert(`${ENTRY_JS} · 角色判断里出现 admin`, /['"]admin['"]/.test(fn), true);
assert(`${ENTRY_JS} · 角色判断里出现 editor`, /['"]editor['"]/.test(fn), true);
assert(`${ENTRY_JS} · 角色判断作用于 manualPublishMenuLink`, /manualPublishMenuLink/.test(fn), true);
/* 失效方向必须是「看不见」：取不到角色（空串）时要隐藏，不能放行 */
assert(`${ENTRY_JS} · 取不到角色时保持隐藏（不把空 role 算放行）`, /role\s*===\s*["']{2}/.test(fn), false);

/* 调用点检查：函数写对了但没人调 == 半边工程。
   单 7 的时区 bug 正是这个形态，所以这一条不能省。
   两个前提缺一不可，否则断言是假的：
     - 先剥注释，否则被注释掉的调用照样被算作调用；
     - lookbehind 排除 `function` 定义本身，否则"只有一个定义、零个调用"也会过关。 */
const codeOnly = stripJsComments(entry);
/* 2026-09-21 §7 第七刀把线索管理台（`applyManualPublishVisibility` 住在它旁边）搬去了
   geo-entry-leads.js，主文件那句调用改走 `globalThis.GEO?.applyManualPublishVisibility?.()`
   —— 与其他拆出块的回挂口径一致。所以这里要同时认「直接调用」和「可选链调用」两种形态，
   否则断言会因为 `?.()` 里的 `?.` 而假红（函数明明被调了）。 */
const callSites = [...codeOnly.matchAll(/(?<!function\s+)applyManualPublishVisibility\s*(?:\?\.)?\s*\(\s*\)/g)].length;
assert(`${ENTRY_JS} · applyManualPublishVisibility() 真的被调用（剥注释 + 排除函数定义）`, callSites >= 1, true);

/* 元素登记：elements 里要有这一项，否则上面的 if 直接短路 */
assert(`${ENTRY_JS} · elements 里登记了 manualPublishMenuLink`, /manualPublishMenuLink:\s*\$\("manualPublishMenuLink"\)/.test(entry), true);

console.log('');
if (failures === 0) {
    console.log(`✓ 全部通过：${checks} 项断言\n`);
    process.exit(0);
}
console.log(`✗ ${failures} 项失败（共 ${checks} 项）\n`);
process.exit(1);
