#!/usr/bin/env node
/**
 * 会话身份绑定（B）· 六个壳页启动行为一致性 + 主工作台 401 重试 前端回归测试
 *
 * 为什么要这个测试
 * ────────────────
 * B 缺陷 B1：五个自持 session 的壳页里，只有 /manual-publish 启动时「有旧 token 就直接用」，
 * 跳过身份核验——退出账户中心 / 换账号后仍以旧身份操作。本批把它的启动改成和另外四个
 * 一致：无条件先 session-login。B 缺陷 B4：主工作台 api() 中途 401 没有自愈，
 * 本批加了「用 cookie 重换 token 再重试一次」。
 *
 * 与 panel-session-reverify.test.mjs 同源的老教训：这些面板是**复制关系**，
 * 所以 PANELS 数组必须放**全部 5 个**（entry / platform-ops / analytics /
 * manual-publish / visibility），少一个就漏一类。
 *
 * 测什么（读真实源文件断言，不抄一份实现自测自话）
 * ──────────────────────────────────────────────
 *   1. 每个壳页都存在 bootstrap 启动函数，且它**调用了 session-login**（无条件先核验）
 *   2. 每个壳页的启动**不被 `if (token())` 短路**——即「有旧 token 就跳过核验」的形态已消失
 *   3. 每个壳页确实在启动入口调用了 bootstrap()
 *   4. 主工作台 api() 有 401 单次重试（reverifyOnce + 每请求一次上限 + 重试走 apiWithSessionRetry(..., true)）
 *
 * 运行
 * ─────
 *   node tests/js/panel-boot-session.test.mjs
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));

/** 全部 5 个自持 session 的壳页。新增同类面板时必须加进来。 */
const PANELS = [
    { name: '主工作台', file: 'public/geo-entry.js' },
    { name: '平台运营', file: 'public/geo-platform-ops.js' },
    { name: '数据分析', file: 'public/geo-analytics.js' },
    { name: '手动发布', file: 'public/geo-manual-publish.js' },
    { name: 'AI 可见性', file: 'public/geo-visibility.js' },
];

const ENTRY = 'public/geo-entry.js';

let failures = 0;
let checks = 0;

function assert(label, actual, expected) {
    checks++;
    if (actual !== expected) {
        failures++;
        console.log(`  ✗ ${label}\n      实际: ${JSON.stringify(actual)}\n      期望: ${JSON.stringify(expected)}`);
    }
    return actual === expected;
}

/**
 * 剥注释。必须**认字符串**——源码里有 'https://example.test/login' 这种含 // 的字面量，
 * 朴素地按 // 切会把它削掉，进而把「调用点静态检查」变成恒真断言（单 7 教训）。
 */
function stripComments(src) {
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

/** 按花括号配对，从真实源文件里抠出整段函数体。 */
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

console.log('\n会话身份绑定 · 六个壳页启动一致性 + 主工作台 401 重试测试\n');

/* ────────────────────────────────────────────────────────────
   1 / 2 / 3：六个壳页同款
   ──────────────────────────────────────────────────────────── */
for (const panel of PANELS) {
    const path = resolve(HERE, '../..', panel.file);
    const raw = readFileSync(path, 'utf8');
    const src = stripComments(raw);
    const before = failures;
    const tag = panel.name;

    /* ---- 1. bootstrap 函数存在且调用 session-login（无条件先核验）---- */
    const boot = extractFunction(src, 'bootstrap');
    assert(`${tag} · 存在 bootstrap 启动函数`, boot.length > 0, true);
    assert(
        `${tag} · bootstrap 调用了 session-login`,
        /\/api\/v1\/auth\/session-login/.test(boot) || /sessionLoginEndpoint/.test(boot),
        true,
    );

    /* ---- 2. 启动不被「有 token 就跳过核验」的 if (token()) 短路 ---- */
    // 旧 manual-publish 的形态是 `if (token()) { hideGuard(); boot(); }`——
    // 有旧 token 就直接 boot、跳过 session-login。这是 B1 要修的根。
    const shortCircuit = /if\s*\(\s*token\s*\(\s*\)\s*\)\s*\{[^}]*?(?:boot|bootstrap)\s*\(/.test(src);
    assert(`${tag} · 启动不被 if (token()) 短路`, shortCircuit, false);

    /* ---- 3. 启动入口真的调用了 bootstrap()（只定义没调用等于没修）---- */
    assert(`${tag} · 启动入口调用了 bootstrap()`, /bootstrap\s*\(\s*\)/.test(src), true);

    console.log(`  ${failures === before ? '✓' : '✗'} ${tag.padEnd(8)} ${panel.file}`);
}

/* ────────────────────────────────────────────────────────────
   4. 主工作台 api() 中途 401 单次重试（B4）
   ──────────────────────────────────────────────────────────── */
{
    const entryRaw = readFileSync(resolve(HERE, '../..', ENTRY), 'utf8');
    const entry = stripComments(entryRaw);
    const before = failures;

    const apiRetry = extractFunction(entry, 'apiWithSessionRetry');
    assert('主工作台 · api 有 401 重试分支', /response\.status\s*===\s*401/.test(apiRetry), true);
    assert('主工作台 · 401 时调用 reverifyOnce 重换 token', /reverifyOnce\s*\(/.test(apiRetry), true);
    // 每个请求最多重试一次：重试后 (isRetry=true) 不再重试。
    assert(
        '主工作台 · 重试走 apiWithSessionRetry(path, options, true)',
        /apiWithSessionRetry\s*\(\s*path\s*,\s*options\s*,\s*true\s*\)/.test(apiRetry),
        true,
    );
    assert('主工作台 · 存在「重试过就不再重试」的 isRetry 判断', /!isRetry/.test(apiRetry), true);

    const reverify = extractFunction(entry, 'reverifyOnce');
    // 整页只核验一次 + 并发共用。少了这个守卫：同时发的几个请求会各打一次 session-login。
    assert('主工作台 · reverifyOnce 有一次性守卫 if (!reverifyPromise)', /if\s*\(\s*!\s*reverifyPromise\s*\)/.test(reverify), true);
    assert('主工作台 · 并发 401 共用同一个核验 promise', /return\s+reverifyPromise\s*;/.test(reverify), true);
    assert('主工作台 · 重新核验走现成的 session-login', /\/api\/v1\/auth\/session-login/.test(reverify) || /sessionLoginEndpoint/.test(reverify), true);
    // 不 reload：reload 会让「重试刚才那一次请求」失去意义，也可能把 401 变成刷新循环。
    assert('主工作台 · 重新核验不触发页面 reload', /window\.location\.reload/.test(reverify), false);
    // 重换失败 = 没登录（不是没权限）→ 跳登录页，绝不竖「无访问权限」墙。
    assert('主工作台 · 重换失败跳登录页', /accountLoginUrl\s*\(\s*\)/.test(reverify), true);

    console.log(`  ${failures === before ? '✓' : '✗'} ${'主工作台 401 重试'.padEnd(8)} ${ENTRY}`);
}

console.log('');
if (failures === 0) {
    console.log(`✓ 全部通过：${checks} 项断言\n`);
    process.exit(0);
}
console.log(`✗ ${failures} 项失败（共 ${checks} 项）\n`);
process.exit(1);
