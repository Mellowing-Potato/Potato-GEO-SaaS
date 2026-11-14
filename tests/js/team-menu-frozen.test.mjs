#!/usr/bin/env node
/**
 * 团队管理入口冻结 · 前端回归测试
 *
 * 背景（2026-08-31 老板拍板）
 * ──────────────────────────
 * 商业模式是「一人一个租户」，跟角色没关系。上游 GEOFlow 是单人/小团队自用产品，
 * 我们复刻时把它的 admin / editor / member 三级角色一起搬了过来，但：
 *
 *   · 自助注册的每个客户都会拿到一个新租户 + role='admin'（LocalAccounts::create），
 *     所以 Permissions::allows() 第一行的 admin 短路会无条件放行 —— 角色系统留着零运行时代价；
 *   · 而工作台侧栏的「团队管理」有「邀请成员」表单，角色下拉三个选项全在。
 *     客户点两下就能邀请出一个 member/editor，一旦邀请，对方立刻掉进那片前端债：
 *     写按钮显示但点不动、发票页把 403 渲染成「暂无发票记录」、一堆 403 打在控制台。
 *
 * ⇒ 拍板：角色系统**冻结不删**，只关掉入口。以后卖企业版（一个账号绑多人）再复活。
 *
 * 测什么（读真实源文件做静态断言）
 * ──────────────────────────────
 *   1. 侧栏「团队管理」链接是 hidden 的；
 *   2. 🔴 /#team 深链接也进不去 —— 光藏链接不够，setActiveWorkspaceView() 吃的是
 *      window.location.hash，地址栏直接敲 #team 会把面板放出来。必须在
 *      normalizeWorkspaceView() 里折回默认视图；
 *   3. 🔴 没有任何代码在运行时把它重新放出来（不能一边 hidden 一边 JS 取消 hidden）；
 *   4. 这是「关门」不是「删门」：teamPanel / loadTeam / /api/v1/team/ 都还在
 *      —— 手动发布的指派人下拉正在调这个接口，删了会连带炸掉单 7。
 *
 * 运行
 * ────
 *   node tests/js/team-menu-frozen.test.mjs
 */

import { readFileSync } from 'node:fs';
// 面板已拆到 workbench/panels/*（计划 §7 第 2 步）：侧栏链接在父模板，teamPanel 在分部里，
// 所以这里读整组模板，而不是只读父模板。
import { workbenchTemplateSource } from './helpers/workbench-templates.mjs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { readEntrySource } from './helpers/entry-source.mjs';
import { workspaceNavigationSource } from './helpers/workspace-nav-source.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '../..');

const BLADE = 'resources/views/welcome.blade.php';
const ENTRY_JS = 'public/geo-entry.js';
const MANUAL_PUBLISH_JS = 'public/geo-manual-publish.js';

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

/** 剥 Blade 注释：注释里写了 hidden、FROZEN_WORKSPACE_VIEWS 等词，不剥会让断言恒真。 */
function stripBladeComments(source) {
    return source.replace(/\{\{--[\s\S]*?--\}\}/g, '');
}

/**
 * 剥 JS 注释 —— 必须**认字符串**，不能拿正则一把梭。
 *
 * geo-entry.js 里有 `Accept` 头写成「星号-斜杠-星号」的字符串字面量。
 * 朴素的块注释正则会把那个字面量里的「斜杠前的星号」当成块注释结尾、
 * 把前面某处的注释开头当成开头，一口吃掉五万多个字符，
 * 把 loadTeam / normalizeWorkspaceView 整段吞掉 —— 于是「源码里找不到这个函数」，
 * 或者更糟：断言恒假而看不出原因。（本测试第一版就是这么写的，当场炸了。）
 * 同源教训见 panel-session-reverify.test.mjs 的 stripComments。
 */
function stripJsComments(src) {
    let out = '';
    let i = 0;
    while (i < src.length) {
        const c = src[i];
        if (c === '"' || c === "'" || c === '`') {
            const quote = c;
            out += c;
            i++;
            while (i < src.length) {
                out += src[i];
                if (src[i] === '\\') { out += src[i + 1] ?? ''; i += 2; continue; }
                if (src[i] === quote) { i++; break; }
                i++;
            }
            continue;
        }
        if (c === '/' && src[i + 1] === '/') {
            while (i < src.length && src[i] !== '\n') i++;
            continue;
        }
        if (c === '/' && src[i + 1] === '*') {
            i += 2;
            while (i < src.length && !(src[i] === '*' && src[i + 1] === '/')) i++;
            i += 2;
            continue;
        }
        out += c;
        i++;
    }
    return out;
}

/** 按花括号配对抠函数体。 */
function extractFunction(source, name) {
    const marker = `function ${name}(`;
    const start = source.indexOf(marker);
    if (start === -1) throw new Error(`源码里找不到 \`${marker}\``);
    const bodyStart = source.indexOf('{', start);
    let depth = 0;
    for (let i = bodyStart; i < source.length; i++) {
        if (source[i] === '{') depth++;
        else if (source[i] === '}' && --depth === 0) return source.slice(start, i + 1);
    }
    throw new Error(`\`function ${name}\` 花括号未配对`);
}

console.log('\n团队管理入口冻结测试\n');

const blade = stripBladeComments(workbenchTemplateSource());
const entryRaw = readEntrySource();
const entry = stripJsComments(entryRaw);

/* ── 1. 侧栏链接 hidden ────────────────────────────────────────── */
const navigation = workspaceNavigationSource();
const navigationLabel = 'WorkspaceNavigation.php / 设置菜单渲染';
const anchors = [...navigation.html.matchAll(/<a\b[^>]*\bhref="#team"[^>]*>/g)].map((m) => m[0]);
if (!assert(`${navigationLabel} · 侧栏存在且只有一个 href="#team" 链接`, anchors.length, 1)) {
    console.log('\n✗ 锚点不唯一，后续断言无意义\n');
    process.exit(1);
}
assert(`${navigationLabel} · 「团队管理」链接是 hidden 的`, /\bhidden\b/.test(anchors[0]), true);
assert(`${navigationLabel} · 链接带 id（复活时好找、也便于本测试定位）`, /id="teamMenuLink"/.test(anchors[0]), true);

/* ── 2. 🔴 深链接也进不去 ───────────────────────────────────────── */
let normalize;
try {
    normalize = extractFunction(entry, 'normalizeWorkspaceView');
} catch (error) {
    failures++;
    console.log(`  ✗ ${ENTRY_JS} · ${error.message}`);
    console.log('\n✗ 无法继续\n');
    process.exit(1);
}
assert(`${ENTRY_JS} · 存在冻结视图名单 FROZEN_WORKSPACE_VIEWS`, /const\s+FROZEN_WORKSPACE_VIEWS\s*=/.test(entry), true);
const listMatch = entry.match(/const\s+FROZEN_WORKSPACE_VIEWS\s*=\s*\[([^\]]*)\]/);
assert(`${ENTRY_JS} · 名单里含 "team"`, !!listMatch && /["']team["']/.test(listMatch[1]), true);
/* 名单写对了但没人查 == 半边工程：必须在 normalizeWorkspaceView 里真的用上。 */
assert(`${ENTRY_JS} · normalizeWorkspaceView 真的查了这个名单`, /FROZEN_WORKSPACE_VIEWS/.test(normalize), true);
/* 折回默认视图，不是返回原值。 */
assert(
    `${ENTRY_JS} · 命中冻结名单时折回默认视图`,
    /FROZEN_WORKSPACE_VIEWS[\s\S]{0,80}?return\s+defaultWorkspaceView/.test(normalize),
    true,
);

/* ── 3. 🔴 没有代码在运行时把它放出来 ─────────────────────────── */
assert(
    `${ENTRY_JS} · 没有任何代码把 teamMenuLink 的 hidden 取消掉`,
    /teamMenuLink[\s\S]{0,120}?hidden\s*=\s*(false|!)/.test(entry),
    false,
);

/* ── 4. 关的是门，不是接口 ──────────────────────────────────────── */
assert(`${BLADE} · teamPanel 面板本体保留（冻结不是删除）`, /id="teamPanel"/.test(blade), true);
assert(`${ENTRY_JS} · loadTeam() 保留`, /function\s+loadTeam\s*\(/.test(entry), true);
const manualPublish = stripJsComments(readFileSync(resolve(ROOT, MANUAL_PUBLISH_JS), 'utf8'));
assert(
    `${MANUAL_PUBLISH_JS} · 单 7 指派人下拉仍在调 /api/v1/team/（接口没被连坐）`,
    /\/api\/v1\/team\//.test(manualPublish),
    true,
);

console.log('');
if (failures === 0) {
    console.log(`✓ 全部通过：${checks} 项断言\n`);
    process.exit(0);
}
console.log(`✗ ${failures} 项失败（共 ${checks} 项）\n`);
process.exit(1);
